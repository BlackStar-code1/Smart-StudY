<?php
require_once 'db_config.php';

// Get all users
function getAllUsers($role = null) {
    global $conn;
    
    if ($role) {
        $query = "SELECT * FROM users WHERE role = ? ORDER BY created_at DESC";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $role);
    } else {
        $query = "SELECT * FROM users ORDER BY created_at DESC";
        $stmt = $conn->prepare($query);
    }
    
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Get user statistics
function getUserStatistics() {
    global $conn;
    
    $query = "SELECT 
              COUNT(*) as total_users,
              SUM(CASE WHEN role = 'student' THEN 1 ELSE 0 END) as total_students,
              SUM(CASE WHEN role = 'teacher' THEN 1 ELSE 0 END) as total_teachers,
              SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_users
              FROM users";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    
    return $stmt->get_result()->fetch_assoc();
}

// Get task statistics for admin
function getAdminTaskStatistics() {
    global $conn;
    
    $query = "SELECT 
              COUNT(*) as total_tasks,
              COUNT(DISTINCT teacher_id) as total_teachers_assigned,
              SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_tasks,
              SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_tasks
              FROM tasks";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    
    return $stmt->get_result()->fetch_assoc();
}

// Update user status
function updateUserStatus($user_id, $status) {
    global $conn;
    
    $query = "UPDATE users SET status = ? WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("si", $status, $user_id);
    
    return $stmt->execute();
}

// Change user role
function changeUserRole($user_id, $new_role) {
    global $conn;
    
    $valid_roles = ['student', 'teacher', 'admin'];
    if (!in_array($new_role, $valid_roles)) {
        return false;
    }
    
    $query = "UPDATE users SET role = ? WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("si", $new_role, $user_id);
    
    return $stmt->execute();
}

// Delete user (soft delete)
function deleteUser($user_id) {
    global $conn;
    
    $query = "UPDATE users SET status = 'inactive' WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $user_id);
    
    return $stmt->execute();
}

// Get all tasks (admin)
function getAllTasks($limit = 100, $offset = 0) {
    global $conn;
    
    $query = "SELECT t.*, u.full_name as teacher_name 
              FROM tasks t 
              LEFT JOIN users u ON t.teacher_id = u.id 
              ORDER BY t.created_at DESC 
              LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $limit, $offset);
    $stmt->execute();
    
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Get all submissions (admin)
function getAllSubmissions($limit = 100, $offset = 0) {
    global $conn;
    
    $query = "SELECT ts.*, t.title, u.full_name as student_name, teacher.full_name as teacher_name
              FROM task_submissions ts
              JOIN tasks t ON ts.task_id = t.id
              JOIN users u ON ts.student_id = u.id
              LEFT JOIN users teacher ON t.teacher_id = teacher.id
              ORDER BY ts.submission_date DESC
              LIMIT ? OFFSET ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $limit, $offset);
    $stmt->execute();
    
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Get system statistics
function getSystemStatistics() {
    global $conn;
    
    $users = getUserStatistics();
    $tasks = getAdminTaskStatistics();
    
    // Get submission statistics
    $submission_query = "SELECT COUNT(*) as total_submissions FROM task_submissions";
    $submission_stmt = $conn->prepare($submission_query);
    $submission_stmt->execute();
    $submission_data = $submission_stmt->get_result()->fetch_assoc();
    
    // Get notification statistics
    $notification_query = "SELECT COUNT(*) as total_notifications FROM notifications";
    $notification_stmt = $conn->prepare($notification_query);
    $notification_stmt->execute();
    $notification_data = $notification_stmt->get_result()->fetch_assoc();
    
    return [
        'users' => $users,
        'tasks' => $tasks,
        'submissions' => $submission_data,
        'notifications' => $notification_data
    ];
}

// Get pending approvals (if needed)
function getPendingApprovals() {
    global $conn;
    
    // Get unverified users
    $query = "SELECT id, username, email, full_name, created_at 
              FROM users WHERE email_verified = FALSE 
              ORDER BY created_at DESC";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Set admin settings
function setAdminSetting($key, $value) {
    global $conn;
    
    $check_query = "SELECT id FROM admin_settings WHERE setting_key = ?";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bind_param("s", $key);
    $check_stmt->execute();
    
    if ($check_stmt->get_result()->num_rows > 0) {
        $update_query = "UPDATE admin_settings SET setting_value = ? WHERE setting_key = ?";
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bind_param("ss", $value, $key);
        return $update_stmt->execute();
    } else {
        $insert_query = "INSERT INTO admin_settings (setting_key, setting_value) VALUES (?, ?)";
        $insert_stmt = $conn->prepare($insert_query);
        $insert_stmt->bind_param("ss", $key, $value);
        return $insert_stmt->execute();
    }
}

// Get admin setting
function getAdminSetting($key, $default = null) {
    global $conn;
    
    $query = "SELECT setting_value FROM admin_settings WHERE setting_key = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("s", $key);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    
    return $result ? $result['setting_value'] : $default;
}

// Get teacher performance
function getTeacherPerformance($teacher_id = null) {
    global $conn;
    
    if ($teacher_id) {
        $query = "SELECT 
                  u.id, u.full_name, COUNT(t.id) as total_tasks,
                  COUNT(DISTINCT ts.student_id) as students_engaged,
                  AVG(ts.grade) as average_grade
                  FROM users u
                  LEFT JOIN tasks t ON u.id = t.teacher_id
                  LEFT JOIN task_submissions ts ON t.id = ts.task_id
                  WHERE u.id = ? AND u.role = 'teacher'
                  GROUP BY u.id";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $teacher_id);
    } else {
        $query = "SELECT 
                  u.id, u.full_name, COUNT(t.id) as total_tasks,
                  COUNT(DISTINCT ts.student_id) as students_engaged,
                  AVG(ts.grade) as average_grade
                  FROM users u
                  LEFT JOIN tasks t ON u.id = t.teacher_id
                  LEFT JOIN task_submissions ts ON t.id = ts.task_id
                  WHERE u.role = 'teacher'
                  GROUP BY u.id
                  ORDER BY total_tasks DESC";
        $stmt = $conn->prepare($query);
    }
    
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Get student performance
function getStudentPerformance($student_id = null) {
    global $conn;
    
    if ($student_id) {
        $query = "SELECT 
                  u.id, u.full_name, COUNT(ts.id) as total_submissions,
                  SUM(CASE WHEN ts.status = 'graded' THEN 1 ELSE 0 END) as graded_submissions,
                  AVG(ts.grade) as average_grade,
                  SUM(CASE WHEN ts.grade >= 60 THEN 1 ELSE 0 END) as passed
                  FROM users u
                  LEFT JOIN task_submissions ts ON u.id = ts.student_id
                  WHERE u.id = ? AND u.role = 'student'
                  GROUP BY u.id";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $student_id);
    } else {
        $query = "SELECT 
                  u.id, u.full_name, COUNT(ts.id) as total_submissions,
                  SUM(CASE WHEN ts.status = 'graded' THEN 1 ELSE 0 END) as graded_submissions,
                  AVG(ts.grade) as average_grade,
                  SUM(CASE WHEN ts.grade >= 60 THEN 1 ELSE 0 END) as passed
                  FROM users u
                  LEFT JOIN task_submissions ts ON u.id = ts.student_id
                  WHERE u.role = 'student'
                  GROUP BY u.id
                  ORDER BY average_grade DESC";
        $stmt = $conn->prepare($query);
    }
    
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
?>
