<?php

/**
 * ISARMS - Admin: Student Management
 */
define('ROOT', dirname(__DIR__, 2));
require_once ROOT . '/includes/config.php';
require_once ROOT . '/includes/functions.php';
requireAuth('Admin', 'Registrar');

$db   = Database::getInstance();
$user = getCurrentUser();

function studentSectionLabel($sectionName, $programCode = '') {
  $sectionName = trim((string)$sectionName);
  $programCode = trim((string)$programCode);
  if ($sectionName !== '' && $programCode !== '') {
    $sectionName = preg_replace('/^' . preg_quote($programCode, '/') . '\s*[-:\x{2013}\x{2014}]?\s*/iu', '', $sectionName);
  }
  return trim($sectionName);
}

// Pagination
$perPage = 15;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

// Filters
$search    = sanitize($_GET['search']    ?? '');
$progID    = (int)($_GET['program_id']  ?? 0);
$sectionID = (int)($_GET['section_id'] ?? 0);
$enrolled  = $_GET['enrolled'] ?? '';

// Year level tab: '' means "All" (Show All), otherwise 1-5 (5 = irregular)
$yearLvlRaw = $_GET['year_level'] ?? '';
$yearLvl    = ($yearLvlRaw === '' || $yearLvlRaw === 'all') ? '' : (int)$yearLvlRaw;

$where  = ['s.dateDeleted IS NULL'];
$params = [];

// Each filter below is independent — it is only added to the WHERE clause
// when it actually has a value. If the search box is left empty, no search
// condition is added, so it does NOT hide results. This means picking a
// dropdown (Program / Section / Year Level / Enrollment Status) while the
// search box is blank still correctly shows matching results — it does not
// return "no results" just because search is empty.
if ($search) {
    $where[] = "(s.studentNo LIKE :srch_no OR s.fName LIKE :srch_fn OR s.mName LIKE :srch_mn OR s.lName LIKE :srch_ln OR CONCAT(s.fName, ' ', COALESCE(s.mName, ''), ' ', s.lName) LIKE :srch_full OR CONCAT(s.lName, ', ', s.fName) LIKE :srch_full_lname OR s.email LIKE :srch_em)";
    $params[':srch_no'] = "%$search%";
    $params[':srch_fn'] = "%$search%";
    $params[':srch_mn'] = "%$search%";
    $params[':srch_ln'] = "%$search%";
    $params[':srch_full'] = "%$search%";
    $params[':srch_full_lname'] = "%$search%";
    $params[':srch_em'] = "%$search%";
}
if ($progID)    { $where[] = "s.programID=:pid";  $params[':pid'] = $progID; }
if ($yearLvl !== '') { $where[] = "s.yearLevel=:yl"; $params[':yl'] = $yearLvl; }
if ($sectionID) {
  $where[] = "COALESCE(s.sectionID, (SELECT e_sec.sectionID FROM enrollments e_sec WHERE e_sec.studentID=s.studentID AND e_sec.status='Active' AND e_sec.dateDeleted IS NULL ORDER BY e_sec.enrollmentID DESC LIMIT 1)) = :sec";
    $params[':sec'] = $sectionID;
}
if ($enrolled !== '') { $where[] = "s.isEnroll=:en"; $params[':en'] = (int)$enrolled; }

$whereSQL = implode(' AND ', $where);

$hasExplicitFilter = $search !== '' || $progID > 0 || $yearLvl !== '' || $sectionID > 0 || $enrolled !== '';

