<?php

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

function studentSectionYearLevel($sectionName, $programCode = ''): ?int {
    $label = studentSectionLabel($sectionName, $programCode);
    if (preg_match('/^(?:year|yr)\s*[-\s]*([1-5])\b/i', $label, $matches)) {
        return (int)$matches[1];
    }
    if (preg_match('/^([1-5])(?:st|nd|rd|th)?(?=\s*(?:year|yr)?(?:[\s\-_–]|[A-Za-z]|$))/i', $label, $matches)) {
        return (int)$matches[1];
    }
    return null;
}

function progressedSectionLabel($sectionName, $programCode, int $targetYear): ?string {
    $label = studentSectionLabel($sectionName, $programCode);
    $pattern = '/^((?:year|yr)\s*[-\s]*)?([1-5])(st|nd|rd|th)?(?=\s*(?:year|yr)?(?:[\s\-_–]|[A-Za-z]|$))/iu';
    $replacements = 0;
    $targetLabel = preg_replace_callback(
        $pattern,
        static function (array $matches) use ($targetYear): string {
            $ordinal = $matches[3] ?? '';
            if ($ordinal !== '') {
                $ordinal = [1=>'st', 2=>'nd', 3=>'rd', 4=>'th'][$targetYear] ?? '';
            }
            return ($matches[1] ?? '') . $targetYear . $ordinal;
        },
        $label,
        1,
        $replacements
    );

    return $replacements === 1 ? $targetLabel : null;
}

function advancePassingStudent(Database $db, int $studentID): array {
    $student = $db->fetchOne(
        "SELECT s.studentID, s.fName, s.mName, s.lName, s.extName, s.yearLevel, s.programID,
                s.isActive, s.isDropped, s.isGraduated, s.dateDeleted, p.programCode
         FROM students s
         JOIN programs p ON p.programID=s.programID
         WHERE s.studentID=:id",
        [':id'=>$studentID]
    );
    if (!$student) return ['ok'=>false, 'message'=>'Student not found.'];
    if (!empty($student['dateDeleted'])) return ['ok'=>false, 'message'=>'Student is archived.'];
    if (!(int)$student['isActive']) return ['ok'=>false, 'message'=>'Student account is inactive.'];
    if ((int)$student['isDropped']) return ['ok'=>false, 'message'=>'Student is dropped.'];
    if ((int)$student['isGraduated']) return ['ok'=>false, 'message'=>'Student is already graduated.'];

    $latest = $db->fetchOne(
        "SELECT e.acadYearID, e.semester, e.sectionID, ay.academicYear, sec.sectionName
         FROM enrollments e
         JOIN acad_years ay ON ay.acadYearID=e.acadYearID
         LEFT JOIN sections sec ON sec.sectionID=e.sectionID
         WHERE e.studentID=:sid AND e.status='Active' AND e.dateDeleted IS NULL
         ORDER BY ay.academicYear DESC, e.semester DESC, e.enrollmentID DESC
         LIMIT 1",
        [':sid'=>$studentID]
    );
    if (!$latest) return ['ok'=>false, 'message'=>'No completed enrollment period found.'];

    $period = $db->fetchOne(
        "SELECT COUNT(DISTINCT e.enrollmentID) AS totalSubjects,
                COUNT(DISTINCT CASE WHEN (g.status IN ('Posted','Approved') OR
                            (g.status='Pending' AND g.submittedAt IS NOT NULL
                             AND g.computedGrade IS NOT NULL AND g.gradeEquivalent IS NOT NULL))
                    AND g.gradeEquivalent IS NOT NULL AND g.gradeEquivalent <= 3.00
                    THEN e.enrollmentID END) AS passingSubjects
         FROM enrollments e
         LEFT JOIN grades g ON g.enrollmentID=e.enrollmentID AND g.dateDeleted IS NULL
         WHERE e.studentID=:sid AND e.acadYearID=:ay AND e.semester=:sem
           AND e.status='Active' AND e.dateDeleted IS NULL",
        [':sid'=>$studentID, ':ay'=>$latest['acadYearID'], ':sem'=>$latest['semester']]
    );

    $periodIsPassing = (int)$period['totalSubjects'] > 0
        && (int)$period['totalSubjects'] === (int)$period['passingSubjects'];
    if (!$periodIsPassing && (int)$latest['semester'] === 2) {
        $firstSemester = $db->fetchOne(
            "SELECT e.sectionID, sec.sectionName
             FROM enrollments e
             LEFT JOIN sections sec ON sec.sectionID=e.sectionID
             WHERE e.studentID=:sid AND e.acadYearID=:ay AND e.semester=1
               AND e.status='Active' AND e.dateDeleted IS NULL
             ORDER BY e.enrollmentID DESC
             LIMIT 1",
            [':sid'=>$studentID, ':ay'=>$latest['acadYearID']]
        );
        if ($firstSemester) {
            $firstSemesterPeriod = $db->fetchOne(
                "SELECT COUNT(DISTINCT e.enrollmentID) AS totalSubjects,
                        COUNT(DISTINCT CASE WHEN (g.status IN ('Posted','Approved') OR
                                    (g.status='Pending' AND g.submittedAt IS NOT NULL
                                     AND g.computedGrade IS NOT NULL AND g.gradeEquivalent IS NOT NULL))
                            AND g.gradeEquivalent IS NOT NULL AND g.gradeEquivalent <= 3.00
                            THEN e.enrollmentID END) AS passingSubjects
                 FROM enrollments e
                 LEFT JOIN grades g ON g.enrollmentID=e.enrollmentID AND g.dateDeleted IS NULL
                 WHERE e.studentID=:sid AND e.acadYearID=:ay AND e.semester=1
                   AND e.status='Active' AND e.dateDeleted IS NULL",
                [':sid'=>$studentID, ':ay'=>$latest['acadYearID']]
            );
            $periodIsPassing = (int)$firstSemesterPeriod['totalSubjects'] > 0
                && (int)$firstSemesterPeriod['totalSubjects'] === (int)$firstSemesterPeriod['passingSubjects'];
            if ($periodIsPassing) {
                $latest['semester'] = 1;
                $latest['sectionID'] = $firstSemester['sectionID'];
                $latest['sectionName'] = $firstSemester['sectionName'];
            }
        }
    }
    if (!$periodIsPassing) {
        return ['ok'=>false, 'message'=>'Latest semester is not fully posted and passing.'];
    }
    if (hasBackSubjects($studentID)) {
        return ['ok'=>false, 'message'=>'Student has failed or incomplete back subjects.'];
    }

    $currentYear = studentSectionYearLevel($latest['sectionName'] ?? '', $student['programCode'])
        ?? (int)$student['yearLevel'];
    $semester = (int)$latest['semester'];
    $targetYear = $currentYear;
    $targetAY = (int)$latest['acadYearID'];
    if ($semester === 1) {
        $targetSemester = 2;
    } else {
        if ($currentYear >= 4) return ['ok'=>false, 'message'=>'Fourth-year students cannot be advanced automatically.'];
        $targetSemester = 1;
        $targetYear = $currentYear + 1;
        $nextAY = $db->fetchOne(
            "SELECT acadYearID, academicYear FROM acad_years
             WHERE dateDeleted IS NULL AND acadYearID > :ay
             ORDER BY acadYearID ASC LIMIT 1",
            [':ay'=>$targetAY]
        );
        if (!$nextAY) return ['ok'=>false, 'message'=>'No next academic year is configured.'];
        $targetAY = (int)$nextAY['acadYearID'];
    }

    $targetSectionLabel = progressedSectionLabel(
        $latest['sectionName'] ?? '',
        $student['programCode'],
        $targetYear
    );
    if ($targetSectionLabel === null) {
        return ['ok'=>false, 'message'=>'The current section name does not identify a year/cohort to match in the next period.'];
    }

    $targetSections = $db->fetchAll(
        "SELECT sectionID, sectionName FROM sections
         WHERE programID=:program AND acadYearID=:ay AND dateDeleted IS NULL
         ORDER BY sectionID",
        [':program'=>$student['programID'], ':ay'=>$targetAY]
    );
    $targetSection = null;
    foreach ($targetSections as $candidateSection) {
        if (strcasecmp(studentSectionLabel($candidateSection['sectionName'], $student['programCode']), $targetSectionLabel) === 0) {
            $targetSection = $candidateSection;
            break;
        }
    }
    if (!$targetSection) {
        return ['ok'=>false, 'message'=>"No matching section ({$targetSectionLabel}) is configured for the next academic period."];
    }
    $sectionID = (int)$targetSection['sectionID'];

    $subjects = $db->fetchAll(
        "SELECT ss.subjectID, sub.subjectCode, sub.yearLevel,
                EXISTS (
                    SELECT 1
                    FROM enrollments prior_e
                    JOIN grades prior_g ON prior_g.enrollmentID=prior_e.enrollmentID
                    WHERE prior_e.studentID=:student
                      AND prior_e.subjectID=ss.subjectID
                      AND prior_g.dateDeleted IS NULL
                      AND prior_g.status IN ('Posted','Approved')
                      AND prior_g.gradeEquivalent IS NOT NULL
                      AND prior_g.gradeEquivalent <= 3.00
                ) AS previouslyPassed
         FROM section_subjects ss
         JOIN subjects sub ON sub.subjectID=ss.subjectID AND sub.dateDeleted IS NULL
         WHERE ss.sectionID=:section AND ss.acadYearID=:ay AND ss.semester=:sem AND ss.dateDeleted IS NULL
         ORDER BY ss.subjectID",
        [':student'=>$studentID, ':section'=>$sectionID, ':ay'=>$targetAY, ':sem'=>$targetSemester]
    );
    if (!$subjects) return ['ok'=>false, 'message'=>'No subjects are configured for the next academic period.'];
    $subjectsToEnroll = [];
    $previouslyPassedCount = 0;
    foreach ($subjects as $subject) {
        if (!isSubjectYearAllowedForStudent($targetYear, $subject['yearLevel'])) {
            return ['ok'=>false, 'message'=>"Subject {$subject['subjectCode']} is outside the target Year {$targetYear} curriculum."];
        }
        if ((int)$subject['previouslyPassed'] === 1) {
            $previouslyPassedCount++;
            continue;
        }
        $subjectsToEnroll[] = $subject;
    }
    if (!$subjectsToEnroll) {
        return ['ok'=>false, 'message'=>'All subjects configured for the next period have already been passed. No repeat enrollments were created; update the section curriculum first.'];
    }

    $existingTargetSubjects = $db->fetchAll(
        "SELECT subjectID FROM enrollments
         WHERE studentID=:sid AND sectionID=:section AND acadYearID=:ay AND semester=:sem
           AND status='Active' AND dateDeleted IS NULL",
        [':sid'=>$studentID, ':section'=>$sectionID, ':ay'=>$targetAY, ':sem'=>$targetSemester]
    );
    $existingTargetSubjectIDs = array_fill_keys(array_map('intval', array_column($existingTargetSubjects, 'subjectID')), true);
    $subjectsToEnroll = array_values(array_filter(
        $subjectsToEnroll,
        static fn(array $subject): bool => !isset($existingTargetSubjectIDs[(int)$subject['subjectID']])
    ));
    $targetAcademicYear = $db->fetchOne(
        "SELECT academicYear FROM acad_years WHERE acadYearID=:ay",
        [':ay'=>$targetAY]
    );
    if (!$subjectsToEnroll) {
        $periodLabel = $targetAcademicYear['academicYear'] . ' - ' . ($targetSemester === 1 ? '1st' : '2nd') . ' Semester';
        return [
            'ok'=>true,
            'alreadyEnrolled'=>true,
            'message'=>fullName($student) . ": already enrolled in all subjects for {$periodLabel}."
        ];
    }

    $enrolled = 0;
    $db->beginTransaction();
    try {
        foreach ($subjectsToEnroll as $subject) {
            $exists = $db->fetchOne(
                "SELECT enrollmentID FROM enrollments
                 WHERE studentID=:sid AND subjectID=:subject AND acadYearID=:ay AND semester=:sem
                   AND dateDeleted IS NULL",
                [':sid'=>$studentID, ':subject'=>$subject['subjectID'], ':ay'=>$targetAY, ':sem'=>$targetSemester]
            );
            if ($exists) continue;
            $db->insert(
                "INSERT INTO enrollments
                 (dateofEnroll, acadYear, semester, studentID, subjectID, programID, sectionID, acadYearID, status)
                 VALUES (NOW(), :academicYear, :semester, :student, :subject, :program, :section, :ay, 'Active')",
                [':academicYear'=>$targetAcademicYear['academicYear'], ':semester'=>$targetSemester,
                 ':student'=>$studentID, ':subject'=>$subject['subjectID'], ':program'=>$student['programID'],
                 ':section'=>$sectionID, ':ay'=>$targetAY]
            );
            $enrolled++;
        }
        $db->query("UPDATE students SET yearLevel=:year, isEnroll=1 WHERE studentID=:id", [':year'=>$targetYear, ':id'=>$studentID]);
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->getConnection()->inTransaction()) $db->rollback();
        return ['ok'=>false, 'message'=>'Enrollment failed: ' . $exception->getMessage()];
    }

    $periodLabel = $targetAcademicYear['academicYear'] . ' - ' . ($targetSemester === 1 ? '1st' : '2nd') . ' Semester';
    $studentName = fullName($student);
    notifyUsersByRole('Admin', 'Student Advanced', "Registrar advanced {$studentName} to {$periodLabel}.", 'Student', 'Registrar');
    createNotification($studentID, 'Student', 'Next Enrollment Created', "You were advanced to {$periodLabel} and enrolled in {$enrolled} subject(s).", 'Student', 'Registrar');
    $repeatNote = $previouslyPassedCount > 0
        ? " {$previouslyPassedCount} previously passed subject(s) were skipped."
        : '';
    return ['ok'=>true, 'message'=>"{$studentName}: {$periodLabel}, {$enrolled} subject(s) enrolled.{$repeatNote}"];
}

