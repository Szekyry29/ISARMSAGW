<?php
define('ROOT', dirname(__DIR__, 2));
require_once ROOT . '/includes/config.php';
require_once ROOT . '/includes/functions.php';
requireAuth('Student');

$db = Database::getInstance();
$user = getCurrentUser();
$studentID = $user['id'];

$student = $db->fetchOne(
    "SELECT s.*, p.programName, p.programCode FROM students s 
     JOIN programs p ON p.programID = s.programID 
     WHERE s.studentID = :id AND s.isActive = 1 AND s.dateDeleted IS NULL",
    [':id' => $studentID]
);
$csrf = generateCSRF();

if (!$student) {
    header('Location: ' . BASE_URL . 'modules/student/dashboard.php');
    exit;
}

$yearLabels = [1 => '1st Year', 2 => '2nd Year', 3 => '3rd Year', 4 => '4th Year', 5 => 'Irregular'];
$semesterLabels = [1 => '1st Semester', 2 => '2nd Semester'];

$pageTitle = 'Enroll in Subjects';
$activeMenu = 'enroll_subjects.php';
require_once ROOT . '/includes/header.php';
?>

<style>
    .enrollment-container {
        max-width: 1000px;
        margin: 0 auto;
    }
    
    .student-info-card {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 20px;
        border-radius: 8px;
        margin-bottom: 20px;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    }
    
    .student-info-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
        margin-bottom: 10px;
    }
    
    .student-info-item {
        font-size: 14px;
    }
    
    .student-info-item label {
        opacity: 0.9;
        font-weight: 600;
        text-transform: uppercase;
        font-size: 12px;
        letter-spacing: 0.5px;
    }
    
    .student-info-item .value {
        font-size: 16px;
        font-weight: 500;
        margin-top: 4px;
    }
    
    .form-section {
        background: white;
        padding: 20px;
        border-radius: 8px;
        margin-bottom: 20px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    }
    
    .form-section h5 {
        margin-bottom: 15px;
        color: #333;
        font-weight: 600;
    }
    
    .enrollment-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
        margin-bottom: 15px;
    }
    
    .form-group {
        display: flex;
        flex-direction: column;
    }
    
    .form-group label {
        font-weight: 600;
        margin-bottom: 8px;
        color: #555;
        font-size: 14px;
    }
    
    .form-group select,
    .form-group input {
        padding: 10px;
        border: 1px solid #ddd;
        border-radius: 4px;
        font-size: 14px;
    }
    
    .form-group select:focus,
    .form-group input:focus {
        outline: none;
        border-color: #667eea;
        box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    }
    
    .btn-load {
        padding: 10px 20px;
        background: #667eea;
        color: white;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-weight: 600;
        align-self: flex-end;
        transition: background 0.3s;
    }
    
    .btn-load:hover {
        background: #5568d3;
    }
    
    .btn-load:disabled {
        background: #ccc;
        cursor: not-allowed;
    }
    
    .subjects-container {
        display: grid;
        gap: 12px;
    }
    
    .subject-card {
        background: #f8f9fa;
        border: 1px solid #e9ecef;
        border-radius: 6px;
        padding: 15px;
        display: grid;
        grid-template-columns: auto 1fr auto;
        gap: 15px;
        align-items: center;
        transition: all 0.3s;
    }
    
    .subject-card:hover {
        border-color: #667eea;
        box-shadow: 0 2px 8px rgba(102, 126, 234, 0.1);
    }
    
    .subject-card.enrolled {
        background: #e7f5e9;
        border-color: #4caf50;
    }
    
    .subject-checkbox {
        width: 24px;
        height: 24px;
        cursor: pointer;
    }
    
    .subject-info h6 {
        margin: 0 0 5px 0;
        color: #333;
        font-size: 15px;
        font-weight: 600;
    }
    
    .subject-info p {
        margin: 3px 0;
        color: #666;
        font-size: 13px;
    }
    
    .badge {
        display: inline-block;
        padding: 4px 8px;
        border-radius: 3px;
        font-size: 12px;
        font-weight: 600;
        margin-right: 5px;
    }
    
    .badge-teacher {
        background: #e3f2fd;
        color: #1976d2;
    }
    
    .badge-enrolled {
        background: #c8e6c9;
        color: #2e7d32;
    }
    
    .action-buttons {
        display: flex;
        gap: 8px;
    }
    
    .btn-enroll {
        padding: 6px 12px;
        background: #4caf50;
        color: white;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-weight: 600;
        font-size: 12px;
        transition: background 0.3s;
    }
    
    .btn-enroll:hover:not(:disabled) {
        background: #45a049;
    }
    
    .btn-enroll:disabled {
        background: #ccc;
        cursor: not-allowed;
    }
    
    .btn-drop {
        padding: 6px 12px;
        background: #f44336;
        color: white;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-weight: 600;
        font-size: 12px;
        transition: background 0.3s;
    }
    
    .btn-drop:hover {
        background: #da190b;
    }
    
    .loading {
        text-align: center;
        padding: 20px;
        color: #999;
    }
    
    .spinner {
        border: 3px solid #f3f3f3;
        border-top: 3px solid #667eea;
        border-radius: 50%;
        width: 20px;
        height: 20px;
        animation: spin 1s linear infinite;
        display: inline-block;
        margin-right: 10px;
    }
    
    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
    
    .no-subjects {
        text-align: center;
        padding: 30px;
        color: #999;
    }
    
    .summary-card {
        background: #fff3cd;
        border: 1px solid #ffc107;
        border-radius: 6px;
        padding: 15px;
        margin-bottom: 20px;
    }
    
    .summary-card h6 {
        margin: 0 0 10px 0;
        color: #856404;
        font-weight: 600;
    }
    
    .summary-card p {
        margin: 5px 0;
        color: #856404;
        font-size: 14px;
    }
    
    .error-message {
        background: #ffebee;
        border: 1px solid #f44336;
        color: #c62828;
        padding: 12px;
        border-radius: 4px;
        margin-bottom: 15px;
    }
    
    .success-message {
        background: #e8f5e9;
        border: 1px solid #4caf50;
        color: #2e7d32;
        padding: 12px;
        border-radius: 4px;
        margin-bottom: 15px;
    }
