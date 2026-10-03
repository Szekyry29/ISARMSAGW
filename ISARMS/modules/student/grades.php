<?php
define('ROOT', dirname(__DIR__, 2));
require_once ROOT . '/includes/config.php';
require_once ROOT . '/includes/functions.php';
requireAuth('Student');

$db        = Database::getInstance();
$user      = getCurrentUser();
$studentID = $user['id'];

// Filters
$latestPeriod = getLatestEnrollmentPeriod($studentID);
$semFilter = array_key_exists('semester', $_GET) ? (int)$_GET['semester'] : (int)($latestPeriod['semester'] ?? 0);
$yearFilter = array_key_exists('year_level', $_GET) ? (int)$_GET['year_level'] : 0;

$where  = ["e.studentID=:sid", "e.status='Active'", "e.dateDeleted IS NULL"];
$params = [':sid' => $studentID];
if ($semFilter) { $where[] = "e.semester=:sem";  $params[':sem'] = $semFilter; }
if ($yearFilter) { $where[] = "sub.yearLevel=:year"; $params[':year'] = $yearFilter; }

$grades = $db->fetchAll("
          SELECT g.gradeID, g.midterm, g.finals, g.computedGrade, g.gradeEquivalent,
            g.remarks,
            CASE WHEN g.status='Pending' AND g.submittedAt IS NOT NULL
                  AND g.computedGrade IS NOT NULL AND g.gradeEquivalent IS NOT NULL
               THEN 'Posted' ELSE g.status END AS status,
            g.teacherRemarks, g.approvedAt, g.submittedAt,
           sub.subjectCode, sub.subjectName, sub.units,
          CONCAT(u.lName, ', ', u.fName) AS teacherName,
           ay.academicYear, ay.acadYearID, e.semester, sec.sectionName
        FROM enrollments e
        LEFT JOIN grades g ON g.enrollmentID = e.enrollmentID AND g.dateDeleted IS NULL
       JOIN subjects sub   ON sub.subjectID  = e.subjectID
        LEFT JOIN users u  ON u.userID       = g.teacherID
    JOIN acad_years ay  ON ay.acadYearID  = e.acadYearID
    JOIN sections sec   ON sec.sectionID  = e.sectionID
    WHERE " . implode(' AND ', $where) . "
    ORDER BY ay.academicYear DESC, e.semester, sub.subjectCode
", $params);

// Group by AY + Semester
$grouped = [];
foreach ($grades as $g) {
    $key = $g['acadYearID'] . '_' . $g['semester'];
    $grouped[$key]['label'] = $g['academicYear'] . ' — ' . ($g['semester']==1?'1st':'2nd') . ' Semester';
    $grouped[$key]['rows'][] = $g;
}

// Keep the summary tied to the latest exact period while the list shows all years.
$termGWA = $latestPeriod
  ? computeGWA($studentID, (int)$latestPeriod['acadYearID'], (int)$latestPeriod['semester'])
  : null;

// Stats
$totalApproved = count(array_filter($grades, fn($g) => in_array($g['status'], ['Posted', 'Approved'], true)));
$totalPending  = count(array_filter($grades, fn($g) => in_array($g['status'],['Pending','Returned'])));
$totalFailed   = count(array_filter($grades, fn($g) => $g['remarks']==='Failed' && in_array($g['status'], ['Posted', 'Approved'], true)));

$pageTitle  = 'My Grades';
$activeMenu = 'grades.php';
require_once ROOT . '/includes/header.php';
?>

<!-- Summary Strip -->
<div class="stat-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:20px;">
  <div class="stat-card">
    <div class="stat-icon blue"><i class="fas fa-list-ol"></i></div>
    <div class="stat-info"><div class="value"><?= count($grades) ?></div><div class="label">Total Subjects</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
    <div class="stat-info"><div class="value"><?= $totalApproved ?></div><div class="label">Official Grades</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon orange"><i class="fas fa-hourglass-half"></i></div>
    <div class="stat-info"><div class="value"><?= $totalPending ?></div><div class="label">Draft / Returned</div></div>
  </div>
  <div class="stat-card">
    <div class="stat-icon blue"><i class="fas fa-calculator"></i></div>
    <div class="stat-info">
      <div class="value" style="font-size:22px;"><?= $termGWA && $termGWA['gwa']>0 ? formatGWA($termGWA['gwa']) : 'N/A' ?></div>
      <div class="label">Latest Semester GWA</div>
    </div>
  </div>
</div>

<!-- Filters -->
<div class="card" style="margin-bottom:16px;">
  <div class="card-body" style="padding:16px 20px;">
    <form method="GET" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
      <div>
        <label class="form-label">Year Level</label>
        <select name="year_level" class="form-control" style="width:150px;">
          <option value="">All Year Levels</option>
          <?php foreach ([1=>'1st Year',2=>'2nd Year',3=>'3rd Year',4=>'4th Year',5=>'Irregular'] as $yearValue=>$yearLabel): ?>
          <option value="<?= $yearValue ?>" <?= $yearFilter===$yearValue?'selected':'' ?>><?= $yearLabel ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="form-label">Semester</label>
        <select name="semester" class="form-control" style="width:160px;">
          <option value="">Both Semesters</option>
          <option value="1" <?= $semFilter===1?'selected':'' ?>>1st Semester</option>
          <option value="2" <?= $semFilter===2?'selected':'' ?>>2nd Semester</option>
        </select>
      </div>
      <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
      <a href="grades.php" class="btn btn-outline"><i class="fas fa-times"></i> Clear</a>
    </form>
  </div>
</div>

<?php if (empty($grades)): ?>
<div class="card">
  <div style="padding:60px;text-align:center;color:var(--text-muted);">
    <i class="fas fa-clipboard" style="font-size:48px;display:block;margin-bottom:14px;opacity:.25;color:var(--blue);"></i>
    <div style="font-size:16px;font-weight:600;margin-bottom:8px;">No grade records found</div>
    <div style="font-size:13px;">Grades will appear here once your teachers have encoded and submitted them.</div>
  </div>
</div>
<?php else: ?>

<?php foreach ($grouped as $key => $group): ?>
<?php
  // Semester GWA only shows once every subject in this semester has been
  // Posted — computeGWA() already returns count=0 (and gwa=0) unless every
  // active enrollment in this acadYear+semester has a Posted grade.
  $groupAcadYearID = $group['rows'][0]['acadYearID'];
  $groupSemester   = $group['rows'][0]['semester'];
  $semResult       = computeGWA($studentID, $groupAcadYearID, $groupSemester);
  $semGWA          = $semResult['gwa'];
  $semComplete     = $semResult['count'] > 0;
?>
<div class="card" style="margin-bottom:18px;">
  <div class="card-header" style="background:var(--blue-light);">
    <div class="card-title">
      <i class="fas fa-calendar-alt" style="color:var(--blue);"></i>
      <?= htmlspecialchars($group['label']) ?>
    </div>
    <?php if ($semComplete): ?>
    <div style="display:flex;align-items:center;gap:12px;">
      <div style="font-size:13px;font-weight:600;color:var(--blue);">
        Semester GWA: <span style="font-size:20px;"><?= formatGWA($semGWA) ?></span>
      </div>
    </div>
    <?php else: ?>
    <div style="font-size:12px;color:var(--text-muted);">
      GWA appears once every subject this semester is Posted.
    </div>
    <?php endif; ?>
  </div>
  <div style="overflow-x:auto;">
    <table class="data-table">
      <thead>
        <tr>
          <th>Subject</th>
          <th>Section</th>
          <th>Teacher</th>
          <th style="text-align:center;">Units</th>
          <th style="text-align:center;">Midterm</th>
          <th style="text-align:center;">Finals</th>
          <th style="text-align:center;">Final Grade</th>
          <th style="text-align:center;">Equivalent</th>
          <th style="text-align:center;">Remarks</th>
          <th style="text-align:center;">Status</th>
          <th>Date</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($group['rows'] as $g): ?>
        <tr>
          <td>
            <div style="font-weight:700;color:var(--blue);font-size:13px;"><?= htmlspecialchars($g['subjectCode']) ?></div>
            <div style="font-size:11.5px;color:var(--text-muted);"><?= htmlspecialchars($g['subjectName']) ?></div>
          </td>
          <td style="font-size:12.5px;"><?= htmlspecialchars($g['sectionName']) ?></td>
          <td style="font-size:12.5px;"><?= htmlspecialchars($g['teacherName']) ?></td>
          <td style="text-align:center;font-weight:700;"><?= $g['units'] ?></td>
          <td style="text-align:center;">
            <?php if (in_array($g['status'], ['Posted', 'Approved'], true)): ?>
              <span style="font-weight:600;"><?= $g['midterm'] ?? '—' ?></span>
            <?php else: ?>
              <span style="color:var(--text-muted);">—</span>
            <?php endif; ?>
          </td>
          <td style="text-align:center;">
            <?php if (in_array($g['status'], ['Posted', 'Approved'], true)): ?>
              <span style="font-weight:600;"><?= $g['finals'] ?? '—' ?></span>
            <?php else: ?>
              <span style="color:var(--text-muted);">—</span>
            <?php endif; ?>
          </td>
          <td style="text-align:center;font-weight:700;font-size:16px;color:var(--blue);">
            <?= $g['computedGrade'] ?? '—' ?>
          </td>
          <td style="text-align:center;font-size:20px;font-weight:700;color:<?= ($g['gradeEquivalent']??5)<=3?'var(--success)':'var(--danger)' ?>;">
            <?= $g['gradeEquivalent'] ? number_format((float)$g['gradeEquivalent'],2) : '—' ?>
          </td>
          <td style="text-align:center;">
            <?php $rem=$g['remarks']??''; $rc=match($rem){'Passed'=>'status-approved','Failed'=>'status-flagged',default=>''}; ?>
            <?= $rem ? "<span class='status-badge $rc'>$rem</span>" : '<span style="color:var(--text-muted);">—</span>' ?>
          </td>
          <td style="text-align:center;">
            <span class="status-badge <?= gradeStatusClass($g['status'] ?? 'Pending') ?>"><?= htmlspecialchars(normalizeGradeStatusDisplay($g['status'] ?? 'Not Graded')) ?></span>
          </td>
          <td style="font-size:11.5px;color:var(--text-muted);">
            <?= $g['approvedAt'] ? date('M d, Y', strtotime($g['approvedAt'])) : ($g['submittedAt'] ? date('M d, Y', strtotime($g['submittedAt'])) : '—') ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<div style="font-size:12px;color:var(--text-muted);margin-top:8px;padding:12px 4px;border-top:1px solid var(--border);">
  <i class="fas fa-info-circle" style="color:var(--blue);"></i>
  Only <strong>Posted</strong> grades are included in GWA computation, and a semester's GWA only appears once every subject in that semester has been Posted. Grades still in progress show status as <em>Pending</em> or <em>Returned</em>.
</div>

<?php require_once ROOT . '/includes/footer.php'; ?>