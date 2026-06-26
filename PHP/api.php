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

// (Giữ nguyên toàn bộ đoạn code tính toán $weekOffset, $classes, $weekSchedule ở file api.php cũ tại đây...)
// ...
$weekOffset = isset($_GET['week']) ? (int)$_GET['week'] : 0;
$today = new DateTime();
if ($weekOffset !== 0) { $today->modify($weekOffset . ' week'); }
$monday = clone $today; $monday->modify('this week Monday');
$sunday = clone $today; $sunday->modify('this week Sunday');

$view_user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : $_SESSION['user_id'];

$canView = false;
if ($_SESSION['role'] === 'admin' || $view_user_id === (int)$_SESSION['user_id']) {
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

$stmt = $db->prepare("SELECT * FROM classes WHERE status = 'Active' AND (assigned_user_id IS NULL OR assigned_user_id = ?)");
$stmt->execute([$view_user_id]);
$classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
$overrideStmt = $db->query("SELECT class_id, override_date, new_date, new_slot, action_type FROM class_schedule_overrides");
$overrides = $overrideStmt->fetchAll(PDO::FETCH_ASSOC);
$weekSchedule = [];
$monthPreview = [];
$daysOfWeek = [1 => 'Thứ 2', 2 => 'Thứ 3', 3 => 'Thứ 4', 4 => 'Thứ 5', 5 => 'Thứ 6', 6 => 'Thứ 7', 0 => 'Chủ Nhật'];
$datesStructure = [];
$allowedSlots = ['S1', 'S2', 'C1', 'C2', 'T1', 'T2'];
$tempDate = clone $monday;
for ($i = 0; $i < 7; $i++) {
    $dateRaw = $tempDate->format('Y-m-d');
    $dayNum = (int)$tempDate->format('w');
    $datesStructure[] = ['date_raw' => $dateRaw, 'date_formatted' => $tempDate->format('d/m'), 'day_name' => $daysOfWeek[$dayNum]];
    $weekSchedule[$dateRaw] = [];
    $tempDate->modify('+1 day');
}
foreach ($classes as $class) {
    $effectiveSessions = buildClassSessionDates($class, $overrides);
    foreach ($effectiveSessions as $sessionInfo) {
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
        foreach ($allowedSlots as $slot) {
            if (strpos($displaySlot, $slot) === 0) {
                $slotCode = $slot;
                break;
            }
        }
        $weekSchedule[$displayDate][] = ['name' => $class['class_name'], 'time' => $displaySlot, 'slot_code' => $slotCode, 'class_id' => $class['id']];

        $monthPreview[$displayDate][] = ['name' => $class['class_name'], 'time' => $displaySlot, 'slot_code' => $slotCode, 'class_id' => $class['id']];
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
    foreach ($allowedSlots as $slot) {
        if (!in_array($slot, $busySlots, true)) {
            $freeSlots[$dateRaw][] = $slot;
        }
    }
}

// Trả thêm thông tin quyền user để hiển thị nút Admin nếu cần
echo json_encode([
    'user_role' => $_SESSION['role'],
    'monday' => $monday->format('d/m/Y'),
    'sunday' => $sunday->format('d/m/Y'),
    'dates' => $datesStructure,
    'schedule' => $weekSchedule,
    'free_slots' => $freeSlots,
    'month_preview' => $monthPreview
]);