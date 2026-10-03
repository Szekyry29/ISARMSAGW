<?php
define('ROOT', dirname(__DIR__, 2));
require_once ROOT . '/includes/config.php';
require_once ROOT . '/includes/functions.php';
requireAuth('Admin');

$db   = Database::getInstance();
$msg  = $err = '';

function writeSubjectStreamPayload(array $payload): void {
    $feedDir = ROOT . '/storage';
    if (!is_dir($feedDir)) {
        @mkdir($feedDir, 0755, true);
    }
    $feedPath = $feedDir . '/subjects_feed.json';
    @file_put_contents(
        $feedPath,
        json_encode([
            'time'    => time(),
            'subject' => $payload,
            'kind'    => 'subject_update'
        ]),
        LOCK_EX
    );
}

// Student availability is no longer a separate manual/derived field.
// The Course / Program tag alone determines who the subject is for:
// tagging a subject to a specific program (e.g. BSIT) restricts it to
// that program's students; leaving it untagged makes it available to all.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) { $err = 'Invalid request.'; }
    else {
        $action = $_POST['action'] ?? '';
        if ($action === 'add') {
            $code = strtoupper(trim($_POST['subjectCode'] ?? ''));
            $exists = $db->fetchOne("SELECT subjectID FROM subjects WHERE subjectCode=:c AND dateDeleted IS NULL",[':c'=>$code]);
            if ($exists) { $err = "Subject code '$code' already exists."; }
            else {
                $newId = $db->insert("INSERT INTO subjects (subjectCode,subjectName,units,programID,yearLevel,semester) VALUES (:c,:n,:u,:p,:y,:s)",
                    [':c'=>$code,':n'=>sanitize($_POST['subjectName']??''),':u'=>(float)($_POST['units']??3),
                     ':p'=>!empty($_POST['programID'])?(int)$_POST['programID']:null,
                     ':y'=>!empty($_POST['yearLevel'])?(int)$_POST['yearLevel']:null,
                     ':s'=>!empty($_POST['semester'])?(int)$_POST['semester']:null]);
                writeSubjectStreamPayload(['id' => (int)$newId, 'action' => 'add', 'updatedAt' => time()]);
                $msg = 'Subject added successfully.';
            }
        } elseif ($action === 'edit') {
            $sid = (int)($_POST['subjectID'] ?? 0);
            $db->query("UPDATE subjects SET subjectCode=:c,subjectName=:n,units=:u,programID=:p,yearLevel=:y,semester=:s WHERE subjectID=:id",
                [':c'=>strtoupper(trim($_POST['subjectCode']??'')),':n'=>sanitize($_POST['subjectName']??''),
                 ':u'=>(float)($_POST['units']??3),':p'=>!empty($_POST['programID'])?(int)$_POST['programID']:null,
                 ':y'=>!empty($_POST['yearLevel'])?(int)$_POST['yearLevel']:null,
                 ':s'=>!empty($_POST['semester'])?(int)$_POST['semester']:null,
                 ':id'=>$sid]);
            writeSubjectStreamPayload(['id' => (int)$sid, 'action' => 'edit', 'updatedAt' => time()]);
            $msg = 'Subject updated successfully.';
        } elseif ($action === 'archive') {
          // Soft-delete: mark as archived instead of a hard delete.
          $sid = (int)($_POST['subjectID'] ?? 0);
          $ts = date('Y-m-d H:i:s');
          $db->query("UPDATE subjects SET dateDeleted=:ts WHERE subjectID=:id",[':ts'=>$ts,':id'=>$sid]);
          // Drop (soft-delete) enrollments for this subject
          $db->query("UPDATE enrollments SET status='Dropped', dateDeleted=:ts WHERE subjectID=:id AND dateDeleted IS NULL",[':ts'=>$ts,':id'=>$sid]);
          writeSubjectStreamPayload(['id' => (int)$sid, 'action' => 'archive', 'updatedAt' => time()]);
          $msg = 'Subject archived.';
        } elseif ($action === 'restore') {
          // Un-archive: clear dateDeleted so the subject becomes active again.
          $sid = (int)($_POST['subjectID'] ?? 0);
          // Find the subject's deleted timestamp so we only restore enrollments removed by this archive
          $s = $db->fetchOne("SELECT dateDeleted FROM subjects WHERE subjectID=:id",[':id'=>$sid]);
          $deletedAt = $s['dateDeleted'] ?? null;
          if ($deletedAt) {
            $db->query("UPDATE enrollments SET dateDeleted=NULL, status='Active' WHERE subjectID=:id AND dateDeleted=:ts",[':id'=>$sid,':ts'=>$deletedAt]);
          }
          $db->query("UPDATE subjects SET dateDeleted=NULL WHERE subjectID=:id",[':id'=>$sid]);
          writeSubjectStreamPayload(['id' => (int)$sid, 'action' => 'restore', 'updatedAt' => time()]);
          $msg = 'Subject restored.';
        }
    }
}

