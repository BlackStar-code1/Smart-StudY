<?php
// Database Configuration
define('DB_SERVER', 'localhost');
define('DB_USERNAME', 'root');
define('DB_PASSWORD', '');
define('DB_DATABASE', 'smartsp');

// Create connection
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Create database if not exists
$sql = "CREATE DATABASE IF NOT EXISTS " . DB_DATABASE;
if ($conn->query($sql) === TRUE) {
    // Select the database
    $conn->select_db(DB_DATABASE);
} else {
    die("Error creating database: " . $conn->error);
}

// Create tables
$tables_sql = array(
    // Users Table
    "CREATE TABLE IF NOT EXISTS users (
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
        status ENUM('active', 'inactive') DEFAULT 'active'
    )",
    
    // Tasks Table
    "CREATE TABLE IF NOT EXISTS tasks (
        id INT PRIMARY KEY AUTO_INCREMENT,
        teacher_id INT NOT NULL,
        title VARCHAR(255) NOT NULL,
        description LONGTEXT,
        subject VARCHAR(100),
        due_date DATETIME NOT NULL,
        section_id INT NULL,
        year VARCHAR(10) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        status ENUM('pending', 'completed', 'overdue') DEFAULT 'pending',
        FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE
    )",
    
    // Task Submissions Table
    "CREATE TABLE IF NOT EXISTS task_submissions (
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
        FOREIGN KEY (graded_by) REFERENCES users(id) ON DELETE SET NULL
    )",
    
    // Task Files Table
    "CREATE TABLE IF NOT EXISTS task_files (
        id INT PRIMARY KEY AUTO_INCREMENT,
        task_id INT NOT NULL,
        file_name VARCHAR(255) NOT NULL,
        file_path VARCHAR(255) NOT NULL,
        file_size INT,
        uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
    )",
    
    // Calendar Events Table
    "CREATE TABLE IF NOT EXISTS calendar_events (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        title VARCHAR(255) NOT NULL,
        description TEXT,
        start_date DATETIME NOT NULL,
        end_date DATETIME,
        event_type ENUM('task', 'exam', 'class', 'other') DEFAULT 'other',
        color VARCHAR(7) DEFAULT '#3498db',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )",
    
    // Notifications Table
    "CREATE TABLE IF NOT EXISTS notifications (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        title VARCHAR(255) NOT NULL,
        message LONGTEXT,
        type ENUM('task', 'submission', 'grade', 'announcement', 'system') DEFAULT 'system',
        related_id INT,
        is_read BOOLEAN DEFAULT FALSE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )",
    
    // Class Groups Table
    "CREATE TABLE IF NOT EXISTS class_groups (
        id INT PRIMARY KEY AUTO_INCREMENT,
        teacher_id INT NOT NULL,
        class_name VARCHAR(100) NOT NULL,
        description TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE
    )",
    
    // Class Students (Many-to-Many) Table
    "CREATE TABLE IF NOT EXISTS class_students (
        id INT PRIMARY KEY AUTO_INCREMENT,
        class_id INT NOT NULL,
        student_id INT NOT NULL,
        enrolled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (class_id) REFERENCES class_groups(id) ON DELETE CASCADE,
        FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE KEY unique_enrollment (class_id, student_id)
    )",
    
    // Announcements Table
    "CREATE TABLE IF NOT EXISTS announcements (
        id INT PRIMARY KEY AUTO_INCREMENT,
        teacher_id INT NOT NULL,
        title VARCHAR(255) NOT NULL,
        content LONGTEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE
    )",
    
    // Admin Settings Table
    "CREATE TABLE IF NOT EXISTS admin_settings (
        id INT PRIMARY KEY AUTO_INCREMENT,
        setting_key VARCHAR(100) UNIQUE NOT NULL,
        setting_value LONGTEXT,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )"
);

foreach ($tables_sql as $sql) {
    if ($conn->query($sql) === FALSE) {
        error_log("Error creating table: " . $conn->error);
    }
}

// Connection with selected database
$conn = new mysqli(DB_SERVER, DB_USERNAME, DB_PASSWORD, DB_DATABASE);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$conn->set_charset("utf8mb4");

// Add 'approved' and 'rejected' to task_submissions status if not already present
$alter_sql = "ALTER TABLE task_submissions MODIFY COLUMN status ENUM('pending','submitted','approved','rejected','graded') DEFAULT 'submitted'";
@$conn->query($alter_sql); // suppress error if already altered

// Add section and year support to tasks if not already present
$alter_tasks_sql = "ALTER TABLE tasks ADD COLUMN IF NOT EXISTS section_id INT NULL, ADD COLUMN IF NOT EXISTS year VARCHAR(10) NULL";
@$conn->query($alter_tasks_sql);
@$conn->query("ALTER TABLE tasks ADD CONSTRAINT fk_tasks_section FOREIGN KEY (section_id) REFERENCES class_groups(id) ON DELETE SET NULL");
?>

