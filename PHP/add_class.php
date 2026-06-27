<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$message = "";

// 1. Xử lý Thêm lớp học mới
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_class'])) {
    $className = trim($_POST['class_name']); 
    $totalSessions = (int)$_POST['total_sessions'];
    $classType = ($_POST['class_type'] ?? 'fixed') === 'flexible' ? 'flexible' : 'fixed';
    $assignedUserId = (int)($_POST['assigned_user_id'] ?? $_SESSION['user_id'] ?? 0);
    if ($assignedUserId <= 0) { $assignedUserId = (int)($_SESSION['user_id'] ?? 0); }

    if ($classType === 'flexible') {
        // LỚP LINH HOẠT: Không cần ngày khai giảng hay thứ cố định, tạo lớp trống trước
        if (!empty($className) && $totalSessions > 0) {
            $stmt = $db->prepare("INSERT INTO classes (class_name, start_date, schedule_days, slot_time, total_sessions, status, class_type, flexible_slots, assigned_user_id) VALUES (?, NOW(), 'Linh hoạt', 'Xoay ca', ?, 'Active', 'flexible', '', ?)");
            $stmt->execute([$className, $totalSessions, $assignedUserId]);
            $message = "<p class='success'>✓ Đã khởi tạo lớp học xoay ca linh hoạt thành công! Hãy bấm nút 'Xếp lịch bằng tay' tại danh sách lớp để gán ngày học.</p>";
        } else {
            $message = "<p class='error'>⚠ Vui lòng điền tên lớp và số buổi dạy!</p>";
        }
    } else {
        // LỚP CỐ ĐỊNH: Logic xếp lịch tự động cũ
        $startDate = $_POST['start_date'];
        $slotTime = trim($_POST['slot_time'] ?? ''); 
        $scheduleDays = isset($_POST['days']) ? implode(',', $_POST['days']) : '';

        if (!empty($className) && !empty($startDate) && !empty($scheduleDays) && $totalSessions > 0 && $slotTime !== '') {
            $stmt = $db->prepare("INSERT INTO classes (class_name, start_date, schedule_days, slot_time, total_sessions, status, class_type, flexible_slots, assigned_user_id) VALUES (?, ?, ?, ?, ?, 'Active', 'fixed', '', ?)");
            $stmt->execute([$className, $startDate, $scheduleDays, $slotTime, $totalSessions, $assignedUserId]);
            $message = "<p class='success'>✓ Đã khởi tạo lớp học cố định và xếp lịch tự động thành công!</p>";
        } else {
            $message = "<p class='error'>⚠ Vui lòng điền đầy đủ các thông tin bắt buộc cho lớp cố định!</p>";
        }
    }
}

// 2. Xử lý Thêm nhanh học viên bằng AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['api']) && $_GET['api'] === 'quick_add') {
    header('Content-Type: application/json'); $classId = (int)($_POST['modal_class_id_input'] ?? 0); $method = $_POST['add_method'] ?? 'available';
    if ($classId <= 0) { echo json_encode(['success' => false, 'message' => 'Lỗi lớp học']); exit; }
    if ($method === 'available') {
        $studentId = (int)($_POST['select_student_id'] ?? 0);
        if ($studentId > 0) {
            try {
                $db->prepare("INSERT INTO student_class (student_id, class_id) VALUES (?, ?)")->execute([$studentId, $classId]);
                $stStmt = $db->prepare("SELECT student_name, phone FROM students WHERE id = ?"); $stStmt->execute([$studentId]);
                echo json_encode(['success' => true, 'message' => '✓ Ghi danh thành công!', 'student' => $stStmt->fetch(PDO::FETCH_ASSOC)]);
            } catch (Exception $e) { echo json_encode(['success' => false, 'message' => '⚠ Học viên đã có trong lớp!']); }
        }
    } else if ($method === 'new') {
        $name = trim($_POST['new_student_name'] ?? ''); $phone = trim($_POST['new_student_phone'] ?? '');
        if (!empty($name) && !empty($phone)) {
            $db->prepare("INSERT INTO students (student_name, phone) VALUES (?, ?)")->execute([$name, $phone]); $newId = $db->lastInsertId();
            $db->prepare("INSERT INTO student_class (student_id, class_id) VALUES (?, ?)")->execute([$newId, $classId]);
            echo json_encode(['success' => true, 'message' => '✓ Tạo mới thành công!', 'student' => ['student_name' => $name, 'phone' => $phone]]);
        }
    }
    exit;
}

