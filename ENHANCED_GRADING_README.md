# Enhanced Grading and Student Management System

This enhanced database schema extends the Smart Study Planner with comprehensive grading and student management capabilities.

## 🚀 Features

### Student Management
- **Student Profiles**: Extended user information including student ID, contact details, emergency contacts
- **Academic Periods**: Support for semesters, quarters, and academic years
- **Subject Management**: Organized course/subject catalog with credits and departments
- **Class Enrollments**: Track student enrollment in specific classes and periods

### Advanced Grading System
- **Flexible Grading Scales**: Support for percentage, points, letter grades, and pass/fail
- **Assignment Categories**: Weighted grading categories (Homework, Quizzes, Projects, Exams)
- **Detailed Grades**: Individual assignment grading with categories and weights
- **Grade History**: Audit trail for all grade changes
- **Grade Reports**: Generate progress reports, transcripts, and final reports

### Analytics & Reporting
- **Performance Metrics**: Track student progress and trends
- **Grade Distribution**: Class-wide grade analysis
- **Student Summaries**: GPA, credits, and performance overviews
- **Attendance Tracking**: Monitor student attendance patterns

## 📊 Database Tables

### Core Tables
- `student_profiles` - Extended student information
- `academic_periods` - Semesters, quarters, academic years
- `subjects` - Course/subject catalog
- `class_enrollments` - Student-class-period relationships

### Grading Tables
- `grading_scales` - Different grading systems
- `grading_scale_ranges` - Grade letter definitions
- `assignment_categories` - Weighted assignment types
- `detailed_grades` - Individual assignment grades
- `grade_history` - Audit trail for grade changes

### Reporting Tables
- `grade_reports` - Generated report storage
- `performance_metrics` - Student analytics
- `attendance_records` - Attendance tracking

## 🛠️ Installation

1. **Run the Setup Script**:
   ```bash
   # Access via web browser
   http://localhost/smartsp/setup_enhanced_grading.php
   ```

2. **Or Execute SQL Manually**:
   ```bash
   mysql -u root -p smartsp < config/enhanced_grading_schema.sql
   ```

## 📈 Usage Examples

### Add a Student Profile
```sql
INSERT INTO student_profiles (user_id, student_id, date_of_birth, gender, enrollment_date)
VALUES (1, 'STU001', '2005-05-15', 'male', '2024-09-01');
```

### Create an Academic Period
```sql
INSERT INTO academic_periods (name, start_date, end_date, type, status)
VALUES ('Fall 2024', '2024-09-01', '2024-12-20', 'semester', 'active');
```

### Record a Detailed Grade
```sql
INSERT INTO detailed_grades (submission_id, category_id, score, max_score, graded_by)
VALUES (1, 1, 85.00, 100.00, 2);
```

### View Student Performance
```sql
SELECT * FROM student_grade_summary WHERE student_id = 'STU001';
```

## 🎯 Key Benefits

1. **Comprehensive Student Tracking**: Complete student lifecycle management
2. **Flexible Grading**: Support multiple grading systems and scales
3. **Weighted Assessments**: Proper grade calculation with assignment weights
4. **Audit Trail**: Track all grade changes for accountability
5. **Analytics**: Data-driven insights for student performance
6. **Reporting**: Generate various types of academic reports
7. **Attendance Integration**: Monitor attendance impact on grades

## 🔧 Configuration

### Default Grading Scale
- A: 90-100 (4.0 GPA)
- B: 80-89.99 (3.0 GPA)
- C: 70-79.99 (2.0 GPA)
- D: 60-69.99 (1.0 GPA)
- F: 0-59.99 (0.0 GPA)

### Default Assignment Categories
- Homework: 30% weight
- Quizzes: 20% weight
- Projects: 25% weight
- Exams: 25% weight

## 📊 Views Available

- `student_grade_summary` - Student performance overview
- `class_performance` - Class-wide statistics
- `grade_distribution` - Grade distribution analysis

## 🔄 Integration with Existing System

This enhanced schema builds upon the existing Smart Study Planner database:
- Extends `task_submissions` with `detailed_grades`
- Links to existing `users`, `tasks`, and `class_groups` tables
- Maintains backward compatibility

## 🚨 Important Notes

- **Backup First**: Always backup your database before running setup
- **Test Environment**: Test in development before production deployment
- **Data Migration**: May need to migrate existing data to new tables
- **Permissions**: Ensure proper database user permissions

## 📞 Support

For issues or questions about the enhanced grading system, check:
1. Database error logs
2. PHP error logs
3. Existing Smart Study Planner documentation</content>
<parameter name="filePath">c:\xampp\htdocs\smartsp\ENHANCED_GRADING_README.md