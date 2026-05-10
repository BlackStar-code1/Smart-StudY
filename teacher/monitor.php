<?php
session_start();
require_once '../config/db_config.php';
require_once '../config/auth.php';
require_once '../config/notifications.php';

if (!checkUserRole('teacher')) {
    header('Location: ../login.php');
    exit();
}

$teacher_id = $_SESSION['user_id'];

// All students
$students = $conn->query(
    "SELECT id, full_name, username, email, created_at, status FROM users WHERE role='student' AND status='active' ORDER BY full_name"
)->fetch_all(MYSQLI_ASSOC);

// Tasks created by this teacher
$tasks_result = $conn->prepare("SELECT id, title, subject, due_date, status FROM tasks WHERE teacher_id = ? ORDER BY created_at DESC");
$tasks_result->bind_param('i', $teacher_id);
$tasks_result->execute();
$teacher_tasks = $tasks_result->get_result()->fetch_all(MYSQLI_ASSOC);
$task_ids = array_column($teacher_tasks, 'id');

// Build per-student stats
$student_stats = [];
foreach ($students as $s) {
    $sid = $s['id'];

    // Submissions for THIS teacher's tasks only
    if (!empty($task_ids)) {
        $placeholders = implode(',', array_fill(0, count($task_ids), '?'));
        $types = str_repeat('i', count($task_ids));

        $q = $conn->prepare(
            "SELECT ts.*, t.title, t.due_date, t.subject
             FROM task_submissions ts
             JOIN tasks t ON ts.task_id = t.id
             WHERE ts.student_id = ? AND ts.task_id IN ($placeholders)
             ORDER BY ts.submission_date DESC"
        );
        $params = array_merge([$sid], $task_ids);
        $q->bind_param('i' . $types, ...$params);
        $q->execute();
        $subs = $q->get_result()->fetch_all(MYSQLI_ASSOC);
    } else {
        $subs = [];
    }

    $total_assigned  = count(array_filter($subs, fn($r) => true));
    $submitted_count = count(array_filter($subs, fn($r) => $r['status'] !== 'pending'));
    $graded_count    = count(array_filter($subs, fn($r) => $r['status'] === 'graded'));
    $grades          = array_filter(array_column($subs, 'grade'), fn($g) => $g !== null);
    $avg_grade       = count($grades) ? round(array_sum($grades) / count($grades), 1) : null;
    $overdue         = count(array_filter($subs, fn($r) => $r['status'] === 'pending' && strtotime($r['due_date']) < time()));

    $student_stats[$sid] = [
        'info'            => $s,
        'submissions'     => $subs,
        'total_assigned'  => $total_assigned,
        'submitted_count' => $submitted_count,
        'graded_count'    => $graded_count,
        'avg_grade'       => $avg_grade,
        'overdue'         => $overdue,
        'completion_pct'  => $total_assigned > 0 ? round(($submitted_count / $total_assigned) * 100) : 0,
    ];
}

// Selected student detail
$view_sid    = isset($_GET['student']) ? intval($_GET['student']) : 0;
$view_student = $view_sid && isset($student_stats[$view_sid]) ? $student_stats[$view_sid] : null;

