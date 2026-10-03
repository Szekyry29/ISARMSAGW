<?php

define('ROOT', dirname(__DIR__, 2));

require_once ROOT . '/includes/config.php';
require_once ROOT . '/includes/functions.php';

requireAuth('Teacher');

$db   = Database::getInstance();
$user = getCurrentUser();
$tid  = $user['id'];

/*
|--------------------------------------------------------------------------
| Get Sections Assigned to Teacher
|--------------------------------------------------------------------------
|
| Teachers can have assignments in different academic years.
| Archived/deleted sections are excluded.
|
*/
$mySections = $db->fetchAll("
    SELECT DISTINCT
           sec.sectionID,
           sec.sectionName,
           p.programCode,
           p.programName,
           ay.academicYear,
           ay.status AS ayStatus,
           ay.acadYearID
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
        ay.status = 'Active' DESC,
        ay.academicYear DESC,
        sec.sectionName
", [
    ':t' => $tid
]);


/*
|--------------------------------------------------------------------------
| Selected Filters
|--------------------------------------------------------------------------
*/

$selSection = (int)($_GET['section_id'] ?? 0);

if (!$selSection && !empty($mySections)) {
    $selSection = (int)($mySections[0]['sectionID'] ?? 0);
}

$selSubject = (int)($_GET['subject_id'] ?? 0);
$selSem     = (int)($_GET['semester'] ?? 0);


/*
|--------------------------------------------------------------------------
| Subjects Assigned to Teacher in Selected Section
|--------------------------------------------------------------------------
*/

$mySubjectsInSection = [];

if ($selSection) {

    $mySubjectsInSection = $db->fetchAll("
        SELECT
            ss.secSubID,
            ss.subjectID,
            ss.semester,
            ss.schedule,
            ss.room,

            sub.subjectCode,
            sub.subjectName,
            sub.units

        FROM section_subjects ss

        JOIN subjects sub
            ON sub.subjectID = ss.subjectID

        JOIN sections sec
            ON sec.sectionID = ss.sectionID

        JOIN acad_years ay
            ON ay.acadYearID = ss.acadYearID

        WHERE ss.sectionID = :sec
          AND ss.teacherID = :t
          AND ss.subjectID IS NOT NULL
          AND ss.semester IS NOT NULL
          AND ss.dateDeleted IS NULL
          AND sub.dateDeleted IS NULL
          AND sec.dateDeleted IS NULL
          AND ay.dateDeleted IS NULL
          AND ay.status = 'Active'

        ORDER BY
            ss.semester,
            sub.subjectCode
    ", [
        ':sec' => $selSection,
        ':t'   => $tid
    ]);
}


/*
|--------------------------------------------------------------------------
| Students + Existing Grades
|--------------------------------------------------------------------------
*/

$students    = [];
$sectionInfo = null;
$acadYearID  = 0;
$secSubInfo  = null;


/*
|--------------------------------------------------------------------------
| Verify Teacher Assignment
|--------------------------------------------------------------------------
*/

if ($selSection && $selSubject && $selSem) {

    $secSubInfo = $db->fetchOne("
        SELECT
            ss.*,

            sub.subjectCode,
            sub.subjectName,
            sub.units,

            sec.sectionName,

            p.programCode,

            ay.academicYear,
            ay.acadYearID

        FROM section_subjects ss

        JOIN subjects sub
            ON sub.subjectID = ss.subjectID

        JOIN sections sec
            ON sec.sectionID = ss.sectionID

        JOIN programs p
            ON p.programID = sec.programID

        JOIN acad_years ay
            ON ay.acadYearID = ss.acadYearID

        WHERE ss.sectionID = :sec
          AND ss.subjectID = :sub
          AND ss.semester = :sem
          AND ss.teacherID = :t

          AND ss.dateDeleted IS NULL
          AND sub.dateDeleted IS NULL
          AND sec.dateDeleted IS NULL

    ", [
        ':sec' => $selSection,
        ':sub' => $selSubject,
        ':sem' => $selSem,
        ':t'   => $tid
    ]);


    /*
    |--------------------------------------------------------------------------
    | Teacher is not authorized for this subject
    |--------------------------------------------------------------------------
    */

    if (!$secSubInfo) {

        $selSubject = 0;

    } else {

        $acadYearID = (int)$secSubInfo['acadYearID'];


        /*
        |--------------------------------------------------------------------------
        | Load ACTIVE Students and Existing Grades
        |--------------------------------------------------------------------------
        |
        | IMPORTANT:
        |
        | students.dateDeleted IS NULL
        |
        | means the student is active.
        |
        | Archived students have:
        |
        | students.dateDeleted = NOW()
        |
        | Therefore archived students will NOT appear here.
        |
        | Their enrollment and grade records remain in the database.
        |
        |--------------------------------------------------------------------------
        */

        $students = $db->fetchAll("
            SELECT
                e.enrollmentID,

                s.studentID,
                s.studentNo,

                CONCAT(
                    s.lName,
                    ', ',
                    s.fName,
                    ' ',
                    COALESCE(
                        SUBSTRING(s.mName,1,1),
                        ''
                    ),
                    CASE
                        WHEN s.mName IS NOT NULL
                         AND s.mName != ''
                        THEN '.'
                        ELSE ''
                    END
                ) AS fullName,

                s.fName,
                s.lName,

                g.gradeID,
                g.midterm,
                g.finals,
                g.computedGrade,
                g.gradeEquivalent,
                g.remarks,
                g.status,
                g.submittedAt,
                g.teacherRemarks,
                g.registrarNotes

            FROM enrollments e

            JOIN students s
                ON s.studentID = e.studentID

            LEFT JOIN grades g
                ON g.enrollmentID = e.enrollmentID
               AND g.teacherID = :t
               AND g.dateDeleted IS NULL

            WHERE e.sectionID = :sec
              AND e.subjectID = :sub
              AND e.semester = :sem
                            AND e.acadYearID = :ay

              /*
              |--------------------------------------------------------------------------
              | ACTIVE STUDENT ONLY
              |--------------------------------------------------------------------------
              |
              | This is the important archive connection.
              |
              | Archived student:
              |     s.dateDeleted IS NOT NULL
              |
              | Active student:
              |     s.dateDeleted IS NULL
              |
              |--------------------------------------------------------------------------
              */

              AND s.dateDeleted IS NULL

              /*
              |--------------------------------------------------------------------------
              | Active enrollment only
              |--------------------------------------------------------------------------
              */

              AND e.status = 'Active'
              AND e.dateDeleted IS NULL

            ORDER BY
                s.lName,
                s.fName
        ", [
            ':t'   => $tid,
            ':sec' => $selSection,
            ':sub' => $selSubject,
            ':sem' => $selSem,
            ':ay'  => $acadYearID
        ]);
    }
}


