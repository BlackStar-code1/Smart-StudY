<?php
require_once 'db_config.php';

// Create Task
function createTask($teacher_id, $title, $description, $subject, $due_date, $section_id = null, $year = null) {
    global $conn;
    
    $query = "INSERT INTO tasks (teacher_id, title, description, subject, due_date, section_id, `year`) 
              VALUES (?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("issssis", $teacher_id, $title, $description, $subject, $due_date, $section_id, $year);
    
    if ($stmt->execute()) {
        return ['success' => true, 'task_id' => $conn->insert_id];
    }
    
    return ['success' => false];
}

function assignTaskToSection($task_id, $class_id) {
    global $conn;

    $student_query = "SELECT cs.student_id FROM class_students cs JOIN users u ON cs.student_id = u.id WHERE cs.class_id = ? AND u.status = 'active'";
    $stmt = $conn->prepare($student_query);
    $stmt->bind_param('i', $class_id);
    $stmt->execute();
    $students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    $assigned_ids = [];
    foreach ($students as $student) {
        $insert = $conn->prepare("INSERT IGNORE INTO task_submissions (task_id, student_id, status) VALUES (?, ?, 'pending')");
        $insert->bind_param('ii', $task_id, $student['student_id']);
        $insert->execute();
        if ($insert->affected_rows > 0) {
            $assigned_ids[] = $student['student_id'];
        }
    }

    return $assigned_ids;
}

// Add file to task
function addTaskFile($task_id, $file_name, $file_path, $file_size) {
    global $conn;
    
    $query = "INSERT INTO task_files (task_id, file_name, file_path, file_size) 
              VALUES (?, ?, ?, ?)";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("issi", $task_id, $file_name, $file_path, $file_size);
    
    return $stmt->execute();
}

// Get all tasks for a teacher
function getTeacherTasks($teacher_id) {
    global $conn;
    
    $query = "SELECT t.*, cg.class_name AS section_name FROM tasks t 
              LEFT JOIN class_groups cg ON t.section_id = cg.id 
              WHERE t.teacher_id = ? ORDER BY t.due_date DESC";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $teacher_id);
    $stmt->execute();
    
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Get task by ID with files
function getTaskById($task_id) {
    global $conn;
    
    $query = "SELECT t.*, u.full_name as teacher_name, cg.class_name AS section_name 
              FROM tasks t 
              LEFT JOIN users u ON t.teacher_id = u.id 
              LEFT JOIN class_groups cg ON t.section_id = cg.id 
              WHERE t.id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $task_id);
    $stmt->execute();
    $task = $stmt->get_result()->fetch_assoc();
    
    if ($task) {
        // Get files
        $file_query = "SELECT * FROM task_files WHERE task_id = ?";
        $file_stmt = $conn->prepare($file_query);
        $file_stmt->bind_param("i", $task_id);
        $file_stmt->execute();
        $task['files'] = $file_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    
    return $task;
}

// Get all available tasks for a student
function getStudentAvailableTasks($student_id) {
    global $conn;
    
    $query = "SELECT t.*, u.full_name as teacher_name 
              FROM tasks t 
              LEFT JOIN users u ON t.teacher_id = u.id 
              WHERE t.section_id IS NULL 
                 OR t.id IN (SELECT task_id FROM task_submissions WHERE student_id = ?) 
              ORDER BY t.due_date ASC";
    $stmt = $conn->prepare($query);
    $stmt->bind_param('i', $student_id);
    $stmt->execute();
    
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Get student submitted tasks
function getStudentSubmissions($student_id) {
    global $conn;
    
    $query = "SELECT ts.*, t.title, t.due_date, u.full_name as teacher_name
              FROM task_submissions ts
              JOIN tasks t ON ts.task_id = t.id
              LEFT JOIN users u ON t.teacher_id = u.id
              WHERE ts.student_id = ?
              ORDER BY ts.submission_date DESC";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $student_id);
    $stmt->execute();
    
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Submit task
function submitTask($task_id, $student_id, $file_path, $notes) {
    global $conn;

    // Check if already submitted
    $check_query = "SELECT id FROM task_submissions WHERE task_id = ? AND student_id = ?";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bind_param("ii", $task_id, $student_id);
    $check_stmt->execute();

    if ($check_stmt->get_result()->num_rows > 0) {
        return ['success' => false, 'message' => 'Already submitted'];
    }

    $query = "INSERT INTO task_submissions (task_id, student_id, file_path, notes, status) 
              VALUES (?, ?, ?, ?, 'submitted')";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("iiss", $task_id, $student_id, $file_path, $notes);

    if ($stmt->execute()) {
        $submission_id = $conn->insert_id;

        // Notify the teacher about the new submission
        // (Used by teacher/submissions.php to show pending items)
        $teacherStmt = $conn->prepare(
            "SELECT teacher_id, title FROM tasks WHERE id = ?"
        );
        $teacherStmt->bind_param('i', $task_id);
        $teacherStmt->execute();
        $taskRow = $teacherStmt->get_result()->fetch_assoc();

        if (!empty($taskRow['teacher_id'])) {
            $teacher_id = (int)$taskRow['teacher_id'];

            // Ensure notifications helper exists (notifications.php defines createNotification)
            // submitTask.php already includes config/tasks.php, so this should be safe.
            require_once __DIR__ . '/notifications.php';

            $studentNameStmt = $conn->prepare("SELECT full_name FROM users WHERE id = ?");
            $studentNameStmt->bind_param('i', $student_id);
            $studentNameStmt->execute();
            $studentRow = $studentNameStmt->get_result()->fetch_assoc();
            $studentName = trim($studentRow['full_name'] ?? '') ?: 'a student';

            createNotification(
                $teacher_id,
                'New Submission',
                'A student has turned in "' . ($taskRow['title'] ?? 'task') . '". (' . $studentName . ')',
                'submission',
                $submission_id
            );
        }

        return ['success' => true, 'submission_id' => $submission_id];
    }

    return ['success' => false];
}

// Get task submissions for teacher
function getTaskSubmissions($task_id) {
    global $conn;
    
    $query = "SELECT ts.*, u.full_name, u.profile_pic
              FROM task_submissions ts
              JOIN users u ON ts.student_id = u.id
              WHERE ts.task_id = ?
              ORDER BY ts.submission_date DESC";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $task_id);
    $stmt->execute();
    
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Grade submission
function gradeSubmission($submission_id, $grade, $feedback, $graded_by) {
    global $conn;

    // Normalize grade to 0-100 integer (legacy UI uses 0-100)
    $grade = max(0, min(100, (int)$grade));
    $feedback = (string)$feedback;
    $submission_id = (int)$submission_id;
    $graded_by = (int)$graded_by;

    // --- 1) Write enhanced grading tables (detailed_grades + grade_history) ---
    // Enhanced schema uses: detailed_grades(score, max_score, percentage, grade_letter, grade_points)
    // For backward compatibility, we map the legacy single score to a single detailed_grade row.

    // Determine grade letter + points from grading_scale_ranges
    $scaleStmt = $conn->prepare(
        "SELECT gsr.grade_letter, gsr.grade_points
         FROM grading_scale_ranges gsr
         ORDER BY gsr.min_percentage DESC
         WHERE ? BETWEEN gsr.min_percentage AND gsr.max_percentage
         LIMIT 1"
    );

    $percentage = (float)$grade; // since legacy grade is 0-100
    $letter = null;
    $points = null;

    if ($scaleStmt) {
        $scaleStmt->bind_param('d', $percentage);
        $scaleStmt->execute();
        $row = $scaleStmt->get_result()->fetch_assoc();
        if ($row) {
            $letter = $row['grade_letter'];
            $points = $row['grade_points'];
        }
    }

    if ($letter === null) {
        // Fallback to the same thresholds used in UI
        if ($grade >= 90) { $letter = 'A'; $points = 4.0; }
        elseif ($grade >= 75) { $letter = 'B'; $points = 3.0; }
        elseif ($grade >= 60) { $letter = 'C'; $points = 2.0; }
        else { $letter = 'F'; $points = 0.0; }
    }

    // Grab old values for grade_history (from task_submissions)
    $oldStmt = $conn->prepare("SELECT grade, feedback FROM task_submissions WHERE id = ?");
    $oldStmt->bind_param('i', $submission_id);
    $oldStmt->execute();
    $oldRow = $oldStmt->get_result()->fetch_assoc();

    $oldScore = $oldRow && $oldRow['grade'] !== null ? (float)$oldRow['grade'] : null;
    $oldFeedback = $oldRow ? (string)($oldRow['feedback'] ?? '') : '';

    // Upsert detailed_grades: easiest safe approach = delete then insert per submission_id.
    // (Schema does not define a unique constraint on submission_id.)
    $conn->begin_transaction();
    try {
        $conn->query("DELETE FROM detailed_grades WHERE submission_id = " . (int)$submission_id);

        $ins = $conn->prepare(
            "INSERT INTO detailed_grades
                (submission_id, category_id, score, max_score, percentage, grade_letter, grade_points, weight, comments, graded_by)
             VALUES
                (?, NULL, ?, 100.00, ?, ?, ?, 1.00, ?, ?)"
        );

        // submission_id(int), score(int), percentage(double), grade_letter(string), grade_points(double), comments(string), graded_by(int)
        $ins->bind_param(
            'iddsdsi',
            $submission_id,
            $grade,
            $percentage,
            $letter,
            $points,
            $feedback,
            $graded_by
        );
        $ins->execute();

        // Write grade_history if old values exist (or if feedback/score changed)
        if ($oldScore !== null || $oldFeedback !== $feedback) {
            $hist = $conn->prepare(
                "INSERT INTO grade_history
                    (submission_id, old_score, new_score, old_feedback, new_feedback, changed_by, change_reason)
                 VALUES
                    (?, ?, ?, ?, ?, ?, ? )"
            );

            $newScore = (float)$grade;
            $changeReason = 'Updated grade';

            $hist->bind_param(
                'idddsss',
                $submission_id,
                $oldScore,
                $newScore,
                $oldFeedback,
                $feedback,
                $graded_by,
                $changeReason
            );
            $hist->execute();
        }

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        // If enhanced tables fail, we still update legacy task_submissions.
        // Keep error hidden to avoid breaking teacher flow.
    }

    // --- 2) Keep legacy task_submissions in sync for existing UI ---
    $query = "UPDATE task_submissions 
              SET grade = ?, feedback = ?, graded_by = ?, status = 'graded', graded_at = NOW()
              WHERE id = ?";

    $stmt = $conn->prepare($query);
    $stmt->bind_param("isii", $grade, $feedback, $graded_by, $submission_id);
    return $stmt->execute();
}

// Update task
function updateTask($task_id, $title, $description, $subject, $due_date, $section_id = null, $year = null) {
    global $conn;
    
    $query = "UPDATE tasks SET title = ?, description = ?, subject = ?, due_date = ?, section_id = ?, `year` = ? WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ssssisi", $title, $description, $subject, $due_date, $section_id, $year, $task_id);
    
    return $stmt->execute();
}

// Delete task
function deleteTask($task_id) {
    global $conn;
    
    $query = "DELETE FROM tasks WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $task_id);
    
    return $stmt->execute();
}

// Get task statistics
function getTaskStatistics($task_id) {
    global $conn;
    
    $query = "SELECT 
              COUNT(*) as total_submissions,
              SUM(CASE WHEN status = 'submitted' THEN 1 ELSE 0 END) as pending_grading,
              SUM(CASE WHEN status = 'graded' THEN 1 ELSE 0 END) as graded,
              AVG(CASE WHEN grade IS NOT NULL THEN grade ELSE NULL END) as average_grade
              FROM task_submissions
              WHERE task_id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $task_id);
    $stmt->execute();
    
    return $stmt->get_result()->fetch_assoc();
}

// Get submission by ID
function getSubmissionById($submission_id) {
    global $conn;
    
    $query = "SELECT ts.*, t.title, t.due_date, u.full_name, u.email
              FROM task_submissions ts
              JOIN tasks t ON ts.task_id = t.id
              JOIN users u ON ts.student_id = u.id
              WHERE ts.id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $submission_id);
    $stmt->execute();
    
    return $stmt->get_result()->fetch_assoc();
}
?>
