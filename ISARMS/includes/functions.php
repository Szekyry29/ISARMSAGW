<?php
/**
 * ISARMS - Core Helper Functions
 */

require_once __DIR__ . '/config.php';

// ─── Session Management ───────────────────────────────────────
function startSecureSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $isLocalHost = preg_match('/^(localhost|127\.0\.0\.1|\[::1\]|192\.168\.|10\.|172\.(1[6-9]|2\d|3[01]))/i', $host) === 1;
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                  || ($_SERVER['SERVER_PORT'] ?? '') === '443';

        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => $isLocalHost ? '' : $host,
            'secure'   => $secure && !$isLocalHost,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function isLoggedIn(): bool {
    startSecureSession();
    if (!isset($_SESSION['user_id'], $_SESSION['last_activity'])) return false;
    if ((time() - $_SESSION['last_activity']) > SESSION_TIMEOUT) {
        session_unset();
        session_destroy();
        return false;
    }
    $_SESSION['last_activity'] = time();
    return true;
}

function requireAuth(string ...$roles): void {
    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . 'index.php?timeout=1');
        exit;
    }
    if (!empty($roles) && !in_array($_SESSION['role'], $roles, true)) {
        header('Location: ' . BASE_URL . 'unauthorized.php');
        exit;
    }
}

function getCurrentUser(): ?array {
    if (!isLoggedIn()) return null;
    return [
        'id'    => $_SESSION['user_id'],
        'name'  => $_SESSION['full_name'],
        'role'  => $_SESSION['role'],
        'email' => $_SESSION['email'],
        'pic'   => $_SESSION['profile_pic'] ?? null,
    ];
}

function gradeStatusValues(): array {
    static $values = null;
    if ($values !== null) {
        return $values;
    }

    $values = ['Approved'];
    $db = Database::getInstance();
    try {
        $column = $db->fetchOne("SHOW COLUMNS FROM grades LIKE 'status'");
        $type = strtolower((string)($column['Type'] ?? ''));
        if (strpos($type, 'posted') !== false) {
            $values = ['Posted', 'Approved'];
        }
    } catch (Throwable $e) {
        $values = ['Approved'];
    }

    return $values;
}

function postedGradeStatus(): string {
    $values = gradeStatusValues();
    return $values[0] ?? 'Approved';
}

function officialGradeFilter(string $column = 'g.status'): string {
    $values = gradeStatusValues();
    $alias = substr($column, 0, strrpos($column, '.'));
    return '(' . $column . " IN ('" . implode("','", $values) . "') OR ("
        . $column . "='Pending' AND " . $alias . ".submittedAt IS NOT NULL AND "
        . $alias . ".computedGrade IS NOT NULL AND " . $alias . ".gradeEquivalent IS NOT NULL))";
}

function gwaIncludedSubjectFilter(string $enrollmentAlias = 'e'): string {
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $enrollmentAlias)) {
        throw new InvalidArgumentException('Invalid enrollment alias.');
    }
    return "COALESCE((SELECT gwaRule.includeInGwa FROM subject_gwa_rules gwaRule
                     WHERE gwaRule.acadYearID={$enrollmentAlias}.acadYearID
                       AND gwaRule.subjectID={$enrollmentAlias}.subjectID), 1) = 1";
}

// ─── GWA Computation ─────────────────────────────────────────
/**
 * Compute GWA using weighted formula: Σ(grade_equivalent × units) / Σunits
 * Posted grades are released for viewing, but a semester contributes to
 * GWA only after every active subject in that semester has a Posted grade.
 *
 * @param  int       $studentID
 * @param  int|null  $acadYearID  null = cumulative all years
 * @param  int|null  $semester    null = both semesters
 * @return array ['gwa' => float, 'totalUnits' => float, 'weightedSum' => float, 'grades' => array]
 */
