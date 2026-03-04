<?php
session_start();

// Check if user is already logged in
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    header('Location: dashboard.php');
    exit;
}

// Get error message if exists
$error_message = '';
if (isset($_SESSION['login_error'])) {
    $error_message = $_SESSION['login_error'];
    unset($_SESSION['login_error']);
}

// Get success message if exists
$success_message = '';
if (isset($_SESSION['success_message'])) {
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

// Check for password reset success
$password_reset_success = false;
if (isset($_SESSION['password_reset_success'])) {
    $password_reset_success = true;
    unset($_SESSION['password_reset_success']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - NISU SmartPOP</title>
    <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
</head>
<body>
    <img src="bg.jpg" class="bg" alt="Logo">
    <div class="login-container" id="loginContainer">
        <div class="login-left" id="loginLeft">
            <div class="logo-container">
                <div class="logo">
                    <img src="cherry_logo2.png" class="logo-image" alt="Logo">
                    <h1>NISU SmartPOP Shops</h1>
                </div>
                <p>Sign in to continue to your dashboard</p>
                <div class="click-hint">Click to continue →</div>
            </div>
        </div>
        
        <div class="login-right" id="loginRight">
            <div class="form-container">
                <h2>Login to Your Account</h2>
                
                <?php if (!empty($error_message)): ?>
                    <div class="alert alert-error">
                        <?php echo htmlspecialchars($error_message); ?>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($success_message)): ?>
                    <div class="alert alert-success">
                        <?php echo htmlspecialchars($success_message); ?>
                    </div>
                <?php endif; ?>
                
                <form id="loginForm" action="process_login.php" method="POST">
                    <div class="form-group">
                        <label for="email">Email Address</label>
                        <input type="email" id="email" name="email" required autocomplete="email">
                        <span class="input-icon">📧</span>
                    </div>
                    
                    <div class="form-group">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" required autocomplete="current-password">
                        <span class="input-icon">🔒</span>
                        <span class="toggle-password" onclick="togglePasswordVisibility()">👁</span>
                    </div>
                    
                    <div class="form-options">
                        <label class="remember-me">
                            <input type="checkbox" name="remember">
                            Remember me
                        </label>
                        <a href="forgot_password.php" class="forgot-password">Forgot Password?</a>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">Login</button>
                    
                    <div class="signup-link">
                        Don't have an account? <a href="signup.php">Sign up</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="script.js"></script>
    <script>
        function togglePasswordVisibility() {
            var passwordInput = document.getElementById('password');
            var toggleIcon = document.querySelector('.toggle-password');
            if (!passwordInput || !toggleIcon) return;
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.textContent = '🙈';
            } else {
                passwordInput.type = 'password';
                toggleIcon.textContent = '👁';
                
            }
        }
    </script>
    
    <?php if ($password_reset_success): ?>
    <script>
        // Show success alert
        window.addEventListener('DOMContentLoaded', function() {
            alert('Password reset successfully! You can now login with your new password.');
        });
    </script>
    <?php endif; ?>
</body>
</html>