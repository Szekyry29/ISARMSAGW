<?php
define('ROOT', dirname(__DIR__, 2));
require_once ROOT . '/includes/config.php';
require_once ROOT . '/includes/functions.php';
requireAuth('Registrar');

$db   = Database::getInstance();
$user = getCurrentUser();

$acadYears = $db->fetchAll("SELECT acadYearID, academicYear FROM acad_years WHERE dateDeleted IS NULL ORDER BY academicYear DESC");
$activeAY = $db->fetchOne("SELECT acadYearID FROM acad_years WHERE status='Active' LIMIT 1");
$activeSemesterRow = $activeAY ? $db->fetchOne(
  "SELECT MAX(semester) AS semester FROM enrollments
   WHERE acadYearID=:ay AND status='Active' AND dateDeleted IS NULL",
  [':ay'=>$activeAY['acadYearID']]
) : null;
$defaultSemester = (int)($activeSemesterRow['semester'] ?? 0) ?: 2;
$acadYearFilter = (int)($_GET['acad_year_id'] ?? ($activeAY['acadYearID'] ?? ($acadYears[0]['acadYearID'] ?? 0)));
$semesterFilter = (int)($_GET['semester'] ?? $defaultSemester);
if (!in_array($semesterFilter, [1, 2], true)) $semesterFilter = $defaultSemester;

$yearFilter = (int)($_GET['year_level'] ?? 0);
$programFilter = (int)($_GET['program_id'] ?? 0);
$search = sanitize($_GET['search'] ?? '');

$where = ["s.dateDeleted IS NULL"];
$params = [];
if ($yearFilter > 0) {
    $where[] = 's.yearLevel = :yl';
    $params[':yl'] = $yearFilter;
}
if ($programFilter > 0) {
    $where[] = 's.programID = :pid';
    $params[':pid'] = $programFilter;
}
if ($search !== '') {
  $where[] = '(s.studentNo LIKE :search1 OR s.fName LIKE :search2 OR s.lName LIKE :search3 OR p.programCode LIKE :search4)';
  $params[':search1'] = '%' . $search . '%';
  $params[':search2'] = '%' . $search . '%';
  $params[':search3'] = '%' . $search . '%';
  $params[':search4'] = '%' . $search . '%';
}

$students = $db->fetchAll(
    "SELECT s.studentID, s.studentNo, s.fName, s.mName, s.lName, s.yearLevel, s.isGraduated, s.isDropped,
            p.programCode, p.programName, sec.sectionName,
                 completed_gwa.gwa,
                 completed_gwa.totalUnits
     FROM students s
     JOIN programs p ON p.programID = s.programID
     LEFT JOIN (
         SELECT e.studentID, e.sectionID
         FROM enrollments e
         WHERE e.acadYearID=:section_ay AND e.semester=:section_sem
           AND e.status = 'Active' AND e.dateDeleted IS NULL
         GROUP BY e.studentID
     ) current_section ON current_section.studentID = s.studentID
     LEFT JOIN sections sec ON sec.sectionID = current_section.sectionID
     LEFT JOIN (
              SELECT e.studentID,
                     ROUND(SUM(g.gradeEquivalent * sub.units) / SUM(sub.units), 4) AS gwa,
                     SUM(sub.units) AS totalUnits
              FROM enrollments e
              JOIN grades g ON g.enrollmentID = e.enrollmentID
              JOIN subjects sub ON sub.subjectID = g.subjectID
              WHERE e.acadYearID=:gwa_ay AND e.semester=:gwa_sem
                AND e.status='Active' AND e.dateDeleted IS NULL
                AND " . gwaIncludedSubjectFilter('e') . "
                AND " . officialGradeFilter('g.status') . "
                AND g.dateDeleted IS NULL AND g.gradeEquivalent IS NOT NULL
              GROUP BY e.studentID
              HAVING COUNT(DISTINCT e.enrollmentID) = (
                  SELECT COUNT(*) FROM enrollments all_e
                  WHERE all_e.studentID=e.studentID
                    AND all_e.acadYearID=:count_ay
                    AND all_e.semester=:count_sem
                    AND all_e.status='Active' AND all_e.dateDeleted IS NULL
                      AND " . gwaIncludedSubjectFilter('all_e') . "
              )
     ) completed_gwa ON completed_gwa.studentID = s.studentID
     WHERE " . implode(' AND ', $where) . "
     ORDER BY s.yearLevel DESC, s.lName, s.fName",
    $params + [
      ':section_ay'=>$acadYearFilter,
      ':section_sem'=>$semesterFilter,
      ':gwa_ay'=>$acadYearFilter,
      ':gwa_sem'=>$semesterFilter,
      ':count_ay'=>$acadYearFilter,
      ':count_sem'=>$semesterFilter,
    ]
);

