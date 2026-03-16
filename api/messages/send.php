<?php
/**
 * Send a New Message
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

    // Validate required fields
    if (empty($input['conversation_id']) || empty($input['content'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Conversation ID and content required']);
        exit;
    }

    $conversationId = (int)$input['conversation_id'];
    $content = trim($input['content']);
    $type = $input['type'] ?? 'text';
    $metadata = $input['metadata'] ?? null;

    if (empty($content) && $type === 'text') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Message content cannot be empty']);
        exit;
    }

    $db = new Database();
    $conn = $db->getConnection();

    // Verify user has access to this conversation and get receiver ID
    $stmt = $conn->prepare("
        SELECT c.*,
               CASE 
                   WHEN c.customer_id = ? THEN 'customer'
                   ELSE 'provider'
               END as sender_role,
               CASE 
                   WHEN c.customer_id = ? THEN 
                       (SELECT user_id FROM provider_profiles WHERE id = c.provider_id)
                   ELSE 
                       (SELECT user_id FROM customer_profiles WHERE id = c.customer_id)
               END as receiver_id
        FROM conversations c
        WHERE c.id = ? 
        AND (
            c.customer_id = ? 
            OR c.provider_id = (
                SELECT id FROM provider_profiles WHERE user_id = ?
            )
        )
    ");

    $stmt->execute([
        $decoded->user_id,
        $decoded->user_id,
        $conversationId,
        $decoded->user_id,
        $decoded->user_id
    ]);

    $conversation = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$conversation) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied']);
        exit;
    }

    // Begin transaction
    $conn->beginTransaction();

    // Insert message
    $stmt = $conn->prepare("
        INSERT INTO messages (
            conversation_id, sender_id, receiver_id, 
            content, type, metadata, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");

    $messageContent = $type === 'text' ? $content : json_encode($content);

    $stmt->execute([
        $conversationId,
        $decoded->user_id,
        $conversation['receiver_id'],
        $messageContent,
        $type,
        $metadata ? json_encode($metadata) : null
    ]);

    $messageId = $conn->lastInsertId();

    // Update conversation last activity
    $stmt = $conn->prepare("
        UPDATE conversations 
        SET updated_at = NOW(),
            last_message_id = ?
        WHERE id = ?
    ");
    $stmt->execute([$messageId, $conversationId]);

    // Get the created message
    $stmt = $conn->prepare("
        SELECT 
            m.*,
            CASE 
                WHEN m.sender_id = ? THEN 1 
                ELSE 0 
            END as is_me
        FROM messages m
        WHERE m.id = ?
    ");
    $stmt->execute([$decoded->user_id, $messageId]);
    $message = $stmt->fetch(PDO::FETCH_ASSOC);

    // Format message
    $message['created_at_formatted'] = date('g:i A', strtotime($message['created_at']));
    $message['is_me'] = (bool)$message['is_me'];
    $message['is_read'] = (bool)$message['is_read'];

    if ($message['type'] === 'file' || $message['type'] === 'image') {
        $message['content'] = json_decode($message['content'], true);
    }

    // Create notification for receiver
    $stmt = $conn->prepare("
        INSERT INTO notifications (
            user_id, type, title, message, data, created_at
        ) VALUES (
            ?, 'message', 'New Message',
            ?, ?, NOW()
        )
    ");

    $senderName = $decoded->user_type === 'provider' 
        ? "Service Provider" 
        : "Customer";

    $notificationMessage = "You have a new message from {$senderName}";
    $notificationData = json_encode([
        'conversation_id' => $conversationId,
        'message_id' => $messageId,
        'sender_id' => $decoded->user_id
    ]);

    $stmt->execute([
        $conversation['receiver_id'],
        $notificationMessage,
        $notificationData
    ]);

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Message sent successfully',
        'data' => $message
    ]);

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log("Send message error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred']);
}
?>