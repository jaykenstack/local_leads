<?php
/**
 * Search Conversations
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

    $query = isset($_GET['q']) ? trim($_GET['q']) : '';

    if (strlen($query) < 2) {
        echo json_encode(['success' => true, 'conversations' => []]);
        exit;
    }

    $db = new Database();
    $conn = $db->getConnection();

    // Search conversations by user name or message content
    $searchTerm = '%' . $query . '%';

    $stmt = $conn->prepare("
        SELECT DISTINCT
            c.id,
            c.created_at as conversation_created,
            c.updated_at as last_activity,
            CASE 
                WHEN c.customer_id = ? THEN 'provider'
                ELSE 'customer'
            END as other_user_type,
            CASE 
                WHEN c.customer_id = ? THEN p.business_name
                ELSE CONCAT(u_customer.first_name, ' ', u_customer.last_name)
            END as other_user_name,
            CASE 
                WHEN c.customer_id = ? THEN u_provider.avatar_url
                ELSE u_customer.avatar_url
            END as other_user_avatar,
            (
                SELECT m.message 
                FROM messages m 
                WHERE m.conversation_id = c.id 
                ORDER BY m.created_at DESC 
                LIMIT 1
            ) as last_message,
            (
                SELECT m.created_at 
                FROM messages m 
                WHERE m.conversation_id = c.id 
                ORDER BY m.created_at DESC 
                LIMIT 1
            ) as last_message_time,
            (
                SELECT COUNT(*) 
                FROM messages m 
                WHERE m.conversation_id = c.id 
                    AND m.receiver_id = ? 
                    AND m.is_read = 0
            ) as unread_count
        FROM conversations c
        LEFT JOIN provider_profiles p ON c.provider_id = p.id
        LEFT JOIN customer_profiles cu ON c.customer_id = cu.id
        LEFT JOIN users u_provider ON p.user_id = u_provider.id
        LEFT JOIN users u_customer ON cu.user_id = u_customer.id
        LEFT JOIN messages m ON c.id = m.conversation_id
        WHERE (
            c.customer_id = ? OR c.provider_id = (
                SELECT id FROM provider_profiles WHERE user_id = ?
            )
        )
        AND (
            CASE 
                WHEN c.customer_id = ? THEN p.business_name
                ELSE CONCAT(u_customer.first_name, ' ', u_customer.last_name)
            END LIKE ?
            OR m.content LIKE ?
        )
        ORDER BY last_message_time DESC
        LIMIT 20
    ");

    $stmt->execute([
        $decoded->user_id,
        $decoded->user_id,
        $decoded->user_id,
        $decoded->user_id,
        $decoded->user_id,
        $decoded->user_id,
        $decoded->user_id,
        $searchTerm,
        $searchTerm
    ]);

    $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format conversations
    foreach ($conversations as &$conv) {
        $conv['last_message_time_formatted'] = formatMessageTime($conv['last_message_time']);
        $conv['last_message_preview'] = substr($conv['last_message'], 0, 50) . 
            (strlen($conv['last_message']) > 50 ? '...' : '');
        $conv['unread_count'] = (int)$conv['unread_count'];
        
        // Highlight search term in name
        $conv['other_user_name'] = highlightSearchTerm($conv['other_user_name'], $query);
        
        if (!$conv['other_user_avatar']) {
            $conv['other_user_avatar'] = '/public/assets/images/avatars/default-avatar.jpg';
        }
    }

    echo json_encode([
        'success' => true,
        'conversations' => $conversations
    ]);

} catch (Exception $e) {
    error_log("Search conversations error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred']);
}

/**
 * Format message time for display
 */
function formatMessageTime($timestamp) {
    if (!$timestamp) return '';
    
    $messageTime = new DateTime($timestamp);
    $now = new DateTime();
    $diff = $now->diff($messageTime);

    if ($diff->days == 0) {
        if ($diff->h == 0) {
            if ($diff->i == 0) {
                return 'Just now';
            }
            return $diff->i . ' min ago';
        }
        return $messageTime->format('g:i A');
    } elseif ($diff->days == 1) {
        return 'Yesterday';
    } elseif ($diff->days < 7) {
        return $messageTime->format('l');
    } else {
        return $messageTime->format('M j');
    }
}

/**
 * Highlight search term in text
 */
function highlightSearchTerm($text, $term) {
    return preg_replace('/(' . preg_quote($term, '/') . ')/i', '<mark>$1</mark>', $text);
}
?>