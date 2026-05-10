<?php
session_start();
require_once '../config/db_config.php';
require_once '../config/auth.php';
require_once '../config/admin.php';

if (!checkUserRole('admin')) {
    header('Location: ../login.php');
    exit();
}

// Get analytics data
function getAnalyticsData() {
    global $conn;

    $analytics = [];

    // User registration trends (last 30 days)
    $user_trends_query = "
        SELECT DATE(created_at) as date, COUNT(*) as count
        FROM users
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY DATE(created_at)
        ORDER BY date
    ";
    $analytics['user_trends'] = $conn->query($user_trends_query)->fetch_all(MYSQLI_ASSOC);

    // Task creation trends (last 30 days)
    $task_trends_query = "
        SELECT DATE(created_at) as date, COUNT(*) as count
        FROM tasks
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY DATE(created_at)
        ORDER BY date
    ";
    $analytics['task_trends'] = $conn->query($task_trends_query)->fetch_all(MYSQLI_ASSOC);

    // Submission trends (last 30 days)
    $submission_trends_query = "
        SELECT DATE(submission_date) as date, COUNT(*) as count
        FROM task_submissions
        WHERE submission_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY DATE(submission_date)
        ORDER BY date
    ";
    $analytics['submission_trends'] = $conn->query($submission_trends_query)->fetch_all(MYSQLI_ASSOC);

    // Top performing teachers (by task completion rate)
    $teacher_performance_query = "
        SELECT
            u.id,
            u.full_name,
            COUNT(DISTINCT t.id) as total_tasks,
            COUNT(DISTINCT CASE WHEN ts.status = 'graded' THEN ts.id END) as graded_submissions,
            AVG(ts.grade) as avg_grade
        FROM users u
        LEFT JOIN tasks t ON u.id = t.teacher_id
        LEFT JOIN task_submissions ts ON t.id = ts.task_id
        WHERE u.role = 'teacher'
        GROUP BY u.id, u.full_name
        HAVING total_tasks > 0
        ORDER BY avg_grade DESC, graded_submissions DESC
        LIMIT 10
    ";
    $analytics['teacher_performance'] = $conn->query($teacher_performance_query)->fetch_all(MYSQLI_ASSOC);

    // Student engagement (submissions per student)
    $student_engagement_query = "
        SELECT
            u.id,
            u.full_name,
            COUNT(ts.id) as total_submissions,
            COUNT(DISTINCT t.id) as unique_tasks,
            AVG(ts.grade) as avg_grade
        FROM users u
        LEFT JOIN task_submissions ts ON u.id = ts.student_id
        LEFT JOIN tasks t ON ts.task_id = t.id
        WHERE u.role = 'student'
        GROUP BY u.id, u.full_name
        ORDER BY total_submissions DESC
        LIMIT 10
    ";
    $analytics['student_engagement'] = $conn->query($student_engagement_query)->fetch_all(MYSQLI_ASSOC);

    // Task status distribution
    $task_status_query = "
        SELECT status, COUNT(*) as count
        FROM tasks
        GROUP BY status
    ";
    $analytics['task_status'] = $conn->query($task_status_query)->fetch_all(MYSQLI_ASSOC);

    // Submission status distribution
    $submission_status_query = "
        SELECT status, COUNT(*) as count
        FROM task_submissions
        GROUP BY status
    ";
    $analytics['submission_status'] = $conn->query($submission_status_query)->fetch_all(MYSQLI_ASSOC);

    // Grade distribution
    $grade_distribution_query = "
        SELECT
            CASE
                WHEN grade >= 90 THEN 'A (90-100)'
                WHEN grade >= 80 THEN 'B (80-89)'
                WHEN grade >= 70 THEN 'C (70-79)'
                WHEN grade >= 60 THEN 'D (60-69)'
                ELSE 'F (0-59)'
            END as grade_range,
            COUNT(*) as count
        FROM task_submissions
        WHERE grade IS NOT NULL
        GROUP BY grade_range
        ORDER BY MIN(grade) DESC
    ";
    $analytics['grade_distribution'] = $conn->query($grade_distribution_query)->fetch_all(MYSQLI_ASSOC);

    // System health metrics
    $system_health_query = "
        SELECT
            (SELECT COUNT(*) FROM users WHERE status = 'active') as active_users,
            (SELECT COUNT(*) FROM users WHERE email_verified = 1) as verified_users,
            (SELECT COUNT(*) FROM tasks WHERE due_date < CURDATE() AND status = 'pending') as overdue_tasks,
            (SELECT COUNT(*) FROM notifications WHERE is_read = 0) as unread_notifications
    ";
    $analytics['system_health'] = $conn->query($system_health_query)->fetch_assoc();

    return $analytics;
}

