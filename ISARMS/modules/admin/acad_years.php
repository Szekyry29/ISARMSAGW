<?php
define('ROOT', dirname(__DIR__, 2));
require_once ROOT . '/includes/config.php';
require_once ROOT . '/includes/functions.php';
requireAuth('Admin');

$db  = Database::getInstance();
$msg = $err = '';

function isValidAcademicYearLabel(string $academicYear): bool {
  if (!preg_match('/^(\d{4})-(\d{4})(?: (?:1st|2nd) Sem)?$/', $academicYear, $matches)) {
    return false;
  }

  return (int)$matches[2] === (int)$matches[1] + 1;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) { $err = 'Invalid request.'; }
    else {
        $action = $_POST['action'] ?? '';
        if ($action === 'add') {
          $ay = trim(sanitize($_POST['academicYear'] ?? ''));
          if (!isValidAcademicYearLabel($ay)) {
            $err = 'Enter a valid academic year, such as 2029-2030.';
          } else {
            $exists = $db->fetchOne("SELECT acadYearID FROM acad_years WHERE academicYear=:ay AND dateDeleted IS NULL",[':ay'=>$ay]);
            if ($exists) { $err = "Academic Year '$ay' already exists."; }
            else {
              $sourceYear = $db->fetchOne("SELECT acadYearID FROM acad_years WHERE status='Active' AND dateDeleted IS NULL ORDER BY acadYearID DESC LIMIT 1");
              $db->beginTransaction();
              try {
                $newYearID = $db->insert("INSERT INTO acad_years (academicYear,acadDesc,status) VALUES (:ay,:d,:s)",
                  [':ay'=>$ay,':d'=>sanitize($_POST['acadDesc']??''),':s'=>$_POST['status']??'Inactive']);
                $copiedSections = 0;
                $copiedSubjects = 0;

                if ($sourceYear) {
                  $sections = $db->fetchAll("SELECT sectionID, sectionName, sectionDesc, programID FROM sections WHERE acadYearID=:ay AND dateDeleted IS NULL", [':ay'=>$sourceYear['acadYearID']]);
                  foreach ($sections as $section) {
                    $newSectionID = $db->insert(
                      "INSERT INTO sections (sectionName,sectionDesc,programID,acadYearID) VALUES (:name,:description,:program,:ay)",
                      [':name'=>$section['sectionName'],':description'=>$section['sectionDesc'],':program'=>$section['programID'],':ay'=>$newYearID]
                    );
                    $copiedSections++;

                    $offerings = $db->fetchAll(
                      "SELECT subjectID, semester FROM section_subjects WHERE sectionID=:section AND acadYearID=:ay AND dateDeleted IS NULL",
                      [':section'=>$section['sectionID'],':ay'=>$sourceYear['acadYearID']]
                    );
                    foreach ($offerings as $offering) {
                      $db->insert(
                        "INSERT INTO section_subjects (sectionID,subjectID,teacherID,semester,acadYearID,schedule,room) VALUES (:section,:subject,NULL,:semester,:ay,NULL,NULL)",
                        [':section'=>$newSectionID,':subject'=>$offering['subjectID'],':semester'=>$offering['semester'],':ay'=>$newYearID]
                      );
                      $copiedSubjects++;
                    }
                  }
                }

                $db->commit();
                $msg = $sourceYear
                  ? "Academic year added. Copied {$copiedSections} sections and {$copiedSubjects} subject offerings; teachers and schedules are unassigned."
                  : 'Academic year added. No active academic year was available to copy.';
              } catch (Throwable $exception) {
                $db->rollback();
                error_log('Academic year setup failed: ' . $exception->getMessage());
                $err = 'Academic year setup failed. Run the latest database migrations and try again.';
              }
            }
            }
        } elseif ($action === 'edit') {
            $id = (int)($_POST['acadYearID'] ?? 0);
          $ay = trim(sanitize($_POST['academicYear'] ?? ''));
          if (!isValidAcademicYearLabel($ay)) {
            $err = 'Enter a valid academic year, such as 2029-2030.';
          } else {
            $db->query("UPDATE acad_years SET academicYear=:ay,acadDesc=:d,status=:s WHERE acadYearID=:id",
              [':ay'=>$ay,':d'=>sanitize($_POST['acadDesc']??''),
               ':s'=>$_POST['status']??'Inactive',':id'=>$id]);
            $msg = 'Academic year updated.';
          }
        } elseif ($action === 'set_active') {
            $id = (int)($_POST['acadYearID'] ?? 0);
            $db->query("UPDATE acad_years SET status='Inactive' WHERE dateDeleted IS NULL");
            $db->query("UPDATE acad_years SET status='Active' WHERE acadYearID=:id",[':id'=>$id]);
            $msg = 'Active academic year updated.';
        } elseif ($action === 'archive') {
            $id = (int)($_POST['acadYearID'] ?? 0);
            // Prevent archiving the Active year
            $current = $db->fetchOne("SELECT status FROM acad_years WHERE acadYearID=:id",[':id'=>$id]);
            if ($current && $current['status'] === 'Active') {
                $err = 'Cannot archive the currently Active academic year. Set another year as Active first.';
            } else {
                $db->query("UPDATE acad_years SET status='Archived', dateDeleted=NOW() WHERE acadYearID=:id",[':id'=>$id]);
                $msg = 'Academic year archived.';
            }
        } elseif ($action === 'restore') {
            $id = (int)($_POST['acadYearID'] ?? 0);
            $db->query("UPDATE acad_years SET status='Inactive', dateDeleted=NULL WHERE acadYearID=:id",[':id'=>$id]);
            $msg = 'Academic year restored.';
        }
    }
}

