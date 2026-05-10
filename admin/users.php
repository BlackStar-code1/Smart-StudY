<?php
session_start();
require_once '../config/db_config.php';
require_once '../config/auth.php';
require_once '../config/admin.php';

if (!checkUserRole('admin')) {
    header('Location: ../login.php');
    exit();
}

// Handle user actions
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $user_id = intval($_POST['user_id']);
    
    if ($_POST['action'] == 'change_status') {
        $status = $_POST['status'];
        if (updateUserStatus($user_id, $status)) {
            header('Location: users.php?status_updated=1');
            exit();
        }
    } elseif ($_POST['action'] == 'change_role') {
        $new_role = $_POST['role'];
        if (changeUserRole($user_id, $new_role)) {
            header('Location: users.php?role_updated=1');
            exit();
        }
    } elseif ($_POST['action'] == 'delete_user') {
        if (deleteUser($user_id)) {
            header('Location: users.php?user_deleted=1');
            exit();
        }
    }
}

// Get filter parameters
$role_filter = $_GET['role'] ?? '';
$status_filter = $_GET['status'] ?? '';
$search = $_GET['search'] ?? '';

// Build query
$query = "SELECT * FROM users WHERE 1=1";
$params = [];
$types = '';

if ($role_filter) {
    $query .= " AND role = ?";
    $params[] = $role_filter;
    $types .= 's';
}

