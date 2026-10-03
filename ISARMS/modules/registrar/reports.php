<?php
define('ROOT', dirname(__DIR__, 2));
require_once ROOT . '/includes/config.php';
require_once ROOT . '/includes/functions.php';
requireAuth('Registrar','Admin');

$db   = Database::getInstance();
$user = getCurrentUser();

$type         = sanitize($_GET['type']          ?? '');
$ayFilter     = (int)($_GET['acad_year_id']    ?? 0);
$progFilter   = (int)($_GET['program_id']      ?? 0);
$semFilter    = isset($_GET['semester']) ? ($_GET['semester'] === '' ? null : (int)$_GET['semester']) : null;
$yearFilter   = (int)($_GET['year_level']      ?? 0);
$sectionFilter = (int)($_GET['section_id']    ?? 0);
$studentID    = (int)($_GET['student_id']      ?? 0);
$search       = sanitize($_GET['search']        ?? '');
$downloadMode = isset($_GET['download']); // was: $printMode = isset($_GET['print']);

function transcriptYearLevelLabels(array $rows): array
{
    $levels = [];
    foreach ($rows as $row) {
        $level = (int)($row['subjectYearLevel'] ?? 0);
        if ($level >= 1 && $level <= 4) {
            $levels[$level] = 'Year ' . $level;
        }
    }
    ksort($levels);
    return array_values($levels);
}

$acadYears = $db->fetchAll("SELECT acadYearID, academicYear, status FROM acad_years WHERE dateDeleted IS NULL ORDER BY academicYear DESC");
$programs  = $db->fetchAll("SELECT programID, programCode, programName FROM programs WHERE dateDeleted IS NULL ORDER BY programCode");
$activeAcademicYear = $db->fetchOne("SELECT acadYearID FROM acad_years WHERE status='Active' AND dateDeleted IS NULL ORDER BY acadYearID DESC LIMIT 1");
$sectionAcadYearID = $ayFilter ?: (int)($activeAcademicYear['acadYearID'] ?? 0);

$reportData = [];
$reportTitle = '';

if (in_array($type, ['gwa_summary', 'rankings'], true)) {
  $activeAY = $db->fetchOne("SELECT acadYearID FROM acad_years WHERE status='Active' LIMIT 1");
  $defaultAY = (int)($activeAY['acadYearID'] ?? ($acadYears[0]['acadYearID'] ?? 0));
  $activeSemesterRow = $defaultAY ? $db->fetchOne(
    "SELECT MAX(semester) AS semester FROM enrollments
     WHERE acadYearID=:ay AND status='Active' AND dateDeleted IS NULL",
    [':ay'=>$defaultAY]
  ) : null;
  $defaultSemester = (int)($activeSemesterRow['semester'] ?? 0) ?: 2;
  $ayFilter = $ayFilter ?: $defaultAY;
  if (!in_array($semFilter, [1, 2], true)) $semFilter = $defaultSemester;
}

