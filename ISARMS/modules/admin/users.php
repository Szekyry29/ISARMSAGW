<?php
/**
 * ISARMS - Admin: User Management
 * Manages Teachers, Registrars, and Admins
 */
define('ROOT', dirname(__DIR__, 2));
require_once ROOT . '/includes/config.php';
require_once ROOT . '/includes/functions.php';
requireAuth('Admin');

$db   = Database::getInstance();
$user = getCurrentUser();

// Handle inline actions via POST
$msg = $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        $err = 'Invalid request.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'add') {
            $email   = strtolower(trim($_POST['email'] ?? ''));
            $fName   = normalizeName($_POST['fName'] ?? '');
            $mName   = normalizeName($_POST['mName'] ?? '');
            $lName   = normalizeName($_POST['lName'] ?? '');
            $extName = normalizeName($_POST['extName'] ?? '');
            $role    = validateRoleName($_POST['roleName'] ?? 'Teacher');
            $program = !empty($_POST['programID']) ? (int)$_POST['programID'] : null;

            if (!validateName($fName)) {
                $err = 'Please enter a valid first name without numbers.';
            } elseif ($mName !== '' && !validateName($mName)) {
                $err = 'Middle name may only contain letters, spaces, hyphens, apostrophes, and periods.';
            } elseif (!validateName($lName)) {
                $err = 'Please enter a valid last name without numbers.';
            } elseif (!validateEmail($email)) {
                $err = 'Please enter a valid institutional email address.';
            } else {
                $exists = $db->fetchOne("SELECT userID FROM users WHERE email=:e AND dateDeleted IS NULL", [':e'=>$email]);
                if ($exists) {
                    $err = 'Email already exists in the system.';
                } else {
                    $db->insert(
                        "INSERT INTO users (fName,mName,lName,extName,email,password,roleName,programID)
                         VALUES (:fn,:mn,:ln,:en,:em,:pw,:rn,:pid)",
                        [
                            ':fn'  => $fName,
                            ':mn'  => $mName,
                            ':ln'  => $lName,
                            ':en'  => $extName,
                            ':em'  => $email,
                            ':pw'  => hashPassword($_POST['password'] ?? 'neust2025'),
                            ':rn'  => $role,
                            ':pid' => $program,
                        ]
                    );
                    $msg = 'User account created successfully.';
                }
            }
        } elseif ($action === 'edit') {
            $uid     = (int)($_POST['userID'] ?? 0);
            $fName   = normalizeName($_POST['fName'] ?? '');
            $mName   = normalizeName($_POST['mName'] ?? '');
            $lName   = normalizeName($_POST['lName'] ?? '');
            $extName = normalizeName($_POST['extName'] ?? '');
            $role    = validateRoleName($_POST['roleName'] ?? 'Teacher');
            $program = !empty($_POST['programID']) ? (int)$_POST['programID'] : null;

            if (!validateName($fName)) {
                $err = 'Please enter a valid first name without numbers.';
            } elseif ($mName !== '' && !validateName($mName)) {
                $err = 'Middle name may only contain letters, spaces, hyphens, apostrophes, and periods.';
            } elseif (!validateName($lName)) {
                $err = 'Please enter a valid last name without numbers.';
            } else {
                $db->query(
                    "UPDATE users SET fName=:fn,mName=:mn,lName=:ln,extName=:en,
                     roleName=:rn,programID=:pid,isActive=:ia WHERE userID=:id",
                    [
                        ':fn'  => $fName,
                        ':mn'  => $mName,
                        ':ln'  => $lName,
                        ':en'  => $extName,
                        ':rn'  => $role,
                        ':pid' => $program,
                        ':ia'  => (int)($_POST['isActive'] ?? 1),
                        ':id'  => $uid,
                    ]
                );
                if (!empty($_POST['new_password'])) {
                    $db->query("UPDATE users SET password=:pw WHERE userID=:id",
                               [':pw' => hashPassword($_POST['new_password']), ':id' => $uid]);
                }
                $msg = 'User updated successfully.';
            }
        } elseif ($action === 'deactivate') {
            // Deactivate: set dateDeleted + isActive=0
            $uid = (int)($_POST['userID'] ?? 0);
            if ($uid === $user['id']) {
                $err = 'You cannot deactivate your own account.';
            } else {
                $db->query("UPDATE users SET dateDeleted=NOW(), isActive=0 WHERE userID=:id", [':id'=>$uid]);
                $msg = 'User account deactivated.';
            }
        } elseif ($action === 'activate') {
            // Activate: restore user (clear dateDeleted + isActive=1)
            $uid = (int)($_POST['userID'] ?? 0);
            $db->query("UPDATE users SET dateDeleted=NULL, isActive=1 WHERE userID=:id", [':id'=>$uid]);
            $msg = 'User account activated successfully.';
        }
    }
}

