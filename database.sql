CREATE DATABASE IF NOT EXISTS `lich_day_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `lich_day_db`;

CREATE TABLE IF NOT EXISTS `classes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `class_name` VARCHAR(255) NOT NULL,
    `start_date` DATE NOT NULL,
    `schedule_days` VARCHAR(50) NOT NULL, -- Lưu chuỗi phân tách: "T2,T4,T6"
    `slot_time` VARCHAR(100) NOT NULL,     -- Ví dụ: "S1 (07:30 - 09:00)"
    `total_sessions` INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

USE `lich_day_db`;

CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL, -- Sẽ được mã hóa bảo mật bằng bcrypt
    `email` VARCHAR(255) NULL UNIQUE,
    `google_id` VARCHAR(255) NULL UNIQUE,
    `provider` VARCHAR(20) NOT NULL DEFAULT 'local',
    `role` VARCHAR(20) NOT NULL DEFAULT 'user' -- 'admin' hoặc 'user'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tạo sẵn tài khoản Admin mặc định: admin / 123456
-- (Mật khẩu dưới đây là chuỗi đã mã hóa của '123456')
INSERT INTO `users` (`username`, `password`, `role`) 
VALUES ('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin')
ON DUPLICATE KEY UPDATE `id`=`id`;