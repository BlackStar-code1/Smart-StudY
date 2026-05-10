<?php
// Enhanced Grading System Setup Script
// Run this script to set up the enhanced grading and student management database

require_once 'db_config.php';

echo "<h1>Smart Study Planner - Enhanced Grading System Setup</h1>";
echo "<pre>";

// Read the SQL schema file
$sql_file = 'enhanced_grading_schema.sql';
if (!file_exists($sql_file)) {
    die("Error: SQL schema file '$sql_file' not found.\n");
}

$sql_content = file_get_contents($sql_file);

// Split into individual statements
$statements = array_filter(array_map('trim', explode(';', $sql_content)));

// Execute each statement
$success_count = 0;
$error_count = 0;

foreach ($statements as $statement) {
    if (empty($statement) || strpos($statement, '--') === 0) {
        continue; // Skip empty lines and comments
    }

    // Remove multi-line comments
    $statement = preg_replace('/\/\*.*?\*\//s', '', $statement);

    if (!empty(trim($statement))) {
        if ($conn->query($statement) === TRUE) {
            echo "✓ Executed: " . substr($statement, 0, 50) . "...\n";
            $success_count++;
        } else {
            echo "✗ Error executing: " . substr($statement, 0, 50) . "...\n";
            echo "  Error: " . $conn->error . "\n";
            $error_count++;
        }
    }
}

echo "\n";
echo "Setup Complete!\n";
echo "Successful statements: $success_count\n";
echo "Errors: $error_count\n";

if ($error_count == 0) {
    echo "\n🎉 Enhanced grading system has been successfully set up!\n";
    echo "\nNew features available:\n";
    echo "- Student profiles with detailed information\n";
    echo "- Academic periods and semesters\n";
    echo "- Subject/course management\n";
    echo "- Detailed grading with categories and weights\n";
    echo "- Grade history and audit trail\n";
    echo "- Attendance tracking\n";
    echo "- Performance metrics and analytics\n";
    echo "- Comprehensive reporting system\n";
} else {
    echo "\n⚠️  Some statements failed. Please check the errors above.\n";
}

echo "</pre>";
?></content>
<parameter name="filePath">c:\xampp\htdocs\smartsp\setup_enhanced_grading.php