<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim($_POST['full_name'] ?? '');
    if ($fullName === '') {
        $error = 'Vui lòng nhập tên hiển thị.';
    } else {
        $stmt = $db->prepare('UPDATE users SET full_name = ? WHERE id = ?');
        $stmt->execute([$fullName, $_SESSION['user_id']]);
        $_SESSION['display_name'] = $fullName;
        $message = 'Cập nhật tên thành công.';
        header('Location: ../HTML/index.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Cài đặt tài khoản</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../CSS/style.css">
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-brand">Lịch Dạy Nội Bộ</div>
        <ul class="sidebar-menu">
            <li><a href="../HTML/index.php">📅 Lịch Dạy Của Tôi</a></li>
            <li><a href="view_others.php">🔍 Xem Lịch Người Khác</a></li>
            <li><a href="add_class.php">➕ Thêm Lớp & Xếp Lịch</a></li>
            <li><a href="manage_slots.php">🕒 Quản lý ca dạy</a></li>
            <li><a href="manual_schedule.php">🗓 Xếp Lịch Thủ Công</a></li>
            <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
            <li><a href="admin_users.php">👤 Quản lý người dùng</a></li>
            <?php endif; ?>
            <li class="active"><a href="settings.php">⚙ Cài đặt</a></li>
        </ul>
        <div class="sidebar-footer">
            <div class="sidebar-user">
                <div class="sidebar-user-label">Đăng nhập</div>
                <div class="sidebar-user-name"><?= htmlspecialchars($_SESSION['display_name'] ?? $_SESSION['username'] ?? 'Người dùng') ?></div>
            </div>
            <a href="logout.php" class="btn-delete" style="display: block; text-align: center;">Đăng xuất</a>
        </div>
    </div>

    <div class="main-content">
        <div class="header-wrapper">
            <div>
                <h2>Cài đặt tài khoản</h2>
                <span style="font-size: 0.85rem; color: var(--text-muted);">Bạn có thể thay đổi tên hiển thị của mình</span>
            </div>
        </div>

        <div class="card" style="max-width: 560px; margin: 0 auto;">
            <?php if (!empty($message)): ?><p class="success"><?= htmlspecialchars($message) ?></p><?php endif; ?>
            <?php if (!empty($error)): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
            <form method="POST">
                <div class="form-group">
                    <label for="full_name">Tên hiển thị</label>
                    <input type="text" id="full_name" name="full_name" placeholder="Tên của bạn" value="<?= htmlspecialchars($_SESSION['display_name'] ?? $_SESSION['username'] ?? '') ?>" required>
                </div>
                <button type="submit" class="btn" style="width: 100%;">Lưu thay đổi</button>
            </form>
        </div>
    </div>
</body>
</html>
