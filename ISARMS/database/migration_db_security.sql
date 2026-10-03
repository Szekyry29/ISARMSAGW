-- ============================================================
-- ISARMS DATABASE SECURITY HARDENING MIGRATION
-- Adds stored procedure, triggers, and integrity safeguards.
-- Safe to apply on top of the existing isarms_db schema.
-- ============================================================

USE `isarms_db`;

DELIMITER $$

DROP PROCEDURE IF EXISTS `sp_mark_student_archive`$$
CREATE PROCEDURE `sp_mark_student_archive`(IN `p_student_id` INT, IN `p_action` VARCHAR(20))
BEGIN
    IF p_student_id IS NULL OR p_student_id <= 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid student ID.';
    END IF;

    IF p_action NOT IN ('graduate', 'drop') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Action must be graduate or drop.';
    END IF;

    IF p_action = 'graduate' THEN
        UPDATE students
        SET isGraduated = 1,
            isDropped = 0,
            isEnroll = 0,
            isActive = 0,
            dateGraduated = CURRENT_TIMESTAMP,
            droppedAt = NULL
        WHERE studentID = p_student_id AND dateDeleted IS NULL;
    ELSE
        UPDATE students
        SET isGraduated = 0,
            isDropped = 1,
            isEnroll = 0,
            isActive = 0,
            dateGraduated = NULL,
            droppedAt = CURRENT_TIMESTAMP
        WHERE studentID = p_student_id AND dateDeleted IS NULL;
    END IF;
END$$

-- Prevent hard deletes on core tables; app uses soft delete via dateDeleted.
DROP TRIGGER IF EXISTS `trg_block_delete_acad_years`$$
CREATE TRIGGER `trg_block_delete_acad_years`
BEFORE DELETE ON `acad_years`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Hard delete is not allowed. Use soft delete via dateDeleted.';
END$$

DROP TRIGGER IF EXISTS `trg_block_delete_programs`$$
CREATE TRIGGER `trg_block_delete_programs`
BEFORE DELETE ON `programs`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Hard delete is not allowed. Use soft delete via dateDeleted.';
END$$

DROP TRIGGER IF EXISTS `trg_block_delete_sections`$$
CREATE TRIGGER `trg_block_delete_sections`
BEFORE DELETE ON `sections`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Hard delete is not allowed. Use soft delete via dateDeleted.';
END$$

DROP TRIGGER IF EXISTS `trg_block_delete_section_subjects`$$
CREATE TRIGGER `trg_block_delete_section_subjects`
BEFORE DELETE ON `section_subjects`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Hard delete is not allowed. Use soft delete via dateDeleted.';
END$$

DROP TRIGGER IF EXISTS `trg_block_delete_subjects`$$
CREATE TRIGGER `trg_block_delete_subjects`
BEFORE DELETE ON `subjects`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Hard delete is not allowed. Use soft delete via dateDeleted.';
END$$

DROP TRIGGER IF EXISTS `trg_block_delete_users`$$
CREATE TRIGGER `trg_block_delete_users`
BEFORE DELETE ON `users`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Hard delete is not allowed. Use soft delete via dateDeleted.';
END$$

DROP TRIGGER IF EXISTS `trg_block_delete_students`$$
CREATE TRIGGER `trg_block_delete_students`
BEFORE DELETE ON `students`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Hard delete is not allowed. Use soft delete via dateDeleted.';
END$$

DROP TRIGGER IF EXISTS `trg_block_delete_enrollments`$$
CREATE TRIGGER `trg_block_delete_enrollments`
BEFORE DELETE ON `enrollments`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Hard delete is not allowed. Use soft delete via dateDeleted.';
END$$

DROP TRIGGER IF EXISTS `trg_block_delete_grades`$$
CREATE TRIGGER `trg_block_delete_grades`
BEFORE DELETE ON `grades`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Hard delete is not allowed. Use soft delete via dateDeleted.';
END$$

DROP TRIGGER IF EXISTS `trg_block_delete_gwa_records`$$
CREATE TRIGGER `trg_block_delete_gwa_records`
BEFORE DELETE ON `gwa_records`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Hard delete is not allowed. Use soft delete via dateDeleted.';
END$$

DROP TRIGGER IF EXISTS `trg_block_delete_ranking`$$
CREATE TRIGGER `trg_block_delete_ranking`
BEFORE DELETE ON `ranking`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Hard delete is not allowed. Use soft delete via dateDeleted.';
END$$

DROP TRIGGER IF EXISTS `trg_block_delete_notifications`$$
CREATE TRIGGER `trg_block_delete_notifications`
BEFORE DELETE ON `notifications`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Hard delete is not allowed. Use soft delete via dateDeleted.';
END$$

-- Protect student status consistency.
DROP TRIGGER IF EXISTS `trg_students_status_guard`$$
CREATE TRIGGER `trg_students_status_guard`
BEFORE UPDATE ON `students`
FOR EACH ROW
BEGIN
    IF NEW.yearLevel < 1 OR NEW.yearLevel > 4 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'yearLevel must be between 1 and 5.';
    END IF;

    IF NEW.isGraduated = 1 THEN
        SET NEW.isEnroll = 0;
        SET NEW.isActive = 0;
        SET NEW.isDropped = 0;
        SET NEW.droppedAt = NULL;
        IF NEW.dateGraduated IS NULL THEN
            SET NEW.dateGraduated = CURRENT_TIMESTAMP;
        END IF;
    END IF;

    IF NEW.isDropped = 1 THEN
        SET NEW.isEnroll = 0;
        SET NEW.isActive = 0;
        SET NEW.isGraduated = 0;
        SET NEW.dateGraduated = NULL;
        IF NEW.droppedAt IS NULL THEN
            SET NEW.droppedAt = CURRENT_TIMESTAMP;
        END IF;
    END IF;
