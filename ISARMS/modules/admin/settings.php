<?php
define('ROOT', dirname(__DIR__, 2));
require_once ROOT . '/includes/config.php';
require_once ROOT . '/includes/functions.php';
requireAuth('Admin');

$db   = Database::getInstance();
$user = getCurrentUser();
$msg  = $err = '';

// Values used to repopulate the profile form (defaults come from DB, overridden below on failed submit)
$formFName = $formMName = $formLName = null;

// Handle password change / profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) { $err = 'Invalid request.'; }
    else {
        $action = $_POST['action'] ?? '';
        if ($action === 'change_password') {
            $current = $_POST['current_password'] ?? '';
            $new     = $_POST['new_password']     ?? '';
            $confirm = $_POST['confirm_password'] ?? '';
            $me = $db->fetchOne("SELECT password FROM users WHERE userID=:id",[':id'=>$user['id']]);
            if (!verifyPassword($current, $me['password'])) { $err = 'Current password is incorrect.'; }
            elseif (strlen($new) < 8) { $err = 'New password must be at least 8 characters.'; }
            elseif ($new !== $confirm) { $err = 'Passwords do not match.'; }
            elseif (verifyPassword($new, $me['password'])) { $err = 'New password must be different from the current password.'; }
            else {
                $db->query("UPDATE users SET password=:pw WHERE userID=:id",[':pw'=>hashPassword($new),':id'=>$user['id']]);
                $msg = 'Password changed successfully.';
            }
        } elseif ($action === 'update_profile') {
            $fName = normalizeName($_POST['fName'] ?? '');
            $mName = normalizeName($_POST['mName'] ?? '');
            $lName = normalizeName($_POST['lName'] ?? '');

            // Preserve what the admin typed in case validation fails
            $formFName = $fName;
            $formMName = $mName;
            $formLName = $lName;

            if (!validateName($fName)) {
                $err = 'Please enter a valid first name without numbers.';
            } elseif ($mName !== '' && !validateName($mName)) {
                $err = 'Middle name may only contain letters, spaces, hyphens, apostrophes, and periods.';
            } elseif (!validateName($lName)) {
                $err = 'Please enter a valid last name without numbers.';
            } else {
                $db->query("UPDATE users SET fName=:fn,mName=:mn,lName=:ln WHERE userID=:id",
                    [':fn'=>$fName,':mn'=>$mName,':ln'=>$lName,':id'=>$user['id']]);
                // Refresh session name
                $updated = $db->fetchOne("SELECT fName,mName,lName,extName FROM users WHERE userID=:id",[':id'=>$user['id']]);
                $_SESSION['full_name'] = fullName($updated);
                $msg = 'Profile updated.';
                // Clear preserved values since the update succeeded
                $formFName = $formMName = $formLName = null;
            }
        }
    }
}

// Fetch fresh user data
$me = $db->fetchOne("SELECT * FROM users WHERE userID=:id",[':id'=>$user['id']]);

// Values to render in the profile form: preserved (on failed submit) or fresh from DB
$displayFName = $formFName ?? $me['fName'];
$displayMName = $formMName ?? ($me['mName'] ?? '');
$displayLName = $formLName ?? $me['lName'];

$csrf = generateCSRF();
$pageTitle = 'My Profile'; $activeMenu = 'settings.php';
require_once ROOT . '/includes/header.php';
?>

<?php if ($msg): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger"><i class="fas fa-times-circle"></i> <?= htmlspecialchars($err) ?></div><?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start;">

  <!-- Profile Settings (LEFT) -->
  <div class="card">
    <div class="card-header"><div class="card-title"><i class="fas fa-user-circle"></i> My Profile</div></div>
    <div class="card-body">
      <div style="display:flex;align-items:center;gap:16px;margin-bottom:24px;padding-bottom:24px;border-bottom:1px solid var(--border);">
        <div style="width:72px;height:72px;border-radius:50%;background:var(--green);display:flex;align-items:center;justify-content:center;font-size:28px;font-weight:700;color:#fff;flex-shrink:0;">
          <?= strtoupper(substr($me['fName'],0,1)) ?>
        </div>
        <div>
          <div style="font-family:'Playfair Display',serif;font-size:18px;font-weight:700;"><?= htmlspecialchars(fullName($me)) ?></div>
          <div style="font-size:13px;color:var(--text-muted);"><?= htmlspecialchars($me['email']) ?></div>
          <span class="role-badge role-<?= htmlspecialchars($me['roleName']) ?>" style="margin-top:6px;display:inline-block;"><?= htmlspecialchars($me['roleName']) ?></span>
        </div>
      </div>
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>"/>
        <input type="hidden" name="action"     value="update_profile"/>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
          <div><label class="form-label">First Name</label><input name="fName" class="form-control" value="<?= htmlspecialchars($displayFName) ?>" required/></div>
          <div><label class="form-label">Middle Name</label><input name="mName" class="form-control" value="<?= htmlspecialchars($displayMName) ?>"/></div>
        </div>

        <div style="margin-bottom:12px;">
          <label class="form-label">Last Name</label>
          <input name="lName" class="form-control" value="<?= htmlspecialchars($displayLName) ?>" required/>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:20px;">
          <div>
            <label class="form-label">Email Address</label>
            <input class="form-control" value="<?= htmlspecialchars($me['email']) ?>" disabled style="background:var(--bg);color:var(--text-muted);"/>
            <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">Cannot be changed here.</div>
          </div>
          <div>
            <label class="form-label">Role</label>
            <input class="form-control" value="<?= htmlspecialchars($me['roleName']) ?>" disabled style="background:var(--bg);color:var(--text-muted);"/>
            <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">Assigned by the system.</div>
          </div>
        </div>

        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update Profile</button>
      </form>
    </div>
  </div>

  <!-- Change Password (RIGHT) -->
  <div class="card">
    <div class="card-header"><div class="card-title"><i class="fas fa-lock"></i> Change Password</div></div>
    <div class="card-body">
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>"/>
        <input type="hidden" name="action"     value="change_password"/>
        <div style="margin-bottom:14px;">
          <label class="form-label">Current Password</label>
          <input name="current_password" type="password" class="form-control" required placeholder="Enter current password"/>
        </div>
        <div style="margin-bottom:14px;">
          <label class="form-label">New Password <span style="font-size:11px;color:var(--text-muted);">(min. 8 characters)</span></label>
          <input name="new_password" type="password" class="form-control" required placeholder="New password" minlength="8"/>
        </div>
        <div style="margin-bottom:20px;">
          <label class="form-label">Confirm New Password</label>
          <input name="confirm_password" type="password" class="form-control" required placeholder="Repeat new password"/>
        </div>
        <button type="submit" class="btn btn-primary"><i class="fas fa-key"></i> Change Password</button>
      </form>
    </div>
  </div>

</div>

<?php require_once ROOT . '/includes/footer.php'; ?>