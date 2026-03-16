<?php
/**
 * Get Provider Review Statistics
 */

require_once '../../config/database.php';
require_once '../../config/app.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: ' . APP_URL);
header('Access-Control-Allow-Methods: GET');

$providerId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$providerId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Provider ID required']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();

    // Get review statistics
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_reviews,
            COALESCE(AVG(overall_rating), 0) as avg_rating,
            COALESCE(AVG(quality_rating), 0) as avg_quality,
            COALESCE(AVG(punctuality_rating), 0) as avg_punctuality,
            COALESCE(AVG(professionalism_rating), 0) as avg_professionalism,
            COALESCE(AVG(value_rating), 0) as avg_value,
            COALESCE(AVG(communication_rating), 0) as avg_communication,
            SUM(CASE WHEN overall_rating = 5 THEN 1 ELSE 0 END) as five_star,
            SUM(CASE WHEN overall_rating = 4 THEN 1 ELSE 0 END) as four_star,
            SUM(CASE WHEN overall_rating = 3 THEN 1 ELSE 0 END) as three_star,
            SUM(CASE WHEN overall_rating = 2 THEN 1 ELSE 0 END) as two_star,
            SUM(CASE WHEN overall_rating = 1 THEN 1 ELSE 0 END) as one_star
        FROM reviews
        WHERE provider_id = ? AND status = 'published'
    ");
    $stmt->execute([$providerId]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get response statistics
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_reviews,
            SUM(CASE WHEN response_from_provider IS NOT NULL THEN 1 ELSE 0 END) as responded,
            AVG(CASE 
                WHEN response_from_provider IS NOT NULL 
                THEN TIMESTAMPDIFF(HOUR, created_at, response_date)
                ELSE NULL 
            END) as avg_response_hours
        FROM reviews
        WHERE provider_id = ? AND status = 'published'
    ");
    $stmt->execute([$providerId]);
    $responseStats = $stmt->fetch(PDO::FETCH_ASSOC);

    // Get monthly trends (last 6 months)
    $stmt = $conn->prepare("
        SELECT 
            DATE_FORMAT(created_at, '%Y-%m') as month,
            COUNT(*) as review_count,
            AVG(overall_rating) as avg_rating
        FROM reviews
        WHERE provider_id = ? 
            AND status = 'published'
            AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(created_at, '%Y-%m')
        ORDER BY month DESC
    ");
    $stmt->execute([$providerId]);
    $trends = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get recent reviews for preview
    $stmt = $conn->prepare("
        SELECT 
            r.id,
            r.overall_rating,
            r.comment,
            r.created_at,
            u.first_name as customer_first_name,
            u.last_name as customer_last_name,
            r.anonymous
        FROM reviews r
        JOIN customer_profiles c ON r.customer_id = c.id
        JOIN users u ON c.user_id = u.id
        WHERE r.provider_id = ? AND r.status = 'published'
        ORDER BY r.created_at DESC
        LIMIT 3
    ");
    $stmt->execute([$providerId]);
    $recent = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($recent as &$review) {
        $review['customer_name'] = $review['anonymous'] 
            ? 'Anonymous' 
            : $review['customer_first_name'] . ' ' . substr($review['customer_last_name'], 0, 1) . '.';
        unset($review['customer_first_name'], $review['customer_last_name'], $review['anonymous']);
    }

    // Calculate response rate
    $responseRate = $responseStats['total_reviews'] > 0 
        ? round(($responseStats['responded'] / $responseStats['total_reviews']) * 100, 1)
        : 0;

    echo json_encode([
        'success' => true,
        'stats' => [
            'total_reviews' => (int)$stats['total_reviews'],
            'average_rating' => round((float)$stats['avg_rating'], 1),
            'distribution' => [
                5 => (int)$stats['five_star'],
                4 => (int)$stats['four_star'],
                3 => (int)$stats['three_star'],
                2 => (int)$stats['two_star'],
                1 => (int)$stats['one_star']
            ]
        ],
        'categories' => [
            'quality' => round((float)$stats['avg_quality'], 1),
            'punctuality' => round((float)$stats['avg_punctuality'], 1),
            'professionalism' => round((float)$stats['avg_professionalism'], 1),
            'value' => round((float)$stats['avg_value'], 1),
            'communication' => round((float)$stats['avg_communication'], 1)
        ],
        'response_stats' => [
            'response_rate' => $responseRate,
            'average_response_time' => round((float)$responseStats['avg_response_hours'], 1),
            'total_responded' => (int)$responseStats['responded']
        ],
        'trends' => $trends,
        'recent_reviews' => $recent
    ]);

} catch (PDOException $e) {
    error_log("Get review stats error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
}
?>