$msg = '';
$err = '';

/*
|--------------------------------------------------------------------------
| HANDLE POST ACTIONS
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {

        $err = 'Invalid request.';

    } else {

        $action = $_POST['action'] ?? '';

        if ($action === 'advance_all_passing') {
            if (($_POST['view'] ?? '') === 'archived') {
                $err = 'Archived students cannot be advanced.';
            } else {
                $passingStudents = $db->fetchAll(
                    "SELECT studentID FROM students
                     WHERE dateDeleted IS NULL AND isActive=1 AND isDropped=0 AND isGraduated=0
                     ORDER BY studentID"
                );
                $advanced = 0;
                $alreadyEnrolled = 0;
                $skipped = 0;
                $skipReasons = [];
                foreach ($passingStudents as $passingStudent) {
                    $result = advancePassingStudent($db, (int)$passingStudent['studentID']);
                    if ($result['ok'] && !empty($result['alreadyEnrolled'])) $alreadyEnrolled++;
                    elseif ($result['ok']) $advanced++;
                    else {
                        $skipped++;
                        $reason = $result['message'] ?? 'Not eligible.';
                        $skipReasons[$reason] = ($skipReasons[$reason] ?? 0) + 1;
                    }
                }
                if ($advanced > 0 || $alreadyEnrolled > 0) {
                    $messages = [];
                    if ($advanced > 0) $messages[] = "Advanced {$advanced} passing student(s) and created their next-period enrollments.";
                    if ($alreadyEnrolled > 0) $messages[] = "{$alreadyEnrolled} student(s) are already enrolled in all next-period subjects.";
                    $msg = implode(' ', $messages);
                    if ($skipped > 0) {
                        $reasons = [];
                        foreach ($skipReasons as $reason => $count) $reasons[] = "{$count}: {$reason}";
                        $msg .= ' Skipped: ' . implode(' ', $reasons);
                    }
                } else {
                    if ($skipReasons) {
                        $reasons = [];
                        foreach ($skipReasons as $reason => $count) $reasons[] = "{$count} student(s): {$reason}";
                        $err = 'No students were advanced. ' . implode(' ', $reasons);
                    } else {
                        $err = 'No active students are available to advance.';
                    }
                }
            }

        /*
        |--------------------------------------------------------------------------
        | UPDATE YEAR
        |--------------------------------------------------------------------------
        */

        } elseif ($action === 'advance_student') {
            $result = advancePassingStudent($db, (int)($_POST['studentID'] ?? 0));
            if ($result['ok']) $msg = $result['message'];
            else $err = $result['message'];


        /*
        |--------------------------------------------------------------------------
        | UPDATE YEAR
        |--------------------------------------------------------------------------
        */

        } elseif ($action === 'update_year') {

            $sid     = (int)($_POST['studentID'] ?? 0);
            $newYear = (int)($_POST['yearLevel'] ?? 0);

            if ($sid <= 0) {

                $err = 'Invalid student.';

            } elseif ($newYear < 1 || $newYear > 4) {

                $err = 'Invalid year level.';

            } else {

                $studentCheck = $db->fetchOne(
                    "
                    SELECT
                        studentID,
                        isDropped,
                        dateDeleted
                    FROM students
                    WHERE studentID = :id
                    ",
                    [
                        ':id' => $sid
                    ]
                );

                if (!$studentCheck) {

                    $err = 'Student not found.';

                } elseif (!empty($studentCheck['dateDeleted'])) {

                    $err = 'Archived students cannot be updated.';

                } elseif ((int)$studentCheck['isDropped'] === 1) {

                    $err = 'Dropped students must be reactivated first.';

                } else {

                    $db->query(
                        "
                        UPDATE students
                        SET yearLevel = :yl
                        WHERE studentID = :id
                        ",
                        [
                            ':yl' => $newYear,
                            ':id' => $sid
                        ]
                    );

                    $msg = 'Student year level updated.';
                    $studentNameRow = $db->fetchOne("SELECT fName, mName, lName, extName FROM students WHERE studentID=:id", [':id'=>$sid]);
                    $studentName = $studentNameRow ? fullName($studentNameRow) : "Student #{$sid}";
                    notifyUsersByRole('Admin', 'Student Year Advanced', "Registrar advanced {$studentName} to Year {$newYear}.", 'Student', 'Registrar');
                    createNotification($sid, 'Student', 'Year Level Updated', "Your year level was advanced to Year {$newYear} by the Registrar.", 'Student', 'Registrar');
                }
            }


        /*
        |--------------------------------------------------------------------------
        | TOGGLE ENROLLMENT
        |--------------------------------------------------------------------------
        */

        } elseif ($action === 'toggle_enroll') {

            $sid = (int)($_POST['studentID'] ?? 0);
            $val = (int)($_POST['isEnroll'] ?? 0);

            if ($sid <= 0) {

                $err = 'Invalid student.';

            } elseif (!in_array($val, [0, 1], true)) {

                $err = 'Invalid enrollment status.';

            } else {

                $studentCheck = $db->fetchOne(
                    "
                    SELECT
                        studentID,
                        isGraduated,
                        isDropped,
                        isActive,
                        dateDeleted
                    FROM students
                    WHERE studentID = :id
                    ",
                    [
                        ':id' => $sid
                    ]
                );

                if (!$studentCheck) {

                    $err = 'Student not found.';

                } elseif (!empty($studentCheck['dateDeleted'])) {

                    $err = 'Archived students cannot be enrolled.';

                } elseif ((int)$studentCheck['isDropped'] === 1) {

                    $err = 'Dropped students must be reactivated before enrollment.';

                } elseif ((int)$studentCheck['isActive'] !== 1) {

                    $err = 'Inactive students cannot be enrolled.';

                } else {

                    $db->query(
                        "
                        UPDATE students
                        SET isEnroll = :e
                        WHERE studentID = :id
                        ",
                        [
                            ':e'  => $val,
                            ':id' => $sid
                        ]
                    );

                    $msg = $val
                        ? 'Student marked as enrolled.'
                        : 'Student marked as not enrolled.';
                    $studentNameRow = $db->fetchOne("SELECT fName, mName, lName, extName FROM students WHERE studentID=:id", [':id'=>$sid]);
                    $studentName = $studentNameRow ? fullName($studentNameRow) : "Student #{$sid}";
                    notifyUsersByRole('Admin', $val ? 'Student Enrolled' : 'Student Unenrolled', "Registrar changed the enrollment status of {$studentName}.", 'Student', 'Registrar');
                    createNotification($sid, 'Student', $val ? 'Enrollment Updated' : 'Enrollment Removed', $val ? 'Your enrollment status was marked active by the Registrar.' : 'Your enrollment status was marked inactive by the Registrar.', 'Enrollment', 'Registrar');
                }
            }


        /*
        |--------------------------------------------------------------------------
        | DROP STUDENT
        |--------------------------------------------------------------------------
        |
        | Dropped students are NOT permanently removed.
        | They can be reactivated later.
        |--------------------------------------------------------------------------
        */

        } elseif ($action === 'drop_student') {

            $sid = (int)($_POST['studentID'] ?? 0);

            if ($sid <= 0) {

                $err = 'Invalid student.';

            } else {

                $studentCheck = $db->fetchOne(
                    "
                    SELECT
                        studentID,
                        dateDeleted,
                        isDropped
                    FROM students
                    WHERE studentID = :id
                    ",
                    [
                        ':id' => $sid
                    ]
                );

                if (!$studentCheck) {

                    $err = 'Student not found.';

                } elseif (!empty($studentCheck['dateDeleted'])) {

                    $err = 'Archived students cannot be dropped.';

                } elseif ((int)$studentCheck['isDropped'] === 1) {

                    $err = 'Student is already marked as dropped.';

                } else {

                    $db->query(
                        "
                        UPDATE students
                        SET
                            isDropped = 1,
                            droppedAt = NOW(),
                            isEnroll = 0,
                            isActive = 0
                        WHERE studentID = :id
                        ",
                        [
                            ':id' => $sid
                        ]
                    );

                    $msg = 'Student marked as dropped. The student can be reactivated later.';
                    $studentNameRow = $db->fetchOne("SELECT fName, mName, lName, extName FROM students WHERE studentID=:id", [':id'=>$sid]);
                    $studentName = $studentNameRow ? fullName($studentNameRow) : "Student #{$sid}";
                    notifyUsersByRole('Admin', 'Student Dropped', "Registrar dropped {$studentName}.", 'Student', 'Registrar');
                    createNotification($sid, 'Student', 'Student Dropped', 'Your student record was marked as dropped by the Registrar.', 'Student', 'Registrar');
                }
            }


        /*
        |--------------------------------------------------------------------------
        | REACTIVATE DROPPED STUDENT
        |--------------------------------------------------------------------------
        |
        | This allows a previously dropped student to return.
        | After reactivation, the student can be enrolled again.
        |--------------------------------------------------------------------------
        */

        } elseif ($action === 'reactivate_student') {

            $sid = (int)($_POST['studentID'] ?? 0);

            if ($sid <= 0) {

                $err = 'Invalid student.';

            } else {

                $studentCheck = $db->fetchOne(
                    "
                    SELECT
                        studentID,
                        isDropped,
                        isGraduated,
                        dateDeleted
                    FROM students
                    WHERE studentID = :id
                    ",
                    [
                        ':id' => $sid
                    ]
                );

                if (!$studentCheck) {

                    $err = 'Student not found.';

                } elseif (!empty($studentCheck['dateDeleted'])) {

                    $err = 'Archived students must be restored first.';

                } elseif ((int)$studentCheck['isGraduated'] === 1) {

                    $err = 'This student is already graduated.';

                } elseif ((int)$studentCheck['isDropped'] !== 1) {

                    $err = 'Student is not marked as dropped.';

                } else {

                    $db->query(
                        "
                        UPDATE students
                        SET
                            isDropped = 0,
                            droppedAt = NULL,
                            isActive = 1,
                            isEnroll = 0
                        WHERE studentID = :id
                        ",
                        [
                            ':id' => $sid
                        ]
                    );

                    $msg = 'Student reactivated successfully. The student can now be enrolled again.';
                }
            }


        /*
        |--------------------------------------------------------------------------
        | ARCHIVE STUDENT
        |--------------------------------------------------------------------------
        |
        | Archive replaces the old graduation/archive workflow.
        | Any active student can be archived.
        |--------------------------------------------------------------------------
        */

        } elseif ($action === 'archive_student') {

            $sid = (int)($_POST['studentID'] ?? 0);

            if ($sid <= 0) {

                $err = 'Invalid student.';

            } else {

                $studentCheck = $db->fetchOne(
                    "
                    SELECT
                        studentID,
                        isDropped,
                        dateDeleted
                    FROM students
                    WHERE studentID = :id
                    ",
                    [
                        ':id' => $sid
                    ]
                );

                if (!$studentCheck) {

                    $err = 'Student not found.';

                } elseif (!empty($studentCheck['dateDeleted'])) {

                    $err = 'Student is already archived.';

                } else {

                    $db->query(
                        "
                        UPDATE students
                        SET
                            dateDeleted = NOW(),
                            isActive = 0,
                            isEnroll = 0
                        WHERE studentID = :id
                        ",
                        [
                            ':id' => $sid
                        ]
                    );

                    $msg = 'Student archived successfully.';
                    $studentNameRow = $db->fetchOne("SELECT fName, mName, lName, extName FROM students WHERE studentID=:id", [':id'=>$sid]);
                    $studentName = $studentNameRow ? fullName($studentNameRow) : "Student #{$sid}";
                    notifyUsersByRole('Admin', 'Student Archived', "Registrar archived {$studentName}.", 'Student', 'Registrar');
                    createNotification($sid, 'Student', 'Student Record Archived', 'Your student record was archived by the Registrar.', 'Student', 'Registrar');
                }
            }


        /*
        |--------------------------------------------------------------------------
        | RESTORE ARCHIVED STUDENT
        |--------------------------------------------------------------------------
        */

        } elseif ($action === 'restore_student') {

            $sid = (int)($_POST['studentID'] ?? 0);

            if ($sid <= 0) {

                $err = 'Invalid student.';

            } else {

                $studentCheck = $db->fetchOne(
                    "
                    SELECT
                        studentID,
                        isDropped,
                        isGraduated,
                        dateDeleted
                    FROM students
                    WHERE studentID = :id
                    ",
                    [
                        ':id' => $sid
                    ]
                );

                if (!$studentCheck) {

                    $err = 'Student not found.';

                } elseif (empty($studentCheck['dateDeleted'])) {

                    $err = 'Student is not archived.';

                } else {

                    if ((int)$studentCheck['isDropped'] === 1) {

                        $db->query(
                            "
                            UPDATE students
                            SET
                                dateDeleted = NULL,
                                isActive = 0,
                                isEnroll = 0
                            WHERE studentID = :id
                            ",
                            [
                                ':id' => $sid
                            ]
                        );

                        $msg = 'Archived student restored, but remains dropped. Reactivate the student before enrollment.';

                    } else {

                        $db->query(
                            "
                            UPDATE students
                            SET
                                dateDeleted = NULL,
                                isActive = 1,
                                isEnroll = 0
                            WHERE studentID = :id
                            ",
                            [
                                ':id' => $sid
                            ]
                        );

                        $msg = 'Student restored successfully. The student can now be enrolled.';
                    }
                }
            }


        /*
        |--------------------------------------------------------------------------
        | ADVANCE YEAR
        |--------------------------------------------------------------------------
        */

        } elseif ($action === 'advance_year') {
            $result = advancePassingStudent($db, (int)($_POST['studentID'] ?? 0));
            if ($result['ok']) $msg = $result['message'];
            else $err = $result['message'];
        }
    }
}