if (!$hasExplicitFilter) {
    $total = 0;
    $pages = 1;
    $students = [];
} else {
    $total    = $db->fetchOne("SELECT COUNT(*) as c FROM students s WHERE $whereSQL", $params)['c'];
    $pages    = (int)ceil($total / $perPage);
    $students = $db->fetchAll("
        SELECT s.studentID, s.studentNo, s.fName, s.mName, s.lName, s.extName,
               s.sex, s.yearLevel, s.isEnroll, s.isActive, s.email, s.dateCreated,
               COALESCE(s.sectionID, (
                 SELECT e.sectionID
                 FROM enrollments e
                 WHERE e.studentID=s.studentID AND e.dateDeleted IS NULL AND e.status='Active'
                 ORDER BY e.enrollmentID DESC
                 LIMIT 1
               )) AS sectionID,
               p.programCode, p.programName,
               COALESCE(
                 (SELECT sec.sectionName FROM sections sec WHERE sec.sectionID=s.sectionID AND sec.dateDeleted IS NULL LIMIT 1),
                 (SELECT sec.sectionName
                  FROM enrollments e2
                  LEFT JOIN sections sec ON sec.sectionID=e2.sectionID
                  WHERE e2.studentID=s.studentID AND e2.dateDeleted IS NULL AND e2.status='Active'
                  ORDER BY e2.enrollmentID DESC
                  LIMIT 1)
               ) AS sectionName
        FROM students s 
        JOIN programs p ON p.programID=s.programID
        WHERE $whereSQL
        ORDER BY s.lName, s.fName
        LIMIT $perPage OFFSET $offset
    ", $params);
}

$programs = $db->fetchAll("SELECT programID, programCode, programName FROM programs WHERE dateDeleted IS NULL ORDER BY programCode");
$activeAcademicYear = $db->fetchOne("SELECT acadYearID, academicYear FROM acad_years WHERE status='Active' AND dateDeleted IS NULL ORDER BY acadYearID DESC LIMIT 1");
$sections = $db->fetchAll(
  "SELECT sec.sectionID, sec.sectionName, sec.programID, p.programCode, ay.academicYear
   FROM sections sec
   JOIN programs p ON p.programID=sec.programID
   JOIN acad_years ay ON ay.acadYearID=sec.acadYearID
   WHERE sec.dateDeleted IS NULL AND sec.acadYearID=:acadYearID
   ORDER BY sec.sectionName",
  [':acadYearID' => (int)($activeAcademicYear['acadYearID'] ?? 0)]
);

// Counts per year level for the tab badges (respects search/program/section/enrolled filters, ignores year_level itself)
$countWhere  = ['s.dateDeleted IS NULL'];
$countParams = [];
if ($search) {
    $countWhere[] = "(s.studentNo LIKE :srch_no OR s.fName LIKE :srch_fn OR s.mName LIKE :srch_mn OR s.lName LIKE :srch_ln OR CONCAT(s.fName, ' ', COALESCE(s.mName, ''), ' ', s.lName) LIKE :srch_full OR CONCAT(s.lName, ', ', s.fName) LIKE :srch_full_lname OR s.email LIKE :srch_em)";
    $countParams[':srch_no'] = "%$search%";
    $countParams[':srch_fn'] = "%$search%";
    $countParams[':srch_mn'] = "%$search%";
    $countParams[':srch_ln'] = "%$search%";
    $countParams[':srch_full'] = "%$search%";
    $countParams[':srch_full_lname'] = "%$search%";
    $countParams[':srch_em'] = "%$search%";
}
if ($progID)    { $countWhere[] = "s.programID=:pid";  $countParams[':pid'] = $progID; }
if ($sectionID) {
    $countWhere[] = "COALESCE(s.sectionID, (SELECT e_sec.sectionID FROM enrollments e_sec WHERE e_sec.studentID=s.studentID AND e_sec.status='Active' AND e_sec.dateDeleted IS NULL ORDER BY e_sec.enrollmentID DESC LIMIT 1)) = :sec";
    $countParams[':sec'] = $sectionID;
}
if ($enrolled !== '') { $countWhere[] = "s.isEnroll=:en"; $countParams[':en'] = (int)$enrolled; }
$countWhereSQL = implode(' AND ', $countWhere);

$yearCountsRaw = $db->fetchAll("
    SELECT s.yearLevel, COUNT(*) as c
    FROM students s
    JOIN programs p ON p.programID=s.programID
    WHERE $countWhereSQL
    GROUP BY s.yearLevel
", $countParams);
$yearCounts = [1=>0, 2=>0, 3=>0, 4=>0, 5=>0];
foreach ($yearCountsRaw as $row) {
    if (isset($yearCounts[(int)$row['yearLevel']])) {
        $yearCounts[(int)$row['yearLevel']] = (int)$row['c'];
    }
}
$allCount = array_sum($yearCounts);

// Counts per program for the Course tab badges (respects search/section/year/enrolled filters, ignores program itself)
$progCountWhere  = ['s.dateDeleted IS NULL'];
$progCountParams = [];
if ($search) {
    $progCountWhere[] = "(s.studentNo LIKE :srch_no OR s.fName LIKE :srch_fn OR s.mName LIKE :srch_mn OR s.lName LIKE :srch_ln OR CONCAT(s.fName, ' ', COALESCE(s.mName, ''), ' ', s.lName) LIKE :srch_full OR CONCAT(s.lName, ', ', s.fName) LIKE :srch_full_lname OR s.email LIKE :srch_em)";
    $progCountParams[':srch_no'] = "%$search%";
    $progCountParams[':srch_fn'] = "%$search%";
    $progCountParams[':srch_mn'] = "%$search%";
    $progCountParams[':srch_ln'] = "%$search%";
    $progCountParams[':srch_full'] = "%$search%";
    $progCountParams[':srch_full_lname'] = "%$search%";
    $progCountParams[':srch_em'] = "%$search%";
}
if ($yearLvl !== '') { $progCountWhere[] = "s.yearLevel=:yl"; $progCountParams[':yl'] = $yearLvl; }
if ($sectionID) {
    $progCountWhere[] = "COALESCE(s.sectionID, (SELECT e_sec.sectionID FROM enrollments e_sec WHERE e_sec.studentID=s.studentID AND e_sec.status='Active' AND e_sec.dateDeleted IS NULL ORDER BY e_sec.enrollmentID DESC LIMIT 1)) = :sec";
    $progCountParams[':sec'] = $sectionID;
}
if ($enrolled !== '') { $progCountWhere[] = "s.isEnroll=:en"; $progCountParams[':en'] = (int)$enrolled; }
$progCountWhereSQL = implode(' AND ', $progCountWhere);

$progCountsRaw = $db->fetchAll("
    SELECT s.programID, COUNT(*) as c
    FROM students s
    WHERE $progCountWhereSQL
    GROUP BY s.programID
", $progCountParams);
$progCounts = [];
foreach ($progCountsRaw as $row) {
    $progCounts[(int)$row['programID']] = (int)$row['c'];
}
$allProgCount = array_sum($progCounts);

$duplicateStudentNos = [];
$studentNosSeen = [];
foreach ($students as $s) {
    if (isset($studentNosSeen[$s['studentNo']])) {
        $duplicateStudentNos[$s['studentNo']] = true;
    } else {
        $studentNosSeen[$s['studentNo']] = true;
    }
}
$duplicateWarning = $duplicateStudentNos
    ? 'Warning: duplicate student number(s) found in the result: ' . implode(', ', array_keys($duplicateStudentNos)) . '. Please review the student records.'
    : '';

$defaultStudentNoYear = studentNumberYearFromAcademicYear($activeAcademicYear['academicYear'] ?? '') ?? (int)date('Y');
$studentNoByAcademicYear = [$defaultStudentNoYear => generateNextStudentNo($db, $defaultStudentNoYear)];
foreach ($sections as $section) {
  $year = studentNumberYearFromAcademicYear($section['academicYear'] ?? '');
  if ($year !== null && !isset($studentNoByAcademicYear[$year])) {
    $studentNoByAcademicYear[$year] = generateNextStudentNo($db, $year);
  }
}
$nextStudentNo = $studentNoByAcademicYear[$defaultStudentNoYear];

$pageTitle  = 'Student Management';
$activeMenu = 'students.php';
require_once ROOT . '/includes/header.php';
?>

<!-- Actions Bar -->
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px;">
  <div style="font-size:14px;color:var(--text-muted);">
    Showing <strong><?= number_format(count($students)) ?></strong> of <strong><?= number_format($total) ?></strong> students
  </div>

  <?php if ($duplicateWarning): ?>
  <div style="width:100%;padding:12px 16px;background:#fef3c7;border:1px solid #fcd34d;border-radius:12px;color:#92400e;font-size:13px;">
    <i class="fas fa-exclamation-triangle" style="margin-right:8px;"></i>
    <?= htmlspecialchars($duplicateWarning) ?>
  </div>
  <?php endif; ?>

  <div style="display:flex;gap:10px;align-items:center;">

    <!-- Export CSV Button -->
    <button class="btn btn-outline btn-sm" onclick="exportCSV()" id="exportBtn"
            style="display:flex;align-items:center;gap:6px;">
      <i class="fas fa-download"></i> Export CSV
    </button>

    <button class="btn btn-outline btn-sm" type="button" onclick="openStudentImportModal()"
            style="display:flex;align-items:center;gap:6px;">
      <i class="fas fa-file-import"></i> Import Students
    </button>

    <!-- Add Student Split Button -->
    <div style="position:relative;">
      <div style="display:flex;border-radius:10px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08);">
        <button class="btn btn-primary" onclick="openAddStudent()"
                style="border-radius:10px 0 0 10px;border-right:1px solid rgba(255,255,255,.25);padding:8px 16px;display:flex;align-items:center;gap:6px;">
          <i class="fas fa-user-plus"></i> Add New Student
        </button>
        <button id="addDropToggle" class="btn btn-primary" onclick="toggleAddDropdown()"
                style="border-radius:0 10px 10px 0;padding:8px 12px;">
          <i class="fas fa-chevron-down" style="font-size:11px;"></i>
        </button>
      </div>
      <!-- Dropdown Menu -->
      <div id="addDropMenu" style="display:none;position:absolute;right:0;top:calc(100% + 6px);background:#fff;border:1px solid var(--border);border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.12);z-index:200;min-width:190px;overflow:hidden;">
        <button onclick="openAddStudent(); closeAddDropdown();"
                style="display:flex;align-items:center;gap:10px;width:100%;padding:12px 16px;background:none;border:none;cursor:pointer;font-size:13px;color:var(--text);text-align:left;"
                onmouseover="this.style.background='var(--bg)'" onmouseout="this.style.background='none'">
          <i class="fas fa-user-plus" style="color:var(--green);width:16px;"></i>
          Add New Student
        </button>
        <div style="height:1px;background:var(--border);margin:0 12px;"></div>
        <button onclick="openAddOldStudent(); closeAddDropdown();"
                style="display:flex;align-items:center;gap:10px;width:100%;padding:12px 16px;background:none;border:none;cursor:pointer;font-size:13px;color:var(--text);text-align:left;"
                onmouseover="this.style.background='var(--bg)'" onmouseout="this.style.background='none'">
          <i class="fas fa-user-clock" style="color:var(--blue);width:16px;"></i>
          Add Old Student
        </button>
      </div>
    </div>

  </div>
</div>

<!-- ================================================================ -->
<!-- COURSE / PROGRAM TAB NAV                                          -->
<!-- Same idea as the Subjects page: Course is its own nav bar instead  -->
<!-- of a dropdown. Real links (not JS show/hide) since the table below -->
<!-- is paginated and server-filtered — switching courses needs a       -->
<!-- fresh query. Each link preserves the other active filters (search, -->
<!-- section, year level, enrollment status) and resets back to page 1. -->
<!-- Badge counts respect those other filters but ignore the course     -->
<!-- itself, so they show how many students each course WOULD have if   -->
<!-- you switched to it, given what's currently filtered.               -->
<!-- ================================================================ -->
<?php
  $courseTabs = ['' => ['label' => 'All Programs', 'count' => $allProgCount]];
  foreach ($programs as $p) {
      $courseTabs[$p['programID']] = ['label' => $p['programCode'], 'count' => $progCounts[$p['programID']] ?? 0];
  }
?>
<div class="course-tabs" style="display:flex;gap:28px;flex-wrap:wrap;border-bottom:1px solid #e5e7eb;margin-bottom:16px;">
  <?php foreach ($courseTabs as $key => $tab):
      $isActive = ($progID === 0 && $key === '') || ($progID !== 0 && $key !== '' && (int)$key === $progID);
      $tabParams = $_GET;
      unset($tabParams['page']);
      if ($key === '') { unset($tabParams['program_id']); } else { $tabParams['program_id'] = $key; }
      $tabUrl = 'students.php' . (!empty($tabParams) ? '?' . http_build_query($tabParams) : '');
  ?>
    <a href="<?= htmlspecialchars($tabUrl) ?>"
       style="text-decoration:none;padding:10px 2px 12px;font-weight:700;font-size:14px;
              display:flex;align-items:center;gap:8px;
              color:<?= $isActive ? 'var(--text)' : 'var(--text-muted)' ?>;
              border-bottom:3px solid <?= $isActive ? 'var(--blue)' : 'transparent' ?>;margin-bottom:-1px;">
      <i class="fas fa-graduation-cap" style="color:<?= $isActive ? 'var(--blue)' : 'var(--text-muted)' ?>;"></i>
      <?= htmlspecialchars($tab['label']) ?>
      <span style="background:#f1f5f9;color:#334155;padding:2px 8px;border-radius:10px;font-size:12px;font-weight:700;">
        <?= number_format($tab['count']) ?>
      </span>
    </a>
  <?php endforeach; ?>
</div>

<!-- ================================================================ -->
<!-- YEAR LEVEL TAB NAV                                                -->
<!-- Nested one level below Course: same nav-bar pattern, and its      -->
<!-- counts already respect whichever Course tab is currently active   -->
<!-- (progID is part of the query these counts run against), so this   -->
<!-- bar always reflects "years within the selected course."           -->
<!-- ================================================================ -->
<?php
  $yearTabs = [
    ''  => ['label' => 'All Years', 'count' => $allCount],
    1   => ['label' => '1st Year',  'count' => $yearCounts[1]],
    2   => ['label' => '2nd Year',  'count' => $yearCounts[2]],
    3   => ['label' => '3rd Year',  'count' => $yearCounts[3]],
    4   => ['label' => '4th Year',  'count' => $yearCounts[4]],
    5   => ['label' => 'Irregular', 'count' => $yearCounts[5]],
  ];
?>
<div class="year-tabs" style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px;margin-left:6px;">
  <?php foreach ($yearTabs as $key => $tab):
      $isActive = ($yearLvl === '' && $key === '') || ($yearLvl !== '' && $yearLvl === $key);
      $tabParams = $_GET;
      unset($tabParams['page']);
      if ($key === '') { unset($tabParams['year_level']); } else { $tabParams['year_level'] = $key; }
      $tabUrl = 'students.php' . (!empty($tabParams) ? '?' . http_build_query($tabParams) : '');
  ?>
    <a href="<?= htmlspecialchars($tabUrl) ?>"
       style="text-decoration:none;padding:6px 13px;border-radius:20px;font-weight:600;font-size:12px;
              display:flex;align-items:center;gap:6px;border:1px solid <?= $isActive ? 'var(--blue)' : '#e5e7eb' ?>;
              background:<?= $isActive ? 'var(--blue)' : '#fff' ?>;color:<?= $isActive ? '#fff' : 'var(--text-muted)' ?>;">
      <i class="fas fa-layer-group"></i>
      <?= htmlspecialchars($tab['label']) ?>
      <span style="background:<?= $isActive ? 'rgba(255,255,255,.25)' : '#f1f5f9' ?>;color:<?= $isActive ? '#fff' : '#334155' ?>;padding:1px 6px;border-radius:10px;font-size:11px;">
        <?= number_format($tab['count']) ?>
      </span>
    </a>
  <?php endforeach; ?>
</div>

<!-- ================================================================ -->
<!-- FILTERS                                                           -->
<!-- ================================================================ -->
<div class="card" style="margin-bottom:20px;">
  <div class="card-body">
    <form method="GET">
      <!--
        Course and Year Level now live in the tab navs above, not in
        this form — but their current values still have to ride along
        whenever one of the dropdowns below auto-submits, or they'd
        silently reset to "All Programs" / "All Years". These hidden
        fields carry them forward.
      -->
      <input type="hidden" name="program_id"  value="<?= $progID ?: '' ?>"/>
      <input type="hidden" name="year_level" value="<?= $yearLvl === '' ? '' : $yearLvl ?>"/>

      <!-- Row 1: Search + Section -->
      <div style="display:grid;grid-template-columns:2fr 1fr;gap:12px;margin-bottom:12px;">
        <div>
          <label class="form-label">Search</label>
          <div style="position:relative;">
            <i class="fas fa-search" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--text-muted);"></i>
            <input type="text" name="search" class="form-control"
                   placeholder="Name, student number, email…"
                   value="<?= htmlspecialchars($search) ?>"
                   style="padding-left:36px;"/>
          </div>
        </div>
        <div>
          <label class="form-label">Section</label>
          <select name="section_id" class="form-control">
            <option value="">All Sections</option>
            <?php foreach ($sections as $sec): ?>
            <option value="<?= $sec['sectionID'] ?>" <?= $sectionID == $sec['sectionID'] ? 'selected' : '' ?>>
              <?= htmlspecialchars(studentSectionLabel($sec['sectionName'], $sec['programCode'] ?? '') . ' (' . $sec['academicYear'] . ')') ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <!-- Row 2: Enrollment Status + Buttons -->
      <div style="display:grid;grid-template-columns:1fr auto;gap:12px;align-items:end;">
        <div>
          <label class="form-label">Enrollment Status</label>
          <select name="enrolled" class="form-control">
            <option value="">All</option>
            <option value="1" <?= $enrolled === '1' ? 'selected' : '' ?>>Enrolled</option>
            <option value="0" <?= $enrolled === '0' ? 'selected' : '' ?>>Not Enrolled</option>
          </select>
        </div>
        <div style="display:flex;gap:8px;">
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-filter"></i> Filter
          </button>
          <a href="students.php" class="btn btn-outline" title="Clear filters">
            <i class="fas fa-times"></i>
          </a>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- ================================================================ -->
<!-- STUDENTS TABLE                                                    -->
<!-- ================================================================ -->
<div class="card">
  <div style="overflow-x:auto;">
    <table class="data-table">
      <thead>
        <tr>
          <th><input type="checkbox" id="checkAll" onchange="toggleAll(this)"/></th>
          <th>Student No.</th>
          <th>Full Name</th>
          <th>Program</th>
          <th>Section</th>
          <th>Year</th>
          <th>Status</th>
          <th>Email</th>
          <th style="text-align:center;">Enrolled</th>
          <th style="text-align:center;">Active</th>
          <th style="text-align:center;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($students)): ?>
        <tr>
          <td colspan="11" style="text-align:center;padding:40px;color:var(--text-muted);">
            No students found matching your criteria.
          </td>
        </tr>
        <?php else: ?>
        <?php $currentSection = null; ?>
        <?php foreach ($students as $s): ?>
        <?php $displaySection = studentSectionLabel($s['sectionName'], $s['programCode']); ?>
        <?php if ($displaySection !== $currentSection): $currentSection = $displaySection; ?>
        <tr>
          <td colspan="11" style="background:var(--bg);padding:10px 16px;border-top:2px solid var(--border);">
            <div style="display:flex;align-items:center;gap:8px;font-weight:700;font-size:13px;color:var(--text);">
              <i class="fas fa-users" style="color:var(--blue);"></i>
              <?= $currentSection ? htmlspecialchars($currentSection) : 'No Section Assigned' ?>
            </div>
          </td>
        </tr>
        <?php endif; ?>
        <tr>
          <td><input type="checkbox" class="row-check" value="<?= $s['studentID'] ?>"/></td>
          <td style="font-weight:600;font-size:13px;"><?= htmlspecialchars($s['studentNo']) ?></td>
          <td>
            <div style="font-weight:600;font-size:13px;">
              <?= htmlspecialchars(
                    trim("{$s['lName']}, {$s['fName']} "
                      . ($s['mName'] ? substr($s['mName'],0,1).'.' : '')
                      . ($s['extName'] ? ' '.$s['extName'] : ''))
                  ) ?>
            </div>
            <div style="font-size:11px;color:var(--text-muted);"><?= htmlspecialchars($s['sex']) ?></div>
          </td>
          <td>
            <span style="font-weight:600;color:var(--green);"><?= htmlspecialchars($s['programCode']) ?></span><br/>
            <span style="font-size:11px;color:var(--text-muted);"><?= htmlspecialchars($s['programName']) ?></span>
          </td>
          <td style="text-align:center;">
            <?php if ($s['sectionName']): ?>
              <?= htmlspecialchars(studentSectionLabel($s['sectionName'], $s['programCode'])) ?>
            <?php else: ?>
              <span style="color:var(--text-muted);font-size:12px;">—</span>
            <?php endif; ?>
          </td>
          <td style="text-align:center;">
            <span style="font-weight:700;">Year <?= (int)$s['yearLevel'] ?></span>
          </td>
          <td style="text-align:center;">
            <?php
              $isIrreg = isStudentIrregular($s['studentID']);
              $hasBack = hasBackSubjects($s['studentID']);
              $badges = '';
              
              if ($isIrreg) {
                $badges .= '<span class="status-badge" style="background:#FF9800;color:#fff;margin-right:4px;font-size:11px;font-weight:600;">IRREGULAR</span>';
              }
              if ($hasBack) {
                $badges .= '<span class="status-badge status-flagged" style="font-size:11px;font-weight:600;">HAS BACK</span>';
              }
              
              if ($badges) {
                echo $badges;
              } else {
                echo '<span style="color:var(--text-muted);font-size:12px;">—</span>';
              }
            ?>
          </td>
          <td style="font-size:13px;"><?= htmlspecialchars($s['email']) ?></td>
          <td style="text-align:center;">
            <?= $s['isEnroll']
                ? '<span class="status-badge status-approved">Yes</span>'
                : '<span class="status-badge" style="background:var(--border);color:var(--text-muted);">No</span>' ?>
          </td>
          <td style="text-align:center;">
            <?= $s['isActive']
                ? '<span class="status-badge status-approved">Active</span>'
                : '<span class="status-badge status-flagged">Inactive</span>' ?>
          </td>
          <td style="text-align:center;">
            <div style="display:flex;gap:6px;justify-content:center;">
              <a href="student_profile.php?id=<?= $s['studentID'] ?>"
                 class="btn btn-outline btn-sm" title="View Profile">
                <i class="fas fa-eye"></i>
              </a>
              <button class="btn btn-outline btn-sm"
                      onclick="editStudent(<?= $s['studentID'] ?>)" title="Edit">
                <i class="fas fa-pen"></i>
              </button>
              <button class="btn btn-sm"
                      style="background:#F59E0B;border-color:#F59E0B;color:#fff;"
                      onclick="toggleActive(<?= $s['studentID'] ?>, <?= (int)$s['isActive'] ?>)"
                      title="<?= $s['isActive'] ? 'Deactivate' : 'Activate' ?>">
                <i class="fas fa-<?= $s['isActive'] ? 'user-slash' : 'user-check' ?>"></i>
              </button>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Pagination -->
  <?php if ($pages > 1): ?>
  <div style="padding:16px 24px;border-top:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;">
    <div style="font-size:13px;color:var(--text-muted);">
      Page <?= $page ?> of <?= $pages ?>
    </div>
    <div style="display:flex;gap:6px;">
      <?php if ($page > 1): ?>
      <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page-1])) ?>"
         class="btn btn-outline btn-sm">
        <i class="fas fa-chevron-left"></i> Prev
      </a>
      <?php endif; ?>
      <?php for ($p = max(1,$page-2); $p <= min($pages,$page+2); $p++): ?>
      <a href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>"
         class="btn btn-sm <?= $p == $page ? 'btn-primary' : 'btn-outline' ?>"><?= $p ?></a>
      <?php endfor; ?>
      <?php if ($page < $pages): ?>
      <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page+1])) ?>"
         class="btn btn-outline btn-sm">
        Next <i class="fas fa-chevron-right"></i>
      </a>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- ================================================================ -->
