<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_slot'])) {
        $slotCode = strtoupper(trim($_POST['slot_code'] ?? ''));
        $slotLabel = trim($_POST['slot_label'] ?? '');
        $startTime = trim($_POST['start_time'] ?? '');
        $endTime = trim($_POST['end_time'] ?? '');

        if ($slotCode !== '' && $slotLabel !== '' && $startTime !== '' && $endTime !== '') {
            $stmt = $db->prepare('INSERT INTO teaching_slots (slot_code, slot_label, start_time, end_time, is_active) VALUES (?, ?, ?, ?, 1)');
            $stmt->execute([$slotCode, $slotLabel, $startTime, $endTime]);
            $message = "<p class='success'>Đã thêm ca dạy mới.</p>";
        } else {
            $message = "<p class='error'>Vui lòng điền đầy đủ thông tin.</p>";
        }
    } elseif (isset($_POST['toggle_slot'])) {
        $id = (int)($_POST['slot_id'] ?? 0);
        $stmt = $db->prepare('UPDATE teaching_slots SET is_active = 1 - is_active WHERE id = ?');
        $stmt->execute([$id]);
        $message = "<p class='success'>Đã đổi trạng thái ca dạy.</p>";
    } elseif (isset($_POST['delete_slot'])) {
        $id = (int)($_POST['slot_id'] ?? 0);
        $stmt = $db->prepare('DELETE FROM teaching_slots WHERE id = ?');
        $stmt->execute([$id]);
        $message = "<p class='success'>Đã xóa ca dạy.</p>";
    }
}

$slots = $db->query('SELECT * FROM teaching_slots ORDER BY start_time, slot_code')->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Quản lý ca dạy</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../CSS/style.css?v=sidebar-fix-3">
</head>
<body>
    <?php require_once __DIR__ . '/sidebar.php'; ?>

    <div class="main-content">
        <div class="header-wrapper">
            <div>
                <h2>Quản lý ca dạy</h2>
                <span style="font-size: 0.85rem; color: var(--text-muted);">Thêm, bật/tắt hoặc xóa các ca học khác nhau.</span>
            </div>
        </div>

        <?= $message ?>

        <div class="card" style="max-width: 700px; margin: 0 auto 24px auto;">
            <h3>Thêm ca dạy mới</h3>
            <form method="POST" class="form-group" style="margin-bottom:0;">
                <input type="hidden" name="add_slot" value="1">
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                    <div>
                        <label>Mã ca</label>
                        <input type="text" name="slot_code" placeholder="VD: S3" required>
                    </div>
                    <div>
                        <label>Tên ca</label>
                        <input type="text" name="slot_label" placeholder="VD: S3 (09:30 - 11:00)" required>
                    </div>
                </div>
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px; margin-top:12px;">
                    <div>
                        <label>Giờ bắt đầu</label>
                        <input type="time" name="start_time" required>
                    </div>
                    <div>
                        <label>Giờ kết thúc</label>
                        <input type="time" name="end_time" required>
                    </div>
                </div>
                <button type="submit" class="btn" style="margin-top:12px;">+ Thêm ca</button>
            </form>
        </div>

        <div class="card" style="max-width: 900px; margin: 0 auto;">
            <h3>Danh sách ca dạy hiện có</h3>
            <table class="table-responsive" style="width:100%; border-collapse: collapse;">
                <thead>
                    <tr>
                        <th style="text-align:left; padding:8px;">Mã ca</th>
                        <th style="text-align:left; padding:8px;">Tên ca</th>
                        <th style="text-align:left; padding:8px;">Thời gian</th>
                        <th style="text-align:left; padding:8px;">Trạng thái</th>
                        <th style="text-align:left; padding:8px;">Hành động</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($slots as $slot): ?>
                    <tr>
                        <td style="padding:8px;"><?= htmlspecialchars($slot['slot_code']) ?></td>
                        <td style="padding:8px;"><?= htmlspecialchars($slot['slot_label']) ?></td>
                        <td style="padding:8px;"><?= htmlspecialchars($slot['start_time']) ?> - <?= htmlspecialchars($slot['end_time']) ?></td>
                        <td style="padding:8px;"><?= (int)$slot['is_active'] === 1 ? 'Đang dùng' : 'Tắt' ?></td>
                        <td style="padding:8px;">
                            <form method="POST" style="display:inline-block; margin-right:6px;">
                                <input type="hidden" name="toggle_slot" value="1">
                                <input type="hidden" name="slot_id" value="<?= (int)$slot['id'] ?>">
                                <button type="submit" class="btn" style="padding:6px 10px;">Bật/Tắt</button>
                            </form>
                            <form method="POST" style="display:inline-block;">
                                <input type="hidden" name="delete_slot" value="1">
                                <input type="hidden" name="slot_id" value="<?= (int)$slot['id'] ?>">
                                <button type="submit" class="btn-delete" style="padding:6px 10px;">Xóa</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