// Filters
$progFilter  = (int)($_GET['program_id'] ?? 0);
$yearFilter  = (int)($_GET['year_level'] ?? 0);
$semFilter   = (int)($_GET['semester']   ?? 0);
$search      = sanitize($_GET['search']  ?? '');
$showArchived = isset($_GET['archived']) && $_GET['archived'] == '1';

$where = [$showArchived ? "s.dateDeleted IS NOT NULL" : "s.dateDeleted IS NULL"]; $params = [];

// Each filter below is independent — it is only added to the WHERE clause
// when it actually has a value. If the search box is left empty, no search
// condition is added, so it does NOT hide results. This means picking a
// dropdown (Program / Year Level / Semester) while the search box is blank
// still correctly shows matching results — it does not return "no results"
// just because search is empty.
if ($progFilter) { $where[] = "s.programID=:p";  $params[':p'] = $progFilter; }
if ($yearFilter) { $where[] = "s.yearLevel=:y";  $params[':y'] = $yearFilter; }
if ($semFilter)  { $where[] = "s.semester=:sm";  $params[':sm'] = $semFilter; }
if ($search)     { $where[] = "(s.subjectCode LIKE :q1 OR s.subjectName LIKE :q2)"; $params[':q1'] = "%$search%"; $params[':q2'] = "%$search%"; }

$activeYear = $db->fetchOne("SELECT acadYearID FROM acad_years WHERE status='Active' AND dateDeleted IS NULL LIMIT 1");
$activeYearID = $activeYear ? (int)$activeYear['acadYearID'] : null;
$joinActiveYear = $activeYearID !== null ? " AND e.acadYearID=:activeYearID" : '';

$subjectsQuery = "
    SELECT s.*, p.programCode,
           CASE WHEN s.programID IS NULL OR s.programID = 0 THEN 'All Programs' ELSE p.programCode END AS courseTag,
           COUNT(e.enrollmentID) as enrollCount
    FROM subjects s
    LEFT JOIN programs p ON p.programID=s.programID
    LEFT JOIN enrollments e ON e.subjectID=s.subjectID
        AND e.status='Active'
        AND e.dateDeleted IS NULL" . $joinActiveYear . "
    WHERE ".implode(' AND ',$where)."
    GROUP BY s.subjectID
    ORDER BY p.programCode, s.yearLevel, s.semester, s.subjectCode
";
if ($activeYearID !== null) {
    $params[':activeYearID'] = $activeYearID;
}
$subjects = $db->fetchAll($subjectsQuery, $params);

$programs = $db->fetchAll("SELECT programID, programCode, programName FROM programs WHERE dateDeleted IS NULL ORDER BY programCode");
$semLabels = [1=>'1st Semester', 2=>'2nd Semester'];
$yearLabels = [1=>'1st Year', 2=>'2nd Year', 3=>'3rd Year', 4=>'4th Year', 5=>'Irregular'];

