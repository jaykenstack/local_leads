<?php
/**
 * Add Payment Method for Customer
 */

require_once '../../config/database.php';
require_once '../../config/app.php';
require_once '../../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Stripe\Stripe;
use Stripe\PaymentMethod;
use Stripe\Customer;

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

    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input || empty($input['payment_method_id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Payment method ID required']);
        exit;
    }

    $paymentMethodId = $input['payment_method_id'];
    $setDefault = isset($input['set_default']) ? (bool)$input['set_default'] : true;
    $billingDetails = $input['billing_details'] ?? null;

    $db = new Database();
    $conn = $db->getConnection();

    // Begin transaction
    $conn->beginTransaction();

    // Initialize Stripe
    Stripe::setApiKey(STRIPE_SECRET_KEY);

    // Get or create Stripe customer
    $stmt = $conn->prepare("
        SELECT stripe_customer_id, email, first_name, last_name 
        FROM customer_profiles cp
        JOIN users u ON cp.user_id = u.id
        WHERE cp.user_id = ?
    ");
    $stmt->execute([$decoded->user_id]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$customer['stripe_customer_id']) {
        // Create Stripe customer
        $stripeCustomer = Customer::create([
            'email' => $customer['email'],
            'name' => $customer['first_name'] . ' ' . $customer['last_name'],
            'payment_method' => $paymentMethodId,
        ]);

        $stripeCustomerId = $stripeCustomer->id;

        // Save to database
        $stmt = $conn->prepare("
            UPDATE customer_profiles 
            SET stripe_customer_id = ? 
            WHERE user_id = ?
        ");
        $stmt->execute([$stripeCustomerId, $decoded->user_id]);
    } else {
        $stripeCustomerId = $customer['stripe_customer_id'];
        
        // Attach payment method to customer
        $paymentMethod = PaymentMethod::retrieve($paymentMethodId);
        $paymentMethod->attach(['customer' => $stripeCustomerId]);
    }

    // Get payment method details
    $paymentMethod = PaymentMethod::retrieve($paymentMethodId);

    // If setting as default, remove default from others
    if ($setDefault) {
        $stmt = $conn->prepare("
            UPDATE customer_payment_methods 
            SET is_default = 0 
            WHERE customer_id = ?
        ");
        $stmt->execute([$decoded->user_id]);
    }

    // Save payment method to database
    $stmt = $conn->prepare("
        INSERT INTO customer_payment_methods (
            customer_id, stripe_payment_method_id, card_brand,
            card_last4, card_exp_month, card_exp_year, is_default,
            billing_address, billing_city, billing_state, billing_zip,
            created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            card_brand = VALUES(card_brand),
            card_last4 = VALUES(card_last4),
            card_exp_month = VALUES(card_exp_month),
            card_exp_year = VALUES(card_exp_year),
            is_default = VALUES(is_default),
            billing_address = VALUES(billing_address),
            billing_city = VALUES(billing_city),
            billing_state = VALUES(billing_state),
            billing_zip = VALUES(billing_zip),
            updated_at = NOW()
    ");

    $stmt->execute([
        $decoded->user_id,
        $paymentMethodId,
        $paymentMethod->card->brand,
        $paymentMethod->card->last4,
        $paymentMethod->card->exp_month,
        $paymentMethod->card->exp_year,
        $setDefault ? 1 : 0,
        $billingDetails['address'] ?? null,
        $billingDetails['city'] ?? null,
        $billingDetails['state'] ?? null,
        $billingDetails['zip'] ?? null
    ]);

    $methodId = $conn->lastInsertId();

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Payment method added successfully',
        'method_id' => $methodId,
        'method' => [
            'id' => $methodId,
            'stripe_payment_method_id' => $paymentMethodId,
            'card_brand' => $paymentMethod->card->brand,
            'card_last4' => $paymentMethod->card->last4,
            'card_exp_month' => $paymentMethod->card->exp_month,
            'card_exp_year' => $paymentMethod->card->exp_year,
            'is_default' => $setDefault
        ]
    ]);

} catch (Stripe\Exception\CardException $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Card error: ' . $e->getMessage()]);
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
    error_log("Add payment method error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred']);
}
?>