$acadYears = $db->fetchAll("
    SELECT ay.*,
           COUNT(DISTINCT e.enrollmentID) as enrollCount,
           COUNT(DISTINCT e.studentID)    as studentCount
    FROM acad_years ay
    LEFT JOIN enrollments e ON e.acadYearID=ay.acadYearID AND e.dateDeleted IS NULL
    WHERE ay.dateDeleted IS NULL
    GROUP BY ay.acadYearID
    ORDER BY ay.academicYear DESC
");

$archivedYears = $db->fetchAll("
    SELECT ay.*,
           COUNT(DISTINCT e.enrollmentID) as enrollCount,
           COUNT(DISTINCT e.studentID)    as studentCount
    FROM acad_years ay
    LEFT JOIN enrollments e ON e.acadYearID=ay.acadYearID AND e.dateDeleted IS NULL
    WHERE ay.dateDeleted IS NOT NULL
    GROUP BY ay.acadYearID
    ORDER BY ay.academicYear DESC
");

$activeYearLabel = '';
foreach ($acadYears as $ay) {
    if (($ay['status'] ?? '') === 'Active') {
        $activeYearLabel = $ay['academicYear'];
        break;
    }
}
$activeYearCount = count($acadYears);
$archivedYearCount = count($archivedYears);
$inactiveYearCount = 0;
foreach ($acadYears as $ay) {
    if (($ay['status'] ?? '') !== 'Active') {
        $inactiveYearCount++;
    }
}

$csrf = generateCSRF();
$pageTitle = 'Academic Years'; $activeMenu = 'acad_years.php';
require_once ROOT . '/includes/header.php';
?>

<?php if ($msg): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger"><i class="fas fa-times-circle"></i> <?= htmlspecialchars($err) ?></div><?php endif; ?>

<!-- Info Banner -->
<div class="alert alert-info" style="margin-bottom:20px;">
  <i class="fas fa-info-circle"></i>
  Only <strong>one</strong> academic year can be <strong>Active</strong> at a time. The active year is used for current enrollments, grade encoding, and GWA computation.
  The <strong>Archive</strong> button is disabled for the currently Active year.
</div>

<div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:12px;margin-bottom:16px;">
  <div style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
    <div class="alert alert-info" style="margin:0;padding:10px 14px;">
      <i class="fas fa-calendar-alt"></i> Active year: <strong><?= htmlspecialchars($activeYearLabel ?: 'None') ?></strong>
      • <strong><?= $activeYearCount ?></strong> total • <strong><?= $inactiveYearCount ?></strong> inactive • <strong><?= $archivedYearCount ?></strong> archived
    </div>
    <input type="text" id="acadYearSearch" class="form-control" placeholder="Search academic years..." style="min-width:240px;max-width:320px;">
  </div>
  <button class="btn btn-primary" onclick="openModal('addModal')"><i class="fas fa-plus"></i> Add Academic Year</button>
</div>

<!-- Active / Inactive Academic Years -->
<div class="card">
  <div style="overflow-x:auto;">
    <table class="data-table">
      <thead>
        <tr>
          <th>Academic Year</th>
          <th>Description</th>
          <th style="text-align:center;">Enrollments</th>
          <th style="text-align:center;">Students</th>
          <th style="text-align:center;">Status</th>
          <th>Created</th>
          <th style="text-align:center;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($acadYears)): ?>
        <tr><td colspan="7" style="text-align:center;padding:40px;color:var(--text-muted);">No academic years set up yet.</td></tr>
        <?php else: foreach ($acadYears as $ay): ?>
        <tr style="<?= $ay['status']==='Active' ? 'background:var(--green-light);' : '' ?>" data-acad-row data-search="<?= htmlspecialchars(strtolower($ay['academicYear'] . ' ' . ($ay['acadDesc'] ?? '') . ' ' . ($ay['status'] ?? ''))) ?>">
          <td>
            <div style="font-family:'Playfair Display',serif;font-size:18px;font-weight:700;color:var(--text);">
              <?= htmlspecialchars($ay['academicYear']) ?>
            </div>
          </td>
          <td style="font-size:13px;color:var(--text-muted);"><?= htmlspecialchars($ay['acadDesc'] ?: '—') ?></td>
          <td style="text-align:center;font-weight:600;"><?= number_format($ay['enrollCount']) ?></td>
          <td style="text-align:center;font-weight:600;"><?= number_format($ay['studentCount']) ?></td>
          <td style="text-align:center;">
            <?php if ($ay['status'] === 'Active'): ?>
              <span class="status-badge status-approved" style="font-size:13px;padding:6px 14px;">
                <i class="fas fa-circle" style="font-size:8px;"></i> Active
              </span>
            <?php else: ?>
              <span class="status-badge" style="background:var(--border);color:var(--text-muted);">Inactive</span>
            <?php endif; ?>
          </td>
          <td style="font-size:12px;color:var(--text-muted);"><?= date('M d, Y', strtotime($ay['dateCreated'])) ?></td>
          <td style="text-align:center;">
            <div style="display:flex;gap:6px;justify-content:center;flex-wrap:wrap;">

              <!-- Set Active button — only for Inactive -->
              <?php if ($ay['status'] !== 'Active'): ?>
              <form method="POST" onsubmit="return confirm('Set <?= htmlspecialchars(addslashes($ay['academicYear'])) ?> as the active academic year?')">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>"/>
                <input type="hidden" name="action"     value="set_active"/>
                <input type="hidden" name="acadYearID" value="<?= $ay['acadYearID'] ?>"/>
                <button class="btn btn-gold btn-sm" type="submit" title="Set Active">
                  <i class="fas fa-star"></i> Set Active
                </button>
              </form>
              <?php endif; ?>

              <!-- Edit button — always visible -->
              <button class="btn btn-outline btn-sm" onclick="openEdit(<?= htmlspecialchars(json_encode($ay),ENT_QUOTES) ?>)" title="Edit">
                <i class="fas fa-pen"></i>
              </button>

              <!-- Archive button — visible for ALL, disabled if Active -->
              <?php if ($ay['status'] === 'Active'): ?>
              <button class="btn btn-outline btn-sm" disabled title="Cannot archive the active year"
                style="opacity:.65;cursor:not-allowed;">
                <i class="fas fa-archive"></i>
              </button>
              <?php else: ?>
              <form method="POST" onsubmit="return confirm('Archive this academic year? You can restore it later.')">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>"/>
                <input type="hidden" name="action"     value="archive"/>
                <input type="hidden" name="acadYearID" value="<?= $ay['acadYearID'] ?>"/>
                <button class="btn btn-outline btn-sm" type="submit" title="Archive">
                  <i class="fas fa-archive"></i>
                </button>
              </form>
              <?php endif; ?>

            </div>
          </td>
        </tr>
        <?php endforeach; endif; ?>
        <tr id="noAcadYearResults" style="display:none;"><td colspan="7" style="text-align:center;padding:40px;color:var(--text-muted);">No matching academic years found.</td></tr>
      </tbody>
    </table>
  </div>
</div>


<!-- ── ARCHIVED ACADEMIC YEARS ── -->
<div style="margin-top:40px;">
  <!-- Section Header -->
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;padding:16px 20px;background:#fff3cd;border:1px solid #ffc107;border-radius:12px;">
    <div style="display:flex;align-items:center;gap:12px;">
      <div style="width:40px;height:40px;border-radius:10px;background:#ffc107;display:flex;align-items:center;justify-content:center;">
        <i class="fas fa-archive" style="color:#fff;font-size:18px;"></i>
      </div>
      <div>
        <div style="font-weight:700;font-size:16px;color:#856404;">Archived Academic Years</div>
        <div style="font-size:12px;color:#997404;">
          <?= count($archivedYears) ?> academic year(s) archived — click <strong>Restore</strong> to bring them back
        </div>
      </div>
    </div>
    <?php if (!empty($archivedYears)): ?>
    <span style="background:#ffc107;color:#fff;font-size:13px;font-weight:700;padding:4px 14px;border-radius:20px;">
      <?= count($archivedYears) ?> archived
    </span>
    <?php endif; ?>
  </div>

  <?php if (empty($archivedYears)): ?>
  <!-- Empty State -->
  <div style="text-align:center;padding:40px 24px;background:#fafafa;border:2px dashed #dee2e6;border-radius:16px;">
    <div style="width:56px;height:56px;border-radius:50%;background:#f0f0f0;display:flex;align-items:center;justify-content:center;margin:0 auto 14px;">
      <i class="fas fa-archive" style="font-size:24px;color:#adb5bd;"></i>
    </div>
    <div style="font-weight:600;color:#6c757d;font-size:15px;margin-bottom:4px;">No Archived Academic Years</div>
    <div style="font-size:13px;color:#adb5bd;">Academic years you archive will appear here and can be restored anytime.</div>
  </div>

  <?php else: ?>
  <!-- Archived Table -->
  <div class="card">
    <div style="overflow-x:auto;">
      <table class="data-table">
        <thead>
          <tr>
            <th>Academic Year</th>
            <th>Description</th>
            <th style="text-align:center;">Enrollments</th>
            <th style="text-align:center;">Students</th>
            <th>Archived On</th>
            <th style="text-align:center;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($archivedYears as $ay): ?>
          <tr style="opacity:0.8;">
            <td>
              <div style="font-family:'Playfair Display',serif;font-size:17px;font-weight:700;color:#6c757d;">
                <?= htmlspecialchars($ay['academicYear']) ?>
              </div>
            </td>
            <td style="font-size:13px;color:var(--text-muted);"><?= htmlspecialchars($ay['acadDesc'] ?: '—') ?></td>
            <td style="text-align:center;font-weight:600;"><?= number_format($ay['enrollCount']) ?></td>
            <td style="text-align:center;font-weight:600;"><?= number_format($ay['studentCount']) ?></td>
            <td style="font-size:12px;color:var(--text-muted);">
              <i class="fas fa-clock"></i> <?= date('M d, Y', strtotime($ay['dateDeleted'])) ?>
            </td>
            <td style="text-align:center;">
              <form method="POST" onsubmit="return confirm('Restore this academic year?')">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>"/>
                <input type="hidden" name="action"     value="restore"/>
                <input type="hidden" name="acadYearID" value="<?= $ay['acadYearID'] ?>"/>
                <button type="submit" class="btn btn-outline btn-sm">
                  <i class="fas fa-undo-alt"></i> Restore
                </button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>
</div>


<!-- Add Modal -->
<div id="addModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:999;align-items:center;justify-content:center;padding:24px;">
  <div style="background:#fff;border-radius:16px;padding:32px;width:460px;max-width:100%;box-shadow:0 20px 60px rgba(0,0,0,.2);">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;">
      <h3 style="font-family:'Playfair Display',serif;font-size:20px;">Add Academic Year</h3>
      <button onclick="closeModal('addModal')" style="background:none;border:none;font-size:20px;cursor:pointer;">&times;</button>
    </div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>"/>
      <input type="hidden" name="action"     value="add"/>
      <div style="margin-bottom:14px;">
        <label class="form-label">Academic Year *</label>
        <input name="academicYear" class="form-control" required maxlength="20" pattern="[0-9]{4}-[0-9]{4}( (1st|2nd) Sem)?" placeholder="e.g. 2029-2030" oninput="updateDesc(this.value)" title="Enter a consecutive year range, such as 2029-2030."/>
      </div>
      <div style="margin-bottom:14px;">
        <label class="form-label">Description</label>
        <input name="acadDesc" id="add_desc" class="form-control" placeholder="e.g. First Semester 2025-2026"/>
      </div>
      <div style="margin-bottom:24px;">
        <label class="form-label">Status</label>
        <select name="status" class="form-control">
          <option value="Inactive">Inactive</option>
          <option value="Active">Active (will deactivate current)</option>
        </select>
      </div>
      <div style="display:flex;gap:10px;justify-content:flex-end;">
        <button type="button" class="btn btn-outline" onclick="closeModal('addModal')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit Modal -->
<div id="editModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:999;align-items:center;justify-content:center;padding:24px;">
  <div style="background:#fff;border-radius:16px;padding:32px;width:460px;max-width:100%;box-shadow:0 20px 60px rgba(0,0,0,.2);">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;">
      <h3 style="font-family:'Playfair Display',serif;font-size:20px;">Edit Academic Year</h3>
      <button onclick="closeModal('editModal')" style="background:none;border:none;font-size:20px;cursor:pointer;">&times;</button>
    </div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>"/>
      <input type="hidden" name="action"     value="edit"/>
      <input type="hidden" name="acadYearID" id="edit_ayid"/>
      <div style="margin-bottom:14px;">
        <label class="form-label">Academic Year *</label>
        <input name="academicYear" id="edit_ay" class="form-control" required maxlength="20" pattern="[0-9]{4}-[0-9]{4}( (1st|2nd) Sem)?" title="Enter a consecutive year range, such as 2029-2030."/>
      </div>
      <div style="margin-bottom:14px;">
        <label class="form-label">Description</label>
        <input name="acadDesc" id="edit_aydesc" class="form-control"/>
      </div>
      <div style="margin-bottom:24px;">
        <label class="form-label">Status</label>
        <select name="status" id="edit_aystatus" class="form-control">
          <option value="Inactive">Inactive</option>
          <option value="Active">Active</option>
        </select>
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

function updateDesc(val) {
  var desc = document.getElementById('add_desc');
  var match = String(val).match(/^(\d{4}-\d{4})(?: (1st|2nd) Sem)?$/);
  if (!match) { desc.value = ''; return; }
  desc.value = match[2]
    ? (match[2] === '1st' ? 'First' : 'Second') + ' Semester of Academic Year ' + match[1]
    : 'Academic Year ' + match[1];
}

function openEdit(ay) {
  document.getElementById('edit_ayid').value     = ay.acadYearID;
  document.getElementById('edit_ay').value       = ay.academicYear;
  document.getElementById('edit_aydesc').value   = ay.acadDesc || '';
  document.getElementById('edit_aystatus').value = ay.status;
  openModal('editModal');
}
function filterAcadYears() {
  var term = (document.getElementById('acadYearSearch').value || '').toLowerCase();
  var rows = document.querySelectorAll('[data-acad-row]');
  var visible = 0;
  rows.forEach(function(row) {
    var matches = (row.getAttribute('data-search') || '').includes(term);
    row.style.display = matches ? '' : 'none';
    if (matches) visible++;
  });
  var empty = document.getElementById('noAcadYearResults');
  if (empty) empty.style.display = visible > 0 ? 'none' : '';
}
document.addEventListener('DOMContentLoaded', function() {
  var input = document.getElementById('acadYearSearch');
  if (input) input.addEventListener('input', filterAcadYears);
});
</script>
<?php require_once ROOT . '/includes/footer.php'; ?>