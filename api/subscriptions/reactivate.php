<?php
/**
 * Reactivate Cancelled Subscription
 */

require_once '../../config/database.php';
require_once '../../config/app.php';
require_once '../../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Stripe\Stripe;
use Stripe\Subscription;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: ' . APP_URL);
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Authorization');

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
    
    if ($decoded->user_type !== 'provider') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied']);
        exit;
    }

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

    // Get cancelled subscription
    $stmt = $conn->prepare("
        SELECT ps.*, sp.name as plan_name
        FROM provider_subscriptions ps
        JOIN subscription_plans sp ON ps.plan_id = sp.id
        WHERE ps.provider_id = ? AND ps.cancel_at_period_end = 1
        ORDER BY ps.created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$provider['id']]);
    $subscription = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$subscription) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'No cancelled subscription found']);
        exit;
    }

    // Begin transaction
    $conn->beginTransaction();

    // Initialize Stripe
    Stripe::setApiKey(STRIPE_SECRET_KEY);

    // Update Stripe subscription
    $stripeSubscription = Subscription::update(
        $subscription['stripe_subscription_id'],
        ['cancel_at_period_end' => false]
    );

    // Update local subscription
    $stmt = $conn->prepare("
        UPDATE provider_subscriptions 
        SET cancel_at_period_end = 0,
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$subscription['id']]);

    // Log activity
    logActivity($conn, $decoded->user_id, 'subscription_reactivated', 
        "Reactivated {$subscription['plan_name']} plan");

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Subscription reactivated successfully'
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
    error_log("Reactivate subscription error: " . $e->getMessage());
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