/*
|--------------------------------------------------------------------------
| FILTERS
|--------------------------------------------------------------------------
*/

$search = sanitize($_GET['search'] ?? '');

$progID = (int)($_GET['program_id'] ?? 0);

$yearLvl = (int)($_GET['year_level'] ?? 0);

$sectionID = (int)($_GET['section_id'] ?? 0);

$enrolled = isset($_GET['enrolled'])
    ? (string)$_GET['enrolled']
    : '';

$studentType = $_GET['student_type'] ?? '';

$showArchived = ($_GET['view'] ?? '') === 'archived';

$perPage = 20;

$page = max(
    1,
    (int)($_GET['page'] ?? 1)
);

/*
|--------------------------------------------------------------------------
| BUILD FILTER QUERY (excluding program/year, applied separately for tab counts)
|--------------------------------------------------------------------------
*/

$where  = [$showArchived ? 's.dateDeleted IS NOT NULL' : 's.dateDeleted IS NULL'];
$params = [];


/*
|--------------------------------------------------------------------------
| SEARCH
|--------------------------------------------------------------------------
*/

if ($search !== '') {

    $where[] = "
        (
            s.studentNo LIKE :search
            OR s.fName LIKE :search_fname
            OR s.mName LIKE :search_mname
            OR s.lName LIKE :search_lname
            OR CONCAT(s.fName, ' ', COALESCE(s.mName, ''), ' ', s.lName) LIKE :search_full_name
            OR CONCAT(s.lName, ', ', s.fName) LIKE :search_name_list
            OR s.email LIKE :search_email
        )
    ";

    $searchValue = '%' . $search . '%';

    $params[':search']             = $searchValue;
    $params[':search_fname']       = $searchValue;
    $params[':search_mname']       = $searchValue;
    $params[':search_lname']       = $searchValue;
    $params[':search_full_name']    = $searchValue;
    $params[':search_name_list']    = $searchValue;
    $params[':search_email']       = $searchValue;
}


