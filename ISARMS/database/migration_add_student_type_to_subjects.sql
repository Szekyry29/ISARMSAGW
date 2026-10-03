-- Migration: Add student type availability to subjects
-- Allows controlling which subjects are available for regular vs irregular students

ALTER TABLE `subjects` ADD COLUMN `availableFor` ENUM('all', 'regular_only', 'irregular_only') NOT NULL DEFAULT 'all' COMMENT 'all = both regular and irregular, regular_only = only regular students, irregular_only = only irregular students' AFTER `semester`;
