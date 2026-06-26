<?php
// Cấu hình thông tin kết nối MySQL
$host = '103.200.23.120'; // Thay đổi nếu cần thiết
$dbname = 'phamduch_lichday';
$username = 'phamduch_lichday'; // Tài khoản MySQL mặc định trên XAMPP
$password = 'Phamduchuan2005';     // Mật khẩu mặc định trên XAMPP thường để trống
$port = '3306'; 
try {
    $db = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4;port=$port", $username, $password);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Kết nối MySQL thất bại: " . $e->getMessage());
}

function ensureUserAccountColumns(PDO $db): void {
    $columns = [];
    foreach ($db->query('SHOW COLUMNS FROM users') as $row) {
        $columns[] = $row['Field'];
    }

    $changes = [];
    if (!in_array('full_name', $columns, true)) {
        $changes[] = "ADD COLUMN full_name VARCHAR(100) NULL AFTER username";
    }
    if (!in_array('status', $columns, true)) {
        $changes[] = "ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'pending' AFTER role";
    }
    if (!in_array('created_at', $columns, true)) {
        $changes[] = "ADD COLUMN created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP AFTER status";
    }

    if (!empty($changes)) {
        $db->exec('ALTER TABLE users ' . implode(', ', $changes));
    }

    $db->exec("UPDATE users SET status = 'active' WHERE role = 'admin' AND (status IS NULL OR status = '')");
    $db->exec("UPDATE users SET full_name = username WHERE (full_name IS NULL OR full_name = '')");
}

