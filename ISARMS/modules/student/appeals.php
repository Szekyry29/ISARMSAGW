<?php
/**
 * ISARMS - Student: Grade Appeals
 * Allows students to appeal grades and track appeal status
 */
define('ROOT', dirname(__DIR__, 2));
require_once ROOT . '/includes/config.php';
require_once ROOT . '/includes/functions.php';
requireAuth('Student');

$db   = Database::getInstance();
$user = getCurrentUser();
$student = $db->fetchOne("SELECT * FROM students WHERE studentID = :id", [':id' => $user['id']]);

$pageTitle  = 'Grade Appeals';
$activeMenu = 'appeals.php';
require_once ROOT . '/includes/header.php';
?>

<div style="max-width:1200px;margin:0 auto;">
    
    <!-- Header -->
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;">
        <div>
            <h1 style="font-size:24px;font-weight:600;margin-bottom:4px;">Grade Appeals</h1>
            <p style="color:#666;font-size:14px;">Appeal grades you believe are incorrect</p>
        </div>
        <button id="btnNewAppeal" style="background:#2563eb;color:white;padding:10px 20px;border:none;border-radius:6px;cursor:pointer;font-weight:500;">
            <i class="fas fa-plus"></i> New Appeal
        </button>
    </div>

    <!-- Tabs -->
    <div style="display:flex;gap:4px;border-bottom:2px solid #e5e7eb;margin-bottom:24px;">
        <button class="tab-btn active" data-tab="pending" style="padding:12px 20px;border:none;background:none;cursor:pointer;font-weight:500;border-bottom:3px solid #2563eb;color:#2563eb;">
            <i class="fas fa-hourglass-half"></i> Pending
        </button>
        <button class="tab-btn" data-tab="resolved" style="padding:12px 20px;border:none;background:none;cursor:pointer;font-weight:500;color:#666;">
            <i class="fas fa-check-circle"></i> Resolved
        </button>
        <button class="tab-btn" data-tab="all" style="padding:12px 20px;border:none;background:none;cursor:pointer;font-weight:500;color:#666;">
            <i class="fas fa-list"></i> All Appeals
        </button>
    </div>

    <!-- Appeals List -->
    <div id="appealsList" style="display:grid;gap:16px;">
        <div style="text-align:center;padding:40px;color:#999;">
            <i class="fas fa-inbox" style="font-size:48px;margin-bottom:16px;opacity:.5;"></i>
            <p>Loading appeals...</p>
        </div>
    </div>

</div>

<!-- Modal: New Appeal -->
<div id="modalAppeal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:white;border-radius:12px;padding:24px;max-width:500px;width:90%;max-height:90vh;overflow:auto;">
        <h2 style="margin:0 0 16px;font-size:20px;">Appeal a Grade</h2>
        
        <div style="margin-bottom:16px;">
            <label style="display:block;margin-bottom:6px;font-weight:500;">Select Grade to Appeal</label>
            <select id="selectGrade" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;">
                <option value="">-- Choose a grade --</option>
            </select>
        </div>

        <div id="gradeDetails" style="background:#f9fafb;padding:12px;border-radius:6px;margin-bottom:16px;display:none;">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;font-size:13px;">
                <div><strong>Subject:</strong> <span id="detSubject">-</span></div>
                <div><strong>Academic Year:</strong> <span id="detYear">-</span></div>
                <div><strong>Grade:</strong> <span id="detGrade">-</span></div>
                <div><strong>Units:</strong> <span id="detUnits">-</span></div>
            </div>
        </div>

        <div style="margin-bottom:16px;">
            <label style="display:block;margin-bottom:6px;font-weight:500;">Reason for Appeal</label>
            <select id="appealReason" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;">
                <option value="">-- Select reason --</option>
                <option value="Computation Error">Computation Error</option>
                <option value="Missed Exam">Missed Exam (with excuse)</option>
                <option value="Extra Credit">Extra Credit Not Recorded</option>
                <option value="Grading Dispute">Grading Dispute</option>
                <option value="Other">Other</option>
            </select>
        </div>

        <div style="margin-bottom:16px;">
            <label style="display:block;margin-bottom:6px;font-weight:500;">Explanation (required)</label>
            <textarea id="appealRemarks" placeholder="Explain why you believe the grade is incorrect..."
                      style="width:100%;padding:8px;border:1px solid #ddd;border-radius:6px;font-family:inherit;resize:vertical;min-height:100px;"></textarea>
            <small style="color:#666;">Max 500 characters</small>
        </div>

        <div style="display:flex;gap:8px;">
            <button id="btnSubmitAppeal" style="flex:1;background:#2563eb;color:white;padding:10px;border:none;border-radius:6px;cursor:pointer;font-weight:500;">
                <i class="fas fa-paper-plane"></i> Submit Appeal
            </button>
            <button id="btnCloseAppeal" style="flex:1;background:#e5e7eb;color:#333;padding:10px;border:none;border-radius:6px;cursor:pointer;">Cancel</button>
        </div>
    </div>
</div>

<style>
.appeal-card {
    background:white;
    border:1px solid #e5e7eb;
    border-radius:8px;
    padding:16px;
    transition:all .2s;
}
.appeal-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,.1); }
.appeal-status {
    display:inline-block;
    padding:4px 12px;
    border-radius:20px;
    font-size:12px;
    font-weight:600;
}
.appeal-status.pending { background:#fef3c7;color:#92400e; }
.appeal-status.resolved { background:#d1fae5;color:#065f46; }
.appeal-status.rejected { background:#fee2e2;color:#991b1b; }
</style>

<script>
const studentID = <?= $user['id'] ?>;
let currentTab = 'pending';
let appeals = [];
let appealableGrades = [];

// Load appealable grades for new appeal form
function loadAppealableGrades() {
    fetch('<?= BASE_URL ?>api/appeals.php?action=get_appealable_grades')
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                appealableGrades = data.data;
                populateGradeSelect();
            }
        });
}

