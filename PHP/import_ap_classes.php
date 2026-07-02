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
$progressDetailsPath = $inputPaths[5] ?? null;

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

$progressDetailHtmlByClassId = [];
$progressDetailDownloadErrors = [];
if ($progressDetailsPath !== null) {
    if (!is_file($progressDetailsPath)) {
        fwrite(STDERR, "Khong tim thay file HTML tien do chi tiet hoc vien: {$progressDetailsPath}\n");
        exit(1);
    }

    $progressDetailsJson = file_get_contents($progressDetailsPath);
    if ($progressDetailsJson === false || trim($progressDetailsJson) === '') {
        fwrite(STDERR, "File HTML tien do chi tiet hoc vien rong hoac khong doc duoc.\n");
        exit(1);
    }
    $decodedProgressDetails = json_decode($progressDetailsJson, true);
    if (!is_array($decodedProgressDetails)) {
        fwrite(STDERR, "File HTML tien do chi tiet hoc vien khong dung dinh dang JSON.\n");
        exit(1);
    }
    if (isset($decodedProgressDetails['details']) && is_array($decodedProgressDetails['details'])) {
        $progressDetailDownloadErrors = isset($decodedProgressDetails['errors']) && is_array($decodedProgressDetails['errors'])
            ? $decodedProgressDetails['errors']
            : [];
        $decodedProgressDetails = $decodedProgressDetails['details'];
    }

    foreach ($decodedProgressDetails as $classId => $detailHtml) {
        if (is_string($detailHtml) && trim($detailHtml) !== '') {
            $progressDetailHtmlByClassId[(int)$classId] = $detailHtml;
        }
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
    $existingRowsByDate = [];
    foreach (loadClassOverrides($db, $classId) as $row) {
        $existingRowsByDate[(string)$row['override_date']] = $row;
    }

    foreach ($expectedRows as $expectedRow) {
        $overrideDate = (string)($expectedRow['override_date'] ?? '');
        if ($overrideDate === '') {
            continue;
        }

        $existingRow = $existingRowsByDate[$overrideDate] ?? null;
        if (!$existingRow) {
            return true;
        }

        $normalizedExpected = [
            'override_date' => normalizeComparableValue($expectedRow['override_date'] ?? ''),
            'new_date' => normalizeComparableValue($expectedRow['new_date'] ?? ''),
            'new_slot' => normalizeComparableValue($expectedRow['new_slot'] ?? ''),
            'new_user_id' => (int)($expectedRow['new_user_id'] ?? 0),
            'action_type' => normalizeComparableValue($expectedRow['action_type'] ?? ''),
        ];

        if ($existingRow !== $normalizedExpected) {
            return true;
        }
    }

    return false;
}

function replaceClassOverrides(PDO $db, int $classId, array $expectedRows): void {
    if (empty($expectedRows)) {
        return;
    }

    foreach ($expectedRows as $row) {
        saveClassScheduleOverride(
            $db,
            $classId,
            (string)$row['override_date'],
            (string)($row['new_date'] ?? '') ?: null,
            (string)($row['new_slot'] ?? '') ?: null,
            (int)($row['new_user_id'] ?? 0) ?: null,
            (string)$row['action_type']
        );
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

        $attendedSessions = [];
        for ($i = 7; $i < $tds->length; $i++) {
            $cellText = cleanTextValue($tds->item($i)->textContent ?? '');
            if ($cellText === '') {
                continue;
            }
            if (!preg_match_all('/(\d{1,2})\/(\d{1,2})\/(\d{4})/u', $cellText, $dateMatches, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($dateMatches as $dateMatch) {
                $day = str_pad($dateMatch[1], 2, '0', STR_PAD_LEFT);
                $month = str_pad($dateMatch[2], 2, '0', STR_PAD_LEFT);
                $date = $dateMatch[3] . '-' . $month . '-' . $day;
                $attendedSessions[$date] = [
                    'date' => $date,
                    'slot' => $slotCode,
                ];
            }
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
            'attended_sessions' => array_values($attendedSessions),
        ];
    }

    return $rows;
}

function extractProgressDatesFromText(string $text, string $slotCode = '', bool $allowMissingYear = false, ?int $defaultYear = null): array {
    $sessions = [];
    $pattern = $allowMissingYear
        ? '/(?<!\d)(\d{1,2})\/(\d{1,2})(?:\/(\d{4}))?(?!\d)/u'
        : '/(?<!\d)(\d{1,2})\/(\d{1,2})\/(\d{4})(?!\d)/u';
    if (!preg_match_all($pattern, $text, $dateMatches, PREG_SET_ORDER)) {
        return $sessions;
    }

    $defaultYear = $defaultYear ?: (int)date('Y');
    foreach ($dateMatches as $dateMatch) {
        $dayNumber = (int)$dateMatch[1];
        $monthNumber = (int)$dateMatch[2];
        $yearNumber = isset($dateMatch[3]) && $dateMatch[3] !== '' ? (int)$dateMatch[3] : $defaultYear;
        if (!checkdate($monthNumber, $dayNumber, $yearNumber)) {
            continue;
        }

        $day = str_pad((string)$dayNumber, 2, '0', STR_PAD_LEFT);
        $month = str_pad((string)$monthNumber, 2, '0', STR_PAD_LEFT);
        $date = $yearNumber . '-' . $month . '-' . $day;
        $sessions[$date] = [
            'date' => $date,
            'slot' => $slotCode,
        ];
    }

    return $sessions;
}

function getProgressRowCells(DOMXPath $xpath, DOMNode $row): array {
    $cells = [];
    foreach ($xpath->query('./th|./td', $row) as $cell) {
        if ($cell instanceof DOMElement) {
            $cells[] = $cell;
        }
    }
    return $cells;
}

function progressCellLooksLikePresent(DOMElement $cell): bool {
    $text = cleanTextValue($cell->textContent ?? '');
    $className = strtolower((string)$cell->getAttribute('class'));
    $title = strtolower((string)$cell->getAttribute('title'));

    if (preg_match('/vang|absent|nghi|chua|chưa|khong|không|none|danger|secondary/u', $text . ' ' . $className . ' ' . $title)) {
        return false;
    }

    if ($text !== '' && $text !== '-' && $text !== '0') {
        return true;
    }

    if (preg_match('/present|success|checked|attended|co-hoc|có học/u', $className . ' ' . $title)) {
        return true;
    }

    $html = strtolower($cell->ownerDocument ? $cell->ownerDocument->saveHTML($cell) : '');
    return strpos($html, 'fa-check') !== false || strpos($html, 'glyphicon-ok') !== false || strpos($html, 'checked') !== false;
}

function extractProgressDatesFromCellText(string $text, string $slotCode, int $defaultYear): array {
    $sessions = [];
    foreach (extractProgressDatesFromText($text, $slotCode, false, $defaultYear) as $date => $session) {
        $sessions[$date] = $session;
    }

    $trimmed = trim($text);
    if (!preg_match('/^\d+\s*\/\s*\d+$/', $trimmed)) {
        foreach (extractProgressDatesFromText($text, $slotCode, true, $defaultYear) as $date => $session) {
            $sessions[$date] = $session;
        }
    }

    return $sessions;
}

function extractProgressDatesFromSessionCellText(string $text, string $slotCode, int $defaultYear): array {
    $sessions = [];
    foreach (extractProgressDatesFromText($text, $slotCode, false, $defaultYear) as $date => $session) {
        $sessions[$date] = $session;
    }
    foreach (extractProgressDatesFromText($text, $slotCode, true, $defaultYear) as $date => $session) {
        $sessions[$date] = $session;
    }
    return $sessions;
}

function progressCellLooksLikeTotal(string $text, int $index, int $cellCount): bool {
    $text = trim($text);
    if ($index !== $cellCount - 1) {
        return false;
    }
    return (bool)preg_match('/^\d+\s*\/\s*\d+$/', $text);
}

function parseProgressDetailRowsFromHtml(string $detailHtml, int $sourceClassId): array {
    $xpath = htmlToDomXPath($detailHtml);
    $rows = [];
    $defaultYear = (int)date('Y');

    foreach ($xpath->query('//table') as $table) {
        $headerDatesByIndex = [];
        $headerSlotsByIndex = [];
        foreach ($xpath->query('.//thead/tr|.//tr[th]', $table) as $headerRow) {
            foreach (getProgressRowCells($xpath, $headerRow) as $index => $cell) {
                $cellText = cleanTextValue($cell->textContent ?? '');
                $dates = extractProgressDatesFromCellText($cellText, '', $defaultYear);
                if (count($dates) === 1) {
                    $headerDatesByIndex[$index] = reset($dates);
                }
                if (preg_match('/^B\d+$/i', $cellText)) {
                    $headerSlotsByIndex[$index] = strtoupper($cellText);
                }
            }
        }

        foreach ($xpath->query('.//tr', $table) as $tr) {
            $tds = $xpath->query('./td', $tr);
            if ($tds->length < 2) {
                continue;
            }

            $cells = [];
            foreach ($tds as $cell) {
                if ($cell instanceof DOMElement) {
                    $cells[] = $cell;
                }
            }
            $studentName = '';
            $phone = '';
            $identityIndexes = [];
            if (isset($cells[0])) {
                $firstCellText = cleanTextValue($cells[0]->textContent ?? '');
                if ($firstCellText !== '' && preg_match('/[^\W\d_]/u', $firstCellText)) {
                    $studentName = $firstCellText;
                    $identityIndexes[0] = true;
                }
            }
            if (isset($cells[1])) {
                $phone = cleanPhoneValue($cells[1]->textContent ?? '');
                $identityIndexes[1] = true;
            }

            foreach ($cells as $index => $cell) {
                $cellText = cleanTextValue($cell->textContent ?? '');
                if ($cellText === '') {
                    continue;
                }

                $candidatePhone = cleanPhoneValue($cellText);
                if ($phone === '' && strlen($candidatePhone) >= 8) {
                    $phone = $candidatePhone;
                    $identityIndexes[$index] = true;
                    continue;
                }

                if ($studentName === ''
                    && preg_match('/[^\W\d_]/u', $cellText)
                    && !preg_match('/\d{1,2}\/\d{1,2}/', $cellText)
                    && !preg_match('/%|@|ca\s*\d|bu[oổ]i|l[oớ]p|gi[aá]o|t[oổ]ng|ti[eế]n|phone|sdt|sđt/i', $cellText)
                ) {
                    $studentName = $cellText;
                    $identityIndexes[$index] = true;
                }
            }

            if ($studentName === '') {
                continue;
            }

            $attendedSessions = [];
            foreach ($cells as $index => $cell) {
                if (isset($identityIndexes[$index])) {
                    continue;
                }

                $cellText = cleanTextValue($cell->textContent ?? '');
                if (progressCellLooksLikeTotal($cellText, $index, count($cells))) {
                    continue;
                }

                $slotCode = $headerSlotsByIndex[$index] ?? ('B' . max(1, $index - 1));
                if ($cellText !== '' && $cellText !== '-') {
                    foreach (extractProgressDatesFromSessionCellText($cellText, $slotCode, $defaultYear) as $session) {
                        $sessionKey = (string)($session['date'] ?? '') . '|' . (string)($session['slot'] ?? '');
                        $attendedSessions[$sessionKey] = $session;
                    }
                }

                if (isset($headerDatesByIndex[$index]) && progressCellLooksLikePresent($cell)) {
                    $date = (string)$headerDatesByIndex[$index]['date'];
                    $headerDatesByIndex[$index]['slot'] = $slotCode;
                    $attendedSessions[$date . '|' . $slotCode] = $headerDatesByIndex[$index];
                }
            }

            if (empty($attendedSessions)) {
                $fullText = cleanTextValue($tr->textContent ?? '');
                foreach (extractProgressDatesFromText($fullText, '', false, $defaultYear) as $session) {
                    $sessionKey = (string)($session['date'] ?? '') . '|' . (string)($session['slot'] ?? '');
                    $attendedSessions[$sessionKey] = $session;
                }
            }

            if (empty($attendedSessions)) {
                continue;
            }

            $rows[normalizeStudentKey($studentName)] = [
                'student_name' => $studentName,
                'phone' => $phone,
                'source_class_id' => $sourceClassId,
                'attended_sessions' => array_values($attendedSessions),
            ];
        }
    }

    return $rows;
}

function buildProgressDetailMap(array $detailHtmlByClassId): array {
    $detailMap = [];
    foreach ($detailHtmlByClassId as $sourceClassId => $detailHtml) {
        $sourceClassId = (int)$sourceClassId;
        foreach (parseProgressDetailRowsFromHtml((string)$detailHtml, $sourceClassId) as $studentKey => $row) {
            $detailMap[$sourceClassId][$studentKey] = $row;
        }
    }

    return $detailMap;
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

function normalizeProgressSourceSessions(array $sourceSessions, int $studiedSessions): array {
    $validSessions = [];
    $seenDates = [];
    foreach ($sourceSessions as $session) {
        $date = (string)($session['date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            continue;
        }
        if (isset($seenDates[$date])) {
            continue;
        }
        $seenDates[$date] = true;
        $slot = trim((string)($session['slot'] ?? ''));
        $validSessions[] = [
            'date' => $date,
            'slot' => $slot,
        ];
    }

    if ($studiedSessions > 0 && count($validSessions) > $studiedSessions) {
        $validSessions = array_slice($validSessions, 0, $studiedSessions);
    }

    return $validSessions;
}

function pruneApAttendanceSessions(PDO $db, int $classId, int $studentId, array $keptSessions): int {
    $keepKeys = [];
    $keepDates = [];
    foreach ($keptSessions as $session) {
        $keepKeys[(string)$session['date'] . '|' . (string)$session['slot']] = true;
        $keepDates[(string)$session['date']] = true;
    }

    $stmt = $db->prepare("
        SELECT id, attendance_date, slot_time
        FROM attendance
        WHERE class_id = ?
          AND student_id = ?
          AND status = 'Present'
          AND slot_time REGEXP '^B[0-9]+$'
    ");
    $stmt->execute([$classId, $studentId]);

    $deleteStmt = $db->prepare('DELETE FROM attendance WHERE id = ?');
    $deleted = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = (string)$row['attendance_date'] . '|' . (string)$row['slot_time'];
        $date = (string)$row['attendance_date'];
        if (isset($keepKeys[$key]) && isset($keepDates[$date])) {
            unset($keepDates[$date]);
            continue;
        }
        $deleteStmt->execute([(int)$row['id']]);
        $deleted++;
    }

    return $deleted;
}

function syncAttendanceFromProgress(PDO $db, array $studentProgress, array $class, bool $dryRun): array {
    $studiedSessions = max(0, (int)$studentProgress['studied_sessions']);
    $classId = (int)$class['id'];
    $studentId = (int)$studentProgress['student_id'];
    $sourceSessions = $studentProgress['attended_sessions'] ?? [];
    if ($studiedSessions <= 0 && (empty($sourceSessions) || !is_array($sourceSessions))) {
        return ['inserted' => 0, 'existing' => 0, 'deleted' => 0, 'flexible_skipped' => 0];
    }

    $inserted = 0;
    $existing = 0;
    $deleted = 0;
    if (!empty($sourceSessions) && is_array($sourceSessions)) {
        $sourceSessions = normalizeProgressSourceSessions($sourceSessions, $studiedSessions);
        foreach ($sourceSessions as $session) {
            $date = (string)($session['date'] ?? '');
            $slot = (string)($session['slot'] ?? ($studentProgress['slot_code'] ?? ($class['slot_time'] ?? '')));

            if ($dryRun) {
                $inserted++;
                continue;
            }

            $saveMode = saveAttendanceRecord($db, $classId, $studentId, $date, $slot, 'Present');
            if ($saveMode === 'updated') {
                $existing++;
                continue;
            }

            $inserted++;
        }
        if (!$dryRun) {
            $deleted += pruneApAttendanceSessions($db, $classId, $studentId, $sourceSessions);
        }

        return ['inserted' => $inserted, 'existing' => $existing, 'deleted' => $deleted, 'flexible_skipped' => 0];
    }

    if (($class['class_type'] ?? 'fixed') === 'flexible') {
        return ['inserted' => 0, 'existing' => 0, 'deleted' => 0, 'flexible_skipped' => 1];
    }

    $overrideStmt = $db->prepare('SELECT class_id, override_date, new_date, new_slot, new_user_id, action_type FROM class_schedule_overrides WHERE class_id = ?');
    $overrideStmt->execute([$classId]);
    $sessions = buildClassSessionDates($class, $overrideStmt->fetchAll(PDO::FETCH_ASSOC));

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

        $saveMode = saveAttendanceRecord($db, $classId, $studentId, $date, $slot, 'Present');
        if ($saveMode === 'updated') {
            $existing++;
            continue;
        }

        $inserted++;
    }

    return ['inserted' => $inserted, 'existing' => $existing, 'deleted' => 0, 'flexible_skipped' => 0];
}

function importProgressFromSource(PDO $db, string $progressHtml, bool $dryRun, array $progressDetailHtmlByClassId = [], array $progressDetailDownloadErrors = []): array {
    $progressRows = parseProgressRowsFromHtml($progressHtml);
    $progressDetailMap = buildProgressDetailMap($progressDetailHtmlByClassId);
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
    $attendanceDeleted = 0;
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
        $sourceClassId = (int)($row['source_class_id'] ?? 0);
        $studentKey = normalizeStudentKey($row['student_name'] ?? '');
        $detailRow = $progressDetailMap[$sourceClassId][$studentKey] ?? null;
        if ($detailRow && !empty($detailRow['attended_sessions'])) {
            $row['attended_sessions'] = $detailRow['attended_sessions'];
        }
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
        $attendanceDeleted += $attendanceResult['deleted'] ?? 0;
        $flexibleSkipped += $attendanceResult['flexible_skipped'];
    }

    return [
        'source_progress_rows' => count($progressRows),
        'detail_class_pages' => count($progressDetailHtmlByClassId),
        'detail_class_page_errors' => count($progressDetailDownloadErrors),
        'detail_students_with_dates' => array_sum(array_map('count', $progressDetailMap)),
        'progress_upserted' => $upserted,
        'progress_unchanged' => $unchanged,
        'missing_students_by_name' => $missingStudents,
        'missing_classes_by_name' => $missingClasses,
        'fixed_attendance_inserted' => $attendanceInserted,
        'fixed_attendance_existing_skipped' => $attendanceExisting,
        'attendance_reconciled_deleted' => $attendanceDeleted,
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
        $progressImportResult = importProgressFromSource($db, $progressHtml, $dryRun, $progressDetailHtmlByClassId, $progressDetailDownloadErrors);
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