/*
|--------------------------------------------------------------------------
| SECTION FILTER
|--------------------------------------------------------------------------
*/

if ($sectionID > 0) {

    $where[] = "
        s.studentID IN (
            SELECT e.studentID
            FROM enrollments e
            WHERE e.sectionID = :section_id
              AND e.dateDeleted IS NULL
        )
    ";

    $params[':section_id'] = $sectionID;
}


/*
|--------------------------------------------------------------------------
| ENROLLMENT STATUS FILTER
|--------------------------------------------------------------------------
*/

if ($enrolled === '1' || $enrolled === '0') {

    $where[] = 's.isEnroll = :enrolled';

    $params[':enrolled'] = (int)$enrolled;
}


/*
|--------------------------------------------------------------------------
| STUDENT TYPE
|--------------------------------------------------------------------------
*/

if ($studentType === 'regular') {

    $where[] = 's.isEnroll = 1';

} elseif ($studentType === 'irregular') {

    $where[] = 's.isEnroll = 1 AND s.yearLevel = 5';
}


/*
|--------------------------------------------------------------------------
| BASE WHERE (search/section/enrolled/type only — used for tab/pill counts)
|--------------------------------------------------------------------------
*/

$baseWhereSQL = implode(' AND ', $where);


/*
|--------------------------------------------------------------------------
| PROGRAMS (for tabs) + counts under current base filters
|--------------------------------------------------------------------------
*/

$programs = $db->fetchAll(
    "
    SELECT
        programID,
        programCode
    FROM programs
    WHERE dateDeleted IS NULL
    ORDER BY programCode ASC
    "
);

$programCounts = [];

foreach ($programs as $p) {

    $cParams = $params;
    $cParams[':cnt_program_id'] = $p['programID'];

    $row = $db->fetchOne(
        "
        SELECT COUNT(*) AS c
        FROM students s
        WHERE $baseWhereSQL
          AND s.programID = :cnt_program_id
        ",
        $cParams
    );

    $programCounts[$p['programID']] = (int)($row['c'] ?? 0);
}

$allProgramsCountRow = $db->fetchOne(
    "
    SELECT COUNT(*) AS c
    FROM students s
    WHERE $baseWhereSQL
    ",
    $params
);

$allProgramsCount = (int)($allProgramsCountRow['c'] ?? 0);


/*
|--------------------------------------------------------------------------
| YEAR LEVEL PILLS + counts under current base filters + selected program
|--------------------------------------------------------------------------
*/

$yearWhereSQL = $baseWhereSQL;
$yearParams   = $params;

if ($progID > 0) {

    $yearWhereSQL .= ' AND s.programID = :year_program_id';
    $yearParams[':year_program_id'] = $progID;
}

$yearCounts = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];

foreach ($yearCounts as $yl => $ignore) {

    $yParams = $yearParams;
    $yParams[':cnt_year_level'] = $yl;

    $row = $db->fetchOne(
        "
        SELECT COUNT(*) AS c
        FROM students s
        WHERE $yearWhereSQL
          AND s.yearLevel = :cnt_year_level
        ",
        $yParams
    );

    $yearCounts[$yl] = (int)($row['c'] ?? 0);
}


/*
|--------------------------------------------------------------------------
| FINAL WHERE (adds program + year on top of base filters)
|--------------------------------------------------------------------------
*/

if ($progID > 0) {

    $where[] = 's.programID = :program_id';

    $params[':program_id'] = $progID;
}

if ($yearLvl > 0) {

    $where[] = 's.yearLevel = :year_level';

    $params[':year_level'] = $yearLvl;
}

$whereSQL = implode(' AND ', $where);

$hasExplicitFilter = $search !== '' || $progID > 0 || $yearLvl > 0 || $sectionID > 0 || $enrolled !== '' || $studentType !== '' || $showArchived;

