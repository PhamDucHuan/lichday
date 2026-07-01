<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if ($_SESSION['role'] === 'admin') {
    $users = $db->query("SELECT id, username FROM users WHERE status = 'active' ORDER BY username ASC")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $db->prepare("
        SELECT DISTINCT u.id, u.username
        FROM users u
        LEFT JOIN user_view_permissions p ON p.viewed_user_id = u.id AND p.viewer_id = ?
        WHERE u.status = 'active'
          AND (u.id = ? OR p.viewer_id IS NOT NULL)
        ORDER BY u.username ASC
    ");
    $stmt->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$target_user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$target_username = "";
foreach ($users as $u) {
    if ((int)$u['id'] === $target_user_id) {
        $target_username = $u['username'];
        break;
    }
}
if (($target_user_id === 0 || $target_username === "") && count($users) > 0) {
    $target_user_id = (int)$users[0]['id'];
    $target_username = $users[0]['username'];
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Xem Lịch Nhân Sự Khác</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../CSS/style.css?v=sidebar-fix-3">
</head>
<body>
    <?php require_once __DIR__ . '/sidebar.php'; ?>

    <div class="main-content">
        <div id="student-info-modal" class="modal" style="display:none;">
            <div class="modal-content modal-wide student-modal-card">
                <div class="student-modal-header">
                    <strong id="student-modal-title" class="student-modal-title">Danh sách học viên</strong>
                    <button type="button" class="student-modal-x" onclick="closeStudentInfoModal()" aria-label="Đóng">×</button>
                </div>
                <div id="student-modal-body">
                    <div class="student-list-empty">Đang tải thông tin học viên...</div>
                </div>
            </div>
        </div>

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

        function closeStudentInfoModal() {
            document.getElementById('student-info-modal').style.display = 'none';
        }

        function studentStatusClass(status) {
            if (status === 'Present') return 'status-present';
            if (status === 'Absent') return 'status-absent';
            return 'status-expected';
        }

        function studentPrimaryStatusLabel(student) {
            return student.attendance_status === 'Expected' ? 'Dự kiến (Chưa học)' : (student.status_label || 'Dự kiến');
        }

        function renderStudentInfo(data) {
            const title = document.getElementById('student-modal-title');
            const body = document.getElementById('student-modal-body');
            const classInfo = data.class || {};
            const students = data.students || [];
            title.innerText = 'Danh sách học viên';

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

            body.innerHTML = `
                <div class="student-simple-modal">
                    ${rows || '<div class="student-list-empty">Lớp này chưa có học viên.</div>'}
                    <div class="student-modal-footer">
                        <button type="button" class="student-modal-close" onclick="closeStudentInfoModal()">Đóng</button>
                    </div>
                </div>
            `;
        }

        async function openStudentInfoModal(classId, className, sessionDate) {
            const modal = document.getElementById('student-info-modal');
            const title = document.getElementById('student-modal-title');
            const body = document.getElementById('student-modal-body');
            title.innerText = 'Danh sách học viên';
            body.innerHTML = '<div class="student-list-empty">Đang tải thông tin học viên...</div>';
            modal.style.display = 'flex';

            try {
                const response = await fetch(`class_students_api.php?class_id=${encodeURIComponent(classId)}&session_date=${encodeURIComponent(sessionDate || '')}&user_id=${encodeURIComponent(targetUserId)}`);
                if (!response.ok) {
                    body.innerHTML = '<div class="student-list-empty">Không tải được danh sách học viên.</div>';
                    return;
                }
                renderStudentInfo(await response.json());
            } catch (error) {
                console.error('Student details error:', error);
                body.innerHTML = '<div class="student-list-empty">Không tải được danh sách học viên.</div>';
            }
        }

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
                    headerRow.innerHTML += `<th>${escapeHtml(item.day_name)}<small>${escapeHtml(item.date_formatted)}</small></th>`;
                });

                const bodyRow = document.getElementById('table-body');
                bodyRow.innerHTML = '';

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
                                    <div class="session-card" style="cursor:pointer; margin-bottom:6px;" onclick='openStudentInfoModal(${jsArg(session.class_id || '')}, ${jsArg(session.name)}, ${jsArg(item.date_raw)})'>
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
                            card.innerHTML = `<strong>${escapeHtml(item.day_name)} ${escapeHtml(item.date_formatted)}</strong><div class="permission-group" style="margin-top:8px;">${freeSlots.map(slot => `<label><input type="checkbox" checked disabled>${escapeHtml(slot)}</label>`).join('')}</div>`;
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
                            card.innerHTML = `<strong>${escapeHtml(date)}</strong><div class="permission-group" style="margin-top:8px;">${items.map(item => `<label><input type="checkbox" checked disabled>${escapeHtml(item.name)} - ${escapeHtml(item.time)} - ${escapeHtml(item.teacher || 'Chưa gán')} - ${escapeHtml(item.student_count ?? 0)} học viên</label>`).join('')}</div>`;
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
            document.getElementById('student-info-modal').addEventListener('click', function(e) {
                if (e.target === this) closeStudentInfoModal();
            });
            fetchSchedule(currentWeekOffset);
        }
    </script>
</body>
</html>
