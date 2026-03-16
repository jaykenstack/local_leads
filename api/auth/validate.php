<?php
/**
 * Token Validation API Endpoint
 */

require_once '../../config/database.php';
require_once '../../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: ' . APP_URL);
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Get authorization header
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? '';

if (!preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'No token provided']);
    exit;
}

$token = $matches[1];

try {
    // Decode JWT
    $decoded = JWT::decode($token, new Key(JWT_SECRET, 'HS256'));
    
    $db = new Database();
    $conn = $db->getConnection();

    // Get user from database
    $stmt = $conn->prepare("
        SELECT id, email, first_name, last_name, user_type, avatar_url, is_active
        FROM users 
        WHERE id = ?
    ");
    $stmt->execute([$decoded->user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !$user['is_active']) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'User not found or inactive']);
        exit;
    }

    // Check if token exists in refresh tokens (optional)
    $stmt = $conn->prepare("
        SELECT id FROM refresh_tokens 
        WHERE user_id = ? AND expires_at > NOW()
    ");
    $stmt->execute([$user['id']]);
    
    $hasValidRefreshToken = $stmt->fetch() ? true : false;

    echo json_encode([
        'success' => true,
        'valid' => true,
        'has_refresh_token' => $hasValidRefreshToken,
        'user' => [
            'id' => $user['id'],
            'email' => $user['email'],
            'first_name' => $user['first_name'],
            'last_name' => $user['last_name'],
            'user_type' => $user['user_type'],
            'avatar' => $user['avatar_url'] ?: '/public/assets/images/avatars/default-avatar.jpg'
        ]
    ]);

} catch (ExpiredException $e) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Token expired', 'code' => 'token_expired']);
} catch (SignatureInvalidException $e) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid token signature']);
} catch (Exception $e) {
    error_log("Token validation error: " . $e->getMessage());
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Invalid token']);
}
?>