// Overall stats
$total_students   = count($students);
$total_tasks      = count($teacher_tasks);
$all_subs_flat    = array_merge(...array_column($student_stats, 'submissions') ?: [[]]);
$total_submitted  = count(array_filter($all_subs_flat, fn($r) => $r['status'] !== 'pending'));
$total_graded     = count(array_filter($all_subs_flat, fn($r) => $r['status'] === 'graded'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Monitor Students - Smart Study Planner</title>
<style>
*{margin:0;padding:0;box-sizing:border-box;}
:root{--primary:#3498db;--purple:#9b59b6;--green:#2ecc71;--red:#e74c3c;--orange:#f39c12;--dark:#2c3e50;--text:#34495e;}
body{font-family:'Segoe UI',sans-serif;background:#f5f7fa;color:var(--text);}
.navbar{background:linear-gradient(135deg,var(--primary) 0%,var(--purple) 100%);color:white;padding:1rem 2rem;display:flex;justify-content:space-between;align-items:center;box-shadow:0 2px 10px rgba(0,0,0,.1);}
.navbar-brand{font-size:1.3rem;font-weight:bold;}
.navbar-menu{display:flex;gap:1.5rem;align-items:center;}
.navbar-menu a{color:white;text-decoration:none;font-size:.95rem;transition:.3s;}
.navbar-menu a:hover,.navbar-menu a.active{opacity:.8;font-weight:bold;}
.container{max-width:1300px;margin:2rem auto;padding:0 1rem;}
.page-header{background:white;padding:1.5rem 2rem;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,.05);margin-bottom:1.5rem;}
.page-header h1{color:var(--dark);font-size:1.5rem;margin-bottom:.3rem;}
.page-header p{color:#7f8c8d;font-size:.9rem;}
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.5rem;}
.stat-box{background:white;padding:1.2rem;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,.05);text-align:center;border-top:4px solid var(--primary);}
.stat-box.green{border-top-color:var(--green);}
.stat-box.orange{border-top-color:var(--orange);}
.stat-box.purple{border-top-color:var(--purple);}
.stat-number{font-size:1.8rem;font-weight:bold;color:var(--primary);}
.stat-box.green .stat-number{color:var(--green);}
.stat-box.orange .stat-number{color:var(--orange);}
.stat-box.purple .stat-number{color:var(--purple);}
.stat-label{color:#7f8c8d;font-size:.82rem;margin-top:.3rem;}
.layout{display:grid;grid-template-columns:340px 1fr;gap:1.5rem;}
.card{background:white;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,.05);overflow:hidden;}
.card-header{padding:1rem 1.5rem;border-bottom:1px solid #ecf0f1;display:flex;justify-content:space-between;align-items:center;}
.card-header h2{color:var(--dark);font-size:1rem;}
.card-body{padding:1rem;}
.search-box{width:100%;padding:.6rem .8rem;border:2px solid #ecf0f1;border-radius:6px;font-size:.9rem;margin-bottom:.8rem;font-family:inherit;}
.search-box:focus{outline:none;border-color:var(--primary);}
.student-list{display:grid;gap:.5rem;max-height:calc(100vh - 320px);overflow-y:auto;}
.student-card{padding:.9rem 1rem;border-radius:8px;border:2px solid #ecf0f1;cursor:pointer;transition:all .2s;}
.student-card:hover{border-color:var(--primary);background:#f0f7ff;}
.student-card.active{border-color:var(--primary);background:#ebf5fb;}
.student-name{font-weight:600;color:var(--dark);font-size:.95rem;margin-bottom:.3rem;}
.student-meta{font-size:.78rem;color:#7f8c8d;display:flex;gap:.8rem;flex-wrap:wrap;}
.progress-bar-wrap{margin-top:.5rem;}
.progress-bar{height:6px;background:#ecf0f1;border-radius:3px;overflow:hidden;}
.progress-fill{height:100%;border-radius:3px;background:var(--green);transition:width .4s;}
.progress-fill.low{background:var(--red);}
.progress-fill.mid{background:var(--orange);}
.progress-label{font-size:.72rem;color:#7f8c8d;margin-top:.2rem;}
.badge{display:inline-block;padding:.15rem .5rem;border-radius:20px;font-size:.72rem;font-weight:600;}
.badge-pending{background:#fff3cd;color:#856404;}
.badge-submitted{background:#cce5ff;color:#004085;}
.badge-graded{background:#d4edda;color:#155724;}
.badge-overdue{background:#f8d7da;color:#721c24;}
.detail-header{padding:1.5rem;border-bottom:1px solid #ecf0f1;display:flex;justify-content:space-between;align-items:flex-start;}
.detail-name{font-size:1.2rem;font-weight:700;color:var(--dark);}
.detail-email{font-size:.85rem;color:#7f8c8d;margin-top:.2rem;}
.detail-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:.8rem;padding:1rem 1.5rem;border-bottom:1px solid #ecf0f1;}
.d-stat{text-align:center;padding:.8rem;background:#f8f9fa;border-radius:8px;}
.d-stat-num{font-size:1.4rem;font-weight:700;color:var(--primary);}
.d-stat-num.green{color:var(--green);}
.d-stat-num.orange{color:var(--orange);}
.d-stat-num.red{color:var(--red);}
.d-stat-label{font-size:.75rem;color:#7f8c8d;margin-top:.2rem;}
.task-table{width:100%;border-collapse:collapse;font-size:.88rem;}
.task-table th{padding:.7rem 1rem;text-align:left;background:#f8f9fa;color:var(--dark);font-weight:600;border-bottom:2px solid #ecf0f1;}
.task-table td{padding:.7rem 1rem;border-bottom:1px solid #ecf0f1;vertical-align:middle;}
.task-table tr:hover td{background:#f8f9fa;}
.grade-pill{display:inline-block;padding:.2rem .6rem;border-radius:20px;font-weight:700;font-size:.82rem;}
.grade-a{background:#d4edda;color:#155724;}
.grade-b{background:#cce5ff;color:#004085;}
.grade-c{background:#fff3cd;color:#856404;}
.grade-f{background:#f8d7da;color:#721c24;}
.empty-state{text-align:center;padding:3rem;color:#7f8c8d;}
.empty-state .icon{font-size:3rem;margin-bottom:1rem;}
.btn{padding:.4rem .9rem;border:none;border-radius:5px;cursor:pointer;font-size:.82rem;font-weight:600;transition:all .2s;text-decoration:none;display:inline-block;}
.btn-primary{background:var(--primary);color:white;}
.btn-sm{padding:.3rem .6rem;font-size:.78rem;}
@media(max-width:900px){.layout{grid-template-columns:1fr;}.stats-grid{grid-template-columns:repeat(2,1fr);}.detail-stats{grid-template-columns:repeat(2,1fr);}}
</style>
</head>
<body>
<nav class="navbar">
  <div class="navbar-brand">📚 Smart Study Planner - Teacher</div>
  <div class="navbar-menu">
    <a href="dashboard.php">Dashboard</a>
    <a href="tasks.php">Manage Tasks</a>
    <a href="monitor.php" class="active">Monitor Students</a>
    <a href="../calendar.php">Calendar</a>
    <a href="../profile.php">Profile</a>
    <a href="../logout.php">Logout</a>
  </div>
</nav>

<div class="container">

  <div class="page-header">
    <h1>👥 Monitor Students</h1>
    <p>Track student progress, submission rates, and grades across all your tasks.</p>
  </div>

  <div class="stats-grid">
    <div class="stat-box"><div class="stat-number"><?= $total_students ?></div><div class="stat-label">Active Students</div></div>
    <div class="stat-box purple"><div class="stat-number"><?= $total_tasks ?></div><div class="stat-label">Your Tasks</div></div>
    <div class="stat-box green"><div class="stat-number"><?= $total_submitted ?></div><div class="stat-label">Total Submitted</div></div>
    <div class="stat-box orange"><div class="stat-number"><?= $total_graded ?></div><div class="stat-label">Total Graded</div></div>
  </div>

  <div class="layout">

    <!-- LEFT: Student List -->
    <div class="card">
      <div class="card-header"><h2>Students (<?= $total_students ?>)</h2></div>
      <div class="card-body">
        <input type="text" class="search-box" id="studentSearch" placeholder="🔍 Search students..." oninput="filterStudents()">
        <div class="student-list" id="studentList">
          <?php foreach ($student_stats as $sid => $st): ?>
          <?php
            $pct = $st['completion_pct'];
            $fill_class = $pct >= 70 ? '' : ($pct >= 40 ? 'mid' : 'low');
          ?>
          <div class="student-card <?= $view_sid == $sid ? 'active' : '' ?>"
               onclick="window.location='monitor.php?student=<?= $sid ?>'"
               data-name="<?= strtolower(htmlspecialchars($st['info']['full_name'] ?: $st['info']['username'])) ?>">
            <div class="student-name"><?= htmlspecialchars($st['info']['full_name'] ?: $st['info']['username']) ?></div>
            <div class="student-meta">
              <span>📧 <?= htmlspecialchars($st['info']['email']) ?></span>
              <?php if ($st['overdue'] > 0): ?>
              <span style="color:var(--red);">⚠️ <?= $st['overdue'] ?> overdue</span>
              <?php endif; ?>
            </div>
            <div class="progress-bar-wrap">
              <div class="progress-bar"><div class="progress-fill <?= $fill_class ?>" style="width:<?= $pct ?>%"></div></div>
              <div class="progress-label"><?= $st['submitted_count'] ?>/<?= $st['total_assigned'] ?> submitted &bull; <?= $pct ?>% completion</div>
            </div>
          </div>
          <?php endforeach; ?>
          <?php if (empty($students)): ?>
          <div class="empty-state"><p>No active students found.</p></div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- RIGHT: Student Detail -->
    <div class="card">
      <?php if ($view_student): ?>
      <?php $si = $view_student['info']; ?>
      <div class="detail-header">
        <div>
          <div class="detail-name"><?= htmlspecialchars($si['full_name'] ?: $si['username']) ?></div>
          <div class="detail-email">📧 <?= htmlspecialchars($si['email']) ?> &bull; Joined <?= date('M d, Y', strtotime($si['created_at'])) ?></div>
        </div>
        <a href="monitor.php" class="btn btn-primary btn-sm">✕ Close</a>
      </div>

      <div class="detail-stats">
        <div class="d-stat">
          <div class="d-stat-num"><?= $view_student['total_assigned'] ?></div>
          <div class="d-stat-label">Assigned</div>
        </div>
        <div class="d-stat">
          <div class="d-stat-num green"><?= $view_student['submitted_count'] ?></div>
          <div class="d-stat-label">Submitted</div>
        </div>
        <div class="d-stat">
          <div class="d-stat-num orange"><?= $view_student['graded_count'] ?></div>
          <div class="d-stat-label">Graded</div>
        </div>
        <div class="d-stat">
          <div class="d-stat-num <?= $view_student['avg_grade'] !== null ? ($view_student['avg_grade'] >= 75 ? 'green' : ($view_student['avg_grade'] >= 50 ? 'orange' : 'red')) : '' ?>">
            <?= $view_student['avg_grade'] !== null ? $view_student['avg_grade'] . '%' : 'N/A' ?>
          </div>
          <div class="d-stat-label">Avg Grade</div>
        </div>
      </div>

      <?php
        $pct = $view_student['completion_pct'];
        $fill_class = $pct >= 70 ? '' : ($pct >= 40 ? 'mid' : 'low');
      ?>
      <div style="padding:1rem 1.5rem;border-bottom:1px solid #ecf0f1;">
        <div style="display:flex;justify-content:space-between;font-size:.85rem;margin-bottom:.4rem;">
          <span style="font-weight:600;color:var(--dark);">Overall Completion</span>
          <span style="color:#7f8c8d;"><?= $pct ?>%</span>
        </div>
        <div class="progress-bar" style="height:10px;">
          <div class="progress-fill <?= $fill_class ?>" style="width:<?= $pct ?>%"></div>
        </div>
      </div>

      <div style="padding:1rem 1.5rem;">
        <h3 style="font-size:.95rem;color:var(--dark);margin-bottom:.8rem;">Task Breakdown</h3>
        <?php if (empty($view_student['submissions'])): ?>
          <div class="empty-state"><div class="icon">📭</div><p>No tasks assigned to this student yet.</p><a href="tasks.php" class="btn btn-primary">Go to Tasks</a></div>
        <?php else: ?>
        <div style="overflow-x:auto;">
        <table class="task-table">
          <thead>
            <tr>
              <th>Task</th>
              <th>Subject</th>
              <th>Due Date</th>
              <th>Status</th>
              <th>Grade</th>
              <th>Submitted</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($view_student['submissions'] as $sub): ?>
            <?php
              $is_overdue = $sub['status'] === 'pending' && strtotime($sub['due_date']) < time();
              $grade = $sub['grade'];
              $grade_class = '';
              if ($grade !== null) {
                $grade_class = $grade >= 90 ? 'grade-a' : ($grade >= 75 ? 'grade-b' : ($grade >= 60 ? 'grade-c' : 'grade-f'));
              }
            ?>
            <tr>
              <td style="font-weight:600;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($sub['title']) ?></td>
              <td><?= htmlspecialchars($sub['subject'] ?? '—') ?></td>
              <td style="white-space:nowrap;"><?= date('M d, Y', strtotime($sub['due_date'])) ?></td>
              <td>
                <?php if ($is_overdue): ?>
                  <span class="badge badge-overdue">Overdue</span>
                <?php else: ?>
                  <span class="badge badge-<?= $sub['status'] ?>"><?= ucfirst($sub['status']) ?></span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($grade !== null): ?>
                  <span class="grade-pill <?= $grade_class ?>"><?= $grade ?>/100</span>
                <?php else: ?>
                  <span style="color:#bdc3c7;">—</span>
                <?php endif; ?>
              </td>
              <td style="white-space:nowrap;color:#7f8c8d;font-size:.82rem;">
                <?= $sub['submission_date'] ? date('M d, Y', strtotime($sub['submission_date'])) : '—' ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
        <?php endif; ?>
      </div>

      <?php else: ?>
      <div class="card-header"><h2>👤 Student Detail</h2></div>
      <div class="card-body">
        <div class="empty-state">
          <div class="icon">👈</div>
          <p>Select a student from the list to view their progress and task breakdown.</p>
        </div>
      </div>
      <?php endif; ?>
    </div>

  </div><!-- /layout -->
</div><!-- /container -->

<script>
function filterStudents() {
  const q = document.getElementById('studentSearch').value.toLowerCase();
  document.querySelectorAll('.student-card').forEach(card => {
    card.style.display = card.dataset.name.includes(q) ? '' : 'none';
  });
}
</script>
</body>
</html>
