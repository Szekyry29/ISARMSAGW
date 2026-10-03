<?php
define('ROOT', dirname(__DIR__, 2));
require_once ROOT . '/includes/config.php';
require_once ROOT . '/includes/functions.php';
requireAuth('Registrar');

$db   = Database::getInstance();
$user = getCurrentUser();

$statusFilter = sanitize($_GET['status']      ?? 'Pending');
$progFilter   = (int)($_GET['program_id']    ?? 0);
$ayFilter     = (int)($_GET['acad_year_id']  ?? 0);

$honorEligibilitySql = "
    gr.latinHonor<>'None'
    AND gr.gwaValue > 0
    AND s.isActive = 1
    AND s.dateDeleted IS NULL
    AND EXISTS (
        SELECT 1
        FROM enrollments active_e
        WHERE active_e.studentID = gr.studentID
          AND active_e.status = 'Active'
          AND active_e.dateDeleted IS NULL
          AND (active_e.acadYearID = gr.acadYearID OR gr.acadYearID IS NULL)
          AND (active_e.semester = gr.semester OR gr.semester IS NULL)
    )
    AND NOT EXISTS (
        SELECT 1
        FROM enrollments missing_e
        WHERE missing_e.studentID = gr.studentID
          AND missing_e.status = 'Active'
          AND missing_e.dateDeleted IS NULL
          AND (missing_e.acadYearID = gr.acadYearID OR gr.acadYearID IS NULL)
          AND (missing_e.semester = gr.semester OR gr.semester IS NULL)
          AND " . gwaIncludedSubjectFilter('missing_e') . "
          AND NOT EXISTS (
              SELECT 1
              FROM grades missing_g
              WHERE missing_g.enrollmentID = missing_e.enrollmentID
                AND missing_g.dateDeleted IS NULL
                AND missing_g.status IN ('Posted', 'Approved')
                AND missing_g.gradeEquivalent IS NOT NULL
          )
    )
";

$scopeWhere = [];
$scopeParams = [];
if ($progFilter) { $scopeWhere[] = "s.programID=:pid"; $scopeParams[':pid'] = $progFilter; }
if ($ayFilter)   { $scopeWhere[] = "gr.acadYearID=:ay"; $scopeParams[':ay'] = $ayFilter; }

