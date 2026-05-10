<?php
require_once 'db_config.php';

// Create Notification
function createNotification($user_id, $title, $message, $type = 'system', $related_id = null) {
    global $conn;
    
    $query = "INSERT INTO notifications (user_id, title, message, type, related_id) 
              VALUES (?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("isssi", $user_id, $title, $message, $type, $related_id);
    
    return $stmt->execute();
}

// Get user notifications
function getUserNotifications($user_id, $limit = 50) {
    global $conn;
    
    $query = "SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $user_id, $limit);
    $stmt->execute();
    
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Get unread notifications count
function getUnreadNotificationsCount($user_id) {
    global $conn;
    
    $query = "SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = FALSE";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    
    $result = $stmt->get_result()->fetch_assoc();
    return $result['count'];
}

// Mark notification as read
function markNotificationAsRead($notification_id) {
    global $conn;
    
    $query = "UPDATE notifications SET is_read = TRUE WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $notification_id);
    
    return $stmt->execute();
}

// Mark all notifications as read
function markAllNotificationsAsRead($user_id) {
    global $conn;
    
    $query = "UPDATE notifications SET is_read = TRUE WHERE user_id = ? AND is_read = FALSE";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $user_id);
    
    return $stmt->execute();
}

// Delete notification
function deleteNotification($notification_id) {
    global $conn;
    
    $query = "DELETE FROM notifications WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $notification_id);
    
    return $stmt->execute();
}

// Broadcast notification to multiple users
function broadcastNotification($user_ids, $title, $message, $type = 'announcement', $related_id = null) {
    global $conn;
    
    $query = "INSERT INTO notifications (user_id, title, message, type, related_id) VALUES (?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($query);
    
    foreach ($user_ids as $user_id) {
        $stmt->bind_param("isssi", $user_id, $title, $message, $type, $related_id);
        $stmt->execute();
    }
    
    return true;
}

// ============ CALENDAR FUNCTIONS ============

// Create calendar event
function createCalendarEvent($user_id, $title, $description, $start_date, $end_date, $event_type = 'other', $color = '#3498db') {
    global $conn;
    
    $query = "INSERT INTO calendar_events (user_id, title, description, start_date, end_date, event_type, color) 
              VALUES (?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("issssss", $user_id, $title, $description, $start_date, $end_date, $event_type, $color);
    
    if ($stmt->execute()) {
        return ['success' => true, 'event_id' => $conn->insert_id];
    }
    
    return ['success' => false];
}

// Get user calendar events
function getUserCalendarEvents($user_id, $start_date = null, $end_date = null) {
    global $conn;
    
    if ($start_date && $end_date) {
        $query = "SELECT * FROM calendar_events WHERE user_id = ? AND start_date >= ? AND start_date <= ? ORDER BY start_date ASC";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("iss", $user_id, $start_date, $end_date);
    } else {
        $query = "SELECT * FROM calendar_events WHERE user_id = ? ORDER BY start_date ASC";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $user_id);
    }
    
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Get calendar events for current month
function getCalendarEventsMonth($user_id, $month, $year) {
    global $conn;
    
    $start_date = $year . '-' . str_pad($month, 2, '0', STR_PAD_LEFT) . '-01';
    $end_date = date('Y-m-t', strtotime($start_date));
    
    $query = "SELECT * FROM calendar_events 
              WHERE user_id = ? AND DATE(start_date) >= ? AND DATE(start_date) <= ? 
              ORDER BY start_date ASC";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("iss", $user_id, $start_date, $end_date);
    $stmt->execute();
    
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Get calendar event by ID
function getCalendarEventById($event_id) {
    global $conn;
    
    $query = "SELECT * FROM calendar_events WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $event_id);
    $stmt->execute();
    
    return $stmt->get_result()->fetch_assoc();
}

// Update calendar event
function updateCalendarEvent($event_id, $title, $description, $start_date, $end_date, $event_type = 'other', $color = '#3498db') {
    global $conn;
    
    $query = "UPDATE calendar_events SET title = ?, description = ?, start_date = ?, end_date = ?, event_type = ?, color = ? WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ssssssi", $title, $description, $start_date, $end_date, $event_type, $color, $event_id);
    
    return $stmt->execute();
}

// Delete calendar event
function deleteCalendarEvent($event_id) {
    global $conn;
    
    $query = "DELETE FROM calendar_events WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $event_id);
    
    return $stmt->execute();
}

// Auto-create calendar event from task
function createTaskCalendarEvent($task_id) {
    global $conn;
    
    $query = "SELECT id, teacher_id, title, due_date FROM tasks WHERE id = ?";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $task_id);
    $stmt->execute();
    $task = $stmt->get_result()->fetch_assoc();
    
    if ($task) {
        return createCalendarEvent(
            $task['teacher_id'],
            $task['title'],
            'Task Assignment: ' . $task['title'],
            $task['due_date'],
            $task['due_date'],
            'task',
            '#e74c3c'
        );
    }
    
    return ['success' => false];
}

// Get today's events
function getTodayEvents($user_id) {
    global $conn;
    
    $today = date('Y-m-d');
    
    $query = "SELECT * FROM calendar_events 
              WHERE user_id = ? AND DATE(start_date) = ? 
              ORDER BY start_date ASC";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("is", $user_id, $today);
    $stmt->execute();
    
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

// Get upcoming events (next 7 days)
function getUpcomingEvents($user_id) {
    global $conn;
    
    $today = date('Y-m-d');
    $next_week = date('Y-m-d', strtotime('+7 days'));
    
    $query = "SELECT * FROM calendar_events 
              WHERE user_id = ? AND DATE(start_date) >= ? AND DATE(start_date) <= ? 
              ORDER BY start_date ASC";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("iss", $user_id, $today, $next_week);
    $stmt->execute();
    
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
?>
