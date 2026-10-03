<?php
define('ROOT', dirname(__DIR__, 2));
require_once ROOT . '/includes/config.php';
require_once ROOT . '/includes/functions.php';
requireAuth('Admin', 'Registrar');

$user      = getCurrentUser();
$db        = Database::getInstance();
$studentID = (int)($_GET['id'] ?? 0);
$editMode  = isset($_GET['edit']) && $_GET['edit'] == '1';
$msg = $err = '';

function studentSectionLabel($sectionName, $programCode = '') {
  $sectionName = trim((string)$sectionName);
  $programCode = trim((string)$programCode);
  if ($sectionName !== '' && $programCode !== '') {
    $sectionName = preg_replace('/^' . preg_quote($programCode, '/') . '\s*[-:\x{2013}\x{2014}]?\s*/iu', '', $sectionName);
  }
  return trim($sectionName);
}

if (!$studentID) { header('Location: students.php'); exit; }

$student = $db->fetchOne("
    SELECT s.*, p.programCode, p.programName
    FROM students s JOIN programs p ON p.programID=s.programID
    WHERE s.studentID=:id AND s.dateDeleted IS NULL
", [':id' => $studentID]);
if (!$student) { header('Location: students.php'); exit; }

// Handle student update and actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        $err = 'Invalid request.';
    } else {
        if ($_POST['action'] === 'update_student') {
            $fName = normalizeName($_POST['fName'] ?? '');
            $mName = normalizeName($_POST['mName'] ?? '');
            $lName = normalizeName($_POST['lName'] ?? '');
            $email = normalizeEmail($_POST['email'] ?? '');
            $sex = $_POST['sex'] ?? '';
            $dateofBirth = $_POST['dateofBirth'] ?? '';
            $currentAddress = sanitizeTextArea($_POST['currentAddress'] ?? '');
            $permanentAddress = sanitizeTextArea($_POST['permanentAddress'] ?? '');
            $yearLevel = (int)($_POST['yearLevel'] ?? 0);
            $programID = (int)($_POST['programID'] ?? 0);
            $isEnroll = isset($_POST['isEnroll']) ? 1 : 0;
            $isActive = isset($_POST['isActive']) ? 1 : 0;

            if (!validateName($fName)) {
                $err = 'Please enter a valid first name without numbers.';
            } elseif ($mName !== '' && !validateName($mName)) {
                $err = 'Middle name may only contain letters, spaces, hyphens, apostrophes, and periods.';
            } elseif (!validateName($lName)) {
                $err = 'Please enter a valid last name without numbers.';
            } elseif (!validateEmail($email)) {
                $err = 'Invalid email address.';
            } elseif (!validateDate($dateofBirth)) {
                $err = 'Invalid date of birth format. Use YYYY-MM-DD.';
            } elseif (!validateAddress($currentAddress)) {
                $err = 'Please enter a valid current address.';
            } elseif (!validateAddress($permanentAddress)) {
                $err = 'Please enter a valid permanent address.';
            } else {
                $db->query("UPDATE students SET 
                    fName=:fn, mName=:mn, lName=:ln, email=:em, sex=:sx, 
                    dateofBirth=:dob, currentAddress=:ca, permanentAddress=:pa,
                    yearLevel=:yl, programID=:pid, isEnroll=:en, isActive=:ia
                    WHERE studentID=:id", [
                    ':fn'=>$fName, ':mn'=>$mName, ':ln'=>$lName, ':em'=>$email,
                    ':sx'=>$sex, ':dob'=>$dateofBirth, ':ca'=>$currentAddress,
                    ':pa'=>$permanentAddress, ':yl'=>$yearLevel, ':pid'=>$programID,
                    ':en'=>$isEnroll, ':ia'=>$isActive, ':id'=>$studentID
                ]);
                $msg = 'Student information updated successfully.';
                notifyUsersByRole('Admin', 'Student Profile Updated', "{$user['name']} updated the profile of " . fullName($student) . '.', 'Student', 'Registrar');
            }
        } elseif ($_POST['action'] === 'graduate_student') {
            if ($student['isDropped']) {
                $err = 'A dropped student cannot be graduated.';
            } elseif (!canGraduateStudent($studentID)) {
                $err = 'Student does not qualify for graduation yet.';
            } else {
                $db->query("UPDATE students SET isGraduated=1, dateGraduated=NOW(), isEnroll=0, isActive=0 WHERE studentID=:id",[':id'=>$studentID]);
                $msg = 'Student marked as graduated.';
                notifyUsersByRole('Admin', 'Student Advanced', "{$user['name']} marked " . fullName($student) . ' as graduated.', 'Student', 'Registrar');
            }
        } elseif ($_POST['action'] === 'drop_student') {
            $db->query("UPDATE students SET isDropped=1, droppedAt=NOW(), isEnroll=0, isActive=0 WHERE studentID=:id",[':id'=>$studentID]);
            $msg = 'Student marked as dropped.';
            notifyUsersByRole('Admin', 'Student Dropped', "{$user['name']} dropped " . fullName($student) . '.', 'Student', 'Registrar');
        }

        // Refresh student data after action
        if (!$err) {
            $student = $db->fetchOne(" 
                SELECT s.*, p.programCode, p.programName
                FROM students s JOIN programs p ON p.programID=s.programID
                WHERE s.studentID=:id AND s.dateDeleted IS NULL
            ", [':id' => $studentID]);
        }
        $editMode = false;
    }
}

