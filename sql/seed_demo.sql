SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

INSERT INTO settings (setting_key, setting_value)
VALUES ('school_name', CONVERT(UNHEX('e0b982e0b8a3e0b887e0b980e0b8a3e0b8b5e0b8a2e0b899e0b895e0b8b1e0b8a7e0b8ade0b8a2e0b988e0b8b2e0b887') USING utf8mb4))
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

TRUNCATE TABLE attendance_items;
TRUNCATE TABLE attendance;
TRUNCATE TABLE schedule_slots;
TRUNCATE TABLE class_homeroom_teachers;
TRUNCATE TABLE students;
TRUNCATE TABLE classes;
TRUNCATE TABLE teachers;
TRUNCATE TABLE subjects;

DELETE FROM users WHERE role = 'teacher';
ALTER TABLE users AUTO_INCREMENT = 2;
UPDATE users
SET password_hash = '$2y$10$JA0pPArdPlVg/O8FGnJJrOxtEOirV887IOKMkPvR6mMvxUA1xmLLS',
    role = 'admin',
    teacher_id = NULL,
    class_id = NULL,
    active = 1
WHERE username = 'admin';

INSERT INTO teachers (id, teacher_code, name_th, phone, active) VALUES
(1, 'T101', 'ครูสมชาย ใจดี', '0800000001', 1),
(2, 'T201', 'ครูสุดา รักเรียน', '0800000002', 1),
(3, 'T301', 'ครูอนันต์ ตั้งใจ', '0800000003', 1);

INSERT INTO classes (id, class_name, room_no, homeroom_teacher_id, active) VALUES
(1, 'ม.1/1', '1/1', 1, 1),
(2, 'ม.2/1', '2/1', 2, 1),
(3, 'ม.3/1', '3/1', 3, 1);

INSERT INTO class_homeroom_teachers (class_id, teacher_id, sort_order) VALUES
(1, 1, 1),
(2, 2, 1),
(3, 3, 1);

INSERT INTO users (username, password_hash, role, teacher_id, class_id, active) VALUES
('m101', '$2y$10$UI/0kEQsixaImgSjB7bGJOhQ/j5ht707fFJk76j5hY0kFqPlLwL/.', 'teacher', 1, 1, 1),
('m201', '$2y$10$UI/0kEQsixaImgSjB7bGJOhQ/j5ht707fFJk76j5hY0kFqPlLwL/.', 'teacher', 2, 2, 1),
('m301', '$2y$10$UI/0kEQsixaImgSjB7bGJOhQ/j5ht707fFJk76j5hY0kFqPlLwL/.', 'teacher', 3, 3, 1);

INSERT INTO students (student_no, student_code, prefix_th, first_name_th, last_name_th, class_id, active) VALUES
(1, 'D101001', 'เด็กชาย', 'กิตติ', 'ทดลอง', 1, 1),
(2, 'D101002', 'เด็กหญิง', 'มณีรัตน์', 'ตัวอย่าง', 1, 1),
(3, 'D101003', 'เด็กชาย', 'ปกรณ์', 'สมมุติ', 1, 1),
(4, 'D101004', 'เด็กหญิง', 'อรทัย', 'ใจดี', 1, 1),
(5, 'D101005', 'เด็กชาย', 'ธนกร', 'รักเรียน', 1, 1),
(1, 'D201001', 'เด็กชาย', 'วีรภัทร', 'ทดลอง', 2, 1),
(2, 'D201002', 'เด็กหญิง', 'ณัฐชา', 'ตัวอย่าง', 2, 1),
(3, 'D201003', 'เด็กชาย', 'ภูริ', 'สมมุติ', 2, 1),
(4, 'D201004', 'เด็กหญิง', 'ชลธิชา', 'ใจดี', 2, 1),
(5, 'D201005', 'เด็กชาย', 'ศุภกิตติ์', 'รักเรียน', 2, 1),
(1, 'D301001', 'เด็กชาย', 'ภาคิน', 'ทดลอง', 3, 1),
(2, 'D301002', 'เด็กหญิง', 'ปุณยาพร', 'ตัวอย่าง', 3, 1),
(3, 'D301003', 'เด็กชาย', 'รชต', 'สมมุติ', 3, 1),
(4, 'D301004', 'เด็กหญิง', 'กมลชนก', 'ใจดี', 3, 1),
(5, 'D301005', 'เด็กชาย', 'นวพล', 'รักเรียน', 3, 1);

INSERT INTO subjects (id, subject_code, subject_name, active) VALUES
(1, 'HOMEROOM', 'โฮมรูม', 1),
(2, 'DEMO101', 'วิชาทดลอง', 1);

SET FOREIGN_KEY_CHECKS = 1;
