<?php
session_start();
require_once '../config/db_config.php';
require_once '../config/auth.php';
require_once '../config/notifications.php';

if (!checkUserRole('student')) {
    header('Location: ../login.php');
    exit();
}

$student_id   = $_SESSION['user_id'];
$unread_count = getUnreadNotificationsCount($student_id);

// Fetch all graded submissions for this student
$stmt = $conn->prepare(
    "SELECT ts.id, ts.task_id, ts.status, ts.grade, ts.feedback,
            ts.submission_date, ts.graded_at, ts.notes, ts.file_path,
            t.title, t.subject, t.due_date, t.description,
            u.full_name as teacher_name
     FROM task_submissions ts
     JOIN tasks t ON ts.task_id = t.id
     JOIN users u ON t.teacher_id = u.id
     WHERE ts.student_id = ?
     ORDER BY ts.graded_at DESC, ts.submission_date DESC"
);
$stmt->bind_param('i', $student_id);
$stmt->execute();
$all_submissions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Separate graded vs pending
$graded_subs  = array_filter($all_submissions, fn($s) => $s['status'] === 'graded');
$approved_subs= array_filter($all_submissions, fn($s) => $s['status'] === 'approved');
$rejected_subs= array_filter($all_submissions, fn($s) => $s['status'] === 'rejected');
$pending_subs = array_filter($all_submissions, fn($s) => $s['status'] === 'submitted');

// Grade stats
$grades      = array_filter(array_column($graded_subs, 'grade'), fn($g) => $g !== null);
$avg_grade   = count($grades) ? round(array_sum($grades) / count($grades), 1) : null;
$highest     = count($grades) ? max($grades) : null;
$lowest      = count($grades) ? min($grades) : null;
$pass_count  = count(array_filter($grades, fn($g) => $g >= 60));
$fail_count  = count(array_filter($grades, fn($g) => $g < 60));

