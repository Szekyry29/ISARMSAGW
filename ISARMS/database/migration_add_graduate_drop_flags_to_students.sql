-- Migration: Add graduation and dropped status fields to students table
-- This migration supports registrar graduate/drop features.

ALTER TABLE students
  ADD COLUMN isGraduated TINYINT(1) NOT NULL DEFAULT 0 AFTER isActive,
  ADD COLUMN isDropped TINYINT(1) NOT NULL DEFAULT 0 AFTER isGraduated,
  ADD COLUMN dateGraduated TIMESTAMP NULL DEFAULT NULL AFTER isDropped,
  ADD COLUMN droppedAt TIMESTAMP NULL DEFAULT NULL AFTER dateGraduated;
