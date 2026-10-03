-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3308
-- Generation Time: Apr 24, 2026 at 10:00 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `isarms_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `acad_years`
--

CREATE TABLE `acad_years` (
  `acadYearID` int(11) NOT NULL,
  `academicYear` varchar(20) NOT NULL COMMENT 'e.g. 2024-2025',
  `acadDesc` varchar(100) DEFAULT NULL,
  `status` enum('Active','Inactive','Archived') NOT NULL DEFAULT 'Inactive',
  `dateCreated` timestamp NOT NULL DEFAULT current_timestamp(),
  `dateDeleted` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `acad_years`
--

INSERT INTO `acad_years` (`acadYearID`, `academicYear`, `acadDesc`, `status`, `dateCreated`, `dateDeleted`) VALUES
(1, '2023-2024', 'Academic Year 2023-2024', 'Inactive', '2026-04-22 05:34:42', NULL),
(2, '2024-2025', 'Academic Year 2024-2025', 'Inactive', '2026-04-22 05:34:42', NULL),
(3, '2025-2026', 'Academic Year 2025-2026', 'Active', '2026-04-22 05:34:42', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `archiving`
--

CREATE TABLE `archiving` (
  `archiveID` int(11) NOT NULL,
  `drop` tinyint(1) DEFAULT 0,
  `graduate` tinyint(1) DEFAULT 0,
  `dateArchived` date DEFAULT NULL,
  `dateDeleted` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `enrollments`
--

CREATE TABLE `enrollments` (
  `enrollmentID` int(11) NOT NULL,
  `dateofEnroll` date NOT NULL,
  `acadYear` varchar(20) NOT NULL,
  `semester` tinyint(1) NOT NULL COMMENT '1=First, 2=Second',
  `studentID` int(11) NOT NULL,
  `subjectID` int(11) NOT NULL,
  `programID` int(11) NOT NULL,
  `sectionID` int(11) NOT NULL,
  `acadYearID` int(11) NOT NULL,
  `status` enum('Active','Dropped','Withdrawn') NOT NULL DEFAULT 'Active',
  `dateCreated` timestamp NOT NULL DEFAULT current_timestamp(),
  `dateDeleted` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `enrollments`
--

INSERT INTO `enrollments` (`enrollmentID`, `dateofEnroll`, `acadYear`, `semester`, `studentID`, `subjectID`, `programID`, `sectionID`, `acadYearID`, `status`, `dateCreated`, `dateDeleted`) VALUES
(1, '2026-04-24', '2025-2026', 2, 2, 44, 1, 13, 3, 'Active', '2026-04-24 07:51:40', NULL),
(2, '2026-04-24', '2025-2026', 2, 2, 45, 1, 13, 3, 'Active', '2026-04-24 07:51:40', NULL),
(3, '2026-04-24', '2025-2026', 2, 2, 57, 1, 13, 3, 'Active', '2026-04-24 07:51:40', NULL),
(4, '2026-04-24', '2025-2026', 2, 2, 58, 1, 13, 3, 'Active', '2026-04-24 07:51:40', NULL),
(5, '2026-04-24', '2025-2026', 2, 2, 59, 1, 13, 3, 'Active', '2026-04-24 07:51:40', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `grades`
--

CREATE TABLE `grades` (
  `gradeID` int(11) NOT NULL,
  `enrollmentID` int(11) NOT NULL,
  `studentID` int(11) NOT NULL,
  `subjectID` int(11) NOT NULL,
  `acadYearID` int(11) NOT NULL,
  `midterm` decimal(5,2) DEFAULT NULL,
  `finals` decimal(5,2) DEFAULT NULL,
  `computedGrade` decimal(5,2) DEFAULT NULL COMMENT 'Auto-computed',
  `gradeEquivalent` decimal(3,2) DEFAULT NULL COMMENT 'NEUST scale: 1.00–5.00',
  `postedGrade` decimal(3,2) DEFAULT NULL COMMENT 'Registrar-approved final',
  `remarks` enum('Passed','Failed','Inc','Dropped','Withdrawn') DEFAULT NULL,
  `status` enum('Pending','Submitted','Approved','Flagged','Returned') NOT NULL DEFAULT 'Pending',
  `teacherID` int(11) NOT NULL,
  `encodedAt` timestamp NULL DEFAULT NULL,
  `submittedAt` timestamp NULL DEFAULT NULL,
  `approvedAt` timestamp NULL DEFAULT NULL,
  `approvedByID` int(11) DEFAULT NULL COMMENT 'Registrar userID',
  `teacherRemarks` text DEFAULT NULL,
  `registrarNotes` text DEFAULT NULL,
  `dateCreated` timestamp NOT NULL DEFAULT current_timestamp(),
  `dateDeleted` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `grade_audit_log`
--

CREATE TABLE `grade_audit_log` (
  `logID` int(11) NOT NULL,
  `gradeID` int(11) NOT NULL,
  `actionBy` int(11) NOT NULL COMMENT 'userID or studentID',
  `actionRole` enum('Teacher','Registrar','Admin') NOT NULL,
  `action` varchar(50) NOT NULL COMMENT 'Encoded, Submitted, Approved, Flagged, Returned',
  `oldValue` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`oldValue`)),
  `newValue` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`newValue`)),
  `notes` text DEFAULT NULL,
  `actionAt` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `gwa_records`
--

CREATE TABLE `gwa_records` (
  `gwaID` int(11) NOT NULL,
  `studentID` int(11) NOT NULL,
  `acadYearID` int(11) NOT NULL,
  `semester` tinyint(1) DEFAULT NULL COMMENT 'NULL = cumulative',
  `totalUnits` decimal(5,1) NOT NULL DEFAULT 0.0,
  `weightedSum` decimal(8,3) NOT NULL DEFAULT 0.000,
  `gwaValue` decimal(5,4) NOT NULL DEFAULT 0.0000 COMMENT '4 decimal precision',
  `latinHonor` enum('Summa Cum Laude','Magna Cum Laude','Cum Laude','Dean''s List','None') DEFAULT 'None',
  `validationStatus` enum('Pending','Validated','Flagged') NOT NULL DEFAULT 'Pending',
  `validatedByID` int(11) DEFAULT NULL,
  `validatedAt` timestamp NULL DEFAULT NULL,
  `computedAt` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `notifID` int(11) NOT NULL,
  `recipientID` int(11) NOT NULL,
  `recipientType` enum('Student','User') NOT NULL,
  `title` varchar(150) NOT NULL,
  `message` text NOT NULL,
  `type` enum('Grade','GWA','Honors','System','Approval') NOT NULL,
  `isRead` tinyint(1) NOT NULL DEFAULT 0,
  `createdAt` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `programs`
--

CREATE TABLE `programs` (
  `programID` int(11) NOT NULL,
  `programCode` varchar(20) NOT NULL COMMENT 'e.g. BSIT, BSCS',
  `programName` varchar(150) NOT NULL,
  `programDesc` text DEFAULT NULL,
  `dateCreated` timestamp NOT NULL DEFAULT current_timestamp(),
  `dateDeleted` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `programs`
--

INSERT INTO `programs` (`programID`, `programCode`, `programName`, `programDesc`, `dateCreated`, `dateDeleted`) VALUES
(1, 'BSIT', 'Bachelor of Science in Information Technology', 'Covers software development, networking, and IT management', '2026-04-22 05:34:42', NULL),
(4, 'BEED', 'Bachelor of Elementary Education', 'Teacher education for elementary level', '2026-04-22 05:34:42', NULL),
(6, 'BSBA', 'Bachelor of Science in Business Administration', 'Business and management studies', '2026-04-22 05:34:42', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `ranking`
--

CREATE TABLE `ranking` (
  `rankingID` int(11) NOT NULL,
  `badge` varchar(100) DEFAULT NULL,
  `studentID` int(11) NOT NULL,
  `gradeID` int(11) NOT NULL,
  `dateCreated` timestamp NOT NULL DEFAULT current_timestamp(),
  `dateDeleted` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sections`