// 3. XỬ LÝ MỚI: Xếp lịch thủ công bằng tay từng ca cụ thể cho lớp Linh hoạt
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_flexible_schedule'])) {
    $classId = (int)$_POST['flex_class_id'];
    $targetIndex = (int)$_POST['flex_session_index'];
    $newDate = trim($_POST['flex_date'] ?? '');
    $newSlot = trim($_POST['flex_slot'] ?? '');

    if ($classId > 0 && $newDate !== '' && $newSlot !== '') {
        $overrideDateIdentifier = "FLEX-ID-{$targetIndex}";
        
        // Xóa cấu hình cũ của buổi này nếu có
        $db->prepare("DELETE FROM class_schedule_overrides WHERE class_id = ? AND override_date = ?")->execute([$classId, $overrideDateIdentifier]);
        
        // Thêm lịch mới bằng tay vào bảng override
        $stmt = $db->prepare("INSERT INTO class_schedule_overrides (class_id, override_date, new_date, new_slot, action_type) VALUES (?, ?, ?, ?, 'move')");
        $stmt->execute([$classId, $overrideDateIdentifier, $newDate, $newSlot]);
        $message = "<p class='success'>✓ Đã gán ngày học thủ công cho ca thành công!</p>";
    }
}

// 4. Xử lý đổi trạng thái lớp và xóa lớp
if (isset($_GET['action']) && isset($_GET['id'])) {
    $db->prepare("UPDATE classes SET status = ? WHERE id = ?")->execute([$_GET['action'], (int)$_GET['id']]);
    header('Location: add_class.php'); exit;
}
if (isset($_GET['delete'])) {
    $db->prepare("DELETE FROM classes WHERE id = ?")->execute([(int)$_GET['delete']]);
    header('Location: add_class.php'); exit;
}