/*
|--------------------------------------------------------------------------
| Grade Scale
|--------------------------------------------------------------------------
|
| 98 - 100 = 1.00
| 95 - 97  = 1.25
| 92 - 94  = 1.50
| 89 - 91  = 1.75
| 86 - 88  = 2.00
| 83 - 85  = 2.25
| 80 - 82  = 2.50
| 70 - 79  = 3.00 PASSED
| 60 - 69  = 4.00 CONDITIONAL
| < 60     = 5.00 FAILED
|
*/

$gradeScale = [
    98 => 1.00,
    95 => 1.25,
    92 => 1.50,
    89 => 1.75,
    86 => 2.00,
    83 => 2.25,
    80 => 2.50,
    70 => 3.00,
    60 => 4.00
];


/*
|--------------------------------------------------------------------------
| Grade Status
|--------------------------------------------------------------------------
*/

$allLocked = !empty($students)
    && count(
        array_filter(
            $students,
            fn($s) => !in_array(($s['status'] ?? ''), ['Posted', 'Approved'], true)
        )
    ) === 0;


$canSubmit = !empty($students)
    && count(
        array_filter(
            $students,
            fn($s) => ($s['status'] ?? '') === 'Pending' && empty($s['submittedAt'])
        )
    ) > 0;


$pageTitle  = 'Encode Grades';
$activeMenu = 'encode_grades.php';

require_once ROOT . '/includes/header.php';

?>


<!-- =========================================================
     SECTION / SUBJECT SELECTION
========================================================= -->

<div class="card" style="margin-bottom:16px;">

    <div class="card-header">

        <div class="card-title">
            <i class="fas fa-filter"></i>
            Select Class to Grade
        </div>

        <?php if ($secSubInfo): ?>

            <div style="
                font-size:13px;
                color:var(--text-muted);
            ">

                <?= htmlspecialchars($secSubInfo['sectionName']) ?>

                &bull;

                <?= htmlspecialchars($secSubInfo['subjectCode']) ?>

                &bull;

                <?= $secSubInfo['semester'] == 1 ? '1st' : '2nd' ?>
                Sem

                &bull;

                <?= htmlspecialchars($secSubInfo['academicYear']) ?>

            </div>

        <?php endif; ?>

    </div>


    <div class="card-body" style="padding:16px 20px;">

        <form
            method="GET"
            id="filterForm"
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
                    Section *
                </label>

                <select
                    name="section_id"
                    class="form-control"
                    onchange="
                        document
                            .getElementById('filterForm')
                            .submit()
                    "
                >

                    <option value="">
                        — Select Section —
                    </option>

                    <?php foreach ($mySections as $sec): ?>

                        <option
                            value="<?= (int)$sec['sectionID'] ?>"
                            <?= $selSection == $sec['sectionID']
                                ? 'selected'
                                : '' ?>
                        >

                            <?= htmlspecialchars(
                                "{$sec['sectionName']} ({$sec['programCode']}) — {$sec['academicYear']}"
                            ) ?>

                        </option>

                    <?php endforeach; ?>

                </select>

            </div>


            <!-- SUBJECT -->

            <div>

                <label class="form-label">

                    Subject *

                    <span style="
                        font-size:11px;
                        color:var(--text-muted);
                    ">
                        (only your assigned subjects)
                    </span>

                </label>

                <select
                    name="subject_id"
                    class="form-control"
                    <?= empty($mySubjectsInSection)
                        ? 'disabled'
                        : '' ?>
                >

                    <option value="">
                        — Select Subject —
                    </option>

                    <?php foreach ($mySubjectsInSection as $sub): ?>

                        <option
                            value="<?= (int)$sub['subjectID'] ?>"
                            <?= $selSubject == $sub['subjectID']
                                ? 'selected'
                                : '' ?>
                        >

                            [<?= $sub['semester'] == 1
                                ? '1st'
                                : '2nd' ?>S]

                            <?= htmlspecialchars(
                                "{$sub['subjectCode']} – {$sub['subjectName']} ({$sub['units']}u)"
                            ) ?>

                        </option>

                    <?php endforeach; ?>

                </select>

            </div>


            <!-- SEMESTER -->

            <div>

                <label class="form-label">
                    Semester *
                </label>

                <select
                    name="semester"
                    class="form-control"
                    <?= empty($mySubjectsInSection)
                        ? 'disabled'
                        : '' ?>
                >

                    <option value="">
                        —
                    </option>

                    <option
                        value="1"
                        <?= $selSem === 1
                            ? 'selected'
                            : '' ?>
                    >
                        1st Semester
                    </option>

                    <option
                        value="2"
                        <?= $selSem === 2
                            ? 'selected'
                            : '' ?>
                    >
                        2nd Semester
                    </option>

                </select>

            </div>


            <!-- LOAD -->

            <button
                type="submit"
                class="btn btn-primary"
            >

                <i class="fas fa-search"></i>

                Load

            </button>

        </form>

    </div>

</div>


<!-- =========================================================
     SUBJECT SELECTION MESSAGE
========================================================= -->

<?php if (
    $selSection &&
    !empty($mySubjectsInSection) &&
    !$selSubject
): ?>

    <div class="alert alert-info">

        <i class="fas fa-info-circle"></i>

        You teach
        <?= count($mySubjectsInSection) ?>
        subject(s) in this section.

        Select a subject above to load its grade sheet.

    </div>

<?php endif; ?>


<!-- =========================================================
     GRADE SHEET
========================================================= -->