<!-- ADD NEW STUDENT MODAL — Auto-generated Student No.               -->
<!-- ================================================================ -->
<div id="addModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:999;align-items:center;justify-content:center;overflow-y:auto;padding:24px;">
  <div style="background:#fff;border-radius:16px;padding:32px;width:640px;max-width:100%;box-shadow:0 20px 60px rgba(0,0,0,.2);">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
      <h3 style="font-family:'Source Sans 3', sans-serif;font-size:20px;">Add New Student</h3>
      <button onclick="closeAddStudent()" style="background:none;border:none;cursor:pointer;font-size:18px;color:var(--text-muted);">
        <i class="fas fa-times"></i>
      </button>
    </div>
    <p style="font-size:12px;color:var(--text-muted);margin-bottom:20px;display:flex;align-items:center;gap:6px;">
      <i class="fas fa-info-circle" style="color:var(--green);"></i>
      Student number is <strong>auto-generated</strong> using the selected section's academic year; without a section, the active academic year is used.
    </p>
    <form id="addStudentForm" onsubmit="submitAddStudent(event)">
      <input type="hidden" name="autoGenerateStudentNo" value="1"/>
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-bottom:16px;">
        <div>
          <label class="form-label">First Name <span style="color:var(--danger)">*</span></label>
          <input name="fName" class="form-control" required placeholder="First name"
            pattern="[A-Za-zÀ-ÖØ-öø-ÿ\s\-\.]+"
            title="Letters only — no numbers or special characters"
            oninput="this.value=this.value.replace(/[^A-Za-zÀ-ÖØ-öø-ÿ\s\-\.]/g,'')"/>
        </div>
        <div>
          <label class="form-label">Middle Name</label>
          <input name="mName" class="form-control" placeholder="Middle name"
            pattern="[A-Za-zÀ-ÖØ-öø-ÿ\s\-\.]*"
            title="Letters only — no numbers or special characters"
            oninput="this.value=this.value.replace(/[^A-Za-zÀ-ÖØ-öø-ÿ\s\-\.]/g,'')"/>
        </div>
        <div>
          <label class="form-label">Last Name <span style="color:var(--danger)">*</span></label>
          <input name="lName" class="form-control" required placeholder="Last name"
            pattern="[A-Za-zÀ-ÖØ-öø-ÿ\s\-\.]+"
            title="Letters only — no numbers or special characters"
            oninput="this.value=this.value.replace(/[^A-Za-zÀ-ÖØ-öø-ÿ\s\-\.]/g,'')"/>
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-bottom:16px;">
        <div>
          <label class="form-label">Extension <span style="font-size:11px;color:var(--text-muted);">(Jr., Sr., III)</span></label>
          <input name="extName" class="form-control" placeholder="Jr., Sr., III"
            pattern="[A-Za-z\s\.\-IVX]*"
            title="Letters only (e.g. Jr., Sr., III)"
            oninput="this.value=this.value.replace(/[^A-Za-z\s\.\-IVX]/g,'')"/>
        </div>
        <div>
          <label class="form-label">Date of Birth <span style="color:var(--danger)">*</span></label>
          <input name="dateofBirth" type="date" class="form-control" required/>
        </div>
        <div>
          <label class="form-label">Sex <span style="color:var(--danger)">*</span></label>
          <select name="sex" class="form-control" required>
            <option value="">— Select —</option>
            <option>Male</option>
            <option>Female</option>
          </select>
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
        <div>
          <label class="form-label">
            Student No. <span style="color:var(--danger)">*</span>
            <span style="font-size:11px;color:var(--text-muted);">(Auto-generated)</span>
          </label>
          <div style="position:relative;">
            <input name="studentNo" id="add_studentNo" class="form-control" required
                   placeholder="Auto-generated" readonly
                   style="background:var(--bg);padding-right:36px;"/>
            <i class="fas fa-lock" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:12px;"></i>
          </div>
        </div>
        <div>
          <label class="form-label">Program <span style="color:var(--danger)">*</span></label>
          <select name="programID" class="form-control" required onchange="filterSections(this.value, 'add')">
            <option value="">— Select Program —</option>
            <?php foreach ($programs as $p): ?>
            <option value="<?= $p['programID'] ?>"><?= htmlspecialchars("{$p['programCode']} – {$p['programName']}") ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-bottom:16px;">
        <div>
          <label class="form-label">Section</label>
          <select name="sectionID" id="add_sectionID" class="form-control">
            <option value="">— Select Section —</option>
            <?php foreach ($sections as $sec): ?>
            <option value="<?= $sec['sectionID'] ?>" data-program="<?= $sec['programID'] ?>" data-academic-year-start="<?= studentNumberYearFromAcademicYear($sec['academicYear'] ?? '') ?? '' ?>">
              <?= htmlspecialchars(studentSectionLabel($sec['sectionName'], $sec['programCode'] ?? '') . ' (' . $sec['academicYear'] . ')') ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="form-label">Year Level <span style="color:var(--danger)">*</span></label>
          <select name="yearLevel" class="form-control" required>
            <option value="1">1st Year</option>
            <option value="2">2nd Year</option>
            <option value="3">3rd Year</option>
            <option value="4">4th Year</option>
            <option value="5">Irregular</option>
          </select>
        </div>
        <div>
          <label class="form-label">Email Address <span style="color:var(--danger)">*</span></label>
          <input name="email" type="email" class="form-control" required placeholder="student@neust.edu.ph"/>
        </div>
      </div>
      <div style="margin-bottom:16px;">
        <label class="form-label">Initial Password <span style="color:var(--danger)">*</span></label>
        <input name="password" type="text" class="form-control" required placeholder="Temporary password"/>
      </div>
      <div style="display:flex;gap:10px;justify-content:flex-end;">
        <button type="button" class="btn btn-outline" onclick="closeAddStudent()">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Student</button>
      </div>
    </form>
  </div>
