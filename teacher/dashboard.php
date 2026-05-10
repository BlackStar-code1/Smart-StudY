<?php
session_start();
require_once '../config/db_config.php';
require_once '../config/auth.php';
require_once '../config/tasks.php';
require_once '../config/notifications.php';
if (!checkUserRole('teacher')) { header('Location: ../login.php'); exit(); }

$teacher_id = $_SESSION['user_id'];

// ── POST: Approve ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'approve_submission') {
    $sid = intval($_POST['submission_id']);
    $v = $conn->prepare("SELECT ts.id, ts.student_id, t.title FROM task_submissions ts JOIN tasks t ON ts.task_id=t.id WHERE ts.id=? AND t.teacher_id=?");
    $v->bind_param('ii', $sid, $teacher_id); $v->execute();
    $row = $v->get_result()->fetch_assoc();
    if ($row) {
        $u = $conn->prepare("UPDATE task_submissions SET status='approved' WHERE id=?");
        $u->bind_param('i', $sid); $u->execute();
        createNotification($row['student_id'], 'Submission Approved', 'Your submission for "'.$row['title'].'" has been approved.', 'submission', $sid);
    }
    header('Location: dashboard.php?approved=1'); exit();
}

// ── POST: Grade ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'grade_submission') {
    $sid = intval($_POST['submission_id']);
    $grade = max(0, min(100, intval($_POST['grade'])));
    $feedback = trim($_POST['feedback'] ?? '');
    $v = $conn->prepare("SELECT ts.id, ts.student_id, t.title FROM task_submissions ts JOIN tasks t ON ts.task_id=t.id WHERE ts.id=? AND t.teacher_id=?");
    $v->bind_param('ii', $sid, $teacher_id); $v->execute();
    $row = $v->get_result()->fetch_assoc();
    if ($row) {
        gradeSubmission($sid, $grade, $feedback, $teacher_id);
        createNotification($row['student_id'], 'Task Graded', 'Your submission for "'.$row['title'].'" has been graded: '.$grade.'/100', 'grade', $sid);
    }
    header('Location: dashboard.php?graded=1'); exit();
}

// ── POST: Create Task ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_task') {
    $title       = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $subject     = trim($_POST['subject'] ?? '');
    $due_date    = $_POST['due_date'] ?? '';
    $section_id  = !empty($_POST['section_id']) ? intval($_POST['section_id']) : null;
    $year        = trim($_POST['year'] ?? '');
    if ($section_id) {
        $vs = $conn->prepare("SELECT id FROM class_groups WHERE id=? AND teacher_id=?");
        $vs->bind_param('ii', $section_id, $teacher_id); $vs->execute();
        if ($vs->get_result()->num_rows === 0) $section_id = null;
    }
    if (!empty($title) && !empty($due_date)) {
        $result = createTask($teacher_id, $title, $description, $subject, $due_date, $section_id, $year ?: null);
        if ($result['success']) {
            $task_id = $result['task_id'];
            if (!empty($_FILES['task_files']['name'][0])) {
                $dir = '../uploads/tasks/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                foreach ($_FILES['task_files']['name'] as $k => $fn) {
                    if ($_FILES['task_files']['error'][$k] === 0) {
                        $fp = $dir . time() . '_' . basename($fn);
                        if (move_uploaded_file($_FILES['task_files']['tmp_name'][$k], $fp))
                            addTaskFile($task_id, $fn, $fp, $_FILES['task_files']['size'][$k]);
                    }
                }
            }
            if ($section_id) {
                $assigned = assignTaskToSection($task_id, $section_id);
                foreach ($assigned as $student_id)
                    createNotification($student_id, 'New Task Assigned', 'A new task has been assigned: '.$title, 'task', $task_id);
            }
            createNotification($teacher_id, 'Task Created', 'Task created: '.$title, 'task', $task_id);
            header('Location: dashboard.php?created=1'); exit();
        }
    }
}

// ── Fetch ─────────────────────────────────────────────────────────────────────
$tasks         = getTeacherTasks($teacher_id);
$notifications = getUserNotifications($teacher_id, 8);
$unread_count  = getUnreadNotificationsCount($teacher_id);

