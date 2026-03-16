<?php
/**
 * Get Customer's Pending Reviews (Completed Services Not Reviewed)
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

    // Get customer ID
    $stmt = $conn->prepare("SELECT id FROM customer_profiles WHERE user_id = ?");
    $stmt->execute([$decoded->user_id]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$customer) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Customer profile not found']);
        exit;
    }

    // Get completed services without reviews
    $stmt = $conn->prepare("
        SELECT 
            sr.id as request_id,
            sr.title,
            sr.description,
            sr.completed_at,
            p.id as provider_id,
            p.business_name as provider_name,
            s.name as service_name
        FROM service_requests sr
        JOIN provider_profiles p ON sr.provider_id = p.id
        JOIN services s ON sr.service_id = s.id
        LEFT JOIN reviews r ON sr.id = r.request_id
        WHERE sr.customer_id = ? 
            AND sr.status = 'completed'
            AND r.id IS NULL
        ORDER BY sr.completed_at DESC
        LIMIT 20
    ");
    $stmt->execute([$customer['id']]);
    $pending = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($pending as &$item) {
        $item['completed_at_formatted'] = date('M j, Y', strtotime($item['completed_at']));
        $item['days_ago'] = floor((time() - strtotime($item['completed_at'])) / (24 * 60 * 60));
    }

    echo json_encode([
        'success' => true,
        'pending' => $pending,
        'count' => count($pending)
    ]);

} catch (Exception $e) {
    error_log("Get pending reviews error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred']);
}
?>