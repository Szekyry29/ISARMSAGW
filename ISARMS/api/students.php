<?php
define('ROOT', dirname(__DIR__));
require_once ROOT . '/includes/config.php';
require_once ROOT . '/includes/functions.php';
header('Content-Type: application/json');
startSecureSession();
if (!isLoggedIn()) jsonResponse(false, 'Unauthorized.');

$body   = $_POST ?: (json_decode(file_get_contents('php://input'), true) ?? []);
$action = $body['action'] ?? '';
if (in_array($action, ['import_students', 'add_student', 'toggle_active', 'update', 'reset_password'], true)) {
    requireCSRFToken();
}
$db     = Database::getInstance();
$user   = getCurrentUser();

function assignStudentToSection(Database $db, int $studentID, int $programID, int $sectionID): int {
    $student = $db->fetchOne(
        "SELECT yearLevel FROM students WHERE studentID=:student AND programID=:program",
        [':student'=>$studentID, ':program'=>$programID]
    );
    if (!$student) throw new RuntimeException('Student not found for the selected program.');

    $section = $db->fetchOne(
        "SELECT sec.sectionID, sec.acadYearID, ay.academicYear
         FROM sections sec
         JOIN acad_years ay ON ay.acadYearID=sec.acadYearID
         WHERE sec.sectionID=:section AND sec.programID=:program
           AND sec.dateDeleted IS NULL AND ay.dateDeleted IS NULL",
        [':section'=>$sectionID, ':program'=>$programID]
    );
    if (!$section) throw new RuntimeException('The selected section does not belong to the selected program.');

    $subjects = $db->fetchAll(
        "SELECT ss.subjectID, ss.semester, sub.yearLevel
         FROM section_subjects ss
         JOIN subjects sub ON sub.subjectID=ss.subjectID AND sub.dateDeleted IS NULL
         WHERE ss.sectionID=:section AND ss.acadYearID=:ay AND ss.dateDeleted IS NULL
         ORDER BY ss.semester, ss.subjectID",
        [':section'=>$sectionID, ':ay'=>$section['acadYearID']]
    );
    if (!$subjects) return 0;

    $created = 0;
    foreach ($subjects as $subject) {
        if (!isSubjectYearAllowedForStudent((int)$student['yearLevel'], $subject['yearLevel'])) continue;

        $existing = $db->fetchOne(
            "SELECT enrollmentID, status FROM enrollments
             WHERE studentID=:student AND subjectID=:subject AND acadYearID=:ay AND semester=:semester",
            [':student'=>$studentID, ':subject'=>$subject['subjectID'], ':ay'=>$section['acadYearID'], ':semester'=>$subject['semester']]
        );
        if ($existing) {
            $db->query(
                "UPDATE enrollments
                 SET status='Active', dateDeleted=NULL, sectionID=:section, programID=:program, acadYear=:academicYear
                 WHERE enrollmentID=:id",
                [':section'=>$sectionID, ':program'=>$programID, ':academicYear'=>$section['academicYear'], ':id'=>$existing['enrollmentID']]
            );
            continue;
        }
        $db->insert(
            "INSERT INTO enrollments
             (dateofEnroll, acadYear, semester, studentID, subjectID, programID, sectionID, acadYearID, status)
             VALUES (NOW(), :academicYear, :semester, :student, :subject, :program, :section, :ay, 'Active')",
            [':academicYear'=>$section['academicYear'], ':semester'=>$subject['semester'], ':student'=>$studentID,
             ':subject'=>$subject['subjectID'], ':program'=>$programID, ':section'=>$sectionID, ':ay'=>$section['acadYearID']]
        );
        $created++;
    }
    return $created;
}