function computeGWA(int $studentID, ?int $acadYearID = null, ?int $semester = null): array {
    $db  = Database::getInstance();

        $sql = "SELECT g.gradeEquivalent, s.units, g.gradeID
                        FROM grades g
                        JOIN enrollments e ON e.enrollmentID = g.enrollmentID
                        JOIN subjects s    ON s.subjectID = g.subjectID
                        WHERE e.studentID = :sid
                            AND " . officialGradeFilter('g.status') . "
                            AND g.dateDeleted IS NULL
                            AND e.status = 'Active'
                            AND e.dateDeleted IS NULL
                                AND " . gwaIncludedSubjectFilter('e') . "
                            AND NOT EXISTS (
                                    SELECT 1
                                    FROM enrollments missing
                                    WHERE missing.studentID = e.studentID
                                        AND missing.acadYearID = e.acadYearID
                                        AND missing.semester = e.semester
                                        AND missing.status = 'Active'
                                        AND missing.dateDeleted IS NULL
                                        AND " . gwaIncludedSubjectFilter('missing') . "
                                        AND NOT EXISTS (
                                                SELECT 1
                                                FROM grades missingGrade
                                                WHERE missingGrade.enrollmentID = missing.enrollmentID
                                                    AND " . officialGradeFilter('missingGrade.status') . "
                                                    AND missingGrade.dateDeleted IS NULL
                                        )
                            )";
    $params = [':sid' => $studentID];

    if ($acadYearID !== null) {
        $sql .= " AND e.acadYearID = :ayi";
        $params[':ayi'] = $acadYearID;
    }
    if ($semester !== null) {
        $sql .= " AND e.semester = :sem";
        $params[':sem'] = $semester;
    }

    $rows = $db->fetchAll($sql, $params);

    $totalUnits  = 0.0;
    $weightedSum = 0.0;
    foreach ($rows as $row) {
        if ($row['gradeEquivalent'] !== null) {
            $totalUnits  += (float)$row['units'];
            $weightedSum += (float)$row['gradeEquivalent'] * (float)$row['units'];
        }
    }

    $gwa = ($totalUnits > 0) ? round($weightedSum / $totalUnits, 4) : 0.0000;

    return [
        'gwa'         => $gwa,
        'totalUnits'  => $totalUnits,
        'weightedSum' => $weightedSum,
        'grades'      => $rows,
        'count'       => count($rows),
    ];
}

function getRegisteredUnits(int $studentID, ?int $acadYearID = null, ?int $semester = null): float {
    $db = Database::getInstance();
    $sql = "SELECT COALESCE(SUM(sub.units), 0) AS totalUnits
            FROM enrollments e
            JOIN subjects sub ON sub.subjectID = e.subjectID
            WHERE e.studentID = :sid
              AND e.status = 'Active'
              AND e.dateDeleted IS NULL
              AND sub.dateDeleted IS NULL";
    $params = [':sid' => $studentID];
    if ($acadYearID !== null) {
        $sql .= " AND e.acadYearID = :ay";
        $params[':ay'] = $acadYearID;
    }
    if ($semester !== null) {
        $sql .= " AND e.semester = :sem";
        $params[':sem'] = $semester;
    }
    return (float)($db->fetchOne($sql, $params)['totalUnits'] ?? 0);
}

function getLatestEnrollmentPeriod(int $studentID): ?array {
    $db = Database::getInstance();
    return $db->fetchOne(
        "SELECT e.acadYearID, e.semester, ay.academicYear
         FROM enrollments e
         JOIN acad_years ay ON ay.acadYearID=e.acadYearID
         WHERE e.studentID=:sid AND e.status='Active' AND e.dateDeleted IS NULL
         ORDER BY (ay.status='Active') DESC, e.acadYearID DESC, e.semester DESC
         LIMIT 1",
        [':sid'=>$studentID]
    ) ?: null;
}

