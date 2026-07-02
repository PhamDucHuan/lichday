<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$message = '';
$postedDate = $_POST['date'] ?? null;
$requestedDate = $_GET['date'] ?? null;
$attendanceDate = is_string($postedDate) && isValidDateString($postedDate)
    ? $postedDate
    : (is_string($requestedDate) && isValidDateString($requestedDate) ? $requestedDate : date('Y-m-d'));
$selectedClassId = (int)($_GET['class_id'] ?? $_POST['class_id'] ?? 0);
$currentUserId = (int)($_SESSION['user_id'] ?? 0);

function canTakeAttendanceForClass(PDO $db, int $classId, string $attendanceDate, int $userId): bool {
    if ($classId <= 0 || $userId <= 0 || !isValidDateString($attendanceDate)) {
        return false;
    }

    $classStmt = $db->prepare("SELECT * FROM classes WHERE id = ? AND status = 'Active' LIMIT 1");
    $classStmt->execute([$classId]);
    $class = $classStmt->fetch(PDO::FETCH_ASSOC);
    if (!$class) {
        return false;
    }

    $overrideStmt = $db->prepare("SELECT class_id, override_date, new_date, new_slot, new_user_id, action_type FROM class_schedule_overrides WHERE class_id = ?");
    $overrideStmt->execute([$classId]);

    foreach (buildClassSessionDates($class, $overrideStmt->fetchAll(PDO::FETCH_ASSOC)) as $session) {
        if (($session['display_date'] ?? '') !== $attendanceDate) {
            continue;
        }

        $assignedUserId = (int)($session['assigned_user_id'] ?? $class['assigned_user_id'] ?? 0);
        if ($assignedUserId === $userId) {
            return true;
        }
    }

    return false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_attendance_class'])) {
    $selectedClassId = (int)($_POST['class_id'] ?? 0);
    $slotTime = trim($_POST['slot_time'] ?? 'Ca học');
    $attendanceData = $_POST['attendance_data'] ?? [];

    if (!canTakeAttendanceForClass($db, $selectedClassId, $attendanceDate, $currentUserId)) {
        $message = "<p class='error'>Bạn không có quyền điểm danh lớp này. Chỉ giáo viên được gán cho buổi học mới được điểm danh.</p>";
        $selectedClassId = 0;
    } elseif ($selectedClassId > 0 && !empty($attendanceData)) {
        foreach ($attendanceData as $studentId => $status) {
            $studentId = (int)$studentId;
            $status = $status === 'Absent' ? 'Absent' : 'Present';

            saveAttendanceRecord($db, $selectedClassId, $studentId, $attendanceDate, $slotTime, $status);
        }

        $message = "<p class='success'>Đã lưu điểm danh lớp ngày " . date('d/m/Y', strtotime($attendanceDate)) . " thành công.</p>";
    } else {
        $message = "<p class='error'>Vui lòng chọn lớp và học viên cần điểm danh.</p>";
    }
}

