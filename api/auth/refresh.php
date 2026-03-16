<?php
/**
 * Token Refresh API Endpoint
 */

require_once '../../config/database.php';
require_once '../../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: ' . APP_URL);
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || empty($input['refresh_token'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Refresh token required']);
    exit;
}

$refreshToken = $input['refresh_token'];

try {
    $db = new Database();
    $conn = $db->getConnection();

    // Verify refresh token
    $stmt = $conn->prepare("
        SELECT rt.*, u.id as user_id, u.user_type, u.is_active
        FROM refresh_tokens rt
        JOIN users u ON rt.user_id = u.id
        WHERE rt.token = ? AND rt.expires_at > NOW()
    ");
    $stmt->execute([$refreshToken]);
    $tokenData = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tokenData) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid or expired refresh token']);
        exit;
    }

    if (!$tokenData['is_active']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Account is deactivated']);
        exit;
    }

    // Generate new access token
    $payload = [
        'user_id' => $tokenData['user_id'],
        'user_type' => $tokenData['user_type'],
        'iat' => time(),
        'exp' => time() + 3600, // 1 hour
        'jti' => bin2hex(random_bytes(16))
    ];
    
    $newAccessToken = JWT::encode($payload, JWT_SECRET, 'HS256');

    // Optionally rotate refresh token
    $rotateRefresh = isset($input['rotate']) ? (bool)$input['rotate'] : false;
    
    if ($rotateRefresh) {
        // Delete old refresh token
        $stmt = $conn->prepare("DELETE FROM refresh_tokens WHERE id = ?");
        $stmt->execute([$tokenData['id']]);

        // Create new refresh token
        $newRefreshToken = bin2hex(random_bytes(64));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));
        
        $stmt = $conn->prepare("
            INSERT INTO refresh_tokens (user_id, token, expires_at, created_at)
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([$tokenData['user_id'], $newRefreshToken, $expiresAt]);
    } else {
        $newRefreshToken = $refreshToken;
    }

    echo json_encode([
        'success' => true,
        'access_token' => $newAccessToken,
        'refresh_token' => $newRefreshToken,
        'token_type' => 'Bearer',
        'expires_in' => 3600
    ]);

} catch (PDOException $e) {
    error_log("Token refresh error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
} catch (Exception $e) {
    error_log("Token refresh error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred']);
}
?>