if (!$hasExplicitFilter) {
    $total = 0;
    $students = [];
    $pages = 1;
    $archivedCount = 0;
} else {
    /*
    |--------------------------------------------------------------------------
    | TOTAL STUDENTS
    |--------------------------------------------------------------------------
    */

    $totalResult = $db->fetchOne(
        "
        SELECT COUNT(*) AS c
        FROM students s
        WHERE $whereSQL
        ",
        $params
    );

    $total = (int)($totalResult['c'] ?? 0);

    $archivedCountRow = $db->fetchOne(
        "SELECT COUNT(*) AS c FROM students WHERE dateDeleted IS NOT NULL"
    );
    $archivedCount = (int)($archivedCountRow['c'] ?? 0);

    $pages = max(
        1,
        (int)ceil($total / $perPage)
    );

    if ($page > $pages) {
        $page = $pages;
    }

    $offset = ($page - 1) * $perPage;

    /*
    |--------------------------------------------------------------------------
    | FETCH STUDENTS
    |--------------------------------------------------------------------------
    */

    $students = $db->fetchAll(
        "
        SELECT

            s.studentID,
            s.studentNo,
            s.fName,
            s.mName,
            s.lName,
            s.yearLevel,

            s.isEnroll,
            s.isActive,
            s.isGraduated,
            s.isDropped,

            s.email,
            s.dateCreated,
            s.dateDeleted,

            p.programCode,

            sec.sectionName,

            (
                SELECT COUNT(*)
                FROM grades g
                JOIN enrollments e
                    ON e.enrollmentID = g.enrollmentID
                WHERE e.studentID = s.studentID
                  AND g.status = 'Submitted'
                  AND g.dateDeleted IS NULL
            ) AS pendingGrades,

            (
                SELECT COUNT(*)
                FROM grades g
                JOIN enrollments e
                    ON e.enrollmentID = g.enrollmentID
                WHERE e.studentID = s.studentID
                  AND g.status = 'Approved'
                  AND g.dateDeleted IS NULL
            ) AS approvedGrades,

            validated_gwa.gwaValue AS gwa

        FROM students s

        JOIN programs p
            ON p.programID = s.programID

        LEFT JOIN enrollments current_enrollment
            ON current_enrollment.enrollmentID = (
                SELECT e_current.enrollmentID
                FROM enrollments e_current
                JOIN acad_years ay_current
                    ON ay_current.acadYearID = e_current.acadYearID
                WHERE e_current.studentID = s.studentID
                  AND e_current.status = 'Active'
                  AND e_current.dateDeleted IS NULL
                ORDER BY ay_current.academicYear DESC,
                         e_current.semester DESC,
                         e_current.enrollmentID DESC
                LIMIT 1
            )

        LEFT JOIN sections sec
            ON sec.sectionID = current_enrollment.sectionID

        LEFT JOIN (
            SELECT gr.studentID, gr.gwaValue
            FROM gwa_records gr
            JOIN (
                SELECT studentID, MAX(gwaID) AS gwaID
                FROM gwa_records
                WHERE validationStatus = 'Validated'
                  AND gwaValue > 0
                GROUP BY studentID
            ) latest_gwa
              ON latest_gwa.gwaID = gr.gwaID
        ) validated_gwa
            ON validated_gwa.studentID = s.studentID

        WHERE $whereSQL

        ORDER BY
            s.yearLevel ASC,
            s.lName ASC,
            s.fName ASC

        LIMIT $perPage
        OFFSET $offset
        ",
        $params
    );
}


/*
|--------------------------------------------------------------------------
| CALCULATE RANKS
|--------------------------------------------------------------------------
*/

$allRankEntries = $db->fetchAll(
    "
    SELECT
        s.studentID,
        s.yearLevel,
        s.isGraduated,
        gr.gwaValue AS gwa

    FROM students s
        JOIN (
                SELECT studentID, MAX(gwaID) AS gwaID
                FROM gwa_records
                WHERE semester IS NULL
                    AND validationStatus = 'Validated'
                    AND gwaValue > 0
                GROUP BY studentID
        ) latest_gr ON latest_gr.studentID = s.studentID
        JOIN gwa_records gr ON gr.gwaID = latest_gr.gwaID

    WHERE s.dateDeleted IS NULL
    "
);


$rankGroups = [];

foreach ($allRankEntries as $entry) {

    if ($entry['gwa'] === null) {
        continue;
    }

    $yearForRanking =
        (
            (int)($entry['isGraduated'] ?? 0) === 1
            &&
            (int)$entry['yearLevel'] >= 4
        )
        ? 4
        : (int)$entry['yearLevel'];

    $rankGroups[$yearForRanking][] = $entry;
}


foreach ($rankGroups as &$group) {

    usort(
        $group,
        function ($a, $b) {

            $gwaCompare =
                (float)$a['gwa'] <=> (float)$b['gwa'];

            if ($gwaCompare !== 0) {
                return $gwaCompare;
            }

            return (int)$a['studentID']
                <=> (int)$b['studentID'];
        }
    );

    $position = 1;

    foreach ($group as &$item) {

        $item['rank'] = $position++;

    }
}

unset($group, $item);


$studentRanks = [];

foreach ($rankGroups as $group) {

    foreach ($group as $item) {

        $studentRanks[$item['studentID']]
            = $item['rank'];
    }
}


foreach ($students as &$s) {

    $s['rank'] =
        $studentRanks[$s['studentID']] ?? null;
}

unset($s);


/*
|--------------------------------------------------------------------------
| SECTIONS
|--------------------------------------------------------------------------
*/

$sections = $db->fetchAll(
    "
    SELECT
        sec.sectionID,
        sec.sectionName,
        p.programCode,
        ay.academicYear
    FROM sections sec
    JOIN programs p ON p.programID = sec.programID
    JOIN acad_years ay ON ay.acadYearID = sec.acadYearID
    WHERE sec.dateDeleted IS NULL
      AND ay.status = 'Active'
      AND ay.dateDeleted IS NULL
    ORDER BY sec.sectionName ASC, ay.academicYear DESC
    "
);


/*
|--------------------------------------------------------------------------
| DUPLICATE STUDENT NUMBERS
|--------------------------------------------------------------------------
*/

$duplicateStudentNos = [];

$seenStudentNos = [];

foreach ($students as $s) {

    $studentNo = $s['studentNo'];

    if (isset($seenStudentNos[$studentNo])) {

        $duplicateStudentNos[$studentNo] = true;

    } else {

        $seenStudentNos[$studentNo] = true;
    }
}


$duplicateWarning = '';

if ($duplicateStudentNos) {

    $duplicateWarning =
        'Warning: duplicate student number(s) found in the result: '
        . implode(', ', array_keys($duplicateStudentNos))
        . '. Please check the data.';
}


/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

$csrf = generateCSRF();


/*
|--------------------------------------------------------------------------
| HELPERS FOR TAB/PILL LINKS
|--------------------------------------------------------------------------
*/

function buildStudentsUrl(array $overrides = []): string
{
    $q = array_merge($_GET, $overrides);

    unset($q['page']);

    return 'students.php?' . http_build_query($q);
}


/*
|--------------------------------------------------------------------------
| PAGE
|--------------------------------------------------------------------------
*/

$pageTitle  = 'Student Records';
$activeMenu = 'students.php';

require_once ROOT . '/includes/header.php';

?>


<!-- =========================================================
     ALERTS
========================================================= -->

<?php if ($msg): ?>

<div class="alert alert-success">

    <i class="fas fa-check-circle"></i>

    <?= htmlspecialchars($msg) ?>

</div>

<?php endif; ?>


<?php if ($err): ?>

<div class="alert alert-danger">

    <i class="fas fa-times-circle"></i>

    <?= htmlspecialchars($err) ?>

</div>

<?php endif; ?>


<?php if ($duplicateWarning): ?>

<div class="alert alert-warning">

    <i class="fas fa-exclamation-triangle"></i>

    <?= htmlspecialchars($duplicateWarning) ?>

</div>

<?php endif; ?>


<!-- =========================================================
     RESULT COUNT
========================================================= -->

<div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:18px;">

    <span style="font-size:15px;">
        <strong><?= number_format($total) ?></strong>
        student<?= $total === 1 ? '' : 's' ?> found
    </span>

    <button type="button" class="btn btn-primary" onclick="openStudentImportModal()">
        <i class="fas fa-file-import"></i>
        Import Students
    </button>

</div>

<div style="display:flex; gap:8px; margin-bottom:18px; flex-wrap:wrap;">

    <a
        href="<?= htmlspecialchars(buildStudentsUrl(['view' => ''])) ?>"
        class="btn <?= !$showArchived ? 'btn-primary' : 'btn-outline' ?>"
    >
        <i class="fas fa-users"></i>
        Active Students
    </a>

    <a
        href="<?= htmlspecialchars(buildStudentsUrl(['view' => 'archived'])) ?>"
        class="btn <?= $showArchived ? 'btn-primary' : 'btn-outline' ?>"
    >
        <i class="fas fa-box-archive"></i>
        Archived Students
        <span style="margin-left:4px;">(<?= number_format($archivedCount) ?>)</span>
    </a>

</div>

<?php if (!$showArchived): ?>
<form method="POST" style="margin-bottom:18px;" onsubmit="return confirm('Advance every eligible passing student and enroll them in the next configured semester or academic year?');">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
    <input type="hidden" name="action" value="advance_all_passing">
    <input type="hidden" name="view" value="">
    <button type="submit" class="btn btn-success">
        <i class="fas fa-forward"></i>
        Advance All Passing Students
    </button>
    <span style="font-size:12px;color:var(--text-muted);margin-left:8px;">
        Posts grades required; next-period subjects are added automatically.
    </span>
</form>
<?php endif; ?>


<!-- =========================================================
     PROGRAM TABS
========================================================= -->

