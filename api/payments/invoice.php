<?php
/**
 * Generate and Download Invoice
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

    $invoiceId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $format = isset($_GET['format']) ? $_GET['format'] : 'json'; // 'json' or 'pdf'

    if (!$invoiceId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invoice ID required']);
        exit;
    }

    $db = new Database();
    $conn = $db->getConnection();

    // Get invoice details with proper authorization
    $stmt = $conn->prepare("
        SELECT 
            sf.*,
            sr.id as request_id,
            sr.title as request_title,
            sr.description as request_description,
            sr.created_at as request_date,
            p.id as provider_id,
            p.business_name as provider_name,
            p.business_address as provider_address,
            p.business_phone as provider_phone,
            p.business_email as provider_email,
            p.tax_id as provider_tax_id,
            c.id as customer_id,
            u_customer.first_name as customer_first_name,
            u_customer.last_name as customer_last_name,
            u_customer.email as customer_email,
            u_customer.phone as customer_phone,
            cp.default_address as customer_address,
            cp.default_city as customer_city,
            cp.default_state as customer_state,
            cp.default_zip as customer_zip
        FROM service_fees sf
        JOIN service_requests sr ON sf.request_id = sr.id
        JOIN provider_profiles p ON sf.provider_id = p.id
        JOIN customer_profiles cp ON sf.customer_id = cp.id
        JOIN users u_customer ON cp.user_id = u_customer.id
        WHERE sf.id = ? 
        AND (
            cp.user_id = ? 
            OR p.user_id = ? 
            OR EXISTS (SELECT 1 FROM users WHERE id = ? AND is_admin = 1)
        )
    ");
    $stmt->execute([$invoiceId, $decoded->user_id, $decoded->user_id, $decoded->user_id]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$invoice) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Invoice not found']);
        exit;
    }

    // Format invoice data
    $invoiceData = [
        'invoice_number' => 'INV-' . str_pad($invoice['id'], 8, '0', STR_PAD_LEFT),
        'invoice_date' => date('F j, Y', strtotime($invoice['created_at'])),
        'due_date' => date('F j, Y', strtotime('+7 days', strtotime($invoice['created_at']))),
        'status' => $invoice['status'],
        'from' => [
            'name' => $invoice['provider_name'],
            'address' => $invoice['provider_address'],
            'phone' => $invoice['provider_phone'],
            'email' => $invoice['provider_email'],
            'tax_id' => $invoice['provider_tax_id']
        ],
        'to' => [
            'name' => $invoice['customer_first_name'] . ' ' . $invoice['customer_last_name'],
            'address' => $invoice['customer_address'],
            'city' => $invoice['customer_city'],
            'state' => $invoice['customer_state'],
            'zip' => $invoice['customer_zip'],
            'email' => $invoice['customer_email'],
            'phone' => $invoice['customer_phone']
        ],
        'request' => [
            'id' => $invoice['request_id'],
            'title' => $invoice['request_title'],
            'description' => $invoice['request_description'],
            'date' => date('F j, Y', strtotime($invoice['request_date']))
        ],
        'items' => [
            [
                'description' => 'Service Fee for Request #' . $invoice['request_id'],
                'quantity' => 1,
                'unit_price' => $invoice['subtotal'] / 100,
                'amount' => $invoice['subtotal'] / 100
            ]
        ],
        'subtotal' => $invoice['subtotal'] / 100,
        'platform_fee' => $invoice['platform_fee'] / 100,
        'tax' => $invoice['tax_amount'] / 100,
        'total' => $invoice['total_amount'] / 100,
        'payment' => [
            'method' => 'Credit Card',
            'transaction_id' => $invoice['stripe_payment_intent_id'],
            'paid_at' => $invoice['paid_at'] ? date('F j, Y g:i A', strtotime($invoice['paid_at'])) : null
        ]
    ];

    if ($format === 'pdf') {
        // Generate PDF
        require_once '../../vendor/autoload.php';
        
        use Dompdf\Dompdf;
        use Dompdf\Options;

        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isPhpEnabled', false);
        $options->set('isRemoteEnabled', true);

        $dompdf = new Dompdf($options);

        // Generate HTML for PDF
        $html = generateInvoiceHTML($invoiceData);

        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        // Output PDF
        $filename = 'invoice-' . $invoiceData['invoice_number'] . '.pdf';
        
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        echo $dompdf->output();
        exit;

    } else {
        // Return JSON
        echo json_encode([
            'success' => true,
            'invoice' => $invoiceData
        ]);
    }

} catch (Exception $e) {
    error_log("Get invoice error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred']);
}

/**
 * Generate HTML for PDF invoice
 */
