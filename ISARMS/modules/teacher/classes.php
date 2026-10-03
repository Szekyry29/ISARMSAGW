<?php

define('ROOT', dirname(__DIR__, 2));

require_once ROOT . '/includes/config.php';
require_once ROOT . '/includes/functions.php';

requireAuth('Teacher');

$db   = Database::getInstance();
$user = getCurrentUser();

$tid = $user['id'];


/*
|--------------------------------------------------------------------------
| MY SECTIONS
|--------------------------------------------------------------------------
|
| Only sections where this teacher has at least one subject.
|
| IMPORTANT:
| A section is shown only when it still has at least one ACTIVE student.
|
| Graduated:
|     isGraduated = 1
|
| Dropped:
|     isDropped = 1
|
| Archived:
|     dateDeleted IS NOT NULL
|
| Inactive:
|     isActive = 0
|
|--------------------------------------------------------------------------
*/

$mySections = $db->fetchAll(
    "
    SELECT DISTINCT

        sec.sectionID,
        sec.sectionName,

        p.programCode,
        p.programName,

        ay.academicYear,
        ay.status AS ayStatus

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

      /*
      |--------------------------------------------------------------------------
      | Only show section if it has at least one active student.
      |--------------------------------------------------------------------------
      */

      AND EXISTS (

          SELECT 1

          FROM enrollments e

          JOIN students s
              ON s.studentID = e.studentID

          WHERE e.sectionID = sec.sectionID

            AND e.status = 'Active'

            AND e.dateDeleted IS NULL

            AND s.isGraduated = 0

            AND s.isDropped = 0

            AND s.isActive = 1

            AND s.dateDeleted IS NULL

      )

    ORDER BY

        ay.status = 'Active' DESC,

        ay.academicYear DESC,

        sec.sectionName ASC
    ",
    [
        ':t' => $tid
    ]
);


/*
|--------------------------------------------------------------------------
| SELECTED SECTION
|--------------------------------------------------------------------------
*/

$selectedID = (int)($_GET['section_id'] ?? 0);


/*
|--------------------------------------------------------------------------
| DEFAULT SECTION
|--------------------------------------------------------------------------
*/

if (!$selectedID && !empty($mySections)) {

    $selectedID =
        (int)($mySections[0]['sectionID'] ?? 0);
}


$section  = null;
$students = [];
$subjects = [];


/*
|--------------------------------------------------------------------------
| LOAD SECTION
|--------------------------------------------------------------------------
*/

