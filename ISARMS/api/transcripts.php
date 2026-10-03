<?php
/**
 * ISARMS - Transcript API
 * Generates and manages student academic transcripts
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
if ($action === 'mark_downloaded') {
    requireCSRFToken();
}

switch ($action) {
    case 'generate_pdf':
        /**
         * Generate a PDF transcript for a student
         */
        requireAuth('Student', 'Admin', 'Registrar');
        
        // Determine whose transcript
        if ($_SESSION['role'] === 'Student') {
            $studentID = $user['id'];
        } else {
            $studentID = (int)($_GET['studentID'] ?? 0);
            if (!$studentID) jsonResponse(false, 'Student ID required.');
        }
        
        // Get student data
        $student = $db->fetchOne(
            "SELECT * FROM students WHERE studentID = :sid AND dateDeleted IS NULL",
            [':sid' => $studentID]
        );
        
        if (!$student) {
            jsonResponse(false, 'Student not found.');
        }
        
        // Get program
        $program = $db->fetchOne(
            "SELECT * FROM programs WHERE programID = :pid",
            [':pid' => $student['programID']]
        );
        
        // Get all released grades for the student's complete transcript.
        $grades = $db->fetchAll("
            SELECT g.gradeID, g.acadYearID, g.computedGrade, g.gradeEquivalent, g.postedGrade, g.remarks,
                   s.subjectCode, s.subjectName, s.units,
                     ay.academicYear,
                   e.semester
            FROM grades g
            JOIN enrollments e ON e.enrollmentID = g.enrollmentID
            JOIN subjects s ON s.subjectID = g.subjectID
                JOIN acad_years ay ON ay.acadYearID = e.acadYearID
            WHERE g.studentID = :sid AND (g.status = 'Posted' OR g.status = 'Approved') AND g.dateDeleted IS NULL
            ORDER BY ay.academicYear DESC, e.semester DESC, s.subjectCode
        ", [':sid' => $studentID]);
        
        // Get GWA records
        $gwaRecords = $db->fetchAll("
            SELECT gwaID, acadYearID, semester, gwaValue, latinHonor, validationStatus
            FROM gwa_records
            WHERE studentID = :sid AND validationStatus = 'Validated' AND dateDeleted IS NULL
            ORDER BY acadYearID DESC, semester DESC
        ", [':sid' => $studentID]);
        
        // Log transcript generation
        $logID = $db->insert(
            "INSERT INTO transcript_logs (studentID, requestedBy, requestedByRole, format, purpose, generatedAt, ipAddress, userAgent)
             VALUES (:sid, :rid, :role, 'PDF', 'Student Request', NOW(), :ip, :ua)",
            [
                ':sid' => $studentID,
                ':rid' => $user['id'],
                ':role' => $_SESSION['role'],
                ':ip' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
                ':ua' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)
            ]
        );
        
        // Prepare response with transcript data
        $transcriptData = [
            'student' => $student,
            'program' => $program,
            'grades' => $grades,
            'gwaRecords' => $gwaRecords,
            'logID' => $logID,
            'generatedAt' => date('Y-m-d H:i:s'),
            'institution' => APP_CAMPUS,
            'appVersion' => APP_VERSION
        ];
        
        // Return transcript data (client will generate PDF using this data)
        jsonResponse(true, 'Transcript generated.', ['transcript' => $transcriptData]);
        break;

    case 'get_my_transcript':
        /**
         * Get transcript data for logged-in student
         */
        requireAuth('Student');
        $studentID = $user['id'];
        
        // Get student data
        $student = $db->fetchOne(
            "SELECT studentID, studentNo, fName, mName, lName, extName, dateofBirth, sex, email, yearLevel, programID
             FROM students WHERE studentID = :sid AND dateDeleted IS NULL",
            [':sid' => $studentID]
        );
        
        if (!$student) {
            jsonResponse(false, 'Student record not found.');
        }
        
        // Get program
        $program = $db->fetchOne(
            "SELECT programID, programCode, programName FROM programs WHERE programID = :pid",
            [':pid' => $student['programID']]
        );
        
        // Get all released grades grouped by semester
        $gradesBySemester = [];
        $grades = $db->fetchAll("
            SELECT g.gradeID, g.acadYearID, g.computedGrade, g.gradeEquivalent, g.postedGrade,
                   g.remarks, g.status,
                   s.subjectCode, s.subjectName, s.units,
                   ay.academicYear,
                   e.semester
            FROM grades g
            JOIN enrollments e ON e.enrollmentID = g.enrollmentID
            JOIN subjects s ON s.subjectID = g.subjectID
                JOIN acad_years ay ON ay.acadYearID = e.acadYearID
            WHERE g.studentID = :sid AND (g.status = 'Posted' OR g.status = 'Approved') AND g.dateDeleted IS NULL
            ORDER BY ay.academicYear ASC, e.semester ASC, s.subjectCode ASC
        ", [':sid' => $studentID]);
        
        $totalUnits = 0;
        
        foreach ($grades as $grade) {
            $key = "{$grade['academicYear']}-S{$grade['semester']}";
            if (!isset($gradesBySemester[$key])) {
                $gradesBySemester[$key] = [
                    'academicYear' => $grade['academicYear'],
                    'semester' => $grade['semester'],
                    'grades' => [],
                    'semesterUnits' => 0,
                    'semesterGradePoints' => 0,
                    'semesterGPA' => 0
                ];
            }
            $gradesBySemester[$key]['grades'][] = $grade;
            $totalUnits += $grade['units'];
            if ($grade['gradeEquivalent'] !== null) {
                $gradesBySemester[$key]['semesterUnits'] += (float)$grade['units'];
                $gradesBySemester[$key]['semesterGradePoints'] += (float)$grade['gradeEquivalent'] * (float)$grade['units'];
            }
        }
        
        // A transcript term GWA includes only released grades with equivalents.
        foreach ($gradesBySemester as &$sem) {
            $sem['semesterGPA'] = $sem['semesterUnits'] > 0
                ? round($sem['semesterGradePoints'] / $sem['semesterUnits'], 4)
                : 0;
            unset($sem['semesterGradePoints']);
        }
        unset($sem);
        
        $data = [
            'student' => $student,
            'program' => $program,
            'gradesBySemester' => array_values($gradesBySemester),
            'totalUnits' => $totalUnits,
            'institutionName' => APP_CAMPUS,
            'appName' => APP_NAME,
            'generatedDate' => date('Y-m-d H:i:s')
        ];
        
        jsonResponse(true, 'Transcript data loaded.', $data);
        break;

    case 'get_transcript_logs':
        /**
         * Get transcript generation/download logs
         * Admin/Registrar can see all; Student can see their own
         */
        requireAuth('Student', 'Registrar', 'Admin');
        
        $studentID = $_SESSION['role'] === 'Student' ? $user['id'] : (int)($_GET['studentID'] ?? $user['id']);
        
        $logs = $db->fetchAll("
            SELECT logID, studentID, requestedBy, requestedByRole, format, purpose,
                   fileSize, generatedAt, downloadedAt, ipAddress
            FROM transcript_logs
            WHERE studentID = :sid AND dateDeleted IS NULL
            ORDER BY generatedAt DESC
            LIMIT 50
        ", [':sid' => $studentID]);
        
        jsonResponse(true, 'Transcript logs loaded.', ['data' => $logs]);
        break;

    case 'mark_downloaded':
        /**
         * Mark transcript as downloaded
         */
        requireAuth('Student', 'Registrar', 'Admin');
        
        $logID = (int)($_POST['logID'] ?? 0);
        if (!$logID) jsonResponse(false, 'Log ID required.');
        
        $db->query(
            "UPDATE transcript_logs SET downloadedAt = NOW() WHERE logID = :id AND dateDeleted IS NULL",
            [':id' => $logID]
        );
        
        jsonResponse(true, 'Download recorded.');
        break;

    default:
        jsonResponse(false, 'Invalid action.');
}
?>
