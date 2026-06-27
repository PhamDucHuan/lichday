<?php
session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// KIỂM TRA QUYỀN: Nếu chưa đăng nhập, trả về lỗi 401 ngay lập tức
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized. Vui lòng đăng nhập.']);
    exit;
}

require_once 'config.php';

$weekOffset = isset($_GET['week']) ? (int)$_GET['week'] : 0;
$today = new DateTime();
if ($weekOffset !== 0) { $today->modify($weekOffset . ' week'); }
$monday = clone $today; $monday->modify('this week Monday');
$sunday = clone $today; $sunday->modify('this week Sunday');

$view_user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : $_SESSION['user_id'];

$canView = false;
if ($_SESSION['role'] === 'admin' || (int)$view_user_id === (int)$_SESSION['user_id']) {
    $canView = true;
} else {
    $stmt = $db->prepare("SELECT 1 FROM user_view_permissions WHERE viewer_id = ? AND viewed_user_id = ? LIMIT 1");
    $stmt->execute([$_SESSION['user_id'], $view_user_id]);
    $canView = (bool)$stmt->fetchColumn();
}

if (!$canView) {
    http_response_code(403);
    echo json_encode(['error' => 'Bạn không có quyền xem lịch của người này.']);
    exit;
}

// Lấy danh sách ca học thực tế trong DB để định hình dòng
$slotsData = getTeachingSlotOptions($db);

// Lấy toàn bộ lớp học đang hoạt động
$classes = $db->query("SELECT * FROM classes WHERE status = 'Active'")->fetchAll(PDO::FETCH_ASSOC);

// Lấy toàn bộ lịch sử đổi lịch/bỏ buổi thủ công
$overrideRows = $db->query("SELECT class_id, override_date, new_date, new_slot, new_user_id, action_type FROM class_schedule_overrides")->fetchAll(PDO::FETCH_ASSOC);

$weekSchedule = [];
$monthPreview = [];
$usersQuery = $db->query("SELECT id, username, full_name FROM users")->fetchAll(PDO::FETCH_ASSOC);
$userMap = []; foreach ($usersQuery as $u) { $userMap[$u['id']] = $u['full_name'] ?: $u['username']; }

$daysOfWeek = [1 => 'Thứ 2', 2 => 'Thứ 3', 3 => 'Thứ 4', 4 => 'Thứ 5', 5 => 'Thứ 6', 6 => 'Thứ 7', 0 => 'Chủ Nhật'];
$datesStructure = [];
$tempDate = clone $monday;

for ($i = 0; $i < 7; $i++) {
    $dateRaw = $tempDate->format('Y-m-d');
    $dayNum = (int)$tempDate->format('w');
    $datesStructure[] = ['date_raw' => $dateRaw, 'date_formatted' => $tempDate->format('d/m'), 'day_name' => $daysOfWeek[$dayNum]];
    $weekSchedule[$dateRaw] = [];
    $tempDate->modify('+1 day');
}

foreach ($classes as $class) {
    $effectiveSessions = buildClassSessionDates($class, $overrideRows);
    foreach ($effectiveSessions as $sessionInfo) {
        // Lọc chuẩn xác: Chỉ lấy ca dạy có ID giảng viên trùng với người đang được xem lịch
        if ((int)$sessionInfo['assigned_user_id'] !== (int)$view_user_id) {
            continue;
        }

        $displayDate = $sessionInfo['display_date'];
        $displaySlot = $sessionInfo['display_slot'];

        if (!array_key_exists($displayDate, $weekSchedule)) {
            $currentDate = new DateTime($displayDate);
            if ($currentDate >= $monday && $currentDate <= $sunday) {
                $weekSchedule[$displayDate] = [];
            } else {
                continue;
            }
        }

        $slotCode = null;
        foreach ($slotsData as $slotItem) {
            if (strpos($displaySlot, $slotItem['slot_code']) === 0) {
                $slotCode = $slotItem['slot_code'];
                break;
            }
        }
        
        $teacherName = $userMap[$sessionInfo['assigned_user_id']] ?? '';
        $weekSchedule[$displayDate][] = [
            'name' => $class['class_name'], 
            'time' => $displaySlot, 
            'slot_code' => $slotCode, 
            'class_id' => $class['id'],
            'teacher' => $teacherName
        ];

        $monthPreview[$displayDate][] = [
            'name' => $class['class_name'], 
            'time' => $displaySlot, 
            'slot_code' => $slotCode, 
            'class_id' => $class['id']
        ];
    }
}

$freeSlots = [];
foreach ($datesStructure as $dayInfo) {
    $dateRaw = $dayInfo['date_raw'];
    $busySlots = [];
    foreach ($weekSchedule[$dateRaw] as $entry) {
        if (!empty($entry['slot_code'])) {
            $busySlots[] = $entry['slot_code'];
        }
    }
    $freeSlots[$dateRaw] = [];
    foreach ($slotsData as $slotItem) {
        if (!in_array($slotItem['slot_code'], $busySlots, true)) {
            $freeSlots[$dateRaw][] = $slotItem['slot_code'];
        }
    }
}

echo json_encode([
    'user_role' => $_SESSION['role'],
    'monday' => $monday->format('d/m/Y'),
    'sunday' => $sunday->format('d/m/Y'),
    'dates' => $datesStructure,
    'schedule' => $weekSchedule,
    'free_slots' => $freeSlots,
    'month_preview' => $monthPreview,
    'slots_definitions' => $slotsData
]);