$classStmt = $db->prepare("
    SELECT DISTINCT c.*
    FROM classes c
    LEFT JOIN class_schedule_overrides o
        ON o.class_id = c.id
       AND o.action_type = 'move'
       AND o.new_user_id = ?
    WHERE c.status = 'Active'
      AND (c.assigned_user_id = ? OR o.class_id IS NOT NULL)
");
$classStmt->execute([$currentUserId, $currentUserId]);
$classes = $classStmt->fetchAll(PDO::FETCH_ASSOC);
$classIds = array_map(static fn($class) => (int)$class['id'], $classes);
$classPlaceholders = !empty($classIds) ? implode(',', array_fill(0, count($classIds), '?')) : '';

$overrideRows = [];
if (!empty($classIds)) {
    $overrideStmt = $db->prepare("SELECT class_id, override_date, new_date, new_slot, new_user_id, action_type FROM class_schedule_overrides WHERE class_id IN ($classPlaceholders)");
    $overrideStmt->execute($classIds);
    $overrideRows = $overrideStmt->fetchAll(PDO::FETCH_ASSOC);
}
$slotsDefinitions = getTeachingSlotOptions($db);
$users = $db->query("SELECT id, username, full_name FROM users")->fetchAll(PDO::FETCH_ASSOC);

$userMap = [];
foreach ($users as $user) {
    $userMap[(int)$user['id']] = $user['full_name'] ?: $user['username'];
}

$studentCountByClass = [];
if (!empty($classIds)) {
    $studentCountStmt = $db->prepare("SELECT class_id, COUNT(*) AS total FROM student_class WHERE class_id IN ($classPlaceholders) GROUP BY class_id");
    $studentCountStmt->execute($classIds);
    foreach ($studentCountStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $studentCountByClass[(int)$row['class_id']] = (int)$row['total'];
    }
}

$attendanceCountByClass = [];
if (!empty($classIds)) {
    $attendanceCountStmt = $db->prepare("SELECT class_id, COUNT(*) AS total FROM attendance WHERE attendance_date = ? AND class_id IN ($classPlaceholders) GROUP BY class_id");
    $attendanceCountStmt->execute(array_merge([$attendanceDate], $classIds));
    foreach ($attendanceCountStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $attendanceCountByClass[(int)$row['class_id']] = (int)$row['total'];
    }
}

$todayClasses = [];
foreach ($classes as $class) {
    $effectiveSessions = buildClassSessionDates($class, $overrideRows);
    foreach ($effectiveSessions as $session) {
        if ($session['display_date'] === $attendanceDate) {
            $slotCode = extractTeachingSlotCode($session['display_slot'], $slotsDefinitions) ?: 'Khác';

            $classId = (int)$class['id'];
            $assignedUserId = (int)($session['assigned_user_id'] ?? $class['assigned_user_id'] ?? 0);
            if ($assignedUserId !== $currentUserId) {
                continue;
            }

            $todayClasses[] = [
                'class_id' => $classId,
                'class_name' => $class['class_name'],
                'slot_code' => $slotCode,
                'slot_label' => $session['display_slot'],
                'teacher_name' => $userMap[$assignedUserId] ?? 'Chưa gán',
                'student_count' => $studentCountByClass[$classId] ?? 0,
                'attendance_count' => $attendanceCountByClass[$classId] ?? 0,
            ];
        }
    }
}

usort($todayClasses, static function($a, $b) {
    $slotCompare = strcmp($a['slot_code'], $b['slot_code']);
    return $slotCompare !== 0 ? $slotCompare : strcmp($a['class_name'], $b['class_name']);
});

$selectedClassInfo = null;
foreach ($todayClasses as $classInfo) {
    if ((int)$classInfo['class_id'] === $selectedClassId) {
        $selectedClassInfo = $classInfo;
        break;
    }
}

$studentsInSelectedClass = [];
$attendanceStatusByStudent = [];

if ($selectedClassInfo) {
    $studentStmt = $db->prepare("
        SELECT s.id, s.student_name, s.phone
        FROM student_class sc
        JOIN students s ON s.id = sc.student_id
        WHERE sc.class_id = ?
        ORDER BY s.student_name ASC
    ");
    $studentStmt->execute([$selectedClassId]);
    $studentsInSelectedClass = $studentStmt->fetchAll(PDO::FETCH_ASSOC);

    $attendanceStmt = $db->prepare("
        SELECT student_id, status
        FROM attendance
        WHERE attendance_date = ? AND class_id = ?
    ");
    $attendanceStmt->execute([$attendanceDate, $selectedClassId]);
    foreach ($attendanceStmt->fetchAll(PDO::FETCH_ASSOC) as $attendanceRow) {
        $attendanceStatusByStudent[(int)$attendanceRow['student_id']] = $attendanceRow['status'];
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Điểm Danh Học Viên</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap"></noscript>
    <link rel="stylesheet" href="../CSS/style.css?v=sidebar-fix-3">
    <style>
        .attendance-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px; }
        .attendance-class-card { position: relative; background: #fff; border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 18px; box-shadow: var(--shadow-sm); display: flex; flex-direction: column; gap: 14px; }
        .attendance-class-card:hover { border-color: var(--primary); box-shadow: var(--shadow-md); }
        .attendance-card-title { margin: 0; color: var(--primary); font-size: 1.05rem; }
        .attendance-done-badge { position: absolute; top: 12px; right: 12px; background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; border-radius: 999px; padding: 5px 10px; font-size: 0.78rem; font-weight: 800; }
        .attendance-meta-grid { display: grid; gap: 8px; }
        .attendance-meta-row { display: flex; justify-content: space-between; gap: 12px; color: var(--text-muted); font-size: 0.92rem; }
        .attendance-meta-row strong { color: var(--text-main); text-align: right; }
        .attendance-slot-badge { display: inline-flex; width: fit-content; background: var(--primary-light); color: var(--primary); padding: 5px 10px; border-radius: 999px; font-weight: 700; font-size: 0.85rem; }
        .attendance-section { margin-bottom: 24px; background: #fff; padding: 20px; border-radius: var(--radius-md); border: 1px solid var(--border-color); box-shadow: var(--shadow-sm); }
        .attendance-class-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 14px; border-bottom: 2px solid var(--primary-light); padding-bottom: 14px; margin-bottom: 16px; flex-wrap: wrap; }
        .attendance-class-title { font-size: 1.15rem; color: var(--text-main); font-weight: 700; }
        .attendance-status-options { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; min-width: 220px; }
        .attendance-status-option input { position: absolute; opacity: 0; pointer-events: none; }
        .attendance-status-option span { display: flex; align-items: center; justify-content: center; min-height: 38px; padding: 8px 12px; border-radius: var(--radius-sm); border: 1px solid var(--border-color); background: #fff; color: var(--text-muted); cursor: pointer; font-weight: 700; font-size: 0.9rem; transition: all 0.2s ease; box-sizing: border-box; }
        .attendance-status-option input:checked + .status-present { color: #065f46; background-color: #d1fae5; border-color: #a7f3d0; }
        .attendance-status-option input:checked + .status-absent { color: #b91c1c; background-color: #fee2e2; border-color: #fca5a5; }
        @media (max-width: 760px) {
            .attendance-meta-row { flex-direction: column; gap: 2px; }
            .attendance-meta-row strong { text-align: left; }
        }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/sidebar.php'; ?>

    <div class="main-content">
        <div class="header-wrapper">
            <div>
                <h2>Điểm Danh Học Viên</h2>
                <span style="font-size: 0.85rem; color: var(--text-muted);">Chọn ngày, xem danh sách lớp trong ngày rồi bấm vào lớp cần điểm danh</span>
            </div>
        </div>

        <?= $message ?>

        <div class="card" style="margin-bottom: 24px; padding: 20px;">
            <form method="GET" id="date-filter-form">
                <div style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
                    <div style="flex: 1; min-width: 220px; max-width: 300px;">
                        <label style="display:block; margin-bottom:6px; font-weight:500;">Chọn ngày điểm danh:</label>
                        <input type="date" name="date" value="<?= htmlspecialchars($attendanceDate) ?>" onchange="this.form.submit()" style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: var(--radius-sm);">
                    </div>
                    <div style="margin-top: 22px;">
                        <button type="submit" class="btn">Tải lịch ngày này</button>
                    </div>
                </div>
            </form>
        </div>

        <?php if ($selectedClassInfo): ?>
            <div style="margin-bottom: 16px;">
                <a class="btn" href="attendance.php?date=<?= urlencode($attendanceDate) ?>" style="background:#f8fafc; color:var(--text-main); border:1px solid var(--border-color); box-shadow:none;">← Quay lại danh sách lớp</a>
            </div>

            <form method="POST">
                <input type="hidden" name="class_id" value="<?= (int)$selectedClassInfo['class_id'] ?>">
                <input type="hidden" name="date" value="<?= htmlspecialchars($attendanceDate) ?>">
                <input type="hidden" name="slot_time" value="<?= htmlspecialchars($selectedClassInfo['slot_label']) ?>">

                <div class="attendance-section">
                    <div class="attendance-class-header">
                        <div>
                            <div class="attendance-class-title">Lớp: <span style="color: var(--primary);"><?= htmlspecialchars($selectedClassInfo['class_name']) ?></span></div>
                            <div style="margin-top:8px; color:var(--text-muted);">
                                Giáo viên: <strong style="color:var(--text-main);"><?= htmlspecialchars($selectedClassInfo['teacher_name']) ?></strong>
                                · Học viên: <strong style="color:var(--text-main);"><?= (int)$selectedClassInfo['student_count'] ?></strong>
                            </div>
                        </div>
                        <div class="attendance-slot-badge"><?= htmlspecialchars($selectedClassInfo['slot_label']) ?></div>
                    </div>

                    <?php if (!empty($studentsInSelectedClass)): ?>
                        <table class="admin-table" style="width: 100%; border: none; margin-top: 0;">
                            <thead>
                                <tr>
                                    <th style="padding: 10px;">Học viên</th>
                                    <th style="padding: 10px;">Số điện thoại</th>
                                    <th style="padding: 10px; text-align: center; width: 300px;">Trạng thái điểm danh</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($studentsInSelectedClass as $st):
                                    $oldStatus = $attendanceStatusByStudent[(int)$st['id']] ?? null;
                                ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($st['student_name']) ?></strong></td>
                                    <td><?= htmlspecialchars($st['phone']) ?></td>
                                    <td style="text-align: center;">
                                        <div class="attendance-status-options">
                                            <label class="attendance-status-option">
                                                <input type="radio" name="attendance_data[<?= (int)$st['id'] ?>]" value="Present" <?= $oldStatus !== 'Absent' ? 'checked' : '' ?>>
                                                <span class="status-present">Đi học</span>
                                            </label>
                                            <label class="attendance-status-option">
                                                <input type="radio" name="attendance_data[<?= (int)$st['id'] ?>]" value="Absent" <?= $oldStatus === 'Absent' ? 'checked' : '' ?>>
                                                <span class="status-absent">Vắng</span>
                                            </label>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p style="color: var(--text-muted); font-size: 0.9rem; font-style: italic; margin: 10px 0 0 0;">Lớp này hiện chưa có học viên nào.</p>
                    <?php endif; ?>
                </div>

                <button type="submit" name="save_attendance_class" class="btn" style="width: 100%; padding: 14px; font-size: 1rem; font-weight: 600;">Lưu điểm danh lớp này</button>
            </form>
        <?php elseif (!empty($todayClasses)): ?>
            <div class="attendance-grid">
                <?php foreach ($todayClasses as $classInfo):
                    $isAttendanceDone = (int)$classInfo['student_count'] > 0
                        && (int)$classInfo['attendance_count'] >= (int)$classInfo['student_count'];
                ?>
                    <div class="attendance-class-card">
                        <?php if ($isAttendanceDone): ?>
                            <span class="attendance-done-badge">Đã điểm danh</span>
                        <?php endif; ?>
                        <div>
                            <span class="attendance-slot-badge"><?= htmlspecialchars($classInfo['slot_label']) ?></span>
                            <h3 class="attendance-card-title" style="margin-top:12px;"><?= htmlspecialchars($classInfo['class_name']) ?></h3>
                        </div>
                        <div class="attendance-meta-grid">
                            <div class="attendance-meta-row">
                                <span>Giáo viên</span>
                                <strong><?= htmlspecialchars($classInfo['teacher_name']) ?></strong>
                            </div>
                            <div class="attendance-meta-row">
                                <span>Số lượng học viên</span>
                                <strong><?= (int)$classInfo['student_count'] ?> học viên</strong>
                            </div>
                            <div class="attendance-meta-row">
                                <span>Ca dạy</span>
                                <strong><?= htmlspecialchars($classInfo['slot_label']) ?></strong>
                            </div>
                            <div class="attendance-meta-row">
                                <span>Đã điểm danh</span>
                                <strong><?= (int)$classInfo['attendance_count'] ?>/<?= (int)$classInfo['student_count'] ?></strong>
                            </div>
                        </div>
                        <a class="btn" href="attendance.php?date=<?= urlencode($attendanceDate) ?>&class_id=<?= (int)$classInfo['class_id'] ?>" style="width:100%; box-sizing:border-box;">Điểm danh lớp này</a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="card" style="text-align: center; padding: 40px;">
                <span style="font-size: 3rem;">📅</span>
                <h3 style="margin-top: 15px; color: var(--text-muted);">Không có ca dạy nào diễn ra vào ngày <?= date('d/m/Y', strtotime($attendanceDate)) ?>.</h3>
                <p style="color: var(--text-muted); font-size: 0.9rem;">Hệ thống tự động đồng bộ theo thời khóa biểu thực tế.</p>
            </div>
        <?php endif; ?>
    </div>

</body>
</html>
