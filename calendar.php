<?php
session_start();
require_once 'config/db_config.php';
require_once 'config/auth.php';
require_once 'config/notifications.php';

if (!isLoggedIn()) {
    header('Location: login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$month = isset($_GET['month']) ? intval($_GET['month']) : date('m');
$year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');

$events = getCalendarEventsMonth($user_id, $month, $year);
$today_events = getTodayEvents($user_id);
$upcoming_events = getUpcomingEvents($user_id);

// Handle event creation
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'create_event') {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $start_date = $_POST['start_date'] ?? '';
    $end_date = $_POST['end_date'] ?? '';
    $event_type = $_POST['event_type'] ?? 'other';
    $color = $_POST['color'] ?? '#3498db';
    
    if (!empty($title) && !empty($start_date)) {
        createCalendarEvent($user_id, $title, $description, $start_date, $end_date, $event_type, $color);
        header('Location: calendar.php?created=1');
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calendar - Smart Study Planner</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        :root {
            --primary: #3498db;
            --secondary: #2ecc71;
            --dark: #2c3e50;
            --light: #ecf0f1;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f5f7fa;
            color: var(--dark);
        }
        
        .navbar {
            background: linear-gradient(135deg, var(--primary) 0%, var(--secondary) 100%);
            color: white;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .navbar a {
            color: white;
            text-decoration: none;
            margin-left: 2rem;
            transition: 0.3s;
        }
        
        .navbar a:hover {
            opacity: 0.8;
        }
        
        .container {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 1rem;
        }
        
        .calendar-wrapper {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 2rem;
            margin-bottom: 2rem;
        }
        
        .calendar-card {
            background: white;
            border-radius: 10px;
            padding: 2rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .calendar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }
        
        .calendar-header h2 {
            color: var(--primary);
        }
        
        .nav-button {
            background: var(--primary);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 5px;
            cursor: pointer;
            transition: 0.3s;
        }
        
        .nav-button:hover {
            background: #2980b9;
        }
        
        .weekdays {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 0.5rem;
            margin-bottom: 1rem;
        }
        
        .weekday {
            text-align: center;
            font-weight: 600;
            color: var(--primary);
            padding: 0.5rem;
        }
        
        .days {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 0.5rem;
        }
        
        .day {
            aspect-ratio: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 0.5rem;
            border-radius: 5px;
            background: #f8f9fa;
            cursor: pointer;
            transition: all 0.3s;
            position: relative;
            font-size: 0.9rem;
        }
        
        .day:hover {
            background: #ecf0f1;
            transform: scale(1.05);
        }
        
        .day.other-month {
            color: #bdc3c7;
        }
        
        .day.today {
            background: var(--primary);
            color: white;
            font-weight: bold;
        }
        
        .day.has-event::after {
            content: '';
            width: 4px;
            height: 4px;
            background: var(--primary);
            border-radius: 50%;
            position: absolute;
            bottom: 3px;
        }
        
        .events-list {
            background: white;
            border-radius: 10px;
            padding: 2rem;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .events-list h3 {
            color: var(--primary);
            margin-bottom: 1rem;
        }
        
        .event-item {
            padding: 1rem;
            border-left: 4px solid var(--primary);
            background: #f8f9fa;
            border-radius: 5px;
            margin-bottom: 0.8rem;
        }
        
        .event-title {
            font-weight: 600;
            color: var(--dark);
            margin-bottom: 0.3rem;
        }
        
        .event-time {
            font-size: 0.85rem;
            color: #7f8c8d;
        }
        
        .btn {
            padding: 0.6rem 1.2rem;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            background: var(--primary);
            color: white;
            transition: 0.3s;
            width: 100%;
            margin-top: 1rem;
        }
        
        .btn:hover {
            background: #2980b9;
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            overflow-y: auto;
        }
        
        .modal.active {
            display: flex;
        }
        
        .modal-content {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            max-width: 500px;
            width: 90%;
            margin: 2rem auto;
        }
        
        .form-group {
            margin-bottom: 1rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
        }
        
        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 0.7rem;
            border: 2px solid #ecf0f1;
            border-radius: 5px;
            font-family: inherit;
        }
        
        @media (max-width: 768px) {
            .calendar-wrapper {
                grid-template-columns: 1fr;
            }
            
            .day {
                font-size: 0.8rem;
            }
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <div>📚 Smart Study Planner</div>
        <div>
            <a href="<?php echo ($_SESSION['role'] == 'student' ? 'student/dashboard.php' : ($_SESSION['role'] == 'teacher' ? 'teacher/dashboard.php' : 'admin/dashboard.php')); ?>">Dashboard</a>
            <a href="profile.php">Profile</a>
            <a href="logout.php">Logout</a>
        </div>
    </nav>

    <div class="container">
        <div class="calendar-wrapper">
            <div class="calendar-card">
                <div class="calendar-header">
                    <button class="nav-button" onclick="changeMonth(-1)">← Prev</button>
                    <h2 id="monthYear"></h2>
                    <button class="nav-button" onclick="changeMonth(1)">Next →</button>
                </div>
                
                <div class="weekdays">
                    <div class="weekday">Sun</div>
                    <div class="weekday">Mon</div>
                    <div class="weekday">Tue</div>
                    <div class="weekday">Wed</div>
                    <div class="weekday">Thu</div>
                    <div class="weekday">Fri</div>
                    <div class="weekday">Sat</div>
                </div>
                
                <div class="days" id="calendar"></div>
                
                <button class="btn" onclick="openEventModal()">+ Add Event</button>
            </div>

            <div>
                <div class="events-list">
                    <h3>📅 Today's Events</h3>
                    <?php if (empty($today_events)): ?>
                        <p style="color: #7f8c8d;">No events today</p>
                    <?php else: ?>
                        <?php foreach ($today_events as $event): ?>
                            <div class="event-item">
                                <div class="event-title"><?php echo htmlspecialchars($event['title']); ?></div>
                                <div class="event-time"><?php echo date('h:i A', strtotime($event['start_date'])); ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="events-list" style="margin-top: 1.5rem;">
                    <h3>📌 Upcoming Events</h3>
                    <?php if (empty($upcoming_events)): ?>
                        <p style="color: #7f8c8d;">No upcoming events</p>
                    <?php else: ?>
                        <?php foreach ($upcoming_events as $event): ?>
                            <div class="event-item">
                                <div class="event-title"><?php echo htmlspecialchars($event['title']); ?></div>
                                <div class="event-time"><?php echo date('M d, Y h:i A', strtotime($event['start_date'])); ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Event Modal -->
    <div class="modal" id="eventModal">
        <div class="modal-content">
            <h2>Add Event</h2>
            <form method="POST">
                <input type="hidden" name="action" value="create_event">
                
                <div class="form-group">
                    <label for="title">Event Title *</label>
                    <input type="text" id="title" name="title" required>
                </div>
                
                <div class="form-group">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" rows="3"></textarea>
                </div>
                
                <div class="form-group">
                    <label for="start_date">Start Date & Time *</label>
                    <input type="datetime-local" id="start_date" name="start_date" required>
                </div>
                
                <div class="form-group">
                    <label for="end_date">End Date & Time</label>
                    <input type="datetime-local" id="end_date" name="end_date">
                </div>
                
                <div class="form-group">
                    <label for="event_type">Event Type</label>
                    <select id="event_type" name="event_type">
                        <option value="other">Other</option>
                        <option value="task">Task</option>
                        <option value="exam">Exam</option>
                        <option value="class">Class</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="color">Color</label>
                    <input type="color" id="color" name="color" value="#3498db">
                </div>
                
                <div style="display: flex; gap: 1rem;">
                    <button type="submit" class="btn" style="margin: 1rem 0; flex: 1;">Save Event</button>
                    <button type="button" class="btn" style="margin: 1rem 0; flex: 1; background: #7f8c8d;" onclick="closeEventModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        let currentMonth = <?php echo $month; ?>;
        let currentYear = <?php echo $year; ?>;
        
        function renderCalendar() {
            const firstDay = new Date(currentYear, currentMonth - 1, 1);
            const lastDay = new Date(currentYear, currentMonth, 0);
            const daysInMonth = lastDay.getDate();
            const startingDayOfWeek = firstDay.getDay();
            
            const monthYear = new Date(currentYear, currentMonth - 1);
            document.getElementById('monthYear').textContent = monthYear.toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
            
            const calendar = document.getElementById('calendar');
            calendar.innerHTML = '';
            
            // Previous month days
            const prevMonthLastDay = new Date(currentYear, currentMonth - 1, 0).getDate();
            for (let i = startingDayOfWeek - 1; i >= 0; i--) {
                const day = document.createElement('div');
                day.className = 'day other-month';
                day.textContent = prevMonthLastDay - i;
                calendar.appendChild(day);
            }
            
            // Current month days
            for (let i = 1; i <= daysInMonth; i++) {
                const day = document.createElement('div');
                day.className = 'day';
                day.textContent = i;
                
                const today = new Date();
                if (i === today.getDate() && currentMonth === today.getMonth() + 1 && currentYear === today.getFullYear()) {
                    day.classList.add('today');
                }
                
                calendar.appendChild(day);
            }
            
            // Next month days
            const totalCells = calendar.children.length;
            const remainingCells = 42 - totalCells;
            for (let i = 1; i <= remainingCells; i++) {
                const day = document.createElement('div');
                day.className = 'day other-month';
                day.textContent = i;
                calendar.appendChild(day);
            }
        }
        
        function changeMonth(offset) {
            currentMonth += offset;
            if (currentMonth > 12) {
                currentMonth = 1;
                currentYear++;
            } else if (currentMonth < 1) {
                currentMonth = 12;
                currentYear--;
            }
            window.location.href = `calendar.php?month=${currentMonth}&year=${currentYear}`;
        }
        
        function openEventModal() {
            document.getElementById('eventModal').classList.add('active');
        }
        
        function closeEventModal() {
            document.getElementById('eventModal').classList.remove('active');
        }
        
        renderCalendar();
    </script>
</body>
</html>