/** Create or refresh a semester GWA record only when all active subjects are graded. */
function syncCompletedGWARecord(int $studentID, int $acadYearID, int $semester): bool {
    $db = Database::getInstance();
    $active = $db->fetchOne(
        "SELECT COUNT(*) AS total FROM enrollments
         WHERE studentID=:sid AND acadYearID=:ay AND semester=:sem
                     AND status='Active' AND dateDeleted IS NULL
                     AND " . gwaIncludedSubjectFilter('enrollments') . "",
        [':sid'=>$studentID, ':ay'=>$acadYearID, ':sem'=>$semester]
    );
    $result = computeGWA($studentID, $acadYearID, $semester);
    if ((int)($active['total'] ?? 0) === 0 || $result['count'] !== (int)$active['total']) return false;

    $honor = classifyLatinHonor($result['gwa'], $studentID, $acadYearID, $semester);
    $existing = $db->fetchOne(
        "SELECT gwaID FROM gwa_records WHERE studentID=:sid AND acadYearID=:ay AND semester=:sem",
        [':sid'=>$studentID, ':ay'=>$acadYearID, ':sem'=>$semester]
    );
    $values = [':gwa'=>$result['gwa'], ':tu'=>$result['totalUnits'], ':ws'=>$result['weightedSum'], ':honor'=>$honor];
    if ($existing) {
        $db->query("UPDATE gwa_records SET gwaValue=:gwa,totalUnits=:tu,weightedSum=:ws,latinHonor=:honor,validationStatus='Pending',computedAt=NOW() WHERE gwaID=:id",
            $values + [':id'=>$existing['gwaID']]);
    } else {
        $db->insert("INSERT INTO gwa_records (studentID,acadYearID,semester,totalUnits,weightedSum,gwaValue,latinHonor) VALUES (:sid,:ay,:sem,:tu,:ws,:gwa,:honor)",
            $values + [':sid'=>$studentID, ':ay'=>$acadYearID, ':sem'=>$semester]);
    }
    return true;
}

/**
 * Classify Latin Honor based on GWA, grade constraints, and year level.
 * Years 1-3: Dean's List only.
 * Year 4 graduates: evaluate against the full cumulative GWA and rank them in the 4th-year group.
 */
function classifyLatinHonor(float $gwa, int $studentID, ?int $acadYearID = null, ?int $semester = null): string {
    if ($gwa <= 0) return 'None';

    $db = Database::getInstance();

    // Get student's year level and graduation status.
    $student = $db->fetchOne("SELECT yearLevel, isGraduated FROM students WHERE studentID=:id", [':id'=>$studentID]);
    $yearLevel = (int)($student['yearLevel'] ?? 0);
    $isGraduated = ((int)($student['isGraduated'] ?? 0) === 1);
    $effectiveYearLevel = ($isGraduated && $yearLevel >= 4) ? 4 : $yearLevel;

    // Check no grade below the threshold (2.00) for honors eligibility
    $sql = "SELECT COUNT(*) as cnt
            FROM grades g
            JOIN enrollments e ON e.enrollmentID = g.enrollmentID
            WHERE g.studentID = :sid
              AND " . officialGradeFilter('g.status') . "
              AND g.gradeEquivalent > :threshold
              AND g.dateDeleted IS NULL";
    $params = [':sid' => $studentID, ':threshold' => HONORS_NO_BELOW];
    if ($acadYearID) {
        $sql .= " AND e.acadYearID = :ayi";
        $params[':ayi'] = $acadYearID;
    }
    if ($semester !== null) {
        $sql .= " AND e.semester = :sem";
        $params[':sem'] = $semester;
    }

    $result  = $db->fetchOne($sql, $params);
    $hasLow  = (int)($result['cnt'] ?? 0) > 0;

    // Years 1-3: Only Dean's List eligible.
    if ($effectiveYearLevel < 4) {
        if ($hasLow) return 'None';
        if ($gwa >= DEAN_MIN && $gwa <= DEAN_MAX) return "Dean's List";
        return 'None';
    }

    // Year 4: Full classification but with GWA >= 2.25 restriction.
    if ($hasLow) {
        return 'None';
    }

    // For year 4: if GWA >= 2.25, only Dean's List (not Cum Laude+)
    if ($gwa >= YEAR4_HIGH_HONORS_MAX) {
        if ($gwa >= DEAN_MIN && $gwa <= DEAN_MAX) return "Dean's List";
        return 'None';
    }

    // Year 4 with GWA < 2.25: Can be Cum Laude to Summa Cum Laude
    if ($gwa >= SUMMA_MIN && $gwa <= SUMMA_MAX) return 'Summa Cum Laude';
    if ($gwa >= MAGNA_MIN && $gwa <= MAGNA_MAX) return 'Magna Cum Laude';
    if ($gwa >= CUMLAUD_MIN && $gwa <= CUMLAUD_MAX) return 'Cum Laude';

    return 'None';
}

