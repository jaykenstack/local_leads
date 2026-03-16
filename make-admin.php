<?php
// make-admin.php - Fix admin creation script

// Display errors for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>🔧 Admin Creation Tool</h2>";

// Define the correct path to your config file
$config_path = __DIR__ . '/config/database.php';

echo "Looking for config at: " . $config_path . "<br>";

if (!file_exists($config_path)) {
    die("❌ Config file not found at: " . $config_path);
}

echo "✅ Config file found!<br>";

require_once $config_path;

// Check if form was submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    
    if (empty($email)) {
        echo "<p style='color: red;'>❌ Please enter an email address</p>";
    } else {
        try {
            $db = new Database();
            $conn = $db->getConnection();
            
            // First, check if user exists
            $check = $conn->prepare("SELECT id, email, is_admin FROM users WHERE email = ?");
            $check->execute([$email]);
            $user = $check->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                echo "<p style='color: red;'>❌ User with email '$email' not found!</p>";
            } else {
                // Update to admin
                $stmt = $conn->prepare("UPDATE users SET is_admin = 1 WHERE email = ?");
                $stmt->execute([$email]);
                
                if ($stmt->rowCount() > 0) {
                    echo "<p style='color: green; font-weight: bold;'>✅ SUCCESS! User '$email' is now an admin!</p>";
                    echo "<p>Current status:</p>";
                    echo "<ul>";
                    echo "<li>User ID: " . $user['id'] . "</li>";
                    echo "<li>Email: " . $user['email'] . "</li>";
                    echo "<li>Previous admin status: " . ($user['is_admin'] ? 'Yes' : 'No') . "</li>";
                    echo "<li>New admin status: Yes</li>";
                    echo "</ul>";
                } else {
                    echo "<p style='color: orange;'>⚠️ User already an admin or no changes made.</p>";
                }
            }
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ Database error: " . $e->getMessage() . "</p>";
        }
    }
}

// Show all users (for reference)
try {
    $db = new Database();
    $conn = $db->getConnection();
    
    $users = $conn->query("SELECT id, email, first_name, last_name, is_admin FROM users LIMIT 10");
    
    echo "<h3>📋 First 10 Users in Database:</h3>";
    echo "<table border='1' cellpadding='8' style='border-collapse: collapse;'>";
    echo "<tr><th>ID</th><th>Email</th><th>Name</th><th>Is Admin?</th></tr>";
    
    while ($user = $users->fetch(PDO::FETCH_ASSOC)) {
        $adminStatus = $user['is_admin'] ? '✅ Yes' : '❌ No';
        echo "<tr>";
        echo "<td>" . $user['id'] . "</td>";
        echo "<td>" . $user['email'] . "</td>";
        echo "<td>" . $user['first_name'] . " " . $user['last_name'] . "</td>";
        echo "<td>" . $adminStatus . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} catch (Exception $e) {
    // Silently fail if can't show users
}
?>

<h3>✏️ Make a User Admin</h3>
<form method="POST">
    <label for="email">Email Address:</label><br>
    <input type="email" name="email" id="email" required style="padding: 8px; width: 300px; margin: 10px 0;"><br>
    <button type="submit" style="padding: 10px 20px; background: #3b82f6; color: white; border: none; border-radius: 5px; cursor: pointer;">
        Make Admin
    </button>
</form>

<p style="margin-top: 20px; color: #666;">
    ⚠️ <strong>Security Note:</strong> Delete this file after use!
</p>