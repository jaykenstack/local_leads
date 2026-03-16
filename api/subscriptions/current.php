<?php
/**
 * Get Current Provider Subscription
 */

require_once '../../config/database.php';
require_once '../../config/app.php';
require_once '../../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: ' . APP_URL);
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Authorization');

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

    $db = new Database();
    $conn = $db->getConnection();

    // Get provider ID from user ID
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
        SELECT 
            ps.*,
            sp.name as plan_name,
            sp.price_monthly,
            sp.price_yearly,
            sp.leads_per_month,
            sp.featured_listing,
            sp.priority_support,
            sp.analytics_access,
            sp.api_access,
            sp.max_service_areas,
            sp.max_team_members,
            sp.commission_rate,
            sp.badge_type,
            pm.id as payment_method_id,
            pm.card_brand,
            pm.card_last4,
            pm.card_exp_month,
            pm.card_exp_year
        FROM provider_subscriptions ps
        JOIN subscription_plans sp ON ps.plan_id = sp.id
        LEFT JOIN customer_payment_methods pm ON pm.id = ps.payment_method_id
        WHERE ps.provider_id = ? 
        AND ps.status IN ('active', 'trialing', 'past_due')
        ORDER BY ps.created_at DESC
        LIMIT 1
    ");
    
    $stmt->execute([$provider['id']]);
    $subscription = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$subscription) {
        echo json_encode([
            'success' => true,
            'subscription' => null,
            'message' => 'No active subscription found'
        ]);
        exit;
    }

    // Calculate days remaining
    $now = new DateTime();
    $end = new DateTime($subscription['current_period_end']);
    $interval = $now->diff($end);
    $subscription['days_remaining'] = $interval->days;
    $subscription['is_expiring_soon'] = $interval->days <= 7;

    // Format dates
    $subscription['start_date_formatted'] = date('M j, Y', strtotime($subscription['current_period_start']));
    $subscription['end_date_formatted'] = date('M j, Y', strtotime($subscription['current_period_end']));
    
    // Format prices
    $subscription['price_formatted'] = '$' . number_format($subscription['price_monthly'] / 100, 2);

    // Get usage stats
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as leads_used,
            SUM(CASE WHEN viewed_at IS NOT NULL THEN 1 ELSE 0 END) as viewed_leads,
            SUM(CASE WHEN converted = 1 THEN 1 ELSE 0 END) as converted_leads
        FROM lead_delivery
        WHERE provider_id = ? 
        AND delivered_at >= ?
    ");
    $stmt->execute([$provider['id'], $subscription['current_period_start']]);
    $usage = $stmt->fetch(PDO::FETCH_ASSOC);

    $subscription['usage'] = [
        'leads_used' => (int)$usage['leads_used'],
        'leads_limit' => $subscription['leads_per_month'] == -1 ? 'Unlimited' : (int)$subscription['leads_per_month'],
        'viewed_leads' => (int)$usage['viewed_leads'],
        'converted_leads' => (int)$usage['converted_leads'],
        'conversion_rate' => $usage['leads_used'] > 0 ? round(($usage['converted_leads'] / $usage['leads_used']) * 100, 1) : 0
    ];

    // Get payment method
    if ($subscription['payment_method_id']) {
        $subscription['payment_method'] = [
            'id' => $subscription['payment_method_id'],
            'card_brand' => $subscription['card_brand'],
            'card_last4' => $subscription['card_last4'],
            'card_exp_month' => $subscription['card_exp_month'],
            'card_exp_year' => $subscription['card_exp_year']
        ];
    } else {
        $subscription['payment_method'] = null;
    }

    // Remove redundant fields
    unset($subscription['payment_method_id']);
    unset($subscription['card_brand']);
    unset($subscription['card_last4']);
    unset($subscription['card_exp_month']);
    unset($subscription['card_exp_year']);

    echo json_encode([
        'success' => true,
        'subscription' => $subscription
    ]);

} catch (ExpiredException $e) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Token expired']);
} catch (Exception $e) {
    error_log("Get current subscription error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred']);
}
?>