/**
 * Return a student's rank among their year level based on cumulative approved GWA.
 * Students with no approved grades are excluded from the ranking.
 */
function studentAcademicRecordIsComplete(int $studentID, ?int $acadYearID = null, ?int $semester = null): bool {
    $db = Database::getInstance();

    $activeCount = (int)($db->fetchOne(
        "SELECT COUNT(*) AS c FROM enrollments WHERE studentID=:sid AND status='Active' AND dateDeleted IS NULL",
        [':sid' => $studentID]
    )['c'] ?? 0);
    if ($activeCount === 0) {
        return false;
    }

    $sql = "SELECT COUNT(*) AS c
            FROM enrollments e
            WHERE e.studentID = :sid
              AND e.status = 'Active'
              AND e.dateDeleted IS NULL";
    $params = [':sid' => $studentID];

    if ($acadYearID !== null) {
        $sql .= " AND e.acadYearID = :ay";
        $params[':ay'] = $acadYearID;
    }
    if ($semester !== null) {
        $sql .= " AND e.semester = :sem";
        $params[':sem'] = $semester;
    }

    $sql .= " AND NOT EXISTS (
                SELECT 1
                FROM grades g
                WHERE g.enrollmentID = e.enrollmentID
                  AND g.dateDeleted IS NULL
                  AND g.gradeEquivalent IS NOT NULL
                  AND g.status IN ('Posted', 'Approved')
            )";

    $row = $db->fetchOne($sql, $params);
    return ((int)($row['c'] ?? 0) === 0);
}

function getYearLevelRank(int $studentID, int $yearLevel): ?int {
    $db = Database::getInstance();

    $student = $db->fetchOne("SELECT yearLevel FROM students WHERE studentID=:id AND dateDeleted IS NULL", [':id' => $studentID]);
    if (!$student) {
        return null;
    }

    $activeAY = $db->fetchOne("SELECT acadYearID FROM acad_years WHERE status='Active' LIMIT 1");
    if ($activeAY && !studentAcademicRecordIsComplete($studentID, (int)$activeAY['acadYearID'])) {
        return null;
    }

    $cumulative = computeGWA($studentID);
    if ((float)$cumulative['gwa'] <= 0) {
        return null;
    }

    $rows = $db->fetchAll(
        "SELECT s.studentID,
                ROUND(SUM(g.gradeEquivalent * sub.units) / SUM(sub.units), 4) AS gwa
         FROM students s
         JOIN enrollments e ON e.studentID = s.studentID AND e.dateDeleted IS NULL AND e.status='Active'
         JOIN grades g ON g.enrollmentID = e.enrollmentID AND g.gradeEquivalent IS NOT NULL AND g.dateDeleted IS NULL
         JOIN subjects sub ON sub.subjectID = g.subjectID
         WHERE s.yearLevel = :yl
           AND s.dateDeleted IS NULL
                     AND " . gwaIncludedSubjectFilter('e') . "
         GROUP BY s.studentID
         HAVING SUM(sub.units) > 0
         ORDER BY gwa ASC, s.lName, s.fName",
        [':yl' => $yearLevel]
    );

    $rank = 1;
    foreach ($rows as $row) {
        if ((int)$row['studentID'] === $studentID) {
            return $rank;
        }
        $rank++;
    }

    return null;
}

