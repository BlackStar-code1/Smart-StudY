-- SmartSP Admin Database Setup
-- This file contains all SQL queries needed to set up the admin-related database structure

-- =====================================================
-- 1. CREATE DATABASE
-- =====================================================
CREATE DATABASE IF NOT EXISTS smartsp;
USE smartsp;

-- =====================================================
-- 2. CREATE TABLES
-- =====================================================

-- Users Table (with Admin role)
CREATE TABLE IF NOT EXISTS users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    role ENUM('student', 'teacher', 'admin') DEFAULT 'student',
    otp VARCHAR(6),
    otp_expires DATETIME,
    email_verified BOOLEAN DEFAULT FALSE,
    verified_at DATETIME,
    profile_pic VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    status ENUM('active', 'inactive') DEFAULT 'active',
    INDEX idx_role (role),
    INDEX idx_email (email),
    INDEX idx_status (status)
);

-- Tasks Table
CREATE TABLE IF NOT EXISTS tasks (
    id INT PRIMARY KEY AUTO_INCREMENT,
    teacher_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    description LONGTEXT,
    subject VARCHAR(100),
    due_date DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('pending', 'completed', 'overdue') DEFAULT 'pending',
    FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_teacher_id (teacher_id),
    INDEX idx_status (status),
    INDEX idx_due_date (due_date)
);

-- Task Submissions Table
CREATE TABLE IF NOT EXISTS task_submissions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    task_id INT NOT NULL,
    student_id INT NOT NULL,
    submission_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    file_path VARCHAR(255),
    notes TEXT,
    status ENUM('pending', 'submitted', 'graded') DEFAULT 'submitted',
    grade INT,
    feedback TEXT,
    graded_by INT,
    graded_at DATETIME,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (graded_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_task_id (task_id),
    INDEX idx_student_id (student_id),
    INDEX idx_status (status)
);

-- Task Files Table
CREATE TABLE IF NOT EXISTS task_files (
    id INT PRIMARY KEY AUTO_INCREMENT,
    task_id INT NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_size INT,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    INDEX idx_task_id (task_id)
);

-- Calendar Events Table
CREATE TABLE IF NOT EXISTS calendar_events (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    start_date DATETIME NOT NULL,
    end_date DATETIME,
    event_type ENUM('task', 'exam', 'class', 'other') DEFAULT 'other',
    color VARCHAR(7) DEFAULT '#3498db',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_start_date (start_date)
);

-- Notifications Table
CREATE TABLE IF NOT EXISTS notifications (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    message LONGTEXT,
    type ENUM('task', 'submission', 'grade', 'announcement', 'system') DEFAULT 'system',
    related_id INT,
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_is_read (is_read),
    INDEX idx_created_at (created_at)
);

-- Class Groups Table
CREATE TABLE IF NOT EXISTS class_groups (
    id INT PRIMARY KEY AUTO_INCREMENT,
    teacher_id INT NOT NULL,
    class_name VARCHAR(100) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_teacher_id (teacher_id)
);

-- Class Students (Many-to-Many) Table
CREATE TABLE IF NOT EXISTS class_students (
    id INT PRIMARY KEY AUTO_INCREMENT,
    class_id INT NOT NULL,
    student_id INT NOT NULL,
    enrolled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (class_id) REFERENCES class_groups(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_enrollment (class_id, student_id),
    INDEX idx_class_id (class_id),
    INDEX idx_student_id (student_id)
);

-- Announcements Table
CREATE TABLE IF NOT EXISTS announcements (
    id INT PRIMARY KEY AUTO_INCREMENT,
    teacher_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    content LONGTEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_teacher_id (teacher_id),
    INDEX idx_created_at (created_at)
);

-- Admin Settings Table (for system configurations)
CREATE TABLE IF NOT EXISTS admin_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value LONGTEXT,
    setting_type ENUM('string', 'number', 'boolean', 'json') DEFAULT 'string',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT,
    INDEX idx_setting_key (setting_key)
);

-- Admin Logs Table (for audit trail)
CREATE TABLE IF NOT EXISTS admin_logs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    admin_id INT NOT NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(50),
    entity_id INT,
    old_value LONGTEXT,
    new_value LONGTEXT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_admin_id (admin_id),
    INDEX idx_action (action),
    INDEX idx_created_at (created_at)
);

-- =====================================================
-- 3. CREATE ADMIN USER (Default Admin Account)
-- =====================================================
-- Password: Admin@123 (hashed with bcrypt)
-- You should change this immediately after first login

