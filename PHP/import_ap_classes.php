<?php
require_once __DIR__ . '/config.php';

$dryRun = in_array('--dry-run', $argv, true);
$inputPaths = array_values(array_filter(array_slice($argv, 1), static function ($arg) {
    return $arg !== '--dry-run';
}));
$htmlPath = $inputPaths[0] ?? dirname(__DIR__) . DIRECTORY_SEPARATOR . '.codex_admin_classes.html';
$studentsHtmlPath = $inputPaths[1] ?? null;
$progressHtmlPath = $inputPaths[2] ?? null;
$slotsReportHtmlPath = $inputPaths[3] ?? null;
$centerScheduleHtmlPath = $inputPaths[4] ?? null;

if (!is_file($htmlPath)) {
    fwrite(STDERR, "Không tìm thấy file HTML nguồn: {$htmlPath}\n");
    exit(1);
}

$html = file_get_contents($htmlPath);
if ($html === false || trim($html) === '') {
    fwrite(STDERR, "File HTML nguồn rỗng hoặc không đọc được.\n");
    exit(1);
}

$studentsHtml = '';
if ($studentsHtmlPath !== null) {
    if (!is_file($studentsHtmlPath)) {
        fwrite(STDERR, "Khong tim thay file HTML hoc vien: {$studentsHtmlPath}\n");
        exit(1);
    }

    $studentsHtml = file_get_contents($studentsHtmlPath);
    if ($studentsHtml === false || trim($studentsHtml) === '') {
        fwrite(STDERR, "File HTML hoc vien rong hoac khong doc duoc.\n");
        exit(1);
    }
}

$progressHtml = '';
if ($progressHtmlPath !== null) {
    if (!is_file($progressHtmlPath)) {
        fwrite(STDERR, "Khong tim thay file HTML tien do hoc vien: {$progressHtmlPath}\n");
        exit(1);
    }

    $progressHtml = file_get_contents($progressHtmlPath);
    if ($progressHtml === false || trim($progressHtml) === '') {
        fwrite(STDERR, "File HTML tien do hoc vien rong hoac khong doc duoc.\n");
        exit(1);
    }
}

$slotsReportHtml = '';
if ($slotsReportHtmlPath !== null) {
    if (!is_file($slotsReportHtmlPath)) {
        fwrite(STDERR, "Khong tim thay file HTML bao cao ca day: {$slotsReportHtmlPath}\n");
        exit(1);
    }

    $slotsReportHtml = file_get_contents($slotsReportHtmlPath);
    if ($slotsReportHtml === false || trim($slotsReportHtml) === '') {
        fwrite(STDERR, "File HTML bao cao ca day rong hoac khong doc duoc.\n");
        exit(1);
    }
}

$centerScheduleHtml = '';
if ($centerScheduleHtmlPath !== null) {
    if (!is_file($centerScheduleHtmlPath)) {
        fwrite(STDERR, "Khong tim thay file HTML lich day trung tam: {$centerScheduleHtmlPath}\n");
        exit(1);
    }

    $centerScheduleHtml = file_get_contents($centerScheduleHtmlPath);
    if ($centerScheduleHtml === false || trim($centerScheduleHtml) === '') {
        fwrite(STDERR, "File HTML lich day trung tam rong hoac khong doc duoc.\n");
        exit(1);
    }
}

function decodeHtmlJson(string $json): array {
    $decoded = html_entity_decode($json, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $data = json_decode($decoded, true);
    return is_array($data) ? $data : [];
}

function normalizeUsername(string $name): string {
    $map = [
        'à'=>'a','á'=>'a','ạ'=>'a','ả'=>'a','ã'=>'a','â'=>'a','ầ'=>'a','ấ'=>'a','ậ'=>'a','ẩ'=>'a','ẫ'=>'a','ă'=>'a','ằ'=>'a','ắ'=>'a','ặ'=>'a','ẳ'=>'a','ẵ'=>'a',
        'è'=>'e','é'=>'e','ẹ'=>'e','ẻ'=>'e','ẽ'=>'e','ê'=>'e','ề'=>'e','ế'=>'e','ệ'=>'e','ể'=>'e','ễ'=>'e',
        'ì'=>'i','í'=>'i','ị'=>'i','ỉ'=>'i','ĩ'=>'i',
        'ò'=>'o','ó'=>'o','ọ'=>'o','ỏ'=>'o','õ'=>'o','ô'=>'o','ồ'=>'o','ố'=>'o','ộ'=>'o','ổ'=>'o','ỗ'=>'o','ơ'=>'o','ờ'=>'o','ớ'=>'o','ợ'=>'o','ở'=>'o','ỡ'=>'o',
        'ù'=>'u','ú'=>'u','ụ'=>'u','ủ'=>'u','ũ'=>'u','ư'=>'u','ừ'=>'u','ứ'=>'u','ự'=>'u','ử'=>'u','ữ'=>'u',
        'ỳ'=>'y','ý'=>'y','ỵ'=>'y','ỷ'=>'y','ỹ'=>'y','đ'=>'d',
        'À'=>'a','Á'=>'a','Ạ'=>'a','Ả'=>'a','Ã'=>'a','Â'=>'a','Ầ'=>'a','Ấ'=>'a','Ậ'=>'a','Ẩ'=>'a','Ẫ'=>'a','Ă'=>'a','Ằ'=>'a','Ắ'=>'a','Ặ'=>'a','Ẳ'=>'a','Ẵ'=>'a',
        'È'=>'e','É'=>'e','Ẹ'=>'e','Ẻ'=>'e','Ẽ'=>'e','Ê'=>'e','Ề'=>'e','Ế'=>'e','Ệ'=>'e','Ể'=>'e','Ễ'=>'e',
        'Ì'=>'i','Í'=>'i','Ị'=>'i','Ỉ'=>'i','Ĩ'=>'i',
        'Ò'=>'o','Ó'=>'o','Ọ'=>'o','Ỏ'=>'o','Õ'=>'o','Ô'=>'o','Ồ'=>'o','Ố'=>'o','Ộ'=>'o','Ổ'=>'o','Ỗ'=>'o','Ơ'=>'o','Ờ'=>'o','Ớ'=>'o','Ợ'=>'o','Ở'=>'o','Ỡ'=>'o',
        'Ù'=>'u','Ú'=>'u','Ụ'=>'u','Ủ'=>'u','Ũ'=>'u','Ư'=>'u','Ừ'=>'u','Ứ'=>'u','Ự'=>'u','Ử'=>'u','Ữ'=>'u',
        'Ỳ'=>'y','Ý'=>'y','Ỵ'=>'y','Ỷ'=>'y','Ỹ'=>'y','Đ'=>'d',
    ];
    $plain = strtr($name, $map);
    $plain = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '.', $plain));
    return trim($plain, '.') ?: 'teacher';
}

