<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    jsonResponse(['error' => 'Unauthorized'], 401);
}

$classId = isset($_GET['class_id']) ? (int)$_GET['class_id'] : 0;
$viewUserId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : (int)$_SESSION['user_id'];
$sessionDate = trim($_GET['session_date'] ?? '');

if ($classId <= 0) {
    jsonResponse(['error' => 'Missing class_id'], 400);
}

$canViewUser = false;
if (($_SESSION['role'] ?? '') === 'admin' || $viewUserId === (int)$_SESSION['user_id']) {
    $canViewUser = true;
} else {
    $permissionStmt = $db->prepare('SELECT 1 FROM user_view_permissions WHERE viewer_id = ? AND viewed_user_id = ? LIMIT 1');
    $permissionStmt->execute([$_SESSION['user_id'], $viewUserId]);
    $canViewUser = (bool)$permissionStmt->fetchColumn();
}

if (!$canViewUser) {
    jsonResponse(['error' => 'Forbidden'], 403);
}

$classStmt = $db->prepare('SELECT * FROM classes WHERE id = ? LIMIT 1');
$classStmt->execute([$classId]);
$class = $classStmt->fetch(PDO::FETCH_ASSOC);

if (!$class) {
    jsonResponse(['error' => 'Class not found'], 404);
}

$overrideStmt = $db->prepare('SELECT class_id, override_date, new_date, new_slot, new_user_id, action_type FROM class_schedule_overrides WHERE class_id = ?');
$overrideStmt->execute([$classId]);
$overrideRows = $overrideStmt->fetchAll(PDO::FETCH_ASSOC);

$matchedSession = null;
$effectiveSessions = buildClassSessionDates($class, $overrideRows);
foreach ($effectiveSessions as $sessionInfo) {
    if ((int)$sessionInfo['assigned_user_id'] !== $viewUserId) {
        continue;
    }
    if ($sessionDate !== '' && isValidDateString($sessionDate) && $sessionInfo['display_date'] !== $sessionDate) {
        continue;
    }
    $matchedSession = $sessionInfo;
    break;
}

if (!$matchedSession) {
    jsonResponse(['error' => 'Forbidden'], 403);
}

$teacherStmt = $db->prepare('SELECT username, full_name FROM users WHERE id = ? LIMIT 1');
$teacherStmt->execute([$viewUserId]);
$teacher = $teacherStmt->fetch(PDO::FETCH_ASSOC);
$teacherName = $teacher ? ($teacher['full_name'] ?: $teacher['username']) : '';
$attendanceDate = $matchedSession['display_date'];

$studentStmt = $db->prepare("
    SELECT
        s.id,
        s.student_name,
        s.phone,
        COALESCE(sp.studied_sessions, 0) AS studied_sessions,
        COALESCE(sp.total_sessions, c.total_sessions) AS total_sessions,
        COALESCE(sp.progress_percent, 0) AS progress_percent,
        a.status AS attendance_status
    FROM student_class sc
    JOIN students s ON s.id = sc.student_id
    JOIN classes c ON c.id = sc.class_id
    LEFT JOIN student_progress sp ON sp.student_id = s.id AND sp.class_id = sc.class_id
    LEFT JOIN attendance a ON a.student_id = s.id AND a.class_id = sc.class_id AND a.attendance_date = ?
    WHERE sc.class_id = ?
    ORDER BY s.student_name ASC
");
$studentStmt->execute([$attendanceDate, $classId]);
$students = $studentStmt->fetchAll(PDO::FETCH_ASSOC);

jsonResponse([
    'class' => [
        'id' => (int)$class['id'],
        'name' => $class['class_name'],
        'teacher' => $teacherName,
        'session_date' => $matchedSession['display_date'],
        'slot' => $matchedSession['display_slot'],
        'total_sessions' => (int)$class['total_sessions'],
        'student_count' => count($students),
    ],
    'students' => array_map(static function ($student) {
        $attendanceStatus = $student['attendance_status'] ?: 'Expected';
        $statusLabel = 'Dự kiến';
        if ($attendanceStatus === 'Present') {
            $statusLabel = 'Đi học';
        } elseif ($attendanceStatus === 'Absent') {
            $statusLabel = 'Vắng';
        }

        return [
            'id' => (int)$student['id'],
            'name' => $student['student_name'],
            'phone' => $student['phone'],
            'studied_sessions' => (int)$student['studied_sessions'],
            'total_sessions' => (int)$student['total_sessions'],
            'progress_percent' => (int)$student['progress_percent'],
            'attendance_status' => $attendanceStatus,
            'status_label' => $statusLabel,
        ];
    }, $students),
]);
