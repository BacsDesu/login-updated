<?php
session_start();

// ── Auth guard — admin only ───────────────────────────
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php'); exit;
}
if (($_SESSION['user_role'] ?? '') !== 'admin') {
    header('Location: dashboard.php'); exit;
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php'); exit;
}

// ── DB Config ────────────────────────────────────────
$host   = 'localhost';
$dbname = 'login_system';
$dbuser = 'root';
$dbpass = '';

$pending_apps  = [];
$analytics     = ['events' => [], 'product_mix' => []];
$fee_ledger    = [];
$total_fees    = 0;
$total_paid    = 0;
$total_unpaid  = 0;
$paid_count    = 0;
$overdue_count = 0;
$admin_name    = $_SESSION['user_name'] ?? 'Admin';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $dbuser, $dbpass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // ── Auto-migrate: ensure stall_applications table & columns exist ──
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
    $chkCol = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME=? AND COLUMN_NAME=?");
    $adminMigs = [
        ["stall_applications","business_name","ALTER TABLE `stall_applications` ADD COLUMN `business_name` varchar(255) DEFAULT NULL"],
        ["stall_applications","stall_type",   "ALTER TABLE `stall_applications` ADD COLUMN `stall_type` varchar(100) DEFAULT NULL"],
        ["stall_applications","stall_size",   "ALTER TABLE `stall_applications` ADD COLUMN `stall_size` varchar(100) DEFAULT NULL"],
        ["stall_applications","status",       "ALTER TABLE `stall_applications` ADD COLUMN `status` varchar(50) NOT NULL DEFAULT 'Submitted'"],
        ["stall_applications","event_id",     "ALTER TABLE `stall_applications` ADD COLUMN `event_id` int(11) DEFAULT NULL"],
        ["stall_applications","updated_at",   "ALTER TABLE `stall_applications` ADD COLUMN `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"],
        ["form_templates",    "event_target", "ALTER TABLE `form_templates` ADD COLUMN `event_target` varchar(255) DEFAULT NULL"],
    ];
    foreach ($adminMigs as [$t, $c, $s]) {
        $chkCol->execute([$dbname, $t, $c]);
        if ((int)$chkCol->fetchColumn() === 0) $pdo->exec($s);
    }

    // ── Vetting queue: ALL applications (Submitted shown first) ──
    $stmt = $pdo->query("
        SELECT sa.id,
               COALESCE(u.full_name, 'Unknown Vendor') AS vendor,
               COALESCE(sa.business_name, 'Unnamed Stall')  AS stall,
               COALESCE(sa.stall_type, 'General')            AS type,
               COALESCE(sa.stall_size, 'Standard')           AS size,
               COALESCE(e.title, 'No Event')                 AS event,
               DATE_FORMAT(sa.created_at,'%b %d')            AS submitted,
               sa.status,
               CASE WHEN fs.id IS NOT NULL THEN 1 ELSE 0 END AS docs
        FROM stall_applications sa
        LEFT JOIN users u  ON u.id  = sa.user_id
        LEFT JOIN events e ON e.id  = sa.event_id
        LEFT JOIN form_submissions fs
               ON fs.user_id = sa.user_id
        WHERE sa.status = 'Submitted'
        ORDER BY sa.created_at DESC
        LIMIT 100
    ");
    $pending_apps = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Analytics: events with application counts ─────
    $stmt = $pdo->query("
        SELECT e.id, e.title AS name, e.capacity,
               COUNT(sa.id) AS applications
        FROM events e
        LEFT JOIN stall_applications sa ON sa.event_id = e.id
        WHERE e.is_active = 1
        GROUP BY e.id
        ORDER BY e.start_date ASC
        LIMIT 10
    ");
    $evRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Product mix per event
    foreach ($evRows as &$ev) {
        $stmt2 = $pdo->prepare("
            SELECT stall_type, COUNT(*) AS cnt
            FROM stall_applications
            WHERE event_id = ? AND stall_type IS NOT NULL
            GROUP BY stall_type
        ");
        $stmt2->execute([$ev['id']]);
        $types = [];
        $total = max($ev['applications'], 1);
        foreach ($stmt2->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $types[$t['stall_type']] = round($t['cnt'] / $total * 100);
        }
        $ev['types'] = $types;
        $analytics['events'][] = $ev;
    }
    unset($ev);

    // Overall product mix
    $stmt = $pdo->query("SELECT stall_type, COUNT(*) AS cnt FROM stall_applications WHERE stall_type IS NOT NULL GROUP BY stall_type ORDER BY cnt DESC LIMIT 10");
    $total_apps = 0;
    $mix_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($mix_rows as $r) $total_apps += $r['cnt'];
    foreach ($mix_rows as $r) {
        $analytics['product_mix'][$r['stall_type']] = $total_apps > 0 ? round($r['cnt']/$total_apps*100) : 0;
    }

    // ── Fee ledger: stall_applications with fee tracking ─
    // We derive amount from stall_size (simple mapping), paid from status
    $sizeAmounts = ['Small'=>1000, 'Medium'=>1300, 'Large'=>1500, 'Standard'=>1000];
    $stmt = $pdo->query("
        SELECT sa.id, u.full_name AS vendor, sa.business_name AS stall,
               e.title AS event, sa.stall_size AS size,
               sa.status,
               DATE_FORMAT(COALESCE(e.start_date, sa.created_at), '%b %d') AS due,
               sa.created_at
        FROM stall_applications sa
        JOIN users u ON u.id = sa.user_id
        LEFT JOIN events e ON e.id = sa.event_id
        WHERE sa.status IN ('Approved','Paid','Confirmed','Rejected','Submitted','Info Requested')
        ORDER BY sa.created_at DESC
        LIMIT 50
    ");
    $allApps = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($allApps as $app) {
        $amount = $sizeAmounts[$app['size']] ?? 1000;
        $paid   = in_array($app['status'], ['Paid','Confirmed']) ? $amount : 0;
        $today  = new DateTime();
        $due    = $app['due'];

        // Determine fee status
        if ($paid >= $amount) {
            $fee_status = 'paid';
        } else {
            // Check if due date has passed
            $due_dt = $app['event'] ? DateTime::createFromFormat('M d', $due) : null;
            $fee_status = ($due_dt && $due_dt < $today) ? 'overdue' : ($paid > 0 ? 'partial' : 'unpaid');
        }

        $fee_ledger[] = [
            'id'     => $app['id'],
            'vendor' => $app['vendor'],
            'stall'  => $app['stall'],
            'event'  => $app['event'] ?? '—',
            'amount' => $amount,
            'paid'   => $paid,
            'status' => $fee_status,
            'due'    => $due,
        ];
    }

    $total_fees   = array_sum(array_column($fee_ledger, 'amount'));
    $total_paid   = array_sum(array_column($fee_ledger, 'paid'));
    $total_unpaid = $total_fees - $total_paid;
    $paid_count   = count(array_filter($fee_ledger, fn($r) => $r['status'] === 'paid'));
    $overdue_count= count(array_filter($fee_ledger, fn($r) => $r['status'] === 'overdue'));

    // ── Admin name from DB ────────────────────────────
    $stmt = $pdo->prepare("SELECT full_name FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $adminRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($adminRow) $admin_name = $adminRow['full_name'];

} catch (PDOException $e) {
    error_log("Admin dashboard DB error: " . $e->getMessage());
}

// Fetch events list for form builder dropdown
$events_list = [];
try {
    $stmt = $pdo->query("SELECT id, title FROM events WHERE is_active=1 ORDER BY start_date ASC");
    $events_list = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SmartPOP — Admin Command Center</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700;800&family=JetBrains+Mono:wght@300;400;500;600&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="admin.css">
</head>
<body>

<div class="scan-line"></div>

<!-- ════════ SIDEBAR ════════ -->
<aside class="admin-sidebar" id="adminSidebar">
    <div class="sidebar-brand">
        <div class="brand-mark"><span class="brand-icon">⬡</span></div>
        <div class="brand-text">
            <span class="brand-name">SmartPOP</span>
            <span class="brand-role">Admin Panel</span>
        </div>
    </div>

    <nav class="admin-nav">
        <div class="nav-section-label">MODULES</div>
        <a href="#" class="anav-item active" onclick="switchSection('vetting',this);return false;">
            <span class="anav-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></span>
            <span>Vetting Queue</span>
            <span class="anav-count" id="pendingCount"><?= count($pending_apps) ?></span>
        </a>
        <a href="#" class="anav-item" onclick="switchSection('analytics',this);return false;">
            <span class="anav-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg></span>
            <span>Event Analytics</span>
        </a>
        <a href="#" class="anav-item" onclick="switchSection('comms',this);return false;">
            <span class="anav-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg></span>
            <span>Comm Hub</span>
        </a>
        <a href="#" class="anav-item" onclick="switchSection('revenue',this);return false;">
            <span class="anav-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg></span>
            <span>Revenue & Fees</span>
            <?php if($overdue_count > 0): ?>
            <span class="anav-alert"><?= $overdue_count ?></span>
            <?php endif; ?>
        </a>
        <a href="#" class="anav-item" onclick="switchSection('formbuilder',this);return false;">
            <span class="anav-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg></span>
            <span>Form Builder</span>
        </a>
        <a href="#" class="anav-item" onclick="switchSection('users',this);return false;">
            <span class="anav-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg></span>
            <span>Users</span>
        </a>
    </nav>

    <div class="sidebar-footer">
        <div class="admin-user">
            <div class="admin-avatar"><?= strtoupper(substr($admin_name,0,1)) ?></div>
            <div class="admin-info">
                <span class="admin-name"><?= htmlspecialchars($admin_name) ?></span>
                <span class="admin-badge">⬡ ROOT ACCESS</span>
            </div>
        </div>
        <a href="?logout=1" class="sidebar-logout" title="Logout">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
        </a>
    </div>
</aside>

<!-- ════════ MAIN AREA ════════ -->
<div class="admin-main" id="adminMain">

    <header class="admin-topbar">
        <button class="topbar-toggle" onclick="toggleSidebar()" id="sidebarToggle">
            <span></span><span></span><span></span>
        </button>
        <div class="topbar-title" id="topbarTitle">Vetting Queue</div>
        <div class="topbar-right">
            <div class="topbar-clock" id="adminClock"></div>
            <div class="topbar-status">
                <span class="status-led"></span>
                <span>SYSTEM ONLINE</span>
            </div>
        </div>
    </header>

    <!-- ══ VETTING QUEUE ══ -->
    <section class="admin-section active" id="sec-vetting">
        <div class="section-header">
            <div>
                <h1 class="section-title">Applicant Vetting Queue</h1>
                <p class="section-sub" id="vettingSubtitle"><?= count($pending_apps) ?> applications awaiting review</p>
            </div>
            <div class="section-controls">
                <input type="text" class="search-box" id="vettingSearch" placeholder="Search vendor, stall, event…" oninput="filterVetting(this.value)">
                <select class="filter-select" id="typeFilter" onchange="filterVetting()">
                    <option value="">All Types</option>
                    <option>Food</option><option>Crafts</option><option>Produce</option>
                    <option>Clothing</option><option>Arts</option><option>Services</option>
                    <option>General</option>
                </select>
            </div>
        </div>

        <div class="vetting-grid" id="vettingGrid">
            <?php if (empty($pending_apps)): ?>
            <div style="grid-column:1/-1;text-align:center;padding:60px;color:#6b7280;">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin-bottom:12px;opacity:.4"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <p>No pending applications</p>
            </div>
            <?php else: ?>
            <?php foreach($pending_apps as $i => $app): ?>
            <div class="app-card" data-id="<?= $app['id'] ?>"
                 data-type="<?= htmlspecialchars($app['type'] ?? '') ?>"
                 data-vendor="<?= strtolower($app['vendor']) ?>"
                 data-stall="<?= strtolower($app['stall']) ?>"
                 data-event="<?= strtolower($app['event'] ?? '') ?>"
                 style="animation-delay:<?= $i*0.07 ?>s">
                <div class="app-card-top">
                    <div class="app-type-badge badge-<?= strtolower($app['type'] ?? 'general') ?>"><?= htmlspecialchars($app['type'] ?? 'General') ?></div>
                    <div class="app-id">#<?= str_pad($app['id'],4,'0',STR_PAD_LEFT) ?></div>
                </div>
                <h3 class="app-vendor"><?= htmlspecialchars($app['vendor']) ?></h3>
                <p class="app-stall">🏪 <?= htmlspecialchars($app['stall']) ?></p>
                <div class="app-meta">
                    <span>📅 <?= htmlspecialchars($app['submitted']) ?></span>
                    <span>📐 <?= htmlspecialchars($app['size'] ?? '—') ?></span>
                    <span>🎪 <?= htmlspecialchars($app['event'] ?? 'N/A') ?></span>
                </div>
                <div class="app-docs <?= $app['docs'] ? 'docs-ok' : 'docs-missing' ?>">
                    <?php if($app['docs']): ?>
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg> Docs complete
                    <?php else: ?>
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> Docs missing
                    <?php endif; ?>
                </div>
                <div class="app-actions">
                    <button class="act-btn act-approve" onclick="vetAction(<?= $app['id'] ?>,'approve',this)">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Approve
                    </button>
                    <button class="act-btn act-info" onclick="vetAction(<?= $app['id'] ?>,'info',this)">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg> More Info
                    </button>
                    <button class="act-btn act-reject" onclick="vetAction(<?= $app['id'] ?>,'reject',this)">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> Reject
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>

    <!-- ══ ANALYTICS ══ -->
    <section class="admin-section" id="sec-analytics">
        <div class="section-header">
            <div>
                <h1 class="section-title">Event Analytics</h1>
                <p class="section-sub">Stall interest and product diversity across all events</p>
            </div>
        </div>
        <div class="analytics-grid">
            <div class="analytics-card span-1">
                <div class="ac-header">
                    <h3 class="ac-title">Product Mix</h3>
                    <span class="ac-sub">Overall diversity</span>
                </div>
                <?php if (empty($analytics['product_mix'])): ?>
                <p style="color:#6b7280;text-align:center;padding:40px 0">No application data yet</p>
                <?php else: ?>
                <div class="donut-wrap">
                    <canvas id="donutChart" width="200" height="200"></canvas>
                    <div class="donut-center">
                        <div class="donut-total" id="donutTotal">0</div>
                        <div class="donut-label">Total Apps</div>
                    </div>
                </div>
                <div class="donut-legend" id="donutLegend"></div>
                <?php endif; ?>
            </div>

            <div class="analytics-card span-2">
                <div class="ac-header">
                    <h3 class="ac-title">Event Capacity vs Applications</h3>
                    <span class="ac-sub">Fill rate per event</span>
                </div>
                <?php if (empty($analytics['events'])): ?>
                <p style="color:#6b7280;text-align:center;padding:40px 0">No event data yet</p>
                <?php else: ?>
                <div class="bar-chart-wrap" id="barChartWrap"></div>
                <?php endif; ?>
            </div>

            <?php foreach($analytics['events'] as $ev): ?>
            <div class="analytics-card event-breakdown">
                <div class="ac-header">
                    <h3 class="ac-title"><?= htmlspecialchars($ev['name']) ?></h3>
                    <span class="fill-rate"><?= $ev['capacity'] > 0 ? round($ev['applications']/$ev['capacity']*100) : 0 ?>% Full</span>
                </div>
                <?php if (empty($ev['types'])): ?>
                <p style="color:#6b7280;font-size:12px">No applications yet</p>
                <?php else: ?>
                <div class="mini-bars">
                    <?php foreach($ev['types'] as $type => $pct): if(!$pct) continue; ?>
                    <div class="mbar-row">
                        <span class="mbar-label"><?= htmlspecialchars($type) ?></span>
                        <div class="mbar-track">
                            <div class="mbar-fill badge-bg-<?= strtolower($type) ?>" data-val="<?= $pct ?>" style="width:0"></div>
                        </div>
                        <span class="mbar-val"><?= $pct ?>%</span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- ══ COMM HUB ══ -->
    <section class="admin-section" id="sec-comms">
        <div class="section-header">
            <div>
                <h1 class="section-title">Communication Hub</h1>
                <p class="section-sub">Broadcast alerts to vendors via SMS or Email</p>
            </div>
        </div>
        <div class="comms-layout">
            <div class="comms-compose">
                <div class="compose-card">
                    <h3 class="compose-title">New Broadcast</h3>
                    <div class="compose-form" id="composeForm">
                        <div class="cf-group">
                            <label class="cf-label">Channel</label>
                            <div class="channel-toggle">
                                <button class="ch-btn active" onclick="setChannel('email',this)">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg> Email
                                </button>
                                <button class="ch-btn" onclick="setChannel('sms',this)">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg> SMS
                                </button>
                                <button class="ch-btn" onclick="setChannel('both',this)">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg> Both
                                </button>
                            </div>
                        </div>
                        <div class="cf-group">
                            <label class="cf-label">Recipients</label>
                            <div class="recipient-chips" id="recipientChips">
                                <div class="chip chip-active" data-group="all" onclick="toggleChip(this)">All Vendors</div>
                                <div class="chip" data-group="approved" onclick="toggleChip(this)">Approved Only</div>
                                <div class="chip" data-group="pending" onclick="toggleChip(this)">Pending Only</div>
                                <?php foreach ($events_list as $ev): ?>
                                <div class="chip" data-group="event_<?= $ev['id'] ?>" onclick="toggleChip(this)"><?= htmlspecialchars($ev['title']) ?></div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="cf-group" id="subjectGroup">
                            <label class="cf-label">Subject <span class="cf-required">*</span></label>
                            <input type="text" class="cf-input" id="msgSubject" placeholder="e.g. Event starts in 1 hour">
                        </div>
                        <div class="cf-group">
                            <label class="cf-label">Message <span class="cf-required">*</span>
                                <span class="char-counter" id="charCounter">0 / 160</span>
                            </label>
                            <textarea class="cf-textarea" id="msgBody" rows="5" placeholder="Type your broadcast message…" oninput="updateCharCount(this)"></textarea>
                        </div>
                        <div class="cf-group">
                            <label class="cf-label">Quick Templates</label>
                            <div class="templates-grid">
                                <button class="tpl-btn" onclick="loadTemplate('event_start')">🚀 Event Start</button>
                                <button class="tpl-btn" onclick="loadTemplate('weather')">⛈ Weather Alert</button>
                                <button class="tpl-btn" onclick="loadTemplate('payment')">💸 Payment Due</button>
                                <button class="tpl-btn" onclick="loadTemplate('reminder')">⏰ Reminder</button>
                            </div>
                        </div>
                        <button class="broadcast-btn" onclick="sendBroadcast()">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                            Send Broadcast
                        </button>
                    </div>
                </div>
            </div>
            <div class="comms-log">
                <div class="log-card">
                    <h3 class="compose-title">Broadcast Log</h3>
                    <div class="log-list" id="broadcastLog">
                        <div class="log-empty" id="logEmpty">No broadcasts sent yet.</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ══ REVENUE ══ -->
    <section class="admin-section" id="sec-revenue">
        <div class="section-header">
            <div>
                <h1 class="section-title">Revenue & Fee Management</h1>
                <p class="section-sub">Stall rental fee ledger and payment tracking</p>
            </div>
            <button class="export-btn" onclick="exportCSV()">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                Export CSV
            </button>
        </div>
        <div class="rev-summary">
            <div class="rev-card">
                <div class="rev-icon">₱</div>
                <div class="rev-data">
                    <div class="rev-val" data-target="<?= $total_fees ?>">₱0</div>
                    <div class="rev-lbl">Total Expected</div>
                </div>
            </div>
            <div class="rev-card success">
                <div class="rev-icon">✓</div>
                <div class="rev-data">
                    <div class="rev-val" data-target="<?= $total_paid ?>">₱0</div>
                    <div class="rev-lbl">Total Collected</div>
                </div>
            </div>
            <div class="rev-card danger">
                <div class="rev-icon">!</div>
                <div class="rev-data">
                    <div class="rev-val" data-target="<?= $total_unpaid ?>">₱0</div>
                    <div class="rev-lbl">Outstanding</div>
                </div>
            </div>
            <div class="rev-card neutral">
                <div class="rev-icon">⚑</div>
                <div class="rev-data">
                    <div class="rev-val"><?= $overdue_count ?></div>
                    <div class="rev-lbl">Overdue Accounts</div>
                </div>
            </div>
        </div>
        <div class="ledger-wrap">
            <?php if (empty($fee_ledger)): ?>
            <p style="text-align:center;padding:40px;color:#6b7280;">No applications in the system yet.</p>
            <?php else: ?>
            <table class="ledger-table" id="ledgerTable">
                <thead>
                    <tr>
                        <th onclick="sortLedger('vendor')">Vendor <span class="sort-icon">↕</span></th>
                        <th>Stall</th>
                        <th onclick="sortLedger('event')">Event <span class="sort-icon">↕</span></th>
                        <th onclick="sortLedger('amount')">Amount <span class="sort-icon">↕</span></th>
                        <th>Paid</th>
                        <th>Balance</th>
                        <th>Due Date</th>
                        <th onclick="sortLedger('status')">Status <span class="sort-icon">↕</span></th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody id="ledgerBody">
                    <?php foreach($fee_ledger as $row): ?>
                    <tr class="ledger-row" data-id="<?= $row['id'] ?>" data-status="<?= $row['status'] ?>">
                        <td class="td-vendor"><?= htmlspecialchars($row['vendor']) ?></td>
                        <td class="td-stall"><?= htmlspecialchars($row['stall']) ?></td>
                        <td class="td-event"><?= htmlspecialchars($row['event']) ?></td>
                        <td class="td-mono">₱<?= number_format($row['amount']) ?></td>
                        <td class="td-mono td-paid">₱<?= number_format($row['paid']) ?></td>
                        <td class="td-mono <?= ($row['amount']-$row['paid'])>0 ? 'td-owed' : 'td-zero' ?>">₱<?= number_format($row['amount']-$row['paid']) ?></td>
                        <td><?= htmlspecialchars($row['due']) ?></td>
                        <td><span class="status-pill pill-<?= $row['status'] ?>"><?= ucfirst($row['status']) ?></span></td>
                        <td>
                            <?php if($row['status'] !== 'paid'): ?>
                            <button class="ledger-btn" onclick="markPaid(<?= $row['id'] ?>,this)">Mark Paid</button>
                            <?php else: ?>
                            <span class="paid-check">✓ Cleared</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </section>

    <!-- ══ FORM BUILDER ══ -->
    <section class="admin-section" id="sec-formbuilder">
        <div class="section-header">
            <div>
                <h1 class="section-title">Application Form Builder</h1>
                <p class="section-sub">Drag fields to build custom application forms for events</p>
            </div>
            <div class="fb-header-actions">
                <button class="fb-btn-secondary" onclick="clearForm()">Clear All</button>
                <button class="fb-btn-primary" onclick="saveForm()">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                    Save Form
                </button>
            </div>
        </div>

        <div class="fb-layout">
            <div class="fb-palette">
                <h3 class="fb-palette-title">Field Types</h3>
                <div class="palette-fields">
                    <div class="palette-item" draggable="true" data-type="text" ondragstart="paletteDrag(event)"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="4 7 4 4 20 4 20 7"/><line x1="9" y1="20" x2="15" y2="20"/><line x1="12" y1="4" x2="12" y2="20"/></svg> Short Text</div>
                    <div class="palette-item" draggable="true" data-type="textarea" ondragstart="paletteDrag(event)"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="7" y1="8" x2="17" y2="8"/><line x1="7" y1="12" x2="17" y2="12"/><line x1="7" y1="16" x2="13" y2="16"/></svg> Long Text</div>
                    <div class="palette-item" draggable="true" data-type="email" ondragstart="paletteDrag(event)"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg> Email</div>
                    <div class="palette-item" draggable="true" data-type="phone" ondragstart="paletteDrag(event)"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07A19.5 19.5 0 013.07 9.81"/></svg> Phone</div>
                    <div class="palette-item" draggable="true" data-type="select" ondragstart="paletteDrag(event)"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg> Dropdown</div>
                    <div class="palette-item" draggable="true" data-type="radio" ondragstart="paletteDrag(event)"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="3"/></svg> Radio Group</div>
                    <div class="palette-item" draggable="true" data-type="checkbox" ondragstart="paletteDrag(event)"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg> Checkbox</div>
                    <div class="palette-item" draggable="true" data-type="date" ondragstart="paletteDrag(event)"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg> Date</div>
                    <div class="palette-item" draggable="true" data-type="file" ondragstart="paletteDrag(event)"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg> File Upload</div>
                    <div class="palette-item" draggable="true" data-type="heading" ondragstart="paletteDrag(event)"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h16M4 18h7"/></svg> Section Heading</div>
                </div>
                <div class="fb-form-meta">
                    <h3 class="fb-palette-title" style="margin-top:20px">Form Settings</h3>
                    <div class="meta-field">
                        <label>Form Title</label>
                        <input type="text" id="fbFormTitle" value="Stall Application Form" class="cf-input">
                    </div>
                    <div class="meta-field">
                        <label>Target Event</label>
                        <select id="fbEventTarget" class="cf-input">
                            <option value="">— Select Event —</option>
                            <?php foreach ($events_list as $ev): ?>
                            <option value="<?= htmlspecialchars($ev['title']) ?>"><?= htmlspecialchars($ev['title']) ?></option>
                            <?php endforeach; ?>
                            <option value="__new__">+ New Event</option>
                        </select>
                    </div>
                    <div class="meta-field">
                        <label>Deadline</label>
                        <input type="date" id="fbDeadline" class="cf-input">
                    </div>
                </div>
            </div>

            <div class="fb-canvas-wrap">
                <div class="fb-canvas-title">
                    <span id="fbCanvasTitle">Stall Application Form</span>
                    <span class="fb-field-count" id="fbFieldCount">0 fields</span>
                </div>
                <div class="fb-canvas" id="fbCanvas" ondragover="canvasDragOver(event)" ondrop="canvasDrop(event)" ondragleave="canvasDragLeave(event)">
                    <div class="fb-empty" id="fbEmpty">
                        <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M12 5v14M5 12h14"/></svg>
                        <p>Drag fields here to build your form</p>
                    </div>
                </div>
                <div class="fb-canvas-footer">
                    <button class="fb-btn-secondary" onclick="previewForm()">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg> Preview
                    </button>
                    <button class="fb-btn-secondary" onclick="exportFormJSON()">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg> Export JSON
                    </button>
                </div>
            </div>

            <div class="fb-editor" id="fbEditor">
                <h3 class="fb-palette-title">Field Properties</h3>
                <div class="editor-empty" id="editorEmpty"><p>Select a field to edit its properties</p></div>
                <div class="editor-form" id="editorForm" style="display:none">
                    <div class="meta-field"><label>Label</label><input type="text" id="ef_label" class="cf-input" oninput="updateSelectedField()"></div>
                    <div class="meta-field"><label>Placeholder</label><input type="text" id="ef_placeholder" class="cf-input" oninput="updateSelectedField()"></div>
                    <div class="meta-field" id="ef_options_group" style="display:none">
                        <label>Options (one per line)</label>
                        <textarea class="cf-textarea" id="ef_options" rows="4" oninput="updateSelectedField()" placeholder="Option 1&#10;Option 2&#10;Option 3"></textarea>
                    </div>
                    <div class="meta-field">
                        <label class="cf-check-label"><input type="checkbox" id="ef_required" onchange="updateSelectedField()"> Required field</label>
                    </div>
                    <button class="delete-field-btn" onclick="deleteSelectedField()">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a1 1 0 011-1h4a1 1 0 011 1v2"/></svg> Remove Field
                    </button>
                </div>
            </div>
        </div>

        <div class="pf-section">
            <div class="pf-section-header">
                <h3>Published Forms</h3>
                <span class="pf-section-sub">Forms currently available for vendors to apply</span>
            </div>
            <div id="publishedFormsList" class="pf-list"><div class="pf-empty">Loading…</div></div>
        </div>

        <div id="submissionsPanel" class="sub-panel" style="display:none">
            <div class="pf-section-header">
                <h3>Submissions</h3>
                <button class="pf-close-btn" onclick="document.getElementById('submissionsPanel').style.display='none'">✕ Close</button>
            </div>
            <div id="submissionsList" class="sub-list"></div>
        </div>
    </section>

    <!-- ══ USERS ══ -->
    <section class="admin-section" id="sec-users">
        <div class="section-header">
            <div>
                <h1 class="section-title">User Management</h1>
                <p class="section-sub" id="userSubtitle">Loading users…</p>
            </div>
            <div class="section-controls">
                <input type="text" class="search-box" id="userSearch" placeholder="Search name or email…" oninput="filterUsers(this.value)">
            </div>
        </div>
        <div class="ledger-wrap">
            <table class="ledger-table" id="usersTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Stall</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Joined</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody id="usersBody">
                    <tr><td colspan="8" style="text-align:center;padding:30px;color:#6b7280;">Loading…</td></tr>
                </tbody>
            </table>
        </div>
    </section>

</div><!-- /admin-main -->

<!-- ════════ VET ACTION MODAL ════════ -->
<div class="modal-overlay" id="vetModal" onclick="closeVetModal(event)">
    <div class="vet-modal">
        <div class="vm-header">
            <h3 id="vmTitle">Action</h3>
            <button onclick="closeVetModal()" class="vm-close">✕</button>
        </div>
        <div class="vm-body">
            <p id="vmDesc"></p>
            <textarea class="cf-textarea" id="vmNote" rows="3" placeholder="Add a note (optional)…"></textarea>
            <div class="vm-actions">
                <button class="vm-cancel" onclick="closeVetModal()">Cancel</button>
                <button class="vm-confirm" id="vmConfirm">Confirm</button>
            </div>
        </div>
    </div>
</div>

<!-- ════════ FORM PREVIEW MODAL ════════ -->
<div class="modal-overlay" id="previewModal" onclick="closePreview(event)">
    <div class="preview-modal">
        <div class="vm-header">
            <h3 id="previewTitle">Form Preview</h3>
            <button onclick="closePreview()" class="vm-close">✕</button>
        </div>
        <div class="preview-body" id="previewBody"></div>
    </div>
</div>

<!-- ════════ TOAST ════════ -->
<div class="admin-toast" id="adminToast"></div>

<script>
const ANALYTICS_DATA = <?= json_encode($analytics) ?>;
const LEDGER_DATA    = <?= json_encode($fee_ledger) ?>;
</script>
<script src="admin.js"></script>

<script>
// ── Live User Management ──────────────────────────────
let allUsers = [];

function loadUsers() {
    fetch('process_admin.php?action=get_users', {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) return;
        allUsers = data.data;
        document.getElementById('userSubtitle').textContent = allUsers.length + ' registered users';
        renderUsers(allUsers);
    })
    .catch(e => console.error(e));
}

function renderUsers(users) {
    const tbody = document.getElementById('usersBody');
    if (!users.length) {
        tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:30px;color:#6b7280;">No users found</td></tr>';
        return;
    }
    tbody.innerHTML = users.map(u => `
        <tr class="ledger-row">
            <td class="td-mono">${u.id}</td>
            <td class="td-vendor">${escHtml(u.full_name)}</td>
            <td>${escHtml(u.email)}</td>
            <td>${escHtml(u.stall_name || '—')}</td>
            <td><span class="status-pill pill-${u.role}">${u.role}</span></td>
            <td><span class="status-pill pill-${u.is_active=='1'?'paid':'overdue'}">${u.is_active=='1'?'Active':'Inactive'}</span></td>
            <td>${u.created_at}</td>
            <td>
                <button class="ledger-btn" onclick="toggleUserStatus(${u.id}, ${u.is_active}, this)">
                    ${u.is_active=='1' ? 'Deactivate' : 'Activate'}
                </button>
            </td>
        </tr>`).join('');
}

function filterUsers(q) {
    const lq = q.toLowerCase();
    renderUsers(allUsers.filter(u =>
        u.full_name.toLowerCase().includes(lq) ||
        u.email.toLowerCase().includes(lq) ||
        (u.stall_name||'').toLowerCase().includes(lq)
    ));
}

function toggleUserStatus(userId, currentStatus, btn) {
    const newStatus = currentStatus == 1 ? 0 : 1;
    const fd = new FormData();
    fd.append('action', 'toggle_user');
    fd.append('user_id', userId);
    fd.append('is_active', newStatus);
    fetch('process_admin.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showToast(data.message, 'success');
            loadUsers();
        } else {
            showToast(data.message || 'Error', 'error');
        }
    });
}

function escHtml(s) {
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}

// Hook into section switch
const _origSwitch = typeof switchSection === 'function' ? switchSection : null;
document.querySelectorAll('.anav-item').forEach(a => {
    a.addEventListener('click', () => {
        const sec = a.getAttribute('onclick')?.match(/'(\w+)'/)?.[1];
        if (sec === 'users') setTimeout(loadUsers, 100);
    });
});
</script>
</body>
</html>