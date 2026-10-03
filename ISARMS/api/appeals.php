<?php
/**
 * ISARMS - Grade Appeals API
 * Handles student appeals of grades and registrar review workflow
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
if (in_array($action, ['create_appeal', 'review_appeal'], true)) {
    requireCSRFToken();
}

switch ($action) {
    case 'get_appealable_grades':
        /**
         * Get grades that a student can appeal
         * Only shows Approved grades from past semesters
         */
        requireAuth('Student');
        $studentID = $user['id'];
        
        $appeals = $db->fetchAll("
            SELECT g.gradeID, g.enrollmentID, g.subjectID, g.acadYearID,
                   g.midterm, g.finals, g.computedGrade, g.gradeEquivalent, 
                   g.postedGrade, g.remarks, g.status, g.encodedAt, g.submittedAt, g.approvedAt,
                   s.subjectCode, s.subjectName, s.units,
                   ay.academicYear,
                   e.semester,
                   CONCAT(u.fName, ' ', u.lName) as teacherName,
                   (SELECT COUNT(*) FROM grade_appeals ga WHERE ga.gradeID = g.gradeID AND ga.dateDeleted IS NULL) as hasExistingAppeal
            FROM grades g
            JOIN enrollments e ON e.enrollmentID = g.enrollmentID
            JOIN subjects s ON s.subjectID = g.subjectID
            JOIN acad_years ay ON ay.acadYearID = g.acadYearID
            JOIN users u ON u.userID = g.teacherID
            WHERE g.studentID = :sid 
              AND g.status = 'Approved'
              AND g.dateDeleted IS NULL
              AND e.dateDeleted IS NULL
            ORDER BY ay.academicYear DESC, e.semester DESC, s.subjectCode
        ", [':sid' => $studentID]);
        
        jsonResponse(true, 'Appealable grades loaded.', ['data' => $appeals]);
        break;

    case 'create_appeal':
        /**
         * Create a new grade appeal
         */
        requireAuth('Student');
        $studentID = $user['id'];
        
        $required = ['gradeID', 'reasonCode', 'studentRemarks'];
        foreach ($required as $field) {
            if (empty($_POST[$field])) {
                jsonResponse(false, "Field '$field' is required.");
            }
        }
        
        $gradeID = (int)$_POST['gradeID'];
        $reasonCode = sanitize($_POST['reasonCode']);
        $studentRemarks = sanitize($_POST['studentRemarks']);
        
        // Verify student owns this grade
        $grade = $db->fetchOne(
            "SELECT g.gradeID, g.enrollmentID, e.studentID FROM grades g 
             JOIN enrollments e ON e.enrollmentID = g.enrollmentID 
             WHERE g.gradeID = :gid",
            [':gid' => $gradeID]
        );
        
        if (!$grade || $grade['studentID'] != $studentID) {
            jsonResponse(false, 'Grade not found or access denied.');
        }
        
        // Check if appeal already exists
        $exists = $db->fetchOne(
            "SELECT appealID FROM grade_appeals WHERE gradeID = :gid AND dateDeleted IS NULL",
            [':gid' => $gradeID]
        );
        
        if ($exists) {
            jsonResponse(false, 'An appeal for this grade already exists.');
        }
        
        // Create appeal
        $appealID = $db->insert(
            "INSERT INTO grade_appeals (gradeID, enrollmentID, studentID, reasonCode, studentRemarks, status)
             VALUES (:gid, :eid, :sid, :rc, :rem, 'Pending')",
            [
                ':gid' => $gradeID,
                ':eid' => $grade['enrollmentID'],
                ':sid' => $studentID,
                ':rc' => $reasonCode,
                ':rem' => $studentRemarks
            ]
        );
        
        // Notify registrars
        $registrars = $db->fetchAll(
            "SELECT userID, fName, lName FROM users WHERE roleName = 'Registrar' AND isActive = 1 AND dateDeleted IS NULL"
        );
        
        foreach ($registrars as $reg) {
            createNotification($reg['userID'], 'User', 'New Grade Appeal',
                "{$user['name']} submitted a grade appeal. Registrar review is pending.", 'Approval', 'Student');
        }
        $admins = $db->fetchAll(
            "SELECT userID FROM users WHERE roleName='Admin' AND isActive=1 AND dateDeleted IS NULL"
        );
        foreach ($admins as $admin) {
            createNotification($admin['userID'], 'User', 'New Grade Appeal',
                "{$user['name']} submitted a grade appeal for Registrar review.", 'Approval', 'Student');
        }
        
        jsonResponse(true, 'Grade appeal submitted successfully.', ['appealID' => $appealID]);
        break;

    case 'get_my_appeals':
        /**
         * Get appeals submitted by the logged-in student
         */
        requireAuth('Student');
        $studentID = $user['id'];
        
        $appeals = $db->fetchAll("
            SELECT ga.appealID, ga.gradeID, ga.reasonCode, ga.studentRemarks, ga.status,
                   ga.reviewedAt, ga.newGrade, ga.registrarRemarks,
                   s.subjectCode, s.subjectName,
                   g.computedGrade, g.gradeEquivalent, g.postedGrade,
                   ay.academicYear, e.semester
            FROM grade_appeals ga
            JOIN grades g ON g.gradeID = ga.gradeID
            JOIN enrollments e ON e.enrollmentID = ga.enrollmentID
            JOIN subjects s ON s.subjectID = g.subjectID
            JOIN acad_years ay ON ay.acadYearID = g.acadYearID
            WHERE ga.studentID = :sid AND ga.dateDeleted IS NULL
            ORDER BY ga.appealedAt DESC
        ", [':sid' => $studentID]);
        
        jsonResponse(true, 'Appeals loaded.', ['data' => $appeals]);
        break;

    case 'get_appeals_for_review':
        /**
         * Get pending appeals for registrar review
         */
        requireAuth('Registrar');
        
        $status = $_GET['status'] ?? 'Pending';
        $subjectID = !empty($_GET['subjectID']) ? (int)$_GET['subjectID'] : null;
        $studentID = !empty($_GET['studentID']) ? (int)$_GET['studentID'] : null;
        
        $where = ['ga.dateDeleted IS NULL'];
        $params = [];
        
        if ($status !== 'all') {
            $where[] = "ga.status = :st";
            $params[':st'] = $status;
        }
        if ($subjectID) {
            $where[] = "g.subjectID = :sid";
            $params[':sid'] = $subjectID;
        }
        if ($studentID) {
            $where[] = "ga.studentID = :stid";
            $params[':stid'] = $studentID;
        }
        
        $whereSQL = implode(' AND ', $where);
        
        $appeals = $db->fetchAll("
            SELECT ga.appealID, ga.gradeID, ga.studentID, ga.enrollmentID, 
                   ga.reasonCode, ga.studentRemarks, ga.status, ga.appealedAt,
                   g.computedGrade, g.gradeEquivalent, g.postedGrade,
                   s.subjectCode, s.subjectName, s.units,
                   st.studentNo, st.fName, st.mName, st.lName,
                   ay.academicYear, e.semester,
                   CONCAT(u.fName, ' ', u.lName) as teacherName
            FROM grade_appeals ga
            JOIN grades g ON g.gradeID = ga.gradeID
            JOIN enrollments e ON e.enrollmentID = ga.enrollmentID
            JOIN students st ON st.studentID = ga.studentID
            JOIN subjects s ON s.subjectID = g.subjectID
            JOIN acad_years ay ON ay.acadYearID = g.acadYearID
            JOIN users u ON u.userID = g.teacherID
            WHERE $whereSQL
            ORDER BY ga.appealedAt DESC
        ", $params);
        
        jsonResponse(true, 'Appeals loaded.', ['data' => $appeals]);
        break;

    case 'review_appeal':
        /**
         * Registrar reviews and approves/rejects an appeal
         */
        requireAuth('Registrar');
        
        $appealID = (int)($_POST['appealID'] ?? 0);
        $decision = sanitize($_POST['decision'] ?? ''); // 'Approved', 'Rejected'
        $registrarRemarks = sanitize($_POST['registrarRemarks'] ?? '');
        $newGrade = !empty($_POST['newGrade']) ? (float)$_POST['newGrade'] : null;
        
        if (!$appealID || !in_array($decision, ['Approved', 'Rejected'])) {
            jsonResponse(false, 'Invalid appeal ID or decision.');
        }
        
        $appeal = $db->fetchOne("SELECT * FROM grade_appeals WHERE appealID = :aid", [':aid' => $appealID]);
        if (!$appeal) {
            jsonResponse(false, 'Appeal not found.');
        }
        
        // Update appeal
        $newStatus = ($decision === 'Approved') ? 'Resolved' : 'Rejected';
        $db->query(
            "UPDATE grade_appeals SET status = :st, reviewedByID = :rid, registrarRemarks = :rem, 
             newGrade = :ng, reviewedAt = NOW() WHERE appealID = :aid",
            [
                ':st' => $newStatus,
                ':rid' => $user['id'],
                ':rem' => $registrarRemarks,
                ':ng' => $newGrade,
                ':aid' => $appealID
            ]
        );
        
        // If approved, update the grade
        if ($decision === 'Approved' && $newGrade !== null) {
            $db->query(
                "UPDATE grades SET postedGrade = :pg WHERE gradeID = :gid",
                [':pg' => $newGrade, ':gid' => $appeal['gradeID']]
            );
        }
        
        // Notify student using only columns available in the live schema.
        createNotification(
            (int)$appeal['studentID'], 'Student', 'Grade Appeal ' . $decision,
            "{$user['name']} has {$decision} your grade appeal.", 'Approval', 'Registrar'
        );
        $appealStudent = $db->fetchOne("SELECT fName, mName, lName, extName FROM students WHERE studentID=:id", [':id'=>$appeal['studentID']]);
        $appealStudentName = $appealStudent ? fullName($appealStudent) : "Student #{$appeal['studentID']}";
        notifyUsersByRole('Admin', 'Grade Appeal Decision Recorded',
            "{$user['name']} recorded a {$decision} decision for {$appealStudentName}'s grade appeal.", 'Approval', 'Registrar');
        
        jsonResponse(true, 'Appeal decision recorded.');
        break;

    default:
        jsonResponse(false, 'Invalid action.');
}
?>
