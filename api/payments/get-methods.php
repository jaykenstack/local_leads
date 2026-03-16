<?php
/**
 * Get Customer's Saved Payment Methods
 */

require_once '../../config/database.php';
require_once '../../config/app.php';
require_once '../../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: ' . APP_URL);
header('Access-Control-Allow-Methods: GET');
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

    $db = new Database();
    $conn = $db->getConnection();

    // Get payment methods
    $stmt = $conn->prepare("
        SELECT 
            id,
            stripe_payment_method_id,
            card_brand,
            card_last4,
            card_exp_month,
            card_exp_year,
            is_default,
            billing_address,
            billing_city,
            billing_state,
            billing_zip,
            created_at
        FROM customer_payment_methods
        WHERE customer_id = ?
        ORDER BY is_default DESC, created_at DESC
    ");
    $stmt->execute([$decoded->user_id]);
    $methods = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($methods as &$method) {
        $method['is_default'] = (bool)$method['is_default'];
        $method['card_expiry'] = $method['card_exp_month'] . '/' . $method['card_exp_year'];
        $method['card_display'] = $method['card_brand'] . ' •••• ' . $method['card_last4'];
    }

    echo json_encode([
        'success' => true,
        'methods' => $methods
    ]);

} catch (Exception $e) {
    error_log("Get payment methods error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred']);
}
?>