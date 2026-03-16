<?php
/**
 * Get Single Conversation with Messages
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

    $conversationId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if (!$conversationId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Conversation ID required']);
        exit;
    }

    $db = new Database();
    $conn = $db->getConnection();

    // Verify user has access to this conversation
    $stmt = $conn->prepare("
        SELECT c.*,
               CASE 
                   WHEN c.customer_id = ? THEN 'provider'
                   ELSE 'customer'
               END as user_role,
               CASE 
                   WHEN c.customer_id = ? THEN p.id
                   ELSE cu.id
               END as other_user_profile_id,
               CASE 
                   WHEN c.customer_id = ? THEN p.business_name
                   ELSE CONCAT(u_customer.first_name, ' ', u_customer.last_name)
               END as other_user_name,
               CASE 
                   WHEN c.customer_id = ? THEN u_provider.avatar_url
                   ELSE u_customer.avatar_url
               END as other_user_avatar,
               CASE 
                   WHEN c.customer_id = ? THEN p.user_id
                   ELSE cu.user_id
               END as other_user_id,
               CASE 
                   WHEN EXISTS (
                       SELECT 1 FROM user_sessions 
                       WHERE user_id = CASE 
                           WHEN c.customer_id = ? THEN p.user_id
                           ELSE cu.user_id
                       END
                       AND last_activity > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                   ) THEN 1 ELSE 0 
               END as is_online,
               (
                   SELECT last_activity 
                   FROM user_sessions 
                   WHERE user_id = CASE 
                       WHEN c.customer_id = ? THEN p.user_id
                       ELSE cu.user_id
                   END
                   ORDER BY last_activity DESC 
                   LIMIT 1
               ) as last_seen
        FROM conversations c
        LEFT JOIN provider_profiles p ON c.provider_id = p.id
        LEFT JOIN customer_profiles cu ON c.customer_id = cu.id
        LEFT JOIN users u_provider ON p.user_id = u_provider.id
        LEFT JOIN users u_customer ON cu.user_id = u_customer.id
        WHERE c.id = ? 
        AND (
            c.customer_id = ? 
            OR c.provider_id = (
                SELECT id FROM provider_profiles WHERE user_id = ?
            )
        )
    ");

    $stmt->execute([
        $decoded->user_id,
        $decoded->user_id,
        $decoded->user_id,
        $decoded->user_id,
        $decoded->user_id,
        $decoded->user_id,
        $decoded->user_id,
        $conversationId,
        $decoded->user_id,
        $decoded->user_id
    ]);

    $conversation = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$conversation) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Conversation not found']);
        exit;
    }

    // Get messages
    $stmt = $conn->prepare("
        SELECT 
            m.*,
            CASE 
                WHEN m.sender_id = ? THEN 1 
                ELSE 0 
            END as is_me
        FROM messages m
        WHERE m.conversation_id = ?
        ORDER BY m.created_at ASC
        LIMIT 100
    ");

    $stmt->execute([$decoded->user_id, $conversationId]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Mark received messages as read
    $stmt = $conn->prepare("
        UPDATE messages 
        SET is_read = 1, read_at = NOW()
        WHERE conversation_id = ? 
            AND receiver_id = ? 
            AND is_read = 0
    ");
    $stmt->execute([$conversationId, $decoded->user_id]);

    // Format messages
    foreach ($messages as &$message) {
        $message['created_at_formatted'] = date('g:i A', strtotime($message['created_at']));
        $message['date_formatted'] = date('M j, Y', strtotime($message['created_at']));
        $message['is_me'] = (bool)$message['is_me'];
        $message['is_read'] = (bool)$message['is_read'];
        
        // Parse message content based on type
        if ($message['type'] === 'file' || $message['type'] === 'image') {
            $message['content'] = json_decode($message['content'], true);
        }
    }

    // Group messages by date
    $groupedMessages = [];
    $currentDate = null;
    
    foreach ($messages as $message) {
        $date = date('Y-m-d', strtotime($message['created_at']));
        
        if ($date !== $currentDate) {
            $currentDate = $date;
            $groupedMessages[] = [
                'type' => 'date_separator',
                'date' => date('l, F j, Y', strtotime($date)),
                'messages' => []
            ];
        }
        
        $groupedMessages[count($groupedMessages) - 1]['messages'][] = $message;
    }

    // Get other user info
    $otherUser = [
        'id' => $conversation['other_user_profile_id'],
        'user_id' => $conversation['other_user_id'],
        'name' => $conversation['other_user_name'],
        'avatar' => $conversation['other_user_avatar'] ?: '/public/assets/images/avatars/default-avatar.jpg',
        'online' => (bool)$conversation['is_online'],
        'last_seen' => $conversation['last_seen']
    ];

    if ($conversation['last_seen']) {
        $lastSeen = new DateTime($conversation['last_seen']);
        $now = new DateTime();
        $diff = $now->diff($lastSeen);
        
        if ($diff->days == 0) {
            if ($diff->h == 0) {
                if ($diff->i == 0) {
                    $otherUser['last_seen_formatted'] = 'Just now';
                } else {
                    $otherUser['last_seen_formatted'] = $diff->i . ' minutes ago';
                }
            } else {
                $otherUser['last_seen_formatted'] = 'Today at ' . $lastSeen->format('g:i A');
            }
        } elseif ($diff->days == 1) {
            $otherUser['last_seen_formatted'] = 'Yesterday at ' . $lastSeen->format('g:i A');
        } else {
            $otherUser['last_seen_formatted'] = $lastSeen->format('M j, Y \a\\t g:i A');
        }
    }

    echo json_encode([
        'success' => true,
        'conversation' => [
            'id' => $conversation['id'],
            'created_at' => $conversation['created_at'],
            'related_request_id' => $conversation['request_id']
        ],
        'other_user' => $otherUser,
        'messages' => $groupedMessages
    ]);

} catch (Exception $e) {
    error_log("Get conversation error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred']);
}
?>