switch ($action) {
    case 'import_students':
        requireAuth('Admin', 'Registrar');
        if (empty($_FILES['file']['tmp_name']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            jsonResponse(false, 'Please select a valid CSV file.');
        }
        $handle = fopen($_FILES['file']['tmp_name'], 'r');
        if (!$handle) jsonResponse(false, 'The uploaded CSV file could not be opened.');
        $firstLine = fgets($handle);
        if ($firstLine === false) {
            fclose($handle);
            jsonResponse(false, 'The CSV file is empty.');
        }
        $delimiterCounts = [
            ',' => substr_count($firstLine, ','),
            ';' => substr_count($firstLine, ';'),
            "\t" => substr_count($firstLine, "\t"),
        ];
        $delimiter = array_search(max($delimiterCounts), $delimiterCounts, true);
        if ($delimiterCounts[$delimiter] === 0) {
            fclose($handle);
            jsonResponse(false, 'The CSV header must contain comma-, semicolon-, or tab-separated columns.');
        }
        rewind($handle);
        $header = fgetcsv($handle, 0, $delimiter, '"', '\\');
        if (!$header) {
            fclose($handle);
            jsonResponse(false, 'The CSV file is empty.');
        }
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
        $normalizeHeader = static fn($value) => strtolower(preg_replace('/[^a-z0-9]/i', '', (string)$value));
        $columns = array_map($normalizeHeader, $header);
        $aliases = [
            'firstname'=>'fname', 'givenname'=>'fname', 'fullname'=>'name', 'full_name'=>'name', 'full-name'=>'name', 'name'=>'name',
            'lastname'=>'lname', 'surname'=>'lname', 'middlename'=>'mname', 'studentnumber'=>'studentno', 'studentid'=>'studentno',
            'dateofbirth'=>'dateofbirth', 'dob'=>'dateofbirth', 'programcode'=>'programcode',
            'program'=>'programcode', 'course'=>'programcode', 'coursecode'=>'programcode',
            'programid'=>'programid', 'sectionid'=>'sectionid', 'year'=>'yearlevel',
            'yearlevel'=>'yearlevel', 'emailaddress'=>'email', 'password'=>'password',
            'gender'=>'sex', 'extension'=>'extname'
        ];
        $mapped = [];
        foreach ($columns as $index => $column) $mapped[$aliases[$column] ?? $column] = $index;

        $hasNameColumns = array_key_exists('fname', $mapped) || array_key_exists('lname', $mapped) || array_key_exists('name', $mapped);
        $requiredStrict = ['studentno','dateofbirth','sex','yearlevel','email'];
        foreach ($requiredStrict as $required) {
            if (!array_key_exists($required, $mapped)) jsonResponse(false, "CSV is missing required column: $required");
        }
        if (!$hasNameColumns) jsonResponse(false, 'CSV is missing required column: name');

        $programsByCode = [];
        foreach ($db->fetchAll("SELECT programID, programCode FROM programs WHERE dateDeleted IS NULL") as $program) {
            $programsByCode[strtolower(trim($program['programCode']))] = (int)$program['programID'];
        }
        $sectionsByProgram = [];
        foreach ($db->fetchAll(
            "SELECT sec.sectionID, sec.sectionName, sec.programID, p.programCode
             FROM sections sec
             JOIN programs p ON p.programID=sec.programID AND p.dateDeleted IS NULL
             JOIN acad_years ay ON ay.acadYearID=sec.acadYearID AND ay.dateDeleted IS NULL
             WHERE sec.dateDeleted IS NULL
             ORDER BY (ay.status='Active') DESC, ay.acadYearID DESC"
        ) as $section) {
            $sectionsByProgram[(int)$section['programID']][] = $section;
        }
        $normalizeSectionName = static function (string $sectionName, string $programCode): string {
            $sectionName = preg_replace('/^' . preg_quote($programCode, '/') . '\s*[-:\x{2013}\x{2014}]?\s*/iu', '', trim($sectionName));
            return strtolower(preg_replace('/[^a-z0-9]/i', '', $sectionName));
        };
        $created = 0; $skipped = 0; $errors = []; $rowNumber = 1;
        while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            $rowNumber++;
            if (count(array_filter($row, static fn($value) => trim((string)$value) !== '')) === 0) continue;
            $value = static function ($key) use ($row, $mapped) { return isset($mapped[$key]) ? trim((string)($row[$mapped[$key]] ?? '')) : ''; };

            $firstName = $value('fname');
            $lastName = $value('lname');
            $middleName = $value('mname');
            $extName = $value('extname');
            if ((!$firstName || !$lastName) && $value('name')) {
                $fullName = trim($value('name'));
                $parts = preg_split('/\s+/', $fullName, -1, PREG_SPLIT_NO_EMPTY);
                if ($parts) {
                    $firstName = $firstName ?: ($parts[0] ?? '');
                    $lastName = $lastName ?: (array_pop($parts) ?: '');
                    $middleName = $middleName ?: implode(' ', $parts);
                }
            }

            $programID = (int)$value('programid');
            if (!$programID && $value('programcode')) $programID = $programsByCode[strtolower($value('programcode'))] ?? 0;
            $studentNo = $value('studentno'); $email = strtolower($value('email'));
            $dateOfBirth = $value('dateofbirth');
            if ($dateOfBirth !== '') {
                $birthDate = DateTime::createFromFormat('!Y-m-d', $dateOfBirth)
                    ?: DateTime::createFromFormat('!n/j/Y', $dateOfBirth)
                    ?: DateTime::createFromFormat('!m/d/Y', $dateOfBirth);
                if ($birthDate) $dateOfBirth = $birthDate->format('Y-m-d');
            }
            $sex = $value('sex');
            $yearLevel = (int)$value('yearlevel');
            if (!checkdate((int)substr($dateOfBirth, 5, 2), (int)substr($dateOfBirth, 8, 2), (int)substr($dateOfBirth, 0, 4))
                || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateOfBirth)) {
                $errors[] = "Row $rowNumber: date of birth must be a valid date."; continue;
            }
            if (!$programID || !$studentNo || !$email || !$firstName || !$lastName || !$dateOfBirth || !$sex || !$yearLevel) {
                $errors[] = "Row $rowNumber: missing name, student number, birth date, sex, year level, email, or valid program."; continue;
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = "Row $rowNumber: invalid email."; continue; }
            if (!in_array($sex, ['Male', 'Female'], true)) { $errors[] = "Row $rowNumber: sex must be Male or Female."; continue; }
            if ($yearLevel < 1 || $yearLevel > 5) { $errors[] = "Row $rowNumber: year level must be 1 to 5 (5 = irregular)."; continue; }
            $duplicate = $db->fetchOne("SELECT studentID FROM students WHERE studentNo=:sno OR email=:email", [':sno'=>$studentNo, ':email'=>$email]);
            if ($duplicate) { $skipped++; continue; }
            $sectionValue = $value('sectionid');
            $sectionID = 0;
            if ($sectionValue !== '') {
                foreach ($sectionsByProgram[$programID] ?? [] as $candidate) {
                    $matchesSection = ctype_digit($sectionValue)
                        ? (int)$candidate['sectionID'] === (int)$sectionValue
                        : $normalizeSectionName((string)$candidate['sectionName'], (string)$candidate['programCode'])
                            === $normalizeSectionName($sectionValue, (string)$candidate['programCode']);
                    if ($matchesSection) {
                        $sectionID = (int)$candidate['sectionID'];
                        break;
                    }
                }
                if (!$sectionID) {
                    $errors[] = "Row $rowNumber: section '{$sectionValue}' was not found for this program."; continue;
                }
            }
            try {
                $db->beginTransaction();
                $studentID = $db->insert("INSERT INTO students (fName,mName,lName,extName,studentNo,dateofBirth,sex,programID,sectionID,yearLevel,email,password)
                    VALUES (:fn,:mn,:ln,:en,:sno,:dob,:sex,:pid,:section,:year,:email,:password)", [
                    ':fn'=>sanitize($firstName), ':mn'=>sanitize($middleName), ':ln'=>sanitize($lastName), ':en'=>sanitize($extName),
                    ':sno'=>sanitize($studentNo), ':dob'=>$dateOfBirth, ':sex'=>$sex, ':pid'=>$programID, ':section'=>$sectionID ?: null,
                    ':year'=>$yearLevel, ':email'=>$email,
                    ':password'=>hashPassword($value('password') ?: $studentNo)
                ]);
                if ($sectionID > 0) {
                    assignStudentToSection($db, $studentID, $programID, $sectionID);
                    $db->query("UPDATE students SET isEnroll=1 WHERE studentID=:id", [':id'=>$studentID]);
                }
                $db->commit();
                $created++;
            } catch (Throwable $exception) {
                if ($db->getConnection()->inTransaction()) $db->rollback();
                $errors[] = "Row $rowNumber: " . $exception->getMessage();
            }
        }
        fclose($handle);
        if ($created === 0 && $errors) {
            jsonResponse(false, 'No students were imported. ' . implode(' ', array_slice($errors, 0, 3)), ['created'=>0, 'skipped'=>$skipped, 'errors'=>$errors]);
        }
        $message = "Imported $created student(s); skipped $skipped duplicate(s).";
        if ($errors) $message .= ' ' . count($errors) . ' row(s) need review.';
        jsonResponse(true, $message, ['created'=>$created, 'skipped'=>$skipped, 'errors'=>$errors]);
        break;

    case 'add_student':
        requireAuth('Admin', 'Registrar');
        $autoGenerateStudentNo = !empty($body['autoGenerateStudentNo']);
        $required = ['fName','lName','dateofBirth','sex','programID','yearLevel','email','password'];
        if (!$autoGenerateStudentNo) $required[] = 'studentNo';
        foreach ($required as $f) {
            if (empty($body[$f])) jsonResponse(false, "Field '$f' is required.");
        }
        $yearLevel = (int)($body['yearLevel'] ?? 0);
        if ($yearLevel < 1 || $yearLevel > 5) {
            jsonResponse(false, 'Year level must be 1 to 5 (5 = irregular).');
        }
        $sectionID = (int)($body['sectionID'] ?? 0);
        $db->beginTransaction();
        try {
            $studentNo = trim((string)($body['studentNo'] ?? ''));
            if ($autoGenerateStudentNo) {
                if ($sectionID > 0) {
                    $yearRecord = $db->fetchOne(
                        "SELECT ay.academicYear FROM sections sec
                         JOIN acad_years ay ON ay.acadYearID=sec.acadYearID
                         WHERE sec.sectionID=:section AND sec.programID=:program
                           AND sec.dateDeleted IS NULL AND ay.dateDeleted IS NULL",
                        [':section'=>$sectionID, ':program'=>(int)$body['programID']]
                    );
                } else {
                    $yearRecord = $db->fetchOne(
                        "SELECT academicYear FROM acad_years
                         WHERE status='Active' AND dateDeleted IS NULL
                         ORDER BY acadYearID DESC LIMIT 1"
                    );
                }
                $studentNoYear = studentNumberYearFromAcademicYear($yearRecord['academicYear'] ?? '');
                if (!$studentNoYear) throw new RuntimeException('Select a valid section or active academic year before generating a student number.');
                $studentNo = generateNextStudentNo($db, $studentNoYear);
            }

            $exists = $db->fetchOne("SELECT studentID FROM students WHERE studentNo=:sno OR email=:em",
                [':sno'=>$studentNo, ':em'=>strtolower(trim($body['email']))]);
            if ($exists) throw new RuntimeException('Student number or email already exists. Please retry.');

            $id = $db->insert(
                "INSERT INTO students (fName,mName,lName,extName,studentNo,dateofBirth,sex,programID,sectionID,yearLevel,email,password)
                 VALUES (:fn,:mn,:ln,:en,:sno,:dob,:sx,:pid,:section,:yl,:em,:pw)",
                [
                    ':fn'  => sanitize($body['fName']),
                    ':mn'  => sanitize($body['mName'] ?? ''),
                    ':ln'  => sanitize($body['lName']),
                    ':en'  => sanitize($body['extName'] ?? ''),
                    ':sno' => sanitize($studentNo),
                    ':dob' => $body['dateofBirth'],
                    ':sx'  => $body['sex'],
                    ':pid' => (int)$body['programID'],
                    ':section' => $sectionID ?: null,
                    ':yl'  => $yearLevel,
                    ':em'  => strtolower(trim($body['email'])),
                    ':pw'  => hashPassword($body['password']),
                ]
            );
            $enrollments = $sectionID > 0
                ? assignStudentToSection($db, $id, (int)$body['programID'], $sectionID)
                : 0;
            if ($enrollments > 0) {
                $db->query("UPDATE students SET isEnroll=1 WHERE studentID=:id", [':id'=>$id]);
            }
            $db->commit();
        } catch (Throwable $exception) {
            $db->rollback();
            jsonResponse(false, $exception->getMessage());
        }
        jsonResponse(true, $sectionID > 0 ? 'Student added and assigned to the selected section.' : 'Student added successfully.', ['studentID' => $id]);
        break;

    case 'toggle_active':
        requireAuth('Admin');
        $sid      = (int)($body['studentID'] ?? 0);
        $isActive = (int)(bool)($body['isActive'] ?? false);
        $db->query("UPDATE students SET isActive=:a WHERE studentID=:id", [':a'=>$isActive,':id'=>$sid]);
        jsonResponse(true, $isActive ? 'Student activated.' : 'Student deactivated.');
        break;

    case 'update':
        requireAuth('Admin', 'Registrar');
        $sid = (int)($body['studentID'] ?? 0);
        if (!$sid) jsonResponse(false, 'Student ID required.');
        $existing = $db->fetchOne("SELECT studentID, email FROM students WHERE studentID=:id AND dateDeleted IS NULL", [':id'=>$sid]);
        if (!$existing) jsonResponse(false, 'Student not found.');
        $email = strtolower(trim((string)($body['email'] ?? '')));
        $duplicate = $db->fetchOne("SELECT studentID FROM students WHERE email=:email AND studentID<>:id", [':email'=>$email, ':id'=>$sid]);
        if ($duplicate) jsonResponse(false, 'That email address is already used by another student.');
        $yearLevel = (int)($body['yearLevel'] ?? 0);
        if ($yearLevel < 1 || $yearLevel > 5) {
            jsonResponse(false, 'Year level must be 1 to 5 (5 = irregular).');
        }
        $sectionID = (int)($body['sectionID'] ?? 0);
        if ($sectionID > 0) {
            $section = $db->fetchOne(
                "SELECT sectionID FROM sections WHERE sectionID=:section AND programID=:program AND dateDeleted IS NULL",
                [':section'=>$sectionID, ':program'=>(int)($body['programID'] ?? 0)]
            );
            if (!$section) jsonResponse(false, 'The selected section does not belong to the selected program.');
        }
        try {
            $db->query(
                "UPDATE students SET fName=:fn,mName=:mn,lName=:ln,extName=:en,yearLevel=:yl,programID=:pid,sectionID=:section,email=:em,sex=:sx,dateofBirth=:dob,isEnroll=:ie,isActive=:ia WHERE studentID=:id",
                [':fn'=>sanitize($body['fName']??''),':mn'=>sanitize($body['mName']??''),
                 ':ln'=>sanitize($body['lName']??''),':en'=>sanitize($body['extName']??''),
                 ':yl'=>$yearLevel,':pid'=>(int)($body['programID']??0),':section'=>$sectionID ?: null,
                 ':em'=>$email,':sx'=>$body['sex']??'',
                 ':dob'=>$body['dateofBirth']??null,':ie'=>isset($body['isEnroll'])?1:0,
                 ':ia'=>isset($body['isActive'])?1:0,':id'=>$sid]
            );
            if ($sectionID > 0) {
                $db->query(
                    "UPDATE enrollments SET sectionID=:section WHERE studentID=:student AND status='Active' AND dateDeleted IS NULL",
                    [':section'=>$sectionID, ':student'=>$sid]
                );
            }
        } catch (Throwable $exception) {
            error_log('Student update failed: ' . $exception->getMessage());
            jsonResponse(false, 'Student record could not be updated. Please check the values and try again.');
        }
        jsonResponse(true, 'Student record updated.');
        break;

    case 'get_student':
        requireAuth('Admin', 'Registrar');
        $sid = (int)($body['studentID'] ?? 0);
        if (!$sid) jsonResponse(false, 'Student ID required.');
        $student = $db->fetchOne(
            "SELECT s.*,
                    COALESCE(s.sectionID, (
                        SELECT e.sectionID FROM enrollments e
                        WHERE e.studentID=s.studentID AND e.status='Active' AND e.dateDeleted IS NULL
                        ORDER BY e.enrollmentID DESC LIMIT 1
                    )) AS sectionID
             FROM students s
             WHERE s.studentID=:id",
            [':id'=>$sid]
        );
        if (!$student) jsonResponse(false, 'Student not found.');
        jsonResponse(true, 'Student loaded.', ['data' => $student]);
        break;

    case 'reset_password':
        requireAuth('Admin');
        $sid = (int)($body['studentID'] ?? 0);
        $pw  = $body['password'] ?? '';
        if (!$sid || !$pw) jsonResponse(false, 'Invalid request.');
        $db->query("UPDATE students SET password=:pw WHERE studentID=:id",
                   [':pw'=>hashPassword($pw),':id'=>$sid]);
        $passwordStudent = $db->fetchOne("SELECT fName, mName, lName, extName FROM students WHERE studentID=:id", [':id'=>$sid]);
        $passwordStudentName = $passwordStudent ? fullName($passwordStudent) : "Student #{$sid}";
        notifyUsersByRole('Registrar', 'Student Password Updated', "Admin updated the password for {$passwordStudentName}.", 'Security', 'Admin');
        createNotification($sid, 'Student', 'Password Updated', 'Your student account password was updated by the Administrator.', 'Security', 'Admin');
        jsonResponse(true, 'Password reset successfully.');
        break;

    default:
        jsonResponse(false, 'Invalid action.');
}
