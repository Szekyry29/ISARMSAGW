<?php
define('ROOT', dirname(__DIR__, 2));
require_once ROOT . '/includes/config.php';
require_once ROOT . '/includes/functions.php';
requireAuth('Student');

$db        = Database::getInstance();
$user      = getCurrentUser();
$studentID = $user['id'];
$msg = $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        $err = 'Invalid request.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'change_password') {
            $current = $_POST['current_password'] ?? '';
            $new     = $_POST['new_password']     ?? '';
            $confirm = $_POST['confirm_password'] ?? '';
            $me = $db->fetchOne("SELECT password FROM students WHERE studentID=:id",[':id'=>$studentID]);
            if (!verifyPassword($current, $me['password'])) {
                $err = 'Current password is incorrect.';
            } elseif (strlen($new) < 8) {
                $err = 'New password must be at least 8 characters.';
            } elseif ($new !== $confirm) {
                $err = 'Passwords do not match.';
            } else {
                $db->query("UPDATE students SET password=:pw WHERE studentID=:id",[':pw'=>hashPassword($new),':id'=>$studentID]);
                $msg = 'Password changed successfully.';
            }
        } elseif ($action === 'update_address') {
            $currentAddress = sanitizeTextArea($_POST['currentAddress'] ?? '');
            $permanentAddress = sanitizeTextArea($_POST['permanentAddress'] ?? '');

            if (!validateAddress($currentAddress)) {
                $err = 'Please enter a valid current address.';
            } elseif (!validateAddress($permanentAddress)) {
                $err = 'Please enter a valid permanent address.';
            } else {
                $db->query("UPDATE students SET currentAddress=:ca, permanentAddress=:pa WHERE studentID=:id",
                    [':ca'=>$currentAddress,
                     ':pa'=>$permanentAddress,
                     ':id'=>$studentID]);
                $msg = 'Address information updated.';
            }
        } elseif ($action === 'upload_photo') {
          if (!empty($_FILES['photo']['name']) && ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $allowedMimeTypes = [
              'jpg' => ['image/jpeg'],
              'jpeg' => ['image/jpeg'],
              'png' => ['image/png'],
              'webp' => ['image/webp'],
            ];
            $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
            $size = (int)($_FILES['photo']['size'] ?? 0);
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES['photo']['tmp_name']);
            if (!isset($allowedMimeTypes[$ext]) || !in_array($mime, $allowedMimeTypes[$ext], true)) {
                    $err = 'Only JPG, PNG, or WEBP images are allowed.';
            } elseif ($size <= 0 || $size > 2 * 1024 * 1024 || !is_uploaded_file($_FILES['photo']['tmp_name'])) {
                    $err = 'Image must be under 2MB.';
                } else {
                    $uploadDir = ROOT . '/uploads/profiles/';
                    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
              $filename = 'student_' . $studentID . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                    if (move_uploaded_file($_FILES['photo']['tmp_name'], $uploadDir . $filename)) {
                        $db->query("UPDATE students SET profilePic=:pic WHERE studentID=:id",
                                   [':pic'=>'profiles/'.$filename,':id'=>$studentID]);
                        $_SESSION['profile_pic'] = 'profiles/' . $filename;
                        $msg = 'Profile photo updated.';
                    } else {
                        $err = 'Failed to upload photo. Check folder permissions.';
                    }
                }
            }
        }
    }
}

$student = $db->fetchOne("
    SELECT s.*, p.programCode, p.programName
    FROM students s JOIN programs p ON p.programID=s.programID
    WHERE s.studentID=:id
", [':id' => $studentID]);

$cumGWA = computeGWA($studentID);
$honor  = classifyLatinHonor($cumGWA['gwa'], $studentID);
$latestPeriod = getLatestEnrollmentPeriod($studentID);
$termGWA = $latestPeriod
  ? computeGWA($studentID, (int)$latestPeriod['acadYearID'], (int)$latestPeriod['semester'])
  : ['gwa'=>0, 'totalUnits'=>0];
$registeredUnits = (float)($db->fetchOne(
  "SELECT COALESCE(SUM(sub.units), 0) AS totalUnits
   FROM enrollments e
   JOIN subjects sub ON sub.subjectID=e.subjectID
   WHERE e.studentID=:sid AND e.status='Active' AND e.dateDeleted IS NULL",
  [':sid'=>$studentID]
)['totalUnits'] ?? 0);

/**
 * Full display name — "First M. Last Ext"
 * e.g. "Juan D. Dela Cruz Jr."
 */
$fullDisplay = $student['fName'];
if (!empty($student['mName'])) {
    $fullDisplay .= ' ' . mb_substr($student['mName'], 0, 1) . '.';
}
$fullDisplay .= ' ' . $student['lName'];
if (!empty($student['extName'])) {
    $fullDisplay .= ' ' . $student['extName'];
}

$csrf = generateCSRF();
$pageTitle  = 'My Profile';
$activeMenu = 'profile.php';
require_once ROOT . '/includes/header.php';
?>

<style>
/* ── Unified label style ── */
.field-label {
    font-size: 11px;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: .7px;
    font-weight: 600;
    margin-bottom: 4px;
    display: block;
}

/* ── Read-only / non-editable value style (gray) ── */
.readonly-value {
    font-size: 14px;
    font-weight: 500;
    color: #9ca3af;
}

/* ── Mobile-style list rows for Personal Information ── */
.info-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 13px 0;
    border-bottom: 1px solid var(--border);
}
.info-row:last-child {
    border-bottom: none;
}
.info-row-label {
    font-size: 11px;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: .7px;
    font-weight: 600;
    flex-shrink: 0;
    width: 140px;
}
.info-row-value {
    font-size: 14px;
    font-weight: 500;
    color: #9ca3af;
    text-align: right;
    flex: 1;
}