/**
 * Get student's rank and honors classification for display
 * Returns: ['rank' => int, 'total' => int, 'gwa' => float, 'honor' => string]
 */
function getStudentHonorsDisplay(int $studentID): array {
    $db = Database::getInstance();

    $student = $db->fetchOne(
        "SELECT yearLevel FROM students WHERE studentID=:id",
        [':id'=>$studentID]
    );

    if (!$student || !studentAcademicRecordIsComplete($studentID)) {
        return ['rank'=>null, 'total'=>0, 'gwa'=>0, 'honor'=>'None'];
    }

    $yearLevel = (int)$student['yearLevel'];

    // Get cumulative GWA
    $gwaResult = computeGWA($studentID, null, null);
    $gwa = (float)$gwaResult['gwa'];
    $honor = classifyLatinHonor($gwa, $studentID, null);

    // Get rank among year level
    $rows = $db->fetchAll(
        "SELECT s.studentID,
                ROUND(SUM(g.gradeEquivalent * sub.units) / SUM(sub.units), 4) AS gwa
         FROM students s
         JOIN enrollments e ON e.studentID = s.studentID AND e.dateDeleted IS NULL
         JOIN grades g ON g.enrollmentID = e.enrollmentID AND (g.status = 'Posted' OR g.status = 'Approved') AND g.gradeEquivalent IS NOT NULL
         JOIN subjects sub ON sub.subjectID = g.subjectID
                 WHERE s.yearLevel = :yl AND s.dateDeleted IS NULL
                     AND " . gwaIncludedSubjectFilter('e') . "
         GROUP BY s.studentID
         HAVING SUM(sub.units) > 0
         ORDER BY gwa ASC, s.lName, s.fName",
        [':yl' => $yearLevel]
    );

    $rank = 1;
    foreach ($rows as $row) {
        if ((int)$row['studentID'] === $studentID) {
            return ['rank'=>$rank, 'total'=>count($rows), 'gwa'=>round($gwa,4), 'honor'=>$honor];
        }
        $rank++;
    }

    return ['rank'=>null, 'total'=>count($rows), 'gwa'=>round($gwa,4), 'honor'=>$honor];
}

/**
 * Classify Dean's List (per semester: GWA 1.00–1.75, no grade below 2.00, no INC)
 */
function classifyDeansListSemester(int $studentID, int $acadYearID, int $semester): bool {
    $result = computeGWA($studentID, $acadYearID, $semester);
    if ($result['gwa'] < SUMMA_MIN || $result['gwa'] > CUMLAUD_MAX) return false;

    $db  = Database::getInstance();
    $sql = "SELECT COUNT(*) as cnt FROM grades g
            JOIN enrollments e ON e.enrollmentID = g.enrollmentID
            WHERE g.studentID = :sid AND e.acadYearID = :ayi AND e.semester = :sem
              AND " . officialGradeFilter('g.status') . "
              AND " . gwaIncludedSubjectFilter('e') . "
              AND (g.gradeEquivalent > :threshold OR g.remarks = 'Inc')";
    $row = $db->fetchOne($sql, [
        ':sid' => $studentID, ':ayi' => $acadYearID,
        ':sem' => $semester,  ':threshold' => HONORS_NO_BELOW
    ]);
    return (int)($row['cnt'] ?? 1) === 0;
}

/**
 * Convert numeric percentage to NEUST 5-point grade equivalent
 * Based on NEUST grading system
 */
