<?php
// process_profile.php — Handles profile update (name, stall name, bio, avatar)
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$is_ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
if (!$is_ajax) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$host        = 'localhost';
$dbname      = 'login_system';
$db_username = 'root';
$db_password = '';

$user_id   = (int)$_SESSION['user_id'];
$full_name = trim($_POST['full_name']  ?? '');
$stall_name= trim($_POST['stall_name'] ?? '');
$bio       = trim($_POST['bio']        ?? '');

// ── Validate ──────────────────────────────────────────
if (empty($full_name)) {
    echo json_encode(['success' => false, 'message' => 'Full name is required.']);
    exit;
}
if (strlen($full_name) < 2 || strlen($full_name) > 100) {
    echo json_encode(['success' => false, 'message' => 'Name must be 2–100 characters.']);
    exit;
}
if (strlen($stall_name) > 100) {
    echo json_encode(['success' => false, 'message' => 'Stall name must be under 100 characters.']);
    exit;
}
if (strlen($bio) > 500) {
    echo json_encode(['success' => false, 'message' => 'Bio must be under 500 characters.']);
    exit;
}

// ── Avatar Upload ─────────────────────────────────────
$avatar_path = null;
$avatar_updated = false;

if (!empty($_FILES['avatar']['name'])) {
    $file     = $_FILES['avatar'];
    $maxSize  = 3 * 1024 * 1024; // 3MB
    $allowed  = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'Upload failed. Please try again.']);
        exit;
    }
    if ($file['size'] > $maxSize) {
        echo json_encode(['success' => false, 'message' => 'Image must be under 3MB.']);
        exit;
    }

    // Validate actual MIME type (not just extension)
    $finfo    = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, $allowed)) {
        echo json_encode(['success' => false, 'message' => 'Only JPEG, PNG, WebP or GIF images allowed.']);
        exit;
    }

    $ext      = match($mimeType) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
        default      => 'jpg',
    };

    $uploadDir = __DIR__ . '/uploads/avatars/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $filename   = 'avatar_' . $user_id . '_' . time() . '.' . $ext;
    $uploadPath = $uploadDir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
        echo json_encode(['success' => false, 'message' => 'Could not save avatar. Check server permissions.']);
        exit;
    }

    $avatar_path    = 'uploads/avatars/' . $filename;
    $avatar_updated = true;

    // Delete old avatar to save space
    try {
        $pdo2 = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $db_username, $db_password);
        $pdo2->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $s = $pdo2->prepare("SELECT avatar_path FROM users WHERE id = ?");
        $s->execute([$user_id]);
        $old = $s->fetchColumn();
        if ($old && file_exists(__DIR__ . '/' . $old)) {
            @unlink(__DIR__ . '/' . $old);
        }
    } catch (Exception $e) {}
}

// ── Database Update ───────────────────────────────────
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $db_username, $db_password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if ($avatar_updated) {
        $stmt = $pdo->prepare("
            UPDATE users
            SET full_name = ?, stall_name = ?, bio = ?, avatar_path = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$full_name, $stall_name, $bio, $avatar_path, $user_id]);
    } else {
        $stmt = $pdo->prepare("
            UPDATE users
            SET full_name = ?, stall_name = ?, bio = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$full_name, $stall_name, $bio, $user_id]);
    }

    if ($stmt->rowCount() === 0) {
        // Might mean nothing changed — that's fine
    }

    // ── Refresh session ──
    $_SESSION['user_name']  = $full_name;
    if ($stall_name) {
        $_SESSION['stall_name'] = $stall_name;
    }

    // Fetch latest avatar_path from DB to return
    $s2 = $pdo->prepare("SELECT avatar_path, stall_name FROM users WHERE id = ?");
    $s2->execute([$user_id]);
    $row = $s2->fetch(PDO::FETCH_ASSOC);
    $final_avatar = $row['avatar_path'] ?? null;

    echo json_encode([
        'success'     => true,
        'message'     => 'Profile updated successfully!',
        'full_name'   => $full_name,
        'stall_name'  => $row['stall_name'] ?? '',
        'avatar_path' => $final_avatar ? $final_avatar . '?v=' . time() : null,
    ]);

} catch (PDOException $e) {
    error_log('Profile update error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
}
?>