$cq = $conn->prepare("SELECT cg.id, cg.class_name, cg.description, COUNT(cs.student_id) as student_count FROM class_groups cg LEFT JOIN class_students cs ON cg.id=cs.class_id WHERE cg.teacher_id=? GROUP BY cg.id ORDER BY cg.class_name");
$cq->bind_param('i', $teacher_id); $cq->execute();
$teacher_classes = $cq->get_result()->fetch_all(MYSQLI_ASSOC);

$class_students = [];
foreach ($teacher_classes as $class) {
    $sq = $conn->prepare("SELECT u.id, u.full_name, u.username, u.email, cs.enrolled_at FROM class_students cs JOIN users u ON cs.student_id=u.id WHERE cs.class_id=? AND u.status='active' ORDER BY u.full_name");
    $sq->bind_param('i', $class['id']); $sq->execute();
    $class_students[$class['id']] = $sq->get_result()->fetch_all(MYSQLI_ASSOC);
}

$total_submissions = 0; $pending_grading = 0; $total_assigned_students = 0;
foreach ($tasks as $task) {
    $s = getTaskStatistics($task['id']);
    $total_submissions += $s['total_submissions'];
    $pending_grading   += $s['pending_grading'];
}
foreach ($teacher_classes as $c) $total_assigned_students += $c['student_count'];

