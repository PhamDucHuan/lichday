<?php
session_start();
require_once '../PHP/config.php';

$slotRows = getTeachingSlotOptions($db);
$slotOptions = array_map(static fn($slot) => $slot['slot_label'], $slotRows);

if (!isset($_SESSION['user_id'])) {
    header('Location: ../PHP/login.php');
    exit;
}
$usersList = $db->query("SELECT id, username, full_name FROM users WHERE status='active' ORDER BY full_name, username")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Lịch Dạy Của Tôi</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../CSS/style.css?v=sidebar-fix-3">
</head>
<body>
    <?php require_once __DIR__ . '/../PHP/sidebar.php'; ?>

    <div class="main-content">
        <div id="schedule-action-modal" class="modal" style="display:none;">
            <div class="modal-content modal-wide student-modal-card">
                <div class="student-modal-header">
                    <strong id="panel-class-name" class="student-modal-title">Danh sách học viên</strong>
                    <button type="button" class="student-modal-x" onclick="closeActionPanel()" aria-label="Đóng">×</button>
                </div>
                <div id="class-student-details">
                    <div class="student-list-empty">Đang tải thông tin học viên...</div>
                </div>
                <form id="move-form" class="compact-form" style="display:none;">
                    <input type="hidden" id="panel-class-id" name="class_id">
                    <input type="hidden" id="panel-session-date" name="session_date">
                    <input type="hidden" name="action" value="move">
                    <div class="form-group">
                        <label>Ngày mới</label>
                        <input type="date" id="panel-new-date" name="new_date" required>
                    </div>
                    <div class="form-group">
                        <label>Giảng viên dạy thay (Tùy chọn)</label>
                        <select id="panel-new-user" name="new_user_id">
                            <option value="0">-- Giữ nguyên giảng viên gốc --</option>
                            <?php foreach ($usersList as $u): ?>
                                <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['full_name'] ?: $u['username']) ?></option>
                            <?php endforeach; ?>
                        </select>
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
                <thead>
                    <tr id="table-header"></tr>
                </thead>
                <tbody id="table-body"></tbody>
            </table>
        </div>
    </div>

    <script>
        let currentWeekOffset = 0;
        let activeClassId = null;
        let activeSessionDate = null;

        function escapeHtml(value) {
            return String(value ?? '').replace(/[&<>"']/g, char => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            }[char]));
        }

        function jsArg(value) {
            return escapeHtml(JSON.stringify(String(value ?? '')));
        }

        function openActionPanel(classId, className, sessionDate) {
            document.getElementById('panel-class-id').value = classId;
            document.getElementById('panel-session-date').value = sessionDate;
            document.getElementById('panel-class-name').innerText = 'Danh sách học viên';
            document.getElementById('schedule-action-modal').style.display = 'flex';
            activeClassId = classId;
            activeSessionDate = sessionDate;
            document.getElementById('panel-new-date').value = sessionDate;
            loadClassStudentDetails(classId, sessionDate);
        }

        function closeActionPanel() {
            document.getElementById('schedule-action-modal').style.display = 'none';
        }

        function studentStatusClass(status) {
            if (status === 'Present') return 'status-present';
            if (status === 'Absent') return 'status-absent';
            return 'status-expected';
        }

        function studentPrimaryStatusLabel(student) {
            return student.attendance_status === 'Expected' ? 'Dự kiến (Chưa học)' : (student.status_label || 'Dự kiến');
        }

        function renderClassStudentDetails(data) {
            const panel = document.getElementById('class-student-details');
            const classInfo = data.class || {};
            const students = data.students || [];
            const rows = students.map(student => `
                <div class="student-simple-row">
                    <div class="student-simple-main">
                        <div class="student-simple-title">
                            <span class="student-status-badge ${studentStatusClass(student.attendance_status)}">${escapeHtml(studentPrimaryStatusLabel(student))}</span>
                            <span class="student-simple-name">${escapeHtml(student.name)}</span>
                        </div>
                        <div class="student-simple-class">Lớp học: ${escapeHtml(classInfo.name || '')}</div>
                    </div>
                    <span class="student-status-badge student-status-pill ${studentStatusClass(student.attendance_status)}">${escapeHtml(student.status_label || 'Dự kiến')}</span>
                </div>
            `).join('');

            panel.innerHTML = `
                <div class="student-simple-modal">
                    ${rows || '<div class="student-list-empty">Lớp này chưa có học viên.</div>'}
                    <div class="student-modal-footer">
                        <button type="button" class="student-modal-close" onclick="closeActionPanel()">Đóng</button>
                    </div>
                </div>
            `;
        }

        async function loadClassStudentDetails(classId, sessionDate) {
            const panel = document.getElementById('class-student-details');
            panel.innerHTML = '<div class="student-list-empty">Đang tải thông tin học viên...</div>';
            try {
                const response = await fetch(`../PHP/class_students_api.php?class_id=${encodeURIComponent(classId)}&session_date=${encodeURIComponent(sessionDate || '')}`);
                if (!response.ok) {
                    panel.innerHTML = '<div class="student-list-empty">Không tải được danh sách học viên.</div>';
                    return;
                }
                renderClassStudentDetails(await response.json());
            } catch (error) {
                console.error('Student details error:', error);
                panel.innerHTML = '<div class="student-list-empty">Không tải được danh sách học viên.</div>';
            }
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
                headerRow.innerHTML = '<th style="background-color: #e2e8f0; font-weight: bold; width: 140px;">Ca dạy</th>';
                data.dates.forEach(item => {
                    headerRow.innerHTML += '<th>' + escapeHtml(item.day_name) + '<small>' + escapeHtml(item.date_formatted) + '</small></th>';
                });

                const bodyContainer = document.getElementById('table-body');
                bodyContainer.innerHTML = '';

                data.slots_definitions.forEach(slotItem => {
                    const rowHasSessions = data.dates.some(item => {
                        const daySessions = data.schedule[item.date_raw] || [];
                        return daySessions.some(s => s.slot_code === slotItem.slot_code);
                    });
                    let rowHtml = `<tr class="${rowHasSessions ? '' : 'empty-slot-row'}">`;
                    rowHtml += `<td class="slot-label-cell">${escapeHtml(slotItem.slot_label)}</td>`;

                    data.dates.forEach(item => {
                        let cellContent = '';
                        const daySessions = data.schedule[item.date_raw] || [];
                        const matchedSessions = daySessions.filter(s => s.slot_code === slotItem.slot_code);

                        if (matchedSessions.length > 0) {
                            matchedSessions.forEach(session => {
                                cellContent += `
                                    <div class="session-card" data-class-id="${escapeHtml(session.class_id || '')}" style="cursor:pointer; margin-bottom: 6px;" onclick='openActionPanel(${jsArg(session.class_id || '')}, ${jsArg(session.name)}, ${jsArg(item.date_raw)})'>
                                        <div class="class-name">${escapeHtml(session.teacher || 'Chưa gán')}</div>
                                        <div class="class-time">${escapeHtml(session.name)}</div>
                                        <div class="class-meta">Dự kiến: ${escapeHtml(session.student_count ?? 0)} HV</div>
                                    </div>`;
                            });
                        } else {
                            cellContent = '<span class="empty-day">·</span>';
                        }
                        rowHtml += `<td class="${matchedSessions.length > 0 ? '' : 'empty-cell'}">${cellContent}</td>`;
                    });

                    rowHtml += `</tr>`;
                    bodyContainer.innerHTML += rowHtml;
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
