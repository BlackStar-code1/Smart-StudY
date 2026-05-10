-- Smart Study Planner - Enhanced Grading and Student Database Schema
-- This extends the existing database with comprehensive grading and student management features

USE smartsp;

-- =====================================================
-- STUDENT INFORMATION TABLES
-- =====================================================

-- Student Profiles Table (extends user information)
CREATE TABLE IF NOT EXISTS student_profiles (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL UNIQUE,
    student_id VARCHAR(20) UNIQUE, -- School student ID
    date_of_birth DATE,
    gender ENUM('male', 'female', 'other'),
    phone VARCHAR(20),
    address TEXT,
    emergency_contact_name VARCHAR(100),
    emergency_contact_phone VARCHAR(20),
    enrollment_date DATE DEFAULT CURRENT_DATE,
    graduation_year YEAR,
    gpa DECIMAL(3,2) DEFAULT 0.00,
    total_credits INT DEFAULT 0,
    status ENUM('active', 'inactive', 'graduated', 'transferred') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_student_id (student_id),
    INDEX idx_status (status)
);

-- Academic Years/Semesters Table
CREATE TABLE IF NOT EXISTS academic_periods (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL, -- e.g., "Fall 2024", "Spring 2025"
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    type ENUM('semester', 'quarter', 'year') DEFAULT 'semester',
    status ENUM('upcoming', 'active', 'completed') DEFAULT 'upcoming',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_dates (start_date, end_date),
    INDEX idx_status (status)
);

-- Subjects/Courses Table
CREATE TABLE IF NOT EXISTS subjects (
    id INT PRIMARY KEY AUTO_INCREMENT,
    code VARCHAR(20) UNIQUE NOT NULL, -- e.g., "MATH101"
    name VARCHAR(100) NOT NULL,
    description TEXT,
    credits INT DEFAULT 1,
    department VARCHAR(100),
    level ENUM('elementary', 'middle', 'high', 'college') DEFAULT 'high',
    status ENUM('active', 'inactive') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_code (code),
    INDEX idx_department (department)
);

-- Class Enrollments Table (links students to specific class instances)
CREATE TABLE IF NOT EXISTS class_enrollments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    class_id INT NOT NULL, -- references class_groups
    academic_period_id INT NOT NULL,
    enrollment_date DATE DEFAULT CURRENT_DATE,
    status ENUM('enrolled', 'dropped', 'completed', 'failed') DEFAULT 'enrolled',
    final_grade DECIMAL(5,2),
    grade_letter CHAR(2),
    credits_earned INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES class_groups(id) ON DELETE CASCADE,
    FOREIGN KEY (academic_period_id) REFERENCES academic_periods(id) ON DELETE CASCADE,
    UNIQUE KEY unique_enrollment (student_id, class_id, academic_period_id),
    INDEX idx_student (student_id),
    INDEX idx_class (class_id),
    INDEX idx_period (academic_period_id)
);

-- =====================================================
-- GRADING SYSTEM TABLES
-- =====================================================

-- Grading Scales Table
CREATE TABLE IF NOT EXISTS grading_scales (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(50) NOT NULL, -- e.g., "Standard Scale", "Pass/Fail"
    scale_type ENUM('percentage', 'points', 'letter', 'pass_fail') DEFAULT 'percentage',
    min_score DECIMAL(5,2),
    max_score DECIMAL(5,2),
    passing_score DECIMAL(5,2),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_type (scale_type)
);

-- Grading Scale Ranges Table
CREATE TABLE IF NOT EXISTS grading_scale_ranges (
    id INT PRIMARY KEY AUTO_INCREMENT,
    scale_id INT NOT NULL,
    grade_letter VARCHAR(5) NOT NULL, -- e.g., "A", "B+", "Pass"
    min_percentage DECIMAL(5,2),
    max_percentage DECIMAL(5,2),
    grade_points DECIMAL(3,2), -- GPA points
    description VARCHAR(100),
    FOREIGN KEY (scale_id) REFERENCES grading_scales(id) ON DELETE CASCADE,
    INDEX idx_scale (scale_id)
);

-- Assignment Categories Table (for weighted grading)
CREATE TABLE IF NOT EXISTS assignment_categories (
    id INT PRIMARY KEY AUTO_INCREMENT,
    name VARCHAR(50) NOT NULL, -- e.g., "Homework", "Quizzes", "Exams"
    description TEXT,
    weight_percentage DECIMAL(5,2) DEFAULT 0.00, -- Weight in final grade
    color VARCHAR(7) DEFAULT '#3498db',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_name (name)
);

