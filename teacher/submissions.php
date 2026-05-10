<?php
session_start();
require_once '../config/db_config.php';
require_once '../config/auth.php';
require_once '../config/tasks.php';
require_once '../config/notifications.php';

// CSRF helper for teacher actions
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];


if (!checkUserRole('teacher')) {
    header('Location: ../login.php');
    exit();
}

$teacher_id = $_SESSION['user_id'];

// ── Handle approve / reject ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['approve_submission', 'reject_submission'])) {
    if (!hash_equals($csrf_token, $_POST['csrf_token'] ?? '')) {
        header('HTTP/1.1 400 Bad Request');
        exit('Invalid CSRF token');
    }

    $submission_id = intval($_POST['submission_id']);
    $action        = $_POST['action'];
    $reject_reason = trim($_POST['reject_reason'] ?? '');

    $verify = $conn->prepare(
        "SELECT ts.id, ts.student_id, ts.task_id, t.title
         FROM task_submissions ts
         JOIN tasks t ON ts.task_id = t.id
         WHERE ts.id = ? AND t.teacher_id = ?"
    );
    $verify->bind_param('ii', $submission_id, $teacher_id);
    $verify->execute();
    $sub_row = $verify->get_result()->fetch_assoc();

if ($sub_row) {
        if ($action === 'approve_submission') {
                $upd = $conn->prepare("UPDATE task_submissions SET status='approved', feedback=NULL WHERE id=?");
            $upd->bind_param('i', $submission_id);
            $upd->execute();
            createNotification(
                $sub_row['student_id'],
                'Submission Approved',
                'Your submission for "' . $sub_row['title'] . '" has been approved by your teacher.',
                'submission',
                $submission_id
            );
            header('Location: submissions.php?approved=1&task=' . intval($sub_row['task_id']));
        } else {
            // Reject — store reason in feedback and keep student able to resubmit
            $upd = $conn->prepare("UPDATE task_submissions SET status='rejected', feedback=? WHERE id=?");
            $upd->bind_param('si', $reject_reason, $submission_id);
            $upd->execute();

            $rejection_message = 'Your submission for "' . $sub_row['title'] . '" was rejected';
            if ($reject_reason !== '') {
                $rejection_message .= ': ' . $reject_reason;
            }
            $rejection_message .= '. Please resubmit.';


            createNotification(
                $sub_row['student_id'],
                'Submission Rejected',
                $rejection_message,
                'submission',
                $submission_id
            );
            header('Location: submissions.php?rejected=1');
        }
        exit();
    }
}

// ── Handle grading ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'grade_submission') {
    $submission_id = intval($_POST['submission_id']);
    $grade         = max(0, min(100, intval($_POST['grade'])));
    $feedback      = trim($_POST['feedback'] ?? '');

    // Verify submission belongs to this teacher's task
    $verify = $conn->prepare(
        "SELECT ts.id, ts.student_id, ts.task_id, t.title
         FROM task_submissions ts
         JOIN tasks t ON ts.task_id = t.id
         WHERE ts.id = ? AND t.teacher_id = ?"
    );
    $verify->bind_param('ii', $submission_id, $teacher_id);
    $verify->execute();
    $sub_row = $verify->get_result()->fetch_assoc();

    if ($sub_row) {
        gradeSubmission($submission_id, $grade, $feedback, $teacher_id);
        createNotification(
            $sub_row['student_id'],
            'Task Graded',
            'Your submission for "' . $sub_row['title'] . '" has been graded: ' . $grade . '/100',
            'grade',
            $submission_id
        );
        $redirect = 'submissions.php?graded=1&task=' . $sub_row['task_id'];
        header('Location: ' . $redirect);
        exit();
    }
}

// ── Filters ───────────────────────────────────────────────────────────────────
$filter_task   = isset($_GET['task'])   ? intval($_GET['task'])   : 0;
$filter_status = $_GET['status'] ?? '';
$search        = trim($_GET['search'] ?? '');

// ── Fetch all submissions for this teacher's tasks ────────────────────────────
$sql = "SELECT ts.id, ts.task_id, ts.student_id, ts.status, ts.grade,
               ts.feedback, ts.notes, ts.file_path, ts.submission_date,
               t.title as task_title, t.subject, t.due_date,
               u.full_name as student_name, u.username, u.email
        FROM task_submissions ts
        JOIN tasks t ON ts.task_id = t.id
        JOIN users u ON ts.student_id = u.id
        WHERE t.teacher_id = ?
          AND ts.status != 'graded'";

