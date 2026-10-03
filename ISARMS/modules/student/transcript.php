<?php
/**
 * ISARMS - Student: Academic Transcript
 * View and download official transcript
 */
define('ROOT', dirname(__DIR__, 2));
require_once ROOT . '/includes/config.php';
require_once ROOT . '/includes/functions.php';
requireAuth('Student');

$db   = Database::getInstance();
$user = getCurrentUser();
$student = $db->fetchOne("SELECT * FROM students WHERE studentID = :id", [':id' => $user['id']]);
$program = $db->fetchOne("SELECT * FROM programs WHERE programID = :pid", [':pid' => $student['programID']]);
$activeAcademicYear = $db->fetchOne("SELECT academicYear FROM acad_years WHERE status = 'Active' AND dateDeleted IS NULL ORDER BY acadYearID DESC LIMIT 1");

$pageTitle  = 'Academic Transcript';
$activeMenu = 'transcript.php';
require_once ROOT . '/includes/header.php';
?>

<div style="max-width:900px;margin:0 auto;">

    <!-- Header -->
    <div style="background:linear-gradient(135deg,var(--blue-dark) 0%,var(--blue) 100%);color:white;padding:24px;border-radius:12px;margin-bottom:24px;">
        <h1 style="margin:0 0 4px;font-size:24px;">Official Academic Transcript</h1>
        <p style="margin:0;opacity:.9;">Download and view your complete academic record</p>
    </div>

    <!-- Transcript Preview -->
    <div style="background:white;border:1px solid #e5e7eb;border-radius:12px;padding:24px;margin-bottom:24px;">
        
        <!-- Header Section -->
        <div style="text-align:center;padding-bottom:20px;border-bottom:2px solid #e5e7eb;margin-bottom:20px;">
            <h2 style="margin:0 0 4px;font-size:18px;font-weight:600;"><?= APP_CAMPUS ?></h2>
            <h3 style="margin:0 0 8px;font-size:16px;">ACADEMIC TRANSCRIPT</h3>
            <p style="margin:0;font-size:12px;color:#666;">Official Record of Academic Achievement</p>
        </div>

        <!-- Student Info -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px;padding-bottom:24px;border-bottom:1px solid #e5e7eb;">
            <div>
                <div style="margin-bottom:12px;">
                    <p style="margin:0;font-size:12px;color:#666;font-weight:500;text-transform:uppercase;">Student Number</p>
                    <p style="margin:4px 0 0;font-size:14px;font-weight:600;"><?= $student['studentNo'] ?></p>
                </div>
                <div>
                    <p style="margin:0;font-size:12px;color:#666;font-weight:500;text-transform:uppercase;">Full Name</p>
                    <p style="margin:4px 0 0;font-size:14px;font-weight:600;"><?= fullName($student) ?></p>
                </div>
            </div>
            <div>
                <div style="margin-bottom:12px;">
                    <p style="margin:0;font-size:12px;color:#666;font-weight:500;text-transform:uppercase;">Program</p>
                    <p style="margin:4px 0 0;font-size:14px;font-weight:600;"><?= $program['programName'] ?> (<?= $program['programCode'] ?>)</p>
                </div>
                <div style="margin-bottom:12px;">
                    <p style="margin:0;font-size:12px;color:#666;font-weight:500;text-transform:uppercase;">Current Year Level</p>
                    <p style="margin:4px 0 0;font-size:14px;font-weight:600;">Year <?= (int)$student['yearLevel'] ?></p>
                </div>
                <div>
                    <p style="margin:0;font-size:12px;color:#666;font-weight:500;text-transform:uppercase;">Academic Year</p>
                    <p style="margin:4px 0 0;font-size:14px;font-weight:600;"><?= htmlspecialchars($activeAcademicYear['academicYear'] ?? 'N/A') ?></p>
                </div>
            </div>
        </div>

        <!-- Grades Table (placeholder - will be filled by JS) -->
        <div id="transcriptContent" style="margin-bottom:24px;">
            <div style="text-align:center;padding:40px;color:#999;">
                <i class="fas fa-spinner fa-spin"></i> Loading transcript data...
            </div>
        </div>

        <!-- Transcript Summary -->
        <div id="cumulativeSummary" style="background:#f9fafb;padding:16px;border-radius:8px;border-left:4px solid #2563eb;">
            <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:16px;font-size:14px;">
                <div><strong>Units in released grades:</strong> <span id="totalUnits">—</span></div>
                <div><strong>Semesters with grades:</strong> <span id="semesterCount">—</span></div>
            </div>
            <p style="margin:12px 0 0;color:#526174;font-size:13px;">Each semester's GWA is shown separately with its academic year.</p>
        </div>

    </div>

    <!-- Actions -->
    <div style="display:flex;gap:12px;justify-content:center;">
        <button id="btnPrint" type="button" style="background:#2563eb;color:white;padding:12px 24px;border:none;border-radius:6px;cursor:pointer;font-weight:600;font-size:14px;">
            <i class="fas fa-print"></i> Print / Save as PDF
        </button>
        <a href="grades.php" style="background:#fff;color:#1a3a8c;padding:12px 24px;border:1px solid #dce4f0;border-radius:6px;text-decoration:none;font-weight:600;font-size:14px;">
            <i class="fas fa-arrow-left"></i> Back to Grades
        </a>
    </div>

    <!-- Download History -->
    <div style="margin-top:40px;padding-top:24px;border-top:2px solid #e5e7eb;">
        <h3 style="margin:0 0 16px;font-size:16px;font-weight:600;">Transcript Download History</h3>
        <div id="downloadHistory" style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:16px;">
            <p style="margin:0;color:#666;font-size:14px;text-align:center;">Loading history...</p>
        </div>
    </div>

