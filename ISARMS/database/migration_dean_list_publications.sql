CREATE TABLE IF NOT EXISTS `dean_list_publications` (
  `publicationID` INT(11) NOT NULL AUTO_INCREMENT,
  `acadYearID`    INT(11) NOT NULL,
  `semester`      TINYINT(1) NOT NULL,
  `publishedByID` INT(11) NOT NULL,
  `publishedAt`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`publicationID`),
  UNIQUE KEY `uq_dean_list_period` (`acadYearID`,`semester`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;