$programs = $db->fetchAll("SELECT programID, programCode FROM programs WHERE dateDeleted IS NULL ORDER BY programCode");

$rankGroups = [];
foreach ($students as $entry) {
    if ($entry['gwa'] === null || $entry['gwa'] <= 0) {
        continue;
    }
    $yearKey = (int)$entry['yearLevel'];
    $rankGroups[$yearKey][] = $entry;
}

foreach ($rankGroups as &$group) {
    usort($group, function ($a, $b) {
        $gwaCmp = (float)($a['gwa'] ?? 0) <=> (float)($b['gwa'] ?? 0);
        if ($gwaCmp !== 0) return $gwaCmp;
        return strcmp(($a['lName'] ?? ''), ($b['lName'] ?? ''));
    });
    $position = 1;
    foreach ($group as &$item) {
        $item['rank'] = $position++;
    }
}
unset($group, $item);

$studentRanks = [];
foreach ($rankGroups as $group) {
    foreach ($group as $item) {
        $studentRanks[$item['studentID']] = $item['rank'];
    }
}
  $selectedAYLabel = '';
  foreach ($acadYears as $academicYear) {
    if ((int)$academicYear['acadYearID'] === $acadYearFilter) {
      $selectedAYLabel = $academicYear['academicYear'];
      break;
    }
  }
  $selectedTermLabel = $selectedAYLabel . ' — ' . ($semesterFilter === 1 ? '1st' : '2nd') . ' Semester';

$pageTitle = 'Registrar Leaderboard';
$activeMenu = 'leaderboard.php';
require_once ROOT . '/includes/header.php';
?>

