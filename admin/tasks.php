<?php
session_start();
require_once '../config/db_config.php';
require_once '../config/auth.php';
require_once '../config/admin.php';

if (!checkUserRole('admin')) {
    header('Location: ../login.php');
    exit();
}

// Handle task actions
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $task_id = intval($_POST['task_id']);
    
    if ($_POST['action'] == 'delete_task') {
        // Delete task (this will cascade to submissions and files)
        $query = "DELETE FROM tasks WHERE id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $task_id);
        
        if ($stmt->execute()) {
            header('Location: tasks.php?task_deleted=1');
            exit();
        }
    }
}

// Get filter parameters
$status_filter = $_GET['status'] ?? '';
$teacher_filter = $_GET['teacher'] ?? '';
$search = $_GET['search'] ?? '';
$limit = intval($_GET['limit'] ?? 50);
$offset = intval($_GET['offset'] ?? 0);

// Build query
$query = "SELECT t.*, u.full_name as teacher_name, u.email as teacher_email,
          COUNT(ts.id) as submission_count,
          COUNT(CASE WHEN ts.status = 'graded' THEN 1 END) as graded_count
          FROM tasks t
          LEFT JOIN users u ON t.teacher_id = u.id
          LEFT JOIN task_submissions ts ON t.id = ts.task_id";

$where_conditions = [];
$params = [];
$types = '';

