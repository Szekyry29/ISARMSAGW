<?php
define('ROOT', __DIR__);
require_once ROOT . '/includes/config.php';
require_once ROOT . '/includes/functions.php';

$db = Database::getInstance();
$periods = $db->fetchAll(
    "SELECT pub.acadYearID, pub.semester, pub.publishedAt, ay.academicYear
     FROM dean_list_publications pub
     JOIN acad_years ay ON ay.acadYearID=pub.acadYearID AND ay.dateDeleted IS NULL
     ORDER BY ay.academicYear DESC, pub.semester DESC"
);

$selectedPeriodKey = trim((string)($_GET['period'] ?? ''));
$searchTerm = trim((string)($_GET['q'] ?? ''));
$selectedCourse = trim((string)($_GET['course'] ?? ''));
$selectedYearLevel = trim((string)($_GET['year_level'] ?? ''));
$selectedPeriod = null;
foreach ($periods as $period) {
    $periodKey = $period['acadYearID'] . '_' . $period['semester'];
    if ($selectedPeriodKey === '' && $selectedPeriod === null) {
        $selectedPeriod = $period;
        $selectedPeriodKey = $periodKey;
    } elseif ($periodKey === $selectedPeriodKey) {
        $selectedPeriod = $period;
    }
}

$students = [];
if ($selectedPeriod) {
    $students = $db->fetchAll(
        "SELECT s.fName, s.mName, s.lName, s.extName, s.yearLevel,
                p.programCode, gr.gwaValue, gr.totalUnits
         FROM dean_list_publications pub
         JOIN gwa_records gr ON gr.acadYearID=pub.acadYearID AND gr.semester=pub.semester
           AND gr.validationStatus='Validated' AND gr.latinHonor='Dean\'s List'
         JOIN students s ON s.studentID=gr.studentID AND s.isActive=1 AND s.dateDeleted IS NULL
         JOIN programs p ON p.programID=s.programID AND p.dateDeleted IS NULL
         WHERE pub.acadYearID=:ay AND pub.semester=:semester
         ORDER BY gr.gwaValue ASC, s.lName, s.fName",
        [':ay'=>$selectedPeriod['acadYearID'], ':semester'=>$selectedPeriod['semester']]
    );
}
$allStudents = $students;
$courses = array_values(array_unique(array_column($allStudents, 'programCode')));
sort($courses);
$yearLevels = array_values(array_unique(array_map('intval', array_column($allStudents, 'yearLevel'))));
sort($yearLevels);

