<?php
/**
 * ISARMS - Enrollment Management
 * Accessible by: Admin, Registrar
 *
 * This page handles:
 * - Enrolling a student into subjects (one student, multiple subjects at once)
 * - Bulk enrollment (all students in a section into all section subjects)
 * - Viewing and dropping enrollments
 */
define('ROOT', dirname(__DIR__, 2));
require_once ROOT . '/includes/config.php';
require_once ROOT . '/includes/functions.php';
requireAuth('Admin', 'Registrar');

$db   = Database::getInstance();
$user = getCurrentUser();
$msg  = $err = '';

// ── Handle POST actions ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'enroll_student') {
        // Enroll a single student into selected subjects
        $studentID  = (int)($_POST['studentID']  ?? 0);
        $sectionID  = (int)($_POST['sectionID']  ?? 0);
        $acadYearID = (int)($_POST['acadYearID'] ?? 0);
        $semester   = (int)($_POST['semester']   ?? 0);
        $subjectIDs = $_POST['subjectIDs'] ?? [];

        if (!$studentID || !$sectionID || !$acadYearID || !$semester || empty($subjectIDs)) {
            $err = 'Please fill in all required fields and select at least one subject.';
        } else {
            $student = $db->fetchOne("SELECT programID, yearLevel FROM students WHERE studentID=:id", [':id'=>$studentID]);
            $ay      = $db->fetchOne("SELECT academicYear FROM acad_years WHERE acadYearID=:id", [':id'=>$acadYearID]);
            $studentNameRow = $db->fetchOne("SELECT fName, mName, lName, extName FROM students WHERE studentID=:id", [':id'=>$studentID]);
            $studentName = $studentNameRow ? fullName($studentNameRow) : "Student #{$studentID}";

            $enrolled = 0; $skipped = 0; $yearLevelSkipped = 0;
            foreach ($subjectIDs as $subjectID) {
                $subjectID = (int)$subjectID;

              $subjectAssignment = $db->fetchOne(
                "SELECT sub.yearLevel
                 FROM section_subjects ss
                 JOIN subjects sub ON sub.subjectID=ss.subjectID
                 WHERE ss.sectionID=:section AND ss.subjectID=:subject
                   AND ss.acadYearID=:ay AND ss.semester=:semester
                   AND ss.dateDeleted IS NULL AND sub.dateDeleted IS NULL",
                [':section'=>$sectionID, ':subject'=>$subjectID, ':ay'=>$acadYearID, ':semester'=>$semester]
              );
              if (!$subjectAssignment
                || !isSubjectYearAllowedForStudent((int)$student['yearLevel'], $subjectAssignment['yearLevel'])) {
                $yearLevelSkipped++;
                continue;
              }

                // Check for ANY existing row (active OR previously dropped) for this
                // studentID + subjectID + acadYearID + semester combo. The unique
                // key 'uq_enroll' in the DB is on these columns regardless of
                // dateDeleted/status, so a dropped row still blocks a plain INSERT.
                $existing = $db->fetchOne(
                    "SELECT enrollmentID, status, dateDeleted
                     FROM enrollments
                     WHERE studentID=:s AND subjectID=:sub AND acadYearID=:ay AND semester=:sem",
                    [':s'=>$studentID, ':sub'=>$subjectID, ':ay'=>$acadYearID, ':sem'=>$semester]
                );

                if ($existing && $existing['status'] === 'Active' && $existing['dateDeleted'] === null) {
                    // Already actively enrolled — nothing to do
                    $skipped++;
                    continue;
                }

                if ($existing) {
                    // A row already exists (previously dropped) — reactivate it
                    // instead of inserting a new row, to avoid violating uq_enroll.
                    $db->query(
                        "UPDATE enrollments
                         SET status='Active', dateDeleted=NULL, dateofEnroll=NOW(),
                             sectionID=:sec, programID=:pid, acadYear=:ay
                         WHERE enrollmentID=:id",
                        [
                            ':sec' => $sectionID,
                            ':pid' => $student['programID'],
                            ':ay'  => $ay['academicYear'],
                            ':id'  => $existing['enrollmentID'],
                        ]
                    );
                } else {
                    // No prior row for this combo — safe to insert fresh
                    $db->insert(
                        "INSERT INTO enrollments (dateofEnroll,acadYear,semester,studentID,subjectID,programID,sectionID,acadYearID,status)
                         VALUES (NOW(),:ay,:sem,:sid,:sub,:pid,:sec,:ayid,'Active')",
                        [
                            ':ay'   => $ay['academicYear'],
                            ':sem'  => $semester,
                            ':sid'  => $studentID,
                            ':sub'  => $subjectID,
                            ':pid'  => $student['programID'],
                            ':sec'  => $sectionID,
                            ':ayid' => $acadYearID,
                        ]
                    );
                }
                $enrolled++;
            }

            // Mark student as enrolled
            if ($enrolled > 0) {
                $db->query("UPDATE students SET isEnroll=1 WHERE studentID=:id", [':id'=>$studentID]);
              $subjectIDs = array_values(array_filter(array_map('intval', $subjectIDs)));
              $subjectPlaceholders = [];
              $teacherParams = [':sec'=>$sectionID, ':sem'=>$semester, ':ay'=>$acadYearID];
              foreach ($subjectIDs as $index => $subjectIDForNotification) {
                $placeholder = ':subject' . $index;
                $subjectPlaceholders[] = $placeholder;
                $teacherParams[$placeholder] = $subjectIDForNotification;
              }
              $teachers = $db->fetchAll(
                "SELECT DISTINCT teacherID FROM section_subjects
                 WHERE sectionID=:sec AND subjectID IN (" . implode(',', $subjectPlaceholders) . ")
                   AND semester=:sem AND acadYearID=:ay AND dateDeleted IS NULL",
                $teacherParams
              );
              foreach ($teachers as $teacher) {
                    createNotification((int)$teacher['teacherID'], 'User', 'Student Added to Subject', "Admin enrolled {$studentName} in one of your subjects.", 'Enrollment', 'Admin');
              }
                $msg = "Successfully registered for $enrolled subject(s)." . ($skipped > 0 ? " $skipped already registered (skipped)." : '');
                if ($yearLevelSkipped > 0) $msg .= " $yearLevelSkipped subject(s) skipped because they do not match the student's year level or section assignment.";
            } else {
                $err = $yearLevelSkipped > 0
                  ? 'No subjects were registered because they do not match the student year level or selected section assignment.'
                  : "No new registrations created. Student may already be registered for all selected subjects.";
            }
        }

    } elseif ($action === 'bulk_enroll') {
        // Enroll ALL students in a section into ALL subjects of that section
        $sectionID  = (int)($_POST['sectionID']  ?? 0);
        $acadYearID = (int)($_POST['acadYearID'] ?? 0);
        $semester   = (int)($_POST['semester']   ?? 0);

        if (!$sectionID || !$acadYearID || !$semester) {
            $err = 'Please select section, academic year, and semester.';
        } else {
            $ay = $db->fetchOne("SELECT academicYear FROM acad_years WHERE acadYearID=:id", [':id'=>$acadYearID]);

            // Get all students assigned to this section's program
            $section  = $db->fetchOne("SELECT programID FROM sections WHERE sectionID=:id", [':id'=>$sectionID]);
            $students = $db->fetchAll(
                "SELECT studentID, programID, yearLevel FROM students WHERE programID=:pid AND isActive=1 AND dateDeleted IS NULL",
                [':pid' => $section['programID']]
            );

            // Get subjects for this section from section_subjects (not all program subjects)
            $subjects = $db->fetchAll(
                "SELECT DISTINCT ss.subjectID, ss.semester, sub.yearLevel
                 FROM section_subjects ss
                 JOIN subjects sub ON sub.subjectID=ss.subjectID AND sub.dateDeleted IS NULL
                 WHERE ss.sectionID=:sid AND ss.acadYearID=:ay AND ss.semester=:sem AND ss.dateDeleted IS NULL",
                [':sid'=>$sectionID, ':ay'=>$acadYearID, ':sem'=>$semester]
            );

            if (empty($students)) { $err = 'No active students found for this program.'; }
            elseif (empty($subjects)) { $err = 'No subjects configured for this section and semester. Add subjects to the section first via Admin → Sections.'; }
            else {
                $enrolled = 0; $skipped = 0; $yearLevelSkipped = 0;
                foreach ($students as $stu) {
                    foreach ($subjects as $sub) {
                    if (!isSubjectYearAllowedForStudent((int)$stu['yearLevel'], $sub['yearLevel'])) {
                      $yearLevelSkipped++;
                      continue;
                    }

                        // Check for ANY existing row (active OR previously dropped)
                        // for this studentID + subjectID + acadYearID + semester
                        // combo — same reasoning as in enroll_student above.
                        $existing = $db->fetchOne(
                            "SELECT enrollmentID, status, dateDeleted
                             FROM enrollments
                             WHERE studentID=:s AND subjectID=:sub AND acadYearID=:ay AND semester=:sem",
                            [':s'=>$stu['studentID'],':sub'=>$sub['subjectID'],':ay'=>$acadYearID,':sem'=>$semester]
                        );

                        if ($existing && $existing['status'] === 'Active' && $existing['dateDeleted'] === null) {
                            $skipped++;
                            continue;
                        }

                        if ($existing) {
                            // Reactivate the previously dropped row instead of
                            // inserting, to avoid violating uq_enroll.
                            $db->query(
                                "UPDATE enrollments
                                 SET status='Active', dateDeleted=NULL, dateofEnroll=NOW(),
                                     sectionID=:sec, programID=:pid, acadYear=:ay
                                 WHERE enrollmentID=:id",
                                [
                                    ':sec' => $sectionID,
                                    ':pid' => $stu['programID'],
                                    ':ay'  => $ay['academicYear'],
                                    ':id'  => $existing['enrollmentID'],
                                ]
                            );
                        } else {
                            $db->insert(
                                "INSERT INTO enrollments (dateofEnroll,acadYear,semester,studentID,subjectID,programID,sectionID,acadYearID,status)
                                 VALUES (NOW(),:ay,:sem,:sid,:sub,:pid,:sec,:ayid,'Active')",
                                [':ay'=>$ay['academicYear'],':sem'=>$semester,':sid'=>$stu['studentID'],
                                 ':sub'=>$sub['subjectID'],':pid'=>$stu['programID'],':sec'=>$sectionID,':ayid'=>$acadYearID]
                            );
                        }
                        $enrolled++;
                    }
                    $db->query("UPDATE students SET isEnroll=1 WHERE studentID=:id", [':id'=>$stu['studentID']]);
                }
                $msg = "Section registration complete: $enrolled new registration(s)." . ($skipped > 0 ? " $skipped already existed (skipped)." : '');
                if ($yearLevelSkipped > 0) $msg .= " $yearLevelSkipped subject enrollment(s) skipped because the subject year did not match the student year.";
            }
        }

    } elseif ($action === 'drop') {
        $enrollmentID = (int)($_POST['enrollmentID'] ?? 0);
        if ($enrollmentID) {
            $db->query("UPDATE enrollments SET status='Dropped', dateDeleted=NOW() WHERE enrollmentID=:id", [':id'=>$enrollmentID]);
            // Check if student has any remaining active enrollments
            $enr = $db->fetchOne("SELECT studentID FROM enrollments WHERE enrollmentID=:id", [':id'=>$enrollmentID]);
            if ($enr) {
                $remaining = $db->fetchOne("SELECT COUNT(*) as c FROM enrollments WHERE studentID=:s AND status='Active' AND dateDeleted IS NULL", [':s'=>$enr['studentID']]);
                if ((int)($remaining['c']??0) === 0) {
                    $db->query("UPDATE students SET isEnroll=0 WHERE studentID=:id", [':id'=>$enr['studentID']]);
                }
            }
            $msg = 'Registration dropped successfully.';
        }
    }
}

