<?php
/**
 * ISARMS - Shared Navigation Header
 * NEUST Theme: Royal Blue + Yellow Gold
 */
if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
$user     = getCurrentUser();
$role     = $user['role'] ?? '';
$initials = strtoupper(substr($user['name'] ?? 'U', 0, 1));

$navLinks = [];
switch ($role) {
    case 'Admin':
        $navLinks = [
            ['icon'=>'fa-gauge-high',         'label'=>'Dashboard',              'href'=>'dashboard.php'],
            ['icon'=>'fa-user-tie',           'label'=>'Faculty Staff',          'href'=>'users.php'],
            ['icon'=>'fa-graduation-cap',     'label'=>'Students',               'href'=>'students.php'],
            ['icon'=>'fa-user-plus',          'label'=>'Student Registration',   'href'=>'enrollment.php'],
            ['icon'=>'fa-book-open-reader',   'label'=>'Subjects',               'href'=>'subjects.php'],
            ['icon'=>'fa-diagram-project',    'label'=>'Programs',               'href'=>'programs.php'],
            ['icon'=>'fa-chalkboard-user',    'label'=>'Sections',               'href'=>'sections.php'],
            ['icon'=>'fa-calendar-days',      'label'=>'Academic Year',         'href'=>'acad_years.php'],
            ['icon'=>'fa-calculator',         'label'=>'GWA Rules',             'href'=>'gwa_settings.php'],
            ['icon'=>'fa-file-pen',           'label'=>'Grade Management',      'href'=>'grades.php'],
            ['icon'=>'fa-gear',               'label'=>'Settings',               'href'=>'settings.php'],
        ];
        break;
    case 'Teacher':
        $navLinks = [
            ['icon'=>'fa-gauge-high',     'label'=>'Dashboard',    'href'=>'dashboard.php'],
            ['icon'=>'fa-people-group',   'label'=>'My Classes',   'href'=>'classes.php'],
            ['icon'=>'fa-pen-to-square',  'label'=>'Encode Grades','href'=>'encode_grades.php'],
            ['icon'=>'fa-chart-column',   'label'=>'Class Report', 'href'=>'class_report.php'],
        ];
        break;
    case 'Registrar':
        $navLinks = [
            ['icon'=>'fa-gauge-high',       'label'=>'Dashboard',         'href'=>'dashboard.php'],
            ['icon'=>'fa-address-book',     'label'=>'Student Records',   'href'=>'students.php'],
            ['icon'=>'fa-chalkboard-user',  'label'=>'Sections',          'href'=>'sections.php'],
            ['icon'=>'fa-clipboard-check',  'label'=>'Grade Monitoring', 'href'=>'grade_approvals.php'],
            ['icon'=>'fa-ranking-star',     'label'=>'Leaderboard',       'href'=>'leaderboard.php'],
            ['icon'=>'fa-award',            'label'=>'Honors Validation', 'href'=>'honors.php'],
            ['icon'=>'fa-file-lines',       'label'=>'Reports',           'href'=>'reports.php'],
        ];
        break;
    case 'Student':
        $navLinks = [
            ['icon'=>'fa-gauge-high',     'label'=>'Dashboard',  'href'=>'dashboard.php'],
            ['icon'=>'fa-graduation-cap', 'label'=>'My Grades',  'href'=>'grades.php'],
            ['icon'=>'fa-calculator',     'label'=>'My GWA',     'href'=>'gwa.php'],
            ['icon'=>'fa-medal',          'label'=>'My Honors',  'href'=>'honors.php'],
            ['icon'=>'fa-id-card',        'label'=>'My Profile', 'href'=>'profile.php'],
        ];
        break;
}

$moduleBase = BASE_URL . 'modules/' . strtolower($role) . '/';

