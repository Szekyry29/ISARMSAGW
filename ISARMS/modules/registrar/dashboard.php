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
// STATS
// IMPORTANT:
// ONLY ACTIVE STUDENTS + ACTIVE ENROLLMENTS ARE INCLUDED
// ============================================================

$stats = [

    // Posted today (legacy Approved values are normalized to Posted)
    'approved_today' => $db->fetchOne("
        SELECT COUNT(*) AS c
        FROM grades g
        INNER JOIN students s
            ON s.studentID = g.studentID
        INNER JOIN enrollments e
            ON e.enrollmentID = g.enrollmentID
        WHERE g.status IN ('Posted', 'Approved')
          AND g.approvedAt IS NOT NULL
          AND DATE(g.approvedAt) = CURDATE()
          AND g.dateDeleted IS NULL

          AND s.isActive = 1
          AND s.dateDeleted IS NULL

          AND e.status = 'Active'
          AND e.dateDeleted IS NULL
    ")['c'] ?? 0,


    // Total active students
    'total_students' => $db->fetchOne("
        SELECT COUNT(*) AS c
        FROM students
        WHERE isActive = 1
          AND dateDeleted IS NULL
          AND studentNo IS NOT NULL
          AND studentNo <> ''
    ")['c'] ?? 0,


    // Honors pending
    // Kept separate because honors may be validated
    // even after the semester is completed.
    'honors_pending' => $db->fetchOne("
        SELECT COUNT(*) AS c
        FROM gwa_records gr
        JOIN students s ON s.studentID = gr.studentID
        WHERE gr.validationStatus = 'Pending'
          AND gr.latinHonor <> 'None'
          AND gr.gwaValue > 0
                    AND s.isActive = 1
                    AND s.dateDeleted IS NULL
                    AND EXISTS (
                            SELECT 1
                            FROM enrollments active_e
                            WHERE active_e.studentID = gr.studentID
                                AND active_e.status = 'Active'
                                AND active_e.dateDeleted IS NULL
                                AND (active_e.acadYearID = gr.acadYearID OR gr.acadYearID IS NULL)
                                AND (active_e.semester = gr.semester OR gr.semester IS NULL)
                    )
          AND NOT EXISTS (
              SELECT 1
              FROM enrollments missing_e
              WHERE missing_e.studentID = gr.studentID
                AND missing_e.status = 'Active'
                AND missing_e.dateDeleted IS NULL
                AND (
                    missing_e.acadYearID = gr.acadYearID
                    OR gr.acadYearID IS NULL
                )
                AND (
                    missing_e.semester = gr.semester
                    OR gr.semester IS NULL
                )
                AND NOT EXISTS (
                    SELECT 1
                    FROM grades missing_g
                    WHERE missing_g.enrollmentID = missing_e.enrollmentID
                      AND missing_g.dateDeleted IS NULL
                      AND missing_g.status IN ('Posted', 'Approved')
                      AND missing_g.gradeEquivalent IS NOT NULL
                )
          )
    ")['c'] ?? 0,


    // Missing grades
    // Only active students with active enrollments and a valid assigned subject
    // and no valid grade record for that enrollment.
    'missing_grades' => $db->fetchOne("
        SELECT COUNT(*) AS c
        FROM enrollments e
        INNER JOIN students s
            ON s.studentID = e.studentID
        INNER JOIN section_subjects ss
            ON ss.sectionID = e.sectionID
           AND ss.subjectID = e.subjectID
           AND ss.semester = e.semester
           AND ss.acadYearID = e.acadYearID
           AND ss.dateDeleted IS NULL
        WHERE e.status = 'Active'
          AND e.dateDeleted IS NULL
          AND s.isActive = 1
          AND s.isGraduated = 0
          AND s.isDropped = 0
          AND s.dateDeleted IS NULL
          AND NOT EXISTS (
              SELECT 1
              FROM grades g
              WHERE g.enrollmentID = e.enrollmentID
                AND g.dateDeleted IS NULL
          )
    ")['c'] ?? 0,
];


// ============================================================
// PENDING GRADE APPROVALS
// ONLY ACTIVE STUDENTS + ACTIVE ENROLLMENTS
// ============================================================

$pendingGrades = $db->fetchAll("
    SELECT
        g.gradeID,
        g.midterm,
        g.finals,
        g.computedGrade,
        g.gradeEquivalent,
        g.teacherRemarks,
        g.submittedAt,

        CONCAT(s.lName, ', ', s.fName) AS studentName,
        s.studentNo,

        sub.subjectCode,
        sub.subjectName,
        sub.units,

        CONCAT(u.lName, ', ', u.fName) AS teacherName,

        p.programCode,
        sec.sectionName,
        ay.academicYear,
        e.semester

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

    INNER JOIN sections sec
        ON sec.sectionID = e.sectionID

    INNER JOIN acad_years ay
        ON ay.acadYearID = e.acadYearID

    WHERE g.status = 'Submitted'
      AND g.dateDeleted IS NULL

      -- IMPORTANT:
      -- archived / graduated / inactive students excluded
      AND s.isActive = 1
      AND s.dateDeleted IS NULL

      -- only currently active enrollment/class
      AND e.status = 'Active'
      AND e.dateDeleted IS NULL

    ORDER BY g.submittedAt ASC

    LIMIT 8
");


// ============================================================
// PENDING HONORS
// ============================================================

$pendingHonors = $db->fetchAll("
    SELECT
        gr.gwaID,
        gr.gwaValue,
        gr.latinHonor,
        gr.semester,
        gr.computedAt,

        CONCAT(s.lName, ', ', s.fName) AS studentName,
        s.studentNo,

        p.programCode,
        ay.academicYear

    FROM gwa_records gr

    INNER JOIN students s
        ON s.studentID = gr.studentID

    INNER JOIN programs p
        ON p.programID = s.programID

    INNER JOIN acad_years ay
        ON ay.acadYearID = gr.acadYearID

    WHERE gr.validationStatus = 'Pending'
      AND gr.latinHonor <> 'None'
      AND gr.gwaValue > 0
      AND NOT EXISTS (
          SELECT 1
          FROM enrollments missing_e
          WHERE missing_e.studentID = gr.studentID
            AND missing_e.status = 'Active'
            AND missing_e.dateDeleted IS NULL
            AND (
                missing_e.acadYearID = gr.acadYearID
                OR gr.acadYearID IS NULL
            )
            AND (
                missing_e.semester = gr.semester
                OR gr.semester IS NULL
            )
            AND NOT EXISTS (
                SELECT 1
                FROM grades missing_g
                WHERE missing_g.enrollmentID = missing_e.enrollmentID
                  AND missing_g.dateDeleted IS NULL
                  AND missing_g.status IN ('Posted', 'Approved')
                  AND missing_g.gradeEquivalent IS NOT NULL
            )
      )

    ORDER BY gr.gwaValue ASC

    LIMIT 6
");


// ============================================================
// FLAGGED RECORDS
// ONLY ACTIVE STUDENTS + ACTIVE ENROLLMENTS
// ============================================================

$flaggedGrades = $db->fetchAll("
    SELECT
        g.gradeID,
        g.registrarNotes,
        g.gradeEquivalent,

        CONCAT(s.lName, ', ', s.fName) AS studentName,
        s.studentNo,

        sub.subjectCode,

        CONCAT(u.lName, ', ', u.fName) AS teacherName

    FROM grades g

    INNER JOIN enrollments e
        ON e.enrollmentID = g.enrollmentID

    INNER JOIN students s
        ON s.studentID = g.studentID

    INNER JOIN subjects sub
        ON sub.subjectID = g.subjectID

    INNER JOIN users u
        ON u.userID = g.teacherID

    WHERE g.status = 'Flagged'
      AND g.dateDeleted IS NULL

      AND s.isActive = 1
      AND s.dateDeleted IS NULL

      AND e.status = 'Active'
      AND e.dateDeleted IS NULL

    ORDER BY g.submittedAt DESC

    LIMIT 5
");


// ============================================================
// WEEKLY APPROVAL TREND
// ONLY ACTIVE STUDENTS / ACTIVE ENROLLMENTS
// ============================================================

$weeklyTrend = $db->fetchAll("
    SELECT
        DATE(g.approvedAt) AS day,
        COUNT(*) AS cnt

    FROM grades g

    INNER JOIN students s
        ON s.studentID = g.studentID

    INNER JOIN enrollments e
        ON e.enrollmentID = g.enrollmentID

    WHERE g.status = 'Approved'
      AND g.approvedAt >= DATE_SUB(NOW(), INTERVAL 7 DAY)
      AND g.dateDeleted IS NULL

      AND s.isActive = 1
      AND s.dateDeleted IS NULL

      AND e.status = 'Active'
      AND e.dateDeleted IS NULL

    GROUP BY DATE(g.approvedAt)

    ORDER BY day
");


$pageTitle  = 'Registrar Dashboard';
$activeMenu = 'dashboard.php';

require_once ROOT . '/includes/header.php';
?>


<!-- ============================================================
     WELCOME BANNER
============================================================ -->

<div style="
    background:linear-gradient(
        135deg,
        var(--blue-dark) 0%,
        var(--blue) 100%
    );
    border-radius:16px;
    padding:26px 30px;
    margin-bottom:22px;
    color:#fff;
    display:flex;
    align-items:center;
    justify-content:space-between;
    position:relative;
    overflow:hidden;
">

    <div style="
        position:absolute;
        right:-40px;
        top:-40px;
        width:220px;
        height:220px;
        border-radius:50%;
        background:rgba(245,197,24,.07);
        pointer-events:none;
    "></div>

    <div style="position:relative;">

        <div style="
            font-size:11px;
            color:rgba(255,255,255,.55);
            text-transform:uppercase;
            letter-spacing:1.5px;
            margin-bottom:5px;
        ">
            Office of the Registrar
        </div>

        <h2 style="
            font-family:'Playfair Display',serif;
            font-size:22px;
            margin-bottom:4px;
        ">
            Good
            <?= date('H') < 12
                ? 'Morning'
                : (date('H') < 17 ? 'Afternoon' : 'Evening')
            ?>,

            <?= e(explode(' ', $user['name'])[0] ?? 'Registrar') ?>

            🎓
        </h2>

        <p style="
            opacity:.75;
            font-size:13px;
        ">
            <?= date('l, F j, Y') ?>
            &bull;
            NEUST Talavera Off-Campus
        </p>

    </div>


    <div style="
        position:relative;
        display:flex;
        gap:14px;
    ">

        <div style="
            background:rgba(255,255,255,.12);
            border-radius:12px;
            padding:14px 20px;
            text-align:center;
            backdrop-filter:blur(4px);
        ">

            <div style="
                font-size:32px;
                font-weight:700;
                color:var(--gold);
                line-height:1;
            ">
                <?= number_format($stats['approved_today']) ?>
            </div>

            <div style="
                font-size:11px;
                margin-top:3px;
            ">
                Posted Today
            </div>

        </div>

    </div>

</div>


<!-- ============================================================
     STAT CARDS
============================================================ -->

<div class="stat-grid" style="
    grid-template-columns:repeat(6,1fr);
">

<?php

$cards = [

    [
        $stats['approved_today'],
        'Posted',
        'fa-check-double',
        'green'
    ],

    [
        $stats['total_students'],
        'Total Students',
        'fa-user-graduate',
        'blue'
    ],

    ...($stats['honors_pending'] > 0 ? [[
        $stats['honors_pending'],
        'Honors Pending',
        'fa-award',
        'gold'
    ]] : []),
];

?>

<?php foreach ($cards as [$val, $label, $icon, $color]): ?>

<div class="stat-card">

    <div class="stat-icon <?= e($color) ?>">
        <i class="fas <?= e($icon) ?>"></i>
    </div>

    <div class="stat-info">

        <div class="value">
            <?= number_format($val) ?>
        </div>

        <div class="label">
            <?= e($label) ?>
        </div>

    </div>

</div>

<?php endforeach; ?>

</div>


<!-- ============================================================
     MAIN GRID
============================================================ -->

<div style="
    display:grid;
    grid-template-columns:1fr 340px;
    gap:20px;
    align-items:start;
">


<!-- ============================================================
     PENDING GRADE APPROVALS
============================================================ -->

<div class="card">

    <div class="card-header">

        <div class="card-title">

            <i class="fas fa-clipboard-check"></i>

            Recently Submitted Grades

        </div>

        <a
            href="grade_approvals.php?status=Submitted"
            class="btn btn-outline btn-sm"
        >
            View All
            <i class="fas fa-arrow-right"></i>
        </a>

    </div>


    <?php if (empty($pendingGrades)): ?>

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
                opacity:.5;
            "
        ></i>

        <div style="
            font-weight:600;
            margin-bottom:6px;
        ">
            All caught up!
        </div>

        <div style="font-size:13px;">
            No submitted grades from active students.
        </div>

    </div>

    <?php else: ?>


    <div style="overflow-x:auto;">

        <table class="data-table">

            <thead>

            <tr>

                <th>Student</th>

                <th>Subject</th>

                <th>Teacher</th>

                <th style="text-align:center;">
                    Grade
                </th>

                <th style="text-align:center;">
                    Equiv.
                </th>

                <th>
                    Submitted
                </th>

                <th style="text-align:center;">
                    Action
                </th>

            </tr>

            </thead>


            <tbody>

            <?php foreach ($pendingGrades as $g): ?>

            <tr>

                <td>

                    <div style="
                        font-weight:700;
                        font-size:13px;
                    ">
                        <?= e($g['studentName']) ?>
                    </div>

                    <div style="
                        font-size:11px;
                        color:var(--text-muted);
                    ">
                        <?= e($g['studentNo']) ?>

                        &bull;

                        <?= e($g['programCode']) ?>
                    </div>

                </td>


                <td>

                    <div style="
                        font-weight:700;
                        color:var(--blue);
                        font-size:13px;
                    ">
                        <?= e($g['subjectCode']) ?>
                    </div>

                    <div style="
                        font-size:11px;
                        color:var(--text-muted);
                    ">
                        <?= e($g['units']) ?>u

                        &bull;

                        <?= e($g['sectionName']) ?>
                    </div>

                </td>


                <td style="font-size:12.5px;">

                    <?= e($g['teacherName']) ?>

                </td>


                <td style="
                    text-align:center;
                    font-weight:700;
                    font-size:15px;
                    color:var(--blue);
                ">

                    <?= $g['computedGrade'] !== null
                        ? number_format((float)$g['computedGrade'], 2)
                        : '—'
                    ?>

                </td>


                <td style="
                    text-align:center;
                    font-size:18px;
                    font-weight:700;
                    color:
                    <?= ($g['gradeEquivalent'] ?? 5) <= 3
                        ? 'var(--success)'
                        : 'var(--danger)'
                    ?>;
                ">

                    <?= $g['gradeEquivalent']
                        ? number_format(
                            (float)$g['gradeEquivalent'],
                            2
                        )
                        : '—'
                    ?>

                </td>


                <td style="
                    font-size:11.5px;
                    color:var(--text-muted);
                ">

                    <?= $g['submittedAt']
                        ? date(
                            'M d, Y',
                            strtotime($g['submittedAt'])
                        )
                        : '—'
                    ?>

                </td>


                <td style="text-align:center;">

                    <div style="
                        display:flex;
                        gap:5px;
                        justify-content:center;
                    ">

                        <span style="font-size:12px;color:var(--text-muted);"><i class="fas fa-eye"></i> View only</span>

                    </div>

                </td>

            </tr>

            <?php endforeach; ?>

            </tbody>

        </table>

    </div>

    <?php endif; ?>

</div>


<!-- ============================================================
     RIGHT COLUMN
============================================================ -->

<div style="
    display:flex;
    flex-direction:column;
    gap:16px;
">


<!-- HONORS -->

<div class="card">

    <div class="card-header">

        <div class="card-title">

            <i class="fas fa-award"></i>

            Honors to Validate

        </div>

        <a
            href="honors.php"
            class="btn btn-outline btn-sm"
        >
            All
        </a>

    </div>


    <?php if (empty($pendingHonors)): ?>

    <div style="
        padding:20px;
        text-align:center;
        color:var(--text-muted);
        font-size:13px;
    ">
        No honors pending validation.
    </div>

    <?php else: ?>


    <?php foreach ($pendingHonors as $h): ?>

    <div style="
        padding:13px 18px;
        border-bottom:1px solid var(--border);
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:10px;
    ">

        <div style="
            min-width:0;
        ">

            <div style="
                font-size:13px;
                font-weight:700;
                white-space:nowrap;
                overflow:hidden;
                text-overflow:ellipsis;
            ">
                <?= e($h['studentName']) ?>
            </div>

            <div style="
                font-size:11px;
                color:var(--text-muted);
            ">
                <?= e($h['studentNo']) ?>

                &bull;

                <?= e($h['programCode']) ?>
            </div>

            <span
                class="<?= e(honorBadgeClass($h['latinHonor'])) ?>"
                style="
                    margin-top:4px;
                    display:inline-block;
                    font-size:11px;
                "
            >
                <?= e($h['latinHonor']) ?>
            </span>

        </div>


        <div style="
            text-align:right;
            flex-shrink:0;
        ">

            <div style="
                font-size:22px;
                font-weight:700;
                color:var(--gold-deep);
            ">
                <?= formatGWA((float)$h['gwaValue']) ?>
            </div>

            <button
                class="btn btn-gold btn-sm"
                style="margin-top:4px;"
                onclick="validateHonor(<?= (int)$h['gwaID'] ?>)"
            >
                <i class="fas fa-check"></i>
            </button>

        </div>

    </div>

    <?php endforeach; ?>

    <?php endif; ?>

</div>


<!-- QUICK ACTIONS -->

<div class="card">

    <div class="card-header">

        <div class="card-title">

            <i class="fas fa-bolt"></i>

            Quick Actions

        </div>

    </div>


    <div
        class="card-body"
        style="
            display:flex;
            flex-direction:column;
            gap:8px;
        "
    >

        <a
            href="grade_approvals.php?status=Submitted"
            class="btn btn-primary"
            style="justify-content:flex-start;"
        >
            <i class="fas fa-check-double"></i>

            Review Pending Grades
        </a>


        <a
            href="honors.php?status=Pending"
            class="btn btn-gold"
            style="justify-content:flex-start;"
        >
            <i class="fas fa-award"></i>

            Validate Honors
        </a>


        <a
            href="reports.php?type=gwa_summary"
            class="btn btn-outline"
            style="justify-content:flex-start;"
        >
            <i class="fas fa-file-alt"></i>

            GWA Summary Report
        </a>


        <a
            href="reports.php?type=honors_list"
            class="btn btn-outline"
            style="justify-content:flex-start;"
        >
            <i class="fas fa-list-ol"></i>

            Honors List Report
        </a>

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
        padding:32px;
        width:480px;
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
            margin-bottom:16px;
        ">
            Subject:
            <strong id="flagSubjectName"></strong>
        </p>


        <input
            type="hidden"
            id="flagGradeID"
        />


        <div style="margin-bottom:16px;">

            <label class="form-label">

                Reason for Flagging

                <span style="color:var(--danger);">
                    *
                </span>

            </label>


            <textarea
                id="flagReason"
                class="form-control"
                rows="3"
                placeholder="Describe the discrepancy or error found…"
            ></textarea>

        </div>


        <div style="
            display:flex;
            gap:10px;
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

                Submit Flag
            </button>

        </div>

    </div>

</div>


<script>

function quickApprove(id)
{
    if (
        !confirm(
            'Approve this grade? It will be included in the student\'s official GWA.'
        )
    ) {
        return;
    }

    callGradesAPI(
        {
            action: 'approve',
            gradeID: id
        },
        function(d)
        {
            showToast(d.message, 'success');

            setTimeout(
                function()
                {
                    location.reload();
                },
                1200
            );
        }
    );
}


function openFlagModal(id, name)
{
    document.getElementById('flagGradeID').value = id;

    document.getElementById('flagSubjectName').textContent = name;

    document.getElementById('flagReason').value = '';

    document.getElementById('flagModal').style.display = 'flex';
}


function closeFlagModal()
{
    document.getElementById('flagModal').style.display = 'none';
}


function submitFlag()
{
    const reason =
        document.getElementById('flagReason').value.trim();

    if (!reason) {

        showToast(
            'Please enter a reason.',
            'error'
        );

        return;
    }


    callGradesAPI(
        {
            action: 'flag',

            gradeID: parseInt(
                document.getElementById('flagGradeID').value
            ),

            reason: reason
        },

        function(d)
        {
            closeFlagModal();

            showToast(
                d.message,
                d.success ? 'success' : 'error'
            );

            if (d.success) {

                setTimeout(
                    function()
                    {
                        location.reload();
                    },
                    1200
                );

            }
        }
    );
}


function validateHonor(id)
{
    if (
        !confirm(
            'Validate this honor classification? The student will be notified.'
        )
    ) {
        return;
    }


    fetch(
        '<?= e(BASE_URL) ?>api/gwa.php',
        {
            method: 'POST',

            headers: {
                'Content-Type': 'application/json'
            },

            body: JSON.stringify(
                {
                    action: 'validate',
                    gwaID: id
                }
            )
        }
    )

    .then(function(r)
    {
        return r.json();
    })

    .then(function(d)
    {
        showToast(
            d.message,
            d.success ? 'success' : 'error'
        );

        if (d.success) {

            setTimeout(
                function()
                {
                    location.reload();
                },
                1200
            );

        }
    })

    .catch(function()
    {
        showToast(
            'Unable to process request.',
            'error'
        );
    });
}


function callGradesAPI(data, cb)
{
    fetch(
        '<?= e(BASE_URL) ?>api/grades.php',
        {
            method: 'POST',

            headers: {
                'Content-Type': 'application/json'
            },

            body: JSON.stringify(data)
        }
    )

    .then(function(r)
    {
        return r.json();
    })

    .then(function(d)
    {
        if (d.success) {

            cb(d);

        } else {

            showToast(
                d.message || 'Request failed.',
                'error'
            );

        }
    })

    .catch(function()
    {
        showToast(
            'Unable to connect to the server.',
            'error'
        );
    });
}


document
    .getElementById('flagModal')
    .addEventListener(
        'click',
        function(e)
        {
            if (e.target === this) {
                closeFlagModal();
            }
        }
    );

</script>


<?php require_once ROOT . '/includes/footer.php'; ?>