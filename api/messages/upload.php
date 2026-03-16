<?php
/**
 * Upload File for Message
 */

require_once '../../config/database.php';
require_once '../../config/app.php';
require_once '../../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: ' . APP_URL);
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Authorization');

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

    // Check if file was uploaded
    if (empty($_FILES['file'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No file uploaded']);
        exit;
    }

    $conversationId = isset($_POST['conversation_id']) ? (int)$_POST['conversation_id'] : 0;

    if (!$conversationId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Conversation ID required']);
        exit;
    }

    $file = $_FILES['file'];

    // Validate file
    $allowedTypes = [
        'image/jpeg', 'image/png', 'image/jpg', 'image/gif',
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'text/plain',
        'application/rtf'
    ];

    $maxSize = 10 * 1024 * 1024; // 10MB

    if (!in_array($file['type'], $allowedTypes)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'File type not allowed']);
        exit;
    }

    if ($file['size'] > $maxSize) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'File size exceeds 10MB limit']);
        exit;
    }

    $db = new Database();
    $conn = $db->getConnection();

    // Verify user has access to this conversation and get receiver ID
    $stmt = $conn->prepare("
        SELECT c.*,
               CASE 
                   WHEN c.customer_id = ? THEN 
                       (SELECT user_id FROM provider_profiles WHERE id = c.provider_id)
                   ELSE 
                       (SELECT user_id FROM customer_profiles WHERE id = c.customer_id)
               END as receiver_id
        FROM conversations c
        WHERE c.id = ? 
        AND (
            c.customer_id = ? 
            OR c.provider_id = (
                SELECT id FROM provider_profiles WHERE user_id = ?
            )
        )
    ");

    $stmt->execute([
        $decoded->user_id,
        $conversationId,
        $decoded->user_id,
        $decoded->user_id
    ]);

    $conversation = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$conversation) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied']);
        exit;
    }

    // Generate unique filename
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'msg_' . uniqid() . '_' . time() . '.' . $extension;
    
    // Determine if it's an image
    $isImage = strpos($file['type'], 'image/') === 0;
    $fileType = $isImage ? 'image' : 'file';
    
    // Upload directory
    $uploadDir = '../../public/assets/uploads/messages/';
    $subDir = $isImage ? 'images/' : 'files/';
    $fullDir = $uploadDir . $subDir;
    
    // Create directory if it doesn't exist
    if (!file_exists($fullDir)) {
        mkdir($fullDir, 0777, true);
    }

    $uploadPath = $fullDir . $filename;
    $relativePath = '/public/assets/uploads/messages/' . $subDir . $filename;

    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to save file']);
        exit;
    }

    // Prepare file metadata
    $fileMetadata = [
        'name' => $file['name'],
        'size' => $file['size'],
        'type' => $file['type'],
        'url' => $relativePath
    ];

    if ($isImage) {
        // Get image dimensions
        list($width, $height) = getimagesize($uploadPath);
        $fileMetadata['width'] = $width;
        $fileMetadata['height'] = $height;
        
        // Create thumbnail for images
        createThumbnail($uploadPath, $fullDir . 'thumb_' . $filename, 200, 200);
        $fileMetadata['thumbnail'] = '/public/assets/uploads/messages/' . $subDir . 'thumb_' . $filename;
    }

    // Begin transaction
    $conn->beginTransaction();

    // Insert message
    $stmt = $conn->prepare("
        INSERT INTO messages (
            conversation_id, sender_id, receiver_id, 
            content, type, created_at
        ) VALUES (?, ?, ?, ?, ?, NOW())
    ");

    $stmt->execute([
        $conversationId,
        $decoded->user_id,
        $conversation['receiver_id'],
        json_encode($fileMetadata),
        $fileType
    ]);

    $messageId = $conn->lastInsertId();

    // Update conversation last activity
    $stmt = $conn->prepare("
        UPDATE conversations 
        SET updated_at = NOW(),
            last_message_id = ?
        WHERE id = ?
    ");
    $stmt->execute([$messageId, $conversationId]);

    // Get the created message
    $stmt = $conn->prepare("
        SELECT 
            m.*,
            CASE 
                WHEN m.sender_id = ? THEN 1 
                ELSE 0 
            END as is_me
        FROM messages m
        WHERE m.id = ?
    ");
    $stmt->execute([$decoded->user_id, $messageId]);
    $message = $stmt->fetch(PDO::FETCH_ASSOC);

    // Format message
    $message['created_at_formatted'] = date('g:i A', strtotime($message['created_at']));
    $message['is_me'] = (bool)$message['is_me'];
    $message['content'] = json_decode($message['content'], true);

    // Create notification for receiver
    $stmt = $conn->prepare("
        INSERT INTO notifications (
            user_id, type, title, message, data, created_at
        ) VALUES (
            ?, 'message', 'New Message',
            ?, ?, NOW()
        )
    ");

    $senderName = $decoded->user_type === 'provider' 
        ? "Service Provider" 
        : "Customer";

    $fileTypeText = $isImage ? 'an image' : 'a file';
    $notificationMessage = "{$senderName} sent you {$fileTypeText}";
    $notificationData = json_encode([
        'conversation_id' => $conversationId,
        'message_id' => $messageId,
        'sender_id' => $decoded->user_id,
        'file_type' => $fileType
    ]);

    $stmt->execute([
        $conversation['receiver_id'],
        $notificationMessage,
        $notificationData
    ]);

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'File uploaded successfully',
        'data' => $message
    ]);

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log("File upload error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred']);
}

/**
 * Create thumbnail for image
 */
function createThumbnail($source, $destination, $width, $height) {
    list($origWidth, $origHeight, $type) = getimagesize($source);
    
    // Calculate aspect ratio
    $ratio = min($width / $origWidth, $height / $origHeight);
    $newWidth = $origWidth * $ratio;
    $newHeight = $origHeight * $ratio;

    // Create image resource based on type
    switch ($type) {
        case IMAGETYPE_JPEG:
            $sourceImage = imagecreatefromjpeg($source);
            break;
        case IMAGETYPE_PNG:
            $sourceImage = imagecreatefrompng($source);
            break;
        case IMAGETYPE_GIF:
            $sourceImage = imagecreatefromgif($source);
            break;
        default:
            return false;
    }

    // Create thumbnail
    $thumb = imagecreatetruecolor($newWidth, $newHeight);
    
    // Preserve transparency for PNG
    if ($type == IMAGETYPE_PNG) {
        imagealphablending($thumb, false);
        imagesavealpha($thumb, true);
        $transparent = imagecolorallocatealpha($thumb, 255, 255, 255, 127);
        imagefilledrectangle($thumb, 0, 0, $newWidth, $newHeight, $transparent);
    }

    imagecopyresampled($thumb, $sourceImage, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);

    // Save thumbnail
    switch ($type) {
        case IMAGETYPE_JPEG:
            imagejpeg($thumb, $destination, 85);
            break;
        case IMAGETYPE_PNG:
            imagepng($thumb, $destination, 6);
            break;
        case IMAGETYPE_GIF:
            imagegif($thumb, $destination);
            break;
    }

    imagedestroy($sourceImage);
    imagedestroy($thumb);
    
    return true;
}
?>