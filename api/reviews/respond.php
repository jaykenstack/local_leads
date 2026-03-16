<?php
/**
 * Provider Respond to Review
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
    
    if ($decoded->user_type !== 'provider') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Only providers can respond to reviews']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input || empty($input['review_id']) || empty($input['response'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Review ID and response required']);
        exit;
    }

    $reviewId = (int)$input['review_id'];
    $response = trim($input['response']);

    if (strlen($response) < 10) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Response must be at least 10 characters']);
        exit;
    }

    $db = new Database();
    $conn = $db->getConnection();

    // Get provider ID
    $stmt = $conn->prepare("SELECT id FROM provider_profiles WHERE user_id = ?");
    $stmt->execute([$decoded->user_id]);
    $provider = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$provider) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Provider profile not found']);
        exit;
    }

    // Verify review belongs to this provider
    $stmt = $conn->prepare("
        SELECT r.*, c.user_id as customer_user_id, 
               u.email as customer_email, u.first_name as customer_first_name
        FROM reviews r
        JOIN customer_profiles c ON r.customer_id = c.id
        JOIN users u ON c.user_id = u.id
        WHERE r.id = ? AND r.provider_id = ?
    ");
    $stmt->execute([$reviewId, $provider['id']]);
    $review = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$review) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Review not found']);
        exit;
    }

    // Check if already responded
    if ($review['response_from_provider']) {
        // Update existing response
        $stmt = $conn->prepare("
            UPDATE reviews 
            SET response_from_provider = ?,
                response_date = NOW(),
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$response, $reviewId]);
        $message = 'Response updated successfully';
    } else {
        // Insert new response
        $stmt = $conn->prepare("
            UPDATE reviews 
            SET response_from_provider = ?,
                response_date = NOW(),
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$response, $reviewId]);
        $message = 'Response submitted successfully';

        // Create notification for customer
        $stmt = $conn->prepare("
            INSERT INTO notifications (
                user_id, type, title, message, data, created_at
            ) VALUES (
                ?, 'review_response', 'Provider Responded to Your Review',
                ?, ?, NOW()
            )
        ");

        $notificationMessage = "The provider responded to your review.";
        $notificationData = json_encode([
            'review_id' => $reviewId,
            'provider_id' => $provider['id']
        ]);

        $stmt->execute([$review['customer_user_id'], $notificationMessage, $notificationData]);

        // Send email notification to customer
        sendResponseNotification(
            $review['customer_email'],
            $review['customer_first_name'],
            $reviewId
        );
    }

    // Log activity
    logActivity($conn, $decoded->user_id, 'review_response', 
        "Responded to review #{$reviewId}");

    echo json_encode([
        'success' => true,
        'message' => $message
    ]);

} catch (Exception $e) {
    error_log("Review response error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred']);
}

/**
 * Send response notification email to customer
 */
function sendResponseNotification($email, $name, $reviewId) {
    require_once '../../vendor/autoload.php';
    
    use PHPMailer\PHPMailer\PHPMailer;
    use PHPMailer\PHPMailer\Exception;

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

        $reviewLink = APP_URL . "/src/public/reviews/?review=" . $reviewId;
        
        $mail->isHTML(true);
        $mail->Subject = 'Provider Responded to Your Review - UrgentServices';
        $mail->Body    = "
            <h2>Provider Response Received</h2>
            <p>Hi {$name},</p>
            <p>The service provider has responded to your review.</p>
            
            <p>Click the button below to view their response:</p>
            <p><a href='{$reviewLink}' style='display: inline-block; background: #3b82f6; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px;'>View Response</a></p>
            
            <p>Best regards,<br>The UrgentServices Team</p>
        ";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Failed to send response notification: " . $mail->ErrorInfo);
        return false;
    }
}

function logActivity($conn, $userId, $action, $description) {
    try {
        $stmt = $conn->prepare("
            INSERT INTO user_activity (user_id, action, description, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $userId,
            $action,
            $description,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    } catch (Exception $e) {
        error_log("Failed to log activity: " . $e->getMessage());
    }
}
?>