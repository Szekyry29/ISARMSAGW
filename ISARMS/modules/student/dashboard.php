<?php
define('ROOT', dirname(__DIR__, 2));
require_once ROOT . '/includes/config.php';
require_once ROOT . '/includes/functions.php';
requireAuth('Student');

$db        = Database::getInstance();
$user      = getCurrentUser();
$studentID = $user['id'];

$student  = $db->fetchOne("SELECT s.*, p.programName, p.programCode FROM students s JOIN programs p ON p.programID=s.programID WHERE s.studentID=:id", [':id'=>$studentID]);
$cumGWA   = computeGWA($studentID);
$latestPeriod = getLatestEnrollmentPeriod($studentID);
$deansListSemesters = [];
$completedSemesterKeys = [];

$studentAcademicYears = $db->fetchAll(
  "SELECT DISTINCT e.acadYearID, ay.academicYear
  FROM enrollments e
  JOIN acad_years ay ON ay.acadYearID = e.acadYearID
  WHERE e.studentID=:sid AND e.status='Active' AND e.dateDeleted IS NULL
  ORDER BY e.acadYearID DESC",
  [':sid'=>$studentID]
);
foreach ($studentAcademicYears as $academicYear) {
  for ($semester = 1; $semester <= 2; $semester++) {
    $activeCount = (int)($db->fetchOne(
      "SELECT COUNT(*) AS total FROM enrollments e
       WHERE e.studentID=:sid AND e.acadYearID=:ay AND e.semester=:sem
         AND e.status='Active' AND e.dateDeleted IS NULL
         AND " . gwaIncludedSubjectFilter('e'),
      [':sid'=>$studentID, ':ay'=>$academicYear['acadYearID'], ':sem'=>$semester]
    )['total'] ?? 0);
    $semesterStats = $db->fetchOne(
      "SELECT COUNT(e.enrollmentID) AS activeSubjects,
          COUNT(CASE WHEN g.gradeEquivalent IS NOT NULL THEN 1 END) AS gradedSubjects,
          COALESCE(SUM(sub.units), 0) AS totalUnits,
          COALESCE(SUM(g.gradeEquivalent * sub.units), 0) AS weightedSum,
          COALESCE(SUM(CASE WHEN g.gradeEquivalent > :threshold OR g.remarks = 'Inc' THEN 1 ELSE 0 END), 0) AS lowGrades
       FROM enrollments e
       JOIN subjects sub ON sub.subjectID=e.subjectID AND sub.dateDeleted IS NULL
       LEFT JOIN grades g ON g.enrollmentID=e.enrollmentID
         AND (g.status IN ('Posted','Approved') OR (g.status='Pending' AND g.submittedAt IS NOT NULL
           AND g.computedGrade IS NOT NULL AND g.gradeEquivalent IS NOT NULL))
         AND g.dateDeleted IS NULL
       WHERE e.studentID=:sid AND e.acadYearID=:ay AND e.semester=:sem
         AND e.status='Active' AND e.dateDeleted IS NULL
         AND " . gwaIncludedSubjectFilter('e'),
      [':threshold'=>HONORS_NO_BELOW, ':sid'=>$studentID, ':ay'=>$academicYear['acadYearID'], ':sem'=>$semester]
    );
    $semesterIsComplete = $activeCount > 0
      && (int)$semesterStats['activeSubjects'] === (int)$semesterStats['gradedSubjects']
      && (float)$semesterStats['totalUnits'] > 0;
    if ($semesterIsComplete) {
      $semesterKey = $academicYear['acadYearID'] . '_' . $semester;
      $semesterGWAValue = (float)$semesterStats['weightedSum'] / (float)$semesterStats['totalUnits'];
      $completedSemesterKeys[$semesterKey] = true;
      if ($semesterGWAValue >= SUMMA_MIN && $semesterGWAValue <= CUMLAUD_MAX && (int)$semesterStats['lowGrades'] === 0) {
        $deansListSemesters[] = $academicYear['academicYear'] . ' ' . ($semester === 1 ? '1st' : '2nd') . ' Semester';
      }
    }
  }
}
$honor    = classifyLatinHonor($cumGWA['gwa'], $studentID);
$yearRank = studentAcademicRecordIsComplete($studentID) ? getYearLevelRank($studentID, (int)$student['yearLevel']) : null;