// Group subjects by Program -> Year Level -> Semester for display.
// Subjects with no program are bucketed under "All Programs";
// subjects with no year/semester set fall under "Unassigned".
$grouped = [];
$progCounts = [];
foreach ($subjects as $s) {
    $progKey  = $s['programCode'] ? $s['programCode'] : 'All Programs';
    $yearKey  = isset($yearLabels[$s['yearLevel']]) ? $yearLabels[$s['yearLevel']] : 'Unassigned Year';
    $semKey   = isset($semLabels[$s['semester']]) ? $semLabels[$s['semester']] : 'Unassigned Semester';
    $grouped[$progKey][$yearKey][$semKey][] = $s;
    $progCounts[$progKey] = ($progCounts[$progKey] ?? 0) + 1;
}

// "All Programs" tab goes last so specific courses are seen first.
if (isset($grouped['All Programs'])) {
    $allProgramsBucket = $grouped['All Programs'];
    unset($grouped['All Programs']);
    $grouped['All Programs'] = $allProgramsBucket;
}

$csrf = generateCSRF();
$pageTitle = 'Subjects'; $activeMenu = 'subjects.php';
require_once ROOT . '/includes/header.php';
?>

<?php if ($msg): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger"><i class="fas fa-times-circle"></i> <?= htmlspecialchars($err) ?></div><?php endif; ?>

<!-- Filters -->
<div class="card" style="margin-bottom:16px;">
  <div class="card-body">
    <form method="GET" style="display:grid;grid-template-columns:2fr 1fr 1fr 1fr auto auto;gap:10px;align-items:end;">
      <div>
        <label class="form-label">Search</label>
        <div style="position:relative;">
          <i class="fas fa-search" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:13px;"></i>
          <input type="text" name="search" class="form-control" style="padding-left:34px;" placeholder="Code or name…" value="<?= htmlspecialchars($search) ?>"/>
        </div>
      </div>
      <div>
        <label class="form-label">Program</label>
        <!--
          onchange = auto-submit. Each dropdown below submits the SAME
          GET form, so whatever is already selected in the other three
          fields (search box included) rides along automatically —
          picking a Program here does not reset/ignore Year Level,
          Semester, or Search, and vice versa. They all AND together.
        -->
        <select name="program_id" class="form-control" onchange="this.form.submit()">
          <option value="">All Programs</option>
          <?php foreach ($programs as $p): ?><option value="<?= $p['programID'] ?>" <?= $progFilter==$p['programID']?'selected':'' ?>><?= $p['programCode'] ?></option><?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="form-label">Year Level</label>
        <select name="year_level" class="form-control" onchange="this.form.submit()">
          <option value="">All Years</option>
          <?php foreach ($yearLabels as $y=>$l): ?><option value="<?= $y ?>" <?= $yearFilter==$y?'selected':'' ?>><?= $l ?></option><?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="form-label">Semester</label>
        <select name="semester" class="form-control" onchange="this.form.submit()">
          <option value="">Both</option>
          <?php foreach ($semLabels as $s=>$l): ?><option value="<?= $s ?>" <?= $semFilter==$s?'selected':'' ?>><?= $l ?></option><?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn btn-outline"><i class="fas fa-filter"></i> Filter</button>
      <a href="subjects.php" class="btn btn-outline" title="Clear all filters"><i class="fas fa-times"></i></a>
      <?php if ($showArchived): ?><input type="hidden" name="archived" value="1"/><?php endif; ?>
    </form>
  </div>
