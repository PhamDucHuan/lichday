<?php
// Cấu hình thông tin kết nối MySQL
$host = getenv('DB_HOST') ?: '103.200.23.120';
$dbname = getenv('DB_NAME') ?: 'phamduch_lichday';
$username = getenv('DB_USER') ?: 'phamduch_lichday';
$password = getenv('DB_PASS') ?: 'Phamduchuan2005';
$port = getenv('DB_PORT') ?: '3306';

// AP class sync settings.
$apSyncUsername = getenv('AP_SYNC_USERNAME') ?: 'huanpd';
$apSyncPassword = getenv('AP_SYNC_PASSWORD') ?: '123';

// Google reCAPTCHA v2 Checkbox.
$recaptchaSiteKeyConfig = '6LcXDDotAAAAADIJyLCJvPgxf-8jPvqpp4sj-Bze';
$recaptchaSecretKeyConfig = '6LcXDDotAAAAACxTdfxrCiiC6QMAjDCaGs4LyO81';
$recaptchaSiteKey = getenv('RECAPTCHA_SITE_KEY') ?: $recaptchaSiteKeyConfig;
$recaptchaSecretKey = getenv('RECAPTCHA_SECRET_KEY') ?: $recaptchaSecretKeyConfig;

// Telegram notification settings.
// Create a bot with BotFather, add it to the group, then put the bot token and group chat_id here.
$telegramBotToken = getenv('TELEGRAM_BOT_TOKEN') ?: '8939237272:AAH62zr8fgoLZFhtwCJKTn8X0j75nrVE9uM';
$telegramChatId = getenv('TELEGRAM_CHAT_ID') ?: '-5423547795';
try {
    $db = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4;port=$port",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    error_log('MySQL connection failed: ' . $e->getMessage());
    http_response_code(500);
    die('Không thể kết nối cơ sở dữ liệu.');
}

function jsonResponse(array $payload, int $statusCode = 200): void {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function verifyRecaptcha(string $secretKey, string $token, $remoteIp = null): bool {
    if ($secretKey === '') {
        return true;
    }

    if ($token === '') {
        return false;
    }

    $postData = http_build_query(array_filter([
        'secret' => $secretKey,
        'response' => $token,
        'remoteip' => $remoteIp,
    ]));

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $postData,
            'timeout' => 8,
        ],
    ]);

    $response = @file_get_contents('https://www.google.com/recaptcha/api/siteverify', false, $context);
    if ($response === false) {
        error_log('reCAPTCHA verification request failed.');
        return false;
    }

    $result = json_decode($response, true);
    return !empty($result['success']);
}

function isValidDateString(string $date): bool {
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    return $dt && $dt->format('Y-m-d') === $date;
}

function sendTelegramTextNotification(string $message): array {
    global $telegramBotToken, $telegramChatId;

    if ($telegramBotToken === '' || $telegramChatId === '') {
        return ['enabled' => false, 'sent' => 0, 'failed' => 0, 'mode' => 'telegram'];
    }

    $payload = json_encode([
        'chat_id' => $telegramChatId,
        'text' => $message,
        'disable_web_page_preview' => true,
    ], JSON_UNESCAPED_UNICODE);

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $payload,
            'timeout' => 8,
            'ignore_errors' => true,
        ],
    ]);

    $url = 'https://api.telegram.org/bot' . $telegramBotToken . '/sendMessage';
    $response = @file_get_contents($url, false, $context);
    $result = is_string($response) ? json_decode($response, true) : null;

    if (is_array($result) && !empty($result['ok'])) {
        return ['enabled' => true, 'sent' => 1, 'failed' => 0, 'mode' => 'telegram'];
    }

    error_log('Telegram notification failed: ' . ($response ?: 'No response'));
    return ['enabled' => true, 'sent' => 0, 'failed' => 1, 'mode' => 'telegram'];
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
        override_date VARCHAR(50) NOT NULL,
        new_date DATE NULL,
        new_slot VARCHAR(50) NULL,
        new_user_id INT NULL,
        action_type VARCHAR(20) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Tự động kiểm tra thêm cột nếu database cũ đã tồn tại
    $columns = [];
    foreach ($db->query('SHOW COLUMNS FROM class_schedule_overrides') as $row) {
        $columns[] = $row['Field'];
        if (($row['Field'] ?? '') === 'override_date' && stripos((string)($row['Type'] ?? ''), 'date') !== false) {
            $db->exec("ALTER TABLE class_schedule_overrides MODIFY override_date VARCHAR(50) NOT NULL");
        }
    }
    if (!in_array('new_user_id', $columns, true)) {
        $db->exec("ALTER TABLE class_schedule_overrides ADD COLUMN new_user_id INT NULL AFTER new_slot");
    }
}