<?php if ($secSubInfo && !empty($students)): ?>

    <!-- INFO BAR -->

    <div style="
        background:var(--blue-light);
        border:1px solid var(--border);
        border-radius:12px;
        padding:13px 18px;
        margin-bottom:14px;
        display:flex;
        align-items:center;
        justify-content:space-between;
        flex-wrap:wrap;
        gap:10px;
    ">

        <div style="
            font-size:13px;
            color:var(--blue);
        ">

            <i class="fas fa-info-circle"></i>

            <strong>Formula:</strong>

            Midterm (50%) + Finals (50%)

            &nbsp;&bull;&nbsp;

            <strong>Passing:</strong>
            3.00 (70%)

            &nbsp;&bull;&nbsp;

            <strong>Conditional:</strong>
            4.00 (60–69%)

            <?php if (!empty($secSubInfo['schedule'])): ?>

                &nbsp;&bull;&nbsp;

                <strong>Schedule:</strong>

                <?= htmlspecialchars(
                    $secSubInfo['schedule']
                ) ?>

            <?php endif; ?>


            <?php if (!empty($secSubInfo['room'])): ?>

                &nbsp;&bull;&nbsp;

                <strong>Room:</strong>

                <?= htmlspecialchars(
                    $secSubInfo['room']
                ) ?>

            <?php endif; ?>

        </div>


        <span style="
            font-size:13px;
            color:var(--text-muted);
        ">

            <?= count($students) ?>

            students

        </span>

    </div>


    <!-- =====================================================
         GRADE TABLE
    ===================================================== -->

    <div class="card">

        <div class="card-header">

            <div class="card-title">

                <i class="fas fa-table"></i>

                Grade Sheet —
                <?= htmlspecialchars(
                    $secSubInfo['subjectCode']
                ) ?>

            </div>


            <div style="
                display:flex;
                gap:8px;
                flex-wrap:wrap;
            ">

                <?php if (!$allLocked): ?>

                    <button
                        class="btn btn-outline btn-sm"
                        onclick="fillSampleGrades()"
                    >

                        <i class="fas fa-magic"></i>

                        Sample

                    </button>


                    <button
                        class="btn btn-primary btn-sm"
                        id="saveBtn"
                        onclick="saveAllGrades()"
                    >

                        <i class="fas fa-save"></i>

                        Save All

                    </button>

                <?php endif; ?>


                <!-- EXPORT -->

                <button
                    class="btn btn-outline btn-sm"
                    onclick="exportGrades()"
                >

                    <i class="fas fa-file-export"></i>

                    Export CSV

                </button>


                <!-- IMPORT -->

                <?php if (!$allLocked): ?>

                    <button
                        class="btn btn-outline btn-sm"
                        onclick="
                            document
                                .getElementById('importFile')
                                .click()
                        "
                    >

                        <i class="fas fa-file-import"></i>

                        Import CSV

                    </button>


                    <input
                        type="file"
                        id="importFile"
                        accept=".csv"
                        style="display:none;"
                        onchange="importGrades(this)"
                    />

                <?php endif; ?>


                <!-- SUBMIT -->

                <button
                    class="btn btn-gold btn-sm"
                    id="submitBtn"
                    onclick="confirmSubmit()"
                    <?= (!$canSubmit && !$allLocked)
                        ? 'disabled'
                        : '' ?>
                >

                    <i class="fas fa-paper-plane"></i>

                    Submit to Admin

                </button>

            </div>

        </div>


        <!-- =================================================
             TABLE
        ================================================= -->

        <div style="overflow-x:auto;">

            <table
                class="data-table"
                id="gradeTable"
            >

                <thead>

                    <tr>

                        <th style="width:36px;">
                            #
                        </th>

                        <th>
                            Student
                        </th>

                        <th style="
                            text-align:center;
                            width:110px;
                        ">

                            Midterm

                            <br>

                            <span style="
                                font-weight:400;
                                font-size:10px;
                            ">
                                50% · 0–100
                            </span>

                        </th>

                        <th style="
                            text-align:center;
                            width:110px;
                        ">

                            Finals

                            <br>

                            <span style="
                                font-weight:400;
                                font-size:10px;
                            ">
                                50% · 0–100
                            </span>

                        </th>

                        <th style="
                            text-align:center;
                            width:90px;
                        ">
                            Grade
                        </th>

                        <th style="
                            text-align:center;
                            width:80px;
                        ">
                            Equiv.
                        </th>

                        <th style="
                            text-align:center;
                            width:100px;
                        ">
                            Remarks
                        </th>

                        <th style="
                            text-align:center;
                            width:100px;
                        ">
                            Status
                        </th>

                        <th style="width:160px;">
                            Notes
                        </th>

                        <?php if (!$allLocked): ?>

                            <th style="
                                width:50px;
                                text-align:center;
                            ">
                            </th>

                        <?php endif; ?>

                    </tr>

                </thead>


                <tbody>

                    <?php foreach (
                        $students as $i => $stu
                    ):

                        $rawStatus = (string)($stu['status'] ?? '');
                            $displayStatus = $rawStatus === 'Pending' && !empty($stu['submittedAt'])
                                ? 'Submitted'
                                : normalizeGradeStatusDisplay($rawStatus);
                        $locked = in_array($rawStatus, ['Posted', 'Approved'], true);

                        // NOTE: $flagged now means "Returned by Admin for
                        // correction" (the old separate Flagged status was
                        // removed and folded into Returned).
                        $flagged = $rawStatus === 'Returned';

                    ?>

                        <tr
                            class="grade-row
                                <?= $flagged
                                    ? 'flagged-row'
                                    : '' ?>"
                            data-status="<?= htmlspecialchars($rawStatus) ?>"
                            style="<?= $flagged
                                ? 'background:#fff9f9;'
                                : '' ?>"
                            data-enrollment="<?= (int)$stu['enrollmentID'] ?>"
                            data-student="<?= (int)$stu['studentID'] ?>"
                            data-grade="<?= $stu['gradeID'] ?? '' ?>"
                            data-subject="<?= (int)$selSubject ?>"
                            data-acadyear="<?= (int)$acadYearID ?>"
                            data-teacher="<?= (int)$tid ?>"
                        >

                            <!-- NUMBER -->

                            <td style="
                                color:var(--text-muted);
                                font-size:12px;
                            ">

                                <?= $i + 1 ?>

                            </td>


                            <!-- STUDENT -->

                            <td>

                                <div style="
                                    font-weight:700;
                                    font-size:13.5px;
                                ">

                                    <?= htmlspecialchars(
                                        $stu['fullName']
                                    ) ?>

                                </div>


                                <div style="
                                    font-size:11.5px;
                                    color:var(--blue);
                                ">

                                    <?= htmlspecialchars(
                                        $stu['studentNo']
                                    ) ?>

                                </div>


                                <?php if (
                                    $flagged &&
                                    !empty($stu['registrarNotes'])
                                ): ?>

                                    <div style="
                                        font-size:11px;
                                        color:var(--danger);
                                        margin-top:3px;
                                        padding:3px 8px;
                                        background:#fef2f2;
                                        border-radius:5px;
                                    ">

                                        <i class="fas fa-flag"></i>

                                        <?= htmlspecialchars(
                                            $stu['registrarNotes']
                                        ) ?>

                                    </div>

                                <?php endif; ?>

                            </td>


                            <!-- MIDTERM -->

                            <td style="text-align:center;">

                                <?php if (
                                    $locked &&
                                    !$flagged
                                ): ?>

                                    <span style="font-weight:700;">

                                        <?= $stu['midterm']
                                            ?? '—' ?>

                                    </span>

                                <?php else: ?>

                                    <input
                                        type="number"
                                        class="form-control mt-input"
                                        style="
                                            text-align:center;
                                            padding:8px;
                                            font-weight:700;
                                        "
                                        min="0"
                                        max="100"
                                        step="0.01"
                                        value="<?= $stu['midterm'] ?? '' ?>"
                                        placeholder="0–100"
                                        oninput="
                                            computeRow(
                                                this.closest('tr')
                                            )
                                        "
                                    >

                                <?php endif; ?>

                            </td>


                            <!-- FINALS -->

                            <td style="text-align:center;">

                                <?php if (
                                    $locked &&
                                    !$flagged
                                ): ?>

                                    <span style="font-weight:700;">

                                        <?= $stu['finals']
                                            ?? '—' ?>

                                    </span>

                                <?php else: ?>

                                    <input
                                        type="number"
                                        class="form-control fn-input"
                                        style="
                                            text-align:center;
                                            padding:8px;
                                            font-weight:700;
                                        "
                                        min="0"
                                        max="100"
                                        step="0.01"
                                        value="<?= $stu['finals'] ?? '' ?>"
                                        placeholder="0–100"
                                        oninput="
                                            computeRow(
                                                this.closest('tr')
                                            )
                                        "
                                    >

                                <?php endif; ?>

                            </td>


                            <!-- COMPUTED GRADE -->

                            <td style="text-align:center;">

                                <div
                                    class="computed-grade"
                                    style="
                                        font-size:18px;
                                        font-weight:700;
                                        color:var(--blue);
                                    "
                                >

                                    <?= $stu['computedGrade']
                                        !== null &&
                                        $stu['computedGrade'] !== ''
                                        ? number_format(
                                            (float)$stu['computedGrade'],
                                            2
                                        )
                                        : '—'
                                    ?>

                                </div>

                            </td>


                            <!-- EQUIVALENT -->

                            <td style="text-align:center;">

                                <?php

                                $existingEquiv =
                                    isset(
                                        $stu['gradeEquivalent']
                                    )
                                    ? (float)$stu['gradeEquivalent']
                                    : 0;

                                if ($existingEquiv <= 3.00) {

                                    $equivColor =
                                        'var(--success)';

                                } elseif (
                                    $existingEquiv == 4.00
                                ) {

                                    $equivColor =
                                        'var(--gold)';

                                } else {

                                    $equivColor =
                                        'var(--danger)';
                                }

                                ?>

                                <div
                                    class="grade-equiv"
                                    style="
                                        font-size:18px;
                                        font-weight:700;
                                        color:<?= $equivColor ?>;
                                    "
                                >

                                    <?= $stu['gradeEquivalent']
                                        !== null &&
                                        $stu['gradeEquivalent'] !== ''
                                        ? number_format(
                                            (float)$stu['gradeEquivalent'],
                                            2
                                        )
                                        : '—'
                                    ?>

                                </div>

                            </td>


                            <!-- REMARKS -->

                            <td
                                style="text-align:center;"
                                class="remarks-cell"
                            >

                                <?php

                                $rem =
                                    $stu['remarks'] ?? '';

                                if ($rem === 'Passed') {

                                    $rc =
                                        'status-approved';

                                } elseif (
                                    $rem === 'Conditional'
                                ) {

                                    $rc =
                                        'status-pending';

                                } elseif (
                                    $rem === 'Failed'
                                ) {

                                    $rc =
                                        'status-flagged';

                                } else {

                                    $rc = '';
                                }

                                ?>

                                <?php if ($rem): ?>

                                    <span
                                        class="status-badge <?= $rc ?>"
                                    >

                                        <?= htmlspecialchars(
                                            $rem
                                        ) ?>

                                    </span>

                                <?php else: ?>

                                    —

                                <?php endif; ?>

                            </td>


                            <!-- STATUS -->

                            <td style="text-align:center;">

                                <?php

                                $st =
                                    $stu['status'] ?? '';
                                $statusLabel = $st === 'Pending' && !empty($stu['submittedAt'])
                                    ? 'Submitted'
                                    : normalizeGradeStatusDisplay((string)$st);

                                ?>

                                <?php if ($st): ?>

                                    <span
                                        class="status-badge
                                            <?= gradeStatusClass($st) ?>"
                                    >

                                        <?= htmlspecialchars(
                                            $statusLabel
                                        ) ?>

                                    </span>

                                <?php else: ?>

                                    <span style="
                                        color:var(--text-muted);
                                        font-size:12px;
                                    ">

                                        Not graded

                                    </span>

                                <?php endif; ?>

                            </td>


                            <!-- NOTES -->

                            <td>

                                <?php if (
                                    $locked &&
                                    !$flagged
                                ): ?>

                                    <span style="
                                        font-size:12px;
                                        color:var(--text-muted);
                                    ">

                                        <?= htmlspecialchars(
                                            $stu['teacherRemarks']
                                                ?? ''
                                        ) ?>

                                    </span>

                                <?php else: ?>

                                    <input
                                        type="text"
                                        class="form-control notes-input"
                                        style="
                                            font-size:12px;
                                            padding:7px;
                                        "
                                        placeholder="Note…"
                                        value="<?= htmlspecialchars(
                                            $stu['teacherRemarks']
                                                ?? ''
                                        ) ?>"
                                    >

                                <?php endif; ?>

                            </td>


                            <!-- CLEAR -->

                            <?php if (!$allLocked): ?>

                                <td style="text-align:center;">

                                    <?php if (!$locked): ?>

                                        <button
                                            class="btn btn-danger btn-sm"
                                            style="
                                                padding:5px 8px;
                                            "
                                            onclick="
                                                clearRow(
                                                    this.closest('tr')
                                                )
                                            "
                                        >

                                            <i class="fas fa-times"></i>

                                        </button>

                                    <?php endif; ?>

                                </td>

                            <?php endif; ?>

                        </tr>

                    <?php endforeach; ?>

                </tbody>

            </table>

        </div>


        <!-- =================================================
             BOTTOM ACTIONS
        ================================================= -->

        <?php if (!$allLocked): ?>

            <div style="
                padding:14px 20px;
                background:var(--bg);
                border-top:1px solid var(--border);
                display:flex;
                justify-content:space-between;
                align-items:center;
                flex-wrap:wrap;
                gap:10px;
            ">

                <div style="
                    font-size:13px;
                    color:var(--text-muted);
                ">

                    <i
                        class="fas fa-info-circle"
                        style="color:var(--blue);"
                    ></i>

                    Save first, then post grades to student records.

                </div>


                <div style="
                    display:flex;
                    gap:10px;
                ">

                    <button
                        class="btn btn-primary"
                        onclick="saveAllGrades()"
                    >

                        <i class="fas fa-save"></i>

                        Save All

                    </button>


                    <button
                        class="btn btn-gold"
                        onclick="confirmSubmit()"
                        <?= !$canSubmit
                            ? 'disabled'
                            : '' ?>
                    >

                        <i class="fas fa-paper-plane"></i>

                        Post Grades

                    </button>

                </div>

            </div>

        <?php else: ?>

            <div style="
                padding:13px 20px;
                background:#dcfce7;
                border-top:1px solid #bbf7d0;
                display:flex;
                gap:10px;
                align-items:center;
            ">

                <i
                    class="fas fa-check-circle"
                    style="
                        color:var(--success);
                        font-size:18px;
                    "
                ></i>

                <span style="
                    font-weight:600;
                    color:var(--success);
                    font-size:13.5px;
                ">

                    All grades posted.
                    Visible in student records and Registrar monitoring.

                </span>

            </div>

        <?php endif; ?>

    </div>


    <!-- =====================================================
         GRADE SCALE
    ===================================================== -->

    <div
        class="card"
        style="margin-top:14px;"
    >

        <div class="card-header">

            <div class="card-title">

                <i class="fas fa-table"></i>

                NEUST Grade Scale

            </div>

        </div>


        <div style="overflow-x:auto;">

            <table
                class="data-table"
                style="font-size:12.5px;"
            >

                <thead>

                    <tr>

                        <?php

                        $scaleLabels = [
                            '98–100',
                            '95–97',
                            '92–94',
                            '89–91',
                            '86–88',
                            '83–85',
                            '80–82',
                            '70–79',
                            '60–69',
                            'Below 60'
                        ];

                        foreach (
                            $scaleLabels as $label
                        ):

                        ?>

                            <th style="text-align:center;">
                                <?= $label ?>%
                            </th>

                        <?php endforeach; ?>

                    </tr>

                </thead>


                <tbody>

                    <tr>

                        <?php

                        $scaleEquivs = [
                            1.00,
                            1.25,
                            1.50,
                            1.75,
                            2.00,
                            2.25,
                            2.50,
                            3.00,
                            4.00,
                            5.00
                        ];

                        foreach (
                            $scaleEquivs as $e
                        ):

                            if ($e <= 3.00) {

                                $scaleColor =
                                    'var(--success)';

                            } elseif ($e == 4.00) {

                                $scaleColor =
                                    'var(--gold)';

                            } else {

                                $scaleColor =
                                    'var(--danger)';
                            }

                        ?>

                            <td style="
                                text-align:center;
                                font-weight:700;
                                color:<?= $scaleColor ?>;
                            ">

                                <?= number_format(
                                    $e,
                                    2
                                ) ?>

                            </td>

                        <?php endforeach; ?>

                    </tr>


                    <tr>

                        <?php

                        $scaleRemarks = [
                            'Passed',
                            'Passed',
                            'Passed',
                            'Passed',
                            'Passed',
                            'Passed',
                            'Passed',
                            'Passed',
                            'Conditional',
                            'Failed'
                        ];

                        foreach (
                            $scaleRemarks as $remark
                        ):

                            if (
                                $remark === 'Passed'
                            ) {

                                $remarkClass =
                                    'status-approved';

                            } elseif (
                                $remark === 'Conditional'
                            ) {

                                $remarkClass =
                                    'status-pending';

                            } else {

                                $remarkClass =
                                    'status-flagged';
                            }

                        ?>

                            <td style="
                                text-align:center;
                                padding-top:4px;
                            ">

                                <span
                                    class="status-badge
                                        <?= $remarkClass ?>"
                                >

                                    <?= $remark ?>

                                </span>

                            </td>

                        <?php endforeach; ?>

                    </tr>

                </tbody>

            </table>

        </div>

    </div>


