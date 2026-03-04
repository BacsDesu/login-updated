<?php
// database_check.php - Check database connection and users table

error_reporting(E_ALL);
ini_set('display_errors', 1);

$host = 'localhost';
$dbname = 'login_system';
$db_username = 'root';
$db_password = '';

echo "<h2>Database Connection Test</h2>";
echo "<style>body{font-family:Arial;padding:20px;} .success{color:green;} .error{color:red;} .info{color:blue;}</style>";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $db_username, $db_password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<p class='success'>✓ Database connection successful!</p>";
    
    // Check users table
    echo "<h3>Users Table Structure:</h3>";
    $stmt = $pdo->query("DESCRIBE users");
    echo "<table border='1' cellpadding='5' cellspacing='0'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['Field']) . "</td>";
        echo "<td>" . htmlspecialchars($row['Type']) . "</td>";
        echo "<td>" . htmlspecialchars($row['Null']) . "</td>";
        echo "<td>" . htmlspecialchars($row['Key']) . "</td>";
        echo "<td>" . htmlspecialchars($row['Default'] ?? 'NULL') . "</td>";
        echo "<td>" . htmlspecialchars($row['Extra']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // List all users
    echo "<h3>Current Users:</h3>";
    $stmt = $pdo->query("SELECT id, email, full_name, created_at FROM users ORDER BY id DESC");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($users)) {
        echo "<p class='error'>No users found in database</p>";
    } else {
        echo "<table border='1' cellpadding='5' cellspacing='0'>";
        echo "<tr><th>ID</th><th>Email</th><th>Full Name</th><th>Created At</th></tr>";
        foreach ($users as $user) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($user['id']) . "</td>";
            echo "<td>" . htmlspecialchars($user['email']) . "</td>";
            echo "<td>" . htmlspecialchars($user['full_name']) . "</td>";
            echo "<td>" . htmlspecialchars($user['created_at']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    // Test password update
    echo "<h3>Password Update Test:</h3>";
    if (!empty($users)) {
        $test_email = $users[0]['email'];
        $test_password = 'TestPassword123';
        $hashed = password_hash($test_password, PASSWORD_DEFAULT);
        
        echo "<p class='info'>Testing with email: " . htmlspecialchars($test_email) . "</p>";
        echo "<p class='info'>Test password: " . htmlspecialchars($test_password) . "</p>";
        
        // Perform update
        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE email = ?");
        $result = $stmt->execute([$hashed, $test_email]);
        $rows = $stmt->rowCount();
        
        echo "<p class='info'>Update executed: " . ($result ? 'YES' : 'NO') . "</p>";
        echo "<p class='info'>Rows affected: " . $rows . "</p>";
        
        if ($rows > 0) {
            // Verify
            $stmt = $pdo->prepare("SELECT password FROM users WHERE email = ?");
            $stmt->execute([$test_email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (password_verify($test_password, $user['password'])) {
                echo "<p class='success'>✓ Password update and verification SUCCESSFUL!</p>";
                echo "<p class='success'>You can now use this email and password to test login:</p>";
                echo "<p><strong>Email:</strong> " . htmlspecialchars($test_email) . "</p>";
                echo "<p><strong>Password:</strong> " . htmlspecialchars($test_password) . "</p>";
            } else {
                echo "<p class='error'>✗ Password verification FAILED</p>";
            }
        } else {
            echo "<p class='error'>✗ No rows were updated</p>";
        }
    }
    
} catch (PDOException $e) {
    echo "<p class='error'>✗ Database Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>

<hr>
<p><a href="forgot_password.php">Go to Password Reset Page</a></p>
<p><a href="index.php">Go to Login Page</a></p>