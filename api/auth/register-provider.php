<?php
/**
 * Provider Registration API Endpoint
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
$required = [
    'business_name', 'first_name', 'last_name', 'email', 
    'phone', 'services', 'service_areas', 'password'
];

foreach ($required as $field) {
    if (empty($input[$field])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => ucfirst(str_replace('_', ' ', $field)) . ' is required']);
        exit;
    }
}

$businessName = trim($input['business_name']);
$yearsInBusiness = isset($input['years_in_business']) ? (int)$input['years_in_business'] : 0;
$businessDescription = $input['business_description'] ?? null;
$firstName = trim($input['first_name']);
$lastName = trim($input['last_name']);
$email = filter_var($input['email'], FILTER_SANITIZE_EMAIL);
$phone = preg_replace('/[^0-9+]/', '', $input['phone']);
$services = $input['services']; // Array
$serviceAreas = $input['service_areas']; // Array
$serviceRadius = isset($input['service_radius']) ? (int)$input['service_radius'] : 25;
$password = $input['password'];

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
            verification_token, created_at
        ) VALUES (?, ?, 'provider', ?, ?, ?, ?, NOW())
    ");
    
    $stmt->execute([$email, $passwordHash, $firstName, $lastName, $phone, $verificationToken]);
    $userId = $conn->lastInsertId();

    // Insert provider profile
    $stmt = $conn->prepare("
        INSERT INTO provider_profiles (
            user_id, business_name, years_in_business, business_description,
            service_radius, verified, created_at
        ) VALUES (?, ?, ?, ?, ?, 0, NOW())
    ");
    $stmt->execute([$userId, $businessName, $yearsInBusiness, $businessDescription, $serviceRadius]);
    $providerId = $conn->lastInsertId();

    // Insert provider services
    $stmt = $conn->prepare("
        INSERT INTO provider_services (provider_id, category_id, is_active)
        VALUES (?, ?, 1)
    ");
    
    foreach ($services as $serviceId) {
        $stmt->execute([$providerId, $serviceId]);
    }

    // Insert provider service areas
    $stmt = $conn->prepare("
        INSERT INTO provider_locations (provider_id, location_id, is_primary)
        VALUES (?, ?, ?)
    ");
    
    foreach ($serviceAreas as $index => $locationId) {
        $isPrimary = ($index === 0) ? 1 : 0;
        $stmt->execute([$providerId, $locationId, $isPrimary]);
    }

    // Create default subscription (free trial)
    $stmt = $conn->prepare("
        INSERT INTO provider_subscriptions (
            provider_id, plan_id, status, current_period_start, current_period_end,
            lead_credits_remaining, created_at
        ) VALUES (
            ?, 1, 'trialing', NOW(), DATE_ADD(NOW(), INTERVAL 14 DAY),
            5, NOW()
        )
    ");
    $stmt->execute([$providerId]);

    // Send verification email
    sendVerificationEmail($email, $firstName, $verificationToken);

    // Send admin notification
    sendAdminNotification($businessName, $email);

    // Log registration
    logActivity($conn, $userId, 'register', 'Provider account created - Pending verification');

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Provider registration submitted! Our team will review your application within 24-48 hours.',
        'user_id' => $userId
    ]);

} catch (PDOException $e) {
    if (isset($conn)) {
        $conn->rollBack();
    }
    error_log("Provider registration error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
} catch (Exception $e) {
    if (isset($conn)) {
        $conn->rollBack();
    }
    error_log("Provider registration error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred']);
}

/**
 * Send verification email
 */
function sendVerificationEmail($email, $name, $token) {
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

        $verificationLink = APP_URL . "/src/auth/verify-email.html?token=" . $token;
        
        $mail->isHTML(true);
        $mail->Subject = 'Verify Your Email - UrgentServices Provider';
        $mail->Body    = "
            <h2>Welcome to UrgentServices Provider Network!</h2>
            <p>Hi {$name},</p>
            <p>Thank you for registering as a service provider. Please verify your email address:</p>
            <p><a href='{$verificationLink}'>Verify Email Address</a></p>
            <p>After verification, our team will review your application and verify your credentials.</p>
            <p>This may take 24-48 hours.</p>
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

/**
 * Send admin notification about new provider
 */
function sendAdminNotification($businessName, $email) {
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
        $mail->addAddress(ADMIN_EMAIL, 'Admin');

        $adminLink = APP_URL . "/admin/providers/pending.php";
        
        $mail->isHTML(true);
        $mail->Subject = 'New Provider Registration Pending Review';
        $mail->Body    = "
            <h2>New Provider Registration</h2>
            <p>A new provider has registered and requires verification:</p>
            <ul>
                <li><strong>Business:</strong> {$businessName}</li>
                <li><strong>Email:</strong> {$email}</li>
            </ul>
            <p><a href='{$adminLink}'>Review Application</a></p>
        ";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Failed to send admin notification: " . $mail->ErrorInfo);
        return false;
    }
}
?>