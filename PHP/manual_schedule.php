<?php
session_start();
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$message = '';
$currentYear = (int)date('Y');
$currentWeekNumber = (int)date('W');
$selectedWeekNumber = $currentWeekNumber;
$selectedYear = $currentYear;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_manual_schedule'])) {
    $classId = (int)($_POST['class_id'] ?? 0);
    $sessionDate = trim($_POST['session_date'] ?? '');
    $newDate = trim($_POST['new_date'] ?? '');
    $newSlot = trim($_POST['new_slot'] ?? '');
    $newUserChanges = isset($_POST['new_user_id']) ? (int)$_POST['new_user_id'] : 0;
    $selectedWeekNumber = (int)($_POST['week_number'] ?? $currentWeekNumber);
    $selectedYear = (int)($_POST['week_year'] ?? $currentYear);
    $action = $_POST['save_manual_schedule'];

    if ($classId > 0 && $sessionDate !== '') {
        $notificationContext = getScheduleNotificationContext($db, $classId, $sessionDate);

        if ($action === 'delete') {
            $db->prepare('DELETE FROM class_schedule_overrides WHERE class_id = ? AND override_date = ?')->execute([$classId, $sessionDate]);
            $stmt = $db->prepare('INSERT INTO class_schedule_overrides (class_id, override_date, action_type) VALUES (?, ?, ?)');
            $stmt->execute([$classId, $sessionDate, 'delete']);
            notifyScheduleChanged($db, 'delete', $notificationContext);
            $message = "<p class='success'>Đã bỏ buổi học này khỏi lịch và đẩy các buổi tiếp theo về sau.</p>";
        } elseif ($action === 'move' && $newDate !== '' && $newSlot !== '') {
            $classStmt = $db->prepare('SELECT * FROM classes WHERE id = ? LIMIT 1');
            $classStmt->execute([$classId]);
            $targetClass = $classStmt->fetch(PDO::FETCH_ASSOC);
            $conflict = null;
            if ($targetClass && (($targetClass['class_type'] ?? 'fixed') === 'one_on_one')) {
                $teacherId = $newUserChanges > 0 ? $newUserChanges : (int)($targetClass['assigned_user_id'] ?? 0);
                $conflict = findTeacherScheduleConflict($db, $newDate, $newSlot, $teacherId, $classId);
            }

            if ($conflict) {
                $message = "<p class='error'>Lớp 1-1 bị trùng lịch với lớp " . htmlspecialchars($conflict['class_name']) . " vào ngày " . date('d/m/Y', strtotime($conflict['date'])) . " (" . htmlspecialchars($conflict['slot']) . ").</p>";
            } else {
                $db->prepare('DELETE FROM class_schedule_overrides WHERE class_id = ? AND override_date = ?')->execute([$classId, $sessionDate]);
                $stmt = $db->prepare('INSERT INTO class_schedule_overrides (class_id, override_date, new_date, new_slot, new_user_id, action_type) VALUES (?, ?, ?, ?, ?, ?)');
                $stmt->execute([$classId, $sessionDate, $newDate, $newSlot, $newUserChanges > 0 ? $newUserChanges : null, 'move']);
                notifyScheduleChanged($db, 'move', $notificationContext, [
                    'new_date' => $newDate,
                    'new_slot' => $newSlot,
                    'new_user_id' => $newUserChanges,
                ]);
                $message = "<p class='success'>Đã đổi lịch và phân công người dạy thay cho ca thành công.</p>";
            }
        } else {
            $message = "<p class='error'>Vui lòng nhập đầy đủ ngày và ca mới khi đổi lịch.</p>";
        }
    } else {
        $message = "<p class='error'>Vui lòng chọn lớp và buổi học trước.</p>";
    }
} else {
    $selectedWeekNumber = (int)($_GET['week_number'] ?? $_GET['week'] ?? $currentWeekNumber);
    $selectedYear = (int)($_GET['week_year'] ?? $_GET['year'] ?? $currentYear);
}