$analytics = getAnalyticsData();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics - Admin Dashboard</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

        .charts-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .chart-container {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        .chart-container h3 {
            color: var(--dark);
            margin-bottom: 1rem;
            font-size: 1.2rem;
        }

        .stats-overview {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            text-align: center;
            border-left: 4px solid var(--primary);
        }

        .stat-card.success { border-left-color: #27ae60; }
        .stat-card.warning { border-left-color: #f39c12; }
        .stat-card.danger { border-left-color: #e74c3c; }
        .stat-card.info { border-left-color: #3498db; }

        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            color: var(--primary);
        }

        .stat-card.success .stat-number { color: #27ae60; }
        .stat-card.warning .stat-number { color: #f39c12; }
        .stat-card.danger .stat-number { color: #e74c3c; }
        .stat-card.info .stat-number { color: #3498db; }

        .stat-label {
            color: #7f8c8d;
            font-size: 0.9rem;
            margin-top: 0.5rem;
        }

        .data-table {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 2rem;
        }

        .data-table h3 {
            color: var(--dark);
            margin-bottom: 1rem;
            font-size: 1.2rem;
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

        .progress-bar {
            width: 100%;
            height: 8px;
            background-color: #ecf0f1;
            border-radius: 4px;
            overflow: hidden;
            margin-top: 0.5rem;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--accent) 0%, #2980b9 100%);
            border-radius: 4px;
        }

        .metric-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
        }

        .metric-card {
            background: white;
            padding: 1.5rem;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }

        .metric-card h4 {
            color: var(--dark);
            margin-bottom: 0.5rem;
            font-size: 1rem;
        }

        .metric-value {
            font-size: 1.5rem;
            font-weight: bold;
            color: var(--primary);
        }

        .metric-change {
            font-size: 0.9rem;
            margin-top: 0.5rem;
        }

        .metric-change.positive {
            color: #27ae60;
        }

        .metric-change.negative {
            color: #e74c3c;
        }

        @media (max-width: 1024px) {
            .charts-grid {
                grid-template-columns: 1fr;
            }

            .stats-overview {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .stats-overview {
                grid-template-columns: 1fr;
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
            <a href="analytics.php" class="active">Analytics</a>
            <a href="../profile.php">Profile</a>
            <a href="../logout.php">Logout</a>
        </div>
    </nav>

    <div class="container">
        <div class="page-header">
            <h1>📊 System Analytics</h1>
            <p>Comprehensive insights into system performance and user activity</p>
        </div>

        <!-- System Health Overview -->
        <div class="stats-overview">
            <div class="stat-card success">
                <div class="stat-number"><?php echo $analytics['system_health']['active_users']; ?></div>
                <div class="stat-label">Active Users</div>
            </div>
            <div class="stat-card info">
                <div class="stat-number"><?php echo $analytics['system_health']['verified_users']; ?></div>
                <div class="stat-label">Verified Users</div>
            </div>
            <div class="stat-card warning">
                <div class="stat-number"><?php echo $analytics['system_health']['overdue_tasks']; ?></div>
                <div class="stat-label">Overdue Tasks</div>
            </div>
            <div class="stat-card danger">
                <div class="stat-number"><?php echo $analytics['system_health']['unread_notifications']; ?></div>
                <div class="stat-label">Unread Notifications</div>
            </div>
        </div>

        <!-- Charts -->
        <div class="charts-grid">
            <div class="chart-container">
                <h3>User Registration Trends (Last 30 Days)</h3>
                <canvas id="userTrendsChart" width="400" height="200"></canvas>
            </div>

            <div class="chart-container">
                <h3>Task Creation Trends (Last 30 Days)</h3>
                <canvas id="taskTrendsChart" width="400" height="200"></canvas>
            </div>

            <div class="chart-container">
                <h3>Task Status Distribution</h3>
                <canvas id="taskStatusChart" width="400" height="200"></canvas>
            </div>

            <div class="chart-container">
                <h3>Grade Distribution</h3>
                <canvas id="gradeDistributionChart" width="400" height="200"></canvas>
            </div>
        </div>

        <!-- Detailed Tables -->
        <div class="data-table">
            <h3>🏆 Top Performing Teachers</h3>
            <table>
                <thead>
                    <tr>
                        <th>Teacher</th>
                        <th>Total Tasks</th>
                        <th>Graded Submissions</th>
                        <th>Average Grade</th>
                        <th>Performance</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($analytics['teacher_performance'] as $teacher): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($teacher['full_name']); ?></td>
                            <td><?php echo $teacher['total_tasks']; ?></td>
                            <td><?php echo $teacher['graded_submissions']; ?></td>
                            <td><?php echo $teacher['avg_grade'] ? number_format($teacher['avg_grade'], 1) : 'N/A'; ?></td>
                            <td>
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width: <?php echo min(100, ($teacher['avg_grade'] ?? 0)); ?>%"></div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="data-table">
            <h3>🎓 Most Active Students</h3>
            <table>
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Total Submissions</th>
                        <th>Unique Tasks</th>
                        <th>Average Grade</th>
                        <th>Engagement</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($analytics['student_engagement'] as $student): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($student['full_name']); ?></td>
                            <td><?php echo $student['total_submissions']; ?></td>
                            <td><?php echo $student['unique_tasks']; ?></td>
                            <td><?php echo $student['avg_grade'] ? number_format($student['avg_grade'], 1) : 'N/A'; ?></td>
                            <td>
                                <div class="progress-bar">
                                    <div class="progress-fill" style="width: <?php echo min(100, ($student['total_submissions'] * 10)); ?>%"></div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- System Metrics -->
        <div class="metric-grid">
            <div class="metric-card">
                <h4>User Verification Rate</h4>
                <div class="metric-value">
                    <?php
                    $total_users = array_sum(array_column($analytics['system_health'], 'active_users', 'verified_users'));
                    $verified_rate = $total_users > 0 ? ($analytics['system_health']['verified_users'] / $total_users) * 100 : 0;
                    echo number_format($verified_rate, 1) . '%';
                    ?>
                </div>
                <div class="metric-change positive">↑ <?php echo number_format($verified_rate, 1); ?>% verified</div>
            </div>

            <div class="metric-card">
                <h4>Task Completion Rate</h4>
                <div class="metric-value">
                    <?php
                    $total_tasks = array_sum(array_column($analytics['task_status'], 'count'));
                    $completed_tasks = 0;
                    foreach ($analytics['task_status'] as $status) {
                        if ($status['status'] === 'completed') {
                            $completed_tasks = $status['count'];
                            break;
                        }
                    }
                    $completion_rate = $total_tasks > 0 ? ($completed_tasks / $total_tasks) * 100 : 0;
                    echo number_format($completion_rate, 1) . '%';
                    ?>
                </div>
                <div class="metric-change <?php echo $completion_rate > 50 ? 'positive' : 'negative'; ?>">
                    <?php echo $completion_rate > 50 ? '↑' : '↓'; ?> <?php echo number_format($completion_rate, 1); ?>% completed
                </div>
            </div>

            <div class="metric-card">
                <h4>Average Grade</h4>
                <div class="metric-value">
                    <?php
                    $total_submissions = array_sum(array_column($analytics['submission_status'], 'count'));
                    $graded_submissions = 0;
                    foreach ($analytics['submission_status'] as $status) {
                        if ($status['status'] === 'graded') {
                            $graded_submissions = $status['count'];
                            break;
                        }
                    }
                    echo $graded_submissions > 0 ? number_format(($graded_submissions / $total_submissions) * 100, 1) . '%' : 'N/A';
                    ?>
                </div>
                <div class="metric-change info">Graded submissions</div>
            </div>

            <div class="metric-card">
                <h4>System Health Score</h4>
                <div class="metric-value">
                    <?php
                    $health_score = 100;
                    if ($analytics['system_health']['overdue_tasks'] > 0) $health_score -= 10;
                    if ($analytics['system_health']['unread_notifications'] > 10) $health_score -= 5;
                    $unverified_rate = $total_users > 0 ? (($total_users - $analytics['system_health']['verified_users']) / $total_users) * 100 : 0;
                    if ($unverified_rate > 20) $health_score -= 10;
                    echo $health_score . '%';
                    ?>
                </div>
                <div class="metric-change <?php echo $health_score > 80 ? 'positive' : ($health_score > 60 ? 'warning' : 'negative'); ?>">
                    <?php echo $health_score > 80 ? 'Excellent' : ($health_score > 60 ? 'Good' : 'Needs Attention'); ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        // User Trends Chart
        const userTrendsCtx = document.getElementById('userTrendsChart').getContext('2d');
        new Chart(userTrendsCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode(array_column($analytics['user_trends'], 'date')); ?>,
                datasets: [{
                    label: 'New Users',
                    data: <?php echo json_encode(array_column($analytics['user_trends'], 'count')); ?>,
                    borderColor: '#3498db',
                    backgroundColor: 'rgba(52, 152, 219, 0.1)',
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });

        // Task Trends Chart
        const taskTrendsCtx = document.getElementById('taskTrendsChart').getContext('2d');
        new Chart(taskTrendsCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode(array_column($analytics['task_trends'], 'date')); ?>,
                datasets: [{
                    label: 'New Tasks',
                    data: <?php echo json_encode(array_column($analytics['task_trends'], 'count')); ?>,
                    borderColor: '#e74c3c',
                    backgroundColor: 'rgba(231, 76, 60, 0.1)',
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });

        // Task Status Chart
        const taskStatusCtx = document.getElementById('taskStatusChart').getContext('2d');
        new Chart(taskStatusCtx, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode(array_column($analytics['task_status'], 'status')); ?>,
                datasets: [{
                    data: <?php echo json_encode(array_column($analytics['task_status'], 'count')); ?>,
                    backgroundColor: ['#f39c12', '#27ae60', '#e74c3c'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });

        // Grade Distribution Chart
        const gradeDistributionCtx = document.getElementById('gradeDistributionChart').getContext('2d');
        new Chart(gradeDistributionCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_column($analytics['grade_distribution'], 'grade_range')); ?>,
                datasets: [{
                    label: 'Students',
                    data: <?php echo json_encode(array_column($analytics['grade_distribution'], 'count')); ?>,
                    backgroundColor: '#9b59b6',
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>