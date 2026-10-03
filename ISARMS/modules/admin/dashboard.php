<?php
define('ROOT', dirname(__DIR__, 2));
require_once ROOT . '/includes/config.php';
require_once ROOT . '/includes/functions.php';
requireAuth('Admin');

$db   = Database::getInstance();
$user = getCurrentUser();

$stats = [
    'students'  => $db->fetchOne("SELECT COUNT(*) AS c FROM students s WHERE s.isActive = 1 AND s.dateDeleted IS NULL AND s.studentNo IS NOT NULL AND s.studentNo <> ''")['c'] ?? 0,
    'teachers'  => $db->fetchOne("SELECT COUNT(*) AS c FROM users u WHERE u.roleName='Teacher' AND u.isActive = 1 AND u.dateDeleted IS NULL AND u.fName IS NOT NULL AND u.fName <> '' AND u.lName IS NOT NULL AND u.lName <> ''")['c'] ?? 0,
    'programs'  => $db->fetchOne("SELECT COUNT(*) AS c FROM programs p WHERE p.dateDeleted IS NULL AND p.programCode IS NOT NULL AND p.programCode <> ''")['c'] ?? 0,
    'subjects'  => $db->fetchOne("SELECT COUNT(*) AS c FROM subjects s WHERE s.dateDeleted IS NULL AND s.subjectCode IS NOT NULL AND s.subjectCode <> ''")['c'] ?? 0,
    'sections'  => $db->fetchOne("SELECT COUNT(*) AS c FROM sections sec WHERE sec.dateDeleted IS NULL AND sec.sectionName IS NOT NULL AND sec.sectionName <> ''")['c'] ?? 0,
    'pending'   => $db->fetchOne("SELECT COUNT(*) AS c FROM grades g JOIN enrollments e ON e.enrollmentID = g.enrollmentID JOIN students s ON s.studentID = g.studentID WHERE g.status='Pending' AND g.submittedAt IS NOT NULL AND g.dateDeleted IS NULL AND e.status='Active' AND e.dateDeleted IS NULL AND s.isActive = 1 AND s.dateDeleted IS NULL")['c'] ?? 0,
    'approved'  => $db->fetchOne("SELECT COUNT(*) AS c FROM grades g JOIN enrollments e ON e.enrollmentID = g.enrollmentID JOIN students s ON s.studentID = g.studentID WHERE (g.status='Posted' OR g.status='Approved') AND g.dateDeleted IS NULL AND e.status='Active' AND e.dateDeleted IS NULL AND s.isActive = 1 AND s.dateDeleted IS NULL")['c'] ?? 0,
    'honors'    => $db->fetchOne("SELECT COUNT(*) AS c FROM gwa_records gr JOIN students s ON s.studentID = gr.studentID WHERE gr.latinHonor <> 'None' AND gr.validationStatus='Validated' AND s.isActive = 1 AND s.dateDeleted IS NULL")['c'] ?? 0,
];