function gradeLabel($g) {
    if ($g >= 90) return ['Excellent', 'grade-a'];
    if ($g >= 75) return ['Good',      'grade-b'];
    if ($g >= 60) return ['Passing',   'grade-c'];
    return ['Failing', 'grade-f'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Grades - Smart Study Planner</title>
<style>
*{margin:0;padding:0;box-sizing:border-box;}
:root{--primary:#3498db;--green:#2ecc71;--red:#e74c3c;--orange:#f39c12;--purple:#9b59b6;--dark:#2c3e50;--text:#34495e;}
body{font-family:'Segoe UI',sans-serif;background:#f5f7fa;color:var(--text);}
.navbar{background:linear-gradient(135deg,var(--primary) 0%,var(--green) 100%);color:white;padding:1rem 2rem;display:flex;justify-content:space-between;align-items:center;box-shadow:0 2px 10px rgba(0,0,0,.1);}
.navbar-brand{font-size:1.3rem;font-weight:bold;}
.navbar-menu{display:flex;gap:1.5rem;align-items:center;}
.navbar-menu a{color:white;text-decoration:none;font-size:.95rem;transition:.3s;}
.navbar-menu a:hover,.navbar-menu a.active{opacity:.8;font-weight:bold;}
.notif-bell{position:relative;cursor:pointer;font-size:1.2rem;}
.notif-badge{position:absolute;top:-8px;right:-8px;background:var(--red);color:white;border-radius:50%;width:18px;height:18px;display:flex;align-items:center;justify-content:center;font-size:.7rem;font-weight:bold;}
.container{max-width:1100px;margin:2rem auto;padding:0 1rem;}
.page-header{background:white;padding:1.5rem 2rem;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,.05);margin-bottom:1.5rem;}
.page-header h1{color:var(--dark);font-size:1.5rem;margin-bottom:.3rem;}
.page-header p{color:#7f8c8d;font-size:.9rem;}
/* Summary cards */
.summary-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:1rem;margin-bottom:1.5rem;}
.sum-card{background:white;border-radius:10px;padding:1.2rem;box-shadow:0 2px 10px rgba(0,0,0,.05);text-align:center;border-top:4px solid var(--primary);}
.sum-card.green{border-top-color:var(--green);}
.sum-card.red{border-top-color:var(--red);}
.sum-card.orange{border-top-color:var(--orange);}
.sum-card.purple{border-top-color:var(--purple);}
.sum-num{font-size:1.8rem;font-weight:900;color:var(--primary);}
.sum-card.green .sum-num{color:var(--green);}
.sum-card.red .sum-num{color:var(--red);}
.sum-card.orange .sum-num{color:var(--orange);}
.sum-card.purple .sum-num{color:var(--purple);}
.sum-label{font-size:.78rem;color:#7f8c8d;margin-top:.3rem;}
/* Average big display */
.avg-banner{background:linear-gradient(135deg,var(--primary),var(--purple));color:white;border-radius:12px;padding:1.5rem 2rem;margin-bottom:1.5rem;display:flex;align-items:center;gap:2rem;}
.avg-score{font-size:3.5rem;font-weight:900;line-height:1;}
.avg-info h2{font-size:1.1rem;margin-bottom:.3rem;}
.avg-info p{font-size:.88rem;opacity:.85;}
.avg-bar-wrap{flex:1;max-width:300px;}
.avg-bar-label{font-size:.78rem;opacity:.8;margin-bottom:.4rem;display:flex;justify-content:space-between;}
.avg-bar{height:10px;background:rgba(255,255,255,.3);border-radius:5px;overflow:hidden;}
.avg-bar-fill{height:100%;background:white;border-radius:5px;transition:width .8s;}
/* Grade cards */
.section-title{font-size:1rem;font-weight:700;color:var(--dark);margin-bottom:.8rem;display:flex;align-items:center;gap:.5rem;}
.grade-grid{display:grid;gap:1rem;margin-bottom:2rem;}
.grade-card{background:white;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,.05);overflow:hidden;border-left:5px solid var(--green);}
.grade-card.fail{border-left-color:var(--red);}
.grade-card.warn{border-left-color:var(--orange);}
.grade-card.good{border-left-color:var(--primary);}
.gc-head{padding:1rem 1.2rem;display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;}
.gc-title{font-weight:700;color:var(--dark);font-size:.95rem;margin-bottom:.3rem;}
.gc-meta{font-size:.78rem;color:#7f8c8d;}
.gc-score-wrap{text-align:right;flex-shrink:0;}
.gc-score{font-size:1.8rem;font-weight:900;}
.gc-score.grade-a{color:#155724;}
.gc-score.grade-b{color:#004085;}
.gc-score.grade-c{color:#856404;}
.gc-score.grade-f{color:#721c24;}
.gc-label{font-size:.72rem;font-weight:700;padding:.2rem .6rem;border-radius:20px;display:inline-block;margin-top:.2rem;}
.grade-a-bg{background:#d4edda;color:#155724;}
.grade-b-bg{background:#cce5ff;color:#004085;}
.grade-c-bg{background:#fff3cd;color:#856404;}
.grade-f-bg{background:#f8d7da;color:#721c24;}
.gc-body{padding:.8rem 1.2rem;border-top:1px solid #f0f0f0;}
.feedback-box{background:#f0fdf4;border-left:3px solid var(--green);padding:.7rem .9rem;border-radius:5px;font-size:.85rem;color:#155724;margin-bottom:.5rem;}
.feedback-box.fail{background:#fff5f5;border-left-color:var(--red);color:#721c24;}
.gc-dates{font-size:.75rem;color:#bdc3c7;display:flex;gap:1rem;flex-wrap:wrap;}
/* Pending section */
.pending-card{background:white;border-radius:10px;padding:1rem 1.2rem;box-shadow:0 2px 10px rgba(0,0,0,.05);border-left:4px solid var(--orange);display:flex;justify-content:space-between;align-items:center;gap:1rem;margin-bottom:.7rem;}
.pending-title{font-weight:600;color:var(--dark);font-size:.9rem;}
.pending-meta{font-size:.78rem;color:#7f8c8d;margin-top:.2rem;}
.badge{display:inline-block;padding:.2rem .6rem;border-radius:20px;font-size:.72rem;font-weight:600;}
.badge-submitted{background:#fff3cd;color:#856404;}
.badge-pending{background:#ecf0f1;color:#7f8c8d;}
.empty-state{text-align:center;padding:3rem;color:#7f8c8d;}
.empty-state .icon{font-size:3rem;margin-bottom:1rem;}
.btn{padding:.4rem .9rem;border:none;border-radius:5px;cursor:pointer;font-size:.82rem;font-weight:600;transition:all .2s;text-decoration:none;display:inline-block;}
.btn-primary{background:var(--primary);color:white;}
.btn-primary:hover{background:#2980b9;}
.btn-sm{padding:.3rem .6rem;font-size:.75rem;}
@media(max-width:900px){.summary-grid{grid-template-columns:repeat(3,1fr);}.avg-banner{flex-direction:column;gap:1rem;}.avg-bar-wrap{max-width:100%;width:100%;}}
@media(max-width:600px){.summary-grid{grid-template-columns:repeat(2,1fr);}}
</style>
</head>
<body>
<nav class="navbar">
  <div class="navbar-brand">📚 Smart Study Planner</div>
  <div class="navbar-menu">
    <a href="dashboard.php">Dashboard</a>
    <a href="tasks.php">Tasks</a>
    <a href="grades.php" class="active">My Grades</a>
    <a href="../calendar.php">Calendar</a>
    <a href="../profile.php">Profile</a>
    <a href="../logout.php">Logout</a>
  </div>
</nav>

<div class="container">

  <div class="page-header">
    <h1>🎓 My Grades</h1>
    <p>View all your graded tasks, scores, and teacher feedback.</p>
  </div>

  <!-- Average Banner -->
  <?php if ($avg_grade !== null): ?>
  <?php [$avg_lbl, $avg_cls] = gradeLabel($avg_grade); ?>
  <div class="avg-banner">
    <div>
      <div style="font-size:.85rem;opacity:.8;margin-bottom:.3rem;">Overall Average</div>
      <div class="avg-score"><?= $avg_grade ?><span style="font-size:1.5rem;font-weight:400;">/100</span></div>
    </div>
    <div class="avg-info">
      <h2><?= $avg_lbl ?></h2>
      <p><?= count($graded_subs) ?> task<?= count($graded_subs) !== 1 ? 's' : '' ?> graded &bull; <?= $pass_count ?> passed &bull; <?= $fail_count ?> failed</p>
    </div>
    <div class="avg-bar-wrap">
      <div class="avg-bar-label"><span>0</span><span><?= $avg_grade ?>%</span><span>100</span></div>
      <div class="avg-bar"><div class="avg-bar-fill" style="width:<?= $avg_grade ?>%"></div></div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Summary Stats -->
  <div class="summary-grid">
    <div class="sum-card"><div class="sum-num"><?= count($all_submissions) ?></div><div class="sum-label">Total Submitted</div></div>
    <div class="sum-card green"><div class="sum-num"><?= count($graded_subs) ?></div><div class="sum-label">Graded</div></div>
    <div class="sum-card orange"><div class="sum-num"><?= count($pending_subs) ?></div><div class="sum-label">Awaiting Grade</div></div>
    <div class="sum-card purple"><div class="sum-num"><?= $highest ?? '—' ?></div><div class="sum-label">Highest Score</div></div>
    <div class="sum-card red"><div class="sum-num"><?= $lowest ?? '—' ?></div><div class="sum-label">Lowest Score</div></div>
  </div>

  <!-- Graded Tasks -->
  <div class="section-title">✅ Graded Tasks (<?= count($graded_subs) ?>)</div>

  <?php if (empty($graded_subs)): ?>
  <div class="empty-state" style="background:white;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,.05);margin-bottom:2rem;">
    <div class="icon">📭</div>
    <p>No graded tasks yet. Submit your tasks and wait for your teacher to grade them.</p>
    <a href="dashboard.php" class="btn btn-primary" style="margin-top:1rem;">Go to Dashboard</a>
  </div>
  <?php else: ?>
  <div class="grade-grid">
    <?php foreach ($graded_subs as $sub):
      [$lbl, $cls] = gradeLabel($sub['grade']);
      $bg_cls = $cls . '-bg';
      $card_border = $sub['grade'] >= 75 ? '' : ($sub['grade'] >= 60 ? 'warn' : 'fail');
      $is_late = $sub['submission_date'] && strtotime($sub['submission_date']) > strtotime($sub['due_date']);
    ?>
    <div class="grade-card <?= $card_border ?>">
      <div class="gc-head">
        <div>
          <div class="gc-title"><?= htmlspecialchars($sub['title']) ?></div>
          <div class="gc-meta">
            <?= htmlspecialchars($sub['subject'] ?? '') ?><?= $sub['subject'] ? ' &bull; ' : '' ?>
            Teacher: <?= htmlspecialchars($sub['teacher_name']) ?> &bull;
            Due: <?= date('M d, Y', strtotime($sub['due_date'])) ?>
            <?php if ($is_late): ?> &bull; <span style="color:var(--red);font-weight:600;">Submitted Late</span><?php endif; ?>
          </div>
        </div>
        <div class="gc-score-wrap">
          <div class="gc-score <?= $cls ?>"><?= $sub['grade'] ?><span style="font-size:.9rem;font-weight:400;">/100</span></div>
          <span class="gc-label <?= $bg_cls ?>"><?= $lbl ?></span>
        </div>
      </div>
      <div class="gc-body">
        <?php if (!empty($sub['feedback'])): ?>
        <div class="feedback-box <?= $sub['grade'] < 60 ? 'fail' : '' ?>">
          ✏️ <strong>Teacher feedback:</strong> <?= htmlspecialchars($sub['feedback']) ?>
        </div>
        <?php else: ?>
        <p style="font-size:.82rem;color:#bdc3c7;margin-bottom:.5rem;">No feedback provided.</p>
        <?php endif; ?>
        <div class="gc-dates">
          <?php if ($sub['submission_date']): ?>
          <span>📤 Submitted: <?= date('M d, Y H:i', strtotime($sub['submission_date'])) ?></span>
          <?php endif; ?>
          <?php if ($sub['graded_at']): ?>
          <span>✅ Graded: <?= date('M d, Y H:i', strtotime($sub['graded_at'])) ?></span>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Awaiting Grade -->
  <?php if (!empty($pending_subs)): ?>
  <div class="section-title">⏳ Awaiting Review (<?= count($pending_subs) ?>)</div>
  <?php foreach ($pending_subs as $sub): ?>
  <div class="pending-card">
    <div>
      <div class="pending-title"><?= htmlspecialchars($sub['title']) ?></div>
      <div class="pending-meta">
        <?= htmlspecialchars($sub['subject'] ?? '') ?><?= $sub['subject'] ? ' &bull; ' : '' ?>
        Teacher: <?= htmlspecialchars($sub['teacher_name']) ?> &bull;
        Due: <?= date('M d, Y', strtotime($sub['due_date'])) ?>
        <?php if ($sub['submission_date']): ?>
        &bull; Submitted: <?= date('M d, Y', strtotime($sub['submission_date'])) ?>
        <?php endif; ?>
      </div>
    </div>
    <span class="badge badge-submitted">⏳ Awaiting Review</span>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>

  <!-- Approved (awaiting grade) -->
  <?php if (!empty($approved_subs)): ?>
  <div class="section-title">✅ Approved — Awaiting Grade (<?= count($approved_subs) ?>)</div>
  <?php foreach ($approved_subs as $sub): ?>
  <div class="pending-card" style="border-left-color:#3498db;">
    <div>
      <div class="pending-title"><?= htmlspecialchars($sub['title']) ?></div>
      <div class="pending-meta">
        Teacher: <?= htmlspecialchars($sub['teacher_name']) ?> &bull;
        Due: <?= date('M d, Y', strtotime($sub['due_date'])) ?>
      </div>
    </div>
    <span class="badge" style="background:#cce5ff;color:#004085;">✅ Approved</span>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>

  <!-- Rejected -->
  <?php if (!empty($rejected_subs)): ?>
  <div class="section-title" style="color:var(--red);">❌ Rejected — Resubmit Required (<?= count($rejected_subs) ?>)</div>
  <?php foreach ($rejected_subs as $sub): ?>
  <div class="pending-card" style="border-left-color:var(--red);background:#fff5f5;">
    <div>
      <div class="pending-title"><?= htmlspecialchars($sub['title']) ?></div>
      <div class="pending-meta">
        Teacher: <?= htmlspecialchars($sub['teacher_name']) ?> &bull;
        Due: <?= date('M d, Y', strtotime($sub['due_date'])) ?>
        <?php if (!empty($sub['feedback'])): ?>
        <br>❌ Reason: <?= htmlspecialchars($sub['feedback']) ?>
        <?php endif; ?>
      </div>
    </div>
    <div style="display:flex;flex-direction:column;align-items:flex-end;gap:.4rem;">
      <span class="badge" style="background:#f8d7da;color:#721c24;">❌ Rejected</span>
      <a href="dashboard.php" class="btn btn-primary btn-sm">Resubmit</a>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>

</div>
</body>
</html>
