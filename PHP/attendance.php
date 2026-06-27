<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$message = '';
$attendanceDate = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

// Xử lý lưu điểm danh khi bấm nút lưu
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_attendance_v2'])) {
    $attendanceData = $_POST['attendance_data'] ?? []; // Mảng chứa [class_id][student_id] => status
    $slotsData = $_POST['slot_time_data'] ?? [];     // Mảng chứa [class_id] => slot_time

    if (!empty($attendanceData)) {
        foreach ($attendanceData as $classId => $students) {
            $slotTime = $slotsData[$classId] ?? 'Ca học';
            foreach ($students as $studentId => $status) {
                // Xóa bản ghi cũ của học viên này trong ngày/lớp này nếu có
                $db->prepare("DELETE FROM attendance WHERE class_id = ? AND student_id = ? AND attendance_date = ?")
                   ->execute([$classId, $studentId, $attendanceDate]);

                // Thêm bản ghi điểm danh mới
                $stmt = $db->prepare("INSERT INTO attendance (class_id, student_id, attendance_date, slot_time, status) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$classId, $studentId, $attendanceDate, $slotTime, $status]);
            }
        }
        $message = "<p class='success'>✓ Đã lưu sổ điểm danh ngày " . date('d/m/Y', strtotime($attendanceDate)) . " thành công! Lịch tịnh tiến bù buổi đã tự động đồng bộ.</p>";
    }
}

// 1. Lấy toàn bộ danh sách lớp học đang Active
$classes = $db->query("SELECT * FROM classes WHERE status = 'Active'")->fetchAll(PDO::FETCH_ASSOC);

// 2. Lấy toàn bộ cấu hình ghi đè lịch thủ công
$overrideRows = $db->query("SELECT class_id, override_date, new_date, new_slot, new_user_id, action_type FROM class_schedule_overrides")->fetchAll(PDO::FETCH_ASSOC);

// 3. Lấy danh sách cấu hình định nghĩa Ca dạy trong hệ thống
$slotsDefinitions = getTeachingSlotOptions($db);

// 4. Tìm kiếm tự động xem hôm nay có những lớp nào học
$todayClasses = [];
foreach ($classes as $class) {
    $effectiveSessions = buildClassSessionDates($class, $overrideRows);
    foreach ($effectiveSessions as $session) {
        // Nếu ca học trùng khớp với ngày đang chọn điểm danh
        if ($session['display_date'] === $attendanceDate) {
            
            // Tìm mã ca học (S1, S2, C1...) tương ứng
            $slotCode = 'Khác';
            foreach ($slotsDefinitions as $slotItem) {
                if (strpos($session['display_slot'], $slotItem['slot_code']) === 0) {
                    $slotCode = $slotItem['slot_code'];
                    break;
                }
            }

            $todayClasses[] = [
                'class_id' => $class['id'],
                'class_name' => $class['class_name'],
                'slot_code' => $slotCode,
                'slot_label' => $session['display_slot']
            ];
        }
    }
}

