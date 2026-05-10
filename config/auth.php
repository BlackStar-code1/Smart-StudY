<?php
require_once __DIR__ . '/db_config.php';

// reCAPTCHA v2 placeholders (set these in production)
// Site key: https://www.google.com/recaptcha/admin/create
// Secret key: https://www.google.com/recaptcha/admin/create
if (!defined('RECAPTCHA_SITE_KEY')) {
    define('RECAPTCHA_SITE_KEY', 'PASTE_YOUR_FREE_SITE_KEY_HERE');
}
if (!defined('RECAPTCHA_SECRET_KEY')) {
    define('RECAPTCHA_SECRET_KEY', 'PASTE_YOUR_FREE_SECRET_KEY_HERE');
}

// Authentication helper functions

// User Registration
function registerUser($username, $email, $password, $full_name, $role = 'student') {
    global $conn;
    
    // Check if user already exists
    $check_query = "SELECT id FROM users WHERE email = ? OR username = ?";
    $stmt = $conn->prepare($check_query);
    
    if (!$stmt) {
        return ['success' => false, 'message' => 'Database error: ' . $conn->error];
    }
    
    $stmt->bind_param("ss", $email, $username);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        return ['success' => false, 'message' => 'Email or username already exists'];
    }
    
    // Hash password
    $hashed_password = password_hash($password, PASSWORD_BCRYPT);
    
    // Insert user (email_verified set to FALSE initially, will be set to TRUE after OTP verification)
    $insert_query = "INSERT INTO users (username, email, password, full_name, role, email_verified) 
                     VALUES (?, ?, ?, ?, ?, FALSE)";
    $stmt = $conn->prepare($insert_query);
    
    if (!$stmt) {
        return ['success' => false, 'message' => 'Database error: ' . $conn->error];
    }
    
    $stmt->bind_param("sssss", $username, $email, $hashed_password, $full_name, $role);
    
    if ($stmt->execute()) {
        return ['success' => true, 'message' => 'Registration successful! You can now login', 'user_id' => $conn->insert_id];
    } else {
        return ['success' => false, 'message' => 'Registration failed: ' . $stmt->error];
    }
}

// User Login
function loginUser($email, $password) {
    global $conn;
    
    $query = "SELECT id, username, email, full_name, role, email_verified, status FROM users WHERE email = ?";
    $stmt = $conn->prepare($query);
    
    if (!$stmt) {
        return ['success' => false, 'message' => 'Database error: ' . $conn->error];
    }
    
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        
        if ($user['status'] !== 'active') {
            return ['success' => false, 'message' => 'Your account is inactive'];
        }
        
        // Verify password
        if (password_verify($password, getUserPassword($email))) {
            return [
                'success' => true,
                'message' => 'Login successful',
                'user' => $user
            ];
        }
    }
    
    return ['success' => false, 'message' => 'Invalid email or password'];
}

// Get user password (helper function)
function getUserPassword($email) {
    global $conn;
    
    $query = "SELECT password FROM users WHERE email = ?";
    $stmt = $conn->prepare($query);
    
    if (!$stmt) {
        error_log("Database error in getUserPassword: " . $conn->error);
        return null;
    }
    
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        return $user['password'];
    }
    
    return null;
}

// Get user by ID
function getUserById($id) {
    global $conn;
    
    $query = "SELECT id, username, email, full_name, role, profile_pic, status FROM users WHERE id = ?";
    $stmt = $conn->prepare($query);
    
    if (!$stmt) {
        error_log("Database error in getUserById: " . $conn->error);
        return null;
    }
    
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    return $result->fetch_assoc();
}

// Create session
function createSession($user) {
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['full_name'] = isset($user['full_name']) ? $user['full_name'] : $user['username'];
}

// Check if user is logged in
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Check user role
function checkUserRole($required_role) {
    if (!isLoggedIn()) {
        return false;
    }
    
    if (is_array($required_role)) {
        return in_array($_SESSION['role'], $required_role);
    }
    
    return $_SESSION['role'] === $required_role;
}

// Logout user
function logoutUser() {
    session_destroy();
    return true;
}

function updateUserProfile($user_id, $full_name, $profile_pic = null) {
    global $conn;
    
    if ($profile_pic) {
        $query = "UPDATE users SET full_name = ?, profile_pic = ? WHERE id = ?";
        $stmt = $conn->prepare($query);
        
        if (!$stmt) {
            error_log("Database error in updateUserProfile: " . $conn->error);
            return false;
        }
        
        $stmt->bind_param("ssi", $full_name, $profile_pic, $user_id);
    } else {
        $query = "UPDATE users SET full_name = ? WHERE id = ?";
        $stmt = $conn->prepare($query);
        
        if (!$stmt) {
            error_log("Database error in updateUserProfile: " . $conn->error);
            return false;
        }
        
        $stmt->bind_param("si", $full_name, $user_id);
    }
    
    return $stmt->execute();
}
?>
