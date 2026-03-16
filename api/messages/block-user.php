<?php
/**
 * Block User from Messaging
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

    if (!$input || empty($input['user_id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'User ID required']);
        exit;
    }

    $blockedUserId = (int)$input['user_id'];

    $db = new Database();
    $conn = $db->getConnection();

    // Check if already blocked
    $stmt = $conn->prepare("
        SELECT id FROM user_blocks 
        WHERE user_id = ? AND blocked_user_id = ?
    ");
    $stmt->execute([$decoded->user_id, $blockedUserId]);
    
    if ($stmt->fetch()) {
        echo json_encode([
            'success' => true,
            'message' => 'User already blocked'
        ]);
        exit;
    }

    // Insert block record
    $stmt = $conn->prepare("
        INSERT INTO user_blocks (user_id, blocked_user_id, created_at)
        VALUES (?, ?, NOW())
    ");
    $stmt->execute([$decoded->user_id, $blockedUserId]);

    echo json_encode([
        'success' => true,
        'message' => 'User blocked successfully'
    ]);

} catch (Exception $e) {
    error_log("Block user error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred']);
}
?>