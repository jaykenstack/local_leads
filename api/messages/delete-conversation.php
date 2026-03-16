<?php
/**
 * Delete Conversation
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

    if (!$input || empty($input['conversation_id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Conversation ID required']);
        exit;
    }

    $conversationId = (int)$input['conversation_id'];

    $db = new Database();
    $conn = $db->getConnection();

    // Verify user has access to this conversation
    $stmt = $conn->prepare("
        SELECT id 
        FROM conversations 
        WHERE id = ? 
        AND (
            customer_id = ? 
            OR provider_id = (
                SELECT id FROM provider_profiles WHERE user_id = ?
            )
        )
    ");
    $stmt->execute([$conversationId, $decoded->user_id, $decoded->user_id]);
    
    if (!$stmt->fetch()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied']);
        exit;
    }

    // Begin transaction
    $conn->beginTransaction();

    // Delete all messages in conversation
    $stmt = $conn->prepare("DELETE FROM messages WHERE conversation_id = ?");
    $stmt->execute([$conversationId]);

    // Delete conversation
    $stmt = $conn->prepare("DELETE FROM conversations WHERE id = ?");
    $stmt->execute([$conversationId]);

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Conversation deleted successfully'
    ]);

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log("Delete conversation error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred']);
}
?>