</div>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
  <div style="font-size:13px;color:var(--text-muted);"><strong><?= count($subjects) ?></strong> <?= $showArchived ? 'archived' : '' ?> subjects found</div>
  <div style="display:flex;gap:8px;">
    <?php
      // Build the toggle link while preserving current filters.
      $toggleParams = $_GET;
      if ($showArchived) { unset($toggleParams['archived']); } else { $toggleParams['archived'] = 1; }
      $toggleUrl = 'subjects.php' . (!empty($toggleParams) ? '?' . http_build_query($toggleParams) : '');
    ?>
    <a href="<?= htmlspecialchars($toggleUrl) ?>" class="btn btn-outline">
      <?php if ($showArchived): ?>
        <i class="fas fa-list"></i> View Active Subjects
      <?php else: ?>
        <i class="fas fa-archive"></i> View Archived
      <?php endif; ?>
    </a>
    <?php if (!$showArchived): ?>
    <button class="btn btn-primary" onclick="openModal('addModal')"><i class="fas fa-plus"></i> Add Subject</button>
    <?php endif; ?>
  </div>
</div>

<?php if (empty($subjects)): ?>
<div class="card">
  <div style="text-align:center;padding:40px;color:var(--text-muted);">No <?= $showArchived ? 'archived' : '' ?> subjects found.</div>
</div>
<?php else: ?>

<!--
  Course / Program nav bar.
  Instead of stacking every program's subjects on the page at once
  (which is what made the list so long), each program is now a tab.
  Only the active tab's panel is visible; switching tabs is pure
  client-side show/hide, no page reload needed.
-->
<?php $progKeysList = array_keys($grouped); ?>
<div class="subject-tabs" style="display:flex;gap:4px;flex-wrap:wrap;border-bottom:2px solid #eee;margin-bottom:16px;">
  <?php foreach ($progKeysList as $i => $progKey):
      $tabId = 'tab-' . md5($progKey);
      $isActive = $i === 0;
  ?>
    <button type="button"
            class="tab-btn<?= $isActive ? ' active' : '' ?>"
            data-tab="<?= $tabId ?>"
            onclick="switchProgramTab(this,'<?= $tabId ?>')"
            style="padding:10px 16px;border:none;background:none;cursor:pointer;font-weight:700;font-size:13px;
                   color:<?= $isActive ? 'var(--green)' : 'var(--text-muted)' ?>;
                   border-bottom:3px solid <?= $isActive ? 'var(--green)' : 'transparent' ?>;
                   display:flex;align-items:center;gap:6px;">
      <i class="fas fa-graduation-cap"></i>
      <?= htmlspecialchars($progKey) ?>
      <span style="background:#f1f5f9;color:#334155;padding:2px 7px;border-radius:10px;font-size:11px;font-weight:700;">
        <?= $progCounts[$progKey] ?? 0 ?>
      </span>
    </button>
  <?php endforeach; ?>
</div>

<?php foreach ($grouped as $progKey => $years):
    $progTabId = 'tab-' . md5($progKey);
    $progIsActive = $progKey === $progKeysList[0];

    // Sort year buckets in natural order (1st..4th, Irregular, then Unassigned last)
    $yearOrder = array_values($yearLabels); $yearOrder[] = 'Unassigned Year';
    uksort($years, function($a,$b) use ($yearOrder){
        $pa = array_search($a, $yearOrder); $pb = array_search($b, $yearOrder);
        $pa = $pa === false ? 999 : $pa; $pb = $pb === false ? 999 : $pb;
        return $pa <=> $pb;
    });
    $yearKeysList = array_keys($years);
