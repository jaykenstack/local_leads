<?php
/**
 * Delete Review
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

    $db = new Database();
    $conn = $db->getConnection();

    // Get customer ID
    $stmt = $conn->prepare("SELECT id FROM customer_profiles WHERE user_id = ?");
    $stmt->execute([$decoded->user_id]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);

    // Check if user is admin (allow admin to delete any review)
    $isAdmin = false;
    $stmt = $conn->prepare("SELECT is_admin FROM users WHERE id = ?");
    $stmt->execute([$decoded->user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    $isAdmin = $user && $user['is_admin'] == 1;

    // Verify review belongs to this customer OR user is admin
    if (!$isAdmin) {
        if (!$customer) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Customer profile not found']);
            exit;
        }

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

        // Check if review is too old to delete (e.g., 7 days)
        $createdAt = new DateTime($review['created_at']);
        $now = new DateTime();
        $daysSinceCreation = $now->diff($createdAt)->days;

        if ($daysSinceCreation > 7) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Reviews cannot be deleted after 7 days']);
            exit;
        }
    }

    // Begin transaction
    $conn->beginTransaction();

    // Get provider ID before deleting
    $stmt = $conn->prepare("SELECT provider_id FROM reviews WHERE id = ?");
    $stmt->execute([$reviewId]);
    $providerId = $stmt->fetchColumn();

    // Delete review photos
    $stmt = $conn->prepare("DELETE FROM review_photos WHERE review_id = ?");
    $stmt->execute([$reviewId]);

    // Delete review votes
    $stmt = $conn->prepare("DELETE FROM review_votes WHERE review_id = ?");
    $stmt->execute([$reviewId]);

    // Delete review reports
    $stmt = $conn->prepare("DELETE FROM review_reports WHERE review_id = ?");
    $stmt->execute([$reviewId]);

    // Delete the review
    $stmt = $conn->prepare("DELETE FROM reviews WHERE id = ?");
    $stmt->execute([$reviewId]);

    // Update provider's review stats
    if ($providerId) {
        $stmt = $conn->prepare("
            UPDATE provider_profiles 
            SET total_reviews = (
                SELECT COUNT(*) FROM reviews WHERE provider_id = ? AND status = 'published'
            ),
            average_rating = (
                SELECT COALESCE(AVG(overall_rating), 0) 
                FROM reviews 
                WHERE provider_id = ? AND status = 'published'
            )
            WHERE id = ?
        ");
        $stmt->execute([$providerId, $providerId, $providerId]);
    }

    // Log activity
    logActivity($conn, $decoded->user_id, 'review_deleted', "Deleted review #{$reviewId}");

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Review deleted successfully'
    ]);

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log("Delete review error: " . $e->getMessage());
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