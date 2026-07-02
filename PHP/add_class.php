<?php
session_start();
require_once 'config.php';
require_once 'ap_sync.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sync_ap_classes') {
    if (($_SESSION['role'] ?? '') !== 'admin') {
        $message = "<p class='error'>Chi tai khoan admin moi duoc cap nhat lop tu AP.</p>";
    } else {
        try {
            $result = runApClassStudentUpdate();
            $message = "<p class='success'>" . htmlspecialchars(buildApClassStudentUpdateMessage($result)) . "</p>";
        } catch (Throwable $e) {
            $message = "<p class='error'>Cap nhat AP that bai: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
    }
}

function jsonAddClassResponse(bool $success, string $message, array $extra = []): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function findClassScheduleConflictInDates(PDO $db, array $dates, string $slot, int $assignedUserId, int $excludeClassId = 0): ?array {
    $dates = array_values(array_unique(array_filter($dates, static fn($date) => is_string($date) && isValidDateString($date))));
    if (empty($dates) || $slot === '' || $assignedUserId <= 0) {
        return null;
    }

    $classStmt = $db->prepare("
        SELECT DISTINCT c.*
        FROM classes c
        LEFT JOIN class_schedule_overrides o
            ON o.class_id = c.id
           AND o.action_type = 'move'
           AND o.new_user_id = ?
        WHERE c.status = 'Active'
          AND c.id <> ?
          AND (c.assigned_user_id = ? OR o.class_id IS NOT NULL)
    ");
    $classStmt->execute([$assignedUserId, $excludeClassId, $assignedUserId]);
    $classes = $classStmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($classes)) {
        return null;
    }

    $classIds = array_map(static fn($class) => (int)$class['id'], $classes);
    $classPlaceholders = implode(',', array_fill(0, count($classIds), '?'));
    $overrideStmt = $db->prepare("SELECT class_id, override_date, new_date, new_slot, new_user_id, action_type FROM class_schedule_overrides WHERE class_id IN ($classPlaceholders)");
    $overrideStmt->execute($classIds);
    $overrideRows = $overrideStmt->fetchAll(PDO::FETCH_ASSOC);
    $dateMap = array_fill_keys($dates, true);

    foreach ($classes as $class) {
        foreach (buildClassSessionDates($class, $overrideRows) as $session) {
            $displayDate = (string)($session['display_date'] ?? '');
            if (!isset($dateMap[$displayDate]) || ($session['display_slot'] ?? '') !== $slot) {
                continue;
            }

            if ((int)($session['assigned_user_id'] ?? $class['assigned_user_id'] ?? 0) === $assignedUserId) {
                return [
                    'class_id' => (int)$class['id'],
                    'class_name' => $class['class_name'] ?? 'Không rõ lớp',
                    'date' => $displayDate,
                    'slot' => $slot,
                ];
            }
        }
    }

    return null;
}

function findClassScheduleConflict(PDO $db, string $date, string $slot, int $assignedUserId, int $excludeClassId = 0): ?array {
    return findClassScheduleConflictInDates($db, [$date], $slot, $assignedUserId, $excludeClassId);
}

function findOneOnOneCreationConflict(PDO $db, string $startDate, array $days, int $totalSessions, string $slotTime, int $assignedUserId, int $excludeClassId = 0): ?array {
    return findClassScheduleConflictInDates($db, generateDates($startDate, $days, $totalSessions), $slotTime, $assignedUserId, $excludeClassId);
}

function buildClassValidationErrors(string $classType, string $className, int $totalSessions, int $assignedUserId, string $startDate = '', string $slotTime = '', array $days = []): array {
    $errors = [];
    if ($className === '') {
        $errors[] = 'Nhập mã / tên lớp học.';
    }
    if ($totalSessions <= 0) {
        $errors[] = 'Nhập tổng số buổi dạy lớn hơn 0.';
    }
    if ($assignedUserId <= 0) {
        $errors[] = 'Chọn người dạy mặc định.';
    }

    if ($classType !== 'flexible') {
        if ($startDate === '') {
            $errors[] = 'Chọn ngày khai giảng.';
        } elseif (!isValidDateString($startDate)) {
            $errors[] = 'Ngày khai giảng không hợp lệ.';
        }
        if ($slotTime === '') {
            $errors[] = 'Chọn ca dạy.';
        }
        if (empty($days)) {
            $errors[] = 'Chọn ít nhất một ngày học cố định trong tuần.';
        }
    }

    return $errors;
}

function formatValidationMessage(array $errors): string {
    return "Vui lòng bổ sung:\n- " . implode("\n- ", $errors);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['add_class']) || (isset($_GET['api']) && $_GET['api'] === 'add_class'))) {
    $isApiAddClass = isset($_GET['api']) && $_GET['api'] === 'add_class';
    $className = trim($_POST['class_name'] ?? '');
    $totalSessions = (int)($_POST['total_sessions'] ?? 0);
    $postedClassType = $_POST['class_type'] ?? 'fixed';
    $classType = in_array($postedClassType, ['fixed', 'flexible', 'one_on_one'], true) ? $postedClassType : 'fixed';
    $assignedUserId = (int)($_POST['assigned_user_id'] ?? $_SESSION['user_id'] ?? 0);
    if ($assignedUserId <= 0) { $assignedUserId = (int)($_SESSION['user_id'] ?? 0); }
    $responseMessage = '';
    $responseSuccess = false;

    if ($classType === 'flexible') {
        $errors = buildClassValidationErrors($classType, $className, $totalSessions, $assignedUserId);
        if (empty($errors)) {
            $stmt = $db->prepare("INSERT INTO classes (class_name, start_date, schedule_days, slot_time, total_sessions, status, class_type, flexible_slots, assigned_user_id) VALUES (?, NOW(), 'Linh hoạt', 'Xoay ca', ?, 'Active', 'flexible', '', ?)");
            $stmt->execute([$className, $totalSessions, $assignedUserId]);
            $responseSuccess = true;
            $responseMessage = "Đã khởi tạo lớp học xoay ca linh hoạt thành công! Hãy bấm nút 'Xếp lịch bằng tay' tại danh sách lớp để gán ngày học.";
        } else {
            $responseMessage = formatValidationMessage($errors);
        }
    } else {
        $startDate = $_POST['start_date'] ?? '';
        $slotTime = trim($_POST['slot_time'] ?? '');
        $days = $_POST['days'] ?? [];
        $scheduleDays = !empty($days) ? implode(',', $days) : '';
        $errors = buildClassValidationErrors($classType, $className, $totalSessions, $assignedUserId, $startDate, $slotTime, $days);

        if (empty($errors)) {
            if ($classType === 'one_on_one') {
                $conflict = findOneOnOneCreationConflict($db, $startDate, $days, $totalSessions, $slotTime, $assignedUserId);
                if ($conflict) {
                    $responseMessage = "Lớp 1-1 bị trùng lịch với lớp {$conflict['class_name']} vào ngày " . date('d/m/Y', strtotime($conflict['date'])) . " ({$conflict['slot']}).";
                }
            }

            if ($responseMessage === '') {
                $stmt = $db->prepare("INSERT INTO classes (class_name, start_date, schedule_days, slot_time, total_sessions, status, class_type, flexible_slots, assigned_user_id) VALUES (?, ?, ?, ?, ?, 'Active', ?, '', ?)");
                $stmt->execute([$className, $startDate, $scheduleDays, $slotTime, $totalSessions, $classType, $assignedUserId]);
                $responseSuccess = true;
                $responseMessage = $classType === 'one_on_one'
                    ? "Đã khởi tạo lớp 1-1 thành công!"
                    : "Đã khởi tạo lớp học cố định và xếp lịch tự động thành công!";
            }
        } else {
            $responseMessage = formatValidationMessage($errors);
        }
    }

    if ($isApiAddClass) {
        jsonAddClassResponse($responseSuccess, $responseMessage);
    }

    $message = "<p class='" . ($responseSuccess ? 'success' : 'error') . "'>" . htmlspecialchars($responseMessage) . "</p>";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_class'])) {
    $classId = (int)($_POST['edit_class_id'] ?? 0);
    $className = trim($_POST['class_name'] ?? '');
    $totalSessions = (int)($_POST['total_sessions'] ?? 0);
    $postedClassType = $_POST['class_type'] ?? 'fixed';
    $classType = in_array($postedClassType, ['fixed', 'flexible', 'one_on_one'], true) ? $postedClassType : 'fixed';
    $assignedUserId = (int)($_POST['assigned_user_id'] ?? 0);
    $startDate = $_POST['start_date'] ?? '';
    $slotTime = trim($_POST['slot_time'] ?? '');
    $days = $_POST['days'] ?? [];
    $scheduleDays = !empty($days) ? implode(',', $days) : '';
    $responseMessage = '';
    $responseSuccess = false;

    $classStmt = $db->prepare("SELECT * FROM classes WHERE id = ? LIMIT 1");
    $classStmt->execute([$classId]);
    $existingClass = $classStmt->fetch(PDO::FETCH_ASSOC);

    if (!$existingClass) {
        $responseMessage = 'Không tìm thấy lớp cần sửa.';
    } elseif ($classType === 'flexible') {
        $errors = buildClassValidationErrors($classType, $className, $totalSessions, $assignedUserId);
        if (empty($errors)) {
            $stmt = $db->prepare("UPDATE classes
                SET class_name = ?, start_date = NOW(), schedule_days = 'Linh hoạt', slot_time = 'Xoay ca', total_sessions = ?, class_type = ?, assigned_user_id = ?
                WHERE id = ?");
            $stmt->execute([$className, $totalSessions, $classType, $assignedUserId, $classId]);
            $responseSuccess = true;
            $responseMessage = 'Đã cập nhật lớp và giữ các lịch chỉnh tay hiện có.';
        } else {
            $responseMessage = formatValidationMessage($errors);
        }
    } else {
        $errors = buildClassValidationErrors($classType, $className, $totalSessions, $assignedUserId, $startDate, $slotTime, $days);
        if (empty($errors)) {
            if ($classType === 'one_on_one') {
                $conflict = findOneOnOneCreationConflict($db, $startDate, $days, $totalSessions, $slotTime, $assignedUserId, $classId);
                if ($conflict) {
                    $responseMessage = "Lớp 1-1 bị trùng lịch với lớp {$conflict['class_name']} vào ngày " . date('d/m/Y', strtotime($conflict['date'])) . " ({$conflict['slot']}).";
                }
            }

            if ($responseMessage === '') {
                $stmt = $db->prepare("UPDATE classes
                    SET class_name = ?, start_date = ?, schedule_days = ?, slot_time = ?, total_sessions = ?, class_type = ?, assigned_user_id = ?
                    WHERE id = ?");
                $stmt->execute([$className, $startDate, $scheduleDays, $slotTime, $totalSessions, $classType, $assignedUserId, $classId]);
                $responseSuccess = true;
                $responseMessage = 'Đã cập nhật lớp và giữ các lịch chỉnh tay hiện có.';
            }
        } else {
            $responseMessage = formatValidationMessage($errors);
        }
    }

    $message = "<p class='" . ($responseSuccess ? 'success' : 'error') . "'>" . htmlspecialchars($responseMessage) . "</p>";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['api']) && $_GET['api'] === 'quick_add') {
    header('Content-Type: application/json');
    $classId = (int)($_POST['modal_class_id_input'] ?? 0);
    $method = $_POST['add_method'] ?? 'available';

    if ($classId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Lỗi lớp học']);
        exit;
    }

    if ($method === 'available') {
        $studentId = (int)($_POST['select_student_id'] ?? 0);
        if ($studentId > 0) {
            try {
                $db->prepare("INSERT INTO student_class (student_id, class_id) VALUES (?, ?)")->execute([$studentId, $classId]);
                $stStmt = $db->prepare("SELECT student_name, phone FROM students WHERE id = ?");
                $stStmt->execute([$studentId]);
                echo json_encode(['success' => true, 'message' => 'Ghi danh thành công!', 'student' => $stStmt->fetch(PDO::FETCH_ASSOC)]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => 'Học viên đã có trong lớp!']);
            }
        } else {
            echo json_encode(['success' => false, 'message' => 'Vui lòng chọn học viên cần ghi danh.']);
        }
    } elseif ($method === 'new') {
        $name = trim($_POST['new_student_name'] ?? '');
        $phone = trim($_POST['new_student_phone'] ?? '');
        if (!empty($name) && !empty($phone)) {
            $db->prepare("INSERT INTO students (student_name, phone) VALUES (?, ?)")->execute([$name, $phone]);
            $newId = $db->lastInsertId();
            $db->prepare("INSERT INTO student_class (student_id, class_id) VALUES (?, ?)")->execute([$newId, $classId]);
            echo json_encode(['success' => true, 'message' => 'Tạo mới thành công!', 'student' => ['student_name' => $name, 'phone' => $phone]]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Vui lòng nhập đầy đủ tên học viên và số điện thoại.']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Cách ghi danh không hợp lệ.']);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_flexible_schedule'])) {
    $classId = (int)$_POST['flex_class_id'];
    $targetIndex = (int)$_POST['flex_session_index'];
    $newDate = trim($_POST['flex_date'] ?? '');
    $newSlot = trim($_POST['flex_slot'] ?? '');

    if ($classId > 0 && $newDate !== '' && $newSlot !== '') {
        $classStmt = $db->prepare("SELECT * FROM classes WHERE id = ? LIMIT 1");
        $classStmt->execute([$classId]);
        $targetClass = $classStmt->fetch(PDO::FETCH_ASSOC);
        if ($targetClass && (($targetClass['class_type'] ?? 'fixed') === 'one_on_one')) {
            $conflict = findClassScheduleConflict($db, $newDate, $newSlot, (int)($targetClass['assigned_user_id'] ?? 0), $classId);
            if ($conflict) {
                $message = "<p class='error'>Lớp 1-1 bị trùng lịch với lớp " . htmlspecialchars($conflict['class_name']) . " vào ngày " . date('d/m/Y', strtotime($conflict['date'])) . " (" . htmlspecialchars($conflict['slot']) . ").</p>";
            }
        }

        if ($message === '') {
            $overrideDateIdentifier = "FLEX-ID-{$targetIndex}";
            saveClassScheduleOverride($db, $classId, $overrideDateIdentifier, $newDate, $newSlot, null, 'move');
            $message = "<p class='success'>Đã gán ngày học thủ công cho ca thành công!</p>";
        }
    }
}

if (isset($_GET['action']) && isset($_GET['id'])) {
    $db->prepare("UPDATE classes SET status = ? WHERE id = ?")->execute([$_GET['action'], (int)$_GET['id']]);
    header('Location: add_class.php');
    exit;
}
if (isset($_GET['delete'])) {
    $db->prepare("DELETE FROM classes WHERE id = ?")->execute([(int)$_GET['delete']]);
    header('Location: add_class.php');
    exit;
}

$classes = $db->query("SELECT * FROM classes ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$users = $db->query("SELECT id, username, full_name FROM users ORDER BY full_name, username")->fetchAll(PDO::FETCH_ASSOC);
$allStudents = $db->query("SELECT id, student_name, phone FROM students ORDER BY student_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$overrideRows = $db->query("SELECT * FROM class_schedule_overrides")->fetchAll(PDO::FETCH_ASSOC);
$joinedStudentRows = $db->query("
    SELECT sc.class_id, s.student_name, s.phone
    FROM student_class sc
    JOIN students s ON s.id = sc.student_id
    ORDER BY sc.class_id ASC, s.student_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$userMap = [];
foreach ($users as $user) {
    $userMap[(int)$user['id']] = $user;
}
$overrideRowsByClass = [];
foreach ($overrideRows as $overrideRow) {
    $overrideRowsByClass[(int)$overrideRow['class_id']][] = $overrideRow;
}
$studentsByClass = [];
foreach ($joinedStudentRows as $studentRow) {
    $studentsByClass[(int)$studentRow['class_id']][] = [
        'student_name' => $studentRow['student_name'],
        'phone' => $studentRow['phone'],
    ];
}
$slotRows = getTeachingSlotOptions($db);
$slotOptions = array_map(static fn($slot) => $slot['slot_label'], $slotRows);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Cấu Hình Lớp Học</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap"></noscript>
    <link rel="stylesheet" href="../CSS/style.css?v=sidebar-fix-3">
    <style>
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 0.8rem; font-weight: 600; text-transform: uppercase; }
        .badge-active { background: #d1fae5; color: #065f46; }
        .badge-paused { background: #fef3c7; color: #92400e; }
        .badge-closed { background: #f1f5f9; color: #475569; }
        .action-links { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        .status-select { padding: 6px 10px; border-radius: var(--radius-sm); border: 1px solid var(--border-color); font-size: 0.85rem; background: #fff; cursor: pointer; font-weight: 500; }
        .custom-modal { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.5); display: none; align-items: center; justify-content: center; z-index: 9999; padding: 16px; }
        .custom-modal-content { background: white; width: min(600px, 100%); border-radius: var(--radius-md); padding: 24px; box-shadow: var(--shadow-lg); max-height: 90vh; overflow-y: auto; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid var(--border-color); padding-bottom: 12px; }
        .modal-close-btn { background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-muted); }
        .action-header-bar { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 24px; }
        .search-box-container { background: white; border: 1px solid var(--border-color); padding: 14px 20px; border-radius: var(--radius-md); box-shadow: var(--shadow-sm); margin-bottom: 12px; display: flex; align-items: center; gap: 12px; }
        .advanced-class-filters { background: white; border: 1px solid var(--border-color); padding: 14px 20px; border-radius: var(--radius-md); box-shadow: var(--shadow-sm); margin-bottom: 20px; display: grid; grid-template-columns: repeat(3, minmax(150px, 1fr)) auto; align-items: end; gap: 12px; }
        .filter-field { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
        .filter-field label { color: var(--text-muted); font-size: 0.76rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.04em; }
        .search-input { flex: 1; padding: 10px 14px; border: 1px solid var(--border-color); border-radius: var(--radius-sm); font-size: 0.95rem; }
        .filter-select { width: 100%; padding: 10px 12px; border: 1px solid var(--border-color); border-radius: var(--radius-sm); background: #fff; color: var(--text-main); font-size: 0.95rem; }
        .filter-summary { margin: -8px 0 14px; color: var(--text-muted); font-size: 0.88rem; }
        .no-results { text-align: center; padding: 30px; color: var(--text-muted); font-style: italic; background: white; border: 1px solid var(--border-color); border-top: none; }
        @media (max-width: 1100px) {
            .advanced-class-filters { grid-template-columns: 1fr 1fr; }
        }
        @media (max-width: 700px) {
            .advanced-class-filters { grid-template-columns: 1fr; }
        }
        .tab-btn-group { display: flex; gap: 10px; margin-bottom: 12px; margin-top: 15px; border-top: 1px dashed var(--border-color); padding-top: 15px; }
        .tab-btn { background: #f1f5f9; color: var(--text-main); border: 1px solid var(--border-color); padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 0.85rem; font-weight: 500; }
        .tab-btn.active { background: var(--primary); color: white; border-color: var(--primary); }
        #addClassAlertMessage { padding: 10px; border-radius: 4px; font-size: 0.9rem; font-weight: 500; margin-bottom: 12px; display: none; white-space: pre-line; }
        #modalAlertMessage { padding: 10px; border-radius: 4px; font-size: 0.9rem; font-weight: 500; margin-bottom: 12px; display: none; }
        .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
        .alert-error { background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; }
        .flex-session-row { display: flex; justify-content: space-between; align-items: center; gap: 10px; padding: 10px; border-bottom: 1px solid #f1f5f9; }
        .flex-session-row:hover { background: #f8fafc; }
        .warning-note { background:#fffbeb; border:1px solid #fde68a; color:#92400e; border-radius:var(--radius-sm); padding:10px 12px; font-size:0.9rem; line-height:1.45; margin-bottom:14px; }
        .sync-loading-overlay { position: fixed; inset: 0; z-index: 10000; display: none; align-items: center; justify-content: center; background: rgba(15, 23, 42, 0.58); padding: 20px; }
        .sync-loading-overlay.is-visible { display: flex; }
        .sync-loading-box { width: min(380px, 100%); background: #fff; border-radius: var(--radius-md); padding: 28px; text-align: center; box-shadow: var(--shadow-lg); border: 1px solid var(--border-color); }
        .sync-spinner { width: 48px; height: 48px; margin: 0 auto 16px; border: 5px solid #dbeafe; border-top-color: #0f766e; border-radius: 50%; animation: syncSpin 0.85s linear infinite; }
        .sync-loading-title { margin: 0 0 8px; color: var(--text-main); font-size: 1.15rem; font-weight: 800; }
        .sync-loading-text { margin: 0; color: var(--text-muted); line-height: 1.5; }
        @keyframes syncSpin { to { transform: rotate(360deg); } }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/sidebar.php'; ?>
    <div id="syncLoadingOverlay" class="sync-loading-overlay" role="status" aria-live="polite" aria-hidden="true">
        <div class="sync-loading-box">
            <div class="sync-spinner"></div>
            <p class="sync-loading-title">Đang cập nhật dữ liệu AP</p>
            <p class="sync-loading-text">Hệ thống đang tải lớp và học viên. Vui lòng đợi, không đóng trang này.</p>
        </div>
    </div>

    <div class="main-content">
        <div class="header-wrapper">
            <div>
                <h2>Cấu Hình & Quản Lý Lớp Học</h2>
                <span style="font-size: 0.85rem; color: var(--text-muted);">Khởi tạo khóa học, sắp xếp tiến độ hoặc tự lập lịch bằng tay</span>
            </div>
        </div>

        <?= $message ?>

        <div class="action-header-bar">
            <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
                <form method="POST" style="margin:0;" id="syncApForm">
                    <input type="hidden" name="action" value="sync_ap_classes">
                    <button type="submit" class="btn" id="syncApButton" style="padding: 12px 20px; font-weight:600; background:#0f766e;">Cap nhat lop & hoc vien tu AP</button>
                </form>
            <?php endif; ?>
            <button class="btn" onclick="openModal('addClassModal')" style="padding: 12px 20px; font-weight:600;">+ Khởi Tạo Lớp Học Mới</button>
        </div>

        <div id="addClassModal" class="custom-modal">
            <div class="custom-modal-content">
                <div class="modal-header">
                    <h3 style="margin:0;">Thêm Lớp Dạy Hệ Thống</h3>
                    <button class="modal-close-btn" onclick="closeModal('addClassModal')">&times;</button>
                </div>
                <div id="addClassAlertMessage"></div>
                <form action="add_class.php" method="POST" id="addClassForm" novalidate>
                    <input type="hidden" name="add_class" value="1">
                    <div class="form-group">
                        <label>Mã / Tên Lớp Học:</label>
                        <input type="text" name="class_name" placeholder="Ví dụ: THNC.2606.04" required style="width:100%; padding:10px; border:1px solid var(--border-color); border-radius:var(--radius-sm);">
                    </div>

                    <div class="form-group">
                        <label>Loại lớp học:</label>
                        <div class="checkbox-group" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:8px;">
                            <label><input type="radio" name="class_type" value="fixed" checked onchange="toggleClassType(this.value)"> Lớp học cố định (Tự sinh lịch)</label>
                            <label><input type="radio" name="class_type" value="one_on_one" onchange="toggleClassType(this.value)"> Lớp 1-1 (Không trùng lịch)</label>
                            <label><input type="radio" name="class_type" value="flexible" onchange="toggleClassType(this.value)"> Lớp xoay ca linh hoạt (Xếp bằng tay)</label>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr; gap: 16px;">
                        <div class="form-group">
                            <label>Tổng Số Buổi Dạy Của Lớp:</label>
                            <input type="number" name="total_sessions" min="1" placeholder="Ví dụ: 12" required style="width:100%; padding:10px; border:1px solid var(--border-color); border-radius:var(--radius-sm);">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Người dạy mặc định:</label>
                        <select name="assigned_user_id" required style="width:100%; padding:10px; border:1px solid var(--border-color); border-radius:var(--radius-sm);">
                            <?php foreach ($users as $user): ?>
                                <option value="<?= (int)$user['id'] ?>" <?= ((int)$user['id'] === (int)($_SESSION['user_id'] ?? 0)) ? 'selected' : '' ?>><?= htmlspecialchars($user['full_name'] ?: $user['username']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div id="fixed-class-only-options">
                        <div class="form-group">
                            <label>Ngày Khai Giảng (Bắt đầu):</label>
                            <input type="date" name="start_date" id="start_date" style="width:100%; padding:10px; border:1px solid var(--border-color); border-radius:var(--radius-sm);">
                        </div>
                        <div class="form-group">
                            <label>Khung Giờ Dạy (Ca học cố định):</label>
                            <select name="slot_time" id="slot_time" style="width:100%; padding:10px; border:1px solid var(--border-color); border-radius:var(--radius-sm);">
                                <option value="" disabled selected>-- Chọn ca dạy phù hợp --</option>
                                <?php foreach ($slotRows as $slot): ?>
                                    <option value="<?= htmlspecialchars($slot['slot_label']) ?>"><?= htmlspecialchars($slot['slot_label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Lịch Học Cố Định Hằng Tuần:</label>
                            <div class="checkbox-group">
                                <label><input type="checkbox" name="days[]" value="T2"> Thứ 2</label>
                                <label><input type="checkbox" name="days[]" value="T3"> Thứ 3</label>
                                <label><input type="checkbox" name="days[]" value="T4"> Thứ 4</label>
                                <label><input type="checkbox" name="days[]" value="T5"> Thứ 5</label>
                                <label><input type="checkbox" name="days[]" value="T6"> Thứ 6</label>
                                <label><input type="checkbox" name="days[]" value="T7"> Thứ 7</label>
                                <label><input type="checkbox" name="days[]" value="CN"> Chủ Nhật</label>
                            </div>
                        </div>
                    </div>

                    <button type="submit" name="add_class" class="btn" style="width:100%; padding:12px; margin-top:10px;">Khởi Tạo Khóa Học</button>
                </form>
            </div>
        </div>

        <div id="editClassModal" class="custom-modal">
            <div class="custom-modal-content">
                <div class="modal-header">
                    <h3 style="margin:0;">Sửa lớp & sắp lịch lại</h3>
                    <button class="modal-close-btn" onclick="closeModal('editClassModal')">&times;</button>
                </div>
                <div class="warning-note">Khi lưu thay đổi, toàn bộ lịch chỉnh tay/các lần dời buổi của lớp này sẽ được xóa để hệ thống sắp lịch lại từ đầu theo cấu hình mới.</div>
                <form action="add_class.php" method="POST" id="editClassForm">
                    <input type="hidden" name="edit_class" value="1">
                    <input type="hidden" name="edit_class_id" id="edit_class_id">
                    <div class="form-group">
                        <label>Mã / Tên Lớp Học:</label>
                        <input type="text" name="class_name" id="edit_class_name" required style="width:100%; padding:10px; border:1px solid var(--border-color); border-radius:var(--radius-sm);">
                    </div>

                    <div class="form-group">
                        <label>Loại lớp học:</label>
                        <div class="checkbox-group" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:8px;">
                            <label><input type="radio" name="class_type" value="fixed" onchange="toggleEditClassType(this.value)"> Lớp học cố định</label>
                            <label><input type="radio" name="class_type" value="one_on_one" onchange="toggleEditClassType(this.value)"> Lớp 1-1</label>
                            <label><input type="radio" name="class_type" value="flexible" onchange="toggleEditClassType(this.value)"> Lớp xoay ca linh hoạt</label>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Tổng Số Buổi Dạy Của Lớp:</label>
                        <input type="number" name="total_sessions" id="edit_total_sessions" min="1" required style="width:100%; padding:10px; border:1px solid var(--border-color); border-radius:var(--radius-sm);">
                    </div>

                    <div class="form-group">
                        <label>Người dạy mặc định:</label>
                        <select name="assigned_user_id" id="edit_assigned_user_id" required style="width:100%; padding:10px; border:1px solid var(--border-color); border-radius:var(--radius-sm);">
                            <?php foreach ($users as $user): ?>
                                <option value="<?= (int)$user['id'] ?>"><?= htmlspecialchars($user['full_name'] ?: $user['username']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div id="edit-fixed-class-options">
                        <div class="form-group">
                            <label>Ngày Khai Giảng (Bắt đầu lại):</label>
                            <input type="date" name="start_date" id="edit_start_date" style="width:100%; padding:10px; border:1px solid var(--border-color); border-radius:var(--radius-sm);">
                        </div>
                        <div class="form-group">
                            <label>Khung Giờ Dạy:</label>
                            <select name="slot_time" id="edit_slot_time" style="width:100%; padding:10px; border:1px solid var(--border-color); border-radius:var(--radius-sm);">
                                <option value="">-- Chọn ca dạy phù hợp --</option>
                                <?php foreach ($slotRows as $slot): ?>
                                    <option value="<?= htmlspecialchars($slot['slot_label']) ?>"><?= htmlspecialchars($slot['slot_label']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Lịch Học Cố Định Hằng Tuần:</label>
                            <div class="checkbox-group">
                                <label><input type="checkbox" name="days[]" value="T2"> Thứ 2</label>
                                <label><input type="checkbox" name="days[]" value="T3"> Thứ 3</label>
                                <label><input type="checkbox" name="days[]" value="T4"> Thứ 4</label>
                                <label><input type="checkbox" name="days[]" value="T5"> Thứ 5</label>
                                <label><input type="checkbox" name="days[]" value="T6"> Thứ 6</label>
                                <label><input type="checkbox" name="days[]" value="T7"> Thứ 7</label>
                                <label><input type="checkbox" name="days[]" value="CN"> Chủ Nhật</label>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn" style="width:100%; padding:12px; margin-top:10px; background:#0f766e;">Lưu và sắp lịch lại từ đầu</button>
                </form>
            </div>
        </div>

        <div id="viewStudentsModal" class="custom-modal">
            <div class="custom-modal-content">
                <div class="modal-header">
                    <h3 style="margin:0;">Học viên lớp: <span id="modalClassNameTitle" style="color:var(--primary);"></span></h3>
                    <button class="modal-close-btn" onclick="closeModal('viewStudentsModal')">&times;</button>
                </div>
                <div id="modalAlertMessage"></div>
                <h4 style="margin-bottom:8px;">Danh sách thành viên</h4>
                <div style="max-height: 180px; overflow-y:auto; border:1px solid var(--border-color); border-radius:4px; margin-bottom:15px; background:#f8fafc;">
                    <table class="admin-table" style="margin-top:0; border:none; width:100%;">
                        <tbody id="modalStudentListBody"></tbody>
                    </table>
                </div>
                <form id="quickAddStudentForm">
                    <input type="hidden" name="modal_class_id_input" id="modalClassIdInput">
                    <input type="hidden" name="add_method" id="addMethodInput" value="available">
                    <div class="tab-btn-group">
                        <button type="button" class="tab-btn active" id="tabAvailableBtn" onclick="switchAddMethod('available')">Chọn sẵn</button>
                        <button type="button" class="tab-btn" id="tabNewBtn" onclick="switchAddMethod('new')">Tạo mới</button>
                    </div>
                    <div id="methodAvailableGroup" class="form-group">
                        <select name="select_student_id" id="select_student_id" style="width:100%; padding:10px; border:1px solid var(--border-color); border-radius:var(--radius-sm);">
                            <option value="">-- Chọn học viên --</option>
                            <?php foreach ($allStudents as $st): ?>
                                <option value="<?= $st['id'] ?>"><?= htmlspecialchars($st['student_name']) ?> (<?= htmlspecialchars($st['phone']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div id="methodNewGroup" style="display:none;">
                        <div class="form-group"><input type="text" name="new_student_name" id="new_student_name" placeholder="Tên học viên" style="width:100%; padding:10px; border:1px solid var(--border-color); border-radius:var(--radius-sm);"></div>
                        <div class="form-group"><input type="text" name="new_student_phone" id="new_student_phone" placeholder="Số điện thoại" style="width:100%; padding:10px; border:1px solid var(--border-color); border-radius:var(--radius-sm);"></div>
                    </div>
                    <button type="submit" class="btn" style="width:100%;">Ghi danh học viên</button>
                </form>
            </div>
        </div>

        <div id="flexibleScheduleModal" class="custom-modal">
            <div class="custom-modal-content" style="width:min(650px, 100%);">
                <div class="modal-header">
                    <h3 style="margin:0;">Xếp lịch thủ công lớp: <span id="flexClassNameTitle" style="color:var(--primary);"></span></h3>
                    <button class="modal-close-btn" onclick="closeModal('flexibleScheduleModal')">&times;</button>
                </div>
                <div style="max-height:450px; overflow-y:auto; border:1px solid var(--border-color); padding:10px; border-radius:6px; background:#f8fafc;">
                    <div id="flexibleSessionsContainer"></div>
                </div>
            </div>
        </div>

        <div class="search-box-container">
            <span style="font-size: 1.1rem;">🔍</span>
            <input type="text" id="classSearchInput" class="search-input" placeholder="Nhập mã lớp học hoặc tên giảng viên cần tìm...">
        </div>

        <div class="advanced-class-filters">
            <div class="filter-field">
                <label for="classStatusFilter">Trạng thái</label>
                <select id="classStatusFilter" class="filter-select">
                    <option value="">Tất cả trạng thái</option>
                    <option value="Active">Hoạt động</option>
                    <option value="Paused">Tạm dừng</option>
                    <option value="Closed">Đóng lớp</option>
                </select>
            </div>
            <div class="filter-field">
                <label for="classTypeFilter">Loại lớp</label>
                <select id="classTypeFilter" class="filter-select">
                    <option value="">Tất cả loại lớp</option>
                    <option value="fixed">Cố định</option>
                    <option value="one_on_one">Lớp 1-1</option>
                    <option value="flexible">Xoay ca linh hoạt</option>
                </select>
            </div>
            <div class="filter-field">
                <label for="classTeacherFilter">Người dạy</label>
                <select id="classTeacherFilter" class="filter-select">
                    <option value="">Tất cả người dạy</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?= (int)$user['id'] ?>"><?= htmlspecialchars($user['full_name'] ?: $user['username']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="button" class="btn-delete" id="clearClassFiltersBtn" style="padding: 10px 12px;">Xóa lọc</button>
        </div>
        <div id="classFilterSummary" class="filter-summary"></div>

        <div id="classTableWrapper" style="background: white; border-radius: var(--radius-md); overflow: hidden; box-shadow: var(--shadow-sm); border: 1px solid var(--border-color);">
            <table class="admin-table" style="margin-top: 0; border: none;" id="classTable">
                <thead>
                    <tr>
                        <th>Tên Lớp Học</th>
                        <th>Loại lớp</th>
                        <th>Người dạy</th>
                        <th>Lịch Học / Thời Gian</th>
                        <th>Thời Lượng</th>
                        <th>Trạng Thái</th>
                        <th style="text-align:center;">Học Viên</th>
                        <th style="text-align:center;">Xếp Lịch Thủ Công</th>
                        <th>Thao Tác</th>
                    </tr>
                </thead>
                <tbody id="classTableBody">
                    <?php foreach ($classes as $c): ?>
                    <?php
                        $isFlex = (($c['class_type'] ?? 'fixed') === 'flexible');
                        $isOneOnOne = (($c['class_type'] ?? 'fixed') === 'one_on_one');
                        $classId = (int)$c['id'];
                        $sessions = buildClassSessionDates($c, $overrideRowsByClass[$classId] ?? []);
                        $teacherName = ((isset($userMap[(int)$c['assigned_user_id']]) ? $userMap[(int)$c['assigned_user_id']]['full_name'] : '') ?: (isset($userMap[(int)$c['assigned_user_id']]) ? $userMap[(int)$c['assigned_user_id']]['username'] : 'Chưa gán'));
                        $typeLabel = $isFlex ? 'Xoay ca linh hoạt' : ($isOneOnOne ? 'Lớp 1-1' : 'Cố định');
                        $typeStyle = $isFlex ? '#fef3c7; color:#d97706;' : ($isOneOnOne ? '#e0f2fe; color:#0369a1;' : '#d1fae5; color:#065f46;');

                        $stList = $studentsByClass[$classId] ?? [];
                        $editPayload = [
                            'id' => (int)$c['id'],
                            'class_name' => $c['class_name'] ?? '',
                            'class_type' => $c['class_type'] ?? 'fixed',
                            'total_sessions' => (int)($c['total_sessions'] ?? 0),
                            'assigned_user_id' => (int)($c['assigned_user_id'] ?? 0),
                            'start_date' => $c['start_date'] ?? '',
                            'slot_time' => $c['slot_time'] ?? '',
                            'schedule_days' => array_values(array_filter(array_map('trim', explode(',', (string)($c['schedule_days'] ?? ''))))),
                        ];
                    ?>
                    <tr class="class-row"
                        data-status="<?= htmlspecialchars($c['status']) ?>"
                        data-type="<?= htmlspecialchars($c['class_type'] ?? 'fixed') ?>"
                        data-teacher-id="<?= (int)($c['assigned_user_id'] ?? 0) ?>"
                        data-search="<?= htmlspecialchars(strtolower(trim(($c['class_name'] ?? '') . ' ' . $teacherName . ' ' . ($c['schedule_days'] ?? '') . ' ' . ($c['slot_time'] ?? '') . ' ' . ($c['status'] ?? '') . ' ' . $typeLabel))) ?>">
                        <td class="class-name"><strong><?= htmlspecialchars($c['class_name']) ?></strong></td>
                        <td><span class="badge" style="background:<?= $typeStyle ?>"><?= htmlspecialchars($typeLabel) ?></span></td>
                        <td class="class-teacher"><?= htmlspecialchars($teacherName) ?></td>
                        <td>
                            <?php if ($isFlex): ?>
                                <span style="color:#64748b; font-style:italic;">Xếp tay tự do</span>
                            <?php else: ?>
                                <small>Thứ: <?= htmlspecialchars($c['schedule_days']) ?> (<?= htmlspecialchars($c['slot_time']) ?>)</small>
                            <?php endif; ?>
                        </td>
                        <td><b><?= htmlspecialchars($c['total_sessions']) ?></b> buổi</td>
                        <td><span class="badge badge-<?= strtolower($c['status']) ?>"><?= htmlspecialchars($c['status']) ?></span></td>

                        <td style="text-align:center;">
                            <button type="button" class="btn" id="btnCountClass-<?= $c['id'] ?>" style="padding:4px 8px; font-size:0.8rem; background:#4f46e5;" onclick='openViewStudentsModal(<?= $c['id'] ?>, <?= json_encode($c['class_name']) ?>, <?= json_encode($stList) ?>)'>Xem (<?= count($stList) ?>)</button>
                        </td>

                        <td style="text-align:center;">
                            <?php if ($isFlex): ?>
                                <button type="button" class="btn" style="padding:4px 8px; font-size:0.8rem; background:#0f766e;" onclick='openFlexibleScheduleModal(<?= $c['id'] ?>, <?= json_encode($c['class_name']) ?>, <?= json_encode($sessions) ?>, <?= json_encode($slotOptions) ?>)'>Xếp lịch thủ công</button>
                            <?php else: ?>
                                <span style="color:#cbd5e1;">Tự động</span>
                            <?php endif; ?>
                        </td>

                        <td class="action-links">
                            <button type="button" class="btn" style="padding:6px 10px; font-size:0.85rem; background:#0f766e;" onclick='openEditClassModal(<?= htmlspecialchars(json_encode($editPayload, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>)'>Sửa/Sắp lại</button>
                            <select class="status-select" onchange="window.location.href='add_class.php?action=' + this.value + '&id=<?= $c['id'] ?>'">
                                <option value="Active" <?= $c['status'] === 'Active' ? 'selected' : '' ?>>Hoạt động</option>
                                <option value="Paused" <?= $c['status'] === 'Paused' ? 'selected' : '' ?>>Tạm dừng</option>
                                <option value="Closed" <?= $c['status'] === 'Closed' ? 'selected' : '' ?>>Đóng lớp</option>
                            </select>
                            <a href="add_class.php?delete=<?= $c['id'] ?>" class="btn-delete" style="padding:6px 10px; font-size:0.85rem;" onclick="return confirm('Xóa lớp này?')">Xóa</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div id="classNoResultsMessage" class="no-results" style="display:none;">Không tìm thấy lớp nào phù hợp với bộ lọc.</div>
        </div>
    </div>

    <script>
        function openModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modalId === 'addClassModal') {
                const alertBox = document.getElementById('addClassAlertMessage');
                if (alertBox) alertBox.style.display = 'none';
            }
            if (modal) modal.style.display = 'flex';
        }

        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) modal.style.display = 'none';
        }

        const syncApForm = document.getElementById('syncApForm');
        function resetSyncLoadingState() {
            const overlay = document.getElementById('syncLoadingOverlay');
            const button = document.getElementById('syncApButton');
            if (overlay) {
                overlay.classList.remove('is-visible');
                overlay.setAttribute('aria-hidden', 'true');
            }
            if (button) {
                button.disabled = false;
                button.innerText = 'Cap nhat lop & hoc vien tu AP';
            }
        }

        window.addEventListener('pageshow', resetSyncLoadingState);

        if (syncApForm) {
            syncApForm.addEventListener('submit', function(e) {
                if (!confirm('Cap nhat lop va hoc vien moi tu AP ngay bay gio?')) {
                    e.preventDefault();
                    return;
                }

                const overlay = document.getElementById('syncLoadingOverlay');
                const button = document.getElementById('syncApButton');
                if (overlay) {
                    overlay.classList.add('is-visible');
                    overlay.setAttribute('aria-hidden', 'false');
                }
                if (button) {
                    button.disabled = true;
                    button.innerText = 'Dang cap nhat...';
                }
            });
        }

        function toggleClassType(type) {
            const fixedGroup = document.getElementById('fixed-class-only-options');
            if (type === 'flexible') {
                fixedGroup.style.display = 'none';
                document.getElementById('start_date').required = false;
                document.getElementById('slot_time').required = false;
            } else {
                fixedGroup.style.display = 'block';
                document.getElementById('start_date').required = true;
                document.getElementById('slot_time').required = true;
            }
        }

        function toggleEditClassType(type) {
            const fixedGroup = document.getElementById('edit-fixed-class-options');
            const startDate = document.getElementById('edit_start_date');
            const slotTime = document.getElementById('edit_slot_time');
            if (type === 'flexible') {
                fixedGroup.style.display = 'none';
                startDate.required = false;
                slotTime.required = false;
            } else {
                fixedGroup.style.display = 'block';
                startDate.required = true;
                slotTime.required = true;
            }
        }

        function openEditClassModal(classData) {
            const form = document.getElementById('editClassForm');
            if (!form) return;

            form.querySelector('#edit_class_id').value = classData.id || '';
            form.querySelector('#edit_class_name').value = classData.class_name || '';
            form.querySelector('#edit_total_sessions').value = classData.total_sessions || '';
            form.querySelector('#edit_assigned_user_id').value = classData.assigned_user_id || '';
            form.querySelector('#edit_start_date').value = (classData.start_date || '').substring(0, 10);
            form.querySelector('#edit_slot_time').value = classData.slot_time || '';

            const classType = classData.class_type || 'fixed';
            const typeInput = form.querySelector(`input[name="class_type"][value="${classType}"]`);
            if (typeInput) typeInput.checked = true;

            const days = Array.isArray(classData.schedule_days) ? classData.schedule_days : [];
            form.querySelectorAll('input[name="days[]"]').forEach(input => {
                input.checked = days.includes(input.value);
            });

            toggleEditClassType(classType);
            openModal('editClassModal');
        }

        const editClassForm = document.getElementById('editClassForm');
        if (editClassForm) {
            editClassForm.addEventListener('submit', function(e) {
                if (!confirm('Luu thay doi va sap lich lai tu dau cho lop nay? Lich chinh tay/cu se bi xoa.')) {
                    e.preventDefault();
                }
            });
        }

        document.getElementById('addClassForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const alertBox = document.getElementById('addClassAlertMessage');
            alertBox.style.display = 'none';

            try {
                const response = await fetch('add_class.php?api=add_class', {
                    method: 'POST',
                    body: new FormData(this)
                });
                const responseText = await response.text();
                let result;
                try {
                    result = JSON.parse(responseText);
                } catch (parseError) {
                    throw new Error(responseText.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim() || 'Phản hồi từ máy chủ không hợp lệ.');
                }

                alertBox.className = result.success ? 'alert-success' : 'alert-error';
                alertBox.innerText = result.message || 'Đã xử lý yêu cầu.';
                alertBox.style.display = 'block';

                if (result.success) {
                    setTimeout(() => window.location.href = 'add_class.php', 650);
                }
            } catch (error) {
                alertBox.className = 'alert-error';
                alertBox.innerText = error.message || 'Không thể gửi yêu cầu. Vui lòng thử lại.';
                alertBox.style.display = 'block';
            }
        });

        function openFlexibleScheduleModal(classId, className, sessions, slotOptions) {
            document.getElementById('flexClassNameTitle').innerText = className;
            const container = document.getElementById('flexibleSessionsContainer');
            container.innerHTML = '';

            sessions.forEach((session, index) => {
                const displayDate = session.display_date ? session.display_date : '';
                const displaySlot = session.display_date ? session.display_slot : '';

                let slotOptionsHtml = `<option value="">-- Chọn ca --</option>`;
                slotOptions.forEach(opt => {
                    slotOptionsHtml += `<option value="${opt}" ${displaySlot === opt ? 'selected' : ''}>${opt}</option>`;
                });

                container.innerHTML += `
                    <form method="POST" action="add_class.php" class="flex-session-row">
                        <input type="hidden" name="flex_class_id" value="${classId}">
                        <input type="hidden" name="flex_session_index" value="${index}">
                        <div style="font-weight:600; width:80px;">Buổi ${index + 1}</div>
                        <div>
                            <input type="date" name="flex_date" value="${displayDate}" required style="padding:5px; border:1px solid #cbd5e1; border-radius:4px;">
                        </div>
                        <div>
                            <select name="flex_slot" required style="padding:5px; border:1px solid #cbd5e1; border-radius:4px; width:160px;">
                                ${slotOptionsHtml}
                            </select>
                        </div>
                        <div>
                            <button type="submit" name="submit_flexible_schedule" class="btn" style="padding:5px 10px; font-size:0.8rem;">Gán ca</button>
                        </div>
                    </form>
                `;
            });

            openModal('flexibleScheduleModal');
        }

        window.addEventListener('click', function(e) {
            if (e.target.classList.contains('custom-modal')) e.target.style.display = 'none';
        });

        let localModalStudents = [];
        let currentViewingClassId = null;

        function openViewStudentsModal(classId, className, students) {
            currentViewingClassId = classId;
            localModalStudents = students;
            document.getElementById('modalClassIdInput').value = classId;
            document.getElementById('modalClassNameTitle').innerText = className;
            document.getElementById('modalAlertMessage').style.display = 'none';
            renderModalStudentTable();
            switchAddMethod('available');
            openModal('viewStudentsModal');
        }

        function renderModalStudentTable() {
            const tbody = document.getElementById('modalStudentListBody');
            tbody.innerHTML = '';
            if (localModalStudents.length > 0) {
                localModalStudents.forEach(st => {
                    tbody.innerHTML += `<tr><td style='padding:6px;'><strong>${st.student_name}</strong></td><td style='color:gray;'>${st.phone}</td></tr>`;
                });
            } else {
                tbody.innerHTML = '<tr><td style="color:gray;font-style:italic;padding:10px;">Chưa có học viên.</td></tr>';
            }
        }

        function switchAddMethod(method) {
            document.getElementById('addMethodInput').value = method;
            if (method === 'available') {
                document.getElementById('tabAvailableBtn').classList.add('active');
                document.getElementById('tabNewBtn').classList.remove('active');
                document.getElementById('methodAvailableGroup').style.display = 'block';
                document.getElementById('methodNewGroup').style.display = 'none';
            } else {
                document.getElementById('tabNewBtn').classList.add('active');
                document.getElementById('tabAvailableBtn').classList.remove('active');
                document.getElementById('methodNewGroup').style.display = 'block';
                document.getElementById('methodAvailableGroup').style.display = 'none';
            }
        }

        document.getElementById('quickAddStudentForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const alertBox = document.getElementById('modalAlertMessage');
            alertBox.style.display = 'none';
            const response = await fetch('add_class.php?api=quick_add', { method: 'POST', body: new FormData(this) });
            const result = await response.json();

            if (result.success) {
                alertBox.className = 'alert-success';
                alertBox.innerText = result.message;
                alertBox.style.display = 'block';
                localModalStudents.push(result.student);
                renderModalStudentTable();
                document.getElementById('btnCountClass-' + currentViewingClassId).innerText = `Xem (${localModalStudents.length})`;
                document.getElementById('new_student_name').value = '';
                document.getElementById('new_student_phone').value = '';
                document.getElementById('select_student_id').value = '';
            } else {
                alertBox.className = 'alert-error';
                alertBox.innerText = result.message;
                alertBox.style.display = 'block';
            }
        });

        const classSearchInput = document.getElementById('classSearchInput');
        const classStatusFilter = document.getElementById('classStatusFilter');
        const classTypeFilter = document.getElementById('classTypeFilter');
        const classTeacherFilter = document.getElementById('classTeacherFilter');
        const clearClassFiltersBtn = document.getElementById('clearClassFiltersBtn');
        const classFilterSummary = document.getElementById('classFilterSummary');
        const classNoResultsMessage = document.getElementById('classNoResultsMessage');

        function applyClassFilters() {
            const query = (classSearchInput?.value || '').toLowerCase().trim();
            const status = classStatusFilter?.value || '';
            const type = classTypeFilter?.value || '';
            const teacherId = classTeacherFilter?.value || '';
            const rows = Array.from(document.querySelectorAll('.class-row'));
            let visibleCount = 0;

            rows.forEach(row => {
                const rowSearch = row.dataset.search || '';
                const rowStatus = row.dataset.status || '';
                const rowType = row.dataset.type || 'fixed';
                const rowTeacherId = row.dataset.teacherId || '';
                const isMatch = (!query || rowSearch.includes(query))
                    && (!status || rowStatus === status)
                    && (!type || rowType === type)
                    && (!teacherId || rowTeacherId === teacherId);

                row.style.display = isMatch ? '' : 'none';
                if (isMatch) visibleCount++;
            });

            if (classFilterSummary) {
                classFilterSummary.innerText = `Đang hiển thị ${visibleCount}/${rows.length} lớp.`;
            }
            if (classNoResultsMessage) {
                classNoResultsMessage.style.display = visibleCount === 0 ? 'block' : 'none';
            }
        }

        [classSearchInput, classStatusFilter, classTypeFilter, classTeacherFilter].forEach(control => {
            if (control) control.addEventListener('input', applyClassFilters);
            if (control) control.addEventListener('change', applyClassFilters);
        });

        if (clearClassFiltersBtn) {
            clearClassFiltersBtn.addEventListener('click', () => {
                if (classSearchInput) classSearchInput.value = '';
                if (classStatusFilter) classStatusFilter.value = '';
                if (classTypeFilter) classTypeFilter.value = '';
                if (classTeacherFilter) classTeacherFilter.value = '';
                applyClassFilters();
            });
        }

        applyClassFilters();
    </script>
</body>
</html>