$params = [$teacher_id];
$types  = 'i';

if ($filter_task) {
    $sql .= " AND ts.task_id = ?";
    $params[] = $filter_task;
    $types   .= 'i';
}
if ($filter_status) {
    $sql .= " AND ts.status = ?";
    $params[] = $filter_status;
    $types   .= 's';
}
if ($search) {
    $sql .= " AND (u.full_name LIKE ? OR t.title LIKE ?)";
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $types   .= 'ss';
}

$sql .= " ORDER BY ts.submission_date DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$submissions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ── Teacher's tasks for filter dropdown ───────────────────────────────────────
$tasks_stmt = $conn->prepare("SELECT id, title FROM tasks WHERE teacher_id = ? ORDER BY created_at DESC");
$tasks_stmt->bind_param('i', $teacher_id);
$tasks_stmt->execute();
$teacher_tasks = $tasks_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ── Stats ─────────────────────────────────────────────────────────────────────
$total      = count($submissions);
$pending_g  = count(array_filter($submissions, fn($s) => $s['status'] === 'submitted'));
$approved   = count(array_filter($submissions, fn($s) => $s['status'] === 'approved'));
$rejected   = count(array_filter($submissions, fn($s) => $s['status'] === 'rejected'));
$graded     = count(array_filter($submissions, fn($s) => $s['status'] === 'graded'));
$avg_grade  = null;

// Pending grading/approval actions are teacher-only; CSRF handled above

