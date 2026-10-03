<?php
define('ROOT', dirname(__DIR__, 2));
require_once ROOT . '/includes/config.php';
require_once ROOT . '/includes/functions.php';
requireAuth('Admin');

$db   = Database::getInstance();
$user = getCurrentUser();
$msg  = $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) { $err = 'Invalid request.'; }
    else {
        $action = $_POST['action'] ?? '';
        if ($action === 'add') {
            $code = strtoupper(trim($_POST['programCode'] ?? ''));
            $exists = $db->fetchOne("SELECT programID FROM programs WHERE programCode=:c AND dateDeleted IS NULL", [':c'=>$code]);
            if ($exists) { $err = "Program code '$code' already exists."; }
            else {
                $db->insert("INSERT INTO programs (programCode,programName,programDesc) VALUES (:c,:n,:d)",
                    [':c'=>$code, ':n'=>sanitize($_POST['programName']??''), ':d'=>sanitize($_POST['programDesc']??'')]);
                $msg = 'Program added successfully.';
            }
        } elseif ($action === 'edit') {
            $pid = (int)($_POST['programID'] ?? 0);
            $db->query("UPDATE programs SET programCode=:c,programName=:n,programDesc=:d WHERE programID=:id",
                [':c'=>strtoupper(trim($_POST['programCode']??'')),
                 ':n'=>sanitize($_POST['programName']??''),
                 ':d'=>sanitize($_POST['programDesc']??''),':id'=>$pid]);
            $msg = 'Program updated successfully.';
        } elseif ($action === 'archive') {
          $pid = (int)($_POST['programID'] ?? 0);
          $ts = date('Y-m-d H:i:s');
          // Mark program archived (only dateDeleted is available in the live schema)
          $db->query("UPDATE programs SET dateDeleted=:ts WHERE programID=:id",[':ts'=>$ts,':id'=>$pid]);
          // Deactivate students in this program
          $db->query("UPDATE students SET isActive=0 WHERE programID=:id AND dateDeleted IS NULL",[':id'=>$pid]);
          // Drop (soft-delete) enrollments belonging to this program (only those not already deleted)
          $db->query("UPDATE enrollments SET status='Dropped', dateDeleted=:ts WHERE programID=:id AND dateDeleted IS NULL",[':ts'=>$ts,':id'=>$pid]);
          $msg = 'Program archived.';
        } elseif ($action === 'restore') {
          $pid = (int)($_POST['programID'] ?? 0);
          // Find when the program was archived so we only restore enrollments removed by the archive
          $p = $db->fetchOne("SELECT dateDeleted FROM programs WHERE programID=:id",[':id'=>$pid]);
          $deletedAt = $p['dateDeleted'] ?? null;
          if ($deletedAt) {
            // Restore enrollments that were soft-deleted at the same time as the program
            $db->query("UPDATE enrollments SET dateDeleted=NULL, status='Active' WHERE programID=:id AND dateDeleted=:ts",[':id'=>$pid,':ts'=>$deletedAt]);
          }
          // Restore program and reactivate
          $db->query("UPDATE programs SET dateDeleted=NULL WHERE programID=:id",[':id'=>$pid]);
          // Reactivate students in this program (only those not deleted)
          $db->query("UPDATE students SET isActive=1 WHERE programID=:id AND dateDeleted IS NULL",[':id'=>$pid]);
          $msg = 'Program restored successfully.';
        }
    }
}

