<?php

define('ROOT', dirname(__DIR__, 2));
require_once ROOT . '/includes/config.php';
require_once ROOT . '/includes/functions.php';
requireAuth('Registrar', 'Admin');

$db  = Database::getInstance();
$msg = $err = '';

function writeSectionStreamPayload(array $payload): void {
    $feedDir = ROOT . '/storage';
    if (!is_dir($feedDir)) {
        @mkdir($feedDir, 0755, true);
    }
    $feedPath = $feedDir . '/sections_feed.json';
    @file_put_contents(
        $feedPath,
        json_encode([
            'time'    => time(),
            'section' => $payload,
            'kind'    => 'section_update'
        ]),
        LOCK_EX
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        $err = 'Invalid request.';
    } else {

        $action = $_POST['action'] ?? '';

        if ($action === 'add_section') {

            $name = sanitize($_POST['sectionName'] ?? '');
            $desc = sanitize($_POST['sectionDesc'] ?? '');
            $pid  = (int)($_POST['programID'] ?? 0);
            $ayid = (int)($_POST['acadYearID'] ?? 0);

            if (!$name || !$pid || !$ayid) {
                $err = 'Section name, program and academic year are required.';
            } else {

                $exists = $db->fetchOne(
                    "SELECT sectionID
                     FROM sections
                     WHERE sectionName = :n
                       AND programID = :p
                       AND acadYearID = :ay
                       AND dateDeleted IS NULL",
                    [
                        ':n'  => $name,
                        ':p'  => $pid,
                        ':ay' => $ayid
                    ]
                );

                if ($exists) {

                    $err = "Section '$name' already exists for this program and academic year.";

                } else {

                    $id = $db->insert(
                        "INSERT INTO sections
                            (sectionName, sectionDesc, programID, acadYearID)
                         VALUES
                            (:n, :d, :p, :ay)",
                        [
                            ':n'  => $name,
                            ':d'  => $desc,
                            ':p'  => $pid,
                            ':ay' => $ayid
                        ]
                    );

                    $msg = "Section '$name' created. Now add subjects to it below.";

                    $sectionPayload = [
                        'id' => (int)$id,
                        'sectionName' => $name,
                        'programCode' => $db->fetchOne("SELECT programCode FROM programs WHERE programID = :id", [':id' => $pid])['programCode'] ?? '',
                        'programID' => (int)$pid,
                        'academicYear' => $db->fetchOne("SELECT academicYear FROM acad_years WHERE acadYearID = :id", [':id' => $ayid])['academicYear'] ?? '',
                        'ayStatus' => $db->fetchOne("SELECT status FROM acad_years WHERE acadYearID = :id", [':id' => $ayid])['status'] ?? '',
                        'updatedAt' => time(),
                    ];
                    writeSectionStreamPayload($sectionPayload);

                    if (!empty($_POST['ajax'])) {

                        header('Content-Type: application/json');

                        $prog = $db->fetchOne(
                            "SELECT programCode
                             FROM programs
                             WHERE programID = :id",
                            [':id' => $pid]
                        );

                        $ay = $db->fetchOne(
                            "SELECT academicYear, status
                             FROM acad_years
                             WHERE acadYearID = :id",
                            [':id' => $ayid]
                        );

                        $sectionData = [
                            'id'           => $id,
                            'sectionName'  => $name,
                            'programCode'  => $prog['programCode'] ?? '',
                            'academicYear' => $ay['academicYear'] ?? '',
                            'ayStatus'     => $ay['status'] ?? ''
                        ];

                        $resp = [
                            'success'      => true,
                            'id'           => $id,
                            'sectionName'  => $name,
                            'programCode'  => $prog['programCode'] ?? '',
                            'academicYear' => $ay['academicYear'] ?? '',
                            'ayStatus'     => $ay['status'] ?? ''
                        ];

                        $feedDir = ROOT . '/storage';

                        if (!is_dir($feedDir)) {
                            @mkdir($feedDir, 0755, true);
                        }

                        $feedPath = $feedDir . '/sections_feed.json';

                        @file_put_contents(
                            $feedPath,
                            json_encode([
                                'time'    => time(),
                                'section' => $sectionData
                            ]),
                            LOCK_EX
                        );

                        echo json_encode($resp);
                        exit;
                    }

                    header(
                        "Location: sections.php?section_id=" .
                        (int)$id .
                        "&msg=" .
                        urlencode($msg)
                    );
                    exit;
                }
            }
        }

        elseif ($action === 'edit_section') {

            $sid  = (int)($_POST['sectionID'] ?? 0);
            $name = sanitize($_POST['sectionName'] ?? '');
            $desc = sanitize($_POST['sectionDesc'] ?? '');
            $pid  = (int)($_POST['programID'] ?? 0);
            $ayid = (int)($_POST['acadYearID'] ?? 0);

            if (!$sid || !$name || !$pid || !$ayid) {
                $err = 'Section name, program and academic year are required.';
            } else {

                $duplicate = $db->fetchOne(
                    "SELECT sectionID
                     FROM sections
                     WHERE sectionName = :n
                       AND programID = :p
                       AND acadYearID = :ay
                       AND sectionID != :id
                       AND dateDeleted IS NULL",
                    [
                        ':n'  => $name,
                        ':p'  => $pid,
                        ':ay' => $ayid,
                        ':id' => $sid
                    ]
                );

                if ($duplicate) {

                    $err = "Section '$name' already exists for this program and academic year.";

                } else {

                    $db->query(
                        "UPDATE sections
                         SET sectionName = :n,
                             sectionDesc = :d,
                             programID = :p,
                             acadYearID = :ay
                         WHERE sectionID = :id",
                        [
                            ':n'  => $name,
                            ':d'  => $desc,
                            ':p'  => $pid,
                            ':ay' => $ayid,
                            ':id'  => $sid
                        ]
                    );

                    $sectionPayload = [
                        'id' => (int)$sid,
                        'sectionName' => $name,
                        'programCode' => $db->fetchOne("SELECT programCode FROM programs WHERE programID = :id", [':id' => $pid])['programCode'] ?? '',
                        'programID' => (int)$pid,
                        'academicYear' => $db->fetchOne("SELECT academicYear FROM acad_years WHERE acadYearID = :id", [':id' => $ayid])['academicYear'] ?? '',
                        'ayStatus' => $db->fetchOne("SELECT status FROM acad_years WHERE acadYearID = :id", [':id' => $ayid])['status'] ?? '',
                        'updatedAt' => time(),
                    ];
                    writeSectionStreamPayload($sectionPayload);

                    $msg = 'Section updated.';

                    header(
                        "Location: sections.php?section_id=" .
                        $sid .
                        "&msg=" .
                        urlencode($msg)
                    );
                    exit;
                }
            }
        }

        elseif ($action === 'delete_section') {

            $sid = (int)($_POST['sectionID'] ?? 0);

            $inUseRow = $db->fetchOne(
                "SELECT COUNT(*) AS c
                 FROM enrollments
                 WHERE sectionID = :id
                   AND dateDeleted IS NULL",
                [':id' => $sid]
            );

            $inUse = (int)($inUseRow['c'] ?? 0);

            if ($inUse > 0) {

                $err = "Cannot delete: section has $inUse enrollment(s).";

            } else {

                $db->query(
                    "UPDATE section_subjects
                     SET dateDeleted = NOW()
                     WHERE sectionID = :id
                       AND dateDeleted IS NULL",
                    [':id' => $sid]
                );

                $db->query(
                    "UPDATE sections
                     SET dateDeleted = NOW()
                     WHERE sectionID = :id",
                    [':id' => $sid]
                );

                writeSectionStreamPayload([
                    'id' => (int)$sid,
                    'deleted' => true,
                    'updatedAt' => time(),
                ]);

                $msg = 'Section deleted.';

                header(
                    "Location: sections.php?msg=" .
                    urlencode($msg)
                );
                exit;
            }
        }

        elseif ($action === 'add_subject') {

            $sid   = (int)($_POST['sectionID'] ?? 0);
            $subid = (int)($_POST['subjectID'] ?? 0);
            $tid   = (int)($_POST['teacherID'] ?? 0);
            $sem   = (int)($_POST['semester'] ?? 0);
            $ayid  = (int)($_POST['acadYearID'] ?? 0);
            $sched = sanitize($_POST['schedule'] ?? '');
            $room  = sanitize($_POST['room'] ?? '');

            if (!$sid || !$subid || !$sem || !$ayid) {

                $err = 'Subject, semester and academic year are required.';

            } else {

                $assignment = $db->fetchOne(
                    "SELECT sec.programID AS sectionProgramID,
                            sec.sectionName,
                            sub.programID AS subjectProgramID,
                            sub.yearLevel AS subjectYearLevel,
                            u.programID AS teacherProgramID,
                            u.userID AS matchedTeacherID
                     FROM sections sec
                     JOIN subjects sub ON sub.subjectID = :sub
                     LEFT JOIN users u
                       ON u.userID = :teacher
                      AND u.roleName = 'Teacher'
                      AND u.isActive = 1
                      AND u.dateDeleted IS NULL
                     WHERE sec.sectionID = :section
                       AND sec.dateDeleted IS NULL
                       AND sub.dateDeleted IS NULL",
                    [':sub' => $subid, ':teacher' => $tid, ':section' => $sid]
                );

                if (!$assignment) {
                    $err = 'Invalid subject, section or teacher assignment.';
                } elseif ($tid && !$assignment['matchedTeacherID']) {
                    $err = 'Invalid subject, section or teacher assignment.';
                } elseif (($sectionYearLevel = extractYearLevel($assignment['sectionName'])) >= 1
                    && $sectionYearLevel <= 4
                    && (int)($assignment['subjectYearLevel'] ?? 0) !== $sectionYearLevel) {
                    $err = 'The subject year level must match the section year level.';
                } elseif ($tid && $assignment['subjectProgramID'] !== null
                    && (int)$assignment['subjectProgramID'] !== 0
                    && (int)$assignment['teacherProgramID'] !== (int)$assignment['sectionProgramID']) {
                    $err = 'Major subjects can only be assigned to a teacher from the section program.';
                }

                if ($err) {
                    header(
                        "Location: sections.php?section_id=" .
                        $sid .
                        "&msg=" .
                        urlencode($err) .
                        "&err=1"
                    );
                    exit;
                }

                                $exists = $db->fetchOne(
                                        "SELECT secSubID, dateDeleted
                                         FROM section_subjects
                                         WHERE sectionID = :s
                                             AND subjectID = :sub
                                             AND semester = :sem
                                             AND acadYearID = :ay
                                         ORDER BY secSubID DESC
                                         LIMIT 1",
                    [
                        ':s'   => $sid,
                        ':sub' => $subid,
                        ':sem' => $sem,
                        ':ay'  => $ayid
                    ]
                );

                if ($exists && $exists['dateDeleted'] === null) {
                    $err = 'This subject is already added to the section for that semester.';
                } else {
                    if ($exists) {
                        $db->query(
                            "UPDATE section_subjects
                             SET teacherID=:teacher, schedule=:schedule, room=:room, dateDeleted=NULL
                             WHERE secSubID=:id AND dateDeleted IS NOT NULL",
                            [
                                ':teacher'=>$tid ?: null,
                                ':schedule'=>$sched,
                                ':room'=>$room,
                                ':id'=>$exists['secSubID'],
                            ]
                        );
                        $msg = 'Previously removed subject assignment restored.';
                    } else {
                        try {
                            $db->insert(
                                "INSERT INTO section_subjects
                                    (sectionID, subjectID, teacherID, semester, acadYearID, schedule, room)
                                 VALUES
                                    (:s, :sub, :t, :sem, :ay, :sch, :rm)",
                                [
                                    ':s'   => $sid,
                                    ':sub' => $subid,
                                    ':t'   => $tid ?: null,
                                    ':sem' => $sem,
                                    ':ay'  => $ayid,
                                    ':sch' => $sched,
                                    ':rm'  => $room
                                ]
                            );
                            $msg = 'Subject added to section.';
                        } catch (PDOException $exception) {
                            if ($exception->getCode() === '23000' && (int)($exception->errorInfo[1] ?? 0) === 1062) {
                                $err = 'This subject was already added to the section for that semester.';
                            } else {
                                error_log('Section subject assignment failed: ' . $exception->getMessage());
                                $err = 'The subject could not be added. Please try again.';
                            }
                        }
                    }

                    if (!$err) {
                        writeSectionStreamPayload([
                            'id' => (int)$sid,
                            'subjectAdded' => true,
                            'updatedAt' => time(),
                        ]);

                        $assignmentInfo = $db->fetchOne(
                            "SELECT sub.subjectCode, sub.subjectName, sec.sectionName
                             FROM subjects sub JOIN sections sec ON sec.sectionID=:section
                             WHERE sub.subjectID=:subject",
                            [':section'=>$sid, ':subject'=>$subid]
                        );
                        $assignmentLabel = $assignmentInfo
                            ? $assignmentInfo['subjectCode'] . ' - ' . $assignmentInfo['subjectName'] . ' in ' . $assignmentInfo['sectionName']
                            : "subject #{$subid} in section #{$sid}";
                        if ($tid) {
                            $teacherInfo = $db->fetchOne("SELECT fName, mName, lName, extName FROM users WHERE userID=:id", [':id'=>$tid]);
                            $teacherName = $teacherInfo ? fullName($teacherInfo) : "Teacher #{$tid}";
                            createNotification($tid, 'User', 'Subject Assignment Added', "Admin assigned {$teacherName} to teach {$assignmentLabel}.", 'Assignment', 'Admin');
                        }
                    }
                }
            }

            header(
                "Location: sections.php?section_id=" .
                $sid .
                "&msg=" .
                urlencode($msg ?: $err) .
                "&err=" .
                ($err ? 1 : 0)
            );
            exit;
        }

        elseif ($action === 'update_subject') {

            $ssid  = (int)($_POST['secSubID'] ?? 0);
            $sid   = (int)($_POST['sectionID'] ?? 0);
            $tid   = (int)($_POST['teacherID'] ?? 0);
            $sched = sanitize($_POST['schedule'] ?? '');
            $room  = sanitize($_POST['room'] ?? '');

            if (!$ssid || !$sid) {

                $err = 'Invalid subject assignment.';

            } else {

                $assignment = $db->fetchOne(
                    "SELECT sec.programID AS sectionProgramID,
                            sub.programID AS subjectProgramID
                     FROM section_subjects ss
                     JOIN sections sec ON sec.sectionID = ss.sectionID
                     JOIN subjects sub ON sub.subjectID = ss.subjectID
                     WHERE ss.secSubID = :id
                       AND ss.sectionID = :section
                       AND ss.dateDeleted IS NULL
                       AND sec.dateDeleted IS NULL
                       AND sub.dateDeleted IS NULL",
                    [':id' => $ssid, ':section' => $sid]
                );

                $teacher = $tid ? $db->fetchOne(
                    "SELECT userID, programID FROM users WHERE userID=:teacher AND roleName='Teacher' AND isActive=1 AND dateDeleted IS NULL",
                    [':teacher' => $tid]
                ) : null;

                if (!$assignment) {
                    $err = 'Invalid subject, section or teacher assignment.';
                } elseif ($tid && !$teacher) {
                    $err = 'Invalid subject, section or teacher assignment.';
                } elseif ($tid && $assignment['subjectProgramID'] !== null
                    && (int)$assignment['subjectProgramID'] !== 0
                    && (int)$teacher['programID'] !== (int)$assignment['sectionProgramID']) {
                    $err = 'Major subjects can only be assigned to a teacher from the section program.';
                }

                if ($err) {
                    header(
                        "Location: sections.php?section_id=" .
                        $sid .
                        "&msg=" .
                        urlencode($err) .
                        "&err=1"
                    );
                    exit;
                }

                $db->query(
                    "UPDATE section_subjects
                     SET teacherID = :t,
                         schedule = :sch,
                         room = :rm
                     WHERE secSubID = :id
                       AND dateDeleted IS NULL",
                    [
                        ':t'   => $tid ?: null,
                        ':sch' => $sched,
                        ':rm'  => $room,
                        ':id'  => $ssid
                    ]
                );

                writeSectionStreamPayload([
                    'id' => (int)$sid,
                    'subjectUpdated' => true,
                    'updatedAt' => time(),
                ]);

                $msg = 'Subject assignment updated.';
                if ($tid) {
                    $teacherInfo = $db->fetchOne("SELECT fName, mName, lName, extName FROM users WHERE userID=:id", [':id'=>$tid]);
                    $teacherName = $teacherInfo ? fullName($teacherInfo) : "Teacher #{$tid}";
                    createNotification($tid, 'User', 'Subject Assignment Updated', "Admin updated the teaching assignment for {$teacherName} in section #{$sid}.", 'Assignment', 'Admin');
                }
            }

            header(
                "Location: sections.php?section_id=" .
                $sid .
                "&msg=" .
                urlencode($msg ?: $err) .
                "&err=" .
                ($err ? 1 : 0)
            );
            exit;
        }

        elseif ($action === 'remove_subject') {

            $ssid = (int)($_POST['secSubID'] ?? 0);
            $sid  = (int)($_POST['sectionID'] ?? 0);

            $ss = $db->fetchOne(
                "SELECT subjectID, acadYearID, semester
                 FROM section_subjects
                 WHERE secSubID = :id
                   AND dateDeleted IS NULL",
                [':id' => $ssid]
            );

            if (!$ss) {

                $err = 'Subject assignment not found.';

            } else {

                $hasGradesRow = $db->fetchOne(
                    "SELECT COUNT(*) AS c
                     FROM grades g
                     JOIN enrollments e
                       ON e.enrollmentID = g.enrollmentID
                     WHERE e.sectionID = :sec
                       AND e.subjectID = :sub
                       AND e.semester = :sem
                       AND e.acadYearID = :ay
                       AND g.dateDeleted IS NULL",
                    [
                        ':sec' => $sid,
                        ':sub' => $ss['subjectID'],
                        ':sem' => $ss['semester'],
                        ':ay'  => $ss['acadYearID']
                    ]
                );

                $hasGrades = (int)($hasGradesRow['c'] ?? 0);

                if ($hasGrades > 0) {

                    $err = "Cannot remove: $hasGrades grade record(s) exist for this subject.";

                } else {

                    $db->query(
                        "UPDATE section_subjects
                         SET dateDeleted = NOW()
                         WHERE secSubID = :id",
                        [':id' => $ssid]
                    );

                    writeSectionStreamPayload([
                        'id' => (int)$sid,
                        'subjectRemoved' => true,
                        'updatedAt' => time(),
                    ]);

                    $msg = 'Subject removed from section.';
                    $assignedTeacher = $db->fetchOne(
                        "SELECT teacherID FROM section_subjects WHERE secSubID=:id",
                        [':id'=>$ssid]
                    );
                    if ($assignedTeacher) {
                        createNotification((int)$assignedTeacher['teacherID'], 'User', 'Subject Assignment Removed', "Your subject assignment for section #{$sid} was removed by the Admin.", 'Assignment', 'Admin');
                    }
                }
            }

            header(
                "Location: sections.php?section_id=" .
                $sid .
                "&msg=" .
                urlencode($msg ?: $err) .
                "&err=" .
                ($err ? 1 : 0)
            );
            exit;
        }
    }
}