// Current semester grades
$activeAY = $db->fetchOne("SELECT acadYearID, academicYear FROM acad_years WHERE status='Active' LIMIT 1");
$semGrades = [];
if ($activeAY && $latestPeriod && (int)$latestPeriod['acadYearID'] === (int)$activeAY['acadYearID']) {
    $semGrades = $db->fetchAll("
        SELECT g.gradeEquivalent, g.computedGrade, g.remarks,
             CASE
               WHEN g.status='Pending' AND g.submittedAt IS NOT NULL
                 AND g.computedGrade IS NOT NULL AND g.gradeEquivalent IS NOT NULL
               THEN 'Posted'
               ELSE g.status
             END AS status,
               sub.subjectCode, sub.subjectName, sub.units, e.semester
        FROM enrollments e
        JOIN subjects sub ON sub.subjectID=e.subjectID
        LEFT JOIN grades g ON g.enrollmentID=e.enrollmentID
        WHERE e.studentID=:sid AND e.acadYearID=:ay AND e.semester=:sem AND e.status='Active' AND e.dateDeleted IS NULL
        ORDER BY sub.subjectCode
      ", [':sid'=>$studentID, ':ay'=>$activeAY['acadYearID'], ':sem'=>$latestPeriod['semester']]);
}
    $currentTermGWA = ($activeAY && $latestPeriod && (int)$latestPeriod['acadYearID'] === (int)$activeAY['acadYearID'])
      ? computeGWA($studentID, (int)$latestPeriod['acadYearID'], (int)$latestPeriod['semester'])
      : ['gwa'=>0, 'totalUnits'=>0];

// Determine whether the student currently qualifies to see the Latin Honors UI.
// Not qualified when: no cumulative GWA yet, OR at least one approved grade
// falls within the 2.25 - 5.00 range (below Latin Honor grade standards).
$hasLowGrade = (int)($db->fetchOne(
    "SELECT COUNT(*) as c FROM grades WHERE studentID=:sid AND (status='Approved' OR status='Posted') AND gradeEquivalent >= 2.25 AND gradeEquivalent <= 5.00 AND dateDeleted IS NULL",
    [':sid'=>$studentID]
)['c'] ?? 0) > 0;
$hasNoGWA         = $cumGWA['gwa'] <= 0;
$latinNotEligible = $hasNoGWA || $hasLowGrade;
$showHonorRank = studentAcademicRecordIsComplete($studentID) && $yearRank;

$pageTitle  = 'My Dashboard';
$activeMenu = 'dashboard.php';
require_once ROOT . '/includes/header.php';
?>

<!-- Profile Banner -->
<div style="background:linear-gradient(135deg,var(--blue-dark) 0%,var(--blue) 100%);border-radius:16px;padding:28px 32px;margin-bottom:24px;color:#fff;display:grid;grid-template-columns:auto 1fr auto;gap:24px;align-items:center;box-shadow:0 16px 40px rgba(0,0,0,.14);">
  <div style="width:72px;height:72px;border-radius:50%;background:#ffd54f;display:flex;align-items:center;justify-content:center;font-size:28px;font-weight:700;color:#103138;">
    <?= strtoupper(substr($student['fName'],0,1)) ?>
  </div>
  <div>
    <h2 style="font-family:'Playfair Display',serif;font-size:22px;margin-bottom:6px;color:#fff;text-shadow:0 1px 4px rgba(0,0,0,.3);"><?= htmlspecialchars(fullName($student)) ?></h2>
    <p style="opacity:.95;font-size:14px;line-height:1.7;color:rgba(255,255,255,.95);"><?= htmlspecialchars($student['studentNo']) ?> &bull; <?= htmlspecialchars($student['programCode']) ?> &bull; Year <?= $student['yearLevel'] ?></p>
    <?php if ($honor !== 'None'): ?>
    <div style="margin-top:12px;"><span class="<?= honorBadgeClass($honor) ?>" style="font-size:13px;">✨ <?= htmlspecialchars($honor) ?></span></div>
    <?php endif; ?>
  </div>
  <div style="text-align:right;">
    <div style="font-size:11px;opacity:.95;text-transform:uppercase;letter-spacing:1px;margin-bottom:6px;color:rgba(255,255,255,.9);">Current Semester GWA</div>
    <div style="font-size:50px;font-weight:700;color:#ffe082;line-height:1.02;text-shadow:0 1px 3px rgba(0,0,0,.25);"><?= $currentTermGWA['gwa'] > 0 ? formatGWA($currentTermGWA['gwa']) : 'N/A' ?></div>
    <div style="font-size:12px;opacity:.85;margin-top:4px;color:rgba(255,255,255,.9);"><?= number_format((float)$currentTermGWA['totalUnits'], 1) ?> semester units</div>
    <?php if (!empty($deansListSemesters)): ?>
    <div style="font-size:11px;margin-top:8px;color:#ffe082;">
      <i class="fas fa-award"></i> Dean's List: <?= htmlspecialchars(implode(', ', $deansListSemesters)) ?>
    </div>
    <?php endif; ?>
    <?php if ($yearRank): ?>
    <div style="margin-top:10px;">
      <span class="<?= getRankingBadgeClass($yearRank) ?>" style="font-size:13px;display:inline-flex;align-items:center;padding:7px 12px;gap:6px;">
        <i class="fas fa-award" style="font-size:12px;"></i>
        Year <?= $student['yearLevel'] ?> Rank: <?= htmlspecialchars(getPositionLabel($yearRank)) ?>
      </span>
    </div>
    <?php endif; ?>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 300px;gap:20px;align-items:start;">
  <!-- Current Enrollment -->
  <div class="card">
    <div class="card-header">
      <div class="card-title"><i class="fas fa-list-ol"></i> Current Enrollment <?= $activeAY ? '— ' . htmlspecialchars($activeAY['academicYear']) : '' ?></div>
      <a href="grades.php" class="btn btn-outline btn-sm">All Grades</a>
    </div>
    <?php if (empty($semGrades)): ?>
    <div style="padding:40px;text-align:center;color:var(--text-muted);">No current enrollment records found.</div>
    <?php else: ?>
    <table class="data-table">
      <thead><tr><th>Subject</th><th>Units</th><th>Sem</th><th style="text-align:center;">Grade</th><th style="text-align:center;">Equiv.</th><th style="text-align:center;">Status</th></tr></thead>
      <tbody>
        <?php foreach ($semGrades as $g): ?>
        <tr>
          <td>
            <div style="font-weight:600;font-size:13px;"><?= htmlspecialchars($g['subjectCode']) ?></div>
            <div style="font-size:11px;color:var(--text-muted);"><?= htmlspecialchars($g['subjectName']) ?></div>
          </td>
          <td style="font-weight:600;"><?= $g['units'] ?></td>
          <td style="font-size:12px;">
            <?= $g['semester']==1?'1st':'2nd' ?>
            <?php $semesterKey = $activeAY['acadYearID'] . '_' . (int)$g['semester']; ?>
            <?php if (isset($completedSemesterKeys[$semesterKey]) && in_array($activeAY['academicYear'] . ' ' . ($g['semester']==1?'1st':'2nd') . ' Semester', $deansListSemesters, true)): ?>
              <span class="status-badge status-approved" style="display:block;margin-top:4px;font-size:10px;">Dean's List</span>
            <?php endif; ?>
          </td>
          <td style="text-align:center;font-weight:700;"><?= $g['computedGrade'] ?? '—' ?></td>
          <td style="text-align:center;font-size:16px;font-weight:700;color:<?= ($g['gradeEquivalent']??5)<=3?'var(--green)':'var(--text-muted)' ?>;">
            <?= $g['gradeEquivalent'] ? number_format($g['gradeEquivalent'],2) : '—' ?>
          </td>
          <td style="text-align:center;">
            <span class="status-badge <?= gradeStatusClass($g['status'] ?? 'Pending') ?>"><?= htmlspecialchars(normalizeGradeStatusDisplay($g['status'] ?? 'Not Graded')) ?></span>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <!-- Side Panel -->
  <div style="display:flex;flex-direction:column;gap:20px;">
    <div class="card">
      <div class="card-header"><div class="card-title"><i class="fas fa-award"></i> Honor Classification</div></div>
      <div class="card-body">
        <?php if ($latinNotEligible): ?>
        <!-- Not eligible UI: locked state in the same blue theme as the eligible/qualified design -->
        <div style="text-align:center;padding:20px 12px;background:linear-gradient(135deg,var(--blue-dark) 0%,var(--blue) 100%);border-radius:12px;color:#fff;">
          <div style="font-size:36px;margin-bottom:10px;opacity:.85;">🔒</div>
          <div style="font-size:14px;font-weight:700;color:#fff;margin-bottom:6px;">Not Yet Eligible for Latin Honors</div>
          <div style="font-size:12px;color:rgba(255,255,255,.75);line-height:1.7;">
            <?php if ($hasNoGWA): ?>
              No cumulative GWA computed yet.
            <?php else: ?>
              You have a grade within the 2.25–5.00 range, which does not meet Latin Honor grade standards.
            <?php endif; ?>
          </div>
        </div>
        <?php elseif ($honor !== 'None'): ?>
        <div style="text-align:center;padding:16px 0;">
          <div style="font-size:48px;margin-bottom:12px;">🏆</div>
          <span class="<?= honorBadgeClass($honor) ?>" style="font-size:15px;"><?= htmlspecialchars($honor) ?></span>
        </div>
        <?php else: ?>
        <div style="font-size:13px;color:var(--text-muted);line-height:1.8;padding:8px 0;">
          <div style="margin-bottom:8px;font-weight:600;color:var(--text);">Latin Honor Requirements:</div>
          <div>🥇 Summa: GWA 1.00–1.20</div>
          <div>🥈 Magna: GWA 1.21–1.45</div>
          <div>🥉 Cum Laude: GWA 1.46–1.75</div>
          <div style="margin-top:8px;font-size:12px;">No grade below 2.00 required</div>
        </div>
        <?php endif; ?>
        <a href="gwa.php" class="btn btn-primary" style="width:100%;justify-content:center;margin-top:12px;">
          <i class="fas fa-calculator"></i> View Full GWA
        </a>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><div class="card-title"><i class="fas fa-user"></i> My Profile</div></div>
      <div class="card-body" style="font-size:13px;">
        <div style="display:flex;flex-direction:column;gap:8px;color:var(--text-muted);">
          <div><strong style="color:var(--text);">Program:</strong> <?= htmlspecialchars($student['programName']) ?></div>
          <div><strong style="color:var(--text);">Email:</strong> <?= htmlspecialchars($student['email']) ?></div>
          <div><strong style="color:var(--text);">Status:</strong> <?= $student['isEnroll'] ? '<span style="color:var(--success);">Enrolled</span>' : 'Not Enrolled' ?></div>
        </div>
        <a href="profile.php" class="btn btn-outline" style="width:100%;justify-content:center;margin-top:14px;">
          View Profile
        </a>
      </div>
    </div>
  </div>
</div>

<?php require_once ROOT . '/includes/footer.php'; ?>