?>
  <div class="tab-panel" id="<?= $progTabId ?>" style="display:<?= $progIsActive ? 'block' : 'none' ?>;">

    <?php if (count($yearKeysList) > 1): ?>
    <!--
      Year Level sub-tabs, nested inside this Program tab.
      Same idea as the Program tabs above, one level down: instead of
      stacking every year's subjects one after another, only the
      selected year is visible at a time. Skipped entirely (see PHP
      count check) when this program only has one year bucket to
      show — no point rendering a single-button tab bar.
    -->
    <div class="year-tabs" data-owner="<?= $progTabId ?>" style="display:flex;gap:4px;flex-wrap:wrap;margin-bottom:14px;">
      <?php foreach ($yearKeysList as $j => $yearKey):
          $yearTabId = 'year-' . md5($progKey . '|' . $yearKey);
          $yearIsActive = $j === 0;
      ?>
        <button type="button"
                class="tab-btn year-tab-btn<?= $yearIsActive ? ' active' : '' ?>"
                onclick="switchYearTab(this,'<?= $yearTabId ?>')"
                style="padding:7px 14px;border:1px solid #e5e7eb;border-radius:20px;background:<?= $yearIsActive ? 'var(--green)' : '#fff' ?>;
                       color:<?= $yearIsActive ? '#fff' : 'var(--text-muted)' ?>;cursor:pointer;font-weight:600;font-size:12px;
                       display:flex;align-items:center;gap:6px;">
          <i class="fas fa-layer-group"></i>
          <?= htmlspecialchars($yearKey) ?>
          <span style="background:<?= $yearIsActive ? 'rgba(255,255,255,.25)' : '#f1f5f9' ?>;color:<?= $yearIsActive ? '#fff' : '#334155' ?>;padding:1px 6px;border-radius:10px;font-size:11px;">
            <?= array_sum(array_map('count', $years[$yearKey])) ?>
          </span>
        </button>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php foreach ($years as $yearKey => $sems):
        $yearTabId = 'year-' . md5($progKey . '|' . $yearKey);
        $yearIsActive = $yearKey === $yearKeysList[0];
        $multiYear = count($yearKeysList) > 1;
    ?>
      <div class="year-panel" id="<?= $yearTabId ?>" style="<?= $multiYear ? 'display:'.($yearIsActive ? 'block' : 'none').';' : '' ?>">
        <?php if (!$multiYear): ?>
        <h4 style="font-size:14px;font-weight:700;margin-bottom:8px;color:var(--text-dark, #333);">
          <i class="fas fa-layer-group"></i> <?= htmlspecialchars($yearKey) ?>
        </h4>
        <?php endif; ?>
        <?php
          $semOrder = array_values($semLabels); $semOrder[] = 'Unassigned Semester';
          uksort($sems, function($a,$b) use ($semOrder){
              $pa = array_search($a, $semOrder); $pb = array_search($b, $semOrder);
              $pa = $pa === false ? 999 : $pa; $pb = $pb === false ? 999 : $pb;
              return $pa <=> $pb;
          });
        ?>
        <?php foreach ($sems as $semKey => $rows): ?>
        <div class="card" style="margin-bottom:12px;">
          <div style="padding:10px 16px;font-size:12px;font-weight:700;color:var(--text-muted);border-bottom:1px solid #eee;">
            <i class="fas fa-calendar"></i> <?= htmlspecialchars($semKey) ?>
          </div>
          <div style="overflow-x:auto;">
            <!--
              table-layout:fixed + colgroup below locks each column to a
              consistent width/position across EVERY semester table on the
              page, regardless of how long the content in any given row is
              (e.g. a "Restore" button vs an icon-only Archive button).
              Without this, each table auto-sizes its own columns and the
              Units / Course-Program / Enrollments / Actions columns end up
              misaligned from one table block to the next.
            -->
            <table class="data-table" style="table-layout:fixed;width:100%;">
              <colgroup>
                <col style="width:14%">
                <col style="width:36%">
                <col style="width:8%">
                <col style="width:16%">
                <col style="width:12%">
                <col style="width:14%">
              </colgroup>
              <thead>
                <tr>
                  <th>Subject Code</th>
                  <th>Subject Name</th>
                  <th style="text-align:center;">Units</th>
                  <th style="text-align:center;">Course / Program</th>
                  <th style="text-align:center;">Enrollments</th>
                  <th style="text-align:center;">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($rows as $s): ?>
                <tr>
                  <td style="font-weight:700;color:var(--green);font-size:14px;vertical-align:middle;"><?= htmlspecialchars($s['subjectCode']) ?></td>
                  <td style="font-size:13px;vertical-align:middle;"><?= htmlspecialchars($s['subjectName']) ?></td>
                  <td style="text-align:center;vertical-align:middle;font-weight:700;font-size:16px;"><?= $s['units'] ?></td>
                  <td style="text-align:center;vertical-align:middle;">
                    <?php
                      $courseLabel = $s['programCode'] ?: 'All Programs';
                      echo '<span style="background:#e0f2fe;color:#0f172a;padding:4px 8px;border-radius:4px;font-size:11px;font-weight:700;">' . htmlspecialchars($courseLabel) . '</span>';
                    ?>
                  </td>
                  <td style="text-align:center;vertical-align:middle;font-weight:600;"><?= $s['enrollCount'] ?></td>
                  <td style="text-align:center;vertical-align:middle;">
                    <div style="display:flex;gap:6px;justify-content:center;">
                      <?php if (!$showArchived): ?>
                        <button class="btn btn-outline btn-sm" onclick="viewEnrollments(<?= $s['subjectID'] ?>, '<?= htmlspecialchars($s['subjectCode'], ENT_QUOTES) ?>')" title="View Enrollments">
                          <i class="fas fa-users"></i>
                        </button>
                        <button class="btn btn-outline btn-sm" onclick="openEdit(<?= htmlspecialchars(json_encode($s),ENT_QUOTES) ?>)" title="Edit">
                          <i class="fas fa-pen"></i>
                        </button>
                        <form method="POST" onsubmit="return confirm('Archive this subject? It can be restored later.')" style="display:inline;">
                          <input type="hidden" name="csrf_token"  value="<?= $csrf ?>"/>
                          <input type="hidden" name="action"      value="archive"/>
                          <input type="hidden" name="subjectID"   value="<?= $s['subjectID'] ?>"/>
                          <button class="btn btn-danger btn-sm" type="submit" title="Archive"><i class="fas fa-archive"></i></button>
                        </form>
                      <?php else: ?>
                        <form method="POST" onsubmit="return confirm('Restore this subject?')" style="display:inline;">
                          <input type="hidden" name="csrf_token"  value="<?= $csrf ?>"/>
                          <input type="hidden" name="action"      value="restore"/>
                          <input type="hidden" name="subjectID"   value="<?= $s['subjectID'] ?>"/>
                          <button class="btn btn-primary btn-sm" type="submit" title="Restore"><i class="fas fa-undo"></i> Restore</button>
                        </form>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </div>