// 5. Sắp xếp danh sách lớp học tự động tìm được theo thứ tự ca học tăng dần (S1 -> S2 -> C1...)
usort($todayClasses, function($a, $b) {
    return strcmp($a['slot_code'], $b['slot_code']);
});
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Điểm Danh Học Viên Tự Động</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../CSS/style.css">
    <style>
        .attendance-section { margin-bottom: 30px; background: #fff; padding: 20px; border-radius: var(--radius-md); border: 1px solid var(--border-color); box-shadow: var(--shadow-sm); }
        .attendance-class-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid var(--primary-light); padding-bottom: 10px; margin-bottom: 15px; }
        .attendance-class-title { font-size: 1.1rem; color: var(--text-main); font-weight: 700; }
        .attendance-slot-badge { background: var(--primary-light); color: var(--primary); padding: 4px 10px; border-radius: 20px; font-weight: 600; font-size: 0.85rem; }
        
        /* Style Dropbox trạng thái điểm danh */
        .attendance-select { 
            padding: 8px 12px; 
            border-radius: var(--radius-sm); 
            border: 1px solid var(--border-color); 
            font-size: 0.9rem; 
            background: #fff; 
            cursor: pointer; 
            font-weight: 600;
            width: 180px;
        }
        .status-present { color: #065f46; background-color: #d1fae5 !important; border-color: #a7f3d0; }
        .status-absent { color: #b91c1c; background-color: #fee2e2 !important; border-color: #fca5a5; }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-brand">Lịch Dạy Nội Bộ</div>
        <ul class="sidebar-menu">
            <li><a href="../HTML/index.php">📅 Lịch Dạy Của Tôi</a></li>
            <li><a href="view_others.php">🔍 Xem Lịch Người Khác</a></li>
            <li><a href="add_class.php">➕ Thêm Lớp & Xếp Lịch</a></li>
            <li><a href="manage_students.php">👤 Quản lý học viên</a></li>
            <li class="active"><a href="attendance.php">✅ Điểm danh học viên</a></li>
            <li><a href="student_stats.php">📊 Thống kê học viên</a></li>
            <li><a href="manage_slots.php">🕒 Quản lý ca dạy</a></li>
            <li><a href="manual_schedule.php">🗓 Xếp Lịch Thủ Công</a></li>
            <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
            <li><a href="admin_users.php">👤 Quản lý người dùng</a></li>
            <?php endif; ?>
        </ul>
        <div class="sidebar-footer">
            <div class="sidebar-user">
                <div class="sidebar-user-label">Đăng nhập</div>
                <div class="sidebar-user-name"><?= htmlspecialchars($_SESSION['display_name'] ?? $_SESSION['username'] ?? 'Người dùng') ?></div>
            </div>
            <a href="settings.php" class="btn" style="display:block; text-align:center; margin-bottom:10px; background:#1e293b; border:1px solid #334155;">⚙ Cài đặt</a>
            <a href="logout.php" class="btn-delete" style="display: block; text-align: center;">Đăng xuất</a>
        </div>
    </div>

    <div class="main-content">
        <div class="header-wrapper">
            <div>
                <h2>Sổ Điểm Danh Tự Động Theo Ngày</h2>
                <span style="font-size: 0.85rem; color: var(--text-muted);">Hệ thống tự nhận diện các ca học diễn ra trong ngày được chọn</span>
            </div>
        </div>

        <?= $message ?>

        <!-- Khu vực chọn ngày làm việc -->
        <div class="card" style="margin-bottom: 24px; padding: 20px;">
            <form method="GET" id="date-filter-form">
                <div style="display: flex; align-items: center; gap: 15px;">
                    <div style="flex: 1; max-width: 300px;">
                        <label style="display:block; margin-bottom:6px; font-weight:500;">Chọn ngày điểm danh:</label>
                        <input type="date" name="date" value="<?= htmlspecialchars($attendanceDate) ?>" onchange="this.form.submit()" style="width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: var(--radius-sm);">
                    </div>
                    <div style="margin-top: 22px;">
                        <button type="submit" class="btn">Tải lịch ngày này</button>
                    </div>
                </div>
            </form>
        </div>

        <?php if (!empty($todayClasses)): ?>
            <form method="POST">
                <?php foreach ($todayClasses as $classInfo): 
                    $cId = $classInfo['class_id'];
                    // Lấy danh sách học viên thuộc lớp hiện tại
                    $stmtSt = $db->prepare("SELECT s.* FROM students s JOIN student_class sc ON sc.student_id = s.id WHERE sc.class_id = ?");
                    $stmtSt->execute([$cId]);
                    $studentsInClass = $stmtSt->fetchAll(PDO::FETCH_ASSOC);
                ?>
                    <div class="attendance-section">
                        <div class="attendance-class-header">
                            <div class="attendance-class-title">Lớp: <span style="color: var(--primary);"><?= htmlspecialchars($classInfo['class_name']) ?></span></div>
                            <div class="attendance-slot-badge">🕒 <?= htmlspecialchars($classInfo['slot_label']) ?></div>
                        </div>

                        <!-- Lưu trữ ngầm khung giờ hiện hành để truyền dữ liệu lên DB -->
                        <input type="hidden" name="slot_time_data[<?= $cId ?>]" value="<?= htmlspecialchars($classInfo['slot_label']) ?>">

                        <?php if (!empty($studentsInClass)): ?>
                            <table class="admin-table" style="width: 100%; border: none; margin-top: 0;">
                                <thead>
                                    <tr>
                                        <th style="padding: 10px;">Học viên</th>
                                        <th style="padding: 10px;">Số điện thoại</th>
                                        <th style="padding: 10px; text-align: center; width: 300px;">Trạng thái điểm danh</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($studentsInClass as $st): 
                                        // Kiểm tra trạng thái điểm danh cũ (nếu có)
                                        $stmtOld = $db->prepare("SELECT status FROM attendance WHERE class_id = ? AND student_id = ? AND attendance_date = ?");
                                        $stmtOld->execute([$cId, $st['id'], $attendanceDate]);
                                        $oldStatus = $stmtOld->fetchColumn();
                                        
                                        // Gán class màu sắc dựa trên trạng thái hiện tại
                                        $selectClass = ($oldStatus === 'Absent') ? 'status-absent' : 'status-present';
                                    ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($st['student_name']) ?></strong></td>
                                        <td><?= htmlspecialchars($st['phone']) ?></td>
                                        <td style="text-align: center;">
                                            <!-- ĐÃ THAY ĐỔI: Chuyển đổi thành Dropbox select trạng thái màu sắc động -->
                                            <select name="attendance_data[<?= $cId ?>][<?= $st['id'] ?>]" 
                                                    class="attendance-select <?= $selectClass ?>" 
                                                    onchange="updateSelectColor(this)">
                                                <option value="Present" class="status-present" <?= $oldStatus !== 'Absent' ? 'selected' : '' ?>>▶ Đi học</option>
                                                <option value="Absent" class="status-absent" <?= $oldStatus === 'Absent' ? 'selected' : '' ?>>✖ Vắng (Tự bù buổi)</option>
                                            </select>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <p style="color: var(--text-muted); font-size: 0.9rem; font-style: italic; margin: 10px 0 0 0;">Lớp này hiện chưa có học viên nào.</p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <button type="submit" name="save_attendance_v2" class="btn" style="width: 100%; padding: 14px; font-size: 1rem; font-weight: 600;">LƯU SỔ ĐIỂM DANH TOÀN BỘ CÁC CA TRONG NGÀY</button>
            </form>
        <?php else: ?>
            <div class="card" style="text-align: center; padding: 40px;">
                <span style="font-size: 3rem;">🗓️</span>
                <h3 style="margin-top: 15px; color: var(--text-muted);">Không có ca dạy nào diễn ra vào ngày <?= date('d/m/Y', strtotime($attendanceDate)) ?>.</h3>
                <p style="color: var(--text-muted); font-size: 0.9rem;">Hệ thống tự động đồng bộ theo thời khóa biểu thực tế.</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- SCRIPT CHUYỂN ĐỔI MÀU SẮC ĐỘNG KHI THAY ĐỔI DROPBOX TRÊN GIAO DIỆN -->
    <script>
        function updateSelectColor(selectElement) {
            if (selectElement.value === 'Absent') {
                selectElement.classList.remove('status-present');
                selectElement.classList.add('status-absent');
            } else {
                selectElement.classList.remove('status-absent');
                selectElement.classList.add('status-present');
            }
        }
    </script>
</body>
</html>