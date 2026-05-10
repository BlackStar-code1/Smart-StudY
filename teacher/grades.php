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

// Fetch all graded submissions for this teacher
$stmt = $conn->prepare(
    "SELECT ts.id, ts.task_id, ts.student_id, ts.grade, ts.feedback,
            ts.submission_date, ts.graded_at, ts.notes, ts.file_path,
            t.title AS task_title, t.subject, t.due_date,
            u.full_name AS student_name, u.username, u.email, u.profile_pic,
            grader.full_name AS graded_by_name
     FROM task_submissions ts
     JOIN tasks t ON ts.task_id = t.id
     JOIN users u ON ts.student_id = u.id
     LEFT JOIN users grader ON ts.graded_by = grader.id
     WHERE t.teacher_id = ? AND ts.status = 'graded'
     ORDER BY ts.graded_at DESC, ts.submission_date DESC"
);
$stmt->bind_param('i', $teacher_id);
$stmt->execute();
$graded_submissions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Stats
$grades = array_filter(array_column($graded_submissions, 'grade'), fn($g) => $g !== null);
$avg_grade = count($grades) ? round(array_sum($grades) / count($grades), 1) : null;
$highest = count($grades) ? max($grades) : null;
$lowest  = count($grades) ? min($grades) : null;

function gradeLabel($g) {
    if ($g >= 90) return ['Excellent', 'grade-a', 'grade-a-bg'];
    if ($g >= 75) return ['Good', 'grade-b', 'grade-b-bg'];
    if ($g >= 60) return ['Passing', 'grade-c', 'grade-c-bg'];
    return ['Failing', 'grade-f', 'grade-f-bg'];
}

function gradeCardBorder($g) {
    if ($g >= 75) return '';
    if ($g >= 60) return 'warn';
    return 'fail';
}