// Fetch all honors for ranking purposes (no program/year filters for rank calculation)
$allHonors = $db->fetchAll("
    SELECT gr.gwaID, gr.gwaValue, gr.latinHonor, s.yearLevel, gr.validationStatus
    FROM gwa_records gr
    JOIN students s ON s.studentID=gr.studentID
    WHERE $honorEligibilitySql
      AND gr.validationStatus=:vs
    ORDER BY gr.latinHonor, s.yearLevel, gr.gwaValue ASC
", [':vs'=>$statusFilter]);

// Pre-calculate totals per honor type + year level combination
$totalsPerGroup = [];
foreach ($allHonors as $h) {
    $groupKey = $h['latinHonor'] . '|' . $h['yearLevel'];
    $totalsPerGroup[$groupKey] = ($totalsPerGroup[$groupKey] ?? 0) + 1;
}

// Calculate rank for each student based on honor type + year level only
$rankMap = [];
$currentGroup = null;
$rank = 1;
foreach ($allHonors as $h) {
    $groupKey = $h['latinHonor'] . '|' . $h['yearLevel'];
    if ($groupKey !== $currentGroup) {
        $currentGroup = $groupKey;
        $rank = 1;
    }
    $rankMap[$h['gwaID']] = [
        'rank' => $rank,
        'total' => $totalsPerGroup[$groupKey] ?? 1
    ];
    $rank++;
}

// Apply the same eligibility and scope to the displayed rows and tab counts.
$where = array_merge(["gr.validationStatus=:vs", $honorEligibilitySql], $scopeWhere);
$params = array_merge([':vs'=>$statusFilter], $scopeParams);

$honors = $db->fetchAll("
    SELECT gr.gwaID, gr.gwaValue, gr.latinHonor, gr.validationStatus,
           gr.semester, gr.totalUnits, gr.computedAt, gr.validatedAt,
           CONCAT(s.lName,', ',s.fName,' ',COALESCE(SUBSTRING(s.mName,1,1),''),'.') AS studentName,
           s.studentID, s.studentNo, s.yearLevel,
           p.programCode, ay.academicYear,
           CONCAT(uv.lName,', ',uv.fName) AS validatedByName
    FROM gwa_records gr
    JOIN students   s  ON s.studentID  =gr.studentID
    JOIN programs   p  ON p.programID  =s.programID
    JOIN acad_years ay ON ay.acadYearID=gr.acadYearID
    LEFT JOIN users uv ON uv.userID    =gr.validatedByID
    WHERE ".implode(' AND ',$where)."
    ORDER BY gr.gwaValue ASC
", $params);

$countWhere = array_merge([$honorEligibilitySql], $scopeWhere);
$counts = $db->fetchAll("
  SELECT gr.validationStatus, COUNT(DISTINCT gr.gwaID) AS cnt
  FROM gwa_records gr
  JOIN students s ON s.studentID=gr.studentID
  JOIN programs p ON p.programID=s.programID
  JOIN acad_years ay ON ay.acadYearID=gr.acadYearID
  WHERE " . implode(' AND ', $countWhere) . "
  GROUP BY gr.validationStatus
", $scopeParams);
$cmap     = array_column($counts,'cnt','validationStatus');
$programs = $db->fetchAll("SELECT programID, programCode FROM programs WHERE dateDeleted IS NULL ORDER BY programCode");
$acadYears= $db->fetchAll("SELECT acadYearID, academicYear FROM acad_years WHERE dateDeleted IS NULL ORDER BY academicYear DESC");
$publicationPeriods = $db->fetchAll(
    "SELECT ay.acadYearID, ay.academicYear, sem.semester,
            COUNT(DISTINCT gr.gwaID) AS eligibleCount, pub.publishedAt
     FROM acad_years ay
     CROSS JOIN (SELECT 1 AS semester UNION ALL SELECT 2) sem
     LEFT JOIN gwa_records gr ON gr.acadYearID=ay.acadYearID AND gr.semester=sem.semester
       AND gr.validationStatus='Validated' AND gr.latinHonor=:deanList
     LEFT JOIN dean_list_publications pub ON pub.acadYearID=ay.acadYearID AND pub.semester=sem.semester
     WHERE ay.dateDeleted IS NULL
     GROUP BY ay.acadYearID, ay.academicYear, sem.semester, pub.publishedAt
     HAVING eligibleCount > 0 OR pub.publishedAt IS NOT NULL
     ORDER BY ay.academicYear DESC, sem.semester DESC",
    [':deanList'=>"Dean's List"]
);
$csrf = generateCSRF();

$pageTitle  = 'Honors Validation';
$activeMenu = 'honors.php';
require_once ROOT . '/includes/header.php';
?>

<div style="display:flex;gap:8px;margin-bottom:18px;">
  <?php foreach (['Pending','Validated'] as $st): ?>
  <?php $statusParams = $_GET; $statusParams['status'] = $st; ?>
  <a href="?<?= htmlspecialchars(http_build_query($statusParams)) ?>" style="text-decoration:none;">
    <div style="padding:10px 20px;border-radius:10px;display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;
      background:<?= $statusFilter===$st?'var(--blue)':'#fff' ?>;color:<?= $statusFilter===$st?'#fff':'var(--text)' ?>;
      border:1.5px solid <?= $statusFilter===$st?'var(--blue)':'var(--border)' ?>;box-shadow:var(--shadow-sm);">
      <span style="font-size:20px;font-weight:700;"><?= $cmap[$st]??0 ?></span><?= $st ?>
    </div>
  </a>
  <?php endforeach; ?>
  <?php if ($statusFilter==='Validated' && !empty($honors)): ?>
  <a href="reports.php?type=honors_list" class="btn btn-gold" style="margin-left:auto;">
    <i class="fas fa-print"></i> Print Honors List
  </a>
  <?php endif; ?>
</div>

<div class="card" style="margin-bottom:18px;">
  <div class="card-header">
    <div class="card-title"><i class="fas fa-bullhorn"></i> Public Dean's List</div>
  </div>
  <div style="overflow-x:auto;">
    <table class="data-table">
      <thead><tr><th>Academic Year</th><th>Semester</th><th>Validated Students</th><th>Publication</th><th>Action</th></tr></thead>
      <tbody>
        <?php if (!$publicationPeriods): ?><tr><td colspan="5" style="text-align:center;padding:24px;color:var(--text-muted);">No validated Dean's List records are available to publish.</td></tr><?php endif; ?>
        <?php foreach ($publicationPeriods as $period): ?>
        <tr>
          <td><?= htmlspecialchars($period['academicYear']) ?></td>
          <td><?= (int)$period['semester'] === 1 ? '1st' : '2nd' ?> Semester</td>
          <td><?= (int)$period['eligibleCount'] ?></td>
          <td><?php if ($period['publishedAt']): ?>Published <?= date('M d, Y', strtotime($period['publishedAt'])) ?><?php else: ?>Not published<?php endif; ?></td>
          <td>
            <button class="btn <?= $period['publishedAt'] ? 'btn-outline' : 'btn-primary' ?> btn-sm" type="button"
              onclick="setDeanListPublication(<?= (int)$period['acadYearID'] ?>,<?= (int)$period['semester'] ?>,<?= $period['publishedAt'] ? 'false' : 'true' ?>)">
              <i class="fas <?= $period['publishedAt'] ? 'fa-eye-slash' : 'fa-bullhorn' ?>"></i>
              <?= $period['publishedAt'] ? 'Unpublish' : 'Publish list' ?>
            </button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card" style="margin-bottom:14px;">
  <div class="card-body" style="padding:13px 18px;">
    <form method="GET" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
      <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>"/>
      <div><label class="form-label">Program</label><select name="program_id" class="form-control"><option value="">All</option>
        <?php foreach ($programs as $p): ?><option value="<?= $p['programID'] ?>" <?= $progFilter==$p['programID']?'selected':'' ?>><?= $p['programCode'] ?></option><?php endforeach; ?>
      </select></div>
      <div><label class="form-label">Academic Year</label><select name="acad_year_id" class="form-control"><option value="">All</option>
        <?php foreach ($acadYears as $ay): ?><option value="<?= $ay['acadYearID'] ?>" <?= $ayFilter==$ay['acadYearID']?'selected':'' ?>><?= $ay['academicYear'] ?></option><?php endforeach; ?>
      </select></div>
      <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter"></i> Filter</button>
      <a href="honors.php" class="btn btn-outline btn-sm"><i class="fas fa-times"></i></a>
    </form>
  </div>
</div>

<?php if (empty($honors)): ?>
<div class="card"><div style="padding:48px;text-align:center;color:var(--text-muted);">
  <i class="fas fa-award" style="font-size:44px;display:block;margin-bottom:14px;opacity:.2;color:var(--gold);"></i>
  <div style="font-weight:600;margin-bottom:6px;">No <?= strtolower($statusFilter) ?> honor records</div>
  <div style="font-size:13px;">Honor records appear after GWA is computed from released grades.</div>
</div></div>
<?php else:
  $grouped = [];
  foreach ($honors as $h) $grouped[$h['latinHonor']][] = $h;
  $orderMap = ['Summa Cum Laude'=>1,'Magna Cum Laude'=>2,'Cum Laude'=>3,"Dean's List"=>4];
  uksort($grouped, fn($a,$b) => ($orderMap[$a]??99) <=> ($orderMap[$b]??99));
?>

<?php foreach ($grouped as $honorType => $rows): ?>
<div style="margin-bottom:22px;">
  <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
    <span class="<?= honorBadgeClass($honorType) ?>" style="font-size:14px;"><?= htmlspecialchars($honorType) ?></span>
    <span style="font-size:13px;color:var(--text-muted);"><?= count($rows) ?> student(s)</span>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:12px;">
    <?php foreach ($rows as $h):
      $borderColor = match($h['latinHonor']){'Summa Cum Laude'=>'#f5c518','Magna Cum Laude'=>'#c0c0c0','Cum Laude'=>'#cd7f32',default=>'var(--blue)'};
      $studentRank = $rankMap[$h['gwaID']] ?? ['rank'=>1, 'total'=>1];
    ?>
    <div class="card" style="border-left:4px solid <?= $borderColor ?>;padding:0;">
      <div style="padding:16px 18px;">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:10px;margin-bottom:10px;">
          <div style="flex:1;min-width:0;">
            <div style="font-weight:700;font-size:13.5px;"><?= htmlspecialchars($h['studentName']) ?></div>
            <div style="font-size:11.5px;color:var(--blue);font-weight:600;"><?= htmlspecialchars($h['studentNo']) ?></div>
            <div style="font-size:11px;color:var(--text-muted);margin-top:2px;">
              <?= $h['programCode'] ?> &bull; Yr <?= $h['yearLevel'] ?> &bull; <?= $h['academicYear'] ?>
              <?= $h['semester']?' &bull; '.($h['semester']==1?'1st':'2nd').' Sem':' &bull; Cumulative' ?>
            </div>
          </div>
          <div style="text-align:right;flex-shrink:0;">
            <div style="font-size:26px;font-weight:700;color:var(--gold-deep);line-height:1;"><?= formatGWA((float)$h['gwaValue']) ?></div>
            <div style="font-size:11px;color:var(--text-muted);"><?= $h['totalUnits'] ?> units</div>
          </div>
        </div>
        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 12px;background:rgba(59,130,246,0.05);border-radius:6px;margin:10px 0;font-size:12px;">
          <span style="font-weight:600;color:var(--blue);">
            <i class="fas fa-medal"></i> Rank #<?= $studentRank['rank'] ?> of <?= $studentRank['total'] ?> (Year <?= $h['yearLevel'] ?>)
          </span>
        </div>
        <div style="display:flex;align-items:center;justify-content:space-between;padding-top:10px;border-top:1px solid var(--border);">
          <div style="font-size:11.5px;color:var(--text-muted);">
            <?php if ($h['validationStatus']==='Validated'): ?>
            <span style="color:var(--success);font-weight:600;"><i class="fas fa-check-circle"></i> Validated <?= date('M d, Y',strtotime($h['validatedAt'])) ?></span>
            <?php else: ?>
            Computed <?= date('M d, Y',strtotime($h['computedAt'])) ?>
            <?php endif; ?>
          </div>
            <?php if ($statusFilter==='Pending'): ?>
          <div style="display:flex;gap:6px;">
            <button class="btn btn-success btn-sm" onclick="validateHonor(<?= $h['gwaID'] ?>)"><i class="fas fa-check"></i> Validate</button>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<script>
const deanListCsrfToken = <?= json_encode($csrf) ?>;
function setDeanListPublication(acadYearID, semester, shouldPublish) {
  const action = shouldPublish ? 'publish_deans_list' : 'unpublish_deans_list';
  const verb = shouldPublish ? 'publish' : 'remove this list from public viewing';
  if (!confirm(`Are you sure you want to ${verb}?`)) return;
  fetch('<?= BASE_URL ?>api/gwa.php', {
    method: 'POST',
    headers: {'Content-Type':'application/json', 'X-CSRF-Token':deanListCsrfToken},
    body: JSON.stringify({action, acadYearID, semester})
  }).then(response => response.json()).then(data => {
    showToast(data.message, data.success ? 'success' : 'error');
    if (data.success) setTimeout(() => location.reload(), 800);
  }).catch(() => showToast('Unable to update publication. Please try again.', 'error'));
}
function validateHonor(id){if(!confirm('Validate this honor? Student will be notified.'))return;fetch('<?= BASE_URL ?>api/gwa.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'validate',gwaID:id})}).then(r=>r.json()).then(d=>{showToast(d.message,d.success?'success':'error');if(d.success)setTimeout(()=>location.reload(),1200);});}
</script>

<?php require_once ROOT . '/includes/footer.php'; ?>