$notifCount = 0;
try {
    $db2 = Database::getInstance();
    $roleTypeMap = [
        'Student'   => 'Student',
      'Registrar' => 'User',
      'Admin'     => 'User',
        'Teacher'   => 'User',
    ];
    $rType = $roleTypeMap[$role] ?? 'User';
    $nr = $db2->fetchOne(
      "SELECT COUNT(*) AS c
       FROM notifications
       WHERE recipientID=:id AND recipientType=:rt AND isRead=0",
        [':id'=>$user['id'], ':rt'=>$rType]
    );
    $notifCount = (int)($nr['c'] ?? 0);
} catch(Exception $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?= htmlspecialchars($pageTitle ?? 'Dashboard') ?> — ISARMS | NEUST</title>
  <link rel="icon" type="image/png" href="<?= BASE_URL ?>assets/img/neust-logo.png"/>
  <link rel="shortcut icon" type="image/png" href="<?= BASE_URL ?>assets/img/neust-logo.png"/>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Source+Sans+3:wght@300;400;600;700&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      /* ── NEUST Brand ── */
      --blue:        #1a3a8c;
      --blue-dark:   #0f2260;
      --blue-mid:    #2451c8;
      --blue-light:  #e8eef8;
      --blue-hover:  rgba(255,255,255,0.09);
      --gold:        #f5c518;
      --gold-deep:   #c9a300;
      --gold-light:  #fffbe6;
      /* ── Neutrals ── */
      --text:        #111827;
      --text-dark:   #111827;
      --text-muted:  #526174;
      --border:      #dce4f0;
      --bg:          #f0f4fb;
      --white:       #ffffff;
      /* ── Semantic ── */
      --success:     #16a34a;
      --danger:      #dc2626;
      --warning:     #d97706;
      --info:        #2563eb;
      --green:       var(--blue);
      --green-dark:  var(--blue-dark);
      --green-light: var(--blue-light);
      --light:       #f8fafc;
      /* ── Layout ── */
      --sidebar-w:   265px;
      --header-h:    64px;
      --shadow-sm:   0 2px 8px rgba(15,34,96,0.07);
      --shadow:      0 6px 24px rgba(15,34,96,0.13);
      --radius:      12px;
    }

    body,
    input,
    select,
    textarea,
    button,
    table,
    .btn,
    .form-control {
      font-family: 'Source Sans 3', sans-serif;
    }

    :focus-visible {
      outline: 3px solid var(--gold-deep);
      outline-offset: 2px;
    }

    body {
      background: var(--bg);
      color: var(--text);
      display: flex;
      min-height: 100vh;
    }

    /* ════════ SIDEBAR ════════ */
    .sidebar {
      width: var(--sidebar-w);
      background: var(--blue-dark);
      display: flex; flex-direction: column;
      position: fixed; top: 0; left: 0;
      height: 100vh; z-index: 100;
      transition: transform .3s;
      overflow-y: auto;
      scrollbar-width: thin;
      scrollbar-color: rgba(255,255,255,.06) transparent;
    }

    .sidebar-top-stripe {
      height: 4px;
      background: linear-gradient(90deg, var(--gold) 0%, var(--gold-deep) 100%);
      flex-shrink: 0;
    }

    .sidebar-brand {
      padding: 16px 18px 14px;
      border-bottom: 1px solid rgba(255,255,255,.07);
      flex-shrink: 0;
    }

    .brand-mark { display: flex; align-items: center; gap: 12px; }

    .brand-logo {
      width: 44px; height: 44px; border-radius: 10px;
      background: #fff;
      display: flex; align-items: center; justify-content: center;
      flex-shrink: 0; overflow: hidden;
      padding: 3px;
    }

    .brand-logo img { width: 100%; height: 100%; object-fit: contain; }
    .brand-logo .fallback { font-size: 20px; color: var(--blue-dark); display: none; }

    .brand-text .bname {
      font-family: 'Playfair Display', serif;
      font-size: 13.5px; color: #fff; font-weight: 700; line-height: 1.2;
    }
    .brand-text .bsub {
      font-size: 9.5px; color: var(--gold);
      text-transform: uppercase; letter-spacing: 1.8px; margin-top: 2px;
    }

    .sidebar-nav { flex: 1; padding: 12px 10px; }

    .nav-section-label {
      font-size: 9px; text-transform: uppercase;
      letter-spacing: 2.2px; color: rgba(255,255,255,.28);
      padding: 10px 10px 4px; margin-top: 4px;
    }

    .nav-item {
      display: flex; align-items: center; gap: 11px;
      padding: 10px 12px; border-radius: 9px;
      color: rgba(255,255,255,.65);
      text-decoration: none;
      font-size: 13.5px; font-weight: 400;
      transition: all .16s; margin-bottom: 2px;
    }
    .nav-item i { width: 18px; font-size: 14px; flex-shrink: 0; }
    .nav-item:hover { background: var(--blue-hover); color: #fff; }
    .nav-item.active {
      background: var(--gold);
      color: var(--blue-dark);
      font-weight: 700;
    }
    .nav-item.active i { color: var(--blue-dark); }

    .nav-badge {
      margin-left: auto;
      background: var(--danger); color: #fff;
      font-size: 10px; font-weight: 700;
      padding: 2px 7px; border-radius: 20px;
      min-width: 20px; text-align: center;
    }

    .sidebar-footer {
      padding: 12px; border-top: 1px solid rgba(255,255,255,.07);
      flex-shrink: 0;
    }

    .user-card {
      display: flex; align-items: center; gap: 10px;
      padding: 10px 11px; border-radius: 9px;
      background: rgba(255,255,255,.06);
    }

    .user-avatar {
      width: 36px; height: 36px; border-radius: 50%;
      background: var(--gold); display: flex;
      align-items: center; justify-content: center;
      font-weight: 700; font-size: 14px;
      color: var(--blue-dark); flex-shrink: 0; overflow: hidden;
    }
    .user-avatar img { width: 100%; height: 100%; object-fit: cover; }
    .user-info { flex: 1; min-width: 0; }
    .user-info .uname { font-size: 12.5px; color: #fff; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .user-info .urole { font-size: 10.5px; color: var(--gold); margin-top: 1px; }
    .logout-btn {
      background: none; border: none; color: rgba(255,255,255,.4);
      cursor: pointer; font-size: 15px; padding: 4px; transition: color .2s;
    }
    .logout-btn:hover { color: #ff6b6b; }

    /* ════════ MAIN ════════ */
    .main { margin-left: var(--sidebar-w); flex: 1; min-width: 0; display: flex; flex-direction: column; min-height: 100vh; }

    .topbar {
      height: var(--header-h);
      background: var(--white); border-bottom: 1px solid var(--border);
      display: flex; align-items: center; justify-content: space-between;
      padding: 0 26px;
      position: sticky; top: 0; z-index: 50;
      box-shadow: var(--shadow-sm);
    }

    .topbar-left { display: flex; align-items: center; gap: 14px; min-width: 0; }
    .topbar-left h1 { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-family: 'Source Sans 3', sans-serif; font-size: 19px; color: var(--text); }
    .topbar-right { display: flex; align-items: center; gap: 14px; }

    .notif-btn {
      position: relative; background: none; border: none;
      cursor: pointer; color: var(--text-muted); font-size: 18px;
      padding: 6px; transition: color .2s;
    }
    .notif-btn:hover { color: var(--blue); }
    .notification-menu { position:relative; }
    .notification-popover { position:absolute; right:0; top:38px; width:310px; max-width:calc(100vw - 28px); background:#fff; border:1px solid var(--border); border-radius:12px; box-shadow:var(--shadow); z-index:200; overflow:hidden; }
    .notification-popover-head { display:flex; justify-content:space-between; align-items:center; padding:13px 16px; border-bottom:1px solid var(--border); }
    .notification-popover-head a { color:var(--blue); font-size:12px; text-decoration:none; }
    #notificationList { max-height:280px; overflow-y:auto; overscroll-behavior:contain; scrollbar-width:thin; }
    #notificationList::-webkit-scrollbar { width:6px; }
    #notificationList::-webkit-scrollbar-thumb { background:#cbd5e1; border-radius:6px; }
    .notification-item { display:block; width:100%; padding:12px 16px; color:var(--text); text-decoration:none; text-align:left; border:0; border-bottom:1px solid var(--border); background:#fff; cursor:pointer; }
    .notification-item.unread { background:var(--blue-light); font-weight:700; }
    .notification-item:hover { background:#f5f8ff; }
    .notification-item small { display:block; margin-top:3px; color:var(--text-muted); font-weight:400; line-height:1.35; }
    .notification-empty { padding:22px 16px; color:var(--text-muted); font-size:13px; text-align:center; }

    .notif-badge {
      position: absolute; top: 0; right: 0;
      width: 17px; height: 17px;
      background: var(--danger); border-radius: 50%;
      font-size: 9px; font-weight: 700; color: #fff;
      display: flex; align-items: center; justify-content: center;
      border: 2px solid #fff;
    }

    .role-chip {
      padding: 4px 14px; border-radius: 20px;
      font-size: 11.5px; font-weight: 700;
    }
    .role-Admin     { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
    .role-Teacher   { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }
    .role-Registrar { background: #fffbeb; color: #b45309; border: 1px solid #fde68a; }
    .role-Student   { background: var(--blue-light); color: var(--blue); border: 1px solid #c7d2fe; }

    .sidebar-toggle {
      display: none; width: 42px; height: 42px; flex: 0 0 42px;
      align-items: center; justify-content: center;
      background: var(--blue-light); border: 1px solid #c7d2fe;
      border-radius: 10px; cursor: pointer; font-size: 18px; color: var(--blue);
    }
    .sidebar-toggle:hover { background: #e0e7ff; }

    .page-content { padding: 24px 26px; flex: 1; min-width: 0; }
    .page-content > * { min-width: 0; max-width: 100%; }

    .sidebar-backdrop {
      display: none;
      position: fixed;
      inset: 0;
      z-index: 90;
      border: 0;
      background: rgba(8, 18, 44, .52);
      opacity: 0;
      transition: opacity .2s;
    }

    /* ════════ COMPONENTS ════════ */
    .card {
      background: var(--white); border-radius: var(--radius);
      border: 1px solid var(--border); box-shadow: var(--shadow-sm);
      overflow: hidden;
    }
    .card-header {
      padding: 15px 22px; border-bottom: 1px solid var(--border);
      display: flex; align-items: center; justify-content: space-between;
    }
    .card-title {
      font-family: 'Source Sans 3', sans-serif;
      font-size: 16px; color: var(--text);
      display: flex; align-items: center; gap: 9px;
    }
    .card-title i { color: var(--blue); font-size: 14px; }
    .card-body { padding: 22px; }

    /* Stat Grid */
    .stat-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(185px, 1fr));
      gap: 14px; margin-bottom: 22px;
    }
    .stat-card {
      background: var(--white); border-radius: var(--radius);
      border: 1px solid var(--border); padding: 18px 20px;
      display: flex; align-items: center; gap: 15px;
      box-shadow: var(--shadow-sm);
      transition: transform .2s, box-shadow .2s;
    }
    .stat-card:hover { transform: translateY(-2px); box-shadow: var(--shadow); }
    .stat-icon {
      width: 48px; height: 48px; border-radius: 11px;
      display: flex; align-items: center; justify-content: center;
      font-size: 19px; flex-shrink: 0;
    }
    .stat-icon.blue   { background: var(--blue-light); color: var(--blue); }
    .stat-icon.gold   { background: var(--gold-light); color: var(--gold-deep); }
    .stat-icon.green  { background: #dcfce7; color: #16a34a; }
    .stat-icon.red    { background: #fee2e2; color: var(--danger); }
    .stat-icon.orange { background: #fff7ed; color: var(--warning); }
    .stat-icon.purple { background: #f3e8ff; color: #9333ea; }
    .stat-info .value { font-size: 28px; font-weight: 700; color: var(--text); line-height: 1; }
    .stat-info .label { font-size: 12.5px; color: var(--text-muted); margin-top: 4px; }

    /* Tables */
    .data-table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
    .data-table th {
      background: var(--bg); padding: 11px 16px;
      text-align: left; font-weight: 700;
      font-size: 11px; text-transform: uppercase; letter-spacing: .6px;
      color: var(--text-muted); border-bottom: 2px solid var(--border);
    }
    .data-table td { padding: 12px 16px; border-bottom: 1px solid var(--border); vertical-align: middle; }
    .data-table tr:last-child td { border-bottom: none; }
    .data-table tr:hover td { background: #f5f8ff; }
    .page-content table {
      max-width: 100%;
    }

    /* Status Badges */
    .status-badge {
      display: inline-flex; align-items: center; gap: 5px;
      padding: 3px 10px; border-radius: 20px;
      font-size: 11.5px; font-weight: 600;
    }
    .status-approved  { background: #dcfce7; color: #15803d; }
    .status-pending   { background: #fff7ed; color: #c2410c; }
    .status-submitted { background: #dbeafe; color: #1d4ed8; }
    .status-flagged   { background: #fee2e2; color: #b91c1c; }
    .status-returned  { background: #f3e8ff; color: #7e22ce; }

    /* Honor Badges */
    .badge-summa { background: linear-gradient(135deg,#f5c518,#e6a800); color: #3d2800; padding: 4px 13px; border-radius: 20px; font-size: 12px; font-weight: 700; display:inline-block; }
    .badge-magna { background: linear-gradient(135deg,#c0c0c0,#9e9e9e); color: #111; padding: 4px 13px; border-radius: 20px; font-size: 12px; font-weight: 700; display:inline-block; }
    .badge-cum   { background: linear-gradient(135deg,#cd7f32,#a0601e); color: #fff; padding: 4px 13px; border-radius: 20px; font-size: 12px; font-weight: 700; display:inline-block; }
    .badge-deans { background: linear-gradient(135deg,var(--blue),var(--blue-mid)); color: #fff; padding: 4px 13px; border-radius: 20px; font-size: 12px; font-weight: 700; display:inline-block; }
    .badge-none  { background: var(--border); color: var(--text-muted); padding: 4px 13px; border-radius: 20px; font-size: 12px; display:inline-block; }
    .badge-rank  { background: linear-gradient(135deg,#22c55e,#166534); color: #fff; padding: 4px 13px; border-radius: 20px; font-size: 12px; font-weight: 700; display:inline-block; }

    /* Buttons */
    .btn {
      display: inline-flex; align-items: center; gap: 7px;
      padding: 9px 18px; border-radius: 9px;
      font-family: 'Source Sans 3', sans-serif;
      font-size: 13px; font-weight: 600;
      cursor: pointer; border: none;
      transition: all .18s; text-decoration: none; white-space: nowrap;
    }
    .btn-primary { background: var(--blue); color: #fff; }
    .btn-primary:hover { background: var(--blue-dark); box-shadow: 0 4px 14px rgba(26,58,140,.3); }
    .btn-gold    { background: var(--gold); color: var(--blue-dark); font-weight: 700; }
    .btn-gold:hover { background: var(--gold-deep); }
    .btn-outline { background: transparent; border: 1.5px solid var(--border); color: var(--text-dark); }
    .btn-outline:hover { border-color: var(--blue); color: var(--blue); background: var(--blue-light); }
    .btn-danger  { background: var(--danger); color: #fff; }
    .btn-danger:hover { background: #b91c1c; }
    .btn-success { background: var(--success); color: #fff; }
    .btn-sm { padding: 6px 13px; font-size: 12px; border-radius: 7px; }

    /* Forms */
    .form-control {
      width: 100%; padding: 10px 14px;
      border: 1.5px solid var(--border); border-radius: 9px;
      font-family: 'Source Sans 3', sans-serif;
      font-size: 13.5px; color: var(--text-dark);
      background: var(--white); outline: none;
      transition: border-color .2s, box-shadow .2s;
    }
    .form-control:focus { border-color: var(--blue); box-shadow: 0 0 0 3px rgba(26,58,140,.09); }
    .form-label { font-size: 12.5px; font-weight: 600; color: var(--text-dark); margin-bottom: 6px; display: block; }

    /* Alerts */
    .alert { padding: 12px 16px; border-radius: 10px; font-size: 13.5px; margin-bottom: 16px; display: flex; align-items: flex-start; gap: 10px; }
    .alert i { margin-top: 2px; flex-shrink: 0; }
    .alert-success { background: #dcfce7; color: #15803d; border-left: 3px solid var(--success); }
    .alert-warning { background: #fff7ed; color: #c2410c; border-left: 3px solid var(--warning); }
    .alert-danger  { background: #fee2e2; color: var(--danger); border-left: 3px solid var(--danger); }
    .alert-info    { background: var(--blue-light); color: var(--blue); border-left: 3px solid var(--blue); }

    .page-toolbar {
      display: flex; align-items: center; justify-content: space-between;
      gap: 10px; flex-wrap: wrap; margin-bottom: 16px;
    }
    .toolbar-actions {
      display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
    }
    .page-title-group {
      display: flex; flex-direction: column; gap: 3px;
    }
    .page-kicker {
      font-size: 11px; font-weight: 700; letter-spacing: 1.4px;
      text-transform: uppercase; color: var(--blue);
    }
    .page-title-group h2 {
      font-family: 'Playfair Display', serif; font-size: 20px; color: var(--text);
      margin: 0; font-weight: 700;
    }
    .filter-toolbar {
      display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 10px; align-items: end;
    }
    .empty-state {
      padding: 42px 24px; text-align: center; color: var(--text-muted);
      border: 1px dashed var(--border); border-radius: var(--radius);
      background: var(--light);
    }
    .hero-banner {
      background: linear-gradient(135deg, var(--blue-dark), var(--blue));
      border-radius: 16px; padding: 24px 28px; margin-bottom: 18px;
      color: #fff; display: flex; align-items: center; justify-content: space-between;
      gap: 20px; box-shadow: var(--shadow-sm);
    }
    .hero-banner .hero-title {
      font-family: 'Playfair Display', serif; font-size: 22px; font-weight: 700;
      margin-bottom: 4px;
    }
    .hero-banner .hero-subtitle { font-size: 13px; opacity: 0.85; }
    .pill {
      display: inline-flex; align-items: center; gap: 6px; padding: 5px 10px;
      border-radius: 999px; font-size: 12px; font-weight: 600; background: rgba(255,255,255,.12);
      color: #fff; border: 1px solid rgba(255,255,255,.22);
    }

    /* Modal overlay */
    .modal-overlay {
      display: none; position: fixed; inset: 0;
      background: rgba(0,0,0,.48); z-index: 999;
      align-items: center; justify-content: center; padding: 24px;
    }
    .modal-box {
      background: #fff; border-radius: 16px; padding: 32px;
      max-height: 90vh; overflow-y: auto;
      box-shadow: 0 20px 60px rgba(0,0,0,.2);
    }
    .modal-header {
      display: flex; justify-content: space-between; align-items: center;
      margin-bottom: 24px;
    }
    .modal-title { font-family: 'Playfair Display', serif; font-size: 20px; color: var(--text-dark); }
    .modal-close {
      background: none; border: none; font-size: 22px;
      cursor: pointer; color: var(--text-muted); line-height: 1;
    }
    .modal-close:hover { color: var(--danger); }

    @media (max-width: 768px) {
      html, body { max-width: 100%; overflow-x: hidden; }
      .sidebar {
        width: min(84vw, 290px);
        transform: translateX(-110%);
        box-shadow: 18px 0 40px rgba(15,34,96,.22);
      }
      .sidebar.open { transform: translateX(0); }
      .sidebar-backdrop.open { display: block; opacity: 1; }
      body.nav-open { overflow: hidden; }
      .main { width: 100%; min-width: 0; margin-left: 0; }
      .sidebar-toggle { display: inline-flex; }
      .page-content { width: 100%; padding: 14px 16px; }
      .page-content [style*="grid-template-columns"] {
        grid-template-columns: repeat(auto-fit, minmax(min(100%, 240px), 1fr)) !important;
      }
      .page-content .stat-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
      }
      .topbar {
        padding: 10px 14px;
        min-height: 60px;
        height: auto;
        align-items: center;
      }
      .topbar-left { flex: 1; }
      .topbar-left h1 { font-size: 17px; }
      .topbar-right { gap: 8px; flex: 0 0 auto; }
      .role-chip { display: none; }
      .notification-popover {
        width: min(300px, calc(100vw - 26px));
        right: -8px;
      }
      .page-toolbar {
        flex-direction: column;
        align-items: flex-start;
      }
      .toolbar-actions { width: 100%; }
      .card-header {
        padding: 12px 14px;
        align-items: flex-start;
        flex-direction: column;
        gap: 8px;
      }
      .card-body { padding: 14px; }
      .filter-toolbar {
        grid-template-columns: 1fr;
      }
      .data-table {
        display: block;
        width: 100%;
        max-width: 100%;
        overflow-x: auto;
        white-space: nowrap;
        -webkit-overflow-scrolling: touch;
        overscroll-behavior-x: contain;
      }
      .page-content table:not(.data-table) {
        display: block;
        width: 100%;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        overscroll-behavior-x: contain;
      }
      .page-content input,
      .page-content select,
      .page-content textarea { max-width: 100%; }
      .modal-overlay { padding: 12px; }
      .modal-box {
        width: 100%;
        max-width: 100%;
        max-height: calc(100dvh - 24px);
        padding: 20px;
      }
      .page-content > div[style*="display:flex"] {
        flex-wrap: wrap !important;
      }
    }

    @media (max-width: 520px) {
      .page-content .stat-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
      }
      .brand-text .bname {
        font-size: 12px;
      }
      .nav-item {
        padding: 11px 10px;
        font-size: 12.8px;
      }
      .user-card {
        padding: 9px 8px;
      }
      .page-content {
        padding: 12px 12px 18px;
      }
      .topbar-left {
        gap: 8px;
      }
      .topbar-left h1 {
        font-size: 15px;
      }
      .notif-btn {
        font-size: 17px;
      }
      .hero-banner {
        align-items: flex-start;
        flex-direction: column;
        padding: 18px;
        gap: 14px;
      }
      .hero-banner > * { max-width: 100%; }
      .card { border-radius: 10px; }
      .card-header { min-width: 0; }
      .card-title { min-width: 0; line-height: 1.35; }
      .stat-card { gap: 10px; padding: 14px 12px; }
      .stat-icon { width: 40px; height: 40px; font-size: 16px; }
      .stat-info .value { font-size: 24px; }
      .stat-info .label { font-size: 11px; }
      .btn { max-width: 100%; }
    }

    @media (max-width: 360px) {
      .page-content .stat-grid {
        grid-template-columns: minmax(0, 1fr) !important;
      }
      .topbar { padding-right: 10px; padding-left: 10px; }
      .topbar-left h1 { font-size: 14px; }
      .topbar-right { gap: 4px; }
    }

    /* Disabled elements styling */
    button:disabled, input:disabled, select:disabled, textarea:disabled,
    [disabled], .disabled {
      opacity: 0.55;
      color: #999;
      cursor: not-allowed !important;
      background-color: #f5f5f5 !important;
      pointer-events: none;
    }
    a.disabled, a:disabled {
      opacity: 0.55;
      color: #999;
      cursor: not-allowed !important;
      pointer-events: none;
      text-decoration: none;
    }
  </style>
  <script>
    (() => {
      const csrfToken = <?= json_encode(generateCSRF()) ?>;
      const originalFetch = window.fetch.bind(window);
      window.fetch = (resource, options = {}) => {
        const requestUrl = new URL(resource instanceof Request ? resource.url : resource, window.location.href);
        const method = (options.method || (resource instanceof Request ? resource.method : 'GET')).toUpperCase();
        if (requestUrl.origin !== window.location.origin || ['GET', 'HEAD', 'OPTIONS'].includes(method)) {
          return originalFetch(resource, options);
        }
        const headers = new Headers(resource instanceof Request ? resource.headers : undefined);
        new Headers(options.headers || {}).forEach((value, key) => headers.set(key, value));
        headers.set('X-CSRF-Token', csrfToken);
        return originalFetch(resource, {...options, headers});
      };
    })();
  </script>
</head>
<body>

<aside class="sidebar" id="sidebar">
  <div class="sidebar-top-stripe"></div>

  <div class="sidebar-brand">
    <div class="brand-mark">
      <div class="brand-logo">
        <img src="<?= BASE_URL ?>assets/img/neust-logo.png" alt="NEUST"
             onerror="this.style.display='none';this.nextElementSibling.style.display='flex'"/>
        <span class="fallback" style=""><i class="fas fa-university" style="font-size:20px;color:var(--blue-dark);"></i></span>
      </div>
      <div class="brand-text">
        <div class="bname">NEUST Talavera</div>
        <div class="bsub">ISARMS · <?= date('Y') ?></div>
      </div>
    </div>
  </div>

  <nav class="sidebar-nav">
    <div class="nav-section-label">Navigation</div>
    <?php foreach ($navLinks as $link): ?>
    <a class="nav-item <?= ($activeMenu ?? '') === $link['href'] ? 'active' : '' ?>"
       href="<?= $moduleBase . $link['href'] ?>"
       title="<?= htmlspecialchars($link['label']) ?>"
       aria-label="<?= htmlspecialchars($link['label']) ?>">
      <i class="fas <?= $link['icon'] ?>"></i>
      <?= htmlspecialchars($link['label']) ?>
    </a>
    <?php endforeach; ?>
  </nav>

  <div class="sidebar-footer">
    <div class="user-card">
      <div class="user-avatar">
        <?php if (!empty($user['pic'])): ?>
          <img src="<?= BASE_URL ?>uploads/<?= htmlspecialchars($user['pic']) ?>" alt=""/>
        <?php else: ?>
          <?= $initials ?>
        <?php endif; ?>
      </div>
      <div class="user-info">
        <div class="uname"><?= htmlspecialchars($user['name'] ?? '') ?></div>
        <div class="urole"><?= htmlspecialchars($role) ?></div>
      </div>
      <a class="logout-btn" href="<?= BASE_URL ?>logout.php" title="Sign Out">
        <i class="fas fa-sign-out-alt"></i>
      </a>
    </div>
  </div>
</aside>

<button type="button" class="sidebar-backdrop" id="sidebarBackdrop"
        aria-label="Close navigation menu" tabindex="-1"></button>

<script>
function setMobileNavigation(open) {
  const sidebar = document.getElementById('sidebar');
  const toggle = document.getElementById('sidebarToggle');
  const backdrop = document.getElementById('sidebarBackdrop');
  sidebar.classList.toggle('open', open);
  backdrop.classList.toggle('open', open);
  document.body.classList.toggle('nav-open', open);
  toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
  toggle.setAttribute('aria-label', open ? 'Close navigation menu' : 'Open navigation menu');
}

document.addEventListener('DOMContentLoaded', function () {
  document.getElementById('sidebarToggle').addEventListener('click', function () {
    setMobileNavigation(!document.getElementById('sidebar').classList.contains('open'));
  });
  document.getElementById('sidebarBackdrop').addEventListener('click', function () {
    setMobileNavigation(false);
  });
  document.querySelectorAll('#sidebar .nav-item').forEach(function (link) {
    link.addEventListener('click', function () { setMobileNavigation(false); });
  });
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') setMobileNavigation(false);
  });
  window.addEventListener('resize', function () {
    if (window.innerWidth > 768) setMobileNavigation(false);
  });
});
</script>

<main class="main">
  <div class="topbar">
    <div class="topbar-left">
      <button class="sidebar-toggle" id="sidebarToggle" aria-label="Open navigation menu" aria-controls="sidebar" aria-expanded="false">
        <i class="fas fa-bars"></i>
      </button>
      <h1><?= htmlspecialchars($pageTitle ?? 'Dashboard') ?></h1>
    </div>
    <div class="topbar-right">
      <div class="notification-menu">
      <button type="button" class="notif-btn" id="notificationButton" title="Notifications" aria-expanded="false">
        <i class="fas fa-bell"></i>
        <?php if ($notifCount > 0): ?>
          <span class="notif-badge"><?= $notifCount > 9 ? '9+' : $notifCount ?></span>
        <?php endif; ?>
      </button>
      <div class="notification-popover" id="notificationPopover" hidden>
        <div class="notification-popover-head"><strong>Notifications</strong><span style="font-size:11px;color:var(--text-muted);">Click an item to mark read</span></div>
        <div id="notificationList"><div class="notification-empty">Loading...</div></div>
      </div>
      </div>
      <span class="role-chip role-<?= $role ?>"><?= $role ?></span>
    </div>
  </div>

  <div class="page-content">
