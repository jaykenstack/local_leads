<?php
/**
 * Purchase Additional Credits
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

    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input || empty($input['package_id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Package ID required']);
        exit;
    }

    $packageId = (int)$input['package_id'];
    $paymentMethodId = $input['payment_method_id'] ?? null;

    $db = new Database();
    $conn = $db->getConnection();

    // Get provider ID
    $stmt = $conn->prepare("SELECT id, stripe_customer_id FROM provider_profiles WHERE user_id = ?");
    $stmt->execute([$decoded->user_id]);
    $provider = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$provider) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Provider profile not found']);
        exit;
    }

    // Get package details
    $stmt = $conn->prepare("
        SELECT * FROM credit_packages 
        WHERE id = ? AND is_active = 1
    ");
    $stmt->execute([$packageId]);
    $package = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$package) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid package selected']);
        exit;
    }

    // Begin transaction
    $conn->beginTransaction();

    // Initialize Stripe
    Stripe::setApiKey(STRIPE_SECRET_KEY);

    // Create payment intent
    $paymentIntentData = [
        'amount' => $package['price'],
        'currency' => 'usd',
        'customer' => $provider['stripe_customer_id'],
        'metadata' => [
            'provider_id' => $provider['id'],
            'package_id' => $packageId,
            'credits' => $package['credits'],
            'type' => 'credit_purchase'
        ]
    ];

    if ($paymentMethodId) {
        $paymentIntentData['payment_method'] = $paymentMethodId;
        $paymentIntentData['confirm'] = true;
        $paymentIntentData['off_session'] = true;
    }

    $paymentIntent = PaymentIntent::create($paymentIntentData);

    // Calculate expiry date (credits expire in 1 year)
    $expiresAt = date('Y-m-d H:i:s', strtotime('+1 year'));

    // Record purchase
    $stmt = $conn->prepare("
        INSERT INTO credit_purchases (
            provider_id, package_id, amount, price_per_credit,
            total_amount, stripe_payment_intent_id,
            expires_at, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
    ");

    $stmt->execute([
        $provider['id'],
        $packageId,
        $package['credits'],
        $package['price'] / $package['credits'],
        $package['price'],
        $paymentIntent->id,
        $expiresAt
    ]);

    $purchaseId = $conn->lastInsertId();

    // Update subscription credits
    $stmt = $conn->prepare("
        UPDATE provider_subscriptions 
        SET lead_credits_remaining = lead_credits_remaining + ?,
            updated_at = NOW()
        WHERE provider_id = ? AND status IN ('active', 'trialing')
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$package['credits'], $provider['id']]);

    // Log activity
    logActivity($conn, $decoded->user_id, 'credits_purchased', 
        "Purchased {$package['credits']} credits for $" . number_format($package['price'] / 100, 2));

    $conn->commit();

    $response = [
        'success' => true,
        'message' => 'Credits purchased successfully',
        'purchase_id' => $purchaseId,
        'credits_added' => $package['credits']
    ];

    if (!$paymentMethodId) {
        $response['client_secret'] = $paymentIntent->client_secret;
        $response['requires_action'] = $paymentIntent->status === 'requires_action';
    }

    echo json_encode($response);

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
    error_log("Purchase credits error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred']);
}

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