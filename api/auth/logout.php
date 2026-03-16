<?php
/**
 * Logout API Endpoint
 */

require_once '../../config/database.php';

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
    $db = new Database();
    $conn = $db->getConnection();

    // Delete refresh token from database
    $stmt = $conn->prepare("DELETE FROM refresh_tokens WHERE token = ?");
    $stmt->execute([$token]);

    // Also delete any expired tokens
    $stmt = $conn->prepare("DELETE FROM refresh_tokens WHERE expires_at < NOW()");
    $stmt->execute();

    echo json_encode(['success' => true, 'message' => 'Logged out successfully']);

} catch (PDOException $e) {
    error_log("Logout error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
}
?>