END$$

-- Validate grade values and populate approval timestamps.
DROP TRIGGER IF EXISTS `trg_grades_status_guard`$$
CREATE TRIGGER `trg_grades_status_guard`
BEFORE UPDATE ON `grades`
FOR EACH ROW
BEGIN
    IF NEW.midterm IS NOT NULL AND (NEW.midterm < 0 OR NEW.midterm > 100) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'midterm must be between 0 and 100.';
    END IF;

    IF NEW.finals IS NOT NULL AND (NEW.finals < 0 OR NEW.finals > 100) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'finals must be between 0 and 100.';
    END IF;

    IF NEW.computedGrade IS NOT NULL AND (NEW.computedGrade < 0 OR NEW.computedGrade > 100) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'computedGrade must be between 0 and 100.';
    END IF;

    IF NEW.gradeEquivalent IS NOT NULL AND (NEW.gradeEquivalent < 0 OR NEW.gradeEquivalent > 5) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'gradeEquivalent must be between 0 and 5.';
    END IF;

    IF NEW.status = 'Submitted' AND OLD.status <> 'Submitted' AND NEW.submittedAt IS NULL THEN
        SET NEW.submittedAt = CURRENT_TIMESTAMP;
    END IF;

    IF NEW.status = 'Approved' AND OLD.status <> 'Approved' AND NEW.approvedAt IS NULL THEN
        SET NEW.approvedAt = CURRENT_TIMESTAMP;
    END IF;
END$$

-- Create audit trail entries for grade changes.
DROP TRIGGER IF EXISTS `trg_grades_audit_insert`$$
CREATE TRIGGER `trg_grades_audit_insert`
AFTER INSERT ON `grades`
FOR EACH ROW
BEGIN
    INSERT INTO `grade_audit_log` (
        `gradeID`, `actionBy`, `actionRole`, `action`, `oldValue`, `newValue`, `notes`, `actionAt`
    ) VALUES (
        NEW.gradeID,
        COALESCE(NEW.teacherID, 0),
        'Teacher',
        'Encoded',
        JSON_OBJECT(),
        JSON_OBJECT(
            'gradeID', NEW.gradeID,
            'studentID', NEW.studentID,
            'subjectID', NEW.subjectID,
            'status', NEW.status,
            'computedGrade', NEW.computedGrade,
            'gradeEquivalent', NEW.gradeEquivalent
        ),
        'Auto-audited grade creation.',
        CURRENT_TIMESTAMP
    );
END$$

DROP TRIGGER IF EXISTS `trg_grades_audit_update`$$
CREATE TRIGGER `trg_grades_audit_update`
AFTER UPDATE ON `grades`
FOR EACH ROW
BEGIN
    INSERT INTO `grade_audit_log` (
        `gradeID`, `actionBy`, `actionRole`, `action`, `oldValue`, `newValue`, `notes`, `actionAt`
    ) VALUES (
        NEW.gradeID,
        COALESCE(NEW.teacherID, 0),
        CASE
            WHEN NEW.approvedByID IS NOT NULL THEN 'Registrar'
            WHEN NEW.teacherID IS NOT NULL THEN 'Teacher'
            ELSE 'Admin'
        END,
        CASE
            WHEN NEW.status <> OLD.status THEN NEW.status
            ELSE 'Updated'
        END,
        JSON_OBJECT(
            'status', OLD.status,
            'computedGrade', OLD.computedGrade,
            'gradeEquivalent', OLD.gradeEquivalent,
            'approvedAt', OLD.approvedAt,
            'submittedAt', OLD.submittedAt
        ),
        JSON_OBJECT(
            'status', NEW.status,
            'computedGrade', NEW.computedGrade,
            'gradeEquivalent', NEW.gradeEquivalent,
            'approvedAt', NEW.approvedAt,
            'submittedAt', NEW.submittedAt
        ),
        'Auto-audited grade update.',
        CURRENT_TIMESTAMP
    );
END$$

DELIMITER ;

-- Optional integrity constraints for safer data values.
ALTER TABLE `subjects`
  ADD CONSTRAINT `chk_subject_units_positive` CHECK (`units` > 0);

ALTER TABLE `section_subjects`
  ADD CONSTRAINT `chk_secsub_semester` CHECK (`semester` IN (1,2));

ALTER TABLE `enrollments`
  ADD CONSTRAINT `chk_enrollment_semester` CHECK (`semester` IN (1,2));

ALTER TABLE `students`
    ADD CONSTRAINT `chk_student_year_level` CHECK (`yearLevel` BETWEEN 1 AND 5);

ALTER TABLE `grades`
  ADD CONSTRAINT `chk_grade_equiv_range` CHECK (`gradeEquivalent` IS NULL OR (`gradeEquivalent` BETWEEN 0 AND 5));

ALTER TABLE `notifications`
  ADD INDEX `idx_notifications_recipient_read` (`recipientID`, `recipientType`, `isRead`);

ALTER TABLE `grades`
  ADD INDEX `idx_grades_status_deleted` (`status`, `dateDeleted`);

ALTER TABLE `students`
  ADD INDEX `idx_students_status_deleted` (`isGraduated`, `isDropped`, `isActive`, `dateDeleted`);
