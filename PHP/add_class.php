<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$message = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_class'])) {
    $className = trim($_POST['class_name']); 
    $startDate = $_POST['start_date'];
    $slotTime = trim($_POST['slot_time'] ?? ''); 
    $totalSessions = (int)$_POST['total_sessions'];
    $scheduleDays = isset($_POST['days']) ? implode(',', $_POST['days']) : '';
    $classType = ($_POST['class_type'] ?? 'fixed') === 'flexible' ? 'flexible' : 'fixed';
    $assignedUserId = (int)($_POST['assigned_user_id'] ?? $_SESSION['user_id'] ?? 0);
    if ($assignedUserId <= 0) {
        $assignedUserId = (int)($_SESSION['user_id'] ?? 0);
    }
    $flexibleSlots = '';
    if ($classType === 'flexible' && isset($_POST['flexible_slots'])) {
        $flexibleSlots = implode(',', array_filter(array_map('trim', (array)$_POST['flexible_slots']), static fn($value) => $value !== ''));
    }

    if (!empty($className) && !empty($startDate) && !empty($scheduleDays) && $totalSessions > 0 && ($classType === 'fixed' ? $slotTime !== '' : $flexibleSlots !== '')) {
        $stmt = $db->prepare("INSERT INTO classes (class_name, start_date, schedule_days, slot_time, total_sessions, status, class_type, flexible_slots, assigned_user_id) VALUES (?, ?, ?, ?, ?, 'Active', ?, ?, ?)");
        $stmt->execute([$className, $startDate, $scheduleDays, $slotTime, $totalSessions, $classType, $flexibleSlots, $assignedUserId]);
        $message = "<p class='success'>✓ Đã khởi tạo lớp học và xếp lịch tự động thành công!</p>";
    } else {
        $message = "<p class='error'>⚠ Vui lòng điền đầy đủ các thông tin bắt buộc!</p>";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['manual_schedule'])) {
    $classId = (int)($_POST['class_id'] ?? 0);
    $manualDate = trim($_POST['manual_date'] ?? '');
    $manualSlot = trim($_POST['manual_slot'] ?? '');

    if ($classId > 0 && $manualDate !== '' && $manualSlot !== '') {
        $stmt = $db->prepare('UPDATE classes SET manual_date = ?, manual_slot = ? WHERE id = ?');
        $stmt->execute([$manualDate, $manualSlot, $classId]);
        $message = "<p class='success'>✓ Đã cập nhật lịch dạy thủ công thành công!</p>";
    } else {
        $message = "<p class='error'>⚠ Vui lòng chọn đầy đủ ngày và ca học!</p>";
    }
}

if (isset($_GET['action']) && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $action = $_GET['action'];
    if (in_array($action, ['Active', 'Paused', 'Closed'])) {
        $stmt = $db->prepare("UPDATE classes SET status = ? WHERE id = ?");
        $stmt->execute([$action, $id]);
        header('Location: add_class.php');
        exit;
    }
}

if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $db->prepare("DELETE FROM classes WHERE id = ?");
    $stmt->execute([$id]);
    header('Location: add_class.php'); 
    exit;
}

