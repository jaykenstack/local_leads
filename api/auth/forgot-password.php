<?php
/**
 * Forgot Password API Endpoint
 */

require_once '../../config/database.php';
require_once '../../config/app.php';
require_once '../../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;

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

if (!$input || empty($input['email'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Email required']);
    exit;
}

$email = filter_var($input['email'], FILTER_SANITIZE_EMAIL);

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid email format']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();

    // Find user
    $stmt = $conn->prepare("
        SELECT id, email, first_name, user_type 
        FROM users 
        WHERE email = ?
    ");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Always return success to prevent email enumeration
    if (!$user) {
        echo json_encode(['success' => true, 'message' => 'If an account exists, a reset link will be sent']);
        exit;
    }

    // Generate reset token
    $resetToken = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

    // Store reset token
    $stmt = $conn->prepare("
        UPDATE users 
        SET reset_token = ?, 
            reset_token_expires = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$resetToken, $expiresAt, $user['id']]);

    // Send reset email
    sendResetEmail($user['email'], $user['first_name'], $resetToken);

    // Log request
    logActivity($conn, $user['id'], 'forgot_password', 'Password reset requested');

    echo json_encode(['success' => true, 'message' => 'If an account exists, a reset link will be sent']);

} catch (PDOException $e) {
    error_log("Forgot password error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
}

/**
 * Send password reset email
 */
function sendResetEmail($email, $name, $token) {
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;

        $mail->setFrom(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);
        $mail->addAddress($email, $name);

        $resetLink = APP_URL . "/src/auth/reset-password.html?token=" . $token;
        
        $mail->isHTML(true);
        $mail->Subject = 'Reset Your Password - UrgentServices';
        $mail->Body    = "
            <h2>Password Reset Request</h2>
            <p>Hi {$name},</p>
            <p>We received a request to reset your password. Click the link below to set a new password:</p>
            <p><a href='{$resetLink}'>Reset Password</a></p>
            <p>This link will expire in 1 hour.</p>
            <p>If you didn't request this, please ignore this email.</p>
            <br>
            <p>Best regards,<br>The UrgentServices Team</p>
        ";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Failed to send reset email: " . $mail->ErrorInfo);
        return false;
    }
}
?>