<?php
session_start();
require_once '../config/db_config.php';
require_once '../config/auth.php';
require_once '../config/admin.php';

if (!checkUserRole('admin')) {
    header('Location: ../login.php');
    exit();
}

$admin_id = $_SESSION['user_id'];
$stats = getSystemStatistics();
$users = getAllUsers();
$teachers = getAllUsers('teacher');
$students = getAllUsers('student');

// Handle user status change
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'change_status') {
        $user_id = intval($_POST['user_id']);
        $status = $_POST['status'];
        if (updateUserStatus($user_id, $status)) {
            header('Location: dashboard.php?status_updated=1');
            exit();
        }
    } elseif ($_POST['action'] == 'change_role') {
        $user_id = intval($_POST['user_id']);
        $new_role = $_POST['role'];
        if (changeUserRole($user_id, $new_role)) {
            header('Location: dashboard.php?role_updated=1');
            exit();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Smart Study Planner</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        :root {
            --primary: #e74c3c;
            --secondary: #34495e;
            --accent: #3498db;
            --dark: #2c3e50;
            --light: #ecf0f1;
            --text: #34495e;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f5f7fa;
            color: var(--text);
        }
        
        .navbar {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .navbar-brand {
            font-size: 1.3rem;
            font-weight: bold;
        }
        
        .navbar-menu {
            display: flex;
            gap: 2rem;
            align-items: center;
        }
        
        .navbar-menu a {
            color: white;
            text-decoration: none;
            transition: 0.3s;
        }
        
        .navbar-menu a:hover {
            opacity: 0.8;
        }
        
        .container {
            max-width: 1400px;
            margin: 2rem auto;
            padding: 0 1rem;
        }
        
        .dashboard-header {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 2rem;
        }
        
        .dashboard-header h1 {
            color: var(--dark);
            margin-bottom: 0.5rem;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .stat-box {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            border-left: 4px solid var(--primary);
        }
        
        .stat-box.teachers {
            border-left-color: #3498db;
        }
        
        .stat-box.students {
            border-left-color: #2ecc71;
        }
        
        .stat-box.tasks {
            border-left-color: #f39c12;
        }
        
        .stat-box.submissions {
            border-left-color: #9b59b6;
        }
        
        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            color: var(--primary);
        }
        
        .stat-box.teachers .stat-number { color: #3498db; }
        .stat-box.students .stat-number { color: #2ecc71; }
        .stat-box.tasks .stat-number { color: #f39c12; }
        .stat-box.submissions .stat-number { color: #9b59b6; }
        
        .stat-label {
            color: #7f8c8d;
            font-size: 0.95rem;
            margin-top: 0.5rem;
        }
        
        .card {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 2rem;
        }
        
        .card h2 {
            color: var(--dark);
            margin-bottom: 1.5rem;
            font-size: 1.3rem;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        table thead {
            background-color: #f8f9fa;
        }
        
        table th {
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: var(--dark);
            border-bottom: 2px solid #ecf0f1;
        }
        
        table td {
            padding: 1rem;
            border-bottom: 1px solid #ecf0f1;
        }
        
        table tbody tr:hover {
            background-color: #f8f9fa;
        }
        
        .badge {
            display: inline-block;
            padding: 0.3rem 0.8rem;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        
        .badge-active {
            background-color: #d4edda;
            color: #155724;
        }
        
        .badge-inactive {
            background-color: #f8d7da;
            color: #721c24;
        }
        
        .badge-student {
            background-color: #cce5ff;
            color: #004085;
        }
        
        .badge-teacher {
            background-color: #d1ecf1;
            color: #0c5460;
        }
        
        .badge-admin {
            background-color: #e2e3e5;
            color: #383d41;
        }
        
        .actions {
            display: flex;
            gap: 0.5rem;
        }
        
        .btn {
            padding: 0.4rem 0.8rem;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: 0.85rem;
            transition: 0.3s;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-primary {
            background-color: var(--accent);
            color: white;
        }
        
        .btn-primary:hover {
            opacity: 0.9;
        }
        
        .btn-danger {
            background-color: #e74c3c;
            color: white;
        }
        
        .btn-danger:hover {
            opacity: 0.9;
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .modal.active {
            display: flex;
        }
        
        .modal-content {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            max-width: 500px;
            width: 90%;
            animation: slideUp 0.3s ease;
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .form-group {
            margin-bottom: 1rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
        }
        
        .form-group select {
            width: 100%;
            padding: 0.7rem;
            border: 2px solid #ecf0f1;
            border-radius: 5px;
        }
        
        @media (max-width: 1024px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            table {
                font-size: 0.9rem;
            }
            
            table th, table td {
                padding: 0.7rem;
            }
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="navbar-brand">🔐 Admin Dashboard</div>
        <div class="navbar-menu">
            <a href="dashboard.php">Dashboard</a>
            <a href="users.php">Users</a>
            <a href="tasks.php">Tasks</a>
            <a href="analytics.php">Analytics</a>
            <a href="../profile.php">Profile</a>
            <a href="../logout.php">Logout</a>
        </div>
    </nav>

    <div class="container">
        <div class="dashboard-header">
            <h1>🎯 Admin Dashboard</h1>
            <p>System Overview & Management</p>
        </div>

        <div class="stats-grid">
            <div class="stat-box">
                <div class="stat-number"><?php echo $stats['users']['total_users']; ?></div>
                <div class="stat-label">Total Users</div>
            </div>
            <div class="stat-box teachers">
                <div class="stat-number"><?php echo $stats['users']['total_teachers']; ?></div>
                <div class="stat-label">Teachers</div>
            </div>
            <div class="stat-box students">
                <div class="stat-number"><?php echo $stats['users']['total_students']; ?></div>
                <div class="stat-label">Students</div>
            </div>
            <div class="stat-box">
                <div class="stat-number"><?php echo $stats['users']['active_users']; ?></div>
                <div class="stat-label">Active Users</div>
            </div>
            <div class="stat-box tasks">
                <div class="stat-number"><?php echo $stats['tasks']['total_tasks']; ?></div>
                <div class="stat-label">Total Tasks</div>
            </div>
            <div class="stat-box submissions">
                <div class="stat-number"><?php echo $stats['submissions']['total_submissions']; ?></div>
                <div class="stat-label">Total Submissions</div>
            </div>
            <div class="stat-box">
                <div class="stat-number"><?php echo $stats['notifications']['total_notifications']; ?></div>
                <div class="stat-label">Notifications</div>
            </div>
        </div>

        <div class="card">
            <h2>👥 User Management</h2>
            <table>
                <thead>
                    <tr>
                        <th>Full Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($users, 0, 10) as $user): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                            <td><span class="badge badge-<?php echo $user['role']; ?>"><?php echo ucfirst($user['role']); ?></span></td>
                            <td><span class="badge badge-<?php echo $user['status']; ?>"><?php echo ucfirst($user['status']); ?></span></td>
                            <td>
                                <div class="actions">
                                    <button class="btn btn-primary" onclick="openUserModal(<?php echo $user['id']; ?>, '<?php echo $user['role']; ?>', '<?php echo $user['status']; ?>')">Manage</button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p style="margin-top: 1rem;"><a href="users.php">View All Users →</a></p>
        </div>

        <div class="card">
            <h2>📊 System Overview</h2>
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem;">
                <div style="background: #f8f9fa; padding: 1rem; border-radius: 5px;">
                    <h3 style="color: #3498db; margin-bottom: 0.5rem;">Task Statistics</h3>
                    <p>Total: <?php echo $stats['tasks']['total_tasks']; ?> tasks</p>
                    <p>Pending: <?php echo $stats['tasks']['pending_tasks']; ?> tasks</p>
                    <p>Completed: <?php echo $stats['tasks']['completed_tasks']; ?> tasks</p>
                </div>
                <div style="background: #f8f9fa; padding: 1rem; border-radius: 5px;">
                    <h3 style="color: #2ecc71; margin-bottom: 0.5rem;">Submission Statistics</h3>
                    <p>Total Submissions: <?php echo $stats['submissions']['total_submissions']; ?></p>
                    <p>Avg. Success Rate: N/A</p>
                </div>
                <div style="background: #f8f9fa; padding: 1rem; border-radius: 5px;">
                    <h3 style="color: #9b59b6; margin-bottom: 0.5rem;">User Activity</h3>
                    <p>Active Users: <?php echo $stats['users']['active_users']; ?></p>
                    <p>Inactive Users: <?php echo ($stats['users']['total_users'] - $stats['users']['active_users']); ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- User Management Modal -->
    <div class="modal" id="userModal">
        <div class="modal-content">
            <h2>Manage User</h2>
            <form method="POST">
                <input type="hidden" name="user_id" id="userId" value="">
                
                <div class="form-group">
                    <label for="role">Change Role</label>
                    <select id="role" name="role">
                        <option value="student">Student</option>
                        <option value="teacher">Teacher</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="status">Change Status</label>
                    <select id="status" name="status">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
                </div>
                
                <div style="display: flex; gap: 1rem;">
                    <button type="button" class="btn btn-primary" onclick="submitUserAction('change_role')" style="flex: 1; padding: 0.7rem;">Change Role</button>
                    <button type="button" class="btn btn-primary" onclick="submitUserAction('change_status')" style="flex: 1; padding: 0.7rem;">Change Status</button>
                    <button type="button" class="btn btn-danger" onclick="closeUserModal()" style="flex: 1; padding: 0.7rem;">Close</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openUserModal(userId, role, status) {
            document.getElementById('userId').value = userId;
            document.getElementById('role').value = role;
            document.getElementById('status').value = status;
            document.getElementById('userModal').classList.add('active');
        }
        
        function closeUserModal() {
            document.getElementById('userModal').classList.remove('active');
        }
        
        function submitUserAction(action) {
            const form = document.querySelector('#userModal form');
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'action';
            input.value = action;
            form.appendChild(input);
            form.submit();
        }
    </script>
</body>
</html>
