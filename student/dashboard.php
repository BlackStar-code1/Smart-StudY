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

// ── Handle POST ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = $_POST['action'] ?? '';
    $task_id = intval($_POST['task_id'] ?? 0);

    // Submit task with optional file
    if ($action === 'submit_task' && $task_id) {
        $notes     = trim($_POST['notes'] ?? '');
        $file_path = null;

        if (isset($_FILES['submission_file']) && $_FILES['submission_file']['error'] === 0) {
            $upload_dir = '../uploads/submissions/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $fname     = time() . '_' . basename($_FILES['submission_file']['name']);
            $file_path = $upload_dir . $fname;
            if (!move_uploaded_file($_FILES['submission_file']['tmp_name'], $file_path))
                $file_path = null;
        }

        $result = submitTask($task_id, $student_id, $file_path, $notes);
        if ($result['success']) {
            createNotification($student_id, 'Task Submitted', 'You submitted a task successfully.', 'submission', $task_id);
            header('Location: dashboard.php?submitted=1');
            exit();
        } else {
            // If already submitted but rejected, allow resubmit by updating
            $upd = $conn->prepare("UPDATE task_submissions SET file_path=?, notes=?, status='submitted', submission_date=NOW() WHERE task_id=? AND student_id=? AND status='rejected'");
            $upd->bind_param('ssii', $file_path, $notes, $task_id, $student_id);
            if ($upd->execute() && $upd->affected_rows > 0) {
                createNotification($student_id, 'Task Resubmitted', 'You resubmitted a task.', 'submission', $task_id);
                header('Location: dashboard.php?submitted=1');
                exit();
            }
        }
    }

    // Turn in (no file needed)
    if ($action === 'mark_complete' && $task_id) {
        // Check not already submitted
        $chk = $conn->prepare("SELECT id FROM task_submissions WHERE task_id=? AND student_id=?");
        $chk->bind_param('ii', $task_id, $student_id);
        $chk->execute();
        if ($chk->get_result()->num_rows === 0) {
            $ins = $conn->prepare("INSERT INTO task_submissions (task_id, student_id, notes, status) VALUES (?, ?, 'Turned in', 'submitted')");
            $ins->bind_param('ii', $task_id, $student_id);
            $ins->execute();
            createNotification($student_id, 'Task Turned In', 'You turned in a task.', 'submission', $task_id);
        }
        header('Location: dashboard.php?completed=1');
        exit();
    }
}

// ── Fetch data ────────────────────────────────────────────────────────────────
// All tasks available to this student (assigned + general)
$all_tasks_stmt = $conn->prepare(
    "SELECT t.*, u.full_name as teacher_name
     FROM tasks t
     LEFT JOIN users u ON t.teacher_id = u.id
     WHERE t.section_id IS NULL
        OR t.id IN (SELECT task_id FROM task_submissions WHERE student_id = ?)
     ORDER BY t.due_date ASC"
);
$all_tasks_stmt->bind_param('i', $student_id);
$all_tasks_stmt->execute();
$all_tasks = $all_tasks_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Build submission map: task_id => submission row
$sub_map = [];
$sub_stmt = $conn->prepare(
    "SELECT task_id, status, grade, submission_date, file_path, notes
     FROM task_submissions WHERE student_id = ?"
);
$sub_stmt->bind_param('i', $student_id);
$sub_stmt->execute();
foreach ($sub_stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row)
    $sub_map[$row['task_id']] = $row;

// Enrich tasks with submission status
foreach ($all_tasks as &$t) {
    $sub = $sub_map[$t['id']] ?? null;
    $t['my_status']   = $sub ? $sub['status'] : 'pending';
    $t['my_grade']    = $sub['grade'] ?? null;
    $t['my_sub_date'] = $sub['submission_date'] ?? null;

    // Overdue means due date passed and it is NOT yet graded.
    $t['is_overdue']  = (strtotime($t['due_date']) < time()) && ($t['my_status'] !== 'graded');
    $t['is_rejected'] = ($t['my_status'] === 'rejected');
}
unset($t);

// Hide tasks from dashboard once submitted/approved/rejected.
// Keep only pending tasks until teacher finishes grading.
$all_tasks = array_values(array_filter($all_tasks, function ($t) {
    return ($t['my_status'] ?? 'pending') === 'pending';
}));