// Filters
$roleFilter    = sanitize($_GET['role']    ?? '');
$search        = sanitize($_GET['search']  ?? '');
$statusFilter  = $_GET['status']  ?? '';
$archiveFilter = $_GET['archive'] ?? 'active'; // 'active' | 'archived' | 'all'

$where  = ["u.userID != :me"];
$params = [':me' => $user['id']];

// Archive / active scope
if ($archiveFilter === 'archived') {
    $where[] = "u.dateDeleted IS NOT NULL";
} elseif ($archiveFilter === 'all') {
    // no dateDeleted filter — show everything
} else {
    // default: active (not archived)
    $where[] = "u.dateDeleted IS NULL";
}

if ($roleFilter)          { $where[] = "u.roleName=:rn";      $params[':rn'] = $roleFilter; }
if ($search)              { $where[] = "(u.fName LIKE :s1 OR u.lName LIKE :s2 OR u.email LIKE :s3)"; $params[':s1'] = "%$search%"; $params[':s2'] = "%$search%"; $params[':s3'] = "%$search%"; }
if ($statusFilter !== '') { $where[] = "u.isActive=:ia";       $params[':ia'] = (int)$statusFilter; }

$whereSQL = implode(' AND ', $where);
$users    = $db->fetchAll("
    SELECT u.*, p.programCode
    FROM users u
    LEFT JOIN programs p ON p.programID = u.programID
    WHERE $whereSQL
    ORDER BY u.roleName, u.lName, u.fName
", $params);

$programs = $db->fetchAll("SELECT programID, programCode, programName FROM programs WHERE dateDeleted IS NULL ORDER BY programCode");

// Counts per role (active, non-archived only), including the signed-in admin.
$roleCounts = $db->fetchAll("SELECT roleName, COUNT(*) as cnt FROM users WHERE dateDeleted IS NULL AND isActive=1 GROUP BY roleName");
$rcMap = array_column($roleCounts, 'cnt', 'roleName');

// Archived count (for badge)
$archivedCount = $db->fetchOne("SELECT COUNT(*) as cnt FROM users WHERE dateDeleted IS NOT NULL AND userID!=:me", [':me'=>$user['id']])['cnt'] ?? 0;

$csrf = generateCSRF();
$pageTitle  = 'User Management';
$activeMenu = 'users.php';
require_once ROOT . '/includes/header.php';
?>

<?php if ($msg): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger"><i class="fas fa-times-circle"></i> <?= htmlspecialchars($err) ?></div><?php endif; ?>

<!-- Role Summary Cards -->
<div class="stat-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:20px;">
  <?php foreach (['Admin'=>['fa-shield-alt','blue'],'Teacher'=>['fa-chalkboard-teacher','green'],'Registrar'=>['fa-id-badge','gold']] as $rn=>[$ic,$cl]): ?>
  <div class="stat-card">
    <div class="stat-icon <?= $cl ?>"><i class="fas <?= $ic ?>"></i></div>
    <div class="stat-info">
      <div class="value"><?= $rcMap[$rn] ?? 0 ?></div>
      <div class="label">Active <?= $rn ?>s</div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Archive Scope Tabs -->
<div style="display:flex;gap:6px;margin-bottom:16px;border-bottom:2px solid var(--border-color,#e5e7eb);padding-bottom:0;">
  <?php
  $tabs = [
    'active'   => ['label' => 'Active Users',   'icon' => 'fa-users'],
    'archived' => ['label' => 'Deactivated',        'icon' => 'fa-user-slash'],
    'all'      => ['label' => 'All Users',        'icon' => 'fa-list'],
  ];
  foreach ($tabs as $tabKey => $tab):
    $isActive = $archiveFilter === $tabKey;
    $tabUrl = '?' . http_build_query(array_merge($_GET, ['archive' => $tabKey, 'search' => $search, 'role' => $roleFilter, 'status' => $statusFilter]));
  ?>
  <a href="<?= $tabUrl ?>" style="
    display:inline-flex;align-items:center;gap:6px;padding:8px 16px;
    font-size:13px;font-weight:<?= $isActive ? '700' : '500' ?>;
    color:<?= $isActive ? 'var(--green)' : 'var(--text-muted)' ?>;
    border-bottom:2px solid <?= $isActive ? 'var(--green)' : 'transparent' ?>;
    margin-bottom:-2px;text-decoration:none;transition:color .15s;
  ">
    <i class="fas <?= $tab['icon'] ?>"></i>
    <?= $tab['label'] ?>
    <?php if ($tabKey === 'archived' && $archivedCount > 0): ?>
    <span style="background:var(--danger,#ef4444);color:#fff;font-size:10px;font-weight:700;padding:1px 6px;border-radius:999px;line-height:1.6;"><?= $archivedCount ?></span>
    <?php endif; ?>
  </a>
  <?php endforeach; ?>
</div>

<!-- Filters + Add Button -->
<div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;margin-bottom:16px;flex-wrap:wrap;">
  <form method="GET" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
    <input type="hidden" name="archive" value="<?= htmlspecialchars($archiveFilter) ?>"/>
    <div>
      <label class="form-label">Search</label>
      <div style="position:relative;">
        <i class="fas fa-search" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:13px;"></i>
        <input type="text" name="search" class="form-control" style="padding-left:34px;width:220px;" placeholder="Name or email…" value="<?= htmlspecialchars($search) ?>"/>
      </div>
    </div>
    <div>
      <label class="form-label">Role</label>
      <select name="role" class="form-control">
        <option value="">All Roles</option>
        <?php foreach (['Admin','Teacher','Registrar'] as $r): ?>
        <option value="<?= $r ?>" <?= $roleFilter===$r?'selected':'' ?>><?= $r ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php if ($archiveFilter !== 'archived'): ?>
    <div>
      <label class="form-label">Status</label>
      <select name="status" class="form-control">
        <option value="">All</option>
        <option value="1" <?= $statusFilter==='1'?'selected':'' ?>>Active</option>
        <option value="0" <?= $statusFilter==='0'?'selected':'' ?>>Inactive</option>
      </select>
    </div>
    <?php endif; ?>
    <button type="submit" class="btn btn-outline"><i class="fas fa-filter"></i> Filter</button>
    <a href="users.php?archive=<?= htmlspecialchars($archiveFilter) ?>" class="btn btn-outline"><i class="fas fa-times"></i></a>
  </form>
  <?php if ($archiveFilter !== 'archived'): ?>
  <button class="btn btn-primary" onclick="openModal('addModal')">
    <i class="fas fa-user-plus"></i> Add User
  </button>
  <?php endif; ?>
</div>

<!-- Users Table -->
<div class="card">
  <div style="overflow-x:auto;">
    <table class="data-table">
      <thead>
        <tr>
          <th>#</th>
          <th>Full Name</th>
          <th>Email</th>
          <th>Role</th>
          <th>Program</th>
          <th style="text-align:center;">Status</th>
          <th><?= $archiveFilter === 'archived' ? 'Deactivated On' : 'Last Login' ?></th>
          <th style="text-align:center;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($users)): ?>
        <tr><td colspan="8" style="text-align:center;padding:40px;color:var(--text-muted);">No users found.</td></tr>
        <?php else: ?>
        <?php foreach ($users as $i => $u): ?>
        <tr style="<?= $u['dateDeleted'] ? 'opacity:0.7;' : '' ?>">
          <td style="color:var(--text-muted);font-size:12px;"><?= $i + 1 ?></td>
          <td>
            <div style="display:flex;align-items:center;gap:10px;">
              <div style="width:34px;height:34px;border-radius:50%;background:<?= $u['dateDeleted'] ? '#9ca3af' : 'var(--green)' ?>;color:#fff;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;flex-shrink:0;">
                <?= strtoupper(substr($u['fName'],0,1)) ?>
              </div>
              <div>
                <div style="display:flex;align-items:center;gap:6px;">
                  <span style="font-weight:600;font-size:13px;"><?= htmlspecialchars("{$u['lName']}, {$u['fName']} " . ($u['mName'] ? substr($u['mName'],0,1).'.' : '')) ?> <?= htmlspecialchars($u['extName'] ?? '') ?></span>
                  <?php if ($u['dateDeleted']): ?>
                  <span style="font-size:10px;font-weight:600;background:#fef3c7;color:#92400e;padding:1px 7px;border-radius:999px;">Deactivated</span>
                  <?php endif; ?>
                </div>
                <div style="font-size:11px;color:var(--text-muted);">ID #<?= $u['userID'] ?></div>
              </div>
            </div>
          </td>
          <td style="font-size:13px;"><?= htmlspecialchars($u['email']) ?></td>
          <td>
            <span class="role-badge role-<?= $u['roleName'] ?>"><?= $u['roleName'] ?></span>
          </td>
          <td style="font-size:13px;color:var(--green);font-weight:600;"><?= $u['programCode'] ? htmlspecialchars($u['programCode']) : '<span style="color:var(--text-muted);">—</span>' ?></td>
          <td style="text-align:center;">
            <?= $u['isActive']
              ? '<span class="status-badge status-approved">Active</span>'
              : '<span class="status-badge status-flagged">Inactive</span>' ?>
          </td>
          <td style="font-size:12px;color:var(--text-muted);">
            <?php if ($archiveFilter === 'archived'): ?>
              <?= $u['dateDeleted'] ? date('M d, Y g:i A', strtotime($u['dateDeleted'])) : '—' ?>
            <?php else: ?>
              <?= $u['lastLogin'] ? date('M d, Y g:i A', strtotime($u['lastLogin'])) : 'Never' ?>
            <?php endif; ?>
          </td>
          <td style="text-align:center;">
            <div style="display:flex;gap:6px;justify-content:center;">
              <?php if (!$u['dateDeleted']): ?>
              <!-- Edit button (only for active users) -->
              <button class="btn btn-outline btn-sm" title="Edit"
                onclick="openEdit(<?= htmlspecialchars(json_encode($u), ENT_QUOTES) ?>)">
                <i class="fas fa-pen"></i>
              </button>
              <?php endif; ?>

              <?php if ($u['userID'] !== $user['id']): ?>
                <?php if ($u['dateDeleted']): ?>
                <!-- Activate button -->
                <form method="POST" style="display:inline;" onsubmit="return confirm('Activate this user account?')">
                  <input type="hidden" name="csrf_token" value="<?= $csrf ?>"/>
                  <input type="hidden" name="action"     value="activate"/>
                  <input type="hidden" name="userID"     value="<?= $u['userID'] ?>"/>
                  <button class="btn btn-outline btn-sm" type="submit" title="Activate" style="color:var(--green);border-color:var(--green);">
                    <i class="fas fa-user-check"></i>
                  </button>
                </form>
                <?php else: ?>
                <!-- Deactivate button -->
                <form method="POST" style="display:inline;" onsubmit="return confirm('Deactivate this user? They will no longer be able to log in.')">
                  <input type="hidden" name="csrf_token" value="<?= $csrf ?>"/>
                  <input type="hidden" name="action"     value="deactivate"/>
                  <input type="hidden" name="userID"     value="<?= $u['userID'] ?>"/>
                  <button class="btn btn-warning btn-sm" type="submit" title="Deactivate" style="background:#fef3c7;color:#92400e;border:1px solid #fcd34d;">
                    <i class="fas fa-user-slash"></i>
                  </button>
                </form>
                <?php endif; ?>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ── ADD USER MODAL ── -->
<div id="addModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:999;align-items:center;justify-content:center;padding:24px;">
  <div style="background:#fff;border-radius:16px;padding:32px;width:600px;max-width:100%;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2);">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;">
      <h3 style="font-family:'Playfair Display',serif;font-size:20px;"><i class="fas fa-user-plus" style="color:var(--green);margin-right:8px;"></i>Add New User</h3>
      <button onclick="closeModal('addModal')" style="background:none;border:none;font-size:20px;cursor:pointer;color:var(--text-muted);">&times;</button>
    </div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>"/>
      <input type="hidden" name="action"     value="add"/>
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;margin-bottom:14px;">
        <div>
          <label class="form-label">First Name *</label>
          <input name="fName" class="form-control" required placeholder="First name"
            pattern="[A-Za-zÀ-ÖØ-öø-ÿ\s\-\.]+"
            title="Letters only — no numbers or special characters"
            oninput="this.value=this.value.replace(/[^A-Za-zÀ-ÖØ-öø-ÿ\s\-\.]/g,'')"/>
        </div>
        <div>
          <label class="form-label">Middle Name</label>
          <input name="mName" class="form-control" placeholder="Middle name"
            pattern="[A-Za-zÀ-ÖØ-öø-ÿ\s\-\.]*"
            title="Letters only — no numbers or special characters"
            oninput="this.value=this.value.replace(/[^A-Za-zÀ-ÖØ-öø-ÿ\s\-\.]/g,'')"/>
        </div>
        <div>
          <label class="form-label">Last Name *</label>
          <input name="lName" class="form-control" required placeholder="Last name"
            pattern="[A-Za-zÀ-ÖØ-öø-ÿ\s\-\.]+"
            title="Letters only — no numbers or special characters"
            oninput="this.value=this.value.replace(/[^A-Za-zÀ-ÖØ-öø-ÿ\s\-\.]/g,'')"/>
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
        <div>
          <label class="form-label">Extension</label>
          <input name="extName" class="form-control" placeholder="Jr., Sr., III"
            pattern="[A-Za-z\s\.\-IVX]*"
            title="Letters only (e.g. Jr., Sr., III)"
            oninput="this.value=this.value.replace(/[^A-Za-z\s\.\-IVX]/g,'')"/>
        </div>
        <div>
          <label class="form-label">Role *</label>
          <select name="roleName" class="form-control" onchange="toggleProgram(this)" required>
            <option value="Teacher">Teacher</option>
            <option value="Registrar">Registrar</option>
            <option value="Admin">Admin</option>
          </select>
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
        <div><label class="form-label">Institutional Email *</label><input name="email" type="email" class="form-control" required placeholder="user@neust.edu.ph"/></div>
        <div><label class="form-label">Initial Password *</label><input name="password" type="text" class="form-control" required placeholder="Temporary password" value="neust2025"/></div>
      </div>
      <div id="programField" style="margin-bottom:20px;">
        <label class="form-label">Assigned Program <span style="font-size:11px;color:var(--text-muted);">(for Teachers)</span></label>
        <select name="programID" class="form-control">
          <option value="">— No specific program —</option>
          <?php foreach ($programs as $p): ?>
          <option value="<?= $p['programID'] ?>"><?= htmlspecialchars("{$p['programCode']} – {$p['programName']}") ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="display:flex;gap:10px;justify-content:flex-end;">
        <button type="button" class="btn btn-outline" onclick="closeModal('addModal')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Create User</button>
      </div>
    </form>
  </div>
</div>

<!-- ── EDIT USER MODAL ── -->
<div id="editModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:999;align-items:center;justify-content:center;padding:24px;">
  <div style="background:#fff;border-radius:16px;padding:32px;width:600px;max-width:100%;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2);">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;">
      <h3 style="font-family:'Playfair Display',serif;font-size:20px;"><i class="fas fa-user-edit" style="color:var(--green);margin-right:8px;"></i>Edit User</h3>
      <button onclick="closeModal('editModal')" style="background:none;border:none;font-size:20px;cursor:pointer;color:var(--text-muted);">&times;</button>
    </div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>"/>
      <input type="hidden" name="action"     value="edit"/>
      <input type="hidden" name="userID"     id="edit_userID"/>
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;margin-bottom:14px;">
        <div>
          <label class="form-label">First Name *</label>
          <input name="fName" id="edit_fName" class="form-control" required
            pattern="[A-Za-zÀ-ÖØ-öø-ÿ\s\-\.]+"
            title="Letters only — no numbers or special characters"
            oninput="this.value=this.value.replace(/[^A-Za-zÀ-ÖØ-öø-ÿ\s\-\.]/g,'')"/>
        </div>
        <div>
          <label class="form-label">Middle Name</label>
          <input name="mName" id="edit_mName" class="form-control"
            pattern="[A-Za-zÀ-ÖØ-öø-ÿ\s\-\.]*"
            title="Letters only — no numbers or special characters"
            oninput="this.value=this.value.replace(/[^A-Za-zÀ-ÖØ-öø-ÿ\s\-\.]/g,'')"/>
        </div>
        <div>
          <label class="form-label">Last Name *</label>
          <input name="lName" id="edit_lName" class="form-control" required
            pattern="[A-Za-zÀ-ÖØ-öø-ÿ\s\-\.]+"
            title="Letters only — no numbers or special characters"
            oninput="this.value=this.value.replace(/[^A-Za-zÀ-ÖØ-öø-ÿ\s\-\.]/g,'')"/>
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
        <div>
          <label class="form-label">Extension</label>
          <input name="extName" id="edit_extName" class="form-control"
            pattern="[A-Za-z\s\.\-IVX]*"
            title="Letters only (e.g. Jr., Sr., III)"
            oninput="this.value=this.value.replace(/[^A-Za-z\s\.\-IVX]/g,'')"/>
        </div>
        <div>
          <label class="form-label">Role *</label>
          <select name="roleName" id="edit_roleName" class="form-control" required>
            <option value="Teacher">Teacher</option>
            <option value="Registrar">Registrar</option>
            <option value="Admin">Admin</option>
          </select>
        </div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
        <div>
          <label class="form-label">Status</label>
          <select name="isActive" id="edit_isActive" class="form-control">
            <option value="1">Active</option>
            <option value="0">Inactive</option>
          </select>
        </div>
        <div>
          <label class="form-label">Program</label>
          <select name="programID" id="edit_programID" class="form-control">
            <option value="">— No specific program —</option>
            <?php foreach ($programs as $p): ?>
            <option value="<?= $p['programID'] ?>"><?= htmlspecialchars("{$p['programCode']} – {$p['programName']}") ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div style="margin-bottom:20px;">
        <label class="form-label">New Password <span style="font-size:11px;color:var(--text-muted);">(leave blank to keep current)</span></label>
        <input name="new_password" type="password" class="form-control" placeholder="Enter new password to change…"/>
      </div>
      <div style="display:flex;gap:10px;justify-content:flex-end;">
        <button type="button" class="btn btn-outline" onclick="closeModal('editModal')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button>
      </div>
    </form>
  </div>
</div>

<script>
function openModal(id)  { document.getElementById(id).style.display = 'flex'; }
function closeModal(id) { document.getElementById(id).style.display = 'none'; }

function toggleProgram(sel) {
  document.getElementById('programField').style.display = sel.value === 'Teacher' ? 'block' : 'none';
}

function openEdit(u) {
  document.getElementById('edit_userID').value    = u.userID;
  document.getElementById('edit_fName').value     = u.fName;
  document.getElementById('edit_mName').value     = u.mName || '';
  document.getElementById('edit_lName').value     = u.lName;
  document.getElementById('edit_extName').value   = u.extName || '';
  document.getElementById('edit_roleName').value  = u.roleName;
  document.getElementById('edit_isActive').value  = u.isActive;
  document.getElementById('edit_programID').value = u.programID || '';
  openModal('editModal');
}

// Close modal on backdrop click
['addModal','editModal'].forEach(id => {
  document.getElementById(id).addEventListener('click', function(e) {
    if (e.target === this) closeModal(id);
  });
});
</script>

<?php require_once ROOT . '/includes/footer.php'; ?>