<?php endforeach; ?>
<?php endif; ?>

<?php foreach (['add'=>'Add New Subject','edit'=>'Edit Subject'] as $mode=>$title): ?>
<div id="<?= $mode ?>Modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:999;align-items:center;justify-content:center;padding:24px;">
  <div style="background:#fff;border-radius:16px;padding:32px;width:540px;max-width:100%;box-shadow:0 20px 60px rgba(0,0,0,.2);">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;">
      <h3 style="font-family:'Playfair Display',serif;font-size:20px;"><?= $title ?></h3>
      <button onclick="closeModal('<?= $mode ?>Modal')" style="background:none;border:none;font-size:20px;cursor:pointer;">&times;</button>
    </div>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>"/>
      <input type="hidden" name="action"     value="<?= $mode ?>"/>
      <?php if ($mode==='edit'): ?><input type="hidden" name="subjectID" id="edit_sid"/><?php endif; ?>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
        <div>
          <label class="form-label">Subject Code *</label>
          <input name="subjectCode" id="<?= $mode ?>_code" class="form-control" required placeholder="CS101" style="text-transform:uppercase;"/>
        </div>
        <div>
          <label class="form-label">Units *</label>
          <input name="units" id="<?= $mode ?>_units" type="number" class="form-control" step="0.5" min="0.5" max="9" required value="3"/>
        </div>
      </div>
      <div style="margin-bottom:14px;">
        <label class="form-label">Subject Name *</label>
        <input name="subjectName" id="<?= $mode ?>_name" class="form-control" required placeholder="Introduction to Computing"/>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;margin-bottom:14px;">
        <div>
          <label class="form-label">Course / Program Tag</label>
          <select name="programID" id="<?= $mode ?>_prog" class="form-control">
            <option value="">All Programs (Shared Subject)</option>
            <?php foreach ($programs as $p): ?><option value="<?= $p['programID'] ?>"><?= htmlspecialchars($p['programCode']) ?> — <?= htmlspecialchars($p['programName']) ?></option><?php endforeach; ?>
          </select>
          <small style="color:var(--text-muted);margin-top:6px;display:block;">Set this to a specific course if this subject belongs only to BSIT, BSCS, etc.</small>
        </div>
        <div>
          <label class="form-label">Year Level</label>
          <select name="yearLevel" id="<?= $mode ?>_year" class="form-control">
            <option value="">—</option>
            <?php foreach ($yearLabels as $y=>$l): ?><option value="<?= $y ?>"><?= $l ?></option><?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="form-label">Semester</label>
          <select name="semester" id="<?= $mode ?>_sem" class="form-control">
            <option value="">—</option>
            <?php foreach ($semLabels as $s=>$l): ?><option value="<?= $s ?>"><?= $l ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>
      <div style="display:flex;gap:10px;justify-content:flex-end;">
        <button type="button" class="btn btn-outline" onclick="closeModal('<?= $mode ?>Modal')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> <?= $mode==='add'?'Add Subject':'Update Subject' ?></button>
      </div>
    </form>
  </div>