// Optional message
$saved = isset($_GET['saved']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Teacher Grades - Smart Study Planner</title>
<style>
*{margin:0;padding:0;box-sizing:border-box;}
:root{--primary:#3498db;--green:#2ecc71;--red:#e74c3c;--orange:#f39c12;--purple:#9b59b6;--dark:#2c3e50;--text:#34495e;}
body{font-family:'Segoe UI',sans-serif;background:#f5f7fa;color:var(--text);}
.navbar{background:linear-gradient(135deg,var(--primary) 0%,var(--purple) 100%);color:white;padding:1rem 2rem;display:flex;justify-content:space-between;align-items:center;box-shadow:0 2px 10px rgba(0,0,0,.1);}
.navbar-brand{font-size:1.3rem;font-weight:bold;}
.navbar-menu{display:flex;gap:1.5rem;align-items:center;}
.navbar-menu a{color:white;text-decoration:none;font-size:.95rem;transition:.3s;}
.navbar-menu a:hover,.navbar-menu a.active{opacity:.8;font-weight:bold;}
.container{max-width:1200px;margin:2rem auto;padding:0 1rem;}
.page-header{background:white;padding:1.5rem 2rem;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,.05);margin-bottom:1.5rem;}
.page-header h1{color:var(--dark);font-size:1.5rem;margin-bottom:.3rem;}
.page-header p{color:#7f8c8d;font-size:.9rem;}
.alert{padding:.9rem 1rem;border-radius:5px;margin-bottom:1rem;font-size:.9rem;}
.alert-success{background:#d4edda;color:#155724;border-left:4px solid var(--green);}
.summary-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.5rem;}
.sum-card{background:white;padding:1.2rem;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,.05);text-align:center;border-top:4px solid var(--primary);}
.sum-card.green{border-top-color:var(--green);}
.sum-card.orange{border-top-color:var(--orange);}
.sum-card.purple{border-top-color:var(--purple);}
.sum-card.red{border-top-color:var(--red);}
.sum-num{font-size:1.8rem;font-weight:900;color:var(--primary);}
.sum-card.green .sum-num{color:var(--green);}
.sum-card.orange .sum-num{color:var(--orange);}
.sum-card.purple .sum-num{color:var(--purple);}
.sum-card.red .sum-num{color:var(--red);}
.sum-label{color:#7f8c8d;font-size:.82rem;margin-top:.3rem;}
.card{background:white;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,.05);overflow:hidden;}
.card-header{padding:1rem 1.5rem;border-bottom:1px solid #ecf0f1;display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;}
.card-header h2{color:var(--dark);font-size:1rem;}
.grade-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:1rem;padding:1.5rem;}
.grade-card{border:2px solid #ecf0f1;border-radius:10px;overflow:hidden;transition:border-color .2s;background:#fff;}
.grade-card:hover{border-color:var(--primary);}
.grade-card.warn{border-color:var(--orange);}
.grade-card.fail{border-color:var(--red);}
.grad-head{padding:1rem 1.2rem;background:#f8f9fa;border-bottom:1px solid #ecf0f1;display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;}
.gc-title{font-weight:800;color:var(--dark);font-size:.98rem;}
.gc-meta{font-size:.78rem;color:#7f8c8d;margin-top:.25rem;line-height:1.4;}
.grade-display{display:inline-flex;align-items:center;gap:.4rem;padding:.25rem .7rem;border-radius:20px;font-weight:900;font-size:.9rem;}
.grade-a{background:#d4edda;color:#155724;}
.grade-b{background:#cce5ff;color:#004085;}
.grade-c{background:#fff3cd;color:#856404;}
.grade-f{background:#f8d7da;color:#721c24;}
.grade-num{font-weight:1000;}
.gc-body{padding:1rem 1.2rem;}
.feedback-box{background:#f0fdf4;border-left:3px solid var(--green);padding:.7rem .9rem;border-radius:6px;font-size:.85rem;color:#155724;margin-bottom:.8rem;}
.feedback-box.fail{background:#fff5f5;border-left-color:var(--red);color:#721c24;}
.meta-row{font-size:.78rem;color:#7f8c8d;margin-top:.4rem;display:flex;gap:.8rem;flex-wrap:wrap;}
.btn{padding:.5rem 1rem;border:none;border-radius:5px;cursor:pointer;font-size:.85rem;font-weight:700;transition:all .2s;text-decoration:none;display:inline-flex;align-items:center;gap:.4rem;}
.btn-secondary{background:#ecf0f1;color:var(--dark);}
.btn-secondary:hover{background:#d5dbdb;}
.empty-state{text-align:center;padding:3rem;color:#7f8c8d;}
.empty-state .icon{font-size:3rem;margin-bottom:1rem;}
@media(max-width:900px){.summary-grid{grid-template-columns:repeat(2,1fr);} .grade-grid{grid-template-columns:1fr;} }
</style>
</head>
<body>
<nav class="navbar">
  <div class="navbar-brand">📚 Smart Study Planner - Teacher</div>
  <div class="navbar-menu">
    <a href="dashboard.php">Dashboard</a>
    <a href="tasks.php">Manage Tasks</a>
    <a href="submissions.php">Submissions</a>
    <a href="grades.php" class="active">Grades</a>
    <a href="monitor.php">Monitor Students</a>
    <a href="../profile.php">Profile</a>
    <a href="../logout.php">Logout</a>
  </div>
</nav>

<div class="container">

  <div class="page-header">
    <h1>📊 Teacher Grades</h1>
    <p>Only graded submissions (score + feedback). Use submissions.php to approve/reject and grade pending items.</p>
  </div>

  <?php if ($saved): ?>
    <div class="alert alert-success">✅ Grade updated successfully.</div>
  <?php endif; ?>

  <div class="summary-grid">
    <div class="sum-card"><div class="sum-num"><?= count($graded_submissions) ?></div><div class="sum-label">Graded Submissions</div></div>
    <div class="sum-card green"><div class="sum-num"><?= $avg_grade !== null ? $avg_grade : '—' ?></div><div class="sum-label">Average / 100</div></div>
    <div class="sum-card purple"><div class="sum-num"><?= $highest ?? '—' ?></div><div class="sum-label">Highest</div></div>
    <div class="sum-card red"><div class="sum-num"><?= $lowest ?? '—' ?></div><div class="sum-label">Lowest</div></div>
  </div>

  <div class="card">
    <div class="card-header">
      <h2>Submissions List</h2>
      <a class="btn btn-secondary" href="submissions.php">← Back to Submissions</a>
    </div>

    <?php if (empty($graded_submissions)): ?>
      <div class="empty-state">
        <div class="icon">📭</div>
        <div>No graded submissions yet.</div>
        <div style="margin-top:1rem;">
          <a class="btn btn-secondary" href="submissions.php">Go to Submissions</a>
        </div>
      </div>
    <?php else: ?>
      <div class="grade-grid">
        <?php foreach ($graded_submissions as $sub):
          $g = $sub['grade'];
          [$lbl, $cls, $bg] = gradeLabel($g);
          $border = gradeCardBorder($g);
        ?>
        <div class="grade-card <?= $border ?>">
          <div class="grad-head">
            <div>
              <div class="gc-title">🏷️ <?= htmlspecialchars($sub['task_title']) ?></div>
              <div class="gc-meta">
                <?= htmlspecialchars($sub['subject'] ?? '—') ?>
                <br>
                Student: <?= htmlspecialchars($sub['student_name'] ?: $sub['username']) ?>
                <br>
                Due: <?= date('M d, Y', strtotime($sub['due_date'])) ?>
              </div>
            </div>
            <div style="text-align:right;">
              <div class="grade-display <?= $cls ?>">
                <span class="grade-num"><?= (int)$g ?></span>
                <span style="font-weight:700;font-size:.8rem;opacity:.9;">/100</span>
              </div>
              <div style="margin-top:.35rem;font-size:.78rem;color:#7f8c8d;font-weight:700;"><?= htmlspecialchars($lbl) ?></div>
            </div>
          </div>
          <div class="gc-body">
            <?php if (!empty($sub['feedback'])): ?>
              <div class="feedback-box <?= $g < 60 ? 'fail' : '' ?>">✏️ <strong>Feedback:</strong> <?= htmlspecialchars($sub['feedback']) ?></div>
            <?php else: ?>
              <div class="feedback-box" style="background:#f8f9fa;border-left-color:#bdc3c7;color:#7f8c8d;">No feedback provided.</div>
            <?php endif; ?>
            <div class="meta-row">
              <span>Submitted: <?= !empty($sub['submission_date']) ? date('M d, Y H:i', strtotime($sub['submission_date'])) : '—' ?></span>
              <span>Graded: <?= !empty($sub['graded_at']) ? date('M d, Y H:i', strtotime($sub['graded_at'])) : '—' ?></span>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

  </div>
</div>
</body>
</html>

