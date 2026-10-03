<?php
/**
 * ISARMS - Student Self-Enrollment in Subjects
 * Accessible by: Student
 * 
 * This endpoint handles:
 * - Getting available subjects for enrollment based on student profile
 * - Student self-enrollment into subjects
 * - Dropping/unenrolling from subjects
 */
define('ROOT', dirname(__DIR__));
require_once ROOT . '/includes/config.php';
require_once ROOT . '/includes/functions.php';
header('Content-Type: application/json');
startSecureSession();
if (!isLoggedIn()) jsonResponse(false, 'Unauthorized.');

$db     = Database::getInstance();
$user   = getCurrentUser();
$action = $_GET['action'] ?? '';

// Only students can access this
if ($user['role'] !== 'Student') {
    jsonResponse(false, 'Only students can self-enroll in subjects.');
}

$studentID = $user['id'];

function getOpenEnrollmentYearForStudentAPI(Database $db, int $yearID): ?array {
    return $db->fetchOne(
        "SELECT acadYearID, academicYear
         FROM acad_years
         WHERE acadYearID=:id AND status='Active' AND dateDeleted IS NULL
           AND (enrollmentStartDate IS NULL OR enrollmentStartDate<=CURDATE())
           AND (enrollmentEndDate IS NULL OR enrollmentEndDate>=CURDATE())",
        [':id'=>$yearID]
    );
}

