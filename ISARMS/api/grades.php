<?php
/**
 * ISARMS - Grades API
 * Handles grade save, automatic post, admin return, and legacy review actions
 */
define('ROOT', dirname(__DIR__));
require_once ROOT . '/includes/config.php';
require_once ROOT . '/includes/functions.php';

header('Content-Type: application/json');
startSecureSession();

if (!isLoggedIn()) {
    jsonResponse(false, 'Unauthorized. Please log in.');
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';
if (in_array($action, ['save_batch', 'submit_section', 'admin_edit', 'approve', 'flag', 'return'], true)) {
    requireCSRFToken();
}
$db     = Database::getInstance();
$user   = getCurrentUser();
$postedStatus = postedGradeStatus();

function refreshStudentGradeGwa(int $studentID, int $acadYearID, int $semester): void {
    $db = Database::getInstance();
    $result = computeGWA($studentID, $acadYearID, $semester);
    $honor = classifyLatinHonor($result['gwa'], $studentID, $acadYearID, $semester);
    $existing = $db->fetchOne(
        "SELECT gwaID FROM gwa_records WHERE studentID=:sid AND acadYearID=:ay AND semester=:sem",
        [':sid'=>$studentID, ':ay'=>$acadYearID, ':sem'=>$semester]
    );

    if ($existing) {
        $db->query("UPDATE gwa_records SET gwaValue=:gwa, totalUnits=:tu, weightedSum=:ws,
                    latinHonor=:lh, validationStatus='Pending', computedAt=NOW() WHERE gwaID=:id",
            [':gwa'=>$result['gwa'], ':tu'=>$result['totalUnits'], ':ws'=>$result['weightedSum'],
             ':lh'=>$honor, ':id'=>$existing['gwaID']]);
    } else {
        $db->insert("INSERT INTO gwa_records (studentID,acadYearID,semester,totalUnits,weightedSum,gwaValue,latinHonor)
                     VALUES (:sid,:ay,:sem,:tu,:ws,:gwa,:lh)",
            [':sid'=>$studentID, ':ay'=>$acadYearID, ':sem'=>$semester, ':tu'=>$result['totalUnits'],
             ':ws'=>$result['weightedSum'], ':gwa'=>$result['gwa'], ':lh'=>$honor]);
    }

    $studentMeta = $db->fetchOne("SELECT yearLevel, isGraduated FROM students WHERE studentID=:sid", [':sid'=>$studentID]);
    $useCompleteCumulative = !empty($studentMeta)
        && ((int)($studentMeta['isGraduated'] ?? 0) === 1)
        && ((int)($studentMeta['yearLevel'] ?? 0) >= 4);
    $cumulative = $useCompleteCumulative
        ? computeGWA($studentID, null, null)
        : computeGWA($studentID, $acadYearID, null);
    $cumHonor = classifyLatinHonor($cumulative['gwa'], $studentID, $useCompleteCumulative ? null : $acadYearID);
    $existingCumulative = $db->fetchOne(
        "SELECT gwaID FROM gwa_records WHERE studentID=:sid AND acadYearID=:ay AND semester IS NULL",
        [':sid'=>$studentID, ':ay'=>$acadYearID]
    );

    if ($existingCumulative) {
        $db->query("UPDATE gwa_records SET gwaValue=:gwa, totalUnits=:tu, weightedSum=:ws,
                    latinHonor=:lh, validationStatus='Pending', computedAt=NOW() WHERE gwaID=:id",
            [':gwa'=>$cumulative['gwa'], ':tu'=>$cumulative['totalUnits'], ':ws'=>$cumulative['weightedSum'],
             ':lh'=>$cumHonor, ':id'=>$existingCumulative['gwaID']]);
    } else {
        $db->insert("INSERT INTO gwa_records (studentID,acadYearID,semester,totalUnits,weightedSum,gwaValue,latinHonor)
                     VALUES (:sid,:ay,NULL,:tu,:ws,:gwa,:lh)",
            [':sid'=>$studentID, ':ay'=>$acadYearID, ':tu'=>$cumulative['totalUnits'],
             ':ws'=>$cumulative['weightedSum'], ':gwa'=>$cumulative['gwa'], ':lh'=>$cumHonor]);
    }
}

switch ($action) {

    // ── Teacher: Save batch grades ─────────────────────────────
    case 'save_batch':
        requireAuth('Teacher');
        $grades = $body['grades'] ?? [];
        if (empty($grades)) jsonResponse(false, 'No grade data provided.');

        $saved = 0;
        $db->beginTransaction();
        try {
            foreach ($grades as $g) {
                $enrollmentID    = (int)($g['enrollmentID']    ?? 0);
                $studentID       = (int)($g['studentID']       ?? 0);
                $subjectID       = (int)($g['subjectID']       ?? 0);
                $acadYearID      = (int)($g['acadYearID']      ?? 0);
                $midtermInput    = $g['midterm'] ?? null;
                $finalsInput     = $g['finals'] ?? null;
                if (!is_numeric($midtermInput) || !is_numeric($finalsInput)) {
                    throw new InvalidArgumentException('Enter numeric midterm and final scores.');
                }
                $midterm = (float)$midtermInput;
                $finals = (float)$finalsInput;
                if ($midterm < 0 || $midterm > 100 || $finals < 0 || $finals > 100) {
                    throw new InvalidArgumentException('Grades must be between 0 and 100.');
                }
                $computedGrade = computeFinalGrade($midterm, $finals);
                $gradeEquiv = convertToGradeEquivalent($computedGrade);
                $teacherRemarks  = sanitize($g['teacherRemarks'] ?? '');

                $remarks = ($gradeEquiv <= 3.00) ? 'Passed' : 'Failed';

                if (!empty($g['gradeID'])) {
                    $old = $db->fetchOne("SELECT g.* FROM grades g WHERE g.gradeID=:id AND g.teacherID=:tid AND g.dateDeleted IS NULL", [':id' => $g['gradeID'], ':tid' => $user['id']]);
                    if (!$old) {
                        throw new Exception('Unauthorized grade record.');
                    }
                    if (in_array($old['status'], ['Posted', 'Approved'], true)) continue;

                    $enrollmentID = (int)$old['enrollmentID'];
                    $studentID    = (int)$old['studentID'];
                    $subjectID    = (int)$old['subjectID'];
                    $acadYearID   = (int)$old['acadYearID'];

                    $db->query("UPDATE grades SET
                        midterm=:mt, finals=:fn, computedGrade=:cg, gradeEquivalent=:ge,
                        remarks=:rem, teacherRemarks=:tr, encodedAt=NOW(), status='Pending',
                        submittedAt=NULL, approvedAt=NULL, approvedByID=NULL
                        WHERE gradeID=:id",
                        [':mt'=>$midterm,':fn'=>$finals,':cg'=>$computedGrade,
                         ':ge'=>$gradeEquiv,':rem'=>$remarks,':tr'=>$teacherRemarks,':id'=>$g['gradeID']]);

                    logAudit($g['gradeID'], $user['id'], 'Teacher', 'Updated',
                             ['midterm'=>$old['midterm'],'finals'=>$old['finals']],
                             ['midterm'=>$midterm,'finals'=>$finals]);
                } else {
                    $enrollment = $db->fetchOne("SELECT e.sectionID, e.subjectID, e.semester, e.acadYearID
                        FROM enrollments e
                        WHERE e.enrollmentID=:ei AND e.studentID=:sid AND e.status='Active' AND e.dateDeleted IS NULL",
                        [':ei' => $enrollmentID, ':sid' => $studentID]);

                    if (!$enrollment) {
                        throw new Exception('Invalid enrollment record.');
                    }

                    $validAssignment = $db->fetchOne("SELECT 1 FROM section_subjects
                        WHERE sectionID=:sec AND subjectID=:sub AND semester=:sem AND teacherID=:tid AND acadYearID=:ay AND dateDeleted IS NULL
                        LIMIT 1",
                        [':sec' => $enrollment['sectionID'], ':sub' => $enrollment['subjectID'], ':sem' => $enrollment['semester'], ':tid' => $user['id'], ':ay' => $enrollment['acadYearID']]);

                    if (!$validAssignment) {
                        throw new Exception('You are not assigned to this class/subject.');
                    }

                    $newID = $db->insert(
                        "INSERT INTO grades (enrollmentID,studentID,subjectID,acadYearID,midterm,finals,
                          computedGrade,gradeEquivalent,remarks,teacherID,teacherRemarks,encodedAt,status)
                         VALUES (:ei,:si,:subi,:ay,:mt,:fn,:cg,:ge,:rem,:tid,:tr,NOW(),'Pending')",
                        [':ei'=>$enrollmentID,':si'=>$studentID,':subi'=>$subjectID,
                         ':ay'=>$acadYearID,':mt'=>$midterm,':fn'=>$finals,
                         ':cg'=>$computedGrade,':ge'=>$gradeEquiv,':rem'=>$remarks,
                         ':tid'=>$user['id'],':tr'=>$teacherRemarks]
                    );
                    logAudit($newID, $user['id'], 'Teacher', 'Encoded', null,
                             ['midterm'=>$midterm,'finals'=>$finals,'grade'=>$gradeEquiv]);
                }
                $saved++;
            }
            $db->commit();
            jsonResponse(true, "Grades saved successfully ($saved records).");
        } catch (Exception $e) {
            $db->rollback();
            error_log($e->getMessage());
            jsonResponse(false, $e->getMessage() ?: 'An error occurred while saving grades.');
        }
        break;

    // ── Teacher: Post section grades ────────────────────────────
    case 'submit_section':
        requireAuth('Teacher');
        $sectionID  = (int)($body['sectionID']  ?? 0);
        $subjectID  = (int)($body['subjectID']  ?? 0);
        $semester   = (int)($body['semester']   ?? 0);
        $teacherID  = (int)($body['teacherID']  ?? 0);

        if ($teacherID !== $user['id']) jsonResponse(false, 'Unauthorized action.');

        $validAssignment = $db->fetchOne("SELECT 1 FROM section_subjects
            WHERE sectionID=:sec AND subjectID=:sub AND semester=:sem AND teacherID=:tid AND dateDeleted IS NULL LIMIT 1",
            [':sec'=>$sectionID, ':sub'=>$subjectID, ':sem'=>$semester, ':tid'=>$teacherID]);

        if (!$validAssignment) {
            jsonResponse(false, 'You are not assigned to this class/subject.');
        }

        $db->beginTransaction();
        try {
            // Post all saved grades for this class.
            $grades = $db->fetchAll("
                SELECT g.gradeID, g.studentID, g.acadYearID, g.enrollmentID FROM grades g
                JOIN enrollments e ON e.enrollmentID = g.enrollmentID
                WHERE e.sectionID=:sec AND e.subjectID=:sub AND e.semester=:sem
                  AND g.teacherID=:tid AND g.status='Pending' AND g.submittedAt IS NULL AND g.dateDeleted IS NULL
            ", [':sec'=>$sectionID,':sub'=>$subjectID,':sem'=>$semester,':tid'=>$teacherID]);

            if (empty($grades)) jsonResponse(false, 'No pending grades to submit. Save grades first.');

            $subjectInfo = $db->fetchOne("SELECT subjectCode, subjectName FROM subjects WHERE subjectID=:id", [':id'=>$subjectID]);
            $subjectLabel = $subjectInfo ? $subjectInfo['subjectCode'] . ' - ' . $subjectInfo['subjectName'] : 'subject';
            $studentsToRefresh = [];
            foreach ($grades as $g) {
                $db->query("UPDATE grades SET status=:status, postedGrade=gradeEquivalent,
                            submittedAt=NOW(), approvedAt=NULL, approvedByID=NULL
                            WHERE gradeID=:id AND status='Pending'",
                    [':status'=>$postedStatus, ':id'=>$g['gradeID']]);
                logAudit($g['gradeID'], $user['id'], 'Teacher', 'Posted',
                         ['status'=>'Pending'], ['status'=>$postedStatus]);
                $studentsToRefresh[(int)$g['studentID'] . ':' . (int)$g['acadYearID']] = [
                    'studentID'=>(int)$g['studentID'], 'acadYearID'=>(int)$g['acadYearID']
                ];
                createNotification((int)$g['studentID'], 'Student', 'Grade Posted',
                    "Your grade for {$subjectLabel} has been posted and is now part of your official academic record.",
                    'Grade', 'Teacher');
            }

            foreach ($studentsToRefresh as $studentGrade) {
                refreshStudentGradeGwa($studentGrade['studentID'], $studentGrade['acadYearID'], $semester);
            }
            $db->commit();
            jsonResponse(true, count($grades) . ' grade(s) posted successfully.');
        } catch (Exception $e) {
            $db->rollback();
            jsonResponse(false, 'Submission failed. Please try again.');
        }
        break;

    // ── Admin: edit a teacher-submitted grade ───────────────────
    case 'admin_edit':
        requireAuth('Admin');
        jsonResponse(false, 'MIS users cannot edit grade scores. Return the grade to the teacher for correction.');
        break;

    // ── Registrar: Handle legacy grades already waiting in the old queue ──
    case 'approve':
        requireAuth('Registrar');
        $gradeID = (int)($body['gradeID'] ?? 0);
        if (!$gradeID) jsonResponse(false, 'Invalid grade record.');

        $grade = $db->fetchOne("SELECT * FROM grades WHERE gradeID=:id AND dateDeleted IS NULL", [':id'=>$gradeID]);
        if (!$grade) jsonResponse(false, 'Grade record not found.');
        if ($grade['status'] !== 'Pending' || empty($grade['submittedAt'])) {
            jsonResponse(false, 'Grade is not in the legacy review queue.');
        }

        $db->beginTransaction();
        try {
            $db->query("UPDATE grades SET status=:status, postedGrade=gradeEquivalent,
                        approvedAt=NOW(), approvedByID=:uid WHERE gradeID=:id",
                [':status'=>$postedStatus, ':uid'=>$user['id'], ':id'=>$gradeID]);
            logAudit($gradeID, $user['id'], 'Registrar', 'Posted',
                ['status'=>'Pending'], ['status'=>$postedStatus]);

            $subject = $db->fetchOne("SELECT subjectCode, subjectName FROM subjects WHERE subjectID=:id", [':id'=>$grade['subjectID']]);
            $subjectLabel = $subject ? $subject['subjectCode'] . ' - ' . $subject['subjectName'] : 'a subject';
            createNotification($grade['studentID'], 'Student', 'Grade Posted',
                "Your grade for {$subjectLabel} has been posted and is now part of your official academic record.",
                'Grade', 'Registrar');

            $enrollment = $db->fetchOne("SELECT acadYearID, semester FROM enrollments WHERE enrollmentID=:id",
                [':id'=>$grade['enrollmentID']]);
            if ($enrollment) {
                refreshStudentGradeGwa((int)$grade['studentID'], (int)$enrollment['acadYearID'], (int)$enrollment['semester']);
            }

            $db->commit();
            jsonResponse(true, 'Legacy grade posted and GWA recomputed successfully.');
        } catch (Exception $e) {
            $db->rollback();
            error_log($e->getMessage());
            jsonResponse(false, 'Posting failed. Please try again.');
        }
        break;

    // ── Registrar/Admin: Flag grade (alias of return) ──────────
    case 'flag':
        requireAuth('Admin', 'Registrar');
        $gradeID = (int)($body['gradeID'] ?? 0);
        $reason  = sanitize($body['reason'] ?? '');

        if (!$gradeID || !$reason) jsonResponse(false, 'Grade ID and reason are required.');

        $grade = $db->fetchOne("SELECT * FROM grades WHERE gradeID=:id", [':id' => $gradeID]);
        if (!$grade) jsonResponse(false, 'Grade not found.');

        $db->query("UPDATE grades SET status='Returned', submittedAt=NULL, registrarNotes=:r WHERE gradeID=:id",
                   [':r' => $reason, ':id' => $gradeID]);

        logAudit($gradeID, $user['id'], 'Admin', 'Returned', ['status'=>$grade['status']], ['status'=>'Returned'], $reason);

        $student = $db->fetchOne("SELECT CONCAT(fName, ' ', lName) AS name FROM students WHERE studentID=:id", [':id' => $grade['studentID']]);
        $subject = $db->fetchOne("SELECT subjectCode, subjectName FROM subjects WHERE subjectID=:id", [':id' => $grade['subjectID']]);
        $subjectLabel = $subject ? $subject['subjectCode'] . ' - ' . $subject['subjectName'] : 'subject';
        createNotification($grade['teacherID'], 'User',
            'Grade Returned for Review',
            "{$user['name']} returned {$student['name']}'s grade in {$subjectLabel} for correction: \"$reason\". Please review and re-post.",
            'Approval', 'Admin');

        jsonResponse(true, 'Grade returned and teacher notified.');
        break;

    // ── Registrar: Return grade to teacher ──────────────────────
    case 'return':
        requireAuth('Admin', 'Registrar');
        $gradeID = (int)($body['gradeID'] ?? 0);
        $notes   = sanitize($body['notes'] ?? '');
        if (!$gradeID || $notes === '') jsonResponse(false, 'Grade ID and return reason are required.');

        $grade = $db->fetchOne("SELECT teacherID, studentID, subjectID, status FROM grades WHERE gradeID=:id AND dateDeleted IS NULL", [':id'=>$gradeID]);
        if (!$grade) jsonResponse(false, 'Grade record not found.');

        $db->query("UPDATE grades SET status='Returned', submittedAt=NULL, approvedAt=NULL,
                    approvedByID=NULL, registrarNotes=:n WHERE gradeID=:id",
            [':n'=>$notes, ':id'=>$gradeID]);
        logAudit($gradeID, $user['id'], $user['role'], 'Returned',
            ['status'=>$grade['status']], ['status'=>'Returned'], $notes);

        $student = $db->fetchOne("SELECT CONCAT(fName, ' ', lName) AS name FROM students WHERE studentID=:id", [':id'=>$grade['studentID']]);
        $subject = $db->fetchOne("SELECT subjectCode, subjectName FROM subjects WHERE subjectID=:id", [':id'=>$grade['subjectID']]);
        $subjectLabel = $subject ? $subject['subjectCode'] . ' - ' . $subject['subjectName'] : 'subject';
        createNotification($grade['teacherID'], 'User', 'Grade Returned',
            "{$student['name']}'s grade in {$subjectLabel} has been returned for correction: \"$notes\"", 'Approval', $user['role']);

        jsonResponse(true, 'Grade returned to teacher for correction.');
        break;

    default:
        jsonResponse(false, 'Invalid action.');
}