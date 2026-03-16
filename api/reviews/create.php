<?php
/**
 * Create New Review
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
    
    if ($decoded->user_type !== 'customer') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Only customers can write reviews']);
        exit;
    }

    // Get POST data
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid input']);
        exit;
    }

    // Validate required fields
    $required = ['request_id', 'ratings', 'comment'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => ucfirst(str_replace('_', ' ', $field)) . ' is required']);
            exit;
        }
    }

    $requestId = (int)$input['request_id'];
    $ratings = $input['ratings'];
    $title = $input['title'] ?? null;
    $comment = trim($input['comment']);
    $pros = $input['pros'] ?? null;
    $cons = $input['cons'] ?? null;
    $photos = $input['photos'] ?? [];
    $anonymous = isset($input['anonymous']) ? (bool)$input['anonymous'] : false;

    // Validate ratings
    $requiredRatings = ['overall', 'quality', 'punctuality', 'professionalism', 'value', 'communication'];
    foreach ($requiredRatings as $rating) {
        if (!isset($ratings[$rating]) || $ratings[$rating] < 1 || $ratings[$rating] > 5) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Invalid {$rating} rating"]);
            exit;
        }
    }

    $db = new Database();
    $conn = $db->getConnection();

    // Get customer ID
    $stmt = $conn->prepare("SELECT id FROM customer_profiles WHERE user_id = ?");
    $stmt->execute([$decoded->user_id]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$customer) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Customer profile not found']);
        exit;
    }

    // Verify the service request exists and belongs to this customer
    $stmt = $conn->prepare("
        SELECT sr.*, p.id as provider_id, p.business_name, 
               u.first_name, u.last_name, u.email
        FROM service_requests sr
        JOIN provider_profiles p ON sr.provider_id = p.id
        JOIN users u ON p.user_id = u.id
        WHERE sr.id = ? AND sr.customer_id = ? AND sr.status = 'completed'
    ");
    $stmt->execute([$requestId, $customer['id']]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$request) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Completed service request not found']);
        exit;
    }

    // Check if already reviewed
    $stmt = $conn->prepare("SELECT id FROM reviews WHERE request_id = ?");
    $stmt->execute([$requestId]);
    if ($stmt->fetch()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This service has already been reviewed']);
        exit;
    }

    // Begin transaction
    $conn->beginTransaction();

    // Insert review
    $stmt = $conn->prepare("
        INSERT INTO reviews (
            request_id, customer_id, provider_id,
            overall_rating, quality_rating, punctuality_rating,
            professionalism_rating, value_rating, communication_rating,
            title, pros, cons, comment,
            anonymous, ip_address, created_at
        ) VALUES (
            ?, ?, ?,
            ?, ?, ?,
            ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, NOW()
        )
    ");

    $stmt->execute([
        $requestId,
        $customer['id'],
        $request['provider_id'],
        $ratings['overall'],
        $ratings['quality'],
        $ratings['punctuality'],
        $ratings['professionalism'],
        $ratings['value'],
        $ratings['communication'],
        $title,
        $pros,
        $cons,
        $comment,
        $anonymous ? 1 : 0,
        $_SERVER['REMOTE_ADDR'] ?? null
    ]);

    $reviewId = $conn->lastInsertId();

    // Insert review photos
    if (!empty($photos)) {
        $stmt = $conn->prepare("
            INSERT INTO review_photos (review_id, photo_url, sort_order, created_at)
            VALUES (?, ?, ?, NOW())
        ");
        
        foreach ($photos as $index => $photo) {
            $stmt->execute([$reviewId, $photo, $index]);
        }
    }

    // Update provider's review stats (will be recalculated by trigger)
    $stmt = $conn->prepare("
        UPDATE provider_profiles 
        SET total_reviews = total_reviews + 1,
            average_rating = (
                SELECT AVG(overall_rating) 
                FROM reviews 
                WHERE provider_id = ? AND status = 'published'
            )
        WHERE id = ?
    ");
    $stmt->execute([$request['provider_id'], $request['provider_id']]);

    // Create notification for provider
    $stmt = $conn->prepare("
        INSERT INTO notifications (
            user_id, type, title, message, data, created_at
        ) VALUES (
            ?, 'review', 'New Review Received',
            ?, ?, NOW()
        )
    ");

    $customerName = $anonymous ? 'A customer' : $request['first_name'] . ' ' . $request['last_name'];
    $message = "{$customerName} left a {$ratings['overall']}-star review for your service.";
    $notificationData = json_encode([
        'review_id' => $reviewId,
        'request_id' => $requestId,
        'rating' => $ratings['overall']
    ]);

    $stmt->execute([$request['user_id'], $message, $notificationData]);

    // Send email notification to provider
    sendReviewNotification($request['email'], $request['first_name'], $reviewId, $ratings['overall']);

    // Log activity
    logActivity($conn, $decoded->user_id, 'review_written', 
        "Wrote a {$ratings['overall']}-star review for {$request['business_name']}");

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Review submitted successfully',
        'review_id' => $reviewId
    ]);

} catch (ExpiredException $e) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Token expired']);
} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log("Create review error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred']);
}

/**
 * Send review notification email to provider
 */
function sendReviewNotification($email, $name, $reviewId, $rating) {
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

        $reviewLink = APP_URL . "/src/dashboard/provider/reviews/?id=" . $reviewId;
        
        $starRating = str_repeat('★', $rating) . str_repeat('☆', 5 - $rating);
        
        $mail->isHTML(true);
        $mail->Subject = 'New Review Received - UrgentServices';
        $mail->Body    = "
            <h2>You've Received a New Review!</h2>
            <p>Hi {$name},</p>
            <p>A customer has left a review for your service.</p>
            
            <div style='background: #f3f4f6; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                <p style='font-size: 24px; color: #f59e0b;'>{$starRating}</p>
                <p><strong>Rating:</strong> {$rating} out of 5 stars</p>
            </div>
            
            <p>Click the button below to view the full review:</p>
            <p><a href='{$reviewLink}' style='display: inline-block; background: #3b82f6; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px;'>View Review</a></p>
            
            <p>Best regards,<br>The UrgentServices Team</p>
        ";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Failed to send review notification: " . $mail->ErrorInfo);
        return false;
    }
}

/**
 * Log user activity
 */
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