if ($status_filter) {
    $query .= " AND status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

if ($search) {
    $query .= " AND (full_name LIKE ? OR email LIKE ? OR username LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= 'sss';
}

$query .= " ORDER BY created_at DESC";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get statistics
$user_stats = getUserStatistics();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - Admin Dashboard</title>
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

        .navbar-menu a:hover, .navbar-menu a.active {
            opacity: 0.8;
            font-weight: bold;
        }

        .container {
            max-width: 1400px;
            margin: 2rem auto;
            padding: 0 1rem;
        }

        .page-header {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 2rem;
        }

        .page-header h1 {
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
            text-align: center;
        }

        .stat-box.teachers { border-left-color: #3498db; }
        .stat-box.students { border-left-color: #2ecc71; }
        .stat-box.admins { border-left-color: #9b59b6; }

        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            color: var(--primary);
        }

        .stat-box.teachers .stat-number { color: #3498db; }
        .stat-box.students .stat-number { color: #2ecc71; }
        .stat-box.admins .stat-number { color: #9b59b6; }

        .stat-label {
            color: #7f8c8d;
            font-size: 0.9rem;
            margin-top: 0.5rem;
        }

        .filters {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 2rem;
        }

        .filters form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            align-items: end;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: var(--dark);
        }

        .form-group input, .form-group select {
            padding: 0.7rem;
            border: 2px solid #ecf0f1;
            border-radius: 5px;
            font-size: 1rem;
        }

        .btn {
            padding: 0.7rem 1.5rem;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: 600;
            transition: 0.3s;
            text-decoration: none;
            display: inline-block;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--accent) 0%, #2980b9 100%);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(52, 152, 219, 0.3);
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

        .btn-sm {
            padding: 0.4rem 0.8rem;
            font-size: 0.85rem;
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

        .alert {
            padding: 1rem;
            border-radius: 5px;
            margin-bottom: 1rem;
        }

        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
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
            <a href="users.php" class="active">Users</a>
            <a href="tasks.php">Tasks</a>
            <a href="analytics.php">Analytics</a>
            <a href="../profile.php">Profile</a>
            <a href="../logout.php">Logout</a>
        </div>
    </nav>

    <div class="container">
        <div class="page-header">
            <h1>👥 User Management</h1>
            <p>Manage all users, roles, and permissions</p>
        </div>

        <?php if (isset($_GET['status_updated'])): ?>
            <div class="alert alert-success">User status updated successfully!</div>
        <?php endif; ?>

        <?php if (isset($_GET['role_updated'])): ?>
            <div class="alert alert-success">User role updated successfully!</div>
        <?php endif; ?>

        <?php if (isset($_GET['user_deleted'])): ?>
            <div class="alert alert-success">User deleted successfully!</div>
        <?php endif; ?>

        <div class="stats-grid">
            <div class="stat-box">
                <div class="stat-number"><?php echo $user_stats['total_users']; ?></div>
                <div class="stat-label">Total Users</div>
            </div>
            <div class="stat-box teachers">
                <div class="stat-number"><?php echo $user_stats['total_teachers']; ?></div>
                <div class="stat-label">Teachers</div>
            </div>
            <div class="stat-box students">
                <div class="stat-number"><?php echo $user_stats['total_students']; ?></div>
                <div class="stat-label">Students</div>
            </div>
            <div class="stat-box">
                <div class="stat-number"><?php echo $user_stats['active_users']; ?></div>
                <div class="stat-label">Active Users</div>
            </div>
        </div>

        <div class="filters">
            <form method="GET">
                <div class="form-group">
                    <label for="role">Filter by Role</label>
                    <select id="role" name="role">
                        <option value="">All Roles</option>
                        <option value="student" <?php echo $role_filter === 'student' ? 'selected' : ''; ?>>Student</option>
                        <option value="teacher" <?php echo $role_filter === 'teacher' ? 'selected' : ''; ?>>Teacher</option>
                        <option value="admin" <?php echo $role_filter === 'admin' ? 'selected' : ''; ?>>Admin</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="status">Filter by Status</label>
                    <select id="status" name="status">
                        <option value="">All Status</option>
                        <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="search">Search</label>
                    <input type="text" id="search" name="search" placeholder="Name, email, or username" value="<?php echo htmlspecialchars($search); ?>">
                </div>

                <div class="form-group">
                    <button type="submit" class="btn btn-primary">Filter</button>
                    <a href="users.php" class="btn" style="background: #ecf0f1; color: #2c3e50; margin-left: 0.5rem;">Clear</a>
                </div>
            </form>
        </div>

        <div class="card">
            <h2>Users (<?php echo count($users); ?>)</h2>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Full Name</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Email Verified</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td><?php echo $user['id']; ?></td>
                            <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                            <td><?php echo htmlspecialchars($user['username']); ?></td>
                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                            <td><span class="badge badge-<?php echo $user['role']; ?>"><?php echo ucfirst($user['role']); ?></span></td>
                            <td><span class="badge badge-<?php echo $user['status']; ?>"><?php echo ucfirst($user['status']); ?></span></td>
                            <td><?php echo $user['email_verified'] ? '✅' : '❌'; ?></td>
                            <td><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                            <td>
                                <div class="actions">
                                    <button class="btn btn-primary btn-sm" onclick="openUserModal(<?php echo $user['id']; ?>, '<?php echo $user['role']; ?>', '<?php echo $user['status']; ?>')">Manage</button>
                                    <button class="btn btn-danger btn-sm" onclick="deleteUser(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['full_name']); ?>')">Delete</button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
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

    <!-- Delete Confirmation Modal -->
    <div class="modal" id="deleteModal">
        <div class="modal-content">
            <h2>Confirm Delete</h2>
            <p>Are you sure you want to delete user "<span id="deleteUserName"></span>"? This action cannot be undone.</p>
            <form method="POST" id="deleteForm">
                <input type="hidden" name="user_id" id="deleteUserId" value="">
                <input type="hidden" name="action" value="delete_user">
                <div style="display: flex; gap: 1rem; margin-top: 1rem;">
                    <button type="submit" class="btn btn-danger" style="flex: 1;">Delete User</button>
                    <button type="button" class="btn" onclick="closeDeleteModal()" style="flex: 1; background: #ecf0f1; color: #2c3e50;">Cancel</button>
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

        function deleteUser(userId, userName) {
            document.getElementById('deleteUserId').value = userId;
            document.getElementById('deleteUserName').textContent = userName;
            document.getElementById('deleteModal').classList.add('active');
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').classList.remove('active');
        }
    </script>
</body>
</html>