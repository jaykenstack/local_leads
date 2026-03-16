<?php
/**
 * Reset Password API Endpoint
 */

require_once '../../config/database.php';
require_once '../../config/app.php';

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

if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid input']);
    exit;
}

$token = $input['token'] ?? '';
$password = $input['password'] ?? '';

if (empty($token) || empty($password)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Token and password required']);
    exit;
}

// Validate password strength
if (strlen($password) < 8) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters']);
    exit;
}

if (!preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password) || !preg_match('/[^a-zA-Z0-9]/', $password)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Password must contain uppercase, number, and special character']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();

    // Find user with valid reset token
    $stmt = $conn->prepare("
        SELECT id, email, first_name 
        FROM users 
        WHERE reset_token = ? AND reset_token_expires > NOW()
    ");
    $stmt->execute([$token]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid or expired reset token']);
        exit;
    }

    // Hash new password
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    // Update password and clear reset token
    $stmt = $conn->prepare("
        UPDATE users 
        SET password_hash = ?,
            reset_token = NULL,
            reset_token_expires = NULL,
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$passwordHash, $user['id']]);

    // Log password reset
    logActivity($conn, $user['id'], 'reset_password', 'Password reset completed');

    echo json_encode(['success' => true, 'message' => 'Password reset successfully']);

} catch (PDOException $e) {
    error_log("Reset password error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
}
?>