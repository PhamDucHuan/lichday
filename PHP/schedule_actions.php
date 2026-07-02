<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

$action = $_POST['action'] ?? '';
$classId = (int)($_POST['class_id'] ?? 0);
$sessionDate = trim($_POST['session_date'] ?? '');

if ($action === 'delete') {
    if ($classId <= 0 || $sessionDate === '') {
        jsonResponse(['success' => false, 'message' => 'Thieu hoac sai thong tin ngay hoc.'], 422);
    }

    $notificationContext = getScheduleNotificationContext($db, $classId, $sessionDate);
    saveClassScheduleOverride($db, $classId, $sessionDate, null, null, null, 'delete');
    notifyScheduleChanged($db, 'delete', $notificationContext);
    jsonResponse(['success' => true, 'message' => 'Da xoa lich cua ngay nay va day cac buoi sau ve phia sau.']);
}

if ($action === 'move') {
    $newDate = trim($_POST['new_date'] ?? '');
    $newSlot = trim($_POST['new_slot'] ?? '');
    $newUserChanges = isset($_POST['new_user_id']) ? (int)$_POST['new_user_id'] : 0;

    if ($classId <= 0 || $sessionDate === '' || !isValidDateString($newDate) || $newSlot === '') {
        jsonResponse(['success' => false, 'message' => 'Thieu hoac sai thong tin doi lich.'], 422);
    }

    $classStmt = $db->prepare('SELECT * FROM classes WHERE id = ? LIMIT 1');
    $classStmt->execute([$classId]);
    $targetClass = $classStmt->fetch(PDO::FETCH_ASSOC);
    if ($targetClass && (($targetClass['class_type'] ?? 'fixed') === 'one_on_one')) {
        $teacherId = $newUserChanges > 0 ? $newUserChanges : (int)($targetClass['assigned_user_id'] ?? 0);
        $conflict = findTeacherScheduleConflict($db, $newDate, $newSlot, $teacherId, $classId);
        if ($conflict) {
            jsonResponse([
                'success' => false,
                'message' => 'Lop 1-1 bi trung lich voi lop ' . $conflict['class_name'] . ' vao ngay ' . date('d/m/Y', strtotime($conflict['date'])) . ' (' . $conflict['slot'] . ').'
            ], 422);
        }
    }

    $notificationContext = getScheduleNotificationContext($db, $classId, $sessionDate);
    saveClassScheduleOverride($db, $classId, $sessionDate, $newDate, $newSlot, $newUserChanges > 0 ? $newUserChanges : null, 'move');
    notifyScheduleChanged($db, 'move', $notificationContext, [
        'new_date' => $newDate,
        'new_slot' => $newSlot,
        'new_user_id' => $newUserChanges,
    ]);
    jsonResponse(['success' => true, 'message' => 'Da doi lich va cap nhat giang vien day thay thanh cong.']);
}

jsonResponse(['success' => false, 'message' => 'Unknown action'], 400);