</div>

<!-- ================================================================ -->
<!-- ADD OLD STUDENT MODAL — Manual Student No.                        -->
<!-- ================================================================ -->
<div id="addOldModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:999;align-items:center;justify-content:center;overflow-y:auto;padding:24px;">
  <div style="background:#fff;border-radius:16px;padding:32px;width:640px;max-width:100%;box-shadow:0 20px 60px rgba(0,0,0,.2);">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
      <h3 style="font-family:'Source Sans 3', sans-serif;font-size:20px;">Add Old Student</h3>
      <button onclick="closeAddOldStudent()" style="background:none;border:none;cursor:pointer;font-size:18px;color:var(--text-muted);">
        <i class="fas fa-times"></i>
      </button>
    </div>
    <p style="font-size:12px;color:var(--text-muted);margin-bottom:20px;display:flex;align-items:center;gap:6px;">
      <i class="fas fa-exclamation-circle" style="color:var(--blue);"></i>
      For returning or transferee students. Enter the student's <strong>existing student number</strong> manually.
    </p>
    <form id="addOldStudentForm" onsubmit="submitAddOldStudent(event)">
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-bottom:16px;">
        <div>
          <label class="form-label">First Name <span style="color:var(--danger)">*</span></label>
          <input name="fName" class="form-control" required placeholder="First name"
            pattern="[A-Za-zÀ-ÖØ-öø-ÿ\s\-\.]+"
            title="Letters only — no numbers or special characters"
            oninput="this.value=this.value.replace(/[^A-Za-zÀ-ÖØ-öø-ÿ\s\-\.]/g,'')"/>
        </div>
        <div>
          <label class="form-label">Middle Name</label>
          <input name="mName" class="form-control" placeholder="Middle name"
            pattern="[A-Za-zÀ-ÖØ-öø-ÿ\s\-\.]*"
            title="Letters only — no numbers or special characters"
            oninput="this.value=this.value.replace(/[^A-Za-zÀ-ÖØ-öø-ÿ\s\-\.]/g,'')"/>
        </div>
        <div>
          <label class="form-label">Last Name <span style="color:var(--danger)">*</span></label>
          <input name="lName" class="form-control" required placeholder="Last name"
            pattern="[A-Za-zÀ-ÖØ-öø-ÿ\s\-\.]+"
            title="Letters only — no numbers or special characters"
            oninput="this.value=this.value.replace(/[^A-Za-zÀ-ÖØ-öø-ÿ\s\-\.]/g,'')"/>
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-bottom:16px;">
        <div>
          <label class="form-label">Extension <span style="font-size:11px;color:var(--text-muted);">(Jr., Sr., III)</span></label>
          <input name="extName" class="form-control" placeholder="Jr., Sr., III"
            pattern="[A-Za-z\s\.\-IVX]*"
            title="Letters only (e.g. Jr., Sr., III)"
            oninput="this.value=this.value.replace(/[^A-Za-z\s\.\-IVX]/g,'')"/>
        </div>
        <div>
          <label class="form-label">Date of Birth <span style="color:var(--danger)">*</span></label>
          <input name="dateofBirth" type="date" class="form-control" required/>
        </div>
        <div>
          <label class="form-label">Sex <span style="color:var(--danger)">*</span></label>
          <select name="sex" class="form-control" required>
            <option value="">— Select —</option>
            <option>Male</option>
            <option>Female</option>
          </select>
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
        <div>
          <label class="form-label">
            Student No. <span style="color:var(--danger)">*</span>
            <span style="font-size:11px;color:var(--blue);font-weight:600;">✎ Manual Entry</span>
          </label>
          <div style="position:relative;">
            <input name="studentNo" id="old_studentNo" class="form-control" required
                   placeholder="e.g. 2021-00123"
                   style="border-color:var(--blue);padding-right:36px;"
                   oninput="this.value=this.value.replace(/[^0-9\-]/g,'')"/>
            <i class="fas fa-pen" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);color:var(--blue);font-size:12px;"></i>
          </div>
          <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">Format: YYYY-NNNNN &nbsp;(e.g. 2021-00123)</div>
        </div>
        <div>
          <label class="form-label">Program <span style="color:var(--danger)">*</span></label>
          <select name="programID" class="form-control" required onchange="filterSections(this.value, 'old')">
            <option value="">— Select Program —</option>
            <?php foreach ($programs as $p): ?>
            <option value="<?= $p['programID'] ?>"><?= htmlspecialchars("{$p['programCode']} – {$p['programName']}") ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-bottom:16px;">
        <div>
          <label class="form-label">Section</label>
          <select name="sectionID" id="old_sectionID" class="form-control">
            <option value="">— Select Section —</option>
            <?php foreach ($sections as $sec): ?>
            <option value="<?= $sec['sectionID'] ?>" data-program="<?= $sec['programID'] ?>">
              <?= htmlspecialchars(studentSectionLabel($sec['sectionName'], $sec['programCode'] ?? '') . ' (' . $sec['academicYear'] . ')') ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="form-label">Year Level <span style="color:var(--danger)">*</span></label>
          <select name="yearLevel" class="form-control" required>
            <option value="1">1st Year</option>
            <option value="2">2nd Year</option>
            <option value="3">3rd Year</option>
            <option value="4">4th Year</option>
            <option value="5">Irregular</option>
          </select>
        </div>
        <div>
          <label class="form-label">Email Address <span style="color:var(--danger)">*</span></label>
          <input name="email" type="email" class="form-control" required placeholder="student@neust.edu.ph"/>
        </div>
      </div>
      <div style="margin-bottom:16px;">
        <label class="form-label">Initial Password <span style="color:var(--danger)">*</span></label>
        <input name="password" type="text" class="form-control" required placeholder="Temporary password"/>
      </div>
      <div style="display:flex;gap:10px;justify-content:flex-end;">
        <button type="button" class="btn btn-outline" onclick="closeAddOldStudent()">Cancel</button>
        <button type="submit" class="btn btn-primary" style="background:var(--blue);border-color:var(--blue);">
          <i class="fas fa-save"></i> Save Old Student
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ================================================================ -->
<!-- EDIT STUDENT MODAL                                                -->
<!-- ================================================================ -->
<div id="editModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:999;align-items:center;justify-content:center;overflow-y:auto;padding:24px;">
  <div style="background:#fff;border-radius:16px;padding:32px;width:640px;max-width:100%;box-shadow:0 20px 60px rgba(0,0,0,.2);">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;">
      <h3 style="font-family:'Source Sans 3', sans-serif;font-size:20px;">Edit Student</h3>
      <button onclick="closeEditStudent()" style="background:none;border:none;cursor:pointer;font-size:18px;color:var(--text-muted);">
        <i class="fas fa-times"></i>
      </button>
    </div>
    <form id="editStudentForm" onsubmit="submitEditStudent(event)">
      <input type="hidden" name="studentID" id="edit_studentID"/>
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-bottom:16px;">
        <div>
          <label class="form-label">First Name <span style="color:var(--danger)">*</span></label>
          <input name="fName" id="edit_fName" class="form-control" required placeholder="First name"
            pattern="[A-Za-zÀ-ÖØ-öø-ÿ\s\-\.]+"
            title="Letters only — no numbers or special characters"
            oninput="this.value=this.value.replace(/[^A-Za-zÀ-ÖØ-öø-ÿ\s\-\.]/g,'')"/>
        </div>
        <div>
          <label class="form-label">Middle Name</label>
          <input name="mName" id="edit_mName" class="form-control" placeholder="Middle name"
            pattern="[A-Za-zÀ-ÖØ-öø-ÿ\s\-\.]*"
            title="Letters only — no numbers or special characters"
            oninput="this.value=this.value.replace(/[^A-Za-zÀ-ÖØ-öø-ÿ\s\-\.]/g,'')"/>
        </div>
        <div>
          <label class="form-label">Last Name <span style="color:var(--danger)">*</span></label>
          <input name="lName" id="edit_lName" class="form-control" required placeholder="Last name"
            pattern="[A-Za-zÀ-ÖØ-öø-ÿ\s\-\.]+"
            title="Letters only — no numbers or special characters"
            oninput="this.value=this.value.replace(/[^A-Za-zÀ-ÖØ-öø-ÿ\s\-\.]/g,'')"/>
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-bottom:16px;">
        <div>
          <label class="form-label">Extension <span style="font-size:11px;color:var(--text-muted);">(Jr., Sr., III)</span></label>
          <input name="extName" id="edit_extName" class="form-control" placeholder="Jr., Sr., III"
            pattern="[A-Za-z\s\.\-IVX]*"
            title="Letters only (e.g. Jr., Sr., III)"
            oninput="this.value=this.value.replace(/[^A-Za-z\s\.\-IVX]/g,'')"/>
        </div>
        <div>
          <label class="form-label">Student No. <span style="color:var(--danger)">*</span></label>
          <input name="studentNo" id="edit_studentNo" class="form-control" required
                 placeholder="2024-00001" readonly style="background:var(--bg);"/>
        </div>
        <div>
          <label class="form-label">Date of Birth</label>
          <input name="dateofBirth" id="edit_dateofBirth" type="date" class="form-control"/>
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-bottom:16px;">
        <div>
          <label class="form-label">Sex</label>
          <select name="sex" id="edit_sex" class="form-control">
            <option value="">— Select —</option>
            <option>Male</option>
            <option>Female</option>
          </select>
        </div>
        <div>
          <label class="form-label">Program <span style="color:var(--danger)">*</span></label>
          <select name="programID" id="edit_programID" class="form-control" required
                  onchange="filterSections(this.value, 'edit')">
            <option value="">— Select Program —</option>
            <?php foreach ($programs as $p): ?>
            <option value="<?= $p['programID'] ?>"><?= htmlspecialchars("{$p['programCode']} – {$p['programName']}") ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="form-label">Section</label>
          <select name="sectionID" id="edit_sectionID" class="form-control">
            <option value="">— Select Section —</option>
            <?php foreach ($sections as $sec): ?>
            <option value="<?= $sec['sectionID'] ?>" data-program="<?= $sec['programID'] ?>">
              <?= htmlspecialchars(studentSectionLabel($sec['sectionName'], $sec['programCode'] ?? '') . ' (' . $sec['academicYear'] . ')') ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;">
        <div>
          <label class="form-label">Year Level</label>
          <select name="yearLevel" id="edit_yearLevel" class="form-control">
            <option value="1">1st Year</option>
            <option value="2">2nd Year</option>
            <option value="3">3rd Year</option>
            <option value="4">4th Year</option>
            <option value="5">Irregular</option>
          </select>
        </div>
        <div>
          <label class="form-label">Email Address <span style="color:var(--danger)">*</span></label>
          <input name="email" id="edit_email" type="email" class="form-control" required
                 placeholder="student@neust.edu.ph"/>
        </div>
      </div>
      <div style="display:flex;gap:24px;margin-bottom:24px;">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
          <input type="checkbox" name="isEnroll" id="edit_isEnroll"/> Enrolled
        </label>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
          <input type="checkbox" name="isActive" id="edit_isActive"/> Active Account
        </label>
      </div>
      <div style="display:flex;gap:10px;justify-content:flex-end;">
        <button type="button" class="btn btn-outline" onclick="closeEditStudent()">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update Student</button>
      </div>
    </form>
  </div>