$classes = $db->query("SELECT * FROM classes ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$users = $db->query("SELECT id, username, full_name FROM users ORDER BY full_name, username")->fetchAll(PDO::FETCH_ASSOC);
$userMap = [];
foreach ($users as $user) {
    $userMap[(int)$user['id']] = $user;
}

$slotRows = getTeachingSlotOptions($db);
$slotOptions = array_map(static fn($slot) => $slot['slot_label'], $slotRows);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Cấu Hình Lớp Học Nội Bộ</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../CSS/style.css">
    <style>
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 0.8rem; font-weight: 600; text-transform: uppercase; }
        .badge-active { background: #d1fae5; color: #065f46; }
        .badge-paused { background: #fef3c7; color: #92400e; }
        .badge-closed { background: #f1f5f9; color: #475569; }
        .action-links { display: flex; gap: 8px; align-items: center; }
        .status-select { padding: 6px 10px; border-radius: var(--radius-sm); border: 1px solid var(--border-color); font-size: 0.85rem; background: #fff; cursor: pointer; font-weight: 500; }
        
        /* CSS chống tràn cho cột Giờ Dạy */
        .truncated-cell {
            max-width: 160px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .truncated-cell:hover {
            white-space: normal;
            overflow: visible;
            word-break: break-word;
            background: #fafafa;
        }
    </style>
</head>
<body>

    <div class="sidebar">
        <div class="sidebar-brand">Lịch Dạy Nội Bộ</div>
        <ul class="sidebar-menu">
            <li><a href="../HTML/index.php">📅 Lịch Dạy Của Tôi</a></li>
            <li><a href="view_others.php">🔍 Xem Lịch Người Khác</a></li>
            <li class="active"><a href="add_class.php">➕ Thêm Lớp & Xếp Lịch</a></li>
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
                <h2>Cấu Hình Lớp Học</h2>
                <span style="font-size: 0.85rem; color: var(--text-muted);">Thêm mới khóa dạy hoặc thay đổi trạng thái tiến độ</span>
            </div>
        </div>
        
        <?= $message ?>

        <div class="card" style="max-width: 680px; margin: 0 auto 40px auto;">
            <h3>Thêm Lớp Dạy & Lập Lịch Tự Động</h3>
            <form action="add_class.php" method="POST">
                <div class="form-group">
                    <label>Mã / Tên Lớp Học:</label>
                    <input type="text" name="class_name" placeholder="Ví dụ: THNC.2606.04" required>
                </div>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                    <div class="form-group">
                        <label>Ngày Khai Giảng (Bắt đầu):</label>
                        <input type="date" name="start_date" required>
                    </div>
                    <div class="form-group">
                        <label>Tổng Số Buổi Dạy Của Lớp:</label>
                        <input type="number" name="total_sessions" min="1" placeholder="Ví dụ: 12" required>
                    </div>
                </div>

                <div class="form-group">
                    <label>Loại lớp học:</label>
                    <div class="checkbox-group" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:8px;">
                        <label><input type="radio" name="class_type" value="fixed" checked onchange="toggleClassType(this.value)"> Lớp học cố định</label>
                        <label><input type="radio" name="class_type" value="flexible" onchange="toggleClassType(this.value)"> Lớp học xoay ca linh hoạt</label>
                    </div>
                </div>

                <div class="form-group">
                    <label>Người dạy / Gắn lớp cho:</label>
                    <select name="assigned_user_id" required>
                        <?php foreach ($users as $user): ?>
                            <option value="<?= (int)$user['id'] ?>" <?= ((int)$user['id'] === (int)($_SESSION['user_id'] ?? 0)) ? 'selected' : '' ?>><?= htmlspecialchars($user['full_name'] ?: $user['username']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" id="fixed-slot-group">
                    <label>Khung Giờ Dạy (Ca học cố định):</label>
                    <select name="slot_time">
                        <option value="" disabled selected>-- Chọn ca dạy phù hợp --</option>
                        <?php foreach ($slotRows as $slot): ?>
                            <option value="<?= htmlspecialchars($slot['slot_label']) ?>"><?= htmlspecialchars($slot['slot_label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" id="flexible-slot-group" style="display:none;">
                    <label>Danh sách ca có thể xoay cho lớp linh hoạt:</label>
                    <div class="checkbox-group">
                        <?php foreach ($slotRows as $slot): ?>
                            <label><input type="checkbox" name="flexible_slots[]" value="<?= htmlspecialchars($slot['slot_label']) ?>"> <?= htmlspecialchars($slot['slot_label']) ?></label>
                        <?php endforeach; ?>
                    </div>
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

                <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:8px;">
                    <a href="manage_slots.php" class="btn" style="background:#0f766e;">+ Thêm ca dạy khác</a>
                    <button type="submit" name="add_class" class="btn" style="flex:1; padding: 12px;">Khởi Tạo Khóa Học</button>
                </div>
            </form>
        </div>

        <script>
            function toggleClassType(type) {
                const fixedSlotGroup = document.getElementById('fixed-slot-group');
                const flexibleSlotGroup = document.getElementById('flexible-slot-group');
                if (!fixedSlotGroup || !flexibleSlotGroup) return;
                if (type === 'flexible') {
                    fixedSlotGroup.style.display = 'none';
                    flexibleSlotGroup.style.display = 'block';
                } else {
                    fixedSlotGroup.style.display = 'block';
                    flexibleSlotGroup.style.display = 'none';
                }
            }
            document.addEventListener('DOMContentLoaded', () => {
                const selectedType = document.querySelector('input[name="class_type"]:checked');
                if (selectedType) toggleClassType(selectedType.value);
            });
        </script>

        <div class="card" style="max-width: 900px; margin: 0 auto 24px auto;">
            <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:8px;">
                <h3 style="margin-top: 0; margin-bottom: 0;">Sửa lịch dạy thủ công</h3>
                <a href="manual_schedule.php" class="btn" style="padding: 8px 12px;">🗓 Mở trang xếp lịch thủ công</a>
            </div>
            <p class="permission-helper">Nếu có lớp cần đổi sang ngày/ca khác, bạn có thể ghi đè lịch thủ công ở đây.</p>
            <form method="POST" class="form-group">
                <div style="display:grid; grid-template-columns: 1.2fr 1fr 1fr auto; gap: 12px; align-items:end; flex-wrap:wrap;">
                    <div>
                        <label>Chọn lớp</label>
                        <select name="class_id" required>
                            <option value="">-- Chọn lớp --</option>
                            <?php foreach ($classes as $c): ?>
                                <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['class_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>Ngày dạy mới</label>
                        <input type="date" name="manual_date" required>
                    </div>
                    <div>
                        <label>Ca học mới</label>
                        <select name="manual_slot" required>
                            <option value="">-- Chọn ca --</option>
                            <?php foreach ($slotOptions as $slot): ?>
                                <option value="<?= htmlspecialchars($slot) ?>"><?= htmlspecialchars($slot) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <button type="submit" name="manual_schedule" class="btn">Lưu lịch thủ công</button>
                    </div>
                </div>
            </form>
        </div>

        <h3>Danh Sách Khóa Học Hệ Thống Đang Xử Lý</h3>
        <div style="background: white; border-radius: var(--radius-md); overflow: hidden; box-shadow: var(--shadow-sm); border: 1px solid var(--border-color);">
            <table class="admin-table" style="margin-top: 0; border: none;">
                <thead>
                    <tr>
                        <th>Mã Lớp Học</th>
                        <th>Ngày Bắt Đầu</th>
                        <th>Thứ Cố Định</th>
                        <th>Loại lớp</th>
                        <th>Người dạy</th>
                        <th>Giờ Dạy</th>
                        <th>Thời Lượng</th>
                        <th>Trạng Thái</th>
                        <th>Thao Tác</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($classes as $c): ?>
                    <?php 
                        // Gom toàn bộ text giờ dạy để gắn vào tooltip title khi rê chuột
                        $fullSlotsText = (($c['class_type'] ?? 'fixed') === 'flexible' ? ($c['flexible_slots'] ?: 'Ca xoay') : $c['slot_time']);
                        if (!empty($c['manual_slot'])) {
                            $fullSlotsText .= " [Lịch thủ công: " . $c['manual_slot'] . "]";
                        }
                    ?>
                    <tr>
                        <td><strong style="color: var(--primary);"><?= htmlspecialchars($c['class_name']) ?></strong></td>
                        <td><?= htmlspecialchars($c['start_date']) ?></td>
                        <td><span style="background: #f1f5f9; padding: 4px 8px; border-radius:4px; font-size:0.85rem; font-weight:500;"><?= htmlspecialchars($c['schedule_days']) ?></span></td>
                        <td><span class="badge badge-<?= ($c['class_type'] ?? 'fixed') === 'flexible' ? 'active' : 'paused' ?>"><?= (($c['class_type'] ?? 'fixed') === 'flexible') ? 'Linh hoạt' : 'Cố định' ?></span></td>
                        <td><?= htmlspecialchars(((isset($userMap[(int)$c['assigned_user_id']]) ? $userMap[(int)$c['assigned_user_id']]['full_name'] : '') ?: (isset($userMap[(int)$c['assigned_user_id']]) ? $userMap[(int)$c['assigned_user_id']]['username'] : 'Chưa gán'))) ?></td>
                        
                        <td class="truncated-cell" title="<?= htmlspecialchars($fullSlotsText) ?>">
                            <?= htmlspecialchars(($c['class_type'] ?? 'fixed') === 'flexible' ? ($c['flexible_slots'] ?: 'Ca xoay') : $c['slot_time']) ?>
                            <?php if (!empty($c['manual_slot'])): ?>
                                <br><span style="color:#7c3aed; font-size:0.8rem;">[Lịch thủ công: <?= htmlspecialchars($c['manual_slot']) ?>]</span>
                            <?php endif; ?>
                        </td>
                        
                        <td><b><?= htmlspecialchars($c['total_sessions']) ?></b> buổi</td>
                        <td>
                            <span class="badge badge-<?= strtolower($c['status']) ?>"><?= $c['status'] ?></span>
                        </td>
                        <td class="action-links">
                            <select class="status-select" onchange="window.location.href='add_class.php?action=' + this.value + '&id=<?= $c['id'] ?>'">
                                <option value="Active" <?= $c['status'] === 'Active' ? 'selected' : '' ?>>▶ Hoạt động</option>
                                <option value="Paused" <?= $c['status'] === 'Paused' ? 'selected' : '' ?>>⏸ Tạm dừng</option>
                                <option value="Closed" <?= $c['status'] === 'Closed' ? 'selected' : '' ?>>✖ Đóng lớp</option>
                            </select>
                            
                            <a href="add_class.php?delete=<?= $c['id'] ?>" class="btn-delete" style="padding:6px 10px; font-size:0.85rem;" onclick="return confirm('Bạn chắc chắn muốn xóa lớp này?')">Xóa</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>