if ($selectedID) {

    $section = $db->fetchOne(
        "
        SELECT

            sec.*,

            p.programCode,
            p.programName,

            ay.academicYear

        FROM sections sec

        JOIN programs p
            ON p.programID = sec.programID

        JOIN acad_years ay
            ON ay.acadYearID = sec.acadYearID

        WHERE sec.sectionID = :id

          AND sec.dateDeleted IS NULL
        ",
        [
            ':id' => $selectedID
        ]
    );


    /*
    |--------------------------------------------------------------------------
    | SECURITY:
    | Make sure teacher actually has a subject in this section.
    |--------------------------------------------------------------------------
    */

    if ($section) {

        $teacherSectionCheck = $db->fetchOne(
            "
            SELECT secSubID

            FROM section_subjects

            WHERE sectionID = :sectionID

              AND teacherID = :teacherID

              AND dateDeleted IS NULL

            LIMIT 1
            ",
            [
                ':sectionID' => $selectedID,
                ':teacherID' => $tid
            ]
        );

        if (!$teacherSectionCheck) {

            $section = null;
        }
    }


    /*
    |--------------------------------------------------------------------------
    | LOAD SUBJECTS
    |--------------------------------------------------------------------------
    */

    if ($section) {

        /*
        |--------------------------------------------------------------------------
        | Only subjects THIS teacher teaches in this section.
        |--------------------------------------------------------------------------
        */

        $subjects = $db->fetchAll(
            "
            SELECT

                ss.secSubID,
                ss.subjectID,
                ss.semester,
                ss.schedule,
                ss.room,

                sub.subjectCode,
                sub.subjectName,
                sub.units,

                COUNT(
                    DISTINCT e.studentID
                ) AS enrolledCount,

                SUM(
                    CASE
                        WHEN g.status IN ('Approved', 'Posted')
                        THEN 1
                        ELSE 0
                    END
                ) AS posted,

                SUM(
                    CASE
                        WHEN g.status = 'Pending'
                        THEN 1
                        ELSE 0
                    END
                ) AS pending

            FROM section_subjects ss

            JOIN subjects sub
                ON sub.subjectID = ss.subjectID

            /*
            |--------------------------------------------------------------------------
            | Active enrollments only.
            |--------------------------------------------------------------------------
            */

            LEFT JOIN enrollments e

                ON e.sectionID = ss.sectionID

                AND e.subjectID = ss.subjectID

                AND e.semester = ss.semester

                AND e.acadYearID = ss.acadYearID

                AND e.status = 'Active'

                AND e.dateDeleted IS NULL

            /*
            |--------------------------------------------------------------------------
            | IMPORTANT:
            | Exclude grades belonging to graduated,
            | dropped, archived or inactive students.
            |--------------------------------------------------------------------------
            */

            LEFT JOIN students st
                ON st.studentID = e.studentID

                AND st.isGraduated = 0

                AND st.isDropped = 0

                AND st.isActive = 1

                AND st.dateDeleted IS NULL

            LEFT JOIN grades g

                ON g.enrollmentID = e.enrollmentID

                AND g.teacherID = ss.teacherID

                AND g.dateDeleted IS NULL

                AND st.studentID IS NOT NULL

            WHERE ss.sectionID = :sid

              AND ss.teacherID = :t
              AND ss.subjectID IS NOT NULL
              AND ss.semester IS NOT NULL
              AND ss.dateDeleted IS NULL

              AND sub.dateDeleted IS NULL

              AND EXISTS (
                  SELECT 1
                  FROM acad_years ay2
                  WHERE ay2.acadYearID = ss.acadYearID
                    AND ay2.dateDeleted IS NULL
                    AND ay2.status = 'Active'
              )

            GROUP BY

                ss.secSubID,
                ss.subjectID,
                ss.semester,
                ss.schedule,
                ss.room,
                sub.subjectCode,
                sub.subjectName,
                sub.units

            ORDER BY

                ss.semester ASC,
                sub.subjectCode ASC
            ",
            [
                ':sid' => $selectedID,
                ':t'   => $tid
            ]
        );


        /*
        |--------------------------------------------------------------------------
        | LOAD STUDENT ROSTER
        |--------------------------------------------------------------------------
        |
        | THIS IS THE IMPORTANT FIX.
        |
        | Graduated students:
        |     s.isGraduated = 0
        |
        | Archived students:
        |     s.dateDeleted IS NULL
        |
        | Dropped students:
        |     s.isDropped = 0
        |
        | Inactive students:
        |     s.isActive = 1
        |
        |--------------------------------------------------------------------------
        */

        $students = $db->fetchAll(
            "
            SELECT

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
                         AND s.mName <> ''
                        THEN '.'
                        ELSE ''
                    END
                ) AS fullName,

                s.fName,
                s.mName,
                s.sex,
                s.yearLevel,

                s.isEnroll,
                s.isActive,
                s.isGraduated,
                s.isDropped,
                s.dateDeleted,

                                (
                                        SELECT gr.latinHonor
                                        FROM gwa_records gr
                                        WHERE gr.studentID = s.studentID
                                            AND gr.validationStatus = 'Validated'
                                            AND gr.latinHonor <> 'None'
                                            AND gr.gwaValue > 0
                                        ORDER BY gr.gwaID DESC
                                        LIMIT 1
                                ) AS validatedHonor,

                /*
                |--------------------------------------------------------------------------
                | Overall approved GWA
                |--------------------------------------------------------------------------
                */

                ROUND(
                    (
                        SELECT

                            SUM(
                                g2.gradeEquivalent
                                *
                                sub2.units
                            )
                            /
                            NULLIF(
                                SUM(sub2.units),
                                0
                            )

                        FROM grades g2

                        JOIN enrollments e2
                            ON e2.enrollmentID =
                               g2.enrollmentID

                        JOIN subjects sub2
                            ON sub2.subjectID =
                               g2.subjectID

                        WHERE e2.studentID =
                              s.studentID

                          AND g2.status IN ('Approved', 'Posted')

                          AND g2.gradeEquivalent
                              IS NOT NULL

                          AND g2.dateDeleted
                              IS NULL
                    ),
                    4
                ) AS gwa,


                /*
                |--------------------------------------------------------------------------
                | Number of active subjects for this teacher
                |--------------------------------------------------------------------------
                */

                COUNT(
                    DISTINCT e.subjectID
                ) AS enrolledSubjects,


                /*
                |--------------------------------------------------------------------------
                | Approved grades entered by this teacher
                |--------------------------------------------------------------------------
                */

                SUM(
                    CASE

                        WHEN g.status IN ('Approved', 'Posted')

                         AND g.teacherID = :t

                        THEN 1

                        ELSE 0

                    END
                ) AS myApproved,


                /*
                |--------------------------------------------------------------------------
                | Pending / submitted grades entered by teacher
                |--------------------------------------------------------------------------
                */

                SUM(
                    CASE

                            WHEN g.status IN ('Pending', 'Returned')

                         AND g.teacherID = :t2

                        THEN 1

                        ELSE 0

                    END
                ) AS myPending


            FROM enrollments e

            JOIN students s
                ON s.studentID = e.studentID


            /*
            |--------------------------------------------------------------------------
            | ONLY ACTIVE STUDENTS
            |--------------------------------------------------------------------------
            */

            LEFT JOIN grades g

                ON g.enrollmentID =
                   e.enrollmentID

                AND g.dateDeleted IS NULL


            WHERE e.sectionID = :sec

              AND e.status = 'Active'

              AND e.dateDeleted IS NULL


              /*
              |--------------------------------------------------------------------------
              | DO NOT SHOW GRADUATED STUDENTS
              |--------------------------------------------------------------------------
              */

              AND s.isGraduated = 0


              /*
              |--------------------------------------------------------------------------
              | DO NOT SHOW ARCHIVED STUDENTS
              |--------------------------------------------------------------------------
              */

              AND s.dateDeleted IS NULL


              /*
              |--------------------------------------------------------------------------
              | DO NOT SHOW DROPPED STUDENTS
              |--------------------------------------------------------------------------
              */

              AND s.isDropped = 0


              /*
              |--------------------------------------------------------------------------
              | DO NOT SHOW INACTIVE STUDENTS
              |--------------------------------------------------------------------------
              */

              AND s.isActive = 1

                            /* Show students with at least one complete semester. */
                            AND EXISTS (
                                    SELECT 1
                                    FROM enrollments complete_e
                                    WHERE complete_e.studentID = s.studentID
                                        AND complete_e.status = 'Active'
                                        AND complete_e.dateDeleted IS NULL
                                        AND " . gwaIncludedSubjectFilter('complete_e') . "
                                    GROUP BY complete_e.acadYearID, complete_e.semester
                                    HAVING COUNT(*) = SUM(
                                            CASE WHEN EXISTS (
                                                    SELECT 1
                                                    FROM grades complete_g
                                                    WHERE complete_g.enrollmentID = complete_e.enrollmentID
                                                        AND " . officialGradeFilter('complete_g.status') . "
                                                        AND complete_g.gradeEquivalent IS NOT NULL
                                                        AND complete_g.dateDeleted IS NULL
                                            ) THEN 1 ELSE 0 END
                                    )
                            )

              /*
              |--------------------------------------------------------------------------
              | Only students enrolled in at least
              | one subject handled by this teacher.
              |--------------------------------------------------------------------------
              */

              AND e.subjectID IN (

                  SELECT ss2.subjectID

                  FROM section_subjects ss2

                  WHERE ss2.sectionID = :sec2

                    AND ss2.teacherID = :t3

                    AND ss2.dateDeleted IS NULL

              )


            GROUP BY

                s.studentID,
                s.studentNo,
                s.lName,
                s.fName,
                s.mName,
                s.sex,
                s.yearLevel,
                s.isEnroll,
                s.isActive,
                s.isGraduated,
                s.isDropped,
                s.dateDeleted


            ORDER BY

                s.lName ASC,
                s.fName ASC
            ",
            [
                ':t'    => $tid,
                ':t2'   => $tid,
                ':t3'   => $tid,
                ':sec'  => $selectedID,
                ':sec2' => $selectedID
            ]
        );

        foreach ($students as &$studentRow) {
            $completeSemester = $db->fetchOne(
                "SELECT ay.academicYear, e.acadYearID, e.semester,
                        ROUND(SUM(g.gradeEquivalent * sub.units) / NULLIF(SUM(sub.units), 0), 4) AS semesterGwa
                 FROM enrollments e
                 JOIN acad_years ay ON ay.acadYearID = e.acadYearID
                 JOIN subjects sub ON sub.subjectID = e.subjectID AND sub.dateDeleted IS NULL
                 LEFT JOIN grades g ON g.enrollmentID = e.enrollmentID
                     AND " . officialGradeFilter('g.status') . "
                     AND g.gradeEquivalent IS NOT NULL
                     AND g.dateDeleted IS NULL
                                 WHERE e.studentID=:sid AND e.status='Active' AND e.dateDeleted IS NULL
                                     AND " . gwaIncludedSubjectFilter('e') . "
                 GROUP BY ay.academicYear, e.acadYearID, e.semester
                 HAVING COUNT(DISTINCT e.enrollmentID) = COUNT(DISTINCT CASE WHEN g.gradeEquivalent IS NOT NULL THEN e.enrollmentID END)
                 ORDER BY e.acadYearID DESC, e.semester DESC
                 LIMIT 1",
                [':sid' => $studentRow['studentID']]
            );
            $studentRow['semesterGwa'] = $completeSemester['semesterGwa'] ?? null;
            $studentRow['semesterGwaLabel'] = $completeSemester
                ? $completeSemester['academicYear'] . ' ' . ((int)$completeSemester['semester'] === 1 ? '1st' : '2nd') . ' Sem'
                : null;
        }
        unset($studentRow);
    }
}