if ($type === 'gwa_summary') {
  $reportTitle = 'GWA Summary Report';
    // Only "Posted" grades are official/complete anywhere else in the system
    // (grades.php, gwa.php, computeGWA, leaderboard.php). A student only
    // appears here once every active enrollment for the period has a
    // Posted grade — no partially-graded students leak into the summary.
    $where = [officialGradeFilter('g.status'),"g.dateDeleted IS NULL","s.dateDeleted IS NULL","s.isActive = 1",
      "(" . gwaIncludedSubjectFilter('e') . ")",
      "NOT EXISTS (SELECT 1 FROM enrollments missing_e
                    WHERE missing_e.studentID=e.studentID AND missing_e.acadYearID=e.acadYearID
                      AND missing_e.semester=e.semester AND missing_e.status='Active' AND missing_e.dateDeleted IS NULL
              AND " . gwaIncludedSubjectFilter('missing_e') . "
                      AND NOT EXISTS (SELECT 1 FROM grades missing_g WHERE missing_g.enrollmentID=missing_e.enrollmentID
                     AND " . officialGradeFilter('missing_g.status') . " AND missing_g.dateDeleted IS NULL))"]; $params = [];
    if ($search !== '') {
        // FIX: each placeholder must be unique when using native (non-emulated) prepares —
        // reusing :search 4x in one query caused "SQLSTATE[HY093]: Invalid parameter number"
        $where[] = "(s.studentNo LIKE :search1 OR s.fName LIKE :search2 OR s.lName LIKE :search3 OR p.programCode LIKE :search4)";
        $params[':search1'] = '%'.$search.'%';
        $params[':search2'] = '%'.$search.'%';
        $params[':search3'] = '%'.$search.'%';
        $params[':search4'] = '%'.$search.'%';
    }
    $where[] = "e.acadYearID=:ay";
    $params[':ay'] = $ayFilter;
    $where[] = "e.semester=:sem";
    $params[':sem'] = $semFilter;
    if ($progFilter) { $where[]="s.programID=:pid";  $params[':pid'] =$progFilter; }
    if ($sectionFilter) { $where[] = "e.sectionID=:section_id"; $params[':section_id'] = $sectionFilter; }
    $reportData = $db->fetchAll("
        SELECT CONCAT(s.lName,', ',s.fName,' ',COALESCE(SUBSTRING(s.mName,1,1),''),'.') AS studentName,
               s.studentNo, s.yearLevel, p.programCode, ay.academicYear, e.semester,
               COUNT(g.gradeID) AS subjectCount,
               SUM(sub.units)   AS totalUnits,
               ROUND(SUM(g.gradeEquivalent*sub.units)/SUM(sub.units),4) AS gwa
        FROM grades g
        JOIN enrollments e  ON e.enrollmentID=g.enrollmentID
        JOIN students   s   ON s.studentID   =g.studentID
        JOIN subjects   sub ON sub.subjectID =g.subjectID
        JOIN programs   p   ON p.programID   =s.programID
        JOIN acad_years ay ON ay.acadYearID=e.acadYearID
        WHERE ".implode(' AND ',$where)."
        GROUP BY g.studentID, ay.academicYear, e.semester ORDER BY gwa ASC
    ", $params);

} elseif ($type === 'honors_list') {
    $reportTitle = 'Validated Honors List';
    // Only fully validated records with a computed GWA count here — matches
    // the "complete grades and has a GWA" rule applied across every report.
    // FIX: validationStatus='Validated' alone is not reliable — a gwa_records
    // row can stay "Validated" even after one of the underlying grades gets
    // returned/edited post-computation, making the stored GWA stale. Cross-
    // check against the live grades table (same completeness rule as
    // gwa_summary) so a student only appears once every active enrollment in
    // that acadYear/semester scope genuinely has a Posted grade right now.
    $where = ["gr.latinHonor<>'None'","gr.validationStatus='Validated'","gr.gwaValue > 0","s.dateDeleted IS NULL","s.isActive = 1",
        "NOT EXISTS (SELECT 1 FROM enrollments missing_e
                    WHERE missing_e.studentID=gr.studentID AND missing_e.acadYearID=gr.acadYearID
                      AND (missing_e.semester=gr.semester OR gr.semester IS NULL)
                      AND missing_e.status='Active' AND missing_e.dateDeleted IS NULL
                      AND NOT EXISTS (SELECT 1 FROM grades missing_g WHERE missing_g.enrollmentID=missing_e.enrollmentID
                                     AND (missing_g.status='Posted' OR missing_g.status='Approved') AND missing_g.dateDeleted IS NULL))"]; $params = [];
    if ($search !== '') {
        $where[] = "(s.studentNo LIKE :search1 OR s.fName LIKE :search2 OR s.lName LIKE :search3 OR p.programCode LIKE :search4)";
        $params[':search1'] = '%'.$search.'%';
        $params[':search2'] = '%'.$search.'%';
        $params[':search3'] = '%'.$search.'%';
        $params[':search4'] = '%'.$search.'%';
    }
    if ($ayFilter)   { $where[]="gr.acadYearID=:ay"; $params[':ay'] =$ayFilter; }
    if ($progFilter) { $where[]="s.programID=:pid";  $params[':pid']=$progFilter; }
    if ($sectionFilter) { $where[] = "gr.studentID IN (SELECT studentID FROM enrollments WHERE sectionID=:section_id AND dateDeleted IS NULL)"; $params[':section_id'] = $sectionFilter; }
    $reportData = $db->fetchAll("
        SELECT CONCAT(s.lName,', ',s.fName,' ',COALESCE(SUBSTRING(s.mName,1,1),''),'.') AS studentName,
               s.studentNo, s.yearLevel, p.programCode,
               ay.academicYear, gr.gwaValue, gr.latinHonor, gr.semester, gr.totalUnits
        FROM gwa_records gr
        JOIN students   s  ON s.studentID  =gr.studentID
        JOIN programs   p  ON p.programID  =s.programID
        JOIN acad_years ay ON ay.acadYearID=gr.acadYearID
        WHERE ".implode(' AND ',$where)."
        ORDER BY gr.latinHonor, gr.gwaValue ASC
    ", $params);

} elseif ($type === 'rankings') {
    $reportTitle = 'Student Ranking Report';
    // FIX: was including validationStatus IN ('Validated','Pending'), which let
    // students with an unfinished/unvalidated GWA computation show up in the
    // rankings. Rankings must only include fully validated, complete-grade
    // records — same rule as gwa_summary / honors_list / transcript.
    // FIX 2: 'Validated' alone can still be stale (a grade returned/edited
    // after the GWA was computed and validated). Cross-check live grades so
    // only students whose enrollments in that acadYear/semester scope are
    // ALL currently Posted make it into the rankings.
    $where = ["gr.gwaValue > 0","gr.validationStatus = 'Validated'","s.dateDeleted IS NULL","s.isActive = 1",
        "NOT EXISTS (SELECT 1 FROM enrollments missing_e
                    WHERE missing_e.studentID=gr.studentID AND missing_e.acadYearID=gr.acadYearID
                      AND (missing_e.semester=gr.semester OR gr.semester IS NULL)
                      AND missing_e.status='Active' AND missing_e.dateDeleted IS NULL
                      AND NOT EXISTS (SELECT 1 FROM grades missing_g WHERE missing_g.enrollmentID=missing_e.enrollmentID
                                     AND (missing_g.status='Posted' OR missing_g.status='Approved') AND missing_g.dateDeleted IS NULL))"]; $params = [];
    if ($search !== '') {
        $where[] = "(s.studentNo LIKE :search1 OR s.fName LIKE :search2 OR s.lName LIKE :search3 OR p.programCode LIKE :search4)";
        $params[':search1'] = '%'.$search.'%';
        $params[':search2'] = '%'.$search.'%';
        $params[':search3'] = '%'.$search.'%';
        $params[':search4'] = '%'.$search.'%';
    }
    if ($ayFilter)   { $where[] = "gr.acadYearID=:ay"; $params[':ay'] = $ayFilter; }
    if ($progFilter) { $where[] = "s.programID=:pid";  $params[':pid'] = $progFilter; }
    if ($yearFilter) { $where[] = "s.yearLevel=:yl";   $params[':yl'] = $yearFilter; }
    if ($sectionFilter) { $where[] = "gr.studentID IN (SELECT studentID FROM enrollments WHERE sectionID=:section_id AND dateDeleted IS NULL)"; $params[':section_id'] = $sectionFilter; }
    if ($semFilter !== null) {
        $where[] = "gr.semester=:sem";
        $params[':sem'] = $semFilter;
    } else {
        $where[] = "gr.semester IS NULL";
    }
    $reportData = $db->fetchAll("
        SELECT CONCAT(s.lName,', ',s.fName,' ',COALESCE(SUBSTRING(s.mName,1,1),''),'.') AS studentName,
               s.studentNo, s.yearLevel, p.programCode,
               ay.academicYear, gr.gwaValue, gr.latinHonor, gr.semester, gr.totalUnits,
               s.programID
        FROM gwa_records gr
        JOIN students   s  ON s.studentID  =gr.studentID
        JOIN programs   p  ON p.programID  =s.programID
        JOIN acad_years ay ON ay.acadYearID=gr.acadYearID
        WHERE ".implode(' AND ',$where)."
        ORDER BY gr.gwaValue ASC, s.lName, s.fName
    ", $params);

} elseif ($type === 'transcript') {
    $reportTitle = 'Student Academic Transcript';

    // Keep the transcript view empty until a student is searched. Once a name
    // or student number is entered, show every matching active student,
    // regardless of GWA or honors classification.
    $where = ["s.dateDeleted IS NULL", "s.isActive = 1"];
    $params = [];
    if ($search !== '') {
        $where[] = "(s.studentNo LIKE :search1 OR s.fName LIKE :search2 OR s.lName LIKE :search3 OR p.programCode LIKE :search4)";
        $params[':search1'] = '%'.$search.'%';
        $params[':search2'] = '%'.$search.'%';
        $params[':search3'] = '%'.$search.'%';
        $params[':search4'] = '%'.$search.'%';
    }
    if ($ayFilter)      { $where[] = "EXISTS (SELECT 1 FROM enrollments qe WHERE qe.studentID=s.studentID AND qe.acadYearID=:ay AND qe.dateDeleted IS NULL)"; $params[':ay'] = $ayFilter; }
    if ($progFilter)    { $where[] = "s.programID=:pid";  $params[':pid'] = $progFilter; }
    if ($yearFilter)    { $where[] = "s.yearLevel=:yl";   $params[':yl'] = $yearFilter; }
    if ($sectionFilter) { $where[] = "s.studentID IN (SELECT studentID FROM enrollments WHERE sectionID=:section_id AND status='Active' AND dateDeleted IS NULL)"; $params[':section_id'] = $sectionFilter; }

    $qualifiedStudents = [];
    if ($search !== '') {
      $qualifiedStudents = $db->fetchAll("
        SELECT s.studentID, s.studentNo, s.fName, s.mName, s.lName, s.yearLevel, p.programCode, p.programName
        FROM students s
        JOIN programs p ON p.programID = s.programID
        WHERE ".implode(' AND ',$where)."
        ORDER BY s.lName, s.fName
      ", $params);
    }

    $reportData = [];
    foreach ($qualifiedStudents as $qs) {
        // Only "Posted" grades are official — matches every other student-facing
        // view (grades.php, gwa.php). Subjects still Submitted/Approved but not
        // yet Posted are not yet final and should not appear on the transcript.
        $rows = $db->fetchAll("
            SELECT g.midterm, g.finals, g.computedGrade, g.gradeEquivalent, g.remarks, g.status, g.approvedAt,
                   sub.subjectCode, sub.subjectName, sub.units, sub.yearLevel AS subjectYearLevel,
                   ay.academicYear, e.semester
            FROM grades g
            JOIN enrollments e  ON e.enrollmentID = g.enrollmentID
            JOIN subjects sub   ON sub.subjectID = g.subjectID
            JOIN acad_years ay  ON ay.acadYearID = e.acadYearID
            WHERE g.studentID = :sid
              AND (g.status = 'Posted' OR g.status = 'Approved')
              AND g.dateDeleted IS NULL
              AND e.dateDeleted IS NULL
            ORDER BY ay.academicYear, e.semester, sub.subjectCode
        ", [':sid' => $qs['studentID']]);
        $reportData[] = ['student' => $qs, 'rows' => $rows];
    }
}

// ---------------------------------------------------------------------
// Download mode — build the same layout as before, but instead of a
// server-side PDF library, use html2pdf.js (loaded from CDN) to render
// the content into a PDF entirely in the browser and auto-trigger a
// download. No Composer / PHP library install required.
// ---------------------------------------------------------------------
if ($downloadMode && $type) {

  notifyUsersByRole(
    $user['role'] === 'Admin' ? 'Registrar' : 'Admin',
    'Report Generated',
    "{$user['name']} ({$user['role']}) generated a {$reportTitle}.",
    'Report',
    $user['role']
  );

    // Build a clean filename for the downloaded file
    $slug = strtolower(preg_replace('/[^A-Za-z0-9]+/', '_', $reportTitle));
    if ($type === 'transcript') {
        if (count($reportData) === 1) {
            $slug .= '_' . preg_replace('/[^A-Za-z0-9]+/', '_', $reportData[0]['student']['studentNo']);
        } else {
            $slug .= '_' . count($reportData) . '_students';
        }
    }
    $pdfFilename = $slug . '_' . date('Ymd_His') . '.pdf';

    ?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"/><title><?= htmlspecialchars($reportTitle) ?></title>
<link rel="icon" type="image/png" href="<?= BASE_URL ?>assets/img/neust-logo.png"/>
<link href="https://fonts.googleapis.com/css2?family=Source+Sans+3:wght@400;600;700&display=swap" rel="stylesheet"/>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Source Sans 3',sans-serif;font-size:12.5px;color:#111;background:#f4f6fb;padding:24px;min-height:100vh}
.status-bar{max-width:860px;margin:0 auto 14px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.status-bar .msg{font-size:13px;color:#334155}
.status-bar .msg.done{color:#15803d;font-weight:600}
.status-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.status-actions a,.status-actions button{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:42px;padding:9px 14px;border:1px solid #1a3a8c;border-radius:7px;background:#fff;color:#1a3a8c;text-decoration:none;font:600 13px 'Source Sans 3',sans-serif;cursor:pointer}
.status-actions .download-button{background:#1a3a8c;color:#fff}
.status-actions button:disabled{opacity:.6;cursor:wait}
.page-shell{width:100%;max-width:860px;margin:0 auto;background:#fff;padding:24px}
.print-header{display:grid;grid-template-columns:78px 1fr;align-items:center;gap:16px;margin-bottom:18px}
.print-logo{width:78px;flex-shrink:0}
.print-logo img{width:100%;height:auto;display:block}
.print-title{text-align:center}
.print-title .top-label{font-size:11px;color:#555;margin-bottom:4px;letter-spacing:.9px}
.print-title .main-title{font-size:18px;font-weight:700;color:#111;letter-spacing:.4px;line-height:1.15;margin-bottom:4px}
.print-title .brand{font-size:13px;font-weight:700;color:#111;letter-spacing:.8px;margin-bottom:4px}
.print-title .sub-label{font-size:11px;color:#555;margin-bottom:2px}
.report-label{font-size:18px;font-weight:700;text-transform:uppercase;letter-spacing:1.1px;margin:18px 0 10px;text-align:center;color:#111}
.meta{font-size:12px;color:#222;margin-bottom:18px;line-height:1.55}
.meta strong{color:#111}
#pdf-content .report-table-wrap{width:100%;margin:0 0 18px;overflow:visible}
#pdf-content table{width:100%;border-collapse:collapse;border:1px solid #cbd5e1;table-layout:fixed}
#pdf-content th{background:#e2e8f0;color:#111;padding:9px 8px;font-size:10.5px;text-transform:uppercase;letter-spacing:.35px;text-align:left;border-bottom:1px solid #cbd5e1;overflow-wrap:anywhere}
#pdf-content td{padding:9px 8px;border-bottom:1px solid #e2e8f0;vertical-align:middle;overflow-wrap:anywhere}
#pdf-content thead{display:table-header-group}
#pdf-content tr{break-inside:avoid;page-break-inside:avoid}
#pdf-content .semester-heading,#pdf-content .student-meta{break-inside:avoid;page-break-inside:avoid}
tr:nth-child(even)td{background:#f8fafc}
.pass{color:#15803d;font-weight:700}.fail{color:#dc2626;font-weight:700}
.sum-row{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:18px}
.sum-box{padding:14px;border:1px solid #e2e8f0;border-radius:8px;text-align:center;background:#f8fafc}
.sum-val{font-size:20px;font-weight:700;color:#1a3a8c}.sum-lbl{font-size:10px;color:#667085;margin-top:4px;text-transform:uppercase;letter-spacing:.8px}
.year-level-tags{display:inline-flex;align-items:center;gap:5px;flex-wrap:wrap;margin-left:8px;vertical-align:middle}
.year-level-tag{display:inline-block;padding:2px 8px;border-radius:999px;background:#e8edff;color:#1a3a8c;font-size:10px;font-weight:700;white-space:nowrap}
.sig{display:grid;grid-template-columns:1fr 1fr;gap:40px;margin-top:28px}
.sig-line{border-top:1px solid #333;padding-top:8px;text-align:center;font-size:11px;color:#444}
.footer{margin-top:24px;border-top:1px solid #e2e8f0;padding-top:14px;font-size:10.5px;color:#667085;display:flex;justify-content:space-between;flex-wrap:wrap;gap:12px}
@media(max-width:600px){body{padding:12px}.status-bar{align-items:stretch}.status-bar .msg{width:100%;line-height:1.4}.status-actions{width:100%}.status-actions a,.status-actions button{flex:1 1 140px}.page-shell{padding:16px}.print-header{grid-template-columns:54px minmax(0,1fr);gap:10px}.print-title .main-title{font-size:15px}.sum-row{grid-template-columns:repeat(2,minmax(0,1fr))}.sig{gap:20px}}
@media print{body{padding:0;background:#fff}.status-bar{display:none}.page-shell{max-width:none;padding:0}.report-table-wrap{overflow:visible}}
</style></head><body>
<div class="status-bar">
  <div class="msg" id="status-msg">Preparing your report…</div>
  <div class="status-actions">
    <button type="button" class="download-button" id="download-pdf">Download PDF</button>
    <button type="button" id="print-report">Print / Save PDF</button>
    <a href="reports.php?type=<?= urlencode($type) ?>&<?= http_build_query(array_diff_key($_GET,['download'=>1])) ?>">Back to reports</a>
  </div>
</div>
<div class="page-shell" id="pdf-content">
<div class="print-header">
  <div class="print-logo"><img src="<?= BASE_URL ?>assets/img/neust-logo.png" alt="NEUST Logo" crossorigin="anonymous"/></div>
  <div class="print-title">
    <div class="top-label">Republic of the Philippines</div>
    <div class="main-title">Nueva Ecija University of Science and Technology</div>
    <div class="brand">Talavera Off-Campus</div>
    <div class="sub-label">Integrated Student Academic Records Management System</div>
  </div>
</div>
<div class="report-label"><?= htmlspecialchars($reportTitle) ?></div>
<div class="meta">
  <?php if ($ayFilter): $ay=$db->fetchOne("SELECT academicYear FROM acad_years WHERE acadYearID=:id",[':id'=>$ayFilter]); echo "A.Y.: <strong>{$ay['academicYear']}</strong> &bull; "; endif; ?>
  <?php if ($type==='gwa_summary' && $semFilter): echo "Semester: <strong>".($semFilter===1?'1st':'2nd')."</strong> &bull; "; endif; ?>
  <?php if ($progFilter): $pg=$db->fetchOne("SELECT programCode FROM programs WHERE programID=:id",[':id'=>$progFilter]); echo "Program: <strong>{$pg['programCode']}</strong> &bull; "; endif; ?>
  <?php if ($type==='transcript' && count($reportData)===1): echo "Student: <strong>".htmlspecialchars($reportData[0]['student']['studentNo'])."</strong> &bull; "; endif; ?>
  Records: <strong><?= count($reportData) ?></strong>
</div>

<?php if ($type==='gwa_summary'): ?>
<div class="sum-row">
  <div class="sum-box"><div class="sum-val"><?= count($reportData) ?></div><div class="sum-lbl">Total Students</div></div>
  <div class="sum-box"><div class="sum-val"><?= count(array_filter($reportData,fn($r)=>$r['gwa']<=1.75)) ?></div><div class="sum-lbl">Honors Eligible</div></div>
  <div class="sum-box"><div class="sum-val"><?= count($reportData)>0?number_format(array_sum(array_column($reportData,'gwa'))/count($reportData),4):'—' ?></div><div class="sum-lbl">Average GWA</div></div>
  <div class="sum-box"><div class="sum-val"><?= count($reportData)>0?number_format(min(array_column($reportData,'gwa')),4):'—' ?></div><div class="sum-lbl">Best GWA</div></div>
</div>
<div class="report-table-wrap"><table><thead><tr><th>#</th><th>Student Name</th><th>Student No.</th><th>Program</th><th>Yr.</th><th>Period</th><th>Subjects</th><th>Units</th><th>GWA</th></tr></thead>
<tbody><?php $n=1; foreach ($reportData as $r): ?>
<tr><td><?= $n++ ?></td><td style="font-weight:600;"><?= htmlspecialchars($r['studentName']) ?></td>
<td><?= htmlspecialchars($r['studentNo']) ?></td><td><?= $r['programCode'] ?></td>
<td style="text-align:center;"><?= $r['yearLevel'] ?></td><td><?= htmlspecialchars($r['academicYear'].' / '.($r['semester']==1?'1st':'2nd').' Semester') ?></td><td style="text-align:center;"><?= $r['subjectCount'] ?></td>
<td style="text-align:center;"><?= $r['totalUnits'] ?></td>
<td style="font-weight:700;color:<?= (float)$r['gwa']<=1.75?'#15803d':'#1a3a8c' ?>;"><?= number_format((float)$r['gwa'],4) ?></td>
</tr><?php endforeach; ?></tbody></table></div>

<?php elseif ($type==='honors_list'):
  $prev=''; $n=1; ?>
<div class="report-table-wrap"><table><thead><tr><th>#</th><th>Student Name</th><th>Student No.</th><th>Program</th><th>Yr</th><th>A.Y. / Semester</th><th>GWA</th><th>Units</th><th>Classification</th></tr></thead>
<tbody><?php foreach ($reportData as $r):
  if ($prev!==$r['latinHonor']){echo "<tr><td colspan='9' style='background:#1a3a8c;color:#fff;font-weight:700;padding:8px 12px;'>{$r['latinHonor']}</td></tr>"; $prev=$r['latinHonor'];}
?><tr><td><?= $n++ ?></td><td style="font-weight:600;"><?= htmlspecialchars($r['studentName']) ?></td>
<td><?= $r['studentNo'] ?></td><td><?= $r['programCode'] ?></td><td style="text-align:center;"><?= $r['yearLevel'] ?></td>
<td><?= $r['academicYear'] ?><?= $r['semester']?' / '.($r['semester']==1?'1st':'2nd').' Sem':' / Cumulative' ?></td>
<td style="font-weight:700;" class="pass"><?= number_format((float)$r['gwaValue'],4) ?></td>
<td style="text-align:center;"><?= $r['totalUnits'] ?></td><td style="font-weight:700;color:#c9a300;"><?= $r['latinHonor'] ?></td>
</tr><?php endforeach; ?></tbody></table></div>

<?php elseif ($type==='rankings'):
  $n=1; ?>
<div class="report-table-wrap"><table><thead><tr><th>#</th><th>Rank</th><th>Student Name</th><th>Student No.</th><th>Program</th><th>Yr</th><th>GWA</th><th>Semester</th></tr></thead>
<tbody><?php foreach ($reportData as $r): ?>
<tr><td><?= $n ?></td>
<td><span><?= htmlspecialchars(getPositionLabel($n)) ?></span></td>
<td style="font-weight:600;"> <?= htmlspecialchars($r['studentName']) ?></td>
<td style="font-weight:600;"> <?= $r['studentNo'] ?></td>
<td style="font-weight:700;"> <?= $r['programCode'] ?></td>
<td style="text-align:center;"> <?= $r['yearLevel'] ?></td>
<td style="text-align:center;font-size:18px;font-weight:700;color:<?= (float)$r['gwaValue']<=1.75?'#15803d':'#1a3a8c' ?>;"> <?= number_format((float)$r['gwaValue'],4) ?></td>
<td style="text-align:center;"> <?= $r['semester'] ? ($r['semester']==1?'1st':'2nd') : 'Cumulative' ?></td>
</tr><?php $n++; endforeach; ?></tbody></table></div>

<?php elseif ($type==='transcript'):
  foreach ($reportData as $si => $entry):
    $tStudent = $entry['student'];
    $rows     = $entry['rows'];
    $grouped=[]; foreach ($rows as $gr){ $k=$gr['academicYear'].'_'.$gr['semester']; $grouped[$k][]=$gr; }
?>
<div class="student-meta" style="margin-bottom:14px;padding:12px;background:#f0f4fb;font-size:12px;<?= $si>0?'margin-top:28px;border-top:2px solid #1a3a8c;padding-top:16px;':'' ?>">
  <div style="color:#666;font-size:10px;text-transform:uppercase;">Full Name</div><div style="font-weight:700;"><?= htmlspecialchars($tStudent['lName'].', '.$tStudent['fName'].' '.($tStudent['mName']?substr($tStudent['mName'],0,1).'.':'')) ?></div>
  <div style="color:#666;font-size:10px;text-transform:uppercase;margin-top:6px;">Student No.</div><div style="font-weight:700;"><?= htmlspecialchars($tStudent['studentNo']) ?></div>
  <div style="color:#666;font-size:10px;text-transform:uppercase;margin-top:6px;">Program</div><div style="font-weight:700;"><?= htmlspecialchars($tStudent['programCode']) ?> — <?= htmlspecialchars($tStudent['programName']) ?></div>
  <div style="color:#666;font-size:10px;text-transform:uppercase;margin-top:6px;">Year Level</div>
  <div style="font-weight:700;">
    <?= (int)$tStudent['yearLevel'] === 5 ? 'Irregular' : 'Year ' . (int)$tStudent['yearLevel'] ?>
  </div>
</div>
<?php foreach ($grouped as $key => $gRows):
  [$ay,$sem] = explode('_',$key);
  $semUnits=0; $semW=0;
  foreach ($gRows as $r){ if($r['gradeEquivalent']){$semUnits+=$r['units'];$semW+=$r['gradeEquivalent']*$r['units'];}}
  $semGWA = $semUnits>0?round($semW/$semUnits,4):0;
?>
<div style="margin-bottom:14px;">
<div class="semester-heading" style="font-weight:700;font-size:12px;margin-bottom:6px;padding:4px 0;border-bottom:1px solid #ddd;">
  <?= htmlspecialchars($ay) ?> — <?= $sem==1?'1st':'2nd' ?> Semester
  <span class="year-level-tags" aria-label="Inferred year levels">
    <?php foreach (transcriptYearLevelLabels($gRows) ?: ['Year level not set'] as $yearLevelLabel): ?>
    <span class="year-level-tag"><?= htmlspecialchars($yearLevelLabel) ?></span>
    <?php endforeach; ?>
  </span>
  <?php if ($semGWA): ?><span style="float:right;color:#1a3a8c;">Semester GWA: <?= number_format($semGWA,4) ?></span><?php endif; ?>
</div>
<div class="report-table-wrap"><table><thead><tr><th>Subject Code</th><th>Subject Name</th><th style="text-align:center;">Units</th><th style="text-align:center;">Grade</th><th style="text-align:center;">Equiv.</th><th style="text-align:center;">Remarks</th></tr></thead>
<tbody><?php foreach ($gRows as $r): ?>
<tr><td style="font-weight:600;"><?= $r['subjectCode'] ?></td><td><?= htmlspecialchars($r['subjectName']) ?></td>
<td style="text-align:center;"><?= $r['units'] ?></td>
<td style="text-align:center;font-weight:700;"><?= $r['computedGrade']?number_format((float)$r['computedGrade'],2):'—' ?></td>
<td style="text-align:center;font-weight:700;" class="<?= ($r['gradeEquivalent']??5)<=3?'pass':'fail' ?>"><?= $r['gradeEquivalent']?number_format((float)$r['gradeEquivalent'],2):'—' ?></td>
<td style="text-align:center;" class="<?= $r['remarks']==='Passed'?'pass':($r['remarks']==='Failed'?'fail':'') ?>"><?= $r['remarks']??'—' ?></td>
</tr><?php endforeach; ?></tbody></table></div>
</div>
<?php endforeach; ?>
<?php endforeach; ?>
<?php endif; ?>

<div class="sig">
  <div><div class="sig-line">Prepared by:<br/><?= htmlspecialchars($user['name']) ?>, Registrar</div></div>
  <div><div class="sig-line">Noted by:<br/>Campus Director / Dean</div></div>
</div>
<div class="footer">This is a system-generated document. For official purposes, proper certification is required. &bull; NEUST Talavera &bull; <?= date('Y') ?></div>
</div>

<script>
let isGenerating = false;
let hasAutoTriggered = false;

function downloadPdf() {
  if (isGenerating) return; // prevent overlapping/duplicate downloads
  if (typeof html2pdf !== 'function') {
    document.getElementById('status-msg').textContent = 'PDF download is unavailable. Use Print / Save PDF instead.';
    return;
  }
  isGenerating = true;
  const downloadButton = document.getElementById('download-pdf');
  downloadButton.disabled = true;
  downloadButton.textContent = 'Generating PDF…';

  const el = document.getElementById('pdf-content');
  const opt = {
    margin:       [10, 8, 10, 8],
    filename:     '<?= addslashes($pdfFilename) ?>',
    image:        { type: 'jpeg', quality: 0.98 },
    html2canvas:  { scale: 2, useCORS: true },
    jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' },
    pagebreak:    { mode: ['css', 'legacy'], avoid: ['tr', '.student-meta', '.semester-heading', '.sig'] }
  };
  document.getElementById('status-msg').textContent = 'Generating PDF, please wait…';
  document.getElementById('status-msg').classList.remove('done');

  html2pdf().set(opt).from(el).save().then(function () {
    document.getElementById('status-msg').textContent = '✓ PDF downloaded.';
    document.getElementById('status-msg').classList.add('done');
    isGenerating = false;
    downloadButton.disabled = false;
    downloadButton.textContent = 'Download PDF again';
  }).catch(function (error) {
    console.error('Report PDF generation failed:', error);
    document.getElementById('status-msg').textContent = 'PDF download failed. Use Print / Save PDF or try Download PDF again.';
    isGenerating = false;
    downloadButton.disabled = false;
    downloadButton.textContent = 'Try Download PDF again';
  });
}

document.getElementById('download-pdf').addEventListener('click', downloadPdf);
document.getElementById('print-report').addEventListener('click', function () {
  window.print();
});

// Mobile browsers may block automatic downloads without a direct user gesture.
window.addEventListener('load', function () {
  if (hasAutoTriggered || window.matchMedia('(max-width: 768px)').matches) {
    document.getElementById('status-msg').textContent = 'Tap Download PDF to save your report, or use Print / Save PDF.';
    return;
  }
  hasAutoTriggered = true;
  downloadPdf();
});
</script>
</body></html>
<?php
    exit;
}

$pageTitle  = 'Reports';
$activeMenu = 'reports.php';
require_once ROOT . '/includes/header.php';
?>

<!-- Report Type Cards -->
<?php if (!$type): ?>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:18px;margin-bottom:24px;">
  <?php $rCards = [
    ['gwa_summary','GWA Summary Report','Comprehensive GWA list for all students in a selected period.','fa-chart-bar','btn-primary'],
    ['rankings','Student Ranking Report','Rank students by GWA for one semester.','fa-medal','btn-gold'],
    ['honors_list','Validated Honors List','Official list of students with Latin honors.','fa-award','btn-warning'],
    ['transcript','Student Transcript','Individual academic record for a specific student.','fa-scroll','btn-outline'],
  ]; ?>
  <?php foreach ($rCards as [$rtype,$rtitle,$rdesc,$icon,$btnCls]): ?>
  <div class="card" style="padding:28px;text-align:center;">
    <div style="width:56px;height:56px;background:var(--blue-light);border-radius:14px;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;font-size:24px;color:var(--blue);">
      <i class="fas <?= $icon ?>"></i>
    </div>
    <h3 style="font-family:'Playfair Display',serif;font-size:17px;margin-bottom:8px;"><?= $rtitle ?></h3>
    <p style="font-size:13px;color:var(--text-muted);line-height:1.6;margin-bottom:20px;"><?= $rdesc ?></p>
    <a href="?type=<?= $rtype ?>" class="btn <?= $btnCls ?>" style="width:100%;justify-content:center;"><i class="fas fa-arrow-right"></i> Generate</a>
  </div>
  <?php endforeach; ?>
</div>
<?php require_once ROOT . '/includes/footer.php'; exit; endif; ?>

<!-- Filter Form -->
<div class="card" style="margin-bottom:18px;">
  <div class="card-header">
    <div class="card-title"><i class="fas fa-filter"></i> <?= htmlspecialchars($reportTitle) ?></div>
    <?php if (!empty($reportData)): ?>
    <a href="?<?= http_build_query(array_merge($_GET,['download'=>1])) ?>" class="btn btn-gold">
      <i class="fas fa-file-pdf"></i> Download PDF
    </a>
    <?php endif; ?>
  </div>
  <div class="card-body" style="padding:16px 20px;">
    <form method="GET" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
      <input type="hidden" name="type" value="<?= htmlspecialchars($type) ?>"/>
      <div><label class="form-label">Search Student</label><input type="search" name="search" class="form-control" placeholder="Name, student no., program" value="<?= htmlspecialchars($search) ?>"></div>
      <div><label class="form-label">Academic Year</label>
        <select name="acad_year_id" class="form-control"><?php if (!in_array($type, ['gwa_summary', 'rankings'], true)): ?><option value="">All Years</option><?php endif; ?>
        <?php foreach ($acadYears as $ay): ?><option value="<?= $ay['acadYearID'] ?>" <?= $ayFilter==$ay['acadYearID']?'selected':'' ?>><?= $ay['academicYear'] ?></option><?php endforeach; ?>
      </select></div>
      <div><label class="form-label">Program</label>
        <select name="program_id" class="form-control"><option value="">All</option>
        <?php foreach ($programs as $p): ?><option value="<?= $p['programID'] ?>" <?= $progFilter==$p['programID']?'selected':'' ?>><?= $p['programCode'] ?></option><?php endforeach; ?>
      </select></div>
      <div><label class="form-label">Year Level</label>
        <select name="year_level" class="form-control"><option value="">Any</option>
          <option value="1" <?= $yearFilter===1?'selected':'' ?>>1st Year</option>
          <option value="2" <?= $yearFilter===2?'selected':'' ?>>2nd Year</option>
          <option value="3" <?= $yearFilter===3?'selected':'' ?>>3rd Year</option>
          <option value="4" <?= $yearFilter===4?'selected':'' ?>>4th Year</option>
        </select>
      </div>
      <?php if ($type === 'transcript'): ?>
      <div><label class="form-label">Section</label>
        <select name="section_id" class="form-control">
          <option value="">All Sections</option>
          <?php foreach ($db->fetchAll("SELECT sec.sectionID, sec.sectionName, p.programCode, ay.academicYear FROM sections sec JOIN programs p ON p.programID=sec.programID JOIN acad_years ay ON ay.acadYearID=sec.acadYearID WHERE sec.dateDeleted IS NULL AND sec.acadYearID=:acadYearID ORDER BY sec.sectionName", [':acadYearID'=>$sectionAcadYearID]) as $sec): ?>
          <option value="<?= $sec['sectionID'] ?>" <?= $sectionFilter==$sec['sectionID']?'selected':'' ?>><?= htmlspecialchars($sec['sectionName'] . ' (' . $sec['academicYear'] . ')') ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php else: ?>
      <?php if (in_array($type,['gwa_summary','rankings'])): ?>
      <div><label class="form-label">Semester</label>
        <select name="semester" class="form-control">
          <option value="1" <?= $semFilter===1?'selected':'' ?>>1st</option>
          <option value="2" <?= $semFilter===2?'selected':'' ?>>2nd</option>
        </select></div>
      <?php endif; ?>
      <?php endif; ?>
      <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Generate</button>
      <a href="reports.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Back</a>
    </form>
  </div>
  </div>

<!-- Preview Table -->
<?php if (!empty($reportData)): ?>
<div class="card">
  <div class="card-header">
    <div class="card-title"><i class="fas fa-table"></i> Preview — <?= count($reportData) ?> record(s)</div>
  </div>
  <div style="overflow-x:auto;">
    <table class="data-table">
      <?php if ($type==='gwa_summary'): ?>
      <thead><tr><th>#</th><th>Student Name</th><th>Student No.</th><th>Program</th><th style="text-align:center;">Yr</th><th>Period</th><th style="text-align:center;">Subjects</th><th style="text-align:center;">Units</th><th style="text-align:center;">GWA</th></tr></thead>
      <tbody><?php $n=1; foreach ($reportData as $r): ?>
      <tr><td style="color:var(--text-muted);"><?= $n++ ?></td><td style="font-weight:700;"><?= htmlspecialchars($r['studentName']) ?></td>
      <td style="color:var(--blue);font-weight:600;"><?= $r['studentNo'] ?></td><td style="font-weight:700;"><?= $r['programCode'] ?></td>
      <td style="text-align:center;"><?= $r['yearLevel'] ?></td><td><?= htmlspecialchars($r['academicYear'].' / '.($r['semester']==1?'1st':'2nd').' Semester') ?></td><td style="text-align:center;"><?= $r['subjectCount'] ?></td>
      <td style="text-align:center;"><?= $r['totalUnits'] ?></td>
      <td style="text-align:center;font-size:18px;font-weight:700;color:<?= (float)$r['gwa']<=1.75?'var(--success)':'var(--blue)' ?>;"><?= number_format((float)$r['gwa'],4) ?></td>
      </tr><?php endforeach; ?></tbody>

      <?php elseif ($type==='honors_list'): ?>
      <thead><tr><th>#</th><th>Student Name</th><th>Student No.</th><th>Program</th><th>A.Y. / Sem</th><th style="text-align:center;">GWA</th><th>Classification</th></tr></thead>
      <tbody><?php $n=1; foreach ($reportData as $r): ?>
      <tr><td style="color:var(--text-muted);"><?= $n++ ?></td><td style="font-weight:700;"><?= htmlspecialchars($r['studentName']) ?></td>
      <td style="color:var(--blue);font-weight:600;"><?= $r['studentNo'] ?></td><td style="font-weight:700;"><?= $r['programCode'] ?></td>
      <td><?= $r['academicYear'] ?><?= $r['semester']?' / '.($r['semester']==1?'1st':'2nd').' Sem':' / Cumulative' ?></td>
      <td style="text-align:center;font-size:18px;font-weight:700;color:var(--success);"><?= number_format((float)$r['gwaValue'],4) ?></td>
      <td><span class="<?= honorBadgeClass($r['latinHonor']) ?>"><?= htmlspecialchars($r['latinHonor']) ?></span></td>
      </tr><?php endforeach; ?></tbody>

      <?php elseif ($type==='rankings'): ?>
      <thead><tr><th>#</th><th>Rank</th><th>Student Name</th><th>Student No.</th><th>Program</th><th style="text-align:center;">Yr</th><th style="text-align:center;">GWA</th><th style="text-align:center;">Sem</th></tr></thead>
      <tbody><?php $n=1; foreach ($reportData as $r): ?>
      <tr><td style="color:var(--text-muted);"><?= $n ?></td>
      <td><span class="<?= getRankingBadgeClass($n) ?>"><?= htmlspecialchars(getPositionLabel($n)) ?></span></td>
      <td style="font-weight:700;"><?= htmlspecialchars($r['studentName']) ?></td>
      <td style="color:var(--blue);font-weight:600;"><?= $r['studentNo'] ?></td>
      <td style="font-weight:700;"><?= $r['programCode'] ?></td>
      <td style="text-align:center;"><?= $r['yearLevel'] ?></td>
      <td style="text-align:center;font-size:18px;font-weight:700;color:<?= (float)$r['gwaValue']<=1.75?'var(--success)':'var(--blue)' ?>;"><?= number_format((float)$r['gwaValue'],4) ?></td>
      <td style="text-align:center;"><?= $r['semester'] ? ($r['semester']==1?'1st':'2nd') : 'Cumulative' ?></td>
      </tr><?php $n++; endforeach; ?></tbody>

      <?php elseif ($type==='transcript'): ?>
      <thead><tr><th>Student</th><th>Student No.</th><th>A.Y.</th><th>Sem</th><th>Subject</th><th style="text-align:center;">Units</th><th style="text-align:center;">Grade</th><th style="text-align:center;">Equiv.</th><th style="text-align:center;">Remarks</th></tr></thead>
      <tbody><?php foreach ($reportData as $entry): $tStudent = $entry['student']; if (empty($entry['rows'])): ?>
      <tr>
        <td style="font-weight:700;"><?= htmlspecialchars($tStudent['lName'].', '.$tStudent['fName']) ?></td>
        <td style="color:var(--blue);font-weight:600;"><?= htmlspecialchars($tStudent['studentNo']) ?></td>
        <td colspan="7" style="text-align:center;color:var(--text-muted);">No posted grade records yet</td>
      </tr>
      <?php else:
        $previewGroups = [];
        foreach ($entry['rows'] as $previewRow) {
          $previewGroups[$previewRow['academicYear'] . '_' . $previewRow['semester']][] = $previewRow;
        }
        foreach ($previewGroups as $previewRows):
          $previewFirstRow = $previewRows[0];
          $previewTerm = $previewFirstRow['academicYear'] . ' — ' . ((int)$previewFirstRow['semester'] === 1 ? '1st' : '2nd') . ' Semester';
      ?>
      <tr class="transcript-term-row">
        <td colspan="9">
          <strong><?= htmlspecialchars($previewTerm) ?></strong>
          <span class="year-level-tags" aria-label="Inferred year levels">
            <?php foreach (transcriptYearLevelLabels($previewRows) ?: ['Year level not set'] as $yearLevelLabel): ?>
            <span class="year-level-tag"><?= htmlspecialchars($yearLevelLabel) ?></span>
            <?php endforeach; ?>
          </span>
        </td>
      </tr>
      <?php foreach ($previewRows as $r): ?>
      <tr><td style="font-weight:700;"><?= htmlspecialchars($tStudent['lName'].', '.$tStudent['fName']) ?></td>
      <td style="color:var(--blue);font-weight:600;"><?= htmlspecialchars($tStudent['studentNo']) ?></td>
      <td><?= $r['academicYear'] ?></td><td><?= $r['semester']==1?'1st':'2nd' ?></td>
      <td><div style="font-weight:700;color:var(--blue);"><?= $r['subjectCode'] ?></div><div style="font-size:11px;color:var(--text-muted);"><?= htmlspecialchars($r['subjectName']) ?></div></td>
      <td style="text-align:center;"><?= $r['units'] ?></td>
      <td style="text-align:center;font-weight:700;"><?= $r['computedGrade']?number_format((float)$r['computedGrade'],2):'—' ?></td>
      <td style="text-align:center;font-size:18px;font-weight:700;color:<?= ($r['gradeEquivalent']??5)<=3?'var(--success)':'var(--danger)' ?>;"><?= $r['gradeEquivalent']?number_format((float)$r['gradeEquivalent'],2):'—' ?></td>
      <td style="text-align:center;"><?php $rem=$r['remarks']??''; $rc=match($rem){'Passed'=>'status-approved','Failed'=>'status-flagged',default=>''}; echo $rem?"<span class='status-badge $rc'>$rem</span>":'—'; ?></td>
      </tr><?php endforeach; endforeach; endif; endforeach; ?></tbody>
      <?php endif; ?>
    </table>
  </div>
</div>
<?php elseif ($type): ?>
<div class="card"><div style="padding:40px;text-align:center;color:var(--text-muted);">
  <i class="fas fa-inbox" style="font-size:40px;display:block;margin-bottom:12px;opacity:.2;color:var(--blue);"></i>
  <div style="font-weight:600;margin-bottom:6px;">No records found</div>
  <div style="font-size:13px;">Adjust your filters and try again.</div>
</div></div>
<?php endif; ?>

<?php require_once ROOT . '/includes/footer.php'; ?>