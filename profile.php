<?php
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit;
}

$host = 'localhost';
$dbname = 'login_system';
$db_username = 'root';
$db_password = '';

$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
    header('Location: index.php');
    exit;
}

$fullName = '';
$email = '';
$stallName = '';
$avatarPath = '';
$successMessage = '';
$errorMessage = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $db_username, $db_password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $fullName = trim($_POST['full_name'] ?? '');
        $stallName = trim($_POST['stall_name'] ?? '');

        if ($fullName === '') {
            $errorMessage = 'Name is required.';
        }

        $newAvatarPath = null;
        if (!$errorMessage && isset($_FILES['avatar']) && $_FILES['avatar']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
                $tmpName = $_FILES['avatar']['tmp_name'];
                $fileSize = $_FILES['avatar']['size'];
                $fileName = $_FILES['avatar']['name'];

                if ($fileSize > 2 * 1024 * 1024) {
                    $errorMessage = 'Avatar must be less than 2MB.';
                } else {
                    $imageInfo = @getimagesize($tmpName);
                    if ($imageInfo === false) {
                        $errorMessage = 'Please upload a valid image file.';
                    } else {
                        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
                        if (!in_array($ext, $allowed, true)) {
                            $errorMessage = 'Allowed avatar types: JPG, PNG, GIF.';
                        } else {
                            $uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'avatars';
                            if (!is_dir($uploadDir)) {
                                mkdir($uploadDir, 0777, true);
                            }
                            $newFileName = 'user_' . $userId . '_' . time() . '.' . $ext;
                            $targetPath = $uploadDir . DIRECTORY_SEPARATOR . $newFileName;
                            if (move_uploaded_file($tmpName, $targetPath)) {
                                $newAvatarPath = 'uploads/avatars/' . $newFileName;
                            } else {
                                $errorMessage = 'Failed to save avatar.';
                            }
                        }
                    }
                }
            } else {
                $errorMessage = 'Error uploading avatar.';
            }
        }

        if (!$errorMessage) {
            if ($newAvatarPath !== null) {
                $stmt = $pdo->prepare('UPDATE users SET full_name = ?, stall_name = ?, avatar_path = ? WHERE id = ?');
                $stmt->execute([$fullName, $stallName !== '' ? $stallName : null, $newAvatarPath, $userId]);
                $avatarPath = $newAvatarPath;
            } else {
                $stmt = $pdo->prepare('UPDATE users SET full_name = ?, stall_name = ? WHERE id = ?');
                $stmt->execute([$fullName, $stallName !== '' ? $stallName : null, $userId]);
            }

            $_SESSION['user_name'] = $fullName;
            $successMessage = 'Profile updated successfully.';
        }
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $errorMessage) {
        $stmt = $pdo->prepare('SELECT full_name, email, stall_name, avatar_path FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            if (!$fullName) {
                $fullName = $user['full_name'] ?? '';
            }
            if (!$stallName) {
                $stallName = $user['stall_name'] ?? '';
            }
            $email = $user['email'] ?? '';
            if (!$avatarPath) {
                $avatarPath = $user['avatar_path'] ?? '';
            }
        }
    }
} catch (PDOException $e) {
    $errorMessage = 'Database error. Please try again later.';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Profile</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #0f172a, #020617);
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: #e5e7eb;
        }
        .profile-wrapper {
            width: 100%;
            max-width: 480px;
            background: rgba(15, 23, 42, 0.95);
            border-radius: 20px;
            padding: 24px 24px 20px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.6);
            backdrop-filter: blur(18px);
            transform: translateY(16px);
            opacity: 0;
            animation: fadeInUp 0.5s forwards;
        }
        .profile-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 18px;
        }
        .profile-title {
            font-size: 20px;
            font-weight: 600;
        }
        .back-link {
            font-size: 13px;
            color: #93c5fd;
            text-decoration: none;
        }
        .back-link:hover {
            text-decoration: underline;
        }
        .avatar-preview {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 16px;
        }
        .avatar-circle-lg {
            width: 72px;
            height: 72px;
            border-radius: 999px;
            background: linear-gradient(135deg, #38bdf8, #a855f7);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            font-weight: 700;
            color: #0b1120;
            overflow: hidden;
        }
        .avatar-circle-lg img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .avatar-text {
            font-size: 13px;
            color: #9ca3af;
        }
        form {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        label {
            font-size: 13px;
            color: #9ca3af;
        }
        input[type="text"],
        input[type="email"],
        input[type="file"] {
            padding: 10px 12px;
            border-radius: 10px;
            border: 1px solid #4b5563;
            background: #020617;
            color: #e5e7eb;
            font-size: 14px;
            outline: none;
        }
        input[type="text"]:focus,
        input[type="email"]:focus {
            border-color: #60a5fa;
            box-shadow: 0 0 0 1px rgba(96,165,250,0.3);
        }
        .hint {
            font-size: 11px;
            color: #6b7280;
        }
        .btn-row {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 10px;
        }
        .btn {
            border: none;
            border-radius: 999px;
            padding: 9px 18px;
            font-size: 14px;
            cursor: pointer;
        }
        .btn-secondary {
            background: rgba(31, 41, 55, 0.9);
            color: #e5e7eb;
        }
        .btn-primary {
            background: linear-gradient(135deg, #3b82f6, #22c55e);
            color: #0f172a;
            font-weight: 600;
        }
        .message {
            font-size: 13px;
            margin-bottom: 10px;
            padding: 8px 10px;
            border-radius: 8px;
        }
        .message-success {
            background: rgba(22,163,74,0.15);
            color: #bbf7d0;
            border: 1px solid rgba(34,197,94,0.4);
        }
        .message-error {
            background: rgba(248,113,113,0.12);
            color: #fecaca;
            border: 1px solid rgba(248,113,113,0.5);
        }
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(16px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>
</head>
<body>
<div class="profile-wrapper">
    <div class="profile-header">
        <div class="profile-title">Edit profile</div>
        <a href="dashboard_ui.php" class="back-link">Back to dashboard</a>
    </div>

    <?php if ($successMessage): ?>
        <div class="message message-success"><?php echo htmlspecialchars($successMessage); ?></div>
    <?php endif; ?>

    <?php if ($errorMessage): ?>
        <div class="message message-error"><?php echo htmlspecialchars($errorMessage); ?></div>
    <?php endif; ?>

    <div class="avatar-preview">
        <div class="avatar-circle-lg" id="avatarPreview">
            <?php if ($avatarPath): ?>
                <img src="<?php echo htmlspecialchars($avatarPath); ?>" alt="Avatar">
            <?php else: ?>
                <?php echo htmlspecialchars(strtoupper(substr($fullName !== '' ? $fullName : $email, 0, 1))); ?>
            <?php endif; ?>
        </div>
        <div class="avatar-text">
            You can upload an image for your profile avatar.<br>
            JPG, PNG, or GIF, max 2MB.
        </div>
    </div>

    <form method="post" enctype="multipart/form-data">
        <div class="form-group">
            <label for="full_name">Name</label>
            <input type="text" id="full_name" name="full_name" value="<?php echo htmlspecialchars($fullName); ?>" required>
        </div>

        <div class="form-group">
            <label for="stall_name">Stall name</label>
            <input type="text" id="stall_name" name="stall_name" value="<?php echo htmlspecialchars($stallName); ?>">
            <div class="hint">This name can appear on your dashboard and permits.</div>
        </div>

        <div class="form-group">
            <label for="email">Email (read-only)</label>
            <input type="email" id="email" value="<?php echo htmlspecialchars($email); ?>" disabled>
        </div>

        <div class="form-group">
            <label for="avatar">Avatar</label>
            <input type="file" id="avatar" name="avatar" accept="image/*">
        </div>

        <div class="btn-row">
            <a href="dashboard_ui.php" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">Save changes</button>
        </div>
    </form>
</div>

<script>
    const avatarInput = document.getElementById('avatar');
    const avatarPreview = document.getElementById('avatarPreview');

    if (avatarInput && avatarPreview) {
        avatarInput.addEventListener('change', function () {
            const file = this.files && this.files[0];
            if (!file) return;
            if (!file.type.startsWith('image/')) return;
            const reader = new FileReader();
            reader.onload = function (e) {
                avatarPreview.innerHTML = '';
                const img = document.createElement('img');
                img.src = e.target.result;
                avatarPreview.appendChild(img);
            };
            reader.readAsDataURL(file);
        });
    }
</script>
</body>
</html>