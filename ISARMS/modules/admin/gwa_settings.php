<?php
define('ROOT', dirname(__DIR__, 2));
require_once ROOT . '/includes/config.php';
require_once ROOT . '/includes/functions.php';
requireAuth('Admin');

$db = Database::getInstance();
$user = getCurrentUser();
$message = '';
$error = '';
$academicYears = $db->fetchAll(
    "SELECT acadYearID, academicYear, status FROM acad_years
     WHERE dateDeleted IS NULL ORDER BY acadYearID DESC"
);
$activeYear = $db->fetchOne(
    "SELECT acadYearID FROM acad_years WHERE status='Active' AND dateDeleted IS NULL
     ORDER BY acadYearID DESC LIMIT 1"
);
$selectedYearID = (int)($_POST['acadYearID'] ?? $_GET['acad_year_id'] ?? $activeYear['acadYearID'] ?? ($academicYears[0]['acadYearID'] ?? 0));
$validYearIDs = array_map(static fn($year) => (int)$year['acadYearID'], $academicYears);
if (!in_array($selectedYearID, $validYearIDs, true)) {
    $selectedYearID = (int)($activeYear['acadYearID'] ?? ($academicYears[0]['acadYearID'] ?? 0));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Refresh the page and try again.';
    } elseif ($selectedYearID < 1) {
        $error = 'Create an academic year before configuring GWA rules.';
    } else {
        $subjects = $db->fetchAll("SELECT subjectID FROM subjects WHERE dateDeleted IS NULL");
        $allowedSubjectIDs = array_map(static fn($subject) => (int)$subject['subjectID'], $subjects);
        $submittedIDs = array_map('intval', (array)($_POST['includeSubjects'] ?? []));
        $includedIDs = array_fill_keys(array_intersect($submittedIDs, $allowedSubjectIDs), true);

        $db->beginTransaction();
        try {
            foreach ($allowedSubjectIDs as $subjectID) {
                $db->query(
                    "INSERT INTO subject_gwa_rules (acadYearID, subjectID, includeInGwa, updatedByID)
                     VALUES (:ay, :subject, :included, :user)
                     ON DUPLICATE KEY UPDATE includeInGwa=VALUES(includeInGwa),
                         updatedByID=VALUES(updatedByID), updatedAt=CURRENT_TIMESTAMP",
                    [':ay'=>$selectedYearID, ':subject'=>$subjectID,
                     ':included'=>isset($includedIDs[$subjectID]) ? 1 : 0, ':user'=>$user['id']]
                );
            }

              $records = $db->fetchAll(
                "SELECT gwaID, studentID, semester FROM gwa_records WHERE acadYearID=:ay",
                [':ay'=>$selectedYearID]
              );
              foreach ($records as $record) {
                $recordSemester = $record['semester'] === null ? null : (int)$record['semester'];
                $studentID = (int)$record['studentID'];
                $studentMeta = $recordSemester === null
                    ? $db->fetchOne("SELECT yearLevel, isGraduated FROM students WHERE studentID=:id", [':id'=>$studentID])
                    : null;
                $fullCumulative = $recordSemester === null
                    && !empty($studentMeta)
                    && (int)($studentMeta['isGraduated'] ?? 0) === 1
                    && (int)($studentMeta['yearLevel'] ?? 0) >= 4;
                $result = $fullCumulative
                    ? computeGWA($studentID, null, null)
                    : computeGWA($studentID, $selectedYearID, $recordSemester);
                $honor = $result['count'] > 0
                  ? classifyLatinHonor($result['gwa'], $studentID, $fullCumulative ? null : $selectedYearID, $recordSemester)
                  : 'None';
                $db->query(
                  "UPDATE gwa_records SET gwaValue=:gwa, totalUnits=:units, weightedSum=:weighted,
                     latinHonor=:honor, validationStatus='Pending', validatedByID=NULL,
                     validatedAt=NULL, computedAt=NOW() WHERE gwaID=:id",
                  [':gwa'=>$result['gwa'], ':units'=>$result['totalUnits'], ':weighted'=>$result['weightedSum'],
                   ':honor'=>$honor, ':id'=>$record['gwaID']]
                );
              }

                  $yearStudents = $db->fetchAll(
                  "SELECT DISTINCT studentID FROM enrollments
                   WHERE acadYearID=:ay AND status='Active' AND dateDeleted IS NULL",
                  [':ay'=>$selectedYearID]
                  );
                  foreach ($yearStudents as $yearStudent) {
                  $studentID = (int)$yearStudent['studentID'];
                  $periods = $db->fetchAll(
                    "SELECT DISTINCT semester FROM enrollments
                     WHERE studentID=:student AND acadYearID=:ay AND status='Active' AND dateDeleted IS NULL",
                    [':student'=>$studentID, ':ay'=>$selectedYearID]
                  );
                  foreach ($periods as $period) {
                    syncCompletedGWARecord($studentID, $selectedYearID, (int)$period['semester']);
                  }

                  $studentMeta = $db->fetchOne(
                    "SELECT yearLevel, isGraduated FROM students WHERE studentID=:id",
                    [':id'=>$studentID]
                  );
                  $useFullCumulative = !empty($studentMeta)
                    && (int)($studentMeta['isGraduated'] ?? 0) === 1
                    && (int)($studentMeta['yearLevel'] ?? 0) >= 4;
                  $cumulative = $useFullCumulative
                    ? computeGWA($studentID, null, null)
                    : computeGWA($studentID, $selectedYearID, null);
                  if ($cumulative['count'] === 0) continue;

                  $cumulativeHonor = classifyLatinHonor(
                    $cumulative['gwa'], $studentID, $useFullCumulative ? null : $selectedYearID
                  );
                  $cumulativeRecord = $db->fetchOne(
                    "SELECT gwaID FROM gwa_records WHERE studentID=:student AND acadYearID=:ay AND semester IS NULL",
                    [':student'=>$studentID, ':ay'=>$selectedYearID]
                  );
                  if ($cumulativeRecord) {
                    $db->query(
                      "UPDATE gwa_records SET gwaValue=:gwa, totalUnits=:units, weightedSum=:weighted,
                         latinHonor=:honor, validationStatus='Pending', validatedByID=NULL,
                         validatedAt=NULL, computedAt=NOW() WHERE gwaID=:id",
                      [':gwa'=>$cumulative['gwa'], ':units'=>$cumulative['totalUnits'],
                       ':weighted'=>$cumulative['weightedSum'], ':honor'=>$cumulativeHonor,
                       ':id'=>$cumulativeRecord['gwaID']]
                    );
                  } else {
                    $db->insert(
                      "INSERT INTO gwa_records (studentID,acadYearID,semester,totalUnits,weightedSum,gwaValue,latinHonor)
                       VALUES (:student,:ay,NULL,:units,:weighted,:gwa,:honor)",
                      [':student'=>$studentID, ':ay'=>$selectedYearID, ':units'=>$cumulative['totalUnits'],
                       ':weighted'=>$cumulative['weightedSum'], ':gwa'=>$cumulative['gwa'], ':honor'=>$cumulativeHonor]
                    );
                  }
                  }
            $db->commit();
              $message = 'GWA rules saved and that academic year\'s stored GWA records were recalculated. Registrar validation is required again.';
        } catch (Throwable $exception) {
            if ($db->getConnection()->inTransaction()) $db->rollback();
            $error = 'Unable to save GWA rules. Please try again.';
            error_log($exception->getMessage());
        }
    }
}

