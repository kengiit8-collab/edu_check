-- phpMyAdmin SQL Dump
-- version 5.1.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Jun 14, 2026 at 11:45 AM
-- Server version: 5.7.24
-- PHP Version: 8.3.1

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `edu_check`
--

-- --------------------------------------------------------

--
-- Table structure for table `attendance`
--

CREATE TABLE `attendance` (
  `id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `teacher_id` int(11) DEFAULT NULL,
  `subject_id` int(11) DEFAULT NULL,
  `schedule_slot_id` int(11) DEFAULT NULL,
  `attendance_date` date NOT NULL,
  `academic_year` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `term` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `room_note` text COLLATE utf8mb4_unicode_ci,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `attendance_items`
--

CREATE TABLE `attendance_items` (
  `id` int(11) NOT NULL,
  `attendance_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `status` enum('present','absent','leave','late','activity') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'present',
  `note` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `classes`
--

CREATE TABLE `classes` (
  `id` int(11) NOT NULL,
  `class_name` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `room_no` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `homeroom_teacher_id` int(11) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `classes`
--

INSERT INTO `classes` (`id`, `class_name`, `room_no`, `homeroom_teacher_id`, `active`, `created_at`) VALUES
(1, 'ม.1/1', '1/1', 1, 1, '2026-06-14 11:32:17'),
(2, 'ม.2/1', '2/1', 2, 1, '2026-06-14 11:32:17'),
(3, 'ม.3/1', '3/1', 3, 1, '2026-06-14 11:32:17');

-- --------------------------------------------------------

--
-- Table structure for table `class_homeroom_teachers`
--

CREATE TABLE `class_homeroom_teachers` (
  `class_id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `sort_order` tinyint(4) NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `class_homeroom_teachers`
--

INSERT INTO `class_homeroom_teachers` (`class_id`, `teacher_id`, `sort_order`) VALUES
(1, 1, 1),
(2, 2, 1),
(3, 3, 1);

-- --------------------------------------------------------

--
-- Table structure for table `schedule_slots`
--

CREATE TABLE `schedule_slots` (
  `id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `weekday` tinyint(4) NOT NULL,
  `period_no` tinyint(4) NOT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `room` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `academic_year` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `term` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `setting_key` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `setting_value` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `settings`
--

INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
('academic_year', '2569'),
('logo_path', 'img/logo-20260614184319.png'),
('school_name', 'โรงเรียนทดสอบการใช้งาน'),
('term', '1');

-- --------------------------------------------------------

--
-- Table structure for table `students`
--

CREATE TABLE `students` (
  `id` int(11) NOT NULL,
  `student_no` int(11) DEFAULT NULL,
  `student_code` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `prefix_th` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `first_name_th` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_name_th` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `class_id` int(11) NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `students`
--

INSERT INTO `students` (`id`, `student_no`, `student_code`, `prefix_th`, `first_name_th`, `last_name_th`, `class_id`, `active`, `created_at`) VALUES
(1, 1, 'D101001', 'เด็กชาย', 'กิตติ', 'ทดลอง', 1, 1, '2026-06-14 11:32:18'),
(2, 2, 'D101002', 'เด็กหญิง', 'มณีรัตน์', 'ตัวอย่าง', 1, 1, '2026-06-14 11:32:18'),
(3, 3, 'D101003', 'เด็กชาย', 'ปกรณ์', 'สมมุติ', 1, 1, '2026-06-14 11:32:18'),
(4, 4, 'D101004', 'เด็กหญิง', 'อรทัย', 'ใจดี', 1, 1, '2026-06-14 11:32:18'),
(5, 5, 'D101005', 'เด็กชาย', 'ธนกร', 'รักเรียน', 1, 1, '2026-06-14 11:32:18'),
(6, 1, 'D201001', 'เด็กชาย', 'วีรภัทร', 'ทดลอง', 2, 1, '2026-06-14 11:32:18'),
(7, 2, 'D201002', 'เด็กหญิง', 'ณัฐชา', 'ตัวอย่าง', 2, 1, '2026-06-14 11:32:18'),
(8, 3, 'D201003', 'เด็กชาย', 'ภูริ', 'สมมุติ', 2, 1, '2026-06-14 11:32:18'),
(9, 4, 'D201004', 'เด็กหญิง', 'ชลธิชา', 'ใจดี', 2, 1, '2026-06-14 11:32:18'),
(10, 5, 'D201005', 'เด็กชาย', 'ศุภกิตติ์', 'รักเรียน', 2, 1, '2026-06-14 11:32:18'),
(11, 1, 'D301001', 'เด็กชาย', 'ภาคิน', 'ทดลอง', 3, 1, '2026-06-14 11:32:18'),
(12, 2, 'D301002', 'เด็กหญิง', 'ปุณยาพร', 'ตัวอย่าง', 3, 1, '2026-06-14 11:32:18'),
(13, 3, 'D301003', 'เด็กชาย', 'รชต', 'สมมุติ', 3, 1, '2026-06-14 11:32:18'),
(14, 4, 'D301004', 'เด็กหญิง', 'กมลชนก', 'ใจดี', 3, 1, '2026-06-14 11:32:18'),
(15, 5, 'D301005', 'เด็กชาย', 'นวพล', 'รักเรียน', 3, 1, '2026-06-14 11:32:18');

-- --------------------------------------------------------

--
-- Table structure for table `subjects`
--

CREATE TABLE `subjects` (
  `id` int(11) NOT NULL,
  `subject_code` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject_name` varchar(180) COLLATE utf8mb4_unicode_ci NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `subjects`
--

INSERT INTO `subjects` (`id`, `subject_code`, `subject_name`, `active`) VALUES
(1, 'HOMEROOM', 'โฮมรูม', 1),
(2, 'DEMO101', 'วิชาทดลอง', 1);

-- --------------------------------------------------------

--
-- Table structure for table `teachers`
--

CREATE TABLE `teachers` (
  `id` int(11) NOT NULL,
  `teacher_code` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `name_th` varchar(180) COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `teachers`
--

INSERT INTO `teachers` (`id`, `teacher_code`, `name_th`, `phone`, `active`, `created_at`) VALUES
(1, 'T101', 'ครูสมชาย ใจดี', '0800000001', 1, '2026-06-14 11:32:17'),
(2, 'T201', 'ครูสุดา รักเรียน', '0800000002', 1, '2026-06-14 11:32:17'),
(3, 'T301', 'ครูอนันต์ ตั้งใจ', '0800000003', 1, '2026-06-14 11:32:17');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` enum('admin','teacher') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'teacher',
  `teacher_id` int(11) DEFAULT NULL,
  `class_id` int(11) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password_hash`, `role`, `teacher_id`, `class_id`, `active`, `created_at`) VALUES
(1, 'admin', '$2y$10$JA0pPArdPlVg/O8FGnJJrOxtEOirV887IOKMkPvR6mMvxUA1xmLLS', 'admin', NULL, NULL, 1, '2026-05-10 15:25:20'),
(2, 'm101', '$2y$10$UI/0kEQsixaImgSjB7bGJOhQ/j5ht707fFJk76j5hY0kFqPlLwL/.', 'teacher', 1, 1, 1, '2026-06-14 11:32:18'),
(3, 'm201', '$2y$10$UI/0kEQsixaImgSjB7bGJOhQ/j5ht707fFJk76j5hY0kFqPlLwL/.', 'teacher', 2, 2, 1, '2026-06-14 11:32:18'),
(4, 'm301', '$2y$10$UI/0kEQsixaImgSjB7bGJOhQ/j5ht707fFJk76j5hY0kFqPlLwL/.', 'teacher', 3, 3, 1, '2026-06-14 11:32:18');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `attendance`
--
ALTER TABLE `attendance`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_attendance_session` (`class_id`,`attendance_date`,`subject_id`,`schedule_slot_id`),
  ADD KEY `fk_att_teacher` (`teacher_id`),
  ADD KEY `fk_att_subject` (`subject_id`),
  ADD KEY `fk_att_slot` (`schedule_slot_id`),
  ADD KEY `fk_att_user` (`created_by`);

--
-- Indexes for table `attendance_items`
--
ALTER TABLE `attendance_items`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_att_student` (`attendance_id`,`student_id`),
  ADD KEY `fk_item_student` (`student_id`);

--
-- Indexes for table `classes`
--
ALTER TABLE `classes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_classes_teacher` (`homeroom_teacher_id`);

--
-- Indexes for table `class_homeroom_teachers`
--
ALTER TABLE `class_homeroom_teachers`
  ADD PRIMARY KEY (`class_id`,`teacher_id`),
  ADD KEY `fk_class_homeroom_teacher` (`teacher_id`);

--
-- Indexes for table `schedule_slots`
--
ALTER TABLE `schedule_slots`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_slots_class` (`class_id`),
  ADD KEY `fk_slots_teacher` (`teacher_id`),
  ADD KEY `fk_slots_subject` (`subject_id`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`setting_key`);

--
-- Indexes for table `students`
--
ALTER TABLE `students`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `student_code` (`student_code`),
  ADD KEY `fk_students_class` (`class_id`);

--
-- Indexes for table `subjects`
--
ALTER TABLE `subjects`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_subject_code` (`subject_code`);

--
-- Indexes for table `teachers`
--
ALTER TABLE `teachers`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD KEY `fk_users_teacher` (`teacher_id`),
  ADD KEY `idx_users_class_id` (`class_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `attendance`
--
ALTER TABLE `attendance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `attendance_items`
--
ALTER TABLE `attendance_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `classes`
--
ALTER TABLE `classes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `schedule_slots`
--
ALTER TABLE `schedule_slots`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `students`
--
ALTER TABLE `students`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `subjects`
--
ALTER TABLE `subjects`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `teachers`
--
ALTER TABLE `teachers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `attendance`
--
ALTER TABLE `attendance`
  ADD CONSTRAINT `fk_att_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_att_slot` FOREIGN KEY (`schedule_slot_id`) REFERENCES `schedule_slots` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_att_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_att_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_att_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `attendance_items`
--
ALTER TABLE `attendance_items`
  ADD CONSTRAINT `fk_item_att` FOREIGN KEY (`attendance_id`) REFERENCES `attendance` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_item_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `classes`
--
ALTER TABLE `classes`
  ADD CONSTRAINT `fk_classes_teacher` FOREIGN KEY (`homeroom_teacher_id`) REFERENCES `teachers` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `class_homeroom_teachers`
--
ALTER TABLE `class_homeroom_teachers`
  ADD CONSTRAINT `fk_class_homeroom_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_class_homeroom_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `schedule_slots`
--
ALTER TABLE `schedule_slots`
  ADD CONSTRAINT `fk_slots_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_slots_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_slots_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `students`
--
ALTER TABLE `students`
  ADD CONSTRAINT `fk_students_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