$programs = $db->fetchAll("
    SELECT p.*, COUNT(s.studentID) as studentCount
    FROM programs p
    LEFT JOIN students s ON s.programID=p.programID AND s.dateDeleted IS NULL AND s.isActive=1
    WHERE p.dateDeleted IS NULL
    GROUP BY p.programID
    ORDER BY p.programCode
");

$archived = $db->fetchAll("
    SELECT p.*, COUNT(s.studentID) as studentCount
    FROM programs p
    LEFT JOIN students s ON s.programID=p.programID AND s.dateDeleted IS NULL AND s.isActive=1
    WHERE p.dateDeleted IS NOT NULL
    GROUP BY p.programID
    ORDER BY p.dateDeleted DESC
");

$activeProgramCount = count($programs);
$archivedProgramCount = count($archived);

$csrf = generateCSRF();
$pageTitle = 'Programs'; $activeMenu = 'programs.php';
require_once ROOT . '/includes/header.php';
?>

<?php if ($msg): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger"><i class="fas fa-times-circle"></i> <?= htmlspecialchars($err) ?></div><?php endif; ?>

<!-- ── ACTIVE PROGRAMS ── -->
<div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:12px;margin-bottom:20px;">
  <div style="display:flex;flex-direction:column;gap:8px;">
    <div style="font-size:14px;color:var(--text-muted);">
      <strong><?= $activeProgramCount ?></strong> active programs • <strong><?= $archivedProgramCount ?></strong> archived
    </div>
    <input type="text" id="programSearch" class="form-control" placeholder="Search programs..." style="min-width:260px;max-width:340px;">
  </div>
  <button class="btn btn-primary" onclick="openModal('addModal')"><i class="fas fa-plus"></i> Add Program</button>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px;">
  <?php foreach ($programs as $p): ?>
  <div class="card" data-program-card data-search="<?= htmlspecialchars(strtolower($p['programCode'] . ' ' . $p['programName'] . ' ' . ($p['programDesc'] ?? ''))) ?>" style="padding:0;overflow:hidden;">
    <div style="background:var(--blue);padding:20px 24px;display:flex;align-items:center;justify-content:space-between;">
      <div>
        <div style="font-family:'Playfair Display',serif;font-size:22px;font-weight:700;color:#fff;"><?= htmlspecialchars($p['programCode']) ?></div>
        <div style="font-size:12px;color:rgba(255,255,255,.7);margin-top:2px;"><?= $p['studentCount'] ?> enrolled students</div>
      </div>
      <div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px;">
        <div style="width:48px;height:48px;border-radius:12px;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;">
          <i class="fas fa-graduation-cap" style="font-size:20px;color:#fff;"></i>
        </div>
        <span style="font-size:10px;font-weight:700;letter-spacing:.5px;padding:2px 8px;border-radius:20px;background:rgba(255,255,255,.2);color:#fff;">
          AVAILABLE
        </span>
      </div>
    </div>
    <div style="padding:20px 24px;">
      <div style="font-weight:600;font-size:14px;margin-bottom:6px;"><?= htmlspecialchars($p['programName']) ?></div>
      <div style="font-size:13px;color:var(--text-muted);line-height:1.5;"><?= htmlspecialchars($p['programDesc'] ?: 'No description.') ?></div>
      <div style="display:flex;gap:8px;margin-top:16px;">
        <button class="btn btn-outline btn-sm" style="flex:1;justify-content:center;" onclick="openEdit(<?= htmlspecialchars(json_encode($p),ENT_QUOTES) ?>)">
          <i class="fas fa-pen"></i> Edit
        </button>
        <form method="POST" onsubmit="return confirm('Archive this program? It will no longer appear in active lists.')" style="flex:1;margin:0;">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>"/>
          <input type="hidden" name="action"    value="archive"/>
          <input type="hidden" name="programID" value="<?= $p['programID'] ?>"/>
          <button class="btn btn-outline btn-sm" type="submit" style="width:100%;justify-content:center;">
            <i class="fas fa-archive"></i> Archive
          </button>
        </form>
      </div>
    </div>
  </div>
  <?php endforeach; ?>

  <div id="noProgramResults" style="display:none;grid-column:1/-1;text-align:center;padding:24px;background:#fafafa;border:2px dashed #dee2e6;border-radius:16px;">
    <div style="font-weight:600;color:#6c757d;font-size:15px;margin-bottom:6px;">No matching programs found.</div>
    <div style="font-size:13px;color:#adb5bd;">Try a different keyword or add a new program.</div>
  </div>

  <!-- Add New Card -->
  <div class="card" style="padding:40px;display:flex;flex-direction:column;align-items:center;justify-content:center;cursor:pointer;border:2px dashed var(--border);background:transparent;box-shadow:none;" onclick="openModal('addModal')">
    <div style="width:56px;height:56px;border-radius:50%;background:var(--blue-light);display:flex;align-items:center;justify-content:center;margin-bottom:12px;">
      <i class="fas fa-plus" style="font-size:22px;color:var(--blue);"></i>
    </div>
    <div style="font-weight:600;color:var(--blue);">Add New Program</div>
  </div>
</div>


<!-- ── ARCHIVED PROGRAMS SECTION ── -->
<div style="margin-top:48px;">

  <!-- Section Header -->
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;padding:16px 20px;background:#fff3cd;border:1px solid #ffc107;border-radius:12px;">
    <div style="display:flex;align-items:center;gap:12px;">
      <div style="width:40px;height:40px;border-radius:10px;background:#ffc107;display:flex;align-items:center;justify-content:center;">
        <i class="fas fa-archive" style="color:#fff;font-size:18px;"></i>
      </div>
      <div>
        <div style="font-weight:700;font-size:16px;color:#856404;">Archived Programs</div>
        <div style="font-size:12px;color:#997404;">
          <?= count($archived) ?> program(s) archived — click <strong>Restore</strong> to bring them back
        </div>
      </div>
    </div>
    <?php if (!empty($archived)): ?>
    <span style="background:#ffc107;color:#fff;font-size:13px;font-weight:700;padding:4px 14px;border-radius:20px;">
      <?= count($archived) ?> archived
    </span>
    <?php endif; ?>
  </div>

  <?php if (empty($archived)): ?>
  <!-- Empty State -->
  <div style="text-align:center;padding:48px 24px;background:#fafafa;border:2px dashed #dee2e6;border-radius:16px;">
    <div style="width:64px;height:64px;border-radius:50%;background:#f0f0f0;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;">
      <i class="fas fa-archive" style="font-size:28px;color:#adb5bd;"></i>
    </div>
    <div style="font-weight:600;color:#6c757d;font-size:15px;margin-bottom:6px;">No Archived Programs</div>
    <div style="font-size:13px;color:#adb5bd;">Programs you archive will appear here and can be restored anytime.</div>
  </div>

  <?php else: ?>
  <!-- Archived Cards Grid -->
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px;">
    <?php foreach ($archived as $p): ?>
    <div style="background:#fff;border-radius:16px;overflow:hidden;border:2px solid #ffc107;box-shadow:0 2px 12px rgba(255,193,7,.15);">

      <!-- Card Header -->
      <div style="background:linear-gradient(135deg,#868e96,#adb5bd);padding:20px 24px;display:flex;align-items:center;justify-content:space-between;">
        <div>
          <div style="font-family:'Playfair Display',serif;font-size:22px;font-weight:700;color:#fff;"><?= htmlspecialchars($p['programCode']) ?></div>
          <div style="font-size:12px;color:rgba(255,255,255,.8);margin-top:2px;"><?= $p['studentCount'] ?> enrolled students</div>
        </div>
        <div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px;">
          <div style="width:48px;height:48px;border-radius:12px;background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;">
            <i class="fas fa-archive" style="font-size:20px;color:#fff;"></i>
          </div>
          <span style="font-size:10px;font-weight:700;letter-spacing:.5px;padding:2px 10px;border-radius:20px;background:rgba(0,0,0,.2);color:#fff;">
            ARCHIVED
          </span>
        </div>
      </div>

      <!-- Card Body -->
      <div style="padding:20px 24px;">
        <div style="font-weight:700;font-size:14px;margin-bottom:4px;color:#343a40;"><?= htmlspecialchars($p['programName']) ?></div>
        <div style="font-size:13px;color:#6c757d;line-height:1.5;margin-bottom:10px;"><?= htmlspecialchars($p['programDesc'] ?: 'No description.') ?></div>

        <!-- Archived Date Badge -->
        <div style="display:inline-flex;align-items:center;gap:6px;background:#fff3cd;border:1px solid #ffc107;border-radius:8px;padding:6px 12px;margin-bottom:16px;">
          <i class="fas fa-clock" style="color:#856404;font-size:12px;"></i>
          <span style="font-size:12px;color:#856404;font-weight:600;">Archived on <?= date('M d, Y', strtotime($p['dateDeleted'])) ?></span>
        </div>

        <!-- Restore Button -->
        <form method="POST" onsubmit="return confirm('Restore \'<?= htmlspecialchars(addslashes($p['programCode'])) ?>\' program? It will go back to the active list.')">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>"/>
          <input type="hidden" name="action"     value="restore"/>
          <input type="hidden" name="programID"  value="<?= $p['programID'] ?>"/>
          <button type="submit" class="btn btn-outline btn-sm" style="width:100%;justify-content:center;">
            <i class="fas fa-undo-alt"></i>
            Restore Program
          </button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>


<!-- Add Modal -->
<div id="addModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:999;align-items:center;justify-content:center;padding:24px;">
  <div style="background:#fff;border-radius:16px;padding:32px;width:500px;max-width:100%;box-shadow:0 20px 60px rgba(0,0,0,.2);">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;">
      <h3 style="font-family:'Playfair Display',serif;font-size:20px;">Add Program</h3>
      <button onclick="closeModal('addModal')" style="background:none;border:none;font-size:20px;cursor:pointer;">&times;</button>
    </div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>"/>
      <input type="hidden" name="action"     value="add"/>
      <div style="margin-bottom:14px;">
        <label class="form-label">Program Code * <span style="font-size:11px;color:var(--text-muted);">(e.g., BSIT, BSCS)</span></label>
        <input name="programCode" class="form-control" required placeholder="BSIT" style="text-transform:uppercase;"/>
      </div>
      <div style="margin-bottom:14px;">
        <label class="form-label">Program Name *</label>
        <input name="programName" class="form-control" required placeholder="Bachelor of Science in Information Technology"/>
      </div>
      <div style="margin-bottom:24px;">
        <label class="form-label">Description</label>
        <textarea name="programDesc" class="form-control" rows="3" placeholder="Brief description of the program…"></textarea>
      </div>
      <div style="display:flex;gap:10px;justify-content:flex-end;">
        <button type="button" class="btn btn-outline" onclick="closeModal('addModal')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Program</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Modal -->
<div id="editModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:999;align-items:center;justify-content:center;padding:24px;">
  <div style="background:#fff;border-radius:16px;padding:32px;width:500px;max-width:100%;box-shadow:0 20px 60px rgba(0,0,0,.2);">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;">
      <h3 style="font-family:'Playfair Display',serif;font-size:20px;">Edit Program</h3>
      <button onclick="closeModal('editModal')" style="background:none;border:none;font-size:20px;cursor:pointer;">&times;</button>
    </div>
    <form method="POST">
      <input type="hidden" name="csrf_token"  value="<?= $csrf ?>"/>
      <input type="hidden" name="action"      value="edit"/>
      <input type="hidden" name="programID"   id="edit_pid"/>
      <div style="margin-bottom:14px;">
        <label class="form-label">Program Code *</label>
        <input name="programCode" id="edit_code" class="form-control" required style="text-transform:uppercase;"/>
      </div>
      <div style="margin-bottom:14px;">
        <label class="form-label">Program Name *</label>
        <input name="programName" id="edit_name" class="form-control" required/>
      </div>
      <div style="margin-bottom:24px;">
        <label class="form-label">Description</label>
        <textarea name="programDesc" id="edit_desc" class="form-control" rows="3"></textarea>
      </div>
      <div style="display:flex;gap:10px;justify-content:flex-end;">
        <button type="button" class="btn btn-outline" onclick="closeModal('editModal')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update</button>
      </div>
    </form>
  </div>
</div>

<script>
function openModal(id)  { document.getElementById(id).style.display = 'flex'; }
function closeModal(id) { document.getElementById(id).style.display = 'none'; }
function openEdit(p) {
  document.getElementById('edit_pid').value  = p.programID;
  document.getElementById('edit_code').value = p.programCode;
  document.getElementById('edit_name').value = p.programName;
  document.getElementById('edit_desc').value = p.programDesc || '';
  openModal('editModal');
}
function filterPrograms() {
  var term = (document.getElementById('programSearch').value || '').toLowerCase();
  var cards = document.querySelectorAll('[data-program-card]');
  var visible = 0;
  cards.forEach(function(card) {
    var matches = (card.getAttribute('data-search') || '').includes(term);
    card.style.display = matches ? '' : 'none';
    if (matches) visible++;
  });
  var empty = document.getElementById('noProgramResults');
  if (empty) empty.style.display = visible > 0 ? 'none' : 'block';
}
document.addEventListener('DOMContentLoaded', function() {
  var input = document.getElementById('programSearch');
  if (input) input.addEventListener('input', filterPrograms);
});
</script>
<?php require_once ROOT . '/includes/footer.php'; ?>