$subjects = $selectedYearID > 0 ? $db->fetchAll(
    "SELECT s.subjectID, s.subjectCode, s.subjectName, s.units,
            COALESCE(r.includeInGwa, 1) AS includeInGwa
     FROM subjects s
     LEFT JOIN subject_gwa_rules r ON r.subjectID=s.subjectID AND r.acadYearID=:ay
     WHERE s.dateDeleted IS NULL
     ORDER BY s.subjectCode",
    [':ay'=>$selectedYearID]
) : [];
$csrf = generateCSRF();
$pageTitle = 'GWA Rules';
$activeMenu = 'gwa_settings.php';
require_once ROOT . '/includes/header.php';
?>

<?php if ($message): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($message) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><i class="fas fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="page-toolbar">
  <div class="page-title-group"><div class="page-kicker">Academic policy</div><h2>GWA Subject Rules</h2></div>
</div>

<div class="card" style="margin-bottom:16px;">
  <div class="card-body" style="padding:16px 20px;">
    <form method="get" style="display:flex;align-items:end;gap:12px;flex-wrap:wrap;">
      <div><label class="form-label" for="acad_year_id">Academic Year</label>
        <select class="form-control" id="acad_year_id" name="acad_year_id" onchange="this.form.submit()" required>
          <?php foreach ($academicYears as $year): ?>
          <option value="<?= (int)$year['acadYearID'] ?>" <?= $selectedYearID === (int)$year['acadYearID'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($year['academicYear']) ?><?= $year['status'] === 'Active' ? ' (Active)' : '' ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
    </form>
    <p style="margin-top:12px;color:var(--text-muted);font-size:13px;">
      Rules are stored separately for each academic year. Subjects without a saved rule are included by default.
    </p>
  </div>
</div>

<form method="post" class="card">
  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>"/>
  <input type="hidden" name="acadYearID" value="<?= $selectedYearID ?>"/>
  <div class="card-header"><div class="card-title"><i class="fas fa-list-check"></i> Subject Inclusion</div></div>
  <div style="overflow-x:auto;">
    <table class="data-table">
      <thead><tr><th>Subject</th><th>Units</th><th>Include in GWA</th></tr></thead>
      <tbody>
        <?php if (!$subjects): ?><tr><td colspan="3" style="text-align:center;padding:32px;color:var(--text-muted);">No subjects found.</td></tr><?php endif; ?>
        <?php foreach ($subjects as $subject): ?>
        <tr>
          <td><strong><?= htmlspecialchars($subject['subjectCode']) ?></strong><br><small><?= htmlspecialchars($subject['subjectName']) ?></small></td>
          <td><?= number_format((float)$subject['units'], 1) ?></td>
          <td><label style="display:inline-flex;align-items:center;gap:8px;font-weight:600;cursor:pointer;">
            <input type="checkbox" name="includeSubjects[]" value="<?= (int)$subject['subjectID'] ?>" <?= (int)$subject['includeInGwa'] === 1 ? 'checked' : '' ?>/>
            Include
          </label></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-body" style="display:flex;justify-content:flex-end;padding:14px 18px;">
    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Rules for Academic Year</button>
  </div>
</form>

<?php require_once ROOT . '/includes/footer.php'; ?>