<?php elseif (
    $selSection &&
    $selSubject &&
    $selSem &&
    empty($students)
): ?>

    <!-- NO STUDENTS -->

    <div class="card">

        <div style="
            padding:48px;
            text-align:center;
            color:var(--text-muted);
        ">

            <i
                class="fas fa-users"
                style="
                    font-size:44px;
                    display:block;
                    margin-bottom:14px;
                    opacity:.2;
                    color:var(--blue);
                "
            ></i>


            <div style="
                font-weight:600;
                margin-bottom:6px;
            ">

                No active students enrolled

            </div>


            <div style="font-size:13px;">

                There are currently no active students
                enrolled in this subject.

                Archived students are excluded from
                the active grade sheet.

                Please contact the Registrar if an
                active student should be enrolled.

            </div>

        </div>

    </div>


<?php elseif (!$selSection): ?>

    <!-- NO SECTION -->

    <div class="card">

        <div style="
            padding:48px;
            text-align:center;
            color:var(--text-muted);
        ">

            <i
                class="fas fa-chalkboard-teacher"
                style="
                    font-size:44px;
                    display:block;
                    margin-bottom:14px;
                    opacity:.2;
                    color:var(--blue);
                "
            ></i>


            <div style="
                font-weight:600;
                margin-bottom:6px;
            ">

                Select a section to begin

            </div>


            <div style="font-size:13px;">

                Only subjects you are assigned
                to teach will appear in the
                Subject dropdown.

            </div>

        </div>

    </div>