$programs = $db->fetchAll("SELECT programID, programCode, programName FROM programs WHERE dateDeleted IS NULL ORDER BY programCode");
$sections = $db->fetchAll("SELECT sec.sectionID, sec.sectionName, p.programCode FROM sections sec JOIN programs p ON p.programID=sec.programID WHERE sec.dateDeleted IS NULL ORDER BY sec.sectionName");

// GWA data
$cumGWA = computeGWA($studentID);
$honor  = classifyLatinHonor($cumGWA['gwa'], $studentID);
$latestPeriod = getLatestEnrollmentPeriod($studentID);
$termGWA = $latestPeriod
  ? computeGWA($studentID, (int)$latestPeriod['acadYearID'], (int)$latestPeriod['semester'])
  : ['gwa'=>0, 'totalUnits'=>0];

// Grades by academic year/semester
$grades = $db->fetchAll("
    SELECT g.gradeID, g.midterm, g.finals, g.computedGrade, g.gradeEquivalent,
           g.remarks, g.status, g.approvedAt, g.encodedAt,
           sub.subjectCode, sub.subjectName, sub.units,
           CONCAT(u.lName,', ',u.fName) AS teacherName,
           ay.academicYear, e.acadYearID, e.semester
    FROM grades g
    JOIN enrollments e  ON e.enrollmentID= g.enrollmentID
    JOIN subjects sub   ON sub.subjectID  = g.subjectID
    JOIN users u        ON u.userID       = g.teacherID
    JOIN acad_years ay  ON ay.acadYearID  = e.acadYearID
    WHERE g.studentID = :sid AND g.dateDeleted IS NULL
    ORDER BY ay.academicYear, e.semester, sub.subjectCode
", [':sid' => $studentID]);

// Group grades by year+semester
$grouped = [];
foreach ($grades as $g) {
    $key = $g['academicYear'] . '||' . $g['semester'];
    $grouped[$key][] = $g;
}
$termGrades = array_values(array_filter($grades, static fn($grade) => $latestPeriod
  && (int)$grade['acadYearID'] === (int)$latestPeriod['acadYearID']
  && (int)$grade['semester'] === (int)$latestPeriod['semester']));

// Registrar should return only to the registrar students list

$pageTitle  = 'Student Profile';
$activeMenu = 'students.php';
require_once ROOT . '/includes/header.php';
$csrf = generateCSRF();
?>

<!-- Back Button -->

<div class="page-toolbar">
  <div class="page-title-group">
    <span class="page-kicker">Student Profile</span>
    <h2><?= htmlspecialchars(fullName($student)) ?></h2>
  </div>
  <div class="toolbar-actions">
    <a href="students.php" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> Back to Students</a>
    <?php if (!$editMode): ?>
    <a href="?id=<?= $studentID ?>&edit=1" class="btn btn-primary btn-sm"><i class="fas fa-edit"></i> Edit Student</a>
    <?php if (!$student['isGraduated'] && !$student['isDropped'] && $student['yearLevel'] === 4): ?>
    <form method="POST" style="display:inline;">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>"/>
      <input type="hidden" name="action" value="graduate_student"/>
      <button type="submit" class="btn btn-success btn-sm" onclick="return confirm('Graduate this 4th year student?');">
        <i class="fas fa-user-graduate"></i> Graduate
      </button>
    </form>
    <?php endif; ?>
    <?php if (!$student['isDropped'] && !$student['isGraduated']): ?>
    <form method="POST" style="display:inline;">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>"/>
      <input type="hidden" name="action" value="drop_student"/>
      <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Mark this student as dropped?');">
        <i class="fas fa-user-slash"></i> Drop
      </button>
    </form>
    <?php endif; ?>
    <?php else: ?>
    <a href="?id=<?= $studentID ?>" class="btn btn-outline btn-sm"><i class="fas fa-times"></i> Cancel Edit</a>
    <?php endif; ?>
  </div>
</div>

<?php if ($msg): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger"><i class="fas fa-times-circle"></i> <?= htmlspecialchars($err) ?></div><?php endif; ?>

<?php if ($editMode): ?>
<!-- Edit Form -->
<div class="card" style="margin-bottom:20px;border:2px solid var(--blue);">
  <div class="card-header" style="background:var(--blue);color:#fff;">
    <div class="card-title"><i class="fas fa-edit"></i> Edit Student Information</div>
  </div>
  <div class="card-body">
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>"/>
      <input type="hidden" name="action" value="update_student"/>
      <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;">
        <div>
          <label class="form-label">First Name *</label>
          <input type="text" name="fName" class="form-control" value="<?= htmlspecialchars($student['fName']) ?>" required/>
        </div>
        <div>
          <label class="form-label">Middle Name</label>
          <input type="text" name="mName" class="form-control" value="<?= htmlspecialchars($student['mName'] ?? '') ?>"/>
        </div>
        <div>
          <label class="form-label">Last Name *</label>
          <input type="text" name="lName" class="form-control" value="<?= htmlspecialchars($student['lName']) ?>" required/>
        </div>
        <div>
          <label class="form-label">Email *</label>
          <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($student['email']) ?>" required/>
        </div>
        <div>
          <label class="form-label">Sex</label>
          <select name="sex" class="form-control">
            <option value="Male" <?= $student['sex']=='Male'?'selected':'' ?>>Male</option>
            <option value="Female" <?= $student['sex']=='Female'?'selected':'' ?>>Female</option>
          </select>
        </div>
        <div>
          <label class="form-label">Date of Birth</label>
          <input type="date" name="dateofBirth" class="form-control" value="<?= $student['dateofBirth'] ?>"/>
        </div>
        <div>
          <label class="form-label">Year Level</label>
          <select name="yearLevel" class="form-control">
            <?php foreach ([1=>'1st Year',2=>'2nd Year',3=>'3rd Year',4=>'4th Year'] as $y=>$l): ?>
            <option value="<?= $y ?>" <?= $student['yearLevel']==$y?'selected':'' ?>><?= $l ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="form-label">Course</label>
          <select name="programID" class="form-control">
            <?php foreach ($programs as $p): ?>
            <option value="<?= $p['programID'] ?>" <?= $student['programID']==$p['programID']?'selected':'' ?>><?= $p['programCode'] ?> - <?= $p['programName'] ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="form-label">Current Section</label>
          <div class="form-control" style="display:flex;align-items:center;min-height:42px;">
            <?php
              $currentSection = $db->fetchOne("SELECT e.sectionID, sec.sectionName, p.programCode
                  FROM enrollments e
                  JOIN sections sec ON sec.sectionID = e.sectionID
                  JOIN programs p ON p.programID = sec.programID
                  WHERE e.studentID=:sid AND e.status='Active' AND e.dateDeleted IS NULL
                  ORDER BY e.dateCreated DESC LIMIT 1", [':sid' => $studentID]);
              echo htmlspecialchars($currentSection ? studentSectionLabel($currentSection['sectionName'], $currentSection['programCode'] ?? '') : 'No active section');
            ?>
          </div>
        </div>
        <div>
          <label class="form-label">Current Address</label>
          <input type="text" name="currentAddress" class="form-control" value="<?= htmlspecialchars($student['currentAddress'] ?? '') ?>"/>
        </div>
        <div>
          <label class="form-label">Permanent Address</label>
          <input type="text" name="permanentAddress" class="form-control" value="<?= htmlspecialchars($student['permanentAddress'] ?? '') ?>"/>
        </div>
        <div>
          <label class="form-label" style="display:flex;align-items:center;gap:8px;margin-top:24px;">
            <input type="checkbox" name="isEnroll" <?= $student['isEnroll']?'checked':'' ?>/> Enrolled
          </label>
        </div>
        <div>
          <label class="form-label" style="display:flex;align-items:center;gap:8px;margin-top:24px;">
            <input type="checkbox" name="isActive" <?= $student['isActive']?'checked':'' ?>/> Active Account
          </label>
        </div>
      </div>
      <div style="margin-top:20px;">
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<!-- Profile Header -->
<div class="hero-banner">
  <div style="display:flex;align-items:center;gap:16px;">
    <div style="width:72px;height:72px;border-radius:50%;background:var(--gold);display:flex;align-items:center;justify-content:center;font-size:30px;font-weight:700;color:var(--green-dark);">
      <?= strtoupper(substr($student['fName'],0,1)) ?>
    </div>
    <div>
      <div class="hero-title"><?= htmlspecialchars(fullName($student)) ?></div>
      <div class="hero-subtitle">
        <span class="pill" style="margin-right:8px;"><i class="fas fa-id-card"></i><?= htmlspecialchars($student['studentNo']) ?></span>
        <span class="pill" style="margin-right:8px;"><i class="fas fa-graduation-cap"></i><?= htmlspecialchars($student['programCode']) ?> — Year <?= $student['yearLevel'] ?></span>
        <span class="pill"><i class="fas fa-envelope"></i><?= htmlspecialchars($student['email']) ?></span>
      </div>
      <?php if ($honor !== 'None'): ?>
      <div style="margin-top:10px;"><span class="<?= honorBadgeClass($honor) ?>"><?= htmlspecialchars($honor) ?></span></div>
      <?php endif; ?>
    </div>
  </div>
  <div style="text-align:right;">
    <div style="font-size:11px;opacity:.65;text-transform:uppercase;letter-spacing:1px;">Latest Semester GWA</div>
    <div style="font-size:44px;font-weight:700;color:var(--gold);line-height:1;"><?= $termGWA['gwa'] > 0 ? formatGWA($termGWA['gwa']) : 'N/A' ?></div>
    <div style="font-size:12px;opacity:.75;"><?= $termGWA['totalUnits'] ?> semester units &bull; <?= count($termGrades) ?> subjects</div>
  </div>
</div>

<div style="display:grid;grid-template-columns:280px 1fr;gap:20px;align-items:start;">

  <!-- Info Card -->
  <div style="display:flex;flex-direction:column;gap:16px;">
    <div class="card">
      <div class="card-header"><div class="card-title"><i class="fas fa-info-circle"></i> Student Details</div></div>
      <div class="card-body" style="font-size:13px;">
        <?php $details = [
          ['Date of Birth', date('F j, Y', strtotime($student['dateofBirth']))],
          ['Sex', $student['sex']],
          ['Course', htmlspecialchars($student['programName'])],
          ['Year Level', 'Year ' . $student['yearLevel']],
          ['Enrollment Status', $student['isEnroll'] ? '<span style="color:var(--success);font-weight:600;">Enrolled</span>' : 'Not Enrolled'],
          ['Account Status', $student['isActive'] ? '<span style="color:var(--success);">Active</span>' : '<span style="color:var(--danger);">Inactive</span>'],
          ['Last Login', $student['lastLogin'] ? date('M d, Y g:i A', strtotime($student['lastLogin'])) : 'Never'],
          ['Registered', date('M d, Y', strtotime($student['dateCreated']))],
        ]; ?>
        <?php foreach ($details as [$lbl,$val]): ?>
        <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border);">
          <span style="color:var(--text-muted);"><?= $lbl ?></span>
          <span style="font-weight:600;text-align:right;"><?= $val ?></span>
        </div>
        <?php endforeach; ?>
        <div style="padding:8px 0;border-bottom:1px solid var(--border);">
          <span style="color:var(--text-muted);">Student Status</span>
          <div style="margin-top:6px;">
            <?php
              $isIrreg = isStudentIrregular($student['studentID']);
              $hasBack = hasBackSubjects($student['studentID']);
              $badges = '';

              if ($student['isGraduated']) {
                $badges .= '<span class="status-badge status-approved" style="background:#10b981;color:#fff;font-size:11px;font-weight:600;">GRADUATED</span>';
              } elseif ($student['isDropped']) {
                $badges .= '<span class="status-badge status-flagged" style="background:#ef4444;color:#fff;font-size:11px;font-weight:600;">DROPPED</span>';
              } else {
                if ($isIrreg) {
                  $badges .= '<span class="status-badge" style="background:#FF9800;color:#fff;margin-right:4px;font-size:11px;font-weight:600;">IRREGULAR</span>';
                }
                if ($hasBack) {
                  $badges .= '<span class="status-badge status-flagged" style="font-size:11px;font-weight:600;">HAS BACK SUBJECTS</span>';
                }
              }

              if ($badges) {
                echo $badges;
              } else {
                echo '<span style="color:var(--text-muted);font-size:12px;">Regular, No back subjects</span>';
              }
            ?>
          </div>
        </div>
        <?php if ($student['currentAddress']): ?>
        <div style="padding:8px 0;border-bottom:1px solid var(--border);">
          <div style="color:var(--text-muted);margin-bottom:4px;">Address</div>
          <div style="font-size:12px;"><?= htmlspecialchars($student['currentAddress']) ?></div>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- GWA Summary -->
    <div class="card">
      <div class="card-header"><div class="card-title"><i class="fas fa-chart-bar"></i> GWA Summary</div></div>
      <div class="card-body" style="font-size:13px;">
        <div style="text-align:center;padding:16px 0;">
          <div style="font-size:40px;font-weight:700;color:var(--green);"><?= $termGWA['gwa']>0?formatGWA($termGWA['gwa']):'—' ?></div>
          <div style="color:var(--text-muted);">Latest Semester GWA</div>
          <?php if ($honor !== 'None'): ?>
          <div style="margin-top:10px;"><span class="<?= honorBadgeClass($honor) ?>"><?= htmlspecialchars($honor) ?></span></div>
          <?php endif; ?>
        </div>
        <div style="display:flex;justify-content:space-around;padding-top:12px;border-top:1px solid var(--border);">
          <div style="text-align:center;"><div style="font-size:20px;font-weight:700;"><?= $termGWA['totalUnits'] ?></div><div style="font-size:11px;color:var(--text-muted);">Semester Units</div></div>
          <div style="text-align:center;"><div style="font-size:20px;font-weight:700;"><?= count($termGrades) ?></div><div style="font-size:11px;color:var(--text-muted);">Subjects</div></div>
          <div style="text-align:center;"><div style="font-size:20px;font-weight:700;"><?= count(array_filter($termGrades,fn($g)=>in_array($g['status'], ['Posted', 'Approved'], true))) ?></div><div style="font-size:11px;color:var(--text-muted);">Official</div></div>
        </div>
      </div>
    </div>
  </div>

  <!-- Grades Section -->
  <div>
    <?php if (empty($grades)): ?>
    <div class="card">
      <div class="card-body" style="text-align:center;padding:60px;color:var(--text-muted);">
        <i class="fas fa-clipboard" style="font-size:48px;display:block;margin-bottom:16px;opacity:.3;"></i>
        No grade records found for this student.
      </div>
    </div>
    <?php else: ?>
    <?php foreach ($grouped as $key => $semGrades): ?>
      <?php [$ay,$sem] = explode('||',$key); ?>
      <?php $semGWA = 0; $sumUnits=0; $sumWeighted=0;
        foreach ($semGrades as $g) { if (in_array($g['status'], ['Posted', 'Approved'], true)&&$g['gradeEquivalent']) { $sumUnits+=$g['units']; $sumWeighted+=$g['gradeEquivalent']*$g['units']; }}
        $semGWA = $sumUnits>0 ? round($sumWeighted/$sumUnits,4) : 0;
      ?>
    <div class="card" style="margin-bottom:16px;">
      <div class="card-header" style="background:var(--green-light);">
        <div class="card-title">
          <i class="fas fa-calendar-alt"></i>
          <?= htmlspecialchars($ay) ?> — <?= $sem==1?'1st':'2nd' ?> Semester
        </div>
        <?php if ($semGWA > 0): ?>
        <div style="font-size:13px;font-weight:600;color:var(--green);">
          Semester GWA: <span style="font-size:18px;"><?= formatGWA($semGWA) ?></span>
          <?php if (classifyDeansListSemester($studentID, $db->fetchOne("SELECT acadYearID FROM acad_years WHERE academicYear=:ay",[':ay'=>$ay])['acadYearID']??0, (int)$sem)): ?>
          &nbsp;<span class="badge-deans" style="font-size:11px;">Dean's List</span>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
      <div style="overflow-x:auto;">
        <table class="data-table">
          <thead>
            <tr>
              <th>Subject</th>
              <th>Teacher</th>
              <th style="text-align:center;">Units</th>
              <th style="text-align:center;">Midterm</th>
              <th style="text-align:center;">Finals</th>
              <th style="text-align:center;">Grade</th>
              <th style="text-align:center;">Equiv.</th>
              <th style="text-align:center;">Remarks</th>
              <th style="text-align:center;">Status</th>
              <th style="text-align:center;">Breakdown</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($semGrades as $g): ?>
            <tr style="cursor:pointer;transition:background 0.2s;" onmouseover="this.style.background='rgba(0,0,0,0.02)'" onmouseout="this.style.background=''">
              <td>
                <div style="font-weight:600;font-size:13px;"><?= htmlspecialchars($g['subjectCode']) ?></div>
                <div style="font-size:11px;color:var(--text-muted);"><?= htmlspecialchars($g['subjectName']) ?></div>
              </td>
              <td style="font-size:12px;"><?= htmlspecialchars($g['teacherName']) ?></td>
              <td style="text-align:center;font-weight:600;"><?= $g['units'] ?></td>
              <td style="text-align:center;"><?= $g['midterm'] ?? '—' ?></td>
              <td style="text-align:center;"><?= $g['finals']  ?? '—' ?></td>
              <td style="text-align:center;font-weight:700;font-size:15px;color:var(--green);"><?= $g['computedGrade'] ?? '—' ?></td>
              <td style="text-align:center;font-weight:700;font-size:17px;color:<?= ($g['gradeEquivalent']??5)<=3?'var(--green)':'var(--danger)' ?>;">
                <?= $g['gradeEquivalent'] ? number_format($g['gradeEquivalent'],2) : '—' ?>
              </td>
              <td style="text-align:center;">
                <?php $rem=$g['remarks']??''; $rc=match($rem){'Passed'=>'status-approved','Failed'=>'status-flagged',default=>'status-pending'}; ?>
                <?= $rem?"<span class='status-badge $rc'>$rem</span>":'—' ?>
              </td>
              <td style="text-align:center;">
                <span class="status-badge <?= gradeStatusClass($g['status']) ?>"><?= $g['status'] ?></span>
              </td>
              <td style="text-align:center;">
                <?php if ($g['midterm'] !== null || $g['finals'] !== null): ?>
                  <button type="button" class="btn btn-sm btn-outline breakdown-btn" style="padding:2px 8px;font-size:11px;" title="View grade calculation breakdown"
                    data-subject-code="<?= htmlspecialchars($g['subjectCode']) ?>"
                    data-subject-name="<?= htmlspecialchars($g['subjectName']) ?>"
                    data-teacher-name="<?= htmlspecialchars($g['teacherName']) ?>"
                    data-midterm="<?= htmlspecialchars($g['midterm']) ?>"
                    data-finals="<?= htmlspecialchars($g['finals']) ?>"
                    data-computed-grade="<?= htmlspecialchars($g['computedGrade']) ?>"
                    data-grade-equivalent="<?= htmlspecialchars($g['gradeEquivalent']) ?>"
                    data-encoded-at="<?= htmlspecialchars($g['encodedAt']) ?>"
                    data-approved-at="<?= htmlspecialchars($g['approvedAt']) ?>">
                    <i class="fas fa-calculator"></i> Breakdown
                  </button>
                <?php else: ?>
                  —
                <?php endif; ?>
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
</div>

<!-- Back Subjects Section -->
<?php 
$backSubjects = getBackSubjects($studentID);
if (!empty($backSubjects)):
?>
<div class="card" style="margin-top:20px;border-left:4px solid var(--danger);">
  <div class="card-header" style="background:rgba(244,67,54,0.08);">
    <div class="card-title"><i class="fas fa-exclamation-triangle" style="color:var(--danger);margin-right:8px;"></i> Back Subjects (Need to Retake)</div>
  </div>
  <div style="overflow-x:auto;">
    <table class="data-table">
      <thead>
        <tr>
          <th>Subject Code</th>
          <th>Subject Name</th>
          <th style="text-align:center;">Units</th>
          <th style="text-align:center;">Grade</th>
          <th style="text-align:center;">Status</th>
          <th style="text-align:center;">Academic Year</th>
          <th style="text-align:center;">Semester</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($backSubjects as $bs): ?>
        <tr style="background:rgba(244,67,54,0.04);">
          <td style="font-weight:600;color:var(--danger);"><?= htmlspecialchars($bs['subjectCode']) ?></td>
          <td><?= htmlspecialchars($bs['subjectName']) ?></td>
          <td style="text-align:center;font-weight:600;"><?= $bs['units'] ?></td>
          <td style="text-align:center;font-weight:700;">
            <?php if ($bs['gradeEquivalent'] !== null): ?>
              <span style="color:var(--danger);font-size:15px;"><?= number_format($bs['gradeEquivalent'],2) ?></span>
            <?php else: ?>
              <span style="color:var(--danger);font-weight:600;">INC (Incomplete)</span>
            <?php endif; ?>
          </td>
          <td style="text-align:center;">
            <span class="status-badge status-flagged" style="font-size:11px;">
              <?php if ($bs['gradeEquivalent'] !== null): ?>
                Failed
              <?php else: ?>
                Incomplete
              <?php endif; ?>
            </span>
          </td>
          <td style="text-align:center;font-size:12px;"><?= htmlspecialchars($bs['academicYear']) ?></td>
          <td style="text-align:center;font-size:12px;">
            <?= $bs['semester'] == 1 ? '1st Sem.' : '2nd Sem.' ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php endif; ?>

<!-- Grade Breakdown Modal -->
<div id="gradeBreakdownModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:9999;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:12px;box-shadow:0 10px 40px rgba(0,0,0,0.3);max-width:600px;width:90%;max-height:90vh;overflow-y:auto;">
    <div style="display:flex;justify-content:space-between;align-items:center;padding:20px 24px;border-bottom:1px solid var(--border);background:var(--light);">
      <div>
        <h3 style="margin:0;font-size:16px;font-weight:700;">Grade Computation Breakdown</h3>
        <div id="breakdownSubject" style="font-size:12px;color:var(--text-muted);margin-top:4px;"></div>
      </div>
      <button type="button" onclick="document.getElementById('gradeBreakdownModal').style.display='none'" style="background:none;border:none;font-size:20px;cursor:pointer;color:var(--text-muted);">×</button>
    </div>
    
    <div style="padding:28px 24px;">
      <!-- Grade Components -->
      <div style="background:var(--light);border-left:4px solid var(--blue);border-radius:8px;padding:20px;margin-bottom:24px;">
        <div style="font-weight:600;font-size:13px;text-transform:uppercase;color:var(--text-muted);letter-spacing:0.5px;margin-bottom:16px;">
          <i class="fas fa-chart-line" style="margin-right:6px;"></i>Grade Components
        </div>
        
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
          <div>
            <div style="font-size:11px;color:var(--text-muted);margin-bottom:6px;">Midterm Exam</div>
            <div style="font-size:28px;font-weight:700;color:var(--blue);" id="breakdownMidterm">—</div>
            <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">Weight: 40%</div>
          </div>
          
          <div>
            <div style="font-size:11px;color:var(--text-muted);margin-bottom:6px;">Final Exam</div>
            <div style="font-size:28px;font-weight:700;color:var(--blue);" id="breakdownFinals">—</div>
            <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">Weight: 60%</div>
          </div>
        </div>
      </div>

      <!-- Computation Formula -->
      <div style="background:rgba(76,175,80,0.05);border-left:4px solid var(--green);border-radius:8px;padding:20px;margin-bottom:24px;">
        <div style="font-weight:600;font-size:13px;text-transform:uppercase;color:var(--text-muted);letter-spacing:0.5px;margin-bottom:16px;">
          <i class="fas fa-calculator" style="margin-right:6px;"></i>Formula & Computation
        </div>
        
        <div style="background:#fff;border:1px solid var(--border);border-radius:6px;padding:16px;font-family:monospace;font-size:12px;line-height:1.8;margin-bottom:12px;">
          <div style="color:var(--text-muted);margin-bottom:8px;">Final Grade = (Midterm × 0.40) + (Finals × 0.60)</div>
          <div style="color:var(--green);font-weight:600;" id="breakdownFormula">—</div>
          <div style="color:var(--green);font-size:16px;font-weight:700;margin-top:12px;" id="breakdownComputedGrade">—</div>
        </div>
      </div>

      <!-- Grade Equivalent Conversion -->
      <div style="background:rgba(156,39,176,0.05);border-left:4px solid #9C27F0;border-radius:8px;padding:20px;margin-bottom:24px;">
        <div style="font-weight:600;font-size:13px;text-transform:uppercase;color:var(--text-muted);letter-spacing:0.5px;margin-bottom:16px;">
          <i class="fas fa-exchange-alt" style="margin-right:6px;"></i>Grade Equivalent Conversion
        </div>
        
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
          <div style="background:#fff;border:1px solid var(--border);border-radius:6px;padding:12px;text-align:center;">
            <div style="font-size:11px;color:var(--text-muted);margin-bottom:6px;">Numeric Grade</div>
            <div style="font-size:22px;font-weight:700;" id="breakdownNumericGrade">—</div>
          </div>
          <div style="background:#fff;border:1px solid var(--border);border-radius:6px;padding:12px;text-align:center;">
            <div style="font-size:11px;color:var(--text-muted);margin-bottom:6px;">Grade Equivalent</div>
            <div style="font-size:22px;font-weight:700;color:#9C27F0;" id="breakdownEquivalent">—</div>
          </div>
        </div>
        
        <div style="font-size:11px;color:var(--text-muted);background:#fff;border:1px solid var(--border);border-radius:6px;padding:12px;margin-top:12px;">
          <div style="font-weight:600;margin-bottom:8px;">NEUST Grade Scale:</div>
          <div style="line-height:1.8;">
            98+ = 1.00 | 95-97 = 1.25 | 92-94 = 1.50 | 89-91 = 1.75 | 86-88 = 2.00 | 83-85 = 2.25 | 80-82 = 2.50 | 77-79 = 2.75 | 75-76 = 3.00 | Below 75 = 5.00
          </div>
        </div>
      </div>

      <!-- Grade Status & Remarks -->
      <div style="background:var(--light);border:1px solid var(--border);border-radius:8px;padding:16px;">
        <div style="font-weight:600;font-size:13px;text-transform:uppercase;color:var(--text-muted);letter-spacing:0.5px;margin-bottom:12px;">
          <i class="fas fa-info-circle" style="margin-right:6px;"></i>Status Information
        </div>
        
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;font-size:12px;">
          <div>
            <span style="color:var(--text-muted);">Encoded Date:</span><br>
            <span id="breakdownEncodedAt" style="font-weight:600;">—</span>
          </div>
          <div>
            <span style="color:var(--text-muted);">Approved Date:</span><br>
            <span id="breakdownApprovedAt" style="font-weight:600;">—</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
function showGradeBreakdown(event, gradeData) {
  event.stopPropagation();
  
  // Populate modal with grade data
  document.getElementById('breakdownSubject').textContent = gradeData.subjectCode + ' - ' + gradeData.subjectName + ' (by ' + gradeData.teacherName + ')';
  
  const midterm = gradeData.midterm !== undefined && gradeData.midterm !== '' ? parseFloat(gradeData.midterm) : null;
  const finals = gradeData.finals !== undefined && gradeData.finals !== '' ? parseFloat(gradeData.finals) : null;
  const computedGrade = gradeData.computedGrade !== undefined && gradeData.computedGrade !== '' ? parseFloat(gradeData.computedGrade) : null;
  const gradeEquivalent = gradeData.gradeEquivalent !== undefined && gradeData.gradeEquivalent !== '' ? parseFloat(gradeData.gradeEquivalent) : null;
  
  // Midterm & Finals
  document.getElementById('breakdownMidterm').textContent = midterm !== null ? midterm.toFixed(2) : '—';
  document.getElementById('breakdownFinals').textContent = finals !== null ? finals.toFixed(2) : '—';
  
  // Computation Formula
  if (midterm !== null && finals !== null) {
    const midtermContribution = (midterm * 0.40).toFixed(2);
    const finalsContribution = (finals * 0.60).toFixed(2);
    document.getElementById('breakdownFormula').textContent = 
      '(' + midterm.toFixed(2) + ' × 0.40) + (' + finals.toFixed(2) + ' × 0.60) = ' + 
      midtermContribution + ' + ' + finalsContribution + ' = ' + computedGrade.toFixed(2);
  } else {
    document.getElementById('breakdownFormula').textContent = 'Grade not yet available';
  }
  
  document.getElementById('breakdownNumericGrade').textContent = computedGrade !== null ? computedGrade.toFixed(2) : '—';
  document.getElementById('breakdownComputedGrade').textContent = computedGrade !== null ? 'Final Grade: ' + computedGrade.toFixed(2) : '—';
  
  // Grade Equivalent
  if (gradeEquivalent !== null) {
    document.getElementById('breakdownEquivalent').textContent = gradeEquivalent.toFixed(2);
  } else {
    document.getElementById('breakdownEquivalent').textContent = '—';
  }
  
  // Status
  document.getElementById('breakdownEncodedAt').textContent = gradeData.encodedAt ? new Date(gradeData.encodedAt).toLocaleDateString('en-US', {year:'numeric',month:'short',day:'numeric'}) + ' ' + new Date(gradeData.encodedAt).toLocaleTimeString('en-US', {hour:'2-digit',minute:'2-digit'}) : '—';
  document.getElementById('breakdownApprovedAt').textContent = gradeData.approvedAt ? new Date(gradeData.approvedAt).toLocaleDateString('en-US', {year:'numeric',month:'short',day:'numeric'}) + ' ' + new Date(gradeData.approvedAt).toLocaleTimeString('en-US', {hour:'2-digit',minute:'2-digit'}) : 'Pending Approval';
  
  // Show modal
  document.getElementById('gradeBreakdownModal').style.display = 'flex';
}

function bindGradeBreakdownButtons() {
  document.querySelectorAll('.breakdown-btn').forEach(button => {
    button.addEventListener('click', function(event) {
      event.stopPropagation();
      const gradeData = {
        subjectCode: this.dataset.subjectCode,
        subjectName: this.dataset.subjectName,
        teacherName: this.dataset.teacherName,
        midterm: this.dataset.midterm,
        finals: this.dataset.finals,
        computedGrade: this.dataset.computedGrade,
        gradeEquivalent: this.dataset.gradeEquivalent,
        encodedAt: this.dataset.encodedAt,
        approvedAt: this.dataset.approvedAt,
      };
      showGradeBreakdown(event, gradeData);
    });
  });
}

// Close modal when clicking outside
document.getElementById('gradeBreakdownModal')?.addEventListener('click', function(e) {
  if (e.target === this) {
    this.style.display = 'none';
  }
});

bindGradeBreakdownButtons();
</script>

<?php require_once ROOT . '/includes/footer.php'; ?>