// ── Page filters ───────────────────────────────────────────
$viewMode   = $_GET['view']       ?? 'enroll';   // 'enroll' | 'list'
$selSection = (int)($_GET['section_id']  ?? 0);
$selStudent = (int)($_GET['student_id']  ?? 0);
$selAY      = (int)($_GET['acad_year_id']?? 0);

// Dropdowns
$acadYears = $db->fetchAll("SELECT acadYearID, academicYear, status FROM acad_years WHERE dateDeleted IS NULL AND status='Active' ORDER BY academicYear DESC");
$activeAY  = $db->fetchOne("SELECT acadYearID, academicYear FROM acad_years WHERE status='Active' LIMIT 1");
$programs  = $db->fetchAll("SELECT programID, programCode, programName FROM programs WHERE dateDeleted IS NULL ORDER BY programCode");
$sections  = $db->fetchAll("
  SELECT sec.sectionID, sec.sectionName, p.programCode, p.programID,
       ay.academicYear, ay.acadYearID, ay.status as ayStatus
  FROM sections sec
  JOIN programs p ON p.programID=sec.programID
  JOIN acad_years ay ON ay.acadYearID=sec.acadYearID
  WHERE sec.dateDeleted IS NULL
    AND ay.dateDeleted IS NULL
  ORDER BY ay.status='Active' DESC, ay.academicYear DESC, sec.sectionName
", []);

// Students for dropdown (filter by section's program if section selected)
$studentsForSelect = [];
if ($selSection) {
    $secInfo = $db->fetchOne("SELECT s.programID FROM sections s WHERE s.sectionID=:id",[':id'=>$selSection]);
    if ($secInfo) {
        $studentsForSelect = $db->fetchAll(
            "SELECT studentID, CONCAT(lName,', ',fName,' ',COALESCE(SUBSTRING(mName,1,1),''),'.') AS name, studentNo
             FROM students WHERE programID=:pid AND isActive=1 AND dateDeleted IS NULL ORDER BY lName",
            [':pid'=>$secInfo['programID']]
        );
    }
} else {
    $studentsForSelect = $db->fetchAll(
        "SELECT studentID, CONCAT(lName,', ',fName,' ',COALESCE(SUBSTRING(mName,1,1),''),'.') AS name, studentNo
         FROM students WHERE isActive=1 AND dateDeleted IS NULL ORDER BY lName"
    );
}

// Subjects available for selected section
$subjectsForSection = [];
if ($selSection) {
    // Only show subjects that are actually configured in this section via section_subjects
    $subjectsForSection = $db->fetchAll(
        "SELECT ss.subjectID, sub.subjectCode, sub.subjectName, sub.units, ss.semester,
                CONCAT(u.lName,', ',u.fName) AS teacherName
         FROM section_subjects ss
         JOIN subjects sub ON sub.subjectID=ss.subjectID
         JOIN users    u   ON u.userID     =ss.teacherID
         WHERE ss.sectionID=:sid AND ss.dateDeleted IS NULL
         ORDER BY ss.semester, sub.subjectCode",
        [':sid' => $selSection]
    );
}

// Enrollment list view
$enrollmentList = [];
if ($viewMode === 'list') {
    $where  = ["e.dateDeleted IS NULL","e.status='Active'"]; $params = [];
    if ($selSection) { $where[]="e.sectionID=:sec"; $params[':sec']=$selSection; }
    if ($selStudent) { $where[]="e.studentID=:stu"; $params[':stu']=$selStudent; }
    if ($selAY)      { $where[]="e.acadYearID=:ay";  $params[':ay']=$selAY; }
    $enrollmentList = $db->fetchAll("
        SELECT e.enrollmentID, e.dateofEnroll, e.semester,
               CONCAT(s.lName,', ',s.fName) AS studentName, s.studentNo, s.studentID,
               sub.subjectCode, sub.subjectName, sub.units,
               sec.sectionName, p.programCode, ay.academicYear,
               g.status AS gradeStatus, g.gradeEquivalent
        FROM enrollments e
        JOIN students   s   ON s.studentID   = e.studentID
        JOIN subjects   sub ON sub.subjectID  = e.subjectID
        JOIN sections   sec ON sec.sectionID  = e.sectionID
        JOIN programs   p   ON p.programID    = e.programID
        JOIN acad_years ay  ON ay.acadYearID  = e.acadYearID
        LEFT JOIN grades g  ON g.enrollmentID = e.enrollmentID AND g.dateDeleted IS NULL
        WHERE " . implode(' AND ',$where) . "
        ORDER BY ay.academicYear DESC, s.lName, sub.subjectCode
        LIMIT 200
    ", $params);
}

$pageTitle  = 'Student Registration';
$activeMenu = 'enrollment.php';
require_once ROOT . '/includes/header.php';
$csrf = generateCSRF();
?>

<?php if ($msg): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger"><i class="fas fa-times-circle"></i> <?= htmlspecialchars($err) ?></div><?php endif; ?>

<!-- Mode Tabs -->
<div style="display:flex;gap:10px;margin-bottom:20px;">
  <a href="?view=enroll" style="text-decoration:none;">
    <div style="padding:11px 22px;border-radius:10px;font-size:13.5px;font-weight:600;
      background:<?= $viewMode==='enroll'?'var(--blue)':'#fff' ?>;
      color:<?= $viewMode==='enroll'?'#fff':'var(--text)' ?>;
      border:1.5px solid <?= $viewMode==='enroll'?'var(--blue)':'var(--border)' ?>;
      box-shadow:var(--shadow-sm);">
      <i class="fas fa-user-plus"></i> Register Student
    </div>
  </a>
  <a href="?view=bulk" style="text-decoration:none;">
    <div style="padding:11px 22px;border-radius:10px;font-size:13.5px;font-weight:600;
      background:<?= $viewMode==='bulk'?'var(--blue)':'#fff' ?>;
      color:<?= $viewMode==='bulk'?'#fff':'var(--text)' ?>;
      border:1.5px solid <?= $viewMode==='bulk'?'var(--blue)':'var(--border)' ?>;
      box-shadow:var(--shadow-sm);">
      <i class="fas fa-users"></i> Register Entire Section
    </div>
  </a>
  <a href="?view=list" style="text-decoration:none;">
    <div style="padding:11px 22px;border-radius:10px;font-size:13.5px;font-weight:600;
      background:<?= $viewMode==='list'?'var(--blue)':'#fff' ?>;
      color:<?= $viewMode==='list'?'#fff':'var(--text)' ?>;
      border:1.5px solid <?= $viewMode==='list'?'var(--blue)':'var(--border)' ?>;
      box-shadow:var(--shadow-sm);">
      <i class="fas fa-list"></i> View Registrations
    </div>
  </a>
</div>

<!-- ═══════════════════════════════════════
     ENROLL SINGLE STUDENT
═══════════════════════════════════════ -->
<?php if ($viewMode === 'enroll'): ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start;">

  <!-- Step 1: Select student + section -->
  <div class="card">
    <div class="card-header">
      <div class="card-title"><i class="fas fa-user-graduate"></i> Step 1 — Select Student &amp; Section</div>
    </div>
    <div class="card-body">
      <form method="GET" id="sectionForm">
        <input type="hidden" name="view" value="enroll"/>
        <div style="margin-bottom:14px;">
          <label class="form-label">Section <span style="color:var(--danger);">*</span></label>
            <select name="section_id" class="form-control" id="sectionSelect"
              onchange="document.getElementById('sectionForm').submit()">
            <option value="">— Choose Section —</option>
            <?php foreach ($sections as $sec): ?>
            <option value="<?= $sec['sectionID'] ?>" <?= $selSection==$sec['sectionID']?'selected':'' ?>>
              <?= htmlspecialchars("{$sec['sectionName']} ({$sec['programCode']}) — {$sec['academicYear']}") ?>
              <?= $sec['ayStatus']==='Active'?' ★':'' ?>
            </option>
            <?php endforeach; ?>
          </select>
          <div style="font-size:11.5px;color:var(--text-muted);margin-top:5px;">
            ★ = Active academic year. Create sections in Admin → Sections first.
          </div>
        </div>

        <?php if ($selSection): ?>
        <div style="margin-bottom:14px;">
          <label class="form-label">Student <span style="color:var(--danger);">*</span></label>
          <select name="student_id" class="form-control" id="studentSelect">
            <option value="">— Choose Student —</option>
            <?php foreach ($studentsForSelect as $s): ?>
            <option value="<?= $s['studentID'] ?>" <?= $selStudent==$s['studentID']?'selected':'' ?>>
              <?= htmlspecialchars("{$s['name']} — {$s['studentNo']}") ?>
            </option>
            <?php endforeach; ?>
          </select>
          <div style="font-size:11.5px;color:var(--text-muted);margin-top:5px;">
            Only students matching this section's program are listed.
          </div>
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%;">
          <i class="fas fa-arrow-right"></i> Load Subjects
        </button>
        <?php else: ?>
        <div style="padding:16px;background:var(--blue-light);border-radius:10px;font-size:13px;color:var(--blue);">
          <i class="fas fa-arrow-up"></i> Select a section above to continue.
        </div>
        <?php endif; ?>
      </form>
    </div>
  </div>

  <!-- Step 2: Select subjects -->
  <div class="card">
    <div class="card-header">
      <div class="card-title"><i class="fas fa-book"></i> Step 2 — Select Subjects &amp; Register</div>
    </div>
    <div class="card-body">
      <?php if (!$selSection || !$selStudent): ?>
      <div style="text-align:center;padding:32px;color:var(--text-muted);">
        <i class="fas fa-book-open" style="font-size:40px;display:block;margin-bottom:12px;opacity:.2;color:var(--blue);"></i>
        Complete Step 1 to load available subjects.
      </div>
      <?php elseif (empty($subjectsForSection)): ?>
              <div class="alert alert-warning">
        <i class="fas fa-exclamation-triangle"></i>
        No subjects configured in this section yet. Add subjects in <a href="../admin/sections.php?section_id=<?= $selSection ?>" style="color:inherit;font-weight:700;">Admin → Sections → Add Subject</a> first, then return here to register students.
      </div>
      <?php else:
        $secInfo = $db->fetchOne("SELECT sec.*,ay.acadYearID,ay.academicYear FROM sections sec JOIN acad_years ay ON ay.acadYearID=sec.acadYearID WHERE sec.sectionID=:id",[':id'=>$selSection]);

        // Check which subjects student is already enrolled in
        $alreadyEnrolled = $db->fetchAll(
            "SELECT subjectID FROM enrollments WHERE studentID=:s AND acadYearID=:ay AND dateDeleted IS NULL AND status='Active'",
            [':s'=>$selStudent, ':ay'=>$secInfo['acadYearID']]
        );
        $enrolledSubIDs = array_column($alreadyEnrolled, 'subjectID');

        $studentInfo = $db->fetchOne("SELECT * FROM students WHERE studentID=:id",[':id'=>$selStudent]);
      ?>

      <div style="padding:12px 14px;background:var(--blue-light);border-radius:10px;margin-bottom:16px;font-size:13px;">
        <strong style="color:var(--blue);">Registering:</strong> <?= htmlspecialchars(fullName($studentInfo)) ?> (<?= $studentInfo['studentNo'] ?>)<br/>
        <strong style="color:var(--blue);">Section:</strong> <?= htmlspecialchars($secInfo['sectionName']) ?> — <?= $secInfo['academicYear'] ?>
      </div>

      <form method="POST">
        <input type="hidden" name="action"     value="enroll_student"/>
        <input type="hidden" name="studentID"  value="<?= $selStudent ?>"/>
        <input type="hidden" name="sectionID"  value="<?= $selSection ?>"/>
        <input type="hidden" name="acadYearID" value="<?= $secInfo['acadYearID'] ?>"/>

        <div style="margin-bottom:14px;">
          <label class="form-label">Semester <span style="color:var(--danger);">*</span></label>
          <select name="semester" class="form-control" id="semesterFilter" onchange="filterSubjects(this.value)">
            <option value="">— Select Semester —</option>
            <option value="1">1st Semester</option>
            <option value="2">2nd Semester</option>
          </select>
        </div>

        <div style="margin-bottom:6px;display:flex;align-items:center;justify-content:space-between;">
          <label class="form-label" style="margin:0;">Subjects</label>
          <div style="display:flex;gap:8px;">
            <button type="button" class="btn btn-outline btn-sm" onclick="selectAll(true)">Select All</button>
            <button type="button" class="btn btn-outline btn-sm" onclick="selectAll(false)">Deselect All</button>
          </div>
        </div>

        <div style="border:1.5px solid var(--border);border-radius:10px;overflow:hidden;max-height:320px;overflow-y:auto;" id="subjectList">
          <?php $sems = [1=>'1st Semester', 2=>'2nd Semester'];
          $bySem = [];
          foreach ($subjectsForSection as $sub) $bySem[$sub['semester']][] = $sub;
          foreach ($bySem as $sem => $subs): ?>
          <div class="sem-group" data-sem="<?= $sem ?>" style="border-bottom:2px solid var(--border);">
            <div style="padding:8px 14px;background:var(--bg);font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--blue);">
              <?= $sems[$sem] ?? $sem ?>
            </div>
            <?php foreach ($subs as $sub):
              $alreadyIn = in_array($sub['subjectID'], $enrolledSubIDs);
            ?>
            <label style="display:flex;align-items:center;gap:12px;padding:11px 14px;cursor:<?= $alreadyIn?'default':'pointer' ?>;
                          border-bottom:1px solid var(--border);
                          background:<?= $alreadyIn?'#f0fdf4':'#fff' ?>;"
                   class="subject-row" data-sem="<?= $sub['semester'] ?>">
              <input type="checkbox" name="subjectIDs[]" value="<?= $sub['subjectID'] ?>"
                     class="sub-check"
                     <?= $alreadyIn ? 'checked disabled' : '' ?>
                     style="width:16px;height:16px;flex-shrink:0;"/>
              <div style="flex:1;">
                <div style="font-size:13.5px;font-weight:700;color:<?= $alreadyIn?'var(--success)':'var(--text)' ?>;">
                  <?= htmlspecialchars($sub['subjectCode']) ?>
                  <?php if ($alreadyIn): ?><span style="font-size:11px;font-weight:400;"> ✓ Already registered</span><?php endif; ?>
                </div>
                <div style="font-size:12px;color:var(--text-muted);"><?= htmlspecialchars($sub['subjectName']) ?></div>
              </div>
              <span style="font-size:12px;font-weight:700;color:var(--blue);flex-shrink:0;"><?= $sub['units'] ?>u</span>
            </label>
            <?php endforeach; ?>
          </div>
          <?php endforeach; ?>
        </div>

        <div style="margin-top:16px;">
          <button type="submit" class="btn btn-primary" style="width:100%;padding:13px;font-size:15px;">
            <i class="fas fa-user-check"></i> Register Selected Subjects
          </button>
        </div>
      </form>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════
     BULK ENROLL SECTION
═══════════════════════════════════════ -->
<?php elseif ($viewMode === 'bulk'): ?>

<style>
  .bulk-enrollment-layout {
    display: grid;
    grid-template-columns: minmax(0, 1.35fr) minmax(280px, .85fr);
    gap: 20px;
    align-items: start;
  }

  .bulk-checklist {
    margin-top: 0;
  }

  @media (max-width: 900px) {
    .bulk-enrollment-layout {
      grid-template-columns: 1fr;
    }
  }
</style>

<div class="bulk-enrollment-layout">
  <div class="card">
    <div class="card-header">
      <div class="card-title"><i class="fas fa-users"></i> Register Entire Section</div>
    </div>
    <div class="card-body">
      <div class="alert alert-info" style="margin-bottom:20px;">
        <i class="fas fa-info-circle"></i>
        This will register <strong>all active students</strong> matching the section's program into <strong>all subjects</strong>
        assigned to that program for the selected semester. Existing registrations are skipped automatically.
      </div>

      <form method="POST">
        <input type="hidden" name="action" value="bulk_enroll"/>

        <div style="margin-bottom:16px;">
          <label class="form-label">Section <span style="color:var(--danger);">*</span></label>
          <select name="sectionID" class="form-control" id="bulkSection" onchange="updateBulkInfo(this)">
            <option value="">— Choose Section —</option>
            <?php foreach ($sections as $sec): ?>
            <option value="<?= $sec['sectionID'] ?>"
                    data-program="<?= htmlspecialchars($sec['programCode']) ?>"
                    data-ay="<?= $sec['acadYearID'] ?>"
                    data-ayname="<?= htmlspecialchars($sec['academicYear']) ?>">
              <?= htmlspecialchars("{$sec['sectionName']} ({$sec['programCode']}) — {$sec['academicYear']}") ?>
              <?= $sec['ayStatus']==='Active'?' ★':'' ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div style="margin-bottom:16px;">
          <label class="form-label">Academic Year <span style="color:var(--danger);">*</span></label>
          <select name="acadYearID" class="form-control" id="bulkAY">
            <option value="">— Auto-filled from section —</option>
            <?php foreach ($acadYears as $ay): ?>
            <option value="<?= $ay['acadYearID'] ?>" <?= $ay['status']==='Active'?'selected':'' ?>>
              <?= htmlspecialchars($ay['academicYear']) ?><?= $ay['status']==='Active'?' (Active)':'' ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div style="margin-bottom:24px;">
          <label class="form-label">Semester <span style="color:var(--danger);">*</span></label>
          <select name="semester" class="form-control">
            <option value="">— Select Semester —</option>
            <option value="1">1st Semester</option>
            <option value="2">2nd Semester</option>
          </select>
        </div>

        <div id="bulkPreview" style="display:none;margin-bottom:20px;padding:16px;background:var(--blue-light);border-radius:10px;">
          <div style="font-size:13px;color:var(--blue);">
            <i class="fas fa-info-circle"></i> This will register students from program
            <strong id="bulkProgText">—</strong> into all subjects for the selected semester.
          </div>
        </div>

        <button type="submit" class="btn btn-primary" style="width:100%;padding:14px;font-size:15px;"
                onclick="return confirm('Register all students in this section? Existing registrations will be skipped.')">
          <i class="fas fa-users"></i> Register Entire Section
        </button>
      </form>
    </div>
  </div>

  <!-- Quick setup checklist -->
  <div class="card bulk-checklist">
    <div class="card-header"><div class="card-title"><i class="fas fa-tasks"></i> Pre-Registration Checklist</div></div>
    <div class="card-body" style="font-size:13.5px;">
      <?php
        $checks = [
          ['Active academic year set',      $activeAY !== null,      'Admin → Academic Years → Set Active'],
          ['Programs created',               !empty($programs),       'Admin → Programs'],
          ['Subjects added to programs',     $db->fetchOne("SELECT COUNT(*) as c FROM subjects WHERE dateDeleted IS NULL")['c']>0, 'Admin → Subjects'],
          ['Sections created + teacher assigned', !empty($sections),  'Admin → Sections'],
          ['Students registered',            $db->fetchOne("SELECT COUNT(*) as c FROM students WHERE isActive=1 AND dateDeleted IS NULL AND studentNo IS NOT NULL AND studentNo <> ''")['c']>0,  'Admin → Students → Add Student'],
          ['Teacher accounts created',       $db->fetchOne("SELECT COUNT(*) as c FROM users WHERE roleName='Teacher' AND isActive=1 AND dateDeleted IS NULL AND fName IS NOT NULL AND fName <> '' AND lName IS NOT NULL AND lName <> ''")['c']>0, 'Admin → Users → Add User (Teacher)'],
        ];
      ?>
      <div style="display:flex;flex-direction:column;gap:10px;">
        <?php foreach ($checks as [$label,$ok,$fix]): ?>
        <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 14px;border-radius:9px;background:<?= $ok?'#dcfce7':'#fef2f2' ?>;">
          <div style="display:flex;align-items:center;gap:10px;">
            <i class="fas <?= $ok?'fa-check-circle':'fa-times-circle' ?>" style="color:<?= $ok?'var(--success)':'var(--danger)' ?>;font-size:16px;"></i>
            <span style="font-weight:600;color:<?= $ok?'var(--success)':'var(--danger)' ?>;"><?= $label ?></span>
          </div>
          <?php if (!$ok): ?>
          <span style="font-size:11.5px;color:var(--text-muted);">→ <?= $fix ?></span>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════
     VIEW ENROLLMENT LIST
═══════════════════════════════════════ -->
<?php elseif ($viewMode === 'list'): ?>

<div class="card" style="margin-bottom:14px;">
  <div class="card-body" style="padding:13px 18px;">
    <form method="GET" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
      <input type="hidden" name="view" value="list"/>
      <div>
        <label class="form-label">Section</label>
        <select name="section_id" id="listSectionSelect" class="form-control">
          <option value="">All Sections</option>
          <?php foreach ($sections as $sec): ?>
          <option value="<?= $sec['sectionID'] ?>" <?= $selSection==$sec['sectionID']?'selected':'' ?>>
            <?= htmlspecialchars("{$sec['sectionName']} ({$sec['programCode']})") ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="form-label">Student</label>
        <select name="student_id" id="listStudentSelect" class="form-control">
          <option value="">All Students</option>
          <?php foreach ($studentsForSelect as $s): ?>
          <option value="<?= $s['studentID'] ?>" <?= $selStudent==$s['studentID']?'selected':'' ?>>
            <?= htmlspecialchars($s['name']) ?> (<?= $s['studentNo'] ?>)
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="form-label">Academic Year</label>
        <select name="acad_year_id" id="listAYSelect" class="form-control">
          <option value="">All Years</option>
          <?php foreach ($acadYears as $ay): ?>
          <option value="<?= $ay['acadYearID'] ?>" <?= $selAY==$ay['acadYearID']?'selected':'' ?>>
            <?= htmlspecialchars($ay['academicYear']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter"></i> Filter</button>
      <a href="?view=list" class="btn btn-outline btn-sm"><i class="fas fa-times"></i></a>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header">
    <div class="card-title"><i class="fas fa-list"></i> Active Registrations</div>
    <span style="font-size:13px;color:var(--text-muted);"><?= count($enrollmentList) ?> record(s)</span>
  </div>
  <div style="overflow-x:auto;">
    <?php if (empty($enrollmentList)): ?>
    <div style="padding:48px;text-align:center;color:var(--text-muted);">
      <i class="fas fa-inbox" style="font-size:44px;display:block;margin-bottom:14px;opacity:.2;color:var(--blue);"></i>
      <div style="font-weight:600;margin-bottom:6px;">No registrations found</div>
      <div style="font-size:13px;">Use <a href="?view=enroll" style="color:var(--blue);font-weight:600;">Register Student</a> or
        <a href="?view=bulk" style="color:var(--blue);font-weight:600;">Register Entire Section</a> to add registrations.</div>
    </div>
    <?php else: ?>
    <table class="data-table">
      <thead>
        <tr>
          <th>#</th>
          <th>Student</th>
          <th>Subject</th>
          <th>Section</th>
          <th>Program</th>
          <th style="text-align:center;">Sem</th>
          <th>A.Y.</th>
          <th style="text-align:center;">Grade Status</th>
          <th style="text-align:center;">Registered On</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($enrollmentList as $i => $e): ?>
        <tr>
          <td style="color:var(--text-muted);font-size:12px;"><?= $i+1 ?></td>
          <td>
            <div style="font-weight:700;font-size:13px;"><?= htmlspecialchars($e['studentName']) ?></div>
            <div style="font-size:11px;color:var(--blue);font-weight:600;"><?= $e['studentNo'] ?></div>
          </td>
          <td>
            <div style="font-weight:700;color:var(--blue);"><?= htmlspecialchars($e['subjectCode']) ?></div>
            <div style="font-size:11.5px;color:var(--text-muted);"><?= htmlspecialchars($e['subjectName']) ?> (<?= $e['units'] ?>u)</div>
          </td>
          <td style="font-size:13px;"><?= htmlspecialchars($e['sectionName']) ?></td>
          <td style="font-weight:700;color:var(--blue);"><?= $e['programCode'] ?></td>
          <td style="text-align:center;font-weight:700;"><?= $e['semester']==1?'1st':'2nd' ?></td>
          <td style="font-size:13px;"><?= $e['academicYear'] ?></td>
          <td style="text-align:center;">
            <?php $gs=$e['gradeStatus']??'Not Graded'; $gc=match($gs){'Approved'=>'status-approved','Submitted'=>'status-submitted','Pending'=>'status-pending','Flagged'=>'status-flagged',default=>''}; ?>
            <span class="status-badge <?= $gc ?>" style="font-size:11px;"><?= $gs ?></span>
          </td>
          <td style="text-align:center;font-size:12px;color:var(--text-muted);">
            <?= date('M d, Y', strtotime($e['dateofEnroll'])) ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<script>
function makeSearchable(selectId, placeholder) {
  var sel = document.getElementById(selectId);
  if (!sel) return;
  if (sel._searchCreated) return; sel._searchCreated = true;
  var wrapper = sel.parentNode;
  var input = document.createElement('input');
  input.type = 'text';
  input.className = 'form-control';
  input.placeholder = placeholder || 'Search...';
  input.style.marginBottom = '6px';
  wrapper.insertBefore(input, sel);
  input.addEventListener('input', function() {
    var term = input.value.trim().toLowerCase();
    var options = sel.options;
    var any = false;
    for (var i = 0; i < options.length; i++) {
      var opt = options[i];
      var txt = (opt.text || '').toLowerCase();
      if (!term || txt.indexOf(term) !== -1) { opt.hidden = false; any = true; }
      else { opt.hidden = true; }
    }
    if (!any) {
      if (!sel._noopt) {
        var o = document.createElement('option'); o.text = '— No matches —'; o.disabled = true; o.className = 'no-match';
        sel.appendChild(o); sel._noopt = o;
      }
    } else {
      if (sel._noopt) { try{ sel.removeChild(sel._noopt); }catch(e){} sel._noopt = null; }
    }
  });
}

// Hide all subjects by default until semester is selected and attach searchable inputs.
// NOTE: search inputs are intentionally NOT attached to the student selects
// (studentSelect, listStudentSelect) or to the Step 1 section select
// (sectionSelect) per request — those remain plain dropdowns.
document.addEventListener('DOMContentLoaded', function() {
  filterSubjects('');
  makeSearchable('listSectionSelect','Search sections...');
  makeSearchable('listAYSelect','Search academic years...');
  // Ensure bulk selects also get native searchable inputs
  makeSearchable('bulkSection','Search sections...');
  makeSearchable('bulkAY','Search academic years...');
});

function filterSubjects(sem) {
  document.querySelectorAll('.sem-group').forEach(g => {
    g.style.display = (sem && g.dataset.sem == sem) ? 'block' : 'none';
  });
  // Show message if no semester selected
  const subjectList = document.getElementById('subjectList');
  if (!sem) { subjectList.style.display = 'none'; }
  else { subjectList.style.display = 'block'; }
}

function selectAll(check) {
  document.querySelectorAll('.sub-check:not(:disabled)').forEach(cb => cb.checked = check);
}

function updateBulkInfo(sel) {
  const opt  = sel.options[sel.selectedIndex];
  const prog = opt.getAttribute('data-program') || '';
  const ayid = opt.getAttribute('data-ay')      || '';
  const aynm = opt.getAttribute('data-ayname')  || '';

  var ayEl = document.getElementById('bulkAY'); if (ayEl) ayEl.value = ayid;
  const preview = document.getElementById('bulkPreview');
  const text    = document.getElementById('bulkProgText');
  if (prog) {
    text.textContent = prog + ' (' + aynm + ')';
    preview.style.display = 'block';
  } else {
    preview.style.display = 'none';
  }
}
</script>

<!-- Add Section Modal (AJAX create + append to selects) -->
<div id="addSectionModal" style="display:none;position:fixed;inset:0;align-items:center;justify-content:center;background:rgba(0,0,0,.4);z-index:9999;">
  <div style="background:#fff;width:520px;border-radius:12px;overflow:hidden;box-shadow:0 8px 30px rgba(0,0,0,.3);">
    <div style="padding:14px 18px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;">
      <div style="font-weight:700;font-size:15px;">Create Section</div>
      <button onclick="closeModal('addSectionModal')" class="btn btn-outline btn-sm">Close</button>
    </div>
    <div style="padding:16px;">
      <form id="addSectionFormRegistrar">
        <input type="hidden" name="action" value="add_section" />
        <input type="hidden" name="ajax" value="1" />
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>" />
        <div style="margin-bottom:10px;">
          <label class="form-label">Section Name</label>
          <input type="text" name="sectionName" class="form-control" required />
        </div>
        <div style="margin-bottom:10px;">
          <label class="form-label">Program</label>
          <select name="programID" class="form-control">
            <option value="">— Choose Program —</option>
            <?php foreach ($programs as $p): ?>
            <option value="<?= $p['programID'] ?>"><?= htmlspecialchars($p['programCode'].' — '.$p['programName']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="margin-bottom:10px;">
          <label class="form-label">Academic Year</label>
          <select name="acadYearID" class="form-control">
            <option value="">— Choose Academic Year —</option>
            <?php foreach ($acadYears as $ay): ?>
            <option value="<?= $ay['acadYearID'] ?>"><?= htmlspecialchars($ay['academicYear']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="text-align:right;margin-top:12px;">
          <button type="submit" class="btn btn-primary">Create</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
  document.getElementById('addSectionFormRegistrar').addEventListener('submit', function(e){
  e.preventDefault();
  var f = e.target; var btn = f.querySelector('button[type=submit]'); btn.disabled = true;
  var fd = new FormData(f);
  fetch('<?= BASE_URL ?>modules/admin/sections.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(j => {
      if (j && j.success) {
        var star = j.ayStatus === 'Active' ? ' ★' : '';
        var text = j.sectionName + ' ('+ (j.programCode||'') +') — ' + (j.academicYear||'') + star;
        var adds = ['sectionSelect','bulkSection','listSectionSelect'];
        adds.forEach(function(id){
          var sel = document.getElementById(id); if (!sel) return;
          var o = document.createElement('option'); o.value = j.id; o.text = text; sel.appendChild(o);
        });
        showToast('Section added.');
        closeModal('addSectionModal');
        var s = document.getElementById('sectionSelect'); if (s) { s.value = j.id; try{ s.dispatchEvent(new Event('change')) }catch(e){} }
      } else {
        showToast(j.error || 'Failed to create section', 'error');
      }
    }).catch(err => { showToast('Network error', 'error'); })
    .finally(()=>{ btn.disabled = false; });
});

// Fetch latest sections and merge into selects
function refreshSections() {
  fetch('<?= BASE_URL ?>modules/admin/sections.php?list=1')
    .then(r => r.json())
    .then(j => {
      if (!j || !j.success) return;
      var secs = j.sections || [];
      ['sectionSelect','bulkSection','listSectionSelect'].forEach(function(id){
        var sel = document.getElementById(id); if (!sel) return;
        var cur = sel.value;
        secs.forEach(s => {
          if (!sel.querySelector('option[value="'+s.sectionID+'"]')) {
            var star = s.ayStatus === 'Active' ? ' ★' : '';
            var o = document.createElement('option'); o.value = s.sectionID; o.text = s.sectionName + ' (' + (s.programCode||'') + ') — ' + (s.academicYear||'') + star; sel.appendChild(o);
          }
        });
        sel.value = cur;
      });
      showToast('Sections updated.', 'info');
    }).catch(e => {});
}

// Auto-refresh when user returns to tab
document.addEventListener('visibilitychange', function(){ if (document.visibilityState === 'visible') refreshSections(); });

// SSE listener for instant section updates
if (typeof(EventSource) !== 'undefined') {
  try {
    var es = new EventSource('<?= BASE_URL ?>modules/admin/sections_sse.php');
    es.onmessage = function(e) {
      try {
        var payload = JSON.parse(e.data);
        var s = payload.section; if (!s) return;
        var star = s.ayStatus === 'Active' ? ' ★' : '';
        var text = s.sectionName + ' (' + (s.programCode||'') + ') — ' + (s.academicYear||'') + star;
        ['sectionSelect','bulkSection','listSectionSelect'].forEach(function(id){
          var sel = document.getElementById(id); if (!sel) return;
          if (!sel.querySelector('option[value="'+s.id+'"]')) {
            var o = document.createElement('option'); o.value = s.id; o.text = text; sel.appendChild(o);
          }
        });
      } catch(err) {}
    };
  } catch(err) {}
}
});
</script>

<?php require_once ROOT . '/includes/footer.php'; ?>