-- Migration: Add isActive field to programs table
-- Add archive/inactive functionality to Programs

ALTER TABLE programs ADD COLUMN isActive TINYINT(1) NOT NULL DEFAULT 1 AFTER programDesc;

-- Update existing programs to be active
UPDATE programs SET isActive = 1 WHERE isActive IS NULL;