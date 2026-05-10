<?php
// SQL Syntax Validator for Enhanced Grading Schema
// This script checks the SQL file for basic syntax issues before execution

echo "<h1>SQL Syntax Validation - Enhanced Grading Schema</h1>";
echo "<pre>";

// Check if file exists
$sql_file = 'config/enhanced_grading_schema.sql';
if (!file_exists($sql_file)) {
    die("Error: SQL schema file '$sql_file' not found.\n");
}

// Read the SQL content
$sql_content = file_get_contents($sql_file);

// Basic validation checks
$errors = [];
$warnings = [];

// Check for HTML/XML tags (common corruption issue)
if (strpos($sql_content, '<') !== false || strpos($sql_content, '>') !== false) {
    $errors[] = "Found HTML/XML tags in SQL file - file may be corrupted";
}

// Check for incomplete statements
$statements = array_filter(array_map('trim', explode(';', $sql_content)));
foreach ($statements as $i => $statement) {
    if (!empty($statement)) {
        // Check for CREATE TABLE without closing parenthesis
        if (stripos($statement, 'CREATE TABLE') !== false) {
            if (substr_count($statement, '(') !== substr_count($statement, ')')) {
                $errors[] = "Unmatched parentheses in CREATE TABLE statement " . ($i + 1);
            }
        }

        // Check for basic SQL keywords
        if (stripos($statement, 'CREATE') === false &&
            stripos($statement, 'INSERT') === false &&
            stripos($statement, 'UPDATE') === false &&
            stripos($statement, 'DELETE') === false &&
            stripos($statement, 'ALTER') === false &&
            stripos($statement, 'DROP') === false &&
            stripos($statement, '--') === false) {
            $warnings[] = "Statement " . ($i + 1) . " doesn't start with expected SQL keyword";
        }
    }
}

// Check file size
$file_size = strlen($sql_content);
if ($file_size < 1000) {
    $warnings[] = "SQL file seems too small ($file_size bytes) - may be incomplete";
}

// Report results
echo "File: $sql_file\n";
echo "Size: " . number_format($file_size) . " bytes\n";
echo "Statements found: " . count($statements) . "\n\n";

if (empty($errors) && empty($warnings)) {
    echo "✅ SQL file appears to be valid!\n\n";
    echo "You can now run the setup script:\n";
    echo "http://localhost/smartsp/setup_enhanced_grading.php\n";
} else {
    if (!empty($errors)) {
        echo "❌ ERRORS FOUND:\n";
        foreach ($errors as $error) {
            echo "  - $error\n";
        }
        echo "\n";
    }

    if (!empty($warnings)) {
        echo "⚠️  WARNINGS:\n";
        foreach ($warnings as $warning) {
            echo "  - $warning\n";
        }
        echo "\n";
    }
}

// Show first few lines for verification
echo "First 10 lines of file:\n";
echo str_repeat("-", 50) . "\n";
$lines = explode("\n", $sql_content);
for ($i = 0; $i < min(10, count($lines)); $i++) {
    echo ($i + 1) . ": " . htmlspecialchars($lines[$i]) . "\n";
}

echo "</pre>";
?>