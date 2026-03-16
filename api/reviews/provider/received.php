<?php
/**
 * Get Reviews Received by Provider
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

    // Get reviews received
    $stmt = $conn->prepare("
        SELECT 
            r.*,
            c.id as customer_id,
            u.first_name as customer_first_name,
            u.last_name as customer_last_name,
            u.avatar_url as customer_avatar,
            sr.title as request_title,
            sr.description as request_description,
            s.name as service_name
        FROM reviews r
        JOIN customer_profiles c ON r.customer_id = c.id
        JOIN users u ON c.user_id = u.id
        JOIN service_requests sr ON r.request_id = sr.id
        JOIN services s ON sr.service_id = s.id
        WHERE r.provider_id = ?
        ORDER BY 
            CASE WHEN r.response_from_provider IS NULL THEN 0 ELSE 1 END,
            r.created_at DESC
    ");
    $stmt->execute([$provider['id']]);
    $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($reviews as &$review) {
        // Get photos
        $stmt = $conn->prepare("
            SELECT photo_url FROM review_photos WHERE review_id = ?
        ");
        $stmt->execute([$review['id']]);
        $review['photos'] = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Format customer name
        $review['customer_name'] = $review['anonymous'] 
            ? 'Anonymous' 
            : $review['customer_first_name'] . ' ' . substr($review['customer_last_name'], 0, 1) . '.';

        // Format dates
        $review['created_at_formatted'] = date('M j, Y', strtotime($review['created_at']));
        if ($review['response_date']) {
            $review['response_date_formatted'] = date('M j, Y', strtotime($review['response_date']));
        }

        // Calculate response time
        if ($review['response_date']) {
            $responseTime = strtotime($review['response_date']) - strtotime($review['created_at']);
            $review['response_time_hours'] = round($responseTime / 3600, 1);
        }

        // Check if needs response
        $review['needs_response'] = !$review['response_from_provider'] && 
            (time() - strtotime($review['created_at'])) < 7 * 24 * 60 * 60; // Within 7 days
    }

    echo json_encode([
        'success' => true,
        'reviews' => $reviews
    ]);

} catch (Exception $e) {
    error_log("Get received reviews error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred']);
}
?>