function saveClassScheduleOverride(PDO $db, int $classId, string $overrideDate, ?string $newDate, ?string $newSlot, ?int $newUserId, string $actionType): string {
    $findStmt = $db->prepare('SELECT id FROM class_schedule_overrides WHERE class_id = ? AND override_date = ? LIMIT 1');
    $findStmt->execute([$classId, $overrideDate]);
    $existingId = (int)$findStmt->fetchColumn();

    if ($existingId > 0) {
        $updateStmt = $db->prepare('
            UPDATE class_schedule_overrides
            SET new_date = ?, new_slot = ?, new_user_id = ?, action_type = ?
            WHERE id = ?
        ');
        $updateStmt->execute([$newDate ?: null, $newSlot ?: null, $newUserId ?: null, $actionType, $existingId]);
        return 'updated';
    }

    $insertStmt = $db->prepare('
        INSERT INTO class_schedule_overrides (class_id, override_date, new_date, new_slot, new_user_id, action_type)
        VALUES (?, ?, ?, ?, ?, ?)
    ');
    $insertStmt->execute([$classId, $overrideDate, $newDate ?: null, $newSlot ?: null, $newUserId ?: null, $actionType]);
    return 'inserted';
}

function saveAttendanceRecord(PDO $db, int $classId, int $studentId, string $attendanceDate, string $slotTime, string $status): string {
    $status = $status === 'Absent' ? 'Absent' : 'Present';
    $findStmt = $db->prepare('
        SELECT id
        FROM attendance
        WHERE class_id = ?
          AND student_id = ?
          AND attendance_date = ?
          AND COALESCE(slot_time, "") = ?
        LIMIT 1
    ');
    $findStmt->execute([$classId, $studentId, $attendanceDate, $slotTime]);
    $existingId = (int)$findStmt->fetchColumn();

    if ($existingId > 0) {
        $updateStmt = $db->prepare('UPDATE attendance SET slot_time = ?, status = ? WHERE id = ?');
        $updateStmt->execute([$slotTime, $status, $existingId]);
        return 'updated';
    }

    $insertStmt = $db->prepare('INSERT INTO attendance (class_id, student_id, attendance_date, slot_time, status) VALUES (?, ?, ?, ?, ?)');
    $insertStmt->execute([$classId, $studentId, $attendanceDate, $slotTime, $status]);
    return 'inserted';
}

function syncUserViewPermissions(PDO $db, int $viewerId, array $viewedUserIds): array {
    $viewedUserIds = array_values(array_unique(array_filter(array_map('intval', $viewedUserIds), static function ($viewedUserId) use ($viewerId) {
        return $viewedUserId > 0 && $viewedUserId !== $viewerId;
    })));

    $existingStmt = $db->prepare('SELECT viewed_user_id FROM user_view_permissions WHERE viewer_id = ?');
    $existingStmt->execute([$viewerId]);
    $existingIds = array_map('intval', $existingStmt->fetchAll(PDO::FETCH_COLUMN));

    $existingMap = array_fill_keys($existingIds, true);
    $targetMap = array_fill_keys($viewedUserIds, true);
    $toInsert = array_values(array_filter($viewedUserIds, static fn($viewedUserId) => !isset($existingMap[$viewedUserId])));
    $toDelete = array_values(array_filter($existingIds, static fn($viewedUserId) => !isset($targetMap[$viewedUserId])));

    if (!empty($toInsert)) {
        $insertStmt = $db->prepare('INSERT IGNORE INTO user_view_permissions (viewer_id, viewed_user_id) VALUES (?, ?)');
        foreach ($toInsert as $viewedUserId) {
            $insertStmt->execute([$viewerId, $viewedUserId]);
        }
    }

    if (!empty($toDelete)) {
        $placeholders = implode(',', array_fill(0, count($toDelete), '?'));
        $deleteStmt = $db->prepare("DELETE FROM user_view_permissions WHERE viewer_id = ? AND viewed_user_id IN ($placeholders)");
        $deleteStmt->execute(array_merge([$viewerId], $toDelete));
    }

    return ['inserted' => count($toInsert), 'deleted' => count($toDelete)];
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
        ['S', 'S (08:00 - 11:00)', '08:00:00', '11:00:00'],
        ['S2', 'S2 (09:00 - 10:30)', '09:00:00', '10:30:00'],
        ['C1', 'C1 (14:00 - 15:30)', '14:00:00', '15:30:00'],
        ['C', 'C (14:00 - 17:00)', '14:00:00', '17:00:00'],
        ['C2', 'C2 (15:30 - 17:00)', '15:30:00', '17:00:00'],
        ['T1', 'T1 (18:00 - 19:30)', '18:00:00', '19:30:00'],
        ['T', 'T (18:00 - 21:00)', '18:00:00', '21:00:00'],
        ['T2', 'T2 (19:30 - 21:00)', '19:30:00', '21:00:00'],
    ];

    foreach ($defaultSlots as $slot) {
        $stmt = $db->prepare("INSERT IGNORE INTO teaching_slots (slot_code, slot_label, start_time, end_time, is_active) VALUES (?, ?, ?, ?, 1)");
        $stmt->execute($slot);
    }
}

function ensureStudentProgressTable(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS student_progress (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        class_id INT NOT NULL,
        source_class_name VARCHAR(255) NOT NULL,
        teacher_name VARCHAR(255) NULL,
        slot_code VARCHAR(50) NULL,
        studied_sessions INT NOT NULL DEFAULT 0,
        total_sessions INT NOT NULL DEFAULT 0,
        progress_percent INT NOT NULL DEFAULT 0,
        source_synced_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_student_class_progress (student_id, class_id),
        CONSTRAINT fk_progress_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
        CONSTRAINT fk_progress_class FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function addIndexIfMissing(PDO $db, string $table, string $indexName, string $definition): void {
    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = ?
          AND index_name = ?
    ");
    $stmt->execute([$table, $indexName]);
    if ((int)$stmt->fetchColumn() === 0) {
        $db->exec("ALTER TABLE {$table} ADD INDEX {$indexName} {$definition}");
    }
}

function getIndexColumns(PDO $db, string $table, string $indexName): array {
    $stmt = $db->prepare("
        SELECT column_name
        FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = ?
          AND index_name = ?
        ORDER BY seq_in_index ASC
    ");
    $stmt->execute([$table, $indexName]);
    return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function ensureAttendanceUniqueIndex(PDO $db): void {
    $columns = getIndexColumns($db, 'attendance', 'unique_attendance');
    if ($columns === ['class_id', 'student_id', 'attendance_date', 'slot_time']) {
        return;
    }

    if (!empty($columns)) {
        $db->exec('ALTER TABLE attendance DROP INDEX unique_attendance');
    }

    $db->exec('ALTER TABLE attendance ADD UNIQUE KEY unique_attendance (class_id, student_id, attendance_date, slot_time)');
}

function ensurePerformanceIndexes(PDO $db): void {
    addIndexIfMissing($db, 'classes', 'idx_classes_status', '(status)');
    addIndexIfMissing($db, 'classes', 'idx_classes_assigned_user', '(assigned_user_id)');
    addIndexIfMissing($db, 'classes', 'idx_classes_type', '(class_type)');
    addIndexIfMissing($db, 'students', 'idx_students_name', '(student_name)');
    addIndexIfMissing($db, 'student_class', 'idx_student_class_class', '(class_id)');
    addIndexIfMissing($db, 'student_class', 'idx_student_class_student', '(student_id)');
    addIndexIfMissing($db, 'student_class', 'idx_student_class_class_student', '(class_id, student_id)');
    addIndexIfMissing($db, 'attendance', 'idx_attendance_class_status', '(class_id, status)');
    addIndexIfMissing($db, 'attendance', 'idx_attendance_student_class', '(student_id, class_id)');
    addIndexIfMissing($db, 'attendance', 'idx_attendance_report_lookup', '(class_id, student_id, attendance_date)');
    ensureAttendanceUniqueIndex($db);
    addIndexIfMissing($db, 'class_schedule_overrides', 'idx_overrides_class_date', '(class_id, override_date)');
    addIndexIfMissing($db, 'user_view_permissions', 'idx_permissions_viewer', '(viewer_id)');
    addIndexIfMissing($db, 'user_view_permissions', 'idx_permissions_viewed', '(viewed_user_id)');
    addIndexIfMissing($db, 'student_progress', 'idx_progress_class', '(class_id)');
}

function ensureApplicationSchema(PDO $db): void {
    $schemaVersion = '2026-07-02-attendance-slot-unique';
    $markerFile = __DIR__ . '/.schema_ready_' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $schemaVersion);

    if (is_file($markerFile)) {
        return;
    }

    ensureUserAccountColumns($db);
    ensurePermissionTable($db);
    ensureClassScheduleColumns($db);
    ensureScheduleOverrideTable($db);
    ensureTeachingSlotsTable($db);
    ensureStudentProgressTable($db);
    ensureAttendanceUniqueIndex($db);
    $canRunHeavySchemaUpdates = PHP_SAPI === 'cli'
        || getenv('RUN_HEAVY_SCHEMA_UPDATES') === '1'
        || (isset($_GET['run_schema_indexes']) && ($_SESSION['role'] ?? '') === 'admin');
    if ($canRunHeavySchemaUpdates) {
        ensurePerformanceIndexes($db);
    }

    @file_put_contents($markerFile, date('c'));
}

function getTeachingSlotOptions(PDO $db): array {
    $stmt = $db->query("SELECT slot_code, slot_label, start_time, end_time FROM teaching_slots WHERE is_active = 1 ORDER BY start_time, slot_code");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($rows)) {
        return $rows;
    }

    return [
        ['slot_code' => 'S1', 'slot_label' => 'S1 (07:30 - 09:00)', 'start_time' => '07:30:00', 'end_time' => '09:00:00'],
        ['slot_code' => 'S', 'slot_label' => 'S (08:00 - 11:00)', 'start_time' => '08:00:00', 'end_time' => '11:00:00'],
        ['slot_code' => 'S2', 'slot_label' => 'S2 (09:00 - 10:30)', 'start_time' => '09:00:00', 'end_time' => '10:30:00'],
        ['slot_code' => 'C1', 'slot_label' => 'C1 (14:00 - 15:30)', 'start_time' => '14:00:00', 'end_time' => '15:30:00'],
        ['slot_code' => 'C', 'slot_label' => 'C (14:00 - 17:00)', 'start_time' => '14:00:00', 'end_time' => '17:00:00'],
        ['slot_code' => 'C2', 'slot_label' => 'C2 (15:30 - 17:00)', 'start_time' => '15:30:00', 'end_time' => '17:00:00'],
        ['slot_code' => 'T1', 'slot_label' => 'T1 (18:00 - 19:30)', 'start_time' => '18:00:00', 'end_time' => '19:30:00'],
        ['slot_code' => 'T', 'slot_label' => 'T (18:00 - 21:00)', 'start_time' => '18:00:00', 'end_time' => '21:00:00'],
        ['slot_code' => 'T2', 'slot_label' => 'T2 (19:30 - 21:00)', 'start_time' => '19:30:00', 'end_time' => '21:00:00'],
    ];
}

function extractTeachingSlotCode($slotLabel, array $slotsData) {
    $slotLabel = trim((string)$slotLabel);
    if ($slotLabel === '') {
        return null;
    }

    $slotCodes = array_values(array_filter(array_map(static function ($slot) {
        return trim((string)($slot['slot_code'] ?? ''));
    }, $slotsData)));

    usort($slotCodes, static function ($a, $b) {
        $lengthCompare = strlen($b) <=> strlen($a);
        return $lengthCompare !== 0 ? $lengthCompare : strcmp($a, $b);
    });

    foreach ($slotCodes as $code) {
        if (preg_match('/^' . preg_quote($code, '/') . '(?:\b|\s|\()/iu', $slotLabel)) {
            return $code;
        }
    }

    return null;
}

ensureApplicationSchema($db);

// Hàm chuyển đổi thứ tiếng Việt sang số của PHP (0 = Chủ Nhật, 1 = Thứ 2, ..., 6 = Thứ 7)
function mapVnDayToNum($dayStr) {
    $map = ['CN' => 0, 'T2' => 1, 'T3' => 2, 'T4' => 3, 'T5' => 4, 'T6' => 5, 'T7' => 6];
    return $map[trim($dayStr)] ?? null;
}

// Hàm tự động tính toán các ngày học dựa trên số buổi
function generateDates($startDate, $daysArray, $totalSessions) {
    $dates = [];
    $allowedDays = array_values(array_filter(array_map('mapVnDayToNum', $daysArray), static function ($day) {
        return $day !== null;
    }));
    if ($totalSessions <= 0 || empty($allowedDays)) {
        return $dates;
    }
    
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
    global $db, $preloadedClassAbsentCounts;
    
    $classType = $class['class_type'] ?? 'fixed';
    $classId = (int)$class['id'];
    $overridesByDate = [];
    foreach ($overrides as $row) {
        if ((int)$row['class_id'] === $classId) {
            $overridesByDate[$row['override_date']] = $row;
        }
    }
    
    // NẾU LÀ LỚP XOAY CA LINH HOẠT: Hoàn toàn lấy lịch xếp bằng tay thủ công
    if ($classType === 'flexible') {
        $effectiveSessions = [];
        // Lọc ra các bản ghi xếp lịch thủ công của lớp này
        $classOverrides = array_filter($overridesByDate, static function ($row) {
            return $row['action_type'] === 'move';
        });
        
        // Sắp xếp các buổi học theo ngày tăng dần
        usort($classOverrides, static function ($a, $b) {
            return strcmp($a['new_date'], $b['new_date']);
        });
        
        reset($classOverrides);
        for ($i = 0; $i < (int)$class['total_sessions']; $i++) {
            $currentOverride = current($classOverrides);
            if ($currentOverride) {
                $effectiveSessions[] = [
                    'original_date' => $currentOverride['override_date'], // Ngày định danh gốc
                    'display_date' => $currentOverride['new_date'],
                    'display_slot' => $currentOverride['new_slot'],
                    'assigned_user_id' => $currentOverride['new_user_id'] ?: $class['assigned_user_id']
                ];
                next($classOverrides);
            } else {
                // Nếu chưa được xếp đủ số buổi bằng tay, tạo các buổi chờ (placeholder) để hiển thị cấu hình xếp lịch
                $effectiveSessions[] = [
                    'original_date' => "WAIT-SESSION-{$i}", 
                    'display_date' => null, // Chưa xếp lịch ngày cụ thể
                    'display_slot' => 'Chưa xếp lịch',
                    'assigned_user_id' => $class['assigned_user_id']
                ];
            }
        }
        return $effectiveSessions;
    }

    // NẾU LÀ LỚP CỐ ĐỊNH: Giữ nguyên logic tính tự động cũ
    $daysArray = explode(',', $class['schedule_days']);
    if (is_array($preloadedClassAbsentCounts ?? null)) {
        $absentCounts = $preloadedClassAbsentCounts;
    } else {
        static $absentCounts = null;
        if ($absentCounts === null) {
            $absentCounts = [];
            foreach ($db->query("SELECT class_id, COUNT(*) AS total FROM attendance WHERE status = 'Absent' GROUP BY class_id") as $row) {
                $absentCounts[(int)$row['class_id']] = (int)$row['total'];
            }
        }
    }
    $absentCount = $absentCounts[$classId] ?? 0;

    $actualTotalSessions = (int)$class['total_sessions'] + $absentCount;
    $originalDates = generateDates($class['start_date'], $daysArray, $actualTotalSessions);
    $effectiveSessions = [];
    $lastDate = $class['start_date'];

    foreach ($originalDates as $sessionOrder => $date) {
        $override = $overridesByDate[$date] ?? null;

        if ($override && $override['action_type'] === 'delete') {
            continue;
        }

        $displayDate = $date;
        $displaySlot = $class['slot_time'];
        $assignedUserId = $class['assigned_user_id'];

        if ($override && $override['action_type'] === 'move') {
            if (!empty($override['new_date'])) $displayDate = $override['new_date'];
            if (!empty($override['new_slot'])) $displaySlot = $override['new_slot'];
            if (!empty($override['new_user_id'])) $assignedUserId = $override['new_user_id'];
        }

        $effectiveSessions[] = [
            'original_date' => $date,
            'display_date' => $displayDate,
            'display_slot' => $displaySlot,
            'assigned_user_id' => $assignedUserId
        ];
        $lastDate = $date;
    }

    return $effectiveSessions;
}

function formatVnScheduleDate($date): string {
    if (!$date || !isValidDateString($date)) {
        return 'Chưa có ngày';
    }

    return date('d/m/Y', strtotime($date));
}

function findTeacherScheduleConflict(PDO $db, string $date, string $slot, int $assignedUserId, int $excludeClassId = 0) {
    if (!isValidDateString($date) || $slot === '' || $assignedUserId <= 0) {
        return null;
    }

    $classStmt = $db->prepare("
        SELECT DISTINCT c.*
        FROM classes c
        LEFT JOIN class_schedule_overrides o
            ON o.class_id = c.id
           AND o.action_type = 'move'
           AND o.new_user_id = ?
        WHERE c.status = 'Active'
          AND c.id <> ?
          AND (c.assigned_user_id = ? OR o.class_id IS NOT NULL)
    ");
    $classStmt->execute([$assignedUserId, $excludeClassId, $assignedUserId]);
    $classes = $classStmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($classes)) {
        return null;
    }

    $classIds = array_map(static fn($class) => (int)$class['id'], $classes);
    $classPlaceholders = implode(',', array_fill(0, count($classIds), '?'));
    $overrideStmt = $db->prepare("SELECT class_id, override_date, new_date, new_slot, new_user_id, action_type FROM class_schedule_overrides WHERE class_id IN ($classPlaceholders)");
    $overrideStmt->execute($classIds);
    $overrideRows = $overrideStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($classes as $class) {
        if ((int)$class['id'] === $excludeClassId) {
            continue;
        }

        foreach (buildClassSessionDates($class, $overrideRows) as $session) {
            if (($session['display_date'] ?? '') !== $date || ($session['display_slot'] ?? '') !== $slot) {
                continue;
            }

            if ((int)($session['assigned_user_id'] ?? $class['assigned_user_id'] ?? 0) === $assignedUserId) {
                return [
                    'class_id' => (int)$class['id'],
                    'class_name' => $class['class_name'] ?? 'Không rõ lớp',
                    'date' => $date,
                    'slot' => $slot,
                ];
            }
        }
    }

    return null;
}

function getUserDisplayNameById(PDO $db, int $userId): string {
    if ($userId <= 0) {
        return 'Giữ nguyên giáo viên gốc';
    }

    $stmt = $db->prepare('SELECT username, full_name FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        return 'Không rõ';
    }

    return $user['full_name'] ?: $user['username'];
}

function getScheduleNotificationContext(PDO $db, int $classId, string $sessionDate) {
    if ($classId <= 0 || $sessionDate === '') {
        return null;
    }

    $stmt = $db->prepare('SELECT * FROM classes WHERE id = ? LIMIT 1');
    $stmt->execute([$classId]);
    $class = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$class) {
        return null;
    }

    $overrideStmt = $db->prepare('SELECT class_id, override_date, new_date, new_slot, new_user_id, action_type FROM class_schedule_overrides WHERE class_id = ?');
    $overrideStmt->execute([$classId]);
    $sessions = buildClassSessionDates($class, $overrideStmt->fetchAll(PDO::FETCH_ASSOC));

    $targetSession = null;
    foreach ($sessions as $session) {
        if (($session['original_date'] ?? '') === $sessionDate || ($session['display_date'] ?? '') === $sessionDate) {
            $targetSession = $session;
            break;
        }
    }

    if (!$targetSession) {
        $targetSession = [
            'original_date' => $sessionDate,
            'display_date' => $sessionDate,
            'display_slot' => $class['slot_time'] ?? 'Ca học',
            'assigned_user_id' => $class['assigned_user_id'] ?? 0,
        ];
    }

    return [
        'class_name' => $class['class_name'] ?? 'Không rõ lớp',
        'old_date' => $targetSession['display_date'] ?? $sessionDate,
        'old_slot' => $targetSession['display_slot'] ?? ($class['slot_time'] ?? 'Ca học'),
        'old_teacher' => getUserDisplayNameById($db, (int)($targetSession['assigned_user_id'] ?? $class['assigned_user_id'] ?? 0)),
    ];
}

function notifyScheduleChanged(PDO $db, string $action, $context, array $changes = []): array {
    if (!$context) {
        return ['enabled' => false, 'sent' => 0, 'failed' => 0];
    }

    $actor = $_SESSION['display_name'] ?? $_SESSION['username'] ?? 'Người dùng';

    if ($action === 'delete') {
        $message = "[Lịch Dạy Nội Bộ]\n"
            . "Đã xóa lịch lớp: {$context['class_name']}\n"
            . "Buổi đã xóa: " . formatVnScheduleDate($context['old_date']) . " - {$context['old_slot']}\n"
            . "Giáo viên: {$context['old_teacher']}\n"
            . "Người thao tác: {$actor}";

        return sendTelegramTextNotification($message);
    }

    $newTeacherId = (int)($changes['new_user_id'] ?? 0);
    $newTeacher = $newTeacherId > 0 ? getUserDisplayNameById($db, $newTeacherId) : $context['old_teacher'];
    $newDate = $changes['new_date'] ?? '';
    $newSlot = $changes['new_slot'] ?? '';

    $message = "[Lịch Dạy Nội Bộ]\n"
        . "Đã đổi lịch lớp: {$context['class_name']}\n"
        . "Lịch cũ: " . formatVnScheduleDate($context['old_date']) . " - {$context['old_slot']}\n"
        . "Lịch mới: " . formatVnScheduleDate($newDate) . " - {$newSlot}\n"
        . "Giáo viên: {$newTeacher}\n"
        . "Người thao tác: {$actor}";

    return sendTelegramTextNotification($message);
}
?>
