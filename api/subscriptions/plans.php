<?php
/**
 * Get Available Subscription Plans
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

try {
    $db = new Database();
    $conn = $db->getConnection();

    // Get all active subscription plans
    $stmt = $conn->prepare("
        SELECT 
            id,
            name,
            slug,
            description,
            price_monthly,
            price_yearly,
            leads_per_month,
            lead_credit_price,
            featured_listing,
            priority_support,
            analytics_access,
            api_access,
            max_service_areas,
            max_team_members,
            commission_rate,
            badge_type,
            popular,
            sort_order
        FROM subscription_plans
        WHERE is_active = 1
        ORDER BY sort_order ASC, price_monthly ASC
    ");
    
    $stmt->execute();
    $plans = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Mark popular plan (usually the middle one or explicitly marked)
    foreach ($plans as &$plan) {
        $plan['popular'] = (bool)$plan['popular'];
        $plan['featured_listing'] = (bool)$plan['featured_listing'];
        $plan['priority_support'] = (bool)$plan['priority_support'];
        $plan['analytics_access'] = (bool)$plan['analytics_access'];
        $plan['api_access'] = (bool)$plan['api_access'];
        
        // Format prices
        $plan['price_monthly_formatted'] = '$' . number_format($plan['price_monthly'] / 100, 2);
        $plan['price_yearly_formatted'] = $plan['price_yearly'] ? '$' . number_format($plan['price_yearly'] / 100, 2) : null;
        
        // Calculate savings for yearly
        if ($plan['price_yearly']) {
            $monthlyTotal = $plan['price_monthly'] * 12;
            $savings = $monthlyTotal - $plan['price_yearly'];
            $savingsPercent = round(($savings / $monthlyTotal) * 100);
            $plan['yearly_savings'] = $savingsPercent;
        }
    }

    echo json_encode([
        'success' => true,
        'plans' => $plans
    ]);

} catch (PDOException $e) {
    error_log("Get plans error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
} catch (Exception $e) {
    error_log("Get plans error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred']);
}
?>