$classes = $db->query("SELECT * FROM classes ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$users = $db->query("SELECT id, username, full_name FROM users ORDER BY full_name, username")->fetchAll(PDO::FETCH_ASSOC);
$allStudents = $db->query("SELECT id, student_name, phone FROM students ORDER BY student_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$overrideRows = $db->query("SELECT * FROM class_schedule_overrides")->fetchAll(PDO::FETCH_ASSOC);

$userMap = []; foreach ($users as $user) { $userMap[(int)$user['id']] = $user; }
$slotRows = getTeachingSlotOptions($db);
$slotOptions = array_map(static fn($slot) => $slot['slot_label'], $slotRows);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Cấu Hình Lớp Học</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../CSS/style.css">
    <style>
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 0.8rem; font-weight: 600; text-transform: uppercase; }
        .badge-active { background: #d1fae5; color: #065f46; }
        .badge-paused { background: #fef3c7; color: #92400e; }
        .badge-closed { background: #f1f5f9; color: #475569; }
        .action-links { display: flex; gap: 8px; align-items: center; }
        .status-select { padding: 6px 10px; border-radius: var(--radius-sm); border: 1px solid var(--border-color); font-size: 0.85rem; background: #fff; cursor: pointer; font-weight: 500; }
        .custom-modal { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.5); display: none; align-items: center; justify-content: center; z-index: 9999; padding: 16px; }
        .custom-modal-content { background: white; width: min(600px, 100%); border-radius: var(--radius-md); padding: 24px; box-shadow: var(--shadow-lg); max-height: 90vh; overflow-y: auto; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid var(--border-color); padding-bottom: 12px; }
        .modal-close-btn { background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-muted); }
        .action-header-bar { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 24px; }
        .search-box-container { background: white; border: 1px solid var(--border-color); padding: 14px 20px; border-radius: var(--radius-md); box-shadow: var(--shadow-sm); margin-bottom: 20px; display: flex; align-items: center; gap: 12px; }
        .search-input { flex: 1; padding: 10px 14px; border: 1px solid var(--border-color); border-radius: var(--radius-sm); font-size: 0.95rem; }
        .tab-btn-group { display: flex; gap: 10px; margin-bottom: 12px; margin-top: 15px; border-top: 1px dashed var(--border-color); padding-top: 15px; }
        .tab-btn { background: #f1f5f9; color: var(--text-main); border: 1px solid var(--border-color); padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 0.85rem; font-weight: 500; }
        .tab-btn.active { background: var(--primary); color: white; border-color: var(--primary); }
        #modalAlertMessage { padding: 10px; border-radius: 4px; font-size: 0.9rem; font-weight: 500; margin-bottom: 12px; display: none; }
        .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
        .alert-error { background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; }
        
        /* CSS danh sách ca của lớp linh hoạt */
        .flex-session-row { display: flex; justify-content: space-between; align-items: center; padding: 10px; border-bottom: 1px solid #f1f5f9; }
        .flex-session-row:hover { background: #f8fafc; }
    </style>
</head>
<body>

    <div class="sidebar">
        <div class="sidebar-brand">Lịch Dạy Nội Bộ</div>
        <ul class="sidebar-menu">
            <li><a href="../HTML/index.php">📅 Lịch Dạy Của Tôi</a></li>
            <li><a href="view_others.php">🔍 Xem Lịch Người Khác</a></li>
            <li class="active"><a href="add_class.php">➕ Thêm Lớp & Xếp Lịch</a></li>
            <li><a href="manage_students.php">👤 Quản lý học viên</a></li>
            <li><a href="attendance.php">✅ Điểm danh học viên</a></li>
            <li><a href="student_stats.php">📊 Thống kê học viên</a></li>
            <li><a href="manage_slots.php">🕒 Quản lý ca dạy</a></li>
            <li><a href="manual_schedule.php">🗓 Xếp Lịch Thủ Công</a></li>
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
                <h2>Cấu Hình & Quản Lý Lớp Học</h2>
                <span style="font-size: 0.85rem; color: var(--text-muted);">Khởi tạo khóa học, sắp xếp tiến độ hoặc tự lập lịch bằng tay</span>
            </div>
        </div>
        
        <?= $message ?>

        <div class="action-header-bar">
            <button class="btn" onclick="openModal('addClassModal')" style="padding: 12px 20px; font-weight:600;">➕ Khởi Tạo Lớp Học Mới</button>
        </div>

        <div id="addClassModal" class="custom-modal">
            <div class="custom-modal-content">
                <div class="modal-header">
                    <h3 style="margin:0;">Thêm Lớp Dạy Hệ Thống</h3>
                    <button class="modal-close-btn" onclick="closeModal('addClassModal')">✕</button>
                </div>
                <form action="add_class.php" method="POST">
                    <div class="form-group">
                        <label>Mã / Tên Lớp Học:</label>
                        <input type="text" name="class_name" placeholder="Ví dụ: THNC.2606.04" required style="width:100%; padding:10px; border:1px solid var(--border-color); border-radius:var(--radius-sm);">
                    </div>
                    
                    <div class="form-group">
                        <label>Loại lớp học:</label>
                        <div class="checkbox-group" style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
                            <label><input type="radio" name="class_type" value="fixed" checked onchange="toggleClassType(this.value)"> Lớp học cố định (Tự sinh lịch)</label>
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
                            <label>Lịch Học Cố Định Hàng Tuần:</label>
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

        <div id="viewStudentsModal" class="custom-modal">
            <div class="custom-modal-content">
                <div class="modal-header">
                    <h3 style="margin:0;">Học viên lớp: <span id="modalClassNameTitle" style="color:var(--primary);"></span></h3>
                    <button class="modal-close-btn" onclick="closeModal('viewStudentsModal')">✕</button>
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
                        <div class="form-group"><input type="text" name="new_student_name" id="new_student_name" placeholder="Tên họ học viên" style="width:100%; padding:10px; border:1px solid var(--border-color); border-radius:var(--radius-sm);"></div>
                        <div class="form-group"><input type="text" name="new_student_phone" id="new_student_phone" placeholder="Số điện thoại" style="width:100%; padding:10px; border:1px solid var(--border-color); border-radius:var(--radius-sm);"></div>
                    </div>
                    <button type="submit" class="btn" style="width:100%;">Ghi danh học viên</button>
                </form>
            </div>
        </div>

        <div id="flexibleScheduleModal" class="custom-modal">
            <div class="custom-modal-content" style="width:min(650px, 100%);">
                <div class="modal-header">
                    <h3 style="margin:0;">🗓️ Xếp lịch thủ công lớp: <span id="flexClassNameTitle" style="color:var(--primary);"></span></h3>
                    <button class="modal-close-btn" onclick="closeModal('flexibleScheduleModal')">✕</button>
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
                        $sessions = buildClassSessionDates($c, $overrideRows);
                        $teacherName = ((isset($userMap[(int)$c['assigned_user_id']]) ? $userMap[(int)$c['assigned_user_id']]['full_name'] : '') ?: (isset($userMap[(int)$c['assigned_user_id']]) ? $userMap[(int)$c['assigned_user_id']]['username'] : 'Chưa gán'));
                        
                        $stmtSt = $db->prepare("SELECT s.student_name, s.phone FROM students s JOIN student_class sc ON sc.student_id = s.id WHERE sc.class_id = ?");
                        $stmtSt->execute([$c['id']]); $stList = $stmtSt->fetchAll(PDO::FETCH_ASSOC);
                    ?>
                    <tr class="class-row">
                        <td class="class-name"><strong><?= htmlspecialchars($c['class_name']) ?></strong></td>
                        <td><span class="badge" style="background:<?= $isFlex ? '#fef3c7; color:#d97706;' : '#d1fae5; color:#065f46;' ?>"><?= $isFlex ? 'Xoay ca linh hoạt' : 'Cố định' ?></span></td>
                        <td class="class-teacher"><?= htmlspecialchars($teacherName) ?></td>
                        <td>
                            <?php if ($isFlex): ?>
                                <span style="color:#64748b; font-style:italic;">Xếp tay tự do</span>
                            <?php else: ?>
                                <small>Thứ: <?= htmlspecialchars($c['schedule_days']) ?> (<?= htmlspecialchars($c['slot_time']) ?>)</small>
                            <?php endif; ?>
                        </td>
                        <td><b><?= htmlspecialchars($c['total_sessions']) ?></b> buổi</td>
                        <td><span class="badge badge-<?= strtolower($c['status']) ?>"><?= $c['status'] ?></span></td>
                        
                        <td style="text-align:center;">
                            <button type="button" class="btn" id="btnCountClass-<?= $c['id'] ?>" style="padding:4px 8px; font-size:0.8rem; background:#4f46e5;" onclick='openViewStudentsModal(<?= $c['id'] ?>, "<?= htmlspecialchars($c['class_name']) ?>", <?= json_encode($stList) ?>)'>👥 Xem (<?= count($stList) ?>)</button>
                        </td>

                        <td style="text-align:center;">
                            <?php if ($isFlex): ?>
                                <button type="button" class="btn" style="padding:4px 8px; font-size:0.8rem; background:#0f766e;" onclick='openFlexibleScheduleModal(<?= $c['id'] ?>, "<?= htmlspecialchars($c['class_name']) ?>", <?= json_encode($sessions) ?>, <?= json_encode($slotOptions) ?>)'>🗓️ Xếp lịch thủ công</button>
                            <?php else: ?>
                                <span style="color:#cbd5e1;">— Tự động —</span>
                            <?php endif; ?>
                        </td>
                        
                        <td class="action-links">
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
        </div>
    </div>

    <script>
        function openModal(modalId) { const modal = document.getElementById(modalId); if (modal) modal.style.display = 'flex'; }
        function closeModal(modalId) { const modal = document.getElementById(modalId); if (modal) modal.style.display = 'none'; }

        // Bật tắt ẩn hiện form nhập liệu linh động dựa vào loại hình lớp
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

        // Bật Modal lập lịch tay cho lớp xoay ca tự do
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

        // Đóng hộp thoại tự động khi nhấn click ra ngoài vùng trắng
        window.addEventListener('click', function(e) { if (e.target.classList.contains('custom-modal')) e.target.style.display = 'none'; });

        // Giao tiếp AJAX thêm học viên nhanh
        let localModalStudents = []; let currentViewingClassId = null;
        function openViewStudentsModal(classId, className, students) {
            currentViewingClassId = classId; localModalStudents = students;
            document.getElementById('modalClassIdInput').value = classId; document.getElementById('modalClassNameTitle').innerText = className;
            document.getElementById('modalAlertMessage').style.display = 'none';
            renderModalStudentTable(); switchAddMethod('available'); openModal('viewStudentsModal');
        }
        function renderModalStudentTable() {
            const tbody = document.getElementById('modalStudentListBody'); tbody.innerHTML = '';
            if (localModalStudents.length > 0) {
                localModalStudents.forEach(st => { tbody.innerHTML += `<tr><td style='padding:6px;'><strong>${st.student_name}</strong></td><td style='color:gray;'>${st.phone}</td></tr>`; });
            } else { tbody.innerHTML = '<tr><td style="color:gray;font-style:italic;padding:10px;">Chưa có học viên.</td></tr>'; }
        }
        function switchAddMethod(method) {
            document.getElementById('addMethodInput').value = method;
            if (method === 'available') {
                document.getElementById('tabAvailableBtn').classList.add('active'); document.getElementById('tabNewBtn').classList.remove('active');
                document.getElementById('methodAvailableGroup').style.display = 'block'; document.getElementById('methodNewGroup').style.display = 'none';
            } else {
                document.getElementById('tabNewBtn').classList.add('active'); document.getElementById('tabAvailableBtn').classList.remove('active');
                document.getElementById('methodNewGroup').style.display = 'block'; document.getElementById('methodAvailableGroup').style.display = 'none';
            }
        }
        document.getElementById('quickAddStudentForm').addEventListener('submit', async function(e) {
            e.preventDefault(); const alertBox = document.getElementById('modalAlertMessage'); alertBox.style.display = 'none';
            const response = await fetch('add_class.php?api=quick_add', { method: 'POST', body: new FormData(this) }); const result = await response.json();
            if (result.success) {
                alertBox.className = 'alert-success'; alertBox.innerText = result.message; alertBox.style.display = 'block';
                localModalStudents.push(result.student); renderModalStudentTable();
                document.getElementById('btnCountClass-' + currentViewingClassId).innerText = `👥 Xem (${localModalStudents.length})`;
                document.getElementById('new_student_name').value = ''; document.getElementById('new_student_phone').value = ''; document.getElementById('select_student_id').value = '';
            } else { alertBox.className = 'alert-error'; alertBox.innerText = result.message; alertBox.style.display = 'block'; }
        });
    </script>
</body>
</html>