<?php
session_start();
require_once 'config/db_config.php';
require_once 'config/auth.php';
require_once 'config/tasks.php';

if (!checkUserRole('student')) {
    header('Location: login.php');
    exit();
}

$student_id = $_SESSION['user_id'];
$task_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($task_id <= 0) {
    header('Location: student/tasks.php');
    exit();
}

// Fetch basic task info for display/validation
$taskStmt = $conn->prepare("SELECT id, teacher_id, title, due_date FROM tasks WHERE id = ?");
$taskStmt->bind_param('i', $task_id);
$taskStmt->execute();
$task = $taskStmt->get_result()->fetch_assoc();

if (!$task) {
    header('Location: student/tasks.php');
    exit();
}

$success = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $notes = trim($_POST['notes'] ?? '');

    if (!isset($_FILES['submission_file']) || $_FILES['submission_file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Please attach a file to submit.';
    } else {
        $upload = $_FILES['submission_file'];

        $tmpName = $upload['tmp_name'];
        $originalName = $upload['name'] ?? 'submission';
        $size = (int)($upload['size'] ?? 0);

        // Basic validation
        $allowedExt = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'png', 'jpg', 'jpeg', 'txt'];
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($ext === '' || !in_array($ext, $allowedExt, true)) {
            $error = 'Unsupported file type.';
        } elseif ($size <= 0) {
            $error = 'Uploaded file is empty.';
        } else {
            $uploadDir = __DIR__ . '/uploads/submissions/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            $safeBase = preg_replace('/[^a-zA-Z0-9_-]+/', '_', pathinfo($originalName, PATHINFO_FILENAME));
            $fileName = $student_id . '_' . $task_id . '_' . time() . '_' . $safeBase . ($ext ? ('.' . $ext) : '');
            $targetPath = $uploadDir . $fileName;

            if (!move_uploaded_file($tmpName, $targetPath)) {
                $error = 'Failed to save the uploaded file.';
            } else {
                // Store path relative to site root
                $file_path = 'uploads/submissions/' . $fileName;

                $res = submitTask($task_id, $student_id, $file_path, $notes);
                if (!empty($res['success'])) {
                    $success = true;
                } else {
                    $error = $res['message'] ?? 'Unable to submit. Please try again.';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submit Task - Smart Study Planner</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box;}
        :root{--primary:#3498db;--secondary:#2ecc71;--dark:#2c3e50;--light:#ecf0f1;--text:#34495e;--danger:#e74c3c;}
        body{font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;background:#f5f7fa;color:var(--text);}
        .navbar{background:linear-gradient(135deg,var(--primary) 0%,var(--secondary) 100%);color:#fff;padding:1rem 2rem;display:flex;justify-content:space-between;align-items:center;}
        .navbar a{color:#fff;text-decoration:none;margin-left:1rem;font-weight:600;font-size:.95rem;}
        .container{max-width:980px;margin:2rem auto;padding:0 1rem;}
        .card{background:#fff;border-radius:12px;box-shadow:0 2px 10px rgba(0,0,0,.05);padding:1.5rem;}
        .page-header{margin-bottom:1rem;}
        .page-header h1{color:var(--dark);font-size:1.3rem;margin-bottom:.4rem;}
        .badge{display:inline-block;padding:.2rem .6rem;border-radius:999px;font-size:.78rem;font-weight:700;}
        .badge-info{background:#ebf5fb;color:#004085;}
        .alert{padding:.9rem 1rem;border-radius:8px;margin-bottom:1rem;}
        .alert-success{background:#d4edda;color:#155724;border:1px solid #c3e6cb;}
        .alert-error{background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;}
        label{display:block;margin:.8rem 0 .35rem;font-weight:700;font-size:.9rem;}
        input[type=file], textarea{width:100%;padding:.7rem;border:2px solid var(--light);border-radius:10px;font-family:inherit;}
        textarea{min-height:110px;resize:vertical;}
        .btn{display:inline-flex;align-items:center;justify-content:center;gap:.4rem;padding:.75rem 1.1rem;border:none;border-radius:10px;cursor:pointer;font-weight:800;text-decoration:none;}
        .btn-primary{background:var(--primary);color:#fff;}
        .btn-primary:hover{background:#2980b9;}
        .btn-secondary{background:#ecf0f1;color:var(--dark);}
        .btn-secondary:hover{background:#d5dbdb;}
        .row{display:flex;gap:1rem;flex-wrap:wrap;}
        .col{flex:1;min-width:260px;}
        .meta{color:#7f8c8d;font-size:.92rem;margin-top:.5rem;}
        .hint{color:#7f8c8d;font-size:.82rem;margin-top:.4rem;}
    </style>
</head>
<body>
<nav class="navbar">
    <div style="font-weight:900;">📚 Smart Study Planner</div>
    <div>
        <a href="student/dashboard.php">Dashboard</a>
        <a href="student/tasks.php">Tasks</a>
        <a href="logout.php">Logout</a>
    </div>
</nav>

<div class="container">
    <div class="card">
        <div class="page-header">
            <h1>📤 Submit Task</h1>
            <div class="meta">
                <div><span class="badge badge-info">Task</span> <strong><?= htmlspecialchars($task['title']) ?></strong></div>
                <div>Due: <?= !empty($task['due_date']) ? htmlspecialchars($task['due_date']) : '—' ?></div>
            </div>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success">✅ Submission submitted successfully. Await teacher review.</div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="alert alert-error">❌ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data">
            <div class="row">
                <div class="col">
                    <label>Submission File *</label>
                    <input type="file" name="submission_file" accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.png,.jpg,.jpeg,.txt" required>
                    <div class="hint">Allowed: pdf, doc/docx, ppt/pptx, xls/xlsx, images, txt</div>
                </div>
                <div class="col">
                    <label>Notes (optional)</label>
                    <textarea name="notes" placeholder="Any notes for your teacher...">
</textarea>
                </div>
            </div>

            <div style="display:flex;gap:1rem;flex-wrap:wrap;margin-top:1rem;">
                <button type="submit" class="btn btn-primary">✅ Submit</button>
                <a href="view_task.php?id=<?= (int)$task_id ?>" class="btn btn-secondary">Back</a>
            </div>
        </form>
    </div>
</div>
</body>
</html>