</div>

<!-- ================================================================ -->
<!-- EXPORT CSV PROGRESS OVERLAY                                       -->
<!-- ================================================================ -->
<div id="exportOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:16px;padding:36px 40px;width:340px;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.25);">
    <div style="width:60px;height:60px;border-radius:50%;background:linear-gradient(135deg,var(--green),#1abc9c);display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
      <i class="fas fa-file-csv" style="color:#fff;font-size:26px;"></i>
    </div>
    <h4 style="font-family:'Source Sans 3', sans-serif;font-size:18px;margin-bottom:6px;">Exporting CSV</h4>
    <p style="font-size:13px;color:var(--text-muted);margin-bottom:20px;">Preparing your student data…</p>
    <div style="background:var(--bg);border-radius:99px;height:8px;overflow:hidden;margin-bottom:10px;">
      <div id="exportProgressBar" style="height:100%;width:0%;background:linear-gradient(90deg,var(--green),#1abc9c);border-radius:99px;transition:width .25s ease;"></div>
    </div>
    <div id="exportProgressLabel" style="font-size:12px;color:var(--text-muted);font-weight:600;">0%</div>
  </div>
</div>

<!-- no hidden form needed — export uses fetch + blob -->

<?php require ROOT . '/includes/student_import_modal.php'; ?>