$grades     = array_filter(array_column($submissions, 'grade'), fn($g) => $g !== null);
if (count($grades)) $avg_grade = round(array_sum($grades) / count($grades), 1);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Student Submissions - Smart Study Planner</title>
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
.stat-box.orange{border-top-color:var(--orange);}
.stat-box.green{border-top-color:var(--green);}
.stat-box.purple{border-top-color:var(--purple);}
.stat-number{font-size:1.8rem;font-weight:bold;color:var(--primary);}
.stat-box.orange .stat-number{color:var(--orange);}
.stat-box.green .stat-number{color:var(--green);}
.stat-box.purple .stat-number{color:var(--purple);}
.stat-label{color:#7f8c8d;font-size:.82rem;margin-top:.3rem;}
.filters{background:white;padding:1.2rem 1.5rem;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,.05);margin-bottom:1.5rem;}
.filters form{display:flex;gap:.8rem;flex-wrap:wrap;align-items:flex-end;}
.filter-group{display:flex;flex-direction:column;gap:.3rem;min-width:160px;}
.filter-group label{font-size:.8rem;font-weight:600;color:var(--dark);}
.filter-group input,.filter-group select{padding:.55rem .7rem;border:2px solid #ecf0f1;border-radius:5px;font-size:.88rem;font-family:inherit;}
.filter-group input:focus,.filter-group select:focus{outline:none;border-color:var(--primary);}
.btn{padding:.5rem 1rem;border:none;border-radius:5px;cursor:pointer;font-size:.85rem;font-weight:600;transition:all .2s;text-decoration:none;display:inline-block;}
.btn-primary{background:var(--primary);color:white;}
.btn-primary:hover{background:#2980b9;}
.btn-secondary{background:#ecf0f1;color:var(--dark);}
.btn-secondary:hover{background:#d5dbdb;}
.btn-success{background:var(--green);color:white;}
.btn-success:hover{background:#27ae60;}
.btn-sm{padding:.3rem .7rem;font-size:.78rem;}
.alert{padding:.9rem 1rem;border-radius:5px;margin-bottom:1rem;font-size:.9rem;}
.alert-success{background:#d4edda;color:#155724;border-left:4px solid var(--green);}
.card{background:white;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,.05);overflow:hidden;}
.card-header{padding:1rem 1.5rem;border-bottom:1px solid #ecf0f1;display:flex;justify-content:space-between;align-items:center;}
.card-header h2{color:var(--dark);font-size:1rem;}
.sub-grid{display:grid;gap:1rem;padding:1.5rem;}
.sub-card{border:2px solid #ecf0f1;border-radius:10px;overflow:hidden;transition:border-color .2s;}
.sub-card:hover{border-color:var(--primary);}
.sub-card.needs-grade{border-color:var(--orange);}
.sub-card.graded{border-color:var(--green);}
.sub-head{padding:1rem 1.2rem;background:#f8f9fa;display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;}
.sub-task{font-weight:700;color:var(--dark);font-size:.95rem;}
.sub-student{font-size:.85rem;color:var(--primary);font-weight:600;margin-top:.2rem;}
.sub-meta{font-size:.78rem;color:#7f8c8d;margin-top:.3rem;}
.sub-body{padding:1rem 1.2rem;}
.badge{display:inline-block;padding:.2rem .6rem;border-radius:20px;font-size:.72rem;font-weight:600;}
.badge-submitted{background:#fff3cd;color:#856404;}
.badge-graded{background:#d4edda;color:#155724;}
.badge-pending{background:#ecf0f1;color:#7f8c8d;}
.grade-display{display:inline-flex;align-items:center;gap:.4rem;padding:.3rem .8rem;border-radius:20px;font-weight:700;font-size:.88rem;}
.grade-a{background:#d4edda;color:#155724;}
.grade-b{background:#cce5ff;color:#004085;}
.grade-c{background:#fff3cd;color:#856404;}
.grade-f{background:#f8d7da;color:#721c24;}
.notes-box{background:#f8f9fa;border-radius:6px;padding:.7rem;font-size:.82rem;color:#555;margin-bottom:.8rem;border-left:3px solid #ecf0f1;}
.grade-form{display:flex;gap:.6rem;align-items:flex-end;flex-wrap:wrap;margin-top:.8rem;padding-top:.8rem;border-top:1px solid #ecf0f1;}
.grade-form .fg{display:flex;flex-direction:column;gap:.3rem;}
.grade-form label{font-size:.78rem;font-weight:600;color:var(--dark);}
.grade-form input[type=number]{width:80px;padding:.5rem;border:2px solid #ecf0f1;border-radius:5px;font-size:.9rem;}
.grade-form input[type=text]{width:220px;padding:.5rem;border:2px solid #ecf0f1;border-radius:5px;font-size:.9rem;font-family:inherit;}
.grade-form input:focus{outline:none;border-color:var(--green);}
.feedback-box{background:#f0fdf4;border-left:3px solid var(--green);padding:.6rem .8rem;border-radius:5px;font-size:.82rem;color:#155724;margin-top:.5rem;}
.file-link{display:inline-flex;align-items:center;gap:.4rem;padding:.4rem .8rem;background:#ebf5fb;border-radius:5px;color:var(--primary);text-decoration:none;font-size:.82rem;font-weight:600;border:1px solid #bee3f8;}
.file-link:hover{background:#d6eaf8;}
.empty-state{text-align:center;padding:3rem;color:#7f8c8d;}
.empty-state .icon{font-size:3rem;margin-bottom:1rem;}
.sub-card.approved{border-color:#3498db;}
.sub-card.rejected{border-color:var(--red);}
.badge-approved{background:#cce5ff;color:#004085;}
.badge-rejected{background:#f8d7da;color:#721c24;}
@media(max-width:768px){.stats-grid{grid-template-columns:repeat(2,1fr);}.filters form{flex-direction:column;}.grade-form{flex-direction:column;}.grade-form input[type=text]{width:100%;}}
</style>
</head>
<body>
<nav class="navbar">
  <div class="navbar-brand">📚 Smart Study Planner - Teacher</div>
  <div class="navbar-menu">
    <a href="dashboard.php">Dashboard</a>
    <a href="tasks.php">Manage Tasks</a>
    <a href="submissions.php" class="active">Submissions</a>
    <a href="grades.php">Grades</a>
    <a href="monitor.php">Monitor Students</a>
    <a href="../calendar.php">Calendar</a>
    <a href="../profile.php">Profile</a>
    <a href="../logout.php">Logout</a>
  </div>
</nav>

<div class="container">

  <div class="page-header">
    <h1>📥 Student Submissions</h1>
    <p>Review, view files, and grade all submitted tasks from your students.</p>
  </div>

  <?php if (isset($_GET['graded'])): ?>
  <div class="alert alert-success">✅ Submission graded successfully!</div>
  <?php endif; ?>
  <?php if (isset($_GET['approved'])): ?>
  <div class="alert alert-success">✅ Submission approved! Student has been notified.</div>
  <?php endif; ?>
  <?php if (isset($_GET['rejected'])): ?>
  <div class="alert alert-error" style="background:#f8d7da;color:#721c24;border-left:4px solid var(--red);">❌ Submission rejected. Student has been notified to resubmit.</div>
  <?php endif; ?>

    <div class="stats-grid">
    <div class="stat-box"><div class="stat-number"><?= count(array_filter($submissions, fn($s)=>$s['status']!=='graded')) ?></div><div class="stat-label">Open Items</div></div>
    <div class="stat-box orange"><div class="stat-number"><?= $pending_g ?></div><div class="stat-label">Needs Review</div></div>
    <div class="stat-box" style="border-top-color:#3498db;"><div class="stat-number" style="color:#3498db;">0</div><div class="stat-label">Approved</div></div>
    <div class="stat-box green"><div class="stat-number"><?= $graded ?></div><div class="stat-label">Graded</div></div>
  </div>

  <!-- Filters -->
  <div class="filters">
    <form method="GET">
      <div class="filter-group">
        <label>Task</label>
        <select name="task">
          <option value="">All Tasks</option>
          <?php foreach ($teacher_tasks as $t): ?>
          <option value="<?= $t['id'] ?>" <?= $filter_task == $t['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($t['title']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="filter-group">
        <label>Status</label>
        <select name="status">
          <option value="">All Status</option>
          <option value="submitted" <?= $filter_status === 'submitted' ? 'selected' : '' ?>>Needs Review</option>
          <option value="approved"  <?= $filter_status === 'approved'  ? 'selected' : '' ?>>Approved</option>
          <option value="rejected"  <?= $filter_status === 'rejected'  ? 'selected' : '' ?>>Rejected</option>
          <option value="graded"    <?= $filter_status === 'graded'    ? 'selected' : '' ?>>Graded</option>
          <option value="pending"   <?= $filter_status === 'pending'   ? 'selected' : '' ?>>Pending</option>
        </select>
      </div>
      <div class="filter-group">
        <label>Search Student / Task</label>
        <input type="text" name="search" placeholder="Name or task title..." value="<?= htmlspecialchars($search) ?>">
      </div>
      <div class="filter-group" style="justify-content:flex-end;">
        <button type="submit" class="btn btn-primary">Filter</button>
        <a href="submissions.php" class="btn btn-secondary" style="margin-top:.3rem;">Clear</a>
      </div>
    </form>
  </div>

  <!-- Submissions List -->
  <div class="card">
    <div class="card-header">
      <h2>Submissions (<?= $total ?>)</h2>
      <?php if ($pending_g > 0): ?>
      <span style="background:var(--orange);color:white;padding:.2rem .7rem;border-radius:20px;font-size:.78rem;font-weight:700;"><?= $pending_g ?> need grading</span>
      <?php endif; ?>
    </div>

    <?php if (empty($submissions)): ?>
    <div class="empty-state">
      <div class="icon">📭</div>
      <p>No submissions found<?= ($filter_status || $filter_task || $search) ? ' matching your filters' : ' yet' ?>.</p>
      <?php if ($filter_status || $filter_task || $search): ?>
      <a href="submissions.php" class="btn btn-secondary" style="margin-top:1rem;">Clear Filters</a>
      <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="sub-grid">
      <?php foreach ($submissions as $sub):
        $needs_review = $sub['status'] === 'submitted';
        $is_approved  = $sub['status'] === 'approved';
        $is_rejected  = $sub['status'] === 'rejected';
        $is_graded    = $sub['status'] === 'graded';
        $card_class   = $needs_review ? 'needs-grade' : ($is_approved ? 'approved' : ($is_graded ? 'graded' : ($is_rejected ? 'rejected' : '')));
        $grade        = $sub['grade'];
        $grade_class  = '';
        if ($grade !== null)
          $grade_class = $grade >= 90 ? 'grade-a' : ($grade >= 75 ? 'grade-b' : ($grade >= 60 ? 'grade-c' : 'grade-f'));
        $is_overdue   = strtotime($sub['due_date']) < strtotime($sub['submission_date'] ?? 'now');
      ?>
      <div class="sub-card <?= $card_class ?>">
        <div class="sub-head">
          <div>
            <div class="sub-task">📋 <?= htmlspecialchars($sub['task_title']) ?></div>
            <div class="sub-student">👤 <?= htmlspecialchars($sub['student_name'] ?: $sub['username']) ?> &bull; <?= htmlspecialchars($sub['email']) ?></div>
            <div class="sub-meta">
              Subject: <?= htmlspecialchars($sub['subject'] ?? '—') ?> &bull;
              Due: <?= date('M d, Y', strtotime($sub['due_date'])) ?>
              <?php if ($sub['submission_date']): ?>
              &bull; Submitted: <?= date('M d, Y H:i', strtotime($sub['submission_date'])) ?>
              <?php if ($is_overdue): ?><span style="color:var(--red);font-weight:600;"> (Late)</span><?php endif; ?>
              <?php endif; ?>
            </div>
          </div>
          <div style="display:flex;flex-direction:column;align-items:flex-end;gap:.4rem;">
            <?php if ($needs_review): ?>
              <span class="badge badge-submitted">⏳ Needs Review</span>
            <?php elseif ($is_approved): ?>
              <span class="badge" style="background:#cce5ff;color:#004085;">✅ Approved</span>
            <?php elseif ($is_rejected): ?>
              <span class="badge" style="background:#f8d7da;color:#721c24;">❌ Rejected</span>
            <?php elseif ($is_graded): ?>
              <span class="badge badge-graded">🎓 Graded</span>
            <?php else: ?>
              <span class="badge badge-pending">Pending</span>
            <?php endif; ?>
            <?php if ($grade !== null): ?>
              <span class="grade-display <?= $grade_class ?>"><?= $grade ?>/100</span>
            <?php endif; ?>
          </div>
        </div>

        <div class="sub-body">
          <?php if (!empty($sub['notes'])): ?>
          <div class="notes-box">💬 <strong>Student note:</strong> <?= htmlspecialchars($sub['notes']) ?></div>
          <?php endif; ?>

          <?php if (!empty($sub['file_path'])): ?>
          <div style="margin-bottom:.8rem;">
            <a href="<?= htmlspecialchars($sub['file_path']) ?>" target="_blank" class="file-link">
              📎 View Submitted File
            </a>
          </div>
          <?php else: ?>
          <p style="font-size:.82rem;color:#7f8c8d;margin-bottom:.8rem;">No file attached — marked as complete.</p>
          <?php endif; ?>

          <?php if ($is_graded && !empty($sub['feedback'])): ?>
          <div class="feedback-box">✏️ <strong>Your feedback:</strong> <?= htmlspecialchars($sub['feedback']) ?></div>
          <?php endif; ?>

          <?php if ($is_rejected && !empty($sub['feedback'])): ?>
          <div style="background:#fff5f5;border-left:3px solid var(--red);padding:.6rem .8rem;border-radius:5px;font-size:.82rem;color:#721c24;margin-bottom:.8rem;">
            ❌ <strong>Rejection reason:</strong> <?= htmlspecialchars($sub['feedback']) ?>
          </div>
          <?php endif; ?>

      <?php if ($needs_review): ?>
          <!-- Approve / Reject buttons -->
          <div style="display:flex;gap:.6rem;flex-wrap:wrap;margin-bottom:.8rem;">
            <a href="grade.php?id=<?= $sub['id'] ?>" class="btn btn-success btn-sm">🔍 Grade</a>
            <form method="POST" style="display:inline-flex;">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
              <input type="hidden" name="action" value="approve_submission">
              <input type="hidden" name="submission_id" value="<?= $sub['id'] ?>">
              <button type="submit" class="btn btn-sm" style="background:#3498db;color:white;" onclick="return confirm('Approve this submission?')">✅ Approve</button>
            </form>
            <button class="btn btn-sm" style="background:var(--red);color:white;" onclick="showRejectForm(<?= $sub['id'] ?>)">❌ Reject</button>
          </div>
          <!-- Reject form (hidden by default) -->
          <div id="reject-form-<?= $sub['id'] ?>" style="display:none;background:#fff5f5;border-radius:6px;padding:.8rem;margin-bottom:.8rem;">
            <form method="POST">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
              <input type="hidden" name="action" value="reject_submission">
              <input type="hidden" name="submission_id" value="<?= $sub['id'] ?>">
              <div style="margin-bottom:.5rem;">
                <label style="font-size:.8rem;font-weight:700;color:var(--dark);">Reason for rejection (optional)</label>
                <input type="text" name="reject_reason" placeholder="e.g. Incomplete work, wrong format..." style="width:100%;padding:.5rem;border:2px solid #f8d7da;border-radius:5px;font-size:.85rem;margin-top:.3rem;font-family:inherit;">
              </div>
              <div style="display:flex;gap:.5rem;">
                <button type="submit" class="btn btn-sm" style="background:var(--red);color:white;">Confirm Reject</button>
                <button type="button" class="btn btn-sm" style="background:#ecf0f1;color:var(--dark);" onclick="hideRejectForm(<?= $sub['id'] ?>)">Cancel</button>
              </div>
            </form>
          </div>


          <?php elseif ($is_approved): ?>
          <!-- Approved — ready to grade -->
          <div style="background:#ebf5fb;border-left:3px solid #3498db;padding:.6rem .8rem;border-radius:5px;font-size:.82rem;color:#004085;margin-bottom:.8rem;">
            ✅ Submission approved. You can now grade it.
          </div>
          <div style="display:flex;gap:.6rem;flex-wrap:wrap;margin-bottom:.8rem;">
            <a href="grade.php?id=<?= $sub['id'] ?>" class="btn btn-success btn-sm">🎓 Grade Now</a>
          </div>
          <form method="POST" class="grade-form">
            <input type="hidden" name="action" value="grade_submission">
            <input type="hidden" name="submission_id" value="<?= $sub['id'] ?>">
            <div class="fg">
              <label>Grade (0–100) *</label>
              <input type="number" name="grade" min="0" max="100" placeholder="e.g. 85" required>
            </div>
            <div class="fg" style="flex:1;">
              <label>Feedback</label>
              <input type="text" name="feedback" placeholder="Great work! / Needs improvement...">
            </div>
            <button type="submit" class="btn btn-success btn-sm">✅ Submit Grade</button>
          </form>

          <?php elseif ($is_graded): ?>
          <form method="POST" class="grade-form" style="border-top:1px solid #ecf0f1;padding-top:.8rem;margin-top:.5rem;">
            <input type="hidden" name="action" value="grade_submission">
            <input type="hidden" name="submission_id" value="<?= $sub['id'] ?>">
            <div class="fg">
              <label>Update Grade</label>
              <input type="number" name="grade" min="0" max="100" value="<?= $grade ?>" required>
            </div>
            <div class="fg" style="flex:1;">
              <label>Update Feedback</label>
              <input type="text" name="feedback" value="<?= htmlspecialchars($sub['feedback'] ?? '') ?>">
            </div>
            <button type="submit" class="btn btn-primary btn-sm">Update</button>
          </form>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

</div>

<!-- APPROVE MODAL -->
<div class="modal" id="approveModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;overflow-y:auto;padding:1rem;">
  <div class="modal-box" style="background:white;border-radius:10px;width:100%;max-width:520px;margin:auto;">
    <div class="modal-header" style="padding:1.2rem 1.5rem;border-bottom:1px solid #ecf0f1;display:flex;justify-content:space-between;align-items:center;">
      <h2 style="color:var(--dark);font-size:1.1rem;margin:0;">✅ Approve Submission</h2>
      <button type="button" class="modal-close" onclick="closeApproveModal()" style="background:none;border:none;font-size:1.4rem;cursor:pointer;color:#7f8c8d;line-height:1;">×</button>
    </div>
    <div class="modal-body" style="padding:1.5rem;">
      <p style="margin-bottom:1rem;color:var(--text);font-weight:600;">Are you sure you want to approve this submission?</p>

form method="POST">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
        <input type="hidden" name="action" value="approve_submission">
        <input type="hidden" name="submission_id" id="approve_submission_id" value="">

        <div style="display:flex;gap:.8rem;">
          <button type="submit" class="btn btn-success" style="flex:1;justify-content:center;padding:.8rem;">✅ Confirm Approve</button>
          <button type="button" class="btn btn-secondary" style="flex:1;justify-content:center;padding:.8rem;" onclick="closeApproveModal()">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>

<style>
  /* Minimal modal styles (local to this page) */
  .modal.active{display:flex;}
</style>

<script>
function showRejectForm(id) {

  document.getElementById('reject-form-' + id).style.display = 'block';
}
function hideRejectForm(id) {
  document.getElementById('reject-form-' + id).style.display = 'none';
}

function openApproveModal(id) {
  document.getElementById('approve_submission_id').value = id;
  document.getElementById('approveModal').classList.add('active');
}
function closeApproveModal() {
  document.getElementById('approveModal').classList.remove('active');
  document.getElementById('approve_submission_id').value = '';
}
</script>
</body>
</html>
