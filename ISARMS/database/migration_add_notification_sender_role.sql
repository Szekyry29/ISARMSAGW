USE `isarms_db`;

ALTER TABLE `notifications`
  ADD COLUMN IF NOT EXISTS `senderRole` VARCHAR(20) DEFAULT NULL AFTER `type`;