function populateGradeSelect() {
    const select = document.getElementById('selectGrade');
    appealableGrades.forEach(grade => {
        const opt = document.createElement('option');
        opt.value = grade.gradeID;
        opt.textContent = `${grade.subjectCode} (${grade.academicYear} S${grade.semester}) - ${grade.gradeEquivalent}`;
        select.appendChild(opt);
    });
}

document.getElementById('selectGrade').addEventListener('change', (e) => {
    const gradeID = parseInt(e.target.value);
    const grade = appealableGrades.find(g => g.gradeID === gradeID);
    if (grade) {
        document.getElementById('gradeDetails').style.display = 'grid';
        document.getElementById('detSubject').textContent = grade.subjectName;
        document.getElementById('detYear').textContent = `${grade.academicYear} S${grade.semester}`;
        document.getElementById('detGrade').textContent = grade.gradeEquivalent;
        document.getElementById('detUnits').textContent = grade.units;
    } else {
        document.getElementById('gradeDetails').style.display = 'none';
    }
});

// Modal controls
document.getElementById('btnNewAppeal').addEventListener('click', () => {
    loadAppealableGrades();
    document.getElementById('modalAppeal').style.display = 'flex';
});

document.getElementById('btnCloseAppeal').addEventListener('click', () => {
    document.getElementById('modalAppeal').style.display = 'none';
});

document.getElementById('btnSubmitAppeal').addEventListener('click', () => {
    const gradeID = parseInt(document.getElementById('selectGrade').value);
    const reasonCode = document.getElementById('appealReason').value;
    const remarks = document.getElementById('appealRemarks').value;

    if (!gradeID || !reasonCode || !remarks) {
        alert('Please fill in all fields');
        return;
    }

    const formData = new FormData();
    formData.append('gradeID', gradeID);
    formData.append('reasonCode', reasonCode);
    formData.append('studentRemarks', remarks);

    fetch('<?= BASE_URL ?>api/appeals.php?action=create_appeal', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            alert('Appeal submitted successfully!');
            document.getElementById('modalAppeal').style.display = 'none';
            document.getElementById('selectGrade').value = '';
            document.getElementById('appealReason').value = '';
            document.getElementById('appealRemarks').value = '';
            loadAppeals();
        } else {
            alert('Error: ' + data.message);
        }
    });
});

// Load appeals
function loadAppeals() {
    fetch('<?= BASE_URL ?>api/appeals.php?action=get_my_appeals')
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                appeals = data.data;
                displayAppeals();
            }
        });
}

function displayAppeals() {
    const list = document.getElementById('appealsList');
    
    let filtered = appeals;
    if (currentTab === 'pending') {
        filtered = appeals.filter(a => a.status === 'Pending' || a.status === 'Under Review');
    } else if (currentTab === 'resolved') {
        filtered = appeals.filter(a => a.status === 'Resolved' || a.status === 'Rejected');
    }

    if (filtered.length === 0) {
        list.innerHTML = '<div style="text-align:center;padding:40px;color:#999;"><p>No appeals ' + currentTab + '</p></div>';
        return;
    }

    list.innerHTML = filtered.map(appeal => `
        <div class="appeal-card">
            <div style="display:flex;justify-content:space-between;align-items:start;margin-bottom:12px;">
                <div>
                    <h3 style="margin:0 0 4px;font-size:16px;">${appeal.subjectCode} - ${appeal.subjectName}</h3>
                    <p style="margin:0;font-size:13px;color:#666;">${appeal.academicYear} Semester ${appeal.semester}</p>
                </div>
                <span class="appeal-status ${appeal.status.toLowerCase().replace(' ', '')}">${appeal.status}</span>
            </div>
            
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;font-size:13px;margin-bottom:12px;padding-bottom:12px;border-bottom:1px solid #e5e7eb;">
                <div><strong>Original Grade:</strong> ${appeal.gradeEquivalent}</div>
                <div><strong>Appeal Date:</strong> ${new Date(appeal.appealedAt).toLocaleDateString()}</div>
                ${appeal.newGrade ? `<div><strong>New Grade:</strong> ${appeal.newGrade}</div>` : ''}
            </div>

            <div style="background:#f9fafb;padding:12px;border-radius:6px;margin-bottom:12px;border-left:3px solid #2563eb;">
                <strong style="font-size:12px;color:#666;">Your Appeal:</strong>
                <p style="margin:4px 0 0;font-size:13px;">${appeal.studentRemarks}</p>
            </div>

            ${appeal.registrarRemarks ? `
                <div style="background:#f0fdf4;padding:12px;border-radius:6px;border-left:3px solid #16a34a;">
                    <strong style="font-size:12px;color:#666;">Registrar's Decision:</strong>
                    <p style="margin:4px 0 0;font-size:13px;">${appeal.registrarRemarks}</p>
                </div>
            ` : ''}
        </div>
    `).join('');
}

// Tab switching
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.tab-btn').forEach(b => {
            b.style.color = '#666';
            b.style.borderBottomColor = 'transparent';
        });
        btn.style.color = '#2563eb';
        btn.style.borderBottomColor = '#2563eb';
        currentTab = btn.dataset.tab;
        displayAppeals();
    });
});

// Initial load
loadAppeals();
</script>

<?php require_once ROOT . '/includes/footer.php'; ?>
