<?php
/**
 * Get Customer's Written Reviews
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

    // Get customer's reviews
    $stmt = $conn->prepare("
        SELECT 
            r.*,
            p.business_name as provider_name,
            u.avatar_url as provider_avatar,
            s.name as service_name
        FROM reviews r
        JOIN provider_profiles p ON r.provider_id = p.id
        JOIN users u ON p.user_id = u.id
        JOIN service_requests sr ON r.request_id = sr.id
        JOIN services s ON sr.service_id = s.id
        WHERE r.customer_id = ?
        ORDER BY r.created_at DESC
    ");
    $stmt->execute([$customer['id']]);
    $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($reviews as &$review) {
        // Get photos
        $stmt = $conn->prepare("
            SELECT photo_url FROM review_photos WHERE review_id = ?
        ");
        $stmt->execute([$review['id']]);
        $review['photos'] = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Format date
        $review['created_at_formatted'] = date('M j, Y', strtotime($review['created_at']));
        $review['can_edit'] = (time() - strtotime($review['created_at'])) < 7 * 24 * 60 * 60; // 7 days
    }

    echo json_encode([
        'success' => true,
        'reviews' => $reviews
    ]);

} catch (Exception $e) {
    error_log("Get customer reviews error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred']);
}
?>