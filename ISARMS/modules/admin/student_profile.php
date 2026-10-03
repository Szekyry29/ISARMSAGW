<?php
define('ROOT', dirname(__DIR__, 2));
require_once ROOT . '/includes/config.php';
require_once ROOT . '/includes/functions.php';
requireAuth('Admin', 'Registrar');

$db        = Database::getInstance();
$user      = getCurrentUser();
$studentID = (int)($_GET['id'] ?? 0);
$editMode  = isset($_GET['edit']) && $_GET['edit'] == '1';
$msg = $err = '';

if (!$studentID) { header('Location: students.php'); exit; }

$student = $db->fetchOne("
    SELECT s.*, p.programCode, p.programName
    FROM students s JOIN programs p ON p.programID=s.programID
    WHERE s.studentID=:id AND s.dateDeleted IS NULL
", [':id' => $studentID]);
if (!$student) { header('Location: students.php'); exit; }

// Handle student update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_student') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        $err = 'Invalid request.';
    } else {
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
            $otherRole = $user['role'] === 'Admin' ? 'Registrar' : 'Admin';
            notifyUsersByRole($otherRole, 'Student Record Updated', "{$user['name']} ({$user['role']}) updated the student record for " . fullName($student) . '.', 'Student', $user['role']);
            createNotification($studentID, 'Student', 'Student Record Updated', 'Your student information was updated by the ' . $user['role'] . '.', 'Student', $user['role']);
            // Refresh student data
            $student = $db->fetchOne("
                SELECT s.*, p.programCode, p.programName
                FROM students s JOIN programs p ON p.programID=s.programID
                WHERE s.studentID=:id AND s.dateDeleted IS NULL
            ", [':id' => $studentID]);
            $editMode = false;
        }
    }
}

$programs = $db->fetchAll("SELECT programID, programCode, programName FROM programs WHERE dateDeleted IS NULL ORDER BY programCode");

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

$pageTitle  = 'Student Profile';
$activeMenu = 'students.php';
require_once ROOT . '/includes/header.php';
$csrf = generateCSRF();
?>

<!-- Back Button -->

<div style="margin-bottom:16px;">
  <a href="students.php" class="btn btn-outline btn-sm"><i class="fas fa-arrow-left"></i> Back to Students</a>
  <?php if (!$editMode): ?>
  <a href="?id=<?= $studentID ?>&edit=1" class="btn btn-primary btn-sm" style="margin-left:8px;"><i class="fas fa-edit"></i> Edit Student</a>
  <?php else: ?>
  <a href="?id=<?= $studentID ?>" class="btn btn-outline btn-sm" style="margin-left:8px;"><i class="fas fa-times"></i> Cancel Edit</a>
  <?php endif; ?>
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
<div style="background:linear-gradient(135deg,var(--green-dark),var(--green));border-radius:16px;padding:28px 32px;margin-bottom:20px;color:#fff;display:grid;grid-template-columns:auto 1fr auto;gap:24px;align-items:center;">
  <div style="width:80px;height:80px;border-radius:50%;background:var(--gold);display:flex;align-items:center;justify-content:center;font-size:32px;font-weight:700;color:var(--green-dark);">
    <?= strtoupper(substr($student['fName'],0,1)) ?>
  </div>
  <div>
    <h2 style="font-family:'Playfair Display',serif;font-size:24px;margin-bottom:4px;"><?= htmlspecialchars(fullName($student)) ?></h2>
    <div style="opacity:.8;font-size:14px;line-height:2;">
      <span style="margin-right:16px;"><i class="fas fa-id-card" style="margin-right:5px;"></i><?= htmlspecialchars($student['studentNo']) ?></span>
      <span style="margin-right:16px;"><i class="fas fa-graduation-cap" style="margin-right:5px;"></i><?= htmlspecialchars($student['programCode']) ?> — Year <?= $student['yearLevel'] ?></span>
      <span><i class="fas fa-envelope" style="margin-right:5px;"></i><?= htmlspecialchars($student['email']) ?></span>
    </div>
    <?php if ($honor !== 'None'): ?>
    <div style="margin-top:8px;"><span class="<?= honorBadgeClass($honor) ?>"><?= htmlspecialchars($honor) ?></span></div>
    <?php endif; ?>
  </div>
  <div style="text-align:right;">
    <div style="font-size:11px;opacity:.6;text-transform:uppercase;letter-spacing:1px;">Latest Semester GWA</div>
    <div style="font-size:48px;font-weight:700;color:var(--gold);line-height:1;"><?= $termGWA['gwa'] > 0 ? formatGWA($termGWA['gwa']) : 'N/A' ?></div>
    <div style="font-size:12px;opacity:.7;"><?= $termGWA['totalUnits'] ?> semester units &bull; <?= count($termGrades) ?> subjects</div>
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
              
              if ($isIrreg) {
                $badges .= '<span class="status-badge" style="background:#FF9800;color:#fff;margin-right:4px;font-size:11px;font-weight:600;">IRREGULAR</span>';
              }
              if ($hasBack) {
                $badges .= '<span class="status-badge status-flagged" style="font-size:11px;font-weight:600;">HAS BACK SUBJECTS</span>';
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
            </tr>
          </thead>
          <tbody>
            <?php foreach ($semGrades as $g): ?>
            <tr>
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
                <span class="status-badge <?= gradeStatusClass($g['status']) ?>"><?= normalizeGradeStatusDisplay((string)($g['status'] ?? '')) ?></span>
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

<?php require_once ROOT . '/includes/footer.php'; ?>
