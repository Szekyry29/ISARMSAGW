-- Add sectionID column to students table
-- This allows assigning a default section to students for enrollment purposes

ALTER TABLE `students` 
ADD COLUMN IF NOT EXISTS `sectionID` INT(11) DEFAULT NULL AFTER `programID`,
ADD INDEX `idx_students_section` (`sectionID`);

-- Add foreign key constraint (optional - will fail gracefully if sections table doesn't exist)
-- ALTER TABLE `students` 
-- ADD CONSTRAINT `fk_students_section` FOREIGN KEY (`sectionID`) REFERENCES `sections`(`sectionID`);