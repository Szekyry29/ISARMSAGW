# ISARMS — Integrated Student Academic Records Management System
## NEUST Talavera Off-Campus | PHP + MySQL (XAMPP)

---

## 📋 PROJECT OVERVIEW

**Full Title:** Integrated Student Academic Records Management and GWA Computation System with Latin Honor Classification for NEUST Talavera Off-Campus

**Tech Stack:** PHP 8+, MySQL 8 (XAMPP), HTML5/CSS3, Vanilla JS

---

## Installation (XAMPP)

### Step 1 — Copy Project Files
```
C:\xampp\htdocs\ISARMS\
```

### Step 2 — Create the Database
1. Open `http://localhost/phpmyadmin`
2. Create a new database: `isarms_db`
3. Import: `database/isarms_db.sql`

### Step 3 — Configure Database Access
Set these environment variables in the web-server/PHP process for deployment:

| Variable | Example |
|---|---|
| `ISARMS_DB_HOST` | `127.0.0.1` |
| `ISARMS_DB_PORT` | `3306` |
| `ISARMS_DB_NAME` | `isarms_db` |
| `ISARMS_DB_USER` | A dedicated, least-privilege database user |
| `ISARMS_DB_PASS` | A unique secret stored outside the project |

For local XAMPP only, `includes/config.local.php` may hold local connection settings. It is ignored by Git and blocked by Apache. Do not copy it to production. `BASE_URL` is derived from the request host and scheme.

### Step 4 — Apply Schema Migrations
From the project root, run `php run_migration.php` in a terminal. The runner is CLI-only and applies the project feature, grade-status, automatic-posting, and Dean's List publication migrations. Back up the database before updating an existing installation.

### Step 5 — Configure Uploads and HTTPS
```
C:\xampp\htdocs\ISARMS\uploads\
```
The web-server process needs write access to `uploads/profiles/`. PHP execution is blocked in uploads. Production must use HTTPS and support Apache `.htaccess` rules; otherwise configure equivalent server-level file protections.

### Step 6 — Provision Accounts
Create real staff/student accounts through the Admin and Registrar workflows. Reset any sample/demo account passwords before collecting survey responses. Do not run the demo `setup.php` seed in production; it is CLI-only and creates test records.

### Step 7 — Access the System
```
http://localhost/ISARMS/
```
Use HTTPS for any non-local deployment.

---

## Account Provisioning

| Role       | Email                     | Notes                     |
|------------|---------------------------|---------------------------|
| Admin      | Created by database administrator | Set a unique password before deployment |
| Teacher    | (created by Admin)        | Via Admin > Users         |
| Registrar  | (created by Admin)        | Via Admin > Users         |
| Student    | (created by Admin/Reg)    | Via Admin/Reg > Students  |

---

## 📁 PROJECT STRUCTURE

```
ISARMS/
├── index.php                    ← Login page
├── deans-list.php               ← Public Registrar-published Dean's List
├── system-diagrams.html         ← ERD, context, DFD levels, and use cases
├── logout.php
├── database/
│   └── isarms_db.sql            ← Full database schema
├── includes/
│   ├── config.php               ← DB config + constants
│   ├── functions.php            ← GWA, helpers, auth
│   ├── header.php               ← Shared layout/navbar
│   └── footer.php
├── api/
│   ├── grades.php               ← Grade CRUD + approval workflow
│   ├── gwa.php                  ← GWA recompute + honor validation
│   ├── students.php             ← Student management
│   └── notifications.php       ← Notification system
└── modules/
    ├── admin/
    │   ├── dashboard.php
    │   ├── students.php
    │   ├── users.php
    │   ├── subjects.php
    │   ├── programs.php
    │   ├── sections.php
    │   └── acad_years.php
    ├── teacher/
    │   ├── dashboard.php
    │   ├── classes.php
    │   ├── encode_grades.php
    │   └── class_report.php
    ├── registrar/
    │   ├── dashboard.php
    │   ├── students.php
    │   ├── grade_approvals.php  ← Core approval workflow
    │   ├── honors.php           ← Latin honor validation
    │   ├── reports.php          ← Print-ready reports
    │   └── integrity.php
    └── student/
        ├── dashboard.php
        ├── grades.php
        ├── gwa.php              ← GWA + honors view
        ├── honors.php
        └── profile.php
```

## System Diagrams