$classes = $db->query("SELECT * FROM classes ORDER BY class_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$usersList = $db->query("SELECT id, username, full_name FROM users WHERE status='active' ORDER BY full_name, username")->fetchAll(PDO::FETCH_ASSOC);

$selectedClassId = (int)($_POST['class_id'] ?? $_GET['class_id'] ?? ($classes[0]['id'] ?? 0));
$selectedClass = null;
$overrideRows = [];
$weekEntries = [];
$weekDates = [];
$weekLabel = '';

if ($selectedClassId > 0) {
    $stmt = $db->prepare('SELECT * FROM classes WHERE id = ? LIMIT 1');
    $stmt->execute([$selectedClassId]);
    $selectedClass = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($selectedClass) {
        $overrideStmt = $db->prepare('SELECT * FROM class_schedule_overrides WHERE class_id = ? ORDER BY override_date ASC');
        $overrideStmt->execute([$selectedClassId]);
        $overrideRows = $overrideStmt->fetchAll(PDO::FETCH_ASSOC);
        $sessionOptions = buildClassSessionDates($selectedClass, $overrideRows);

        $weekStart = new DateTime();
        $weekStart->setISODate($selectedYear, $selectedWeekNumber, 1);
        $weekEnd = clone $weekStart;
        $weekEnd->modify('+6 days');

        $weekLabel = 'Tuần ' . $selectedWeekNumber . ' năm ' . $selectedYear . ' · ' . $weekStart->format('d/m/Y') . ' - ' . $weekEnd->format('d/m/Y');
        $daysOfWeek = ['Thứ 2', 'Thứ 3', 'Thứ 4', 'Thứ 5', 'Thứ 6', 'Thứ 7', 'Chủ Nhật'];

        $temp = clone $weekStart;
        for ($i = 0; $i < 7; $i++) {
            $dateKey = $temp->format('Y-m-d');
            $weekDates[] = [ 'label' => $daysOfWeek[$i], 'date' => $dateKey, 'display' => $temp->format('d/m') ];
            $weekEntries[$dateKey] = [];
            $temp->modify('+1 day');
        }

        foreach ($sessionOptions as $session) {
            $sessionDate = $session['display_date'];
            if ($sessionDate >= $weekStart->format('Y-m-d') && $sessionDate <= $weekEnd->format('Y-m-d')) {
                $weekEntries[$sessionDate][] = $session;
            }
        }
    }
}

$slotRows = getTeachingSlotOptions($db);
$slotOptions = array_map(static fn($slot) => $slot['slot_label'], $slotRows);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Xếp Lịch Thủ Công</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../CSS/style.css?v=sidebar-fix-3">
    <style>
        .week-nav { display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:12px; }
        .week-grid { display:grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap:10px; }
        .week-day { border: 1px solid var(--border-color); border-radius: 12px; min-height: 180px; padding: 10px; background: #fff; box-shadow: var(--shadow-sm); }
        .week-day-header { font-weight: 700; margin-bottom: 8px; color: var(--primary); }
        .session-pill { width: 100%; text-align:left; border: none; border-radius: 8px; background: #eff6ff; color: #1e3a8a; padding: 8px; margin-bottom: 6px; cursor:pointer; }
        .session-pill:hover { background: #dbeafe; }
        .modal { position:fixed; inset:0; background: rgba(15,23,42,.45); display:none; align-items:center; justify-content:center; z-index:1000; padding:16px; }
        .modal-content { background:white; width:min(460px, 100%); border-radius:14px; padding:20px; box-shadow:var(--shadow-md); }
        @media (max-width: 980px) { .week-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (max-width: 640px) { .week-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <?php require_once __DIR__ . '/sidebar.php'; ?>

    <div class="main-content">
        <div class="header-wrapper">
            <div>
                <h2>Xếp Lịch Thủ Công</h2>
                <span style="font-size: 0.85rem; color: var(--text-muted);">Xem lịch theo tuần và đổi/bỏ từng buổi học nhanh chóng.</span>
            </div>
        </div>

        <?= $message ?>

        <div class="card" style="margin-bottom: 24px;">
            <form id="schedule-filter-form" method="POST" class="form-group" style="margin-bottom:0;">
                <div style="display:grid; grid-template-columns: 1fr auto auto; gap:12px; align-items:end; flex-wrap:wrap;">
                    <div>
                        <label>Chọn lớp</label>
                        <select name="class_id" id="class_id" required>
                            <option value="">-- Chọn lớp --</option>
                            <?php foreach ($classes as $c): ?>
                                <option value="<?= (int)$c['id'] ?>" <?= ($selectedClassId === (int)$c['id']) ? 'selected' : '' ?>><?= htmlspecialchars($c['class_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>Tuần</label>
                        <input type="number" name="week_number" id="week_number" min="1" max="53" value="<?= $selectedWeekNumber ?>" style="width:90px;">
                        <input type="hidden" name="week_year" id="week_year" value="<?= $selectedYear ?>">
                    </div>
                    <div>
                        <button type="submit" class="btn">Xem lịch tuần</button>
                    </div>
                </div>
            </form>
        </div>

        <?php if ($selectedClass): ?>
        <div class="card">
            <div class="week-nav">
                <div>
                    <h3 style="margin:0;">Lịch tuần của <?= htmlspecialchars($selectedClass['class_name']) ?></h3>
                    <div class="permission-helper">Tuần: <?= htmlspecialchars($weekLabel) ?></div>
                </div>
                <div style="display:flex; gap:8px;">
                    <a href="manual_schedule.php?class_id=<?= (int)$selectedClass['id'] ?>&week_number=<?= max(1, $selectedWeekNumber - 1) ?>&week_year=<?= $selectedYear ?>" class="btn" style="background:#f8fafc; color:var(--text-main); border:1px solid var(--border-color);">← Tuần trước</a>
                    <a href="manual_schedule.php?class_id=<?= (int)$selectedClass['id'] ?>&week_number=<?= $currentWeekNumber ?>&week_year=<?= $currentYear ?>" class="btn" style="background:#f8fafc; color:var(--text-main); border:1px solid var(--border-color);">Tuần hiện tại</a>
                    <a href="manual_schedule.php?class_id=<?= (int)$selectedClass['id'] ?>&week_number=<?= min(53, $selectedWeekNumber + 1) ?>&week_year=<?= $selectedYear ?>" class="btn" style="background:#f8fafc; color:var(--text-main); border:1px solid var(--border-color);">Tuần sau →</a>
                </div>
            </div>

            <div class="week-grid">
                <?php foreach ($weekDates as $day): ?>
                    <?php $dayEntries = $weekEntries[$day['date']] ?? []; ?>
                    <div class="week-day">
                        <div class="week-day-header"><?= htmlspecialchars($day['label']) ?><br><span style="font-size:0.85rem; color:var(--text-muted);"><?= htmlspecialchars($day['display']) ?></span></div>
                        <?php if (!empty($dayEntries)): ?>
                            <?php foreach ($dayEntries as $entry): ?>
                                <button type="button"
                                    class="session-pill"
                                    data-class-id="<?= (int)$selectedClass['id'] ?>"
                                    data-session-date="<?= htmlspecialchars($entry['original_date']) ?>"
                                    data-display-date="<?= htmlspecialchars($entry['display_date']) ?>"
                                    data-slot="<?= htmlspecialchars($entry['display_slot']) ?>"
                                    data-user-id="<?= htmlspecialchars($entry['assigned_user_id']) ?>"
                                    data-class-name="<?= htmlspecialchars($selectedClass['class_name']) ?>">
                                    <strong><?= htmlspecialchars($selectedClass['class_name']) ?></strong><br>
                                    <span><?= htmlspecialchars($entry['display_slot']) ?></span>
                                </button>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="permission-helper">Không có buổi</div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div id="manual-modal" class="modal">
        <div class="modal-content">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                <strong id="modal-title">Đổi lịch buổi học</strong>
                <button type="button" class="btn-delete" onclick="closeModal()" style="padding:4px 8px;">×</button>
            </div>
            <form method="POST" class="form-group" style="margin-bottom:0;">
                <input type="hidden" name="class_id" id="modal-class-id">
                <input type="hidden" name="session_date" id="modal-session-date">
                <input type="hidden" name="week_number" value="<?= $selectedWeekNumber ?>">
                <input type="hidden" name="week_year" value="<?= $selectedYear ?>">
                <div class="form-group">
                    <label>Ngày mới</label>
                    <input type="date" name="new_date" id="modal-new-date" required>
                </div>
                <div class="form-group">
                    <label>Ca mới</label>
                    <select name="new_slot" id="modal-new-slot" required>
                        <option value="">-- Chọn ca --</option>
                        <?php foreach ($slotOptions as $slot): ?>
                            <option value="<?= htmlspecialchars($slot) ?>"><?= htmlspecialchars($slot) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Giảng viên dạy thay (Tùy chọn)</label>
                    <select name="new_user_id" id="modal-new-user">
                        <option value="0">-- Giữ nguyên giảng viên gốc --</option>
                        <?php foreach ($usersList as $u): ?>
                            <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['full_name'] ?: $u['username']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top: 12px;">
                    <button type="submit" name="save_manual_schedule" value="move" class="btn">Đổi lịch buổi này</button>
                    <button type="submit" name="save_manual_schedule" value="delete" class="btn-delete">Bỏ buổi này</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const filterForm = document.getElementById('schedule-filter-form');
        const classSelect = document.getElementById('class_id');

        function submitFilter() { if (filterForm) filterForm.submit(); }
        if (classSelect) classSelect.addEventListener('change', submitFilter);

        function openModal(classId, sessionDate, displayDate, slot, userId, className) {
            document.getElementById('modal-class-id').value = classId;
            document.getElementById('modal-session-date').value = sessionDate;
            document.getElementById('modal-title').innerText = 'Đổi lịch cho ' + className + ' · ' + displayDate + ' · ' + slot;
            document.getElementById('modal-new-date').value = displayDate;
            document.getElementById('modal-new-user').value = userId;
            document.getElementById('manual-modal').style.display = 'flex';
        }

        function closeModal() { document.getElementById('manual-modal').style.display = 'none'; }

        document.querySelectorAll('.session-pill').forEach(button => {
            button.addEventListener('click', () => {
                openModal(
                    button.getAttribute('data-class-id'),
                    button.getAttribute('data-session-date'),
                    button.getAttribute('data-display-date'),
                    button.getAttribute('data-slot'),
                    button.getAttribute('data-user-id') || "0",
                    button.getAttribute('data-class-name')
                );
            });
        });

        document.getElementById('manual-modal').addEventListener('click', function (e) {
            if (e.target === this) closeModal();
        });
    </script>
</body>
</html>
