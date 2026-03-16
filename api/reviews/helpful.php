<?php
/**
 * Mark Review as Helpful/Not Helpful
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
    $voteType = $input['vote_type'] ?? 'helpful'; // 'helpful' or 'unhelpful'

    $db = new Database();
    $conn = $db->getConnection();

    // Check if user already voted
    $stmt = $conn->prepare("
        SELECT * FROM review_votes 
        WHERE review_id = ? AND user_id = ?
    ");
    $stmt->execute([$reviewId, $decoded->user_id]);
    $existingVote = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existingVote) {
        if ($existingVote['vote_type'] === $voteType) {
            // Remove vote (toggle off)
            $stmt = $conn->prepare("
                DELETE FROM review_votes 
                WHERE review_id = ? AND user_id = ?
            ");
            $stmt->execute([$reviewId, $decoded->user_id]);
            $message = 'Vote removed';
        } else {
            // Update vote
            $stmt = $conn->prepare("
                UPDATE review_votes 
                SET vote_type = ?, updated_at = NOW()
                WHERE review_id = ? AND user_id = ?
            ");
            $stmt->execute([$voteType, $reviewId, $decoded->user_id]);
            $message = 'Vote updated';
        }
    } else {
        // Insert new vote
        $stmt = $conn->prepare("
            INSERT INTO review_votes (review_id, user_id, vote_type, created_at)
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([$reviewId, $decoded->user_id, $voteType]);
        $message = 'Vote recorded';
    }

    // Get updated counts
    $stmt = $conn->prepare("
        SELECT 
            SUM(CASE WHEN vote_type = 'helpful' THEN 1 ELSE 0 END) as helpful_count,
            SUM(CASE WHEN vote_type = 'unhelpful' THEN 1 ELSE 0 END) as unhelpful_count
        FROM review_votes
        WHERE review_id = ?
    ");
    $stmt->execute([$reviewId]);
    $counts = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'message' => $message,
        'helpful_count' => (int)$counts['helpful_count'],
        'unhelpful_count' => (int)$counts['unhelpful_count']
    ]);

} catch (Exception $e) {
    error_log("Helpful vote error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred']);
}
?>