if (isset($_GET['list']) && $_GET['list'] === '1') {

    header('Content-Type: application/json');

    $secs = $db->fetchAll(
        "SELECT
            sec.sectionID,
            sec.sectionName,
            sec.sectionDesc,
            p.programCode,
            p.programName,
            p.programID AS pID,
            ay.academicYear,
            ay.acadYearID,
            ay.status AS ayStatus,
            COUNT(DISTINCT ss.secSubID) AS subjectCount,
            COUNT(DISTINCT e.studentID) AS studentCount
         FROM sections sec
         JOIN programs p
           ON p.programID = sec.programID
         JOIN acad_years ay
           ON ay.acadYearID = sec.acadYearID
         LEFT JOIN section_subjects ss
           ON ss.sectionID = sec.sectionID
          AND ss.dateDeleted IS NULL
         LEFT JOIN enrollments e
           ON e.sectionID = sec.sectionID
          AND e.status = 'Active'
          AND e.dateDeleted IS NULL
         WHERE sec.dateDeleted IS NULL
         GROUP BY
            sec.sectionID,
            sec.sectionName,
            sec.sectionDesc,
            p.programCode,
            p.programName,
            p.programID,
            ay.academicYear,
            ay.acadYearID,
            ay.status
         ORDER BY
            ay.status = 'Active' DESC,
            ay.academicYear DESC,
            p.programCode,
            sec.sectionName"
    );

    echo json_encode([
        'success'  => true,
        'sections' => $secs
    ]);

    exit;
}

$selectedID = (int)($_GET['section_id'] ?? 0);

if (isset($_GET['msg'])) {

    if (!empty($_GET['err'])) {
        $err = urldecode($_GET['msg']);
    } else {
        $msg = urldecode($_GET['msg']);
    }
}

$programs = $db->fetchAll(
    "SELECT
        programID,
        programCode,
        programName
     FROM programs
     WHERE dateDeleted IS NULL
     ORDER BY programCode"
);

$acadYears = $db->fetchAll(
    "SELECT
        acadYearID,
        academicYear,
        status
     FROM acad_years
     WHERE dateDeleted IS NULL
       AND status = 'Active'
     ORDER BY academicYear DESC"
);

$teachers = $db->fetchAll(
    "SELECT
        userID,
        CONCAT(lName, ', ', fName) AS name,
        programID
     FROM users
     WHERE roleName = 'Teacher'
       AND isActive = 1
       AND dateDeleted IS NULL
     ORDER BY lName, fName"
);

$activeAY = $db->fetchOne(
    "SELECT acadYearID
     FROM acad_years
     WHERE status = 'Active'
       AND dateDeleted IS NULL
     ORDER BY acadYearID DESC
     LIMIT 1"
);

$allSections = $db->fetchAll(
    "SELECT
        sec.sectionID,
        sec.sectionName,
        sec.sectionDesc,
        p.programCode,
        p.programName,
        p.programID AS pID,
        ay.academicYear,
        ay.status AS ayStatus,
        ay.acadYearID,
        COUNT(DISTINCT ss.secSubID) AS subjectCount,
        COUNT(DISTINCT e.studentID) AS studentCount
     FROM sections sec
     JOIN programs p
       ON p.programID = sec.programID
     JOIN acad_years ay
       ON ay.acadYearID = sec.acadYearID
     LEFT JOIN section_subjects ss
       ON ss.sectionID = sec.sectionID
      AND ss.dateDeleted IS NULL
     LEFT JOIN enrollments e
       ON e.sectionID = sec.sectionID
      AND e.status = 'Active'
      AND e.dateDeleted IS NULL
     WHERE sec.dateDeleted IS NULL
     GROUP BY
        sec.sectionID,
        sec.sectionName,
        sec.sectionDesc,
        p.programCode,
        p.programName,
        p.programID,
        ay.academicYear,
        ay.status,
        ay.acadYearID
     ORDER BY
        ay.status = 'Active' DESC,
        ay.academicYear DESC,
        p.programCode,
        sec.sectionName"
);

