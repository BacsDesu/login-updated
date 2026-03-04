<?php
// test_password_reset.php - Test script for password reset functionality

$host = 'localhost';
$dbname = 'login_system';
$db_username = 'root';
$db_password = '';

echo "<h2>Password Reset Test Script</h2>";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $db_username, $db_password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Test 1: Check if password_resets table exists and has data
    echo "<h3>Test 1: Check password_resets table</h3>";
    $stmt = $pdo->query("SELECT * FROM password_resets ORDER BY created_at DESC LIMIT 5");
    $resets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($resets)) {
        echo "❌ No password reset codes found<br>";
    } else {
        echo "✅ Found " . count($resets) . " password reset code(s):<br>";
        foreach ($resets as $reset) {
            echo "- Email: {$reset['email']}, Code: {$reset['token']}, Expires: {$reset['expires_at']}<br>";
        }
    }
    
    // Test 2: Check users table
    echo "<h3>Test 2: Check users table</h3>";
    $stmt = $pdo->query("SELECT id, email, full_name FROM users ORDER BY created_at DESC LIMIT 5");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($users)) {
        echo "❌ No users found<br>";
    } else {
        echo "✅ Found " . count($users) . " user(s):<br>";
        foreach ($users as $user) {
            echo "- ID: {$user['id']}, Email: {$user['email']}, Name: {$user['full_name']}<br>";
        }
    }
    
    // Test 3: Simulate password update
    if (!empty($users)) {
        $testEmail = $users[0]['email'];
        echo "<h3>Test 3: Simulate password update for $testEmail</h3>";
        
        $testPassword = 'test123456';
        $hashedPassword = password_hash($testPassword, PASSWORD_DEFAULT);
        
        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE email = ?");
        $stmt->execute([$hashedPassword, $testEmail]);
        $rowsAffected = $stmt->rowCount();
        
        if ($rowsAffected > 0) {
            echo "✅ Password update successful! $rowsAffected row(s) affected<br>";
            echo "Test password set to: <strong>$testPassword</strong><br>";
            echo "Hashed: $hashedPassword<br>";
        } else {
            echo "❌ Password update failed! No rows affected<br>";
        }
        
        // Test 4: Verify the password
        echo "<h3>Test 4: Verify password</h3>";
        $stmt = $pdo->prepare("SELECT password FROM users WHERE email = ?");
        $stmt->execute([$testEmail]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (password_verify($testPassword, $user['password'])) {
            echo "✅ Password verification successful!<br>";
        } else {
            echo "❌ Password verification failed!<br>";
        }
    }
    
    echo "<h3>Summary</h3>";
    echo "✅ Database connection: OK<br>";
    echo "✅ password_resets table: OK<br>";
    echo "✅ users table: OK<br>";
    
} catch (PDOException $e) {
    echo "<p style='color: red;'>❌ Database Error: " . $e->getMessage() . "</p>";
}
?>

<style>
    body {
        font-family: Arial, sans-serif;
        max-width: 800px;
        margin: 50px auto;
        padding: 20px;
        background: #f5f5f5;
    }
    h2 {
        color: #333;
        border-bottom: 2px solid #9A4A69;
        padding-bottom: 10px;
    }
    h3 {
        color: #9A4A69;
        margin-top: 20px;
    }
</style>