<?php
session_start();
require_once '../PHP/config.php';

$slotRows = getTeachingSlotOptions($db);
$slotOptions = array_map(static fn($slot) => $slot['slot_label'], $slotRows);

if (!isset($_SESSION['user_id'])) {
    header('Location: ../PHP/login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Lịch Dạy Của Tôi</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../CSS/style.css">
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-brand">Lịch Dạy Nội Bộ</div>
        <ul class="sidebar-menu">
            <li class="active"><a href="index.php">📅 Lịch Dạy Của Tôi</a></li>
            <li><a href="../PHP/view_others.php">🔍 Xem Lịch Người Khác</a></li>
            <li><a href="../PHP/add_class.php">➕ Thêm Lớp & Xếp Lịch</a></li>
            <li><a href="../PHP/manage_slots.php">🕒 Quản lý ca dạy</a></li>
            <li><a href="../PHP/manual_schedule.php">🗓 Xếp Lịch Thủ Công</a></li>
            <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
            <li><a href="../PHP/admin_users.php">👤 Quản lý người dùng</a></li>
            <?php endif; ?>
        </ul>
        <div class="sidebar-footer">
            <div class="sidebar-user">
                <div class="sidebar-user-label">Đăng nhập</div>
                <div class="sidebar-user-name"><?= htmlspecialchars($_SESSION['display_name'] ?? $_SESSION['username'] ?? 'Người dùng') ?></div>
            </div>
            <a href="../PHP/settings.php" class="btn" style="display:block; text-align:center; margin-bottom:10px; background:#1e293b; border:1px solid #334155;">⚙ Cài đặt</a>
            <a href="../PHP/logout.php" class="btn-delete" style="display: block; text-align: center;">Đăng xuất</a>
        </div>
    </div>

    <div class="main-content">
        <div id="schedule-action-modal" class="modal" style="display:none;">
            <div class="modal-content">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                    <strong id="panel-class-name"></strong>
                    <button type="button" class="btn-delete" onclick="closeActionPanel()" style="padding:4px 8px;">✕</button>
                </div>
                <form id="move-form" class="compact-form">
                    <input type="hidden" id="panel-class-id" name="class_id">
                    <input type="hidden" id="panel-session-date" name="session_date">
                    <input type="hidden" name="action" value="move">
                    <div class="form-group">
                        <label>Ngày mới</label>
                        <input type="date" id="panel-new-date" name="new_date" required>
                    </div>
                    <div class="form-group">
                        <label>Ca mới</label>
                        <select id="panel-new-slot" name="new_slot" required>
                            <option value="">-- Chọn ca --</option>
                            <?php foreach ($slotOptions as $slot): ?>
                                <option value="<?= htmlspecialchars($slot) ?>"><?= htmlspecialchars($slot) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="display:flex; gap:10px; flex-wrap:wrap;">
                        <button type="submit" class="btn">Đổi lịch hôm nay</button>
                        <button type="button" class="btn-delete" id="delete-class-btn">Xóa lịch này và đẩy buổi sau</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="header-wrapper">
            <div>
                <h2>Lịch Dạy Của Tôi</h2>
                <span style="font-size: 0.85rem; color: var(--text-muted);">Thời khóa biểu cá nhân trong tuần</span>
            </div>
        </div>

        <div class="navigation">
            <button id="btn-prev" class="btn" style="background-color: #f1f5f9; color: var(--text-main); border: 1px solid var(--border-color); box-shadow:none;">◀ Tuần trước</button>
            <span id="current-week-text" class="current-week">Đang tải tuần làm việc...</span>
            <button id="btn-next" class="btn" style="background-color: #f1f5f9; color: var(--text-main); border: 1px solid var(--border-color); box-shadow:none;">Tuần sau ▶</button>
        </div>

        <div class="table-responsive">
            <table class="schedule-table">
                <thead><tr id="table-header"></tr></thead>
                <tbody><tr id="table-body"></tr></tbody>
            </table>
        </div>
    </div>

    <script>
        let currentWeekOffset = 0;
        let activeClassId = null;
        let activeSessionDate = null;

        function openActionPanel(classId, className, sessionDate) {
            document.getElementById('panel-class-id').value = classId;
            document.getElementById('panel-session-date').value = sessionDate;
            document.getElementById('panel-class-name').innerText = 'Lớp: ' + className;
            document.getElementById('schedule-action-modal').style.display = 'flex';
            activeClassId = classId;
            activeSessionDate = sessionDate;
            document.getElementById('panel-new-date').value = sessionDate;
        }

        function closeActionPanel() {
            document.getElementById('schedule-action-modal').style.display = 'none';
        }

        async function fetchSchedule(offset) {
            try {
                const response = await fetch('../PHP/api.php?week=' + offset);
                if (response.status === 401) {
                    window.location.href = '../PHP/login.php';
                    return;
                }
                const data = await response.json();

                document.getElementById('current-week-text').innerText = 'Tuần: ' + data.monday + ' - ' + data.sunday;

                const headerRow = document.getElementById('table-header');
                headerRow.innerHTML = '';
                data.dates.forEach(item => {
                    headerRow.innerHTML += '<th>' + item.day_name + '<small>' + item.date_formatted + '</small></th>';
                });

                const bodyRow = document.getElementById('table-body');
                bodyRow.innerHTML = '';
                data.dates.forEach(item => {
                    let cellContent = '';
                    if (data.schedule[item.date_raw] && data.schedule[item.date_raw].length > 0) {
                        data.schedule[item.date_raw].forEach(session => {
                            cellContent += `
                                <div class="session-card" data-class-id="${session.class_id || ''}" style="cursor:pointer;" onclick="openActionPanel('${session.class_id || ''}', '${session.name.replace(/'/g, "\\'")}', '${item.date_raw}')">
                                    <div class="class-name">${session.name}</div>
                                    <div class="class-time">${session.time}</div>
                                </div>`;
                        });
                    } else {
                        cellContent = '<span class="empty-day">·</span>';
                    }
                    bodyRow.innerHTML += '<td>' + cellContent + '</td>';
                });
            } catch (error) {
                console.error('Lỗi:', error);
            }
        }

        document.getElementById('btn-prev').addEventListener('click', () => { currentWeekOffset--; fetchSchedule(currentWeekOffset); });
        document.getElementById('btn-next').addEventListener('click', () => { currentWeekOffset++; fetchSchedule(currentWeekOffset); });
        document.getElementById('schedule-action-modal').addEventListener('click', function(e) {
            if (e.target === this) closeActionPanel();
        });
        document.getElementById('move-form').addEventListener('submit', async function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            formData.append('session_date', activeSessionDate || '');
            const response = await fetch('../PHP/schedule_actions.php', { method: 'POST', body: formData });
            const result = await response.json();
            alert(result.message || 'Đã xử lý');
            closeActionPanel();
            fetchSchedule(currentWeekOffset);
        });
        document.getElementById('delete-class-btn').addEventListener('click', async function() {
            if (!activeClassId) return;
            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('class_id', activeClassId);
            formData.append('session_date', activeSessionDate || '');
            const response = await fetch('../PHP/schedule_actions.php', { method: 'POST', body: formData });
            const result = await response.json();
            alert(result.message || 'Đã xử lý');
            closeActionPanel();
            fetchSchedule(currentWeekOffset);
        });
        fetchSchedule(currentWeekOffset);
    </script>
</body>
</html>