function extractYearLevel($sectionName)
{
    $name = trim(
        preg_replace('/\s+/', ' ', (string)$sectionName)
    );

    /*
     * Supports:
     * BSIT 1-A
     * BSIT-1A
     * BSIT 1A
     * BSIT 1-B
     * BSIT-2B
     * BSIT 3-B
     * BSIT-3B
     * 1st Year
     * 2nd Year - A
     * Year 3
     * 4th Yr
     * 5th-Year
     */

    if (preg_match(
        '/(?:^|[\s\-_–])([1-5])\s*(?:st|nd|rd|th)?\s*(?:year|yr)?(?:[\s\-_–]|$)/i',
        $name,
        $m
    )) {
        return (int)$m[1];
    }

    if (preg_match(
        '/\b(?:year|yr)\s*[-\s]*([1-5])\b/i',
        $name,
        $m
    )) {
        return (int)$m[1];
    }

    /*
     * Handles names such as:
     * BSIT-3B
     * BSIT 3B
     * BSIT3B
     *
     * We look for a digit 1-5 followed by a
     * section letter/number pattern.
     */
    if (preg_match(
        '/(?:^|[\s\-_–A-Za-z])([1-5])(?=[A-Za-z](?:[\s\-_–]|$)|[\s\-_–])/i',
        $name,
        $m
    )) {
        return (int)$m[1];
    }

    /*
     * Final fallback:
     * If a standalone 1-5 exists near the beginning,
     * use it as the year level.
     */
    if (preg_match('/(?:^|[\s\-_–])([1-5])(?:\D|$)/i', $name, $m)) {
        return (int)$m[1];
    }

    return 0;
}

foreach ($allSections as &$secRow) {
    $secRow['yearLevel'] = extractYearLevel($secRow['sectionName']);
}

unset($secRow);

usort(
    $allSections,
    function ($a, $b) {

        if ($a['ayStatus'] !== $b['ayStatus']) {
            return $a['ayStatus'] === 'Active' ? -1 : 1;
        }

        if ($a['academicYear'] !== $b['academicYear']) {
            return strcmp(
                $b['academicYear'],
                $a['academicYear']
            );
        }

        if ((int)$a['pID'] !== (int)$b['pID']) {
            return strcmp(
                $a['programCode'],
                $b['programCode']
            );
        }

        if ((int)$a['yearLevel'] !== (int)$b['yearLevel']) {

            /*
             * Recognized years first.
             * Others last.
             */
            if ((int)$a['yearLevel'] === 0) {
                return 1;
            }

            if ((int)$b['yearLevel'] === 0) {
                return -1;
            }

            return (int)$a['yearLevel'] <=> (int)$b['yearLevel'];
        }

        return strnatcasecmp(
            $a['sectionName'],
            $b['sectionName']
        );
    }
);

$section = null;
$secSubjects = [];
$availSubjects = [];

if ($selectedID) {

    $section = $db->fetchOne(
        "SELECT
            sec.*,
            p.programCode,
            p.programName,
            ay.academicYear,
            ay.acadYearID AS ayID
         FROM sections sec
         JOIN programs p
           ON p.programID = sec.programID
         JOIN acad_years ay
           ON ay.acadYearID = sec.acadYearID
         WHERE sec.sectionID = :id
           AND sec.dateDeleted IS NULL",
        [
            ':id' => $selectedID
        ]
    );

    if ($section) {
        $sectionYearLevel = extractYearLevel($section['sectionName']);

        $secSubjects = $db->fetchAll(
            "SELECT
                ss.secSubID,
                ss.subjectID,
                ss.teacherID,
                ss.semester,
                ss.schedule,
                ss.room,
                ss.acadYearID,
                sub.subjectCode,
                sub.subjectName,
                sub.units,
                sub.programID AS subjectProgramID,
                CONCAT(u.lName, ', ', u.fName) AS teacherName,
                COUNT(DISTINCT e.studentID) AS enrolledCount,
                SUM(
                    CASE
                        WHEN g.status = 'Approved' THEN 1
                        ELSE 0
                    END
                ) AS approvedGrades,
                SUM(
                    CASE
                        WHEN g.status = 'Submitted' THEN 1
                        ELSE 0
                    END
                ) AS submittedGrades
             FROM section_subjects ss
             JOIN subjects sub
               ON sub.subjectID = ss.subjectID
                         LEFT JOIN users u
               ON u.userID = ss.teacherID
             LEFT JOIN enrollments e
               ON e.sectionID = ss.sectionID
              AND e.subjectID = ss.subjectID
              AND e.semester = ss.semester
              AND e.acadYearID = ss.acadYearID
              AND e.status = 'Active'
              AND e.dateDeleted IS NULL
             LEFT JOIN grades g
               ON g.enrollmentID = e.enrollmentID
              AND g.teacherID = ss.teacherID
              AND g.dateDeleted IS NULL
             WHERE ss.sectionID = :id
               AND ss.subjectID IS NOT NULL
               AND ss.semester IS NOT NULL
               AND ss.acadYearID IS NOT NULL
               AND ss.dateDeleted IS NULL
               AND sub.dateDeleted IS NULL
             GROUP BY
                ss.secSubID,
                ss.subjectID,
                ss.teacherID,
                ss.semester,
                ss.schedule,
                ss.room,
                ss.acadYearID,
                sub.subjectCode,
                sub.subjectName,
                sub.units,
                u.lName,
                u.fName
             ORDER BY
                ss.semester,
                sub.subjectCode",
            [
                ':id' => $selectedID
            ]
        );

        $yearLevelFilter = '';
        $subjectParams = [':pid' => $section['programID']];
        if ($sectionYearLevel >= 1 && $sectionYearLevel <= 4) {
            $yearLevelFilter = 'AND s.yearLevel = :yearLevel';
            $subjectParams[':yearLevel'] = $sectionYearLevel;
        }

        $availSubjects = $db->fetchAll(
            "SELECT
                s.subjectID,
                s.subjectCode,
                s.subjectName,
                s.units,
                s.semester,
                s.programID,
                p.programCode,
                CASE
                    WHEN s.programID IS NULL
                      OR s.programID = 0
                    THEN 'Shared / All Programs'
                    ELSE CONCAT(p.programCode, ' Only')
                END AS courseLabel
             FROM subjects s
             LEFT JOIN programs p
               ON p.programID = s.programID
             WHERE (
                    s.programID = :pid
                    OR s.programID IS NULL
                    OR s.programID = 0
             )
               AND s.subjectID IS NOT NULL
               AND s.subjectCode IS NOT NULL
               AND s.subjectCode <> ''
               AND s.semester IS NOT NULL
               AND s.dateDeleted IS NULL
               $yearLevelFilter
             ORDER BY
                s.semester,
                s.subjectCode",
            $subjectParams
        );
    }
}

$csrf = generateCSRF();

$pageTitle  = 'Sections';
$activeMenu = 'sections.php';

require_once ROOT . '/includes/header.php';
?>

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