switch ($action) {
    case 'get_years':
        /**
         * Get available academic years
         */
                $years = $db->fetchAll(
                        "SELECT acadYearID, academicYear FROM acad_years
                         WHERE status='Active' AND dateDeleted IS NULL
                             AND (enrollmentStartDate IS NULL OR enrollmentStartDate<=CURDATE())
                             AND (enrollmentEndDate IS NULL OR enrollmentEndDate>=CURDATE())
                         ORDER BY academicYear DESC"
                );
        jsonResponse(true, 'Years loaded.', ['data' => $years]);
        break;

    case 'get_sections':
        /**
         * Get available sections for a given year
         */
        $yearID = (int)($_GET['yearID'] ?? 0);
        if (!$yearID) jsonResponse(false, 'Year ID required.');

        $student = $db->fetchOne("SELECT yearLevel, programID FROM students WHERE studentID=:id AND isActive=1 AND dateDeleted IS NULL", [':id' => $studentID]);
        if (!$student) jsonResponse(false, 'Student not found.');
        if (!getOpenEnrollmentYearForStudentAPI($db, $yearID)) {
            jsonResponse(false, 'Enrollment is not open for the selected academic year.');
        }

        $sections = $db->fetchAll(
            "SELECT sectionID, sectionName FROM sections 
             WHERE acadYearID=:yid AND programID=:pid AND dateDeleted IS NULL 
             ORDER BY sectionName",
            [':yid' => $yearID, ':pid' => $student['programID']]
        );
        jsonResponse(true, 'Sections loaded.', ['data' => $sections]);
        break;

    case 'get_available_subjects':
        /**
         * Get available subjects based on:
         * - Year Level (or irregular status)
         * - Section
         * - Academic Year
         * - Semester
         * - Student type (regular/irregular)
         */
        $yearID = (int)($_GET['yearID'] ?? 0);
        $sectionID = (int)($_GET['sectionID'] ?? 0);
        $studentType = sanitize($_GET['studentType'] ?? 'regular'); // 'regular' or 'irregular'
        $semester = (int)($_GET['semester'] ?? 0);

        if (!$yearID || !$sectionID || !in_array($semester, [1, 2], true)) {
            jsonResponse(false, 'Year ID, Section ID, and Semester required.');
        }

        $student = $db->fetchOne(
            "SELECT yearLevel, programID FROM students WHERE studentID=:id AND isActive=1 AND dateDeleted IS NULL",
            [':id' => $studentID]
        );
        if (!$student) jsonResponse(false, 'Student not found.');

        if (!getOpenEnrollmentYearForStudentAPI($db, $yearID)) {
            jsonResponse(false, 'Enrollment is not open for the selected academic year.');
        }
                    requireCSRFToken();
        $section = $db->fetchOne(
            "SELECT sectionID FROM sections
             WHERE sectionID=:section AND acadYearID=:year AND programID=:program AND dateDeleted IS NULL",
            [':section'=>$sectionID, ':year'=>$yearID, ':program'=>$student['programID']]
        );
        if (!$section) jsonResponse(false, 'The selected section is not available for your program and academic year.');

        $isIrregular = (int)$student['yearLevel'] === 5;
        $studentYear = $student['yearLevel'];
        $programID = $student['programID'];

        // Get subjects for this section and semester
        // Irregular students can take subjects from any year level in their program
        // Regular students only get their year level subjects
        $subjectsQuery = "
            SELECT DISTINCT 
                ss.subjectID, 
                sub.subjectCode, 
                sub.subjectName, 
                sub.units,
                sub.yearLevel,
                ss.teacherID,
                u.fName,
                u.lName,
                CASE WHEN e.enrollmentID IS NOT NULL THEN 1 ELSE 0 END as isEnrolled
            FROM section_subjects ss
            JOIN subjects sub ON sub.subjectID = ss.subjectID
            LEFT JOIN users u ON u.userID = ss.teacherID
            LEFT JOIN enrollments e ON e.subjectID = ss.subjectID 
                AND e.studentID = :sid 
                AND e.acadYearID = :yid 
                AND e.semester = :sem 
                AND e.status = 'Active'
                AND e.dateDeleted IS NULL
            WHERE ss.sectionID = :secid 
                AND ss.semester = :filter_sem
                AND ss.acadYearID = :filter_yid
                AND ss.dateDeleted IS NULL
                AND sub.dateDeleted IS NULL
                AND (sub.programID IS NULL OR sub.programID = 0 OR sub.programID = :pid)
                 AND (sub.availableFor='all'
                     OR (sub.availableFor='regular_only' AND :regularAvailability=1)
                     OR (sub.availableFor='irregular_only' AND :irregularAvailability=1))
                AND (:isIrregular = 1 OR sub.yearLevel IS NULL OR sub.yearLevel = 0 OR sub.yearLevel = :year)
            ORDER BY sub.yearLevel, sub.subjectCode
        ";

        $subjects = $db->fetchAll($subjectsQuery, [
            ':sid' => $studentID,
            ':yid' => $yearID,
            ':sem' => $semester,
            ':secid' => $sectionID,
            ':filter_yid' => $yearID,
            ':filter_sem' => $semester,
            ':pid' => $programID,
            ':year' => $studentYear,
            ':isIrregular' => $isIrregular ? 1 : 0,
            ':regularAvailability' => $isIrregular ? 0 : 1,
            ':irregularAvailability' => $isIrregular ? 1 : 0
        ]);

        jsonResponse(true, 'Subjects loaded.', ['data' => $subjects]);
        break;
                    if ($existing) {
                        if ($existing['status'] === 'Active' && empty($existing['dateDeleted'])) {
                            jsonResponse(false, 'Already enrolled in this subject.');
                        }
                        $existingGrade = $db->fetchOne(
                            "SELECT gradeID FROM grades WHERE enrollmentID=:id AND dateDeleted IS NULL LIMIT 1",
                            [':id'=>$existing['enrollmentID']]
                        );
                        if ($existingGrade) jsonResponse(false, 'This previously graded enrollment cannot be reopened. Contact the Registrar.');
                        $db->query(
                            "UPDATE enrollments SET dateofEnroll=CURDATE(), acadYear=:ay, status='Active', dateDeleted=NULL,
                                programID=:pid, sectionID=:section
                             WHERE enrollmentID=:id",
                            [':ay'=>$ay['academicYear'], ':pid'=>$student['programID'], ':section'=>$sectionID, ':id'=>$existing['enrollmentID']]
                        );
                    } else {
                        $db->insert(
                            "INSERT INTO enrollments (dateofEnroll, acadYear, semester, studentID, subjectID, programID, sectionID, acadYearID, status)
                             VALUES (CURDATE(), :ay, :sem, :sid, :sub, :pid, :sec, :yid, 'Active')",
                            [
                                ':ay' => $ay['academicYear'],
                                ':sem' => $semester,
                                ':sid' => $studentID,
                                ':sub' => $subjectID,
                                ':pid' => $student['programID'],
                                ':sec' => $sectionID,
                                ':yid' => $yearID
                            ]
                        );
                    }
    case 'enroll':
        /**
         * Student self-enrolls into a subject
         */
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, 'POST method required.');
        }

        $subjectID = (int)($_POST['subjectID'] ?? 0);
        $sectionID = (int)($_POST['sectionID'] ?? 0);
        $yearID = (int)($_POST['yearID'] ?? 0);
        $semester = (int)($_POST['semester'] ?? 0);
        $studentType = sanitize($_POST['studentType'] ?? 'regular');

        if (!$subjectID || !$sectionID || !$yearID || !in_array($semester, [1, 2], true)) {
            jsonResponse(false, 'All fields required.');
        }

        // Verify student exists and get details
        $student = $db->fetchOne(
            "SELECT fName, lName, programID, yearLevel FROM students WHERE studentID=:id AND isActive=1 AND dateDeleted IS NULL",
            [':id' => $studentID]
        );
        if (!$student) jsonResponse(false, 'Student not found.');

        $ay = getOpenEnrollmentYearForStudentAPI($db, $yearID);
        if (!$ay) jsonResponse(false, 'Enrollment is not open for the selected academic year.');
        $section = $db->fetchOne(
            "SELECT sectionID FROM sections
             WHERE sectionID=:section AND acadYearID=:year AND programID=:program AND dateDeleted IS NULL",
            [':section'=>$sectionID, ':year'=>$yearID, ':program'=>$student['programID']]
        );
        if (!$section) jsonResponse(false, 'The selected section is not available for your program and academic year.');

        // Eligibility is derived from the student's stored profile, not client input.
        $isIrregular = (int)$student['yearLevel'] === 5;
        $subject = $db->fetchOne(
            "SELECT subjectID FROM subjects 
             WHERE subjectID=:sub AND dateDeleted IS NULL
               AND (programID IS NULL OR programID = 0 OR programID = :pid)
               AND (availableFor='all'
                    OR (availableFor='regular_only' AND :regularAvailability=1)
                    OR (availableFor='irregular_only' AND :irregularAvailability=1))
               AND (:irregular = 1 OR yearLevel IS NULL OR yearLevel = 0 OR yearLevel = :year)",
            [
                ':sub' => $subjectID,
                ':pid' => $student['programID'],
                ':year' => $student['yearLevel'],
                ':irregular' => $isIrregular ? 1 : 0,
                ':regularAvailability' => $isIrregular ? 0 : 1,
                ':irregularAvailability' => $isIrregular ? 1 : 0
            ]
        );
        if (!$subject) jsonResponse(false, 'Subject not available for your profile.');

        // Verify subject belongs to the selected section
        $sectionSubject = $db->fetchOne(
            "SELECT subjectID FROM section_subjects 
             WHERE subjectID=:sub AND sectionID=:sec AND acadYearID=:yid AND semester=:sem AND dateDeleted IS NULL",
            [':sub' => $subjectID, ':sec' => $sectionID, ':yid' => $yearID, ':sem' => $semester]
        );
        if (!$sectionSubject) jsonResponse(false, 'Subject not found in this section.');

        // Check if already enrolled
        $existing = $db->fetchOne(
            "SELECT enrollmentID FROM enrollments 
             WHERE studentID=:sid AND subjectID=:sub AND acadYearID=:yid AND semester=:sem AND dateDeleted IS NULL",
            [':sid' => $studentID, ':sub' => $subjectID, ':yid' => $yearID, ':sem' => $semester]
        );
        if ($existing) jsonResponse(false, 'Already enrolled in this subject.');

        // Get academic year details
        // Create enrollment
        $db->insert(
            "INSERT INTO enrollments (dateofEnroll, acadYear, semester, studentID, subjectID, programID, sectionID, acadYearID, status) 
             VALUES (NOW(), :ay, :sem, :sid, :sub, :pid, :sec, :yid, 'Active')",
            [
                ':ay' => $ay['academicYear'],
                ':sem' => $semester,
                ':sid' => $studentID,
                ':sub' => $subjectID,
                ':pid' => $student['programID'],
                ':sec' => $sectionID,
                ':yid' => $yearID
            ]
        );

        // Mark student as enrolled
        $db->query("UPDATE students SET isEnroll=1 WHERE studentID=:id", [':id' => $studentID]);
        $teachers = $db->fetchAll(
            "SELECT DISTINCT teacherID FROM section_subjects
             WHERE sectionID=:sec AND subjectID=:sub AND semester=:sem AND acadYearID=:ay
               AND dateDeleted IS NULL",
            [':sec'=>$sectionID, ':sub'=>$subjectID, ':sem'=>$semester, ':ay'=>$yearID]
        );
        foreach ($teachers as $teacher) {
            createNotification((int)$teacher['teacherID'], 'User', 'Student Added to Subject', "{$student['fName']} {$student['lName']} enrolled in your subject.", 'Enrollment', 'Student');
        }
        notifyUsersByRole('Admin', 'Student Enrolled in Subject',
            "{$student['fName']} {$student['lName']} enrolled in subject {$subjectID}.", 'System', 'Student');

        jsonResponse(true, 'Successfully enrolled in subject.');
        break;

    case 'drop':
        /**
         * Student drops/unenrolls from a subject
         */
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                requireCSRFToken();
            jsonResponse(false, 'POST method required.');
        }

        $enrollmentID = (int)($_POST['enrollmentID'] ?? 0);
        if (!$enrollmentID) jsonResponse(false, 'Enrollment ID required.');

        // Verify enrollment belongs to this student
        $enrollment = $db->fetchOne(
            "SELECT enrollmentID FROM enrollments WHERE enrollmentID=:id AND studentID=:sid AND dateDeleted IS NULL",
            [':id' => $enrollmentID, ':sid' => $studentID]
        );
        if (!$enrollment) jsonResponse(false, 'Enrollment not found or not yours.');

        // Drop the enrollment
        $db->query(
            "UPDATE enrollments SET status='Dropped', dateDeleted=NOW() WHERE enrollmentID=:id",
            [':id' => $enrollmentID]
        );
        $teacherRows = $db->fetchAll(
            "SELECT DISTINCT ss.teacherID
             FROM section_subjects ss
             JOIN enrollments e ON e.sectionID=ss.sectionID AND e.subjectID=ss.subjectID
                AND e.semester=ss.semester AND e.acadYearID=ss.acadYearID
             WHERE e.enrollmentID=:eid AND ss.dateDeleted IS NULL",
            [':eid'=>$enrollmentID]
        );
        foreach ($teacherRows as $teacher) {
            createNotification((int)$teacher['teacherID'], 'User', 'Student Dropped Subject', "{$student['fName']} {$student['lName']} dropped one of your subjects.", 'Enrollment', 'Student');
        }
        notifyUsersByRole('Admin', 'Student Dropped Subject',
            "{$student['fName']} {$student['lName']} dropped enrollment {$enrollmentID}.", 'System', 'Student');

        jsonResponse(true, 'Successfully dropped subject.');
        break;

    case 'get_my_enrollments':
        /**
         * Get student's current enrollments
         */
        $enrollments = $db->fetchAll(
            "SELECT e.enrollmentID, e.acadYear, e.semester,
                    sub.subjectID, sub.subjectCode, sub.subjectName, sub.units,
                    ay.academicYear, ay.acadYearID,
                    sec.sectionName,
                    CONCAT(u.fName, ' ', u.lName) as teacherName
             FROM enrollments e
             JOIN subjects sub ON sub.subjectID = e.subjectID
             JOIN acad_years ay ON ay.acadYearID = e.acadYearID
             LEFT JOIN sections sec ON sec.sectionID = e.sectionID
             LEFT JOIN section_subjects ss ON ss.subjectID = e.subjectID AND ss.sectionID = e.sectionID AND ss.acadYearID = e.acadYearID AND ss.dateDeleted IS NULL
             LEFT JOIN users u ON u.userID = ss.teacherID
             WHERE e.studentID = :sid AND e.status = 'Active' AND e.dateDeleted IS NULL
             ORDER BY ay.academicYear DESC, e.semester DESC, sub.subjectCode",
            [':sid' => $studentID]
        );

        jsonResponse(true, 'Enrollments loaded.', ['data' => $enrollments]);
        break;

    default:
        jsonResponse(false, 'Invalid action.');
}