<div class="program-tabs" role="tablist">

    <a
        href="<?= htmlspecialchars(buildStudentsUrl(['program_id' => ''])) ?>"
        class="program-tab <?= $progID === 0 ? 'active' : '' ?>"
    >
        <i class="fas fa-graduation-cap"></i>
        All Courses
        <span class="program-tab-count"><?= number_format($allProgramsCount) ?></span>
    </a>

    <?php foreach ($programs as $p): ?>

    <a
        href="<?= htmlspecialchars(buildStudentsUrl(['program_id' => $p['programID']])) ?>"
        class="program-tab <?= $progID === (int)$p['programID'] ? 'active' : '' ?>"
    >
        <i class="fas fa-graduation-cap"></i>
        <?= htmlspecialchars($p['programCode']) ?>
        <span class="program-tab-count"><?= number_format($programCounts[$p['programID']] ?? 0) ?></span>
    </a>

    <?php endforeach; ?>

</div>


<!-- =========================================================
     YEAR LEVEL PILLS
========================================================= -->

<div class="year-pills">

    <a
        href="<?= htmlspecialchars(buildStudentsUrl(['year_level' => ''])) ?>"
        class="year-pill <?= $yearLvl === 0 ? 'active' : '' ?>"
    >
        <i class="fas fa-layer-group"></i>
        All Years
    </a>

    <?php
    $yearLabels = [1 => '1st Year', 2 => '2nd Year', 3 => '3rd Year', 4 => '4th Year', 5 => 'Irregular'];
    ?>

    <?php foreach ($yearLabels as $yl => $label): ?>

    <a
        href="<?= htmlspecialchars(buildStudentsUrl(['year_level' => $yl])) ?>"
        class="year-pill <?= $yearLvl === $yl ? 'active' : '' ?>"
    >
        <i class="fas fa-layer-group"></i>
        <?= $label ?>
        <span class="year-pill-count"><?= number_format($yearCounts[$yl] ?? 0) ?></span>
    </a>

    <?php endforeach; ?>

</div>


<!-- =========================================================
     FILTER (search / section / status / type)
========================================================= -->

<div class="card" style="margin-bottom:16px; overflow:visible;">

    <div class="card-body" style="padding:18px; overflow:visible;">

        <form method="GET" action="students.php" id="studentFilterForm" style="width:100%;">

            <!-- preserve current program/year selection -->
            <input type="hidden" name="program_id" value="<?= (int)$progID ?>">
            <input type="hidden" name="year_level" value="<?= (int)$yearLvl ?>">
            <input type="hidden" name="view" value="<?= $showArchived ? 'archived' : '' ?>">

            <div
                style="
                    display:grid;
                    grid-template-columns:
                        minmax(220px, 1.5fr)
                        repeat(3, minmax(160px, 1fr));
                    gap:12px;
                    align-items:end;
                    width:100%;
                "
                class="student-filter-grid"
            >

                <!-- SEARCH -->

                <div>

                    <label class="form-label" for="studentSearch">
                        Search Student
                    </label>

                    <div style="position:relative; width:100%;">

                        <i
                            class="fas fa-search"
                            style="
                                position:absolute;
                                left:13px;
                                top:50%;
                                transform:translateY(-50%);
                                color:var(--text-muted);
                                font-size:13px;
                                pointer-events:none;
                            "
                        ></i>

                        <input
                            type="text"
                            id="studentSearch"
                            name="search"
                            class="form-control"
                            style="width:100%; padding-left:36px; box-sizing:border-box;"
                            placeholder="Name, student no., email..."
                            value="<?= htmlspecialchars($search) ?>"
                        >

                    </div>

                </div>


                <!-- SECTION -->

                <div>

                    <label class="form-label" for="sectionFilter">
                        Section
                    </label>

                    <select id="sectionFilter" name="section_id" class="form-control" style="width:100%;">

                        <option value="">All Sections</option>

                        <?php foreach ($sections as $sec): ?>

                        <option
                            value="<?= (int)$sec['sectionID'] ?>"
                            <?= $sectionID == $sec['sectionID'] ? 'selected' : '' ?>
                        >
                            <?= htmlspecialchars(studentSectionLabel($sec['sectionName'], $sec['programCode'] ?? '') . ' (' . $sec['academicYear'] . ')') ?>
                        </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <!-- STATUS -->

                <div>

                    <label class="form-label" for="statusFilter">
                        Status
                    </label>

                    <select id="statusFilter" name="enrolled" class="form-control" style="width:100%;">

                        <option value="">All</option>

                        <option value="1" <?= $enrolled === '1' ? 'selected' : '' ?>>
                            Enrolled
                        </option>

                        <option value="0" <?= $enrolled === '0' ? 'selected' : '' ?>>
                            Not Enrolled
                        </option>

                    </select>

                </div>


                <!-- TYPE -->

                <div>

                    <label class="form-label" for="typeFilter">
                        Student Type
                    </label>

                    <select id="typeFilter" name="student_type" class="form-control" style="width:100%;">

                        <option value="">All</option>

                        <option value="regular" <?= $studentType === 'regular' ? 'selected' : '' ?>>
                            Regular
                        </option>

                        <option value="irregular" <?= $studentType === 'irregular' ? 'selected' : '' ?>>
                            Irregular
                        </option>

                    </select>

                </div>

            </div>


            <!-- BUTTONS -->

            <div style="display:flex; justify-content:flex-end; align-items:center; gap:8px; margin-top:14px;">

                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-filter"></i>
                    Filter
                </button>

                <a
                    href="<?= htmlspecialchars(buildStudentsUrl([
                        'search'       => '',
                        'section_id'   => '',
                        'enrolled'     => '',
                        'student_type' => '',
                    ])) ?>"
                    class="btn btn-outline"
                >
                    <i class="fas fa-times"></i>
                    Reset
                </a>

            </div>

        </form>

    </div>

</div>


<style>

/* ---------------------------------------------------------
   PROGRAM TABS
--------------------------------------------------------- */

.program-tabs {
    display:flex;
    align-items:center;
    gap:22px;
    border-bottom:1px solid var(--border);
    margin-bottom:16px;
    flex-wrap:wrap;
}

.program-tab {
    display:flex;
    align-items:center;
    gap:8px;
    padding:10px 2px 12px;
    font-size:14px;
    font-weight:700;
    color:var(--text-muted);
    text-decoration:none;
    border-bottom:2px solid transparent;
    white-space:nowrap;
}

.program-tab:hover {
    color:var(--blue);
}

.program-tab.active {
    color:var(--blue);
    border-bottom-color:var(--blue);
}