<script>
const nextStudentNo = "<?= htmlspecialchars($nextStudentNo, ENT_QUOTES) ?>";
const defaultStudentNoYear = <?= (int)$defaultStudentNoYear ?>;
const studentNoByAcademicYear = <?= json_encode($studentNoByAcademicYear, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

/* ── Add New Student ─────────────────────────────────────── */
function openAddStudent() {
  document.getElementById('addStudentForm').reset();
  updateStudentNoPreview();
  document.getElementById('addModal').style.display = 'flex';
}
function closeAddStudent() { document.getElementById('addModal').style.display = 'none'; }

function updateStudentNoPreview() {
  const sectionSelect = document.getElementById('add_sectionID');
  const selectedYear = sectionSelect.selectedOptions[0]?.dataset.academicYearStart;
  document.getElementById('add_studentNo').value = studentNoByAcademicYear[selectedYear] || studentNoByAcademicYear[defaultStudentNoYear] || nextStudentNo;
}

document.getElementById('add_sectionID').addEventListener('change', updateStudentNoPreview);

function submitAddStudent(e) {
  e.preventDefault();
  const fd   = new FormData(e.target);
  const data = Object.fromEntries(fd.entries());
  data.action = 'add_student';

  fetch('<?= BASE_URL ?>api/students.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(data)
  })
  .then(r => r.json())
  .then(d => {
    showToast(d.message, d.success ? 'success' : 'error');
    if (d.success) { closeAddStudent(); setTimeout(() => location.reload(), 1200); }
  })
  .catch(() => showToast('Network error. Please try again.', 'error'));
}