$student_pending = [];
if (!empty($tasks)) {
    $task_ids = array_column($tasks, 'id');
    $ph = implode(',', array_fill(0, count($task_ids), '?'));
    $all_st = $conn->query("SELECT id, full_name, username, email FROM users WHERE role='student' AND status='active' ORDER BY full_name")->fetch_all(MYSQLI_ASSOC);
    foreach ($all_st as $st) {
        $q = $conn->prepare("SELECT ts.id as sub_id, ts.task_id, ts.status, ts.notes, ts.file_path, ts.submission_date, t.title as task_title, t.subject, t.due_date FROM task_submissions ts JOIN tasks t ON ts.task_id=t.id WHERE ts.student_id=? AND ts.task_id IN ($ph) AND ts.status='submitted' ORDER BY ts.submission_date ASC");
        $params = array_merge([$st['id']], $task_ids);
        $q->bind_param('i'.str_repeat('i', count($task_ids)), ...$params);
        $q->execute();
        $subs = $q->get_result()->fetch_all(MYSQLI_ASSOC);
        if (!empty($subs)) $student_pending[$st['id']] = ['info' => $st, 'subs' => $subs];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Teacher Dashboard - Smart Study Planner</title>
<style>
*{margin:0;padding:0;box-sizing:border-box;}
:root{
  --primary:#3498db;--purple:#9b59b6;--green:#2ecc71;
  --red:#e74c3c;--orange:#f39c12;--dark:#2c3e50;--text:#34495e;
  --bg:#f0f4f8;--card:#ffffff;--border:#e2e8f0;
}
body{font-family:'Segoe UI',Tahoma,sans-serif;background:var(--bg);color:var(--text);}

/* ── Navbar ── */
.navbar{background:linear-gradient(135deg,var(--primary),var(--purple));color:#fff;padding:.9rem 2rem;display:flex;justify-content:space-between;align-items:center;box-shadow:0 2px 12px rgba(0,0,0,.15);position:sticky;top:0;z-index:100;}
.navbar-brand{font-size:1.2rem;font-weight:700;display:flex;align-items:center;gap:.5rem;}
.navbar-menu{display:flex;gap:1.2rem;align-items:center;flex-wrap:wrap;}
.navbar-menu a{color:rgba(255,255,255,.85);text-decoration:none;font-size:.88rem;padding:.4rem .7rem;border-radius:6px;transition:all .2s;}
.navbar-menu a:hover,.navbar-menu a.active{background:rgba(255,255,255,.2);color:#fff;font-weight:600;}

/* ── Layout ── */
.container{max-width:1400px;margin:0 auto;padding:1.5rem 1rem;}
.page-header{background:var(--card);border-radius:12px;padding:1.5rem 2rem;margin-bottom:1.5rem;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem;box-shadow:0 1px 6px rgba(0,0,0,.06);}
.page-header h1{color:var(--dark);font-size:1.4rem;margin-bottom:.2rem;}
.page-header p{color:#7f8c8d;font-size:.88rem;}
.header-actions{display:flex;gap:.6rem;flex-wrap:wrap;}

/* ── Stats ── */
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.5rem;}
.stat-card{background:var(--card);border-radius:12px;padding:1.3rem 1.5rem;box-shadow:0 1px 6px rgba(0,0,0,.06);display:flex;align-items:center;gap:1rem;border-left:4px solid var(--primary);transition:transform .2s;}
.stat-card:hover{transform:translateY(-2px);}
.stat-card.green{border-left-color:var(--green);}
.stat-card.orange{border-left-color:var(--orange);}
.stat-card.purple{border-left-color:var(--purple);}
.stat-icon{font-size:1.8rem;line-height:1;}
.stat-num{font-size:1.9rem;font-weight:800;color:var(--dark);line-height:1;}
.stat-card.green .stat-num{color:var(--green);}
.stat-card.orange .stat-num{color:var(--orange);}
.stat-card.purple .stat-num{color:var(--purple);}
.stat-lbl{font-size:.78rem;color:#7f8c8d;margin-top:.2rem;}

/* ── 3-col grid ── */
.dash-grid{display:grid;grid-template-columns:1fr 1fr 340px;gap:1.5rem;}

/* ── Card ── */
.card{background:var(--card);border-radius:12px;box-shadow:0 1px 6px rgba(0,0,0,.06);overflow:hidden;margin-bottom:1.5rem;}
.card-header{padding:.9rem 1.3rem;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;background:#fafbfc;}
.card-header h2{color:var(--dark);font-size:.95rem;font-weight:700;}
.card-body{padding:1.2rem;}

/* ── Buttons ── */
.btn{padding:.45rem 1rem;border:none;border-radius:7px;cursor:pointer;font-size:.82rem;font-weight:600;transition:all .2s;text-decoration:none;display:inline-flex;align-items:center;gap:.35rem;white-space:nowrap;}
.btn-primary{background:var(--primary);color:#fff;}
.btn-primary:hover{background:#2980b9;transform:translateY(-1px);}
.btn-success{background:var(--green);color:#fff;}
.btn-success:hover{background:#27ae60;}
.btn-warning{background:var(--orange);color:#fff;}
.btn-warning:hover{background:#e67e22;}
.btn-danger{background:var(--red);color:#fff;}
.btn-danger:hover{background:#c0392b;}
.btn-secondary{background:#ecf0f1;color:var(--dark);}
.btn-secondary:hover{background:#dde1e4;}
.btn-purple{background:var(--purple);color:#fff;}
.btn-purple:hover{background:#8e44ad;}
.btn-sm{padding:.3rem .65rem;font-size:.75rem;}
.btn-group{display:flex;gap:.4rem;flex-wrap:wrap;}

/* ── Alerts ── */
.alert{padding:.85rem 1.1rem;border-radius:8px;margin-bottom:1rem;font-size:.88rem;display:flex;align-items:center;gap:.6rem;}
.alert-success{background:#d4edda;color:#155724;border-left:4px solid var(--green);}
.alert-info{background:#cce5ff;color:#004085;border-left:4px solid var(--primary);}

/* ── Task rows ── */
.task-row{padding:.85rem 1rem;border-left:4px solid var(--primary);background:#f8fafc;border-radius:8px;margin-bottom:.7rem;display:flex;justify-content:space-between;align-items:flex-start;gap:.8rem;transition:background .2s;}
.task-row:hover{background:#eef4fb;}
.task-row h3{color:var(--dark);font-size:.9rem;margin-bottom:.25rem;font-weight:600;}
.task-meta{font-size:.75rem;color:#7f8c8d;line-height:1.5;}
.badge-pill{display:inline-block;padding:.15rem .55rem;border-radius:20px;font-size:.7rem;font-weight:700;}
.badge-orange{background:#fff3cd;color:#856404;}
.badge-green{background:#d4edda;color:#155724;}
.badge-blue{background:#cce5ff;color:#004085;}

/* ── Student grade cards ── */
.sgc{border:2px solid var(--border);border-radius:10px;margin-bottom:.8rem;overflow:hidden;transition:border-color .2s;}
.sgc.has-pending{border-color:var(--orange);}
.sgc-head{padding:.8rem 1rem;background:#fafbfc;display:flex;justify-content:space-between;align-items:center;cursor:pointer;user-select:none;}
.sgc-head:hover{background:#f0f0f0;}
.sgc-name{font-weight:700;color:var(--dark);font-size:.9rem;}
.sgc-email{font-size:.73rem;color:#7f8c8d;margin-top:.1rem;}
.sgc-badge{background:var(--orange);color:#fff;border-radius:20px;padding:.15rem .6rem;font-size:.7rem;font-weight:700;}
.sgc-body{display:none;padding:.8rem 1rem;border-top:1px solid var(--border);}
.sgc-body.open{display:block;}
.sub-row{padding:.8rem;background:#fffbf0;border-radius:8px;margin-bottom:.6rem;border-left:3px solid var(--orange);}
.sub-row:last-child{margin-bottom:0;}
.sub-task{font-weight:600;color:var(--dark);font-size:.87rem;margin-bottom:.3rem;}
.sub-info{font-size:.73rem;color:#7f8c8d;margin-bottom:.6rem;line-height:1.6;}
.grade-form-inline{display:flex;gap:.5rem;align-items:flex-end;flex-wrap:wrap;padding-top:.5rem;border-top:1px solid #f0e8d0;}
.grade-form-inline .fg{display:flex;flex-direction:column;gap:.2rem;}
.grade-form-inline label{font-size:.7rem;font-weight:700;color:var(--dark);}
.grade-form-inline input[type=number]{width:68px;padding:.4rem .5rem;border:2px solid var(--border);border-radius:6px;font-size:.9rem;font-weight:700;text-align:center;}
.grade-form-inline input[type=text]{width:160px;padding:.4rem .5rem;border:2px solid var(--border);border-radius:6px;font-size:.8rem;font-family:inherit;}
.grade-form-inline input:focus{outline:none;border-color:var(--green);}
.sub-actions{display:flex;gap:.4rem;flex-wrap:wrap;margin-top:.5rem;}

/* ── Student list ── */
.student-item{display:flex;justify-content:space-between;align-items:center;padding:.75rem .9rem;background:#f8fafc;border-radius:8px;border-left:3px solid var(--green);margin-bottom:.5rem;transition:background .2s;}
.student-item:hover{background:#eef4fb;}
.student-item-name{font-weight:600;color:var(--dark);font-size:.88rem;}
.student-item-email{font-size:.72rem;color:#7f8c8d;margin-top:.1rem;}
.student-item-date{font-size:.68rem;color:#bdc3c7;margin-top:.1rem;}
.class-section{border:1px solid var(--border);border-radius:10px;padding:1rem;margin-bottom:1rem;}
.class-section-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:.8rem;padding-bottom:.6rem;border-bottom:2px solid var(--border);}
.class-section-title{font-weight:700;color:var(--dark);font-size:.92rem;}
.class-count{background:var(--primary);color:#fff;border-radius:20px;padding:.2rem .7rem;font-size:.72rem;font-weight:700;}

/* ── Notifications ── */
.notif-item{padding:.7rem .85rem;border-left:3px solid var(--primary);background:#f8fafc;border-radius:6px;margin-bottom:.5rem;}
.notif-item.unread{border-left-color:var(--green);background:#f0fdf4;}
.notif-title{font-weight:600;color:var(--dark);font-size:.83rem;}
.notif-msg{font-size:.76rem;color:#7f8c8d;margin-top:.15rem;line-height:1.4;}
.notif-time{font-size:.68rem;color:#bdc3c7;margin-top:.15rem;}

/* ── Modal ── */
.modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;padding:1rem;overflow-y:auto;}
.modal.active{display:flex;}
.modal-box{background:#fff;border-radius:14px;width:100%;max-width:560px;margin:auto;animation:slideUp .25s ease;box-shadow:0 20px 60px rgba(0,0,0,.2);}
.modal-header{padding:1.2rem 1.5rem;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;}
.modal-header h2{color:var(--dark);font-size:1.05rem;font-weight:700;}
.modal-close{background:none;border:none;font-size:1.5rem;cursor:pointer;color:#7f8c8d;line-height:1;}
.modal-close:hover{color:var(--red);}
.modal-body{padding:1.5rem;}
.form-group{margin-bottom:1rem;}
.form-group label{display:block;margin-bottom:.4rem;font-weight:600;color:var(--dark);font-size:.88rem;}
.form-group input,.form-group textarea,.form-group select{width:100%;padding:.7rem .85rem;border:2px solid var(--border);border-radius:7px;font-family:inherit;font-size:.92rem;transition:border-color .25s;background:#fff;}
.form-group input:focus,.form-group textarea:focus,.form-group select:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px rgba(52,152,219,.1);}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:.8rem;}

/* ── Empty state ── */
.empty-state{text-align:center;padding:2rem 1rem;color:#7f8c8d;}
.empty-state .empty-icon{font-size:2.5rem;margin-bottom:.6rem;}
.empty-state p{font-size:.88rem;}

@keyframes slideUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
@media(max-width:1100px){.dash-grid{grid-template-columns:1fr 1fr;}}
@media(max-width:768px){.dash-grid{grid-template-columns:1fr;}.stats-grid{grid-template-columns:repeat(2,1fr);}.page-header{flex-direction:column;align-items:flex-start;}.form-row{grid-template-columns:1fr;}}
</style>
</head>
<body>

<nav class="navbar">
  <div class="navbar-brand">📚 Smart Study Planner</div>
  <div class="navbar-menu">
    <a href="dashboard.php" class="active">Dashboard</a>
    <a href="tasks.php">Tasks</a>
    <a href="submissions.php">Submissions</a>
    <a href="monitor.php">Monitor</a>
    <a href="../calendar.php">Calendar</a>
    <a href="../profile.php">Profile</a>
    <a href="../logout.php">Logout</a>
  </div>
</nav>

<div class="container">

<?php if (isset($_GET['created'])): ?><div class="alert alert-success">✅ Task created and assigned successfully!</div><?php endif; ?>
<?php if (isset($_GET['graded'])): ?><div class="alert alert-success">✅ Submission graded — student notified.</div><?php endif; ?>
<?php if (isset($_GET['approved'])): ?><div class="alert alert-info">✅ Submission approved — student notified.</div><?php endif; ?>

<div class="page-header">
  <div>
    <h1>Welcome back, <?= htmlspecialchars($_SESSION['full_name']) ?>! 👋</h1>
    <p>Here's your teaching overview for today</p>
  </div>
  <div class="header-actions">
    <button class="btn btn-primary" onclick="openModal('createTaskModal')">＋ Create Task</button>
    <a href="submissions.php?status=submitted" class="btn btn-warning">
      ⏳ Review Submissions
      <?php if ($pending_grading > 0): ?>
      <span style="background:#fff;color:var(--orange);border-radius:20px;padding:.1rem .5rem;font-size:.7rem;font-weight:900;margin-left:.2rem;"><?= $pending_grading ?></span>
      <?php endif; ?>
    </a>
    <a href="monitor.php" class="btn btn-purple">👥 Monitor Students</a>
  </div>
</div>

<div class="stats-grid">
  <div class="stat-card">
    <div class="stat-icon">📋</div>
    <div><div class="stat-num"><?= count($tasks) ?></div><div class="stat-lbl">Total Tasks</div></div>
  </div>
  <div class="stat-card purple">
    <div class="stat-icon">📥</div>
    <div><div class="stat-num"><?= $total_submissions ?></div><div class="stat-lbl">Total Submissions</div></div>
  </div>
  <div class="stat-card orange">
    <div class="stat-icon">⏳</div>
    <div><div class="stat-num"><?= $pending_grading ?></div><div class="stat-lbl">Needs Grading</div></div>
  </div>
  <div class="stat-card green">
    <div class="stat-icon">👥</div>
    <div><div class="stat-num"><?= $total_assigned_students ?></div><div class="stat-lbl">Assigned Students</div></div>
  </div>
</div>

<div class="dash-grid">

<!-- ── COL 1: Grade Students ── -->
<div>
  <div class="card">
    <div class="card-header">
      <h2>✏️ Grade Students</h2>
      <a href="submissions.php" class="btn btn-secondary btn-sm">View All</a>
    </div>
    <div class="card-body">
      <?php if (empty($student_pending)): ?>
      <div class="empty-state"><div class="empty-icon">🎉</div><p>No pending submissions to grade right now.</p></div>
      <?php else: ?>
      <?php foreach ($student_pending as $sid => $data):
        $st   = $data['info'];
        $subs = $data['subs'];
        $name = htmlspecialchars($st['full_name'] ?: $st['username']);
      ?>
      <div class="sgc has-pending" id="sgc-<?= $sid ?>">
        <div class="sgc-head" onclick="toggleSgc(<?= $sid ?>)">
          <div>
            <div class="sgc-name">👤 <?= $name ?></div>
            <div class="sgc-email"><?= htmlspecialchars($st['email']) ?></div>
          </div>
          <div style="display:flex;align-items:center;gap:.5rem;">
            <span class="sgc-badge"><?= count($subs) ?> pending</span>
            <span id="arr-<?= $sid ?>" style="color:#7f8c8d;font-size:.85rem;transition:transform .2s;">▼</span>
          </div>
        </div>
        <div class="sgc-body open" id="sgcb-<?= $sid ?>">
          <?php foreach ($subs as $sub): ?>
          <div class="sub-row">
            <div class="sub-task">📋 <?= htmlspecialchars($sub['task_title']) ?></div>
            <div class="sub-info">
              <?= $sub['subject'] ? htmlspecialchars($sub['subject']).' &bull; ' : '' ?>
              Due: <?= date('M d, Y', strtotime($sub['due_date'])) ?>
              &bull; Submitted: <?= $sub['submission_date'] ? date('M d H:i', strtotime($sub['submission_date'])) : '—' ?>
              <?php if (!empty($sub['notes'])): ?><br>💬 <?= htmlspecialchars($sub['notes']) ?><?php endif; ?>
              <?php if (!empty($sub['file_path'])): ?>
              <br><a href="<?= htmlspecialchars($sub['file_path']) ?>" target="_blank" style="color:var(--primary);font-weight:600;font-size:.72rem;">📎 View File</a>
              <?php endif; ?>
            </div>
            <!-- Approve button -->
            <div class="sub-actions">
              <button class="btn btn-primary btn-sm" onclick="openApproveModal(<?= $sub['sub_id'] ?>, <?= json_encode($sub['task_title']) ?>, <?= json_encode($st['full_name'] ?: $st['username']) ?>)">✅ Approve</button>
              <a href="grade.php?id=<?= $sub['sub_id'] ?>" class="btn btn-warning btn-sm">🔍 Full Grade</a>
              <?php if ($sub['sub_id']): ?>
              <!-- Added in case UI needs explicit Approve for pending tasks -->
              <?php endif; ?>
            </div>

            <!-- Inline grade form -->
            <form method="POST" class="grade-form-inline" onsubmit="return checkGrade(this)">
              <input type="hidden" name="action" value="grade_submission">
              <input type="hidden" name="submission_id" value="<?= $sub['sub_id'] ?>">
              <div class="fg"><label>Grade</label><input type="number" name="grade" min="0" max="100" placeholder="0-100" required></div>
              <div class="fg"><label>Feedback</label><input type="text" name="feedback" placeholder="Optional..."></div>
              <div class="fg"><label>&nbsp;</label><button type="submit" class="btn btn-success btn-sm">✅ Grade</button></div>
            </form>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ── COL 2: Tasks + Students ── -->
<div>
  <!-- Tasks -->
  <div class="card">
    <div class="card-header">
      <h2>📋 Your Tasks</h2>
      <a href="tasks.php" class="btn btn-secondary btn-sm">Manage All</a>
    </div>
    <div class="card-body">
      <?php if (empty($tasks)): ?>
      <div class="empty-state"><div class="empty-icon">📝</div><p>No tasks yet. <a href="#" onclick="openModal('createTaskModal')" style="color:var(--primary);font-weight:600;">Create one!</a></p></div>
      <?php else: ?>
      <?php foreach (array_slice($tasks, 0, 7) as $task):
        $s = getTaskStatistics($task['id']);
      ?>
      <div class="task-row">
        <div style="flex:1;min-width:0;">
          <h3><?= htmlspecialchars($task['title']) ?></h3>
          <div class="task-meta">
            <?= htmlspecialchars($task['subject'] ?? 'No subject') ?>
            &bull; Due <?= date('M d, Y', strtotime($task['due_date'])) ?>
            <?php if (!empty($task['section_name'])): ?>&bull; <?= htmlspecialchars($task['section_name']) ?><?php endif; ?>
            <?php if (!empty($task['year'])): ?>&bull; <?= htmlspecialchars($task['year']) ?><?php endif; ?>
            &bull; <?= $s['total_submissions'] ?> submitted
            <?php if ($s['pending_grading'] > 0): ?>
            &bull; <span style="color:var(--orange);font-weight:700;"><?= $s['pending_grading'] ?> to grade</span>
            <?php endif; ?>
          </div>
        </div>
        <div class="btn-group">
          <a href="submissions.php?task=<?= $task['id'] ?>" class="btn btn-primary btn-sm">Submissions</a>
          <a href="tasks.php" class="btn btn-secondary btn-sm">Edit</a>
        </div>
      </div>
      <?php endforeach; ?>
      <?php if (count($tasks) > 7): ?>
      <div style="text-align:center;margin-top:.5rem;">
        <a href="tasks.php" class="btn btn-secondary btn-sm">View all <?= count($tasks) ?> tasks →</a>
      </div>
      <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- My Students -->
  <div class="card">
    <div class="card-header">
      <h2>👥 My Students</h2>
      <a href="monitor.php" class="btn btn-secondary btn-sm">Monitor All</a>
    </div>
    <div class="card-body">
      <?php if (empty($teacher_classes)): ?>
      <div class="empty-state"><div class="empty-icon">🏫</div><p>No class groups yet. Assign students to classes to see them here.</p></div>
      <?php else: ?>
      <?php foreach ($teacher_classes as $class):
        $students = $class_students[$class['id']] ?? [];
      ?>
      <div class="class-section">
        <div class="class-section-header">
          <div>
            <div class="class-section-title">📚 <?= htmlspecialchars($class['class_name']) ?></div>
            <?php if (!empty($class['description'])): ?>
            <div style="font-size:.75rem;color:#7f8c8d;margin-top:.15rem;"><?= htmlspecialchars($class['description']) ?></div>
            <?php endif; ?>
          </div>
          <span class="class-count"><?= count($students) ?> students</span>
        </div>
        <?php if (empty($students)): ?>
        <p style="font-size:.82rem;color:#7f8c8d;padding:.3rem 0;">No students enrolled yet.</p>
        <?php else: ?>
        <?php foreach ($students as $student): ?>
        <div class="student-item">
          <div>
            <div class="student-item-name">👤 <?= htmlspecialchars($student['full_name'] ?: $student['username']) ?></div>
            <div class="student-item-email">📧 <?= htmlspecialchars($student['email']) ?></div>
            <div class="student-item-date">Enrolled <?= date('M d, Y', strtotime($student['enrolled_at'])) ?></div>
          </div>
          <div class="btn-group">
            <a href="monitor.php?student=<?= $student['id'] ?>" class="btn btn-primary btn-sm" title="Monitor">👁</a>
            <a href="submissions.php?search=<?= urlencode($student['full_name'] ?: $student['username']) ?>" class="btn btn-secondary btn-sm" title="Submissions">📥</a>
          </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ── COL 3: Notifications ── -->
<div>
  <div class="card">
    <div class="card-header">
      <h2>🔔 Notifications
        <?php if ($unread_count > 0): ?>
        <span style="background:var(--red);color:#fff;border-radius:20px;padding:.1rem .5rem;font-size:.7rem;margin-left:.3rem;"><?= $unread_count ?></span>
        <?php endif; ?>
      </h2>
    </div>
    <div class="card-body">
      <?php if (empty($notifications)): ?>
      <div class="empty-state"><div class="empty-icon">🔕</div><p>No notifications yet.</p></div>
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

  <!-- Quick Links -->
  <div class="card">
    <div class="card-header"><h2>⚡ Quick Actions</h2></div>
    <div class="card-body" style="display:grid;gap:.6rem;">
      <a href="tasks.php" class="btn btn-primary" style="justify-content:center;">📋 Manage All Tasks</a>
      <a href="submissions.php" class="btn btn-warning" style="justify-content:center;">📥 All Submissions</a>
      <a href="submissions.php?status=submitted" class="btn btn-orange" style="justify-content:center;background:var(--orange);color:#fff;">⏳ Needs Grading (<?= $pending_grading ?>)</a>
      <a href="monitor.php" class="btn btn-purple" style="justify-content:center;">👥 Monitor Students</a>
      <a href="../calendar.php" class="btn btn-secondary" style="justify-content:center;">📅 Calendar</a>
      <a href="../profile.php" class="btn btn-secondary" style="justify-content:center;">👤 My Profile</a>
    </div>
  </div>
</div>

</div><!-- /dash-grid -->
</div><!-- /container -->

<!-- ── CREATE TASK MODAL ── -->
<div class="modal" id="createTaskModal">
  <div class="modal-box">
    <div class="modal-header">
      <h2>＋ Create New Task</h2>
      <button class="modal-close" onclick="closeModal('createTaskModal')">×</button>
    </div>
    <div class="modal-body">
      <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="action" value="create_task">
        <div class="form-group">
          <label>Task Title *</label>
          <input type="text" name="title" required placeholder="e.g., Chapter 5 Assignment">
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Subject</label>
            <input type="text" name="subject" placeholder="e.g., Mathematics">
          </div>
          <div class="form-group">
            <label>School Year</label>
            <select name="year">
              <option value="">Select year</option>
              <?php for ($y = intval(date('Y')); $y <= intval(date('Y')) + 4; $y++): ?>
              <option value="<?= $y ?>"><?= $y ?></option>
              <?php endfor; ?>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label>Assign to Section</label>
          <select name="section_id">
            <option value="">General / All students</option>
            <?php foreach ($teacher_classes as $class): ?>
            <option value="<?= intval($class['id']) ?>"><?= htmlspecialchars($class['class_name']) ?> (<?= intval($class['student_count']) ?> students)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Description</label>
          <textarea name="description" rows="3" placeholder="Task instructions and details..."></textarea>
        </div>
        <div class="form-group">
          <label>Due Date *</label>
          <input type="datetime-local" name="due_date" required>
        </div>
        <div class="form-group">
          <label>Attach Files <span style="color:#7f8c8d;font-weight:400;">(optional)</span></label>
          <input type="file" name="task_files[]" multiple accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.jpg,.png">
          <small style="color:#7f8c8d;font-size:.75rem;">PDF, DOC, PPT, XLS, images accepted</small>
        </div>
        <div style="display:flex;gap:.8rem;margin-top:.5rem;">
          <button type="submit" class="btn btn-primary" style="flex:1;justify-content:center;padding:.8rem;">Create Task</button>
          <button type="button" class="btn btn-secondary" style="flex:1;justify-content:center;padding:.8rem;" onclick="closeModal('createTaskModal')">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ── APPROVE MODAL ── -->
<div class="modal" id="approveModal">
  <div class="modal-box" style="max-width:440px;">
    <div class="modal-header">
      <h2>✅ Approve Submission</h2>
      <button class="modal-close" onclick="closeModal('approveModal')">×</button>
    </div>
    <div class="modal-body">
      <div style="background:#f0fdf4;border:2px solid var(--green);border-radius:8px;padding:1rem;margin-bottom:1rem;">
        <p style="font-size:.88rem;color:var(--dark);margin-bottom:.4rem;"><strong>Task:</strong> <span id="appr_task"></span></p>
        <p style="font-size:.88rem;color:var(--dark);"><strong>Student:</strong> <span id="appr_student"></span></p>
      </div>
      <p style="font-size:.85rem;color:#7f8c8d;margin-bottom:1rem;">Approving will notify the student and mark the submission as approved. You can still grade it afterwards.</p>
      <form method="POST" id="approveForm">
        <input type="hidden" name="action" value="approve_submission">
        <input type="hidden" name="submission_id" id="appr_sid">
        <div style="display:flex;gap:.8rem;">
          <button type="submit" class="btn btn-success" style="flex:1;justify-content:center;padding:.8rem;">✅ Confirm Approve</button>
          <button type="button" class="btn btn-secondary" style="flex:1;justify-content:center;padding:.8rem;" onclick="closeModal('approveModal')">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function openModal(id)  { document.getElementById(id).classList.add('active'); }
function closeModal(id) { document.getElementById(id).classList.remove('active'); }

function toggleSgc(sid) {
  const body  = document.getElementById('sgcb-' + sid);
  const arrow = document.getElementById('arr-' + sid);
  const open  = body.classList.toggle('open');
  arrow.style.transform = open ? 'rotate(180deg)' : 'rotate(0deg)';
}

function openApproveModal(sid, task, student) {
  document.getElementById('appr_sid').value     = sid;
  document.getElementById('appr_task').textContent    = task;
  document.getElementById('appr_student').textContent = student;
  openModal('approveModal');
}

function checkGrade(form) {
  const g = parseInt(form.querySelector('[name=grade]').value);
  if (isNaN(g) || g < 0 || g > 100) {
    alert('Grade must be between 0 and 100.');
    return false;
  }
  return confirm('Submit grade of ' + g + '/100?');
}

// Close modal on backdrop click
document.querySelectorAll('.modal').forEach(m => {
  m.addEventListener('click', e => { if (e.target === m) m.classList.remove('active'); });
});
</script>
</body>
</html>
