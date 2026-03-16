<?php
/**
 * Report a Review
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

    if (!$input || empty($input['review_id']) || empty($input['reason'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Review ID and reason required']);
        exit;
    }

    $reviewId = (int)$input['review_id'];
    $reason = $input['reason'];
    $description = $input['description'] ?? null;

    // Validate reason
    $validReasons = ['spam', 'inappropriate', 'fake', 'conflict', 'other'];
    if (!in_array($reason, $validReasons)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid reason']);
        exit;
    }

    $db = new Database();
    $conn = $db->getConnection();

    // Check if user already reported this review
    $stmt = $conn->prepare("
        SELECT * FROM review_reports 
        WHERE review_id = ? AND reporter_id = ?
    ");
    $stmt->execute([$reviewId, $decoded->user_id]);
    
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'You have already reported this review']);
        exit;
    }

    // Insert report
    $stmt = $conn->prepare("
        INSERT INTO review_reports (
            review_id, reporter_id, reason, description, status, created_at
        ) VALUES (?, ?, ?, ?, 'pending', NOW())
    ");
    $stmt->execute([$reviewId, $decoded->user_id, $reason, $description]);

    // Update review reported count
    $stmt = $conn->prepare("
        UPDATE reviews 
        SET reported_count = reported_count + 1
        WHERE id = ?
    ");
    $stmt->execute([$reviewId]);

    // If multiple reports, flag for moderation
    $stmt = $conn->prepare("
        SELECT COUNT(*) as report_count FROM review_reports WHERE review_id = ?
    ");
    $stmt->execute([$reviewId]);
    $reportCount = $stmt->fetchColumn();

    if ($reportCount >= 3) {
        $stmt = $conn->prepare("
            UPDATE reviews SET status = 'flagged' WHERE id = ?
        ");
        $stmt->execute([$reviewId]);

        // Notify admin
        notifyAdmin($reviewId, $reportCount);
    }

    // Log activity
    logActivity($conn, $decoded->user_id, 'review_reported', 
        "Reported review #{$reviewId} for {$reason}");

    echo json_encode([
        'success' => true,
        'message' => 'Review reported successfully. Our team will review it.'
    ]);

} catch (Exception $e) {
    error_log("Report review error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred']);
}

/**
 * Notify admin about flagged review
 */
function notifyAdmin($reviewId, $reportCount) {
    // Implementation depends on your notification system
    // Could be email, database notification, Slack, etc.
    error_log("Review #{$reviewId} flagged with {$reportCount} reports");
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