/*
|--------------------------------------------------------------------------
| PAGE
|--------------------------------------------------------------------------
*/

$pageTitle  = 'My Classes';
$activeMenu = 'classes.php';

require_once ROOT . '/includes/header.php';

?>


<!-- =========================================================
     MAIN LAYOUT
========================================================= -->

<div
    style="
        display:grid;
        grid-template-columns:280px 1fr;
        gap:20px;
        align-items:start;
    "
>


    <!-- =====================================================
         SECTION LIST
    ====================================================== -->

    <div class="card">

        <div class="card-header">

            <div class="card-title">

                <i class="fas fa-list"></i>

                My Sections

            </div>

            <span
                style="
                    font-size:12px;
                    color:var(--text-muted);
                "
            >

                <?= count($mySections) ?>

            </span>

        </div>


        <?php if (empty($mySections)): ?>

            <div
                style="
                    padding:32px;
                    text-align:center;
                    color:var(--text-muted);
                    font-size:13px;
                "
            >

                <i
                    class="fas fa-users-slash"
                    style="
                        display:block;
                        font-size:30px;
                        margin-bottom:12px;
                        opacity:.3;
                    "
                ></i>

                No active sections available.

                <div
                    style="
                        margin-top:8px;
                        font-size:12px;
                    "
                >

                    Your sections will appear here when
                    you have active students assigned.

                </div>

            </div>

        <?php else: ?>

            <?php

            $prevAY = '';

            foreach ($mySections as $sec):

                if ($sec['academicYear'] !== $prevAY):

                    $prevAY =
                        $sec['academicYear'];

            ?>

                <div
                    style="
                        padding:7px 14px;
                        background:
                            <?= $sec['ayStatus'] === 'Active'
                                ? 'var(--blue-light)'
                                : 'var(--bg)' ?>;
                        font-size:10px;
                        font-weight:700;
                        text-transform:uppercase;
                        letter-spacing:1.5px;
                        color:
                            <?= $sec['ayStatus'] === 'Active'
                                ? 'var(--blue)'
                                : 'var(--text-muted)' ?>;
                        border-bottom:1px solid var(--border);
                    "
                >

                    <?= htmlspecialchars(
                        $sec['academicYear']
                    ) ?>

                    <?= $sec['ayStatus'] === 'Active'
                        ? ' ★'
                        : '' ?>

                </div>

            <?php endif; ?>


                <a
                    href="classes.php?section_id=<?= (int)$sec['sectionID'] ?>"
                    style="
                        display:flex;
                        align-items:center;
                        gap:10px;
                        padding:12px 14px;
                        text-decoration:none;
                        border-bottom:1px solid var(--border);
                        background:
                            <?= $selectedID == $sec['sectionID']
                                ? 'var(--blue-light)'
                                : '#fff' ?>;
                        border-left:4px solid
                            <?= $selectedID == $sec['sectionID']
                                ? 'var(--blue)'
                                : 'transparent' ?>;
                    "
                >

                    <div
                        style="
                            flex:1;
                            min-width:0;
                        "
                    >

                        <div
                            style="
                                font-size:13.5px;
                                font-weight:700;
                                color:
                                    <?= $selectedID == $sec['sectionID']
                                        ? 'var(--blue)'
                                        : 'var(--text)' ?>;
                            "
                        >

                            <?= htmlspecialchars(
                                $sec['sectionName']
                            ) ?>

                        </div>

                        <div
                            style="
                                font-size:11.5px;
                                color:var(--text-muted);
                            "
                        >

                            <?= htmlspecialchars(
                                $sec['programCode']
                            ) ?>

                        </div>

                    </div>

                </a>

            <?php endforeach; ?>

        <?php endif; ?>

    </div>


    <!-- =====================================================
         SECTION DETAIL
    ====================================================== -->

    <div>

        <?php if (!$section): ?>

            <div class="card">

                <div
                    style="
                        padding:60px;
                        text-align:center;
                        color:var(--text-muted);
                    "
                >

                    <i
                        class="fas fa-arrow-left"
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
                            font-size:16px;
                            font-weight:600;
                            margin-bottom:8px;
                        "
                    >

                        No section available

                    </div>

                    <div
                        style="
                            font-size:13px;
                        "
                    >

                        Your assigned subjects and active
                        student roster will appear here once
                        a section is assigned.

                    </div>

                </div>

            </div>

        <?php else: ?>


            <!-- =================================================
                 SECTION BANNER
            ================================================== -->

            <div
                style="
                    background:
                        linear-gradient(
                            135deg,
                            var(--blue-dark),
                            var(--blue)
                        );
                    border-radius:14px;
                    padding:20px 26px;
                    margin-bottom:16px;
                    color:#fff;
                    display:flex;
                    justify-content:space-between;
                    align-items:center;
                "
            >

                <div>

                    <div
                        style="
                            font-size:11px;
                            opacity:.6;
                            text-transform:uppercase;
                            letter-spacing:1.5px;
                            margin-bottom:3px;
                        "
                    >

                        My Classes

                    </div>

                    <div
                        style="
                            font-family:'Playfair Display',serif;
                            font-size:22px;
                            font-weight:700;
                        "
                    >

                        <?= htmlspecialchars(
                            $section['sectionName']
                        ) ?>

                    </div>

                    <div
                        style="
                            font-size:13px;
                            opacity:.8;
                        "
                    >

                        <?= htmlspecialchars(
                            $section['programName']
                        ) ?>

                        &bull;

                        <?= htmlspecialchars(
                            $section['academicYear']
                        ) ?>

                    </div>

                </div>


                <div
                    style="
                        display:flex;
                        gap:8px;
                    "
                >

                    <a
                        href="class_report.php?section_id=<?= $selectedID ?>"
                        class="btn btn-outline"
                        style="
                            color:#fff;
                            border-color:rgba(255,255,255,.4);
                        "
                    >

                        <i class="fas fa-chart-bar"></i>

                        Report

                    </a>

                </div>

            </div>


            <!-- =================================================
                 SUBJECTS
            ================================================== -->

            <div
                class="card"
                style="margin-bottom:16px;"
            >

                <div class="card-header">

                    <div class="card-title">

                        <i class="fas fa-book"></i>

                        My Subjects in This Section

                    </div>

                    <span
                        style="
                            font-size:13px;
                            color:var(--text-muted);
                        "
                    >

                        You teach
                        <?= count($subjects) ?>
                        subject(s) here

                    </span>

                </div>


                <?php if (empty($subjects)): ?>

                    <div
                        style="
                            padding:32px;
                            text-align:center;
                            color:var(--text-muted);
                            font-size:13px;
                        "
                    >

                        No subjects assigned to you in this section.

                    </div>

                <?php else: ?>

                    <div style="overflow-x:auto;">

                        <table class="data-table">

                            <thead>

                                <tr>

                                    <th>
                                        Subject
                                    </th>

                                    <th
                                        style="
                                            text-align:center;
                                        "
                                    >
                                        Sem
                                    </th>

                                    <th>
                                        Schedule
                                    </th>

                                    <th
                                        style="
                                            text-align:center;
                                        "
                                    >
                                        Students
                                    </th>

                                    <th
                                        style="
                                            text-align:center;
                                        "
                                    >
                                        Pending
                                    </th>

                                    <th
                                        style="
                                            text-align:center;
                                        "
                                    >
                                        Posted
                                    </th>

                                    <th
                                        style="
                                            text-align:center;
                                        "
                                    >
                                        Action
                                    </th>

                                </tr>

                            </thead>


                            <tbody>

                                <?php foreach ($subjects as $sub): ?>

                                <tr>

                                    <td>

                                        <div
                                            style="
                                                font-weight:700;
                                                color:var(--blue);
                                            "
                                        >

                                            <?= htmlspecialchars(
                                                $sub['subjectCode']
                                            ) ?>

                                        </div>

                                        <div
                                            style="
                                                font-size:11.5px;
                                                color:var(--text-muted);
                                            "
                                        >

                                            <?= htmlspecialchars(
                                                $sub['subjectName']
                                            ) ?>

                                            ·

                                            <?= $sub['units'] ?>u

                                        </div>

                                    </td>


                                    <td
                                        style="
                                            text-align:center;
                                            font-weight:700;
                                        "
                                    >

                                        <?= $sub['semester'] == 1
                                            ? '1st'
                                            : '2nd' ?>

                                    </td>


                                    <td
                                        style="
                                            font-size:12px;
                                            color:var(--text-muted);
                                        "
                                    >

                                        <?= $sub['schedule']
                                            ? htmlspecialchars(
                                                $sub['schedule']
                                            )
                                            : '—' ?>

                                        <?= $sub['room']
                                            ? ' / '
                                            . htmlspecialchars(
                                                $sub['room']
                                            )
                                            : '' ?>

                                    </td>


                                    <td
                                        style="
                                            text-align:center;
                                            font-weight:700;
                                        "
                                    >

                                        <?= (int)$sub['enrolledCount'] ?>

                                    </td>


                                    <td
                                        style="
                                            text-align:center;
                                        "
                                    >

                                        <?= $sub['pending'] > 0

                                            ? "<span class='status-badge status-pending'>"
                                              . (int)$sub['pending']
                                              . "</span>"

                                            : '—'
                                        ?>

                                    </td>


                                    <td
                                        style="
                                            text-align:center;
                                        "
                                    >

                                        <?= $sub['posted'] > 0

                                            ? "<span class='status-badge status-approved'>"
                                              . (int)$sub['posted']
                                              . "</span>"

                                            : '—'
                                        ?>

                                    </td>


                                    <td
                                        style="
                                            text-align:center;
                                        "
                                    >

                                        <a
                                            href="
                                                encode_grades.php
                                                ?section_id=<?= $selectedID ?>
                                                &subject_id=<?= (int)$sub['subjectID'] ?>
                                                &semester=<?= (int)$sub['semester'] ?>
                                            "
                                            class="
                                                btn
                                                btn-primary
                                                btn-sm
                                            "
                                        >

                                            <i class="fas fa-pen"></i>

                                            Grade

                                        </a>

                                    </td>

                                </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                <?php endif; ?>

            </div>


            <!-- =================================================
                 STUDENT ROSTER
            ================================================== -->

            <div class="card">

                <div class="card-header">

                    <div class="card-title">

                        <i class="fas fa-users"></i>

                        Student Roster

                    </div>

                    <span
                        style="
                            font-size:13px;
                            color:var(--text-muted);
                        "
                    >

                        <?= count($students) ?>

                        active student(s) enrolled
                        in my subjects

                    </span>

                </div>


                <?php if (empty($students)): ?>

                    <div
                        style="
                            padding:40px 32px;
                            text-align:center;
                            color:var(--text-muted);
                            font-size:13px;
                        "
                    >

                        <i
                            class="fas fa-user-graduate"
                            style="
                                display:block;
                                font-size:34px;
                                margin-bottom:12px;
                                opacity:.25;
                            "
                        ></i>

                        No active students enrolled yet.

                        <div
                            style="
                                margin-top:8px;
                                font-size:12px;
                            "
                        >

                            Graduated, dropped, archived,
                            and inactive students are
                            automatically hidden from this roster.

                        </div>

                    </div>

                <?php else: ?>

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

                                    <th
                                        style="
                                            text-align:center;
                                        "
                                    >
                                        My Subjects
                                    </th>

                                    <th
                                        style="
                                            text-align:center;
                                        "
                                    >
                                        GWA
                                    </th>

                                    <th
                                        style="
                                            text-align:center;
                                        "
                                    >
                                        Honor
                                    </th>

                                    <th
                                        style="
                                            text-align:center;
                                        "
                                    >
                                        Graded
                                    </th>

                                    <th
                                        style="
                                            text-align:center;
                                        "
                                    >
                                        Pending
                                    </th>

                                    <th
                                        style="
                                            text-align:center;
                                        "
                                    >
                                        Progress
                                    </th>

                                </tr>

                            </thead>


                            <tbody>

                                <?php foreach (
                                    $students as $i => $s
                                ): ?>

                                <?php

                                $studentHonor = $s['validatedHonor'] ?? 'None';

                                ?>


                                <tr>

                                    <!-- NUMBER -->

                                    <td
                                        style="
                                            color:var(--text-muted);
                                            font-size:12px;
                                        "
                                    >

                                        <?= $i + 1 ?>

                                    </td>


                                    <!-- STUDENT -->

                                    <td>

                                        <div
                                            style="
                                                display:flex;
                                                align-items:center;
                                                gap:10px;
                                            "
                                        >

                                            <div
                                                style="
                                                    width:34px;
                                                    height:34px;
                                                    border-radius:50%;
                                                    background:
                                                        linear-gradient(
                                                            135deg,
                                                            var(--blue),
                                                            var(--blue-mid)
                                                        );
                                                    display:flex;
                                                    align-items:center;
                                                    justify-content:center;
                                                    font-weight:700;
                                                    font-size:13px;
                                                    color:#fff;
                                                    flex-shrink:0;
                                                "
                                            >

                                                <?= strtoupper(
                                                    substr(
                                                        $s['fName'],
                                                        0,
                                                        1
                                                    )
                                                ) ?>

                                            </div>


                                            <div>

                                                <div
                                                    style="
                                                        font-weight:700;
                                                        font-size:13.5px;
                                                    "
                                                >

                                                    <?= htmlspecialchars(
                                                        $s['fullName']
                                                    ) ?>

                                                </div>


                                                <div
                                                    style="
                                                        font-size:11px;
                                                        color:var(--text-muted);
                                                    "
                                                >

                                                    <?= htmlspecialchars(
                                                        $s['sex']
                                                    ) ?>

                                                    ·

                                                    Year
                                                    <?= (int)$s['yearLevel'] ?>

                                                </div>

                                            </div>

                                        </div>

                                    </td>


                                    <!-- STUDENT NUMBER -->

                                    <td
                                        style="
                                            font-weight:700;
                                            color:var(--blue);
                                        "
                                    >

                                        <?= htmlspecialchars(
                                            $s['studentNo']
                                        ) ?>

                                    </td>


                                    <!-- MY SUBJECTS -->

                                    <td
                                        style="
                                            text-align:center;
                                            font-weight:700;
                                        "
                                    >

                                        <?= (int)$s['enrolledSubjects'] ?>

                                    </td>


                                    <!-- GWA -->

                                    <td
                                        style="
                                            text-align:center;
                                            font-weight:700;
                                            color:
                                                <?= (
                                                    (float)(
                                                        $s['semesterGwa'] ?? 0
                                                    ) <= 1.75
                                                )
                                                    ? 'var(--success)'
                                                    : 'var(--blue)' ?>;
                                        "
                                    >

                                        <?= $s['semesterGwa']
                                            ? number_format(
                                                (float)$s['semesterGwa'],
                                                4
                                            )
                                            : '—' ?>
                                        <?php if (!empty($s['semesterGwaLabel'])): ?>
                                            <div style="font-size:10px;font-weight:400;color:var(--text-muted);">
                                                <?= htmlspecialchars($s['semesterGwaLabel']) ?>
                                            </div>
                                        <?php endif; ?>

                                    </td>


                                    <!-- HONOR -->

                                    <td
                                        style="
                                            text-align:center;
                                        "
                                    >

                                        <?php if (
                                            $studentHonor !== 'None'
                                        ): ?>

                                            <span
                                                class="<?= honorBadgeClass(
                                                    $studentHonor
                                                ) ?>"
                                            >

                                                <?= htmlspecialchars(
                                                    $studentHonor
                                                ) ?>

                                            </span>

                                        <?php else: ?>

                                            <span
                                                style="
                                                    color:var(--text-muted);
                                                    font-size:12px;
                                                "
                                            >
                                                —
                                            </span>

                                        <?php endif; ?>

                                    </td>


                                    <!-- GRADED -->

                                    <td
                                        style="
                                            text-align:center;
                                        "
                                    >

                                        <span
                                            class="
                                                status-badge
                                                status-approved
                                            "
                                        >

                                            <?= (int)$s['myApproved'] ?>

                                            ✓

                                        </span>

                                    </td>


                                    <!-- PENDING -->

                                    <td
                                        style="
                                            text-align:center;
                                        "
                                    >

                                        <?php if (
                                            (int)$s['myPending'] > 0
                                        ): ?>

                                            <span
                                                class="
                                                    status-badge
                                                    status-pending
                                                "
                                            >

                                                <?= (int)$s['myPending'] ?>

                                            </span>

                                        <?php else: ?>

                                            —

                                        <?php endif; ?>

                                    </td>


                                    <!-- PROGRESS -->

                                    <td>

                                        <?php

                                        $enrolledSubjects =
                                            (int)(
                                                $s['enrolledSubjects']
                                                ?? 0
                                            );

                                        $myApproved =
                                            (int)(
                                                $s['myApproved']
                                                ?? 0
                                            );

                                        $pct =
                                            $enrolledSubjects > 0
                                                ? round(
                                                    (
                                                        $myApproved
                                                        /
                                                        $enrolledSubjects
                                                    )
                                                    * 100
                                                )
                                                : 0;

                                        if ($pct > 100) {
                                            $pct = 100;
                                        }

                                        ?>

                                        <div
                                            style="
                                                display:flex;
                                                align-items:center;
                                                gap:8px;
                                            "
                                        >

                                            <div
                                                style="
                                                    flex:1;
                                                    height:8px;
                                                    background:var(--border);
                                                    border-radius:4px;
                                                    overflow:hidden;
                                                "
                                            >

                                                <div
                                                    style="
                                                        height:100%;
                                                        width:<?= $pct ?>%;
                                                        background:
                                                            <?= $pct == 100
                                                                ? 'var(--success)'
                                                                : (
                                                                    $pct >= 50
                                                                        ? 'var(--blue)'
                                                                        : 'var(--warning)'
                                                                ) ?>;
                                                        border-radius:4px;
                                                    "
                                                ></div>

                                            </div>


                                            <span
                                                style="
                                                    font-size:11px;
                                                    color:var(--text-muted);
                                                    width:32px;
                                                "
                                            >

                                                <?= $pct ?>%

                                            </span>

                                        </div>

                                    </td>

                                </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                <?php endif; ?>

            </div>

        <?php endif; ?>

    </div>

</div>


<!-- =========================================================
     RESPONSIVE
========================================================= -->

<style>

@media (max-width: 1000px) {

    body .card {
        max-width:100%;
    }

}

@media (max-width: 850px) {

    div[style*="grid-template-columns:280px 1fr"] {
        grid-template-columns:1fr !important;
    }

}

</style>


<?php

require_once ROOT . '/includes/footer.php';

?>