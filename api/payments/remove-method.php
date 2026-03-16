<?php
/**
 * Remove Payment Method
 */

require_once '../../config/database.php';
require_once '../../config/app.php';
require_once '../../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Stripe\Stripe;
use Stripe\PaymentMethod;

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

    if (!$input || empty($input['method_id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Method ID required']);
        exit;
    }

    $methodId = (int)$input['method_id'];

    $db = new Database();
    $conn = $db->getConnection();

    // Get payment method details
    $stmt = $conn->prepare("
        SELECT stripe_payment_method_id, is_default 
        FROM customer_payment_methods 
        WHERE id = ? AND customer_id = ?
    ");
    $stmt->execute([$methodId, $decoded->user_id]);
    $method = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$method) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Payment method not found']);
        exit;
    }

    // Begin transaction
    $conn->beginTransaction();

    // Initialize Stripe
    Stripe::setApiKey(STRIPE_SECRET_KEY);

    // Detach from Stripe
    try {
        $paymentMethod = PaymentMethod::retrieve($method['stripe_payment_method_id']);
        $paymentMethod->detach();
    } catch (Exception $e) {
        // Log but continue - might already be detached
        error_log("Stripe detach error: " . $e->getMessage());
    }

    // Remove from database
    $stmt = $conn->prepare("
        DELETE FROM customer_payment_methods 
        WHERE id = ? AND customer_id = ?
    ");
    $stmt->execute([$methodId, $decoded->user_id]);

    // If this was default, set another as default
    if ($method['is_default']) {
        $stmt = $conn->prepare("
            UPDATE customer_payment_methods 
            SET is_default = 1 
            WHERE customer_id = ? 
            ORDER BY created_at DESC 
            LIMIT 1
        ");
        $stmt->execute([$decoded->user_id]);
    }

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Payment method removed successfully'
    ]);

} catch (Stripe\Exception\ApiErrorException $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log("Stripe error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Payment processor error']);
} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log("Remove payment method error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred']);
}
?>