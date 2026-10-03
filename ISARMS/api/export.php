<?php
/**
 * ISARMS - API: Export Handler
 */
define('ROOT', dirname(__DIR__));
require_once ROOT . '/includes/config.php';
require_once ROOT . '/includes/functions.php';
requireAuth('Admin', 'Registrar');

$db   = Database::getInstance();
$type = $_GET['type'] ?? '';

if ($type === 'students') {

    // ── Re-apply the same filters from the list page ──────────────
    $search   = sanitize($_GET['search']    ?? '');
    $progID   = (int)($_GET['program_id']  ?? 0);
    $yearLvl  = (int)($_GET['year_level']  ?? 0);
    $enrolled = $_GET['enrolled'] ?? '';

    $where  = ['s.dateDeleted IS NULL'];
    $params = [];

    if ($search) {
        $where[]         = "(s.studentNo LIKE :srch OR s.fName LIKE :srch OR s.lName LIKE :srch OR s.email LIKE :srch)";
        $params[':srch'] = "%$search%";
    }
    if ($progID)  { $where[] = "s.programID=:pid"; $params[':pid'] = $progID; }
    if ($yearLvl) { $where[] = "s.yearLevel=:yl";  $params[':yl']  = $yearLvl; }
    if ($enrolled !== '') { $where[] = "s.isEnroll=:en"; $params[':en'] = (int)$enrolled; }

    $whereSQL = implode(' AND ', $where);

    $students = $db->fetchAll("
        SELECT
            s.studentNo       AS 'Student No.',
            s.lName           AS 'Last Name',
            s.fName           AS 'First Name',
            s.mName           AS 'Middle Name',
            s.extName         AS 'Extension',
            s.sex             AS 'Sex',
            s.dateofBirth     AS 'Date of Birth',
            p.programCode     AS 'Program Code',
            p.programName     AS 'Program Name',
            sec.sectionName   AS 'Section',
            s.yearLevel       AS 'Year Level',
            s.email           AS 'Email',
            CASE WHEN s.isEnroll = 1 THEN 'Yes' ELSE 'No' END  AS 'Enrolled',
            CASE WHEN s.isActive  = 1 THEN 'Active' ELSE 'Inactive' END AS 'Status',
            DATE_FORMAT(s.dateCreated, '%Y-%m-%d') AS 'Date Added'
        FROM students s
        JOIN programs p   ON p.programID  = s.programID
        LEFT JOIN (
            SELECT e.studentID, e.sectionID
            FROM enrollments e
            WHERE e.status = 'Active' AND e.dateDeleted IS NULL
            GROUP BY e.studentID
        ) current_section ON current_section.studentID = s.studentID
        LEFT JOIN sections sec ON sec.sectionID = current_section.sectionID
        WHERE $whereSQL
        ORDER BY s.lName, s.fName
    ", $params);

    // ── Build filename ─────────────────────────────────────────────
    $filename = 'students_export_' . date('Ymd_His') . '.csv';

    // ── Stream CSV headers ─────────────────────────────────────────
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $out = fopen('php://output', 'w');

    // UTF-8 BOM so Excel opens it correctly
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

    if (empty($students)) {
        // Write headers only if no data
        fputcsv($out, [
            'Student No.', 'Last Name', 'First Name', 'Middle Name', 'Extension',
            'Sex', 'Date of Birth', 'Program Code', 'Program Name', 'Section',
            'Year Level', 'Email', 'Enrolled', 'Status', 'Date Added'
        ]);
        fputcsv($out, ['No records found matching the selected filters.']);
    } else {
        // Column headers from first row keys
        fputcsv($out, array_keys($students[0]));

        // Data rows
        foreach ($students as $row) {
            // Replace nulls with empty string
            $row = array_map(fn($v) => $v ?? '', $row);
            fputcsv($out, $row);
        }
    }

    fclose($out);
    exit;

}

// Unknown type — return JSON error
header('Content-Type: application/json');
echo json_encode(['success' => false, 'message' => 'Invalid export type.']);
exit;