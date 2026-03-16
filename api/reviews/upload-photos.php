<?php
/**
 * Upload Review Photos
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

    // Check if files were uploaded
    if (empty($_FILES['photos'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No photos uploaded']);
        exit;
    }

    $files = $_FILES['photos'];
    $uploadedUrls = [];

    // Handle multiple files
    if (is_array($files['name'])) {
        $fileCount = count($files['name']);
        
        if ($fileCount > 5) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Maximum 5 photos allowed']);
            exit;
        }

        for ($i = 0; $i < $fileCount; $i++) {
            $file = [
                'name' => $files['name'][$i],
                'type' => $files['type'][$i],
                'tmp_name' => $files['tmp_name'][$i],
                'error' => $files['error'][$i],
                'size' => $files['size'][$i]
            ];

            $url = uploadSinglePhoto($file);
            if ($url) {
                $uploadedUrls[] = $url;
            }
        }
    } else {
        // Single file
        if ($_FILES['photos']['error'] === UPLOAD_ERR_OK) {
            $url = uploadSinglePhoto($_FILES['photos']);
            if ($url) {
                $uploadedUrls[] = $url;
            }
        }
    }

    if (empty($uploadedUrls)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to upload photos']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'urls' => $uploadedUrls,
        'message' => count($uploadedUrls) . ' photo(s) uploaded successfully'
    ]);

} catch (Exception $e) {
    error_log("Upload photos error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred']);
}

/**
 * Upload single photo
 */
function uploadSinglePhoto($file) {
    // Validate file type
    $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'image/gif'];
    $maxSize = 5 * 1024 * 1024; // 5MB

    if (!in_array($file['type'], $allowedTypes)) {
        error_log("Invalid file type: " . $file['type']);
        return null;
    }

    if ($file['size'] > $maxSize) {
        error_log("File too large: " . $file['size']);
        return null;
    }

    // Generate unique filename
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'review_' . uniqid() . '_' . time() . '.' . $extension;
    
    // Determine upload directory
    $uploadDir = '../../public/assets/uploads/reviews/';
    
    // Create directory if it doesn't exist
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    $uploadPath = $uploadDir . $filename;

    // Move uploaded file
    if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
        // Return URL relative to web root
        return '/public/assets/uploads/reviews/' . $filename;
    }

    return null;
}
?>