<?php
/**
 * Start New Conversation
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
    if (empty($input['provider_id']) || empty($input['message'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Provider ID and message required']);
        exit;
    }

    $providerId = (int)$input['provider_id'];
    $message = trim($input['message']);
    $requestId = isset($input['request_id']) ? (int)$input['request_id'] : null;

    if (empty($message)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Message cannot be empty']);
        exit;
    }

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

    // Get provider user ID for notifications
    $stmt = $conn->prepare("
        SELECT p.id, p.business_name, u.id as user_id
        FROM provider_profiles p
        JOIN users u ON p.user_id = u.id
        WHERE p.id = ?
    ");
    $stmt->execute([$providerId]);
    $provider = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$provider) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Provider not found']);
        exit;
    }

    // Begin transaction
    $conn->beginTransaction();

    // Check if conversation already exists
    $stmt = $conn->prepare("
        SELECT id FROM conversations 
        WHERE customer_id = ? AND provider_id = ?
    ");
    $stmt->execute([$decoded->user_id, $providerId]);
    $existingConversation = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existingConversation) {
        $conversationId = $existingConversation['id'];
    } else {
        // Create new conversation
        $stmt = $conn->prepare("
            INSERT INTO conversations (
                customer_id, provider_id, request_id, created_at, updated_at
            ) VALUES (?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([$decoded->user_id, $providerId, $requestId]);
        $conversationId = $conn->lastInsertId();
    }

    // Insert message
    $stmt = $conn->prepare("
        INSERT INTO messages (
            conversation_id, sender_id, receiver_id, 
            content, type, created_at
        ) VALUES (?, ?, ?, ?, 'text', NOW())
    ");

    $stmt->execute([
        $conversationId,
        $decoded->user_id,
        $provider['user_id'],
        $message
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

    // Create notification for provider
    $stmt = $conn->prepare("
        INSERT INTO notifications (
            user_id, type, title, message, data, created_at
        ) VALUES (
            ?, 'new_conversation', 'New Message',
            ?, ?, NOW()
        )
    ");

    $notificationMessage = "A customer has started a conversation with you";
    $notificationData = json_encode([
        'conversation_id' => $conversationId,
        'provider_id' => $providerId,
        'customer_id' => $decoded->user_id
    ]);

    $stmt->execute([
        $provider['user_id'],
        $notificationMessage,
        $notificationData
    ]);

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Conversation started successfully',
        'conversation_id' => $conversationId
    ]);

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log("Start conversation error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred']);
}
?>