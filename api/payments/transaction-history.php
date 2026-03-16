<?php
/**
 * Get Transaction History
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

    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
    $type = isset($_GET['type']) ? $_GET['type'] : null;
    $from = isset($_GET['from']) ? $_GET['from'] : null;
    $to = isset($_GET['to']) ? $_GET['to'] : null;

    $offset = ($page - 1) * $limit;

    $db = new Database();
    $conn = $db->getConnection();

    // Build query conditions
    $conditions = ["user_id = ?"];
    $params = [$decoded->user_id];

    if ($type && $type !== 'all') {
        $conditions[] = "transaction_type = ?";
        $params[] = $type;
    }

    if ($from) {
        $conditions[] = "created_at >= ?";
        $params[] = $from . ' 00:00:00';
    }

    if ($to) {
        $conditions[] = "created_at <= ?";
        $params[] = $to . ' 23:59:59';
    }

    $whereClause = implode(" AND ", $conditions);

    // Get total count
    $stmt = $conn->prepare("
        SELECT COUNT(*) as total 
        FROM transactions 
        WHERE {$whereClause}
    ");
    $stmt->execute($params);
    $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Get transactions
    $stmt = $conn->prepare("
        SELECT 
            id,
            transaction_type,
            amount,
            fee,
            net_amount,
            currency,
            status,
            description,
            stripe_transaction_id,
            metadata,
            created_at
        FROM transactions
        WHERE {$whereClause}
        ORDER BY created_at DESC
        LIMIT ? OFFSET ?
    ");

    $params[] = $limit;
    $params[] = $offset;
    $stmt->execute($params);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate summary statistics
    $stmt = $conn->prepare("
        SELECT 
            COALESCE(SUM(CASE WHEN transaction_type = 'payment' AND status = 'succeeded' THEN amount ELSE 0 END), 0) as total_payments,
            COALESCE(SUM(CASE WHEN transaction_type = 'payout' AND status = 'succeeded' THEN amount ELSE 0 END), 0) as total_payouts,
            COALESCE(SUM(CASE WHEN transaction_type = 'refund' AND status = 'succeeded' THEN amount ELSE 0 END), 0) as total_refunds,
            COALESCE(SUM(fee), 0) as total_fees,
            COALESCE(SUM(net_amount), 0) as total_net
        FROM transactions
        WHERE user_id = ? AND status = 'succeeded'
    ");
    $stmt->execute([$decoded->user_id]);
    $summary = $stmt->fetch(PDO::FETCH_ASSOC);

    foreach ($transactions as &$transaction) {
        $transaction['amount_formatted'] = '$' . number_format($transaction['amount'] / 100, 2);
        $transaction['fee_formatted'] = $transaction['fee'] ? '$' . number_format($transaction['fee'] / 100, 2) : null;
        $transaction['net_formatted'] = $transaction['net_amount'] ? '$' . number_format($transaction['net_amount'] / 100, 2) : null;
        $transaction['created_at_formatted'] = date('M j, Y g:i A', strtotime($transaction['created_at']));
        
        if ($transaction['metadata']) {
            $transaction['metadata'] = json_decode($transaction['metadata'], true);
        }
    }

    $totalPages = ceil($total / $limit);

    echo json_encode([
        'success' => true,
        'transactions' => $transactions,
        'summary' => [
            'total_payments' => (float)$summary['total_payments'] / 100,
            'total_payouts' => (float)$summary['total_payouts'] / 100,
            'total_refunds' => (float)$summary['total_refunds'] / 100,
            'total_fees' => (float)$summary['total_fees'] / 100,
            'total_net' => (float)$summary['total_net'] / 100
        ],
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => (int)$total,
            'total_pages' => $totalPages,
            'has_next' => $page < $totalPages,
            'has_prev' => $page > 1
        ]
    ]);

} catch (Exception $e) {
    error_log("Get transaction history error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred']);
}
?>