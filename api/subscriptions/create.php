<?php
/**
 * Create New Subscription
 */

require_once '../../config/database.php';
require_once '../../config/app.php';
require_once '../../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Stripe\Stripe;
use Stripe\PaymentMethod;
use Stripe\Customer;
use Stripe\Subscription;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: ' . APP_URL);
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

// Get authorization header
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? '';

if (!preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No token provided']);
    exit;
}

$token = $matches[1];

try {
    // Decode JWT
    $decoded = JWT::decode($token, new Key(JWT_SECRET, 'HS256'));
    
    if ($decoded->user_type !== 'provider') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied']);
        exit;
    }

    // Get POST data
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid input']);
        exit;
    }

    // Validate required fields
    $required = ['plan_id', 'payment_method_id', 'billing_cycle'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => ucfirst(str_replace('_', ' ', $field)) . ' is required']);
            exit;
        }
    }

    $planId = (int)$input['plan_id'];
    $paymentMethodId = $input['payment_method_id'];
    $billingCycle = $input['billing_cycle']; // 'monthly' or 'yearly'
    $couponCode = $input['coupon_code'] ?? null;

    $db = new Database();
    $conn = $db->getConnection();

    // Get provider ID
    $stmt = $conn->prepare("SELECT id FROM provider_profiles WHERE user_id = ?");
    $stmt->execute([$decoded->user_id]);
    $provider = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$provider) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Provider profile not found']);
        exit;
    }

    // Get plan details
    $stmt = $conn->prepare("
        SELECT * FROM subscription_plans 
        WHERE id = ? AND is_active = 1
    ");
    $stmt->execute([$planId]);
    $plan = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$plan) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid plan selected']);
        exit;
    }

    // Begin transaction
    $conn->beginTransaction();

    // Initialize Stripe
    Stripe::setApiKey(STRIPE_SECRET_KEY);

    // Get or create Stripe customer
    $stmt = $conn->prepare("SELECT stripe_customer_id FROM provider_profiles WHERE id = ?");
    $stmt->execute([$provider['id']]);
    $stripeCustomerId = $stmt->fetchColumn();

    if (!$stripeCustomerId) {
        // Create Stripe customer
        $stmt = $conn->prepare("
            SELECT email, first_name, last_name 
            FROM users 
            WHERE id = ?
        ");
        $stmt->execute([$decoded->user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        $customer = Customer::create([
            'email' => $user['email'],
            'name' => $user['first_name'] . ' ' . $user['last_name'],
            'payment_method' => $paymentMethodId,
            'invoice_settings' => [
                'default_payment_method' => $paymentMethodId
            ]
        ]);

        $stripeCustomerId = $customer->id;

        // Save to database
        $stmt = $conn->prepare("
            UPDATE provider_profiles 
            SET stripe_customer_id = ? 
            WHERE id = ?
        ");
        $stmt->execute([$stripeCustomerId, $provider['id']]);
    }

    // Calculate price based on billing cycle
    $price = ($billingCycle === 'yearly' && $plan['price_yearly']) 
        ? $plan['price_yearly'] 
        : $plan['price_monthly'];

    // Create Stripe subscription
    $subscriptionData = [
        'customer' => $stripeCustomerId,
        'items' => [
            [
                'price_data' => [
                    'currency' => 'usd',
                    'product_data' => [
                        'name' => $plan['name'] . ' Plan',
                        'description' => $plan['description']
                    ],
                    'unit_amount' => $price,
                    'recurring' => [
                        'interval' => $billingCycle === 'yearly' ? 'year' : 'month'
                    ]
                ]
            ]
        ],
        'payment_behavior' => 'default_incomplete',
        'expand' => ['latest_invoice.payment_intent']
    ];

    // Add coupon if provided
    if ($couponCode) {
        $subscriptionData['coupon'] = $couponCode;
    }

    $stripeSubscription = Subscription::create($subscriptionData);

    // Calculate period dates
    $now = new DateTime();
    $periodEnd = clone $now;
    
    if ($billingCycle === 'yearly') {
        $periodEnd->modify('+1 year');
    } else {
        $periodEnd->modify('+1 month');
    }

    // Create subscription in database
    $stmt = $conn->prepare("
        INSERT INTO provider_subscriptions (
            provider_id, plan_id, status, billing_cycle,
            current_period_start, current_period_end,
            stripe_subscription_id, stripe_customer_id,
            lead_credits_remaining, created_at, updated_at
        ) VALUES (
            ?, ?, 'active', ?,
            NOW(), ?,
            ?, ?,
            ?, NOW(), NOW()
        )
    ");

    $leadCredits = $plan['leads_per_month'] == -1 ? 999999 : $plan['leads_per_month'];

    $stmt->execute([
        $provider['id'],
        $planId,
        $billingCycle,
        $periodEnd->format('Y-m-d H:i:s'),
        $stripeSubscription->id,
        $stripeCustomerId,
        $leadCredits
    ]);

    $subscriptionId = $conn->lastInsertId();

    // Save payment method
    $stmt = $conn->prepare("
        INSERT INTO customer_payment_methods (
            customer_id, stripe_payment_method_id, card_brand,
            card_last4, card_exp_month, card_exp_year, is_default
        ) VALUES (?, ?, ?, ?, ?, ?, 1)
        ON DUPLICATE KEY UPDATE
            stripe_payment_method_id = VALUES(stripe_payment_method_id),
            card_brand = VALUES(card_brand),
            card_last4 = VALUES(card_last4),
            card_exp_month = VALUES(card_exp_month),
            card_exp_year = VALUES(card_exp_year),
            is_default = 1
    ");

    // Get payment method details from Stripe
    $paymentMethod = PaymentMethod::retrieve($paymentMethodId);

    $stmt->execute([
        $decoded->user_id,
        $paymentMethodId,
        $paymentMethod->card->brand,
        $paymentMethod->card->last4,
        $paymentMethod->card->exp_month,
        $paymentMethod->card->exp_year
    ]);

    // Log activity
    logActivity($conn, $decoded->user_id, 'subscription_created', 
        "Subscribed to {$plan['name']} plan ({$billingCycle})");

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Subscription created successfully',
        'subscription_id' => $subscriptionId,
        'stripe_subscription_id' => $stripeSubscription->id,
        'client_secret' => $stripeSubscription->latest_invoice->payment_intent->client_secret
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
    error_log("Create subscription error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred']);
}

/**
 * Log user activity
 */
function logActivity($conn, $userId, $action, $description) {
    try {
        $stmt = $conn->prepare("
            INSERT INTO user_activity (user_id, action, description, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $userId,
            $action,
            $description,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    } catch (Exception $e) {
        error_log("Failed to log activity: " . $e->getMessage());
    }
}
?>