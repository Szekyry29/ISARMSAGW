<?php
define('ROOT', dirname(__DIR__));
require_once ROOT . '/includes/config.php';
require_once ROOT . '/includes/functions.php';
header('Content-Type: application/json');
startSecureSession();
if (!isLoggedIn()) jsonResponse(false, 'Unauthorized.');

$db     = Database::getInstance();
$user   = getCurrentUser();
$action = $_GET['action'] ?? '';
if ($action === 'drop') {
    requireCSRFToken();
}

switch ($action) {
    case 'get_by_subject':
        requireAuth('Admin', 'Registrar', 'Teacher');
        $subjectID = (int)($_GET['subjectID'] ?? 0);
        if (!$subjectID) jsonResponse(false, 'Subject ID required.');

        $teacherScope = '';
        $params = [':sid' => $subjectID];
        if ($user['role'] === 'Teacher') {
            $teacherScope = " AND EXISTS (
                SELECT 1 FROM section_subjects assigned
                WHERE assigned.sectionID=e.sectionID AND assigned.subjectID=e.subjectID
                  AND assigned.acadYearID=e.acadYearID AND assigned.semester=e.semester
                  AND assigned.teacherID=:teacherID AND assigned.dateDeleted IS NULL
            )";
            $params[':teacherID'] = $user['id'];
        }
        
        $enrollments = $db->fetchAll("
            SELECT e.enrollmentID, e.acadYear, e.semester, e.status, e.dateCreated,
                   s.studentID, s.studentNo, s.fName, s.mName, s.lName, s.yearLevel,
                   p.programCode, p.programName,
                   sec.sectionName
            FROM enrollments e
            JOIN students s ON s.studentID = e.studentID
            JOIN programs p ON p.programID = e.programID
            LEFT JOIN sections sec ON sec.sectionID = e.sectionID
            WHERE e.subjectID = :sid AND e.dateDeleted IS NULL
            ".$teacherScope."
            ORDER BY e.acadYear DESC, e.semester, s.lName, s.fName
        ", $params);
        
        $data = array_map(function($e) {
            return [
                'enrollmentID' => $e['enrollmentID'],
                'studentID' => $e['studentID'],
                'studentNo' => $e['studentNo'],
                'studentName' => "{$e['lName']}, {$e['fName']}" . ($e['mName'] ? ' ' . substr($e['mName'], 0, 1) . '.' : ''),
                'yearLevel' => $e['yearLevel'],
                'programCode' => $e['programCode'],
                'programName' => $e['programName'],
                'sectionName' => $e['sectionName'] ?? '—',
                'acadYear' => $e['acadYear'],
                'semester' => $e['semester'],
                'status' => $e['status'],
                'dateEnrolled' => $e['dateCreated'],
            ];
        }, $enrollments);
        
        jsonResponse(true, 'Enrollments loaded.', ['data' => $data]);
        break;

    case 'get_by_student':
        requireAuth('Admin', 'Registrar', 'Student');
        $requestedStudentID = isset($_GET['studentID']) ? (int)$_GET['studentID'] : 0;
        $studentID = $user['role'] === 'Student' ? (int)$user['id'] : $requestedStudentID;
        if ($user['role'] === 'Student' && $requestedStudentID !== 0 && $requestedStudentID !== $studentID) {
            jsonResponse(false, 'You can only view your own enrollments.');
        }
        if (!$studentID) jsonResponse(false, 'Student ID required.');
        
        $enrollments = $db->fetchAll("
            SELECT e.enrollmentID, e.acadYear, e.semester, e.status, e.dateCreated,
                   sub.subjectID, sub.subjectCode, sub.subjectName, sub.units,
                   p.programCode, sec.sectionName,
                   CONCAT(u.fName, ' ', u.lName) as teacherName
            FROM enrollments e
            JOIN subjects sub ON sub.subjectID = e.subjectID
            JOIN programs p ON p.programID = e.programID
            LEFT JOIN sections sec ON sec.sectionID = e.sectionID
            LEFT JOIN section_subjects ss ON ss.subjectID = e.subjectID AND ss.sectionID = e.sectionID AND ss.acadYearID = e.acadYearID AND ss.dateDeleted IS NULL
            LEFT JOIN users u ON u.userID = ss.teacherID
            WHERE e.studentID = :sid AND e.dateDeleted IS NULL
            ORDER BY e.acadYear DESC, e.semester, sub.subjectCode
        ", [':sid' => $studentID]);
        
        jsonResponse(true, 'Enrollments loaded.', ['data' => $enrollments]);
        break;

    case 'drop':
        requireAuth('Admin', 'Registrar');
        $enrollmentID = (int)($_POST['enrollmentID'] ?? 0);
        if (!$enrollmentID) jsonResponse(false, 'Enrollment ID required.');
        
        $db->query("UPDATE enrollments SET status='Dropped', dateDeleted=NOW() WHERE enrollmentID=:id", [':id' => $enrollmentID]);
        jsonResponse(true, 'Enrollment dropped successfully.');
        break;

    default:
        jsonResponse(false, 'Invalid action.');
}