function ensureTeacher(PDO $db, string $teacherName, bool $dryRun): int {
    static $cache = [];
    $teacherName = trim($teacherName);
    if ($teacherName === '') {
        $teacherName = 'Chưa rõ giáo viên';
    }
    if (isset($cache[$teacherName])) {
        return $cache[$teacherName];
    }

    $stmt = $db->prepare('SELECT id FROM users WHERE full_name = ? OR username = ? LIMIT 1');
    $stmt->execute([$teacherName, normalizeUsername($teacherName)]);
    $id = (int)($stmt->fetchColumn() ?: 0);
    if ($id > 0) {
        return $cache[$teacherName] = $id;
    }

    if ($dryRun) {
        return $cache[$teacherName] = -1;
    }

    $base = normalizeUsername($teacherName);
    $username = $base;
    $suffix = 2;
    $check = $db->prepare('SELECT COUNT(*) FROM users WHERE username = ?');
    while (true) {
        $check->execute([$username]);
        if ((int)$check->fetchColumn() === 0) {
            break;
        }
        $username = $base . $suffix++;
    }

    $password = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    $insert = $db->prepare("INSERT INTO users (username, password, full_name, role, status) VALUES (?, ?, ?, 'user', 'active')");
    $insert->execute([$username, $password, $teacherName]);
    return $cache[$teacherName] = (int)$db->lastInsertId();
}

function sourceDayToTarget(string $day): string {
    return ['1' => 'T2', '2' => 'T3', '3' => 'T4', '4' => 'T5', '5' => 'T6', '6' => 'T7', '7' => 'CN'][$day] ?? '';
}

function sourceStatusToTarget(string $status): string {
    return ['active' => 'Active', 'paused' => 'Paused', 'closed' => 'Closed'][$status] ?? 'Active';
}

function slotLabel(array $slot): string {
    $name = $slot['name'];
    return sprintf('%s (%s - %s)', $name, substr($slot['start'], 0, 5), substr($slot['end'], 0, 5));
}

function generateSourceSessions(string $startDate, int $totalSessions, array $schedule, array $slots): array {
    if ($startDate === '' || $totalSessions <= 0 || empty($schedule)) {
        return [];
    }

    $sessions = [];
    $date = new DateTimeImmutable($startDate);
    $guard = 0;
    while (count($sessions) < $totalSessions && $guard < 1200) {
        $guard++;
        $phpDay = (int)$date->format('w');
        $sourceDay = $phpDay === 0 ? '7' : (string)$phpDay;
        if (!empty($schedule[$sourceDay]) && is_array($schedule[$sourceDay])) {
            $slotIds = array_keys($schedule[$sourceDay]);
            usort($slotIds, static function ($a, $b) use ($slots) {
                return strcmp($slots[$a]['start'] ?? '99:99:99', $slots[$b]['start'] ?? '99:99:99');
            });
            foreach ($slotIds as $slotId) {
                if (!isset($slots[$slotId])) {
                    continue;
                }
                $sessions[] = [
                    'date' => $date->format('Y-m-d'),
                    'slot' => slotLabel($slots[$slotId]),
                ];
                if (count($sessions) >= $totalSessions) {
                    break;
                }
            }
        }
        $date = $date->modify('+1 day');
    }

    return $sessions;
}

function cleanTextValue($value): string {
    $value = trim((string)$value);
    $value = preg_replace('/\s+/u', ' ', $value);
    return trim($value ?? '');
}

function cleanPhoneValue($value): string {
    $value = cleanTextValue($value);
    $value = trim($value, " \t\n\r\0\x0B,");
    return $value === '---' ? '' : $value;
}

function normalizeStudentKey(string $name): string {
    return normalizeUsername(cleanTextValue($name));
}

function parseSourceClassIds($classIds): array {
    if (is_array($classIds)) {
        $rawIds = $classIds;
    } elseif (is_int($classIds)) {
        $rawIds = [$classIds];
    } elseif (is_string($classIds) && trim($classIds) !== '') {
        $rawIds = explode(',', $classIds);
    } else {
        $rawIds = [];
    }

    $ids = [];
    foreach ($rawIds as $id) {
        $id = (int)trim((string)$id);
        if ($id > 0) {
            $ids[] = $id;
        }
    }

    return array_values(array_unique($ids));
}

