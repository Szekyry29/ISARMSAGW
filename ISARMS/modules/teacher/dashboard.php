<?php
define('ROOT', dirname(__DIR__, 2));
require_once ROOT . '/includes/config.php';
require_once ROOT . '/includes/functions.php';
requireAuth('Teacher');

$db   = Database::getInstance();
$user = getCurrentUser();
$tid  = $user['id'];

// ── Stats using section_subjects ──────────────────────────────
// Teacher's "sections" = distinct sections where they teach at least one subject
$stats = [
    'my_assignments' => $db->fetchOne(
        "SELECT COUNT(*) as c FROM section_subjects ss JOIN acad_years ay ON ay.acadYearID = ss.acadYearID WHERE ss.teacherID=:t AND ss.dateDeleted IS NULL AND ss.sectionID IS NOT NULL AND ss.subjectID IS NOT NULL AND ss.semester IS NOT NULL AND ss.acadYearID IS NOT NULL AND ay.dateDeleted IS NULL AND ay.status='Active'", [':t'=>$tid])['c'] ?? 0,
    'my_sections'    => $db->fetchOne(
        "SELECT COUNT(DISTINCT ss.sectionID) as c FROM section_subjects ss JOIN acad_years ay ON ay.acadYearID = ss.acadYearID WHERE ss.teacherID=:t AND ss.dateDeleted IS NULL AND ss.sectionID IS NOT NULL AND ss.subjectID IS NOT NULL AND ss.semester IS NOT NULL AND ss.acadYearID IS NOT NULL AND ay.dateDeleted IS NULL AND ay.status='Active'", [':t'=>$tid])['c'] ?? 0,
    'my_students'    => $db->fetchOne(
        "SELECT COUNT(DISTINCT e.studentID) as c
         FROM enrollments e
         JOIN section_subjects ss ON ss.sectionID=e.sectionID AND ss.subjectID=e.subjectID AND ss.semester=e.semester AND ss.acadYearID=e.acadYearID
                                  AND ss.teacherID=:t AND ss.dateDeleted IS NULL
                                  AND ss.sectionID IS NOT NULL AND ss.subjectID IS NOT NULL AND ss.semester IS NOT NULL AND ss.acadYearID IS NOT NULL
         JOIN students s ON s.studentID=e.studentID AND s.dateDeleted IS NULL AND s.isActive=1 AND s.studentNo IS NOT NULL AND s.studentNo <> ''
         WHERE e.status='Active' AND e.dateDeleted IS NULL", [':t'=>$tid])['c'] ?? 0,
    'pending'    => $db->fetchOne("SELECT COUNT(*) as c FROM grades g JOIN enrollments e ON e.enrollmentID=g.enrollmentID JOIN students s ON s.studentID=e.studentID JOIN section_subjects ss ON ss.sectionID=e.sectionID AND ss.subjectID=e.subjectID AND ss.semester=e.semester AND ss.acadYearID=e.acadYearID AND ss.teacherID=:t AND ss.dateDeleted IS NULL WHERE g.teacherID=:t2 AND g.status='Pending' AND (g.submittedAt IS NULL OR g.computedGrade IS NULL OR g.gradeEquivalent IS NULL) AND g.dateDeleted IS NULL AND e.status='Active' AND e.dateDeleted IS NULL AND s.isActive=1 AND s.dateDeleted IS NULL",[':t'=>$tid,':t2'=>$tid])['c'] ?? 0,
    'submitted'  => $db->fetchOne("SELECT COUNT(*) as c FROM grades g JOIN enrollments e ON e.enrollmentID=g.enrollmentID JOIN students s ON s.studentID=e.studentID JOIN section_subjects ss ON ss.sectionID=e.sectionID AND ss.subjectID=e.subjectID AND ss.semester=e.semester AND ss.acadYearID=e.acadYearID AND ss.teacherID=:t AND ss.dateDeleted IS NULL WHERE g.teacherID=:t2 AND g.status='Returned' AND g.dateDeleted IS NULL AND e.status='Active' AND e.dateDeleted IS NULL AND s.isActive=1 AND s.dateDeleted IS NULL",[':t'=>$tid,':t2'=>$tid])['c'] ?? 0,
    'approved'   => $db->fetchOne("SELECT COUNT(*) as c FROM grades g JOIN enrollments e ON e.enrollmentID=g.enrollmentID JOIN students s ON s.studentID=e.studentID JOIN section_subjects ss ON ss.sectionID=e.sectionID AND ss.subjectID=e.subjectID AND ss.semester=e.semester AND ss.acadYearID=e.acadYearID AND ss.teacherID=:t AND ss.dateDeleted IS NULL WHERE g.teacherID=:t2 AND (g.status IN ('Approved','Posted') OR (g.status='Pending' AND g.submittedAt IS NOT NULL AND g.computedGrade IS NOT NULL AND g.gradeEquivalent IS NOT NULL)) AND g.dateDeleted IS NULL AND e.status='Active' AND e.dateDeleted IS NULL AND s.isActive=1 AND s.dateDeleted IS NULL",[':t'=>$tid,':t2'=>$tid])['c'] ?? 0,
];

// ── My subject assignments (grouped by section) ───────────────
$myAssignments = $db->fetchAll("
    SELECT ss.secSubID, ss.sectionID, ss.subjectID, ss.semester, ss.schedule, ss.room,
           sec.sectionName, p.programCode, ay.academicYear, ay.acadYearID,
           sub.subjectCode, sub.subjectName, sub.units,
           COUNT(DISTINCT e.studentID) AS studentCount,
           SUM(CASE WHEN g.status='Pending' AND (g.submittedAt IS NULL OR g.computedGrade IS NULL OR g.gradeEquivalent IS NULL) THEN 1 ELSE 0 END) AS pendingGrades,
           SUM(CASE WHEN g.status='Returned' THEN 1 ELSE 0 END) AS submittedGrades,
           SUM(CASE WHEN g.status IN ('Approved','Posted') OR (g.status='Pending' AND g.submittedAt IS NOT NULL AND g.computedGrade IS NOT NULL AND g.gradeEquivalent IS NOT NULL) THEN 1 ELSE 0 END) AS approvedGrades
    FROM section_subjects ss
    JOIN sections   sec ON sec.sectionID  = ss.sectionID
    JOIN subjects   sub ON sub.subjectID  = ss.subjectID
    JOIN programs   p   ON p.programID    = sec.programID
    JOIN acad_years ay  ON ay.acadYearID  = ss.acadYearID
    LEFT JOIN enrollments e ON e.sectionID=ss.sectionID AND e.subjectID=ss.subjectID
                            AND e.semester=ss.semester AND e.acadYearID=ss.acadYearID
                            AND e.status='Active' AND e.dateDeleted IS NULL
    LEFT JOIN grades g ON g.enrollmentID=e.enrollmentID AND g.teacherID=ss.teacherID AND g.dateDeleted IS NULL
    WHERE ss.teacherID=:t AND ss.sectionID IS NOT NULL AND ss.subjectID IS NOT NULL
      AND ss.semester IS NOT NULL AND ss.dateDeleted IS NULL
      AND sec.dateDeleted IS NULL AND sub.dateDeleted IS NULL
      AND ay.dateDeleted IS NULL AND ay.status='Active'
    GROUP BY ss.secSubID
    ORDER BY sec.sectionName, ss.semester, sub.subjectCode
", [':t'=>$tid]);

// Group by section
$bySec = [];
foreach ($myAssignments as $a) {
    $bySec[$a['sectionID']]['info'] = ['name'=>$a['sectionName'],'prog'=>$a['programCode'],'ay'=>$a['academicYear'],'secID'=>$a['sectionID']];
    $bySec[$a['sectionID']]['subjects'][] = $a;
}

// ── Recent activity ─────────────────────────────────────────
$recentActivity = $db->fetchAll("
    SELECT gl.action, gl.actionAt, sub.subjectCode,
           CONCAT(s.lName,', ',s.fName) AS studentName
    FROM grade_audit_log gl
    JOIN grades   g   ON g.gradeID    = gl.gradeID
    JOIN enrollments e ON e.enrollmentID = g.enrollmentID
    JOIN acad_years ay ON ay.acadYearID = e.acadYearID AND ay.status = 'Active' AND ay.dateDeleted IS NULL
    JOIN subjects sub ON sub.subjectID= g.subjectID
    JOIN students s   ON s.studentID  = g.studentID
    WHERE gl.actionBy=:t AND gl.actionRole='Teacher'
    ORDER BY gl.actionAt DESC LIMIT 8
", [':t'=>$tid]);

$pageTitle  = 'Dashboard';
$activeMenu = 'dashboard.php';
require_once ROOT . '/includes/header.php';
?>

<!-- Welcome Banner -->
<div style="background:linear-gradient(135deg,var(--blue-dark),var(--blue));border-radius:16px;padding:26px 30px;margin-bottom:22px;color:#fff;display:flex;align-items:center;justify-content:space-between;position:relative;overflow:hidden;">
  <div style="position:absolute;right:-40px;top:-40px;width:200px;height:200px;border-radius:50%;background:rgba(245,197,24,.07);pointer-events:none;"></div>
  <div style="position:relative;">
    <div style="font-size:11px;color:rgba(255,255,255,.55);text-transform:uppercase;letter-spacing:1.5px;margin-bottom:5px;">Faculty Portal</div>
    <h2 style="font-family:'Playfair Display',serif;font-size:22px;margin-bottom:4px;">
      Good <?= date('H')<12?'Morning':(date('H')<17?'Afternoon':'Evening') ?>, <?= htmlspecialchars(explode(' ',$user['name'])[0]) ?> 👨‍🏫
    </h2>
    <p style="opacity:.75;font-size:13px;"><?= date('l, F j, Y') ?> &bull; You teach <?= $stats['my_assignments'] ?> subject(s) across <?= $stats['my_sections'] ?> section(s)</p>
  </div>
  <div style="position:relative;background:rgba(255,255,255,.1);border-radius:12px;padding:14px 22px;text-align:center;">
    <div style="font-size:32px;font-weight:700;line-height:1;color:var(--gold);"><?= $stats['approved'] ?></div>
    <div style="font-size:12px;margin-top:3px;">Posted Grades</div>
  </div>
</div>

<!-- Stats -->
<div class="stat-grid" style="grid-template-columns:repeat(6,1fr);">
  <?php foreach ([
    [$stats['my_assignments'],'Subject Assignments','fa-book',          'blue'],
    [$stats['my_students'],   'Total Students',     'fa-users',         'green'],
    [$stats['pending'],       'Saved Drafts',       'fa-pen',           'orange'],
      [$stats['submitted'],     'Returned Grades',    'fa-rotate-left',   'blue'],
      [$stats['approved'],      'Posted Grades',      'fa-check-circle',  'green'],
  ] as [$v,$l,$i,$c]): ?>
  <div class="stat-card">
    <div class="stat-icon <?= $c ?>"><i class="fas <?= $i ?>"></i></div>
    <div class="stat-info"><div class="value"><?= $v ?></div><div class="label"><?= $l ?></div></div>
  </div>
  <?php endforeach; ?>
</div>

<div style="display:grid;grid-template-columns:1fr 320px;gap:20px;align-items:start;">

  <!-- My Subject Assignments -->
  <div>
    <?php if (empty($bySec)): ?>
    <div class="card">
      <div style="padding:48px;text-align:center;color:var(--text-muted);">
        <i class="fas fa-chalkboard-teacher" style="font-size:44px;display:block;margin-bottom:14px;opacity:.2;color:var(--blue);"></i>
        <div style="font-weight:600;margin-bottom:6px;">No subject assignments yet</div>
        <div style="font-size:13px;">The administrator needs to assign you to subjects in <strong>Sections → Add Subject</strong>.</div>
      </div>
    </div>
    <?php else: ?>
    <?php foreach ($bySec as $secID => $secData): ?>
    <div class="card" style="margin-bottom:16px;">
      <div class="card-header" style="background:var(--blue-light);">
        <div class="card-title">
          <i class="fas fa-chalkboard"></i>
          <?= htmlspecialchars($secData['info']['name']) ?>
          <span style="font-size:13px;font-weight:400;color:var(--text-muted);">— <?= $secData['info']['prog'] ?> · <?= $secData['info']['ay'] ?></span>
        </div>
        <a href="classes.php?section_id=<?= $secID ?>" class="btn btn-outline btn-sm">
          <i class="fas fa-users"></i> View Roster
        </a>
      </div>
      <table class="data-table">
        <thead>
          <tr>
            <th>Subject</th>
            <th style="text-align:center;">Sem</th>
            <th>Schedule / Room</th>
            <th style="text-align:center;">Students</th>
            <th style="text-align:center;">Drafts</th>
            <th style="text-align:center;">Returned</th>
            <th style="text-align:center;">Posted</th>
            <th style="text-align:center;">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($secData['subjects'] as $a): ?>
          <tr>
            <td>
              <div style="font-weight:700;color:var(--blue);"><?= htmlspecialchars($a['subjectCode']) ?></div>
              <div style="font-size:11.5px;color:var(--text-muted);"><?= htmlspecialchars($a['subjectName']) ?> · <?= $a['units'] ?>u</div>
            </td>
            <td style="text-align:center;font-weight:700;"><?= $a['semester']==1?'1st':'2nd' ?></td>
            <td style="font-size:12px;color:var(--text-muted);">
              <?= $a['schedule'] ? '<div><i class="fas fa-clock" style="margin-right:4px;color:var(--blue);"></i>'.htmlspecialchars($a['schedule']).'</div>' : '' ?>
              <?= $a['room']     ? '<div><i class="fas fa-door-open" style="margin-right:4px;color:var(--blue);"></i>'.htmlspecialchars($a['room']).'</div>'     : '' ?>
              <?= (!$a['schedule']&&!$a['room']) ? '—' : '' ?>
            </td>
            <td style="text-align:center;font-weight:700;font-size:16px;"><?= $a['studentCount'] ?></td>
            <td style="text-align:center;"><?= $a['pendingGrades']>0?"<span class='status-badge status-pending'>{$a['pendingGrades']}</span>":'—' ?></td>
            <td style="text-align:center;"><?= $a['submittedGrades']>0?"<span class='status-badge status-returned'>{$a['submittedGrades']}</span>":'—' ?></td>
            <td style="text-align:center;"><?= $a['approvedGrades']>0?"<span class='status-badge status-approved'>{$a['approvedGrades']}</span>":'—' ?></td>
            <td style="text-align:center;">
              <a href="encode_grades.php?section_id=<?= $a['sectionID'] ?>&subject_id=<?= $a['subjectID'] ?>&semester=<?= $a['semester'] ?>"
                 class="btn btn-primary btn-sm">
                <i class="fas fa-pen"></i> Grade
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- Right column -->
  <div style="display:flex;flex-direction:column;gap:16px;">

    <!-- Grade Workflow -->
    <div class="card">
      <div class="card-header"><div class="card-title"><i class="fas fa-route"></i> Grade Workflow</div></div>
      <div class="card-body" style="padding:16px 20px;">
        <?php foreach ([
          ['Encode Grades','Enter midterm and final scores.','fa-pen','blue','encode_grades.php'],
          ['Save Draft','Save entered grades before submission.','fa-save','orange','encode_grades.php'],
          ['Post Grades','Post saved grades directly to student records.','fa-paper-plane','blue','encode_grades.php'],
          ['MIS Returns','Review and correct grades returned by MIS.','fa-rotate-left','green','encode_grades.php'],
        ] as $i=>[$t,$d,$ic,$c,$link]): ?>
        <a href="<?= htmlspecialchars($link) ?>" style="display:flex;gap:12px;align-items:flex-start;text-decoration:none;color:inherit;<?= $i<3?'margin-bottom:16px;':'' ?>">
          <div style="width:32px;height:32px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;
            <?= match($c){'blue'=>'background:var(--blue);color:#fff;','orange'=>'background:var(--warning);color:#fff;','green'=>'background:var(--success);color:#fff;',default=>''} ?>">
            <?= $i+1 ?>
          </div>
          <div>
            <div style="font-size:13px;font-weight:700;"><?= $t ?></div>
            <div style="font-size:12px;color:var(--text-muted);"><?= $d ?></div>
          </div>
        </a>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Recent Activity -->
    <?php if (!empty($recentActivity)): ?>
    <div class="card">
      <div class="card-header"><div class="card-title"><i class="fas fa-history"></i> Recent Activity</div></div>
      <?php foreach ($recentActivity as $i => $act): ?>
      <div style="display:flex;gap:10px;align-items:center;padding:10px 16px;border-bottom:<?= $i<count($recentActivity)-1?'1px solid var(--border)':'none' ?>;">
        <div style="width:7px;height:7px;border-radius:50%;background:var(--blue);flex-shrink:0;"></div>
        <div style="font-size:12.5px;flex:1;">
          <strong><?= $act['action'] ?></strong> — <?= $act['subjectCode'] ?> for <span style="color:var(--blue);"><?= htmlspecialchars($act['studentName']) ?></span>
        </div>
        <div style="font-size:11px;color:var(--text-muted);white-space:nowrap;"><?= date('M d, g:i A',strtotime($act['actionAt'])) ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once ROOT . '/includes/footer.php'; ?>
