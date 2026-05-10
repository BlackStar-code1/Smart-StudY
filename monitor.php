<?php
session_start();
include 'db.php';

if ($_SESSION['role'] != 'teacher') die("Access denied");

$sql = "SELECT users.username, tasks.subject, submissions.status, submissions.submitted_at
        FROM submissions
        JOIN users ON submissions.student_id = users.id
        JOIN tasks ON submissions.task_id = tasks.id";

$result = $conn->query($sql);

echo "<h2>Student Progress</h2>";

while ($row = $result->fetch_assoc()) {
    echo "{$row['username']} - {$row['subject']} - {$row['status']} - {$row['submitted_at']}<br>";
}
?>