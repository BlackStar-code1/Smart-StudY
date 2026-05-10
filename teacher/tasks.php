<?php
session_start();
require_once '../config/db_config.php';
require_once '../config/auth.php';
require_once '../config/tasks.php';
require_once '../config/notifications.php';

if (!checkUserRole('teacher')) {
    header('Location: ../login.php');
    exit();
}

$teacher_id = $_SESSION['user_id'];
$message = '';
$error = '';

// ── Handle POST actions ───────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // CREATE TASK
    if ($action === 'create_task') {
        $title       = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $subject     = trim($_POST['subject'] ?? '');
        $due_date    = $_POST['due_date'] ?? '';

        if (empty($title) || empty($due_date)) {
            $error = 'Title and due date are required.';
        } else {
            $result = createTask($teacher_id, $title, $description, $subject, $due_date);
            if ($result['success']) {
                $task_id = $result['task_id'];

                // File uploads
                if (!empty($_FILES['task_files']['name'][0])) {
                    $upload_dir = '../uploads/tasks/';
                    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                    foreach ($_FILES['task_files']['name'] as $k => $fname) {
                        if ($_FILES['task_files']['error'][$k] === 0) {
                            $fp = $upload_dir . time() . '_' . basename($fname);
                            if (move_uploaded_file($_FILES['task_files']['tmp_name'][$k], $fp))
                                addTaskFile($task_id, $fname, $fp, $_FILES['task_files']['size'][$k]);
                        }
                    }
                }

                // Assign to selected students
                if (!empty($_POST['assign_students'])) {
                    foreach ($_POST['assign_students'] as $sid) {
                        $sid = intval($sid);
                        $stmt = $conn->prepare("INSERT IGNORE INTO task_submissions (task_id, student_id, status) VALUES (?, ?, 'pending')");
                        $stmt->bind_param('ii', $task_id, $sid);
                        $stmt->execute();
                        createNotification($sid, 'New Task Assigned', 'You have been assigned a new task: ' . $title, 'task', $task_id);
                    }
                }

                createNotification($teacher_id, 'Task Created', 'Task created: ' . $title, 'task', $task_id);
                header('Location: tasks.php?created=1');
                exit();
            } else {
                $error = 'Failed to create task.';
            }
        }
    }

    // EDIT TASK
    elseif ($action === 'edit_task') {
        $task_id     = intval($_POST['task_id']);
        $title       = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $subject     = trim($_POST['subject'] ?? '');
        $due_date    = $_POST['due_date'] ?? '';

        // Verify ownership
        $own = $conn->prepare("SELECT id FROM tasks WHERE id = ? AND teacher_id = ?");
        $own->bind_param('ii', $task_id, $teacher_id);
        $own->execute();
        if ($own->get_result()->num_rows === 0) {
            $error = 'Task not found or access denied.';
        } elseif (empty($title) || empty($due_date)) {
            $error = 'Title and due date are required.';
        } else {
            updateTask($task_id, $title, $description, $subject, $due_date);

            // New file uploads
            if (!empty($_FILES['task_files']['name'][0])) {
                $upload_dir = '../uploads/tasks/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
                foreach ($_FILES['task_files']['name'] as $k => $fname) {
                    if ($_FILES['task_files']['error'][$k] === 0) {
                        $fp = $upload_dir . time() . '_' . basename($fname);
                        if (move_uploaded_file($_FILES['task_files']['tmp_name'][$k], $fp))
                            addTaskFile($task_id, $fname, $fp, $_FILES['task_files']['size'][$k]);
                    }
                }
            }

            header('Location: tasks.php?updated=1');
            exit();
        }
    }

    // DELETE TASK
    elseif ($action === 'delete_task') {
        $task_id = intval($_POST['task_id']);
        $own = $conn->prepare("SELECT id FROM tasks WHERE id = ? AND teacher_id = ?");
        $own->bind_param('ii', $task_id, $teacher_id);
        $own->execute();
        if ($own->get_result()->num_rows > 0) {
            deleteTask($task_id);
            header('Location: tasks.php?deleted=1');
            exit();
        }
    }

    // APPROVE SUBMISSION
    elseif ($action === 'approve_submission') {
        $submission_id = intval($_POST['submission_id']);
        $verify = $conn->prepare(
            "SELECT ts.id, ts.student_id, ts.task_id, t.title
             FROM task_submissions ts
             JOIN tasks t ON ts.task_id = t.id
             WHERE ts.id = ? AND t.teacher_id = ?"
        );
        $verify->bind_param('ii', $submission_id, $teacher_id);
        $verify->execute();
        $sub = $verify->get_result()->fetch_assoc();

        if ($sub) {
            $upd = $conn->prepare("UPDATE task_submissions SET status='approved' WHERE id=?");
            $upd->bind_param('i', $submission_id);
            $upd->execute();
            createNotification(
                $sub['student_id'],
                'Submission Approved',
                'Your submission for "' . $sub['title'] . '" has been approved by your teacher.',
                'submission',
                $submission_id
            );
            header('Location: tasks.php?approved=1&task=' . $sub['task_id']);
            exit();
        }
    }

    // GRADE SUBMISSION
    elseif ($action === 'grade_submission') {
        $submission_id = intval($_POST['submission_id']);
        $grade         = intval($_POST['grade']);
        $feedback      = trim($_POST['feedback'] ?? '');
        $grade         = max(0, min(100, $grade));

        $sub = getSubmissionById($submission_id);
        if ($sub) {
            gradeSubmission($submission_id, $grade, $feedback, $teacher_id);
            createNotification($sub['student_id'] ?? 0, 'Task Graded', 'Your submission for "' . $sub['title'] . '" has been graded: ' . $grade . '/100', 'grade', $submission_id);
            header('Location: tasks.php?graded=1&task=' . ($sub['task_id'] ?? 0));
            exit();
        }
    }

    // ASSIGN STUDENTS TO EXISTING TASK
    elseif ($action === 'assign_students') {
        $task_id = intval($_POST['task_id']);
        $own = $conn->prepare("SELECT title FROM tasks WHERE id = ? AND teacher_id = ?");
        $own->bind_param('ii', $task_id, $teacher_id);
        $own->execute();
        $task_row = $own->get_result()->fetch_assoc();

        if ($task_row && !empty($_POST['assign_students'])) {
            foreach ($_POST['assign_students'] as $sid) {
                $sid = intval($sid);
                $stmt = $conn->prepare("INSERT IGNORE INTO task_submissions (task_id, student_id, status) VALUES (?, ?, 'pending')");
                $stmt->bind_param('ii', $task_id, $sid);
                $stmt->execute();
                if ($stmt->affected_rows > 0)
                    createNotification($sid, 'New Task Assigned', 'You have been assigned: ' . $task_row['title'], 'task', $task_id);
            }
            header('Location: tasks.php?assigned=1&task=' . $task_id);
            exit();
        }
    }
}