<?php endif; ?>


<!-- =========================================================
     SUBMIT CONFIRM MODAL
========================================================= -->

<div
    id="submitModal"
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
        width:460px;
        max-width:90vw;
        box-shadow:0 20px 60px rgba(0,0,0,.2);
        text-align:center;
    ">

        <div style="
            width:64px;
            height:64px;
            border-radius:50%;
            background:var(--gold-light);
            display:flex;
            align-items:center;
            justify-content:center;
            font-size:28px;
            margin:0 auto 14px;
        ">
            📤
        </div>


        <h3 style="
            font-family:'Playfair Display',serif;
            font-size:20px;
            margin-bottom:8px;
        ">

            Post Grades?

        </h3>


        <p style="
            font-size:13.5px;
            color:var(--text-muted);
            line-height:1.7;
            margin-bottom:22px;
        ">

            All saved grades will be posted directly to the student's academic record.
            The Registrar and Admin can view the posted grades.

        </p>


        <div style="
            display:flex;
            gap:10px;
            justify-content:center;
        ">

            <button
                class="btn btn-outline"
                onclick="
                    document
                        .getElementById('submitModal')
                        .style.display='none'
                "
            >

                Cancel

            </button>


            <button
                class="btn btn-gold"
                onclick="doSubmit()"
            >

                <i class="fas fa-paper-plane"></i>

                Post Grades Now

            </button>

        </div>

    </div>

