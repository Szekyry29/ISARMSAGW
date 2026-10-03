-- Admin grade control and notification recipients
-- Run after the base schema and existing migrations.

ALTER TABLE `notifications`
MODIFY COLUMN `recipientType` ENUM('Student','User','Registrar','Admin') NOT NULL;

-- Existing grade_audit_log already supports the Admin action role.
-- Teacher requests/letter references use grades.teacherRemarks and grades.registrarNotes.
