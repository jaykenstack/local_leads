<?php
/**
 * Customer Registration API Endpoint
 */

require_once '../../config/database.php';
require_once '../../config/app.php';
require_once '../../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

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

// Validate required fields
$required = ['first_name', 'last_name', 'email', 'phone', 'password'];
foreach ($required as $field) {
    if (empty($input[$field])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => ucfirst($field) . ' is required']);
        exit;
    }
}

$firstName = trim($input['first_name']);
$lastName = trim($input['last_name']);
$email = filter_var($input['email'], FILTER_SANITIZE_EMAIL);
$phone = preg_replace('/[^0-9+]/', '', $input['phone']);
$password = $input['password'];
$address = $input['address'] ?? null;
$city = $input['city'] ?? null;
$zip = $input['zip'] ?? null;
$receiveOffers = isset($input['receive_offers']) ? (bool)$input['receive_offers'] : false;

// Validate email
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid email format']);
    exit;
}

// Validate phone
if (!preg_match('/^[\+]?[(]?[0-9]{3}[)]?[-\s\.]?[0-9]{3}[-\s\.]?[0-9]{4,6}$/', $phone)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid phone number format']);
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

    // Check if email already exists
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Email already registered']);
        exit;
    }

    // Check if phone already exists
    $stmt = $conn->prepare("SELECT id FROM users WHERE phone = ?");
    $stmt->execute([$phone]);
    
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Phone number already registered']);
        exit;
    }

    // Begin transaction
    $conn->beginTransaction();

    // Hash password
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    // Generate verification token
    $verificationToken = bin2hex(random_bytes(32));

    // Insert user
    $stmt = $conn->prepare("
        INSERT INTO users (
            email, password_hash, user_type, first_name, last_name, phone,
            verification_token, created_at, notification_preferences
        ) VALUES (?, ?, 'customer', ?, ?, ?, ?, NOW(), ?)
    ");
    
    $notificationPrefs = json_encode([
        'email' => true,
        'sms' => true,
        'push' => true,
        'offers' => $receiveOffers
    ]);

    $stmt->execute([$email, $passwordHash, $firstName, $lastName, $phone, $verificationToken, $notificationPrefs]);
    $userId = $conn->lastInsertId();

    // Insert customer profile
    $stmt = $conn->prepare("
        INSERT INTO customer_profiles (
            user_id, default_address, default_city, default_zip, created_at
        ) VALUES (?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$userId, $address, $city, $zip]);

    // Send verification email
    sendVerificationEmail($email, $firstName, $verificationToken);

    // Log registration
    logActivity($conn, $userId, 'register', 'Customer account created');

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Registration successful! Please check your email to verify your account.',
        'user_id' => $userId
    ]);

} catch (PDOException $e) {
    if (isset($conn)) {
        $conn->rollBack();
    }
    error_log("Registration error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
} catch (Exception $e) {
    if (isset($conn)) {
        $conn->rollBack();
    }
    error_log("Registration error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred']);
}

/**
 * Send verification email
 */
function sendVerificationEmail($email, $name, $token) {
    $mail = new PHPMailer(true);

    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;

        // Recipients
        $mail->setFrom(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);
        $mail->addAddress($email, $name);

        // Content
        $verificationLink = APP_URL . "/src/auth/verify-email.html?token=" . $token;
        
        $mail->isHTML(true);
        $mail->Subject = 'Verify Your Email - UrgentServices';
        $mail->Body    = "
            <h2>Welcome to UrgentServices!</h2>
            <p>Hi {$name},</p>
            <p>Please verify your email address by clicking the link below:</p>
            <p><a href='{$verificationLink}'>Verify Email Address</a></p>
            <p>Or copy and paste this link: {$verificationLink}</p>
            <p>This link will expire in 24 hours.</p>
            <br>
            <p>Best regards,<br>The UrgentServices Team</p>
        ";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Failed to send verification email: " . $mail->ErrorInfo);
        return false;
    }
}
?>