// Recent audit logs
$auditLog = $db->fetchAll("
    SELECT gl.action, gl.actionRole, gl.actionAt, gl.notes,
           COALESCE(CONCAT(u.lName,', ',u.fName), CONCAT(s.lName,', ',s.fName)) AS actorName,
           sub.subjectCode, st.studentNo
    FROM grade_audit_log gl
    LEFT JOIN users    u   ON u.userID    = gl.actionBy AND gl.actionRole IN ('Teacher','Registrar','Admin')
    LEFT JOIN students s   ON s.studentID = gl.actionBy AND gl.actionRole = 'Student'
    LEFT JOIN grades   g2  ON g2.gradeID  = gl.gradeID
    LEFT JOIN subjects sub ON sub.subjectID = g2.subjectID
    LEFT JOIN students st  ON st.studentID  = g2.studentID
    ORDER BY gl.actionAt DESC LIMIT 12
");

// Program distribution
$programDist = $db->fetchAll("
    SELECT p.programCode, COUNT(s.studentID) as cnt
    FROM programs p LEFT JOIN students s ON s.programID=p.programID AND s.isActive=1 AND s.dateDeleted IS NULL
    WHERE p.dateDeleted IS NULL GROUP BY p.programID ORDER BY cnt DESC
");

$pageTitle  = 'Admin Dashboard';
$activeMenu = 'dashboard.php';
require_once ROOT . '/includes/header.php';
?>

<!-- Welcome -->
<div style="background:linear-gradient(135deg,var(--blue-dark) 0%,var(--blue) 100%);border-radius:16px;padding:28px 32px;margin-bottom:24px;color:#fff;display:flex;align-items:center;justify-content:space-between;">
  <div>
    <h2 style="font-family:'Playfair Display',serif;font-size:22px;margin-bottom:4px;">
      System Overview — <?= APP_CAMPUS ?>
    </h2>
    <p style="opacity:.8;font-size:14px;"><?= date('l, F j, Y') ?> &bull; Logged in as System Administrator</p>
  </div>
  <div style="text-align:right;">
    <div style="font-size:11px;opacity:.7;text-transform:uppercase;letter-spacing:1px;">ISARMS</div>
    <div style="font-size:13px;color:var(--gold);">v<?= APP_VERSION ?></div>
  </div>
</div>

<!-- Stat Grid -->
<div class="stat-grid" style="grid-template-columns:repeat(4,1fr);">
  <div class="stat-card">
    <div class="stat-icon green"><i class="fas fa-user-graduate"></i></div>
    <div class="stat-info"><div class="value"><?= number_format($stats['students']) ?></div><div class="label">Active Students</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon blue"><i class="fas fa-chalkboard-teacher"></i></div>
    <div class="stat-info"><div class="value"><?= number_format($stats['teachers']) ?></div><div class="label">Faculty Members</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon gold"><i class="fas fa-layer-group"></i></div>
    <div class="stat-info"><div class="value"><?= number_format($stats['programs']) ?></div><div class="label">Programs Offered</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon green"><i class="fas fa-book"></i></div>
    <div class="stat-info"><div class="value"><?= number_format($stats['subjects']) ?></div><div class="label">Active Subjects</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon blue"><i class="fas fa-chalkboard"></i></div>
    <div class="stat-info"><div class="value"><?= number_format($stats['sections']) ?></div><div class="label">Class Sections</div></div>
  </div>
  <a href="grades.php?status=review" class="stat-card" style="color:inherit;text-decoration:none;">
    <div class="stat-icon orange"><i class="fas fa-hourglass-half"></i></div>
    <div class="stat-info"><div class="value"><?= number_format($stats['pending']) ?></div><div class="label">Pending Approvals</div></div>
  </a>
  <div class="stat-card">
    <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
    <div class="stat-info"><div class="value"><?= number_format($stats['approved']) ?></div><div class="label">Posted Grades</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon gold"><i class="fas fa-award"></i></div>
    <div class="stat-info"><div class="value"><?= number_format($stats['honors']) ?></div><div class="label">Validated Honors</div></div>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 340px;gap:20px;align-items:start;">

  <!-- Audit Log -->
  <div class="card">
    <div class="card-header">
      <div class="card-title"><i class="fas fa-history"></i> Recent System Activity</div>
    </div>
    <div style="padding:0;">
      <?php foreach ($auditLog as $log): ?>
      <div style="padding:12px 20px;border-bottom:1px solid var(--border);display:flex;align-items:flex-start;gap:14px;">
        <div style="width:36px;height:36px;border-radius:50%;background:var(--green-light);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
          <?php $icon = match($log['action']){ 'Approved'=>'✅','Flagged'=>'🚩','Submitted'=>'📤','Encoded'=>'✏️','Returned'=>'↩️',default=>'📝' }; ?>
          <span style="font-size:16px;"><?= $icon ?></span>
        </div>
        <div style="flex:1;">
          <div style="font-size:13px;font-weight:600;">
            <?= htmlspecialchars($log['actorName'] ?? 'System') ?>
            <span style="font-weight:400;color:var(--text-muted);"><?= strtolower($log['action']) ?></span>
            <?php if ($log['subjectCode']): ?>
            <span style="color:var(--green);font-weight:600;"><?= htmlspecialchars($log['subjectCode']) ?></span>
            <?php endif; ?>
            <?php if ($log['studentNo']): ?>
            <span style="color:var(--text-muted);">for</span>
            <span style="font-size:12px;"><?= htmlspecialchars($log['studentNo']) ?></span>
            <?php endif; ?>
          </div>
          <?php if ($log['notes']): ?>
          <div style="font-size:12px;color:var(--text-muted);margin-top:2px;"><?= htmlspecialchars(substr($log['notes'],0,80)) ?><?= strlen($log['notes'])>80?'…':'' ?></div>
          <?php endif; ?>
        </div>
        <div style="font-size:11px;color:var(--text-muted);white-space:nowrap;flex-shrink:0;">
          <?= date('M d, g:i A', strtotime($log['actionAt'])) ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Program Distribution -->
  <div style="display:flex;flex-direction:column;gap:20px;">
    <div class="card">
      <div class="card-header"><div class="card-title"><i class="fas fa-chart-pie"></i> Students by Program</div></div>
      <div class="card-body" style="padding:16px 20px;">
        <?php $maxCnt = max(array_column($programDist,'cnt') ?: [1]); ?>
        <?php foreach ($programDist as $pd): ?>
        <div style="margin-bottom:14px;">
          <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:4px;">
            <span style="font-weight:600;"><?= htmlspecialchars($pd['programCode']) ?></span>
            <span style="color:var(--text-muted);"><?= $pd['cnt'] ?></span>
          </div>
          <div style="height:8px;background:var(--border);border-radius:4px;overflow:hidden;">
            <div style="height:100%;width:<?= $maxCnt>0?round($pd['cnt']/$maxCnt*100):0 ?>%;background:var(--green);border-radius:4px;transition:width .6s;"></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Quick Links -->
    <div class="card">
      <div class="card-header"><div class="card-title"><i class="fas fa-bolt"></i> Quick Setup</div></div>
      <div class="card-body" style="display:flex;flex-direction:column;gap:10px;">
        <a href="users.php" class="btn btn-outline" style="justify-content:flex-start;"><i class="fas fa-user-plus"></i> Add Faculty / Staff</a>
        <a href="students.php" class="btn btn-outline" style="justify-content:flex-start;"><i class="fas fa-user-graduate"></i> Manage Students</a>
        <a href="subjects.php" class="btn btn-outline" style="justify-content:flex-start;"><i class="fas fa-book"></i> Manage Subjects</a>
        <a href="acad_years.php" class="btn btn-outline" style="justify-content:flex-start;"><i class="fas fa-calendar-alt"></i> Set Academic Year</a>
        <a href="sections.php" class="btn btn-outline" style="justify-content:flex-start;"><i class="fas fa-chalkboard"></i> Create Sections</a>
      </div>
    </div>
  </div>
</div>

<?php require_once ROOT . '/includes/footer.php'; ?>
