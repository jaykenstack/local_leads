<?php
/**
 * Get Provider Reviews (Public)
 */

require_once '../../config/database.php';
require_once '../../config/app.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: ' . APP_URL);
header('Access-Control-Allow-Methods: GET');

$providerId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
$rating = isset($_GET['rating']) ? (int)$_GET['rating'] : null;
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'recent';
$withPhotos = isset($_GET['photos']) ? (bool)$_GET['photos'] : false;
$withResponses = isset($_GET['responses']) ? (bool)$_GET['responses'] : false;

if (!$providerId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Provider ID required']);
    exit;
}

// Validate pagination
$page = max(1, $page);
$limit = min(50, max(1, $limit));
$offset = ($page - 1) * $limit;

try {
    $db = new Database();
    $conn = $db->getConnection();

    // Verify provider exists
    $stmt = $conn->prepare("
        SELECT id, business_name, verified 
        FROM provider_profiles 
        WHERE id = ?
    ");
    $stmt->execute([$providerId]);
    $provider = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$provider) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Provider not found']);
        exit;
    }

    // Build query conditions
    $conditions = ["r.provider_id = ?", "r.status = 'published'"];
    $params = [$providerId];

    if ($rating) {
        $conditions[] = "r.overall_rating = ?";
        $params[] = $rating;
    }

    if ($withPhotos) {
        $conditions[] = "EXISTS (SELECT 1 FROM review_photos rp WHERE rp.review_id = r.id)";
    }

    if ($withResponses) {
        $conditions[] = "r.response_from_provider IS NOT NULL";
    }

    $whereClause = implode(" AND ", $conditions);

    // Get total count
    $stmt = $conn->prepare("
        SELECT COUNT(*) as total 
        FROM reviews r
        WHERE {$whereClause}
    ");
    $stmt->execute($params);
    $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Determine sort order
    $orderBy = match($sort) {
        'highest' => 'r.overall_rating DESC, r.created_at DESC',
        'lowest' => 'r.overall_rating ASC, r.created_at DESC',
        'oldest' => 'r.created_at ASC',
        default => 'r.created_at DESC' // recent
    };

    // Get reviews
    $stmt = $conn->prepare("
        SELECT 
            r.*,
            c.id as customer_id,
            u.first_name as customer_first_name,
            u.last_name as customer_last_name,
            u.avatar_url as customer_avatar,
            p.business_name as provider_name,
            p.id as provider_id,
            u_provider.avatar_url as provider_avatar
        FROM reviews r
        JOIN customer_profiles c ON r.customer_id = c.id
        JOIN users u ON c.user_id = u.id
        JOIN provider_profiles p ON r.provider_id = p.id
        JOIN users u_provider ON p.user_id = u_provider.id
        WHERE {$whereClause}
        ORDER BY {$orderBy}
        LIMIT ? OFFSET ?
    ");

    $params[] = $limit;
    $params[] = $offset;
    $stmt->execute($params);
    $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get photos for each review
    foreach ($reviews as &$review) {
        $stmt = $conn->prepare("
            SELECT id, photo_url, sort_order
            FROM review_photos
            WHERE review_id = ?
            ORDER BY sort_order ASC
        ");
        $stmt->execute([$review['id']]);
        $review['photos'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Format data
        $review['customer_name'] = $review['anonymous'] 
            ? 'Anonymous' 
            : $review['customer_first_name'] . ' ' . substr($review['customer_last_name'], 0, 1) . '.';
        
        $review['customer_avatar'] = $review['anonymous'] 
            ? '/public/assets/images/avatars/default-avatar.jpg' 
            : ($review['customer_avatar'] ?: '/public/assets/images/avatars/default-avatar.jpg');

        $review['provider_avatar'] = $review['provider_avatar'] ?: '/public/assets/images/avatars/default-avatar.jpg';

        // Get helpful count
        $stmt = $conn->prepare("
            SELECT COUNT(*) as count FROM review_votes 
            WHERE review_id = ? AND vote_type = 'helpful'
        ");
        $stmt->execute([$review['id']]);
        $review['helpful_count'] = $stmt->fetchColumn();

        // Check if current user found this helpful (if logged in)
        $review['user_helpful'] = false; // Would need user ID from session
    }

    // Calculate pagination info
    $totalPages = ceil($total / $limit);

    echo json_encode([
        'success' => true,
        'provider' => [
            'id' => $provider['id'],
            'business_name' => $provider['business_name'],
            'verified' => (bool)$provider['verified']
        ],
        'reviews' => $reviews,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => (int)$total,
            'totalPages' => $totalPages,
            'hasNext' => $page < $totalPages,
            'hasPrev' => $page > 1
        ]
    ]);

} catch (PDOException $e) {
    error_log("Get provider reviews error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error occurred']);
}
?>