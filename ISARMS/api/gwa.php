<?php
define('ROOT', dirname(__DIR__));
require_once ROOT . '/includes/config.php';
require_once ROOT . '/includes/functions.php';
header('Content-Type: application/json');
startSecureSession();
if (!isLoggedIn()) jsonResponse(false, 'Unauthorized.');

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';
$db     = Database::getInstance();
$user   = getCurrentUser();
if (in_array($action, ['validate', 'publish_deans_list', 'unpublish_deans_list'], true)) {
    requireCSRFToken();
}

switch ($action) {
    case 'validate':
        requireAuth('Registrar');
        $gwaID = (int)($body['gwaID'] ?? 0);
        if (!$gwaID) jsonResponse(false, 'Invalid GWA record.');

        $rec = $db->fetchOne("SELECT * FROM gwa_records WHERE gwaID=:id", [':id'=>$gwaID]);
        if (!$rec) jsonResponse(false, 'Record not found.');

        $db->query("UPDATE gwa_records SET validationStatus='Validated', validatedByID=:uid, validatedAt=NOW() WHERE gwaID=:id",
                   [':uid'=>$user['id'], ':id'=>$gwaID]);

        $student = $db->fetchOne("SELECT CONCAT(fName, ' ', lName) AS name FROM students WHERE studentID=:id", [':id' => $rec['studentID']]);
        $studentName = $student['name'] ?? "Student #{$rec['studentID']}";

        // Notify student
        if ($rec['latinHonor'] !== 'None') {
            createNotification($rec['studentID'], 'Student',
                'Latin Honor Confirmed',
            "Congratulations, {$studentName}! Your {$rec['latinHonor']} classification has been validated by the Registrar.",
                'Honors');
        }
        notifyUsersByRole('Admin', 'GWA Validated by Registrar',
            "Registrar validated the GWA record for {$studentName}.", 'GWA', 'Registrar');
        jsonResponse(true, 'Honor classification validated successfully.');
        break;

    case 'publish_deans_list':
    case 'unpublish_deans_list':
        requireAuth('Registrar');
        $acadYearID = (int)($body['acadYearID'] ?? 0);
        $semester = (int)($body['semester'] ?? 0);
        if ($acadYearID < 1 || !in_array($semester, [1, 2], true)) {
            jsonResponse(false, 'Select a valid academic year and semester.');
        }

        if ($action === 'publish_deans_list') {
            $eligibleCount = (int)($db->fetchOne(
                "SELECT COUNT(*) AS total FROM gwa_records
                 WHERE acadYearID=:ay AND semester=:semester
                   AND validationStatus='Validated' AND latinHonor='Dean\'s List'",
                [':ay'=>$acadYearID, ':semester'=>$semester]
            )['total'] ?? 0);
            if ($eligibleCount === 0) {
                jsonResponse(false, 'There are no validated Dean\'s List records to publish for this semester.');
            }

            $db->query(
                "INSERT INTO dean_list_publications (acadYearID, semester, publishedByID, publishedAt)
                 VALUES (:ay, :semester, :userID, NOW())
                 ON DUPLICATE KEY UPDATE publishedByID=VALUES(publishedByID), publishedAt=NOW()",
                [':ay'=>$acadYearID, ':semester'=>$semester, ':userID'=>$user['id']]
            );
            jsonResponse(true, 'Dean\'s List published for public viewing.');
        }

        $db->query(
            "DELETE FROM dean_list_publications WHERE acadYearID=:ay AND semester=:semester",
            [':ay'=>$acadYearID, ':semester'=>$semester]
        );
        jsonResponse(true, 'Dean\'s List publication removed from public viewing.');
        break;

    case 'recompute':
        requireAuth('Registrar', 'Admin');
        $studentID  = (int)($body['studentID']  ?? 0);
        $acadYearID = (int)($body['acadYearID'] ?? 0);
        $semester   = (int)($body['semester']   ?? 0);
        if (!$studentID) jsonResponse(false, 'Student ID required.');
        if (!$acadYearID || !in_array($semester, [1, 2], true)) {
            jsonResponse(false, 'Academic year and semester are required for GWA recomputation.');
        }

        $result = computeGWA($studentID, $acadYearID, $semester);
        $honor  = classifyLatinHonor($result['gwa'], $studentID, $acadYearID, $semester);

        jsonResponse(true, 'GWA recomputed.', [
            'gwa'        => $result['gwa'],
            'gwaFormatted'=> formatGWA($result['gwa']),
            'totalUnits' => $result['totalUnits'],
            'latinHonor' => $honor,
        ]);
        break;

    default:
        jsonResponse(false, 'Invalid action.');
}
