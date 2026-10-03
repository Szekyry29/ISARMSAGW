CREATE TABLE IF NOT EXISTS `subject_gwa_rules` (
  `ruleID`       INT(11) NOT NULL AUTO_INCREMENT,
  `acadYearID`   INT(11) NOT NULL,
  `subjectID`    INT(11) NOT NULL,
  `includeInGwa` TINYINT(1) NOT NULL DEFAULT 1,
  `updatedByID`  INT(11) DEFAULT NULL,
  `updatedAt`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`ruleID`),
  UNIQUE KEY `uq_subject_gwa_rule` (`acadYearID`,`subjectID`),
  KEY `idx_subject_gwa_subject` (`subjectID`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;