</div>
<?php endforeach; ?>

<script>
function openModal(id)  { document.getElementById(id).style.display = 'flex'; }
function closeModal(id) { document.getElementById(id).style.display = 'none'; }

// Program/Course tab switching for the subjects list.
// Only one program's panel is shown at a time so the page isn't
// a long stack of every course's subjects.
function switchProgramTab(btn, tabId) {
  if (!btn) return;
  document.querySelectorAll('.tab-panel').forEach(function(p) { p.style.display = 'none'; });
  var panel = document.getElementById(tabId);
  if (panel) panel.style.display = 'block';

  document.querySelectorAll('.tab-btn').forEach(function(b) {
    b.classList.remove('active');
    b.style.color = 'var(--text-muted)';
    b.style.borderBottomColor = 'transparent';
  });
  btn.classList.add('active');
  btn.style.color = 'var(--green)';
  btn.style.borderBottomColor = 'var(--green)';
}

// Year Level sub-tab switching, scoped to the clicked button's own
// pill-tab bar (data-owner) so clicking a year inside "BSIT" never
// touches the year panels sitting inside "BSCS" or any other program.
function switchYearTab(btn, yearTabId) {
  if (!btn) return;
  var bar = btn.closest('.year-tabs');
  var owner = bar ? bar.getAttribute('data-owner') : null;
  var progPanel = owner ? document.getElementById(owner) : btn.closest('.tab-panel');
  if (!progPanel) return;

  progPanel.querySelectorAll('.year-panel').forEach(function(p) { p.style.display = 'none'; });
  var target = document.getElementById(yearTabId);
  if (target) target.style.display = 'block';

  if (bar) {
    bar.querySelectorAll('.year-tab-btn').forEach(function(b) {
      b.classList.remove('active');
      b.style.background = '#fff';
      b.style.color = 'var(--text-muted)';
      var badge = b.querySelector('span');
      if (badge) {
        badge.style.background = '#f1f5f9';
        badge.style.color = '#334155';
      }
    });
  }
  btn.classList.add('active');
  btn.style.background = 'var(--green)';
  btn.style.color = '#fff';
  var badge = btn.querySelector('span');
  if (badge) {
    badge.style.background = 'rgba(255,255,255,.25)';
    badge.style.color = '#fff';
  }
}