</div>


<script>

/*
|--------------------------------------------------------------------------
| Grade Scale
|--------------------------------------------------------------------------
*/

const SCALE =
    <?= json_encode($gradeScale) ?>;


/*
|--------------------------------------------------------------------------
| Convert Percentage to Equivalent
|--------------------------------------------------------------------------
*/

function toEquiv(score) {

    score =
        parseFloat(score);

    if (isNaN(score)) {
        return 5.00;
    }

    if (score >= 98) return 1.00;
    if (score >= 95) return 1.25;
    if (score >= 92) return 1.50;
    if (score >= 89) return 1.75;
    if (score >= 86) return 2.00;
    if (score >= 83) return 2.25;
    if (score >= 80) return 2.50;
    if (score >= 70) return 3.00;
    if (score >= 60) return 4.00;

    return 5.00;
}


/*
|--------------------------------------------------------------------------
| Get Remarks
|--------------------------------------------------------------------------
*/

function getRemarks(equiv) {

    if (equiv <= 3.00) {
        return 'Passed';
    }

    if (equiv === 4.00) {
        return 'Conditional';
    }

    return 'Failed';
}


/*
|--------------------------------------------------------------------------
| Get Remarks Badge Class
|--------------------------------------------------------------------------
*/

function getRemarksClass(equiv) {

    if (equiv <= 3.00) {
        return 'status-approved';
    }

    if (equiv === 4.00) {
        return 'status-pending';
    }

    return 'status-flagged';
}


/*
|--------------------------------------------------------------------------
| Compute Row
|--------------------------------------------------------------------------
*/

function computeRow(row) {

    const mtInput =
        row.querySelector('.mt-input');

    const fnInput =
        row.querySelector('.fn-input');

    const mt =
        parseFloat(
            mtInput?.value
        );

    const fn =
        parseFloat(
            fnInput?.value
        );


    if (isNaN(mt) && isNaN(fn)) {

        const cg =
            row.querySelector(
                '.computed-grade'
            );

        const ge =
            row.querySelector(
                '.grade-equiv'
            );

        const rm =
            row.querySelector(
                '.remarks-cell'
            );


        if (cg) {
            cg.textContent = '—';
        }


        if (ge) {

            ge.textContent = '—';

            ge.style.color =
                'var(--text-muted)';
        }


        if (rm) {
            rm.innerHTML = '—';
        }

        return;
    }


    const midterm =
        isNaN(mt)
            ? 0
            : Math.min(
                100,
                Math.max(0, mt)
            );


    const finals =
        isNaN(fn)
            ? 0
            : Math.min(
                100,
                Math.max(0, fn)
            );


    const finalGrade =
        Math.round(
            (
                (midterm * 0.50) +
                (finals * 0.50)
            ) * 100
        ) / 100;


    const equiv =
        toEquiv(finalGrade);


    const remarks =
        getRemarks(equiv);


    const remarksClass =
        getRemarksClass(equiv);


    const cg =
        row.querySelector(
            '.computed-grade'
        );


    if (cg) {
        cg.textContent =
            finalGrade.toFixed(2);
    }


    const ge =
        row.querySelector(
            '.grade-equiv'
        );


    if (ge) {

        ge.textContent =
            equiv.toFixed(2);


        if (equiv <= 3.00) {

            ge.style.color =
                'var(--success)';

        } else if (equiv === 4.00) {

            ge.style.color =
                'var(--gold)';

        } else {

            ge.style.color =
                'var(--danger)';
        }
    }


    const rm =
        row.querySelector(
            '.remarks-cell'
        );


    if (rm) {

        rm.innerHTML =
            `<span class="status-badge ${remarksClass}">
                ${remarks}
            </span>`;
    }
}


/*
|--------------------------------------------------------------------------
| Clear Row
|--------------------------------------------------------------------------
*/

