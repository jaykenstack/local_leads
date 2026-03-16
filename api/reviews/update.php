<?php
/**
 * Update Existing Review
 */

require_once '../../config/database.php';
require_once '../../config/app.php';
require_once '../../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

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

    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input || empty($input['review_id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Review ID required']);
        exit;
    }

    $reviewId = (int)$input['review_id'];
    $ratings = $input['ratings'] ?? null;
    $title = $input['title'] ?? null;
    $comment = $input['comment'] ?? null;
    $pros = $input['pros'] ?? null;
    $cons = $input['cons'] ?? null;

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

    // Verify review belongs to this customer
    $stmt = $conn->prepare("
        SELECT * FROM reviews 
        WHERE id = ? AND customer_id = ?
    ");
    $stmt->execute([$reviewId, $customer['id']]);
    $review = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$review) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Review not found']);
        exit;
    }

    // Check if review is too old to edit (e.g., 30 days)
    $createdAt = new DateTime($review['created_at']);
    $now = new DateTime();
    $daysSinceCreation = $now->diff($createdAt)->days;

    if ($daysSinceCreation > 30) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Reviews cannot be edited after 30 days']);
        exit;
    }

    // Begin transaction
    $conn->beginTransaction();

    // Build update query dynamically
    $updates = [];
    $params = [];

    if ($ratings) {
        if (isset($ratings['overall'])) {
            $updates[] = "overall_rating = ?";
            $params[] = $ratings['overall'];
        }
        if (isset($ratings['quality'])) {
            $updates[] = "quality_rating = ?";
            $params[] = $ratings['quality'];
        }
        if (isset($ratings['punctuality'])) {
            $updates[] = "punctuality_rating = ?";
            $params[] = $ratings['punctuality'];
        }
        if (isset($ratings['professionalism'])) {
            $updates[] = "professionalism_rating = ?";
            $params[] = $ratings['professionalism'];
        }
        if (isset($ratings['value'])) {
            $updates[] = "value_rating = ?";
            $params[] = $ratings['value'];
        }
        if (isset($ratings['communication'])) {
            $updates[] = "communication_rating = ?";
            $params[] = $ratings['communication'];
        }
    }

    if ($title !== null) {
        $updates[] = "title = ?";
        $params[] = $title;
    }

    if ($comment !== null) {
        $updates[] = "comment = ?";
        $params[] = $comment;
    }

    if ($pros !== null) {
        $updates[] = "pros = ?";
        $params[] = $pros;
    }

    if ($cons !== null) {
        $updates[] = "cons = ?";
        $params[] = $cons;
    }

    $updates[] = "updated_at = NOW()";

    if (!empty($updates)) {
        $params[] = $reviewId;
        $sql = "UPDATE reviews SET " . implode(", ", $updates) . " WHERE id = ?";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
    }

    // Log activity
    logActivity($conn, $decoded->user_id, 'review_updated', "Updated review #{$reviewId}");

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Review updated successfully'
    ]);

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log("Update review error: " . $e->getMessage());
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