<?php
/**
 * Create Payment Intent for Service
 */

require_once '../../config/database.php';
require_once '../../config/app.php';
require_once '../../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Stripe\Stripe;
use Stripe\PaymentIntent;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: ' . APP_URL);
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? '';

if (!preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No token provided']);
    exit;
}

$token = $matches[1];

try {
    $decoded = JWT::decode($token, new Key(JWT_SECRET, 'HS256'));
    
    if ($decoded->user_type !== 'customer') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Only customers can make payments']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid input']);
        exit;
    }

    // Validate required fields
    $required = ['request_id', 'amount', 'payment_method_id'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => ucfirst(str_replace('_', ' ', $field)) . ' is required']);
            exit;
        }
    }

    $requestId = (int)$input['request_id'];
    $amount = (int)$input['amount']; // in cents
    $paymentMethodId = $input['payment_method_id'];
    $savePaymentMethod = isset($input['save_payment_method']) ? (bool)$input['save_payment_method'] : false;

    $db = new Database();
    $conn = $db->getConnection();

    // Get customer ID
    $stmt = $conn->prepare("SELECT id FROM customer_profiles WHERE user_id = ?");
    $stmt->execute([$decoded->user_id]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$customer) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Customer profile not found']);
        exit;
    }

    // Verify service request exists and belongs to customer
    $stmt = $conn->prepare("
        SELECT sr.*, p.id as provider_id, p.business_name, 
               p.stripe_connect_id, p.commission_rate,
               u.email as customer_email, u.first_name as customer_name
        FROM service_requests sr
        JOIN provider_profiles p ON sr.provider_id = p.id
        JOIN users u ON sr.customer_id = u.id
        WHERE sr.id = ? AND sr.customer_id = ? AND sr.status = 'accepted'
    ");
    $stmt->execute([$requestId, $customer['id']]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$request) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Service request not found or not ready for payment']);
        exit;
    }

    // Begin transaction
    $conn->beginTransaction();

    // Initialize Stripe
    Stripe::setApiKey(STRIPE_SECRET_KEY);

    // Calculate fees
    $platformFee = round($amount * (PLATFORM_FEE_PERCENTAGE / 100));
    $providerAmount = $amount - $platformFee;

    // Get or create Stripe customer
    $stmt = $conn->prepare("
        SELECT stripe_customer_id FROM customer_profiles 
        WHERE user_id = ?
    ");
    $stmt->execute([$decoded->user_id]);
    $stripeCustomerId = $stmt->fetchColumn();

    if (!$stripeCustomerId) {
        // Create Stripe customer
        $customer = \Stripe\Customer::create([
            'email' => $request['customer_email'],
            'name' => $request['customer_name'],
            'payment_method' => $paymentMethodId,
        ]);

        $stripeCustomerId = $customer->id;

        // Save to database
        $stmt = $conn->prepare("
            UPDATE customer_profiles 
            SET stripe_customer_id = ? 
            WHERE user_id = ?
        ");
        $stmt->execute([$stripeCustomerId, $decoded->user_id]);
    }

    // Create payment intent
    $paymentIntentData = [
        'amount' => $amount,
        'currency' => 'usd',
        'customer' => $stripeCustomerId,
        'payment_method' => $paymentMethodId,
        'off_session' => false,
        'confirm' => false,
        'metadata' => [
            'request_id' => $requestId,
            'provider_id' => $request['provider_id'],
            'customer_id' => $decoded->user_id,
            'platform_fee' => $platformFee,
            'provider_amount' => $providerAmount
        ]
    ];

    // Add transfer data if provider has Stripe Connect account
    if ($request['stripe_connect_id']) {
        $paymentIntentData['transfer_data'] = [
            'destination' => $request['stripe_connect_id'],
            'amount' => $providerAmount
        ];
    }

    $paymentIntent = PaymentIntent::create($paymentIntentData);

    // Save payment record
    $stmt = $conn->prepare("
        INSERT INTO service_fees (
            request_id, customer_id, provider_id,
            subtotal, platform_fee, tax_amount, total_amount,
            stripe_payment_intent_id, status, created_at
        ) VALUES (?, ?, ?, ?, ?, 0, ?, ?, 'pending', NOW())
    ");

    $stmt->execute([
        $requestId,
        $customer['id'],
        $request['provider_id'],
        $providerAmount,
        $platformFee,
        $amount,
        $paymentIntent->id
    ]);

    $feeId = $conn->lastInsertId();

    // Save payment method if requested
    if ($savePaymentMethod) {
        $paymentMethod = \Stripe\PaymentMethod::retrieve($paymentMethodId);
        
        $stmt = $conn->prepare("
            INSERT INTO customer_payment_methods (
                customer_id, stripe_payment_method_id, card_brand,
                card_last4, card_exp_month, card_exp_year, is_default
            ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                stripe_payment_method_id = VALUES(stripe_payment_method_id),
                card_brand = VALUES(card_brand),
                card_last4 = VALUES(card_last4),
                card_exp_month = VALUES(card_exp_month),
                card_exp_year = VALUES(card_exp_year)
        ");

        $stmt->execute([
            $decoded->user_id,
            $paymentMethodId,
            $paymentMethod->card->brand,
            $paymentMethod->card->last4,
            $paymentMethod->card->exp_month,
            $paymentMethod->card->exp_year,
            $savePaymentMethod ? 1 : 0
        ]);
    }

    $conn->commit();

    echo json_encode([
        'success' => true,
        'client_secret' => $paymentIntent->client_secret,
        'payment_intent_id' => $paymentIntent->id,
        'fee_id' => $feeId,
        'amount' => $amount,
        'platform_fee' => $platformFee,
        'provider_amount' => $providerAmount
    ]);

} catch (Stripe\Exception\CardException $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'message' => 'Card error: ' . $e->getMessage(),
        'decline_code' => $e->getDeclineCode()
    ]);
} catch (Stripe\Exception\ApiErrorException $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log("Stripe error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Payment processor error']);
} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log("Create payment error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred']);
}
?>