</div>

<script>
const studentID = <?= $user['id'] ?>;

// Load transcript data
function loadTranscript() {
    fetch('<?= BASE_URL ?>api/transcripts.php?action=get_my_transcript')
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                displayTranscript(data);
            }
        })
        .catch(e => console.error(e));
}

function displayTranscript(data) {
    let totalUnits = 0;
    let html = '';

    // Group by academic year and semester
    const grouped = {};
    data.gradesBySemester.forEach(sem => {
        const year = sem.academicYear;
        if (!grouped[year]) grouped[year] = {};
        grouped[year][`Semester ${sem.semester}`] = sem;
    });

    // Build table for each year/semester
    Object.entries(grouped).forEach(([year, semesters]) => {
        html += `<h4 style="margin:20px 0 12px;font-size:14px;font-weight:600;color:#1f2937;">${year}</h4>`;
        
        Object.entries(semesters).forEach(([semLabel, semData]) => {
            const semesterGWA = Number(semData.semesterGPA || 0);
            const semesterUnits = Number(semData.semesterUnits || 0);
            html += `<div style="margin:8px 0;color:#1a3a8c;font-size:14px;font-weight:700;">${semLabel} GWA: ${semesterGWA > 0 ? semesterGWA.toFixed(4) : 'N/A'} <span style="color:#526174;font-size:12px;font-weight:400;">(${semesterUnits.toFixed(1)} graded units)</span></div>`;
            html += `
                <table style="width:100%;margin-bottom:20px;border-collapse:collapse;">
                <thead>
                    <tr style="background:#f3f4f6;">
                        <th style="padding:10px;text-align:left;border:1px solid #e5e7eb;font-weight:600;font-size:12px;">${semLabel}</th>
                        <th style="padding:10px;text-align:right;border:1px solid #e5e7eb;font-weight:600;font-size:12px;">Units</th>
                        <th style="padding:10px;text-align:right;border:1px solid #e5e7eb;font-weight:600;font-size:12px;">Grade</th>
                        <th style="padding:10px;text-align:center;border:1px solid #e5e7eb;font-weight:600;font-size:12px;">Status</th>
                    </tr>
                </thead>
                <tbody>
            `;
            
            semData.grades.forEach(grade => {
                const statusColor = grade.remarks === 'Passed' ? '#10b981' : '#ef4444';
                html += `
                    <tr>
                        <td style="padding:10px;border:1px solid #e5e7eb;font-size:13px;">${grade.subjectCode} - ${grade.subjectName}</td>
                        <td style="padding:10px;border:1px solid #e5e7eb;font-size:13px;text-align:right;">${grade.units}</td>
                        <td style="padding:10px;border:1px solid #e5e7eb;font-size:13px;text-align:right;font-weight:600;">${grade.gradeEquivalent}</td>
                        <td style="padding:10px;border:1px solid #e5e7eb;font-size:13px;text-align:center;color:${statusColor};">${grade.remarks}</td>
                    </tr>
                `;
                totalUnits += grade.units;
            });

            html += `</tbody></table>`;
        });
    });

    document.getElementById('transcriptContent').innerHTML = html || '<p style="text-align:center;color:#999;">No grades to display</p>';

    // Update summary
    document.getElementById('totalUnits').textContent = totalUnits.toFixed(1);
    document.getElementById('semesterCount').textContent = data.gradesBySemester.length;

    // Load download history
    loadDownloadHistory();
}

function loadDownloadHistory() {
    fetch('<?= BASE_URL ?>api/transcripts.php?action=get_transcript_logs')
        .then(r => r.json())
        .then(data => {
            if (data.success && data.data.length > 0) {
                const history = data.data.map(log => `
                    <div style="padding:12px;border-bottom:1px solid #d1d5db;">
                        <p style="margin:0;font-size:13px;"><strong>${log.format}</strong> - ${new Date(log.generatedAt).toLocaleString()}</p>
                        ${log.downloadedAt ? `<p style="margin:4px 0 0;font-size:12px;color:#666;">Downloaded: ${new Date(log.downloadedAt).toLocaleString()}</p>` : ''}
                    </div>
                `).join('');
                document.getElementById('downloadHistory').innerHTML = history;
            }
        });
}

// Use the browser print dialog to print or save as PDF.
document.getElementById('btnPrint').addEventListener('click', () => {
    window.print();
});

// Load on page load
loadTranscript();
</script>

<?php require_once ROOT . '/includes/footer.php'; ?>