<div class="sections-page">

    <div class="section-topbar">

        <div class="section-heading">
            <div class="section-list-title">Sections</div>
            <div class="section-list-subtitle">
                Browse sections by course and year level
            </div>
        </div>

        <div class="section-search-wrap">
            <i class="fas fa-search"></i>
            <input
                type="text"
                id="sectionSearch"
                placeholder="Search section, course or academic year..."
                oninput="renderSections()"
            >
        </div>

        <button
            type="button"
            class="section-filter-button"
            onclick="toggleSectionFilters()"
            id="sectionFilterButton"
        >
            <i class="fas fa-filter"></i>
            Filter
        </button>

        <button
            type="button"
            class="btn btn-primary"
            onclick="openModal('addSectionModal')"
            style="white-space:nowrap;"
        >
            <i class="fas fa-plus"></i>
            New Section
        </button>

    </div>

    <!-- COURSE NAVIGATION -->
    <div class="course-nav-container">

        <div class="nav-label">
            <i class="fas fa-graduation-cap"></i>
            Course
        </div>

        <div
            class="course-nav-bar"
            id="courseNav"
        ></div>

    </div>

    <!-- FILTER -->
    <div
        id="sectionFilterPanel"
        class="section-filter-panel"
        style="display:none;"
    >

        <div class="filter-field">

            <label>Academic Year</label>

            <select
                id="acadYearFilter"
                onchange="renderSections()"
            >

                <?php foreach ($acadYears as $ay): ?>

                    <option
                        value="<?= (int)$ay['acadYearID'] ?>"
                        <?= (
                            $activeAY &&
                            (int)$ay['acadYearID'] ===
                            (int)$activeAY['acadYearID']
                        ) ? 'selected' : '' ?>
                    >
                        <?= htmlspecialchars($ay['academicYear']) ?>
                        <?= $ay['status'] === 'Active' ? ' — Active' : '' ?>
                    </option>

                <?php endforeach; ?>

                <option value="all">
                    All Academic Years
                </option>

            </select>

        </div>

        <div class="filter-field">

            <label>Year Level</label>

            <select
                id="yearLevelFilter"
                onchange="renderSections()"
            >

                <option value="">
                    All Years
                </option>

                <option value="1">
                    1st Year
                </option>

                <option value="2">
                    2nd Year
                </option>

                <option value="3">
                    3rd Year
                </option>

                <option value="4">
                    4th Year
                </option>

                <option value="5">
                    5th Year
                </option>

                <option value="0">
                    Others
                </option>

            </select>

        </div>

        <button
            type="button"
            class="btn btn-outline btn-sm"
            onclick="resetSectionFilters()"
        >
            <i class="fas fa-rotate-left"></i>
            Reset
        </button>

    </div>

    <!-- YEAR NAVIGATION -->
    <div class="year-navigation-container">

        <div class="nav-label">
            <i class="fas fa-layer-group"></i>
            Year Level
        </div>

        <div
            class="year-nav-bar"
            id="yearNav"
        ></div>

    </div>

    <!-- WORKSPACE -->
    <div class="sections-workspace">

        <!-- LEFT -->
        <div class="sections-list-column">

            <div class="sections-list-card">

                <div class="sections-list-head">

                    <div>
                        <div
                            class="section-list-title"
                            id="sectionsListTitle"
                        >
                            Sections
                        </div>

                        <div class="sections-list-subtitle-small">
                            Select a section to manage subjects
                        </div>
                    </div>

                    <div
                        class="section-count-label"
                        id="sectionsListCount"
                    ></div>

                </div>

                <div id="sectionList"></div>

            </div>

        </div>

        <!-- RIGHT -->
        <div class="sections-detail-column">

            <?php if (!$section): ?>

                <div class="empty-detail-card">

                    <div class="empty-detail-icon">
                        <i class="fas fa-chalkboard"></i>
                    </div>

                    <div class="empty-detail-title">
                        Select a section
                    </div>

                    <div class="empty-detail-text">
                        Choose a section from the list to manage
                        subjects, teachers, schedules, enrollment
                        and grade assignments.
                    </div>

                </div>

            <?php else: ?>

                <!-- SECTION HEADER -->
                <div class="section-detail-header">

                    <div class="section-detail-information">

                        <div class="section-detail-label">
                            SECTION DETAIL
                        </div>

                        <div class="section-detail-name">
                            <?= htmlspecialchars($section['sectionName']) ?>
                        </div>

                        <div class="section-detail-meta">

                            <span>
                                <i class="fas fa-graduation-cap"></i>
                                <?= htmlspecialchars($section['programName']) ?>
                            </span>

                            <span>
                                <i class="fas fa-calendar"></i>
                                <?= htmlspecialchars($section['academicYear']) ?>
                            </span>

                        </div>

                        <?php if (!empty($section['sectionDesc'])): ?>

                            <div class="section-detail-description">
                                <?= htmlspecialchars($section['sectionDesc']) ?>
                            </div>

                        <?php endif; ?>

                    </div>

                    <div class="section-detail-actions">

                        <button
                            class="btn btn-outline detail-edit-button"
                            onclick="openEditSection(<?= htmlspecialchars(json_encode($section), ENT_QUOTES) ?>)"
                        >
                            <i class="fas fa-pen"></i>
                            Edit
                        </button>

                        <form
                            method="POST"
                            onsubmit="return confirm('Delete this section? This action cannot be undone.')"
                        >

                            <input
                                type="hidden"
                                name="csrf_token"
                                value="<?= htmlspecialchars($csrf) ?>"
                            >

                            <input
                                type="hidden"
                                name="action"
                                value="delete_section"
                            >

                            <input
                                type="hidden"
                                name="sectionID"
                                value="<?= (int)$section['sectionID'] ?>"
                            >

                            <button
                                type="submit"
                                class="btn btn-danger detail-delete-button"
                                title="Delete section"
                            >
                                <i class="fas fa-trash"></i>
                            </button>

                        </form>

                    </div>

                </div>

                <!-- SUBJECTS -->
                <div class="card subjects-card">

                    <div class="card-header">

                        <div>

                            <div class="card-title">
                                <i class="fas fa-book"></i>
                                Subjects & Teacher Assignments
                            </div>

                            <div class="card-subtitle">
                                Manage subjects and teachers assigned to this section
                            </div>

                        </div>

                        <button
                            class="btn btn-primary btn-sm"
                            onclick="openModal('addSubjectModal')"
                        >
                            <i class="fas fa-plus"></i>
                            Add Subject
                        </button>

                    </div>

                    <?php if (empty($secSubjects)): ?>

                        <div class="subjects-empty-state">

                            <div class="subjects-empty-icon">
                                <i class="fas fa-book-open"></i>
                            </div>

                            <div class="subjects-empty-title">
                                No subjects assigned yet
                            </div>

                            <div class="subjects-empty-text">
                                Add subjects to this section and assign
                                the appropriate teacher for each subject.
                            </div>

                        </div>

                    <?php else: ?>

                        <?php

                        $bySem = [];

                        foreach ($secSubjects as $ss) {
                            $bySem[(int)$ss['semester']][] = $ss;
                        }

                        ksort($bySem);

                        ?>

                        <?php foreach ($bySem as $sem => $rows): ?>

                            <div class="semester-block">

                                <div class="semester-header">

                                    <span>
                                        <?= $sem == 1
                                            ? 'First Semester'
                                            : 'Second Semester'
                                        ?>
                                    </span>

                                    <small>
                                        <?= count($rows) ?>
                                        subject<?= count($rows) === 1 ? '' : 's' ?>
                                    </small>

                                </div>

                                <div class="table-responsive">

                                    <table class="data-table">

                                        <thead>

                                            <tr>

                                                <th>Subject</th>
                                                <th>Units</th>
                                                <th>Assigned Teacher</th>
                                                <th>Schedule / Room</th>
                                                <th style="text-align:center;">
                                                    Enrolled
                                                </th>
                                                <th style="text-align:center;">
                                                    Grades
                                                </th>
                                                <th style="text-align:center;">
                                                    Actions
                                                </th>

                                            </tr>

                                        </thead>

                                        <tbody>

                                            <?php foreach ($rows as $ss): ?>

                                                <tr>

                                                    <td>

                                                        <div class="subject-code">
                                                            <?= htmlspecialchars($ss['subjectCode']) ?>
                                                        </div>

                                                        <div class="subject-name">
                                                            <?= htmlspecialchars($ss['subjectName']) ?>
                                                        </div>

                                                    </td>

                                                    <td class="units-cell">
                                                        <?= htmlspecialchars($ss['units']) ?>
                                                    </td>

                                                    <td>

                                                        <div class="teacher-cell">

                                                            <div class="teacher-avatar">
                                                                <?= strtoupper(
                                                                    substr(
                                                                        trim($ss['teacherName'] ?? ''),
                                                                        0,
                                                                        1
                                                                    ) ?: '?'
                                                                ) ?>
                                                            </div>

                                                            <div class="teacher-name">
                                                                <?= $ss['teacherName'] ? htmlspecialchars($ss['teacherName']) : 'Unassigned' ?>
                                                            </div>

                                                        </div>

                                                    </td>

                                                    <td>

                                                        <?php if (
                                                            !empty($ss['schedule']) ||
                                                            !empty($ss['room'])
                                                        ): ?>

                                                            <div class="schedule-cell">

                                                                <?php if (!empty($ss['schedule'])): ?>

                                                                    <div>
                                                                        <i class="fas fa-clock"></i>
                                                                        <?= htmlspecialchars($ss['schedule']) ?>
                                                                    </div>

                                                                <?php endif; ?>

                                                                <?php if (!empty($ss['room'])): ?>

                                                                    <div>
                                                                        <i class="fas fa-door-open"></i>
                                                                        <?= htmlspecialchars($ss['room']) ?>
                                                                    </div>

                                                                <?php endif; ?>

                                                            </div>

                                                        <?php else: ?>

                                                            <span class="not-set">
                                                                Not set
                                                            </span>

                                                        <?php endif; ?>

                                                    </td>

                                                    <td class="number-cell">
                                                        <?= (int)$ss['enrolledCount'] ?>
                                                    </td>

                                                    <td class="grades-cell">

                                                        <?php if ((int)$ss['approvedGrades'] > 0): ?>

                                                            <span class="status-badge status-approved">
                                                                <?= (int)$ss['approvedGrades'] ?>
                                                                ✓
                                                            </span>

                                                        <?php endif; ?>

                                                        <?php if ((int)$ss['submittedGrades'] > 0): ?>

                                                            <span class="status-badge status-submitted">
                                                                <?= (int)$ss['submittedGrades'] ?>
                                                                ⏳
                                                            </span>

                                                        <?php endif; ?>

                                                        <?php if (
                                                            !(int)$ss['approvedGrades'] &&
                                                            !(int)$ss['submittedGrades']
                                                        ): ?>

                                                            <span class="not-set">
                                                                —
                                                            </span>

                                                        <?php endif; ?>

                                                    </td>

                                                    <td>

                                                        <div class="table-actions">

                                                            <button
                                                                class="btn btn-outline btn-sm"
                                                                onclick="openEditSubjectModal(<?= htmlspecialchars(json_encode($ss), ENT_QUOTES) ?>)"
                                                                title="Edit teacher/schedule"
                                                            >
                                                                <i class="fas fa-pen"></i>
                                                            </button>

                                                            <form
                                                                method="POST"
                                                                onsubmit="return confirm('Remove this subject from the section?')"
                                                            >

                                                                <input
                                                                    type="hidden"
                                                                    name="csrf_token"
                                                                    value="<?= htmlspecialchars($csrf) ?>"
                                                                >

                                                                <input
                                                                    type="hidden"
                                                                    name="action"
                                                                    value="remove_subject"
                                                                >

                                                                <input
                                                                    type="hidden"
                                                                    name="secSubID"
                                                                    value="<?= (int)$ss['secSubID'] ?>"
                                                                >

                                                                <input
                                                                    type="hidden"
                                                                    name="sectionID"
                                                                    value="<?= (int)$selectedID ?>"
                                                                >

                                                                <button
                                                                    type="submit"
                                                                    class="btn btn-danger btn-sm"
                                                                    title="Remove subject"
                                                                >
                                                                    <i class="fas fa-times"></i>
                                                                </button>

                                                            </form>

                                                        </div>

                                                    </td>

                                                </tr>

                                            <?php endforeach; ?>

                                        </tbody>

                                    </table>

                                </div>

                            </div>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </div>

                <!-- HOW THIS WORKS -->
                <div class="card how-works-card">

                    <div class="card-header">

                        <div class="card-title">
                            <i class="fas fa-info-circle"></i>
                            How This Works
                        </div>

                    </div>

                    <div class="card-body">

                        <div class="how-grid">

                            <div class="how-box blue-box">

                                <div class="how-title">
                                    <i class="fas fa-book"></i>
                                    Subject Assignment
                                </div>

                                <div>
                                    Each subject in a section is assigned
                                    to a specific teacher. Different subjects
                                    can have different teachers.
                                </div>

                            </div>

                            <div class="how-box green-box">

                                <div class="how-title">
                                    <i class="fas fa-graduation-cap"></i>
                                    Enrollment
                                </div>

                                <div>
                                    After adding subjects, use
                                    <strong>Student Registration → Register Entire Section</strong>
                                    to enroll students into the section.
                                </div>

                            </div>

                            <div class="how-box gold-box">

                                <div class="how-title">
                                    <i class="fas fa-pen"></i>
                                    Grade Encoding
                                </div>

                                <div>
                                    Teachers can only encode grades for
                                    subjects assigned to them.
                                </div>

                            </div>

                            <div class="how-box red-box">

                                <div class="how-title">
                                    <i class="fas fa-lock"></i>
                                    Data Protection
                                </div>

                                <div>
                                    A subject cannot be removed once
                                    grade records have been encoded.
                                </div>

                            </div>

                        </div>

                    </div>

                </div>

            <?php endif; ?>

        </div>

    </div>

</div>


<!-- ADD SECTION MODAL -->
<div
    id="addSectionModal"
    class="modal-overlay"
    style="display:none;"
>

    <div class="modal-box modal-medium">

        <div class="modal-header">

            <div>
                <h3>Create New Section</h3>
                <p>Add a new class section.</p>
            </div>

            <button
                type="button"
                class="modal-close"
                onclick="closeModal('addSectionModal')"
            >
                &times;
            </button>

        </div>

        <form method="POST">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars($csrf) ?>"
            >

            <input
                type="hidden"
                name="action"
                value="add_section"
            >

            <div class="form-group">

                <label class="form-label">
                    Section Name *
                </label>

                <input
                    name="sectionName"
                    class="form-control"
                    required
                    placeholder="BSIT-1A"
                >

                <div class="form-help">
                    Examples: BSIT-1A, BSIT-2B, BSBA 3-C,
                    4th Year - A.
                </div>

            </div>

            <div class="form-group">

                <label class="form-label">
                    Description
                </label>

                <input
                    name="sectionDesc"
                    class="form-control"
                    placeholder="e.g., Morning Section"
                >

            </div>

            <div class="form-two-columns">

                <div>

                    <label class="form-label">
                        Program *
                    </label>

                    <select
                        name="programID"
                        class="form-control"
                        required
                    >

                        <option value="">
                            — Select Program —
                        </option>

                        <?php foreach ($programs as $p): ?>

                            <option value="<?= (int)$p['programID'] ?>">
                                <?= htmlspecialchars(
                                    $p['programCode'] .
                                    ' – ' .
                                    $p['programName']
                                ) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div>

                    <label class="form-label">
                        Academic Year *
                    </label>

                    <select
                        name="acadYearID"
                        class="form-control"
                        required
                    >

                        <option value="">
                            — Select Academic Year —
                        </option>

                        <?php foreach ($acadYears as $ay): ?>

                            <option
                                value="<?= (int)$ay['acadYearID'] ?>"
                                <?= (
                                    $activeAY &&
                                    (int)$ay['acadYearID'] ===
                                    (int)$activeAY['acadYearID']
                                ) ? 'selected' : '' ?>
                            >
                                <?= htmlspecialchars($ay['academicYear']) ?>
                                <?= $ay['status'] === 'Active' ? ' ★' : '' ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

            </div>

            <div class="modal-info">
                <i class="fas fa-info-circle"></i>
                After creating the section, you can add subjects
                and assign teachers.
            </div>

            <div class="modal-actions">

                <button
                    type="button"
                    class="btn btn-outline"
                    onclick="closeModal('addSectionModal')"
                >
                    Cancel
                </button>

                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    <i class="fas fa-save"></i>
                    Create Section
                </button>

            </div>

        </form>

    </div>