Open `system-diagrams.html` from the project root to view the database ERD, system context diagram, DFD Level 0, DFD Level 1 grade-to-honors workflow, and use-case diagram. The page is self-contained and includes responsive and print-friendly layouts.

---

## 🏛️ GRADING SYSTEM (NEUST 5-Point Scale)

| Score Range | Grade Equivalent |
|-------------|-----------------|
| 98 – 100    | 1.00            |
| 95 – 97     | 1.25            |
| 92 – 94     | 1.50            |
| 89 – 91     | 1.75            |
| 86 – 88     | 2.00            |
| 83 – 85     | 2.25            |
| 80 – 82     | 2.50            |
| 77 – 79     | 2.75            |
| 75 – 76     | 3.00 (Passing)  |
| Below 75    | 5.00 (Failed)   |

**GWA Formula:** Σ(Grade Equivalent × Units) ÷ Σ(Units)
**Final Grade Formula:** Midterm (40%) + Finals (60%)

GWA subject inclusion is configured by an Admin in **GWA Rules**, separately for each academic year. Subjects without an explicit rule are included by default; changing one academic year's rules does not overwrite another year's configuration. Saving changes recalculates that year's stored GWA records and requires Registrar honor validation again.

---

## 🏅 LATIN HONOR STANDARDS (NEUST)

| Honor          | GWA Range     | Condition                |
|----------------|---------------|--------------------------|
| Summa Cum Laude | 1.00 – 1.20  | No grade below 2.00      |
| Magna Cum Laude | 1.21 – 1.45  | No grade below 2.00      |
| Cum Laude       | 1.46 – 1.75  | No grade below 2.00      |
| Dean's List     | 1.00 – 1.75  | Per semester, no INC     |

---

## 🔄 GRADE POSTING WORKFLOW

```
Teacher Encodes Grade
        ↓ Save
   Status: Pending
        ↓ Submit
   Status: Posted
        ↓
 Student Notified
        ↓
Included in GWA

MIS may return a posted grade to the teacher for correction; MIS cannot edit scores.
```

---

## 🔐 SECURITY FEATURES

- **bcrypt** password hashing (cost: 12)
- **PDO Prepared Statements** — SQL injection prevention
- **CSRF Token** validation on login, account forms, and student self-enrollment
- **Session timeout** (30 minutes)
- **Role-based access control** via `requireAuth()`
- **Soft deletes** (`dateDeleted`) — no hard data loss
- **Audit log** for all grade changes
- **CLI-only maintenance scripts** and Apache protections for database dumps, configuration files, and uploads

---

## 📊 KEY FEATURES SUMMARY

| Feature                        | Admin | Teacher | Registrar | Student |
|--------------------------------|-------|---------|-----------|---------|
| Manage Users                   | ✅    | ❌      | ❌        | ❌      |
| Manage Programs/Subjects       | ✅    | ❌      | ❌        | ❌      |
| Encode Grades                  | ❌    | ✅      | ❌        | ❌      |
| Submit Grades for Approval     | ❌    | ✅      | ❌        | ❌      |
| Approve / Flag Grades          | ❌    | ❌      | ✅        | ❌      |
| Validate Latin Honors          | ❌    | ❌      | ✅        | ❌      |
| Generate Official Reports      | ❌    | ❌      | ✅        | ❌      |
| View Own Grades                | ❌    | ❌      | ❌        | ✅      |
| View Own GWA                   | ❌    | ❌      | ❌        | ✅      |
| View Honors Classification     | ❌    | ❌      | ❌        | ✅      |

---

## 👨‍💻 DEVELOPER NOTES

- All timestamps use `Asia/Manila` (+08:00)
- GWA is computed to **4 decimal places**
- Only `Approved` grades are included in GWA computation
- Soft deletes are used throughout — check `dateDeleted IS NULL`
- Notifications are real-time polled every load via `/api/notifications.php`

---

## Deployment Checklist

- Use a dedicated database user; do not deploy with MySQL `root` credentials.
- Store production credentials in the server environment, not in source control.
- Back up the database before every schema migration.
- Enable HTTPS, set strong unique passwords, and rotate any demo credentials.
- Confirm the active academic year and enrollment dates with the Registrar; they are business settings and are not changed automatically by migrations.
- Test Admin, Registrar, Teacher, and Student workflows using separate accounts before survey participants access the system.
- Keep the database backup outside the webroot and protect uploaded student documents as private data.

*ISARMS v1.0.0 — NEUST Talavera Off-Campus Capstone Project*
