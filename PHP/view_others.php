<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if ($_SESSION['role'] === 'admin') {
    $users = $db->query("SELECT id, username FROM users WHERE status = 'active' AND id != " . (int)$_SESSION['user_id'] . " ORDER BY username ASC")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $db->prepare("SELECT u.id, u.username FROM users u JOIN user_view_permissions p ON p.viewed_user_id = u.id WHERE p.viewer_id = ? AND u.status = 'active' AND u.id != ? ORDER BY u.username ASC");
    $stmt->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$target_user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
if ($target_user_id === 0 && count($users) > 0) {
    $target_user_id = (int)$users[0]['id'];
}
$target_username = "";
foreach ($users as $u) {
    if ((int)$u['id'] === $target_user_id) { $target_username = $u['username']; break; }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Xem Lịch Nhân Sự Khác</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../CSS/style.css">
</head>
<body>

    <div class="sidebar">
        <div class="sidebar-brand">Lịch Dạy Nội Bộ</div>
        <ul class="sidebar-menu">
            <li><a href="../HTML/index.php">📅 Lịch Dạy Của Tôi</a></li>
            <li class="active"><a href="view_others.php">🔍 Xem Lịch Người Khác</a></li>
            <li><a href="add_class.php">➕ Thêm Lớp & Xếp Lịch</a></li>
            <li><a href="manage_students.php">👤 Quản lý học viên</a></li>
            <li><a href="attendance.php">✅ Điểm danh học viên</a></li>
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
                <h2>Xem Lịch Giảng Viên Khác</h2>
                <span style="font-size: 0.85rem; color: var(--text-muted);">Danh sách nhân sự được quyền truy cập hệ thống</span>
            </div>
        </div>

        <h3>Chọn nhân sự cần kiểm tra lịch dạy:</h3>
        <?php if (count($users) > 0): ?>
        <div class="user-grid">
            <?php foreach ($users as $usr): ?>
                <a href="view_others.php?user_id=<?= $usr['id'] ?>" class="user-card" style="<?= (int)$usr['id'] === $target_user_id ? 'border-color: var(--primary); background: #f4f3ff;' : '' ?>">
                    <div class="user-avatar"><?= strtoupper(substr($usr['username'], 0, 1)) ?></div>
                    <strong style="font-size: 1rem; color: var(--text-main);"><?= htmlspecialchars($usr['username']) ?></strong>
                    <div style="font-size: 0.8rem; color: var(--text-muted); margin-top:4px;">Giảng viên nội bộ</div>
                </a>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
            <p class="error">Bạn chưa được cấp quyền xem lịch của bất kỳ người dùng nào.</p>
        <?php endif; ?>

        <?php if ($target_user_id > 0): ?>
            <h3 style="margin-top: 40px; border-top: 1px solid var(--border-color); padding-top: 24px;">
                📅 Đang xem thời khóa biểu của: <span style="color: var(--primary);"><?= htmlspecialchars($target_username) ?></span>
            </h3>
            
            <div class="navigation">
                <button id="btn-prev" class="btn" style="background-color: #f1f5f9; color: var(--text-main); border: 1px solid var(--border-color); box-shadow:none;">◀ Tuần trước</button>
                <span id="current-week-text" class="current-week">Đang tải lịch tuần...</span>
                <button id="btn-next" class="btn" style="background-color: #f1f5f9; color: var(--text-main); border: 1px solid var(--border-color); box-shadow:none;">Tuần sau ▶</button>
            </div>

            <div class="admin-card" style="margin-top: 20px;">
                <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:10px;">
                    <h4 style="margin: 0;">🕒 Lịch trống trong tuần</h4>
                    <button id="toggle-free-slots" type="button" class="btn" style="background:#f8fafc; color:var(--text-main); border:1px solid var(--border-color); box-shadow:none; padding:8px 12px;">▾ Mở/thu nhỏ</button>
                </div>
                <div id="free-slots-summary" class="permission-group" style="display:none;"></div>
            </div>

            <div class="admin-card" style="margin-top: 20px;">
                <div style="display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:10px;">
                    <h4 style="margin: 0;">📆 Dự kiến lịch trong tháng</h4>
                    <button id="toggle-month-preview" type="button" class="btn" style="background:#f8fafc; color:var(--text-main); border:1px solid var(--border-color); box-shadow:none; padding:8px 12px;">▾ Mở/thu nhỏ</button>
                </div>
                <div id="month-preview-summary" class="permission-group" style="display:none;"></div>
            </div>

            <div class="table-responsive">
                <table class="schedule-table">
                    <thead><tr id="table-header"></tr></thead>
                    <tbody id="table-body"></tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <script>
        let currentWeekOffset = 0;
        const targetUserId = <?= $target_user_id ?>;
        let freeSlotsExpanded = false;
        let monthPreviewExpanded = false;

        function setFreeSlotsVisibility(isVisible) {
            const summary = document.getElementById('free-slots-summary');
            const button = document.getElementById('toggle-free-slots');
            if (!summary || !button) return;
            summary.style.display = isVisible ? 'block' : 'none';
            button.innerText = isVisible ? '▴ Thu nhỏ' : '▾ Mở/thu nhỏ';
        }

        function setMonthPreviewVisibility(isVisible) {
            const summary = document.getElementById('month-preview-summary');
            const button = document.getElementById('toggle-month-preview');
            if (!summary || !button) return;
            summary.style.display = isVisible ? 'block' : 'none';
            button.innerText = isVisible ? '▴ Thu nhỏ' : '▾ Mở/thu nhỏ';
        }

        async function fetchSchedule(offset) {
            if (targetUserId === 0) return;
            try {
                const response = await fetch(`api.php?week=${offset}&user_id=${targetUserId}`);
                if (response.status === 401) {
                    window.location.href = 'login.php';
                    return;
                }
                const data = await response.json();
                
                document.getElementById('current-week-text').innerText = `Tuần: ${data.monday} - ${data.sunday}`;
                
                const headerRow = document.getElementById('table-header');
                headerRow.innerHTML = '<th style="background-color: #e2e8f0; font-weight: bold; width: 140px;">Ca dạy</th>';
                data.dates.forEach(item => {
                    headerRow.innerHTML += `<th>${item.day_name}<small>${item.date_formatted}</small></th>`;
                });

                const bodyRow = document.getElementById('table-body');
                bodyRow.innerHTML = '';

                // FIX SỬA LỖI ĐOẠN NÀY: Duyệt render động theo slots_definitions nhận từ API
                data.slots_definitions.forEach(slotItem => {
                    let rowHtml = `<tr>`;
                    rowHtml += `<td style="background-color: #f8fafc; font-weight: 600; text-align: left; vertical-align: middle; border-right: 2px solid var(--border-color); color: var(--primary); padding: 10px; font-size: 0.85rem;">${slotItem.slot_label}</td>`;
                    
                    data.dates.forEach(item => {
                        let cellContent = '';
                        const daySessions = data.schedule[item.date_raw] || [];
                        const matchedSessions = daySessions.filter(s => s.slot_code === slotItem.slot_code);

                        if (matchedSessions.length > 0) {
                            matchedSessions.forEach(session => {
                                cellContent += `
                                    <div class="session-card" style="background-color: #f8fafc; border-left-color: var(--text-muted); margin-bottom:6px;">
                                        <div class="class-name" style="color: #334155;">${session.name}</div>
                                        <div class="class-time" style="background: #e2e8f0; color: #475569;">${session.time}</div>
                                    </div>`;
                            });
                        } else {
                            cellContent = '<span class="empty-day">·</span>';
                        }
                        rowHtml += `<td>${cellContent}</td>`;
                    });
                    
                    rowHtml += `</tr>`;
                    bodyRow.innerHTML += rowHtml;
                });

                const freeSlotSummary = document.getElementById('free-slots-summary');
                const toggleButton = document.getElementById('toggle-free-slots');
                freeSlotSummary.innerHTML = '';
                let hasFreeSlots = false;
                if (data.free_slots) {
                    data.dates.forEach(item => {
                        const freeSlots = data.free_slots[item.date_raw] || [];
                        if (freeSlots.length > 0) {
                            hasFreeSlots = true;
                            const card = document.createElement('div');
                            card.className = 'permission-card';
                            card.innerHTML = `<strong>${item.day_name} ${item.date_formatted}</strong><div class="permission-group" style="margin-top:8px;">${freeSlots.map(slot => `<label><input type="checkbox" checked disabled>${slot}</label>`).join('')}</div>`;
                            freeSlotSummary.appendChild(card);
                        }
                    });
                }
                if (!hasFreeSlots) {
                    freeSlotSummary.innerHTML = '<p class="permission-helper">Không có khung giờ trống nào trong tuần này.</p>';
                    toggleButton.style.display = 'none';
                    freeSlotsExpanded = false;
                    setFreeSlotsVisibility(false);
                } else {
                    toggleButton.style.display = 'inline-block';
                    setFreeSlotsVisibility(freeSlotsExpanded);
                }

                const monthPreviewSummary = document.getElementById('month-preview-summary');
                monthPreviewSummary.innerHTML = '';
                if (data.month_preview) {
                    const entries = Object.entries(data.month_preview).slice(0, 10);
                    if (entries.length > 0) {
                        entries.forEach(([date, items]) => {
                            const card = document.createElement('div');
                            card.className = 'permission-card';
                            card.innerHTML = `<strong>${date}</strong><div class="permission-group" style="margin-top:8px;">${items.map(item => `<label><input type="checkbox" checked disabled>${item.name} • ${item.time}</label>`).join('')}</div>`;
                            monthPreviewSummary.appendChild(card);
                        });
                    } else {
                        monthPreviewSummary.innerHTML = '<p class="permission-helper">Không có lịch dự kiến trong tháng này.</p>';
                    }
                }
                setMonthPreviewVisibility(monthPreviewExpanded);
            } catch (error) {
                console.error("Lỗi:", error);
            }
        }

        if (document.getElementById('btn-prev')) {
            document.getElementById('btn-prev').addEventListener('click', () => { currentWeekOffset--; fetchSchedule(currentWeekOffset); });
            document.getElementById('btn-next').addEventListener('click', () => { currentWeekOffset++; fetchSchedule(currentWeekOffset); });
            document.getElementById('toggle-free-slots').addEventListener('click', () => {
                freeSlotsExpanded = !freeSlotsExpanded;
                setFreeSlotsVisibility(freeSlotsExpanded);
            });
            document.getElementById('toggle-month-preview').addEventListener('click', () => {
                monthPreviewExpanded = !monthPreviewExpanded;
                setMonthPreviewVisibility(monthPreviewExpanded);
            });
            fetchSchedule(currentWeekOffset);
        }
    </script>
</body>
</html>