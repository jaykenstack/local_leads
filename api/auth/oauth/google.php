<?php
/**
 * Google OAuth Login
 */

require_once __DIR__ . '/../../../config/database.php';
require_once '../../config/app.php';
require_once '../../vendor/autoload.php';

use Firebase\JWT\JWT;

session_start();

$clientId = GOOGLE_OAUTH_CLIENT_ID;
$clientSecret = GOOGLE_OAUTH_SECRET;
$redirectUri = APP_URL . "/api/auth/oauth/google.php";

// Determine action
if (isset($_GET['code'])) {
    // Handle OAuth callback
    handleGoogleCallback($clientId, $clientSecret, $redirectUri);
} else {
    // Redirect to Google login
    redirectToGoogle($clientId, $redirectUri);
}

/**
 * Redirect to Google OAuth page
 */
function redirectToGoogle($clientId, $redirectUri) {
    $authUrl = "https://accounts.google.com/o/oauth2/v2/auth?" . http_build_query([
        'client_id' => $clientId,
        'redirect_uri' => $redirectUri,
        'response_type' => 'code',
        'scope' => 'email profile',
        'access_type' => 'online',
        'state' => session_id()
    ]);
    
    header('Location: ' . $authUrl);
    exit;
}

/**
 * Handle Google OAuth callback
 */
function handleGoogleCallback($clientId, $clientSecret, $redirectUri) {
    try {
        // Verify state
        if ($_GET['state'] !== session_id()) {
            throw new Exception('Invalid state parameter');
        }

        // Exchange code for access token
        $tokenData = exchangeCodeForToken($_GET['code'], $clientId, $clientSecret, $redirectUri);
        
        // Get user info from Google
        $userInfo = getGoogleUserInfo($tokenData['access_token']);
        
        // Get user type from session
        $userType = $_SESSION['social_login_type'] ?? 'customer';
        
        // Process login/registration
        $result = processSocialLogin($userInfo, 'google', $userType);
        
        // Return to frontend with token
        $script = "
        <script>
            window.opener.postMessage({
                type: 'social-login-success',
                access_token: '{$result['access_token']}',
                refresh_token: '{$result['refresh_token']}',
                redirect_url: '{$result['redirect_url']}'
            }, '" . APP_URL . "');
            window.close();
        </script>
        ";
        
        echo $script;
        
    } catch (Exception $e) {
        error_log("Google OAuth error: " . $e->getMessage());
        echo "<script>window.close();</script>";
    }
}

/**
 * Exchange authorization code for access token
 */
function exchangeCodeForToken($code, $clientId, $clientSecret, $redirectUri) {
    $url = 'https://oauth2.googleapis.com/token';
    
    $data = [
        'code' => $code,
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri' => $redirectUri,
        'grant_type' => 'authorization_code'
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        throw new Exception('Failed to exchange code for token');
    }

    return json_decode($response, true);
}

/**
 * Get user info from Google
 */
function getGoogleUserInfo($accessToken) {
    $url = 'https://www.googleapis.com/oauth2/v2/userinfo';
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $accessToken]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        throw new Exception('Failed to get user info');
    }

    return json_decode($response, true);
}

/**
 * Process social login (create or update user)
 */
function processSocialLogin($userInfo, $provider, $userType) {
    $db = new Database();
    $conn = $db->getConnection();

    $email = $userInfo['email'];
    $firstName = $userInfo['given_name'] ?? explode(' ', $userInfo['name'])[0];
    $lastName = $userInfo['family_name'] ?? '';
    $avatarUrl = $userInfo['picture'] ?? null;

    // Check if user exists
    $stmt = $conn->prepare("SELECT id, user_type FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $existingUser = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existingUser) {
        // Update existing user
        $userId = $existingUser['id'];
        $userType = $existingUser['user_type'];
        
        $stmt = $conn->prepare("
            UPDATE users 
            SET last_login = NOW(),
                avatar_url = COALESCE(?, avatar_url)
            WHERE id = ?
        ");
        $stmt->execute([$avatarUrl, $userId]);
        
    } else {
        // Create new user
        $stmt = $conn->prepare("
            INSERT INTO users (
                email, user_type, first_name, last_name, avatar_url,
                email_verified, created_at, last_login
            ) VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())
        ");
        $stmt->execute([$email, $userType, $firstName, $lastName, $avatarUrl]);
        $userId = $conn->lastInsertId();

        // Create profile based on user type
        if ($userType === 'customer') {
            $stmt = $conn->prepare("
                INSERT INTO customer_profiles (user_id, created_at)
                VALUES (?, NOW())
            ");
            $stmt->execute([$userId]);
        } else {
            $stmt = $conn->prepare("
                INSERT INTO provider_profiles (user_id, business_name, created_at)
                VALUES (?, ?, NOW())
            ");
            $stmt->execute([$userId, $firstName . ' ' . $lastName . "'s Services"]);
        }
    }

    // Generate tokens
    $accessToken = generateAccessToken($userId, $userType);
    $refreshToken = generateRefreshToken($userId);

    // Store refresh token
    $expiresAt = date('Y-m-d H:i:s', strtotime('+30 days'));
    $stmt = $conn->prepare("
        INSERT INTO refresh_tokens (user_id, token, expires_at, created_at)
        VALUES (?, ?, ?, NOW())
    ");
    $stmt->execute([$userId, $refreshToken, $expiresAt]);

    // Store OAuth provider info
    $stmt = $conn->prepare("
        INSERT INTO user_oauth (user_id, provider, provider_id, created_at)
        VALUES (?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE last_login = NOW()
    ");
    $stmt->execute([$userId, $provider, $userInfo['id']]);

    return [
        'access_token' => $accessToken,
        'refresh_token' => $refreshToken,
        'redirect_url' => $userType === 'provider' ? '/src/dashboard/provider/' : '/src/dashboard/customer/'
    ];
}

/**
 * Generate JWT access token
 */
function generateAccessToken($userId, $userType) {
    $payload = [
        'user_id' => $userId,
        'user_type' => $userType,
        'iat' => time(),
        'exp' => time() + 3600,
        'jti' => bin2hex(random_bytes(16))
    ];
    
    return JWT::encode($payload, JWT_SECRET, 'HS256');
}

/**
 * Generate refresh token
 */
function generateRefreshToken($userId) {
    return bin2hex(random_bytes(64));
}
?>