</div>


<!-- EDIT SECTION MODAL -->
<div
    id="editSectionModal"
    class="modal-overlay"
    style="display:none;"
>

    <div class="modal-box modal-medium">

        <div class="modal-header">

            <div>
                <h3>Edit Section</h3>
                <p>Update section information.</p>
            </div>

            <button
                type="button"
                class="modal-close"
                onclick="closeModal('editSectionModal')"
            >
                &times;
            </button>

        </div>

        <form method="POST">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars($csrf) ?>"
            >

            <input
                type="hidden"
                name="action"
                value="edit_section"
            >

            <input
                type="hidden"
                name="sectionID"
                id="es_id"
            >

            <div class="form-group">

                <label class="form-label">
                    Section Name *
                </label>

                <input
                    name="sectionName"
                    id="es_name"
                    class="form-control"
                    required
                >

            </div>

            <div class="form-group">

                <label class="form-label">
                    Description
                </label>

                <input
                    name="sectionDesc"
                    id="es_desc"
                    class="form-control"
                >

            </div>

            <div class="form-two-columns">

                <div>

                    <label class="form-label">
                        Program *
                    </label>

                    <select
                        name="programID"
                        id="es_prog"
                        class="form-control"
                        required
                    >

                        <?php foreach ($programs as $p): ?>

                            <option value="<?= (int)$p['programID'] ?>">
                                <?= htmlspecialchars($p['programCode']) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div>

                    <label class="form-label">
                        Academic Year *
                    </label>

                    <select
                        name="acadYearID"
                        id="es_ay"
                        class="form-control"
                        required
                    >

                        <?php foreach ($acadYears as $ay): ?>

                            <option value="<?= (int)$ay['acadYearID'] ?>">
                                <?= htmlspecialchars($ay['academicYear']) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

            </div>

            <div class="modal-actions">

                <button
                    type="button"
                    class="btn btn-outline"
                    onclick="closeModal('editSectionModal')"
                >
                    Cancel
                </button>

                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    <i class="fas fa-save"></i>
                    Save Changes
                </button>

            </div>

        </form>

    </div>

</div>


<?php if ($section): ?>

<!-- ADD SUBJECT MODAL -->
<div
    id="addSubjectModal"
    class="modal-overlay"
    style="display:none;"
>

    <div class="modal-box modal-large">

        <div class="modal-header">

            <div>

                <h3>Add Subject to Section</h3>

                <p>
                    Section:
                    <strong>
                        <?= htmlspecialchars($section['sectionName']) ?>
                    </strong>
                </p>

            </div>

            <button
                type="button"
                class="modal-close"
                onclick="closeModal('addSubjectModal')"
            >
                &times;
            </button>

        </div>

        <form method="POST">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars($csrf) ?>"
            >

            <input
                type="hidden"
                name="action"
                value="add_subject"
            >

            <input
                type="hidden"
                name="sectionID"
                value="<?= (int)$selectedID ?>"
            >

            <input
                type="hidden"
                name="acadYearID"
                value="<?= (int)$section['ayID'] ?>"
            >

            <?php

            $alreadyInIDs = array_column(
                $secSubjects,
                'subjectID'
            );

            $bySemOptions = [
                1 => '1st Semester',
                2 => '2nd Semester'
            ];

            $grouped = [];

            foreach ($availSubjects as $sub) {
                $grouped[(int)$sub['semester']][] = $sub;
            }

            ksort($grouped);

            ?>

            <div class="form-two-columns">

                <div>

                    <label class="form-label">
                        Subject *
                    </label>

                    <select
                        name="subjectID"
                        class="form-control"
                        required
                        id="asMod_subject"
                    >

                        <option value="">
                            — Choose Subject —
                        </option>

                        <?php foreach ($grouped as $sem => $subs): ?>

                            <optgroup
                                label="<?= htmlspecialchars(
                                    $bySemOptions[$sem] ?? $sem
                                ) ?>"
                            >

                                <?php foreach ($subs as $sub): ?>

                                    <?php
                                    $used = in_array(
                                        $sub['subjectID'],
                                        $alreadyInIDs
                                    );
                                    ?>

                                    <option
                                        value="<?= (int)$sub['subjectID'] ?>"
                                        <?= $used ? 'disabled' : '' ?>
                                        data-sem="<?= (int)$sub['semester'] ?>"
                                        data-program="<?= (int)($sub['programID'] ?? 0) ?>"
                                    >
                                        <?= htmlspecialchars(
                                            $sub['subjectCode'] .
                                            ' – ' .
                                            $sub['subjectName'] .
                                            ' (' .
                                            $sub['units'] .
                                            'u)'
                                        ) ?>
                                        <?= $used ? ' [already added]' : '' ?>
                                    </option>

                                <?php endforeach; ?>

                            </optgroup>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div>

                    <label class="form-label">
                        Semester *
                    </label>

                    <select
                        name="semester"
                        class="form-control"
                        required
                        id="asMod_semester"
                    >

                        <option value="">
                            — Select —
                        </option>

                        <option value="1">
                            1st Semester
                        </option>

                        <option value="2">
                            2nd Semester
                        </option>

                    </select>

                </div>

            </div>

            <div class="form-group">

                <label class="form-label">
                    Assigned Teacher
                </label>

                <select
                    name="teacherID"
                    class="form-control"
                    id="asMod_teacher"
                >

                    <option value="">
                        — Unassigned —
                    </option>

                    <?php foreach ($teachers as $t): ?>

                        <option value="<?= (int)$t['userID'] ?>" data-program="<?= (int)($t['programID'] ?? 0) ?>">
                            <?= htmlspecialchars($t['name']) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>

            <div class="form-two-columns">

                <div>

                    <label class="form-label">
                        Schedule
                    </label>

                    <input
                        name="schedule"
                        class="form-control"
                        placeholder="e.g., MWF 8:00-9:00 AM"
                    >

                </div>

                <div>

                    <label class="form-label">
                        Room
                    </label>

                    <input
                        name="room"
                        class="form-control"
                        placeholder="e.g., Room 201"
                    >

                </div>

            </div>

            <div class="modal-actions">

                <button
                    type="button"
                    class="btn btn-outline"
                    onclick="closeModal('addSubjectModal')"
                >
                    Cancel
                </button>

                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    <i class="fas fa-plus"></i>
                    Add Subject
                </button>

            </div>

        </form>

    </div>

</div>


<!-- EDIT SUBJECT MODAL -->
<div
    id="editSubjectModal"
    class="modal-overlay"
    style="display:none;"
>

    <div class="modal-box modal-medium">

        <div class="modal-header">

            <div>

                <h3>Edit Subject Assignment</h3>

                <p id="esub_info"></p>

            </div>

            <button
                type="button"
                class="modal-close"
                onclick="closeModal('editSubjectModal')"
            >
                &times;
            </button>

        </div>

        <form method="POST">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars($csrf) ?>"
            >

            <input
                type="hidden"
                name="action"
                value="update_subject"
            >

            <input
                type="hidden"
                name="secSubID"
                id="esub_id"
            >

            <input
                type="hidden"
                name="sectionID"
                value="<?= (int)$selectedID ?>"
            >

            <div class="form-group">

                <label class="form-label">
                    Assigned Teacher
                </label>

                <select
                    name="teacherID"
                    id="esub_teacher"
                    class="form-control"
                >

                    <option value="">
                        — Unassigned —
                    </option>

                    <?php foreach ($teachers as $t): ?>

                        <option value="<?= (int)$t['userID'] ?>" data-program="<?= (int)($t['programID'] ?? 0) ?>">
                            <?= htmlspecialchars($t['name']) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>

            <div class="form-two-columns">

                <div>

                    <label class="form-label">
                        Schedule
                    </label>

                    <input
                        name="schedule"
                        id="esub_sched"
                        class="form-control"
                        placeholder="e.g., MWF 8:00-9:00 AM"
                    >

                </div>

                <div>

                    <label class="form-label">
                        Room
                    </label>

                    <input
                        name="room"
                        id="esub_room"
                        class="form-control"
                        placeholder="e.g., Room 201"
                    >

                </div>

            </div>

            <div class="modal-actions">

                <button
                    type="button"
                    class="btn btn-outline"
                    onclick="closeModal('editSubjectModal')"
                >
                    Cancel
                </button>

                <button
                    type="submit"
                    class="btn btn-primary"
                >
                    <i class="fas fa-save"></i>
                    Update
                </button>

            </div>

        </form>

    </div>

</div>

<?php endif; ?>


<style>

/* =========================================================
   MAIN
========================================================= */

.sections-page{
    width:100%;
    max-width:none;
    min-height:0;
}

/* =========================================================
   TOP BAR
========================================================= */

.section-topbar{
    display:grid;
    grid-template-columns:auto minmax(260px,1fr) auto auto;
    gap:14px;
    align-items:center;
    padding:14px 16px;
    background:#fff;
    border:1px solid var(--border);
    border-radius:14px 14px 0 0;
    box-shadow:0 3px 12px rgba(0,0,0,.04);
}

.section-heading{
    min-width:190px;
}

.section-list-title{
    font-family:'Source Sans 3', sans-serif;
    font-size:20px;
    margin:0;
    font-weight:700;
}

.section-list-subtitle{
    font-size:12px;
    color:var(--text-muted);
    margin-top:2px;
}

.sections-list-subtitle-small{
    font-size:10.5px;
    color:var(--text-muted);
    margin-top:2px;
}

/* =========================================================
   SEARCH
========================================================= */

.section-search-wrap{
    position:relative;
    display:flex;
    align-items:center;
    width:100%;
}

.section-search-wrap i{
    position:absolute;
    left:13px;
    color:var(--text-muted);
    font-size:12px;
    z-index:1;
}

.section-search-wrap input{
    width:100%;
    height:40px;
    padding:8px 12px 8px 36px;
    border:1px solid var(--border);
    border-radius:8px;
    font-size:12.5px;
    outline:none;
    box-sizing:border-box;
}

.section-search-wrap input:focus{
    border-color:var(--blue);
    box-shadow:0 0 0 3px rgba(31,64,137,.08);
}

/* =========================================================
   FILTER
========================================================= */

.section-filter-button{
    height:40px;
    padding:0 14px;
    border:1px solid var(--border);
    border-radius:8px;
    background:#fff;
    color:var(--text);
    font-size:12px;
    font-weight:700;
    cursor:pointer;
    white-space:nowrap;
}

.section-filter-button.active,
.section-filter-button:hover{
    border-color:var(--blue);
    color:var(--blue);
    background:var(--blue-light);
}

/* =========================================================
   NAV CONTAINERS
========================================================= */

.course-nav-container,
.year-navigation-container{
    display:flex;
    align-items:center;
    gap:10px;
    min-width:0;
    padding:9px 14px;
    background:#f8faff;
    border:1px solid var(--border);
    border-top:0;
}

.year-navigation-container{
    background:#fff;
}

.nav-label{
    display:flex;
    align-items:center;
    gap:6px;
    flex:0 0 auto;
    min-width:90px;
    color:var(--text-muted);
    font-size:10px;
    font-weight:800;
    text-transform:uppercase;
    letter-spacing:.6px;
}

.nav-label i{
    color:var(--blue);
}

/* =========================================================
   COURSE NAV
========================================================= */

.course-nav-bar{
    display:flex;
    align-items:center;
    gap:7px;
    overflow-x:auto;
    flex:1;
    min-width:0;
    scrollbar-width:thin;
}

.course-tab{
    border:1px solid var(--border);
    background:#fff;
    color:var(--text);
    padding:8px 16px;
    border-radius:8px;
    font-size:12px;
    font-weight:700;
    white-space:nowrap;
    cursor:pointer;
    transition:.15s;
}

