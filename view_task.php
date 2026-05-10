<?php include 'db.php'; ?>

<?php
$id = $_GET['id'];
$task = $conn->query("SELECT * FROM tasks WHERE id=$id")->fetch_assoc();
?>

<!DOCTYPE html>
<html>
<head>
    <title>View Task</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        body { background: #f4f7fb; }
        .card { border-radius: 15px; }
        .badge-priority {
            font-size: 14px;
        }
    </style>
</head>
<body>

<div class="container mt-5">
    <div class="col-md-8 mx-auto">

        <div class="card shadow p-4">

            <h3><?= $task['title'] ?></h3>

            <!-- Priority -->
            <?php if(isset($task['priority'])): ?>
                <span class="badge bg-warning badge-priority">
                    Priority: <?= $task['priority'] ?>
                </span>
            <?php endif; ?>

            <hr>

            <p><strong>Description:</strong></p>
            <p><?= $task['description'] ?></p>

            <p><strong>Due Date:</strong> <?= $task['due_date'] ?></p>

            <!-- Teacher File -->
            <?php if (!empty($task['teacher_file'])): ?>
                <p>
                    <strong>Attachment:</strong><br>
                    <a href="<?= $task['teacher_file'] ?>" target="_blank" 
                       class="btn btn-primary btn-sm">
                        📥 Download File
                    </a>
                </p>
            <?php endif; ?>

            <hr>

            <!-- Submission Status -->
            <p>
                <strong>Status:</strong> 
                <span class="badge bg-<?= $task['status']=='submitted' ? 'success' : 'secondary' ?>">
                    <?= strtoupper($task['status']) ?>
                </span>
            </p>

            <!-- Show submission if already submitted -->
            <?php if ($task['status'] == 'submitted'): ?>

                <div class="alert alert-success">
                    <strong>Your Submission:</strong><br>
                    <?= $task['submission_text'] ?>
                </div>

                <?php if (!empty($task['file_path'])): ?>
                    <a href="<?= $task['file_path'] ?>" target="_blank" class="btn btn-info btn-sm">
                        📎 View Submitted File
                    </a>
                <?php endif; ?>

            <?php else: ?>

                <!-- Submit Button -->
                <a href="submit_task.php?id=<?= $task['id'] ?>" 
                   class="btn btn-success mt-3">
                   📤 Submit Task
                </a>

            <?php endif; ?>

        </div>

    </div>
</div>

</body>
</html>