</style>

<div class="enrollment-container">
    <div class="student-info-card">
        <div class="student-info-row">
            <div class="student-info-item">
                <label>Student Number</label>
                <div class="value"><?= htmlspecialchars($student['studentNo']) ?></div>
            </div>
            <div class="student-info-item">
                <label>Name</label>
                <div class="value"><?= htmlspecialchars("{$student['lName']}, {$student['fName']}") ?></div>
            </div>
            <div class="student-info-item">
                <label>Year Level</label>
                <div class="value"><?= htmlspecialchars($yearLabels[$student['yearLevel']] ?? 'Unknown') ?></div>
            </div>
            <div class="student-info-item">
                <label>Program</label>
                <div class="value"><?= htmlspecialchars($student['programCode']) ?></div>
            </div>
        </div>
    </div>

    <div class="form-section">
        <h5><i class="fas fa-book-open"></i> Select Enrollment Details</h5>
        
        <div class="enrollment-grid">
            <div class="form-group">
                <label for="academicYear">Academic Year *</label>
                <select id="academicYear" required>
                    <option value="">-- Select Academic Year --</option>
                </select>
            </div>

            <div class="form-group">
                <label for="studentType">Student Type</label>
                <select id="studentType" disabled>
                    <option value="<?= (int)$student['yearLevel'] === 5 ? 'irregular' : 'regular' ?>" selected>
                        <?= (int)$student['yearLevel'] === 5 ? 'Irregular (from profile)' : 'Regular (from profile)' ?>
                    </option>
                </select>
            </div>

            <div class="form-group">
                <label for="section">Section *</label>
                <select id="section" required disabled>
                    <option value="">-- Select Section --</option>
                </select>
            </div>

            <div class="form-group">
                <label for="semester">Semester *</label>
                <select id="semester" required>
                    <option value="">-- Select Semester --</option>
                    <option value="1">1st Semester</option>
                    <option value="2">2nd Semester</option>
                </select>
            </div>
        </div>

        <button class="btn-load" id="loadSubjectsBtn" disabled>
            <i class="fas fa-search"></i> Show Available Subjects
        </button>
    </div>

    <div id="subjectsSection" style="display: none;">
        <div class="form-section">
            <h5><i class="fas fa-list"></i> Available Subjects</h5>
            <div id="subjectsContainer" class="subjects-container"></div>
        </div>

        <div class="form-section" style="text-align: right;">
            <button class="btn-load" id="enrollSelectedBtn" style="background: #4caf50;">
                <i class="fas fa-check"></i> Enroll in Selected Subjects
            </button>
        </div>
    </div>

    <div id="enrollmentsSection" style="display: none;">
        <div class="form-section">
            <h5><i class="fas fa-graduation-cap"></i> Your Enrollments</h5>
            <div id="enrollmentsContainer" class="subjects-container"></div>
        </div>
    </div>