--

CREATE TABLE `sections` (
  `sectionID` int(11) NOT NULL,
  `sectionName` varchar(50) NOT NULL,
  `sectionDesc` varchar(200) DEFAULT NULL,
  `programID` int(11) NOT NULL,
  `acadYearID` int(11) NOT NULL,
  `userID` int(11) DEFAULT NULL COMMENT 'Deprecated: use section_subjects',
  `dateCreated` timestamp NOT NULL DEFAULT current_timestamp(),
  `dateDeleted` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sections`
--

INSERT INTO `sections` (`sectionID`, `sectionName`, `sectionDesc`, `programID`, `acadYearID`, `userID`, `dateCreated`, `dateDeleted`) VALUES
(5, 'BSIT 1-A', '', 1, 3, NULL, '2026-04-24 06:04:53', NULL),
(6, 'BSIT 1-B', '', 1, 3, NULL, '2026-04-24 07:06:03', NULL),
(7, 'BSIT 3-C', '', 1, 3, NULL, '2026-04-24 07:06:16', NULL),
(8, 'BSIT 2-A', '', 1, 3, NULL, '2026-04-24 07:06:32', NULL),
(9, 'BSIT 2-B', '', 1, 3, NULL, '2026-04-24 07:06:44', NULL),
(10, 'BSIT 2-C', '', 1, 3, NULL, '2026-04-24 07:07:07', NULL),
(11, 'BSIT 1-C', '', 1, 3, NULL, '2026-04-24 07:07:22', NULL),
(12, 'BSIT 3-A', '', 1, 3, NULL, '2026-04-24 07:07:42', NULL),
(13, 'BSIT 3-B', '', 1, 3, NULL, '2026-04-24 07:08:19', NULL),
(14, 'BSIT 4-B', '', 1, 3, NULL, '2026-04-24 07:09:13', NULL),
(15, 'BSIT 4-A', '', 1, 3, NULL, '2026-04-24 07:09:41', NULL),
(16, 'BSIT 4-C', '', 1, 3, NULL, '2026-04-24 07:10:01', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `section_subjects`
--

CREATE TABLE `section_subjects` (
  `secSubID` int(11) NOT NULL,
  `sectionID` int(11) NOT NULL,
  `subjectID` int(11) NOT NULL,
  `teacherID` int(11) DEFAULT NULL COMMENT 'Teacher for THIS subject in THIS section',
  `semester` tinyint(1) NOT NULL COMMENT '1=First, 2=Second',
  `acadYearID` int(11) NOT NULL,
  `schedule` varchar(100) DEFAULT NULL COMMENT 'e.g. MWF 8:00-9:00 AM',
  `room` varchar(50) DEFAULT NULL,
  `dateCreated` timestamp NOT NULL DEFAULT current_timestamp(),
  `dateDeleted` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `section_subjects`
--

INSERT INTO `section_subjects` (`secSubID`, `sectionID`, `subjectID`, `teacherID`, `semester`, `acadYearID`, `schedule`, `room`, `dateCreated`, `dateDeleted`) VALUES
(1, 5, 6, 4, 1, 3, '', '', '2026-04-24 06:06:08', '2026-04-24 07:04:34'),
(2, 13, 44, 16, 2, 3, '', '', '2026-04-24 07:48:16', NULL),
(3, 13, 45, 7, 2, 3, '', '', '2026-04-24 07:48:48', NULL),
(4, 13, 57, 8, 2, 3, '', '', '2026-04-24 07:49:17', NULL),
(5, 13, 58, 27, 2, 3, '', '', '2026-04-24 07:49:46', NULL),
(6, 13, 59, 27, 2, 3, '', '', '2026-04-24 07:50:07', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `students`
--

CREATE TABLE `students` (
  `studentID` int(11) NOT NULL,
  `studentNo` varchar(20) NOT NULL COMMENT 'Student Number e.g. 2021-00001',
  `archiveID` int(11) DEFAULT NULL,
  `fName` varchar(60) NOT NULL,
  `mName` varchar(60) DEFAULT NULL,
  `lName` varchar(60) NOT NULL,
  `extName` varchar(10) DEFAULT NULL,
  `dateofBirth` date NOT NULL,
  `sex` enum('Male','Female') NOT NULL,
  `permanentAddress` text DEFAULT NULL,
  `currentAddress` text DEFAULT NULL,
  `yearLevel` tinyint(1) NOT NULL DEFAULT 1,
  `isEnroll` tinyint(1) NOT NULL DEFAULT 0,
  `programID` int(11) NOT NULL,
  `email` varchar(120) NOT NULL COMMENT 'Student login email',
  `password` varchar(255) NOT NULL COMMENT 'bcrypt hashed',
  `profilePic` varchar(255) DEFAULT NULL,
  `isActive` tinyint(1) NOT NULL DEFAULT 1,
  `isGraduated` tinyint(1) NOT NULL DEFAULT 0,
  `isDropped` tinyint(1) NOT NULL DEFAULT 0,
  `dateGraduated` timestamp NULL DEFAULT NULL,
  `droppedAt` timestamp NULL DEFAULT NULL,
  `lastLogin` timestamp NULL DEFAULT NULL,
  `dateCreated` timestamp NOT NULL DEFAULT current_timestamp(),
  `dateDeleted` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `students`
--

INSERT INTO `students` (`studentID`, `studentNo`, `archiveID`, `fName`, `mName`, `lName`, `extName`, `dateofBirth`, `sex`, `permanentAddress`, `currentAddress`, `yearLevel`, `isEnroll`, `programID`, `email`, `password`, `profilePic`, `isActive`, `lastLogin`, `dateCreated`, `dateDeleted`) VALUES
(2, '2023-00129', NULL, 'Gliwmaby', 'Salvador', 'Pascual', '', '2004-11-08', 'Male', NULL, NULL, 3, 1, 1, 'mabypascual517@gmail.com', '$2y$12$Vnxthj9KtJ4cUZ4d6ZxNlOm0OmnrRQDJLd2ER67rLYdhMwU54fsw6', NULL, 1, NULL, '2026-04-24 03:41:30', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `subjects`
--

CREATE TABLE `subjects` (
  `subjectID` int(11) NOT NULL,
  `subjectCode` varchar(20) NOT NULL,
  `subjectName` varchar(150) NOT NULL,
  `units` decimal(4,1) NOT NULL DEFAULT 3.0,
  `programID` int(11) DEFAULT NULL,
  `yearLevel` tinyint(1) DEFAULT NULL COMMENT '1=1st, 2=2nd, 3=3rd, 4=4th',
  `semester` tinyint(1) DEFAULT NULL COMMENT '1=First, 2=Second',
  `dateCreated` timestamp NOT NULL DEFAULT current_timestamp(),
  `dateDeleted` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `subjects`
--

INSERT INTO `subjects` (`subjectID`, `subjectCode`, `subjectName`, `units`, `programID`, `yearLevel`, `semester`, `dateCreated`, `dateDeleted`) VALUES
(4, 'ITCAP01', 'CAPSTONE', 3.0, 1, 3, 2, '2026-04-24 03:42:37', '2026-04-24 03:52:30'),
(5, 'CC101', 'Computer', 3.0, 1, 3, 2, '2026-04-24 04:13:37', '2026-04-24 04:14:08'),
(6, 'CC-100', 'Introduction to Computing', 3.0, 1, 1, 1, '2026-04-24 04:59:17', NULL),
(7, 'CC-101', 'Computer Programming 1,Fundamentals', 3.0, 1, 1, 1, '2026-04-24 04:59:55', NULL),
(8, 'IT-NET01', 'Networking 1,Fundamentals', 3.0, 1, 1, 1, '2026-04-24 05:00:33', NULL),
(9, 'GE-04', 'Mathematics in Modern World', 3.0, NULL, NULL, NULL, '2026-04-24 05:01:25', NULL),
(10, 'GE-05', 'Purposive Communication', 3.0, NULL, NULL, NULL, '2026-04-24 05:02:08', NULL),
(11, 'FIL-1', 'Konstekswalisadong Komunikasyon sa Filipino (KOMPIL)', 3.0, NULL, NULL, NULL, '2026-04-24 05:02:44', NULL),
(12, 'GE-07', 'Science, Technology ans Society', 3.0, NULL, NULL, NULL, '2026-04-24 05:04:32', NULL),
(13, 'PATHFIT-1', 'Movement,Competency, Training', 3.0, NULL, NULL, 1, '2026-04-24 05:06:17', NULL),
(14, 'NSTP-1', 'National Training Program 1', 3.0, NULL, NULL, 1, '2026-04-24 05:07:30', NULL),
(15, 'CC-102', 'Computer Programming 2,Intermediate', 3.0, 1, 1, 2, '2026-04-24 05:10:27', NULL),
(16, 'IT-NET02', 'Networking 2, Advanced', 3.0, 1, 1, 2, '2026-04-24 05:11:02', NULL),
(17, 'IT-WS01', 'Web System and Technologies 1', 3.0, 1, 1, 2, '2026-04-24 05:11:44', NULL),
(18, 'GE-01', 'Understanding the Self', 3.0, NULL, NULL, NULL, '2026-04-24 05:12:29', NULL),
(19, 'GE-02', 'Mga Babasahin Hinggil sa Kasaysayan ng Pilipinas', 3.0, NULL, NULL, NULL, '2026-04-24 05:13:12', NULL),
(20, 'FIL-2', 'Filipino sa ibat-ibang Disiplina (FILDIS)', 3.0, NULL, NULL, NULL, '2026-04-24 05:13:43', NULL),
(21, 'PATHFIT-2', 'Exercise-based Fitness Activity', 3.0, NULL, NULL, 2, '2026-04-24 05:14:48', NULL),
(22, 'NSTP-2', 'National Training Program 2', 3.0, NULL, NULL, 2, '2026-04-24 05:15:24', NULL),
(23, 'CC-103', 'Data Structures and Algorithm', 3.0, 1, 2, 1, '2026-04-24 05:17:34', NULL),
(24, 'IT-PF01', 'Object-Oriented Programming 1', 3.0, 1, 2, 1, '2026-04-24 05:18:27', NULL),
(25, 'IT-WS02', 'Web System and Technologies 2', 3.0, 1, 2, 1, '2026-04-24 05:19:58', NULL),
(26, 'IT-MS01', 'Discrete Mathematics', 3.0, 1, 2, 1, '2026-04-24 05:20:33', NULL),
(27, 'GE-06', 'Art Appreciation', 3.0, NULL, NULL, NULL, '2026-04-24 05:21:14', NULL),
(28, 'GE-08', 'Ethics', 3.0, NULL, NULL, NULL, '2026-04-24 05:21:35', NULL),
(29, 'FIL-3', 'Dalumat Ng/Sa Filipino(Dalumat Fil', 3.0, NULL, NULL, NULL, '2026-04-24 05:22:07', NULL),
(30, 'PATHFIT-3', 'Choice of Dance,Sports,Martial arts, Group Exercise, Outdoor and Adventure Activities', 3.0, NULL, NULL, NULL, '2026-04-24 05:24:59', NULL),
(31, 'CC-104', 'Information Management', 3.0, 1, 2, 2, '2026-04-24 05:25:57', NULL),
(32, 'CC-105', 'Applications Development and Emerging Technologies', 3.0, 1, 2, 2, '2026-04-24 05:26:48', NULL),
(33, 'IT-PF02', 'Object-Oriented Programming 2', 3.0, 1, 2, 2, '2026-04-24 05:27:37', NULL),
(34, 'IT-HCI01', 'Introduction to Human Computer Interaction', 3.0, 1, 2, 2, '2026-04-24 05:29:37', NULL),
(35, 'IT-MS02', 'Quantitative Methods', 3.0, 1, 2, 2, '2026-04-24 05:30:22', NULL),
(36, 'GE-03', 'The Contemporary World', 3.0, NULL, NULL, NULL, '2026-04-24 05:31:02', NULL),
(37, 'GE-09', 'The Life and Works of Rizal', 3.0, NULL, NULL, NULL, '2026-04-24 05:31:35', NULL),
(38, 'IT-IM01', 'Advanced Database System', 3.0, 1, 3, 1, '2026-04-24 05:32:25', NULL),
(39, 'IT-IPT01', 'Integrative Programming and Technologies 1', 3.0, 1, 3, 1, '2026-04-24 05:37:14', NULL),
(40, 'IT-SA01', 'Systems Administration and Maintenance', 3.0, 1, 3, 1, '2026-04-24 05:38:01', NULL),
(41, 'IT-IAS01', 'Information Assurance and Security', 3.0, 1, 3, 1, '2026-04-24 05:39:20', NULL),
(42, 'IT-IPT02', 'Mobile Application Development', 3.0, 1, 3, 1, '2026-04-24 05:40:06', NULL),
(43, 'ELP', 'English Language Proficiency', 3.0, NULL, NULL, NULL, '2026-04-24 05:41:29', NULL),
(44, 'IT-IAS02', 'Information Assurance and Security 2', 3.0, 1, 3, 2, '2026-04-24 05:42:32', NULL),
(45, 'IT-CAP01', 'CAPSTONE PROJECT AND RESEARCH 1', 3.0, 1, 3, 2, '2026-04-24 05:43:48', NULL),
(46, 'IT-IMS02', 'Database System Technology 1(SQL)', 3.0, 1, 3, 2, '2026-04-24 05:44:48', NULL),
(47, 'IT-IMS03', 'Database System Technology 2(noSQL)', 3.0, 1, 3, 2, '2026-04-24 05:45:33', NULL),
(48, 'IT-IMS04', 'Database Security', 3.0, 1, 3, 2, '2026-04-24 05:46:01', NULL),
(49, 'IT-SIA01', 'System Integration and Architecture', 3.0, 1, 4, 1, '2026-04-24 05:49:20', NULL),
(50, 'IT-SP01', 'Social and Professional Issues', 3.0, 1, 4, 1, '2026-04-24 05:50:10', NULL),
(51, 'IT-CAP02', 'CAPSTONE PROJECT AND RESEARCH 2', 3.0, 1, 4, 2, '2026-04-24 05:50:49', NULL),
(52, 'IT-SW01', 'Seminars and Workshops', 3.0, 1, 4, 1, '2026-04-24 05:51:29', NULL),
(53, 'IT-IM05', 'Data warehousing and data mining', 3.0, 1, 4, 1, '2026-04-24 05:52:30', NULL),
(54, 'IT-IMS06', 'Geographic Information System', 3.0, 1, 4, 1, '2026-04-24 05:55:19', NULL),
(55, 'IZN', 'International Academic MObility (In-Bound.Out-Bound) Equivalent 20 250 hrs,an Optional Subject', 0.5, 1, 4, 2, '2026-04-24 05:57:40', NULL),
(56, 'IT-OJT101', 'Practicum (OJT), 32hrs/week,min.486 hrs', 6.0, 1, 4, 2, '2026-04-24 05:58:45', NULL),
(57, 'IT-WSO3', 'Web System and Technologies 3', 3.0, 1, 3, 2, '2026-04-24 06:00:58', NULL),
(58, 'IT-WSO4', 'Web System Vulnerabilities', 3.0, 1, 3, 2, '2026-04-24 06:01:40', NULL),
(59, 'IT-WS05', 'Web Security', 3.0, 1, 3, 2, '2026-04-24 06:02:20', NULL),
(60, 'IT-WS06', 'Web Digital Meia', 3.0, 1, 4, 1, '2026-04-24 06:03:19', NULL),
(61, 'IT-WS07', 'Mobile Application Technology', 3.0, 1, 4, 1, '2026-04-24 06:04:26', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `userID` int(11) NOT NULL,
  `fName` varchar(60) NOT NULL,
  `mName` varchar(60) DEFAULT NULL,
  `lName` varchar(60) NOT NULL,
  `extName` varchar(10) DEFAULT NULL COMMENT 'Jr., Sr., III',
  `email` varchar(120) NOT NULL,
  `password` varchar(255) NOT NULL COMMENT 'bcrypt hashed',
  `roleName` enum('Admin','Teacher','Registrar','Student') NOT NULL,
  `profilePic` varchar(255) DEFAULT NULL,
  `programID` int(11) DEFAULT NULL COMMENT 'For teachers only',
  `isActive` tinyint(1) NOT NULL DEFAULT 1,
  `lastLogin` timestamp NULL DEFAULT NULL,
  `dateCreated` timestamp NOT NULL DEFAULT current_timestamp(),
  `dateDeleted` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`userID`, `fName`, `mName`, `lName`, `extName`, `email`, `password`, `roleName`, `profilePic`, `programID`, `isActive`, `lastLogin`, `dateCreated`, `dateDeleted`) VALUES
(1, 'System', '', 'Administrator', NULL, 'admin@neust.edu.ph', '$2y$12$H0cf4IR/6/qdURfib5EeGeqmNUweZ5cg18NCblS74uyB3AL7Z30TC', 'Admin', NULL, NULL, 1, '2026-04-24 07:02:14', '2026-04-22 05:34:42', NULL),
(2, 'Registrar', '', '1', '', 'registrar1@neust.edu.ph', '$2y$12$O034z1x39fywoPG1UvpYMuzRrQ/93QKRtCJKEJcSn.F3enAnOAHVe', 'Registrar', NULL, NULL, 1, '2026-04-24 03:10:06', '2026-04-22 05:36:26', NULL),
(4, 'teacherIT', '', 'IT', '', 'teacherit@neust.edu.ph', '$2y$12$uR7y22McY/pj5ZhVHTXes.80K.I.SiYlWMtjU1XEQ0wYsQ89KA3wm', 'Teacher', NULL, 1, 0, '2026-04-24 06:09:10', '2026-04-24 03:38:29', '2026-04-24 07:04:47'),
(5, 'teacherBA', '', 'BA', '', 'teacherba@neust.edu.ph', '$2y$12$GrA3m.L9bTpBTsbwJiFCVeJeYLoXX1kBEkSD1sOnqiNe1HhvfnyqC', 'Teacher', NULL, 6, 1, NULL, '2026-04-24 03:39:03', NULL),
(6, 'teacherBEED', '', 'BEED', '', 'teacherbeed@neust.edu.ph', '$2y$12$.B8CsW2odrk8X8.4wpGskeXNAdWdvRlws7FbgsiqUT4B692i2JW8y', 'Teacher', NULL, 4, 1, NULL, '2026-04-24 03:39:37', NULL),
(7, 'Berlin', '', 'Ramos', '', 'ramos@neust.edu.ph', '$2y$12$YZDvJx5rOVq5qtOsFogEp.C2gcsKl8wysvtr1fzI4DY67L0eHFjhS', 'Teacher', NULL, 1, 1, '2026-04-24 07:55:49', '2026-04-24 07:15:44', NULL),
(8, 'Rey John', '', 'Aguilar', '', 'aguilar@neust.edu.ph', '$2y$12$PRmq4G93/OJPM2tunoioMOc9b4qHewHW7c6nplOD3d8Rx1dDjEO7C', 'Teacher', NULL, 1, 1, NULL, '2026-04-24 07:17:14', NULL),
(9, 'Johnica', '', 'Alejandro', '', 'alejandro@edu.ph', '$2y$12$uSzhdhWrvWhLNIr3VnZpde.3aquzsOoVvHpdoEQm9ms7r64p/OVxi', 'Teacher', NULL, 1, 1, NULL, '2026-04-24 07:18:02', NULL),
(10, 'Dennis', '', 'Bacani', '', 'bacani@neust.edu.ph', '$2y$12$stb1NooEfMY4XVwexc6euO1bVj9GmHdN3KTsoW0c0VzQ7RzWLC3IC', 'Teacher', NULL, 1, 1, NULL, '2026-04-24 07:21:03', NULL),
(11, 'Maria Nica', '', 'Bautista', '', 'bautista@neust.edu.ph', '$2y$12$eBkxxKJvFMCV/yjjQws4rO9GnTtpnM0X1uwiuqLTAIDMhxzx7QLfi', 'Teacher', NULL, 1, 1, NULL, '2026-04-24 07:21:59', NULL),
(12, 'Joselito', '', 'Bugtong', '', 'joselito@neust.edu.ph', '$2y$12$wzpuWxwjagh0Zw9Anyzw6OqBLcUBSSsqVrlFCH1hP0Hlk1mMwuQ7O', 'Teacher', NULL, 1, 1, NULL, '2026-04-24 07:22:56', NULL),
(13, 'Jude', '', 'Cabana', '', 'cabana@neust.edu.ph', '$2y$12$FNeFB2sRq3HAYbnbH7skMekr7j.ML47qXc4sjahUMShRTS5U2G6AO', 'Teacher', NULL, 1, 1, NULL, '2026-04-24 07:23:46', NULL),
(14, 'Czarina', '', 'Castro', '', 'castro@neust.edu.ph', '$2y$12$0g/VyC2yBk6flKd/Tl3qEOxGEMEFljqBOHhB.GY40DsqVhHbnmTwm', 'Teacher', NULL, 1, 1, NULL, '2026-04-24 07:24:46', NULL),
(15, 'Gayle', '', 'De Jesus', '', 'dejesus@neust.edu.ph', '$2y$12$4dxjjqsp6vcwjvNKzD6M9e/pwGgTN5vS1rGQ5UIGxL.QFtA1lVKsm', 'Teacher', NULL, 1, 1, NULL, '2026-04-24 07:25:29', NULL),
(16, 'Paul Jordan', '', 'Eligino', '', 'eligino@neust.edu.ph', '$2y$12$uwwXFTenlyVXIRmVrwf7oOl3Q5P6GRXcwCNounUYmrROgKtHsAtB2', 'Teacher', NULL, 1, 1, NULL, '2026-04-24 07:26:14', NULL),
(17, 'Kathleen Anne', '', 'Esbieto', '', 'esbieto@neust.edu.ph', '$2y$12$SFgmfbyhBar7UqXIpTVIweZtUEgibgqKyHShpjmH28tjwcFvlpev6', 'Teacher', NULL, 1, 1, NULL, '2026-04-24 07:28:04', NULL),
(18, 'Mark Christian', '', 'Fernandez', '', 'fernandez@neust.edu.ph', '$2y$12$aqaO/gTBjs8w.ZKEkEYCZeh9XYsao5RgIzGoCwJm.ZQsLXjEyme.e', 'Teacher', NULL, 1, 1, NULL, '2026-04-24 07:28:54', NULL),
(19, 'Rosendo', '', 'Labrador', '', 'labrador@neust.edu.ph', '$2y$12$rdCJJuOINmThoymDqDZ8suENjcjp.31KxhqQ3eDMDpGogzYan//lW', 'Teacher', NULL, 1, 1, NULL, '2026-04-24 07:29:33', NULL),
(20, 'Jayson', '', 'Larioque', '', 'larioque@neust.edu.ph', '$2y$12$mCAGvihwuU8viB1Wh7MyFOCHy/8YakT4.B/PNiLnT2KdRf/rZ4jEy', 'Teacher', NULL, 1, 1, NULL, '2026-04-24 07:30:31', NULL),
(21, 'John Philip', '', 'Medina', '', 'medina@neust.edu.ph', '$2y$12$Lgy8WgM0hUhEHW0etKXfVO2BfypicXCAEHb9vsjbUHCMtIPfM9SA6', 'Teacher', NULL, 1, 1, NULL, '2026-04-24 07:31:26', NULL),
(22, 'Mark Edrian', '', 'Mico', '', 'mico@neust.edu.ph', '$2y$12$gVZCmAn5JYJbjbJoNmg4J.wLiEa5RIkvCrJVpoCrCOdkdt.qUAnZu', 'Teacher', NULL, 1, 1, NULL, '2026-04-24 07:32:08', NULL),
(23, 'Noimie', '', 'Orlanda', '', 'orlanda@neust.edu.ph', '$2y$12$PCDhd.vG.mNIZ.HXIe/cduHK557NfkbRDZUsQaKR1G7dolfiYw2J.', 'Teacher', NULL, 1, 1, NULL, '2026-04-24 07:33:05', NULL),
(24, 'Raymond', '', 'Santos', '', 'santos@neust.edu.ph', '$2y$12$pliMYP745gzgt0p.mONiTOJloAi6pEfr4It5vNFjlV/CU7ADIOciW', 'Teacher', NULL, 1, 1, NULL, '2026-04-24 07:33:43', NULL),
(25, 'Jomasel', '', 'Savellano', '', 'savellano@neust.edu.ph', '$2y$12$juEMSGcoDf2cBKAZFgfIMeLPLckr6bZoiBkvlhBnKfvICeG8fPMjW', 'Teacher', NULL, 1, 1, NULL, '2026-04-24 07:34:15', NULL),
(26, 'Remilyn', '', 'Suba', '', 'suba@neust.edu.ph', '$2y$12$7j2OUU8UuTniibvL0Da7xu9yRSxtIusGsG/dT03SNys509Andcm3W', 'Teacher', NULL, 1, 1, NULL, '2026-04-24 07:34:42', NULL),
(27, 'Camilo', '', 'Villaviza', '', 'villaviza@neust.edu.ph', '$2y$12$4DkCRaj9KJeLobij5oUQJ.Fn8B1xIVy2yeJ8l2IyUSbqGxVhrilym', 'Teacher', NULL, 1, 1, NULL, '2026-04-24 07:35:23', NULL),
(28, 'King Ardee', '', 'Miguel', '', 'miguel@neust.edu.ph', '$2y$12$L9S2Cs4u5sggRdsaFfInS.mS1Zza6aTaSM5ZiP9UKQt9el4VWmvHy', 'Teacher', NULL, NULL, 1, NULL, '2026-04-24 07:36:21', NULL),
(29, 'Jaime', '', 'Santos', '', 'jaime@neust.edu.ph', '$2y$12$OkyHyy7vUsD1DsA5RrVrFu4yZ6EhWtvsZgqMoT/jaKagJHAZlGkfy', 'Teacher', NULL, NULL, 1, NULL, '2026-04-24 07:37:13', NULL),
(30, 'Mikee Bryan', '', 'Espinosa', '', 'espinosa@neust.edu.ph', '$2y$12$mik.ESAvP2V1judxv12KZ.K5K781IlXbRJYx5ytZKeQZdOSwc4ilG', 'Teacher', NULL, NULL, 1, NULL, '2026-04-24 07:37:55', NULL),
(31, 'Calos Miguel', '', 'Garcia', '', 'garcia@neust.edu.ph', '$2y$12$/QvPIyreM9ZuuJioHuBq6.l5qC3y0ZIQlV1ZP6cTq5RVDEOLCp3rO', 'Teacher', NULL, NULL, 1, NULL, '2026-04-24 07:38:58', NULL),
(32, 'Patricia', '', 'Diaz', '', 'diaz@neust.edu.ph', '$2y$12$VbA302VP0BjS20WgaR/XuuWuTsT9h4yXHoqk3952vO97EZEkJ.3TS', 'Teacher', NULL, NULL, 1, NULL, '2026-04-24 07:40:41', NULL),
(33, 'Richard', '', 'Genchez', '', 'genchez@neust.edu.ph', '$2y$12$a181M/v/E1nLPSiYnxnmi.nd46TEkWJoQHBrToILXl7N4lTssaqiO', 'Teacher', NULL, NULL, 1, NULL, '2026-04-24 07:41:53', NULL),
(34, 'Pacita', '', 'Delos Ama', '', 'delosama@neust.edu.ph', '$2y$12$wZyS6mtk.fru94gKizoPqeeVxK.zdhDPkK51.zrfo0FILTS2KmwFG', 'Teacher', NULL, NULL, 1, NULL, '2026-04-24 07:43:06', NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `acad_years`
--
ALTER TABLE `acad_years`
  ADD PRIMARY KEY (`acadYearID`);

--
-- Indexes for table `archiving`
--
ALTER TABLE `archiving`
  ADD PRIMARY KEY (`archiveID`);

--
-- Indexes for table `enrollments`
--
ALTER TABLE `enrollments`
  ADD PRIMARY KEY (`enrollmentID`),
  ADD UNIQUE KEY `uq_enroll` (`studentID`,`subjectID`,`acadYearID`,`semester`),
  ADD KEY `fk_enr_subject` (`subjectID`),
  ADD KEY `fk_enr_program` (`programID`),
  ADD KEY `fk_enr_section` (`sectionID`),
  ADD KEY `fk_enr_acadyear` (`acadYearID`);

--
-- Indexes for table `grades`
--
ALTER TABLE `grades`
  ADD PRIMARY KEY (`gradeID`),
  ADD UNIQUE KEY `uq_grade` (`enrollmentID`),
  ADD KEY `fk_gr_student` (`studentID`),
  ADD KEY `fk_gr_subject` (`subjectID`),
  ADD KEY `fk_gr_teacher` (`teacherID`),
  ADD KEY `fk_gr_approver` (`approvedByID`);

--
-- Indexes for table `grade_audit_log`
--
ALTER TABLE `grade_audit_log`
  ADD PRIMARY KEY (`logID`);

--
-- Indexes for table `gwa_records`
--
ALTER TABLE `gwa_records`
  ADD PRIMARY KEY (`gwaID`),
  ADD UNIQUE KEY `uq_gwa` (`studentID`,`acadYearID`,`semester`),
  ADD KEY `fk_gwa_acadyear` (`acadYearID`),
  ADD KEY `fk_gwa_validator` (`validatedByID`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`notifID`);

--
-- Indexes for table `programs`
--
ALTER TABLE `programs`
  ADD PRIMARY KEY (`programID`),
  ADD UNIQUE KEY `uq_programCode` (`programCode`);

--
-- Indexes for table `ranking`
--
ALTER TABLE `ranking`
  ADD PRIMARY KEY (`rankingID`),
  ADD KEY `fk_rank_student` (`studentID`),
  ADD KEY `fk_rank_grade` (`gradeID`);

--
-- Indexes for table `sections`
--
ALTER TABLE `sections`
  ADD PRIMARY KEY (`sectionID`),
  ADD KEY `fk_sec_program` (`programID`),
  ADD KEY `fk_sec_acadyear` (`acadYearID`),
  ADD KEY `fk_sec_teacher` (`userID`);

--
-- Indexes for table `section_subjects`
--
ALTER TABLE `section_subjects`
  ADD PRIMARY KEY (`secSubID`),
  ADD UNIQUE KEY `uq_sec_sub` (`sectionID`,`subjectID`,`semester`,`acadYearID`),
  ADD KEY `fk_ss_subject` (`subjectID`),
  ADD KEY `fk_ss_teacher` (`teacherID`),
  ADD KEY `fk_ss_ay` (`acadYearID`);

--
-- Indexes for table `students`
--
ALTER TABLE `students`
  ADD PRIMARY KEY (`studentID`),
  ADD UNIQUE KEY `uq_studentNo` (`studentNo`),
  ADD UNIQUE KEY `uq_student_email` (`email`),
  ADD KEY `fk_stu_archive` (`archiveID`),
  ADD KEY `fk_stu_program` (`programID`);

--
-- Indexes for table `subjects`
--
ALTER TABLE `subjects`
  ADD PRIMARY KEY (`subjectID`),
  ADD UNIQUE KEY `uq_subjectCode` (`subjectCode`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`userID`),
  ADD UNIQUE KEY `uq_email` (`email`),
  ADD KEY `fk_usr_program` (`programID`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `acad_years`
--
ALTER TABLE `acad_years`
  MODIFY `acadYearID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `archiving`
--
ALTER TABLE `archiving`
  MODIFY `archiveID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `enrollments`
--
ALTER TABLE `enrollments`
  MODIFY `enrollmentID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `grades`
--
ALTER TABLE `grades`
  MODIFY `gradeID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `grade_audit_log`
--
ALTER TABLE `grade_audit_log`
  MODIFY `logID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `gwa_records`
--
ALTER TABLE `gwa_records`
  MODIFY `gwaID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `notifID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `programs`
--
ALTER TABLE `programs`
  MODIFY `programID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `ranking`
--
ALTER TABLE `ranking`
  MODIFY `rankingID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sections`
--
ALTER TABLE `sections`
  MODIFY `sectionID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `section_subjects`
--
ALTER TABLE `section_subjects`
  MODIFY `secSubID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `students`
--
ALTER TABLE `students`
  MODIFY `studentID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `subjects`
--
ALTER TABLE `subjects`
  MODIFY `subjectID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=62;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `userID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=35;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `enrollments`
--
ALTER TABLE `enrollments`
  ADD CONSTRAINT `fk_enr_acadyear` FOREIGN KEY (`acadYearID`) REFERENCES `acad_years` (`acadYearID`),
  ADD CONSTRAINT `fk_enr_program` FOREIGN KEY (`programID`) REFERENCES `programs` (`programID`),
  ADD CONSTRAINT `fk_enr_section` FOREIGN KEY (`sectionID`) REFERENCES `sections` (`sectionID`),
  ADD CONSTRAINT `fk_enr_student` FOREIGN KEY (`studentID`) REFERENCES `students` (`studentID`),
  ADD CONSTRAINT `fk_enr_subject` FOREIGN KEY (`subjectID`) REFERENCES `subjects` (`subjectID`);

--
-- Constraints for table `grades`
--
ALTER TABLE `grades`
  ADD CONSTRAINT `fk_gr_approver` FOREIGN KEY (`approvedByID`) REFERENCES `users` (`userID`),
  ADD CONSTRAINT `fk_gr_enrollment` FOREIGN KEY (`enrollmentID`) REFERENCES `enrollments` (`enrollmentID`),
  ADD CONSTRAINT `fk_gr_student` FOREIGN KEY (`studentID`) REFERENCES `students` (`studentID`),
  ADD CONSTRAINT `fk_gr_subject` FOREIGN KEY (`subjectID`) REFERENCES `subjects` (`subjectID`),
  ADD CONSTRAINT `fk_gr_teacher` FOREIGN KEY (`teacherID`) REFERENCES `users` (`userID`);

--
-- Constraints for table `gwa_records`
--
ALTER TABLE `gwa_records`
  ADD CONSTRAINT `fk_gwa_acadyear` FOREIGN KEY (`acadYearID`) REFERENCES `acad_years` (`acadYearID`),
  ADD CONSTRAINT `fk_gwa_student` FOREIGN KEY (`studentID`) REFERENCES `students` (`studentID`),
  ADD CONSTRAINT `fk_gwa_validator` FOREIGN KEY (`validatedByID`) REFERENCES `users` (`userID`);

--
-- Constraints for table `ranking`
--
ALTER TABLE `ranking`
  ADD CONSTRAINT `fk_rank_grade` FOREIGN KEY (`gradeID`) REFERENCES `grades` (`gradeID`),
  ADD CONSTRAINT `fk_rank_student` FOREIGN KEY (`studentID`) REFERENCES `students` (`studentID`);

--
-- Constraints for table `sections`
--
ALTER TABLE `sections`
  ADD CONSTRAINT `fk_sec_acadyear` FOREIGN KEY (`acadYearID`) REFERENCES `acad_years` (`acadYearID`),
  ADD CONSTRAINT `fk_sec_program` FOREIGN KEY (`programID`) REFERENCES `programs` (`programID`),
  ADD CONSTRAINT `fk_sec_teacher` FOREIGN KEY (`userID`) REFERENCES `users` (`userID`);

--
-- Constraints for table `section_subjects`
--
ALTER TABLE `section_subjects`
  ADD CONSTRAINT `fk_ss_ay` FOREIGN KEY (`acadYearID`) REFERENCES `acad_years` (`acadYearID`),
  ADD CONSTRAINT `fk_ss_section` FOREIGN KEY (`sectionID`) REFERENCES `sections` (`sectionID`),
  ADD CONSTRAINT `fk_ss_subject` FOREIGN KEY (`subjectID`) REFERENCES `subjects` (`subjectID`),
  ADD CONSTRAINT `fk_ss_teacher` FOREIGN KEY (`teacherID`) REFERENCES `users` (`userID`);

--
-- Constraints for table `students`
--
ALTER TABLE `students`
  ADD CONSTRAINT `fk_stu_archive` FOREIGN KEY (`archiveID`) REFERENCES `archiving` (`archiveID`),
  ADD CONSTRAINT `fk_stu_program` FOREIGN KEY (`programID`) REFERENCES `programs` (`programID`);

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_usr_program` FOREIGN KEY (`programID`) REFERENCES `programs` (`programID`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
