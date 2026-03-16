<?php
/**
 * Get Reviews Pending Provider Response
 */

require_once '../../../config/database.php';
require_once '../../../config/app.php';
require_once '../../../vendor/autoload.php';

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

    // Get reviews without response (last 30 days)
    $stmt = $conn->prepare("
        SELECT 
            r.id,
            r.overall_rating,
            r.comment,
            r.created_at,
            u.first_name as customer_first_name,
            u.last_name as customer_last_name,
            r.anonymous
        FROM reviews r
        JOIN customer_profiles c ON r.customer_id = c.id
        JOIN users u ON c.user_id = u.id
        WHERE r.provider_id = ? 
            AND r.response_from_provider IS NULL
            AND r.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ORDER BY r.created_at DESC
    ");
    $stmt->execute([$provider['id']]);
    $pending = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($pending as &$review) {
        $review['customer_name'] = $review['anonymous'] 
            ? 'Anonymous Customer' 
            : $review['customer_first_name'] . ' ' . substr($review['customer_last_name'], 0, 1) . '.';
        
        $review['created_at_formatted'] = date('M j, Y', strtotime($review['created_at']));
        $review['days_ago'] = floor((time() - strtotime($review['created_at'])) / (24 * 60 * 60));
        
        // Urgency based on time elapsed
        if ($review['days_ago'] <= 2) {
            $review['urgency'] = 'normal';
        } elseif ($review['days_ago'] <= 5) {
            $review['urgency'] = 'urgent';
        } else {
            $review['urgency'] = 'critical';
        }
    }

    echo json_encode([
        'success' => true,
        'pending' => $pending,
        'count' => count($pending)
    ]);

} catch (Exception $e) {
    error_log("Get pending responses error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred']);
}
?>