</div>

<script>
const API_BASE = '<?= BASE_URL ?>api/student_subject_enrollment.php';
const CSRF_TOKEN = <?= json_encode($csrf) ?>;

// Load academic years on page load
async function loadAcademicYears() {
    try {
        const res = await fetch(`${API_BASE}?action=get_years`);
        const data = await res.json();
        
        if (data.success) {
            const select = document.getElementById('academicYear');
            data.data.forEach(year => {
                const option = document.createElement('option');
                option.value = year.acadYearID;
                option.textContent = year.academicYear;
                select.appendChild(option);
            });
        }
    } catch (error) {
        console.error('Error loading academic years:', error);
    }
}

// Load sections when academic year changes
document.getElementById('academicYear').addEventListener('change', async function() {
    const yearID = this.value;
    const sectionSelect = document.getElementById('section');
    
    sectionSelect.innerHTML = '<option value="">-- Select Section --</option>';
    sectionSelect.disabled = true;
    
    if (!yearID) return;

    try {
        const res = await fetch(`${API_BASE}?action=get_sections&yearID=${yearID}`);
        const data = await res.json();
        
        if (data.success) {
            data.data.forEach(section => {
                const option = document.createElement('option');
                option.value = section.sectionID;
                option.textContent = section.sectionName;
                sectionSelect.appendChild(option);
            });
            sectionSelect.disabled = false;
        }
    } catch (error) {
        console.error('Error loading sections:', error);
    }
});

// Enable load button when all required fields are filled
function updateLoadButtonState() {
    const yearID = document.getElementById('academicYear').value;
    const studentType = document.getElementById('studentType').value;
    const sectionID = document.getElementById('section').value;
    const semester = document.getElementById('semester').value;
    
    const btn = document.getElementById('loadSubjectsBtn');
    btn.disabled = !(yearID && studentType && sectionID && semester);
}

document.getElementById('academicYear').addEventListener('change', updateLoadButtonState);
document.getElementById('studentType').addEventListener('change', updateLoadButtonState);
document.getElementById('section').addEventListener('change', updateLoadButtonState);
document.getElementById('semester').addEventListener('change', updateLoadButtonState);