.course-tab:hover{
    border-color:var(--blue);
    color:var(--blue);
}

.course-tab.active{
    background:var(--blue);
    border-color:var(--blue);
    color:#fff;
}

/* =========================================================
   YEAR NAV
========================================================= */

.year-nav-bar{
    display:flex;
    align-items:center;
    gap:7px;
    overflow-x:auto;
    flex:1;
    min-width:0;
    scrollbar-width:thin;
}

.year-tab{
    border:1px solid var(--border);
    background:#fff;
    color:var(--text-muted);
    padding:8px 14px;
    border-radius:8px;
    font-size:11.5px;
    font-weight:700;
    white-space:nowrap;
    cursor:pointer;
    transition:.15s;
}

.year-tab:hover{
    border-color:var(--blue);
    color:var(--blue);
}

.year-tab.active{
    background:var(--blue-light);
    border-color:var(--blue);
    color:var(--blue);
}

/* =========================================================
   FILTER PANEL
========================================================= */

.section-filter-panel{
    display:grid;
    grid-template-columns:minmax(180px,260px) minmax(180px,260px) auto;
    gap:10px;
    align-items:end;
    padding:12px 14px;
    border:1px solid var(--border);
    border-top:0;
    background:#fff;
}

.filter-field label{
    display:block;
    font-size:10px;
    text-transform:uppercase;
    letter-spacing:.6px;
    font-weight:800;
    color:var(--text-muted);
    margin-bottom:5px;
}

.filter-field select{
    width:100%;
    height:36px;
    padding:7px 10px;
    border:1px solid var(--border);
    border-radius:7px;
    background:#fff;
    font-size:12px;
}

/* =========================================================
   WORKSPACE
========================================================= */

.sections-workspace{
    display:grid;
    grid-template-columns:minmax(300px,330px) minmax(0,1fr);
    gap:20px;
    align-items:stretch;
    margin-top:18px;
    min-height:0;
}

.sections-list-column{
    min-width:0;
}

.sections-detail-column{
    min-width:0;
    display:flex;
    flex-direction:column;
    gap:16px;
}

/* =========================================================
   SECTION LIST
========================================================= */

.sections-list-card{
    background:#fff;
    border:1px solid var(--border);
    border-radius:14px;
    overflow:hidden;
    box-shadow:0 4px 14px rgba(0,0,0,.04);
    height:auto;
    min-height:0;
}

.sections-list-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:8px;
    padding:13px 14px;
    background:#fafbfe;
    border-bottom:1px solid var(--border);
}

.sections-list-head .section-list-title{
    font-family:inherit;
    font-size:12px;
    text-transform:uppercase;
    letter-spacing:.5px;
}

.section-count-label{
    font-size:11px;
    color:var(--text-muted);
    white-space:nowrap;
}

/* =========================================================
   GROUPS
========================================================= */

.year-group-title{
    padding:8px 14px;
    background:#fafafa;
    border-bottom:1px solid var(--border);
    font-size:10.5px;
    font-weight:800;
    color:var(--text-muted);
    text-transform:uppercase;
    letter-spacing:.6px;
}

.section-item{
    display:flex;
    align-items:center;
    gap:10px;
    width:100%;
    min-width:0;
    padding:12px 14px;
    text-decoration:none;
    border-bottom:1px solid var(--border);
    background:#fff;
    border-left:4px solid transparent;
    transition:.12s;
}

.section-item:hover{
    background:var(--blue-light);
}

.section-item.selected{
    background:var(--blue-light);
    border-left-color:var(--blue);
}

.section-code{
    font-size:9.5px;
    font-weight:800;
    color:var(--blue);
    background:var(--blue-light);
    padding:2px 6px;
    border-radius:4px;
    letter-spacing:.3px;
    display:inline-block;
    margin-bottom:3px;
}

.section-name{
    font-size:13.5px;
    font-weight:700;
    color:var(--text);
    white-space:normal;
    overflow:hidden;
    line-height:1.4;
    word-break:break-word;
}

.section-item.selected .section-name{
    color:var(--blue);
}

.section-meta{
    font-size:11px;
    color:var(--text-muted);
    margin-top:2px;
    white-space:normal;
    overflow:hidden;
    line-height:1.5;
    word-break:break-word;
}

.section-count{
    width:8px;
    height:8px;
    border-radius:50%;
    background:var(--success);
    flex-shrink:0;
    box-shadow:0 0 0 3px rgba(34,197,94,.08);
}

.section-empty{
    padding:50px 20px;
    text-align:center;
    color:var(--text-muted);
    font-size:13px;
}

/* =========================================================
   EMPTY DETAIL
========================================================= */

.empty-detail-card{
    flex:1;
    min-height:220px;
    background:#fff;
    border:1px solid var(--border);
    border-radius:14px;
    box-shadow:0 4px 14px rgba(0,0,0,.04);
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    text-align:center;
    padding:32px 24px;
}

.empty-detail-icon{
    width:76px;
    height:76px;
    border-radius:20px;
    background:var(--blue-light);
    color:var(--blue);
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:34px;
    margin-bottom:18px;
}

.empty-detail-title{
    font-size:20px;
    font-weight:700;
    margin-bottom:8px;
}

.empty-detail-text{
    max-width:500px;
    color:var(--text-muted);
    font-size:13px;
    line-height:1.7;
}

/* =========================================================
   DETAIL HEADER
========================================================= */

.section-detail-header{
    background:linear-gradient(
        135deg,
        var(--blue-dark),
        var(--blue)
    );
    border-radius:14px;
    padding:20px 22px;
    color:#fff;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:20px;
    box-shadow:0 8px 20px rgba(31,64,137,.12);
    min-height:0;
}

.section-detail-information{
    min-width:0;
}

.section-detail-label{
    font-size:10px;
    opacity:.65;
    text-transform:uppercase;
    letter-spacing:1.5px;
    font-weight:800;
    margin-bottom:5px;
}

.section-detail-name{
    font-family:'Source Sans 3', sans-serif;
    font-size:27px;
    font-weight:700;
    line-height:1.15;
}

.section-detail-meta{
    display:flex;
    align-items:center;
    flex-wrap:wrap;
    gap:12px;
    margin-top:9px;
    font-size:12.5px;
    opacity:.88;
}

.section-detail-meta span{
    display:flex;
    align-items:center;
    gap:6px;
}

.section-detail-description{
    font-size:12px;
    opacity:.68;
    margin-top:8px;
}

.section-detail-actions{
    display:flex;
    align-items:center;
    gap:8px;
    flex-shrink:0;
}

.detail-edit-button{
    color:#fff !important;
    border-color:rgba(255,255,255,.4) !important;
}

.detail-edit-button:hover{
    background:rgba(255,255,255,.12) !important;
}

.detail-delete-button{
    min-width:50px;
}

/* =========================================================
   SUBJECT CARD
========================================================= */

.subjects-card{
    overflow:hidden;
}

.card-subtitle{
    font-size:11px;
    color:var(--text-muted);
    margin-top:3px;
}

.semester-block{
    border-bottom:1px solid var(--border);
}

.semester-block:last-child{
    border-bottom:0;
}

.semester-header{
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:9px 20px;
    background:var(--blue-light);
    color:var(--blue);
    font-size:11px;
    font-weight:800;
    text-transform:uppercase;
    letter-spacing:.6px;
}

.semester-header small{
    color:var(--text-muted);
    font-size:10.5px;
    font-weight:500;
    text-transform:none;
    letter-spacing:0;
}

.table-responsive{
    width:100%;
    overflow-x:auto;
}

.subject-code{
    font-weight:700;
    color:var(--blue);
    font-size:13px;
}

.subject-name{
    font-size:11.5px;
    color:var(--text-muted);
    margin-top:2px;
}

.units-cell{
    text-align:center;
    font-weight:700;
}

.teacher-cell{
    display:flex;
    align-items:center;
    gap:8px;
}

.teacher-avatar{
    width:30px;
    height:30px;
    min-width:30px;
    border-radius:50%;
    background:linear-gradient(
        135deg,
        var(--blue),
        var(--blue-mid)
    );
    color:#fff;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:12px;
    font-weight:700;
}

.teacher-name{
    font-size:12.5px;
    font-weight:600;
}

.schedule-cell{
    font-size:12px;
    color:var(--text-muted);
    line-height:1.7;
}

.schedule-cell i{
    width:15px;
    color:var(--blue);
}

.not-set{
    color:var(--text-muted);
    font-size:12px;
    font-style:italic;
}

.number-cell{
    text-align:center;
    font-weight:700;
    font-size:15px;
}

.grades-cell{
    text-align:center;
}

.table-actions{
    display:flex;
    justify-content:center;
    gap:5px;
}

.subjects-empty-state{
    min-height:220px;
    padding:40px 24px;
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    text-align:center;
}

.subjects-empty-icon{
    width:70px;
    height:70px;
    border-radius:18px;
    background:var(--blue-light);
    color:var(--blue);
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:30px;
    margin-bottom:16px;
}

.subjects-empty-title{
    font-size:15px;
    font-weight:700;
    margin-bottom:7px;
}

.subjects-empty-text{
    color:var(--text-muted);
    font-size:12.5px;
    margin-bottom:18px;
}

/* =========================================================
   HOW IT WORKS
========================================================= */

.how-works-card{
    flex-shrink:0;
}

.how-grid{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:14px;
}

.how-box{
    padding:15px;
    border-radius:10px;
    font-size:12.5px;
    line-height:1.7;
    color:var(--text-muted);
}

.how-title{
    font-weight:700;
    margin-bottom:5px;
}

.blue-box{
    background:var(--blue-light);
}

.blue-box .how-title{
    color:var(--blue);
}

.green-box{
    background:#f0fdf4;
}

.green-box .how-title{
    color:var(--success);
}

.gold-box{
    background:var(--gold-light);
}

.gold-box .how-title{
    color:var(--gold-deep);
}

.red-box{
    background:#fef2f2;
}

.red-box .how-title{
    color:var(--danger);
}

/* =========================================================
   MODALS
========================================================= */

.modal-overlay{
    position:fixed;
    inset:0;
    background:rgba(0,0,0,.5);
    z-index:999;
    align-items:center;
    justify-content:center;
    padding:20px;
}

.modal-box{
    background:#fff;
    border-radius:16px;
    padding:30px;
    max-width:100%;
    max-height:90vh;
    overflow-y:auto;
    box-shadow:0 20px 60px rgba(0,0,0,.2);
}

.modal-medium{
    width:520px;
}

.modal-large{
    width:580px;
}

.modal-header{
    display:flex;
    justify-content:space-between;
    align-items:flex-start;
    gap:20px;
    margin-bottom:22px;
}

.modal-header h3{
    font-family:'Source Sans 3', sans-serif;
    font-size:20px;
    margin:0;
}

.modal-header p{
    font-size:12px;
    color:var(--text-muted);
    margin:4px 0 0;
}

.modal-close{
    background:none;
    border:none;
    font-size:24px;
    line-height:1;
    cursor:pointer;
    color:var(--text-muted);
}

.form-group{
    margin-bottom:15px;
}

.form-label{
    display:block;
    font-size:12px;
    font-weight:700;
    margin-bottom:6px;
}

.form-control{
    width:100%;
    box-sizing:border-box;
}

.form-help{
    font-size:10.5px;
    color:var(--text-muted);
    margin-top:5px;
    line-height:1.5;
}

.form-two-columns{
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:14px;
    margin-bottom:16px;
}

.modal-info{
    padding:12px 14px;
    background:var(--blue-light);
    border-radius:9px;
    font-size:12px;
    color:var(--blue);
    margin:18px 0;
}

.modal-actions{
    display:flex;
    gap:10px;
    justify-content:flex-end;
    margin-top:20px;
}

