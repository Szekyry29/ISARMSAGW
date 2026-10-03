<?php
/**
 * ISARMS - Student: GWA & Honors View
 */
define('ROOT', dirname(__DIR__, 2));
require_once ROOT . '/includes/config.php';
require_once ROOT . '/includes/functions.php';
requireAuth('Student');

$db        = Database::getInstance();
$user      = getCurrentUser();
$studentID = $user['id'];

// Get student profile
$student = $db->fetchOne("
    SELECT s.*, p.programName, p.programCode
    FROM students s JOIN programs p ON p.programID = s.programID
    WHERE s.studentID = :id
", [':id' => $studentID]);
$yearRank = studentAcademicRecordIsComplete($studentID) ? getYearLevelRank($studentID, (int)$student['yearLevel']) : null;

// Get all academic years with enrolled subjects
$acadYears = $db->fetchAll("
    SELECT DISTINCT ay.acadYearID, ay.academicYear, ay.status
    FROM enrollments e
    JOIN acad_years ay ON ay.acadYearID = e.acadYearID
    WHERE e.studentID = :sid AND e.dateDeleted IS NULL
    ORDER BY ay.academicYear DESC
", [':sid' => $studentID]);

// Compute cumulative GWA
$cumulative = computeGWA($studentID);
$cumulativeHonor = classifyLatinHonor($cumulative['gwa'], $studentID);
$latestPeriod = getLatestEnrollmentPeriod($studentID);
$latestTermGWA = $latestPeriod
  ? computeGWA($studentID, (int)$latestPeriod['acadYearID'], (int)$latestPeriod['semester'])
  : ['gwa'=>0, 'totalUnits'=>0];
$isDeansList = false;

// Latest per-semester grades released by the teacher or posted by Admin.
// (Moved above the breakdown loop so we can pull each semester's latest
// posted date while building the breakdown.)
$recentGrades = $db->fetchAll("
    SELECT g.gradeEquivalent, g.computedGrade, g.remarks, g.status, g.approvedAt, g.submittedAt,
           sub.subjectCode, sub.subjectName, sub.units,
           ay.academicYear, e.semester
    FROM grades g
    JOIN enrollments e ON e.enrollmentID = g.enrollmentID
    JOIN subjects sub  ON sub.subjectID  = g.subjectID
    JOIN acad_years ay ON ay.acadYearID  = e.acadYearID
    WHERE g.studentID = :sid
      AND (g.status = 'Posted' OR g.status = 'Approved')
      AND g.dateDeleted IS NULL
    ORDER BY ay.academicYear DESC, e.semester DESC, sub.subjectCode
", [':sid' => $studentID]);

// Build a lookup of the latest "posted" date per Academic Year + Semester,
// so the Semester Breakdown card can show when that semester's grades went official.
$postedDates = [];
foreach ($recentGrades as $rg) {
    // Same fallback grades.php uses: prefer approvedAt, fall back to submittedAt.
    $effectiveDate = $rg['approvedAt'] ?: ($rg['submittedAt'] ?? null);
    if (empty($effectiveDate)) continue;
    $key = $rg['academicYear'] . '_' . $rg['semester'];
    if (!isset($postedDates[$key]) || strtotime($effectiveDate) > strtotime($postedDates[$key])) {
        $postedDates[$key] = $effectiveDate;
    }
}

  $gradesBySemester = [1 => [], 2 => []];
  foreach ($recentGrades as $grade) {
    $semester = (int)$grade['semester'];
    if (isset($gradesBySemester[$semester])) {
      $gradesBySemester[$semester][] = $grade;
    }
  }

// Per year/semester breakdown
$breakdown = [];
foreach ($acadYears as $ay) {
    for ($sem = 1; $sem <= 2; $sem++) {
        $result = computeGWA($studentID, $ay['acadYearID'], $sem);
        if ($result['count'] > 0) {
            $isDL   = classifyDeansListSemester($studentID, $ay['acadYearID'], $sem);
            $dateKey = $ay['academicYear'] . '_' . $sem;
            $breakdown[] = [
                'year'       => $ay['academicYear'],
                'sem'        => $sem == 1 ? '1st Semester' : '2nd Semester',
                'gwa'        => $result['gwa'],
                'units'      => $result['totalUnits'],
                'subjects'   => $result['count'],
                'deanslist'  => $isDL,
                'postedDate' => $postedDates[$dateKey] ?? null,
            ];
            if ($isDL) $isDeansList = true;
        }
    }
}

// Determine whether the student currently qualifies to see the Latin Honors UI.
// Not qualified when: no cumulative GWA yet, OR at least one posted grade
// falls within the 2.25 - 5.00 range (below Latin Honor grade standards).
$hasLowGrade = (int)($db->fetchOne(
    "SELECT COUNT(*) as c FROM grades WHERE studentID=:sid AND (status='Posted' OR status='Approved') AND gradeEquivalent >= 2.25 AND gradeEquivalent <= 5.00 AND dateDeleted IS NULL",
    [':sid'=>$studentID]
)['c'] ?? 0) > 0;
$hasNoGWA         = $cumulative['gwa'] <= 0;
$latinNotEligible = $hasNoGWA || $hasLowGrade;

// The Year Level Rank badge should only ever appear once the student's grades
// are complete enough to have produced a cumulative GWA. Before that (no GWA
// yet), showing a "rank" would be misleading since it isn't based on final,
// posted standing.
$showRank = $yearRank && !$hasNoGWA && studentAcademicRecordIsComplete($studentID);

$pageTitle  = 'My GWA & Honors';
$activeMenu = 'gwa.php';
require_once ROOT . '/includes/header.php';
?>

<!-- Student Profile Banner -->
<div style="background:linear-gradient(135deg,var(--blue-dark) 0%,var(--blue) 100%);border-radius:16px;padding:28px 32px;margin-bottom:24px;color:#fff;display:flex;align-items:center;gap:24px;box-shadow:0 14px 40px rgba(0,0,0,.09);">
  <div style="width:72px;height:72px;border-radius:50%;background:#ffd54f;display:flex;align-items:center;justify-content:center;font-size:28px;font-weight:700;color:#11402b;flex-shrink:0;">
    <?= strtoupper(substr($student['fName'],0,1)) ?>
  </div>
  <div style="flex:1;">
    <h2 style="font-family:'Playfair Display',serif;font-size:22px;margin-bottom:6px;color:#fff;text-shadow:0 1px 3px rgba(0,0,0,.35);">
      <?= htmlspecialchars(fullName($student)) ?>
    </h2>
    <div style="opacity:.95;font-size:14px;line-height:1.7;color:rgba(255,255,255,.92);">
      <?= htmlspecialchars($student['studentNo']) ?> &bull;
      <?= htmlspecialchars($student['programName']) ?> (<?= htmlspecialchars($student['programCode']) ?>) &bull;
      Year <?= $student['yearLevel'] ?>
    </div>
    <?php if ($cumulativeHonor !== 'None'): ?>
    <div style="margin-top:12px;">
      <span class="<?= honorBadgeClass($cumulativeHonor) ?>" style="font-size:13px;">
        ⭐ <?= htmlspecialchars($cumulativeHonor) ?>
      </span>
    </div>
    <?php endif; ?>
  </div>
  <div style="text-align:right;">
    <div style="font-size:50px;font-weight:700;color:#ffe082;line-height:1.02;text-shadow:0 1px 3px rgba(0,0,0,.3);">
      <?= $latestTermGWA['gwa'] > 0 ? formatGWA($latestTermGWA['gwa']) : 'N/A' ?>
    </div>
    <div style="font-size:13px;opacity:.95;color:rgba(255,255,255,.9);">
      <?= $latestPeriod ? htmlspecialchars($latestPeriod['academicYear'] . ' — ' . ((int)$latestPeriod['semester'] === 1 ? '1st' : '2nd') . ' Semester GWA') : 'No active enrollment term' ?>
    </div>
    <div style="font-size:12px;opacity:.82;margin-top:6px;color:rgba(255,255,255,.85);">
      <?= number_format((float)$latestTermGWA['totalUnits'], 1) ?> semester units
    </div>
    <?php if ($showRank): ?>
    <div style="margin-top:12px;">
      <span class="<?= getRankingBadgeClass($yearRank) ?>" style="font-size:13px;display:inline-flex;align-items:center;padding:8px 12px;gap:6px;">
        <i class="fas fa-award" style="font-size:12px;"></i>
        Year <?= $student['yearLevel'] ?> Rank: <?= htmlspecialchars(getPositionLabel($yearRank)) ?>
      </span>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Honor Classification Reference -->
<?php if ($latinNotEligible): ?>
<!-- Not eligible UI: locked reference in the same blue theme as the eligible/qualified design -->
<div class="card" style="margin-bottom:20px;background:linear-gradient(135deg,var(--blue-dark) 0%,var(--blue) 100%);border:none;color:#fff;">
  <div class="card-header" style="border-bottom:1px solid rgba(255,255,255,.15);">
    <div class="card-title" style="color:#fff;"><i class="fas fa-lock"></i> Latin Honor Classification — Not Yet Eligible</div>
  </div>
  <div style="padding:24px 28px;">
    <div style="display:flex;align-items:center;gap:16px;">
      <div style="font-size:34px;opacity:.85;">🔒</div>
      <div>
        <div style="font-size:14px;font-weight:700;color:#fff;margin-bottom:4px;">This section unlocks once you meet the grade standards.</div>
        <div style="font-size:12px;color:rgba(255,255,255,.75);line-height:1.7;">
          <?php if ($hasNoGWA): ?>
            No cumulative GWA has been computed yet. Latin Honor and Dean's List classifications require at least one posted grade.
          <?php else: ?>
            One or more of your posted grades falls within 2.25–5.00, which does not meet Latin Honor grade standards (no grade below 2.00 is required).
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>
<?php else: ?>
<div class="card" style="margin-bottom:20px;background:linear-gradient(135deg,var(--blue-dark) 0%,var(--blue) 100%);border:none;border-radius:16px;overflow:hidden;box-shadow:0 14px 40px rgba(0,0,0,.09);">
  <div class="card-header" style="border-bottom:1px solid rgba(255,255,255,.15);">
    <div class="card-title" style="color:#fff;"><i class="fas fa-book"></i> NEUST Latin Honor Standards</div>
  </div>
  <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:0;">
    <?php $honors = [
      ['Summa Cum Laude','1.00 – 1.20','No grade below 2.00, no INC','🥇', $cumulativeHonor === 'Summa Cum Laude'],
      ['Magna Cum Laude','1.21 – 1.45','No grade below 2.00, no INC','🥈', $cumulativeHonor === 'Magna Cum Laude'],
      ['Cum Laude','1.46 – 1.75','No grade below 2.00, no INC','🥉', $cumulativeHonor === 'Cum Laude'],
      ["Dean's List",'1.00 – 1.75','Per semester, no failed/INC','📋', $isDeansList],
    ]; ?>
    <?php foreach ($honors as [$title,$range,$note,$icon,$qualifies]): ?>
    <div style="padding:22px 26px;<?= end($honors) === [$title,$range,$note,$icon,$qualifies] ? '' : 'border-right:1px solid rgba(255,255,255,.12);' ?>">
      <div style="font-size:26px;margin-bottom:12px;"><?= $icon ?></div>
      <div style="font-weight:700;font-size:14px;margin-bottom:8px;color:#fff;"><?= $title ?></div>
      <div style="font-size:19px;font-weight:700;margin-bottom:8px;color:#fff;">GWA <?= $range ?></div>
      <div style="font-size:12px;color:rgba(255,255,255,.7);line-height:1.5;"><?= $note ?></div>
      <?php if ($qualifies): ?>
      <div style="margin-top:12px;background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.25);border-radius:8px;padding:8px 12px;font-size:12px;font-weight:600;color:#fff;">
        ✓ You qualify!
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:300px 1fr;gap:20px;align-items:start;">

  <!-- Semester Breakdown -->
  <div class="card">
    <div class="card-header">
      <div class="card-title"><i class="fas fa-chart-line"></i> Semester Breakdown</div>
    </div>
    <div style="padding:0;">
      <?php if (empty($breakdown)): ?>
        <div style="padding:24px;text-align:center;color:var(--text-muted);font-size:13px;">No computed GWA records yet.</div>
      <?php else: ?>
        <?php foreach ($breakdown as $b): ?>
        <div style="padding:16px 20px;border-bottom:1px solid var(--border);">
          <div style="display:flex;justify-content:space-between;align-items:center;">
            <div>
              <div style="font-size:13px;font-weight:600;"><?= htmlspecialchars($b['year']) ?></div>
              <div style="font-size:12px;color:var(--text-muted);"><?= $b['sem'] ?> &bull; <?= $b['subjects'] ?> subjects &bull; <?= $b['units'] ?> units</div>
              <div style="font-size:11px;color:var(--text-muted);margin-top:2px;">
                <i class="fas fa-calendar-check" style="opacity:.6;"></i>
                <?= $b['postedDate'] ? 'Posted: ' . date('M d, Y', strtotime($b['postedDate'])) : 'Posted date unavailable' ?>
              </div>
              <?php if ($b['deanslist']): ?>
                <span style="font-size:11px;font-weight:700;color:var(--green);background:var(--green-light);padding:2px 8px;border-radius:10px;margin-top:4px;display:inline-block;">
                  📋 Dean's List
                </span>
              <?php endif; ?>
            </div>
            <div style="text-align:right;">
              <div style="font-size:22px;font-weight:700;color:<?= $b['gwa'] > 0 && $b['gwa'] <= 1.75 ? 'var(--green)' : 'var(--text)' ?>;">
                <?= formatGWA($b['gwa']) ?>
              </div>
              <div style="font-size:11px;color:var(--text-muted);">GWA</div>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Grade Records Table -->
  <div class="card">
    <div class="card-header">
      <div class="card-title"><i class="fas fa-list-ol"></i> Official Grade Records</div>
    </div>
    <div style="overflow-x:auto;">
      <?php if (empty($recentGrades)): ?>
        <div style="padding:40px;text-align:center;color:var(--text-muted);">
          <i class="fas fa-hourglass-half" style="font-size:36px;display:block;margin-bottom:12px;opacity:.3;"></i>
          No released grades on record yet.
        </div>
      <?php else: ?>
      <?php foreach ([1 => '1st Semester', 2 => '2nd Semester'] as $semester => $semesterLabel): ?>
        <?php if (empty($gradesBySemester[$semester])) continue; ?>
        <div style="padding:14px 18px;background:var(--blue-light);border-top:1px solid var(--border);border-bottom:1px solid var(--border);font-weight:700;color:var(--blue);">
          <i class="fas fa-calendar-alt"></i> <?= $semesterLabel ?>
        </div>
        <table class="data-table" style="margin-bottom:18px;">
          <thead>
            <tr>
              <th>Subject</th>
              <th>Units</th>
              <th>Academic Year</th>
              <th style="text-align:center;">Final Grade</th>
              <th style="text-align:center;">Equivalent</th>
              <th style="text-align:center;">Remarks</th>
              <th>Posted</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($gradesBySemester[$semester] as $g): ?>
            <tr>
              <td>
                <div style="font-weight:600;font-size:13px;"><?= htmlspecialchars($g['subjectCode']) ?></div>
                <div style="font-size:12px;color:var(--text-muted);"><?= htmlspecialchars($g['subjectName']) ?></div>
              </td>
              <td style="font-weight:600;"><?= $g['units'] ?></td>
              <td style="font-size:13px;"><?= htmlspecialchars($g['academicYear']) ?></td>
              <td style="text-align:center;font-weight:700;font-size:15px;"><?= $g['computedGrade'] ?? '—' ?></td>
              <td style="text-align:center;font-size:18px;font-weight:700;color:<?= ($g['gradeEquivalent'] ?? 5) <= 3 ? 'var(--green)' : 'var(--danger)' ?>;">
                <?= $g['gradeEquivalent'] ? number_format($g['gradeEquivalent'],2) : '—' ?>
              </td>
              <td style="text-align:center;">
                <?php $rem = $g['remarks'] ?? ''; $rc = match($rem){ 'Passed'=>'status-approved','Failed'=>'status-flagged',default=>'status-pending' }; ?>
                <?= $rem ? "<span class='status-badge $rc'>$rem</span>" : '—' ?>
              </td>
              <td style="font-size:12px;color:var(--text-muted);">
                <?php $gDate = $g['approvedAt'] ?: ($g['submittedAt'] ?? null); ?>
                <?= $gDate ? date('M d, Y', strtotime($gDate)) : '—' ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php require_once ROOT . '/includes/footer.php'; ?>