INSERT INTO users (username, email, password, full_name, role, email_verified, verified_at, status) 
VALUES (
    'admin',
    'admin@smartsp.local',
    '$2y$10$JeBDwnsoz84siDtRkhNZ1.8VJm6VjHELkqNjWe1rAhYEp2gNHVnHe',
    'System Administrator',
    'admin',
    TRUE,
    NOW(),
    'active'
) ON DUPLICATE KEY UPDATE updated_at = NOW();

-- =====================================================
-- 4. INSERT DEFAULT ADMIN SETTINGS
-- =====================================================

INSERT INTO admin_settings (setting_key, setting_value, setting_type) VALUES
('site_name', 'SmartSP - Smart School Platform', 'string'),
('site_description', 'An integrated school management and task platform', 'string'),
('max_upload_size', '10485760', 'number'),
('allowed_file_types', 'pdf,doc,docx,xls,xlsx,ppt,pptx,txt,jpg,jpeg,png,gif', 'string'),
('smtp_enabled', 'false', 'boolean'),
('otp_expiry_minutes', '10', 'number'),
('session_timeout_minutes', '30', 'number'),
('enable_user_registration', 'true', 'boolean'),
('enable_email_verification', 'false', 'boolean'),
('task_reminder_hours', '24', 'number')
ON DUPLICATE KEY UPDATE updated_at = NOW();

-- =====================================================
-- 5. CREATE VIEWS FOR ADMIN DASHBOARD
-- =====================================================

-- View for User Statistics
CREATE OR REPLACE VIEW user_statistics AS
SELECT 
    COUNT(*) as total_users,
    SUM(CASE WHEN role = 'student' THEN 1 ELSE 0 END) as total_students,
    SUM(CASE WHEN role = 'teacher' THEN 1 ELSE 0 END) as total_teachers,
    SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) as total_admins,
    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_users,
    SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive_users,
    DATE(created_at) as registration_date
FROM users
GROUP BY DATE(created_at)
ORDER BY registration_date DESC;

-- View for Task Statistics
CREATE OR REPLACE VIEW task_statistics AS
SELECT 
    COUNT(*) as total_tasks,
    COUNT(DISTINCT teacher_id) as total_teachers_assigned,
    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_tasks,
    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_tasks,
    SUM(CASE WHEN status = 'overdue' THEN 1 ELSE 0 END) as overdue_tasks,
    DATE(created_at) as task_date
FROM tasks
GROUP BY DATE(created_at)
ORDER BY task_date DESC;

-- View for Submission Statistics
CREATE OR REPLACE VIEW submission_statistics AS
SELECT 
    COUNT(*) as total_submissions,
    SUM(CASE WHEN status = 'submitted' THEN 1 ELSE 0 END) as submitted_count,
    SUM(CASE WHEN status = 'graded' THEN 1 ELSE 0 END) as graded_count,
    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
    AVG(grade) as average_grade,
    DATE(submission_date) as submission_date
FROM task_submissions
GROUP BY DATE(submission_date)
ORDER BY submission_date DESC;

-- =====================================================
-- 6. CREATE INDEXES FOR PERFORMANCE
-- =====================================================

-- User performance indexes
CREATE INDEX IF NOT EXISTS idx_users_role_status ON users(role, status);
CREATE INDEX IF NOT EXISTS idx_users_created_at ON users(created_at);

-- Task performance indexes
CREATE INDEX IF NOT EXISTS idx_tasks_teacher_status ON tasks(teacher_id, status);
CREATE INDEX IF NOT EXISTS idx_tasks_created_at ON tasks(created_at);

-- Submission performance indexes
CREATE INDEX IF NOT EXISTS idx_submissions_task_student ON task_submissions(task_id, student_id);
CREATE INDEX IF NOT EXISTS idx_submissions_graded_by ON task_submissions(graded_by);
CREATE INDEX IF NOT EXISTS idx_submissions_created_at ON task_submissions(submission_date);

-- Notification performance indexes
CREATE INDEX IF NOT EXISTS idx_notifications_user_read ON notifications(user_id, is_read);
CREATE INDEX IF NOT EXISTS idx_notifications_type ON notifications(type);

-- =====================================================
-- 7. DEFAULT ADMIN CREDENTIALS
-- =====================================================
-- Username: admin
-- Email: admin@smartsp.local
-- Password: Admin@123
-- IMPORTANT: Change this password immediately after first login!
-- To generate a new password hash in PHP:
--   password_hash('your_new_password', PASSWORD_BCRYPT)