function parseStudentsFromHtml(string $studentsHtml): array {
    $students = [];
    if (preg_match_all("/editStudent\\((\\{.*?\\})\\)'/s", $studentsHtml, $matches)) {
        foreach ($matches[1] as $json) {
            $student = decodeHtmlJson($json);
            $name = cleanTextValue($student['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $student['name'] = $name;
            $student['phone'] = cleanPhoneValue($student['phone'] ?? '');
            $student['source_class_ids'] = parseSourceClassIds($student['class_ids'] ?? null);
            $students[(int)($student['id'] ?? 0)] = $student;
        }
    }

    return $students;
}

function buildSourceClassMap(PDO $db): array {
    $map = [];
    $rows = $db->query("SELECT id, flexible_slots FROM classes WHERE flexible_slots LIKE '%source_ap_class_id%'")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $meta = json_decode($row['flexible_slots'] ?? '', true);
        $sourceId = (int)($meta['source_ap_class_id'] ?? 0);
        if ($sourceId > 0) {
            $map[$sourceId] = (int)$row['id'];
        }
    }
    return $map;
}

function normalizeComparableValue($value): string {
    if ($value === null) {
        return '';
    }

    return cleanTextValue((string)$value);
}

function normalizeApClassMeta($json): array {
    $meta = json_decode((string)$json, true);
    if (!is_array($meta)) {
        return [];
    }

    return [
        'source' => normalizeComparableValue($meta['source'] ?? ''),
        'source_ap_class_id' => (int)($meta['source_ap_class_id'] ?? 0),
        'source_status' => normalizeComparableValue($meta['source_status'] ?? ''),
        'source_teacher_id' => isset($meta['source_teacher_id']) ? (int)$meta['source_teacher_id'] : null,
        'source_student_count' => isset($meta['source_student_count']) ? (int)$meta['source_student_count'] : null,
        'source_expected_end_date' => normalizeComparableValue($meta['source_expected_end_date'] ?? ''),
    ];
}

function classHasChanges(array $existing, array $newData): bool {
    $stringFields = ['class_name', 'start_date', 'schedule_days', 'slot_time', 'status', 'class_type'];
    foreach ($stringFields as $field) {
        if (normalizeComparableValue($existing[$field] ?? '') !== normalizeComparableValue($newData[$field] ?? '')) {
            return true;
        }
    }

    if ((int)($existing['total_sessions'] ?? 0) !== (int)($newData['total_sessions'] ?? 0)) {
        return true;
    }

    if ((int)($existing['assigned_user_id'] ?? 0) !== (int)($newData['assigned_user_id'] ?? 0)) {
        return true;
    }

    return normalizeApClassMeta($existing['flexible_slots'] ?? '') !== normalizeApClassMeta($newData['flexible_slots'] ?? '');
}

function buildExpectedClassOverrides(int $sourceId, array $sessions, string $classType, int $teacherId): array {
    if ($classType !== 'flexible') {
        return [];
    }

    $rows = [];
    foreach ($sessions as $index => $session) {
        $rows[] = [
            'override_date' => 'AP-' . $sourceId . '-' . ($index + 1),
            'new_date' => normalizeComparableValue($session['date'] ?? ''),
            'new_slot' => normalizeComparableValue($session['slot'] ?? ''),
            'new_user_id' => $teacherId,
            'action_type' => 'move',
        ];
    }

    return $rows;
}

function loadClassOverrides(PDO $db, int $classId): array {
    $stmt = $db->prepare('SELECT override_date, new_date, new_slot, new_user_id, action_type FROM class_schedule_overrides WHERE class_id = ? ORDER BY override_date ASC, id ASC');
    $stmt->execute([$classId]);

    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rows[] = [
            'override_date' => normalizeComparableValue($row['override_date'] ?? ''),
            'new_date' => normalizeComparableValue($row['new_date'] ?? ''),
            'new_slot' => normalizeComparableValue($row['new_slot'] ?? ''),
            'new_user_id' => (int)($row['new_user_id'] ?? 0),
            'action_type' => normalizeComparableValue($row['action_type'] ?? ''),
        ];
    }

    return $rows;
}

function classOverridesHaveChanges(PDO $db, int $classId, array $expectedRows): bool {
    return loadClassOverrides($db, $classId) !== $expectedRows;
}

function replaceClassOverrides(PDO $db, int $classId, array $expectedRows): void {
    $db->prepare("DELETE FROM class_schedule_overrides WHERE class_id = ?")->execute([$classId]);
    if (empty($expectedRows)) {
        return;
    }

    $override = $db->prepare("INSERT INTO class_schedule_overrides (class_id, override_date, new_date, new_slot, new_user_id, action_type) VALUES (?, ?, ?, ?, ?, ?)");
    foreach ($expectedRows as $row) {
        $override->execute([
            $classId,
            $row['override_date'],
            $row['new_date'],
            $row['new_slot'],
            $row['new_user_id'],
            $row['action_type'],
        ]);
    }
}

