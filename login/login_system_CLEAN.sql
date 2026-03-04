<?php
session_start();

// Check if AJAX request
$is_ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if (!$is_ajax) {
    // Not an AJAX request - redirect back to signup with message
    $_SESSION['signup_error'] = 'Please enable JavaScript to use the signup form.';
    header('Location: signup.php');
    exit;
}

// Set JSON header
header('Content-Type: application/json');

// Database configuration
$host = 'localhost';
$dbname = 'login_system';
$db_username = 'root';
$db_password = '';

// Get POST data
$full_name = trim($_POST['full_name'] ?? '');
$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';
$confirm_password = $_POST['confirm_password'] ?? '';

// Validate input
if (empty($full_name) || empty($email) || empty($password) || empty($confirm_password)) {
    echo json_encode(['success' => false, 'message' => 'Please fill in all fields']);
    exit;
}

if (strlen($full_name) < 2) {
    echo json_encode(['success' => false, 'message' => 'Please enter your full name']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address']);
    exit;
}

if (strlen($password) < 6) {
    echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters']);
    exit;
}

if ($password !== $confirm_password) {
    echo json_encode(['success' => false, 'message' => 'Passwords do not match']);
    exit;
}

try {
    // Connect to database
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $db_username, $db_password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Check if email already exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Email already registered. Please login instead.']);
        exit;
    }
    
    // Hash password
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    
    // Insert new user
    $stmt = $pdo->prepare("INSERT INTO users (full_name, email, password) VALUES (?, ?, ?)");
    $stmt->execute([$full_name, $email, $hashed_password]);
    
    $user_id = $pdo->lastInsertId();
    
    // Auto-login
    $_SESSION['user_id'] = $user_id;
    $_SESSION['user_email'] = $email;
    $_SESSION['user_name'] = $full_name;
    $_SESSION['logged_in'] = true;
    
    // Log registration
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    
    try {
        $stmt = $pdo->prepare("INSERT INTO login_attempts (email, ip_address, success, user_agent) VALUES (?, ?, 1, ?)");
        $stmt->execute([$email, $ip_address, $user_agent]);
    } catch (PDOException $e) {
        // Table might not exist
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Account created successfully! Redirecting...',
        'redirect' => 'dashboard.php'
    ]);
    
} catch (PDOException $e) {
    error_log("Signup error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again later.']);
}
-- profile_update.sql
-- Run this to add profile fields to existing users table

ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `stall_name`  VARCHAR(255)  DEFAULT NULL AFTER `full_name`,
    ADD COLUMN IF NOT EXISTS `avatar_path` VARCHAR(500)  DEFAULT NULL AFTER `stall_name`,
    ADD COLUMN IF NOT EXISTS `bio`         TEXT          DEFAULT NULL AFTER `avatar_path`;
?>