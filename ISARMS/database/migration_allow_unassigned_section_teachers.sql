-- Allow subject offerings to be created before teachers are assigned.
ALTER TABLE `section_subjects`
  MODIFY `teacherID` INT(11) NULL DEFAULT NULL COMMENT 'Teacher for this specific subject';