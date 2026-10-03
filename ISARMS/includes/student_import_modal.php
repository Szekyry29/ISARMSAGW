<div id="studentImportModal" role="dialog" aria-modal="true" aria-labelledby="studentImportTitle"
     style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.58);z-index:1000;align-items:center;justify-content:center;overflow-y:auto;padding:24px;">
  <div style="background:#fff;border-radius:16px;padding:28px;width:620px;max-width:100%;box-shadow:0 20px 60px rgba(0,0,0,.25);">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:8px;">
      <h2 id="studentImportTitle" style="font-size:21px;margin:0;">Import Students from CSV</h2>
      <button type="button" onclick="closeStudentImportModal()" aria-label="Close import dialog"
              style="background:none;border:0;cursor:pointer;font-size:18px;color:var(--text-muted);">
        <i class="fas fa-times"></i>
      </button>
    </div>
    <p style="font-size:13px;color:var(--text-muted);line-height:1.5;margin:0 0 16px;">
      Select the student CSV file. The email is the login username; when no password column or value is provided,
      the student number is used as the initial password.
    </p>
    <form id="studentImportForm" onsubmit="submitStudentImport(event)">
      <label for="studentImportFile" class="form-label">CSV file</label>
      <input id="studentImportFile" name="file" type="file" accept=".csv,text/csv" required
             class="form-control" style="margin-bottom:10px;"/>
      <div style="font-size:12px;color:var(--text-muted);margin-bottom:18px;">
        Columns: studentNo, firstName, middleName, lastName, extName, dateOfBirth, sex, yearLevel, email, programCode, sectionID.
        Password is optional. Section may be a section name such as 1-A or a section ID.
      </div>
      <div id="studentImportResult" role="status" aria-live="polite" style="display:none;margin-bottom:14px;"></div>
      <div style="display:flex;gap:10px;justify-content:flex-end;">
        <button type="button" class="btn btn-outline" onclick="closeStudentImportModal()">Cancel</button>
        <button id="studentImportSubmit" type="submit" class="btn btn-primary">
          <i class="fas fa-file-import"></i> Import Students
        </button>
      </div>
    </form>
  </div>
</div>

<script>
function openStudentImportModal() {
  const modal = document.getElementById('studentImportModal');
  document.getElementById('studentImportForm').reset();
  document.getElementById('studentImportResult').style.display = 'none';
  modal.style.display = 'flex';
  document.getElementById('studentImportFile').focus();
}

function closeStudentImportModal() {
  document.getElementById('studentImportModal').style.display = 'none';
}

async function submitStudentImport(event) {
  event.preventDefault();
  const form = event.currentTarget;
  const submit = document.getElementById('studentImportSubmit');
  const resultBox = document.getElementById('studentImportResult');
  const data = new FormData(form);
  data.append('action', 'import_students');
  submit.disabled = true;
  resultBox.style.display = 'none';

  try {
    const response = await fetch('<?= BASE_URL ?>api/students.php', {method: 'POST', body: data});
    const result = await response.json();
    resultBox.style.display = 'block';
    resultBox.style.padding = '10px 12px';
    resultBox.style.borderRadius = '8px';
    resultBox.style.background = result.success ? '#ecfdf5' : '#fef2f2';
    resultBox.style.color = result.success ? '#166534' : '#991b1b';
    resultBox.textContent = result.message || 'The import could not be completed.';

    if (Array.isArray(result.errors) && result.errors.length) {
      const details = document.createElement('ul');
      details.style.margin = '8px 0 0';
      for (const error of result.errors) {
        const item = document.createElement('li');
        item.textContent = error;
        details.appendChild(item);
      }
      resultBox.appendChild(details);
    }

    if (result.success) {
      setTimeout(() => location.reload(), 1400);
    }
  } catch {
    resultBox.style.display = 'block';
    resultBox.style.padding = '10px 12px';
    resultBox.style.borderRadius = '8px';
    resultBox.style.background = '#fef2f2';
    resultBox.style.color = '#991b1b';
    resultBox.textContent = 'Import failed. Check the CSV file and try again.';
  } finally {
    submit.disabled = false;
  }
}

document.getElementById('studentImportModal').addEventListener('click', function (event) {
  if (event.target === this) closeStudentImportModal();
});
</script>
