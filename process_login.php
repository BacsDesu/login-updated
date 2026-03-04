<?php
session_start();

// Database configuration
$host = 'localhost';
$dbname = 'login_system';
$db_username = 'root';
$db_password = '';

// Get POST data
$email = $_POST['email'] ?? '';
$password = $_POST['password'] ?? '';
$remember = isset($_POST['remember']);

// Validate input
if (empty($email) || empty($password)) {
    $_SESSION['login_error'] = 'Please fill in all fields';
    header('Location: index.php');
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['login_error'] = 'Please enter a valid email address';
    header('Location: index.php');
    exit;
}

try {
    // Connect to database
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $db_username, $db_password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Ensure role column exists (added after initial setup)
    $rc = $pdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='role'")->fetchColumn();
    if ((int)$rc === 0) {
        $pdo->exec("ALTER TABLE users ADD COLUMN role varchar(20) NOT NULL DEFAULT 'user'");
    }

    // Get user from database — includes role
    $stmt = $pdo->prepare("SELECT id, email, password, full_name, is_active, role FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Check if user exists and password is correct
    if ($user && password_verify($password, $user['password'])) {
        
        // Check if account is active
        if (!$user['is_active']) {
            $_SESSION['login_error'] = 'Your account has been deactivated. Please contact support.';
            header('Location: index.php');
            exit;
        }
        
        // Login successful - set session variables
        $_SESSION['user_id']    = $user['id'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_name']  = $user['full_name'];
        $_SESSION['user_role']  = $user['role'];   // ← stores 'admin' or 'user'
        $_SESSION['logged_in']  = true;
        
        // Update last login
        $stmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
        $stmt->execute([$user['id']]);
        
        // Handle remember me
        if ($remember) {
            $token = bin2hex(random_bytes(32));
            setcookie('remember_token', $token, time() + (30 * 24 * 60 * 60), '/');
            
            $stmt = $pdo->prepare("UPDATE users SET remember_token = ? WHERE id = ?");
            $stmt->execute([$token, $user['id']]);
        }
        
        // Log successful login
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        
        try {
            $stmt = $pdo->prepare("INSERT INTO login_attempts (email, ip_address, success, user_agent) VALUES (?, ?, 1, ?)");
            $stmt->execute([$email, $ip_address, $user_agent]);
        } catch (PDOException $e) {
            // Table might not exist, continue anyway
        }
        
        // Redirect admin to admin dashboard, regular users to dashboard
        if ($user['role'] === 'admin') {
            header('Location: admin_dashboard.php');
        } else {
            header('Location: dashboard.php');
        }
        exit;
        
    } else {
        // Login failed
        
        // Log failed login
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        
        try {
            $stmt = $pdo->prepare("INSERT INTO login_attempts (email, ip_address, success, user_agent) VALUES (?, ?, 0, ?)");
            $stmt->execute([$email, $ip_address, $user_agent]);
        } catch (PDOException $e) {
            // Table might not exist, continue anyway
        }
        
        $_SESSION['login_error'] = 'Invalid email or password';
        header('Location: index.php');
        exit;
    }
    
} catch (PDOException $e) {
    $_SESSION['login_error'] = 'Database error. Please try again later.';
    error_log("Login error: " . $e->getMessage());
    header('Location: index.php');
    exit;
}
?>