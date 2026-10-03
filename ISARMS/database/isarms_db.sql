-- ============================================================
-- INTEGRATED STUDENT ACADEMIC RECORDS MANAGEMENT SYSTEM (ISARMS)
-- GWA Computation with Latin Honor Classification
-- NEUST Talavera Off-Campus
-- Database: isarms_db
-- ============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+08:00";

CREATE DATABASE IF NOT EXISTS `isarms_db` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `isarms_db`;

-- ============================================================
-- TABLE: acad_years
-- ============================================================
CREATE TABLE `acad_years` (
  `acadYearID`    INT(11)      NOT NULL AUTO_INCREMENT,
  `academicYear`  VARCHAR(20)  NOT NULL COMMENT 'e.g. 2024-2025',
  `acadDesc`      VARCHAR(100) DEFAULT NULL,
  `status`        ENUM('Active','Inactive','Archived') NOT NULL DEFAULT 'Inactive',
  `dateCreated`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `dateDeleted`   TIMESTAMP    NULL DEFAULT NULL,
  PRIMARY KEY (`acadYearID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: programs
-- ============================================================
CREATE TABLE `programs` (
  `programID`    INT(11)      NOT NULL AUTO_INCREMENT,
  `programCode`  VARCHAR(20)  NOT NULL COMMENT 'e.g. BSIT, BSCS',
  `programName`  VARCHAR(150) NOT NULL,
  `programDesc`  TEXT         DEFAULT NULL,
  `dateCreated`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `dateDeleted`  TIMESTAMP    NULL DEFAULT NULL,
  PRIMARY KEY (`programID`),
  UNIQUE KEY `uq_programCode` (`programCode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: sections
-- ============================================================
CREATE TABLE `sections` (
  `sectionID`    INT(11)      NOT NULL AUTO_INCREMENT,
  `sectionName`  VARCHAR(50)  NOT NULL,
  `sectionDesc`  VARCHAR(200) DEFAULT NULL,
  `programID`    INT(11)      NOT NULL,
  `acadYearID`   INT(11)      NOT NULL,
  `userID`       INT(11)      DEFAULT NULL COMMENT 'Deprecated - use section_subjects for per-subject teacher',
  `dateCreated`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `dateDeleted`  TIMESTAMP    NULL DEFAULT NULL,
  PRIMARY KEY (`sectionID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: section_subjects
-- Each subject in a section has its own assigned teacher
-- This is the correct university model
-- ============================================================
CREATE TABLE `section_subjects` (
  `secSubID`    INT(11)       NOT NULL AUTO_INCREMENT,
  `sectionID`   INT(11)       NOT NULL,
  `subjectID`   INT(11)       NOT NULL,
  `teacherID`   INT(11)       DEFAULT NULL COMMENT 'Teacher for this specific subject',
  `semester`    TINYINT(1)    NOT NULL COMMENT '1=First, 2=Second',
  `acadYearID`  INT(11)       NOT NULL,
  `schedule`    VARCHAR(100)  DEFAULT NULL COMMENT 'e.g. MWF 8:00-9:00 AM',
  `room`        VARCHAR(50)   DEFAULT NULL,
  `dateCreated` TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `dateDeleted` TIMESTAMP     NULL DEFAULT NULL,
  PRIMARY KEY (`secSubID`),
  UNIQUE KEY `uq_sec_sub` (`sectionID`,`subjectID`,`semester`,`acadYearID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: subjects
-- ============================================================
CREATE TABLE `subjects` (
  `subjectID`    INT(11)      NOT NULL AUTO_INCREMENT,
  `subjectCode`  VARCHAR(20)  NOT NULL,
  `subjectName`  VARCHAR(150) NOT NULL,
  `units`        DECIMAL(4,1) NOT NULL DEFAULT 3.0,
  `programID`    INT(11)      DEFAULT NULL,
  `yearLevel`    TINYINT(1)   DEFAULT NULL COMMENT '1=1st, 2=2nd, 3=3rd, 4=4th',
  `semester`     TINYINT(1)   DEFAULT NULL COMMENT '1=First, 2=Second',
  `dateCreated`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `dateDeleted`  TIMESTAMP    NULL DEFAULT NULL,
  PRIMARY KEY (`subjectID`),
  UNIQUE KEY `uq_subjectCode` (`subjectCode`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: archiving
-- ============================================================
CREATE TABLE `archiving` (
  `archiveID`    INT(11)      NOT NULL AUTO_INCREMENT,
  `drop`         TINYINT(1)   DEFAULT 0,
  `graduate`     TINYINT(1)   DEFAULT 0,
  `dateArchived` DATE         DEFAULT NULL,
  `dateDeleted`  TIMESTAMP    NULL DEFAULT NULL,
  PRIMARY KEY (`archiveID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: users  (Admin, Teacher, Registrar accounts)
-- ============================================================
CREATE TABLE `users` (
  `userID`       INT(11)      NOT NULL AUTO_INCREMENT,
  `fName`        VARCHAR(60)  NOT NULL,
  `mName`        VARCHAR(60)  DEFAULT NULL,
  `lName`        VARCHAR(60)  NOT NULL,
  `extName`      VARCHAR(10)  DEFAULT NULL COMMENT 'Jr., Sr., III',
  `email`        VARCHAR(120) NOT NULL,
  `password`     VARCHAR(255) NOT NULL COMMENT 'bcrypt hashed',
  `roleName`     ENUM('Admin','Teacher','Registrar','Student') NOT NULL,
  `profilePic`   VARCHAR(255) DEFAULT NULL,
  `programID`    INT(11)      DEFAULT NULL COMMENT 'For teachers only',
  `isActive`     TINYINT(1)   NOT NULL DEFAULT 1,
  `lastLogin`    TIMESTAMP    NULL DEFAULT NULL,
  `dateCreated`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `dateDeleted`  TIMESTAMP    NULL DEFAULT NULL,
  PRIMARY KEY (`userID`),
  UNIQUE KEY `uq_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: students
-- ============================================================
CREATE TABLE `students` (
  `studentID`        INT(11)      NOT NULL AUTO_INCREMENT,
  `studentNo`        VARCHAR(20)  NOT NULL COMMENT 'Student Number e.g. 2021-00001',
  `archiveID`        INT(11)      DEFAULT NULL,
  `fName`            VARCHAR(60)  NOT NULL,
  `mName`            VARCHAR(60)  DEFAULT NULL,
  `lName`            VARCHAR(60)  NOT NULL,
  `extName`          VARCHAR(10)  DEFAULT NULL,
  `dateofBirth`      DATE         NOT NULL,
  `sex`              ENUM('Male','Female') NOT NULL,
  `permanentAddress` TEXT         DEFAULT NULL,
  `currentAddress`   TEXT         DEFAULT NULL,
  `yearLevel`        TINYINT(1)   NOT NULL DEFAULT 1,
  `isEnroll`         TINYINT(1)   NOT NULL DEFAULT 0,
  `programID`        INT(11)      NOT NULL,
  `email`            VARCHAR(120) NOT NULL COMMENT 'Student login email',
  `password`         VARCHAR(255) NOT NULL COMMENT 'bcrypt hashed',
  `profilePic`       VARCHAR(255) DEFAULT NULL,
  `isActive`         TINYINT(1)   NOT NULL DEFAULT 1,
  `isGraduated`      TINYINT(1)   NOT NULL DEFAULT 0,
  `isDropped`        TINYINT(1)   NOT NULL DEFAULT 0,
  `dateGraduated`    TIMESTAMP    NULL DEFAULT NULL,
  `droppedAt`        TIMESTAMP    NULL DEFAULT NULL,
  `lastLogin`        TIMESTAMP    NULL DEFAULT NULL,
  `dateCreated`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `dateDeleted`      TIMESTAMP    NULL DEFAULT NULL,
  PRIMARY KEY (`studentID`),
  UNIQUE KEY `uq_studentNo` (`studentNo`),
  UNIQUE KEY `uq_student_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: enrollments
-- ============================================================
CREATE TABLE `enrollments` (
  `enrollmentID`  INT(11)   NOT NULL AUTO_INCREMENT,
  `dateofEnroll`  DATE      NOT NULL,
  `acadYear`      VARCHAR(20) NOT NULL,
  `semester`      TINYINT(1)  NOT NULL COMMENT '1=First, 2=Second',
  `studentID`     INT(11)   NOT NULL,
  `subjectID`     INT(11)   NOT NULL,
  `programID`     INT(11)   NOT NULL,
  `sectionID`     INT(11)   NOT NULL,
  `acadYearID`    INT(11)   NOT NULL,
  `status`        ENUM('Active','Dropped','Withdrawn') NOT NULL DEFAULT 'Active',
  `dateCreated`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `dateDeleted`   TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`enrollmentID`),
  UNIQUE KEY `uq_enroll` (`studentID`,`subjectID`,`acadYearID`,`semester`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: grades
-- ============================================================
CREATE TABLE `grades` (
  `gradeID`         INT(11)        NOT NULL AUTO_INCREMENT,
  `enrollmentID`    INT(11)        NOT NULL,
  `studentID`       INT(11)        NOT NULL,
  `subjectID`       INT(11)        NOT NULL,
  `acadYearID`      INT(11)        NOT NULL,
  `midterm`         DECIMAL(5,2)   DEFAULT NULL,
  `finals`          DECIMAL(5,2)   DEFAULT NULL,
  `computedGrade`   DECIMAL(5,2)   DEFAULT NULL COMMENT 'Auto-computed',
  `gradeEquivalent` DECIMAL(3,2)   DEFAULT NULL COMMENT 'NEUST scale: 1.00–5.00',
  `postedGrade`     DECIMAL(3,2)   DEFAULT NULL COMMENT 'Registrar-approved final',
  `remarks`         ENUM('Passed','Failed','Inc','Dropped','Withdrawn') DEFAULT NULL,
  `status`          ENUM('Pending','Submitted','Approved','Flagged','Returned') NOT NULL DEFAULT 'Pending',
  `teacherID`       INT(11)        NOT NULL,
  `encodedAt`       TIMESTAMP      NULL DEFAULT NULL,
  `submittedAt`     TIMESTAMP      NULL DEFAULT NULL,
  `approvedAt`      TIMESTAMP      NULL DEFAULT NULL,
  `approvedByID`    INT(11)        DEFAULT NULL COMMENT 'Registrar userID',
  `teacherRemarks`  TEXT           DEFAULT NULL,
  `registrarNotes`  TEXT           DEFAULT NULL,
  `dateCreated`     TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `dateDeleted`     TIMESTAMP      NULL DEFAULT NULL,
  PRIMARY KEY (`gradeID`),
  UNIQUE KEY `uq_grade` (`enrollmentID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: gwa_records  (computed per semester/year)
-- ============================================================
CREATE TABLE `gwa_records` (
  `gwaID`           INT(11)      NOT NULL AUTO_INCREMENT,
  `studentID`       INT(11)      NOT NULL,
  `acadYearID`      INT(11)      NOT NULL,
  `semester`        TINYINT(1)   DEFAULT NULL COMMENT 'NULL = cumulative',
  `totalUnits`      DECIMAL(5,1) NOT NULL DEFAULT 0,
  `weightedSum`     DECIMAL(8,3) NOT NULL DEFAULT 0,
  `gwaValue`        DECIMAL(5,4) NOT NULL DEFAULT 0 COMMENT '4 decimal precision',
  `latinHonor`      ENUM('Summa Cum Laude','Magna Cum Laude','Cum Laude','Dean''s List','None') DEFAULT 'None',
  `validationStatus` ENUM('Pending','Validated','Flagged') NOT NULL DEFAULT 'Pending',
  `validatedByID`   INT(11)      DEFAULT NULL,
  `validatedAt`     TIMESTAMP    NULL DEFAULT NULL,
  `computedAt`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`gwaID`),
  UNIQUE KEY `uq_gwa` (`studentID`,`acadYearID`,`semester`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: subject_gwa_rules (academic-year-specific inclusion)
-- ============================================================
CREATE TABLE `subject_gwa_rules` (
  `ruleID`       INT(11) NOT NULL AUTO_INCREMENT,
  `acadYearID`   INT(11) NOT NULL,
  `subjectID`    INT(11) NOT NULL,
  `includeInGwa` TINYINT(1) NOT NULL DEFAULT 1,
  `updatedByID`  INT(11) DEFAULT NULL,
  `updatedAt`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`ruleID`),
  UNIQUE KEY `uq_subject_gwa_rule` (`acadYearID`,`subjectID`),
  KEY `idx_subject_gwa_subject` (`subjectID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: dean_list_publications
-- ============================================================
CREATE TABLE `dean_list_publications` (
  `publicationID` INT(11) NOT NULL AUTO_INCREMENT,
  `acadYearID`    INT(11) NOT NULL,
  `semester`      TINYINT(1) NOT NULL,
  `publishedByID` INT(11) NOT NULL,
  `publishedAt`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`publicationID`),
  UNIQUE KEY `uq_dean_list_period` (`acadYearID`,`semester`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: ranking
-- ============================================================
CREATE TABLE `ranking` (
  `rankingID`   INT(11)   NOT NULL AUTO_INCREMENT,
  `badge`       VARCHAR(100) DEFAULT NULL,
  `studentID`   INT(11)   NOT NULL,
  `gradeID`     INT(11)   NOT NULL,
  `dateCreated` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `dateDeleted` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`rankingID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: grade_audit_log
-- ============================================================
CREATE TABLE `grade_audit_log` (
  `logID`       INT(11)   NOT NULL AUTO_INCREMENT,
  `gradeID`     INT(11)   NOT NULL,
  `actionBy`    INT(11)   NOT NULL COMMENT 'userID or studentID',
  `actionRole`  ENUM('Teacher','Registrar','Admin') NOT NULL,
  `action`      VARCHAR(50) NOT NULL COMMENT 'Encoded, Submitted, Approved, Flagged, Returned',
  `oldValue`    JSON      DEFAULT NULL,
  `newValue`    JSON      DEFAULT NULL,
  `notes`       TEXT      DEFAULT NULL,
  `actionAt`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`logID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: notifications
-- ============================================================
CREATE TABLE `notifications` (
  `notifID`     INT(11)      NOT NULL AUTO_INCREMENT,
  `recipientID` INT(11)      NOT NULL,
  `recipientType` ENUM('Student','User') NOT NULL,
  `title`       VARCHAR(150) NOT NULL,
  `message`     TEXT         NOT NULL,
  `type`        ENUM('Grade','GWA','Honors','System','Approval') NOT NULL,
  `isRead`      TINYINT(1)   NOT NULL DEFAULT 0,
  `createdAt`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`notifID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- FOREIGN KEY CONSTRAINTS
-- ============================================================
ALTER TABLE `sections`
  ADD CONSTRAINT `fk_sec_program` FOREIGN KEY (`programID`) REFERENCES `programs`(`programID`),
  ADD CONSTRAINT `fk_sec_acadyear` FOREIGN KEY (`acadYearID`) REFERENCES `acad_years`(`acadYearID`);

ALTER TABLE `section_subjects`
  ADD CONSTRAINT `fk_ss_section` FOREIGN KEY (`sectionID`)  REFERENCES `sections`(`sectionID`),
  ADD CONSTRAINT `fk_ss_subject` FOREIGN KEY (`subjectID`)  REFERENCES `subjects`(`subjectID`),
  ADD CONSTRAINT `fk_ss_teacher` FOREIGN KEY (`teacherID`)  REFERENCES `users`(`userID`),
  ADD CONSTRAINT `fk_ss_ay`      FOREIGN KEY (`acadYearID`) REFERENCES `acad_years`(`acadYearID`);

ALTER TABLE `students`
  ADD CONSTRAINT `fk_stu_archive` FOREIGN KEY (`archiveID`) REFERENCES `archiving`(`archiveID`),
  ADD CONSTRAINT `fk_stu_program` FOREIGN KEY (`programID`) REFERENCES `programs`(`programID`);

ALTER TABLE `users`
  ADD CONSTRAINT `fk_usr_program` FOREIGN KEY (`programID`) REFERENCES `programs`(`programID`);

ALTER TABLE `enrollments`
  ADD CONSTRAINT `fk_enr_student` FOREIGN KEY (`studentID`) REFERENCES `students`(`studentID`),
  ADD CONSTRAINT `fk_enr_subject` FOREIGN KEY (`subjectID`) REFERENCES `subjects`(`subjectID`),
  ADD CONSTRAINT `fk_enr_program` FOREIGN KEY (`programID`) REFERENCES `programs`(`programID`),
  ADD CONSTRAINT `fk_enr_section` FOREIGN KEY (`sectionID`) REFERENCES `sections`(`sectionID`),
  ADD CONSTRAINT `fk_enr_acadyear` FOREIGN KEY (`acadYearID`) REFERENCES `acad_years`(`acadYearID`);

ALTER TABLE `grades`
  ADD CONSTRAINT `fk_gr_enrollment` FOREIGN KEY (`enrollmentID`) REFERENCES `enrollments`(`enrollmentID`),
  ADD CONSTRAINT `fk_gr_student` FOREIGN KEY (`studentID`) REFERENCES `students`(`studentID`),
  ADD CONSTRAINT `fk_gr_subject` FOREIGN KEY (`subjectID`) REFERENCES `subjects`(`subjectID`),
  ADD CONSTRAINT `fk_gr_teacher` FOREIGN KEY (`teacherID`) REFERENCES `users`(`userID`),
  ADD CONSTRAINT `fk_gr_approver` FOREIGN KEY (`approvedByID`) REFERENCES `users`(`userID`);

ALTER TABLE `gwa_records`
  ADD CONSTRAINT `fk_gwa_student` FOREIGN KEY (`studentID`) REFERENCES `students`(`studentID`),
  ADD CONSTRAINT `fk_gwa_acadyear` FOREIGN KEY (`acadYearID`) REFERENCES `acad_years`(`acadYearID`),
  ADD CONSTRAINT `fk_gwa_validator` FOREIGN KEY (`validatedByID`) REFERENCES `users`(`userID`);

ALTER TABLE `ranking`
  ADD CONSTRAINT `fk_rank_student` FOREIGN KEY (`studentID`) REFERENCES `students`(`studentID`),
  ADD CONSTRAINT `fk_rank_grade` FOREIGN KEY (`gradeID`) REFERENCES `grades`(`gradeID`);

-- ============================================================
-- SEED DATA
-- ============================================================

-- Academic Years
INSERT INTO `acad_years` (`academicYear`,`acadDesc`,`status`) VALUES
('2023-2024','Academic Year 2023-2024','Inactive'),
('2024-2025','Academic Year 2024-2025','Active'),
('2025-2026','Academic Year 2025-2026','Inactive');

-- Programs
INSERT INTO `programs` (`programCode`,`programName`,`programDesc`) VALUES
('BSIT','Bachelor of Science in Information Technology','Covers software development, networking, and IT management'),
('BSCS','Bachelor of Science in Computer Science','Covers algorithms, systems, and theoretical computing'),
('BSED','Bachelor of Secondary Education','Teacher education for secondary level'),
('BEED','Bachelor of Elementary Education','Teacher education for elementary level'),
('BSA','Bachelor of Science in Accountancy','Covers accounting, auditing, and finance'),
('BSBA','Bachelor of Science in Business Administration','Business and management studies');

-- Default Admin
INSERT INTO `users` (`fName`,`mName`,`lName`,`email`,`password`,`roleName`,`isActive`) VALUES
('System','','Administrator','admin@neust.edu.ph','$2y$12$yRsigGo7G5S9MxaDY7ljPenM9YCSZFVLsTZSAhcXRWQ4ckqP1OvZC','Admin',1);

-- Latin Honors Reference (for documentation)
-- Summa Cum Laude: GWA 1.00 – 1.20, No grade below 2.00
-- Magna Cum Laude: GWA 1.21 – 1.45, No grade below 2.00
-- Cum Laude:       GWA 1.46 – 1.75, No grade below 2.00
-- Dean's List:     GWA 1.00 – 1.75 per semester

COMMIT;