-- Detailed Grades Table (extends task_submissions)
CREATE TABLE IF NOT EXISTS detailed_grades (
    id INT PRIMARY KEY AUTO_INCREMENT,
    submission_id INT NOT NULL,
    category_id INT, -- Assignment category
    score DECIMAL(5,2),
    max_score DECIMAL(5,2) DEFAULT 100.00,
    percentage DECIMAL(5,2),
    grade_letter VARCHAR(5),
    grade_points DECIMAL(3,2),
    weight DECIMAL(5,2) DEFAULT 1.00, -- Weight within category
    comments TEXT,
    graded_by INT NOT NULL,
    graded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (submission_id) REFERENCES task_submissions(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES assignment_categories(id) ON DELETE SET NULL,
    FOREIGN KEY (graded_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_submission (submission_id),
    INDEX idx_category (category_id),
    INDEX idx_graded_by (graded_by)
);

-- Grade History/Audit Table
CREATE TABLE IF NOT EXISTS grade_history (
    id INT PRIMARY KEY AUTO_INCREMENT,
    submission_id INT NOT NULL,
    old_score DECIMAL(5,2),
    new_score DECIMAL(5,2),
    old_feedback TEXT,
    new_feedback TEXT,
    changed_by INT NOT NULL,
    change_reason TEXT,
    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (submission_id) REFERENCES task_submissions(id) ON DELETE CASCADE,
    FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_submission (submission_id),
    INDEX idx_changed_by (changed_by)
);

-- =====================================================
-- REPORTING AND ANALYTICS TABLES
-- =====================================================

-- Grade Reports Table (for storing generated reports)
CREATE TABLE IF NOT EXISTS grade_reports (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    academic_period_id INT NOT NULL,
    report_type ENUM('progress', 'semester', 'final', 'transcript') DEFAULT 'progress',
    generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    generated_by INT,
    file_path VARCHAR(255),
    status ENUM('draft', 'final', 'archived') DEFAULT 'draft',
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (academic_period_id) REFERENCES academic_periods(id) ON DELETE CASCADE,
    FOREIGN KEY (generated_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_student_period (student_id, academic_period_id),
    INDEX idx_type (report_type)
);

-- Student Performance Metrics Table
CREATE TABLE IF NOT EXISTS performance_metrics (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    academic_period_id INT NOT NULL,
    subject_id INT,
    assignments_completed INT DEFAULT 0,
    assignments_total INT DEFAULT 0,
    average_score DECIMAL(5,2),
    grade_trend ENUM('improving', 'stable', 'declining') DEFAULT 'stable',
    attendance_percentage DECIMAL(5,2),
    participation_score DECIMAL(5,2),
    calculated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (academic_period_id) REFERENCES academic_periods(id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE SET NULL,
    UNIQUE KEY unique_metrics (student_id, academic_period_id, subject_id),
    INDEX idx_student (student_id),
    INDEX idx_period (academic_period_id)
);

-- =====================================================
-- ATTENDANCE SYSTEM TABLES
-- =====================================================

-- Attendance Records Table
CREATE TABLE IF NOT EXISTS attendance_records (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    class_id INT NOT NULL,
    date DATE NOT NULL,
    status ENUM('present', 'absent', 'late', 'excused') DEFAULT 'present',
    check_in_time TIME,
    check_out_time TIME,
    duration_minutes INT,
    notes TEXT,
    marked_by INT NOT NULL,
    marked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (class_id) REFERENCES class_groups(id) ON DELETE CASCADE,
    FOREIGN KEY (marked_by) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_attendance (student_id, class_id, date),
    INDEX idx_student_date (student_id, date),
    INDEX idx_class_date (class_id, date),
    INDEX idx_status (status)
);

-- =====================================================
-- DEFAULT DATA INSERTION
-- =====================================================

-- Insert default grading scale
INSERT INTO grading_scales (name, scale_type, min_score, max_score, passing_score) VALUES
('Standard Percentage Scale', 'percentage', 0, 100, 60) ON DUPLICATE KEY UPDATE name = name;

-- Insert default grading scale ranges
INSERT INTO grading_scale_ranges (scale_id, grade_letter, min_percentage, max_percentage, grade_points, description) VALUES
(1, 'A', 90, 100, 4.0, 'Excellent'),
(1, 'B', 80, 89.99, 3.0, 'Good'),
(1, 'C', 70, 79.99, 2.0, 'Satisfactory'),
(1, 'D', 60, 69.99, 1.0, 'Passing'),
(1, 'F', 0, 59.99, 0.0, 'Failing') ON DUPLICATE KEY UPDATE grade_letter = grade_letter;

-- Insert default assignment categories
INSERT INTO assignment_categories (name, description, weight_percentage, color) VALUES
('Homework', 'Regular homework assignments', 30.00, '#3498db'),
('Quizzes', 'Short quizzes and assessments', 20.00, '#e74c3c'),
('Projects', 'Major projects and assignments', 25.00, '#2ecc71'),
('Exams', 'Mid-term and final exams', 25.00, '#f39c12') ON DUPLICATE KEY UPDATE name = name;

-- Insert sample academic periods
INSERT INTO academic_periods (name, start_date, end_date, type, status) VALUES
('Fall 2024', '2024-09-01', '2024-12-20', 'semester', 'completed'),
('Spring 2025', '2025-01-15', '2025-05-30', 'semester', 'active'),
('Summer 2025', '2025-06-01', '2025-08-15', 'semester', 'upcoming') ON DUPLICATE KEY UPDATE name = name;

-- Insert sample subjects
INSERT INTO subjects (code, name, description, credits, department, level) VALUES
('MATH101', 'Algebra I', 'Introduction to algebraic concepts', 3, 'Mathematics', 'high'),
('ENG201', 'English Literature', 'Study of classic and modern literature', 3, 'English', 'high'),
('SCI301', 'Biology', 'Introduction to biological sciences', 4, 'Science', 'high'),
('HIST401', 'World History', 'Survey of world historical events', 3, 'Social Studies', 'high') ON DUPLICATE KEY UPDATE code = code;

-- =====================================================
-- VIEWS FOR GRADING AND STUDENT REPORTS
-- =====================================================

-- Student Grade Summary View
CREATE OR REPLACE VIEW student_grade_summary AS
SELECT
    sp.student_id,
    u.full_name,
    u.email,
    sp.gpa,
    sp.total_credits,
    COUNT(DISTINCT ce.class_id) as enrolled_classes,
    AVG(dg.percentage) as current_average,
    COUNT(CASE WHEN dg.grade_letter IN ('A', 'B', 'C', 'D') THEN 1 END) as passing_grades,
    COUNT(CASE WHEN dg.grade_letter = 'F' THEN 1 END) as failing_grades,
    MAX(dg.graded_at) as last_grade_date
FROM student_profiles sp
JOIN users u ON sp.user_id = u.id
LEFT JOIN class_enrollments ce ON sp.user_id = ce.student_id AND ce.status = 'enrolled'
LEFT JOIN task_submissions ts ON sp.user_id = ts.student_id
LEFT JOIN detailed_grades dg ON ts.id = dg.submission_id
WHERE u.role = 'student'
GROUP BY sp.student_id, u.full_name, u.email, sp.gpa, sp.total_credits;

-- Class Performance View
CREATE OR REPLACE VIEW class_performance AS
SELECT
    cg.class_name,
    s.name as subject_name,
    ap.name as academic_period,
    COUNT(DISTINCT ce.student_id) as enrolled_students,
    AVG(dg.percentage) as class_average,
    MIN(dg.percentage) as lowest_score,
    MAX(dg.percentage) as highest_score,
    COUNT(dg.id) as total_grades
FROM class_groups cg
JOIN subjects s ON cg.id = s.id -- Assuming subjects are linked to classes
LEFT JOIN academic_periods ap ON ap.status = 'active'
LEFT JOIN class_enrollments ce ON cg.id = ce.class_id AND ce.academic_period_id = ap.id
LEFT JOIN task_submissions ts ON ce.student_id = ts.student_id
LEFT JOIN detailed_grades dg ON ts.id = dg.submission_id
GROUP BY cg.id, cg.class_name, s.name, ap.name;

-- Grade Distribution View
CREATE OR REPLACE VIEW grade_distribution AS
SELECT
    gsr.grade_letter,
    gsr.description,
    COUNT(dg.id) as count,
    ROUND((COUNT(dg.id) / (SELECT COUNT(*) FROM detailed_grades)) * 100, 2) as percentage
FROM grading_scale_ranges gsr
LEFT JOIN detailed_grades dg ON gsr.grade_letter = dg.grade_letter
GROUP BY gsr.grade_letter, gsr.description
ORDER BY gsr.min_percentage DESC;

-- =====================================================
-- INDEXES FOR PERFORMANCE
-- =====================================================

CREATE INDEX IF NOT EXISTS idx_student_profiles_user ON student_profiles(user_id);
CREATE INDEX IF NOT EXISTS idx_detailed_grades_submission ON detailed_grades(submission_id);
CREATE INDEX IF NOT EXISTS idx_class_enrollments_student ON class_enrollments(student_id);
CREATE INDEX IF NOT EXISTS idx_attendance_student_date ON attendance_records(student_id, date);
CREATE INDEX IF NOT EXISTS idx_performance_student_period ON performance_metrics(student_id, academic_period_id);