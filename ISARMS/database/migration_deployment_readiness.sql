-- Additive schema required by current enrollment, program, and notification flows.
USE `isarms_db`;

ALTER TABLE `programs`
  ADD COLUMN IF NOT EXISTS `isActive` TINYINT(1) NOT NULL DEFAULT 1 AFTER `programDesc`;

ALTER TABLE `subjects`
  ADD COLUMN IF NOT EXISTS `availableFor` ENUM('all','regular_only','irregular_only') NOT NULL DEFAULT 'all' AFTER `semester`;

ALTER TABLE `notifications`
  MODIFY COLUMN `recipientType` ENUM('Student','User','Registrar','Admin') NOT NULL;

COMMIT;
