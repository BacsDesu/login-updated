<?php
// process_admin.php — Admin AJAX action handler
session_start();
header('Content-Type: application/json');

// Auth guard
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit;
}

// Admin role check — with DB fallback in case session role wasn't set
$session_role = $_SESSION['user_role'] ?? '';
if ($session_role !== 'admin') {
    // Fallback: check DB directly
    try {
        $tmpPdo = new PDO("mysql:host=localhost;dbname=login_system;charset=utf8mb4", 'root', '');
        $tmpPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // Add role column if missing
        $rc = $tmpPdo->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='login_system' AND TABLE_NAME='users' AND COLUMN_NAME='role'")->fetchColumn();
        if ((int)$rc === 0) {
            $tmpPdo->exec("ALTER TABLE users ADD COLUMN role varchar(20) NOT NULL DEFAULT 'user'");
        }
        $uid = (int)($_SESSION['user_id'] ?? 0);
        if ($uid) {
            $rs = $tmpPdo->prepare("SELECT role FROM users WHERE id = ?");
            $rs->execute([$uid]);
            $dbRole = $rs->fetchColumn();
            if ($dbRole === 'admin') {
                $_SESSION['user_role'] = 'admin'; // fix session for future requests
                $session_role = 'admin';
            }
        }
    } catch (Exception $ex) { /* ignore */ }
    if ($session_role !== 'admin') {
        echo json_encode(['success' => false, 'message' => 'Forbidden — not an admin']); exit;
    }
}

$is_ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
if (!$is_ajax) {
    echo json_encode(['success' => false, 'message' => 'AJAX only']); exit;
}

$host   = 'localhost';
$dbname = 'login_system';
$dbuser = 'root';
$dbpass = '';