// ── Fetch data ────────────────────────────────────────────────────────────────
$tasks = getTeacherTasks($teacher_id);
$all_students = $conn->query("SELECT id, full_name, username FROM users WHERE role='student' AND status='active' ORDER BY full_name")->fetch_all(MYSQLI_ASSOC);

// Active task for submissions panel
$view_task_id   = isset($_GET['task']) ? intval($_GET['task']) : 0;
$view_task      = $view_task_id ? getTaskById($view_task_id) : null;
$submissions    = $view_task_id ? getTaskSubmissions($view_task_id) : [];
$task_stats     = $view_task_id ? getTaskStatistics($view_task_id) : null;

// Already-assigned student IDs for the viewed task
$assigned_ids = [];
if ($view_task_id) {
    $ar = $conn->prepare("SELECT student_id FROM task_submissions WHERE task_id = ?");
    $ar->bind_param('i', $view_task_id);
    $ar->execute();
    foreach ($ar->get_result()->fetch_all(MYSQLI_ASSOC) as $row)
        $assigned_ids[] = $row['student_id'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Tasks - Smart Study Planner</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box;}
        :root{--primary:#3498db;--purple:#9b59b6;--green:#2ecc71;--red:#e74c3c;--dark:#2c3e50;--text:#34495e;}
        body{font-family:'Segoe UI',sans-serif;background:#f5f7fa;color:var(--text);}
        .navbar{background:linear-gradient(135deg,var(--primary) 0%,var(--purple) 100%);color:white;padding:1rem 2rem;display:flex;justify-content:space-between;align-items:center;box-shadow:0 2px 10px rgba(0,0,0,.1);}
        .navbar-brand{font-size:1.3rem;font-weight:bold;}
        .navbar-menu{display:flex;gap:1.5rem;align-items:center;}
        .navbar-menu a{color:white;text-decoration:none;transition:.3s;font-size:.95rem;}
        .navbar-menu a:hover,.navbar-menu a.active{opacity:.8;font-weight:bold;}
        .container{max-width:1300px;margin:2rem auto;padding:0 1rem;}
        .page-header{background:white;padding:1.5rem 2rem;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,.05);margin-bottom:1.5rem;display:flex;justify-content:space-between;align-items:center;}
        .page-header h1{color:var(--dark);font-size:1.5rem;}
        .stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.5rem;}
        .stat-box{background:white;padding:1.2rem;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,.05);text-align:center;border-top:4px solid var(--primary);}
        .stat-box.green{border-top-color:var(--green);}
        .stat-box.red{border-top-color:var(--red);}
        .stat-box.purple{border-top-color:var(--purple);}
        .stat-number{font-size:1.8rem;font-weight:bold;color:var(--primary);}
        .stat-box.green .stat-number{color:var(--green);}
        .stat-box.red .stat-number{color:var(--red);}
        .stat-box.purple .stat-number{color:var(--purple);}
        .stat-label{color:#7f8c8d;font-size:.85rem;margin-top:.3rem;}
        .layout{display:grid;grid-template-columns:1fr 420px;gap:1.5rem;}
        .card{background:white;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,.05);overflow:hidden;}
        .card-header{padding:1rem 1.5rem;border-bottom:1px solid #ecf0f1;display:flex;justify-content:space-between;align-items:center;}
        .card-header h2{color:var(--dark);font-size:1.1rem;}
        .card-body{padding:1.5rem;}
        .task-row{padding:1rem;border-left:4px solid var(--primary);background:#f8f9fa;border-radius:5px;margin-bottom:.8rem;display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;}
        .task-row.overdue{border-left-color:var(--red);}
        .task-row.completed{border-left-color:var(--green);}
        .task-row h3{color:var(--dark);font-size:1rem;margin-bottom:.3rem;}
        .task-meta{font-size:.82rem;color:#7f8c8d;}
        .badge{display:inline-block;padding:.2rem .6rem;border-radius:20px;font-size:.75rem;font-weight:600;}
        .badge-pending{background:#fff3cd;color:#856404;}
        .badge-completed{background:#d4edda;color:#155724;}
        .badge-overdue{background:#f8d7da;color:#721c24;}
        .badge-submitted{background:#cce5ff;color:#004085;}
        .badge-graded{background:#d4edda;color:#155724;}
        .btn{padding:.5rem 1rem;border:none;border-radius:5px;cursor:pointer;font-size:.85rem;font-weight:600;transition:all .2s;text-decoration:none;display:inline-block;}
        .btn-primary{background:var(--primary);color:white;}
        .btn-primary:hover{background:#2980b9;transform:translateY(-1px);}
        .btn-success{background:var(--green);color:white;}
        .btn-success:hover{background:#27ae60;}
        .btn-danger{background:var(--red);color:white;}
        .btn-danger:hover{background:#c0392b;}
        .btn-warning{background:#f39c12;color:white;}
        .btn-warning:hover{background:#e67e22;}
        .btn-secondary{background:#ecf0f1;color:var(--dark);}
        .btn-secondary:hover{background:#d5dbdb;}
        .btn-sm{padding:.3rem .7rem;font-size:.8rem;}
        .btn-group{display:flex;gap:.4rem;flex-wrap:wrap;}
        .alert{padding:.9rem 1rem;border-radius:5px;margin-bottom:1rem;font-size:.9rem;}
        .alert-success{background:#d4edda;color:#155724;border-left:4px solid var(--green);}
        .alert-error{background:#f8d7da;color:#721c24;border-left:4px solid var(--red);}
        .modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;overflow-y:auto;padding:1rem;}
        .modal.active{display:flex;}
        .modal-box{background:white;border-radius:10px;width:100%;max-width:580px;margin:auto;animation:slideUp .3s ease;}
        .modal-header{padding:1.2rem 1.5rem;border-bottom:1px solid #ecf0f1;display:flex;justify-content:space-between;align-items:center;}
        .modal-header h2{color:var(--dark);font-size:1.1rem;}
        .modal-close{background:none;border:none;font-size:1.4rem;cursor:pointer;color:#7f8c8d;line-height:1;}
        .modal-body{padding:1.5rem;}
        .form-group{margin-bottom:1rem;}
        .form-group label{display:block;margin-bottom:.4rem;font-weight:600;color:var(--dark);font-size:.9rem;}
        .form-group input,.form-group textarea,.form-group select{width:100%;padding:.7rem;border:2px solid #ecf0f1;border-radius:5px;font-family:inherit;font-size:.95rem;transition:border-color .3s;}
        .form-group input:focus,.form-group textarea:focus,.form-group select:focus{outline:none;border-color:var(--primary);}
        .student-checklist{max-height:180px;overflow-y:auto;border:2px solid #ecf0f1;border-radius:5px;padding:.5rem;}
        .student-check-item{display:flex;align-items:center;gap:.5rem;padding:.4rem .5rem;border-radius:4px;cursor:pointer;}
        .student-check-item:hover{background:#f8f9fa;}
        .student-check-item input{width:auto;}
        .submissions-list{display:grid;gap:.8rem;}
        .sub-card{background:#f8f9fa;border-radius:8px;padding:1rem;border:1px solid #ecf0f1;}
        .sub-card h4{color:var(--dark);margin-bottom:.4rem;font-size:.95rem;}
        .sub-meta{font-size:.82rem;color:#7f8c8d;margin-bottom:.6rem;}
        .grade-form{display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;margin-top:.6rem;}
        .grade-form input[type=number]{width:70px;padding:.4rem;border:2px solid #ecf0f1;border-radius:5px;font-size:.9rem;}
        .grade-form input[type=text]{flex:1;min-width:120px;padding:.4rem;border:2px solid #ecf0f1;border-radius:5px;font-size:.9rem;}
        .empty-state{text-align:center;padding:2rem;color:#7f8c8d;}
        .empty-state p{margin-bottom:1rem;}
        @keyframes slideUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
        @media(max-width:900px){.layout{grid-template-columns:1fr;}.stats-grid{grid-template-columns:repeat(2,1fr);}}
    </style>
</head>
<body>
<nav class="navbar">
    <div class="navbar-brand">📚 Smart Study Planner - Teacher</div>
    <div class="navbar-menu">
        <a href="dashboard.php">Dashboard</a>
        <a href="tasks.php" class="active">Manage Tasks</a>
        <a href="monitor.php">Monitor Students</a>
        <a href="../calendar.php">Calendar</a>
        <a href="../profile.php">Profile</a>
        <a href="../logout.php">Logout</a>
    </div>
</nav>

<div class="container">

    <div class="page-header">
        <h1>📋 Manage Tasks</h1>
        <button class="btn btn-primary" onclick="openModal('createModal')">+ Create New Task</button>
    </div>

    <?php if (isset($_GET['created'])): ?><div class="alert alert-success">✅ Task created successfully!</div><?php endif; ?>
    <?php if (isset($_GET['updated'])): ?><div class="alert alert-success">✅ Task updated successfully!</div><?php endif; ?>
    <?php if (isset($_GET['deleted'])): ?><div class="alert alert-success">🗑 Task deleted.</div><?php endif; ?>
    <?php if (isset($_GET['graded'])): ?><div class="alert alert-success">✅ Submission graded!</div><?php endif; ?>
    <?php if (isset($_GET['approved'])): ?><div class="alert alert-success">✅ Submission approved!</div><?php endif; ?>
    <?php if (isset($_GET['assigned'])): ?><div class="alert alert-success">✅ Students assigned to task!</div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error">⚠️ <?= htmlspecialchars($error) ?></div><?php endif; ?>

    <?php
    // Stats
    $total = count($tasks);
    $total_subs = 0; $pending_grade = 0; $total_assigned = 0;
    foreach ($tasks as $t) {
        $s = getTaskStatistics($t['id']);
        $total_subs    += $s['total_submissions'];
        $pending_grade += $s['pending_grading'];
        $total_assigned += $s['total_submissions'];
    }
    ?>
    <div class="stats-grid">
        <div class="stat-box"><div class="stat-number"><?= $total ?></div><div class="stat-label">Total Tasks</div></div>
        <div class="stat-box purple"><div class="stat-number"><?= $total_subs ?></div><div class="stat-label">Total Submissions</div></div>
        <div class="stat-box red"><div class="stat-number"><?= $pending_grade ?></div><div class="stat-label">Pending Grading</div></div>
        <div class="stat-box green"><div class="stat-number"><?= count($all_students) ?></div><div class="stat-label">Active Students</div></div>
    </div>

    <div class="layout">

        <!-- LEFT: Task List -->
        <div class="card">
            <div class="card-header">
                <h2>Your Tasks (<?= $total ?>)</h2>
            </div>
            <div class="card-body">
                <?php if (empty($tasks)): ?>
                    <div class="empty-state">
                        <p>No tasks yet.</p>
                        <button class="btn btn-primary" onclick="openModal('createModal')">Create your first task</button>
                    </div>
                <?php else: ?>
                    <?php foreach ($tasks as $t):
                        $s = getTaskStatistics($t['id']);
                        $is_active = ($view_task_id == $t['id']);
                        $row_class = $t['status'] === 'overdue' ? 'overdue' : ($t['status'] === 'completed' ? 'completed' : '');
                    ?>
                    <div class="task-row <?= $row_class ?>" style="<?= $is_active ? 'background:#ebf5fb;border-left-color:#2980b9;' : '' ?>">
                        <div style="flex:1;">
                            <h3><?= htmlspecialchars($t['title']) ?></h3>
                            <div class="task-meta">
                                <?= htmlspecialchars($t['subject'] ?? 'No subject') ?> &bull;
                                Due: <?= date('M d, Y', strtotime($t['due_date'])) ?> &bull;
                                <?= $s['total_submissions'] ?> submitted &bull;
                                <?= $s['pending_grading'] ?> to grade
                            </div>
                            <span class="badge badge-<?= $t['status'] ?>"><?= ucfirst($t['status']) ?></span>
                        </div>
                        <div class="btn-group">
                            <a href="tasks.php?task=<?= $t['id'] ?>" class="btn btn-primary btn-sm">Submissions</a>
                            <button class="btn btn-warning btn-sm" onclick="openEditModal(<?= $t['id'] ?>, <?= htmlspecialchars(json_encode($t), ENT_QUOTES) ?>)">Edit</button>
                            <button class="btn btn-success btn-sm" onclick="openAssignModal(<?= $t['id'] ?>, '<?= htmlspecialchars(addslashes($t['title'])) ?>')">Assign</button>
                            <button class="btn btn-danger btn-sm" onclick="confirmDelete(<?= $t['id'] ?>, '<?= htmlspecialchars(addslashes($t['title'])) ?>')">Delete</button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- RIGHT: Submissions Panel -->
        <div class="card">
            <?php if ($view_task && $task_stats): ?>
            <div class="card-header">
                <h2>📥 <?= htmlspecialchars($view_task['title']) ?></h2>
                <a href="tasks.php" class="btn btn-secondary btn-sm">✕ Close</a>
            </div>
            <div class="card-body">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem;margin-bottom:1rem;">
                    <div class="stat-box" style="padding:.8rem;"><div class="stat-number" style="font-size:1.3rem;"><?= $task_stats['total_submissions'] ?></div><div class="stat-label">Submitted</div></div>
                    <div class="stat-box green" style="padding:.8rem;"><div class="stat-number" style="font-size:1.3rem;"><?= $task_stats['graded'] ?></div><div class="stat-label">Graded</div></div>
                </div>

                <!-- Assign more students -->
                <form method="POST" style="margin-bottom:1rem;">
                    <input type="hidden" name="action" value="assign_students">
                    <input type="hidden" name="task_id" value="<?= $view_task_id ?>">
                    <div class="form-group">
                        <label>Assign More Students</label>
                        <div class="student-checklist">
                            <?php foreach ($all_students as $st): ?>
                            <?php if (!in_array($st['id'], $assigned_ids)): ?>
                            <label class="student-check-item">
                                <input type="checkbox" name="assign_students[]" value="<?= $st['id'] ?>">
                                <?= htmlspecialchars($st['full_name'] ?: $st['username']) ?>
                            </label>
                            <?php endif; ?>
                            <?php endforeach; ?>
                            <?php if (count($assigned_ids) === count($all_students)): ?>
                            <p style="color:#7f8c8d;font-size:.85rem;padding:.5rem;">All students are assigned.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-success btn-sm">Assign Selected</button>
                </form>

                <hr style="margin-bottom:1rem;">

                <h3 style="font-size:.95rem;color:var(--dark);margin-bottom:.8rem;">Submissions (<?= count($submissions) ?>)</h3>
                <?php if (empty($submissions)): ?>
                    <div class="empty-state"><p>No submissions yet.</p></div>
                <?php else: ?>
                <div class="submissions-list">
                    <?php foreach ($submissions as $sub): ?>
                    <div class="sub-card">
                        <h4><?= htmlspecialchars($sub['full_name']) ?></h4>
                        <div class="sub-meta">
                            Submitted: <?= $sub['submission_date'] ? date('M d, Y H:i', strtotime($sub['submission_date'])) : 'Not yet' ?>
                            <?php if ($sub['grade'] !== null): ?> &bull; Grade: <strong><?= $sub['grade'] ?>/100</strong><?php endif; ?>
                        </div>
                        <span class="badge badge-<?= $sub['status'] ?>"><?= ucfirst($sub['status']) ?></span>
                        <?php if (!empty($sub['notes'])): ?>
                            <p style="font-size:.82rem;margin-top:.4rem;color:#555;"><?= htmlspecialchars($sub['notes']) ?></p>
                        <?php endif; ?>
                        <?php if (!empty($sub['file_path'])): ?>
                            <a href="<?= htmlspecialchars($sub['file_path']) ?>" target="_blank" class="btn btn-secondary btn-sm" style="margin-top:.4rem;">📎 View File</a>
                        <?php endif; ?>
                        <?php if ($sub['status'] === 'submitted'): ?>
                        <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.6rem;">
                            <form method="POST" style="display:inline-flex;gap:.4rem;align-items:center;">
                                <input type="hidden" name="action" value="approve_submission">
                                <input type="hidden" name="submission_id" value="<?= $sub['id'] ?>">
                                <button type="submit" class="btn btn-primary btn-sm" onclick="return confirm('Approve this submission?')">✅ Approve</button>
                            </form>
                            <form method="POST" class="grade-form">
                                <input type="hidden" name="action" value="grade_submission">
                                <input type="hidden" name="submission_id" value="<?= $sub['id'] ?>">
                                <input type="number" name="grade" min="0" max="100" placeholder="0-100" required>
                                <input type="text" name="feedback" placeholder="Feedback (optional)">
                                <button type="submit" class="btn btn-success btn-sm">Grade</button>
                            </form>
                        </div>
                        <?php elseif ($sub['status'] === 'graded'): ?>
                            <?php if (!empty($sub['feedback'])): ?>
                            <p style="font-size:.82rem;margin-top:.4rem;color:#27ae60;">💬 <?= htmlspecialchars($sub['feedback']) ?></p>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="card-header"><h2>📥 Submissions</h2></div>
            <div class="card-body">
                <div class="empty-state">
                    <p>Click <strong>Submissions</strong> on any task to view and grade student work.</p>
                </div>
            </div>
            <?php endif; ?>
        </div>

    </div><!-- /layout -->
</div><!-- /container -->

<!-- CREATE TASK MODAL -->
<div class="modal" id="createModal">
    <div class="modal-box">
        <div class="modal-header">
            <h2>Create New Task</h2>
            <button class="modal-close" onclick="closeModal('createModal')">×</button>
        </div>
        <div class="modal-body">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="create_task">
                <div class="form-group">
                    <label>Task Title *</label>
                    <input type="text" name="title" required placeholder="e.g., Chapter 5 Assignment">
                </div>
                <div class="form-group">
                    <label>Subject</label>
                    <input type="text" name="subject" placeholder="e.g., Mathematics">
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" rows="3" placeholder="Task instructions..."></textarea>
                </div>
                <div class="form-group">
                    <label>Due Date *</label>
                    <input type="datetime-local" name="due_date" required>
                </div>
                <div class="form-group">
                    <label>Assign to Students</label>
                    <div class="student-checklist">
                        <?php foreach ($all_students as $st): ?>
                        <label class="student-check-item">
                            <input type="checkbox" name="assign_students[]" value="<?= $st['id'] ?>">
                            <?= htmlspecialchars($st['full_name'] ?: $st['username']) ?>
                        </label>
                        <?php endforeach; ?>
                        <?php if (empty($all_students)): ?>
                        <p style="color:#7f8c8d;font-size:.85rem;padding:.5rem;">No active students found.</p>
                        <?php endif; ?>
                    </div>
                    <small style="color:#7f8c8d;">Leave unchecked to make task available to all students.</small>
                </div>
                <div class="form-group">
                    <label>Attach Files (Optional)</label>
                    <input type="file" name="task_files[]" multiple accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.jpg,.png">
                </div>
                <div style="display:flex;gap:.8rem;">
                    <button type="submit" class="btn btn-primary" style="flex:1;">Create Task</button>
                    <button type="button" class="btn btn-secondary" style="flex:1;" onclick="closeModal('createModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- EDIT TASK MODAL -->
<div class="modal" id="editModal">
    <div class="modal-box">
        <div class="modal-header">
            <h2>Edit Task</h2>
            <button class="modal-close" onclick="closeModal('editModal')">×</button>
        </div>
        <div class="modal-body">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="edit_task">
                <input type="hidden" name="task_id" id="edit_task_id">
                <div class="form-group">
                    <label>Task Title *</label>
                    <input type="text" name="title" id="edit_title" required>
                </div>
                <div class="form-group">
                    <label>Subject</label>
                    <input type="text" name="subject" id="edit_subject">
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" id="edit_description" rows="3"></textarea>
                </div>
                <div class="form-group">
                    <label>Due Date *</label>
                    <input type="datetime-local" name="due_date" id="edit_due_date" required>
                </div>
                <div class="form-group">
                    <label>Add More Files (Optional)</label>
                    <input type="file" name="task_files[]" multiple accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.jpg,.png">
                </div>
                <div style="display:flex;gap:.8rem;">
                    <button type="submit" class="btn btn-warning" style="flex:1;">Save Changes</button>
                    <button type="button" class="btn btn-secondary" style="flex:1;" onclick="closeModal('editModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ASSIGN MODAL -->
<div class="modal" id="assignModal">
    <div class="modal-box">
        <div class="modal-header">
            <h2>Assign Students</h2>
            <button class="modal-close" onclick="closeModal('assignModal')">×</button>
        </div>
        <div class="modal-body">
            <p id="assign_task_name" style="margin-bottom:1rem;color:var(--dark);font-weight:600;"></p>
            <form method="POST">
                <input type="hidden" name="action" value="assign_students">
                <input type="hidden" name="task_id" id="assign_task_id">
                <div class="form-group">
                    <label>Select Students</label>
                    <div class="student-checklist" id="assign_student_list">
                        <?php foreach ($all_students as $st): ?>
                        <label class="student-check-item">
                            <input type="checkbox" name="assign_students[]" value="<?= $st['id'] ?>">
                            <?= htmlspecialchars($st['full_name'] ?: $st['username']) ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div style="display:flex;gap:.8rem;">
                    <button type="submit" class="btn btn-success" style="flex:1;">Assign</button>
                    <button type="button" class="btn btn-secondary" style="flex:1;" onclick="closeModal('assignModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- DELETE CONFIRM MODAL -->
<div class="modal" id="deleteModal">
    <div class="modal-box" style="max-width:420px;">
        <div class="modal-header">
            <h2>Confirm Delete</h2>
            <button class="modal-close" onclick="closeModal('deleteModal')">×</button>
        </div>
        <div class="modal-body">
            <p>Delete task "<strong id="delete_task_name"></strong>"? This will remove all submissions. This cannot be undone.</p>
            <form method="POST" style="margin-top:1rem;">
                <input type="hidden" name="action" value="delete_task">
                <input type="hidden" name="task_id" id="delete_task_id">
                <div style="display:flex;gap:.8rem;">
                    <button type="submit" class="btn btn-danger" style="flex:1;">Yes, Delete</button>
                    <button type="button" class="btn btn-secondary" style="flex:1;" onclick="closeModal('deleteModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openModal(id) { document.getElementById(id).classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }

function openEditModal(id, task) {
    document.getElementById('edit_task_id').value = id;
    document.getElementById('edit_title').value = task.title || '';
    document.getElementById('edit_subject').value = task.subject || '';
    document.getElementById('edit_description').value = task.description || '';
    // Format due_date for datetime-local
    if (task.due_date) {
        const d = new Date(task.due_date.replace(' ', 'T'));
        const pad = n => String(n).padStart(2,'0');
        document.getElementById('edit_due_date').value =
            d.getFullYear()+'-'+pad(d.getMonth()+1)+'-'+pad(d.getDate())+'T'+pad(d.getHours())+':'+pad(d.getMinutes());
    }
    openModal('editModal');
}

function openAssignModal(id, title) {
    document.getElementById('assign_task_id').value = id;
    document.getElementById('assign_task_name').textContent = 'Task: ' + title;
    openModal('assignModal');
}

function confirmDelete(id, title) {
    document.getElementById('delete_task_id').value = id;
    document.getElementById('delete_task_name').textContent = title;
    openModal('deleteModal');
}

// Close modal on backdrop click
document.querySelectorAll('.modal').forEach(m => {
    m.addEventListener('click', e => { if (e.target === m) m.classList.remove('active'); });
});

<?php if ($error): ?>
document.addEventListener('DOMContentLoaded', () => openModal('createModal'));
<?php endif; ?>
</script>
</body>
</html>
