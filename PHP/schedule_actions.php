<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$action = $_POST['action'] ?? '';

if ($action === 'delete') {
    $classId = (int)($_POST['class_id'] ?? 0);
    $sessionDate = trim($_POST['session_date'] ?? '');
    if ($classId > 0 && $sessionDate !== '') {
        $stmt = $db->prepare('INSERT INTO class_schedule_overrides (class_id, override_date, action_type) VALUES (?, ?, ?)');
        $stmt->execute([$classId, $sessionDate, 'delete']);
        echo json_encode(['success' => true, 'message' => 'Đã xóa lịch của ngày này và đẩy các buổi sau về phía sau']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Thiếu thông tin']);
    }
    exit;
}

if ($action === 'move') {
    $classId = (int)($_POST['class_id'] ?? 0);
    $newDate = trim($_POST['new_date'] ?? '');
    $newSlot = trim($_POST['new_slot'] ?? '');
    $newUserChanges = isset($_POST['new_user_id']) ? (int)$_POST['new_user_id'] : 0;
    $sessionDate = trim($_POST['session_date'] ?? '');
    
    if ($classId > 0 && $sessionDate !== '' && $newDate !== '' && $newSlot !== '') {
        $stmt = $db->prepare('INSERT INTO class_schedule_overrides (class_id, override_date, new_date, new_slot, new_user_id, action_type) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$classId, $sessionDate, $newDate, $newSlot, $newUserChanges > 0 ? $newUserChanges : null, 'move']);
        echo json_encode(['success' => true, 'message' => 'Đã đổi lịch và cập nhật thông tin giảng viên dạy thay thành công']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Thiếu thông tin']);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action']);