// Stats
$total_tasks    = count($all_tasks);
$completed      = count(array_filter($all_tasks, fn($t) => $t['my_status'] !== 'pending'));
$pending_count  = count(array_filter($all_tasks, fn($t) => $t['my_status'] === 'pending'));
$graded_count   = count(array_filter($all_tasks, fn($t) => $t['my_status'] === 'graded'));
$overdue_count  = count(array_filter($all_tasks, fn($t) => $t['is_overdue']));
$completion_pct = $total_tasks > 0 ? round(($completed / $total_tasks) * 100) : 0;

$notifications  = getUserNotifications($student_id, 8);
$unread_count   = getUnreadNotificationsCount($student_id);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Student Dashboard - Smart Study Planner</title>
<style>
*{margin:0;padding:0;box-sizing:border-box;}
:root{--primary:#3498db;--green:#2ecc71;--red:#e74c3c;--orange:#f39c12;--purple:#9b59b6;--dark:#2c3e50;--text:#34495e;}
body{font-family:'Segoe UI',sans-serif;background:#f5f7fa;color:var(--text);}
.navbar{background:linear-gradient(135deg,var(--primary) 0%,var(--green) 100%);color:white;padding:1rem 2rem;display:flex;justify-content:space-between;align-items:center;box-shadow:0 2px 10px rgba(0,0,0,.1);}
.navbar-brand{font-size:1.3rem;font-weight:bold;}
.navbar-menu{display:flex;gap:1.5rem;align-items:center;}
.navbar-menu a{color:white;text-decoration:none;font-size:.95rem;transition:.3s;}
.navbar-menu a:hover{opacity:.8;}
.notif-bell{position:relative;cursor:pointer;font-size:1.2rem;}
.notif-badge{position:absolute;top:-8px;right:-8px;background:var(--red);color:white;border-radius:50%;width:18px;height:18px;display:flex;align-items:center;justify-content:center;font-size:.7rem;font-weight:bold;}
.container{max-width:1200px;margin:2rem auto;padding:0 1rem;}
.welcome-bar{background:white;padding:1.5rem 2rem;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,.05);margin-bottom:1.5rem;display:flex;justify-content:space-between;align-items:center;}
.welcome-bar h1{color:var(--dark);font-size:1.4rem;margin-bottom:.2rem;}
.welcome-bar p{color:#7f8c8d;font-size:.9rem;}
.progress-wrap{text-align:right;}
.progress-label{font-size:.82rem;color:#7f8c8d;margin-bottom:.4rem;}
.progress-bar{width:180px;height:8px;background:#ecf0f1;border-radius:4px;overflow:hidden;display:inline-block;}
.progress-fill{height:100%;background:var(--green);border-radius:4px;transition:width .5s;}
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.5rem;}
.stat-box{background:white;padding:1.2rem;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,.05);text-align:center;border-top:4px solid var(--primary);}
.stat-box.green{border-top-color:var(--green);}
.stat-box.red{border-top-color:var(--red);}
.stat-box.purple{border-top-color:var(--purple);}
.stat-number{font-size:1.8rem;font-weight:bold;color:var(--primary);}
.stat-box.green .stat-number{color:var(--green);}
.stat-box.red .stat-number{color:var(--red);}
.stat-box.purple .stat-number{color:var(--purple);}
.stat-label{color:#7f8c8d;font-size:.82rem;margin-top:.3rem;}
.layout{display:grid;grid-template-columns:1fr 320px;gap:1.5rem;}
.card{background:white;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,.05);overflow:hidden;}
.card-header{padding:1rem 1.5rem;border-bottom:1px solid #ecf0f1;display:flex;justify-content:space-between;align-items:center;}
.card-header h2{color:var(--dark);font-size:1rem;}
.card-body{padding:1rem;}
.filter-tabs{display:flex;gap:.4rem;margin-bottom:1rem;flex-wrap:wrap;}
.tab{padding:.35rem .8rem;border-radius:20px;font-size:.8rem;font-weight:600;cursor:pointer;border:2px solid #ecf0f1;background:white;color:#7f8c8d;transition:all .2s;}
.tab.active,.tab:hover{border-color:var(--primary);color:var(--primary);background:#ebf5fb;}
.task-card{padding:1rem;border-left:4px solid var(--primary);background:#f8f9fa;border-radius:6px;margin-bottom:.8rem;transition:transform .2s;}
.task-card:hover{transform:translateX(3px);}
.task-card.done{border-left-color:var(--green);background:#f0fdf4;}
.task-card.overdue{border-left-color:var(--red);background:#fff5f5;}
.task-card.graded{border-left-color:var(--purple);background:#faf5ff;}
.task-top{display:flex;justify-content:space-between;align-items:flex-start;gap:.5rem;margin-bottom:.4rem;}
.task-title{font-weight:600;color:var(--dark);font-size:.95rem;}
.task-meta{font-size:.78rem;color:#7f8c8d;margin-bottom:.5rem;}
.badge{display:inline-block;padding:.2rem .6rem;border-radius:20px;font-size:.72rem;font-weight:600;}
.badge-pending{background:#fff3cd;color:#856404;}
.badge-submitted{background:#cce5ff;color:#004085;}
.badge-graded{background:#d4edda;color:#155724;}
.badge-overdue{background:#f8d7da;color:#721c24;}
.grade-pill{display:inline-block;padding:.2rem .6rem;border-radius:20px;font-weight:700;font-size:.8rem;background:#d4edda;color:#155724;margin-left:.4rem;}
.task-actions{display:flex;gap:.5rem;margin-top:.6rem;flex-wrap:wrap;}
.btn{padding:.4rem .9rem;border:none;border-radius:5px;cursor:pointer;font-size:.82rem;font-weight:600;transition:all .2s;text-decoration:none;display:inline-flex;align-items:center;gap:.3rem;}
.btn-primary{background:var(--primary);color:white;}
.btn-primary:hover{background:#2980b9;transform:translateY(-1px);}
.btn-success{background:var(--green);color:white;}
.btn-success:hover{background:#27ae60;transform:translateY(-1px);}
.btn-secondary{background:#ecf0f1;color:var(--dark);}
.btn-secondary:hover{background:#d5dbdb;}
.btn-sm{padding:.3rem .7rem;font-size:.78rem;}
.notif-item{padding:.8rem;border-left:3px solid var(--primary);background:#f8f9fa;border-radius:5px;margin-bottom:.6rem;}
.notif-item.unread{border-left-color:var(--green);background:#f0fdf4;}
.notif-title{font-weight:600;color:var(--dark);font-size:.88rem;}
.notif-msg{font-size:.8rem;color:#7f8c8d;margin-top:.2rem;}
.notif-time{font-size:.72rem;color:#bdc3c7;margin-top:.2rem;}
.modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;padding:1rem;}
.modal.active{display:flex;}
.modal-box{background:white;border-radius:10px;width:100%;max-width:500px;margin:auto;animation:slideUp .3s ease;}
.modal-header{padding:1.2rem 1.5rem;border-bottom:1px solid #ecf0f1;display:flex;justify-content:space-between;align-items:center;}
.modal-header h2{color:var(--dark);font-size:1.1rem;}
.modal-close{background:none;border:none;font-size:1.4rem;cursor:pointer;color:#7f8c8d;}
.modal-body{padding:1.5rem;}
.form-group{margin-bottom:1rem;}
.form-group label{display:block;margin-bottom:.4rem;font-weight:600;color:var(--dark);font-size:.9rem;}
.form-group input,.form-group textarea{width:100%;padding:.7rem;border:2px solid #ecf0f1;border-radius:5px;font-family:inherit;font-size:.95rem;transition:border-color .3s;}
.form-group input:focus,.form-group textarea:focus{outline:none;border-color:var(--primary);}
.alert{padding:.9rem 1rem;border-radius:5px;margin-bottom:1rem;font-size:.9rem;}
.alert-success{background:#d4edda;color:#155724;border-left:4px solid var(--green);}
.empty-state{text-align:center;padding:2rem;color:#7f8c8d;}
@keyframes slideUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
@media(max-width:900px){.layout{grid-template-columns:1fr;}.stats-grid{grid-template-columns:repeat(2,1fr);}.welcome-bar{flex-direction:column;gap:1rem;align-items:flex-start;}}
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
    <div class="notif-bell" onclick="document.getElementById('notifPanel').classList.toggle('active')">
      🔔
      <?php if ($unread_count > 0): ?><span class="notif-badge"><?= $unread_count ?></span><?php endif; ?>
    </div>
    <a href="../profile.php">Profile</a>
    <a href="../logout.php">Logout</a>
  </div>
</nav>

<div class="container">

  <?php if (isset($_GET['submitted'])): ?><div class="alert alert-success">✅ Task submitted successfully!</div><?php endif; ?>
  <?php if (isset($_GET['completed'])): ?><div class="alert alert-success">✅ Task turned in!</div><?php endif; ?>

  <div class="welcome-bar">
    <div>
      <h1>Welcome, <?= htmlspecialchars($_SESSION['full_name']) ?>! 👋</h1>
      <p>Here's your learning dashboard</p>
    </div>
    <div class="progress-wrap">
      <div class="progress-label">Overall completion: <?= $completion_pct ?>%</div>
      <div class="progress-bar"><div class="progress-fill" style="width:<?= $completion_pct ?>%"></div></div>
    </div>
  </div>

  <div class="stats-grid">
    <div class="stat-box"><div class="stat-number"><?= $total_tasks ?></div><div class="stat-label">Total Tasks</div></div>
    <div class="stat-box green"><div class="stat-number"><?= $completed ?></div><div class="stat-label">Completed</div></div>
    <div class="stat-box red"><div class="stat-number"><?= $overdue_count ?></div><div class="stat-label">Overdue</div></div>
    <div class="stat-box purple"><div class="stat-number"><?= $graded_count ?></div><div class="stat-label">Graded</div></div>
  </div>

  <div class="layout">

    <!-- LEFT: Tasks -->
    <div class="card">
      <div class="card-header">
        <h2>📋 My Tasks</h2>
        <a href="tasks.php" class="btn btn-secondary btn-sm">View All</a>
      </div>
      <div class="card-body">
        <div class="filter-tabs">
          <span class="tab active" onclick="filterTasks('all', this)">All (<?= $total_tasks ?>)</span>
          <span class="tab" onclick="filterTasks('pending', this)">Pending (<?= $pending_count ?>)</span>
          <span class="tab" onclick="filterTasks('submitted', this)">Submitted</span>
          <span class="tab" onclick="filterTasks('graded', this)">Graded (<?= $graded_count ?>)</span>
          <?php if ($overdue_count > 0): ?>
          <span class="tab" style="border-color:var(--red);color:var(--red);" onclick="filterTasks('overdue', this)">⚠️ Overdue (<?= $overdue_count ?>)</span>
          <?php endif; ?>
        </div>

        <?php if (empty($all_tasks)): ?>
          <div class="empty-state"><p>No tasks available yet. Check back later.</p></div>
        <?php else: ?>
          <?php foreach ($all_tasks as $task):
            $status    = $task['my_status'];
            $is_done   = $status !== 'pending' && $status !== 'rejected';
            $card_class = $task['is_overdue'] ? 'overdue' : ($status === 'graded' ? 'graded' : ($status === 'approved' ? 'done' : ($is_done ? 'done' : '')));
            $data_filter = $task['is_overdue'] ? 'overdue' : ($status === 'approved' ? 'submitted' : $status);
          ?>
          <div class="task-card <?= $card_class ?>" data-filter="<?= $data_filter ?>">
            <div class="task-top">
              <div class="task-title"><?= htmlspecialchars($task['title']) ?></div>
              <?php if ($status === 'pending' && !$task['is_overdue']): ?>
                <span class="badge badge-pending">Pending</span>
              <?php elseif ($task['is_overdue']): ?>
                <span class="badge badge-overdue">Overdue</span>
              <?php elseif ($status === 'submitted'): ?>
                <span class="badge badge-submitted">Submitted</span>
              <?php elseif ($status === 'approved'): ?>
                <span class="badge" style="background:#cce5ff;color:#004085;">✅ Approved</span>
              <?php elseif ($status === 'rejected'): ?>
                <span class="badge" style="background:#f8d7da;color:#721c24;">❌ Rejected — Resubmit</span>
              <?php elseif ($status === 'graded'): ?>
                <span class="badge badge-graded">Graded</span>
                <?php if ($task['my_grade'] !== null): ?>
                  <span class="grade-pill"><?= $task['my_grade'] ?>/100</span>
                <?php endif; ?>
              <?php endif; ?>
            </div>
            <div class="task-meta">
              <?= htmlspecialchars($task['subject'] ?? '') ?><?= $task['subject'] ? ' &bull; ' : '' ?>
              Teacher: <?= htmlspecialchars($task['teacher_name'] ?? 'N/A') ?> &bull;
              Due: <?= date('M d, Y', strtotime($task['due_date'])) ?>
              <?php if ($task['my_sub_date']): ?>
                &bull; Submitted: <?= date('M d, Y', strtotime($task['my_sub_date'])) ?>
              <?php endif; ?>
            </div>
            <?php if (!$is_done): ?>
            <div class="task-actions">
              <button class="btn btn-primary btn-sm" onclick="openSubmitModal(<?= $task['id'] ?>, '<?= htmlspecialchars(addslashes($task['title'])) ?>')">
                📤 Submit with File
              </button>
              <form method="POST" style="display:inline;" onsubmit="return confirm('Turn in this task?')">
                <input type="hidden" name="action" value="mark_complete">
                <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                <button type="submit" class="btn btn-success btn-sm">✅ Submit</button>
              </form>
            </div>
            <?php elseif ($status === 'rejected'): ?>
            <div class="task-actions">
              <span style="font-size:.82rem;color:var(--red);font-weight:600;margin-right:.5rem;">❌ Rejected — please resubmit</span>
              <button class="btn btn-primary btn-sm" onclick="openSubmitModal(<?= $task['id'] ?>, '<?= htmlspecialchars(addslashes($task['title'])) ?>')">
                📤 Resubmit
              </button>
            </div>
            <?php elseif ($status === 'graded' && !empty($task['my_grade'])): ?>
            <div class="task-actions">
              <span style="font-size:.82rem;color:#27ae60;font-weight:600;">✅ Graded: <?= $task['my_grade'] ?>/100</span>
            </div>
            <?php else: ?>
            <div class="task-actions">
              <span style="font-size:.82rem;color:#3498db;">✔ Submitted — awaiting grade</span>
            </div>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- RIGHT: Notifications -->
    <div class="card" id="notifPanel">
      <div class="card-header">
        <h2>🔔 Notifications <?php if ($unread_count > 0): ?><span style="background:var(--red);color:white;border-radius:20px;padding:.1rem .5rem;font-size:.72rem;"><?= $unread_count ?></span><?php endif; ?></h2>
      </div>
      <div class="card-body">
        <?php if (empty($notifications)): ?>
          <div class="empty-state"><p>No notifications yet.</p></div>
        <?php else: ?>
          <?php foreach ($notifications as $n): ?>
          <div class="notif-item <?= !$n['is_read'] ? 'unread' : '' ?>">
            <div class="notif-title"><?= htmlspecialchars($n['title']) ?></div>
            <div class="notif-msg"><?= htmlspecialchars($n['message']) ?></div>
            <div class="notif-time"><?= date('M d, g:i a', strtotime($n['created_at'])) ?></div>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

  </div><!-- /layout -->
</div><!-- /container -->

<!-- Submit with File Modal -->
<div class="modal" id="submitModal">
  <div class="modal-box">
    <div class="modal-header">
      <h2>Submit Task</h2>
      <button class="modal-close" onclick="document.getElementById('submitModal').classList.remove('active')">×</button>
    </div>
    <div class="modal-body">
      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="submit_task">
        <input type="hidden" name="task_id" id="submitTaskId">
        <div class="form-group">
          <label>Task: <strong id="submitTaskTitle"></strong></label>
        </div>
        <div class="form-group">
          <label for="submission_file">Upload File</label>
          <input type="file" id="submission_file" name="submission_file" accept=".pdf,.doc,.docx,.txt,.jpg,.png,.zip" required>
        </div>
        <div class="form-group">
          <label for="notes">Notes (Optional)</label>
          <textarea id="notes" name="notes" rows="3" placeholder="Add any notes about your submission..."></textarea>
        </div>
        <div style="display:flex;gap:.8rem;">
          <button type="submit" class="btn btn-primary" style="flex:1;">📤 Submit</button>
          <button type="button" class="btn btn-secondary" style="flex:1;" onclick="document.getElementById('submitModal').classList.remove('active')">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function openSubmitModal(id, title) {
  document.getElementById('submitTaskId').value = id;
  document.getElementById('submitTaskTitle').textContent = title;
  document.getElementById('submitModal').classList.add('active');
}

function filterTasks(filter, el) {
  document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
  el.classList.add('active');
  document.querySelectorAll('.task-card').forEach(card => {
    card.style.display = (filter === 'all' || card.dataset.filter === filter) ? '' : 'none';
  });
}

// Close modal on backdrop click
document.getElementById('submitModal').addEventListener('click', function(e) {
  if (e.target === this) this.classList.remove('active');
});
</script>
</body>
</html>