/* ── Academic summary read-only value ── */
.summary-value {
    font-size: 13px;
    font-weight: 500;
    color: #9ca3af;
}

/* ── Account security read-only value ── */
.security-value {
    font-size: 14px;
    font-weight: 500;
    color: #9ca3af;
    margin-top: 4px;
}

/* ── Full name styling in profile card (occupies full width) ── */
.profile-name {
    font-family: 'Playfair Display', serif;
    font-size: 18px;
    font-weight: 700;
    letter-spacing: .3px;
    width: 100%;
    display: block;
    text-align: center;
    word-break: break-word;
    line-height: 1.3;
    margin-bottom: 10px;
}
</style>

<?php if ($msg): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger"><i class="fas fa-times-circle"></i> <?= htmlspecialchars($err) ?></div><?php endif; ?>

<div style="display:grid;grid-template-columns:300px 1fr;gap:20px;align-items:start;">

  <!-- Left: Photo + Quick Info -->
  <div style="display:flex;flex-direction:column;gap:16px;">

    <!-- Profile Photo Card -->
    <div class="card">
      <div class="card-body" style="text-align:center;padding:28px 24px;">
        <div style="position:relative;display:inline-block;margin-bottom:16px;">
          <?php $pic = $student['profilePic']; ?>
          <div style="width:110px;height:110px;border-radius:50%;background:linear-gradient(135deg,var(--blue),var(--blue-mid));display:flex;align-items:center;justify-content:center;font-size:42px;font-weight:700;color:#fff;margin:0 auto;overflow:hidden;border:4px solid var(--gold);box-shadow:0 4px 20px rgba(26,58,140,.2);">
            <?php if ($pic): ?>
              <img src="<?= BASE_URL ?>uploads/<?= htmlspecialchars($pic) ?>" style="width:100%;height:100%;object-fit:cover;" alt="Profile"/>
            <?php else: ?>
              <?= strtoupper(substr($student['fName'],0,1)) ?>
            <?php endif; ?>
          </div>
        </div>

        <!-- Full visible name in profile card -->
        <div class="profile-name"><?= htmlspecialchars($fullDisplay) ?></div>

        <div style="font-size:13px;color:var(--text-muted);margin-bottom:10px;"><?= htmlspecialchars($student['studentNo']) ?></div>
        <?php if ($honor !== 'None'): ?>
        <span class="<?= honorBadgeClass($honor) ?>"><?= htmlspecialchars($honor) ?></span>
        <?php endif; ?>

        <!-- Photo Upload -->
        <form method="POST" enctype="multipart/form-data" style="margin-top:20px;">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>"/>
          <input type="hidden" name="action"     value="upload_photo"/>
          <label style="cursor:pointer;">
            <div class="btn btn-outline btn-sm" style="width:100%;justify-content:center;margin-bottom:8px;">
              <i class="fas fa-camera"></i> Change Photo
            </div>
            <input type="file" name="photo" accept="image/*" style="display:none;" onchange="this.form.submit()"/>
          </label>
          <div style="font-size:11px;color:var(--text-muted);">JPG, PNG or WEBP · max 2MB</div>
        </form>
      </div>
    </div>

    <!-- Academic Summary -->
    <div class="card">
      <div class="card-header"><div class="card-title"><i class="fas fa-chart-bar"></i> Academic Summary</div></div>
      <div class="card-body" style="padding:16px 20px;">
        <?php $rows = [
          ['Latest Semester GWA', $termGWA['gwa']>0 ? formatGWA($termGWA['gwa']) : 'N/A'],
          ['Registered Units', $registeredUnits],
          ['Latest Semester GWA Units', $termGWA['totalUnits']],
          ['Program',        $student['programCode']],
          ['Year Level',     'Year ' . $student['yearLevel']],
          ['Enrollment',     $student['isEnroll'] ? '<span style="color:var(--success);font-weight:600;">Enrolled</span>' : '<span class="summary-value">Not Enrolled</span>'],
          ['Account Status', $student['isActive']  ? '<span style="color:var(--success);font-weight:600;">Active</span>'   : '<span style="color:var(--danger);font-weight:600;">Inactive</span>'],
        ]; ?>
        <?php foreach ($rows as [$l,$v]): ?>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--border);">
          <span class="field-label" style="margin-bottom:0;"><?= $l ?></span>
          <span class="summary-value"><?= $v ?></span>
        </div>
        <?php endforeach; ?>
        <?php
          $isIrreg = isStudentIrregular($studentID);
          $hasBack = hasBackSubjects($studentID);
          if ($isIrreg || $hasBack):
        ?>
        <div style="padding:8px 0;border-bottom:1px solid var(--border);">
          <span class="field-label" style="margin-bottom:6px;">Student Status</span>
          <div>
            <?php
              $badges = '';
              if ($isIrreg) {
                $badges .= '<span class="status-badge" style="background:#FF9800;color:#fff;margin-right:4px;font-size:11px;font-weight:600;">IRREGULAR</span>';
              }
              if ($hasBack) {
                $badges .= '<span class="status-badge status-flagged" style="font-size:11px;font-weight:600;">HAS BACK</span>';
              }
              echo $badges;
            ?>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Right: Edit Forms -->
  <div style="display:flex;flex-direction:column;gap:16px;">

    <!-- Personal Information — phone/settings style, read-only gray values (full unmasked name here) -->
    <div class="card">
      <div class="card-header">
        <div class="card-title"><i class="fas fa-id-card"></i> Personal Information</div>
        <span style="font-size:12px;color:var(--text-muted);"><i class="fas fa-lock" style="margin-right:4px;"></i>Contact Registrar to update</span>
      </div>
      <div class="card-body" style="padding:0 20px;">
        <?php $fields = [
          ['First Name',    $student['fName']],
          ['Middle Name',   $student['mName'] ?: '—'],
          ['Last Name',     $student['lName']],
          ['Extension',     $student['extName'] ?: '—'],
          ['Date of Birth', date('F j, Y', strtotime($student['dateofBirth']))],
          ['Sex',           $student['sex']],
          ['Student No.',   $student['studentNo']],
          ['Email',         $student['email']],
          ['Program',       $student['programName']],
          ['Year Level',    'Year ' . $student['yearLevel']],
          ['Current Address', $student['currentAddress'] ?: '—'],
          ['Permanent Address', $student['permanentAddress'] ?: '—'],
        ]; ?>
        <?php foreach ($fields as [$label, $val]): ?>
        <div class="info-row">
          <span class="info-row-label"><?= $label ?></span>
          <span class="info-row-value"><?= htmlspecialchars($val) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Change Password + Account Security side by side -->
    <div style="display:grid;grid-template-columns:1.3fr 1fr;gap:16px;align-items:stretch;">

      <!-- Change Password -->
      <div class="card" style="display:flex;flex-direction:column;">
        <div class="card-header"><div class="card-title"><i class="fas fa-key"></i> Change Password</div></div>
        <div class="card-body" style="flex:1;display:flex;flex-direction:column;justify-content:space-between;">
          <form method="POST" style="display:flex;flex-direction:column;flex:1;justify-content:space-between;">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>"/>
            <input type="hidden" name="action"     value="change_password"/>
            <div style="display:flex;flex-direction:column;gap:14px;margin-bottom:20px;">
              <div>
                <label class="field-label">Current Password</label>
                <input name="current_password" type="password" class="form-control" required placeholder="Current password"/>
              </div>
              <div>
                <label class="field-label">New Password <span style="font-size:11px;color:var(--text-muted);text-transform:none;letter-spacing:0;font-weight:400;">(min. 8 chars)</span></label>
                <input name="new_password" type="password" class="form-control" required minlength="8" placeholder="New password"/>
              </div>
              <div>
                <label class="field-label">Confirm Password</label>
                <input name="confirm_password" type="password" class="form-control" required placeholder="Repeat password"/>
              </div>
            </div>
            <button type="submit" class="btn btn-primary" style="align-self:flex-start;"><i class="fas fa-lock"></i> Update Password</button>
          </form>
        </div>
      </div>

      <!-- Account Security -->
      <div class="card" style="display:flex;flex-direction:column;">
        <div class="card-header"><div class="card-title"><i class="fas fa-shield-alt"></i> Account Security</div></div>
        <div class="card-body" style="font-size:13px;flex:1;display:flex;flex-direction:column;justify-content:space-between;">
          <div style="display:flex;flex-direction:column;gap:10px;">
            <div style="padding:14px 18px;background:var(--bg);border-radius:10px;">
              <span class="field-label">Last Login</span>
              <div class="security-value"><?= $student['lastLogin'] ? date('F j, Y, g:i A', strtotime($student['lastLogin'])) : 'No login recorded' ?></div>
            </div>
            <div style="padding:14px 18px;background:var(--bg);border-radius:10px;">
              <span class="field-label">Account Created</span>
              <div class="security-value"><?= date('F j, Y', strtotime($student['dateCreated'])) ?></div>
            </div>
          </div>
          <div style="margin-top:14px;padding:12px 16px;background:var(--blue-light);border-radius:9px;font-size:12.5px;color:var(--blue);">
            <i class="fas fa-info-circle"></i>
            For security, always log out after using a shared computer. Do not share your password with anyone.
          </div>
        </div>
      </div>

    </div>
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