<div class="card" style="margin-bottom:16px;">
  <div class="card-body" style="padding:14px 18px;">
    <form method="GET" class="filter-toolbar">
      <div><label class="form-label">Search Student</label><input type="search" name="search" class="form-control" placeholder="Name, student no., program" value="<?= htmlspecialchars($search) ?>"></div>
      <div>
        <label class="form-label">Academic Year</label>
        <select name="acad_year_id" class="form-control">
          <?php foreach ($acadYears as $academicYear): ?>
          <option value="<?= $academicYear['acadYearID'] ?>" <?= $acadYearFilter === (int)$academicYear['acadYearID'] ? 'selected' : '' ?>><?= htmlspecialchars($academicYear['academicYear']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="form-label">Semester</label>
        <select name="semester" class="form-control">
          <option value="1" <?= $semesterFilter === 1 ? 'selected' : '' ?>>1st Semester</option>
          <option value="2" <?= $semesterFilter === 2 ? 'selected' : '' ?>>2nd Semester</option>
        </select>
      </div>
      <div>
        <label class="form-label">Year Level</label>
        <select name="year_level" class="form-control">
          <option value="0" <?= $yearFilter === 0 ? 'selected' : '' ?>>All Years</option>
          <?php foreach ([1 => '1st Year', 2 => '2nd Year', 3 => '3rd Year', 4 => '4th Year'] as $y => $label): ?>
          <option value="<?= $y ?>" <?= $yearFilter === $y ? 'selected' : '' ?>><?= $label ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="form-label">Program</label>
        <select name="program_id" class="form-control">
          <option value="0">All Programs</option>
          <?php foreach ($programs as $p): ?>
          <option value="<?= $p['programID'] ?>" <?= $programFilter === (int)$p['programID'] ? 'selected' : '' ?>><?= htmlspecialchars($p['programCode']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="toolbar-actions" style="justify-content:flex-start;">
        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter"></i> Filter</button>
        <a href="leaderboard.php" class="btn btn-outline btn-sm"><i class="fas fa-times"></i> Reset</a>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header">
    <div class="card-title"><i class="fas fa-medal"></i> Academic Leaderboard</div>
    <span style="font-size:13px;color:var(--text-muted);">Sorted by <?= htmlspecialchars($selectedTermLabel) ?> GWA; year groups are ordered 4th to 1st.</span>
  </div>
  <div style="overflow-x:auto;">
    <table class="data-table">
      <thead>
        <tr>
          <th style="text-align:center;">Rank</th>
          <th>Student</th>
          <th>Program</th>
          <th style="text-align:center;">Year</th>
          <th style="text-align:center;">Section</th>
          <th style="text-align:center;">GWA</th>
          <th style="text-align:center;">Honor</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($students)): ?>
        <tr><td colspan="7" style="text-align:center;padding:40px;color:var(--text-muted);">No student records found for the selected filters.</td></tr>
        <?php else:
          $displayYears = $yearFilter > 0 ? [$yearFilter] : [4,3,2,1];
          foreach ($displayYears as $displayYear):
            $yearRows = $rankGroups[$displayYear] ?? [];
            if (empty($yearRows)) continue;
        ?>
        <tr style="font-weight:700;height:40px;">
          <td colspan="7" style="padding:10px 16px;border-top:1px solid var(--border);color:var(--text-dark);">
            <i class="fas fa-layer-group" style="margin-right:6px;color:var(--text-muted);"></i> Year <?= $displayYear ?> Students
          </td>
        </tr>
        <?php foreach ($yearRows as $row):
          $isDeansList = classifyDeansListSemester((int)$row['studentID'], $acadYearFilter, $semesterFilter);
        ?>
        <tr>
          <td style="text-align:center;font-weight:700;">
            <?php if (!empty($studentRanks[(int)$row['studentID']])): ?>
            <span class="<?= getRankingBadgeClass($studentRanks[(int)$row['studentID']]) ?>" style="display:inline-flex;justify-content:center;align-items:center;padding:4px 8px;min-width:60px;">
              <?= htmlspecialchars(getPositionLabel($studentRanks[(int)$row['studentID']])) ?>
            </span>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td>
            <div style="display:flex;align-items:center;gap:10px;">
              <div style="width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,var(--blue),var(--blue-mid));color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;flex-shrink:0;">
                <?= strtoupper(substr($row['fName'], 0, 1)) ?>
              </div>
              <div>
                <div style="font-weight:700;font-size:13.5px;">
                  <?= htmlspecialchars("{$row['lName']}, {$row['fName']}" . (!empty($row['mName']) ? ' ' . substr($row['mName'], 0, 1) . '.' : '')) ?>
                </div>
                <div style="font-size:11.5px;color:var(--blue);font-weight:600;"><?= htmlspecialchars($row['studentNo']) ?></div>
              </div>
            </div>
          </td>
          <td style="font-weight:700;color:var(--blue);"><?= htmlspecialchars($row['programCode']) ?></td>
          <td style="text-align:center;font-weight:700;">Year <?= $row['yearLevel'] ?></td>
          <td style="text-align:center;"><?= htmlspecialchars($row['sectionName'] ?: '—') ?></td>
          <td style="text-align:center;font-weight:700;color:<?= ((float)$row['gwa'] <= 1.75) ? 'var(--success)' : 'var(--blue)' ?>;">
            <?= $row['gwa'] ? number_format((float)$row['gwa'], 4) : '—' ?>
          </td>
          <td style="text-align:center;">
            <?php if ($isDeansList): ?>
            <span class="<?= honorBadgeClass("Dean's List") ?>">Dean's List</span>
            <?php else: ?>
            <span style="color:var(--text-muted);font-size:12px;">—</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once ROOT . '/includes/footer.php'; ?>