function clearRow(row) {

    const mt =
        row.querySelector('.mt-input');

    const fn =
        row.querySelector('.fn-input');

    const nt =
        row.querySelector('.notes-input');


    if (mt) {
        mt.value = '';
    }


    if (fn) {
        fn.value = '';
    }


    if (nt) {
        nt.value = '';
    }


    const cg =
        row.querySelector(
            '.computed-grade'
        );

    const ge =
        row.querySelector(
            '.grade-equiv'
        );

    const rm =
        row.querySelector(
            '.remarks-cell'
        );


    if (cg) {
        cg.textContent = '—';
    }


    if (ge) {

        ge.textContent = '—';

        ge.style.color =
            'var(--text-muted)';
    }


    if (rm) {
        rm.innerHTML = '—';
    }
}


/*
|--------------------------------------------------------------------------
| Build Payload
|--------------------------------------------------------------------------
*/

function buildPayload() {

    const rows =
        document.querySelectorAll(
            '#gradeTable tbody tr.grade-row'
        );

    const payload = [];


    rows.forEach(row => {

        const rawStatus = (row.dataset.status || '').trim();
        if (['Posted', 'Approved'].includes(rawStatus)) {
            return;
        }

        const mt =
            row.querySelector(
                '.mt-input'
            );

        const fn =
            row.querySelector(
                '.fn-input'
            );


        /*
        |--------------------------------------------------------------------------
        | Locked row
        |--------------------------------------------------------------------------
        */

        if (!mt) {
            return;
        }


        const mtV =
            parseFloat(
                mt.value
            ) || 0;


        const fnV =
            parseFloat(
                fn?.value
            ) || 0;


        /*
        |--------------------------------------------------------------------------
        | Skip empty row
        |--------------------------------------------------------------------------
        */

        if (
            mt.value === '' &&
            fn?.value === ''
        ) {
            return;
        }


        const final =
            Math.round(
                (
                    (
                        Math.min(
                            100,
                            Math.max(0, mtV)
                        ) * 0.40
                    ) +
                    (
                        Math.min(
                            100,
                            Math.max(0, fnV)
                        ) * 0.60
                    )
                ) * 100
            ) / 100;


        const equiv =
            toEquiv(final);


        const remarks =
            getRemarks(equiv);


        payload.push({

            enrollmentID:
                parseInt(
                    row.dataset.enrollment
                ),

            studentID:
                parseInt(
                    row.dataset.student
                ),

            gradeID:
                row.dataset.grade
                    ? parseInt(
                        row.dataset.grade
                    )
                    : null,

            subjectID:
                parseInt(
                    row.dataset.subject
                ),

            acadYearID:
                parseInt(
                    row.dataset.acadyear
                ),

            teacherID:
                parseInt(
                    row.dataset.teacher
                ),

            midterm:
                Math.min(
                    100,
                    Math.max(0, mtV)
                ),

            finals:
                Math.min(
                    100,
                    Math.max(0, fnV)
                ),

            computedGrade:
                final,

            gradeEquivalent:
                equiv,

            remarks:
                remarks,

            teacherRemarks:
                row.querySelector(
                    '.notes-input'
                )?.value || ''

        });

    });


    return payload;
}


/*
|--------------------------------------------------------------------------
| Save All Grades
|--------------------------------------------------------------------------
*/

async function saveAllGrades() {

    const payload =
        buildPayload();


    if (!payload.length) {

        showToast(
            'No grade data to save. Enter scores first.',
            'error'
        );

        return;
    }


    const btn =
        document.getElementById(
            'saveBtn'
        );


    if (btn) {

        btn.disabled = true;

        btn.innerHTML =
            '<i class="fas fa-spinner fa-spin"></i> Saving…';
    }


    try {

        const r =
            await fetch(
                '<?= BASE_URL ?>api/grades.php',
                {
                    method: 'POST',

                    headers: {
                        'Content-Type':
                            'application/json'
                    },

                    body:
                        JSON.stringify({
                            action:
                                'save_batch',

                            grades:
                                payload
                        })
                }
            );


        const d =
            await r.json();


        showToast(
            d.message ||
            (
                d.success
                    ? 'Grades saved successfully.'
                    : 'Unable to save grades.'
            ),

            d.success
                ? 'success'
                : 'error'
        );


        if (d.success) {

            document
                .getElementById(
                    'submitBtn'
                )
                ?.removeAttribute(
                    'disabled'
                );


            setTimeout(
                () => location.reload(),
                1200
            );
        }


    } catch (e) {

        console.error(e);

        showToast(
            'Network error.',
            'error'
        );


    } finally {

        if (btn) {

            btn.disabled = false;

            btn.innerHTML =
                '<i class="fas fa-save"></i> Save All';
        }
    }
}


/*
|--------------------------------------------------------------------------
| Confirm Submit
|--------------------------------------------------------------------------
*/

function confirmSubmit() {

    const modal =
        document.getElementById(
            'submitModal'
        );


    if (modal) {
        modal.style.display = 'flex';
    }
}


/*
|--------------------------------------------------------------------------
| Submit Grades
|--------------------------------------------------------------------------
*/

async function doSubmit() {

    const modal =
        document.getElementById(
            'submitModal'
        );


    if (modal) {
        modal.style.display = 'none';
    }


    try {

        const r =
            await fetch(
                '<?= BASE_URL ?>api/grades.php',
                {
                    method: 'POST',

                    headers: {
                        'Content-Type':
                            'application/json'
                    },

                    body:
                        JSON.stringify({

                            action:
                                'submit_section',

                            sectionID:
                                <?= (int)$selSection ?>,

                            subjectID:
                                <?= (int)($selSubject ?: 0) ?>,

                            semester:
                                <?= (int)$selSem ?>,

                            teacherID:
                                <?= (int)$tid ?>

                        })
                }
            );


        const d =
            await r.json();


        showToast(
            d.message ||
            (
                d.success
                    ? 'Grades posted successfully.'
                    : 'Unable to post grades.'
            ),

            d.success
                ? 'success'
                : 'error'
        );


        if (d.success) {

            setTimeout(
                () => location.reload(),
                1400
            );
        }


    } catch (e) {

        console.error(e);

        showToast(
            'Network error while submitting grades.',
            'error'
        );
    }
}


/*
|--------------------------------------------------------------------------
| Fill Sample Grades
|--------------------------------------------------------------------------
*/