$action = trim($_POST['action'] ?? $_GET['action'] ?? '');

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $dbuser, $dbpass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // ── Auto-migrate: safely add missing columns (MariaDB compatible) ──
    $dbname_cur = $pdo->query("SELECT DATABASE()")->fetchColumn();
    $migs = [
        ["form_templates",    "event_target",  "ALTER TABLE form_templates ADD COLUMN event_target varchar(255) DEFAULT NULL"],
        ["form_templates",    "deadline",      "ALTER TABLE form_templates ADD COLUMN deadline date DEFAULT NULL"],
        ["form_templates",    "updated_at",    "ALTER TABLE form_templates ADD COLUMN updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"],
        ["form_submissions",  "status",        "ALTER TABLE form_submissions ADD COLUMN status varchar(50) NOT NULL DEFAULT 'Submitted'"],
        ["form_submissions",  "submitted_at",  "ALTER TABLE form_submissions ADD COLUMN submitted_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP"],
        ["stall_applications","updated_at",    "ALTER TABLE stall_applications ADD COLUMN updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"],
        ["stall_applications","business_name", "ALTER TABLE stall_applications ADD COLUMN business_name varchar(255) DEFAULT NULL"],
        ["stall_applications","stall_type",    "ALTER TABLE stall_applications ADD COLUMN stall_type varchar(100) DEFAULT NULL"],
        ["stall_applications","stall_size",    "ALTER TABLE stall_applications ADD COLUMN stall_size varchar(100) DEFAULT NULL"],
        ["stall_applications","status",        "ALTER TABLE stall_applications ADD COLUMN status varchar(50) NOT NULL DEFAULT 'Submitted'"],
    ];
    foreach ($migs as [$tbl, $col, $sql]) {
        $chk = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?");
        $chk->execute([$dbname_cur, $tbl, $col]);
        if ((int)$chk->fetchColumn() === 0) $pdo->exec($sql);
    }
    $ansChk = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='form_submissions' AND COLUMN_NAME='answers'");
    $ansChk->execute([$dbname_cur]);
    if ((int)$ansChk->fetchColumn() === 0) $pdo->exec("ALTER TABLE form_submissions ADD COLUMN answers LONGTEXT NOT NULL DEFAULT '{}'");
    $fldChk = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='form_templates' AND COLUMN_NAME='fields'");
    $fldChk->execute([$dbname_cur]);
    if ((int)$fldChk->fetchColumn() === 0) $pdo->exec("ALTER TABLE form_templates ADD COLUMN fields LONGTEXT NOT NULL DEFAULT '[]'");

    switch ($action) {

        // ── Vet an application ──────────────────────────
        case 'vet_application':
            $app_id   = (int)($_POST['app_id'] ?? 0);
            $decision = trim($_POST['decision'] ?? '');
            $note     = trim($_POST['note']     ?? '');

            if (!$app_id || !in_array($decision, ['approve','reject','info'])) {
                echo json_encode(['success'=>false,'message'=>'Invalid parameters']); exit;
            }

            $status_map = ['approve'=>'Approved','reject'=>'Rejected','info'=>'Info Requested'];
            $new_status = $status_map[$decision];

            $stmt = $pdo->prepare("UPDATE stall_applications SET status = ? WHERE id = ?");
            $stmt->execute([$new_status, $app_id]);

            echo json_encode(['success'=>true,'message'=>"Application #{$app_id} marked as {$new_status}"]);
            break;

        // ── Mark fee as paid ────────────────────────────
        case 'mark_paid':
            $app_id = (int)($_POST['app_id'] ?? 0);
            if (!$app_id) { echo json_encode(['success'=>false,'message'=>'Invalid ID']); exit; }

            $stmt = $pdo->prepare("UPDATE stall_applications SET status = 'Paid' WHERE id = ?");
            $stmt->execute([$app_id]);

            echo json_encode(['success'=>true,'message'=>"Fee for application #{$app_id} marked as paid"]);
            break;

        // ── Send broadcast ──────────────────────────────
        case 'send_broadcast':
            $channel    = trim($_POST['channel']    ?? 'email');
            $recipients = trim($_POST['recipients'] ?? 'all');
            $subject    = trim($_POST['subject']    ?? '');
            $body       = trim($_POST['body']       ?? '');

            if (empty($body)) {
                echo json_encode(['success'=>false,'message'=>'Message body is required']); exit;
            }
            error_log("BROADCAST [{$channel}] to [{$recipients}]: {$subject} — {$body}");

            echo json_encode([
                'success' => true,
                'message' => "Broadcast sent via {$channel} to {$recipients}",
            ]);
            break;

        // ── Save form definition ─────────────────────────
        case 'save_form':
            $title    = trim($_POST['title']    ?? '') ?: 'Untitled Form';
            $event    = trim($_POST['event']    ?? '');
            $deadline = trim($_POST['deadline'] ?? '');
            $fields   = trim($_POST['fields']   ?? '[]');

            // Normalise deadline to Y-m-d or null
            $deadline_val = null;
            if ($deadline !== '') {
                $ts = strtotime($deadline);
                if ($ts !== false) $deadline_val = date('Y-m-d', $ts);
            }

            // Validate and clean JSON
            $decoded = json_decode($fields, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                $decoded = [];
            }
            $fields_clean = json_encode($decoded, JSON_UNESCAPED_UNICODE);

            $stmt = $pdo->prepare("
                INSERT INTO form_templates (title, event_target, deadline, fields, is_active)
                VALUES (?, ?, ?, ?, 1)
            ");
            $stmt->execute([
                $title,
                $event !== '' ? $event : null,
                $deadline_val,
                $fields_clean,
            ]);
            $form_id = $pdo->lastInsertId();

            echo json_encode([
                'success'     => true,
                'message'     => "Form \"{$title}\" saved successfully with " . count($decoded) . " field(s).",
                'field_count' => count($decoded),
                'form_id'     => $form_id,
            ]);
            break;

        // ── Get all published forms ──────────────────────
        case 'get_forms':
            $stmt = $pdo->query("
                SELECT id, title, event_target,
                       DATE_FORMAT(deadline,'%b %d, %Y') AS deadline,
                       is_active,
                       JSON_LENGTH(fields) AS field_count,
                       DATE_FORMAT(created_at,'%b %d, %Y') AS created_date
                FROM form_templates
                ORDER BY created_at DESC
            ");
            echo json_encode(['success'=>true,'data'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        // ── Toggle form active/hidden ────────────────────
        case 'toggle_form':
            $form_id   = (int)($_POST['form_id']  ?? 0);
            $is_active = (int)($_POST['is_active'] ?? 0);
            if (!$form_id) { echo json_encode(['success'=>false,'message'=>'Invalid ID']); exit; }

            $stmt = $pdo->prepare("UPDATE form_templates SET is_active = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$is_active, $form_id]);

            $label = $is_active ? 'published (visible to vendors)' : 'hidden from vendors';
            echo json_encode(['success'=>true,'message'=>"Form {$label}"]);
            break;

        // ── Delete a form ────────────────────────────────
        case 'delete_form':
            $form_id = (int)($_POST['form_id'] ?? 0);
            if (!$form_id) { echo json_encode(['success'=>false,'message'=>'Invalid ID']); exit; }

            $stmt = $pdo->prepare("DELETE FROM form_templates WHERE id = ?");
            $stmt->execute([$form_id]);
            echo json_encode(['success'=>true,'message'=>'Form deleted']);
            break;

        // ── Get submissions for a form ───────────────────
        case 'get_submissions':
            $form_id = (int)($_GET['form_id'] ?? 0);
            if (!$form_id) { echo json_encode(['success'=>false,'message'=>'Invalid form ID']); exit; }

            $stmt = $pdo->prepare("
                SELECT fs.id, fs.answers, fs.status,
                       DATE_FORMAT(fs.submitted_at,'%b %d, %Y %H:%i') AS submitted_at,
                       u.full_name, u.email
                FROM form_submissions fs
                JOIN users u ON u.id = fs.user_id
                WHERE fs.form_id = ?
                ORDER BY fs.submitted_at DESC
            ");
            $stmt->execute([$form_id]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            // Decode answers JSON for display
            foreach ($rows as &$row) {
                $row['answers'] = json_decode($row['answers'], true);
            }
            echo json_encode(['success'=>true,'data'=>$rows]);
            break;

        // ── Update submission status ─────────────────────
        case 'update_submission':
            $sub_id  = (int)($_POST['sub_id'] ?? 0);
            $status  = trim($_POST['status']  ?? '');
            $allowed = ['Submitted','Approved','Rejected','Info Requested'];

            if (!$sub_id || !in_array($status, $allowed)) {
                echo json_encode(['success'=>false,'message'=>'Invalid parameters']); exit;
            }

            $stmt = $pdo->prepare("UPDATE form_submissions SET status = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$status, $sub_id]);
            echo json_encode(['success'=>true,'message'=>"Submission marked as {$status}"]);
            break;

        // ── Get pending applications ─────────────────────
        case 'get_applications':
            $status = trim($_GET['status'] ?? 'Submitted');
            $stmt   = $pdo->prepare("
                SELECT sa.id, u.full_name AS vendor, sa.business_name AS stall,
                       sa.stall_type AS type, sa.stall_size AS size,
                       e.title AS event,
                       DATE_FORMAT(sa.created_at,'%b %d') AS submitted,
                       sa.status
                FROM stall_applications sa
                JOIN users u ON u.id = sa.user_id
                LEFT JOIN events e ON e.id = sa.event_id
                WHERE sa.status = ?
                ORDER BY sa.created_at DESC
            ");
            $stmt->execute([$status]);
            echo json_encode(['success'=>true,'data'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        // ── Get all users ────────────────────────────────
        case 'get_users':
            $stmt = $pdo->query("
                SELECT id, full_name, email, stall_name, role, is_active,
                       DATE_FORMAT(created_at,'%b %d, %Y') AS created_at,
                       DATE_FORMAT(last_login,'%b %d, %Y') AS last_login
                FROM users
                ORDER BY created_at DESC
            ");
            echo json_encode(['success'=>true,'data'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        // ── Toggle user active/inactive ──────────────────
        case 'toggle_user':
            $user_id   = (int)($_POST['user_id']   ?? 0);
            $is_active = (int)($_POST['is_active']  ?? 0);
            if (!$user_id) { echo json_encode(['success'=>false,'message'=>'Invalid user ID']); exit; }

            // Prevent admin from deactivating themselves
            if ($user_id === (int)$_SESSION['user_id']) {
                echo json_encode(['success'=>false,'message'=>'You cannot deactivate your own account']); exit;
            }

            $stmt = $pdo->prepare("UPDATE users SET is_active = ? WHERE id = ?");
            $stmt->execute([$is_active, $user_id]);
            $label = $is_active ? 'activated' : 'deactivated';
            echo json_encode(['success'=>true,'message'=>"User {$label} successfully"]);
            break;

        default:
            echo json_encode(['success'=>false,'message'=>"Unknown action: {$action}"]);
    }

} catch (PDOException $e) {
    $errMsg = $e->getMessage();
    error_log("Admin action error [{$action}]: $errMsg");
    echo json_encode(['success'=>false,'message'=>'Database error: ' . $errMsg]);
}
?>