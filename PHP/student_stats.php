<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Lấy danh sách lớp học để phục vụ bộ lọc.
$classes = $db->query("SELECT id, class_name FROM classes ORDER BY class_name ASC")->fetchAll(PDO::FETCH_ASSOC);

$filterClassId = isset($_GET['filter_class_id']) ? (int)$_GET['filter_class_id'] : 0;
$searchStudent = isset($_GET['search_student']) ? trim($_GET['search_student']) : '';

// Lấy thống kê điểm danh theo từng học viên trong từng lớp.
$sql = "SELECT
            s.id AS student_id,
            s.student_name,
            s.phone,
            c.id AS class_id,
            c.class_name,
            c.total_sessions AS class_total_sessions,
            COALESCE(SUM(CASE WHEN a.status = 'Present' THEN 1 ELSE 0 END), 0) AS present_count,
            COALESCE(SUM(CASE WHEN a.status = 'Absent' THEN 1 ELSE 0 END), 0) AS absent_count
        FROM students s
        JOIN student_class sc ON sc.student_id = s.id
        JOIN classes c ON c.id = sc.class_id
        LEFT JOIN attendance a ON a.student_id = s.id AND a.class_id = c.id
        WHERE 1=1";

$params = [];
if ($filterClassId > 0) {
    $sql .= " AND c.id = ?";
    $params[] = $filterClassId;
}
if ($searchStudent !== '') {
    $sql .= " AND (s.student_name LIKE ? OR s.phone LIKE ?)";
    $params[] = "%$searchStudent%";
    $params[] = "%$searchStudent%";
}
$sql .= " GROUP BY s.id, s.student_name, s.phone, c.id, c.class_name, c.total_sessions
          ORDER BY c.class_name ASC, s.student_name ASC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$statsData = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Thống Kê Tiến Độ Học Viên</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap"></noscript>
    <link rel="stylesheet" href="../CSS/style.css?v=sidebar-fix-3">
    <style>
        .progress-container { background: #e2e8f0; border-radius: 10px; width: 100%; height: 12px; overflow: hidden; margin-top: 5px; }
        .progress-bar { background: var(--primary); height: 100%; border-radius: 10px; transition: width 0.3s ease; }
        .stats-grid-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .stat-mini-card { background: white; border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 16px; box-shadow: var(--shadow-sm); }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/sidebar.php'; ?>

    <div class="main-content">
        <div class="header-wrapper">
            <div>
                <h2>Báo Cáo & Thống Kê Tiến Độ Học Viên</h2>
                <span style="font-size: 0.85rem; color: var(--text-muted);">Theo dõi tổng số buổi học, số ca vắng và phần trăm hoàn thành của từng lớp</span>
            </div>
        </div>

        <div class="card" style="margin-bottom:24px;">
            <form method="GET" style="display:grid; grid-template-columns: 1fr 1fr auto; gap:12px; align-items:end; margin-bottom:0;">
                <div>
                    <label>Lọc theo lớp học:</label>
                    <select name="filter_class_id" style="width:100%; padding:10px; border:1px solid var(--border-color); border-radius:var(--radius-sm);">
                        <option value="0">-- Tất cả các lớp --</option>
                        <?php foreach ($classes as $cls): ?>
                            <option value="<?= $cls['id'] ?>" <?= $filterClassId === (int)$cls['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cls['class_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Tìm học viên:</label>
                    <input type="text" name="search_student" value="<?= htmlspecialchars($searchStudent) ?>" placeholder="Nhập tên học viên hoặc SĐT..." style="width:100%; padding:10px; border:1px solid var(--border-color); border-radius:var(--radius-sm);">
                </div>
                <div>
                    <button type="submit" class="btn" style="padding:11px 20px;">Lọc báo cáo</button>
                </div>
            </form>
        </div>

        <div style="background: white; border-radius: var(--radius-md); overflow: hidden; box-shadow: var(--shadow-sm); border: 1px solid var(--border-color);">
            <table class="admin-table" style="margin-top:0; border:none;">
                <thead>
                    <tr>
                        <th>Tên Học Viên</th>
                        <th>Số Điện Thoại</th>
                        <th>Lớp Học Ghi Danh</th>
                        <th style="text-align:center;">Số Tiết Quy Định</th>
                        <th style="text-align:center; color:green;">Đã Học</th>
                        <th style="text-align:center; color:red;">Số Buổi Vắng</th>
                        <th style="width:180px;">Tiến Độ Đạt Được</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($statsData)): ?>
                        <?php foreach ($statsData as $row):
                            $totalSessionClass = (int)$row['class_total_sessions'];
                            $pCount = (int)$row['present_count'];
                            $percent = $totalSessionClass > 0 ? round(($pCount / $totalSessionClass) * 100) : 0;
                            if ($percent > 100) $percent = 100;
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($row['student_name']) ?></strong></td>
                            <td style="color:var(--text-muted);"><?= htmlspecialchars($row['phone']) ?></td>
                            <td><b style="color:var(--primary);"><?= htmlspecialchars($row['class_name']) ?></b></td>
                            <td style="text-align:center;"><b><?= $totalSessionClass ?></b> buổi</td>
                            <td style="text-align:center; color:green; font-weight:700;"><?= $pCount ?> ca</td>
                            <td style="text-align:center; color:red; font-weight:700;"><?= (int)$row['absent_count'] ?> ca</td>
                            <td>
                                <div style="display:flex; justify-content:space-between; font-size:0.8rem; font-weight:600;">
                                    <span>Hoàn thành</span>
                                    <span><?= $percent ?>%</span>
                                </div>
                                <div class="progress-container">
                                    <div class="progress-bar" style="width: <?= $percent ?>%; background: <?= $percent === 100 ? '#10b981' : 'var(--primary)' ?>;"></div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" style="text-align:center; padding:30px; color:var(--text-muted); font-style:italic;">Không tìm thấy dữ liệu học viên trùng khớp.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
