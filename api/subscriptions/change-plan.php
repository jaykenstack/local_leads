<?php
/**
 * Change Subscription Plan (Upgrade/Downgrade)
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
    
    if ($decoded->user_type !== 'provider') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input || empty($input['plan_id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Plan ID required']);
        exit;
    }

    $newPlanId = (int)$input['plan_id'];
    $immediate = isset($input['immediate']) ? (bool)$input['immediate'] : false;

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

    // Get current subscription
    $stmt = $conn->prepare("
        SELECT ps.*, sp.name as plan_name, sp.price_monthly, sp.price_yearly,
               sp.leads_per_month
        FROM provider_subscriptions ps
        JOIN subscription_plans sp ON ps.plan_id = sp.id
        WHERE ps.provider_id = ? AND ps.status = 'active'
        ORDER BY ps.created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$provider['id']]);
    $currentSubscription = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$currentSubscription) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'No active subscription found']);
        exit;
    }

    // Get new plan details
    $stmt = $conn->prepare("
        SELECT * FROM subscription_plans 
        WHERE id = ? AND is_active = 1
    ");
    $stmt->execute([$newPlanId]);
    $newPlan = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$newPlan) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid plan selected']);
        exit;
    }

    // Begin transaction
    $conn->beginTransaction();

    // Initialize Stripe
    Stripe::setApiKey(STRIPE_SECRET_KEY);

    // Update Stripe subscription
    $stripeSubscription = Subscription::retrieve($currentSubscription['stripe_subscription_id']);

    // Calculate new price based on billing cycle
    $newPrice = ($currentSubscription['billing_cycle'] === 'yearly' && $newPlan['price_yearly'])
        ? $newPlan['price_yearly']
        : $newPlan['price_monthly'];

    // Update subscription item
    $subscriptionItemId = $stripeSubscription->items->data[0]->id;
    
    $updateParams = [
        'items' => [
            [
                'id' => $subscriptionItemId,
                'price_data' => [
                    'currency' => 'usd',
                    'product_data' => [
                        'name' => $newPlan['name'] . ' Plan'
                    ],
                    'unit_amount' => $newPrice,
                    'recurring' => [
                        'interval' => $currentSubscription['billing_cycle'] === 'yearly' ? 'year' : 'month'
                    ]
                ]
            ]
        ],
        'proration_behavior' => $immediate ? 'always_invoice' : 'create_prorations',
        'payment_behavior' => 'default_incomplete'
    ];

    if (!$immediate) {
        $updateParams['billing_cycle_anchor'] = 'unchanged';
    }

    $updatedSubscription = Subscription::update(
        $currentSubscription['stripe_subscription_id'],
        $updateParams
    );

    // Update local subscription
    $leadCredits = $newPlan['leads_per_month'] == -1 ? 999999 : $newPlan['leads_per_month'];

    $stmt = $conn->prepare("
        UPDATE provider_subscriptions 
        SET plan_id = ?,
            lead_credits_remaining = lead_credits_remaining + ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$newPlanId, $leadCredits, $currentSubscription['id']]);

    // Log activity
    logActivity($conn, $decoded->user_id, 'plan_changed', 
        "Changed from {$currentSubscription['plan_name']} to {$newPlan['name']}");

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => $immediate ? 'Plan changed immediately' : 'Plan will change at end of billing period',
        'new_plan' => $newPlan['name'],
        'effective_date' => $immediate ? 'Immediately' : $currentSubscription['current_period_end']
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
    error_log("Change plan error: " . $e->getMessage());
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