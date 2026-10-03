<?php
define('ROOT', dirname(__DIR__, 2));

require_once ROOT . '/includes/config.php';
require_once ROOT . '/includes/functions.php';

requireAuth('Registrar');

$db   = Database::getInstance();
$user = getCurrentUser();


// ============================================================
// HELPER
// ============================================================

function e($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}


// ============================================================
// FILTERS
// ============================================================

$statusFilter = sanitize($_GET['status'] ?? '');

$progFilter = (int)(
    $_GET['program_id'] ?? 0
);

$semFilter = (int)(
    $_GET['semester'] ?? 0
);

$ayFilter = (int)(
    $_GET['acad_year_id'] ?? 0
);

$teachFilter = (int)(
    $_GET['teacher_id'] ?? 0
);

$search = sanitize(
    $_GET['search'] ?? ''
);


// ============================================================
// BUILD WHERE
//
// IMPORTANT:
//
// 1. Grade must not be deleted
// 2. Student must be active
// 3. Student must not be deleted
// 4. Enrollment must be active
// 5. Enrollment must not be deleted
//
// This removes archived/graduated students from approvals.
// ============================================================

$where = [

    'g.dateDeleted IS NULL',

    's.isActive = 1',

    's.dateDeleted IS NULL',

    'e.status = \'Active\'',

    'e.dateDeleted IS NULL'

];

$params = [];


// Status
if ($statusFilter !== '') {
    $statusMap = [
        'Posted' => ['Posted', 'Approved'],
        'Pending' => ['Pending', 'Submitted'],
        'Returned' => ['Returned', 'Flagged'],
    ];

    $values = $statusMap[$statusFilter] ?? [$statusFilter];
    $placeholders = [];
    foreach ($values as $index => $value) {
        $placeholder = ':st' . $index;
        $placeholders[] = $placeholder;
        $params[$placeholder] = $value;
    }
    $where[] = 'g.status IN (' . implode(', ', $placeholders) . ')';
}


// Program
if ($progFilter > 0) {

    $where[] = 'e.programID = :pid';

    $params[':pid'] = $progFilter;
}


// Semester
if ($semFilter > 0) {

    $where[] = 'e.semester = :sem';

    $params[':sem'] = $semFilter;
}


// Academic Year
if ($ayFilter > 0) {

    $where[] = 'e.acadYearID = :ay';

    $params[':ay'] = $ayFilter;
}


// Teacher
if ($teachFilter > 0) {

    $where[] = 'g.teacherID = :tid';

    $params[':tid'] = $teachFilter;
}


// Search
if ($search !== '') {

    $where[] = "
        (
            s.studentNo LIKE :q1
            OR s.lName LIKE :q2
            OR s.fName LIKE :q3
            OR sub.subjectCode LIKE :q4
            OR sub.subjectName LIKE :q5
        )
    ";

    $searchValue = '%' . $search . '%';

    $params[':q1'] = $searchValue;
    $params[':q2'] = $searchValue;
    $params[':q3'] = $searchValue;
    $params[':q4'] = $searchValue;
    $params[':q5'] = $searchValue;
}


$whereSQL = implode(
    ' AND ',
    $where
);


// ============================================================
// GET GRADES
// ONLY ACTIVE STUDENTS + ACTIVE ENROLLMENTS
// ============================================================