// Load available subjects
document.getElementById('loadSubjectsBtn').addEventListener('click', async function() {
    const yearID = document.getElementById('academicYear').value;
    const studentType = document.getElementById('studentType').value;
    const sectionID = document.getElementById('section').value;
    const semester = document.getElementById('semester').value;

    const container = document.getElementById('subjectsContainer');
    container.innerHTML = '<div class="loading"><span class="spinner"></span> Loading subjects...</div>';

    try {
        const url = new URL(`${API_BASE}?action=get_available_subjects`, window.location.origin);
        url.searchParams.set('yearID', yearID);
        url.searchParams.set('studentType', studentType);
        url.searchParams.set('sectionID', sectionID);
        url.searchParams.set('semester', semester);

        const res = await fetch(url);
        const data = await res.json();

        if (!data.success) {
            container.innerHTML = `<div class="error-message">${data.message}</div>`;
            return;
        }

        if (data.data.length === 0) {
            container.innerHTML = '<div class="no-subjects">No subjects available for your selection.</div>';
            document.getElementById('subjectsSection').style.display = 'none';
            return;
        }

        container.innerHTML = '';
        data.data.forEach(subject => {
            const card = document.createElement('div');
            card.className = `subject-card ${subject.isEnrolled ? 'enrolled' : ''}`;
            
            const teacherInfo = subject.teacherID 
                ? `<span class="badge badge-teacher">${htmlEscape(subject.fName)} ${htmlEscape(subject.lName)}</span>`
                : '<span class="badge badge-teacher">No Teacher Assigned</span>';
            
            const enrolledBadge = subject.isEnrolled 
                ? '<span class="badge badge-enrolled">✓ Enrolled</span>'
                : '';
            
            card.innerHTML = `
                <input type="checkbox" class="subject-checkbox" value="${subject.subjectID}" ${subject.isEnrolled ? 'disabled' : ''}>
                <div class="subject-info">
                    <h6>${htmlEscape(subject.subjectCode)} - ${htmlEscape(subject.subjectName)}</h6>
                    <p><strong>Units:</strong> ${subject.units}</p>
                    <p><strong>Year Level:</strong> ${subject.yearLevel || 'Any'}</p>
                    <p><strong>Teacher:</strong> ${teacherInfo} ${enrolledBadge}</p>
                </div>
                <div class="action-buttons">
                    ${!subject.isEnrolled 
                        ? `<button class="btn-enroll" onclick="enrollSubject(${subject.subjectID}, ${yearID}, ${sectionID}, ${semester}, '${studentType}')">
                            <i class="fas fa-check"></i> Enroll
                          </button>`
                        : `<button class="btn-drop" onclick="dropSubject(${subject.subjectID})">
                            <i class="fas fa-times"></i> Drop
                          </button>`
                    }
                </div>
            `;
            container.appendChild(card);
        });

        document.getElementById('subjectsSection').style.display = 'block';
    } catch (error) {
        console.error('Error loading subjects:', error);
        container.innerHTML = '<div class="error-message">Error loading subjects. Please try again.</div>';
    }
});

// Enroll in a single subject
async function enrollSubject(subjectID, yearID, sectionID, semester, studentType) {
    try {
        const formData = new FormData();
        formData.append('action', 'enroll');
        formData.append('subjectID', subjectID);
        formData.append('sectionID', sectionID);
        formData.append('yearID', yearID);
        formData.append('semester', semester);
        formData.append('studentType', studentType);
        formData.append('csrf_token', CSRF_TOKEN);

        const res = await fetch(`${API_BASE}`, {
            method: 'POST',
            body: formData
        });
        const data = await res.json();

        if (data.success) {
            showMessage('Enrolled successfully!', 'success');
            // Reload subjects
            document.getElementById('loadSubjectsBtn').click();
            loadMyEnrollments();
        } else {
            showMessage(data.message || 'Failed to enroll.', 'error');
        }
    } catch (error) {
        console.error('Error enrolling:', error);
        showMessage('Error enrolling in subject.', 'error');
    }
}

// Drop a subject
async function dropSubject(subjectID) {
    // Get the enrollment ID first
    try {
        const enrollRes = await fetch(`${API_BASE}?action=get_my_enrollments`);
        const enrollData = await enrollRes.json();
        
        const enrollment = enrollData.data.find(e => e.subjectID == subjectID);
        if (!enrollment) {
            showMessage('Enrollment not found.', 'error');
            return;
        }

        if (!confirm('Are you sure you want to drop this subject?')) return;

        const formData = new FormData();
        formData.append('action', 'drop');
        formData.append('enrollmentID', enrollment.enrollmentID);
        formData.append('csrf_token', CSRF_TOKEN);

        const res = await fetch(`${API_BASE}`, {
            method: 'POST',
            body: formData
        });
        const data = await res.json();

        if (data.success) {
            showMessage('Subject dropped successfully!', 'success');
            // Reload subjects
            document.getElementById('loadSubjectsBtn').click();
            loadMyEnrollments();
        } else {
            showMessage(data.message || 'Failed to drop.', 'error');
        }
    } catch (error) {
        console.error('Error dropping:', error);
        showMessage('Error dropping subject.', 'error');
    }
}

