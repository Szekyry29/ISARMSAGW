<?php
define('ROOT', dirname(__DIR__, 2));

require_once ROOT . '/includes/config.php';
require_once ROOT . '/includes/functions.php';

requireAuth('Teacher');

$db   = Database::getInstance();
$user = getCurrentUser();
$tid  = (int)$user['id'];

/*
|--------------------------------------------------------------------------
| TEACHER'S SECTIONS
|--------------------------------------------------------------------------
| Only sections where the currently logged-in teacher is assigned are shown.
*/
$mySections = $db->fetchAll("
    SELECT DISTINCT
        sec.sectionID,
        sec.sectionName,
        p.programCode,
        p.programName,
        ay.academicYear,
        ay.status AS academicYearStatus
    FROM section_subjects ss
    JOIN sections sec
        ON sec.sectionID = ss.sectionID
    JOIN programs p
        ON p.programID = sec.programID
    JOIN acad_years ay
        ON ay.acadYearID = ss.acadYearID
    WHERE ss.teacherID = :t
      AND ss.sectionID IS NOT NULL
      AND ss.subjectID IS NOT NULL
      AND ss.semester IS NOT NULL
      AND ss.acadYearID IS NOT NULL
      AND ss.dateDeleted IS NULL
      AND sec.dateDeleted IS NULL
      AND ay.dateDeleted IS NULL
      AND ay.status = 'Active'
    ORDER BY
        ay.academicYear DESC,
        sec.sectionName ASC
", [
    ':t' => $tid
]);

/*
|--------------------------------------------------------------------------
| GET FILTERS
|--------------------------------------------------------------------------
*/
$selSection = (int)($_GET['section_id'] ?? 0);
$selSubject = (int)($_GET['subject_id'] ?? 0);
$selSem     = (int)($_GET['semester'] ?? 0);
$downloadMode = isset($_GET['download']);

/*
|--------------------------------------------------------------------------
| SUBJECTS ASSIGNED TO SELECTED SECTION
|--------------------------------------------------------------------------
*/
$mySubjectsInSection = [];

if ($selSection > 0) {

    $mySubjectsInSection = $db->fetchAll("
        SELECT
            ss.subjectID,
            ss.semester,
            sub.subjectCode,
            sub.subjectName,
            sub.units
        FROM section_subjects ss
        JOIN subjects sub
            ON sub.subjectID = ss.subjectID
        JOIN acad_years ay
            ON ay.acadYearID = ss.acadYearID
        WHERE ss.sectionID = :sec
          AND ss.teacherID = :t
          AND ss.subjectID IS NOT NULL
          AND ss.semester IS NOT NULL
          AND ss.dateDeleted IS NULL
          AND sub.dateDeleted IS NULL
          AND ay.dateDeleted IS NULL
          AND ay.status = 'Active'
        ORDER BY
            ss.semester ASC,
            sub.subjectCode ASC
    ", [
        ':sec' => $selSection,
        ':t'   => $tid
    ]);
}

/*
|--------------------------------------------------------------------------
| REPORT VARIABLES
|--------------------------------------------------------------------------
*/
$report    = null;
$gradeRows = [];

$dist = [
    '1.00–1.75' => 0,
    '2.00–2.75' => 0,
    '3.00'      => 0,
    '5.00'      => 0
];

$classAvg = 0;
$highest  = 0;
$lowest   = 0;

$passed = [];
$failed = [];
$graded = [];

