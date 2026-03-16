<?php
/**
 * Request Payout for Provider
 */

require_once '../../config/database.php';
require_once '../../config/app.php';
require_once '../../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Stripe\Stripe;
use Stripe\Transfer;
use Stripe\Payout;

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
        echo json_encode(['success' => false, 'message' => 'Only providers can request payouts']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input || empty($input['amount'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Amount required']);
        exit;
    }

    $amount = (int)$input['amount']; // in cents
    $payoutMethod = $input['payout_method'] ?? 'bank_account'; // 'bank_account' or 'card'

    // Validate minimum payout
    if ($amount < 1000) { // $10 minimum
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Minimum payout amount is $10']);
        exit;
    }

    $db = new Database();
    $conn = $db->getConnection();

    // Get provider details
    $stmt = $conn->prepare("
        SELECT p.*, u.email, u.first_name, u.last_name,
               COALESCE(SUM(sf.total_amount - sf.platform_fee), 0) as available_balance
        FROM provider_profiles p
        JOIN users u ON p.user_id = u.id
        LEFT JOIN service_fees sf ON p.id = sf.provider_id 
            AND sf.status = 'succeeded'
            AND sf.paid_at IS NOT NULL
            AND NOT EXISTS (
                SELECT 1 FROM provider_payouts pp 
                WHERE pp.provider_id = p.id 
                AND pp.period_end >= sf.paid_at
            )
        WHERE p.user_id = ?
        GROUP BY p.id
    ");
    $stmt->execute([$decoded->user_id]);
    $provider = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$provider) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Provider profile not found']);
        exit;
    }

    // Check if provider has Stripe Connect account
    if (!$provider['stripe_connect_id']) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Please connect your Stripe account first']);
        exit;
    }

    // Check available balance
    if ($provider['available_balance'] < $amount) {
        http_response_code(400);
        echo json_encode([
            'success' => false, 
            'message' => 'Insufficient balance',
            'available_balance' => $provider['available_balance']
        ]);
        exit;
    }

    // Begin transaction
    $conn->beginTransaction();

    // Initialize Stripe
    Stripe::setApiKey(STRIPE_SECRET_KEY);

    // Create payout via Stripe Connect
    $payoutParams = [
        'amount' => $amount,
        'currency' => 'usd',
        'destination' => $provider['stripe_connect_id'],
        'method' => $payoutMethod,
        'metadata' => [
            'provider_id' => $provider['id'],
            'provider_name' => $provider['business_name']
        ]
    ];

    $stripePayout = Payout::create($payoutParams, [
        'stripe_account' => $provider['stripe_connect_id']
    ]);

    // Calculate period (last unpaid period)
    $stmt = $conn->prepare("
        SELECT MIN(paid_at) as period_start, MAX(paid_at) as period_end
        FROM service_fees 
        WHERE provider_id = ? AND status = 'succeeded'
        AND NOT EXISTS (
            SELECT 1 FROM provider_payouts pp 
            WHERE pp.provider_id = ? 
            AND pp.period_end >= service_fees.paid_at
        )
    ");
    $stmt->execute([$provider['id'], $provider['id']]);
    $period = $stmt->fetch(PDO::FETCH_ASSOC);

    // Record payout
    $stmt = $conn->prepare("
        INSERT INTO provider_payouts (
            provider_id, amount, stripe_payout_id, status,
            payout_method, period_start, period_end, created_at
        ) VALUES (?, ?, ?, 'pending', ?, ?, ?, NOW())
    ");

    $stmt->execute([
        $provider['id'],
        $amount,
        $stripePayout->id,
        $payoutMethod,
        $period['period_start'],
        $period['period_end'] ?: date('Y-m-d H:i:s')
    ]);

    $payoutId = $conn->lastInsertId();

    // Log transaction
    $stmt = $conn->prepare("
        INSERT INTO transactions (
            user_id, user_type, transaction_type, amount,
            status, stripe_transaction_id, description, created_at
        ) VALUES (?, 'provider', 'payout', ?, 'pending', ?, ?, NOW())
    ");

    $description = "Payout request for $" . number_format($amount / 100, 2);

    $stmt->execute([
        $decoded->user_id,
        $amount,
        $stripePayout->id,
        $description
    ]);

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Payout requested successfully',
        'payout_id' => $payoutId,
        'stripe_payout_id' => $stripePayout->id,
        'amount' => $amount,
        'status' => 'pending',
        'estimated_arrival' => date('Y-m-d', strtotime('+2 days'))
    ]);

} catch (Stripe\Exception\ApiErrorException $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log("Stripe payout error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Payout processor error: ' . $e->getMessage()]);
} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log("Request payout error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred']);
}
?>