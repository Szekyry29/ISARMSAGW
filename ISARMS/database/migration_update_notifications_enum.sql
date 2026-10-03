-- Migration: Update notifications recipientType enum to include all roles
-- Run this in phpMyAdmin or MySQL command line

ALTER TABLE `notifications`
MODIFY COLUMN `recipientType` ENUM('Student','User','Registrar','Admin') NOT NULL;

-- Update existing 'User' notifications to proper role types if needed
-- (This would require checking user roles, but for now we'll keep existing data)