.program-tab-count {
    background:var(--bg-muted, #eef2f7);
    color:var(--text-muted);
    font-size:12px;
    font-weight:700;
    padding:2px 8px;
    border-radius:999px;
}

.program-tab.active .program-tab-count {
    background:var(--blue);
    color:#fff;
}

/* ---------------------------------------------------------
   YEAR LEVEL PILLS
--------------------------------------------------------- */

.year-pills {
    display:flex;
    align-items:center;
    gap:10px;
    flex-wrap:wrap;
    margin-bottom:18px;
}

.year-pill {
    display:flex;
    align-items:center;
    gap:8px;
    padding:10px 16px;
    border-radius:999px;
    font-size:13px;
    font-weight:700;
    text-decoration:none;
    border:1px solid var(--border);
    background:#fff;
    color:var(--text);
}

.year-pill:hover {
    border-color:var(--blue);
}

.year-pill.active {
    background:var(--blue);
    border-color:var(--blue);
    color:#fff;
}

.year-pill-count {
    background:rgba(0,0,0,0.08);
    color:inherit;
    font-size:12px;
    font-weight:700;
    padding:1px 8px;
    border-radius:999px;
}

.year-pill.active .year-pill-count {
    background:rgba(255,255,255,0.25);
}

/* ---------------------------------------------------------
   FILTER GRID
--------------------------------------------------------- */

.student-filter-grid {
    width:100%;
}

.student-filter-grid .form-label {
    display:block;
    margin-bottom:6px;
    font-size:12px;
    font-weight:700;
}

.student-filter-grid .form-control {
    min-height:44px;
}

@media (max-width:1100px) {
    .student-filter-grid {
        grid-template-columns: repeat(2, minmax(180px, 1fr)) !important;
    }
}

@media (max-width:600px) {
    .student-filter-grid {
        grid-template-columns: 1fr !important;
    }
    .student-filter-grid > div {
        width:100%;
    }
}

/* ---------------------------------------------------------
   ACTIONS DROPDOWN
--------------------------------------------------------- */

.actions-dropdown {
    position:relative;
    display:inline-block;
}

.actions-dropdown-toggle {
    width:34px;
    height:34px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border-radius:8px;
    border:1px solid var(--border);
    background:#fff;
    cursor:pointer;
    color:var(--text);
}

.actions-dropdown-toggle:hover {
    border-color:var(--blue);
    color:var(--blue);
}

.actions-dropdown-menu {
    display:none;
    position:absolute;
    right:0;
    top:calc(100% + 4px);
    min-width:190px;
    background:#fff;
    border:1px solid var(--border);
    border-radius:10px;
    box-shadow:0 8px 24px rgba(0,0,0,0.12);
    z-index:50;
    overflow:hidden;
    text-align:left;
}

.actions-dropdown.open .actions-dropdown-menu {
    display:block;
}

.actions-dropdown-item {
    display:flex;
    align-items:center;
    gap:10px;
    width:100%;
    padding:10px 14px;
    font-size:13px;
    font-weight:600;
    color:var(--text);
    background:none;
    border:none;
    text-align:left;
    cursor:pointer;
    text-decoration:none;
}

.actions-dropdown-item:hover {
    background:var(--bg-muted, #f3f5f8);
}

.actions-dropdown-item.danger {
    color:#ef4444;
}

.actions-dropdown-item.success {
    color:#10b981;
}

.actions-dropdown-item i {
    width:16px;
    text-align:center;
}

.actions-dropdown-divider {
    height:1px;
    background:var(--border);
    margin:4px 0;
}

</style>


<!-- =========================================================
     STUDENT TABLE
========================================================= -->

<div class="card">

    <div class="card-header">

        <div class="card-title">
            <i class="fas fa-users"></i>
            <?= $showArchived ? 'Archived Students' : 'Student Records' ?>
        </div>

        <span style="font-size:13px; color:var(--text-muted);">
            Showing <?= count($students) ?> of <?= number_format($total) ?> students
        </span>

    </div>


    <div style="overflow-x:auto;">

        <table class="data-table">

            <thead>

                <tr>
                    <th>Student</th>
                    <th>Course</th>
                    <th style="text-align:center;">Year</th>
                    <th style="text-align:center;">Section</th>
                    <th style="text-align:center;">Rank</th>
                    <th style="text-align:center;">Type</th>
                    <th style="text-align:center;">Status</th>
                    <th style="text-align:center;">GWA</th>
                    <th style="text-align:center;">Grades</th>
                    <th style="text-align:center;">Enrolled</th>
                    <th style="text-align:center;">Actions</th>
                </tr>

            </thead>


            <tbody>

            <?php if (empty($students)): ?>

                <tr>

                    <td colspan="11" style="text-align:center; padding:40px; color:var(--text-muted);">

                        <i class="fas fa-search" style="font-size:24px; margin-bottom:10px; display:block;"></i>

                        No students found matching your filters.

                    </td>

                </tr>

            <?php else: ?>

                <?php

                $studentsByYear = [];

                foreach ($students as $s) {

                    $year = (int)$s['yearLevel'];

                    if (!isset($studentsByYear[$year])) {
                        $studentsByYear[$year] = [];
                    }

                    $studentsByYear[$year][] = $s;
                }

                ksort($studentsByYear);

                ?>


                <?php foreach ($studentsByYear as $yearLevel => $yearStudents): ?>

                <tr style="background-color:rgba(59,130,246,0.08); font-weight:700; height:40px;">

                    <td colspan="11" style="padding:10px 16px; border-top:2px solid var(--blue); color:var(--blue);">
                        <i class="fas fa-layer-group"></i>
                        <?= $yearLevel === 5 ? 'Irregular Students' : 'Year ' . $yearLevel . ' Students' ?>
                    </td>

                </tr>


                <?php foreach ($yearStudents as $s):

                    $dropdownId = 'actionsDropdown_' . (int)$s['studentID'];
                    $isArchived = !empty($s['dateDeleted']);

                ?>

                <tr>

                    <!-- STUDENT -->

                    <td>

                        <div style="display:flex; align-items:center; gap:10px;">

                            <div
                                style="
                                    width:36px;
                                    height:36px;
                                    border-radius:50%;
                                    background:linear-gradient(135deg, var(--blue), var(--blue-mid));
                                    color:#fff;
                                    display:flex;
                                    align-items:center;
                                    justify-content:center;
                                    font-weight:700;
                                    font-size:13px;
                                    flex-shrink:0;
                                "
                            >
                                <?= strtoupper(substr($s['fName'], 0, 1)) ?>
                            </div>

                            <div>

                                <div style="font-weight:700; font-size:13.5px;">
                                    <?= htmlspecialchars(
                                        "{$s['lName']}, {$s['fName']}"
                                        . ($s['mName'] ? ' ' . substr($s['mName'], 0, 1) . '.' : '')
                                    ) ?>
                                </div>

                                <div style="font-size:11.5px; color:var(--blue); font-weight:600;">
                                    <?= htmlspecialchars($s['studentNo']) ?>
                                </div>

                            </div>

                        </div>

                    </td>


                    <!-- PROGRAM -->

                    <td style="font-weight:700; color:var(--blue);">
                        <?= htmlspecialchars($s['programCode']) ?>
                    </td>


                    <!-- YEAR -->

                    <td style="text-align:center; font-weight:700;">
                        Year <?= (int)$s['yearLevel'] ?>
                    </td>


                    <!-- SECTION -->

                    <td style="text-align:center; font-size:13px;">

                        <?php if (!empty($s['sectionName'])): ?>

                            <?= htmlspecialchars(studentSectionLabel($s['sectionName'], $s['programCode'])) ?>

                        <?php else: ?>

                            <span style="color:var(--text-muted); font-size:12px;">
                                No Section
                            </span>

                        <?php endif; ?>

                    </td>


                    <!-- RANK -->

                    <td style="text-align:center; font-weight:700;">

                        <?php if (!empty($s['gwa']) && $s['rank']): ?>

                            <span
                                class="<?= getRankingBadgeClass($s['rank']) ?>"
                                style="min-width:60px; display:inline-flex; justify-content:center; align-items:center; padding:4px 8px; gap:4px;"
                            >
                                <i class="fas fa-award" style="font-size:11px;"></i>
                                <?= htmlspecialchars(getPositionLabel($s['rank'])) ?>
                            </span>

                        <?php else: ?>

                            <span style="color:var(--text-muted);">—</span>

                        <?php endif; ?>

                    </td>


                    <!-- TYPE -->

                    <td style="text-align:center; font-weight:700;">

                        <?php if ($s['isDropped']): ?>

                            <span style="color:var(--text-muted);">—</span>

                        <?php elseif ($s['isGraduated']): ?>

                            <span style="color:var(--text-muted);">—</span>

                        <?php else: ?>

                            <span class="status-badge <?= ((int)$s['yearLevel'] === 5 || !$s['isEnroll']) ? 'status-flagged' : 'status-approved' ?>">
                                <?= ((int)$s['yearLevel'] === 5) ? 'Irregular' : ($s['isEnroll'] ? 'Regular' : 'Irregular') ?>
                            </span>

                        <?php endif; ?>

                    </td>


                    <!-- STATUS -->

                    <td style="text-align:center;">

                        <?php

                        $isIrreg  = isStudentIrregular($s['studentID']);
                        $hasBack  = hasBackSubjects($s['studentID']);
                        $badges   = '';

                        if (!empty($s['dateDeleted'])) {

                            $badges .= '<span class="status-badge" style="background:#6b7280; color:#fff; font-size:11px; font-weight:600;">ARCHIVED</span>';

                        } elseif ((int)$s['isDropped'] === 1) {

                            $badges .= '<span class="status-badge" style="background:#ef4444; color:#fff; font-size:11px; font-weight:600;">DROPPED</span>';

                        } elseif ((int)$s['isGraduated'] === 1) {

                            $badges .= '<span class="status-badge" style="background:#10b981; color:#fff; font-size:11px; font-weight:600;">GRADUATED</span>';

                        } else {

                            if ($isIrreg) {
                                $badges .= '<span class="status-badge" style="background:#FF9800; color:#fff; margin-right:4px; font-size:11px; font-weight:600;">IRREGULAR</span>';
                            }

                            if ($hasBack) {
                                $badges .= '<span class="status-badge status-flagged" style="font-size:11px; font-weight:600;">HAS BACK</span>';
                            }
                        }

                        echo $badges ?: '<span style="color:var(--text-muted); font-size:12px;">—</span>';

                        ?>

                    </td>


                    <!-- GWA -->

                    <td
                        style="
                            text-align:center;
                            font-size:17px;
                            font-weight:700;
                            color:<?= $s['gwa'] && $s['gwa'] <= 1.75 ? 'var(--success)' : 'var(--blue)' ?>;
                        "
                    >
                        <?= $s['gwa'] ? number_format((float)$s['gwa'], 4) : '—' ?>
                    </td>


                    <!-- GRADES -->

                    <td style="text-align:center; font-size:12.5px;">

                        <span class="status-badge status-approved">
                            <?= $s['approvedGrades'] ?> ✓
                        </span>

                        <?php if ($s['pendingGrades'] > 0): ?>

                            <span class="status-badge status-submitted" style="margin-left:4px;">
                                <?= $s['pendingGrades'] ?> ⏳
                            </span>

                        <?php endif; ?>

                    </td>


                    <!-- ENROLLED -->

                    <td style="text-align:center;">

                        <?php if ($s['isDropped']): ?>

                            <span class="status-badge" style="background:#ef4444; color:#fff;">Dropped</span>

                        <?php elseif ($s['dateDeleted']): ?>

                            <span class="status-badge" style="background:#6b7280; color:#fff;">Archived</span>

                        <?php else: ?>

                            <span class="status-badge <?= $s['isEnroll'] ? 'status-approved' : 'status-pending' ?>">
                                <?= $s['isEnroll'] ? 'Enrolled' : 'Not Enrolled' ?>
                            </span>

                        <?php endif; ?>

                    </td>


                    <!-- ACTIONS DROPDOWN -->

                    <td style="text-align:center;">

                        <div class="actions-dropdown" id="<?= $dropdownId ?>">

                            <button
                                type="button"
                                class="actions-dropdown-toggle"
                                onclick="toggleActionsDropdown('<?= $dropdownId ?>')"
                                title="Actions"
                            >
                                <i class="fas fa-ellipsis-vertical"></i>
                            </button>

                            <div class="actions-dropdown-menu">

                                <!-- VIEW -->

                                <a
                                    href="../registrar/student_profile.php?id=<?= (int)$s['studentID'] ?>"
                                    class="actions-dropdown-item"
                                >
                                    <i class="fas fa-eye"></i>
                                    View Profile
                                </a>

                                <!-- EDIT -->

                                <?php if (!$isArchived): ?>
                                <a
                                    href="../registrar/student_profile.php?id=<?= (int)$s['studentID'] ?>&edit=1"
                                    class="actions-dropdown-item"
                                >
                                    <i class="fas fa-pen"></i>
                                    Edit Student
                                </a>
                                <?php endif; ?>

                                <div class="actions-dropdown-divider"></div>

                                <!-- REACTIVATE -->

                                <?php if ($s['isDropped'] && !$s['isGraduated'] && !$isArchived): ?>

                                <form
                                    method="POST"
                                    onsubmit="return confirm('Reactivate this dropped student? The student will become active and can be enrolled again.');"
                                >
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                    <input type="hidden" name="action" value="reactivate_student">
                                    <input type="hidden" name="studentID" value="<?= (int)$s['studentID'] ?>">

                                    <button type="submit" class="actions-dropdown-item success">
                                        <i class="fas fa-user-check"></i>
                                        Reactivate Student
                                    </button>
                                </form>

                                <?php endif; ?>

                                <?php if ($isArchived): ?>

                                <form
                                    method="POST"
                                    onsubmit="return confirm('Restore this archived student? The student will become visible in active student lists and in Admin.');"
                                >
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                    <input type="hidden" name="action" value="restore_student">
                                    <input type="hidden" name="studentID" value="<?= (int)$s['studentID'] ?>">

                                    <button type="submit" class="actions-dropdown-item success">
                                        <i class="fas fa-box-open"></i>
                                        Restore Student
                                    </button>
                                </form>

                                <?php endif; ?>

                                <!-- ENROLL / UNENROLL -->

                                <?php if (!$isArchived && !$s['isGraduated'] && !$s['isDropped'] && $s['isActive']): ?>

                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                    <input type="hidden" name="action" value="toggle_enroll">
                                    <input type="hidden" name="studentID" value="<?= (int)$s['studentID'] ?>">
                                    <input type="hidden" name="isEnroll" value="<?= $s['isEnroll'] ? 0 : 1 ?>">

                                    <button type="submit" class="actions-dropdown-item">
                                        <i class="fas <?= $s['isEnroll'] ? 'fa-user-minus' : 'fa-user-plus' ?>"></i>
                                        <?= $s['isEnroll'] ? 'Unenroll Student' : 'Enroll Student' ?>
                                    </button>
                                </form>

                                <?php endif; ?>

                                <!-- ADVANCE AND ENROLL -->

                                <?php if (!$isArchived && !$s['isGraduated'] && !$s['isDropped'] && $s['isActive']): ?>

                                <form
                                    method="POST"
                                    onsubmit="return confirm('Advance this student after checking all passing grades and enroll the next configured subjects?');"
                                >
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                    <input type="hidden" name="action" value="advance_student">
                                    <input type="hidden" name="studentID" value="<?= (int)$s['studentID'] ?>">

                                    <button type="submit" class="actions-dropdown-item">
                                        <i class="fas fa-forward"></i>
                                        Advance and Enroll Next Period
                                    </button>
                                </form>

                                <?php endif; ?>

                                <!-- DROP -->

                                <?php if (!$isArchived && !$s['isDropped'] && !$s['isGraduated'] && $s['isActive']): ?>

                                <form
                                    method="POST"
                                    onsubmit="return confirm('Mark this student as dropped? The student can be reactivated later.');"
                                >
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                    <input type="hidden" name="action" value="drop_student">
                                    <input type="hidden" name="studentID" value="<?= (int)$s['studentID'] ?>">

                                    <button type="submit" class="actions-dropdown-item danger">
                                        <i class="fas fa-user-slash"></i>
                                        Drop Student
                                    </button>
                                </form>

                                <?php endif; ?>

                                <!-- ARCHIVE -->

                                <?php if (!$isArchived && !$s['isGraduated']): ?>

                                <div class="actions-dropdown-divider"></div>

                                <form
                                    method="POST"
                                    onsubmit="return confirm('Archive this student? The student will be removed from the students list.');"
                                >
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                                    <input type="hidden" name="action" value="archive_student">
                                    <input type="hidden" name="studentID" value="<?= (int)$s['studentID'] ?>">

                                    <button type="submit" class="actions-dropdown-item danger">
                                        <i class="fas fa-box-archive"></i>
                                        Archive Student
                                    </button>
                                </form>

                                <?php endif; ?>

                            </div>

                        </div>

                    </td>

                </tr>

                <?php endforeach; ?>

                <?php endforeach; ?>

            <?php endif; ?>

            </tbody>

        </table>

    </div>


    <!-- =====================================================
         PAGINATION
    ====================================================== -->

    <?php if ($pages > 1): ?>

    <div
        style="
            padding:14px 20px;
            border-top:1px solid var(--border);
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:10px;
            flex-wrap:wrap;
        "
    >

        <div style="font-size:13px; color:var(--text-muted);">
            Page <?= $page ?> of <?= $pages ?>
        </div>

        <div style="display:flex; gap:6px; flex-wrap:wrap;">

            <?php if ($page > 1): ?>

            <a
                href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>"
                class="btn btn-outline btn-sm"
            >
                <i class="fas fa-chevron-left"></i>
            </a>

            <?php endif; ?>

            <?php for ($p = max(1, $page - 2); $p <= min($pages, $page + 2); $p++): ?>

            <a
                href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>"
                class="btn btn-sm <?= $p == $page ? 'btn-primary' : 'btn-outline' ?>"
            >
                <?= $p ?>
            </a>

            <?php endfor; ?>

            <?php if ($page < $pages): ?>

            <a
                href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>"
                class="btn btn-outline btn-sm"
            >
                <i class="fas fa-chevron-right"></i>
            </a>

            <?php endif; ?>

        </div>

    </div>

    <?php endif; ?>

</div>


<script>

function openRegistrarManual() { document.getElementById('registrarManualModal').style.display = 'flex'; }
function closeRegistrarManual() { document.getElementById('registrarManualModal').style.display = 'none'; }
function submitRegistrarManual(event) {
    event.preventDefault();
    const data = Object.fromEntries(new FormData(event.target).entries());
    data.action = 'add_student';
    fetch('<?= BASE_URL ?>api/students.php', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(data)})
        .then(response => response.json()).then(result => { showToast(result.message, result.success ? 'success' : 'error'); if (result.success) { closeRegistrarManual(); setTimeout(() => location.reload(), 900); } })
        .catch(() => showToast('Unable to save the student.', 'error'));
}
function toggleActionsDropdown(id) {

    const current = document.getElementById(id);

    document.querySelectorAll('.actions-dropdown.open').forEach(function (el) {

        if (el.id !== id) {
            el.classList.remove('open');
        }

    });

    current.classList.toggle('open');

}

document.addEventListener('click', function (e) {

    if (!e.target.closest('.actions-dropdown')) {

        document.querySelectorAll('.actions-dropdown.open').forEach(function (el) {
            el.classList.remove('open');
        });

    }

});

</script>

<?php require ROOT . '/includes/student_import_modal.php'; ?>

<?php

require_once ROOT . '/includes/footer.php';

?>