function convertToGradeEquivalent(float $grade): float {
    if ($grade >= 98) return 1.00;
    if ($grade >= 95) return 1.25;
    if ($grade >= 92) return 1.50;
    if ($grade >= 89) return 1.75;
    if ($grade >= 86) return 2.00;
    if ($grade >= 83) return 2.25;
    if ($grade >= 80) return 2.50;
    if ($grade >= 77) return 2.75;
    if ($grade >= 75) return 3.00;
    return 5.00; // Failed
}

/**
 * Compute final grade: Midterm 40% + Finals 60%
 */
function computeFinalGrade(float $midterm, float $finals): float {
    return round(($midterm * 0.40) + ($finals * 0.60), 2);
}

// ─── Security Helpers ────────────────────────────────────────
function sanitize(string $input): string {
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}

function normalizeName(string $input): string {
    $normalized = preg_replace('/\s+/u', ' ', trim($input));
    return $normalized === '' ? '' : $normalized;
}

function validateName(string $name, int $minLength = 2, int $maxLength = 100): bool {
    $name = normalizeName($name);
    if ($name === '' || mb_strlen($name) < $minLength || mb_strlen($name) > $maxLength) {
        return false;
    }
    return (bool) preg_match('/^[\p{L}\p{M}][\p{L}\p{M} \'\-\.]*$/u', $name);
}

function normalizeEmail(string $email): string {
    return mb_strtolower(trim($email));
}