function openEdit(s) {
  document.getElementById('edit_sid').value   = s.subjectID;
  document.getElementById('edit_code').value  = s.subjectCode;
  document.getElementById('edit_name').value  = s.subjectName;
  document.getElementById('edit_units').value = s.units;
  document.getElementById('edit_prog').value  = s.programID || '';
  document.getElementById('edit_year').value  = s.yearLevel || '';
  document.getElementById('edit_sem').value   = s.semester  || '';
  openModal('editModal');
}

function viewEnrollments(subjectID, subjectCode) {
  document.getElementById('enroll_subjectCode').textContent = subjectCode;
  document.getElementById('enrollModal').style.display = 'flex';

  fetch('<?= BASE_URL ?>api/enrollments.php?action=get_by_subject&subjectID=' + subjectID)
    .then(r => r.json())
    .then(d => {
      const tbody = document.getElementById('enrollments_list');
      if (d.success && d.data && d.data.length > 0) {
        tbody.innerHTML = d.data.map(e => `
          <tr>
            <td>${e.studentNo}</td>
            <td>${e.studentName}</td>
            <td>${e.programCode}</td>
            <td>Year ${e.yearLevel}</td>
            <td>${e.acadYear}</td>
            <td>${e.semester == 1 ? '1st' : '2nd'}</td>
            <td><span class="status-badge ${e.status === 'Active' ? 'status-approved' : 'status-flagged'}">${e.status}</span></td>
          </tr>
        `).join('');
      } else {
        tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;padding:30px;color:var(--text-muted);">No enrollments found for this subject.</td></tr>';
      }
    })
    .catch(err => {
      document.getElementById('enrollments_list').innerHTML = '<tr><td colspan="7" style="text-align:center;padding:30px;color:var(--danger);">Error loading enrollments.</td></tr>';
    });
}

function closeEnrollments() {
  document.getElementById('enrollModal').style.display = 'none';
}

if (typeof(EventSource) !== 'undefined') {
  try {
    const subjectStream = new EventSource('<?= BASE_URL ?>modules/admin/subjects_sse.php');
    subjectStream.onmessage = function() {
      window.location.reload();
    };
    subjectStream.onerror = function() {
      try { subjectStream.close(); } catch (e) {}
    };
  } catch (err) {
    console.warn('Subject SSE unavailable:', err);
  }
}
</script>

<!-- View Enrollments Modal -->
<div id="enrollModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:999;align-items:center;justify-content:center;padding:24px;">
  <div style="background:#fff;border-radius:16px;padding:32px;width:800px;max-width:100%;box-shadow:0 20px 60px rgba(0,0,0,.2);">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;">
      <h3 style="font-family:'Playfair Display',serif;font-size:20px;"><i class="fas fa-users"></i> Enrollments for <span id="enroll_subjectCode"></span></h3>
      <button onclick="closeEnrollments()" style="background:none;border:none;font-size:20px;cursor:pointer;">&times;</button>
    </div>
    <div style="overflow-x:auto;max-height:400px;overflow-y:auto;">
      <table class="data-table">
        <thead>
          <tr>
            <th>Student No.</th>
            <th>Student Name</th>
            <th>Program</th>
            <th>Year</th>
            <th>Academic Year</th>
            <th>Semester</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody id="enrollments_list">
          <tr><td colspan="7" style="text-align:center;padding:30px;">Loading...</td></tr>
        </tbody>
      </table>
    </div>
    <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px;">
      <button type="button" class="btn btn-outline" onclick="closeEnrollments()">Close</button>
    </div>
  </div>
</div>

<?php require_once ROOT . '/includes/footer.php'; ?>