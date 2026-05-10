<?php
session_start();
require_once '../config/db_config.php';
require_once '../config/auth.php';
require_once '../config/tasks.php';
require_once '../config/notifications.php';

if (!checkUserRole('student')) {
    header('Location: ../login.php');
    exit();
}

$student_id = $_SESSION['user_id'];
$tasks = getStudentAvailableTasks($student_id);

// Remove tasks already submitted by this student (submitted/approved/rejected)
// so the "All Tasks" page only shows pending tasks to complete.
$sub_stmt = $conn->prepare(
    "SELECT task_id, status FROM task_submissions WHERE student_id = ?"
);
$sub_stmt->bind_param('i', $student_id);
$sub_stmt->execute();
$submitted = [];
foreach ($sub_stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
    if (in_array($row['status'], ['submitted', 'approved', 'rejected'], true)) {
        $submitted[(int)$row['task_id']] = true;
    }
}

if (!empty($submitted)) {
    $tasks = array_values(array_filter($tasks, function ($t) use ($submitted) {
        return empty($submitted[(int)$t['id']]);
    }));
}

$notifications = getUserNotifications($student_id, 10);
$unread_count = getUnreadNotificationsCount($student_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Tasks - Smart Study Planner</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary: #3498db;
            --secondary: #2ecc71;
            --accent: #e74c3c;
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
            gap: 1.5rem;
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

        .badge {
            background-color: var(--accent);
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.8rem;
            font-weight: bold;
            margin-left: 0.25rem;
        }

        .container {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 1rem;
        }

        .page-header {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 1.5rem;
        }

        .page-header h1 {
            color: var(--dark);
            margin-bottom: 0.5rem;
        }

        .page-header p {
            color: #7f8c8d;
        }

        .task-list {
            display: grid;
            gap: 1rem;
        }

        .task-card {
            background: white;
            border-radius: 10px;
            padding: 1.5rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            display: grid;
            gap: 0.75rem;
        }

        .task-card h2 {
            color: var(--primary);
            font-size: 1.1rem;
        }

        .task-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
            color: #7f8c8d;
            font-size: 0.95rem;
        }

        .task-meta span {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
        }

        .task-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            margin-top: 1rem;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.75rem 1.2rem;
            border: none;
            border-radius: 8px;
            background: var(--primary);
            color: white;
            text-decoration: none;
            cursor: pointer;
            transition: background 0.3s;
        }

        .btn.secondary {
            background: var(--dark);
        }

        .btn:hover {
            background: #2980b9;
        }

        .btn.secondary:hover {
            background: #2c3e50;
        }

        .empty-state {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            text-align: center;
        }

        .empty-state h3 {
            margin-bottom: 0.75rem;
            color: var(--dark);
        }

        @media (max-width: 768px) {
            .navbar {
                flex-direction: column;
                align-items: flex-start;
            }

            .navbar-menu {
                flex-wrap: wrap;
            }
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div class="navbar-brand">📚 Smart Study Planner</div>
        <div class="navbar-menu">
            <a href="dashboard.php">Dashboard</a>
            <a href="tasks.php">All Tasks</a>
            <a href="grades.php">My Grades</a>
            <a href="../calendar.php">Calendar</a>
            <a href="../profile.php">Profile</a>
            <a href="../logout.php">Logout</a>
        </div>
    </nav>

    <div class="container">
        <div class="page-header">
            <h1>All Available Tasks</h1>
            <p>Review all active assignments, due dates, and teacher details.</p>
        </div>

        <?php if (empty($tasks)): ?>
            <div class="empty-state">
                <h3>No tasks available yet</h3>
                <p>Check back later or visit your dashboard for updates.</p>
                <div class="task-actions" style="justify-content:center; margin-top: 1.5rem;">
                    <a href="dashboard.php" class="btn secondary">Back to Dashboard</a>
                    <a href="../calendar.php" class="btn">Open Calendar</a>
                </div>
            </div>
        <?php else: ?>
            <div class="task-list">
                <?php foreach ($tasks as $task): ?>
                    <div class="task-card">
                        <h2><?php echo htmlspecialchars($task['title']); ?></h2>
                        <div class="task-meta">
                            <span>Subject: <?php echo htmlspecialchars($task['subject']); ?></span>
                            <span>Teacher: <?php echo htmlspecialchars($task['teacher_name']); ?></span>
                            <span>Due: <?php echo date('M d, Y', strtotime($task['due_date'])); ?></span>
                        </div>
                        <p><?php echo nl2br(htmlspecialchars($task['description'])); ?></p>
                        <div class="task-actions">
                            <a href="dashboard.php" class="btn secondary">Submit from Dashboard</a>
                            <a href="../calendar.php" class="btn">View Calendar</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
