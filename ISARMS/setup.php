<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (getenv('ISARMS_ALLOW_DEMO_SEED') !== '1') {
    fwrite(STDERR, "Demo seed disabled. Set ISARMS_ALLOW_DEMO_SEED=1 only in a local test environment.\n");
    exit(1);
}

/**
 * ISARMS — First-Time Setup Script
 * Run this ONCE after importing the database: http://localhost/ISARMS/setup.php
 * DELETE this file after setup is complete!
 *
 * This creates all demo accounts, sample data, and enrolls
 * the demo student so you can simulate the whole system.
 */

require_once 'includes/config.php';
require_once 'includes/functions.php';

$db = Database::getInstance();
$log = [];
$errors = [];

function logMsg(string $msg, bool $ok = true) {
    global $log;
    $log[] = ['ok' => $ok, 'msg' => $msg];
}

try {

// ── 1. Academic Year ────────────────────────────────────────
$ay = $db->fetchOne("SELECT acadYearID FROM acad_years WHERE status='Active' LIMIT 1");
if (!$ay) {
    $ayID = $db->insert(
        "INSERT INTO acad_years (academicYear, acadDesc, status) VALUES ('2024-2025', 'Academic Year 2024-2025', 'Active')"
    );
    logMsg("✅ Academic Year 2024-2025 created and set Active.");
} else {
    $ayID = $ay['acadYearID'];
    logMsg("ℹ️ Active academic year already exists (ID: $ayID). Skipped.");
}

// ── 2. Programs ──────────────────────────────────────────────
$programs = [
    ['BSIT', 'Bachelor of Science in Information Technology', 'Covers software development, networking, and IT management'],
    ['BSCS', 'Bachelor of Science in Computer Science',       'Covers algorithms, systems, and theoretical computing'],
    ['BSBA', 'Bachelor of Science in Business Administration','Business and management studies'],
];
$progIDs = [];
foreach ($programs as [$code, $name, $desc]) {
    $exists = $db->fetchOne("SELECT programID FROM programs WHERE programCode=:c AND dateDeleted IS NULL", [':c'=>$code]);
    if (!$exists) {
        $id = $db->insert("INSERT INTO programs (programCode, programName, programDesc) VALUES (:c,:n,:d)",
                          [':c'=>$code,':n'=>$name,':d'=>$desc]);
        $progIDs[$code] = $id;
        logMsg("✅ Program $code created.");
    } else {
        $progIDs[$code] = $exists['programID'];
        logMsg("ℹ️ Program $code already exists. Skipped.");
    }
}

// ── 3. Subjects ──────────────────────────────────────────────
$subjects = [
    // BSIT 1st Year subjects
    ['CC101',  'Introduction to Computing',          3,   'BSIT', 1, 1],
    ['CC102',  'Computer Programming 1',             3,   'BSIT', 1, 1],
    ['MATH101','Mathematics in the Modern World',    3,   'BSIT', 1, 1],
    ['GE101',  'Purposive Communication',            3,   'BSIT', 1, 1],
    ['CC103',  'Computer Programming 2',             3,   'BSIT', 1, 2],
    ['IT101',  'Web Development 1',                  3,   'BSIT', 1, 2],
    ['MATH102','Statistics and Probability',         3,   'BSIT', 1, 2],
    ['GE102',  'The Contemporary World',             3,   'BSIT', 1, 2],
    // BSCS 1st Year subjects
    ['CS101',  'Introduction to Computer Science',   3,   'BSCS', 1, 1],
    ['CS102',  'Discrete Mathematics',               3,   'BSCS', 1, 1],
    ['CS103',  'Programming Fundamentals',           3,   'BSCS', 1, 1],
    ['CS104',  'Data Structures and Algorithms',     3,   'BSCS', 1, 2],
];
$subjectIDs = [];
foreach ($subjects as [$code, $name, $units, $prog, $year, $sem]) {
    $pid = $progIDs[$prog] ?? null;
    $exists = $db->fetchOne("SELECT subjectID FROM subjects WHERE subjectCode=:c AND dateDeleted IS NULL", [':c'=>$code]);
    if (!$exists) {
        $id = $db->insert(
            "INSERT INTO subjects (subjectCode, subjectName, units, programID, yearLevel, semester) VALUES (:c,:n,:u,:p,:y,:s)",
            [':c'=>$code,':n'=>$name,':u'=>$units,':p'=>$pid,':y'=>$year,':s'=>$sem]
        );
        $subjectIDs[$code] = $id;
        logMsg("✅ Subject $code created.");
    } else {
        $subjectIDs[$code] = $exists['subjectID'];
        logMsg("ℹ️ Subject $code already exists. Skipped.");
    }
}

// ── 4. Users (Admin, Teacher, Registrar) ─────────────────────
$users = [
    ['Admin',     'System',     '',        'Administrator', 'admin@neust.edu.ph',      'admin2025',     null],
    ['Teacher',   'Juan',       'A',       'Dela Cruz',     'teacher@neust.edu.ph',    'teacher2025',   'BSIT'],
    ['Teacher',   'Maria',      'B',       'Santos',        'teacher2@neust.edu.ph',   'teacher2025',   'BSCS'],
    ['Registrar', 'Ana',        'C',       'Reyes',         'registrar@neust.edu.ph',  'registrar2025', null],
];
$userIDs = [];
foreach ($users as [$role, $fName, $mName, $lName, $email, $password, $prog]) {
    $exists = $db->fetchOne("SELECT userID FROM users WHERE email=:e AND dateDeleted IS NULL", [':e'=>$email]);
    if (!$exists) {
        $pid = $prog ? ($progIDs[$prog] ?? null) : null;
        $id  = $db->insert(
            "INSERT INTO users (fName,mName,lName,email,password,roleName,programID,isActive)
             VALUES (:fn,:mn,:ln,:em,:pw,:rn,:pid,1)",
            [':fn'=>$fName,':mn'=>$mName,':ln'=>$lName,':em'=>$email,
             ':pw'=>hashPassword($password),':rn'=>$role,':pid'=>$pid]
        );
        $userIDs[$email] = $id;
        logMsg("✅ $role account created: $email / $password");
    } else {
        $userIDs[$email] = $exists['userID'];
        logMsg("ℹ️ User $email already exists. Skipped.");
    }
}

// ── 5. Sections ──────────────────────────────────────────────
$teacherID  = $userIDs['teacher@neust.edu.ph']  ?? null;
$teacher2ID = $userIDs['teacher2@neust.edu.ph'] ?? null;

$sectionsData = [
    ['BSIT 1-A', 'Morning Section - BSIT 1st Year', 'BSIT', $teacherID],
    ['BSIT 1-B', 'Afternoon Section - BSIT 1st Year', 'BSIT', $teacherID],
    ['BSCS 1-A', 'Morning Section - BSCS 1st Year',  'BSCS', $teacher2ID],
];
$sectionIDs = [];
foreach ($sectionsData as [$name, $desc, $prog, $tID]) {
    $pid    = $progIDs[$prog] ?? null;
    $exists = $db->fetchOne("SELECT sectionID FROM sections WHERE sectionName=:n AND acadYearID=:ay AND dateDeleted IS NULL",
                            [':n'=>$name,':ay'=>$ayID]);
    if (!$exists) {
        $id = $db->insert(
            "INSERT INTO sections (sectionName,sectionDesc,programID,acadYearID,userID) VALUES (:n,:d,:p,:ay,:t)",
            [':n'=>$name,':d'=>$desc,':p'=>$pid,':ay'=>$ayID,':t'=>$tID]
        );
        $sectionIDs[$name] = $id;
        logMsg("✅ Section $name created.");
    } else {
        $sectionIDs[$name] = $exists['sectionID'];
        logMsg("ℹ️ Section $name already exists. Skipped.");
    }
}

// ── 6. Students ──────────────────────────────────────────────
$studentsData = [
    ['2024-00001', 'Juan',   'M', 'Student',   'M', '2005-06-15', 'student@neust.edu.ph',   'student2025', 'BSIT'],
    ['2024-00002', 'Maria',  'L', 'Cruz',      'F', '2005-03-22', 'student2@neust.edu.ph',  'student2025', 'BSIT'],
    ['2024-00003', 'Pedro',  'A', 'Reyes',     'M', '2004-11-08', 'student3@neust.edu.ph',  'student2025', 'BSIT'],
    ['2024-00004', 'Ana',    'B', 'Santos',    'F', '2005-08-30', 'student4@neust.edu.ph',  'student2025', 'BSCS'],
];
$studentIDs = [];
foreach ($studentsData as [$no, $fName, $mName, $lName, $sex, $dob, $email, $pw, $prog]) {
    $pid    = $progIDs[$prog] ?? null;
    $exists = $db->fetchOne("SELECT studentID FROM students WHERE studentNo=:n OR email=:e", [':n'=>$no,':e'=>$email]);
    if (!$exists) {
        $id = $db->insert(
            "INSERT INTO students (studentNo,fName,mName,lName,dateofBirth,sex,programID,yearLevel,email,password,isEnroll,isActive)
             VALUES (:no,:fn,:mn,:ln,:dob,:sx,:pid,1,:em,:pw,0,1)",
            [':no'=>$no,':fn'=>$fName,':mn'=>$mName,':ln'=>$lName,
             ':dob'=>$dob,':sx'=>$sex,':pid'=>$pid,':em'=>$email,':pw'=>hashPassword($pw)]
        );
        $studentIDs[$no] = $id;
        logMsg("✅ Student $no ($fName $lName) created. Email: $email / $pw");
    } else {
        $studentIDs[$no] = $exists['studentID'];
        logMsg("ℹ️ Student $no already exists. Skipped.");
    }
}

// ── 7. Enroll students into subjects ─────────────────────────
$bsitSection = $sectionIDs['BSIT 1-A'] ?? null;
$bscsSection = $sectionIDs['BSCS 1-A'] ?? null;

// BSIT students → BSIT 1st year subjects, 1st semester
$bsitStudents = array_filter($studentIDs, function($k) use ($studentsData) {
    foreach ($studentsData as $s) { if ($s[0]===$k && $s[8]==='BSIT') return true; }
    return false;
}, ARRAY_FILTER_USE_KEY);

$bsitSem1Subjects = ['CC101','CC102','MATH101','GE101'];
$enrollCount = 0;

if ($bsitSection) {
    foreach (['2024-00001','2024-00002','2024-00003'] as $sno) {
        $sid = $studentIDs[$sno] ?? null;
        if (!$sid) continue;
        foreach ($bsitSem1Subjects as $sc) {
            $subID = $subjectIDs[$sc] ?? null;
            if (!$subID) continue;
            $exists = $db->fetchOne(
                "SELECT enrollmentID FROM enrollments WHERE studentID=:s AND subjectID=:sub AND acadYearID=:ay AND semester=1",
                [':s'=>$sid,':sub'=>$subID,':ay'=>$ayID]
            );
            if (!$exists) {
                $db->insert(
                    "INSERT INTO enrollments (dateofEnroll,acadYear,semester,studentID,subjectID,programID,sectionID,acadYearID,status)
                     VALUES (NOW(),'2024-2025',1,:sid,:sub,:pid,:sec,:ay,'Active')",
                    [':sid'=>$sid,':sub'=>$subID,':pid'=>$progIDs['BSIT'],':sec'=>$bsitSection,':ay'=>$ayID]
                );
                $enrollCount++;
            }
        }
        $db->query("UPDATE students SET isEnroll=1 WHERE studentID=:id",[':id'=>$sid]);
    }
}

// BSCS student
if ($bscsSection) {
    $sid = $studentIDs['2024-00004'] ?? null;
    if ($sid) {
        foreach (['CS101','CS102','CS103'] as $sc) {
            $subID = $subjectIDs[$sc] ?? null;
            if (!$subID) continue;
            $exists = $db->fetchOne("SELECT enrollmentID FROM enrollments WHERE studentID=:s AND subjectID=:sub AND acadYearID=:ay",
                                    [':s'=>$sid,':sub'=>$subID,':ay'=>$ayID]);
            if (!$exists) {
                $db->insert("INSERT INTO enrollments (dateofEnroll,acadYear,semester,studentID,subjectID,programID,sectionID,acadYearID,status)
                             VALUES (NOW(),'2024-2025',1,:sid,:sub,:pid,:sec,:ay,'Active')",
                            [':sid'=>$sid,':sub'=>$subID,':pid'=>$progIDs['BSCS'],':sec'=>$bscsSection,':ay'=>$ayID]);
                $enrollCount++;
            }
        }
        $db->query("UPDATE students SET isEnroll=1 WHERE studentID=:id",[':id'=>$sid]);
    }
}

logMsg("✅ Created $enrollCount new enrollment records.");

} catch (Exception $e) {
    $errors[] = $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <title>ISARMS Setup</title>
  <link href="https://fonts.googleapis.com/css2?family=Source+Sans+3:wght@400;600;700&display=swap" rel="stylesheet"/>
  <style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:'Source Sans 3',sans-serif;background:#f0f4fb;padding:32px;color:#111}
    .container{max-width:760px;margin:0 auto}
    .header{background:linear-gradient(135deg,#0f2260,#1a3a8c);color:#fff;padding:28px 32px;border-radius:14px;margin-bottom:24px}
    .header h1{font-size:22px;font-weight:700;margin-bottom:4px}
    .header p{opacity:.75;font-size:13px}
    .log-item{padding:12px 16px;border-radius:9px;margin-bottom:6px;font-size:13.5px;display:flex;align-items:flex-start;gap:10px}
    .log-ok  {background:#dcfce7;color:#15803d}
    .log-info{background:#eff6ff;color:#1d4ed8}
    .log-err {background:#fee2e2;color:#dc2626}
    .accounts{background:#fff;border-radius:14px;padding:24px 28px;border:1px solid #dce4f0;margin-top:24px}
    .accounts h2{font-size:18px;font-weight:700;margin-bottom:16px;color:#1a3a8c}
    table{width:100%;border-collapse:collapse;font-size:13px}
    th{background:#1a3a8c;color:#fff;padding:10px 14px;text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.5px}
    td{padding:10px 14px;border-bottom:1px solid #e8eef8}
    tr:nth-child(even)td{background:#f7f9ff}
    .tag{display:inline-block;padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700}
    .tag-admin{background:#eff6ff;color:#1d4ed8}
    .tag-teacher{background:#f0fdf4;color:#15803d}
    .tag-reg{background:#fffbeb;color:#b45309}
    .tag-student{background:#f0f4ff;color:#1a3a8c}
    .warning{background:#fffbeb;border:1px solid #fde68a;padding:16px 20px;border-radius:10px;margin-top:20px;font-size:13.5px;color:#92400e}
    .btn{display:inline-block;padding:12px 24px;border-radius:10px;background:#1a3a8c;color:#fff;text-decoration:none;font-weight:700;font-size:14px;margin-top:16px}
  </style>
</head>
<body>
<div class="container">
  <div class="header">
    <h1>🎓 ISARMS First-Time Setup</h1>
    <p>NEUST Talavera Off-Campus — Integrated Student Academic Records Management System</p>
  </div>

  <?php foreach ($errors as $e): ?>
  <div class="log-item log-err"><i>❌</i> <?= htmlspecialchars($e) ?></div>
  <?php endforeach; ?>

  <?php foreach ($log as $l): ?>
  <div class="log-item <?= strpos($l['msg'],'ℹ️')===0?'log-info':'log-ok' ?>"><?= htmlspecialchars($l['msg']) ?></div>
  <?php endforeach; ?>

  <?php if (empty($errors)): ?>

  <div class="accounts">
    <h2>🔑 Demo Accounts Ready</h2>
    <table>
      <thead>
        <tr><th>Role</th><th>Full Name</th><th>Email</th><th>Password</th></tr>
      </thead>
      <tbody>
        <tr><td><span class="tag tag-admin">Admin</span></td><td>System Administrator</td><td>admin@neust.edu.ph</td><td><strong>admin2025</strong></td></tr>
        <tr><td><span class="tag tag-teacher">Teacher</span></td><td>Juan A. Dela Cruz</td><td>teacher@neust.edu.ph</td><td><strong>teacher2025</strong></td></tr>
        <tr><td><span class="tag tag-teacher">Teacher</span></td><td>Maria B. Santos</td><td>teacher2@neust.edu.ph</td><td><strong>teacher2025</strong></td></tr>
        <tr><td><span class="tag tag-reg">Registrar</span></td><td>Ana C. Reyes</td><td>registrar@neust.edu.ph</td><td><strong>registrar2025</strong></td></tr>
        <tr><td><span class="tag tag-student">Student</span></td><td>Juan M. Student</td><td>student@neust.edu.ph</td><td><strong>student2025</strong></td></tr>
        <tr><td><span class="tag tag-student">Student</span></td><td>Maria L. Cruz</td><td>student2@neust.edu.ph</td><td><strong>student2025</strong></td></tr>
        <tr><td><span class="tag tag-student">Student</span></td><td>Pedro A. Reyes</td><td>student3@neust.edu.ph</td><td><strong>student2025</strong></td></tr>
        <tr><td><span class="tag tag-student">Student</span></td><td>Ana B. Santos (BSCS)</td><td>student4@neust.edu.ph</td><td><strong>student2025</strong></td></tr>
      </tbody>
    </table>

    <div style="margin-top:20px;padding:16px;background:#f0f4ff;border-radius:10px;font-size:13.5px;">
      <strong>📋 What was set up:</strong><br/>
      ✅ Academic Year 2024-2025 (Active) &bull;
      ✅ 3 Programs (BSIT, BSCS, BSBA) &bull;
      ✅ 12 Sample Subjects &bull;
      ✅ 3 Sections &bull;
      ✅ 4 Students enrolled in 1st Semester subjects<br/><br/>
      <strong>🎯 Next step:</strong> Login as <strong>Teacher</strong> → Encode Grades → Submit to Registrar → Registrar Approves → Check as Student!
    </div>

    <a href="index.php" class="btn">🚀 Go to Login Page</a>
  </div>

  <div class="warning">
    ⚠️ <strong>Security Notice:</strong> Delete or rename <code>setup.php</code> after setup is complete to prevent unauthorized access.
    <br/>In production: <code>rename setup.php to setup.php.bak</code> or delete it entirely.
  </div>

  <?php else: ?>
  <div class="log-item log-err">Setup encountered errors. Check your database connection in <code>includes/config.php</code>.</div>
  <?php endif; ?>
</div>
</body>
</html>