// Enroll in selected subjects
document.getElementById('enrollSelectedBtn').addEventListener('click', async function() {
    const checkboxes = document.querySelectorAll('.subject-checkbox:checked');
    if (checkboxes.length === 0) {
        showMessage('Please select at least one subject.', 'error');
        return;
    }

    const yearID = document.getElementById('academicYear').value;
    const sectionID = document.getElementById('section').value;
    const semester = document.getElementById('semester').value;
    const studentType = document.getElementById('studentType').value;

    let enrolled = 0;
    let failed = 0;

    for (const checkbox of checkboxes) {
        const subjectID = checkbox.value;
        try {
            const formData = new FormData();
            formData.append('action', 'enroll');
            formData.append('subjectID', subjectID);
            formData.append('sectionID', sectionID);
            formData.append('yearID', yearID);
            formData.append('semester', semester);
            formData.append('studentType', studentType);

            const res = await fetch(`${API_BASE}`, {
                method: 'POST',
                body: formData
            });
            const data = await res.json();

            if (data.success) {
                enrolled++;
            } else {
                failed++;
            }
        } catch (error) {
            failed++;
        }
    }

    showMessage(`Enrolled: ${enrolled}, Failed: ${failed}`, enrolled > 0 ? 'success' : 'error');
    // Reload subjects
    document.getElementById('loadSubjectsBtn').click();
    loadMyEnrollments();
});

// Load user's current enrollments
async function loadMyEnrollments() {
    try {
        const res = await fetch(`${API_BASE}?action=get_my_enrollments`);
        const data = await res.json();

        if (!data.success || data.data.length === 0) {
            document.getElementById('enrollmentsSection').style.display = 'none';
            return;
        }

        const container = document.getElementById('enrollmentsContainer');
        container.innerHTML = '';

        data.data.forEach(enrollment => {
            const card = document.createElement('div');
            card.className = 'subject-card enrolled';
            
            const teacherInfo = enrollment.teacherName 
                ? enrollment.teacherName
                : 'No Teacher Assigned';
            
            card.innerHTML = `
                <div style="width: 24px; text-align: center;">
                    <i class="fas fa-check-circle" style="color: #4caf50; font-size: 20px;"></i>
                </div>
                <div class="subject-info">
                    <h6>${htmlEscape(enrollment.subjectCode)} - ${htmlEscape(enrollment.subjectName)}</h6>
                    <p><strong>Units:</strong> ${enrollment.units} | <strong>Semester:</strong> ${enrollment.semester}</p>
                    <p><strong>Academic Year:</strong> ${htmlEscape(enrollment.academicYear)} | <strong>Section:</strong> ${htmlEscape(enrollment.sectionName || 'N/A')}</p>
                    <p><strong>Teacher:</strong> ${htmlEscape(teacherInfo)}</p>
                </div>
                <div class="action-buttons">
                    <button class="btn-drop" onclick="dropSubject(${enrollment.subjectID})">
                        <i class="fas fa-times"></i> Drop
                    </button>
                </div>
            `;
            container.appendChild(card);
        });

        document.getElementById('enrollmentsSection').style.display = 'block';
    } catch (error) {
        console.error('Error loading enrollments:', error);
    }
}

// Utility functions
function showMessage(message, type) {
    const container = document.querySelector('.enrollment-container');
    const msgDiv = document.createElement('div');
    msgDiv.className = type === 'success' ? 'success-message' : 'error-message';
    msgDiv.textContent = message;
    container.insertBefore(msgDiv, container.firstChild);
    
    setTimeout(() => msgDiv.remove(), 5000);
}

function htmlEscape(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    loadAcademicYears();
    loadMyEnrollments();
});
</script>

<?php require_once ROOT . '/includes/footer.php'; ?>
