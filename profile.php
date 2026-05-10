<?php
session_start();
require_once 'config/db_config.php';
require_once 'config/auth.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$user = getUserById($user_id);
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $full_name = trim($_POST['full_name'] ?? '');
    $profile_pic = null;
    

    if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] == 0) {
        $upload_dir = 'uploads/profiles/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $file_name = basename($_FILES['profile_pic']['name']);
        $ext = pathinfo($file_name, PATHINFO_EXTENSION);
        
        if (in_array(strtolower($ext), ['jpg', 'jpeg', 'png', 'gif'])) {
            $profile_pic = $upload_dir . $user_id . '_' . time() . '.' . $ext;
            if (!move_uploaded_file($_FILES['profile_pic']['tmp_name'], $profile_pic)) {
                $error = 'Failed to upload profile picture';
                $profile_pic = null;
            }
        } else {
            $error = 'Invalid file format. Please upload an image.';
        }
    }
    
    if (!$error) {
        if (updateUserProfile($user_id, $full_name, $profile_pic)) {
            $_SESSION['full_name'] = $full_name;
            $message = 'Profile updated successfully!';
            $user = getUserById($user_id);
        } else {
            $error = 'Failed to update profile';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - Smart Study Planner</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #3498db 0%, #2ecc71 100%);
            min-height: 100vh;
            padding: 2rem 1rem;
        }
        
        .container {
            max-width: 600px;
            margin: 0 auto;
            background: white;
            border-radius: 10px;
            padding: 2rem;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
        }
        
        h1 {
            color: #2c3e50;
            margin-bottom: 2rem;
            text-align: center;
        }
        
        .profile-pic {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            margin: 0 auto 2rem;
            background: #ecf0f1;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .profile-pic img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .profile-pic-placeholder {
            font-size: 3rem;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        label {
            display: block;
            margin-bottom: 0.5rem;
            color: #2c3e50;
            font-weight: 600;
        }
        
        input, textarea {
            width: 100%;
            padding: 0.8rem;
            border: 2px solid #ecf0f1;
            border-radius: 5px;
            font-family: inherit;
            font-size: 1rem;
            transition: border-color 0.3s;
        }
        
        input:focus, textarea:focus {
            outline: none;
            border-color: #3498db;
        }
        
        .form-group.readonly input {
            background-color: #f8f9fa;
            cursor: not-allowed;
        }
        
        .message {
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 1.5rem;
        }
        
        .success {
            background-color: #d5f4e6;
            color: #27ae60;
            border-left: 4px solid #27ae60;
        }
        
        .error {
            background-color: #fadbd8;
            color: #c0392b;
            border-left: 4px solid #c0392b;
        }
        
        .btn {
            padding: 0.9rem;
            border: none;
            border-radius: 5px;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s;
            font-weight: 600;
            width: 100%;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
            color: white;
            margin-bottom: 0.5rem;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(52, 152, 219, 0.3);
        }
        
        .btn-secondary {
            background-color: #ecf0f1;
            color: #2c3e50;
        }
        
        .btn-secondary:hover {
            background-color: #d5dbdb;
        }
        
        .info-section {
            background-color: #f8f9fa;
            padding: 1.5rem;
            border-radius: 5px;
            margin-bottom: 1.5rem;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.8rem;
            padding-bottom: 0.8rem;
            border-bottom: 1px solid #ecf0f1;
        }
        
        .info-row:last-child {
            margin-bottom: 0;
            border-bottom: none;
        }
        
        .info-label {
            color: #7f8c8d;
            font-weight: 600;
        }
        
        .info-value {
            color: #2c3e50;
        }
        
        .back-link {
            display: block;
            text-align: center;
            margin-top: 1rem;
            color: #3498db;
            text-decoration: none;
        }
        
        .back-link:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>👤 My Profile</h1>
        
        <?php if ($message): ?>
            <div class="message success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="message error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <div class="profile-pic">
            <?php if ($user['profile_pic'] && file_exists($user['profile_pic'])): ?>
                <img src="<?php echo htmlspecialchars($user['profile_pic']); ?>" alt="Profile">
            <?php else: ?>
                <div class="profile-pic-placeholder">👤</div>
            <?php endif; ?>
        </div>
        
        <form method="POST" enctype="multipart/form-data">
            <div class="info-section">
                <div class="info-row">
                    <span class="info-label">Email:</span>
                    <span class="info-value"><?php echo htmlspecialchars($user['email']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Username:</span>
                    <span class="info-value"><?php echo htmlspecialchars($user['username']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Role:</span>
                    <span class="info-value"><?php echo ucfirst($user['role']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Status:</span>
                    <span class="info-value"><?php echo ucfirst($user['status']); ?></span>
                </div>
            </div>
            
            <div class="form-group">
                <label for="full_name">Full Name</label>
                <input type="text" id="full_name" name="full_name" value="<?php echo htmlspecialchars($user['full_name']); ?>" required>
            </div>
            
            <div class="form-group">
                <label for="profile_pic">Profile Picture</label>
                <input type="file" id="profile_pic" name="profile_pic" accept=".jpg,.jpeg,.png,.gif">
                <small>Supported formats: JPG, PNG, GIF</small>
            </div>
            
            <button type="submit" class="btn btn-primary">Update Profile</button>
            <a href="<?php echo ($_SESSION['role'] == 'student' ? 'student/dashboard.php' : ($_SESSION['role'] == 'teacher' ? 'teacher/dashboard.php' : 'admin/dashboard.php')); ?>" class="btn btn-secondary">Back to Dashboard</a>
        </form>
        
        <a href="logout.php" class="back-link">Logout</a>
    </div>
</body>
</html>