function fillSampleGrades() {

    document
        .querySelectorAll(
            '#gradeTable tbody tr.grade-row'
        )
        .forEach(row => {

            const mt =
                row.querySelector(
                    '.mt-input'
                );

            const fn =
                row.querySelector(
                    '.fn-input'
                );


            if (!mt || !fn) {
                return;
            }


            mt.value =
                Math.floor(
                    Math.random() * 31
                ) + 70;


            fn.value =
                Math.floor(
                    Math.random() * 31
                ) + 70;


            computeRow(row);
        });


    showToast(
        'Sample grades filled. Review then Save.',
        'info'
    );
}


/*
|--------------------------------------------------------------------------
| Export Grades to CSV
|--------------------------------------------------------------------------
*/

function exportGrades() {

    const rows =
        document.querySelectorAll(
            '#gradeTable tbody tr.grade-row'
        );


    if (!rows.length) {

        showToast(
            'No students to export.',
            'error'
        );

        return;
    }


    let csv =
        'Student No,Last Name,First Name,Midterm,Finals,Computed Grade,Grade Equivalent,Remarks,Notes\n';


    rows.forEach(row => {

        const stuNo =
            row.querySelector(
                'td:nth-child(2) div:last-child'
            )?.textContent
            ?.trim() || '';


        const name =
            row.querySelector(
                'td:nth-child(2) div:first-child'
            )?.textContent
            ?.trim() || '';


        const nameParts =
            name.split(', ');


        const lName =
            nameParts[0] || '';


        const fName =
            nameParts[1] || '';


        const mt =
            row.querySelector(
                '.mt-input'
            )?.value
            ||
            row.querySelector(
                'td:nth-child(3) span'
            )?.textContent
            ?.trim()
            ||
            '';


        const fn =
            row.querySelector(
                '.fn-input'
            )?.value
            ||
            row.querySelector(
                'td:nth-child(4) span'
            )?.textContent
            ?.trim()
            ||
            '';


        const cg =
            row.querySelector(
                '.computed-grade'
            )?.textContent
            ?.trim()
            || '';


        const ge =
            row.querySelector(
                '.grade-equiv'
            )?.textContent
            ?.trim()
            || '';


        const rem =
            row.querySelector(
                '.remarks-cell'
            )?.textContent
            ?.trim()
            || '';


        const notes =
            row.querySelector(
                '.notes-input'
            )?.value
            || '';


        const escapeCsv =
            value =>
                `"${String(value)
                    .replace(/"/g, '""')}"`;


        csv +=
            [
                stuNo,
                lName,
                fName,
                mt,
                fn,
                cg,
                ge,
                rem,
                notes
            ]
            .map(escapeCsv)
            .join(',')
            + '\n';

    });


    const blob =
        new Blob(
            [csv],
            {
                type:
                    'text/csv;charset=utf-8;'
            }
        );


    const url =
        URL.createObjectURL(blob);


    const a =
        document.createElement('a');


    a.href = url;


    a.download =
        'grades_<?= htmlspecialchars(
            $secSubInfo['subjectCode']
                ?? 'export'
        ) ?>.csv';


    document.body.appendChild(a);

    a.click();

    document.body.removeChild(a);


    URL.revokeObjectURL(url);


    showToast(
        'Grades exported to CSV.',
        'success'
    );
}


/*
|--------------------------------------------------------------------------
| Import Grades from CSV
|--------------------------------------------------------------------------
*/

function importGrades(input) {

    const file =
        input.files[0];


    if (!file) {
        return;
    }


    const reader =
        new FileReader();


    reader.onload =
        function(e) {

            const text =
                e.target.result;


            const lines =
                text
                    .split(/\r?\n/)
                    .filter(
                        l => l.trim()
                    );


            if (lines.length < 2) {

                showToast(
                    'CSV file is empty or invalid.',
                    'error'
                );

                return;
            }


            const rows =
                document.querySelectorAll(
                    '#gradeTable tbody tr.grade-row'
                );


            let imported = 0;


            lines
                .slice(1)
                .forEach(line => {

                    const cols =
                        line
                            .split(
                                /,(?=(?:(?:[^"]*"){2})*[^"]*$)/
                            )
                            .map(
                                c =>
                                    c
                                        .replace(
                                            /^"|"$/g,
                                            ''
                                        )
                                        .replace(
                                            /""/g,
                                            '"'
                                        )
                                        .trim()
                            );


                    if (cols.length < 5) {
                        return;
                    }


                    const stuNo =
                        cols[0];


                    const mt =
                        parseFloat(
                            cols[3]
                        );


                    const fn =
                        parseFloat(
                            cols[4]
                        );


                    if (
                        isNaN(mt) ||
                        isNaN(fn)
                    ) {
                        return;
                    }


                    rows.forEach(row => {

                        const rowStuNo =
                            row.querySelector(
                                'td:nth-child(2) div:last-child'
                            )
                            ?.textContent
                            ?.trim();


                        if (
                            rowStuNo === stuNo
                        ) {

                            const mtIn =
                                row.querySelector(
                                    '.mt-input'
                                );


                            const fnIn =
                                row.querySelector(
                                    '.fn-input'
                                );


                            if (mtIn) {

                                mtIn.value =
                                    Math.min(
                                        100,
                                        Math.max(
                                            0,
                                            mt
                                        )
                                    );

                                mtIn.dispatchEvent(
                                    new Event(
                                        'input'
                                    )
                                );
                            }


                            if (fnIn) {

                                fnIn.value =
                                    Math.min(
                                        100,
                                        Math.max(
                                            0,
                                            fn
                                        )
                                    );

                                fnIn.dispatchEvent(
                                    new Event(
                                        'input'
                                    )
                                );
                            }


                            imported++;
                        }

                    });

                });


            showToast(
                `Imported ${imported} grade(s). Review and Save.`,

                imported > 0
                    ? 'success'
                    : 'warn'
            );
        };


    reader.readAsText(file);


    input.value = '';
}


/*
|--------------------------------------------------------------------------
| Close Modal When Clicking Outside
|--------------------------------------------------------------------------
*/

document
    .getElementById('submitModal')
    ?.addEventListener(
        'click',
        function(e) {

            if (
                e.target === this
            ) {

                this.style.display =
                    'none';
            }

        }
    );

</script>


<?php

require_once ROOT . '/includes/footer.php';

?>