/*
|--------------------------------------------------------------------------
| GENERATE REPORT
|--------------------------------------------------------------------------
*/
if ($selSection > 0 && $selSubject > 0 && $selSem > 0) {

    /*
    |--------------------------------------------------------------------------
    | REPORT INFORMATION
    |--------------------------------------------------------------------------
    */
    $report = $db->fetchOne("
        SELECT
            sec.sectionName,
            p.programCode,
            p.programName,
            ay.academicYear,
            sub.subjectCode,
            sub.subjectName,
            sub.units,
            ss.schedule,
            ss.room
        FROM section_subjects ss
        JOIN sections sec
            ON sec.sectionID = ss.sectionID
        JOIN programs p
            ON p.programID = sec.programID
        JOIN acad_years ay
            ON ay.acadYearID = ss.acadYearID
        JOIN subjects sub
            ON sub.subjectID = ss.subjectID
        WHERE ss.sectionID = :sec
          AND ss.subjectID = :sub
          AND ss.semester = :sem
          AND ss.teacherID = :t
          AND ss.dateDeleted IS NULL
          AND sec.dateDeleted IS NULL
    ", [
        ':sec' => $selSection,
        ':sub' => $selSubject,
        ':sem' => $selSem,
        ':t'   => $tid
    ]);

    /*
    |--------------------------------------------------------------------------
    | STUDENTS + GRADES
    |--------------------------------------------------------------------------
    |
    | IMPORTANT:
    |
    | Archived students are excluded using:
    |
    |   s.dateDeleted IS NULL
    |   s.isActive = 1
    |   s.isEnroll = 1
    |
    | Your student.php archive process uses:
    |
    |   dateDeleted = NOW()
    |   isActive    = 0
    |   isEnroll    = 0
    |
    | Therefore archived students will NOT appear in this report.
    |
    | Existing enrollment and grade records are NOT deleted.
    | They are simply hidden from the active Class Report.
    |
    |--------------------------------------------------------------------------
    */
    if ($report) {

        $gradeParams = [
            ':teacherID' => $tid,
            ':sectionID' => $selSection,
            ':subjectID' => $selSubject,
            ':semester'  => $selSem
        ];

        $gradeRows = $db->fetchAll("
            SELECT
                s.studentNo,

                CONCAT(
                    s.lName,
                    ', ',
                    s.fName,
                    ' ',
                    COALESCE(SUBSTRING(s.mName, 1, 1), ''),
                    CASE
                        WHEN s.mName IS NOT NULL
                         AND s.mName <> ''
                        THEN '.'
                        ELSE ''
                    END
                ) AS fullName,

                g.midterm,
                g.finals,
                g.computedGrade,
                g.gradeEquivalent,
                g.remarks,
                g.status

            FROM enrollments e

            JOIN students s
                ON s.studentID = e.studentID

            LEFT JOIN grades g
                ON g.enrollmentID = e.enrollmentID
               AND g.teacherID = :teacherID
               AND g.dateDeleted IS NULL

            WHERE e.sectionID = :sectionID
              AND e.subjectID = :subjectID
              AND e.semester = :semester

              /*
              |--------------------------------------------------------------
              | ONLY ACTIVE ENROLLMENTS
              |--------------------------------------------------------------
              */
              AND e.status = 'Active'
              AND e.dateDeleted IS NULL

              /*
              |--------------------------------------------------------------
              | IMPORTANT: EXCLUDE ARCHIVED STUDENTS
              |--------------------------------------------------------------
              |
              | student.php archives students by setting:
              |
              | dateDeleted = NOW()
              | isActive    = 0
              | isEnroll    = 0
              |
              | All three conditions are checked here.
              |--------------------------------------------------------------
              */
              AND s.dateDeleted IS NULL
              AND s.isActive = 1
              AND s.isEnroll = 1

            ORDER BY
                s.lName ASC,
                s.fName ASC
        ", $gradeParams);

        /*
        |--------------------------------------------------------------------------
        | GRADE STATISTICS
        |--------------------------------------------------------------------------
        */
        $graded = array_values(
            array_filter(
                $gradeRows,
                fn($r) =>
                    $r['gradeEquivalent'] !== null &&
                    $r['gradeEquivalent'] !== ''
            )
        );

        $passed = array_values(
            array_filter(
                $graded,
                fn($r) => $r['remarks'] === 'Passed'
            )
        );

        $failed = array_values(
            array_filter(
                $graded,
                fn($r) => $r['remarks'] === 'Failed'
            )
        );

        /*
        |--------------------------------------------------------------------------
        | EQUIVALENT GRADES
        |--------------------------------------------------------------------------
        */
        $equivs = [];

        foreach ($graded as $row) {
            if (
                $row['gradeEquivalent'] !== null &&
                $row['gradeEquivalent'] !== ''
            ) {
                $equivs[] = (float)$row['gradeEquivalent'];
            }
        }

        /*
        |--------------------------------------------------------------------------
        | CLASS AVERAGE / HIGHEST / LOWEST
        |--------------------------------------------------------------------------
        */
        if (count($equivs) > 0) {

            $classAvg = round(
                array_sum($equivs) / count($equivs),
                4
            );

            $highest = min($equivs);
            $lowest  = max($equivs);

        } else {

            $classAvg = 0;
            $highest  = 0;
            $lowest   = 0;
        }

        /*
        |--------------------------------------------------------------------------
        | GRADE DISTRIBUTION
        |--------------------------------------------------------------------------
        */
        foreach ($equivs as $e) {

            if ($e >= 1.00 && $e <= 1.75) {

                $dist['1.00–1.75']++;

            } elseif ($e >= 2.00 && $e <= 2.75) {

                $dist['2.00–2.75']++;

            } elseif ($e == 3.00) {

                $dist['3.00']++;

            } else {

                $dist['5.00']++;
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| DOWNLOAD MODE
|--------------------------------------------------------------------------
*/
if ($downloadMode && $report):
    notifyUsersByRole('Admin', 'Teacher Report Generated', $user['name'] . ' generated a class report for ' . ($report['subjectCode'] ?? 'a subject') . '.', 'Report', 'Teacher');
    notifyUsersByRole('Registrar', 'Teacher Report Generated', $user['name'] . ' generated a class report for ' . ($report['subjectCode'] ?? 'a subject') . '.', 'Report', 'Teacher');
    $pdfFilename = 'class_report_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $report['sectionName']) . '_' . date('Ymd_His') . '.pdf';
    $backParams = $_GET;
    unset($backParams['download']);
    $backHref = 'class_report.php' . (!empty($backParams) ? '?' . http_build_query($backParams) : '');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Class Performance Report</title>
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>assets/img/neust-logo.png" />
    <link href="https://fonts.googleapis.com/css2?family=Source+Sans+3:wght@400;600;700&display=swap" rel="stylesheet" />
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Source Sans 3', sans-serif; font-size: 12.5px; color: #111; background: #f4f6fb; padding: 24px; min-height: 100vh; }
        .status-bar { max-width: 860px; margin: 0 auto 14px; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
        .status-bar .msg { font-size: 13px; color: #334155; }
        .status-bar .msg.done { color: #15803d; font-weight: 600; }
        .status-bar a { display: inline-flex; align-items: center; gap: 8px; padding: 8px 14px; border: 1px solid #1a3a8c; border-radius: 6px; color: #1a3a8c; text-decoration: none; font-weight: 600; font-size: 13px; }
        .page-shell { max-width: 860px; margin: 0 auto; background: #fff; border: 1px solid #d6d9e6; padding: 24px; box-shadow: 0 18px 35px rgba(16,24,40,.08); }
        .print-header { display: grid; grid-template-columns: 78px 1fr; align-items: center; gap: 16px; margin-bottom: 18px; }
        .print-logo { width: 78px; flex-shrink: 0; }
        .print-logo img { width: 100%; height: auto; display: block; }
        .print-title { text-align: center; }
        .print-title .top-label { font-size: 11px; color: #555; margin-bottom: 4px; letter-spacing: .9px; }
        .print-title .main-title { font-size: 18px; font-weight: 700; color: #111; letter-spacing: .4px; line-height: 1.15; margin-bottom: 4px; }
        .print-title .brand { font-size: 13px; font-weight: 700; color: #111; letter-spacing: .8px; margin-bottom: 4px; }
        .print-title .sub-label { font-size: 11px; color: #555; margin-bottom: 2px; }
        .report-label { font-size: 18px; font-weight: 700; text-transform: uppercase; letter-spacing: 1.1px; margin: 18px 0 10px; text-align: center; color: #111; }
        .meta { font-size: 12px; color: #222; margin-bottom: 18px; line-height: 1.55; }
        .sum { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; margin-bottom: 16px; }
        .sb { padding: 10px; border: 1px solid #e2e8f0; border-radius: 6px; text-align: center; background: #f8fafc; }
        .sv { font-size: 20px; font-weight: 700; color: #1a3a8c; }
        .sl { font-size: 10px; color: #666; margin-top: 2px; }
        table { width: 100%; border-collapse: collapse; border: 1px solid #cbd5e1; }
        th { background: #e2e8f0; color: #111; padding: 8px 12px; font-size: 10.5px; text-transform: uppercase; letter-spacing: .5px; }
        td { padding: 8px 12px; border-bottom: 1px solid #e2e8f0; }
        tr:nth-child(even) td { background: #f8fafc; }
        .p { color: #15803d; font-weight: 700; }
        .f { color: #dc2626; font-weight: 700; }
        .sig { display: grid; grid-template-columns: 1fr 1fr; gap: 50px; margin-top: 28px; }
        .sl2 { border-top: 1px solid #333; padding-top: 6px; text-align: center; font-size: 11px; }
        .ft { margin-top: 18px; border-top: 1px solid #ddd; padding-top: 10px; font-size: 10.5px; color: #888; display: flex; justify-content: space-between; }
    </style>
</head>
<body>
<div class="status-bar">
    <div class="msg" id="status-msg">Generating PDF, please wait…</div>
    <a href="<?= htmlspecialchars($backHref) ?>">← Back</a>
</div>
<div class="page-shell" id="pdf-content">
    <div class="print-header">
        <div class="print-logo"><img src="<?= BASE_URL ?>assets/img/neust-logo.png" alt="NEUST Logo" /></div>
        <div class="print-title">
            <div class="top-label">Republic of the Philippines</div>
            <div class="main-title">Nueva Ecija University of Science and Technology</div>
            <div class="brand">Talavera Off-Campus</div>
            <div class="sub-label">Integrated Student Academic Records Management System</div>
        </div>
    </div>
    <div class="report-label">Class Performance Report</div>
    <div class="meta">
        <?= htmlspecialchars($report['subjectCode']) ?> — <?= htmlspecialchars($report['subjectName']) ?>
        &bull; Section: <?= htmlspecialchars($report['sectionName']) ?>
        &bull; <?= htmlspecialchars($report['programCode']) ?>
        &bull; <?= htmlspecialchars($report['academicYear']) ?> <?= $selSem == 1 ? '1st' : '2nd' ?> Semester
        <?php if (!empty($report['schedule'])): ?>&bull; <?= htmlspecialchars($report['schedule']) ?><?php endif; ?>
        <?php if (!empty($report['room'])): ?>&bull; Room <?= htmlspecialchars($report['room']) ?><?php endif; ?>
    </div>
    <div class="sum">
        <div class="sb"><div class="sv"><?= count($gradeRows) ?></div><div class="sl">Total Students</div></div>
        <div class="sb"><div class="sv" style="color:#15803d;"><?= count($passed) ?></div><div class="sl">Passed</div></div>
        <div class="sb"><div class="sv" style="color:#dc2626;"><?= count($failed) ?></div><div class="sl">Failed</div></div>
        <div class="sb"><div class="sv"><?= $classAvg ? number_format($classAvg, 4) : '—' ?></div><div class="sl">Class Avg GWA</div></div>
    </div>
    <table>
        <thead>
            <tr>
                <th>#</th><th>Student Name</th><th>Student No.</th><th style="text-align:center;">MT</th><th style="text-align:center;">FN</th><th style="text-align:center;">Grade</th><th style="text-align:center;">Equiv.</th><th style="text-align:center;">Remarks</th><th style="text-align:center;">Status</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($gradeRows as $i => $r): ?>
            <tr>
                <td><?= $i + 1 ?></td>
                <td style="font-weight:600;"><?= htmlspecialchars($r['fullName']) ?></td>
                <td><?= htmlspecialchars($r['studentNo']) ?></td>
                <td style="text-align:center;"><?= $r['midterm'] !== null ? htmlspecialchars($r['midterm']) : '—' ?></td>
                <td style="text-align:center;"><?= $r['finals'] !== null ? htmlspecialchars($r['finals']) : '—' ?></td>
                <td style="text-align:center;font-weight:700;"><?= $r['computedGrade'] !== null ? number_format((float)$r['computedGrade'], 2) : '—' ?></td>
                <td style="text-align:center;font-weight:700;" class="<?= ($r['gradeEquivalent'] !== null && (float)$r['gradeEquivalent'] <= 3) ? 'p' : (($r['gradeEquivalent'] !== null) ? 'f' : '') ?>"><?= $r['gradeEquivalent'] !== null ? number_format((float)$r['gradeEquivalent'], 2) : '—' ?></td>
                <td style="text-align:center;" class="<?= $r['remarks'] === 'Passed' ? 'p' : (($r['remarks'] === 'Failed') ? 'f' : '') ?>"><?= !empty($r['remarks']) ? htmlspecialchars($r['remarks']) : '—' ?></td>
                <td style="text-align:center;"><?= in_array($r['status'] ?? '', ['Posted', 'Approved'], true) ? 'Posted' : (!empty($r['status']) ? htmlspecialchars($r['status']) : 'Not Graded') ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <div class="sig">
        <div><div class="sl2">Prepared by: <?= htmlspecialchars($user['name']) ?><br>Subject Teacher</div></div>
        <div><div class="sl2">Noted by:<br>Registrar</div></div>
    </div>
    <div class="ft"><span>System-generated report. Requires official certification for formal use.</span><span>NEUST Talavera &bull; <?= date('Y') ?></span></div>
</div>
<script>
let isGenerating = false;
window.addEventListener('load', function () {
    if (isGenerating) return;
    isGenerating = true;
    const element = document.getElementById('pdf-content');
    const opt = {
        margin: [10, 8, 10, 8],
        filename: '<?= addslashes($pdfFilename) ?>',
        image: { type: 'jpeg', quality: 0.98 },
        html2canvas: { scale: 2, useCORS: true },
        jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' },
        pagebreak: { mode: ['avoid-all', 'css', 'legacy'] }
    };
    document.getElementById('status-msg').textContent = 'Generating PDF, please wait…';
    html2pdf().set(opt).from(element).save().then(function () {
        document.getElementById('status-msg').textContent = '✓ PDF downloaded.';
        document.getElementById('status-msg').classList.add('done');
    }).catch(function () {
        document.getElementById('status-msg').textContent = 'Something went wrong. Please refresh to try again.';
    });
});
</script>
</body>
</html>
<?php exit; endif;

/*
|--------------------------------------------------------------------------
| NORMAL PAGE
|--------------------------------------------------------------------------
*/
$pageTitle  = 'Class Report';
$activeMenu = 'class_report.php';

require_once ROOT . '/includes/header.php';
?>

<!-- =========================================================
     GENERATE REPORT CARD
========================================================= -->

<div
    class="card"
    style="margin-bottom:16px;"
>

    <div
        class="card-header"
        style="
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            flex-wrap:wrap;
        "
    >

        <div class="card-title">

            <i class="fas fa-filter"></i>

            Generate Report

        </div>

        <?php if ($report): ?>

            <div
                style="
                    display:flex;
                    align-items:center;
                    gap:8px;
                    flex-wrap:wrap;
                "
            >

                <a
                    href="class_report.php"
                    class="btn btn-outline"
                >

                    <i class="fas fa-arrow-left"></i>

                    Back

                </a>

                <a
                    href="?<?= htmlspecialchars(
                        http_build_query(
                            array_merge(
                                $_GET,
                                ['download' => 1]
                            )
                        )
                    ) ?>"
                    target="_blank"
                    class="btn btn-gold"
                >

                    <i class="fas fa-file-pdf"></i>

                    Download PDF

                </a>

            </div>

        <?php endif; ?>

    </div>

    <div
        class="card-body"
        style="padding:16px 20px;"
    >

        <form
            method="GET"
            style="
                display:grid;
                grid-template-columns:1.5fr 1.5fr 1fr auto;
                gap:12px;
                align-items:flex-end;
            "
        >

            <!-- SECTION -->

            <div>

                <label class="form-label">
                    Section
                </label>

                <select
                    name="section_id"
                    class="form-control"
                    onchange="this.form.submit()"
                >

                    <option value="">
                        — Select —
                    </option>

                    <?php foreach ($mySections as $sec): ?>

                        <option
                            value="<?= (int)$sec['sectionID'] ?>"
                            <?= $selSection == $sec['sectionID']
                                ? 'selected'
                                : ''
                            ?>
                        >

                            <?= htmlspecialchars(
                                $sec['sectionName']
                                . ' ('
                                . $sec['programCode']
                                . ') — '
                                . $sec['academicYear']
                            ) ?>

                        </option>

                    <?php endforeach; ?>

                </select>

            </div>

            <!-- SUBJECT -->

            <div>

                <label class="form-label">
                    Subject (My Assignments)
                </label>

                <select
                    name="subject_id"
                    class="form-control"
                    <?= empty($mySubjectsInSection)
                        ? 'disabled'
                        : ''
                    ?>
                >

                    <option value="">
                        — Select —
                    </option>

                    <?php foreach ($mySubjectsInSection as $sub): ?>

                        <option
                            value="<?= (int)$sub['subjectID'] ?>"
                            <?= $selSubject == $sub['subjectID']
                                ? 'selected'
                                : ''
                            ?>
                        >

                            [
                            <?= $sub['semester'] == 1
                                ? '1st'
                                : '2nd'
                            ?>S]

                            <?= htmlspecialchars(
                                $sub['subjectCode']
                                . ' – '
                                . $sub['subjectName']
                            ) ?>

                        </option>

                    <?php endforeach; ?>

                </select>

            </div>

            <!-- SEMESTER -->

            <div>

                <label class="form-label">
                    Semester
                </label>

                <select
                    name="semester"
                    class="form-control"
                    <?= empty($mySubjectsInSection)
                        ? 'disabled'
                        : ''
                    ?>
                >

                    <option value="">
                        —
                    </option>

                    <option
                        value="1"
                        <?= $selSem === 1
                            ? 'selected'
                            : ''
                        ?>
                    >
                        1st
                    </option>

                    <option
                        value="2"
                        <?= $selSem === 2
                            ? 'selected'
                            : ''
                        ?>
                    >
                        2nd
                    </option>

                </select>

            </div>

            <button
                type="submit"
                class="btn btn-primary"
            >

                <i class="fas fa-chart-bar"></i>

                View

            </button>

        </form>

    </div>

</div>


<?php if ($report && !empty($gradeRows)): ?>

<!-- =========================================================
     STATISTICS
========================================================= -->

<div
    class="stat-grid"
    style="
        grid-template-columns:repeat(5,1fr);
        margin-bottom:16px;
    "
>

    <?php

    $statCards = [

        [
            count($gradeRows),
            'Total Students',
            'fa-users',
            'blue'
        ],

        [
            count($graded),
            'Graded',
            'fa-check',
            'green'
        ],

        [
            count($passed),
            'Passed',
            'fa-thumbs-up',
            'green'
        ],

        [
            count($failed),
            'Failed',
            'fa-times-circle',
            'red'
        ],

        [
            $classAvg
                ? number_format($classAvg, 4)
                : 'N/A',
            'Class Avg GWA',
            'fa-calculator',
            'blue'
        ]

    ];

    foreach ($statCards as [$v, $l, $i, $c]):

    ?>

        <div class="stat-card">

            <div class="stat-icon <?= $c ?>">

                <i class="fas <?= $i ?>"></i>

            </div>

            <div class="stat-info">

                <div
                    class="value"
                    style="
                        font-size:
                        <?= strlen((string)$v) > 5
                            ? '18px'
                            : '28px'
                        ?>;
                    "
                >

                    <?= htmlspecialchars((string)$v) ?>

                </div>

                <div class="label">

                    <?= htmlspecialchars($l) ?>

                </div>

            </div>

        </div>

    <?php endforeach; ?>

</div>


<!-- =========================================================
     DISTRIBUTION + STUDENT TABLE
========================================================= -->

<div
    style="
        display:grid;
        grid-template-columns:240px 1fr;
        gap:16px;
        align-items:start;
    "
>

    <!-- =====================================================
         DISTRIBUTION
    ====================================================== -->

    <div class="card">

        <div class="card-header">

            <div class="card-title">

                <i class="fas fa-chart-bar"></i>

                Distribution

            </div>

        </div>

        <div
            class="card-body"
            style="padding:16px 20px;"
        >

            <?php

            $total = array_sum($dist);

            $colors = [

                '1.00–1.75' => 'var(--gold)',

                '2.00–2.75' => 'var(--blue)',

                '3.00' => 'var(--success)',

                '5.00' => 'var(--danger)'

            ];

            $labels = [

                '1.00–1.75' => 'Honors',

                '2.00–2.75' => 'Good',

                '3.00' => 'Passing',

                '5.00' => 'Failed'

            ];

            foreach ($dist as $range => $cnt):

                $pct = $total > 0
                    ? round(($cnt / $total) * 100)
                    : 0;

            ?>

                <div
                    style="
                        margin-bottom:12px;
                    "
                >

                    <div
                        style="
                            display:flex;
                            justify-content:space-between;
                            font-size:12.5px;
                            margin-bottom:4px;
                        "
                    >

                        <span style="font-weight:600;">

                            <?= htmlspecialchars($range) ?>

                            <span
                                style="
                                    color:var(--text-muted);
                                    font-weight:400;
                                "
                            >

                                (
                                <?= htmlspecialchars($labels[$range]) ?>
                                )

                            </span>

                        </span>

                        <span style="font-weight:700;">

                            <?= $cnt ?>

                        </span>

                    </div>

                    <div
                        style="
                            height:9px;
                            background:var(--border);
                            border-radius:4px;
                            overflow:hidden;
                        "
                    >

                        <div
                            style="
                                height:100%;
                                width:<?= $pct ?>%;
                                background:<?= $colors[$range] ?>;
                                border-radius:4px;
                            "
                        ></div>

                    </div>

                </div>

            <?php endforeach; ?>


            <?php if ($highest > 0 && $lowest > 0): ?>

                <div
                    style="
                        display:grid;
                        grid-template-columns:1fr 1fr;
                        gap:8px;
                        margin-top:14px;
                        padding-top:14px;
                        border-top:1px solid var(--border);
                    "
                >

                    <div
                        style="
                            text-align:center;
                            padding:10px;
                            background:var(--gold-light);
                            border-radius:8px;
                        "
                    >

                        <div
                            style="
                                font-size:20px;
                                font-weight:700;
                                color:var(--gold-deep);
                            "
                        >

                            <?= number_format($highest, 2) ?>

                        </div>

                        <div
                            style="
                                font-size:11px;
                                color:var(--text-muted);
                            "
                        >

                            Best GWA

                        </div>

                    </div>


                    <div
                        style="
                            text-align:center;
                            padding:10px;
                            background:#fef2f2;
                            border-radius:8px;
                        "
                    >

                        <div
                            style="
                                font-size:20px;
                                font-weight:700;
                                color:var(--danger);
                            "
                        >

                            <?= number_format($lowest, 2) ?>

                        </div>

                        <div
                            style="
                                font-size:11px;
                                color:var(--text-muted);
                            "
                        >

                            Lowest GWA

                        </div>

                    </div>

                </div>

            <?php endif; ?>

        </div>

    </div>


    <!-- =====================================================
         STUDENT TABLE
    ====================================================== -->

    <div class="card">

        <div class="card-header">

            <div class="card-title">

                <i class="fas fa-table"></i>

                <?= htmlspecialchars($report['subjectCode']) ?>

                —

                <?= htmlspecialchars($report['sectionName']) ?>

            </div>

        </div>

        <div style="overflow-x:auto;">

            <table class="data-table">

                <thead>

                    <tr>

                        <th>
                            #
                        </th>

                        <th>
                            Student
                        </th>

                        <th>
                            Student No.
                        </th>

                        <th style="text-align:center;">
                            MT
                        </th>

                        <th style="text-align:center;">
                            FN
                        </th>

                        <th style="text-align:center;">
                            Grade
                        </th>

                        <th style="text-align:center;">
                            Equiv.
                        </th>

                        <th style="text-align:center;">
                            Remarks
                        </th>

                        <th style="text-align:center;">
                            Status
                        </th>

                    </tr>

                </thead>

                <tbody>

                <?php foreach ($gradeRows as $i => $r): ?>

                    <tr>

                        <td
                            style="
                                color:var(--text-muted);
                            "
                        >

                            <?= $i + 1 ?>

                        </td>


                        <td
                            style="
                                font-weight:700;
                            "
                        >

                            <?= htmlspecialchars(
                                $r['fullName']
                            ) ?>

                        </td>


                        <td
                            style="
                                color:var(--blue);
                                font-weight:600;
                            "
                        >

                            <?= htmlspecialchars(
                                $r['studentNo']
                            ) ?>

                        </td>


                        <td style="text-align:center;">

                            <?= $r['midterm'] !== null
                                ? htmlspecialchars(
                                    $r['midterm']
                                )
                                : '—'
                            ?>

                        </td>


                        <td style="text-align:center;">

                            <?= $r['finals'] !== null
                                ? htmlspecialchars(
                                    $r['finals']
                                )
                                : '—'
                            ?>

                        </td>


                        <td
                            style="
                                text-align:center;
                                font-size:16px;
                                font-weight:700;
                                color:var(--blue);
                            "
                        >

                            <?= $r['computedGrade'] !== null
                                ? number_format(
                                    (float)$r['computedGrade'],
                                    2
                                )
                                : '—'
                            ?>

                        </td>


                        <td
                            style="
                                text-align:center;
                                font-size:18px;
                                font-weight:700;
                                color:
                                <?=
                                    (
                                        $r['gradeEquivalent'] !== null
                                        && (float)$r['gradeEquivalent'] <= 3
                                    )
                                        ? 'var(--success)'
                                        : (
                                            $r['gradeEquivalent'] !== null
                                                ? 'var(--danger)'
                                                : 'var(--text-muted)'
                                        )
                                ?>;
                            "
                        >

                            <?= $r['gradeEquivalent'] !== null
                                ? number_format(
                                    (float)$r['gradeEquivalent'],
                                    2
                                )
                                : '—'
                            ?>

                        </td>


                        <td style="text-align:center;">

                            <?php

                            $rem = $r['remarks'] ?? '';

                            $rc = match ($rem) {

                                'Passed' =>
                                    'status-approved',

                                'Failed' =>
                                    'status-flagged',

                                default =>
                                    ''

                            };

                            if ($rem !== ''):

                            ?>

                                <span
                                    class="status-badge <?= $rc ?>"
                                >

                                    <?= htmlspecialchars($rem) ?>

                                </span>

                            <?php else: ?>

                                —

                            <?php endif; ?>

                        </td>


                        <td style="text-align:center;">

                            <?php

                            $st = $r['status'] ?? '';

                            if ($st !== ''):

                            ?>

                                <span
                                    class="status-badge <?= htmlspecialchars(
                                        gradeStatusClass($st)
                                    ) ?>"
                                >

                                    <?= htmlspecialchars(normalizeGradeStatusDisplay($st)) ?>

                                </span>

                            <?php else: ?>

                                —

                            <?php endif; ?>

                        </td>

                    </tr>

                <?php endforeach; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>


<?php elseif ($selSection && $selSubject && $selSem): ?>

<!-- =========================================================
     NO DATA
========================================================= -->

<div class="card">

    <div
        style="
            padding:40px;
            text-align:center;
            color:var(--text-muted);
        "
    >

        <i
            class="fas fa-inbox"
            style="
                font-size:40px;
                display:block;
                margin-bottom:12px;
                opacity:.2;
                color:var(--blue);
            "
        ></i>

        <div
            style="
                font-weight:600;
                margin-bottom:6px;
            "
        >

            No active students found for this class.

        </div>

        <div
            style="
                font-size:12px;
            "
        >

            Archived students are excluded from the Class Report.

        </div>

    </div>

</div>


<?php else: ?>

<!-- =========================================================
     INITIAL STATE
========================================================= -->

<div class="card">

    <div
        style="
            padding:48px;
            text-align:center;
            color:var(--text-muted);
        "
    >

        <i
            class="fas fa-chart-bar"
            style="
                font-size:44px;
                display:block;
                margin-bottom:14px;
                opacity:.2;
                color:var(--blue);
            "
        ></i>

        <div
            style="
                font-weight:600;
                margin-bottom:6px;
            "
        >

            Select a class to generate the report

        </div>

    </div>

</div>

<?php endif; ?>


<?php
require_once ROOT . '/includes/footer.php';
?>