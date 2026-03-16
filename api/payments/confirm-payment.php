<?php
/**
 * Confirm Payment Intent
 */

require_once '../../config/database.php';
require_once '../../config/app.php';
require_once '../../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Stripe\Stripe;
use Stripe\PaymentIntent;

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

    if (!$input || empty($input['payment_intent_id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Payment intent ID required']);
        exit;
    }

    $paymentIntentId = $input['payment_intent_id'];

    $db = new Database();
    $conn = $db->getConnection();

    // Initialize Stripe
    Stripe::setApiKey(STRIPE_SECRET_KEY);

    // Retrieve and confirm payment intent
    $paymentIntent = PaymentIntent::retrieve($paymentIntentId);
    
    if ($paymentIntent->status === 'requires_confirmation') {
        $paymentIntent->confirm();
    }

    if ($paymentIntent->status === 'succeeded') {
        // Begin transaction
        $conn->beginTransaction();

        // Update payment record
        $stmt = $conn->prepare("
            UPDATE service_fees 
            SET status = 'succeeded', paid_at = NOW()
            WHERE stripe_payment_intent_id = ?
        ");
        $stmt->execute([$paymentIntentId]);

        // Update service request status
        $stmt = $conn->prepare("
            UPDATE service_requests 
            SET payment_status = 'paid', status = 'in_progress'
            WHERE id = (
                SELECT request_id FROM service_fees 
                WHERE stripe_payment_intent_id = ?
            )
        ");
        $stmt->execute([$paymentIntentId]);

        // Get request details for notification
        $stmt = $conn->prepare("
            SELECT sr.id, sr.customer_id, sr.provider_id,
                   u_customer.email as customer_email,
                   u_customer.first_name as customer_name,
                   u_provider.email as provider_email,
                   u_provider.first_name as provider_name,
                   p.business_name
            FROM service_requests sr
            JOIN users u_customer ON sr.customer_id = u_customer.id
            JOIN provider_profiles p ON sr.provider_id = p.id
            JOIN users u_provider ON p.user_id = u_provider.id
            WHERE sr.id = (
                SELECT request_id FROM service_fees 
                WHERE stripe_payment_intent_id = ?
            )
        ");
        $stmt->execute([$paymentIntentId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);

        // Send notifications
        if ($request) {
            // Notify provider
            sendPaymentNotification(
                $request['provider_email'],
                $request['provider_name'],
                $request['id'],
                'provider'
            );

            // Notify customer
            sendPaymentNotification(
                $request['customer_email'],
                $request['customer_name'],
                $request['id'],
                'customer'
            );

            // Create in-app notifications
            createNotification($conn, $request['provider_id'], 'payment_received', 
                "Payment received for service request #{$request['id']}");

            createNotification($conn, $request['customer_id'], 'payment_confirmed', 
                "Your payment for service request #{$request['id']} has been confirmed");
        }

        $conn->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Payment confirmed successfully',
            'payment_intent' => [
                'id' => $paymentIntent->id,
                'amount' => $paymentIntent->amount,
                'status' => $paymentIntent->status
            ]
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Payment requires additional action',
            'payment_intent_status' => $paymentIntent->status,
            'next_action' => $paymentIntent->next_action
        ]);
    }

} catch (Stripe\Exception\CardException $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Card error: ' . $e->getMessage()]);
} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log("Confirm payment error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred']);
}

/**
 * Send payment notification email
 */
function sendPaymentNotification($email, $name, $requestId, $type) {
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

        $dashboardLink = $type === 'provider' 
            ? APP_URL . "/src/dashboard/provider/requests.php?id=" . $requestId
            : APP_URL . "/src/dashboard/customer/requests.php?id=" . $requestId;

        if ($type === 'provider') {
            $subject = 'Payment Received - Service Request #' . $requestId;
            $body = "
                <h2>Payment Received!</h2>
                <p>Hi {$name},</p>
                <p>Great news! The customer has confirmed payment for service request #{$requestId}.</p>
                <p>The funds have been transferred to your account (minus platform fees).</p>
                <p><a href='{$dashboardLink}'>View Request Details</a></p>
            ";
        } else {
            $subject = 'Payment Confirmed - Service Request #' . $requestId;
            $body = "
                <h2>Payment Confirmed!</h2>
                <p>Hi {$name},</p>
                <p>Your payment for service request #{$requestId} has been confirmed.</p>
                <p>The provider will now begin work on your request.</p>
                <p><a href='{$dashboardLink}'>Track Request Progress</a></p>
            ";
        }

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Failed to send payment notification: " . $mail->ErrorInfo);
        return false;
    }
}

/**
 * Create in-app notification
 */
function createNotification($conn, $userId, $type, $message) {
    try {
        $stmt = $conn->prepare("
            INSERT INTO notifications (user_id, type, message, created_at)
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([$userId, $type, $message]);
    } catch (Exception $e) {
        error_log("Failed to create notification: " . $e->getMessage());
    }
}
?>