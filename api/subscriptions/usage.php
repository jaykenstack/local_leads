<?php
/**
 * Get Subscription Usage Statistics
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

    $db = new Database();
    $conn = $db->getConnection();

    if ($decoded->user_type === 'provider') {
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
            SELECT ps.*, sp.leads_per_month, sp.max_service_areas, sp.max_team_members
            FROM provider_subscriptions ps
            JOIN subscription_plans sp ON ps.plan_id = sp.id
            WHERE ps.provider_id = ? AND ps.status IN ('active', 'trialing')
            ORDER BY ps.created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$provider['id']]);
        $subscription = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$subscription) {
            echo json_encode([
                'success' => true,
                'usage' => null
            ]);
            exit;
        }

        // Get lead usage
        $stmt = $conn->prepare("
            SELECT 
                COUNT(*) as total_leads,
                SUM(CASE WHEN viewed_at IS NOT NULL THEN 1 ELSE 0 END) as viewed_leads,
                SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END) as clicked_leads,
                SUM(CASE WHEN converted = 1 THEN 1 ELSE 0 END) as converted_leads
            FROM lead_delivery
            WHERE provider_id = ? 
            AND delivered_at >= ?
        ");
        $stmt->execute([$provider['id'], $subscription['current_period_start']]);
        $leadStats = $stmt->fetch(PDO::FETCH_ASSOC);

        // Get service areas count
        $stmt = $conn->prepare("
            SELECT COUNT(*) as service_areas
            FROM provider_locations
            WHERE provider_id = ?
        ");
        $stmt->execute([$provider['id']]);
        $serviceAreas = $stmt->fetch(PDO::FETCH_ASSOC);

        // Get team members count
        $stmt = $conn->prepare("
            SELECT COUNT(*) as team_members
            FROM provider_team
            WHERE provider_id = ? AND status = 'active'
        ");
        $stmt->execute([$provider['id']]);
        $teamMembers = $stmt->fetch(PDO::FETCH_ASSOC);

        // Get monthly trends
        $stmt = $conn->prepare("
            SELECT 
                DATE_FORMAT(delivered_at, '%Y-%m') as month,
                COUNT(*) as leads,
                SUM(CASE WHEN converted = 1 THEN 1 ELSE 0 END) as conversions,
                SUM(cost) as cost
            FROM lead_delivery
            WHERE provider_id = ? 
            AND delivered_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
            GROUP BY DATE_FORMAT(delivered_at, '%Y-%m')
            ORDER BY month DESC
        ");
        $stmt->execute([$provider['id']]);
        $trends = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $usage = [
            'current_period' => [
                'leads' => (int)$leadStats['total_leads'],
                'leads_limit' => $subscription['leads_per_month'] == -1 ? 'Unlimited' : (int)$subscription['leads_per_month'],
                'viewed_leads' => (int)$leadStats['viewed_leads'],
                'clicked_leads' => (int)$leadStats['clicked_leads'],
                'converted_leads' => (int)$leadStats['converted_leads'],
                'conversion_rate' => $leadStats['total_leads'] > 0 
                    ? round(($leadStats['converted_leads'] / $leadStats['total_leads']) * 100, 1) 
                    : 0,
                'service_areas' => (int)$serviceAreas['service_areas'],
                'service_areas_limit' => $subscription['max_service_areas'] == -1 ? 'Unlimited' : (int)$subscription['max_service_areas'],
                'team_members' => (int)$teamMembers['team_members'],
                'team_members_limit' => $subscription['max_team_members'] == -1 ? 'Unlimited' : (int)$subscription['max_team_members']
            ],
            'trends' => array_map(function($item) {
                return [
                    'month' => $item['month'],
                    'leads' => (int)$item['leads'],
                    'conversions' => (int)$item['conversions'],
                    'cost' => $item['cost'] / 100
                ];
            }, $trends)
        ];

        echo json_encode([
            'success' => true,
            'usage' => $usage
        ]);

    } else {
        // Customer usage stats
        $stmt = $conn->prepare("SELECT id FROM customer_profiles WHERE user_id = ?");
        $stmt->execute([$decoded->user_id]);
        $customer = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$customer) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Customer profile not found']);
            exit;
        }

        // Get customer request stats
        $stmt = $conn->prepare("
            SELECT 
                COUNT(*) as total_requests,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
                AVG(EXTRACT(EPOCH FROM (assigned_at - created_at))/60) as avg_response_time
            FROM service_requests
            WHERE customer_id = ?
        ");
        $stmt->execute([$customer['id']]);
        $requestStats = $stmt->fetch(PDO::FETCH_ASSOC);

        // Get spending
        $stmt = $conn->prepare("
            SELECT 
                SUM(total_amount) as total_spent,
                AVG(total_amount) as avg_spent,
                COUNT(*) as total_payments
            FROM service_fees
            WHERE customer_id = ? AND status = 'succeeded'
        ");
        $stmt->execute([$customer['id']]);
        $spending = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'usage' => [
                'requests' => [
                    'total' => (int)$requestStats['total_requests'],
                    'completed' => (int)$requestStats['completed'],
                    'pending' => (int)$requestStats['pending'],
                    'cancelled' => (int)$requestStats['cancelled'],
                    'avg_response_time' => round((float)$requestStats['avg_response_time'])
                ],
                'spending' => [
                    'total' => $spending['total_spent'] / 100,
                    'average' => $spending['avg_spent'] / 100,
                    'total_payments' => (int)$spending['total_payments']
                ]
            ]
        ]);
    }

} catch (Exception $e) {
    error_log("Get usage error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred']);
}
?>