function importStudentsFromSource(PDO $db, string $studentsHtml, bool $dryRun): array {
    $sourceStudents = parseStudentsFromHtml($studentsHtml);
    $uniqueStudents = [];
    $duplicateSourceRows = 0;

    foreach ($sourceStudents as $student) {
        $key = normalizeStudentKey($student['name']);
        if ($key === '') {
            continue;
        }

        if (!isset($uniqueStudents[$key])) {
            $uniqueStudents[$key] = [
                'name' => $student['name'],
                'phone' => $student['phone'],
                'source_ids' => [(int)$student['id']],
                'source_class_ids' => $student['source_class_ids'],
            ];
            continue;
        }

        $duplicateSourceRows++;
        $uniqueStudents[$key]['source_ids'][] = (int)$student['id'];
        if ($uniqueStudents[$key]['phone'] === '' && $student['phone'] !== '') {
            $uniqueStudents[$key]['phone'] = $student['phone'];
        }
        $uniqueStudents[$key]['source_class_ids'] = array_values(array_unique(array_merge(
            $uniqueStudents[$key]['source_class_ids'],
            $student['source_class_ids']
        )));
    }

    $existingByKey = [];
    foreach ($db->query('SELECT id, student_name, phone FROM students ORDER BY id ASC') as $row) {
        $key = normalizeStudentKey($row['student_name'] ?? '');
        if ($key !== '' && !isset($existingByKey[$key])) {
            $existingByKey[$key] = $row;
        }
    }

    $sourceClassMap = buildSourceClassMap($db);
    $inserted = 0;
    $updated = 0;
    $linked = 0;
    $missingClassLinks = 0;

    $insertStudent = $db->prepare('INSERT INTO students (student_name, phone) VALUES (?, ?)');
    $updateStudentPhone = $db->prepare('UPDATE students SET phone = ? WHERE id = ?');
    $findLink = $db->prepare('SELECT id FROM student_class WHERE student_id = ? AND class_id = ? LIMIT 1');
    $insertLink = $db->prepare('INSERT INTO student_class (student_id, class_id) VALUES (?, ?)');

    foreach ($uniqueStudents as $key => $student) {
        $studentId = 0;
        if (isset($existingByKey[$key])) {
            $studentId = (int)$existingByKey[$key]['id'];
            $existingPhone = cleanPhoneValue($existingByKey[$key]['phone'] ?? '');
            $sourcePhone = cleanPhoneValue($student['phone'] ?? '');
            if ($sourcePhone !== '' && $existingPhone !== $sourcePhone) {
                $updated++;
                if (!$dryRun) {
                    $updateStudentPhone->execute([$sourcePhone, $studentId]);
                    $existingByKey[$key]['phone'] = $sourcePhone;
                }
            }
        } else {
            $inserted++;
            if (!$dryRun) {
                $insertStudent->execute([$student['name'], $student['phone']]);
                $studentId = (int)$db->lastInsertId();
                $existingByKey[$key] = [
                    'id' => $studentId,
                    'student_name' => $student['name'],
                    'phone' => $student['phone'],
                ];
            } else {
                $studentId = -1;
            }
        }

        foreach ($student['source_class_ids'] as $sourceClassId) {
            $targetClassId = $sourceClassMap[$sourceClassId] ?? 0;
            if ($targetClassId <= 0) {
                $missingClassLinks++;
                continue;
            }

            $findLink->execute([$studentId, $targetClassId]);
            if (!$findLink->fetchColumn()) {
                $linked++;
                if (!$dryRun) {
                    $insertLink->execute([$studentId, $targetClassId]);
                }
            }
        }
    }

    return [
        'source_student_rows' => count($sourceStudents),
        'unique_students_by_name' => count($uniqueStudents),
        'duplicate_source_rows_by_name' => $duplicateSourceRows,
        'students_inserted' => $inserted,
        'students_updated' => $updated,
        'student_class_links_inserted' => $linked,
        'student_class_links_missing_class' => $missingClassLinks,
    ];
}

function htmlToDomXPath(string $html): DOMXPath {
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    return new DOMXPath($dom);
}

function parseProgressRowsFromHtml(string $progressHtml): array {
    $xpath = htmlToDomXPath($progressHtml);
    $rows = [];

    foreach ($xpath->query('//tbody/tr') as $tr) {
        $tds = $xpath->query('./td', $tr);
        if ($tds->length < 7) {
            continue;
        }

        $studentName = cleanTextValue($tds->item(0)->textContent ?? '');
        $phone = cleanPhoneValue($tds->item(1)->textContent ?? '');
        $teacherName = cleanTextValue($tds->item(3)->textContent ?? '');
        $slotCode = cleanTextValue($tds->item(4)->textContent ?? '');
        $studiedText = cleanTextValue($tds->item(5)->textContent ?? '');
        $progressText = cleanTextValue($tds->item(6)->textContent ?? '');

        $className = '';
        $sourceClassId = 0;
        $classLinks = $xpath->query('.//a[contains(@href, "admin_student_progress.php?class_id=")]', $tds->item(2));
        if ($classLinks->length > 0) {
            $link = $classLinks->item(0);
            if ($link instanceof DOMElement) {
                $className = cleanTextValue($link->textContent ?? '');
                $href = (string)$link->getAttribute('href');
                $classIdMatch = [];
                if (preg_match('/class_id=([0-9]+)/', $href, $classIdMatch) && isset($classIdMatch[1])) {
                    $sourceClassId = (int)$classIdMatch[1];
                }
            }
        }

        if ($studentName === '' || $className === '') {
            continue;
        }

        $studiedSessions = 0;
        $totalSessions = 0;
        if (preg_match('/(\d+)\s*\/\s*(\d+)/u', $studiedText, $match)) {
            $studiedSessions = (int)$match[1];
            $totalSessions = (int)$match[2];
        }

        $progressPercent = 0;
        if (preg_match('/(\d+)\s*%/', $progressText, $match)) {
            $progressPercent = (int)$match[1];
        } elseif ($totalSessions > 0) {
            $progressPercent = (int)round(($studiedSessions / $totalSessions) * 100);
        }

        $rows[] = [
            'student_name' => $studentName,
            'phone' => $phone,
            'source_class_id' => $sourceClassId,
            'class_name' => $className,
            'teacher_name' => $teacherName,
            'slot_code' => $slotCode,
            'studied_sessions' => $studiedSessions,
            'total_sessions' => $totalSessions,
            'progress_percent' => $progressPercent,
        ];
    }

    return $rows;
}

function buildLocalStudentNameMap(PDO $db): array {
    $map = [];
    foreach ($db->query('SELECT id, student_name FROM students ORDER BY id ASC') as $row) {
        $key = normalizeStudentKey($row['student_name'] ?? '');
        if ($key !== '' && !isset($map[$key])) {
            $map[$key] = (int)$row['id'];
        }
    }
    return $map;
}

function buildLocalClassNameMap(PDO $db): array {
    $map = [];
    foreach ($db->query('SELECT * FROM classes ORDER BY id ASC') as $row) {
        $key = normalizeUsername(cleanTextValue($row['class_name'] ?? ''));
        if ($key !== '' && !isset($map[$key])) {
            $map[$key] = $row;
        }
    }
    return $map;
}

