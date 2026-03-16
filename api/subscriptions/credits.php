<?php
/**
 * Get Credit Balance and Usage
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
        SELECT lead_credits_remaining, lead_credits_used,
               current_period_start, current_period_end
        FROM provider_subscriptions
        WHERE provider_id = ? AND status IN ('active', 'trialing')
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$provider['id']]);
    $subscription = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$subscription) {
        echo json_encode([
            'success' => true,
            'credits' => [
                'balance' => 0,
                'used' => 0,
                'expiring' => []
            ]
        ]);
        exit;
    }

    // Get expiring credits
    $stmt = $conn->prepare("
        SELECT id, amount, expires_at, created_at
        FROM credit_purchases
        WHERE provider_id = ? 
        AND expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 30 DAY)
        AND remaining > 0
        ORDER BY expires_at ASC
    ");
    $stmt->execute([$provider['id']]);
    $expiring = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get credit usage history
    $stmt = $conn->prepare("
        SELECT 
            ld.*,
            sr.title as lead_title,
            sr.description as lead_description
        FROM lead_delivery ld
        JOIN service_requests sr ON ld.lead_id = sr.id
        WHERE ld.provider_id = ? AND ld.credit_used = 1
        ORDER BY ld.delivered_at DESC
        LIMIT 20
    ");
    $stmt->execute([$provider['id']]);
    $usage = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get credit purchases
    $stmt = $conn->prepare("
        SELECT *
        FROM credit_purchases
        WHERE provider_id = ?
        ORDER BY created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$provider['id']]);
    $purchases = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate next billing credit
    $nextBillingCredit = 0;
    if ($subscription['lead_credits_remaining'] > 0) {
        $nextBillingCredit = min($subscription['lead_credits_remaining'], 50); // Cap at 50
    }

    $response = [
        'success' => true,
        'credits' => [
            'balance' => (int)$subscription['lead_credits_remaining'],
            'used' => (int)$subscription['lead_credits_used'],
            'next_billing_credit' => $nextBillingCredit,
            'period_start' => $subscription['current_period_start'],
            'period_end' => $subscription['current_period_end'],
            'expiring' => array_map(function($item) {
                return [
                    'id' => $item['id'],
                    'amount' => (int)$item['amount'],
                    'expires_at' => $item['expires_at'],
                    'days_until_expiry' => floor((strtotime($item['expires_at']) - time()) / 86400)
                ];
            }, $expiring),
            'recent_usage' => array_map(function($item) {
                return [
                    'id' => $item['id'],
                    'lead_id' => $item['lead_id'],
                    'lead_title' => $item['lead_title'],
                    'delivered_at' => $item['delivered_at'],
                    'viewed_at' => $item['viewed_at'],
                    'cost' => $item['cost'] / 100 // Convert cents to dollars
                ];
            }, $usage),
            'purchases' => array_map(function($item) {
                return [
                    'id' => $item['id'],
                    'amount' => $item['amount'],
                    'price' => $item['total_amount'] / 100,
                    'price_per_credit' => $item['price_per_credit'] / 100,
                    'created_at' => $item['created_at']
                ];
            }, $purchases)
        ]
    ];

    echo json_encode($response);

} catch (Exception $e) {
    error_log("Get credits error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred']);
}
?>