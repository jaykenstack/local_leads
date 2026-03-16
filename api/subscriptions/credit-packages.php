<?php
/**
 * Get Available Credit Packages for Purchase
 */

require_once '../../config/database.php';
require_once '../../config/app.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: ' . APP_URL);
header('Access-Control-Allow-Methods: GET');

try {
    $db = new Database();
    $conn = $db->getConnection();

    // Get credit packages
    $stmt = $conn->prepare("
        SELECT 
            id,
            credits,
            price,
            savings_percentage,
            popular,
            description
        FROM credit_packages
        WHERE is_active = 1
        ORDER BY credits ASC
    ");
    
    $stmt->execute();
    $packages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($packages as &$package) {
        $package['price_formatted'] = '$' . number_format($package['price'] / 100, 2);
        $package['price_per_credit'] = '$' . number_format(($package['price'] / $package['credits']) / 100, 2);
        $package['popular'] = (bool)$package['popular'];
    }

    echo json_encode([
        'success' => true,
        'packages' => $packages
    ]);

} catch (PDOException $e) {
    error_log("Get credit packages error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
}
?>