$grades = $db->fetchAll("
    SELECT

        g.gradeID,
        g.midterm,
        g.finals,
        g.computedGrade,
        g.gradeEquivalent,
        g.remarks,
        CASE
            WHEN g.status IN ('Posted', 'Approved') THEN 'Posted'
            WHEN g.status IN ('Pending', 'Submitted') THEN 'Pending'
            WHEN g.status IN ('Returned', 'Flagged') THEN 'Returned'
            ELSE g.status
        END AS status,
        g.teacherRemarks,
        g.registrarNotes,
        g.submittedAt,
        g.approvedAt,

        CONCAT(
            s.lName,
            ', ',
            s.fName,
            ' ',
            COALESCE(
                SUBSTRING(s.mName,1,1),
                ''
            ),
            '.'
        ) AS studentName,

        s.studentNo,
        s.yearLevel,

        sub.subjectCode,
        sub.subjectName,
        sub.units,

        CONCAT(
            u.lName,
            ', ',
            u.fName
        ) AS teacherName,

        p.programCode,

        ay.academicYear,

        e.semester,

        sec.sectionName,

        CONCAT(
            av.lName,
            ', ',
            av.fName
        ) AS approvedByName

    FROM grades g


    INNER JOIN enrollments e
        ON e.enrollmentID = g.enrollmentID


    INNER JOIN students s
        ON s.studentID = g.studentID


    INNER JOIN subjects sub
        ON sub.subjectID = g.subjectID


    INNER JOIN users u
        ON u.userID = g.teacherID


    INNER JOIN programs p
        ON p.programID = e.programID


    INNER JOIN acad_years ay
        ON ay.acadYearID = e.acadYearID


    INNER JOIN sections sec
        ON sec.sectionID = e.sectionID


    LEFT JOIN users av
        ON av.userID = g.approvedByID


    WHERE $whereSQL


    ORDER BY
        g.submittedAt ASC,
        s.lName ASC,
        s.fName ASC


    LIMIT 150

", $params);


// ============================================================
// STATUS COUNTS
//
// IMPORTANT:
// Counts are also filtered to active students/enrollments.
// So the numbers on the tabs are correct.
// ============================================================

$counts = $db->fetchAll("
    SELECT
        CASE
            WHEN g.status IN ('Posted', 'Approved') THEN 'Posted'
            WHEN g.status IN ('Pending', 'Submitted') THEN 'Pending'
            WHEN g.status IN ('Returned', 'Flagged') THEN 'Returned'
            ELSE g.status
        END AS status,
        COUNT(*) AS cnt

    FROM grades g

    INNER JOIN students s
        ON s.studentID = g.studentID

    INNER JOIN enrollments e
        ON e.enrollmentID = g.enrollmentID

    WHERE g.dateDeleted IS NULL

      AND s.isActive = 1
      AND s.dateDeleted IS NULL

      AND e.status = 'Active'
      AND e.dateDeleted IS NULL

    GROUP BY CASE
        WHEN g.status IN ('Posted', 'Approved') THEN 'Posted'
        WHEN g.status IN ('Pending', 'Submitted') THEN 'Pending'
        WHEN g.status IN ('Returned', 'Flagged') THEN 'Returned'
        ELSE g.status
    END
");


$cmap = array_column(
    $counts,
    'cnt',
    'status'
);


// ============================================================
// PROGRAMS
// ============================================================

$programs = $db->fetchAll("
    SELECT
        programID,
        programCode

    FROM programs

    WHERE dateDeleted IS NULL

    ORDER BY programCode
");


// ============================================================
// ACADEMIC YEARS
// ============================================================

$acadYears = $db->fetchAll("
    SELECT
        acadYearID,
        academicYear

    FROM acad_years

    WHERE dateDeleted IS NULL

    ORDER BY academicYear DESC
");


// ============================================================
// TEACHERS
// ============================================================

$teachers = $db->fetchAll("
    SELECT
        userID,
        CONCAT(
            lName,
            ', ',
            fName
        ) AS name

    FROM users

    WHERE roleName = 'Teacher'
      AND isActive = 1
      AND dateDeleted IS NULL

    ORDER BY lName
");


$pageTitle  = 'Grade Monitoring';
$activeMenu = 'grade_approvals.php';

require_once ROOT . '/includes/header.php';

?>


<!-- ============================================================
     STATUS TABS
============================================================ -->

<div style="
    display:flex;
    gap:8px;
    margin-bottom:18px;
    flex-wrap:wrap;
">

<?php foreach (
    ['Posted', 'Pending', 'Returned']
    as $st
): ?>

<a
    href="?status=<?= urlencode($st) ?>"
    style="text-decoration:none;"
>

    <div style="
        padding:10px 18px;
        border-radius:10px;
        cursor:pointer;
        display:flex;
        align-items:center;
        gap:8px;
        font-size:13px;
        font-weight:600;

        background:
        <?= $statusFilter === $st
            ? 'var(--blue)'
            : '#fff'
        ?>;

        color:
        <?= $statusFilter === $st
            ? '#fff'
            : 'var(--text)'
        ?>;

        border:1.5px solid
        <?= $statusFilter === $st
            ? 'var(--blue)'
            : 'var(--border)'
        ?>;

        box-shadow:var(--shadow-sm);
    ">

        <span style="
            font-size:20px;
            font-weight:700;
        ">
            <?= number_format($cmap[$st] ?? 0) ?>
        </span>

        <?= e($st) ?>

    </div>

</a>

<?php endforeach; ?>

</div>


<!-- ============================================================
     SUBMITTED INFORMATION
============================================================ -->

<?php if ($statusFilter === 'Submitted'): ?>

<div style="
    margin-bottom:14px;
    padding:14px 18px;
    background:rgba(59,130,246,.08);
    border:1px solid rgba(59,130,246,.18);
    border-radius:12px;
    color:var(--text-muted);
    font-size:13px;
">

    <strong>Pending review:</strong>

    These grades belong only to
    <strong>active students with active enrollments/classes</strong>.

    Archived, graduated, inactive, or deleted students
    are automatically excluded.

</div>

<?php endif; ?>


<!-- ============================================================
     FILTERS
============================================================ -->

<div
    class="card"
    style="margin-bottom:14px;"
>

    <div
        class="card-body"
        style="padding:13px 18px;"
    >

        <form
            method="GET"
            style="
                display:flex;
                gap:10px;
                align-items:flex-end;
                flex-wrap:wrap;
            "
        >

            <input
                type="hidden"
                name="status"
                value="<?= e($statusFilter) ?>"
            />


            <!-- SEARCH -->

            <div>

                <label class="form-label">
                    Search
                </label>

                <div style="position:relative;">

                    <i
                        class="fas fa-search"
                        style="
                            position:absolute;
                            left:11px;
                            top:50%;
                            transform:translateY(-50%);
                            color:var(--text-muted);
                            font-size:12px;
                        "
                    ></i>

                    <input
                        type="text"
                        name="search"
                        class="form-control"
                        style="
                            padding-left:32px;
                            width:200px;
                        "
                        placeholder="Student/Subject…"
                        value="<?= e($search) ?>"
                    />

                </div>

            </div>


            <!-- PROGRAM -->

            <div>

                <label class="form-label">
                    Program
                </label>

                <select
                    name="program_id"
                    class="form-control"
                >

                    <option value="">
                        All
                    </option>

                    <?php foreach ($programs as $p): ?>

                    <option
                        value="<?= (int)$p['programID'] ?>"
                        <?= $progFilter == $p['programID']
                            ? 'selected'
                            : ''
                        ?>
                    >
                        <?= e($p['programCode']) ?>
                    </option>

                    <?php endforeach; ?>

                </select>

            </div>


            <!-- ACADEMIC YEAR -->

            <div>

                <label class="form-label">
                    Acad Year
                </label>

                <select
                    name="acad_year_id"
                    class="form-control"
                >

                    <option value="">
                        All
                    </option>

                    <?php foreach ($acadYears as $ay): ?>

                    <option
                        value="<?= (int)$ay['acadYearID'] ?>"
                        <?= $ayFilter == $ay['acadYearID']
                            ? 'selected'
                            : ''
                        ?>
                    >
                        <?= e($ay['academicYear']) ?>
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
                >

                    <option value="">
                        Both
                    </option>

                    <option
                        value="1"
                        <?= $semFilter === 1
                            ? 'selected'
                            : ''
                        ?>
                    >
                        1st
                    </option>

                    <option
                        value="2"
                        <?= $semFilter === 2
                            ? 'selected'
                            : ''
                        ?>
                    >
                        2nd
                    </option>

                </select>

            </div>


            <!-- TEACHER -->

            <div>

                <label class="form-label">
                    Teacher
                </label>

                <select
                    name="teacher_id"
                    class="form-control"
                >

                    <option value="">
                        All
                    </option>

                    <?php foreach ($teachers as $t): ?>

                    <option
                        value="<?= (int)$t['userID'] ?>"
                        <?= $teachFilter == $t['userID']
                            ? 'selected'
                            : ''
                        ?>
                    >
                        <?= e($t['name']) ?>
                    </option>

                    <?php endforeach; ?>

                </select>

            </div>


            <button
                type="submit"
                class="btn btn-primary btn-sm"
            >
                <i class="fas fa-filter"></i>

                Filter
            </button>


            <a
                href="grade_approvals.php"
                class="btn btn-outline btn-sm"
                title="Clear filters"
            >
                <i class="fas fa-times"></i>
            </a>

        </form>

    </div>

</div>


<!-- ============================================================
     BULK BAR
============================================================ -->

<?php if (
    $statusFilter === 'Submitted'
    && !empty($grades)
): ?>

<div style="
    display:flex;
    align-items:center;
    gap:12px;
    margin-bottom:12px;
    padding:10px 16px;
    background:var(--blue-light);
    border-radius:10px;
">

    <label style="
        display:flex;
        align-items:center;
        gap:6px;
        font-size:13px;
        font-weight:600;
        cursor:pointer;
    ">

        <input
            type="checkbox"
            id="checkAll"
            onchange="toggleAll(this)"
            style="
                width:15px;
                height:15px;
            "
        />

        Select All
        (<?= count($grades) ?>)

    </label>


    <span style="font-size:12px;color:var(--text-muted);"><i class="fas fa-eye"></i> Viewing only. Admin manages grade changes.</span>

</div>

<?php endif; ?>


<!-- ============================================================
     TABLE
============================================================ -->

<div class="card">

<div style="overflow-x:auto;">

<?php if (empty($grades)): ?>

<div style="
    padding:48px;
    text-align:center;
    color:var(--text-muted);
">

    <i
        class="fas fa-check-circle"
        style="
            font-size:42px;
            display:block;
            margin-bottom:12px;
            color:var(--success);
            opacity:.4;
        "
    ></i>

    <div style="
        font-weight:600;
        margin-bottom:6px;
    ">
        No <?= e(strtolower($statusFilter)) ?> grades
    </div>

    <div style="font-size:13px;">

        No grades from active students/enrollments
        match the selected filters.

    </div>

</div>

<?php else: ?>


<table class="data-table">

<thead>

<tr>

<?php if ($statusFilter === 'Submitted'): ?>

<th>
    <input
        type="checkbox"
        id="hc"
        onchange="toggleAll(this)"
    />
</th>

<?php endif; ?>


<th>Student</th>

<th>Subject</th>

<th>Section / Term</th>

<th>Teacher</th>

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
    Teacher Notes
</th>

<th style="text-align:center;">
    Status
</th>

<th>
    Date
</th>

<th style="text-align:center;">
    Actions
</th>

</tr>

</thead>


<tbody>

<?php foreach ($grades as $g): ?>

<tr>


<?php if ($statusFilter === 'Submitted'): ?>

<td>

    <input
        type="checkbox"
        class="row-check"
        value="<?= (int)$g['gradeID'] ?>"
    />

</td>

<?php endif; ?>


<!-- STUDENT -->

<td>

    <div style="
        font-weight:700;
        font-size:13px;
    ">
        <?= e($g['studentName']) ?>
    </div>

    <div style="
        font-size:11px;
        color:var(--blue);
        font-weight:600;
    ">
        <?= e($g['studentNo']) ?>
    </div>

    <div style="
        font-size:11px;
        color:var(--text-muted);
    ">
        <?= e($g['programCode']) ?>

        · Yr

        <?= e($g['yearLevel']) ?>
    </div>

</td>


<!-- SUBJECT -->

<td>

    <div style="
        font-weight:700;
        color:var(--blue);
    ">
        <?= e($g['subjectCode']) ?>
    </div>

    <div style="
        font-size:11px;
        color:var(--text-muted);
    ">
        <?= e($g['subjectName']) ?>

        (<?= e($g['units']) ?>u)
    </div>

</td>


<!-- SECTION -->

<td style="font-size:12px;">

    <?= e($g['sectionName']) ?>

    <br>

    <span style="
        color:var(--text-muted);
    ">

        <?= e($g['academicYear']) ?>

        ·

        <?= $g['semester'] == 1
            ? '1st'
            : '2nd'
        ?>

        Sem

    </span>

</td>


<!-- TEACHER -->

<td style="font-size:12.5px;">

    <?= e($g['teacherName']) ?>

</td>


<!-- MIDTERM -->

<td style="
    text-align:center;
    font-weight:600;
">

    <?= $g['midterm'] !== null
        ? e($g['midterm'])
        : '—'
    ?>

</td>


<!-- FINALS -->

<td style="
    text-align:center;
    font-weight:600;
">

    <?= $g['finals'] !== null
        ? e($g['finals'])
        : '—'
    ?>

</td>


<!-- COMPUTED -->

<td style="
    text-align:center;
    font-size:16px;
    font-weight:700;
    color:var(--blue);
">

    <?= $g['computedGrade'] !== null
        ? number_format(
            (float)$g['computedGrade'],
            2
        )
        : '—'
    ?>

</td>


<!-- EQUIVALENT -->

<td style="
    text-align:center;
    font-size:20px;
    font-weight:700;
    color:
    <?= ($g['gradeEquivalent'] ?? 5) <= 3
        ? 'var(--success)'
        : 'var(--danger)'
    ?>;
">

    <?= $g['gradeEquivalent'] !== null
        ? number_format(
            (float)$g['gradeEquivalent'],
            2
        )
        : '—'
    ?>

</td>


<!-- REMARKS -->

<td style="text-align:center;">

<?php

$r = $g['remarks'] ?? '';

$rc = match ($r) {

    'Passed' => 'status-approved',

    'Failed' => 'status-flagged',

    default => ''

};

?>

<?= $r
    ? "<span class='status-badge $rc'>"
      . e($r)
      . "</span>"
    : '—'
?>

</td>


<!-- TEACHER NOTES -->

<td style="
    max-width:180px;
    word-break:break-word;
    font-size:12px;
    color:var(--text-muted);
">

    <?= !empty($g['teacherRemarks'])
        ? e($g['teacherRemarks'])
        : '—'
    ?>

</td>


<!-- STATUS -->

<td style="text-align:center;">

    <span
        class="status-badge <?= e(
            gradeStatusClass($g['status'])
        ) ?>"
    >
        <?= e($g['status']) ?>
    </span>


    <?php if (!empty($g['registrarNotes'])): ?>

    <div
        style="
            font-size:10px;
            color:var(--danger);
            margin-top:2px;
        "
        title="<?= e($g['registrarNotes']) ?>"
    >
        <i class="fas fa-sticky-note"></i>
    </div>

    <?php endif; ?>

</td>


<!-- DATE -->

<td style="
    font-size:11.5px;
    color:var(--text-muted);
">

    <?= ($g['approvedAt'] ?? $g['submittedAt'])
        ? date(
            'M d, Y',
            strtotime(
                $g['approvedAt']
                ?? $g['submittedAt']
            )
        )
        : '—'
    ?>


    <?php if (!empty($g['approvedByName'])): ?>

    <div style="
        font-size:10px;
        color:var(--success);
    ">
        <?= e($g['approvedByName']) ?>
    </div>

    <?php endif; ?>

</td>


<!-- ACTIONS -->

<td style="text-align:center;">

    <div style="
        display:flex;
        gap:4px;
        justify-content:center;
    ">


        <button
            class="btn btn-outline btn-sm"
            onclick='openBreakdown(
                <?= json_encode(
                    $g,
                    JSON_HEX_TAG |
                    JSON_HEX_APOS |
                    JSON_HEX_QUOT |
                    JSON_HEX_AMP
                ) ?>
            )'
            title="View Grade Breakdown"
        >
            <i class="fas fa-chart-bar"></i>
        </button>


        <?php if (false): ?>


        <button
            class="btn btn-success btn-sm"
            onclick="approveGrade(<?= (int)$g['gradeID'] ?>)"
            title="Approve"
        >
            <i class="fas fa-check"></i>
        </button>


        <button
            class="btn btn-danger btn-sm"
            onclick='openFlagModal(
                <?= (int)$g['gradeID'] ?>,
                <?= json_encode(
                    $g['subjectName'],
                    JSON_HEX_TAG |
                    JSON_HEX_APOS |
                    JSON_HEX_QUOT |
                    JSON_HEX_AMP
                ) ?>
            )'
            title="Flag"
        >
            <i class="fas fa-flag"></i>
        </button>


        <button
            class="btn btn-outline btn-sm"
            onclick="openReturnModal(<?= (int)$g['gradeID'] ?>)"
            title="Return"
        >
            <i class="fas fa-undo"></i>
        </button>


        <?php endif; ?>

    </div>

</td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

<?php endif; ?>

</div>

</div>


<!-- ============================================================
     GRADE BREAKDOWN MODAL
============================================================ -->

<div
    id="breakdownModal"
    style="
        display:none;
        position:fixed;
        inset:0;
        background:rgba(0,0,0,.55);
        z-index:1000;
        align-items:center;
        justify-content:center;
        backdrop-filter:blur(2px);
    "
>

<div style="
    background:#fff;
    border-radius:20px;
    padding:0;
    width:540px;
    max-width:95vw;
    max-height:90vh;
    overflow-y:auto;
    box-shadow:0 24px 80px rgba(0,0,0,.25);
">


<div style="
    padding:22px 26px 16px;
    border-bottom:1.5px solid var(--border);
    position:sticky;
    top:0;
    background:#fff;
    border-radius:20px 20px 0 0;
    z-index:1;
">

<div style="
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
">

<div>

<h3 style="
    font-family:'Playfair Display',serif;
    margin:0 0 2px;
    font-size:18px;
">
Grade Breakdown
</h3>

<p style="
    margin:0;
    font-size:12px;
    color:var(--text-muted);
">
Registrar verification view
</p>

</div>


<button
    onclick="closeBreakdown()"
    style="
        background:none;
        border:none;
        font-size:20px;
        cursor:pointer;
        color:var(--text-muted);
        line-height:1;
        padding:4px;
    "
>
×
</button>

</div>

</div>


<div style="padding:20px 26px;">

<!-- STUDENT / SUBJECT -->

<div style="
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:12px;
    margin-bottom:18px;
">

<div style="
    background:var(--bg);
    border-radius:10px;
    padding:12px 14px;
">

<div style="
    font-size:10px;
    text-transform:uppercase;
    letter-spacing:.06em;
    color:var(--text-muted);
    margin-bottom:4px;
">
Student
</div>

<div
    id="bd-studentName"
    style="
        font-weight:700;
        font-size:13px;
    "
></div>

<div
    id="bd-studentNo"
    style="
        font-size:11px;
        color:var(--blue);
        font-weight:600;
    "
></div>

<div
    id="bd-program"
    style="
        font-size:11px;
        color:var(--text-muted);
    "
></div>

</div>


<div style="
    background:var(--bg);
    border-radius:10px;
    padding:12px 14px;
">

<div style="
    font-size:10px;
    text-transform:uppercase;
    letter-spacing:.06em;
    color:var(--text-muted);
    margin-bottom:4px;
">
Subject
</div>

<div
    id="bd-subjectCode"
    style="
        font-weight:700;
        font-size:13px;
        color:var(--blue);
    "
></div>

<div
    id="bd-subjectName"
    style="
        font-size:11px;
        color:var(--text-muted);
    "
></div>

<div
    id="bd-term"
    style="
        font-size:11px;
        color:var(--text-muted);
        margin-top:2px;
    "
></div>

</div>

</div>


<!-- GRADE COMPUTATION -->

<div style="margin-bottom:18px;">

<div style="
    font-size:11px;
    font-weight:700;
    text-transform:uppercase;
    letter-spacing:.07em;
    color:var(--text-muted);
    margin-bottom:10px;
">
Grade Computation
</div>


<div style="
    background:var(--bg);
    border-radius:12px;
    padding:16px;
    margin-bottom:12px;
">


<!-- MIDTERM -->

<div style="margin-bottom:14px;">

<div style="
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-bottom:5px;
">

<div style="
    font-size:12px;
    font-weight:600;
">
Midterm Grade
</div>

<div
    id="bd-midterm"
    style="
        font-size:18px;
        font-weight:800;
        color:var(--blue);
    "
></div>

</div>


<div style="
    background:#e5e7eb;
    border-radius:99px;
    height:7px;
    overflow:hidden;
">

<div
    id="bd-midterm-bar"
    style="
        height:100%;
        border-radius:99px;
        background:var(--blue);
        transition:width .5s ease;
        width:0%;
    "
></div>

</div>


<div style="
    font-size:10px;
    color:var(--text-muted);
    margin-top:3px;
">
Weight: 50%
</div>

</div>


<!-- FINALS -->

<div>

<div style="
    display:flex;
    justify-content:space-between;
    align-items:center;
    margin-bottom:5px;
">

<div style="
    font-size:12px;
    font-weight:600;
">
Finals Grade
</div>

<div
    id="bd-finals"
    style="
        font-size:18px;
        font-weight:800;
        color:#8b5cf6;
    "
></div>

</div>


<div style="
    background:#e5e7eb;
    border-radius:99px;
    height:7px;
    overflow:hidden;
">

<div
    id="bd-finals-bar"
    style="
        height:100%;
        border-radius:99px;
        background:#8b5cf6;
        transition:width .5s ease;
        width:0%;
    "
></div>

</div>


<div style="
    font-size:10px;
    color:var(--text-muted);
    margin-top:3px;
">
Weight: 50%
</div>

</div>

</div>


<!-- FORMULA -->

<div style="
    background:#f0f9ff;
    border:1.5px solid #bae6fd;
    border-radius:10px;
    padding:14px 16px;
    font-family:monospace;
">

<div style="
    font-size:11px;
    color:#0369a1;
    font-weight:700;
    margin-bottom:8px;
">
FORMULA
</div>


<div
    id="bd-formula"
    style="
        font-size:13px;
        color:#0c4a6e;
        line-height:1.7;
    "
></div>


<div style="
    border-top:1px solid #bae6fd;
    margin-top:10px;
    padding-top:10px;
    display:flex;
    justify-content:space-between;
    align-items:center;
">

<span style="
    font-size:12px;
    color:#0369a1;
    font-weight:600;
">
Computed Grade
</span>

<span
    id="bd-computed"
    style="
        font-size:22px;
        font-weight:900;
        color:#0369a1;
    "
></span>

</div>

</div>

</div>


<!-- EQUIVALENT / REMARKS -->

<div style="
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:10px;
    margin-bottom:18px;
">

<div
    id="bd-equiv-box"
    style="
        border-radius:10px;
        padding:14px;
        text-align:center;
    "
>

<div style="
    font-size:10px;
    text-transform:uppercase;
    letter-spacing:.06em;
    color:var(--text-muted);
    margin-bottom:4px;
">
Grade Equivalent
</div>

<div
    id="bd-equiv"
    style="
        font-size:36px;
        font-weight:900;
    "
></div>

</div>


<div
    id="bd-remarks-box"
    style="
        border-radius:10px;
        padding:14px;
        text-align:center;
    "
>

<div style="
    font-size:10px;
    text-transform:uppercase;
    letter-spacing:.06em;
    color:var(--text-muted);
    margin-bottom:4px;
">
Remarks
</div>

<div
    id="bd-remarks"
    style="
        font-size:22px;
        font-weight:800;
        margin-top:6px;
    "
></div>

</div>

</div>


<!-- TEACHER NOTES -->

<div style="margin-bottom:18px;">

<div style="
    font-size:11px;
    font-weight:700;
    text-transform:uppercase;
    letter-spacing:.07em;
    color:var(--text-muted);
    margin-bottom:6px;
">
Teacher's Notes
</div>

<div
    id="bd-teacherRemarks"
    style="
        background:var(--bg);
        border-radius:10px;
        padding:12px 14px;
        font-size:13px;
        color:var(--text-muted);
        font-style:italic;
    "
></div>

</div>


<!-- REGISTRAR NOTES -->

<div
    id="bd-regNoteWrap"
    style="
        display:none;
        margin-bottom:18px;
    "
>

<div style="
    font-size:11px;
    font-weight:700;
    text-transform:uppercase;
    letter-spacing:.07em;
    color:var(--danger);
    margin-bottom:6px;
">
Registrar Notes
</div>

<div
    id="bd-regNotes"
    style="
        background:#fef2f2;
        border:1.5px solid #fecaca;
        border-radius:10px;
        padding:12px 14px;
        font-size:13px;
        color:#b91c1c;
    "
></div>

</div>


<!-- META -->

<div style="
    display:flex;
    justify-content:space-between;
    font-size:11px;
    color:var(--text-muted);
    border-top:1.5px solid var(--border);
    padding-top:12px;
">

<div>
Submitted by:

<strong
    id="bd-teacher"
    style="color:var(--text);"
></strong>
</div>

<div>
Date:

<strong
    id="bd-date"
    style="color:var(--text);"
></strong>
</div>

</div>

</div>

</div>

</div>


<!-- ============================================================
     FLAG MODAL
============================================================ -->

<div
    id="flagModal"
    style="
        display:none;
        position:fixed;
        inset:0;
        background:rgba(0,0,0,.5);
        z-index:999;
        align-items:center;
        justify-content:center;
    "
>

<div style="
    background:#fff;
    border-radius:16px;
    padding:28px;
    width:460px;
    max-width:90vw;
    box-shadow:0 20px 60px rgba(0,0,0,.2);
">

<h3 style="
    font-family:'Playfair Display',serif;
    margin-bottom:8px;
">
Flag Grade Record
</h3>


<p style="
    color:var(--text-muted);
    font-size:13px;
    margin-bottom:14px;
">

Subject:

<strong id="flagSubjectName"></strong>

</p>


<input
    type="hidden"
    id="flagGradeID"
/>


<div style="margin-bottom:14px;">

<label class="form-label">

Reason *

</label>

<textarea
    id="flagReason"
    class="form-control"
    rows="3"
    placeholder="Describe the issue…"
></textarea>

</div>


<div style="
    display:flex;
    gap:8px;
    justify-content:flex-end;
">

<button
    class="btn btn-outline"
    onclick="closeFlagModal()"
>
Cancel
</button>


<button
    class="btn btn-danger"
    onclick="submitFlag()"
>

<i class="fas fa-flag"></i>

Flag

</button>

</div>

</div>

</div>


<!-- ============================================================
     RETURN MODAL
============================================================ -->

<div
    id="returnModal"
    style="
        display:none;
        position:fixed;
        inset:0;
        background:rgba(0,0,0,.5);
        z-index:999;
        align-items:center;
        justify-content:center;
    "
>

<div style="
    background:#fff;
    border-radius:16px;
    padding:28px;
    width:460px;
    max-width:90vw;
    box-shadow:0 20px 60px rgba(0,0,0,.2);
">

<h3 style="
    font-family:'Playfair Display',serif;
    margin-bottom:14px;
">
Return Grade to Teacher
</h3>


<input
    type="hidden"
    id="returnGradeID"
/>


<div style="margin-bottom:14px;">

<label class="form-label">
Return Notes
</label>

<textarea
    id="returnNotes"
    class="form-control"
    rows="3"
    placeholder="What needs correction?"
></textarea>

</div>


<div style="
    display:flex;
    gap:8px;
    justify-content:flex-end;
">

<button
    class="btn btn-outline"
    onclick="closeReturnModal()"
>
Cancel
</button>


<button
    class="btn btn-primary"
    onclick="submitReturn()"
>

<i class="fas fa-undo"></i>

Return

</button>

</div>

</div>

</div>


<!-- ============================================================
     BULK FLAG MODAL
============================================================ -->

<div
    id="bulkFlagModal"
    style="
        display:none;
        position:fixed;
        inset:0;
        background:rgba(0,0,0,.5);
        z-index:999;
        align-items:center;
        justify-content:center;
    "
>

<div style="
    background:#fff;
    border-radius:16px;
    padding:28px;
    width:460px;
    max-width:90vw;
    box-shadow:0 20px 60px rgba(0,0,0,.2);
">

<h3 style="
    font-family:'Playfair Display',serif;
    margin-bottom:14px;
">
Flag Selected Grades
</h3>


<div style="margin-bottom:14px;">

<label class="form-label">
Reason *
</label>

<textarea
    id="bulkFlagReason"
    class="form-control"
    rows="3"
    placeholder="Reason for all selected grades…"
></textarea>

</div>


<div style="
    display:flex;
    gap:8px;
    justify-content:flex-end;
">

<button
    class="btn btn-outline"
    onclick="
        document.getElementById(
            'bulkFlagModal'
        ).style.display='none'
    "
>
Cancel
</button>


<button
    class="btn btn-danger"
    onclick="doBulkFlag()"
>

<i class="fas fa-flag"></i>

Flag All

</button>

</div>

</div>

</div>


<script>


// ============================================================
// APPROVE
// ============================================================

function approveGrade(id)
{
    if (!confirm(
        'Approve this grade?'
    )) {
        return;
    }


    callAPI(
        {
            action: 'approve',
            gradeID: id
        },

        function()
        {
            showToast(
                'Approved.',
                'success'
            );

            setTimeout(
                function()
                {
                    location.reload();
                },
                1100
            );
        }
    );
}


// ============================================================
// FLAG
// ============================================================

function openFlagModal(id, name)
{
    document.getElementById(
        'flagGradeID'
    ).value = id;


    document.getElementById(
        'flagSubjectName'
    ).textContent = name;


    document.getElementById(
        'flagReason'
    ).value = '';


    document.getElementById(
        'flagModal'
    ).style.display = 'flex';
}


function closeFlagModal()
{
    document.getElementById(
        'flagModal'
    ).style.display = 'none';
}


function submitFlag()
{
    const reason =
        document.getElementById(
            'flagReason'
        ).value.trim();


    if (!reason) {

        showToast(
            'Reason required.',
            'error'
        );

        return;
    }


    callAPI(
        {
            action: 'flag',

            gradeID: parseInt(
                document.getElementById(
                    'flagGradeID'
                ).value
            ),

            reason: reason
        },

        function()
        {
            closeFlagModal();

            showToast(
                'Flagged.',
                'success'
            );

            setTimeout(
                function()
                {
                    location.reload();
                },
                1100
            );
        }
    );
}


// ============================================================
// RETURN
// ============================================================

function openReturnModal(id)
{
    document.getElementById(
        'returnGradeID'
    ).value = id;


    document.getElementById(
        'returnNotes'
    ).value = '';


    document.getElementById(
        'returnModal'
    ).style.display = 'flex';
}


function closeReturnModal()
{
    document.getElementById(
        'returnModal'
    ).style.display = 'none';
}


function submitReturn()
{
    const notes =
        document.getElementById(
            'returnNotes'
        ).value.trim();


    callAPI(
        {
            action: 'return',

            gradeID: parseInt(
                document.getElementById(
                    'returnGradeID'
                ).value
            ),

            notes: notes
        },

        function()
        {
            closeReturnModal();

            showToast(
                'Returned.',
                'success'
            );

            setTimeout(
                function()
                {
                    location.reload();
                },
                1100
            );
        }
    );
}


// ============================================================
// SELECT ALL
// ============================================================

function toggleAll(cb)
{
    document
        .querySelectorAll('.row-check')
        .forEach(
            function(c)
            {
                c.checked = cb.checked;
            }
        );
}


function getChecked()
{
    return Array
        .from(
            document.querySelectorAll(
                '.row-check:checked'
            )
        )
        .map(
            function(c)
            {
                return parseInt(c.value);
            }
        );
}


// ============================================================
// BULK APPROVE
// ============================================================

async function bulkApprove()
{
    const ids = getChecked();


    if (!ids.length) {

        showToast(
            'Select at least one.',
            'error'
        );

        return;
    }


    if (!confirm(
        `Approve ${ids.length} grade(s)?`
    )) {
        return;
    }


    const results = await Promise.all(
        ids.map(
            function(id)
            {
                return fetch(
                    '<?= e(BASE_URL) ?>api/grades.php',
                    {
                        method: 'POST',

                        headers: {
                            'Content-Type':
                                'application/json'
                        },

                        body: JSON.stringify(
                            {
                                action: 'approve',
                                gradeID: id
                            }
                        )
                    }
                )
                .then(
                    function(r)
                    {
                        return r.json();
                    }
                );
            }
        )
    );


    const failed =
        results.filter(
            function(r)
            {
                return !r.success;
            }
        );


    if (failed.length > 0) {

        showToast(
            `${failed.length} grade(s) could not be approved.`,
            'error'
        );

    } else {

        showToast(
            `${ids.length} approved.`,
            'success'
        );

    }


    setTimeout(
        function()
        {
            location.reload();
        },
        1200
    );
}


// ============================================================
// BULK FLAG
// ============================================================

function openBulkFlagModal()
{
    if (!getChecked().length) {

        showToast(
            'Select at least one.',
            'error'
        );

        return;
    }


    document.getElementById(
        'bulkFlagReason'
    ).value = '';


    document.getElementById(
        'bulkFlagModal'
    ).style.display = 'flex';
}


async function doBulkFlag()
{
    const ids = getChecked();

    const reason =
        document.getElementById(
            'bulkFlagReason'
        ).value.trim();


    if (!reason) {

        showToast(
            'Reason required.',
            'error'
        );

        return;
    }


    const results = await Promise.all(
        ids.map(
            function(id)
            {
                return fetch(
                    '<?= e(BASE_URL) ?>api/grades.php',
                    {
                        method: 'POST',

                        headers: {
                            'Content-Type':
                                'application/json'
                        },

                        body: JSON.stringify(
                            {
                                action: 'flag',
                                gradeID: id,
                                reason: reason
                            }
                        )
                    }
                )
                .then(
                    function(r)
                    {
                        return r.json();
                    }
                );
            }
        )
    );


    const failed =
        results.filter(
            function(r)
            {
                return !r.success;
            }
        );


    document.getElementById(
        'bulkFlagModal'
    ).style.display = 'none';


    if (failed.length > 0) {

        showToast(
            `${failed.length} grade(s) could not be flagged.`,
            'error'
        );

    } else {

        showToast(
            `${ids.length} flagged.`,
            'success'
        );

    }


    setTimeout(
        function()
        {
            location.reload();
        },
        1200
    );
}


// ============================================================
// GENERIC API
// ============================================================

function callAPI(data, cb)
{
    fetch(
        '<?= e(BASE_URL) ?>api/grades.php',
        {
            method: 'POST',

            headers: {
                'Content-Type':
                    'application/json'
            },

            body: JSON.stringify(data)
        }
    )

    .then(
        function(r)
        {
            return r.json();
        }
    )

    .then(
        function(d)
        {
            if (d.success) {

                cb(d);

            } else {

                showToast(
                    d.message ||
                    'Request failed.',
                    'error'
                );

            }
        }
    )

    .catch(
        function()
        {
            showToast(
                'Unable to connect to the server.',
                'error'
            );
        }
    );
}


// ============================================================
// CLOSE MODALS WHEN CLICKING OUTSIDE
// ============================================================

[
    'flagModal',
    'returnModal',
    'bulkFlagModal'
].forEach(
    function(id)
    {
        document
            .getElementById(id)
            ?.addEventListener(
                'click',
                function(e)
                {
                    if (e.target === this) {

                        this.style.display =
                            'none';

                    }
                }
            );
    }
);


// ============================================================
// GRADE BREAKDOWN
// ============================================================

function openBreakdown(g)
{
    const mt =
        parseFloat(g.midterm) || 0;


    const fn =
        parseFloat(g.finals) || 0;


    const computed =
        parseFloat(g.computedGrade)
        ||
        (
            (mt * 0.5)
            +
            (fn * 0.5)
        );


    const equiv =
        parseFloat(g.gradeEquivalent)
        || 0;


    const passed =
        (g.remarks || '')
            .toLowerCase()
            === 'passed';


    document.getElementById(
        'bd-studentName'
    ).textContent =
        g.studentName || '';


    document.getElementById(
        'bd-studentNo'
    ).textContent =
        g.studentNo || '';


    document.getElementById(
        'bd-program'
    ).textContent =
        (g.programCode || '')
        +
        ' · Year '
        +
        (g.yearLevel || '');


    document.getElementById(
        'bd-subjectCode'
    ).textContent =
        g.subjectCode || '';


    document.getElementById(
        'bd-subjectName'
    ).textContent =
        (g.subjectName || '')
        +
        ' ('
        +
        (g.units || 0)
        +
        ' units)';


    document.getElementById(
        'bd-term'
    ).textContent =
        (g.academicYear || '')
        +
        ' · '
        +
        (
            g.semester == 1
                ? '1st'
                : '2nd'
        )
        +
        ' Sem · '
        +
        (g.sectionName || '');


    document.getElementById(
        'bd-teacher'
    ).textContent =
        g.teacherName || '';


    document.getElementById(
        'bd-date'
    ).textContent =
        g.submittedAt
            ? new Date(
                g.submittedAt
            ).toLocaleDateString(
                'en-PH',
                {
                    year: 'numeric',
                    month: 'short',
                    day: 'numeric'
                }
            )
            : '—';


    document.getElementById(
        'bd-midterm'
    ).textContent =
        mt
            ? mt.toFixed(2)
            : '—';


    document.getElementById(
        'bd-finals'
    ).textContent =
        fn
            ? fn.toFixed(2)
            : '—';


    document.getElementById(
        'bd-midterm-bar'
    ).style.width =
        Math.min(
            Math.max(mt, 0),
            100
        )
        + '%';


    document.getElementById(
        'bd-finals-bar'
    ).style.width =
        Math.min(
            Math.max(fn, 0),
            100
        )
        + '%';


    document.getElementById(
        'bd-formula'
    ).innerHTML =

        `(Midterm &times; 50%) + (Finals &times; 50%)<br>` +

        `= (<strong>${mt.toFixed(2)}</strong> &times; 0.50) + ` +

        `(<strong>${fn.toFixed(2)}</strong> &times; 0.50)<br>` +

        `= <strong>${(
            mt * 0.5
        ).toFixed(2)}</strong> + ` +

        `<strong>${(
            fn * 0.5
        ).toFixed(2)}</strong>`;


    document.getElementById(
        'bd-computed'
    ).textContent =
        computed.toFixed(2);


    document.getElementById(
        'bd-equiv'
    ).textContent =
        equiv
            ? equiv.toFixed(2)
            : '—';


    const equivBox =
        document.getElementById(
            'bd-equiv-box'
        );


    const remBox =
        document.getElementById(
            'bd-remarks-box'
        );


    const borderColor =
        passed
            ? '#bbf7d0'
            : '#fecaca';


    const bg =
        passed
            ? '#f0fdf4'
            : '#fef2f2';


    const textCol =
        passed
            ? 'var(--success)'
            : 'var(--danger)';


    equivBox.style.background =
        bg;


    equivBox.style.border =
        '1.5px solid ' +
        borderColor;


    document.getElementById(
        'bd-equiv'
    ).style.color =
        textCol;


    remBox.style.background =
        bg;


    remBox.style.border =
        '1.5px solid ' +
        borderColor;


    document.getElementById(
        'bd-remarks'
    ).textContent =
        g.remarks || '—';


    document.getElementById(
        'bd-remarks'
    ).style.color =
        textCol;


    document.getElementById(
        'bd-teacherRemarks'
    ).textContent =
        g.teacherRemarks
            || 'No notes from teacher.';


    const regWrap =
        document.getElementById(
            'bd-regNoteWrap'
        );


    if (g.registrarNotes) {

        regWrap.style.display =
            'block';


        document.getElementById(
            'bd-regNotes'
        ).textContent =
            g.registrarNotes;

    } else {

        regWrap.style.display =
            'none';

    }


    document.getElementById(
        'breakdownModal'
    ).style.display =
        'flex';
}


function closeBreakdown()
{
    document.getElementById(
        'breakdownModal'
    ).style.display =
        'none';
}


document
    .getElementById(
        'breakdownModal'
    )
    .addEventListener(
        'click',
        function(e)
        {
            if (e.target === this) {

                closeBreakdown();

            }
        }
    );

</script>


<?php require_once ROOT . '/includes/footer.php'; ?>