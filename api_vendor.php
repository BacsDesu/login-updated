<?php
// api_vendor.php — Vendor-facing AJAX data endpoint
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']); exit;
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

$action  = trim($_GET['action'] ?? $_POST['action'] ?? '');
$user_id = (int)($_SESSION['user_id'] ?? 0);

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $dbuser, $dbpass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // ─────────────────────────────────────────────────────
    // AUTO-MIGRATE: create/fix tables for MariaDB compatibility
    // CREATE TABLE IF NOT EXISTS is safe to run every request
    // ─────────────────────────────────────────────────────

    // stall_applications — may not exist at all (setup.php didn't create it)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `stall_applications` (
            `id`            int(11)      NOT NULL AUTO_INCREMENT,
            `user_id`       int(11)      NOT NULL,
            `event_id`      int(11)      DEFAULT NULL,
            `business_name` varchar(255) DEFAULT NULL,
            `stall_type`    varchar(100) DEFAULT NULL,
            `stall_size`    varchar(100) DEFAULT NULL,
            `status`        varchar(50)  NOT NULL DEFAULT 'Submitted',
            `created_at`    timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at`    timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `user_id_idx` (`user_id`),
            KEY `event_id_idx` (`event_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    // Per-column migrations (MariaDB compatible — no IF NOT EXISTS in ALTER)
    $chk = $pdo->prepare("
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?
    ");

    $colMigs = [
        ["form_templates",   "event_target",  "ALTER TABLE `form_templates` ADD COLUMN `event_target` varchar(255) DEFAULT NULL"],
        ["form_templates",   "deadline",      "ALTER TABLE `form_templates` ADD COLUMN `deadline` date DEFAULT NULL"],
        ["form_templates",   "is_active",     "ALTER TABLE `form_templates` ADD COLUMN `is_active` tinyint(1) DEFAULT 1"],
        ["form_templates",   "fields",        "ALTER TABLE `form_templates` ADD COLUMN `fields` LONGTEXT NOT NULL DEFAULT '[]'"],
        ["form_templates",   "updated_at",    "ALTER TABLE `form_templates` ADD COLUMN `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"],
        ["form_submissions", "answers",       "ALTER TABLE `form_submissions` ADD COLUMN `answers` LONGTEXT NOT NULL DEFAULT '{}'"],
        ["form_submissions", "status",        "ALTER TABLE `form_submissions` ADD COLUMN `status` varchar(50) NOT NULL DEFAULT 'Submitted'"],
        ["form_submissions", "submitted_at",  "ALTER TABLE `form_submissions` ADD COLUMN `submitted_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP"],
        ["stall_applications","business_name","ALTER TABLE `stall_applications` ADD COLUMN `business_name` varchar(255) DEFAULT NULL"],
        ["stall_applications","stall_type",   "ALTER TABLE `stall_applications` ADD COLUMN `stall_type` varchar(100) DEFAULT NULL"],
        ["stall_applications","stall_size",   "ALTER TABLE `stall_applications` ADD COLUMN `stall_size` varchar(100) DEFAULT NULL"],
        ["stall_applications","status",       "ALTER TABLE `stall_applications` ADD COLUMN `status` varchar(50) NOT NULL DEFAULT 'Submitted'"],
        ["stall_applications","event_id",     "ALTER TABLE `stall_applications` ADD COLUMN `event_id` int(11) DEFAULT NULL"],
        ["stall_applications","updated_at",   "ALTER TABLE `stall_applications` ADD COLUMN `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"],
    ];

    foreach ($colMigs as [$tbl, $col, $sql]) {
        $chk->execute([$dbname, $tbl, $col]);
        if ((int)$chk->fetchColumn() === 0) {
            $pdo->exec($sql);
        }
    }

    // ─────────────────────────────────────────────────────
    // ACTIONS
    // ─────────────────────────────────────────────────────
    switch ($action) {

        // ── My applications ────────────────────────────────
        case 'my_applications':
            $stmt = $pdo->prepare("
                SELECT sa.id, sa.business_name, sa.stall_type, sa.stall_size,
                       sa.status, sa.created_at,
                       e.title AS event_title, e.start_date, e.end_date
                FROM stall_applications sa
                LEFT JOIN events e ON e.id = sa.event_id
                WHERE sa.user_id = ?
                ORDER BY sa.created_at DESC
            ");
            $stmt->execute([$user_id]);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        // ── Available forms ────────────────────────────────
        case 'available_forms':
            $stmt = $pdo->query("
                SELECT id, title, event_target,
                       DATE_FORMAT(deadline,'%b %d, %Y') AS deadline,
                       created_at
                FROM form_templates
                WHERE is_active = 1
                  AND (deadline IS NULL OR deadline >= CURDATE())
                ORDER BY created_at DESC
            ");
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
            break;

        // ── Get single form ────────────────────────────────
        case 'get_form':
            $form_id = (int)($_GET['form_id'] ?? 0);
            if (!$form_id) { echo json_encode(['success'=>false,'message'=>'Invalid form ID']); exit; }

            $stmt = $pdo->prepare("
                SELECT id, title, event_target, deadline, fields
                FROM form_templates WHERE id = ? AND is_active = 1
            ");
            $stmt->execute([$form_id]);
            $form = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$form) { echo json_encode(['success'=>false,'message'=>'Form not found']); exit; }

            $dec = json_decode($form['fields'] ?? '[]', true);
            $form['fields'] = is_array($dec) ? $dec : [];
            echo json_encode(['success' => true, 'data' => $form]);
            break;

        // ── Submit form → creates stall_application ────────
        case 'submit_form':
            $form_id = (int)($_POST['form_id'] ?? 0);
            $answers = trim($_POST['answers'] ?? '');

            if (!$form_id) {
                echo json_encode(['success'=>false,'message'=>'Missing form ID']); exit;
            }

            // Validate form exists
            $stmt = $pdo->prepare("
                SELECT id, title, event_target FROM form_templates
                WHERE id = ? AND is_active = 1
            ");
            $stmt->execute([$form_id]);
            $form = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$form) {
                echo json_encode(['success'=>false,'message'=>'Form is not available']); exit;
            }

            // Duplicate submission check
            $stmt = $pdo->prepare("
                SELECT id FROM form_submissions WHERE form_id = ? AND user_id = ?
            ");
            $stmt->execute([$form_id, $user_id]);
            if ($stmt->fetch()) {
                echo json_encode(['success'=>false,'message'=>'You have already submitted this form']); exit;
            }

            // Clean and validate answers
            $decoded = json_decode($answers, true);
            if (!is_array($decoded)) $decoded = [];
            $answers_clean = json_encode($decoded, JSON_UNESCAPED_UNICODE);

            // Pull key fields for stall_applications row
            $business   = trim($decoded['business_name'] ?? $decoded['stall_name'] ?? '') ?: ($_SESSION['user_name'] ?? 'Vendor');
            $stall_type = trim($decoded['stall_type'] ?? '') ?: 'General';
            $stall_size = trim($decoded['stall_size'] ?? '') ?: 'Standard';

            // 1. Save to form_submissions
            $stmt = $pdo->prepare("
                INSERT INTO form_submissions (form_id, user_id, answers, status)
                VALUES (?, ?, ?, 'Submitted')
            ");
            $stmt->execute([$form_id, $user_id, $answers_clean]);
            $sub_id = $pdo->lastInsertId();

            // 2. Find matching event (fuzzy match on title)
            $event_id = null;
            if (!empty($form['event_target'])) {
                $stmt2 = $pdo->prepare("
                    SELECT id FROM events
                    WHERE title LIKE ? OR title = ?
                    LIMIT 1
                ");
                $stmt2->execute(['%' . $form['event_target'] . '%', $form['event_target']]);
                $ev = $stmt2->fetch(PDO::FETCH_ASSOC);
                if ($ev) $event_id = (int)$ev['id'];
            }

            // 3. Skip if already applied to this event
            if ($event_id) {
                $dup = $pdo->prepare("
                    SELECT id FROM stall_applications
                    WHERE user_id = ? AND event_id = ? LIMIT 1
                ");
                $dup->execute([$user_id, $event_id]);
                if ($dup->fetch()) {
                    echo json_encode(['success'=>true,'message'=>'Application submitted!','sub_id'=>$sub_id]);
                    break;
                }
            }

            // 4. Insert into stall_applications — admin sees this
            $stmt3 = $pdo->prepare("
                INSERT INTO stall_applications
                    (user_id, event_id, business_name, stall_type, stall_size, status)
                VALUES (?, ?, ?, ?, ?, 'Submitted')
            ");
            $stmt3->execute([$user_id, $event_id, $business, $stall_type, $stall_size]);

            echo json_encode([
                'success' => true,
                'message' => 'Application submitted successfully!',
                'sub_id'  => $sub_id,
            ]);
            break;

        default:
            echo json_encode(['success'=>false,'message'=>"Unknown action: $action"]);
    }

} catch (PDOException $e) {
    $errMsg = $e->getMessage();
    error_log("api_vendor error [$action]: $errMsg");
    echo json_encode(['success'=>false,'message'=>'DB error: ' . $errMsg]);
}
?>