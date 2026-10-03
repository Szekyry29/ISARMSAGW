-- ============================================================
-- ISARMS Migration: Add Critical Missing Features
-- Grade Appeals, Academic Standing, Transcript Logs
-- ============================================================

USE `isarms_db`;

-- ============================================================
-- TABLE: grade_appeals
-- For students to appeal grades and track the workflow
-- ============================================================
CREATE TABLE IF NOT EXISTS `grade_appeals` (
  `appealID`      INT(11)      NOT NULL AUTO_INCREMENT,
  `gradeID`       INT(11)      NOT NULL,
  `studentID`     INT(11)      NOT NULL,
  `enrollmentID`  INT(11)      NOT NULL,
  `reasonCode`    ENUM('Computation Error','Missed Exam','Extra Credit','Grading Dispute','Other') NOT NULL,
  `studentRemarks` TEXT        DEFAULT NULL COMMENT 'Why student is appealing',
  `status`        ENUM('Pending','Under Review','Approved','Rejected','Resolved') NOT NULL DEFAULT 'Pending',
  `reviewedByID`  INT(11)      DEFAULT NULL COMMENT 'Registrar who reviewed',
  `registrarRemarks` TEXT      DEFAULT NULL COMMENT 'Why appeal was approved/rejected',
  `newGrade`      DECIMAL(3,2) DEFAULT NULL COMMENT 'Grade if appeal approved',
  `appealedAt`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `reviewedAt`    TIMESTAMP    NULL DEFAULT NULL,
  `dateDeleted`   TIMESTAMP    NULL DEFAULT NULL,
  PRIMARY KEY (`appealID`),
  UNIQUE KEY `uq_appeal` (`gradeID`, `studentID`),
  FOREIGN KEY `fk_appeal_grade` (`gradeID`) REFERENCES `grades`(`gradeID`),
  FOREIGN KEY `fk_appeal_student` (`studentID`) REFERENCES `students`(`studentID`),
  FOREIGN KEY `fk_appeal_enrollment` (`enrollmentID`) REFERENCES `enrollments`(`enrollmentID`),
  FOREIGN KEY `fk_appeal_reviewer` (`reviewedByID`) REFERENCES `users`(`userID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: academic_standing
-- Tracks student academic status: Good Standing, Probation, Dean's List, etc.
-- ============================================================
CREATE TABLE IF NOT EXISTS `academic_standing` (
  `standingID`    INT(11)      NOT NULL AUTO_INCREMENT,
  `studentID`     INT(11)      NOT NULL,
  `acadYearID`    INT(11)      NOT NULL,
  `semester`      TINYINT(1)   DEFAULT NULL COMMENT 'NULL = cumulative for year',
  `status`        ENUM('Good Standing','Academic Probation','Dean''s List','Honors List','At Risk','Suspended','Graduated') NOT NULL DEFAULT 'Good Standing',
  `gwaValue`      DECIMAL(5,4) NOT NULL DEFAULT 0.0,
  `totalUnits`    DECIMAL(5,1) NOT NULL DEFAULT 0.0,
  `flaggedIssues` JSON         DEFAULT NULL COMMENT 'Array of issues (low GPA, failing grades, etc.)',
  `computedAt`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `dateDeleted`   TIMESTAMP    NULL DEFAULT NULL,
  PRIMARY KEY (`standingID`),
  UNIQUE KEY `uq_standing` (`studentID`, `acadYearID`, `semester`),
  FOREIGN KEY `fk_standing_student` (`studentID`) REFERENCES `students`(`studentID`),
  FOREIGN KEY `fk_standing_acadyear` (`acadYearID`) REFERENCES `acad_years`(`acadYearID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: transcript_logs
-- Logs when transcripts are generated/downloaded
-- ============================================================
CREATE TABLE IF NOT EXISTS `transcript_logs` (
  `logID`         INT(11)      NOT NULL AUTO_INCREMENT,
  `studentID`     INT(11)      NOT NULL,
  `requestedBy`   INT(11)      NOT NULL COMMENT 'userID or studentID',
  `requestedByRole` ENUM('Student','Admin','Registrar') NOT NULL,
  `format`        ENUM('PDF','CSV','JSON') NOT NULL DEFAULT 'PDF',
  `purpose`       VARCHAR(200) DEFAULT NULL COMMENT 'Why transcript was requested',
  `fileSize`      INT(11)      DEFAULT NULL COMMENT 'Bytes',
  `filePath`      VARCHAR(255) DEFAULT NULL COMMENT 'Storage path',
  `generatedAt`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `downloadedAt`  TIMESTAMP    NULL DEFAULT NULL,
  `ipAddress`     VARCHAR(45)  DEFAULT NULL,
  `userAgent`     VARCHAR(255) DEFAULT NULL,
  `dateDeleted`   TIMESTAMP    NULL DEFAULT NULL,
  PRIMARY KEY (`logID`),
  FOREIGN KEY `fk_tl_student` (`studentID`) REFERENCES `students`(`studentID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: student_documents
-- Store student uploads: requirements, proofs, appeal documents, etc.
-- ============================================================
CREATE TABLE IF NOT EXISTS `student_documents` (
  `docID`         INT(11)      NOT NULL AUTO_INCREMENT,
  `studentID`     INT(11)      NOT NULL,
  `docType`       ENUM('Grade Appeal','Medical Certificate','Clearance','Other') NOT NULL,
  `title`         VARCHAR(150) NOT NULL,
  `description`   TEXT         DEFAULT NULL,
  `filePath`      VARCHAR(255) NOT NULL,
  `mimeType`      VARCHAR(50)  DEFAULT NULL,
  `fileSize`      INT(11)      DEFAULT NULL,
  `linkedTo`      INT(11)      DEFAULT NULL COMMENT 'Related entity ID (appealID, etc.)',
  `uploadedAt`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `verifiedAt`    TIMESTAMP    NULL DEFAULT NULL,
  `verifiedByID`  INT(11)      DEFAULT NULL,
  `status`        ENUM('Pending','Verified','Rejected') NOT NULL DEFAULT 'Pending',
  `dateDeleted`   TIMESTAMP    NULL DEFAULT NULL,
  PRIMARY KEY (`docID`),
  FOREIGN KEY `fk_doc_student` (`studentID`) REFERENCES `students`(`studentID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- Add missing columns to existing tables
-- ============================================================

-- Add to students table if not exists
ALTER TABLE `students` ADD COLUMN IF NOT EXISTS `academicStanding` ENUM('Good Standing','Academic Probation','Dean''s List','At Risk','Suspended') DEFAULT 'Good Standing';
ALTER TABLE `students` ADD COLUMN IF NOT EXISTS `lastStandingUpdate` TIMESTAMP NULL DEFAULT NULL;

-- Add to notifications table for better filtering
ALTER TABLE `notifications` ADD COLUMN IF NOT EXISTS `linkedEntityID` INT(11) DEFAULT NULL COMMENT 'Related entity (gradeID, appealID, etc.)';
ALTER TABLE `notifications` ADD COLUMN IF NOT EXISTS `linkedEntityType` VARCHAR(50) DEFAULT NULL COMMENT 'Type of entity';
ALTER TABLE `notifications` ADD COLUMN IF NOT EXISTS `dateDeleted` TIMESTAMP NULL DEFAULT NULL;

-- Add to acad_years table for better management
ALTER TABLE `acad_years` ADD COLUMN IF NOT EXISTS `enrollmentStartDate` DATE DEFAULT NULL;
ALTER TABLE `acad_years` ADD COLUMN IF NOT EXISTS `enrollmentEndDate` DATE DEFAULT NULL;
ALTER TABLE `acad_years` ADD COLUMN IF NOT EXISTS `academicStartDate` DATE DEFAULT NULL;
ALTER TABLE `acad_years` ADD COLUMN IF NOT EXISTS `academicEndDate` DATE DEFAULT NULL;

-- ============================================================
-- Indexes for better performance
-- ============================================================
CREATE INDEX IF NOT EXISTS `idx_appeal_student` ON `grade_appeals`(`studentID`);
CREATE INDEX IF NOT EXISTS `idx_appeal_status` ON `grade_appeals`(`status`);
CREATE INDEX IF NOT EXISTS `idx_standing_student` ON `academic_standing`(`studentID`);
CREATE INDEX IF NOT EXISTS `idx_standing_status` ON `academic_standing`(`status`);
CREATE INDEX IF NOT EXISTS `idx_transcript_student` ON `transcript_logs`(`studentID`);
CREATE INDEX IF NOT EXISTS `idx_transcript_date` ON `transcript_logs`(`generatedAt`);
CREATE INDEX IF NOT EXISTS `idx_document_student` ON `student_documents`(`studentID`);

COMMIT;