function syncAttendanceFromProgress(PDO $db, array $studentProgress, array $class, bool $dryRun): array {
    if (($class['class_type'] ?? 'fixed') === 'flexible') {
        return ['inserted' => 0, 'existing' => 0, 'flexible_skipped' => 1];
    }

    $studiedSessions = max(0, (int)$studentProgress['studied_sessions']);
    if ($studiedSessions <= 0) {
        return ['inserted' => 0, 'existing' => 0, 'flexible_skipped' => 0];
    }

    $classId = (int)$class['id'];
    $studentId = (int)$studentProgress['student_id'];
    $overrideStmt = $db->prepare('SELECT class_id, override_date, new_date, new_slot, new_user_id, action_type FROM class_schedule_overrides WHERE class_id = ?');
    $overrideStmt->execute([$classId]);
    $sessions = buildClassSessionDates($class, $overrideStmt->fetchAll(PDO::FETCH_ASSOC));

    $findAttendance = $db->prepare('SELECT id FROM attendance WHERE class_id = ? AND student_id = ? AND attendance_date = ? LIMIT 1');
    $insertAttendance = $db->prepare("INSERT INTO attendance (class_id, student_id, attendance_date, slot_time, status) VALUES (?, ?, ?, ?, 'Present')");

    $inserted = 0;
    $existing = 0;
    $used = 0;
    foreach ($sessions as $session) {
        if ($used >= $studiedSessions) {
            break;
        }
        if (empty($session['display_date'])) {
            continue;
        }

        $used++;
        $date = (string)$session['display_date'];
        $slot = (string)($session['display_slot'] ?? ($class['slot_time'] ?? ''));

        if ($dryRun) {
            $inserted++;
            continue;
        }

        $findAttendance->execute([$classId, $studentId, $date]);
        if ($findAttendance->fetchColumn()) {
            $existing++;
            continue;
        }

        $insertAttendance->execute([$classId, $studentId, $date, $slot]);
        $inserted++;
    }

    return ['inserted' => $inserted, 'existing' => $existing, 'flexible_skipped' => 0];
}