/* ── Add Old Student ─────────────────────────────────────── */
function openAddOldStudent() {
  document.getElementById('addOldStudentForm').reset();
  document.getElementById('addOldModal').style.display = 'flex';
}
function closeAddOldStudent() { document.getElementById('addOldModal').style.display = 'none'; }

function submitAddOldStudent(e) {
  e.preventDefault();
  const fd   = new FormData(e.target);
  const data = Object.fromEntries(fd.entries());
  data.action = 'add_student'; // same API — student no is manually provided

  fetch('<?= BASE_URL ?>api/students.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(data)
  })
  .then(r => r.json())
  .then(d => {
    showToast(d.message, d.success ? 'success' : 'error');
    if (d.success) { closeAddOldStudent(); setTimeout(() => location.reload(), 1200); }
  })
  .catch(() => showToast('Network error. Please try again.', 'error'));
}

/* ── Split Button Dropdown ───────────────────────────────── */
function toggleAddDropdown() {
  const menu = document.getElementById('addDropMenu');
  menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
}
function closeAddDropdown() {
  document.getElementById('addDropMenu').style.display = 'none';
}
document.addEventListener('click', function(e) {
  const toggle = document.getElementById('addDropToggle');
  const menu   = document.getElementById('addDropMenu');
  if (menu && toggle && !toggle.contains(e.target) && !menu.contains(e.target)) {
    menu.style.display = 'none';
  }
});