/* =========================================================
   RESPONSIVE
========================================================= */

@media(max-width:1200px){

    .section-topbar{
        grid-template-columns:auto minmax(220px,1fr) auto auto;
    }

    .sections-workspace{
        grid-template-columns:290px minmax(0,1fr);
        gap:14px;
    }

}

@media(max-width:950px){

    .section-topbar{
        grid-template-columns:1fr auto auto;
    }

    .section-heading{
        grid-column:1;
    }

    .section-search-wrap{
        grid-column:1/-1;
        grid-row:2;
    }

    .sections-workspace{
        grid-template-columns:1fr;
    }

    .sections-list-column{
        order:2;
    }

    .sections-detail-column{
        order:1;
    }

    .sections-list-card{
        height:auto;
    }

    .empty-detail-card{
        min-height:400px;
    }

}

@media(max-width:700px){

    .section-topbar{
        grid-template-columns:1fr auto;
        padding:12px;
    }

    .section-heading{
        grid-column:1;
    }

    .section-filter-button{
        grid-column:2;
        grid-row:1;
    }

    .section-topbar > .btn{
        grid-column:1/-1;
        grid-row:3;
        justify-self:start;
    }

    .course-nav-container,
    .year-navigation-container{
        align-items:flex-start;
        flex-direction:column;
        gap:6px;
        padding:10px;
    }

    .nav-label{
        min-width:auto;
    }

    .course-nav-bar,
    .year-nav-bar{
        width:100%;
    }

    .section-filter-panel{
        grid-template-columns:1fr;
    }

    .section-filter-panel .btn{
        width:100%;
    }

    .section-detail-header{
        flex-direction:column;
        align-items:flex-start;
        padding:20px;
    }

    .section-detail-actions{
        width:100%;
    }

    .section-detail-actions form{
        display:block;
    }

    .detail-edit-button{
        flex:1;
    }

    .detail-delete-button{
        width:48px;
    }

    .how-grid{
        grid-template-columns:1fr;
    }

    .form-two-columns{
        grid-template-columns:1fr;
    }

}

@media(max-width:500px){

    .section-list-title{
        font-size:18px;
    }

    .section-detail-name{
        font-size:23px;
    }

    .modal-box{
        padding:22px;
    }

    .section-detail-meta{
        flex-direction:column;
        align-items:flex-start;
        gap:5px;
    }

}

</style>


<script>

/* =========================================================
   MODALS
========================================================= */

function openModal(id){

    const modal = document.getElementById(id);

    if(!modal) return;

    modal.style.display = 'flex';

    document.body.style.overflow = 'hidden';
}

function closeModal(id){

    const modal = document.getElementById(id);

    if(!modal) return;

    modal.style.display = 'none';

    document.body.style.overflow = '';
}


/* =========================================================
   EDIT SECTION
========================================================= */

function openEditSection(section){

    const id   = document.getElementById('es_id');
    const name = document.getElementById('es_name');
    const desc = document.getElementById('es_desc');
    const prog = document.getElementById('es_prog');
    const ay   = document.getElementById('es_ay');

    if(id)   id.value   = section.sectionID || '';
    if(name) name.value = section.sectionName || '';
    if(desc) desc.value = section.sectionDesc || '';
    if(prog) prog.value = section.pID || section.programID || '';
    if(ay)   ay.value   = section.ayID || section.acadYearID || '';

    openModal('editSectionModal');
}


/* =========================================================
   EDIT SUBJECT
========================================================= */

function openEditSubjectModal(subject){

    const id      = document.getElementById('esub_id');
    const teacher = document.getElementById('esub_teacher');
    const sched   = document.getElementById('esub_sched');
    const room    = document.getElementById('esub_room');
    const info    = document.getElementById('esub_info');

    if(id){
        id.value = subject.secSubID || '';
    }

    if(teacher){
        filterSubjectTeachers(
            teacher,
            Number(subject.subjectProgramID || 0),
            Number(<?= (int)($section['programID'] ?? 0) ?>),
            Number(subject.teacherID || 0)
        );
        teacher.value = subject.teacherID || '';
        if(teacher.options[teacher.selectedIndex]?.disabled){
            teacher.value = '';
        }
    }

    if(sched){
        sched.value = subject.schedule || '';
    }

    if(room){
        room.value = subject.room || '';
    }

    if(info){

        const subjectCode =
            escapeHtml(subject.subjectCode || '');

        const subjectName =
            escapeHtml(subject.subjectName || '');

        const semester =
            Number(subject.semester) === 1
                ? '1st Semester'
                : '2nd Semester';

        info.innerHTML =
            '<strong>Subject:</strong> ' +
            subjectCode +
            ' – ' +
            subjectName +
            ' &bull; <strong>Semester:</strong> ' +
            semester;
    }

    openModal('editSubjectModal');
}


function filterSubjectTeachers(select, subjectProgramID, sectionProgramID, currentTeacherID){

    if(!select) return;

    const isMinorSubject = subjectProgramID === 0;

    Array.from(select.options).forEach(function(option){

        if(!option.value) return;

        const teacherProgramID = Number(option.dataset.program || 0);
        const matchesSectionProgram = teacherProgramID === sectionProgramID;
        const allowed = isMinorSubject || matchesSectionProgram;

        option.hidden = !allowed;
        option.style.display = allowed ? '' : 'none';
        option.disabled = !allowed;
    });
}


/* =========================================================
   CLOSE MODALS WHEN CLICKING BACKDROP
========================================================= */

[
    'addSectionModal',
    'editSectionModal',
    'addSubjectModal',
    'editSubjectModal'
].forEach(function(id){

    const modal = document.getElementById(id);

    if(!modal) return;

    modal.addEventListener(
        'click',
        function(event){

            if(event.target === modal){
                closeModal(id);
            }

        }
    );

});


/* =========================================================
   ESCAPE KEY
========================================================= */

document.addEventListener(
    'keydown',
    function(event){

        if(event.key !== 'Escape') return;

        [
            'addSectionModal',
            'editSectionModal',
            'addSubjectModal',
            'editSubjectModal'
        ].forEach(function(id){

            const modal = document.getElementById(id);

            if(
                modal &&
                modal.style.display === 'flex'
            ){
                closeModal(id);
            }

        });

    }
);


/* =========================================================
   SUBJECT -> SEMESTER AUTO SELECT
========================================================= */

document
    .querySelector(
        '#addSubjectModal select[name="subjectID"]'
    )
    ?.addEventListener(
        'change',
        function(){

            const selected =
                this.options[this.selectedIndex];

            const sem =
                selected?.getAttribute('data-sem');

            if(sem){

                const semester =
                    document.getElementById(
                        'asMod_semester'
                    );

                if(semester){
                    semester.value = sem;
                }

            }

        }
    );

document
    .querySelector('#asMod_subject')
    ?.addEventListener('change', function(){

        const selected = this.options[this.selectedIndex];
        const teacher = document.getElementById('asMod_teacher');

        filterSubjectTeachers(
            teacher,
            Number(selected?.dataset.program || 0),
            Number(<?= (int)($section['programID'] ?? 0) ?>),
            0
        );

        if(teacher && teacher.options[teacher.selectedIndex]?.disabled){
            teacher.value = '';
        }
    });

filterSubjectTeachers(
    document.getElementById('asMod_teacher'),
    -1,
    Number(<?= (int)($section['programID'] ?? 0) ?>),
    0
);


/* =========================================================
   ALL SECTIONS DATA
========================================================= */

let allSections = [

<?php foreach ($allSections as $i => $sec): ?>

    {
        ID: <?= (int)$sec['sectionID'] ?>,
        name: <?= json_encode($sec['sectionName']) ?>,
        code: <?= json_encode($sec['programCode']) ?>,
        program: <?= json_encode($sec['programName']) ?>,
        programID: <?= (int)$sec['pID'] ?>,
        ay: <?= json_encode($sec['academicYear']) ?>,
        ayID: <?= (int)$sec['acadYearID'] ?>,
        yearLevel: <?= (int)$sec['yearLevel'] ?>,
        isActive: <?= $sec['ayStatus'] === 'Active' ? 'true' : 'false' ?>,
        subjectCount: <?= (int)$sec['subjectCount'] ?>,
        studentCount: <?= (int)$sec['studentCount'] ?>
    }<?= $i < count($allSections) - 1 ? ',' : '' ?>

<?php endforeach; ?>

];


/* =========================================================
   CURRENT FILTER STATE
========================================================= */

let selectedCourse = 'all';
let selectedYear   = 'all';

const currentSelectedID =
    <?= (int)$selectedID ?>;


/* =========================================================
   INITIAL SELECTED SECTION
========================================================= */

const selectedSection =
    allSections.find(
        function(sec){
            return Number(sec.ID) === currentSelectedID;
        }
    );

if(selectedSection){

    selectedCourse =
        String(selectedSection.programID);

    selectedYear =
        String(selectedSection.yearLevel);

}


/* =========================================================
   TOGGLE FILTERS
========================================================= */

function toggleSectionFilters(){

    const panel =
        document.getElementById(
            'sectionFilterPanel'
        );

    const button =
        document.getElementById(
            'sectionFilterButton'
        );

    if(!panel) return;

    const isClosed =
        panel.style.display === 'none';

    panel.style.display =
        isClosed ? 'grid' : 'none';

    if(button){
        button.classList.toggle(
            'active',
            isClosed
        );
    }

}


/* =========================================================
   RESET FILTERS
========================================================= */

function resetSectionFilters(){

    const search =
        document.getElementById(
            'sectionSearch'
        );

    const yearFilter =
        document.getElementById(
            'yearLevelFilter'
        );

    const ayFilter =
        document.getElementById(
            'acadYearFilter'
        );

    if(search){
        search.value = '';
    }

    if(yearFilter){
        yearFilter.value = '';
    }

    const activeAY =
        '<?= $activeAY ? (int)$activeAY['acadYearID'] : 0 ?>';

    if(ayFilter){

        ayFilter.value =
            activeAY
                ? String(activeAY)
                : 'all';

    }

    selectedCourse = 'all';
    selectedYear   = 'all';

    renderCourseNav();
    renderSections();

}


/* =========================================================
   GET COURSES
========================================================= */

function getCourses(){

    const map = new Map();

    allSections.forEach(
        function(sec){

            const key =
                String(sec.programID);

            if(!map.has(key)){

                map.set(
                    key,
                    {
                        id: sec.programID,
                        code: sec.code,
                        name: sec.program
                    }
                );

            }

        }
    );

    return Array.from(map.values()).sort(
        function(a,b){

            return String(a.code)
                .localeCompare(
                    String(b.code)
                );

        }
    );

}


/* =========================================================
   RENDER COURSE NAV
========================================================= */

function renderCourseNav(){

    const nav =
        document.getElementById(
            'courseNav'
        );

    if(!nav) return;

    let html = '';

    html +=
        '<button ' +
        'type="button" ' +
        'class="course-tab ' +
        (
            selectedCourse === 'all'
                ? 'active'
                : ''
        ) +
        '" ' +
        'onclick="selectCourse(\'all\')">' +
        'All Courses' +
        '</button>';

    getCourses().forEach(
        function(course){

            const active =
                String(selectedCourse) ===
                String(course.id);

            html +=
                '<button ' +
                'type="button" ' +
                'class="course-tab ' +
                (
                    active
                        ? 'active'
                        : ''
                ) +
                '" ' +
                'onclick="selectCourse(' +
                Number(course.id) +
                ')">' +
                escapeHtml(course.code) +
                '</button>';

        }
    );

    nav.innerHTML = html;

}