function generateInvoiceHTML($invoice) {
    $statusColor = match($invoice['status']) {
        'succeeded' => '#10b981',
        'pending' => '#f59e0b',
        'failed' => '#ef4444',
        default => '#6b7280'
    };

    $statusText = ucfirst($invoice['status']);

    return "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <title>Invoice {$invoice['invoice_number']}</title>
        <style>
            body { font-family: 'Helvetica', sans-serif; margin: 0; padding: 20px; color: #333; }
            .invoice-box { max-width: 800px; margin: auto; padding: 30px; border: 1px solid #eee; box-shadow: 0 0 10px rgba(0,0,0,0.15); }
            .header { display: flex; justify-content: space-between; margin-bottom: 30px; }
            .logo { font-size: 24px; font-weight: bold; color: #3b82f6; }
            .invoice-title { font-size: 28px; font-weight: bold; color: #333; }
            .status { display: inline-block; padding: 5px 15px; border-radius: 20px; color: white; background-color: {$statusColor}; }
            .addresses { display: flex; justify-content: space-between; margin: 30px 0; }
            .address-box { width: 45%; }
            .address-title { font-weight: bold; margin-bottom: 10px; color: #666; }
            .items-table { width: 100%; border-collapse: collapse; margin: 30px 0; }
            .items-table th { background-color: #f3f4f6; padding: 12px; text-align: left; }
            .items-table td { padding: 12px; border-bottom: 1px solid #e5e7eb; }
            .totals { text-align: right; margin-top: 30px; }
            .total-row { padding: 5px 0; }
            .grand-total { font-size: 18px; font-weight: bold; border-top: 2px solid #333; padding-top: 10px; margin-top: 10px; }
            .footer { margin-top: 50px; text-align: center; color: #9ca3af; font-size: 12px; }
        </style>
    </head>
    <body>
        <div class='invoice-box'>
            <div class='header'>
                <div>
                    <div class='logo'>UrgentServices</div>
                    <div style='margin-top: 10px; color: #666;'>Professional Service Marketplace</div>
                </div>
                <div style='text-align: right;'>
                    <div class='invoice-title'>INVOICE</div>
                    <div style='margin-top: 10px;'>{$invoice['invoice_number']}</div>
                    <div class='status' style='margin-top: 10px;'>{$statusText}</div>
                </div>
            </div>

            <div style='margin-bottom: 20px;'>
                <table style='width: 100%;'>
                    <tr>
                        <td><strong>Invoice Date:</strong> {$invoice['invoice_date']}</td>
                        <td><strong>Due Date:</strong> {$invoice['due_date']}</td>
                    </tr>
                    <tr>
                        <td><strong>Request #:</strong> {$invoice['request']['id']}</td>
                        <td><strong>Service Date:</strong> {$invoice['request']['date']}</td>
                    </tr>
                </table>
            </div>

            <div class='addresses'>
                <div class='address-box'>
                    <div class='address-title'>From:</div>
                    <div><strong>{$invoice['from']['name']}</strong></div>
                    <div>{$invoice['from']['address']}</div>
                    <div>{$invoice['from']['phone']}</div>
                    <div>{$invoice['from']['email']}</div>
                    <div>Tax ID: {$invoice['from']['tax_id']}</div>
                </div>
                <div class='address-box'>
                    <div class='address-title'>To:</div>
                    <div><strong>{$invoice['to']['name']}</strong></div>
                    <div>{$invoice['to']['address']}</div>
                    <div>{$invoice['to']['city']}, {$invoice['to']['state']} {$invoice['to']['zip']}</div>
                    <div>{$invoice['to']['email']}</div>
                    <div>{$invoice['to']['phone']}</div>
                </div>
            </div>

            <table class='items-table'>
                <thead>
                    <tr>
                        <th>Description</th>
                        <th>Qty</th>
                        <th>Unit Price</th>
                        <th>Amount</th>
                    </tr>
                </thead>
                <tbody>
                    " . implode('', array_map(function($item) {
                        return "
                        <tr>
                            <td>{$item['description']}</td>
                            <td>{$item['quantity']}</td>
                            <td>$" . number_format($item['unit_price'], 2) . "</td>
                            <td>$" . number_format($item['amount'], 2) . "</td>
                        </tr>
                        ";
                    }, $invoice['items'])) . "
                </tbody>
            </table>

            <div class='totals'>
                <div class='total-row'>Subtotal: $" . number_format($invoice['subtotal'], 2) . "</div>
                <div class='total-row'>Platform Fee: $" . number_format($invoice['platform_fee'], 2) . "</div>
                <div class='total-row'>Tax: $" . number_format($invoice['tax'], 2) . "</div>
                <div class='grand-total'>Total: $" . number_format($invoice['total'], 2) . "</div>
            </div>

            <div style='margin-top: 30px; padding: 20px; background-color: #f9fafb; border-radius: 8px;'>
                <p><strong>Payment Details:</strong></p>
                <p>Method: {$invoice['payment']['method']}</p>
                <p>Transaction ID: {$invoice['payment']['transaction_id']}</p>
                <p>Paid At: {$invoice['payment']['paid_at']}</p>
            </div>

            <div class='footer'>
                <p>Thank you for using UrgentServices!</p>
                <p>For questions about this invoice, please contact support@urgentservices.com</p>
            </div>
        </div>
    </body>
    </html>
    ";
}
?>