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
$error   = '';

// ── Handle grade submit ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'grade_submission') {
    $submission_id = intval($_POST['submission_id']);
    $grade         = max(0, min(100, intval($_POST['grade'])));
    $feedback      = trim($_POST['feedback'] ?? '');

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
        header('Location: grade.php?id=' . $submission_id . '&saved=1');
        exit();
    } else {
        $error = 'Submission not found or access denied.';
    }
}

// ── Load submission ───────────────────────────────────────────────────────────
$submission_id = intval($_GET['id'] ?? 0);
if (!$submission_id) {
    header('Location: submissions.php');
    exit();
}

$stmt = $conn->prepare(
    "SELECT ts.*, t.title as task_title, t.subject, t.description as task_desc,
            t.due_date, t.teacher_id,
            u.full_name as student_name, u.username, u.email as student_email,
            u.profile_pic,
            grader.full_name as graded_by_name
     FROM task_submissions ts
     JOIN tasks t ON ts.task_id = t.id
     JOIN users u ON ts.student_id = u.id
     LEFT JOIN users grader ON ts.graded_by = grader.id
     WHERE ts.id = ? AND t.teacher_id = ?"
);
$stmt->bind_param('ii', $submission_id, $teacher_id);
$stmt->execute();
$sub = $stmt->get_result()->fetch_assoc();

if (!$sub) {
    header('Location: submissions.php');
    exit();
}

// ── Prev / Next ungraded navigation ──────────────────────────────────────────
$nav = $conn->prepare(
    "SELECT ts.id FROM task_submissions ts
     JOIN tasks t ON ts.task_id = t.id
     WHERE t.teacher_id = ? AND ts.status = 'submitted'
     ORDER BY ts.submission_date ASC"
);
$nav->bind_param('i', $teacher_id);
$nav->execute();
$ungraded_ids = array_column($nav->get_result()->fetch_all(MYSQLI_ASSOC), 'id');
$current_pos  = array_search($submission_id, $ungraded_ids);
$prev_id = ($current_pos !== false && $current_pos > 0) ? $ungraded_ids[$current_pos - 1] : null;
$next_id = ($current_pos !== false && $current_pos < count($ungraded_ids) - 1) ? $ungraded_ids[$current_pos + 1] : null;

// Grade helpers
$grade       = $sub['grade'];
$grade_label = '';
$grade_class = '';
if ($grade !== null) {
    if ($grade >= 90)      { $grade_label = 'Excellent'; $grade_class = 'grade-a'; }
    elseif ($grade >= 75)  { $grade_label = 'Good';      $grade_class = 'grade-b'; }
    elseif ($grade >= 60)  { $grade_label = 'Passing';   $grade_class = 'grade-c'; }
    else                   { $grade_label = 'Failing';   $grade_class = 'grade-f'; }
}

$is_late = $sub['submission_date'] && strtotime($sub['submission_date']) > strtotime($sub['due_date']);

