<?php
$appBase = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/\\');
$currentPage = basename($_SERVER['SCRIPT_NAME'] ?? '');

$menuGroups = [
    [
        'title' => 'Tổng quan',
        'items' => [
            ['file' => 'overview_stats.php', 'href' => $appBase . '/PHP/overview_stats.php', 'icon' => '📈', 'label' => 'Tổng hợp hệ thống'],
        ],
    ],
    [
        'title' => 'Lịch dạy',
        'items' => [
            ['file' => 'index.php', 'href' => $appBase . '/HTML/index.php', 'icon' => '📅', 'label' => 'Lịch Dạy Của Tôi'],
            ['file' => 'view_others.php', 'href' => $appBase . '/PHP/view_others.php', 'icon' => '🔍', 'label' => 'Xem Lịch Người Khác'],
            ['file' => 'manual_schedule.php', 'href' => $appBase . '/PHP/manual_schedule.php', 'icon' => '🗓', 'label' => 'Xếp Lịch Thủ Công'],
        ],
    ],
    [
        'title' => 'Lớp & học viên',
        'items' => [
            ['file' => 'add_class.php', 'href' => $appBase . '/PHP/add_class.php', 'icon' => '➕', 'label' => 'Thêm Lớp & Xếp Lịch'],
            ['file' => 'manage_students.php', 'href' => $appBase . '/PHP/manage_students.php', 'icon' => '👤', 'label' => 'Quản lý học viên'],
            ['file' => 'attendance.php', 'href' => $appBase . '/PHP/attendance.php', 'icon' => '✅', 'label' => 'Điểm danh học viên'],
            ['file' => 'student_stats.php', 'href' => $appBase . '/PHP/student_stats.php', 'icon' => '📊', 'label' => 'Thống kê học viên'],
            ['file' => 'student_session_report.php', 'href' => $appBase . '/PHP/student_session_report.php', 'icon' => '📋', 'label' => 'Báo cáo buổi học'],
        ],
    ],
    [
        'title' => 'Cấu hình',
        'items' => [
            ['file' => 'manage_slots.php', 'href' => $appBase . '/PHP/manage_slots.php', 'icon' => '🕒', 'label' => 'Quản lý ca dạy'],
        ],
    ],
];

if (($_SESSION['role'] ?? '') === 'admin') {
    $menuGroups[] = [
        'title' => 'Quản trị',
        'items' => [
            ['file' => 'admin_users.php', 'href' => $appBase . '/PHP/admin_users.php', 'icon' => '👤', 'label' => 'Quản lý người dùng'],
        ],
    ];
}
?>
<div class="sidebar">
    <div class="sidebar-brand">Lịch Dạy Nội Bộ</div>
    <ul class="sidebar-menu">
        <?php foreach ($menuGroups as $group): ?>
            <li class="sidebar-section-title"><?= htmlspecialchars($group['title']) ?></li>
            <?php foreach ($group['items'] as $item): ?>
                <li class="<?= $currentPage === $item['file'] ? 'active' : '' ?>">
                    <a href="<?= htmlspecialchars($item['href']) ?>">
                        <?= htmlspecialchars($item['icon'] . ' ' . $item['label']) ?>
                    </a>
                </li>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </ul>
    <div class="sidebar-footer">
        <div class="sidebar-user">
            <div class="sidebar-user-label">Đăng nhập</div>
            <div class="sidebar-user-name"><?= htmlspecialchars($_SESSION['display_name'] ?? $_SESSION['username'] ?? 'Người dùng') ?></div>
        </div>
        <a href="<?= htmlspecialchars($appBase . '/PHP/settings.php') ?>" class="btn" style="display:block; text-align:center; margin-bottom:10px; background:#1e293b; border:1px solid #334155;">⚙ Cài đặt</a>
        <a href="<?= htmlspecialchars($appBase . '/PHP/logout.php') ?>" class="btn-delete" style="display: block; text-align: center;">Đăng xuất</a>
    </div>
</div>
