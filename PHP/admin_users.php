<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['approve_user'])) {
        $userId = (int)$_POST['user_id'];
        $db->prepare('UPDATE users SET status = ? WHERE id = ?')->execute(['active', $userId]);
    }

    if (isset($_POST['reject_user'])) {
        $userId = (int)$_POST['user_id'];
        $db->prepare('UPDATE users SET status = ? WHERE id = ?')->execute(['inactive', $userId]);
    }

    if (isset($_POST['save_permissions'])) {
        $viewerId = (int)$_POST['viewer_id'];
        $db->prepare('DELETE FROM user_view_permissions WHERE viewer_id = ?')->execute([$viewerId]);
        if (!empty($_POST['viewed_user_ids'])) {
            $stmt = $db->prepare('INSERT INTO user_view_permissions (viewer_id, viewed_user_id) VALUES (?, ?)');
            foreach ($_POST['viewed_user_ids'] as $viewedUserId) {
                $stmt->execute([$viewerId, (int)$viewedUserId]);
            }
        }
    }

    header('Location: admin_users.php');
    exit;
}

$users = $db->query("SELECT id, username, email, role, status FROM users ORDER BY username ASC")->fetchAll(PDO::FETCH_ASSOC);
$permissions = [];
foreach ($users as $user) {
    $stmt = $db->prepare('SELECT viewed_user_id FROM user_view_permissions WHERE viewer_id = ?');
    $stmt->execute([(int)$user['id']]);
    $permissions[$user['id']] = $stmt->fetchAll(PDO::FETCH_COLUMN);
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Quản lý tài khoản</title>
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
            <li class="active"><a href="admin_users.php">👤 Quản lý người dùng</a></li>
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
                <h2>Quản lý tài khoản</h2>
                <span style="font-size: 0.85rem; color: var(--text-muted);">Admin duyệt đăng ký và cấp quyền xem lịch cho từng người dùng</span>
            </div>
        </div>

        <div class="admin-card">
            <h3>Danh sách tài khoản</h3>
            <table class="admin-list">
                <thead>
                    <tr>
                        <th>Tài khoản</th>
                        <th>Email</th>
                        <th>Vai trò</th>
                        <th>Trạng thái</th>
                        <th>Hành động</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                    <tr>
                        <td><?= htmlspecialchars($u['username']) ?></td>
                        <td><?= htmlspecialchars($u['email'] ?? '') ?></td>
                        <td><?= htmlspecialchars($u['role']) ?></td>
                        <td><span class="badge badge-<?= strtolower($u['status'] ?? 'pending') ?>"><?= htmlspecialchars($u['status'] ?? 'pending') ?></span></td>
                        <td>
                            <?php if (($u['status'] ?? 'pending') === 'pending'): ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                    <button type="submit" name="approve_user" class="btn">Kích hoạt</button>
                                </form>
                            <?php endif; ?>
                            <?php if (($u['status'] ?? 'pending') !== 'inactive'): ?>
                                <form method="POST" style="display:inline; margin-left:6px;">
                                    <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                    <button type="submit" name="reject_user" class="btn-delete">Vô hiệu hóa</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="admin-card">
            <h3>Cấp quyền xem lịch</h3>
            <p class="permission-helper">Admin có thể sắp xếp cho từng người dùng được phép xem lịch của những người khác nào.</p>
            <?php foreach ($users as $u): ?>
                <form method="POST" class="permission-card" style="margin-top: 12px;">
                    <input type="hidden" name="viewer_id" value="<?= (int)$u['id'] ?>">
                    <div class="permission-header">
                        <strong><?= htmlspecialchars($u['username']) ?></strong>
                        <span class="badge badge-active">Được xem lịch của</span>
                    </div>
                    <div class="permission-group">
                        <?php foreach ($users as $target): ?>
                            <?php if ((int)$target['id'] === (int)$u['id']) continue; ?>
                            <label>
                                <input type="checkbox" name="viewed_user_ids[]" value="<?= (int)$target['id'] ?>" <?= in_array((int)$target['id'], $permissions[$u['id']] ?? [], true) ? 'checked' : '' ?>>
                                <?= htmlspecialchars($target['username']) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <button type="submit" name="save_permissions" class="btn" style="margin-top: 12px;">Lưu quyền</button>
                </form>
            <?php endforeach; ?>
        </div>
    </div>
</body>
</html>
