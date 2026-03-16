<?php
/**
 * Check Login Status API Endpoint
 */

require_once '../../config/database.php';
require_once '../../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: ' . APP_URL);
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Authorization');

// Get authorization header
$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? '';

$response = [
    'logged_in' => false,
    'user_type' => null,
    'user' => null
];

if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
    $token = $matches[1];

    try {
        $decoded = JWT::decode($token, new Key(JWT_SECRET, 'HS256'));
        
        $db = new Database();
        $conn = $db->getConnection();

        $stmt = $conn->prepare("
            SELECT id, email, first_name, last_name, user_type, avatar_url
            FROM users 
            WHERE id = ?
        ");
        $stmt->execute([$decoded->user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            $response['logged_in'] = true;
            $response['user_type'] = $user['user_type'];
            $response['user'] = [
                'id' => $user['id'],
                'email' => $user['email'],
                'first_name' => $user['first_name'],
                'last_name' => $user['last_name'],
                'user_type' => $user['user_type'],
                'avatar' => $user['avatar_url'] ?: '/public/assets/images/avatars/default-avatar.jpg'
            ];
        }
    } catch (Exception $e) {
        // Token invalid, just return logged_in false
    }
}

echo json_encode($response);
?>