// File extension for preview
$file_ext = $sub['file_path'] ? strtolower(pathinfo($sub['file_path'], PATHINFO_EXTENSION)) : '';
$is_image  = in_array($file_ext, ['jpg','jpeg','png','gif','webp']);
$is_pdf    = $file_ext === 'pdf';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Grade Submission - Smart Study Planner</title>
<style>
*{margin:0;padding:0;box-sizing:border-box;}
:root{--primary:#3498db;--purple:#9b59b6;--green:#2ecc71;--red:#e74c3c;--orange:#f39c12;--dark:#2c3e50;--text:#34495e;}
body{font-family:'Segoe UI',sans-serif;background:#f5f7fa;color:var(--text);}
.navbar{background:linear-gradient(135deg,var(--primary) 0%,var(--purple) 100%);color:white;padding:1rem 2rem;display:flex;justify-content:space-between;align-items:center;box-shadow:0 2px 10px rgba(0,0,0,.1);}
.navbar-brand{font-size:1.3rem;font-weight:bold;}
.navbar-menu{display:flex;gap:1.5rem;align-items:center;}
.navbar-menu a{color:white;text-decoration:none;font-size:.95rem;transition:.3s;}
.navbar-menu a:hover{opacity:.8;}
.container{max-width:1100px;margin:2rem auto;padding:0 1rem;}
.top-bar{display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;flex-wrap:wrap;gap:.8rem;}
.top-bar h1{color:var(--dark);font-size:1.3rem;}
.nav-btns{display:flex;gap:.5rem;}
.btn{padding:.5rem 1rem;border:none;border-radius:6px;cursor:pointer;font-size:.85rem;font-weight:600;transition:all .2s;text-decoration:none;display:inline-flex;align-items:center;gap:.4rem;}
.btn-primary{background:var(--primary);color:white;}
.btn-primary:hover{background:#2980b9;transform:translateY(-1px);}
.btn-success{background:var(--green);color:white;}
.btn-success:hover{background:#27ae60;transform:translateY(-1px);}
.btn-secondary{background:#ecf0f1;color:var(--dark);}
.btn-secondary:hover{background:#d5dbdb;}
.btn-danger{background:var(--red);color:white;}
.btn-sm{padding:.35rem .7rem;font-size:.78rem;}
.alert{padding:.9rem 1rem;border-radius:6px;margin-bottom:1.2rem;font-size:.9rem;}
.alert-success{background:#d4edda;color:#155724;border-left:4px solid var(--green);}
.alert-error{background:#f8d7da;color:#721c24;border-left:4px solid var(--red);}
.layout{display:grid;grid-template-columns:1fr 380px;gap:1.5rem;align-items:start;}
.card{background:white;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,.05);overflow:hidden;}
.card-header{padding:1rem 1.5rem;border-bottom:1px solid #ecf0f1;display:flex;justify-content:space-between;align-items:center;}
.card-header h2{color:var(--dark);font-size:1rem;font-weight:700;}
.card-body{padding:1.5rem;}
.student-info{display:flex;align-items:center;gap:1rem;margin-bottom:1.2rem;padding-bottom:1.2rem;border-bottom:1px solid #ecf0f1;}
.avatar{width:52px;height:52px;border-radius:50%;background:linear-gradient(135deg,var(--primary),var(--purple));display:flex;align-items:center;justify-content:center;color:white;font-size:1.3rem;font-weight:700;flex-shrink:0;overflow:hidden;}
.avatar img{width:100%;height:100%;object-fit:cover;}
.student-name{font-size:1.1rem;font-weight:700;color:var(--dark);}
.student-email{font-size:.82rem;color:#7f8c8d;margin-top:.2rem;}
.info-row{display:flex;justify-content:space-between;padding:.6rem 0;border-bottom:1px solid #f8f9fa;font-size:.88rem;}
.info-row:last-child{border-bottom:none;}
.info-label{color:#7f8c8d;font-weight:600;}
.info-value{color:var(--dark);font-weight:500;text-align:right;}
.badge{display:inline-block;padding:.25rem .7rem;border-radius:20px;font-size:.75rem;font-weight:700;}
.badge-submitted{background:#fff3cd;color:#856404;}
.badge-graded{background:#d4edda;color:#155724;}
.badge-pending{background:#ecf0f1;color:#7f8c8d;}
.badge-late{background:#f8d7da;color:#721c24;}
.notes-box{background:#fffbeb;border-left:4px solid var(--orange);padding:.9rem 1rem;border-radius:6px;font-size:.88rem;color:#555;margin-bottom:1rem;}
.file-preview{border:2px solid #ecf0f1;border-radius:8px;overflow:hidden;margin-bottom:1rem;}
.file-preview img{width:100%;max-height:400px;object-fit:contain;background:#f8f9fa;display:block;}
.file-preview iframe{width:100%;height:400px;border:none;display:block;}
.file-preview .file-icon{padding:2rem;text-align:center;background:#f8f9fa;}
.file-icon-img{font-size:3rem;margin-bottom:.5rem;}
.file-name{font-size:.85rem;color:#7f8c8d;margin-top:.3rem;}
.file-actions{padding:.8rem 1rem;background:#f8f9fa;border-top:1px solid #ecf0f1;display:flex;gap:.5rem;}
.task-desc{background:#f8f9fa;border-radius:6px;padding:.9rem 1rem;font-size:.88rem;color:#555;line-height:1.6;margin-bottom:1rem;}
.grade-form{display:grid;gap:1rem;}
.form-group{display:flex;flex-direction:column;gap:.4rem;}
.form-group label{font-size:.85rem;font-weight:700;color:var(--dark);}
.form-group input,.form-group textarea,.form-group select{padding:.7rem;border:2px solid #ecf0f1;border-radius:6px;font-size:.95rem;font-family:inherit;transition:border-color .3s;}
.form-group input:focus,.form-group textarea:focus,.form-group select:focus{outline:none;border-color:var(--primary);}
.grade-input-wrap{position:relative;}
.grade-input-wrap input{padding-right:3rem;font-size:1.3rem;font-weight:700;text-align:center;}
.grade-suffix{position:absolute;right:.8rem;top:50%;transform:translateY(-50%);color:#7f8c8d;font-size:.9rem;font-weight:600;}
.grade-preview{text-align:center;padding:1rem;border-radius:8px;margin-top:.5rem;transition:all .3s;}
.grade-preview .score{font-size:2.5rem;font-weight:900;}
.grade-preview .label{font-size:.85rem;font-weight:600;margin-top:.3rem;}
.grade-a{background:#d4edda;color:#155724;}
.grade-b{background:#cce5ff;color:#004085;}
.grade-c{background:#fff3cd;color:#856404;}
.grade-f{background:#f8d7da;color:#721c24;}
.grade-none{background:#f8f9fa;color:#7f8c8d;}
.quick-grades{display:flex;gap:.4rem;flex-wrap:wrap;margin-top:.4rem;}
.qg{padding:.3rem .7rem;border:2px solid #ecf0f1;border-radius:20px;font-size:.78rem;font-weight:700;cursor:pointer;transition:all .2s;background:white;}
.qg:hover{border-color:var(--primary);color:var(--primary);}
.current-grade-box{background:linear-gradient(135deg,#f0fdf4,#dcfce7);border:2px solid var(--green);border-radius:10px;padding:1.2rem;text-align:center;margin-bottom:1rem;}
.current-grade-box .cg-score{font-size:2.2rem;font-weight:900;color:#155724;}
.current-grade-box .cg-label{font-size:.82rem;color:#166534;margin-top:.3rem;}
.current-grade-box .cg-feedback{font-size:.85rem;color:#166534;margin-top:.5rem;font-style:italic;}
.ungraded-notice{background:#fff7ed;border:2px solid var(--orange);border-radius:10px;padding:1rem;text-align:center;margin-bottom:1rem;}
.ungraded-notice p{color:#9a3412;font-size:.88rem;font-weight:600;}
@media(max-width:900px){.layout{grid-template-columns:1fr;}}
</style>
</head>
<body>
<nav class="navbar">
  <div class="navbar-brand">📚 Smart Study Planner - Teacher</div>
  <div class="navbar-menu">
    <a href="dashboard.php">Dashboard</a>
    <a href="tasks.php">Manage Tasks</a>
    <a href="submissions.php">Submissions</a>
    <a href="monitor.php">Monitor Students</a>
    <a href="../profile.php">Profile</a>
    <a href="../logout.php">Logout</a>
  </div>
</nav>

<div class="container">

  <div class="top-bar">
    <div>
      <h1>✏️ Grade Submission</h1>
      <p style="color:#7f8c8d;font-size:.88rem;margin-top:.2rem;">
        <?= htmlspecialchars($sub['task_title']) ?> &bull;
        <?= htmlspecialchars($sub['student_name'] ?: $sub['username']) ?>
      </p>
    </div>
    <div class="nav-btns">
      <?php if ($prev_id): ?>
      <a href="grade.php?id=<?= $prev_id ?>" class="btn btn-secondary btn-sm">← Prev Ungraded</a>
      <?php endif; ?>
      <?php if ($next_id): ?>
      <a href="grade.php?id=<?= $next_id ?>" class="btn btn-secondary btn-sm">Next Ungraded →</a>
      <?php endif; ?>
      <a href="submissions.php" class="btn btn-secondary btn-sm">← All Submissions</a>
    </div>
  </div>

  <?php if (isset($_GET['saved'])): ?>
  <div class="alert alert-success">✅ Grade saved successfully! Student has been notified.</div>
  <?php endif; ?>
  <?php if ($error): ?>
  <div class="alert alert-error">⚠️ <?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <div class="layout">

    <!-- LEFT: Submission Details -->
    <div style="display:grid;gap:1.5rem;">

      <!-- Student Info -->
      <div class="card">
        <div class="card-header"><h2>👤 Student</h2></div>
        <div class="card-body">
          <div class="student-info">
            <div class="avatar">
              <?php if (!empty($sub['profile_pic'])): ?>
              <img src="<?= htmlspecialchars($sub['profile_pic']) ?>" alt="">
              <?php else: ?>
              <?= strtoupper(substr($sub['student_name'] ?: $sub['username'], 0, 1)) ?>
              <?php endif; ?>
            </div>
            <div>
              <div class="student-name"><?= htmlspecialchars($sub['student_name'] ?: $sub['username']) ?></div>
              <div class="student-email"><?= htmlspecialchars($sub['student_email']) ?></div>
            </div>
          </div>
          <div class="info-row">
            <span class="info-label">Task</span>
            <span class="info-value"><?= htmlspecialchars($sub['task_title']) ?></span>
          </div>
          <div class="info-row">
            <span class="info-label">Subject</span>
            <span class="info-value"><?= htmlspecialchars($sub['subject'] ?? '—') ?></span>
          </div>
          <div class="info-row">
            <span class="info-label">Due Date</span>
            <span class="info-value"><?= date('M d, Y H:i', strtotime($sub['due_date'])) ?></span>
          </div>
          <div class="info-row">
            <span class="info-label">Submitted</span>
            <span class="info-value">
              <?= $sub['submission_date'] ? date('M d, Y H:i', strtotime($sub['submission_date'])) : '—' ?>
              <?php if ($is_late): ?><span class="badge badge-late" style="margin-left:.4rem;">Late</span><?php endif; ?>
            </span>
          </div>
          <div class="info-row">
            <span class="info-label">Status</span>
            <span class="info-value">
              <span class="badge badge-<?= $sub['status'] ?>"><?= ucfirst($sub['status']) ?></span>
            </span>
          </div>
          <?php if ($sub['graded_at']): ?>
          <div class="info-row">
            <span class="info-label">Graded At</span>
            <span class="info-value"><?= date('M d, Y H:i', strtotime($sub['graded_at'])) ?></span>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Task Description -->
      <?php if (!empty($sub['task_desc'])): ?>
      <div class="card">
        <div class="card-header"><h2>📋 Task Instructions</h2></div>
        <div class="card-body">
          <div class="task-desc"><?= nl2br(htmlspecialchars($sub['task_desc'])) ?></div>
        </div>
      </div>
      <?php endif; ?>

      <!-- Student Notes -->
      <?php if (!empty($sub['notes'])): ?>
      <div class="card">
        <div class="card-header"><h2>💬 Student Notes</h2></div>
        <div class="card-body">
          <div class="notes-box"><?= nl2br(htmlspecialchars($sub['notes'])) ?></div>
        </div>
      </div>
      <?php endif; ?>

      <!-- Submitted File -->
      <div class="card">
        <div class="card-header"><h2>📎 Submitted File</h2></div>
        <div class="card-body" style="padding:0;">
          <?php if (!empty($sub['file_path'])): ?>
          <div class="file-preview">
            <?php if ($is_image): ?>
            <img src="<?= htmlspecialchars($sub['file_path']) ?>" alt="Submission">
            <?php elseif ($is_pdf): ?>
            <iframe src="<?= htmlspecialchars($sub['file_path']) ?>"></iframe>
            <?php else: ?>
            <div class="file-icon">
              <div class="file-icon-img">
                <?php
                $icons = ['doc'=>'📝','docx'=>'📝','xls'=>'📊','xlsx'=>'📊','ppt'=>'📊','pptx'=>'📊','txt'=>'📄','zip'=>'🗜️'];
                echo $icons[$file_ext] ?? '📁';
                ?>
              </div>
              <div class="file-name"><?= htmlspecialchars(basename($sub['file_path'])) ?></div>
            </div>
            <?php endif; ?>
            <div class="file-actions">
              <a href="<?= htmlspecialchars($sub['file_path']) ?>" target="_blank" class="btn btn-primary btn-sm">📥 Open / Download</a>
              <a href="<?= htmlspecialchars($sub['file_path']) ?>" download class="btn btn-secondary btn-sm">⬇ Download</a>
            </div>
          </div>
          <?php else: ?>
          <div style="padding:1.5rem;text-align:center;color:#7f8c8d;">
            <p style="font-size:1.5rem;margin-bottom:.5rem;">📭</p>
            <p>No file submitted — student marked as complete.</p>
          </div>
          <?php endif; ?>
        </div>
      </div>

    </div>

    <!-- RIGHT: Grading Panel -->
    <div style="display:grid;gap:1.5rem;">

      <!-- Current Grade (if already graded) -->
      <?php if ($sub['status'] === 'graded' && $grade !== null): ?>
      <div class="card">
        <div class="card-header"><h2>✅ Current Grade</h2></div>
        <div class="card-body">
          <div class="current-grade-box">
            <div class="cg-score"><?= $grade ?><span style="font-size:1rem;font-weight:400;">/100</span></div>
            <div class="cg-label"><?= $grade_label ?></div>
            <?php if (!empty($sub['feedback'])): ?>
            <div class="cg-feedback">"<?= htmlspecialchars($sub['feedback']) ?>"</div>
            <?php endif; ?>
          </div>
          <p style="font-size:.8rem;color:#7f8c8d;text-align:center;">You can update the grade below.</p>
        </div>
      </div>
      <?php else: ?>
      <div class="card">
        <div class="card-header"><h2>⏳ Awaiting Grade</h2></div>
        <div class="card-body">
          <div class="ungraded-notice">
            <p>This submission has not been graded yet.</p>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <!-- Grade Form -->
      <div class="card">
        <div class="card-header">
          <h2><?= $sub['status'] === 'graded' ? '✏️ Update Grade' : '🎯 Submit Grade' ?></h2>
        </div>
        <div class="card-body">
          <form method="POST" class="grade-form" id="gradeForm">
            <input type="hidden" name="action" value="grade_submission">
            <input type="hidden" name="submission_id" value="<?= $submission_id ?>">

            <div class="form-group">
              <label>Grade (0 – 100)</label>
              <div class="grade-input-wrap">
                <input type="number" name="grade" id="gradeInput"
                       min="0" max="100" required
                       value="<?= $grade ?? '' ?>"
                       placeholder="Enter score"
                       oninput="updatePreview(this.value)">
                <span class="grade-suffix">/ 100</span>
              </div>
              <!-- Quick grade buttons -->
              <div class="quick-grades">
                <span style="font-size:.75rem;color:#7f8c8d;align-self:center;">Quick:</span>
                <?php foreach ([100,95,90,85,80,75,70,65,60,50,0] as $qg): ?>
                <span class="qg" onclick="setGrade(<?= $qg ?>)"><?= $qg ?></span>
                <?php endforeach; ?>
              </div>
            </div>

            <!-- Live grade preview -->
            <div class="grade-preview grade-none" id="gradePreview">
              <div class="score" id="previewScore"><?= $grade !== null ? $grade : '—' ?></div>
              <div class="label" id="previewLabel"><?= $grade_label ?: 'Enter a score above' ?></div>
            </div>

            <div class="form-group">
              <label>Feedback to Student</label>
              <textarea name="feedback" rows="4"
                        placeholder="e.g. Great work on the analysis! Consider improving the conclusion..."><?= htmlspecialchars($sub['feedback'] ?? '') ?></textarea>
            </div>

            <button type="submit" class="btn btn-success" style="width:100%;padding:.9rem;font-size:1rem;justify-content:center;">
              <?= $sub['status'] === 'graded' ? '✏️ Update Grade' : '✅ Submit Grade' ?>
            </button>

            <?php if ($next_id): ?>
            <a href="grade.php?id=<?= $next_id ?>" class="btn btn-secondary" style="width:100%;justify-content:center;margin-top:.3rem;">
              Skip → Next Ungraded
            </a>
            <?php endif; ?>
          </form>
        </div>
      </div>

      <!-- Ungraded queue count -->
      <?php if (count($ungraded_ids) > 0): ?>
      <div style="background:white;border-radius:10px;padding:1rem 1.5rem;box-shadow:0 2px 10px rgba(0,0,0,.05);text-align:center;">
        <p style="font-size:.85rem;color:#7f8c8d;">
          <strong style="color:var(--orange);"><?= count($ungraded_ids) ?></strong> submission<?= count($ungraded_ids) !== 1 ? 's' : '' ?> still need grading.
          <?php if ($next_id): ?>
          <a href="grade.php?id=<?= $next_id ?>" style="color:var(--primary);font-weight:600;">Grade next →</a>
          <?php endif; ?>
        </p>
      </div>
      <?php endif; ?>

    </div>
  </div>
</div>

<script>
const labels = {
  range: [[90,100,'Excellent','grade-a'],[75,89,'Good','grade-b'],[60,74,'Passing','grade-c'],[0,59,'Failing','grade-f']]
};

function getGradeInfo(v) {
  if (v === '' || v === null || isNaN(v)) return {label:'Enter a score above', cls:'grade-none'};
  v = parseInt(v);
  for (const [min,max,label,cls] of labels.range) {
    if (v >= min && v <= max) return {label, cls};
  }
  return {label:'Invalid', cls:'grade-none'};
}

function updatePreview(val) {
  const info = getGradeInfo(val);
  const preview = document.getElementById('gradePreview');
  const score   = document.getElementById('previewScore');
  const lbl     = document.getElementById('previewLabel');
  preview.className = 'grade-preview ' + info.cls;
  score.textContent = val !== '' ? val + '/100' : '—';
  lbl.textContent   = info.label;
}

function setGrade(v) {
  document.getElementById('gradeInput').value = v;
  updatePreview(v);
}

// Init preview on load
updatePreview(document.getElementById('gradeInput').value);
</script>
</body>
</html>
