<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$totalClasses = (int)$db->query("SELECT COUNT(*) FROM classes")->fetchColumn();
$totalStudents = (int)$db->query("SELECT COUNT(*) FROM students")->fetchColumn();
$activeClasses = (int)$db->query("SELECT COUNT(*) FROM classes WHERE status = 'Active'")->fetchColumn();
$closedClasses = (int)$db->query("SELECT COUNT(*) FROM classes WHERE status = 'Closed'")->fetchColumn();
$pausedClasses = (int)$db->query("SELECT COUNT(*) FROM classes WHERE status = 'Paused'")->fetchColumn();

$classStatusRows = $db->query("
    SELECT COALESCE(NULLIF(status, ''), 'Chưa rõ') AS status_name, COUNT(*) AS total
    FROM classes
    GROUP BY COALESCE(NULLIF(status, ''), 'Chưa rõ')
    ORDER BY total DESC, status_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$teacherStats = $db->query("
    SELECT
        u.id AS teacher_id,
        COALESCE(NULLIF(u.full_name, ''), u.username, 'Chưa gán') AS teacher_name,
        COUNT(DISTINCT c.id) AS class_count,
        COUNT(DISTINCT CASE WHEN c.status = 'Active' THEN c.id END) AS active_class_count,
        COUNT(DISTINCT CASE WHEN c.status = 'Closed' THEN c.id END) AS closed_class_count,
        COUNT(DISTINCT CASE WHEN c.status = 'Paused' THEN c.id END) AS paused_class_count,
        COUNT(DISTINCT sc.student_id) AS student_count
    FROM users u
    LEFT JOIN classes c ON c.assigned_user_id = u.id
    LEFT JOIN student_class sc ON sc.class_id = c.id
    WHERE u.status = 'active' OR c.id IS NOT NULL
    GROUP BY u.id, teacher_name

    UNION ALL

    SELECT
        0 AS teacher_id,
        'Chưa gán giáo viên' AS teacher_name,
        COUNT(DISTINCT c.id) AS class_count,
        COUNT(DISTINCT CASE WHEN c.status = 'Active' THEN c.id END) AS active_class_count,
        COUNT(DISTINCT CASE WHEN c.status = 'Closed' THEN c.id END) AS closed_class_count,
        COUNT(DISTINCT CASE WHEN c.status = 'Paused' THEN c.id END) AS paused_class_count,
        COUNT(DISTINCT sc.student_id) AS student_count
    FROM classes c
    LEFT JOIN student_class sc ON sc.class_id = c.id
    WHERE c.assigned_user_id IS NULL OR c.assigned_user_id = 0
    HAVING class_count > 0

    ORDER BY class_count DESC, student_count DESC, teacher_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$topClasses = $db->query("
    SELECT
        c.id,
        c.class_name,
        c.status,
        COALESCE(NULLIF(u.full_name, ''), u.username, 'Chưa gán') AS teacher_name,
        COUNT(DISTINCT sc.student_id) AS student_count
    FROM classes c
    LEFT JOIN users u ON u.id = c.assigned_user_id
    LEFT JOIN student_class sc ON sc.class_id = c.id
    GROUP BY c.id, c.class_name, c.status, teacher_name
    ORDER BY student_count DESC, c.class_name ASC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

function overviewStatusClass(string $status): string {
    $normalized = strtolower(trim($status));
    if ($normalized === 'active') {
        return 'status-active';
    }
    if ($normalized === 'closed') {
        return 'status-closed';
    }
    if ($normalized === 'paused') {
        return 'status-paused';
    }
    return 'status-unknown';
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Tổng Hợp Hệ Thống</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap"></noscript>
    <link rel="stylesheet" href="../CSS/style.css?v=sidebar-fix-3">
    <style>
        .overview-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .overview-stat { background: #fff; border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 18px; box-shadow: var(--shadow-sm); }
        .overview-stat-label { color: var(--text-muted); font-size: 0.88rem; font-weight: 600; margin-bottom: 8px; }
        .overview-stat-value { color: var(--text-main); font-size: 2rem; font-weight: 800; line-height: 1; }
        .overview-stat-note { margin-top: 8px; color: var(--text-muted); font-size: 0.82rem; }
        .overview-stat.stat-total { background: #eff6ff; border-color: #bfdbfe; }
        .overview-stat.stat-total .overview-stat-label,
        .overview-stat.stat-total .overview-stat-note { color: #1d4ed8; }
        .overview-stat.stat-total .overview-stat-value { color: #1e3a8a; }
        .overview-stat.stat-students { background: #f5f3ff; border-color: #ddd6fe; }
        .overview-stat.stat-students .overview-stat-label,
        .overview-stat.stat-students .overview-stat-note { color: #6d28d9; }
        .overview-stat.stat-students .overview-stat-value { color: #4c1d95; }
        .overview-stat.stat-active { background: #ecfdf5; border-color: #a7f3d0; }
        .overview-stat.stat-active .overview-stat-label,
        .overview-stat.stat-active .overview-stat-note { color: #047857; }
        .overview-stat.stat-active .overview-stat-value { color: #065f46; }
        .overview-stat.stat-closed { background: #f8fafc; border-color: #cbd5e1; }
        .overview-stat.stat-closed .overview-stat-label,
        .overview-stat.stat-closed .overview-stat-note { color: #475569; }
        .overview-stat.stat-closed .overview-stat-value { color: #1e293b; }
        .overview-stat.stat-paused { background: #fffbeb; border-color: #fde68a; }
        .overview-stat.stat-paused .overview-stat-label,
        .overview-stat.stat-paused .overview-stat-note { color: #b45309; }
        .overview-stat.stat-paused .overview-stat-value { color: #92400e; }
        .overview-layout { display: grid; grid-template-columns: minmax(0, 1.6fr) minmax(300px, 0.9fr); gap: 20px; align-items: start; }
        .overview-panel { background: #fff; border: 1px solid var(--border-color); border-radius: var(--radius-md); box-shadow: var(--shadow-sm); overflow: hidden; }
        .overview-panel-header { padding: 18px 20px; border-bottom: 1px solid var(--border-color); }
        .overview-panel-header h3 { margin: 0; font-size: 1rem; }
        .status-list { display: grid; gap: 10px; padding: 18px 20px; }
        .status-row { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 10px 12px; background: #f8fafc; border: 1px solid var(--border-color); border-radius: var(--radius-sm); }
        .status-row strong { color: var(--text-main); }
        .status-pill { display: inline-flex; align-items: center; justify-content: center; min-width: 42px; padding: 4px 8px; border-radius: 999px; background: var(--primary-light); color: var(--primary); font-weight: 800; }
        .status-row.status-active { background: #ecfdf5; border-color: #a7f3d0; }
        .status-row.status-active strong { color: #065f46; }
        .status-row.status-active .status-pill { background: #d1fae5; color: #047857; }
        .status-row.status-closed { background: #f8fafc; border-color: #cbd5e1; }
        .status-row.status-closed strong { color: #334155; }
        .status-row.status-closed .status-pill { background: #e2e8f0; color: #475569; }
        .status-row.status-paused { background: #fffbeb; border-color: #fde68a; }
        .status-row.status-paused strong { color: #92400e; }
        .status-row.status-paused .status-pill { background: #fef3c7; color: #b45309; }
        .status-row.status-unknown { background: #f5f3ff; border-color: #ddd6fe; }
        .status-row.status-unknown strong { color: #5b21b6; }
        .status-row.status-unknown .status-pill { background: #ede9fe; color: #6d28d9; }
        @media (max-width: 980px) { .overview-layout { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/sidebar.php'; ?>

    <div class="main-content">
        <div class="header-wrapper">
            <div>
                <h2>Tổng Hợp Hệ Thống</h2>
                <span style="font-size: 0.85rem; color: var(--text-muted);">Tổng quan lớp học, học viên và phân bổ theo giáo viên</span>
            </div>
        </div>

        <div class="overview-grid">
            <div class="overview-stat stat-total">
                <div class="overview-stat-label">Tổng số lớp</div>
                <div class="overview-stat-value"><?= $totalClasses ?></div>
                <div class="overview-stat-note">Tất cả trạng thái</div>
            </div>
            <div class="overview-stat stat-students">
                <div class="overview-stat-label">Tổng số học viên</div>
                <div class="overview-stat-value"><?= $totalStudents ?></div>
                <div class="overview-stat-note">Học viên trong hệ thống</div>
            </div>
            <div class="overview-stat stat-active">
                <div class="overview-stat-label">Lớp hoạt động</div>
                <div class="overview-stat-value"><?= $activeClasses ?></div>
                <div class="overview-stat-note">Trạng thái Active</div>
            </div>
            <div class="overview-stat stat-closed">
                <div class="overview-stat-label">Lớp đã đóng</div>
                <div class="overview-stat-value"><?= $closedClasses ?></div>
                <div class="overview-stat-note">Trạng thái Closed</div>
            </div>
            <div class="overview-stat stat-paused">
                <div class="overview-stat-label">Lớp tạm dừng</div>
                <div class="overview-stat-value"><?= $pausedClasses ?></div>
                <div class="overview-stat-note">Trạng thái Paused</div>
            </div>
        </div>

        <div class="overview-layout">
            <div class="overview-panel">
                <div class="overview-panel-header">
                    <h3>Số lớp và học viên của mỗi giáo viên</h3>
                </div>
                <table class="admin-table" style="margin-top:0; border:none;">
                    <thead>
                        <tr>
                            <th>Giáo viên</th>
                            <th style="text-align:center;">Tổng lớp</th>
                            <th style="text-align:center;">Đang hoạt động</th>
                            <th style="text-align:center;">Đã đóng</th>
                            <th style="text-align:center;">Tạm dừng</th>
                            <th style="text-align:center;">Học viên</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!empty($teacherStats)): ?>
                            <?php foreach ($teacherStats as $row): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($row['teacher_name']) ?></strong></td>
                                    <td style="text-align:center; font-weight:700;"><?= (int)$row['class_count'] ?></td>
                                    <td style="text-align:center; color:#166534; font-weight:700;"><?= (int)$row['active_class_count'] ?></td>
                                    <td style="text-align:center; color:#475569; font-weight:700;"><?= (int)$row['closed_class_count'] ?></td>
                                    <td style="text-align:center; color:#92400e; font-weight:700;"><?= (int)$row['paused_class_count'] ?></td>
                                    <td style="text-align:center; color:var(--primary); font-weight:800;"><?= (int)$row['student_count'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" style="text-align:center; padding:28px; color:var(--text-muted);">Chưa có dữ liệu giáo viên.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div style="display:grid; gap:20px;">
                <div class="overview-panel">
                    <div class="overview-panel-header">
                        <h3>Trạng thái lớp học</h3>
                    </div>
                    <div class="status-list">
                        <?php foreach ($classStatusRows as $row): ?>
                            <div class="status-row <?= overviewStatusClass((string)$row['status_name']) ?>">
                                <strong><?= htmlspecialchars($row['status_name']) ?></strong>
                                <span class="status-pill"><?= (int)$row['total'] ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="overview-panel">
                    <div class="overview-panel-header">
                        <h3>Top lớp nhiều học viên</h3>
                    </div>
                    <table class="admin-table" style="margin-top:0; border:none;">
                        <thead>
                            <tr>
                                <th>Lớp</th>
                                <th>Giáo viên</th>
                                <th style="text-align:center;">HV</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($topClasses as $row): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($row['class_name']) ?></strong><br>
                                        <span style="color:var(--text-muted); font-size:0.82rem;"><?= htmlspecialchars($row['status']) ?></span>
                                    </td>
                                    <td><?= htmlspecialchars($row['teacher_name']) ?></td>
                                    <td style="text-align:center; font-weight:800; color:var(--primary);"><?= (int)$row['student_count'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