function validateEmail(string $email): bool {
    $email = normalizeEmail($email);
    if ($email === '' || mb_strlen($email) > 254) {
        return false;
    }
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function sanitizeTextArea(string $input): string {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function validateAddress(string $address, int $minLength = 5, int $maxLength = 200): bool {
    $address = trim($address);
    if ($address === '') {
        return true;
    }
    if (mb_strlen($address) < $minLength || mb_strlen($address) > $maxLength) {
        return false;
    }
    if (preg_match('/[\x00-\x1F\x7F]/u', $address)) {
        return false;
    }
    return (bool) preg_match('/^[\p{L}\p{N}\s\.\,\#\-\(\)\/\'\"]+$/u', $address);
}

function validateDate(string $date, string $format = 'Y-m-d'): bool {
    $date = trim($date);
    if ($date === '') {
        return true;
    }
    $dt = DateTime::createFromFormat($format, $date);
    return $dt !== false && $dt->format($format) === $date;
}

function validateRoleName(string $role): string {
    $allowed = ['Admin', 'Registrar', 'Teacher', 'Student'];
    return in_array($role, $allowed, true) ? $role : 'Teacher';
}

function hashPassword(string $password): string {
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

function verifyPassword(string $password, string $hash): bool {
    return password_verify($password, $hash);
}

function generateToken(int $length = 32): string {
    return bin2hex(random_bytes($length));
}

function generateCSRF(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = generateToken();
    }
    return $_SESSION['csrf_token'];
}

function verifyCSRF(string $token): bool {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function requireCSRFToken(): void {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
    if (!verifyCSRF((string)$token)) {
        jsonResponse(false, 'Invalid request token. Refresh the page and try again.');
    }
}

// ─── Response Helpers ────────────────────────────────────────
function jsonResponse(bool $success, string $message, array $data = []): void {
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $data));
    exit;
}

function redirect(string $url): void {
    header("Location: $url");
    exit;
}

// ─── Audit Logging ───────────────────────────────────────────
function logAudit(int $gradeID, int $actionBy, string $actionRole, string $action,
                  ?array $oldValue = null, ?array $newValue = null, string $notes = ''): void {
    $db = Database::getInstance();
    $db->insert(
        "INSERT INTO grade_audit_log (gradeID, actionBy, actionRole, action, oldValue, newValue, notes)
         VALUES (:gid, :aby, :arl, :act, :old, :new, :nts)",
        [
            ':gid' => $gradeID,
            ':aby' => $actionBy,
            ':arl' => $actionRole,
            ':act' => $action,
            ':old' => $oldValue ? json_encode($oldValue) : null,
            ':new' => $newValue ? json_encode($newValue) : null,
            ':nts' => $notes,
        ]
    );
}

// ─── Notification Helper ─────────────────────────────────────
function createNotification(int $recipientID, string $recipientType, string $title,
                             string $message, string $type, string $senderRole = ''): void {
    $db = Database::getInstance();
    $recipientType = $recipientType === 'Student' ? 'Student' : 'User';
        $duplicate = $db->fetchOne(
                "SELECT notifID FROM notifications
                 WHERE recipientID=:rid
                     AND recipientType=:rt
                     AND title=:ti
                     AND message=:msg
                     AND type=:ty
                     AND (senderRole <=> :senderRole)
                     AND createdAt >= DATE_SUB(NOW(), INTERVAL 30 SECOND)
                 LIMIT 1",
                [':rid'=>$recipientID, ':rt'=>$recipientType, ':ti'=>$title,
                 ':msg'=>$message, ':ty'=>$type, ':senderRole'=>$senderRole ?: null]
        );
        if ($duplicate) return;
    $db->insert(
        "INSERT INTO notifications (recipientID, recipientType, title, message, type, senderRole)
         VALUES (:rid, :rt, :ti, :msg, :ty, :senderRole)",
        [':rid' => $recipientID, ':rt' => $recipientType,
         ':ti'  => $title,       ':msg' => $message, ':ty' => $type,
         ':senderRole' => $senderRole ?: null]
    );
}

function notifyUsersByRole(string $role, string $title, string $message, string $type, string $senderRole): void {
    $db = Database::getInstance();
    $users = $db->fetchAll(
        "SELECT userID FROM users WHERE roleName=:role AND isActive=1 AND dateDeleted IS NULL",
        [':role'=>$role]
    );
    foreach ($users as $recipient) {
        createNotification((int)$recipient['userID'], 'User', $title, $message, $type, $senderRole);
    }
}

// ─── Format Helpers ──────────────────────────────────────────
function fullName(array $person): string {
    $name = $person['fName'];
    if (!empty($person['mName'])) $name .= ' ' . substr($person['mName'], 0, 1) . '.';
    $name .= ' ' . $person['lName'];
    if (!empty($person['extName'])) $name .= ' ' . $person['extName'];
    return $name;
}

function formatGWA(float $gwa): string {
    return number_format($gwa, 4);
}

function honorBadgeClass(string $honor): string {
    return match($honor) {
        'Summa Cum Laude' => 'badge-summa',
        'Magna Cum Laude' => 'badge-magna',
        'Cum Laude'       => 'badge-cum',
        'Dean\'s List'    => 'badge-deans',
        default           => 'badge-none',
    };
}

function getPositionLabel(int $position): string {
    return match ($position) {
        1 => '1st',
        2 => '2nd',
        3 => '3rd',
        default => $position . 'th',
    };
}

function getRankingBadgeClass(int $position): string {
    return match ($position) {
        1 => 'badge-summa',
        2 => 'badge-magna',
        3 => 'badge-cum',
        default => 'badge-none',
    };
}

function isStudentGraduated(int $studentID): bool {
    $db = Database::getInstance();
    $result = $db->fetchOne(
        "SELECT isGraduated FROM students WHERE studentID=:sid AND dateDeleted IS NULL",
        [':sid' => $studentID]
    );
    return $result && (int)$result['isGraduated'] === 1;
}

function isStudentDropped(int $studentID): bool {
    $db = Database::getInstance();
    $result = $db->fetchOne(
        "SELECT isDropped FROM students WHERE studentID=:sid AND dateDeleted IS NULL",
        [':sid' => $studentID]
    );
    return $result && (int)$result['isDropped'] === 1;
}

function canGraduateStudent(int $studentID): bool {
    $db = Database::getInstance();
    $student = $db->fetchOne("SELECT yearLevel, isDropped, isGraduated FROM students WHERE studentID=:sid AND dateDeleted IS NULL", [':sid'=>$studentID]);
    if (!$student || (int)$student['isDropped'] === 1 || (int)$student['isGraduated'] === 1) {
        return false;
    }
    if ((int)$student['yearLevel'] !== 4) {
        return false;
    }
    if (hasBackSubjects($studentID)) {
        return false;
    }
    $cumGWA = computeGWA($studentID);
    return $cumGWA['count'] > 0 && $cumGWA['gwa'] > 0;
}

function normalizeGradeStatusDisplay(string $status): string {
    $status = trim((string)$status);
    if (in_array($status, ['Posted', 'Approved'], true)) {
        return 'Posted';
    }
    return $status;
}

function gradeStatusClass(string $status): string {
    $normalized = normalizeGradeStatusDisplay($status);
    return match($normalized) {
        'Posted'    => 'status-approved',
        'Pending'   => 'status-pending',
        default     => 'status-default',
    };
}

function studentNumberYearFromAcademicYear(string $academicYear): ?int {
    return preg_match('/^\s*(\d{4})\s*[-–—]/u', $academicYear, $matches)
        ? (int)$matches[1]
        : null;
}

function generateNextStudentNo(Database $db, int $year): string {
    $row = $db->fetchOne(
        "SELECT MAX(CAST(SUBSTRING_INDEX(studentNo, '-', -1) AS UNSIGNED)) AS lastSequence
         FROM students WHERE studentNo LIKE :prefix",
        [':prefix'=>$year . '-%']
    );
    $sequence = (int)($row['lastSequence'] ?? 0) + 1;
    return $year . '-' . str_pad((string)$sequence, 5, '0', STR_PAD_LEFT);
}

// ─── Student Status Helpers ──────────────────────────────────
/**
 * Check if student is irregular (Year Level 5)
 */
function isStudentIrregular(int $studentID): bool {
    $db = Database::getInstance();
    $result = $db->fetchOne(
        "SELECT yearLevel, isEnroll FROM students WHERE studentID=:sid AND dateDeleted IS NULL",
        [':sid' => $studentID]
    );
    return $result && (int)$result['isEnroll'] === 1 && (int)$result['yearLevel'] === 5;
}

function isSubjectYearAllowedForStudent(int $studentYearLevel, $subjectYearLevel): bool {
    return $studentYearLevel === 5
        || $subjectYearLevel === null
        || (int)$subjectYearLevel === 0
        || (int)$subjectYearLevel === $studentYearLevel;
}

/**
 * Get student's back subjects (failed and incomplete grades)
 * Returns array of subjects needing to be retaken
 */
function getBackSubjects(int $studentID): array {
    $db = Database::getInstance();
    
    $backSubjects = $db->fetchAll(
        "SELECT DISTINCT
            s.subjectID,
            s.subjectCode,
            s.subjectName,
            s.units,
            g.gradeID,
            g.gradeEquivalent,
            g.status as gradeStatus,
            e.acadYearID,
            ay.academicYear,
            e.semester
        FROM subjects s
        JOIN enrollments e ON e.subjectID = s.subjectID
        JOIN grades g ON g.enrollmentID = e.enrollmentID
        JOIN acad_years ay ON ay.acadYearID = e.acadYearID
        WHERE g.studentID = :sid
          AND e.status = 'Active'
          AND s.dateDeleted IS NULL
          AND (
            g.gradeEquivalent > 3.0  -- Failed (grade equivalent 3.1 and above)
            OR g.gradeEquivalent IS NULL  -- Incomplete (NULL grade)
          )
        ORDER BY ay.academicYear DESC, e.semester DESC",
        [':sid' => $studentID]
    );
    
    return $backSubjects;
}

/**
 * Check if student has back subjects
 */
function hasBackSubjects(int $studentID): bool {
    return count(getBackSubjects($studentID)) > 0;
}