/* =========================================================
   GET YEARS FOR SELECTED COURSE

   IMPORTANT (FIXED):
   Previously, year tabs (1st–4th Year) only appeared
   if a section already existed for that year level.
   This caused different courses (e.g., BSIT vs BSBA)
   to show a different, inconsistent set of year tabs.

   Now, the standard year levels 1–4 are ALWAYS included
   for every course, so all courses display the same
   1st, 2nd, 3rd, and 4th Year tabs regardless of whether
   sections already exist for those years. Any additional
   year levels actually found in the data (e.g., 5th Year,
   or "Others" for unrecognized names) are still added on
   top of the standard set.
========================================================= */

function getAvailableYearsForCourse(){

    const source =
        selectedCourse === 'all'
            ? allSections
            : allSections.filter(
                function(sec){
                    return String(sec.programID) ===
                           String(selectedCourse);
                }
            );

    /*
     * Always guarantee 1st–4th Year tabs for every course.
     */
    const years = new Set(['1', '2', '3', '4']);

    source.forEach(
        function(sec){

            years.add(
                String(sec.yearLevel)
            );

        }
    );

    return years;

}


/* =========================================================
   RENDER YEAR NAV
========================================================= */

function renderYearNav(){

    const nav =
        document.getElementById(
            'yearNav'
        );

    if(!nav) return;

    /*
     * IMPORTANT:
     * Year navigation stays visible even
     * when "All Courses" is selected.
     */

    nav.style.display = 'flex';

    const available =
        getAvailableYearsForCourse();

    let html = '';

    html +=
        '<button ' +
        'type="button" ' +
        'class="year-tab ' +
        (
            selectedYear === 'all'
                ? 'active'
                : ''
        ) +
        '" ' +
        'onclick="selectYear(\'all\')">' +
        'All Years' +
        '</button>';

    [1,2,3,4,5,0].forEach(
        function(year){

            if(
                !available.has(
                    String(year)
                )
            ){
                return;
            }

            const active =
                String(selectedYear) ===
                String(year);

            html +=
                '<button ' +
                'type="button" ' +
                'class="year-tab ' +
                (
                    active
                        ? 'active'
                        : ''
                ) +
                '" ' +
                'onclick="selectYear(' +
                year +
                ')">' +
                yearLabel(year) +
                '</button>';

        }
    );

    nav.innerHTML = html;

}


/* =========================================================
   SELECT COURSE
========================================================= */

function selectCourse(id){

    selectedCourse =
        String(id);

    /*
     * Reset year when changing course.
     * This makes sure we don't retain
     * a year that doesn't exist in the
     * newly selected course.
     */
    selectedYear = 'all';

    renderCourseNav();
    renderYearNav();
    renderSections();

}


/* =========================================================
   SELECT YEAR
========================================================= */

function selectYear(year){

    selectedYear =
        String(year);

    /*
     * Do NOT rebuild course nav.
     * It stays visible and selected.
     */

    renderYearNav();
    renderSections();

}


/* =========================================================
   ESCAPE HTML
========================================================= */

function escapeHtml(value){

    return String(
        value ?? ''
    ).replace(
        /[&<>'"]/g,
        function(char){

            return {
                '&':'&amp;',
                '<':'&lt;',
                '>':'&gt;',
                "'":'&#039;',
                '"':'&quot;'
            }[char];

        }
    );

}


/* =========================================================
   YEAR LABEL
========================================================= */

function yearLabel(year){

    return {
        1:'1st Year',
        2:'2nd Year',
        3:'3rd Year',
        4:'4th Year',
        5:'5th Year',
        0:'Others'
    }[year] || 'Others';

}

function extractYearLevelFromName(name){
    const text = String(name || '').trim();
    const match = text.match(/(?:^|[\s\-_–])([1-5])\s*(?:st|nd|rd|th)?\s*(?:year|yr)?(?:[\s\-_–]|$)/i)
        || text.match(/\b(?:year|yr)\s*[-\s]*([1-5])\b/i)
        || text.match(/(?:^|[\s\-_–A-Za-z])([1-5])(?=[A-Za-z](?:[\s\-_–]|$)|[\s\-_–])/i)
        || text.match(/(?:^|[\s\-_–])([1-5])(?:\D|$)/i);
    return match ? Number(match[1]) : 0;
}

async function refreshSectionsFromServer(){
    try {
        const response = await fetch('sections.php?list=1', { cache: 'no-store' });
        const data = await response.json();
        if (!data || !data.success || !Array.isArray(data.sections)) return;

        allSections = data.sections.map(function(sec){
            const yearLevel = extractYearLevelFromName(sec.sectionName || sec.name || '');
            return {
                ID: Number(sec.sectionID),
                name: sec.sectionName || '',
                code: sec.programCode || '',
                program: sec.programName || '',
                programID: Number(sec.pID || sec.programID || 0),
                ay: sec.academicYear || '',
                ayID: Number(sec.acadYearID || 0),
                yearLevel: yearLevel || 0,
                isActive: (sec.ayStatus || '') === 'Active',
                subjectCount: Number(sec.subjectCount || 0),
                studentCount: Number(sec.studentCount || 0)
            };
        });
        renderCourseNav();
        renderYearNav();
        renderSections();
    } catch (e) {
        console.warn('Refresh failed:', e);
    }
}

/* =========================================================
   RENDER SECTIONS
========================================================= */

function renderSections(){

    const list =
        document.getElementById(
            'sectionList'
        );

    if(!list) return;

    const search =
        (
            document.getElementById(
                'sectionSearch'
            )?.value || ''
        )
        .trim()
        .toLowerCase();

    const yearFilter =
        document.getElementById(
            'yearLevelFilter'
        )?.value || '';

    const ayFilter =
        document.getElementById(
            'acadYearFilter'
        )?.value || 'all';

    /*
     * IMPORTANT:
     * selectedYear is applied only to the
     * actual section list.
     *
     * It is NOT used to calculate year tabs.
     */

    let filtered =
        allSections.filter(
            function(sec){

                const courseMatch =
                    selectedCourse === 'all' ||
                    String(sec.programID) ===
                    String(selectedCourse);

                const yearTabMatch =
                    selectedYear === 'all' ||
                    String(sec.yearLevel) ===
                    String(selectedYear);

                const searchMatch =
                    !search ||
                    String(sec.name)
                        .toLowerCase()
                        .includes(search) ||
                    String(sec.code)
                        .toLowerCase()
                        .includes(search) ||
                    String(sec.program)
                        .toLowerCase()
                        .includes(search) ||
                    String(sec.ay)
                        .toLowerCase()
                        .includes(search);

                const yearMatch =
                    !yearFilter ||
                    String(sec.yearLevel) ===
                    String(yearFilter);

                const ayMatch =
                    ayFilter === 'all' ||
                    String(sec.ayID) ===
                    String(ayFilter);

                return (
                    courseMatch &&
                    yearTabMatch &&
                    searchMatch &&
                    yearMatch &&
                    ayMatch
                );

            }
        );


    /*
     * Sorting:
     * Recognized year levels first.
     * Others last.
     */

    filtered.sort(
        function(a,b){

            if(
                Number(a.yearLevel) !==
                Number(b.yearLevel)
            ){

                if(
                    Number(a.yearLevel) === 0
                ){
                    return 1;
                }

                if(
                    Number(b.yearLevel) === 0
                ){
                    return -1;
                }

                return (
                    Number(a.yearLevel) -
                    Number(b.yearLevel)
                );

            }

            return String(a.name)
                .localeCompare(
                    String(b.name),
                    undefined,
                    {
                        numeric:true,
                        sensitivity:'base'
                    }
                );

        }
    );


    /*
     * ALWAYS render year navigation.
     */
    renderYearNav();


    const courses =
        getCourses();

    const selectedCourseObj =
        courses.find(
            function(course){

                return String(course.id) ===
                       String(selectedCourse);

            }
        );

    const title =
        selectedCourse === 'all'
            ? 'All Courses'
            : (
                selectedCourseObj
                    ? selectedCourseObj.code
                    : 'Course'
            );

    const yearTitle =
        selectedYear === 'all'
            ? 'All Years'
            : yearLabel(
                Number(selectedYear)
            );


    const titleElement =
        document.getElementById(
            'sectionsListTitle'
        );

    const countElement =
        document.getElementById(
            'sectionsListCount'
        );


    if(titleElement){

        titleElement.textContent =
            title +
            ' · ' +
            yearTitle;

    }


    if(countElement){

        countElement.textContent =
            filtered.length +
            ' section' +
            (
                filtered.length === 1
                    ? ''
                    : 's'
            );

    }


    if(!filtered.length){

        list.innerHTML =
            '<div class="section-empty">' +
                '<i class="fas fa-search" ' +
                'style="font-size:28px;display:block;margin-bottom:10px;opacity:.25;"></i>' +
                '<strong>No sections found</strong>' +
                '<div style="margin-top:5px;font-size:11px;">' +
                    'Try another course, year level, academic year or search term.' +
                '</div>' +
            '</div>';

        return;

    }


    /*
     * Group sections by year.
     *
     * This makes sure:
     *
     * BSIT
     *   1st Year
     *     BSIT-1A
     *     BSIT-1B
     *   2nd Year
     *     ...
     *   3rd Year
     *     BSIT-3B
     *   Others
     *     ...
     */

    const groups = {};

    filtered.forEach(
        function(sec){

            const key =
                String(sec.yearLevel);

            if(!groups[key]){
                groups[key] = [];
            }

            groups[key].push(sec);

        }
    );


    let html = '';

    [1,2,3,4,5,0].forEach(
        function(year){

            const rows =
                groups[String(year)];

            if(!rows || !rows.length){
                return;
            }

            /*
             * When a specific year is selected,
             * don't show redundant year heading.
             */

            if(selectedYear === 'all'){

                html +=
                    '<div class="year-group-title">' +
                        yearLabel(year) +
                    '</div>';

            }


            rows.forEach(
                function(sec){

                    const selected =
                        currentSelectedID ===
                        Number(sec.ID);

                    html +=
                        '<a ' +
                        'class="section-item ' +
                        (
                            selected
                                ? 'selected'
                                : ''
                        ) +
                        '" ' +
                        'href="sections.php?section_id=' +
                        encodeURIComponent(sec.ID) +
                        '">';

                    html +=
                        '<div style="flex:1;min-width:0;">';

                    html +=
                        '<div class="section-code">' +
                            escapeHtml(sec.code) +
                        '</div>';

                    html +=
                        '<div class="section-name">' +
                            escapeHtml(sec.name) +
                        '</div>';

                    html +=
                        '<div class="section-meta">' +
                            escapeHtml(
                                String(sec.subjectCount)
                            ) +
                            ' subject(s) &bull; ' +
                            escapeHtml(
                                String(sec.studentCount)
                            ) +
                            ' student(s) &bull; ' +
                            escapeHtml(sec.ay) +
                        '</div>';

                    html +=
                        '</div>';

                    if(sec.isActive){

                        html +=
                            '<span ' +
                            'class="section-count" ' +
                            'title="Active Academic Year">' +
                            '</span>';

                    }

                    html +=
                        '</a>';

                }
            );

        }
    );


    list.innerHTML = html;

}


/* =========================================================
   INITIAL RENDER
========================================================= */

renderCourseNav();
renderYearNav();
renderSections();

document.addEventListener('visibilitychange', function(){
    if (document.visibilityState === 'visible') {
        refreshSectionsFromServer();
    }
});

if (typeof(EventSource) !== 'undefined') {
    try {
        const sectionStream = new EventSource('<?= BASE_URL ?>modules/admin/sections_sse.php');
        sectionStream.onmessage = function() {
            refreshSectionsFromServer();
        };
        sectionStream.onerror = function() {
            try { sectionStream.close(); } catch (e) {}
        };
    } catch (err) {
        console.warn('Section SSE unavailable:', err);
    }
}

</script>

<?php require_once ROOT . '/includes/footer.php'; ?>