function importProgressFromSource(PDO $db, string $progressHtml, bool $dryRun): array {
    $progressRows = parseProgressRowsFromHtml($progressHtml);
    $studentsByName = buildLocalStudentNameMap($db);
    $classesByName = buildLocalClassNameMap($db);

    $findProgress = $db->prepare('SELECT source_class_name, teacher_name, slot_code, studied_sessions, total_sessions, progress_percent
        FROM student_progress
        WHERE student_id = ? AND class_id = ?
        LIMIT 1');
    $upsertProgress = $db->prepare('INSERT INTO student_progress
        (student_id, class_id, source_class_name, teacher_name, slot_code, studied_sessions, total_sessions, progress_percent)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            source_class_name = VALUES(source_class_name),
            teacher_name = VALUES(teacher_name),
            slot_code = VALUES(slot_code),
            studied_sessions = VALUES(studied_sessions),
            total_sessions = VALUES(total_sessions),
            progress_percent = VALUES(progress_percent)');

    $upserted = 0;
    $unchanged = 0;
    $missingStudents = 0;
    $missingClasses = 0;
    $attendanceInserted = 0;
    $attendanceExisting = 0;
    $flexibleSkipped = 0;

    foreach ($progressRows as $row) {
        $studentId = $studentsByName[normalizeStudentKey($row['student_name'])] ?? 0;
        $class = $classesByName[normalizeUsername(cleanTextValue($row['class_name']))] ?? null;

        if ($studentId <= 0) {
            $missingStudents++;
            continue;
        }
        if (!$class) {
            $missingClasses++;
            continue;
        }

        $row['student_id'] = $studentId;
        $classId = (int)$class['id'];
        $newProgress = [
            'source_class_name' => normalizeComparableValue($row['class_name']),
            'teacher_name' => normalizeComparableValue($row['teacher_name']),
            'slot_code' => normalizeComparableValue($row['slot_code']),
            'studied_sessions' => (int)$row['studied_sessions'],
            'total_sessions' => (int)$row['total_sessions'],
            'progress_percent' => (int)$row['progress_percent'],
        ];

        $findProgress->execute([$studentId, $classId]);
        $existingProgress = $findProgress->fetch(PDO::FETCH_ASSOC);
        $hasProgressChanges = !$existingProgress
            || normalizeComparableValue($existingProgress['source_class_name'] ?? '') !== $newProgress['source_class_name']
            || normalizeComparableValue($existingProgress['teacher_name'] ?? '') !== $newProgress['teacher_name']
            || normalizeComparableValue($existingProgress['slot_code'] ?? '') !== $newProgress['slot_code']
            || (int)($existingProgress['studied_sessions'] ?? 0) !== $newProgress['studied_sessions']
            || (int)($existingProgress['total_sessions'] ?? 0) !== $newProgress['total_sessions']
            || (int)($existingProgress['progress_percent'] ?? 0) !== $newProgress['progress_percent'];

        if ($hasProgressChanges) {
            $upserted++;
        } else {
            $unchanged++;
        }

        if ($hasProgressChanges && !$dryRun) {
            $upsertProgress->execute([
                $studentId,
                $classId,
                $newProgress['source_class_name'],
                $newProgress['teacher_name'],
                $newProgress['slot_code'],
                $newProgress['studied_sessions'],
                $newProgress['total_sessions'],
                $newProgress['progress_percent'],
            ]);
        }

        $attendanceResult = syncAttendanceFromProgress($db, $row, $class, $dryRun);
        $attendanceInserted += $attendanceResult['inserted'];
        $attendanceExisting += $attendanceResult['existing'];
        $flexibleSkipped += $attendanceResult['flexible_skipped'];
    }

    return [
        'source_progress_rows' => count($progressRows),
        'progress_upserted' => $upserted,
        'progress_unchanged' => $unchanged,
        'missing_students_by_name' => $missingStudents,
        'missing_classes_by_name' => $missingClasses,
        'fixed_attendance_inserted' => $attendanceInserted,
        'fixed_attendance_existing_skipped' => $attendanceExisting,
        'flexible_classes_skipped_auto_attendance' => $flexibleSkipped,
    ];
}

function parseTeachingSlotsFromReport(string $slotsReportHtml): array {
    $xpath = htmlToDomXPath($slotsReportHtml);
    $slots = [];

    foreach ($xpath->query('//table[contains(concat(" ", normalize-space(@class), " "), " table-sticky ")]//thead/tr/th[position() > 1]') as $th) {
        $codeNodes = $xpath->query('.//span[contains(concat(" ", normalize-space(@class), " "), " fw-bold ")]', $th);
        $timeNodes = $xpath->query('.//div[contains(concat(" ", normalize-space(@class), " "), " text-muted ")]', $th);
        if ($codeNodes->length === 0 || $timeNodes->length === 0) {
            continue;
        }

        $slotCode = strtoupper(cleanTextValue($codeNodes->item(0)->textContent ?? ''));
        $timeText = cleanTextValue($timeNodes->item(0)->textContent ?? '');
        if ($slotCode === '' || !preg_match('/(\d{1,2}:\d{2})\s*-\s*(\d{1,2}:\d{2})/', $timeText, $match)) {
            continue;
        }

        $start = sprintf('%s:00', $match[1]);
        $end = sprintf('%s:00', $match[2]);
        $slots[$slotCode] = [
            'slot_code' => $slotCode,
            'slot_label' => sprintf('%s (%s - %s)', $slotCode, $match[1], $match[2]),
            'start_time' => $start,
            'end_time' => $end,
        ];
    }

    return array_values($slots);
}

function importTeachingSlotsFromReport(PDO $db, string $slotsReportHtml, bool $dryRun): array {
    $slots = parseTeachingSlotsFromReport($slotsReportHtml);
    $inserted = 0;
    $updated = 0;

    $find = $db->prepare('SELECT id, slot_label, start_time, end_time, is_active FROM teaching_slots WHERE slot_code = ? LIMIT 1');
    $insert = $db->prepare('INSERT INTO teaching_slots (slot_code, slot_label, start_time, end_time, is_active) VALUES (?, ?, ?, ?, 1)');
    $update = $db->prepare('UPDATE teaching_slots SET slot_label = ?, start_time = ?, end_time = ?, is_active = 1 WHERE slot_code = ?');

    foreach ($slots as $slot) {
        $find->execute([$slot['slot_code']]);
        $existing = $find->fetch(PDO::FETCH_ASSOC);

        if (!$existing) {
            $inserted++;
            if (!$dryRun) {
                $insert->execute([$slot['slot_code'], $slot['slot_label'], $slot['start_time'], $slot['end_time']]);
            }
            continue;
        }

        $hasChanges = ($existing['slot_label'] ?? '') !== $slot['slot_label']
            || ($existing['start_time'] ?? '') !== $slot['start_time']
            || ($existing['end_time'] ?? '') !== $slot['end_time']
            || (int)($existing['is_active'] ?? 0) !== 1;

        if ($hasChanges) {
            $updated++;
            if (!$dryRun) {
                $update->execute([$slot['slot_label'], $slot['start_time'], $slot['end_time'], $slot['slot_code']]);
            }
        }
    }

    return [
        'source_slots' => count($slots),
        'slots_inserted' => $inserted,
        'slots_updated' => $updated,
    ];
}

function buildSourceSlotsFromReport(string $slotsReportHtml): array {
    $slots = [];
    $sourceIdsByCode = [
        'S1' => '1',
        'S' => '7',
        'S2' => '2',
        'C1' => '3',
        'C' => '8',
        'C2' => '4',
        'T1' => '5',
        'T' => '9',
        'T2' => '6',
    ];

    foreach (parseTeachingSlotsFromReport($slotsReportHtml) as $slot) {
        $sourceId = $sourceIdsByCode[$slot['slot_code']] ?? null;
        if ($sourceId === null) {
            continue;
        }
        $slots[$sourceId] = [
            'name' => $slot['slot_code'],
            'start' => $slot['start_time'],
            'end' => $slot['end_time'],
        ];
    }
    return $slots;
}

function parseCenterScheduleRowsFromHtml(string $centerScheduleHtml): array {
    $xpath = htmlToDomXPath($centerScheduleHtml);
    $rows = [];
    $currentDate = '';

    foreach ($xpath->query('//table//tbody/tr') as $tr) {
        $tds = $xpath->query('./td', $tr);
        if ($tds->length < 6) {
            continue;
        }

        $dateText = cleanTextValue($tds->item(0)->textContent ?? '');
        if (preg_match('/(\d{2})\/(\d{2})\/(\d{4})/', $dateText, $dateMatch)) {
            $currentDate = $dateMatch[3] . '-' . $dateMatch[2] . '-' . $dateMatch[1];
        }

        $teacherName = cleanTextValue($tds->item(1)->textContent ?? '');
        $className = cleanTextValue($tds->item(2)->textContent ?? '');
        $slotCode = cleanTextValue($tds->item(3)->textContent ?? '');
        $timeText = cleanTextValue($tds->item(4)->textContent ?? '');
        $attendanceStatus = cleanTextValue($tds->item(5)->textContent ?? '');

        if ($currentDate === '' || $className === '' || $slotCode === '' || $timeText === '') {
            continue;
        }

        $rows[] = [
            'date' => $currentDate,
            'teacher_name' => $teacherName,
            'class_name' => $className,
            'slot_code' => $slotCode,
            'time_text' => $timeText,
            'slot_label' => sprintf('%s (%s)', $slotCode, $timeText),
            'attendance_status' => $attendanceStatus,
        ];
    }

    return $rows;
}

function importCenterScheduleFromSource(PDO $db, string $centerScheduleHtml, bool $dryRun): array {
    $scheduleRows = parseCenterScheduleRowsFromHtml($centerScheduleHtml);
    $classesByName = buildLocalClassNameMap($db);
    $groupedRows = [];
    $missingClasses = 0;
    $matchedRows = 0;

    foreach ($scheduleRows as $row) {
        $key = normalizeUsername(cleanTextValue($row['class_name']));
        $class = $classesByName[$key] ?? null;
        if (!$class) {
            $missingClasses++;
            continue;
        }

        $classId = (int)$class['id'];
        if (!isset($groupedRows[$classId])) {
            $groupedRows[$classId] = [
                'class' => $class,
                'rows' => [],
            ];
        }
        $groupedRows[$classId]['rows'][] = $row;
        $matchedRows++;
    }

    $classesUpdated = 0;
    $overridesReplaced = 0;
    $overrideRowsWritten = 0;
    $updateClass = $db->prepare("UPDATE classes SET schedule_days = 'Linh hoạt', slot_time = 'Xoay ca', class_type = 'flexible', assigned_user_id = ? WHERE id = ?");

    foreach ($groupedRows as $classId => $group) {
        $rows = $group['rows'];
        usort($rows, static function ($a, $b) {
            $dateCompare = strcmp($a['date'], $b['date']);
            if ($dateCompare !== 0) {
                return $dateCompare;
            }
            return strcmp($a['time_text'], $b['time_text']);
        });

        $expectedRows = [];
        $firstTeacherId = 0;
        foreach ($rows as $index => $row) {
            $teacherId = ensureTeacher($db, $row['teacher_name'], $dryRun);
            if ($firstTeacherId <= 0 && $teacherId > 0) {
                $firstTeacherId = $teacherId;
            }

            $expectedRows[] = [
                'override_date' => 'AP-LICH-' . $row['date'] . '-' . ($index + 1),
                'new_date' => $row['date'],
                'new_slot' => $row['slot_label'],
                'new_user_id' => $teacherId,
                'action_type' => 'move',
            ];
        }

        $class = $group['class'];
        $classNeedsFlexibleUpdate = ($class['class_type'] ?? '') !== 'flexible'
            || ($class['schedule_days'] ?? '') !== 'Linh hoạt'
            || ($class['slot_time'] ?? '') !== 'Xoay ca'
            || ($firstTeacherId > 0 && (int)($class['assigned_user_id'] ?? 0) !== $firstTeacherId);
        $hasOverrideChanges = classOverridesHaveChanges($db, $classId, $expectedRows);

        if ($classNeedsFlexibleUpdate || $hasOverrideChanges) {
            $classesUpdated++;
        }

        if ($dryRun) {
            if ($hasOverrideChanges) {
                $overridesReplaced++;
                $overrideRowsWritten += count($expectedRows);
            }
            continue;
        }

        if ($classNeedsFlexibleUpdate) {
            $updateClass->execute([$firstTeacherId > 0 ? $firstTeacherId : ($class['assigned_user_id'] ?? null), $classId]);
        }

        if ($hasOverrideChanges) {
            replaceClassOverrides($db, $classId, $expectedRows);
            $overridesReplaced++;
            $overrideRowsWritten += count($expectedRows);
        }
    }

    return [
        'source_schedule_rows' => count($scheduleRows),
        'matched_schedule_rows' => $matchedRows,
        'missing_classes_by_name' => $missingClasses,
        'classes_updated_from_center_schedule' => $classesUpdated,
        'class_overrides_replaced' => $overridesReplaced,
        'override_rows_written' => $overrideRowsWritten,
    ];
}

$classes = [];
if (preg_match_all("/editClass\\((\\{.*?\\})\\)'/s", $html, $matches)) {
    foreach ($matches[1] as $json) {
        $class = decodeHtmlJson($json);
        if (!empty($class['id'])) {
            $classes[(int)$class['id']] = $class;
        }
    }
}

$classSchedules = [];
$classScheduleMatches = [];
if (preg_match('/const\s+classSchedules\s*=\s*(\{.*?\});/s', $html, $classScheduleMatches) && isset($classScheduleMatches[1])) {
    $decoded = json_decode($classScheduleMatches[1], true);
    if (is_array($decoded)) {
        $classSchedules = $decoded;
    }
}

$defaultSourceSlots = [
    '1' => ['name' => 'S1', 'start' => '07:30:00', 'end' => '09:00:00'],
    '7' => ['name' => 'S',  'start' => '08:00:00', 'end' => '11:00:00'],
    '2' => ['name' => 'S2', 'start' => '09:00:00', 'end' => '10:30:00'],
    '3' => ['name' => 'C1', 'start' => '14:00:00', 'end' => '15:30:00'],
    '8' => ['name' => 'C',  'start' => '14:00:00', 'end' => '17:00:00'],
    '4' => ['name' => 'C2', 'start' => '15:30:00', 'end' => '17:00:00'],
    '5' => ['name' => 'T1', 'start' => '18:00:00', 'end' => '19:30:00'],
    '9' => ['name' => 'T',  'start' => '18:00:00', 'end' => '21:00:00'],
    '6' => ['name' => 'T2', 'start' => '19:30:00', 'end' => '21:00:00'],
];
$slots = $defaultSourceSlots;
if ($slotsReportHtml !== '') {
    $reportSlots = buildSourceSlotsFromReport($slotsReportHtml);
    if (!empty($reportSlots)) {
        $slots = $reportSlots + $defaultSourceSlots;
    }
}

ksort($classes);

$inserted = 0;
$updated = 0;
$teachers = [];
$noSchedule = [];
$fallbackStartDates = [];

if (!$dryRun) {
    $db->beginTransaction();
}

try {
    $slotsImportResult = [];
    if ($slotsReportHtml !== '') {
        $slotsImportResult = importTeachingSlotsFromReport($db, $slotsReportHtml, $dryRun);
    }

    foreach ($classes as $sourceId => $class) {
        $teacherName = trim($class['teacher_name'] ?? '');
        $teacherId = ensureTeacher($db, $teacherName, $dryRun);
        $teachers[$teacherName ?: 'Chưa rõ giáo viên'] = true;

        $schedule = $classSchedules[(string)$sourceId] ?? [];
        $startDate = trim((string)($class['start_date'] ?? ''));
        if ($startDate === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
            $createdAt = trim((string)($class['created_at'] ?? ''));
            $startDate = preg_match('/^\d{4}-\d{2}-\d{2}/', $createdAt, $dateMatch) ? $dateMatch[0] : date('Y-m-d');
            $fallbackStartDates[] = $class['class_name'] ?? ('ID ' . $sourceId);
        }

        $targetDays = [];
        foreach (array_keys($schedule) as $day) {
            $mapped = sourceDayToTarget((string)$day);
            if ($mapped !== '') {
                $targetDays[] = $mapped;
            }
        }
        $targetDays = array_values(array_unique($targetDays));

        $sessions = generateSourceSessions(
            $startDate,
            (int)($class['total_sessions'] ?? 0),
            $schedule,
            $slots
        );

        $slotId = (string)($class['slot_id'] ?? '');
        $defaultSlot = isset($slots[$slotId]) ? slotLabel($slots[$slotId]) : trim((string)($class['slot_name'] ?? 'Ca học'));
        $hasMultipleSlots = count(array_unique(array_map(static function ($session) {
            return $session['slot'];
        }, $sessions))) > 1;
        $classType = $hasMultipleSlots ? 'flexible' : 'fixed';
        $slotTime = $classType === 'flexible' ? 'Xoay ca' : $defaultSlot;
        $scheduleDays = $classType === 'flexible' ? 'Linh hoạt' : implode(',', $targetDays);

        if ($scheduleDays === '') {
            $scheduleDays = 'Linh hoạt';
            $classType = 'flexible';
            $slotTime = 'Xoay ca';
            $noSchedule[] = $class['class_name'] ?? ('ID ' . $sourceId);
        }

        $meta = json_encode([
            'source' => 'ap.tinhoccantho.vn',
            'source_ap_class_id' => $sourceId,
            'source_status' => $class['status'] ?? '',
            'source_teacher_id' => $class['teacher_id'] ?? null,
            'source_student_count' => $class['student_count'] ?? null,
            'source_expected_end_date' => $class['expected_end_date'] ?? null,
        ], JSON_UNESCAPED_UNICODE);

        $find = $db->prepare("SELECT * FROM classes WHERE flexible_slots LIKE ? LIMIT 1");
        $find->execute(['%"source_ap_class_id":' . $sourceId . '%']);
        $existingClass = $find->fetch(PDO::FETCH_ASSOC);
        $existing = (int)($existingClass['id'] ?? 0);
        $newClassData = [
            'class_name' => $class['class_name'],
            'start_date' => $startDate,
            'schedule_days' => $scheduleDays,
            'slot_time' => $slotTime,
            'total_sessions' => (int)$class['total_sessions'],
            'status' => sourceStatusToTarget((string)$class['status']),
            'class_type' => $classType,
            'flexible_slots' => $meta,
            'assigned_user_id' => $teacherId,
        ];
        $expectedOverrides = buildExpectedClassOverrides($sourceId, $sessions, $classType, $teacherId);
        $hasClassChanges = !$existingClass || classHasChanges($existingClass, $newClassData);
        $hasOverrideChanges = $existing > 0 && classOverridesHaveChanges($db, $existing, $expectedOverrides);

        if ($dryRun) {
            if ($existing > 0) {
                if ($hasClassChanges || $hasOverrideChanges) {
                    $updated++;
                }
            } else {
                $inserted++;
            }
            continue;
        }

        if ($existing > 0) {
            $targetClassId = $existing;
            if ($hasClassChanges) {
                $stmt = $db->prepare("UPDATE classes
                    SET class_name = ?, start_date = ?, schedule_days = ?, slot_time = ?, total_sessions = ?, status = ?, class_type = ?, flexible_slots = ?, assigned_user_id = ?
                    WHERE id = ?");
                $stmt->execute([
                    $newClassData['class_name'],
                    $newClassData['start_date'],
                    $newClassData['schedule_days'],
                    $newClassData['slot_time'],
                    $newClassData['total_sessions'],
                    $newClassData['status'],
                    $newClassData['class_type'],
                    $newClassData['flexible_slots'],
                    $newClassData['assigned_user_id'],
                    $targetClassId,
                ]);
            }
            if ($hasClassChanges || $hasOverrideChanges) {
                $updated++;
            }
        } else {
            $stmt = $db->prepare("INSERT INTO classes (class_name, start_date, schedule_days, slot_time, total_sessions, status, class_type, flexible_slots, assigned_user_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $newClassData['class_name'],
                $newClassData['start_date'],
                $newClassData['schedule_days'],
                $newClassData['slot_time'],
                $newClassData['total_sessions'],
                $newClassData['status'],
                $newClassData['class_type'],
                $newClassData['flexible_slots'],
                $newClassData['assigned_user_id'],
            ]);
            $targetClassId = (int)$db->lastInsertId();
            $inserted++;
        }

        if ($existing <= 0 || $hasOverrideChanges) {
            replaceClassOverrides($db, $targetClassId, $expectedOverrides);
        }
    }

    $centerScheduleImportResult = [];
    if ($centerScheduleHtml !== '') {
        $centerScheduleImportResult = importCenterScheduleFromSource($db, $centerScheduleHtml, $dryRun);
    }

    $studentImportResult = [];
    if ($studentsHtml !== '') {
        $studentImportResult = importStudentsFromSource($db, $studentsHtml, $dryRun);
    }

    $progressImportResult = [];
    if ($progressHtml !== '') {
        $progressImportResult = importProgressFromSource($db, $progressHtml, $dryRun);
    }

    if (!$dryRun) {
        $db->commit();
    }
} catch (Throwable $e) {
    if (!$dryRun && $db->inTransaction()) {
        $db->rollBack();
    }
    throw $e;
}

echo json_encode([
    'dry_run' => $dryRun,
    'source_classes' => count($classes),
    'would_insert_or_inserted' => $inserted,
    'would_update_or_updated' => $updated,
    'teachers_seen' => count($teachers),
    'classes_with_fallback_start_date' => $fallbackStartDates,
    'classes_without_schedule' => $noSchedule,
    'slots' => $slotsImportResult ?? [],
    'center_schedule' => $centerScheduleImportResult ?? [],
    'students' => $studentImportResult ?? [],
    'progress' => $progressImportResult ?? [],
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
