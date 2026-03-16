<?php
/**
 * Mark Messages as Read
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

    if (!$input) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid input']);
        exit;
    }

    $conversationId = isset($input['conversation_id']) ? (int)$input['conversation_id'] : 0;
    $messageIds = isset($input['message_ids']) ? $input['message_ids'] : [];

    $db = new Database();
    $conn = $db->getConnection();

    if ($conversationId) {
        // Mark all messages in conversation as read
        $stmt = $conn->prepare("
            UPDATE messages 
            SET is_read = 1, read_at = NOW()
            WHERE conversation_id = ? 
                AND receiver_id = ? 
                AND is_read = 0
        ");
        $stmt->execute([$conversationId, $decoded->user_id]);
        
        $affectedRows = $stmt->rowCount();

        echo json_encode([
            'success' => true,
            'message' => 'Messages marked as read',
            'marked_count' => $affectedRows
        ]);

    } elseif (!empty($messageIds)) {
        // Mark specific messages as read
        $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
        
        $stmt = $conn->prepare("
            UPDATE messages 
            SET is_read = 1, read_at = NOW()
            WHERE id IN ({$placeholders}) 
                AND receiver_id = ? 
                AND is_read = 0
        ");
        
        $params = array_merge($messageIds, [$decoded->user_id]);
        $stmt->execute($params);
        
        $affectedRows = $stmt->rowCount();

        echo json_encode([
            'success' => true,
            'message' => 'Messages marked as read',
            'marked_count' => $affectedRows
        ]);

    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Conversation ID or message IDs required']);
    }

} catch (Exception $e) {
    error_log("Mark read error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred']);
}
?>