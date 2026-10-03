<?php
define('ROOT', dirname(__DIR__, 2));
require_once ROOT . '/includes/config.php';
require_once ROOT . '/includes/functions.php';
requireAuth('Admin');

$db = Database::getInstance();
$search = sanitize($_GET['search'] ?? '');
$reviewQueue = ($_GET['status'] ?? '') === 'review';
$where = ['g.dateDeleted IS NULL', 'e.dateDeleted IS NULL'];
$params = [];
if ($reviewQueue) {
  $where[] = "g.status='Pending' AND g.submittedAt IS NOT NULL";
}
if ($search !== '') {
    // FIX: each LIKE clause needs its own unique named placeholder.
    // Reusing ":search" multiple times in one query throws
    // "SQLSTATE[HY093]: Invalid parameter number" when PDO is running
    // with native prepares (ATTR_EMULATE_PREPARES = false).
    $where[] = '(s.studentNo LIKE :search1 OR s.fName LIKE :search2 OR s.lName LIKE :search3 OR sub.subjectCode LIKE :search4 OR sub.subjectName LIKE :search5)';
    $searchTerm = '%' . $search . '%';
    $params[':search1'] = $searchTerm;
    $params[':search2'] = $searchTerm;
    $params[':search3'] = $searchTerm;
    $params[':search4'] = $searchTerm;
    $params[':search5'] = $searchTerm;
}
$grades = $db->fetchAll("SELECT g.gradeID, g.midterm, g.finals, g.computedGrade, g.gradeEquivalent,
    g.remarks, CASE WHEN g.status IN ('Posted','Approved') THEN 'Posted' ELSE g.status END AS status, g.teacherRemarks, g.registrarNotes, g.submittedAt,
    CONCAT(s.lName, ', ', s.fName) AS studentName, s.studentNo,
    sub.subjectCode, sub.subjectName, sub.units,
    CONCAT(t.lName, ', ', t.fName) AS teacherName, ay.academicYear, e.semester
    FROM grades g
    JOIN enrollments e ON e.enrollmentID=g.enrollmentID
    JOIN students s ON s.studentID=g.studentID
    JOIN subjects sub ON sub.subjectID=g.subjectID
    JOIN users t ON t.userID=g.teacherID
    JOIN acad_years ay ON ay.acadYearID=e.acadYearID
    WHERE " . implode(' AND ', $where) . "
    ORDER BY g.submittedAt DESC, s.lName, sub.subjectCode LIMIT 200", $params);

$pageTitle = 'Grade Management';
$activeMenu = 'grades.php';
require_once ROOT . '/includes/header.php';
?>
<div class="page-toolbar">
  <div class="page-title-group"><div class="page-kicker">Admin control</div><h2><?= $reviewQueue ? 'Grade Review Queue' : 'Teacher-submitted grades' ?></h2></div>
  <div class="alert-info" style="padding:10px 14px;border-radius:8px;font-size:12px;">Grades are posted by teachers. MIS can return a grade for correction; scores are read-only.</div>
</div>
<div class="card" style="margin-bottom:16px;"><div class="card-body" style="padding:14px 18px;">
  <form method="get" class="filter-toolbar">
    <?php if ($reviewQueue): ?><input type="hidden" name="status" value="review"><?php endif; ?>
    <div><label class="form-label">Search</label><input class="form-control" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Student, subject, teacher"></div>
    <!-- FIX: width:auto + flex-grow:0 so the button hugs the "Search" text instead of stretching -->
    <button class="btn btn-primary" type="submit" style="width:auto;flex:0 0 auto;align-self:flex-end;white-space:nowrap;padding:8px 16px;"><i class="fas fa-search"></i> Search</button>
  </form>
</div></div>
<div class="card"><div style="overflow-x:auto;"><table class="data-table"><thead><tr><th>Student</th><th>Subject</th><th>Teacher</th><th>Semester</th><th>Midterm</th><th>Finals</th><th>Equivalent</th><th>Status</th><th>MIS action</th></tr></thead><tbody>
<?php if (!$grades): ?><tr><td colspan="9" style="text-align:center;padding:40px;color:var(--text-muted);">No grade records found.</td></tr><?php endif; ?>
<?php foreach ($grades as $grade): ?><tr>
  <td><strong><?= htmlspecialchars($grade['studentName']) ?></strong><br><small><?= htmlspecialchars($grade['studentNo']) ?></small></td>
  <td><strong><?= htmlspecialchars($grade['subjectCode']) ?></strong><br><small><?= htmlspecialchars($grade['subjectName']) ?></small></td>
  <td><?= htmlspecialchars($grade['teacherName']) ?></td>
  <td><?= htmlspecialchars($grade['academicYear']) ?><br><small><?= $grade['semester']==1?'1st':'2nd' ?> semester</small></td>
  <td><?= $grade['midterm'] !== null ? number_format((float)$grade['midterm'],2) : '—' ?></td>
  <td><?= $grade['finals'] !== null ? number_format((float)$grade['finals'],2) : '—' ?></td>
  <td><strong><?= $grade['gradeEquivalent'] !== null ? number_format((float)$grade['gradeEquivalent'],2) : '—' ?></strong></td>
  <td><span class="status-badge <?= gradeStatusClass($grade['status']) ?>"><?= htmlspecialchars($grade['status'] === 'Pending' ? (!empty($grade['submittedAt']) ? 'Submitted' : 'Draft') : $grade['status']) ?></span><?php if ($grade['teacherRemarks']): ?><br><small title="Teacher request/remarks"><i class="fas fa-file-signature"></i> <?= htmlspecialchars($grade['teacherRemarks']) ?></small><?php endif; ?></td>
  <td><button class="btn btn-outline btn-sm" type="button" onclick="returnGrade(<?= (int)$grade['gradeID'] ?>)"><i class="fas fa-rotate-left"></i> Return</button></td>
</tr><?php endforeach; ?></tbody></table></div></div>
<div id="gradeReasonModal" class="modal-overlay"><div class="modal-box" style="width:480px;"><div class="modal-header"><h3 class="modal-title">Return Grade to Teacher</h3><button class="modal-close" type="button" onclick="closeModal('gradeReasonModal')">&times;</button></div><p style="font-size:13px;color:var(--text-muted);margin-bottom:14px;">Provide a reason or teacher letter reference. The grade will be returned for correction without changing its scores.</p><textarea id="gradeReason" class="form-control" rows="4" placeholder="Required reason or letter reference"></textarea><div style="display:flex;justify-content:flex-end;gap:8px;margin-top:16px;"><button class="btn btn-outline" type="button" onclick="closeModal('gradeReasonModal')">Cancel</button><button class="btn btn-primary" type="button" onclick="submitGradeReturn()"><i class="fas fa-rotate-left"></i> Return to teacher</button></div></div></div>
<script>
let selectedGradeID = 0;
function returnGrade(id) { selectedGradeID=id; document.getElementById('gradeReason').value=''; openModal('gradeReasonModal'); }
async function submitGradeReturn() {
  const reason=document.getElementById('gradeReason').value.trim();
  if (!reason) { showToast('A reason or letter reference is required.','warning'); return; }
  const response=await fetch('<?= BASE_URL ?>api/grades.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'return',gradeID:selectedGradeID,notes:reason})});
  const data=await response.json(); showToast(data.message,data.success?'success':'error'); if(data.success) setTimeout(()=>location.reload(),700);
}
</script>
<?php require_once ROOT . '/includes/footer.php'; ?>