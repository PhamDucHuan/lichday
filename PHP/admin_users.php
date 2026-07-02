<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_user_role'])) {
        $userId = (int)($_POST['user_id'] ?? 0);
        $role = ($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
        $status = $_POST['status'] ?? 'active';
        if (!in_array($status, ['active', 'pending', 'inactive'], true)) {
            $status = 'active';
        }

        if ($userId > 0 && $userId !== (int)$_SESSION['user_id']) {
            $db->prepare('UPDATE users SET role = ?, status = ? WHERE id = ?')->execute([$role, $status, $userId]);
        }
    }

    if (isset($_POST['approve_user'])) {
        $userId = (int)$_POST['user_id'];
        $db->prepare('UPDATE users SET status = ? WHERE id = ?')->execute(['active', $userId]);
    }

    if (isset($_POST['reject_user'])) {
        $userId = (int)$_POST['user_id'];
        if ($userId !== (int)$_SESSION['user_id']) {
            $db->prepare('UPDATE users SET status = ? WHERE id = ?')->execute(['inactive', $userId]);
        }
    }

    if (isset($_POST['save_permissions'])) {
        $viewerId = (int)$_POST['viewer_id'];
        $viewedUserIds = !empty($_POST['viewed_user_ids']) && is_array($_POST['viewed_user_ids'])
            ? $_POST['viewed_user_ids']
            : [];
        syncUserViewPermissions($db, $viewerId, $viewedUserIds);
    }

    header('Location: admin_users.php');
    exit;
}

$users = $db->query("SELECT id, username, full_name, email, role, status FROM users ORDER BY username ASC")->fetchAll(PDO::FETCH_ASSOC);
$permissions = [];
foreach ($db->query('SELECT viewer_id, viewed_user_id FROM user_view_permissions') as $permissionRow) {
    $permissions[(int)$permissionRow['viewer_id']][] = (int)$permissionRow['viewed_user_id'];
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Quản lý tài khoản</title>
    <link rel="stylesheet" href="../CSS/style.css?v=sidebar-fix-3">
</head>
<body>
    <?php require_once __DIR__ . '/sidebar.php'; ?>

    <div class="main-content">
        <div class="header-wrapper">
            <div>
                <h2>Quản lý tài khoản</h2>
                <span style="font-size: 0.85rem; color: var(--text-muted);">Admin duyệt đăng ký, đổi quyền User/Admin và cấp quyền xem lịch</span>
            </div>
        </div>

        <div class="admin-card">
            <h3>Danh sách tài khoản</h3>
            <table class="admin-list">
                <thead>
                    <tr>
                        <th>Tài khoản</th>
                        <th>Email</th>
                        <th>Quyền / Trạng thái</th>
                        <th>Hành động nhanh</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($u['full_name'] ?: $u['username']) ?></strong>
                            <div style="font-size:0.82rem; color:var(--text-muted);"><?= htmlspecialchars($u['username']) ?></div>
                        </td>
                        <td><?= htmlspecialchars($u['email'] ?? '') ?></td>
                        <td>
                            <form method="POST" style="display:grid; grid-template-columns:minmax(90px, 1fr) minmax(120px, 1fr) auto; gap:8px; align-items:center;">
                                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                <select name="role" <?= (int)$u['id'] === (int)$_SESSION['user_id'] ? 'disabled' : '' ?>>
                                    <option value="user" <?= ($u['role'] ?? 'user') === 'user' ? 'selected' : '' ?>>User</option>
                                    <option value="admin" <?= ($u['role'] ?? 'user') === 'admin' ? 'selected' : '' ?>>Admin</option>
                                </select>
                                <select name="status" <?= (int)$u['id'] === (int)$_SESSION['user_id'] ? 'disabled' : '' ?>>
                                    <option value="active" <?= ($u['status'] ?? 'pending') === 'active' ? 'selected' : '' ?>>Hoạt động</option>
                                    <option value="pending" <?= ($u['status'] ?? 'pending') === 'pending' ? 'selected' : '' ?>>Chờ duyệt</option>
                                    <option value="inactive" <?= ($u['status'] ?? 'pending') === 'inactive' ? 'selected' : '' ?>>Vô hiệu hóa</option>
                                </select>
                                <?php if ((int)$u['id'] !== (int)$_SESSION['user_id']): ?>
                                    <button type="submit" name="save_user_role" class="btn" style="padding:6px 10px;">Lưu</button>
                                <?php else: ?>
                                    <span class="badge badge-active">Bạn</span>
                                <?php endif; ?>
                            </form>
                        </td>
                        <td>
                            <?php if (($u['status'] ?? 'pending') === 'pending'): ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                                    <button type="submit" name="approve_user" class="btn">Kích hoạt</button>
                                </form>
                            <?php endif; ?>
                            <?php if (($u['status'] ?? 'pending') !== 'inactive' && (int)$u['id'] !== (int)$_SESSION['user_id']): ?>
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
                        <strong><?= htmlspecialchars($u['full_name'] ?: $u['username']) ?></strong>
                        <span class="badge badge-active">Được xem lịch của</span>
                    </div>
                    <div class="permission-group">
                        <?php foreach ($users as $target): ?>
                            <?php if ((int)$target['id'] === (int)$u['id']) continue; ?>
                            <label>
                                <input type="checkbox" name="viewed_user_ids[]" value="<?= (int)$target['id'] ?>" <?= in_array((int)$target['id'], $permissions[(int)$u['id']] ?? [], true) ? 'checked' : '' ?>>
                                <?= htmlspecialchars($target['full_name'] ?: $target['username']) ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <button type="submit" name="save_permissions" class="btn" style="margin-top: 12px;">Lưu quyền xem lịch</button>
                </form>
            <?php endforeach; ?>
        </div>
    </div>
</body>
</html>