/* ── Edit Student ────────────────────────────────────────── */
function editStudent(studentID) {
  fetch('<?= BASE_URL ?>api/students.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'get_student', studentID: studentID })
  })
  .then(r => r.json())
  .then(d => {
    if (d.success && d.data) {
      const s = d.data;
      document.getElementById('edit_studentID').value    = s.studentID;
      document.getElementById('edit_fName').value        = s.fName    || '';
      document.getElementById('edit_mName').value        = s.mName    || '';
      document.getElementById('edit_lName').value        = s.lName    || '';
      document.getElementById('edit_extName').value      = s.extName  || '';
      document.getElementById('edit_studentNo').value    = s.studentNo|| '';
      document.getElementById('edit_dateofBirth').value  = s.dateofBirth || '';
      document.getElementById('edit_sex').value          = s.sex      || '';
      document.getElementById('edit_programID').value    = s.programID|| '';
      document.getElementById('edit_yearLevel').value    = s.yearLevel|| '';
      document.getElementById('edit_email').value        = s.email    || '';
      document.getElementById('edit_isEnroll').checked   = s.isEnroll == 1;
      document.getElementById('edit_isActive').checked   = s.isActive == 1;

      filterSections(s.programID || '', 'edit');
      setTimeout(() => {
        document.getElementById('edit_sectionID').value = s.sectionID || '';
      }, 50);

      document.getElementById('editModal').style.display = 'flex';
    } else {
      showToast(d.message || 'Failed to load student data', 'error');
    }
  })
  .catch(() => showToast('Network error. Please try again.', 'error'));
}
function closeEditStudent() { document.getElementById('editModal').style.display = 'none'; }

function submitEditStudent(e) {
  e.preventDefault();
  const fd   = new FormData(e.target);
  const data = Object.fromEntries(fd.entries());
  data.action    = 'update';
  data.studentID = parseInt(data.studentID);

  fetch('<?= BASE_URL ?>api/students.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(data)
  })
  .then(r => r.json())
  .then(d => {
    showToast(d.message, d.success ? 'success' : 'error');
    if (d.success) { closeEditStudent(); setTimeout(() => location.reload(), 1200); }
  })
  .catch(() => showToast('Network error. Please try again.', 'error'));
}

/* ── Toggle All / Active ─────────────────────────────────── */
function toggleAll(cb) {
  document.querySelectorAll('.row-check').forEach(c => c.checked = cb.checked);
}

function toggleActive(id, current) {
  const action = current ? 'Deactivate' : 'Activate';
  if (!confirm(action + ' this student?')) return;
  fetch('<?= BASE_URL ?>api/students.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'toggle_active', studentID: id, isActive: !current })
  })
  .then(r => r.json())
  .then(d => {
    showToast(d.message, d.success ? 'success' : 'error');
    if (d.success) setTimeout(() => location.reload(), 1000);
  })
  .catch(() => showToast('Network error. Please try again.', 'error'));
}

/* ── Export CSV — trigger browser download directly ────────── */
function exportCSV() {
  const qs = new URLSearchParams(window.location.search);
  qs.delete('page');
  qs.set('type', 'students');
  window.location.href = '<?= BASE_URL ?>api/export.php?' + qs.toString();
}

/* ── Toast ───────────────────────────────────────────────── */
function showToast(msg, type = 'success') {
  const t = document.createElement('div');
  t.textContent = msg;
  t.style.cssText = `
    position:fixed;bottom:24px;right:24px;z-index:9999;
    padding:14px 20px;border-radius:10px;font-size:14px;font-weight:600;color:#fff;
    background:${type === 'success' ? '#27ae60' : '#c0392b'};
    box-shadow:0 4px 20px rgba(0,0,0,.2);
    animation:fadeInUp .25s ease;
  `;
  document.body.appendChild(t);
  setTimeout(() => t.remove(), 3000);
}

/* ── Filter Sections by Program ─────────────────────────── */
function filterSections(programID, mode) {
  const sectionSelect = document.getElementById(mode + '_sectionID');
  if (!sectionSelect) return;
  sectionSelect.querySelectorAll('option').forEach(opt => {
    if (!opt.value) {
      opt.style.display = '';
    } else {
      opt.style.display = (!programID || opt.dataset.program == programID) ? '' : 'none';
    }
  });
  sectionSelect.value = '';
}

/* ── Close modals on backdrop click ─────────────────────── */
['addModal', 'addOldModal', 'editModal'].forEach(id => {
  const el = document.getElementById(id);
  if (!el) return;
  el.addEventListener('click', function(e) {
    if (e.target !== this) return;
    if (id === 'addModal')    closeAddStudent();
    if (id === 'addOldModal') closeAddOldStudent();
    if (id === 'editModal')   closeEditStudent();
  });
});
</script>

<?php require_once ROOT . '/includes/footer.php'; ?>