function ensurePermissionTable(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS user_view_permissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        viewer_id INT NOT NULL,
        viewed_user_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_permission (viewer_id, viewed_user_id),
        CONSTRAINT fk_permission_viewer FOREIGN KEY (viewer_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_permission_target FOREIGN KEY (viewed_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function ensureClassScheduleColumns(PDO $db): void {
    $columns = [];
    foreach ($db->query('SHOW COLUMNS FROM classes') as $row) {
        $columns[] = $row['Field'];
    }

    $changes = [];
    if (!in_array('manual_date', $columns, true)) {
        $changes[] = "ADD COLUMN manual_date DATE NULL AFTER slot_time";
    }
    if (!in_array('manual_slot', $columns, true)) {
        $changes[] = "ADD COLUMN manual_slot VARCHAR(50) NULL AFTER manual_date";
    }
    if (!in_array('assigned_user_id', $columns, true)) {
        $changes[] = "ADD COLUMN assigned_user_id INT NULL AFTER manual_slot";
    }
    if (!in_array('class_type', $columns, true)) {
        $changes[] = "ADD COLUMN class_type VARCHAR(20) NOT NULL DEFAULT 'fixed' AFTER manual_slot";
    }
    if (!in_array('flexible_slots', $columns, true)) {
        $changes[] = "ADD COLUMN flexible_slots TEXT NULL AFTER class_type";
    }

    if (!empty($changes)) {
        $db->exec('ALTER TABLE classes ' . implode(', ', $changes));
    }
}

function ensureScheduleOverrideTable(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS class_schedule_overrides (
        id INT AUTO_INCREMENT PRIMARY KEY,
        class_id INT NOT NULL,
        override_date DATE NOT NULL,
        new_date DATE NULL,
        new_slot VARCHAR(50) NULL,
        action_type VARCHAR(20) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function ensureTeachingSlotsTable(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS teaching_slots (
        id INT AUTO_INCREMENT PRIMARY KEY,
        slot_code VARCHAR(20) NOT NULL UNIQUE,
        slot_label VARCHAR(100) NOT NULL,
        start_time TIME NOT NULL,
        end_time TIME NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $defaultSlots = [
        ['S1', 'S1 (07:30 - 09:00)', '07:30:00', '09:00:00'],
        ['S2', 'S2 (09:15 - 10:45)', '09:15:00', '10:45:00'],
        ['C1', 'C1 (13:00 - 14:30)', '13:00:00', '14:30:00'],
        ['C2', 'C2 (14:45 - 16:15)', '14:45:00', '16:15:00'],
        ['T1', 'T1 (18:00 - 19:30)', '18:00:00', '19:30:00'],
        ['T2', 'T2 (19:30 - 21:00)', '19:30:00', '21:00:00'],
    ];

    foreach ($defaultSlots as $slot) {
        $stmt = $db->prepare("INSERT IGNORE INTO teaching_slots (slot_code, slot_label, start_time, end_time, is_active) VALUES (?, ?, ?, ?, 1)");
        $stmt->execute($slot);
    }
}

function getTeachingSlotOptions(PDO $db): array {
    $stmt = $db->query("SELECT slot_code, slot_label, start_time, end_time FROM teaching_slots WHERE is_active = 1 ORDER BY start_time, slot_code");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($rows)) {
        return $rows;
    }

    return [
        ['slot_code' => 'S1', 'slot_label' => 'S1 (07:30 - 09:00)', 'start_time' => '07:30:00', 'end_time' => '09:00:00'],
        ['slot_code' => 'S2', 'slot_label' => 'S2 (09:15 - 10:45)', 'start_time' => '09:15:00', 'end_time' => '10:45:00'],
        ['slot_code' => 'C1', 'slot_label' => 'C1 (13:00 - 14:30)', 'start_time' => '13:00:00', 'end_time' => '14:30:00'],
        ['slot_code' => 'C2', 'slot_label' => 'C2 (14:45 - 16:15)', 'start_time' => '14:45:00', 'end_time' => '16:15:00'],
        ['slot_code' => 'T1', 'slot_label' => 'T1 (18:00 - 19:30)', 'start_time' => '18:00:00', 'end_time' => '19:30:00'],
        ['slot_code' => 'T2', 'slot_label' => 'T2 (19:30 - 21:00)', 'start_time' => '19:30:00', 'end_time' => '21:00:00'],
    ];
}

ensureUserAccountColumns($db);
ensurePermissionTable($db);
ensureClassScheduleColumns($db);
ensureScheduleOverrideTable($db);
ensureTeachingSlotsTable($db);

// Hàm chuyển đổi thứ tiếng Việt sang số của PHP (0 = Chủ Nhật, 1 = Thứ 2, ..., 6 = Thứ 7)
function mapVnDayToNum($dayStr) {
    $map = ['CN' => 0, 'T2' => 1, 'T3' => 2, 'T4' => 3, 'T5' => 4, 'T6' => 5, 'T7' => 6];
    return $map[trim($dayStr)] ?? null;
}

// Hàm tự động tính toán các ngày học dựa trên số buổi
function generateDates($startDate, $daysArray, $totalSessions) {
    $dates = [];
    $allowedDays = array_map('mapVnDayToNum', $daysArray);
    
    $currentDate = new DateTime($startDate);
    $count = 0;
    
    // Vòng lặp tìm cho đủ số buổi
    while ($count < $totalSessions) {
        $dayOfWeek = (int)$currentDate->format('w'); // 0 (CN) đến 6 (T7)
        
        if (in_array($dayOfWeek, $allowedDays)) {
            $dates[] = $currentDate->format('Y-m-d');
            $count++;
        }
        $currentDate->modify('+1 day');
    }
    return $dates;
}

function buildClassSessionDates(array $class, array $overrides): array {
    $daysArray = explode(',', $class['schedule_days']);
    $originalDates = generateDates($class['start_date'], $daysArray, (int)$class['total_sessions']);
    $effectiveSessions = [];
    $lastDate = $class['start_date'];
    $classType = $class['class_type'] ?? 'fixed';
    $slotOptions = [];

    if ($classType === 'flexible') {
        $rawSlots = trim($class['flexible_slots'] ?? '');
        if ($rawSlots !== '') {
            $slotOptions = array_values(array_filter(array_map('trim', explode(',', $rawSlots)), static fn($value) => $value !== ''));
        }
    }
    if ($slotOptions === []) {
        $slotOptions = [$class['slot_time']];
    }

    $sessionOrder = 0;
    foreach ($originalDates as $date) {
        $override = null;
        foreach ($overrides as $row) {
            if ((int)$row['class_id'] === (int)$class['id'] && $row['override_date'] === $date) {
                $override = $row;
                break;
            }
        }

        if ($override && $override['action_type'] === 'delete') {
            continue;
        }

        $displayDate = $date;
        $displaySlot = $class['slot_time'];
        if ($classType === 'flexible' && count($slotOptions) > 0) {
            $displaySlot = $slotOptions[$sessionOrder % count($slotOptions)];
        }
        if ($override && $override['action_type'] === 'move' && !empty($override['new_date'])) {
            $displayDate = $override['new_date'];
        }
        if ($override && $override['action_type'] === 'move' && !empty($override['new_slot'])) {
            $displaySlot = $override['new_slot'];
        }

        $effectiveSessions[] = [
            'original_date' => $date,
            'display_date' => $displayDate,
            'display_slot' => $displaySlot,
        ];
        $lastDate = $date;
        $sessionOrder++;
    }

    while (count($effectiveSessions) < (int)$class['total_sessions']) {
        $cursor = new DateTime($lastDate);
        $cursor->modify('+1 day');
        $allowedDays = array_map('mapVnDayToNum', $daysArray);

        while (true) {
            $dayOfWeek = (int)$cursor->format('w');
            if (in_array($dayOfWeek, $allowedDays, true)) {
                $displaySlot = $class['slot_time'];
                if ($classType === 'flexible' && count($slotOptions) > 0) {
                    $displaySlot = $slotOptions[$sessionOrder % count($slotOptions)];
                }
                $effectiveSessions[] = [
                    'original_date' => $cursor->format('Y-m-d'),
                    'display_date' => $cursor->format('Y-m-d'),
                    'display_slot' => $displaySlot,
                ];
                $lastDate = $cursor->format('Y-m-d');
                $sessionOrder++;
                break;
            }
            $cursor->modify('+1 day');
        }
    }

    return $effectiveSessions;
}
?>