$students = array_filter($allStudents, static function (array $student) use ($searchTerm, $selectedCourse, $selectedYearLevel): bool {
    if ($selectedCourse !== '' && $student['programCode'] !== $selectedCourse) return false;
    if ($selectedYearLevel !== '' && (int)$student['yearLevel'] !== (int)$selectedYearLevel) return false;
    if ($searchTerm !== '') {
        $haystack = strtolower(fullName($student) . ' ' . $student['programCode'] . ' ' . $student['yearLevel']);
        if (strpos($haystack, strtolower($searchTerm)) === false) return false;
    }
    return true;
});
$totalHonorees = count($allStudents);
$semesterLabel = $selectedPeriod ? ((int)$selectedPeriod['semester'] === 1 ? '1st' : '2nd') . ' Semester' : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Dean's List | ISARMS — NEUST Talavera</title>
  <link rel="icon" type="image/png" href="<?= BASE_URL ?>assets/img/neust-logo.png"/>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link href="https://fonts.googleapis.com/css2?family=Source+Sans+3:wght@400;600;700;800&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
  <style>
    :root{--navy:#10265f;--blue:#1a3a8c;--gold:#f5c518;--ink:#17243b;--muted:#64748b;--line:#dce4ef;--paper:#fff;--wash:#f1f5fb}
    *{box-sizing:border-box}
    body{margin:0;background:var(--wash);color:var(--ink);font-family:'Source Sans 3',sans-serif}
    .site-head{background:var(--navy);border-top:4px solid var(--gold);color:#fff}
    .head-inner{max-width:1120px;margin:auto;padding:17px 24px;display:flex;align-items:center;justify-content:space-between;gap:20px}
    .brand{display:flex;align-items:center;gap:13px;min-width:0;color:inherit;text-decoration:none}
    .brand img{width:50px;height:50px;object-fit:contain;background:#fff;border-radius:50%;padding:3px}
    .brand strong{display:block;font-size:17px;line-height:1.15}
    .brand span{display:block;color:#f7d950;font-size:11px;font-weight:700;letter-spacing:1px;text-transform:uppercase}
    .head-links{display:flex;gap:10px;align-items:center}
    .head-links a{color:#fff;text-decoration:none;font-weight:700;padding:9px 12px;border-radius:6px;transition:background .15s,color .15s}
    .head-links a.active,.head-links a:hover{background:var(--gold);color:var(--navy)}
    main{max-width:1120px;margin:30px auto;padding:0 24px 46px}
    .title-row{display:flex;justify-content:space-between;align-items:center;gap:18px;margin-bottom:19px;padding:20px 22px;background:linear-gradient(120deg,#fff,#f7f9fd);border:1px solid var(--line);border-left:5px solid var(--gold);border-radius:12px;box-shadow:0 4px 14px rgba(24,49,99,.04)}
    .title-copy{display:flex;align-items:center;gap:15px}
    .title-icon{width:48px;height:48px;display:grid;place-items:center;border-radius:14px;background:#fff4c7;color:#8a6a00;font-size:21px}
    .eyebrow{color:var(--blue);font-size:11px;font-weight:800;letter-spacing:1.4px;text-transform:uppercase}
    h1{margin:3px 0 0;color:var(--navy);font-size:30px;line-height:1.1}
    .posted-note{color:var(--muted);font-size:12px;text-align:right}
    .filter{background:var(--paper);border:1px solid var(--line);border-radius:12px;padding:16px;margin-bottom:17px;display:grid;grid-template-columns:minmax(160px,1.1fr) minmax(120px,.8fr) minmax(140px,.9fr) minmax(190px,1.35fr) auto;align-items:end;gap:12px;box-shadow:0 4px 14px rgba(28,48,86,.04)}
    .filter label{display:block;font-size:11px;font-weight:800;color:#42516a;margin-bottom:5px}
    select,input[type=search]{width:100%;min-height:42px;padding:8px 11px;border:1px solid #d5deeb;border-radius:7px;background:#fff;color:var(--ink);font:inherit}
    select:focus,input[type=search]:focus{outline:3px solid rgba(26,58,140,.13);border-color:var(--blue)}
    .filter button{min-height:42px;padding:8px 16px;border:0;border-radius:7px;background:var(--blue);color:#fff;font:inherit;font-weight:800;cursor:pointer}
    .filter button:hover{background:var(--navy)}
    .list-panel{background:var(--paper);border:1px solid var(--line);border-radius:12px;overflow:hidden;box-shadow:0 5px 18px rgba(28,48,86,.05)}
    .panel-head{padding:16px 19px;background:#eaf0fa;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center;gap:15px}
    .panel-head h2{margin:0;color:var(--navy);font-size:18px}
    .panel-head p{margin:2px 0 0;color:var(--muted);font-size:12px}
    .honoree-count{padding:6px 10px;border-radius:20px;background:#fff;color:var(--blue);font-size:12px;font-weight:800;white-space:nowrap}
    .table-wrap{overflow-x:auto}
    table{width:100%;border-collapse:collapse;text-align:left}
    th{background:#f7f9fc;color:#4d5f7b;font-size:10px;letter-spacing:.8px;text-transform:uppercase}
    th,td{padding:13px 16px;border-bottom:1px solid #e6ebf3}
    tbody tr{transition:background .15s}
    tbody tr:hover{background:#f8faff}
    tbody tr:last-child td{border-bottom:0}
    td.rank{width:68px;color:var(--blue);font-weight:800}
    .rank-badge{display:inline-grid;place-items:center;min-width:34px;height:30px;padding:0 8px;border-radius:10px;background:#edf2ff}
    .rank-badge.rank-1{background:#fff1b8;color:#795d00}
    .rank-badge.rank-2{background:#e7edf4;color:#475569}
    .rank-badge.rank-3{background:#f7e4d5;color:#8a4b20}
    td.name{font-weight:700}
    .program-badge{display:inline-block;padding:4px 9px;border-radius:20px;background:#edf2ff;color:var(--blue);font-size:11px;font-weight:800}
    .year-label{color:#52617a;font-size:12px}
    td.gwa{text-align:right;color:var(--blue);font-size:17px;font-weight:800;font-variant-numeric:tabular-nums}
    .empty{padding:48px 20px;text-align:center;color:var(--muted)}
    .empty i{display:block;margin-bottom:12px;color:#c9a300;font-size:30px}
    .empty strong{display:block;margin-bottom:4px;color:var(--navy);font-size:16px}
    footer{max-width:1120px;margin:auto;padding:0 24px 24px;color:var(--muted);font-size:12px}
    @media(max-width:950px){.filter{grid-template-columns:repeat(3,minmax(0,1fr))}}
    @media(max-width:800px){.filter{grid-template-columns:repeat(2,minmax(0,1fr))}}
    @media(max-width:640px){.head-inner{align-items:flex-start;flex-direction:column}.title-row{align-items:flex-start;flex-direction:column;padding:16px}.title-copy{align-items:flex-start}h1{font-size:26px}.posted-note{text-align:left}.filter{grid-template-columns:1fr}.filter button{width:100%}.panel-head{align-items:flex-start;flex-direction:column}th,td{padding:11px 10px}.program-col{display:none}}
  </style>
</head>
<body>
  <header class="site-head">
    <div class="head-inner">
      <a class="brand" href="<?= BASE_URL ?>index.php">
        <img src="<?= BASE_URL ?>assets/img/neust-logo.png" alt="NEUST logo"/>
        <div><strong>NEUST Talavera</strong><span>ISARMS · Student Honors</span></div>
      </a>
      <nav class="head-links" aria-label="Public navigation">
        <a href="<?= BASE_URL ?>index.php"><i class="fas fa-right-to-bracket"></i> Student Portal</a>
        <a class="active" href="<?= BASE_URL ?>deans-list.php"><i class="fas fa-award"></i> Dean's List</a>
      </nav>
    </div>
  </header>
  <main>
    <section class="title-row" aria-labelledby="pageTitle">
      <div class="title-copy">
        <div class="title-icon" aria-hidden="true"><i class="fas fa-award"></i></div>
        <div><div class="eyebrow">Registrar-published honors</div><h1 id="pageTitle">Dean's List</h1></div>
      </div>
      <?php if ($selectedPeriod): ?>
      <div class="posted-note"><i class="fas fa-circle-check" aria-hidden="true"></i>
        Official list published <?= date('F j, Y', strtotime($selectedPeriod['publishedAt'])) ?>
      </div>
      <?php endif; ?>
    </section>
    <?php if ($periods): ?>
    <form class="filter" method="get">
      <div><label for="period"><i class="fas fa-calendar-days" aria-hidden="true"></i> Academic period</label>
        <select name="period" id="period" onchange="this.form.submit()">
          <?php foreach ($periods as $period): $key = $period['acadYearID'] . '_' . $period['semester']; ?>
          <option value="<?= htmlspecialchars($key) ?>" <?= $key === $selectedPeriodKey ? 'selected' : '' ?>>
            <?= htmlspecialchars($period['academicYear']) ?> · <?= (int)$period['semester'] === 1 ? '1st' : '2nd' ?> Semester
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if ($selectedPeriod && $totalHonorees): ?>
      <div>
        <label for="course"><i class="fas fa-graduation-cap" aria-hidden="true"></i> Course</label>
        <select name="course" id="course" onchange="this.form.submit()">
          <option value="">All Courses</option>
          <?php foreach ($courses as $course): ?>
          <option value="<?= htmlspecialchars($course) ?>" <?= $selectedCourse === $course ? 'selected' : '' ?>>
            <?= htmlspecialchars($course) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="yearLevel"><i class="fas fa-layer-group" aria-hidden="true"></i> Year Level</label>
        <select name="year_level" id="yearLevel" onchange="this.form.submit()">
          <option value="">All Year Levels</option>
          <?php foreach ($yearLevels as $yearLevel): ?>
          <option value="<?= $yearLevel ?>" <?= $selectedYearLevel === (string)$yearLevel ? 'selected' : '' ?>>
            <?= $yearLevel === 5 ? 'Irregular' : 'Year ' . $yearLevel ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="search-field">
        <label for="honoreeSearch"><i class="fas fa-magnifying-glass" aria-hidden="true"></i> Find a student</label>
        <input type="search" id="honoreeSearch" name="q" value="<?= htmlspecialchars($searchTerm) ?>"
               placeholder="Search by name, course, or year" oninput="filterHonorees(this.value)"/>
      </div>
      <button type="submit"><i class="fas fa-filter" aria-hidden="true"></i> Apply Filters</button>
      <?php endif; ?>
    </form>
    <?php endif; ?>
    <section class="list-panel" aria-label="Published Dean's List">
      <?php if ($selectedPeriod): ?>
      <div class="panel-head">
        <div>
          <h2><?= htmlspecialchars($selectedPeriod['academicYear']) ?> · <?= htmlspecialchars($semesterLabel) ?></h2>
          <p>Official Dean's List honorees</p>
        </div>
        <span class="honoree-count" id="honoreeCount" aria-live="polite">
          <?= count($students) ?> of <?= $totalHonorees ?> honoree<?= $totalHonorees === 1 ? '' : 's' ?>
        </span>
      </div>
      <?php endif; ?>
      <?php if (!$selectedPeriod): ?>
      <div class="empty"><i class="fas fa-award"></i><strong>No list published yet</strong>Official Dean's List honorees will appear here after publication.</div>
      <?php elseif (!$students && !$totalHonorees): ?>
      <div class="empty"><i class="fas fa-award"></i><strong>No honorees for this period</strong>There are no eligible students on this published list.</div>
      <?php elseif (!$students): ?>
      <div class="empty"><i class="fas fa-filter"></i><strong>No matching students</strong>Try changing your course, year level, or search.</div>
      <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Rank</th><th>Student</th><th class="program-col">Course</th><th>Year Level</th><th style="text-align:right;">GWA</th></tr></thead>
          <tbody id="honoreeRows">
            <?php foreach ($students as $index => $student): ?>
            <?php $searchable = fullName($student) . ' ' . $student['programCode'] . ' ' . $student['yearLevel']; ?>
            <tr data-search="<?= htmlspecialchars($searchable, ENT_QUOTES) ?>">
              <td class="rank"><span class="rank-badge rank-<?= min($index + 1, 3) ?>">#<?= $index + 1 ?></span></td>
              <td class="name"><?= htmlspecialchars(fullName($student)) ?></td>
              <td class="program-col"><span class="program-badge"><?= htmlspecialchars($student['programCode']) ?></span></td>
              <td class="year-label"><?= (int)$student['yearLevel'] === 5 ? 'Irregular' : 'Year ' . (int)$student['yearLevel'] ?></td>
              <td class="gwa"><?= formatGWA((float)$student['gwaValue']) ?></td>
            </tr>
            <?php endforeach; ?>
            <tr id="noSearchMatches" style="display:none;">
              <td colspan="5" class="empty">No students match your search.</td>
            </tr>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </section>
  </main>
  <footer>Published by the NEUST Talavera Registrar's Office.</footer>
  <script>
    function filterHonorees(query) {
      const term = query.trim().toLocaleLowerCase();
      const rows = Array.from(document.querySelectorAll('#honoreeRows tr[data-search]'));
      let visible = 0;
      rows.forEach(row => {
        const matches = row.dataset.search.toLocaleLowerCase().includes(term);
        row.hidden = !matches;
        if (matches) visible++;
      });
      const noMatches = document.getElementById('noSearchMatches');
      if (noMatches) noMatches.style.display = visible ? 'none' : 'block';
      const count = document.getElementById('honoreeCount');
      if (count) count.textContent = `${visible} of ${rows.length} honoree${rows.length === 1 ? '' : 's'}`;
    }

    const honoreeSearch = document.getElementById('honoreeSearch');
    if (honoreeSearch && honoreeSearch.value) filterHonorees(honoreeSearch.value);
  </script>
</body>
</html>