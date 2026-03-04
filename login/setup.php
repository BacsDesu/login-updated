<?php
// setup.php - Automated Database Setup Script
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database configuration
$host = 'localhost';
$db_username = 'root';
$db_password = '';
$dbname = 'login_system';

$setup_complete = false;
$messages = [];
$errors = [];

function addMessage($msg, $type = 'info') {
    global $messages;
    $messages[] = ['text' => $msg, 'type' => $type];
}

function addError($msg) {
    global $errors;
    $errors[] = $msg;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['setup'])) {
    try {
        // Step 1: Connect to MySQL (without database)
        addMessage("Connecting to MySQL server...", 'info');
        $pdo = new PDO("mysql:host=$host", $db_username, $db_password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        addMessage("✓ Connected to MySQL server", 'success');
        
        // Step 2: Create database if not exists
        addMessage("Creating database '$dbname'...", 'info');
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        addMessage("✓ Database '$dbname' created/verified", 'success');
        
        // Step 3: Select database
        $pdo->exec("USE `$dbname`");
        addMessage("✓ Using database '$dbname'", 'success');
        
        // Step 4: Create users table
        addMessage("Creating 'users' table...", 'info');
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `users` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `email` varchar(255) NOT NULL,
                `password` varchar(255) NOT NULL,
                `full_name` varchar(255) NOT NULL,
                `is_active` tinyint(1) DEFAULT 1,
                `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `last_login` timestamp NULL DEFAULT NULL,
                `remember_token` varchar(100) DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `email` (`email`),
                KEY `email_index` (`email`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        addMessage("✓ Table 'users' created", 'success');
        
        // Step 5: Create login_attempts table (optional)
        addMessage("Creating 'login_attempts' table...", 'info');
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `login_attempts` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `email` varchar(255) NOT NULL,
                `ip_address` varchar(45) NOT NULL,
                `success` tinyint(1) NOT NULL DEFAULT 0,
                `user_agent` text,
                `attempt_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `email_index` (`email`),
                KEY `time_index` (`attempt_time`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        addMessage("✓ Table 'login_attempts' created", 'success');
        
        // Step 6: Create password_resets table (optional - for future use)
        addMessage("Creating 'password_resets' table...", 'info');
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `password_resets` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `email` varchar(255) NOT NULL,
                `token` varchar(255) NOT NULL,
                `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `expires_at` timestamp NOT NULL,
                PRIMARY KEY (`id`),
                KEY `email_index` (`email`),
                KEY `token_index` (`token`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        addMessage("✓ Table 'password_resets' created", 'success');
        
        // Step 7: Create form builder tables
        addMessage("Creating form builder tables...", 'info');
        
        // Form templates table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `form_templates` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `title` varchar(255) NOT NULL,
                `event_target` varchar(255) DEFAULT NULL,
                `deadline` date DEFAULT NULL,
                `fields` JSON NOT NULL,
                `is_active` tinyint(1) DEFAULT 1,
                `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        addMessage("✓ Table 'form_templates' created", 'success');
        
        // Form submissions table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `form_submissions` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `form_id` int(11) NOT NULL,
                `user_id` int(11) NOT NULL,
                `answers` JSON NOT NULL,
                `status` enum('Submitted','Approved','Waitlisted','Rejected') DEFAULT 'Submitted',
                `submitted_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `reviewed_at` timestamp NULL DEFAULT NULL,
                `reviewer_notes` text DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `form_id_index` (`form_id`),
                KEY `user_id_index` (`user_id`),
                KEY `status_index` (`status`),
                FOREIGN KEY (`form_id`) REFERENCES `form_templates`(`id`) ON DELETE CASCADE,
                FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        addMessage("✓ Table 'form_submissions' created", 'success');
        
        // Events table for form targeting
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `events` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `title` varchar(255) NOT NULL,
                `description` text DEFAULT NULL,
                `start_date` date DEFAULT NULL,
                `end_date` date DEFAULT NULL,
                `location` varchar(255) DEFAULT NULL,
                `capacity` int(11) DEFAULT NULL,
                `is_active` tinyint(1) DEFAULT 1,
                `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        addMessage("✓ Table 'events' created", 'success');
        
        // Insert sample events
        $pdo->exec("
            INSERT IGNORE INTO `events` (title, description, start_date, end_date, location, capacity) VALUES
            ('Summer Pop-Up Market', 'Annual summer market event', '2024-06-15', '2024-06-17', 'Main Square', 30),
            ('Night Market Festival', 'Evening market with food and crafts', '2024-07-01', '2024-07-03', 'Downtown Plaza', 25),
            ('Spring Bazaar', 'Spring seasonal market', '2024-03-20', '2024-03-22', 'Community Center', 20)
        ");
        addMessage("✓ Sample events inserted", 'success');
        
        // Step 8: Insert demo user (optional)
        if (isset($_POST['create_demo_user'])) {
            addMessage("Creating demo user...", 'info');
            
            $demo_email = 'demo@example.com';
            $demo_password = 'demo123456';
            $demo_name = 'Demo User';
            
            // Check if demo user exists
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$demo_email]);
            
            if ($stmt->fetch()) {
                addMessage("! Demo user already exists", 'warning');
            } else {
                $hashed_password = password_hash($demo_password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (email, password, full_name) VALUES (?, ?, ?)");
                $stmt->execute([$demo_email, $hashed_password, $demo_name]);
                
                addMessage("✓ Demo user created successfully!", 'success');
                addMessage("Email: $demo_email", 'info');
                addMessage("Password: $demo_password", 'info');
            }
        }
        
        // Step 8: Verify setup
        addMessage("Verifying database setup...", 'info');
        $stmt = $pdo->query("SHOW TABLES");
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        addMessage("✓ Found " . count($tables) . " tables: " . implode(', ', $tables), 'success');
        
        $setup_complete = true;
        addMessage("🎉 Setup completed successfully!", 'success');
        
    } catch (PDOException $e) {
        addError("Database Error: " . $e->getMessage());
        addMessage("✗ Setup failed", 'error');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Setup</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .setup-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            max-width: 700px;
            width: 100%;
            overflow: hidden;
        }
        
        .setup-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .setup-header h1 {
            font-size: 32px;
            margin-bottom: 10px;
        }
        
        .setup-header p {
            font-size: 16px;
            opacity: 0.9;
        }
        
        .setup-content {
            padding: 30px;
        }
        
        .info-box {
            background: #f8f9fa;
            border-left: 4px solid #667eea;
            padding: 20px;
            margin-bottom: 30px;
            border-radius: 5px;
        }
        
        .info-box h3 {
            color: #333;
            margin-bottom: 10px;
            font-size: 18px;
        }
        
        .info-box ul {
            list-style: none;
            padding-left: 0;
        }
        
        .info-box li {
            padding: 5px 0;
            color: #666;
        }
        
        .info-box li:before {
            content: "✓ ";
            color: #667eea;
            font-weight: bold;
            margin-right: 8px;
        }
        
        .config-info {
            background: #fff3cd;
            border: 1px solid #ffc107;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        
        .config-info strong {
            display: block;
            margin-bottom: 10px;
            color: #856404;
        }
        
        .config-info code {
            background: white;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'Courier New', monospace;
            font-size: 14px;
        }
        
        .checkbox-group {
            margin: 20px 0;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 5px;
        }
        
        .checkbox-group label {
            display: flex;
            align-items: center;
            cursor: pointer;
            font-size: 16px;
            color: #333;
        }
        
        .checkbox-group input[type="checkbox"] {
            width: 20px;
            height: 20px;
            margin-right: 10px;
            cursor: pointer;
        }
        
        .btn {
            width: 100%;
            padding: 15px;
            border: none;
            border-radius: 8px;
            font-size: 18px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.4);
        }
        
        .btn-success {
            background: #28a745;
            color: white;
        }
        
        .messages {
            margin-top: 30px;
        }
        
        .message {
            padding: 12px 15px;
            margin-bottom: 10px;
            border-radius: 5px;
            font-size: 14px;
            display: flex;
            align-items: center;
        }
        
        .message:before {
            margin-right: 10px;
            font-size: 18px;
        }
        
        .message-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .message-success:before {
            content: "✓";
        }
        
        .message-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .message-error:before {
            content: "✗";
        }
        
        .message-warning {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }
        
        .message-warning:before {
            content: "!";
        }
        
        .message-info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }
        
        .message-info:before {
            content: "ℹ";
        }
        
        .success-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        
        .success-actions a {
            flex: 1;
            padding: 12px;
            text-align: center;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .link-primary {
            background: #667eea;
            color: white;
        }
        
        .link-primary:hover {
            background: #5568d3;
            transform: translateY(-2px);
        }
        
        .link-secondary {
            background: #6c757d;
            color: white;
        }
        
        .link-secondary:hover {
            background: #5a6268;
            transform: translateY(-2px);
        }
        
        .spinner {
            display: none;
            text-align: center;
            padding: 20px;
        }
        
        .spinner.active {
            display: block;
        }
        
        .spinner-icon {
            display: inline-block;
            width: 40px;
            height: 40px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="setup-container">
        <div class="setup-header">
            <h1>🗄️ Database Setup</h1>
            <p>Automated Installation for Login System</p>
        </div>
        
        <div class="setup-content">
            <?php if (!$setup_complete): ?>
                <div class="info-box">
                    <h3>What will be created:</h3>
                    <ul>
                        <li>Database: <strong><?php echo htmlspecialchars($dbname); ?></strong></li>
                        <li>Table: <strong>users</strong> (for user accounts)</li>
                        <li>Table: <strong>login_attempts</strong> (for security tracking)</li>
                        <li>Table: <strong>password_resets</strong> (for password recovery)</li>
                    </ul>
                </div>
                
                <div class="config-info">
                    <strong>Current Configuration:</strong>
                    Host: <code><?php echo htmlspecialchars($host); ?></code><br>
                    Database: <code><?php echo htmlspecialchars($dbname); ?></code><br>
                    Username: <code><?php echo htmlspecialchars($db_username); ?></code>
                </div>
                
                <form method="POST" id="setupForm">
                    <div class="checkbox-group">
                        <label>
                            <input type="checkbox" name="create_demo_user" checked>
                            Create demo user account (Email: demo@example.com, Password: demo123456)
                        </label>
                    </div>
                    
                    <button type="submit" name="setup" class="btn btn-primary">
                        🚀 Start Setup
                    </button>
                </form>
                
                <div class="spinner" id="spinner">
                    <div class="spinner-icon"></div>
                    <p>Setting up database...</p>
                </div>
                
            <?php else: ?>
                <div class="messages">
                    <?php foreach ($messages as $msg): ?>
                        <div class="message message-<?php echo $msg['type']; ?>">
                            <?php echo htmlspecialchars($msg['text']); ?>
                        </div>
                    <?php endforeach; ?>
                    
                    <?php foreach ($errors as $error): ?>
                        <div class="message message-error">
                            <?php echo htmlspecialchars($error); ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="success-actions">
                    <a href="index.php" class="link-primary">Go to Login Page</a>
                    <a href="database_check.php" class="link-secondary">Check Database</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        const setupForm = document.getElementById('setupForm');
        const spinner = document.getElementById('spinner');
        
        if (setupForm) {
            setupForm.addEventListener('submit', function() {
                spinner.classList.add('active');
                this.querySelector('button').disabled = true;
            });
        }
    </script>
</body>
</html>