if ($status_filter) {
    $where_conditions[] = "t.status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

if ($teacher_filter) {
    $where_conditions[] = "t.teacher_id = ?";
    $params[] = $teacher_filter;
    $types .= 'i';
}

if ($search) {
    $where_conditions[] = "(t.title LIKE ? OR t.description LIKE ? OR u.full_name LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= 'sss';
}

if (!empty($where_conditions)) {
    $query .= " WHERE " . implode(" AND ", $where_conditions);
}

$query .= " GROUP BY t.id ORDER BY t.created_at DESC LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;
$types .= 'ii';

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$tasks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get total count for pagination
$count_query = "SELECT COUNT(*) as total FROM tasks t LEFT JOIN users u ON t.teacher_id = u.id";
if (!empty($where_conditions)) {
    $count_query .= " WHERE " . implode(" AND ", $where_conditions);
}
$count_stmt = $conn->prepare($count_query);
if (!empty(array_slice($params, 0, -2))) { // Remove limit and offset for count
    $count_stmt->bind_param(substr($types, 0, -2), ...array_slice($params, 0, -2));
}
$count_stmt->execute();
$total_tasks = $count_stmt->get_result()->fetch_assoc()['total'];

// Get teachers for filter
$teachers_query = "SELECT id, full_name FROM users WHERE role = 'teacher' ORDER BY full_name";
$teachers = $conn->query($teachers_query)->fetch_all(MYSQLI_ASSOC);

// Get task statistics
$task_stats = getAdminTaskStatistics();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Task Management - Admin Dashboard</title>
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

        .stat-box.pending { border-left-color: #f39c12; }
        .stat-box.completed { border-left-color: #27ae60; }
        .stat-box.overdue { border-left-color: #e74c3c; }

        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            color: var(--primary);
        }

        .stat-box.pending .stat-number { color: #f39c12; }
        .stat-box.completed .stat-number { color: #27ae60; }
        .stat-box.overdue .stat-number { color: #e74c3c; }

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

        .badge-pending {
            background-color: #fff3cd;
            color: #856404;
        }

        .badge-completed {
            background-color: #d4edda;
            color: #155724;
        }

        .badge-overdue {
            background-color: #f8d7da;
            color: #721c24;
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

        .pagination {
            display: flex;
            justify-content: center;
            gap: 0.5rem;
            margin-top: 2rem;
        }

        .pagination a, .pagination span {
            padding: 0.5rem 1rem;
            border: 1px solid #ddd;
            border-radius: 5px;
            text-decoration: none;
            color: var(--text);
        }

        .pagination a:hover, .pagination .active {
            background-color: var(--accent);
            color: white;
            border-color: var(--accent);
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

        .task-description {
            max-width: 300px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
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
            <a href="tasks.php" class="active">Tasks</a>
            <a href="analytics.php">Analytics</a>
            <a href="../profile.php">Profile</a>
            <a href="../logout.php">Logout</a>
        </div>
    </nav>

    <div class="container">
        <div class="page-header">
            <h1>📚 Task Management</h1>
            <p>Monitor and manage all tasks in the system</p>
        </div>

        <?php if (isset($_GET['task_deleted'])): ?>
            <div class="alert alert-success">Task deleted successfully!</div>
        <?php endif; ?>

        <div class="stats-grid">
            <div class="stat-box">
                <div class="stat-number"><?php echo $task_stats['total_tasks']; ?></div>
                <div class="stat-label">Total Tasks</div>
            </div>
            <div class="stat-box pending">
                <div class="stat-number"><?php echo $task_stats['pending_tasks']; ?></div>
                <div class="stat-label">Pending Tasks</div>
            </div>
            <div class="stat-box completed">
                <div class="stat-number"><?php echo $task_stats['completed_tasks']; ?></div>
                <div class="stat-label">Completed Tasks</div>
            </div>
            <div class="stat-box overdue">
                <div class="stat-number"><?php echo $task_stats['overdue_tasks'] ?? 0; ?></div>
                <div class="stat-label">Overdue Tasks</div>
            </div>
        </div>

        <div class="filters">
            <form method="GET">
                <div class="form-group">
                    <label for="status">Filter by Status</label>
                    <select id="status" name="status">
                        <option value="">All Status</option>
                        <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="overdue" <?php echo $status_filter === 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="teacher">Filter by Teacher</label>
                    <select id="teacher" name="teacher">
                        <option value="">All Teachers</option>
                        <?php foreach ($teachers as $teacher): ?>
                            <option value="<?php echo $teacher['id']; ?>" <?php echo $teacher_filter == $teacher['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($teacher['full_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="search">Search</label>
                    <input type="text" id="search" name="search" placeholder="Task title or description" value="<?php echo htmlspecialchars($search); ?>">
                </div>

                <div class="form-group">
                    <label for="limit">Show</label>
                    <select id="limit" name="limit">
                        <option value="25" <?php echo $limit == 25 ? 'selected' : ''; ?>>25 per page</option>
                        <option value="50" <?php echo $limit == 50 ? 'selected' : ''; ?>>50 per page</option>
                        <option value="100" <?php echo $limit == 100 ? 'selected' : ''; ?>>100 per page</option>
                    </select>
                </div>

                <div class="form-group">
                    <button type="submit" class="btn btn-primary">Filter</button>
                    <a href="tasks.php" class="btn" style="background: #ecf0f1; color: #2c3e50; margin-left: 0.5rem;">Clear</a>
                </div>
            </form>
        </div>

        <div class="card">
            <h2>Tasks (<?php echo $total_tasks; ?> total)</h2>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Title</th>
                        <th>Description</th>
                        <th>Teacher</th>
                        <th>Due Date</th>
                        <th>Status</th>
                        <th>Submissions</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tasks as $task): ?>
                        <tr>
                            <td><?php echo $task['id']; ?></td>
                            <td><?php echo htmlspecialchars($task['title']); ?></td>
                            <td class="task-description"><?php echo htmlspecialchars(substr($task['description'] ?? '', 0, 100)); ?></td>
                            <td><?php echo htmlspecialchars($task['teacher_name'] ?? 'Unknown'); ?></td>
                            <td><?php echo date('M d, Y H:i', strtotime($task['due_date'])); ?></td>
                            <td><span class="badge badge-<?php echo $task['status']; ?>"><?php echo ucfirst($task['status']); ?></span></td>
                            <td><?php echo $task['submission_count']; ?> (<?php echo $task['graded_count']; ?> graded)</td>
                            <td><?php echo date('M d, Y', strtotime($task['created_at'])); ?></td>
                            <td>
                                <div class="actions">
                                    <a href="../view_task.php?id=<?php echo $task['id']; ?>" class="btn btn-primary btn-sm" target="_blank">View</a>
                                    <button class="btn btn-danger btn-sm" onclick="deleteTask(<?php echo $task['id']; ?>, '<?php echo htmlspecialchars($task['title']); ?>')">Delete</button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($total_tasks > $limit): ?>
                <div class="pagination">
                    <?php
                    $total_pages = ceil($total_tasks / $limit);
                    $current_page = floor($offset / $limit) + 1;

                    // Previous button
                    if ($current_page > 1) {
                        $prev_offset = $offset - $limit;
                        echo "<a href='?status=$status_filter&teacher=$teacher_filter&search=" . urlencode($search) . "&limit=$limit&offset=$prev_offset'>« Previous</a>";
                    }

                    // Page numbers
                    for ($i = max(1, $current_page - 2); $i <= min($total_pages, $current_page + 2); $i++) {
                        $page_offset = ($i - 1) * $limit;
                        $active_class = $i == $current_page ? 'active' : '';
                        echo "<a href='?status=$status_filter&teacher=$teacher_filter&search=" . urlencode($search) . "&limit=$limit&offset=$page_offset' class='$active_class'>$i</a>";
                    }

                    // Next button
                    if ($current_page < $total_pages) {
                        $next_offset = $offset + $limit;
                        echo "<a href='?status=$status_filter&teacher=$teacher_filter&search=" . urlencode($search) . "&limit=$limit&offset=$next_offset'>Next »</a>";
                    }
                    ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal" id="deleteModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
        <div class="modal-content" style="background: white; padding: 2rem; border-radius: 10px; max-width: 500px; width: 90%; animation: slideUp 0.3s ease;">
            <h2>Confirm Delete</h2>
            <p>Are you sure you want to delete task "<span id="deleteTaskTitle"></span>"? This will also delete all associated submissions and files. This action cannot be undone.</p>
            <form method="POST" id="deleteForm">
                <input type="hidden" name="task_id" id="deleteTaskId" value="">
                <input type="hidden" name="action" value="delete_task">
                <div style="display: flex; gap: 1rem; margin-top: 1rem;">
                    <button type="submit" class="btn btn-danger" style="flex: 1;">Delete Task</button>
                    <button type="button" class="btn" onclick="closeDeleteModal()" style="flex: 1; background: #ecf0f1; color: #2c3e50;">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function deleteTask(taskId, taskTitle) {
            document.getElementById('deleteTaskId').value = taskId;
            document.getElementById('deleteTaskTitle').textContent = taskTitle;
            document.getElementById('deleteModal').style.display = 'flex';
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }
    </script>
</body>
</html>