-- ============================================================
-- MIGRATION: Add section_subjects table
-- Run this in phpMyAdmin after importing the original SQL
-- ============================================================

USE `isarms_db`;

-- New table: section_subjects
-- Each section can have many subjects, each with its own teacher
CREATE TABLE IF NOT EXISTS `section_subjects` (
  `secSubID`     INT(11)   NOT NULL AUTO_INCREMENT,
  `sectionID`    INT(11)   NOT NULL,
  `subjectID`    INT(11)   NOT NULL,
  `teacherID`    INT(11)   DEFAULT NULL COMMENT 'Teacher for THIS subject in THIS section',
  `semester`     TINYINT(1) NOT NULL COMMENT '1=First, 2=Second',
  `acadYearID`   INT(11)   NOT NULL,
  `schedule`     VARCHAR(100) DEFAULT NULL COMMENT 'e.g. MWF 8:00-9:00 AM',
  `room`         VARCHAR(50)  DEFAULT NULL,
  `dateCreated`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `dateDeleted`  TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`secSubID`),
  UNIQUE KEY `uq_sec_sub` (`sectionID`,`subjectID`,`semester`,`acadYearID`),
  CONSTRAINT `fk_ss_section` FOREIGN KEY (`sectionID`) REFERENCES `sections`(`sectionID`),
  CONSTRAINT `fk_ss_subject` FOREIGN KEY (`subjectID`) REFERENCES `subjects`(`subjectID`),
  CONSTRAINT `fk_ss_teacher` FOREIGN KEY (`teacherID`) REFERENCES `users`(`userID`),
  CONSTRAINT `fk_ss_ay`      FOREIGN KEY (`acadYearID`) REFERENCES `acad_years`(`acadYearID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Remove the single-teacher FK from sections (keep column for backward compat but nullable)
-- sections.userID is now deprecated — teacher is assigned per subject via section_subjects
ALTER TABLE `sections` MODIFY `userID` INT(11) DEFAULT NULL COMMENT 'Deprecated: use section_subjects';

