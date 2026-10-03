<?php
define('ROOT', dirname(__DIR__, 2));
require_once ROOT . '/includes/config.php';
require_once ROOT . '/includes/functions.php';
requireAuth('Student');

$db        = Database::getInstance();
$user      = getCurrentUser();
$studentID = $user['id'];

$student  = $db->fetchOne("SELECT s.*, p.programCode, p.programName FROM students s JOIN programs p ON p.programID=s.programID WHERE s.studentID=:id", [':id'=>$studentID]);
$cumGWA   = computeGWA($studentID);
$honor    = classifyLatinHonor($cumGWA['gwa'], $studentID);
$latestPeriod = getLatestEnrollmentPeriod($studentID);
$latestTermGWA = $latestPeriod
  ? computeGWA($studentID, (int)$latestPeriod['acadYearID'], (int)$latestPeriod['semester'])
  : ['gwa'=>0, 'totalUnits'=>0];
$honorDisplay = getStudentHonorsDisplay($studentID);

// GWA records with validated honors
$honorRecords = $db->fetchAll("
    SELECT gr.*, ay.academicYear
    FROM gwa_records gr
    JOIN acad_years ay ON ay.acadYearID=gr.acadYearID
    WHERE gr.studentID=:sid
    ORDER BY ay.academicYear DESC, gr.semester DESC
", [':sid' => $studentID]);

$semesterRecords = array_filter($honorRecords, fn($r) => $r['semester'] !== null);
$cumulativeRecord = array_values(array_filter($honorRecords, fn($r) => $r['semester'] === null));

// Determine whether the student currently qualifies to see the Latin Honors UI.
// Not qualified when: no cumulative GWA yet, OR at least one approved grade
// falls within the 2.25 - 5.00 range (below Latin Honor grade standards).
$hasLowGrade = (int)($db->fetchOne(
    "SELECT COUNT(*) as c FROM grades WHERE studentID=:sid AND (status='Approved' OR status='Posted') AND gradeEquivalent >= 2.25 AND gradeEquivalent <= 5.00 AND dateDeleted IS NULL",
    [':sid'=>$studentID]
)['c'] ?? 0) > 0;

$hasNoGWA         = $cumGWA['gwa'] <= 0;
$latinNotEligible = $hasNoGWA || $hasLowGrade;

$pageTitle  = 'My Honors';
$activeMenu = 'honors.php';
require_once ROOT . '/includes/header.php';
?>

<!-- Hero Banner -->
<div style="background:linear-gradient(135deg,var(--blue-dark) 0%,var(--blue) 100%);border-radius:16px;padding:32px;margin-bottom:24px;color:#fff;position:relative;overflow:hidden;">
  <div style="position:absolute;top:-30px;right:-30px;width:200px;height:200px;border-radius:50%;background:rgba(245,197,24,.08);"></div>
  <div style="position:absolute;bottom:-50px;right:60px;width:150px;height:150px;border-radius:50%;background:rgba(255,255,255,.04);"></div>

  <div style="position:relative;display:grid;grid-template-columns:1fr auto;gap:24px;align-items:center;">
    <div>
      <div style="font-size:12px;color:rgba(255,255,255,.6);text-transform:uppercase;letter-spacing:1.5px;margin-bottom:8px;">
        Academic Honors Summary
      </div>

      <h2 style="font-family:'Playfair Display',serif;font-size:22px;margin-bottom:6px;">
        <?= htmlspecialchars(fullName($student)) ?>
      </h2>

      <div style="font-size:13px;color:rgba(255,255,255,.75);">
        <?= htmlspecialchars($student['studentNo']) ?>
        &bull;
        <?= htmlspecialchars($student['programCode']) ?>
        &bull;
        Year <?= $student['yearLevel'] ?>
      </div>

      <?php if ($honor !== 'None'): ?>
      <div style="margin-top:14px;">
        <span class="<?= honorBadgeClass($honor) ?>" style="font-size:14px;">
          ⭐ <?= htmlspecialchars($honor) ?>
        </span>
      </div>
      <?php else: ?>
      <div style="margin-top:14px;font-size:13px;color:rgba(255,255,255,.5);">
        No Latin honor classification yet. Keep striving!
      </div>
      <?php endif; ?>

      <?php if ($honorDisplay['rank'] && $honorDisplay['total'] > 0): ?>
      <div style="margin-top:12px;padding:8px 12px;background:rgba(255,255,255,.1);border-radius:6px;font-size:12px;display:inline-block;">
        <i class="fas fa-medal"></i>
        Rank <strong>#<?= $honorDisplay['rank'] ?></strong>
        of <?= $honorDisplay['total'] ?>
        in Year <?= $student['yearLevel'] ?>
      </div>
      <?php endif; ?>
    </div>

    <div style="text-align:right;">
      <div style="font-size:11px;color:rgba(255,255,255,.5);text-transform:uppercase;letter-spacing:1px;">
        Latest Semester GWA
      </div>

      <div style="font-size:52px;font-weight:700;color:var(--gold);line-height:1;">
        <?= $latestTermGWA['gwa']>0?formatGWA($latestTermGWA['gwa']):'N/A' ?>
      </div>

      <div style="font-size:12px;color:rgba(255,255,255,.55);">
        <?= $latestTermGWA['totalUnits'] ?> semester units
      </div>
    </div>
  </div>
</div>


<!-- Latin Honor Classifications Reference -->
<?php if ($latinNotEligible): ?>

<!-- Not eligible UI -->
<div class="card" style="margin-bottom:20px;background:linear-gradient(135deg,var(--blue-dark) 0%,var(--blue) 100%);border:none;color:#fff;">

  <div class="card-header" style="border-bottom:1px solid rgba(255,255,255,.15);">
    <div class="card-title" style="color:#fff;">
      <i class="fas fa-lock"></i>
      NEUST Latin Honor Standards — Not Yet Eligible
    </div>
  </div>

  <div style="padding:24px 28px;">
    <div style="display:flex;align-items:center;gap:16px;">

      <div style="font-size:34px;opacity:.85;">
        🔒
      </div>

      <div>
        <div style="font-size:14px;font-weight:700;color:#fff;margin-bottom:4px;">
          This section unlocks once you meet the grade standards.
        </div>

        <div style="font-size:12px;color:rgba(255,255,255,.75);line-height:1.7;">
          <?php if ($hasNoGWA): ?>
            No cumulative GWA has been computed yet. Latin Honor and Dean's List classifications require at least one approved grade.
          <?php else: ?>
            One or more of your approved grades falls within 2.25–5.00, which does not meet Latin Honor grade standards (no grade below 2.00 is required).
          <?php endif; ?>
        </div>
      </div>

    </div>
  </div>
</div>

<?php else: ?>

<!-- BLUE NEUST LATIN HONOR STANDARDS UI -->
<div class="card" style="margin-bottom:20px;background:linear-gradient(135deg,var(--blue-dark) 0%,var(--blue) 100%);border:none;color:#fff;">

  <div class="card-header" style="border-bottom:1px solid rgba(255,255,255,.15);">
    <div class="card-title" style="color:#fff;">
      <i class="fas fa-book-open"></i>
      NEUST Latin Honor Standards
    </div>
  </div>

  <div style="display:grid;grid-template-columns:repeat(4,1fr);">

    <?php $honorsRef = [
      ['Summa Cum Laude','rgba(255,255,255,.12)','#fff','1.00 – 1.20','🥇','No grade below 2.00, no INC'],
      ['Magna Cum Laude','rgba(255,255,255,.12)','#fff','1.21 – 1.45','🥈','No grade below 2.00, no INC'],
      ['Cum Laude','rgba(255,255,255,.12)','#fff','1.46 – 1.75','🥉','No grade below 2.00, no INC'],
      ["Dean's List",'var(--blue)','#fff','1.00 – 1.75','📋','Per semester, no failed/INC'],
    ]; ?>

    <?php foreach ($honorsRef as $i => [$title,$bg,$tc,$range,$icon,$note]): ?>

    <div style="
      padding:20px 22px;
      background:<?= $bg ?>;
      color:<?= $tc ?>;
      <?= $i<3?'border-right:1px solid rgba(255,255,255,.15)':''; ?>
    ">

      <div style="font-size:26px;margin-bottom:10px;">
        <?= $icon ?>
      </div>

      <div style="font-weight:700;font-size:13.5px;margin-bottom:4px;">
        <?= $title ?>
      </div>

      <div style="font-size:19px;font-weight:700;margin-bottom:4px;">
        GWA <?= $range ?>
      </div>

      <div style="font-size:11px;opacity:.85;line-height:1.5;">
        <?= $note ?>
      </div>

      <?php if ($honor === $title): ?>

      <div style="
        margin-top:10px;
        background:rgba(255,255,255,.20);
        border:1px solid rgba(255,255,255,.35);
        border-radius:8px;
        padding:5px 8px;
        font-size:11px;
        font-weight:700;
        color:#fff;
      ">
        ✓ You qualify!
      </div>

      <?php endif; ?>

    </div>

    <?php endforeach; ?>

  </div>
</div>

<?php endif; ?>


<!-- Per Semester Dean's List / GWA History -->
<?php if (!empty($honorRecords)): ?>

<div class="card" style="margin-bottom:20px;">

  <div class="card-header">
    <div class="card-title">
      <i class="fas fa-history"></i>
      GWA History & Honors Records
    </div>
  </div>

  <div style="overflow-x:auto;">

    <table class="data-table">

      <thead>
        <tr>
          <th>Academic Year</th>
          <th>Period</th>
          <th style="text-align:center;">GWA</th>
          <th style="text-align:center;">Total Units</th>
          <th>Classification</th>
          <th style="text-align:center;">Validation</th>
          <th>Date Computed</th>
        </tr>
      </thead>

      <tbody>

        <?php foreach ($honorRecords as $r): ?>

        <tr>

          <td style="font-weight:600;">
            <?= htmlspecialchars($r['academicYear']) ?>
          </td>

          <td style="font-size:13px;">
            <?= $r['semester']
              ? ($r['semester']==1?'1st':'2nd').' Semester'
              : '<strong>Cumulative</strong>' ?>
          </td>

          <td style="
            text-align:center;
            font-size:20px;
            font-weight:700;
            color:<?= (float)$r['gwaValue']<=1.75?'var(--success)':'var(--blue)' ?>;
          ">
            <?= formatGWA((float)$r['gwaValue']) ?>
          </td>

          <td style="text-align:center;font-weight:600;">
            <?= $r['totalUnits'] ?>
          </td>

          <td>

            <?php if ($r['latinHonor'] !== 'None'): ?>

              <span class="<?= honorBadgeClass($r['latinHonor']) ?>">
                <?= htmlspecialchars($r['latinHonor']) ?>
              </span>

            <?php else: ?>

              <span style="color:var(--text-muted);font-size:13px;">
                —
              </span>

            <?php endif; ?>

          </td>

          <td style="text-align:center;">

            <?php $vs = $r['validationStatus']; ?>

            <span class="status-badge <?= $vs==='Validated'?'status-approved':($vs==='Flagged'?'status-flagged':'status-pending') ?>">
              <?= $vs ?>
            </span>

          </td>

          <td style="font-size:12px;color:var(--text-muted);">
            <?= date('M d, Y', strtotime($r['computedAt'])) ?>
          </td>

        </tr>

        <?php endforeach; ?>

      </tbody>

    </table>

  </div>
</div>

<?php else: ?>

<div class="card">

  <div style="padding:48px;text-align:center;color:var(--text-muted);">

    <i class="fas fa-award"
       style="font-size:48px;display:block;margin-bottom:14px;opacity:.2;color:var(--gold);">
    </i>

    <div style="font-size:15px;font-weight:600;margin-bottom:8px;">
      No honor records yet
    </div>

    <div style="font-size:13px;">
      Honor records are generated after the Registrar approves and validates your grades each semester.
    </div>

  </div>

</div>

<?php endif; ?>


<!-- Motivational checklist -->
<div class="card">

  <div class="card-header">
    <div class="card-title">
      <i class="fas fa-tasks"></i>
      Honors Eligibility Checklist
    </div>
  </div>

  <div class="card-body">

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">

      <?php

      $gwaOk     = $cumGWA['gwa'] >= 1.00 && $cumGWA['gwa'] <= 1.75;
      $hasGrades = $cumGWA['count'] > 0;

      // Check for any grades below 2.00
      $lowGrade = $db->fetchOne(
        "SELECT COUNT(*) as c
         FROM grades
         WHERE studentID=:sid
         AND (status='Approved' OR status='Posted')
         AND gradeEquivalent > 2.00
         AND dateDeleted IS NULL",
        [':sid'=>$studentID]
      )['c'] ?? 0;

      $items = [
        ['Have enrolled and approved grades', $hasGrades],
        ['GWA within honors range (1.00–1.75)', $gwaOk && $hasGrades],
        ['No grade below 2.00', (int)$lowGrade === 0 && $hasGrades],
        ['No Incomplete (INC) grades', true],
      ];

      ?>

      <?php foreach ($items as [$label,$passed]): ?>

      <div style="
        display:flex;
        align-items:center;
        gap:12px;
        padding:12px 16px;
        border-radius:10px;
        background:<?= $passed?'#dcfce7':'#fef2f2' ?>;
      ">

        <div style="
          width:28px;
          height:28px;
          border-radius:50%;
          background:<?= $passed?'var(--success)':'var(--danger)' ?>;
          color:#fff;
          display:flex;
          align-items:center;
          justify-content:center;
          font-size:13px;
          flex-shrink:0;
        ">

          <i class="fas <?= $passed?'fa-check':'fa-times' ?>"></i>

        </div>

        <span style="
          font-size:13px;
          font-weight:600;
          color:<?= $passed?'var(--success)':'var(--danger)' ?>;
        ">
          <?= $label ?>
        </span>

      </div>

      <?php endforeach; ?>

    </div>

    <?php if ($honor !== 'None'): ?>

    <div style="
      margin-top:16px;
      padding:16px;
      background:linear-gradient(135deg,var(--gold-light),#fff8d0);
      border-radius:12px;
      border:1px solid var(--gold);
      text-align:center;
    ">

      <div style="font-size:24px;margin-bottom:6px;">
        🎉
      </div>

      <div style="font-weight:700;color:var(--gold-deep);font-size:15px;">
        Congratulations! You qualify for <?= htmlspecialchars($honor) ?>
      </div>

      <div style="font-size:12px;color:var(--text-muted);margin-top:4px;">
        Subject to Registrar validation and confirmation.
      </div>

    </div>

    <?php endif; ?>

  </div>

</div>


<?php require_once ROOT . '/includes/footer.php'; ?>