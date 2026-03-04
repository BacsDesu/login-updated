<?php
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit;
}
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: index.php');
    exit;
}

$user_name  = $_SESSION['user_name']  ?? 'Vendor';
$user_email = $_SESSION['user_email'] ?? '';
$user_id    = (int)($_SESSION['user_id'] ?? 0);

$host   = 'localhost';
$dbname = 'login_system';
$dbuser = 'root';
$dbpass = '';

$stall_name  = '';
$avatar_path = '';
$bio         = '';

$upcoming_events   = [];
$announcements     = [];
$schedule          = [];
$revenue_data      = [];
$months            = [];
$total_revenue     = 0;
$application_count = 0;
$sales_count       = 0;
$inventory_count   = 0;

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $dbuser, $dbpass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Profile
    $stmt = $pdo->prepare("SELECT full_name, stall_name, avatar_path, bio FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($profile) {
        $user_name   = $profile['full_name']   ?: $user_name;
        $stall_name  = $profile['stall_name']  ?? '';
        $avatar_path = $profile['avatar_path'] ?? '';
        $bio         = $profile['bio']         ?? '';
        $_SESSION['user_name'] = $user_name;
    }

    // Upcoming events
    $colors = ['rose','gold','teal','blue','purple'];
    $stmt   = $pdo->query("SELECT id,title,start_date,end_date,location FROM events WHERE is_active=1 AND start_date>=CURDATE() ORDER BY start_date ASC LIMIT 5");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $i => $ev) {
        $upcoming_events[] = [
            'id'       => $ev['id'],
            'title'    => $ev['title'],
            'date'     => date('M j, Y', strtotime($ev['start_date'])),
            'location' => $ev['location'] ?? 'TBA',
            'color'    => $colors[$i % count($colors)],
        ];
    }

    // Announcements from active form templates
    $stmt  = $pdo->query("SELECT id,title,event_target,deadline,created_at FROM form_templates WHERE is_active=1 ORDER BY created_at DESC LIMIT 5");
    $icons = ['📢','✅','🎉','📋','🔔'];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $i => $f) {
        $diff = time() - strtotime($f['created_at']);
        $ago  = $diff < 3600 ? round($diff/60).'m ago' : ($diff < 86400 ? round($diff/3600).'h ago' : round($diff/86400).'d ago');
        $announcements[] = [
            'icon'  => $icons[$i % count($icons)],
            'title' => 'Application Open: ' . $f['title'],
            'body'  => $f['event_target']
                ? 'New form available for '.$f['event_target'].($f['deadline'] ? '. Deadline: '.date('M j, Y',strtotime($f['deadline'])) : '')
                : 'A new application form is now open.',
            'time'  => $ago,
        ];
    }
    if (empty($announcements)) {
        $announcements[] = ['icon'=>'📢','title'=>'No announcements yet','body'=>'Check back later for updates.','time'=>''];
    }

    // My schedule
    $stmt = $pdo->prepare("
        SELECT sa.id, sa.business_name, sa.status,
               e.title AS event_title, e.start_date, e.end_date
        FROM stall_applications sa
        LEFT JOIN events e ON e.id = sa.event_id
        WHERE sa.user_id = ?
        ORDER BY e.start_date ASC LIMIT 10
    ");
    $stmt->execute([$user_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $schedule[] = [
            'event'  => $s['event_title'] ?? $s['business_name'],
            'start'  => $s['start_date'] ? date('M j', strtotime($s['start_date'])) : '—',
            'end'    => $s['end_date']   ? date('M j', strtotime($s['end_date']))   : '—',
            'status' => $s['status'] ?? 'Submitted',
            'stall'  => '#'.str_pad($s['id'],3,'0',STR_PAD_LEFT),
        ];
    }

    // Revenue
    $stmt = $pdo->prepare("SELECT DATE_FORMAT(sale_date,'%b') AS mon, SUM(amount) AS total FROM sales WHERE user_id=? AND sale_date>=DATE_SUB(CURDATE(),INTERVAL 12 MONTH) GROUP BY DATE_FORMAT(sale_date,'%Y-%m') ORDER BY DATE_FORMAT(sale_date,'%Y-%m') ASC");
    $stmt->execute([$user_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) { $revenue_data[] = (float)$r['total']; $months[] = $r['mon']; }
    if (empty($revenue_data)) { $revenue_data = [0]; $months = [date('M')]; }
    $total_revenue = array_sum($revenue_data);

    // Quick stats
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM stall_applications WHERE user_id=?"); $stmt->execute([$user_id]); $application_count = (int)$stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM sales WHERE user_id=?");              $stmt->execute([$user_id]); $sales_count       = (int)$stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM inventory WHERE user_id=?");          $stmt->execute([$user_id]); $inventory_count   = (int)$stmt->fetchColumn();

} catch (Exception $e) {
    error_log("Dashboard DB: ".$e->getMessage());
}

$peer_ratings = [
    ['label'=>'Product Quality','value'=>0],['label'=>'Customer Service','value'=>0],
    ['label'=>'Stall Presentation','value'=>0],['label'=>'Punctuality','value'=>0],
];
$first_name = explode(' ', $user_name)[0];
$hour       = (int)date('H');
$greeting   = $hour < 12 ? 'Good Morning' : ($hour < 18 ? 'Good Afternoon' : 'Good Evening');
$raw        = explode(' ', $user_name);
$initials   = strtoupper(substr($raw[0],0,1).(isset($raw[1]) ? substr($raw[1],0,1) : ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SmartPOP — Dashboard</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@300;400;500;600&family=DM+Sans:ital,wght@0,300;0,400;0,500;0,600;1,300&display=swap" rel="stylesheet">
<link rel="stylesheet" href="dashboard.css">
<link rel="stylesheet" href="profile_edit.css">
<style>
/* ── New Application Modal ── */
.na-ov{display:none;position:fixed;inset:0;background:rgba(2,6,23,.82);backdrop-filter:blur(7px);z-index:1200;align-items:center;justify-content:center;padding:16px}
.na-ov.open{display:flex}
.na-modal{background:#0f172a;border:1px solid rgba(255,255,255,.09);border-radius:20px;width:100%;max-width:560px;max-height:90vh;display:flex;flex-direction:column;box-shadow:0 32px 80px rgba(0,0,0,.75);animation:naIn .28s cubic-bezier(.16,1,.3,1)}
@keyframes naIn{from{opacity:0;transform:translateY(18px) scale(.97)}to{opacity:1;transform:none}}
.na-hdr{display:flex;align-items:center;gap:10px;padding:17px 22px 13px;border-bottom:1px solid rgba(255,255,255,.06);flex-shrink:0}
.na-back{background:rgba(255,255,255,.07);border:none;border-radius:8px;color:#94a3b8;cursor:pointer;padding:5px 11px;font-size:12px;display:none;align-items:center;gap:4px;font-family:'DM Sans',sans-serif;transition:background .15s}
.na-back:hover{background:rgba(255,255,255,.12)}.na-back.vis{display:flex}
.na-title{font-size:16px;font-weight:600;color:#f1f5f9;font-family:'DM Sans',sans-serif;flex:1}
.na-x{background:none;border:none;color:#64748b;cursor:pointer;padding:4px;border-radius:6px;line-height:0;transition:color .15s}.na-x:hover{color:#f1f5f9}
.na-steps{display:flex;align-items:center;padding:9px 22px;border-bottom:1px solid rgba(255,255,255,.05);gap:6px;flex-shrink:0}
.na-s{display:flex;align-items:center;gap:6px;font-size:11px;color:#475569;font-family:'DM Sans',sans-serif}
.na-s.act{color:#93c5fd}.na-s.done{color:#4ade80}
.na-sn{width:19px;height:19px;border-radius:50%;border:1.5px solid currentColor;display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:700;flex-shrink:0}
.na-s.done .na-sn{background:#4ade80;border-color:#4ade80;color:#0f172a}.na-s.act .na-sn{background:#1e3a5f;border-color:#93c5fd}
.na-sdiv{flex:1;height:1px;background:rgba(255,255,255,.08)}
.na-body{flex:1;overflow-y:auto;padding:18px 22px;scrollbar-width:thin;scrollbar-color:rgba(255,255,255,.1) transparent}
/* list */
.na-list{display:flex;flex-direction:column;gap:8px}
.na-item{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.07);border-radius:12px;padding:13px 15px;cursor:pointer;display:flex;align-items:center;gap:12px;transition:background .15s,border-color .2s,transform .12s}
.na-item:hover{background:rgba(255,255,255,.08);border-color:rgba(99,179,237,.4);transform:translateX(2px)}
.na-item-ico{width:36px;height:36px;border-radius:9px;background:linear-gradient(135deg,#1e3a5f,#1e1b4b);display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0}
.na-item-info{flex:1;min-width:0}
.na-item-title{font-size:14px;font-weight:600;color:#f1f5f9;margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-family:'DM Sans',sans-serif}
.na-item-meta{font-size:11px;color:#64748b;display:flex;gap:8px;flex-wrap:wrap}
.na-item-arr{color:#475569;line-height:0}.na-item:hover .na-item-arr{color:#93c5fd}
.na-empty,.na-loading{text-align:center;padding:48px 20px;color:#475569;font-size:13px;font-family:'DM Sans',sans-serif}
.na-spin{display:inline-block;width:22px;height:22px;border:2px solid rgba(255,255,255,.1);border-top-color:#93c5fd;border-radius:50%;animation:nspin .7s linear infinite;margin-bottom:8px}
@keyframes nspin{to{transform:rotate(360deg)}}
/* fields */
.na-form-banner{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.07);border-radius:10px;padding:12px 14px;margin-bottom:16px}
.na-form-banner-t{font-size:14px;font-weight:600;color:#f1f5f9;font-family:'DM Sans',sans-serif;margin-bottom:3px}
.na-form-banner-m{font-size:11px;color:#64748b;display:flex;gap:10px;flex-wrap:wrap}
.na-fg{margin-bottom:13px}
.na-lbl{display:block;font-size:12px;font-weight:500;color:#94a3b8;margin-bottom:5px;font-family:'DM Sans',sans-serif}
.na-lbl .req{color:#f87171;margin-left:2px}
.na-inp,.na-sel,.na-ta{width:100%;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.1);border-radius:9px;color:#f1f5f9;font-size:13px;font-family:'DM Sans',sans-serif;padding:9px 13px;outline:none;transition:border-color .2s,box-shadow .2s;box-sizing:border-box}
.na-inp:focus,.na-sel:focus,.na-ta:focus{border-color:#60a5fa;box-shadow:0 0 0 2px rgba(96,165,250,.15)}
.na-ta{resize:vertical;min-height:72px}.na-sel option{background:#1e293b}
.na-radio-g,.na-check-g{display:flex;flex-direction:column;gap:6px}
.na-radio-i,.na-check-i{display:flex;align-items:center;gap:9px;cursor:pointer;font-size:13px;color:#cbd5e1;padding:7px 11px;border-radius:8px;border:1px solid rgba(255,255,255,.07);transition:background .15s;font-family:'DM Sans',sans-serif}
.na-radio-i:hover,.na-check-i:hover{background:rgba(255,255,255,.05)}
.na-radio-i input,.na-check-i input{accent-color:#60a5fa;width:15px;height:15px}
.na-heading-f{font-size:12px;font-weight:700;color:#93c5fd;text-transform:uppercase;letter-spacing:.06em;padding:8px 0 3px;border-bottom:1px solid rgba(99,179,237,.2);margin-bottom:2px}
.field-err{border-color:#f87171 !important}
/* success */
.na-success{background:rgba(74,222,128,.06);border:1px solid rgba(74,222,128,.25);border-radius:14px;padding:28px 20px;text-align:center}
.na-suc-ico{font-size:44px;margin-bottom:10px}
.na-suc-h{font-size:17px;font-weight:700;color:#f1f5f9;margin-bottom:6px;font-family:'DM Sans',sans-serif}
.na-suc-sub{font-size:13px;color:#64748b;line-height:1.6}
.na-summary{text-align:left;margin-top:16px;background:rgba(255,255,255,.04);border-radius:9px;padding:12px 14px;font-size:12px;color:#94a3b8;line-height:1.9;font-family:'DM Sans',sans-serif}
.na-summary strong{color:#e2e8f0}
/* footer */
.na-ftr{display:flex;align-items:center;justify-content:flex-end;gap:8px;padding:12px 22px 18px;border-top:1px solid rgba(255,255,255,.06);flex-shrink:0}
.na-ftr-fb{flex:1;font-size:12px;color:#f87171;font-family:'DM Sans',sans-serif}
.na-btn-c{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.09);border-radius:999px;color:#94a3b8;font-size:13px;padding:8px 16px;cursor:pointer;font-family:'DM Sans',sans-serif;transition:background .15s}
.na-btn-c:hover{background:rgba(255,255,255,.1)}
.na-btn-s{background:linear-gradient(135deg,#3b82f6,#22c55e);border:none;border-radius:999px;color:#0f172a;font-size:13px;font-weight:700;padding:8px 20px;cursor:pointer;font-family:'DM Sans',sans-serif;display:flex;align-items:center;gap:6px;transition:opacity .15s,transform .1s}
.na-btn-s:hover{opacity:.9;transform:translateY(-1px)}.na-btn-s:disabled{opacity:.45;cursor:not-allowed;transform:none}
.na-btn-done{background:linear-gradient(135deg,#22c55e,#3b82f6);border:none;border-radius:999px;color:#0f172a;font-size:13px;font-weight:700;padding:8px 20px;cursor:pointer;font-family:'DM Sans',sans-serif}
</style>
</head>
<body>

<div class="ambient-bg">
    <div class="orb orb-1"></div><div class="orb orb-2"></div><div class="orb orb-3"></div>
    <div class="grain"></div>
</div>

<div class="shell" id="shell">

<!-- ══ SIDEBAR ══ -->
<aside class="sidebar" id="sidebar">
    <div class="profile-card" id="profileCard">
        <div class="avatar-ring">
            <div class="avatar" id="sidebarAvatar">
                <?php if ($avatar_path && file_exists(__DIR__.'/'.$avatar_path)): ?>
                    <img src="<?php echo htmlspecialchars($avatar_path); ?>?v=<?php echo time(); ?>" alt="Avatar" class="avatar-img">
                <?php else: ?>
                    <?php echo htmlspecialchars($initials); ?>
                <?php endif; ?>
            </div>
            <div class="status-dot"></div>
        </div>
        <div class="profile-info">
            <span class="profile-name" id="sidebarName"><?php echo htmlspecialchars($user_name); ?></span>
            <?php if ($stall_name): ?>
                <span class="profile-stall" id="sidebarStall">🏪 <?php echo htmlspecialchars($stall_name); ?></span>
            <?php else: ?>
                <span class="profile-email" id="sidebarStall"><?php echo htmlspecialchars($user_email); ?></span>
            <?php endif; ?>
        </div>
        <button class="profile-chevron" id="profileToggle">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
        </button>
        <div class="profile-dropdown" id="profileDropdown">
            <a href="#" class="dd-item" onclick="openProfileEdit(); return false;">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                Edit Profile
            </a>
            <div class="dd-sep"></div>
            <a href="?logout=1" class="dd-item dd-danger">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                Log Out
            </a>
        </div>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-label">MENU</div>
        <a href="#" class="nav-item active" onclick="setTab(this,'overview'); return false;">
            <span class="nav-icon"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg></span>
            <span>Dashboard</span><span class="nav-glow"></span>
        </a>
        <a href="#" class="nav-item" id="navApp" onclick="setTab(this,'application'); loadMyApps(); return false;">
            <span class="nav-icon"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg></span>
            <span>My Application</span>
            <span class="nav-badge" id="appBadge" style="<?php echo $application_count > 0 ? '' : 'display:none'; ?>"><?php echo $application_count; ?></span>
            <span class="nav-glow"></span>
        </a>
        <a href="#" class="nav-item" onclick="setTab(this,'schedule'); return false;">
            <span class="nav-icon"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></span>
            <span>Schedule</span><span class="nav-glow"></span>
        </a>
    </nav>

    <div class="sidebar-bottom">
        <div class="sidebar-rule"></div>
        <a href="?logout=1" class="logout-btn">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            <span>Log Out</span>
        </a>
    </div>
</aside>

<!-- ══ MAIN ══ -->
<main class="main-content" id="mainContent">

    <header class="topbar">
        <button class="hamburger" id="hamburger" onclick="toggleSidebar()"><span></span><span></span><span></span></button>
        <div class="topbar-brand">NISU&nbsp;<strong>SmartPOP</strong></div>
        <div class="topbar-right">
            <button class="icon-btn" id="notifBtn">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                <span class="notif-pip"></span>
            </button>
            <span class="topbar-date"><?php echo date('D, M j'); ?></span>
        </div>
    </header>

    <div class="greeting-row">
        <div>
            <p class="greeting-sub"><?php echo $greeting; ?>,</p>
            <h1 class="greeting-name"><?php echo htmlspecialchars($first_name); ?> <span class="wave">👋</span></h1>
        </div>
        <div class="greeting-chips">
            <div class="chip"><span class="chip-val"><?php echo count($upcoming_events); ?></span><span class="chip-lbl">Events</span></div>
            <div class="chip-div"></div>
            <div class="chip"><span class="chip-val">₱<?php echo number_format($total_revenue/1000,1); ?>K</span><span class="chip-lbl">Revenue</span></div>
            <div class="chip-div"></div>
            <div class="chip"><span class="chip-val" id="chipApps"><?php echo $application_count; ?></span><span class="chip-lbl">Apps</span></div>
        </div>
    </div>

    <!-- OVERVIEW TAB -->
    <div class="tab-pane active" id="tab-overview">

        <section class="sec">
            <div class="sec-head">
                <h2 class="sec-title">Upcoming Events</h2>
                <a href="#" class="sec-more" onclick="setTab(document.querySelector('.nav-item:last-of-type'),'schedule'); return false;">View all →</a>
            </div>
            <div class="events-grid">
                <?php if (empty($upcoming_events)): ?>
                <div class="ann-item" style="grid-column:1/-1">
                    <div class="ann-ico">📅</div>
                    <div class="ann-body"><div class="ann-title">No upcoming events</div><div class="ann-text">Check back later for new events.</div></div>
                </div>
                <?php else: foreach ($upcoming_events as $i => $ev): ?>
                <div class="event-card ev-<?php echo $ev['color']; ?>" style="animation-delay:<?php echo $i*0.08; ?>s">
                    <div class="ev-dot"></div>
                    <div class="ev-meta">
                        <span class="ev-date"><?php echo htmlspecialchars($ev['date']); ?></span>
                        <span class="ev-loc">📍 <?php echo htmlspecialchars($ev['location']); ?></span>
                    </div>
                    <h3 class="ev-title"><?php echo htmlspecialchars($ev['title']); ?></h3>
                    <button class="ev-btn" onclick="openNewAppModal()">Apply Now</button>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </section>

        <section class="sec">
            <div class="sec-head">
                <h2 class="sec-title">Announcements</h2>
                <?php if (!empty($announcements) && $announcements[0]['title'] !== 'No announcements yet'): ?><span class="badge-new">NEW</span><?php endif; ?>
            </div>
            <div class="announce-list">
                <?php foreach ($announcements as $i => $a): ?>
                <div class="ann-item" style="animation-delay:<?php echo 0.25+$i*0.08; ?>s">
                    <div class="ann-ico"><?php echo $a['icon']; ?></div>
                    <div class="ann-body">
                        <div class="ann-title"><?php echo htmlspecialchars($a['title']); ?></div>
                        <div class="ann-text"><?php echo htmlspecialchars($a['body']); ?></div>
                    </div>
                    <?php if ($a['time']): ?><span class="ann-time"><?php echo $a['time']; ?></span><?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="sec">
            <div class="sec-head"><h2 class="sec-title">Schedule Reminder</h2></div>
            <?php if (empty($schedule)): ?>
            <div class="ann-item">
                <div class="ann-ico">📋</div>
                <div class="ann-body"><div class="ann-title">No events scheduled</div><div class="ann-text">Apply for an event to see your schedule here.</div></div>
            </div>
            <?php else: foreach ($schedule as $s): ?>
            <div class="sched-row">
                <div class="sched-pip s-<?php echo strtolower(str_replace(' ','',$s['status'])); ?>"></div>
                <div class="sched-info">
                    <div class="sched-name"><?php echo htmlspecialchars($s['event']); ?></div>
                    <div class="sched-sub">📅 <?php echo $s['start']; ?> – <?php echo $s['end']; ?> &nbsp;·&nbsp; 🏪 Stall <?php echo $s['stall']; ?></div>
                </div>
                <span class="sched-tag s-tag-<?php echo strtolower(str_replace(' ','',$s['status'])); ?>"><?php echo $s['status']; ?></span>
            </div>
            <?php endforeach; endif; ?>
        </section>

    </div><!-- /overview -->

    <!-- MY APPLICATION TAB -->
    <div class="tab-pane" id="tab-application">
        <section class="sec">
            <div class="sec-head">
                <h2 class="sec-title">My Applications</h2>
                <button class="btn-sm-primary" onclick="openNewAppModal()">+ New Application</button>
            </div>
            <div class="app-list" id="myAppList">
                <div class="ann-item"><div class="ann-ico">⏳</div><div class="ann-body"><div class="ann-title">Loading…</div></div></div>
            </div>
        </section>
    </div>

    <!-- SCHEDULE TAB -->
    <div class="tab-pane" id="tab-schedule">
        <section class="sec">
            <div class="sec-head"><h2 class="sec-title">My Schedule</h2></div>
            <div class="cal-week">
                <?php
                $dow = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
                $td  = (int)date('j');
                for ($d = $td; $d <= $td+6; $d++): ?>
                <div class="cal-cell<?php echo $d===$td?' cal-today':''; ?>">
                    <span class="cal-wd"><?php echo $dow[($d+5)%7]; ?></span>
                    <span class="cal-dd"><?php echo $d; ?></span>
                </div>
                <?php endfor; ?>
            </div>
            <?php if (empty($schedule)): ?>
            <div class="ann-item"><div class="ann-ico">📋</div><div class="ann-body"><div class="ann-title">No events scheduled yet</div><div class="ann-text">Once your applications are approved they will appear here.</div></div></div>
            <?php else: foreach ($schedule as $s): ?>
            <div class="sched-row large">
                <div class="sched-pip s-<?php echo strtolower(str_replace(' ','',$s['status'])); ?>"></div>
                <div class="sched-info">
                    <div class="sched-name"><?php echo htmlspecialchars($s['event']); ?></div>
                    <div class="sched-sub">📅 <?php echo $s['start']; ?> – <?php echo $s['end']; ?> &nbsp;·&nbsp; 🏪 Stall <?php echo $s['stall']; ?></div>
                    <div class="sched-reminder"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/></svg> Reminder set · 1 day before</div>
                </div>
                <span class="sched-tag s-tag-<?php echo strtolower(str_replace(' ','',$s['status'])); ?>"><?php echo $s['status']; ?></span>
            </div>
            <?php endforeach; endif; ?>
        </section>
    </div>

</main>

<!-- ══ RIGHT PANEL ══ -->
<aside class="right-panel" id="rightPanel">
    <div class="widget rev-widget">
        <div class="w-head"><div><div class="w-label">Total Revenue</div><div class="w-val" id="totalRevenue">₱0</div></div></div>
        <canvas id="revenueChart" height="150"></canvas>
        <div class="chart-labels" id="chartLabels"></div>
    </div>
    <div class="widget rate-widget">
        <div class="w-head"><div><div class="w-label">Peer Rating</div><div class="w-val">— <span class="stars">★★★★★</span></div></div></div>
        <div class="rbars">
            <?php foreach ($peer_ratings as $r): ?>
            <div class="rbar">
                <span class="rbar-lbl"><?php echo htmlspecialchars($r['label']); ?></span>
                <div class="rbar-track"><div class="rbar-fill" data-val="<?php echo $r['value']; ?>"></div></div>
                <span class="rbar-num"><?php echo $r['value']; ?>%</span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="widget qs-widget">
        <div class="w-label">Quick Stats</div>
        <div class="qs-grid">
            <div class="qs-item"><div class="qs-ico">🏪</div><div class="qs-num" id="qsApps"><?php echo $application_count; ?></div><div class="qs-lbl">Applications</div></div>
            <div class="qs-item"><div class="qs-ico">📦</div><div class="qs-num"><?php echo $inventory_count; ?></div><div class="qs-lbl">Items</div></div>
            <div class="qs-item"><div class="qs-ico">🧾</div><div class="qs-num"><?php echo $sales_count; ?></div><div class="qs-lbl">Sales</div></div>
            <div class="qs-item"><div class="qs-ico">📅</div><div class="qs-num"><?php echo count($upcoming_events); ?></div><div class="qs-lbl">Events</div></div>
        </div>
    </div>
</aside>

</div><!-- /shell -->

<!-- ══ PROFILE MODAL ══ -->
<div class="modal-backdrop" id="profileModalBackdrop" onclick="closeProfileEdit()"></div>
<div class="profile-modal" id="profileModal" role="dialog" aria-modal="true">
    <div class="pm-header">
        <h2 class="pm-title">Edit Profile</h2>
        <button class="pm-close" onclick="closeProfileEdit()"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <div class="pm-body">
        <div class="pm-avatar-section">
            <div class="pm-avatar-wrap" id="pmAvatarWrap">
                <div class="pm-avatar" id="pmAvatarDisplay">
                    <?php if ($avatar_path && file_exists(__DIR__.'/'.$avatar_path)): ?>
                        <img src="<?php echo htmlspecialchars($avatar_path); ?>?v=<?php echo time(); ?>" alt="Avatar" id="pmAvatarImg" class="pm-avatar-img">
                    <?php else: ?>
                        <span id="pmAvatarInitials"><?php echo htmlspecialchars($initials); ?></span>
                        <img src="" alt="" id="pmAvatarImg" class="pm-avatar-img" style="display:none">
                    <?php endif; ?>
                </div>
                <label class="pm-avatar-overlay" for="avatarInput" title="Change photo">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                    <span>Change</span>
                </label>
                <input type="file" id="avatarInput" name="avatar" accept="image/jpeg,image/png,image/webp,image/gif" style="display:none">
            </div>
            <div class="pm-avatar-hints">
                <p class="pm-hint-title">Profile Photo</p>
                <p class="pm-hint-sub">JPG, PNG or WebP · Max 3MB</p>
                <button type="button" class="pm-remove-avatar" id="pmRemoveAvatar" onclick="removeAvatarPreview()" <?php echo (!$avatar_path)?'style="display:none"':''; ?>>Remove photo</button>
            </div>
        </div>
        <form id="profileForm" enctype="multipart/form-data">
            <div class="pm-fields">
                <div class="pm-field-group">
                    <label class="pm-label" for="pm_full_name">Full Name <span class="pm-required">*</span></label>
                    <div class="pm-input-wrap">
                        <svg class="pm-input-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        <input type="text" id="pm_full_name" name="full_name" value="<?php echo htmlspecialchars($user_name); ?>" placeholder="Your full name" maxlength="100" required>
                    </div>
                </div>
                <div class="pm-field-group">
                    <label class="pm-label" for="pm_stall_name">Stall Name</label>
                    <div class="pm-input-wrap">
                        <svg class="pm-input-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                        <input type="text" id="pm_stall_name" name="stall_name" value="<?php echo htmlspecialchars($stall_name); ?>" placeholder="e.g. Maria's Kitchen" maxlength="100">
                    </div>
                </div>
                <div class="pm-field-group">
                    <label class="pm-label" for="pm_email">Email Address</label>
                    <div class="pm-input-wrap pm-input-readonly">
                        <svg class="pm-input-icon" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                        <input type="email" id="pm_email" value="<?php echo htmlspecialchars($user_email); ?>" readonly tabindex="-1">
                    </div>
                    <span class="pm-field-note">Email cannot be changed here.</span>
                </div>
                <div class="pm-field-group">
                    <label class="pm-label" for="pm_bio">Bio <span class="pm-char-count" id="bioCharCount"><?php echo strlen($bio); ?>/500</span></label>
                    <div class="pm-input-wrap pm-textarea-wrap">
                        <textarea id="pm_bio" name="bio" rows="3" placeholder="Tell customers about yourself…" maxlength="500"><?php echo htmlspecialchars($bio); ?></textarea>
                    </div>
                </div>
            </div>
            <div class="pm-footer">
                <div class="pm-feedback" id="pmFeedback"></div>
                <div class="pm-actions">
                    <button type="button" class="pm-btn-cancel" onclick="closeProfileEdit()">Cancel</button>
                    <button type="submit" class="pm-btn-save" id="pmSaveBtn">
                        <span class="pm-btn-text">Save Changes</span>
                        <span class="pm-btn-spinner" style="display:none"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="spin"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg></span>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ══ NEW APPLICATION MODAL ══ -->
<div class="na-ov" id="naOv" onclick="naBgClick(event)">
  <div class="na-modal">
    <div class="na-hdr">
      <button class="na-back" id="naBack" onclick="naGoBack()">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg> Back
      </button>
      <span class="na-title" id="naTitle">Choose an Application Form</span>
      <button class="na-x" onclick="naClose()"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
    </div>
    <div class="na-steps" id="naSteps">
      <div class="na-s act" id="naSt1"><div class="na-sn">1</div><span>Select Form</span></div>
      <div class="na-sdiv"></div>
      <div class="na-s" id="naSt2"><div class="na-sn">2</div><span>Fill Details</span></div>
      <div class="na-sdiv"></div>
      <div class="na-s" id="naSt3"><div class="na-sn">3</div><span>Submitted</span></div>
    </div>
    <div class="na-body" id="naBody"></div>
    <div class="na-ftr" id="naFtr">
      <div class="na-ftr-fb" id="naFb"></div>
      <button class="na-btn-c" onclick="naClose()">Cancel</button>
    </div>
  </div>
</div>

<!-- ══ JS DATA ══ -->
<script>
const PROFILE_DATA   = { full_name:<?php echo json_encode($user_name);?>, stall_name:<?php echo json_encode($stall_name);?>, bio:<?php echo json_encode($bio);?>, avatar_path:<?php echo json_encode($avatar_path?:'');?>, initials:<?php echo json_encode($initials);?>, email:<?php echo json_encode($user_email);?> };
const REVENUE_DATA   = <?php echo json_encode($revenue_data);?>;
const REVENUE_MONTHS = <?php echo json_encode($months);?>;
</script>
<script src="dashboard.js"></script>
<script src="profile_edit.js"></script>
<script>
/* ═══════════════════════════════════════════
   LOAD MY APPLICATIONS FROM DATABASE
═══════════════════════════════════════════ */
function loadMyApps() {
    const list = document.getElementById('myAppList');
    list.innerHTML = '<div class="ann-item"><div class="ann-ico" style="font-size:18px">⏳</div><div class="ann-body"><div class="ann-title">Loading your applications…</div></div></div>';

    fetch('api_vendor.php?action=my_applications', { headers:{'X-Requested-With':'XMLHttpRequest'} })
    .then(r => r.json())
    .then(data => {
        if (!data.success || !data.data || !data.data.length) {
            list.innerHTML = `<div class="ann-item">
                <div class="ann-ico">📋</div>
                <div class="ann-body">
                    <div class="ann-title">No applications yet</div>
                    <div class="ann-text">Click <strong>+ New Application</strong> to apply for an open event.</div>
                </div></div>`;
            refreshCounts(0); return;
        }
        refreshCounts(data.data.length);

        const stepMap   = {Submitted:1,'Info Requested':1,Approved:2,Paid:3,Confirmed:4,Rejected:1};
        const tagMap    = {Submitted:'tag-submitted',Approved:'tag-approved',Rejected:'tag-rejected',Paid:'tag-paid',Confirmed:'tag-approved','Info Requested':'tag-pending'};
        const stripeMap = {Submitted:'stripe-submitted',Approved:'stripe-approved',Rejected:'stripe-rejected',Paid:'stripe-paid',Confirmed:'stripe-approved'};
        const labels    = ['Applied','Review','Approval','Payment','Confirmed'];

        list.innerHTML = data.data.map(app => {
            const step   = stepMap[app.status] ?? 1;
            const stripe = stripeMap[app.status] ?? 'stripe-pending';
            const tag    = tagMap[app.status]    ?? 'tag-pending';

            const stepsHtml = labels.map((lbl,i) =>
                `<div class="step ${i<step?'done':i===step?'active':''}"><div class="step-dot"></div><span>${lbl}</span></div>` +
                (i < labels.length-1 ? `<div class="step-line ${i<step?'done':''}"></div>` : '')
            ).join('');

            const title  = esc(app.event_title || app.business_name || 'Application');
            const meta   = [app.stall_type, app.stall_size].filter(Boolean).join(' · ');
            const dates  = (app.start_date && app.end_date)
                ? ` · ${fmtDate(app.start_date)} – ${fmtDate(app.end_date)}` : '';

            return `<div class="app-card">
                <div class="app-stripe ${stripe}"></div>
                <div class="app-body">
                    <div class="app-row">
                        <h3 class="app-name">${title}</h3>
                        <span class="app-tag ${tag}">${esc(app.status)}</span>
                    </div>
                    <p class="app-sub">${esc(meta)}${dates}</p>
                    <div class="progress-steps">${stepsHtml}</div>
                </div>
            </div>`;
        }).join('');
    })
    .catch(() => {
        list.innerHTML = '<div class="ann-item"><div class="ann-ico">⚠️</div><div class="ann-body"><div class="ann-title">Could not load applications</div></div></div>';
    });
}

function refreshCounts(n) {
    ['appBadge','chipApps','qsApps'].forEach(id => {
        const el = document.getElementById(id);
        if (!el) return;
        if (id === 'appBadge') { el.textContent = n; el.style.display = n > 0 ? '' : 'none'; }
        else el.textContent = n;
    });
}

function fmtDate(d) {
    return new Date(d).toLocaleDateString('en-PH',{month:'short',day:'numeric'});
}

// Load immediately so badge is accurate
document.addEventListener('DOMContentLoaded', loadMyApps);

/* ═══════════════════════════════════════════
   NEW APPLICATION MODAL
═══════════════════════════════════════════ */
let naForm = null; // currently selected form

function openNewAppModal() {
    naForm = null;
    document.getElementById('naOv').classList.add('open');
    document.body.style.overflow = 'hidden';
    naStep(1);
    naLoadForms();
}
function naClose() {
    document.getElementById('naOv').classList.remove('open');
    document.body.style.overflow = '';
}
function naGoBack() { naForm = null; naStep(1); naLoadForms(); }
function naBgClick(e) { if (e.target === document.getElementById('naOv')) naClose(); }

function naStep(s) {
    const titles = {1:'Choose an Application Form', 2:'Fill In Application Details', 3:'Application Submitted!'};
    document.getElementById('naTitle').textContent = titles[s];
    document.getElementById('naBack').classList.toggle('vis', s === 2);

    [1,2,3].forEach(n => {
        const el  = document.getElementById('naSt'+n);
        el.className = 'na-s' + (n < s ? ' done' : n === s ? ' act' : '');
        el.querySelector('.na-sn').textContent = n < s ? '✓' : n;
    });

    const ftr = document.getElementById('naFtr');
    ftr.innerHTML = '<div class="na-ftr-fb" id="naFb"></div>';
    if (s <= 2) ftr.innerHTML += `<button class="na-btn-c" onclick="naClose()">Cancel</button>`;
    if (s === 2) ftr.innerHTML += `<button class="na-btn-s" id="naSubBtn" onclick="naSubmit()">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
        Submit Application</button>`;
    if (s === 3) ftr.innerHTML += `<button class="na-btn-done" onclick="naDone()">Close</button>`;
}

/* Step 1 — list forms */
function naLoadForms() {
    document.getElementById('naBody').innerHTML =
        '<div class="na-loading"><div class="na-spin"></div><p>Loading available forms…</p></div>';

    fetch('api_vendor.php?action=available_forms', { headers:{'X-Requested-With':'XMLHttpRequest'} })
    .then(r => r.json())
    .then(data => {
        const forms = data.success && data.data ? data.data : [];
        if (!forms.length) {
            document.getElementById('naBody').innerHTML =
                '<div class="na-empty"><svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="opacity:.35;margin-bottom:10px"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg><p>No application forms are currently open.</p><p style="font-size:11px;margin-top:4px;color:#334155">Check back later for new events.</p></div>';
            return;
        }
        const icons = ['🛒','🏪','🎪','🍱','🎨','🧵','🌿','💼'];
        document.getElementById('naBody').innerHTML =
            '<div class="na-list">' + forms.map((f,i) => `
            <div class="na-item" onclick="naPickForm(${f.id})">
                <div class="na-item-ico">${icons[i%icons.length]}</div>
                <div class="na-item-info">
                    <div class="na-item-title">${esc(f.title)}</div>
                    <div class="na-item-meta">
                        ${f.event_target?`<span>🎪 ${esc(f.event_target)}</span>`:''}
                        ${f.deadline?`<span>⏳ Deadline: ${esc(f.deadline)}</span>`:'<span style="color:#4ade80">✓ Open</span>'}
                    </div>
                </div>
                <div class="na-item-arr"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg></div>
            </div>`).join('') + '</div>';
    })
    .catch(() => {
        document.getElementById('naBody').innerHTML = '<div class="na-empty"><p>Could not load forms. Please try again.</p></div>';
    });
}

/* Step 2 — show fields */
function naPickForm(id) {
    document.getElementById('naBody').innerHTML =
        '<div class="na-loading"><div class="na-spin"></div><p>Loading form…</p></div>';
    naStep(2);

    fetch('api_vendor.php?action=get_form&form_id='+id, { headers:{'X-Requested-With':'XMLHttpRequest'} })
    .then(r => r.json())
    .then(data => {
        if (!data.success || !data.data) {
            document.getElementById('naBody').innerHTML = '<div class="na-empty"><p>Form not found or no longer available.</p></div>';
            return;
        }
        naForm = data.data;
        naRender(data.data);
    })
    .catch(() => {
        document.getElementById('naBody').innerHTML = '<div class="na-empty"><p>Failed to load form. Please try again.</p></div>';
    });
}

function naRender(form) {
    const fields = Array.isArray(form.fields) ? form.fields : [];
    let html = `<div class="na-form-banner">
        <div class="na-form-banner-t">${esc(form.title)}</div>
        <div class="na-form-banner-m">
            ${form.event_target?`<span>🎪 ${esc(form.event_target)}</span>`:''}
            ${form.deadline?`<span>⏳ Deadline: ${esc(form.deadline)}</span>`:''}
        </div>
    </div>`;

    // Always include the 3 core fields
    html += `
    <div class="na-fg">
        <label class="na-lbl">Business / Stall Name <span class="req">*</span></label>
        <input type="text" class="na-inp" data-key="business_name" placeholder="e.g. Maria's Kitchen" required>
    </div>
    <div class="na-fg">
        <label class="na-lbl">Stall Type <span class="req">*</span></label>
        <select class="na-sel" data-key="stall_type" required>
            <option value="">— Select type —</option>
            <option>Food</option><option>Crafts</option><option>Produce</option>
            <option>Clothing</option><option>Arts</option><option>Services</option><option>General</option>
        </select>
    </div>
    <div class="na-fg">
        <label class="na-lbl">Stall Size <span class="req">*</span></label>
        <select class="na-sel" data-key="stall_size" required>
            <option value="">— Select size —</option>
            <option>Small</option><option>Medium</option><option>Large</option>
        </select>
    </div>`;

    // Extra custom fields from Form Builder
    fields.forEach((f, idx) => {
        const k   = `custom_${idx}`;
        const lbl = esc(f.label||`Field ${idx+1}`);
        const ph  = esc(f.placeholder||'');
        const req = f.required ? ' required' : '';
        const rm  = f.required ? '<span class="req">*</span>' : '';
        switch (f.type) {
            case 'heading':  html += `<div class="na-heading-f">${lbl}</div>`; break;
            case 'textarea': html += `<div class="na-fg"><label class="na-lbl">${lbl} ${rm}</label><textarea class="na-ta" data-key="${k}" placeholder="${ph}"${req}></textarea></div>`; break;
            case 'email':    html += `<div class="na-fg"><label class="na-lbl">${lbl} ${rm}</label><input type="email" class="na-inp" data-key="${k}" placeholder="${ph}"${req}></div>`; break;
            case 'phone':    html += `<div class="na-fg"><label class="na-lbl">${lbl} ${rm}</label><input type="tel" class="na-inp" data-key="${k}" placeholder="${ph}"${req}></div>`; break;
            case 'date':     html += `<div class="na-fg"><label class="na-lbl">${lbl} ${rm}</label><input type="date" class="na-inp" data-key="${k}"${req}></div>`; break;
            case 'select': {
                const opts = (f.options||[]).map(o=>`<option value="${esc(o)}">${esc(o)}</option>`).join('');
                html += `<div class="na-fg"><label class="na-lbl">${lbl} ${rm}</label><select class="na-sel" data-key="${k}"${req}><option value="">— Select —</option>${opts}</select></div>`; break;
            }
            case 'radio': {
                const opts = (f.options||[]).map((o,oi)=>`<label class="na-radio-i"><input type="radio" name="nr${idx}" value="${esc(o)}" data-key="${k}"${req&&oi===0?' required':''}> ${esc(o)}</label>`).join('');
                html += `<div class="na-fg"><label class="na-lbl">${lbl} ${rm}</label><div class="na-radio-g">${opts}</div></div>`; break;
            }
            case 'checkbox': {
                const opts = (f.options||[f.label||'Yes']).map((o,oi)=>`<label class="na-check-i"><input type="checkbox" value="${esc(o)}" data-key="${k}_${oi}"> ${esc(o)}</label>`).join('');
                html += `<div class="na-fg"><label class="na-lbl">${lbl}</label><div class="na-check-g">${opts}</div></div>`; break;
            }
            default:
                html += `<div class="na-fg"><label class="na-lbl">${lbl} ${rm}</label><input type="text" class="na-inp" data-key="${k}" placeholder="${ph}"${req}></div>`;
        }
    });

    document.getElementById('naBody').innerHTML = html;
}

/* Step 3 — submit to api_vendor.php → saved in DB → visible in admin */
function naSubmit() {
    if (!naForm) return;
    const body    = document.getElementById('naBody');
    const answers = {};
    let   invalid = false;

    body.querySelectorAll('[data-key]').forEach(el => {
        const k = el.dataset.key;
        if (el.type === 'radio')    { if (el.checked) answers[k] = el.value; }
        else if (el.type === 'checkbox') { if (el.checked) { answers[k] = answers[k]||[]; answers[k].push(el.value); } }
        else answers[k] = el.value.trim();

        if (el.hasAttribute('required') && !el.value.trim()) {
            el.classList.add('field-err'); invalid = true;
        } else el.classList.remove('field-err');
    });

    if (invalid) { document.getElementById('naFb').textContent = 'Please fill in all required fields.'; return; }
    document.getElementById('naFb').textContent = '';

    const btn = document.getElementById('naSubBtn');
    btn.disabled = true;
    btn.innerHTML = '<div class="na-spin" style="width:13px;height:13px;border-width:2px;margin:0"></div> Submitting…';

    const fd = new FormData();
    fd.append('action',  'submit_form');
    fd.append('form_id', naForm.id);
    fd.append('answers', JSON.stringify(answers));

    fetch('api_vendor.php', { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body:fd })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            naShowSuccess(answers);
        } else {
            btn.disabled = false;
            btn.innerHTML = '<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg> Submit Application';
            document.getElementById('naFb').textContent = data.message || 'Submission failed.';
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.innerHTML = 'Submit Application';
        document.getElementById('naFb').textContent = 'Network error. Please try again.';
    });
}

function naShowSuccess(answers) {
    naStep(3);
    const lines = [
        answers.business_name && `<strong>Stall:</strong> ${esc(answers.business_name)}`,
        answers.stall_type    && `<strong>Type:</strong> ${esc(answers.stall_type)}`,
        answers.stall_size    && `<strong>Size:</strong> ${esc(answers.stall_size)}`,
        naForm.event_target   && `<strong>Event:</strong> ${esc(naForm.event_target)}`,
    ].filter(Boolean).join('<br>');

    document.getElementById('naBody').innerHTML = `
    <div class="na-success">
        <div class="na-suc-ico">🎉</div>
        <div class="na-suc-h">Application Submitted!</div>
        <div class="na-suc-sub">
            Your application for <strong>${esc(naForm.title)}</strong> has been received
            and is now visible to the admin for review.
        </div>
        ${lines?`<div class="na-summary">${lines}</div>`:''}
    </div>`;
}

function naDone() {
    naClose();
    loadMyApps(); // instantly refresh the list from DB
}

function esc(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}
</script>
</body>
</html>