<?php
/**
 * Email Verification API Endpoint
 */

require_once '../../config/database.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: ' . APP_URL);
header('Access-Control-Allow-Methods: GET');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$token = $_GET['token'] ?? '';

if (empty($token)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Verification token required']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();

    // Find user with this verification token
    $stmt = $conn->prepare("
        SELECT id, email, first_name, verification_token, email_verified
        FROM users 
        WHERE verification_token = ? AND email_verified = 0
    ");
    $stmt->execute([$token]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        // Check if already verified
        $stmt = $conn->prepare("
            SELECT id, email_verified FROM users WHERE verification_token = ?
        ");
        $stmt->execute([$token]);
        $alreadyVerified = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($alreadyVerified && $alreadyVerified['email_verified']) {
            echo json_encode([
                'success' => true,
                'message' => 'Email already verified',
                'already_verified' => true
            ]);
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid or expired verification token']);
        }
        exit;
    }

    // Update user as verified
    $stmt = $conn->prepare("
        UPDATE users 
        SET email_verified = 1, 
            verification_token = NULL,
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$user['id']]);

    // Log verification
    logActivity($conn, $user['id'], 'verify_email', 'Email address verified');

    echo json_encode([
        'success' => true,
        'message' => 'Email verified successfully',
        'user' => [
            'id' => $user['id'],
            'email' => $user['email'],
            'first_name' => $user['first_name']
        ]
    ]);

} catch (PDOException $e) {
    error_log("Email verification error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
}
?>