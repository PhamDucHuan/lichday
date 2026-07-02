<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

function reportCleanSheetName(string $name, array &$usedNames): string {
    $name = preg_replace('/[\\\\\\/\\?\\*\\[\\]:]+/', ' ', trim($name));
    $name = preg_replace('/\\s+/', ' ', $name ?: 'Sheet');
    $name = function_exists('mb_substr') ? mb_substr($name, 0, 31, 'UTF-8') : substr($name, 0, 31);
    $base = $name;
    $suffix = 2;

    while (isset($usedNames[$name])) {
        $tail = ' ' . $suffix++;
        if (function_exists('mb_substr') && function_exists('mb_strlen')) {
            $name = mb_substr($base, 0, 31 - mb_strlen($tail, 'UTF-8'), 'UTF-8') . $tail;
        } else {
            $name = substr($base, 0, 31 - strlen($tail)) . $tail;
        }
    }

    $usedNames[$name] = true;
    return $name;
}

function reportXml($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function reportDateLabel($date): string {
    return is_string($date) && isValidDateString($date) ? date('d/m/Y', strtotime($date)) : '';
}

function reportWeekdayLabel($date): string {
    if (!is_string($date) || !isValidDateString($date)) {
        return '';
    }

    return ['CN', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7'][(int)date('w', strtotime($date))] ?? '';
}

function reportSessionKey($date, $slot): string {
    return trim((string)$date) . '|' . trim((string)$slot);
}

function reportBuildData(PDO $db, int $filterClassId = 0, int $filterTeacherId = 0, string $searchStudent = ''): array {
    $reportParams = [];
    $reportSql = "
        SELECT
            c.*,
            s.id AS report_student_id,
            s.student_name AS report_student_name,
            s.phone AS report_student_phone,
            sp.slot_code AS report_slot_code,
            sp.studied_sessions AS report_studied_sessions,
            sp.total_sessions AS report_total_sessions,
            sp.progress_percent AS report_progress_percent
        FROM classes c
        JOIN student_class sc ON sc.class_id = c.id
        JOIN students s ON s.id = sc.student_id
        LEFT JOIN student_progress sp ON sp.class_id = c.id AND sp.student_id = s.id
        WHERE c.status = 'Active'
    ";
    if ($filterClassId > 0) {
        $reportSql .= ' AND c.id = ?';
        $reportParams[] = $filterClassId;
    } elseif ($filterTeacherId > 0) {
        $reportSql .= ' AND c.assigned_user_id = ?';
        $reportParams[] = $filterTeacherId;
    }
    if ($searchStudent !== '') {
        $reportSql .= ' AND (s.student_name LIKE ? OR s.phone LIKE ?)';
        $reportParams[] = '%' . $searchStudent . '%';
        $reportParams[] = '%' . $searchStudent . '%';
    }
    $reportSql .= ' ORDER BY c.class_name ASC, s.student_name ASC';

    $reportStmt = $db->prepare($reportSql);
    $reportStmt->execute($reportParams);
    $reportRows = $reportStmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($reportRows)) {
        return [];
    }

    $classesById = [];
    $studentsByClass = [];
    $allStudentIds = [];
    foreach ($reportRows as $row) {
        $classId = (int)$row['id'];
        if (!isset($classesById[$classId])) {
            $class = $row;
            unset(
                $class['report_student_id'],
                $class['report_student_name'],
                $class['report_student_phone'],
                $class['report_slot_code'],
                $class['report_studied_sessions'],
                $class['report_total_sessions'],
                $class['report_progress_percent']
            );
            $classesById[$classId] = $class;
        }

        $studentId = (int)$row['report_student_id'];
        $studentsByClass[$classId][] = [
            'id' => $studentId,
            'student_name' => $row['report_student_name'],
            'phone' => $row['report_student_phone'],
            'progress' => [
                'slot_code' => $row['report_slot_code'],
                'studied_sessions' => $row['report_studied_sessions'],
                'total_sessions' => $row['report_total_sessions'],
                'progress_percent' => $row['report_progress_percent'],
            ],
        ];
        $allStudentIds[$studentId] = true;
    }

    $classes = array_values($classesById);
    $classIds = array_keys($classesById);
    $classPlaceholders = implode(',', array_fill(0, count($classIds), '?'));
    $studentIds = array_keys($allStudentIds);
    $studentPlaceholders = implode(',', array_fill(0, count($studentIds), '?'));

    $overridesByClass = [];
    $overrideStmt = $db->prepare("SELECT class_id, override_date, new_date, new_slot, new_user_id, action_type FROM class_schedule_overrides WHERE class_id IN ($classPlaceholders)");
    $overrideStmt->execute($classIds);
    foreach ($overrideStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $overridesByClass[(int)$row['class_id']][] = $row;
    }

    $attendanceByClassStudent = [];
    $attendanceStmt = $db->prepare("
        SELECT class_id, student_id, attendance_date, slot_time, status
        FROM attendance
        WHERE class_id IN ($classPlaceholders)
          AND student_id IN ($studentPlaceholders)
        ORDER BY class_id ASC, student_id ASC, attendance_date ASC, slot_time ASC
    ");
    $attendanceStmt->execute(array_merge($classIds, $studentIds));
    foreach ($attendanceStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $classId = (int)$row['class_id'];
        $studentId = (int)$row['student_id'];
        $date = (string)$row['attendance_date'];
        $slot = (string)($row['slot_time'] ?? '');
        $status = (string)($row['status'] ?? '');
        $attendanceByClassStudent[$classId][$studentId]['by_session'][reportSessionKey($date, $slot)] = $status;
        $attendanceByClassStudent[$classId][$studentId]['by_date'][$date] = $status;
        if ($status === 'Present') {
            if (isset($attendanceByClassStudent[$classId][$studentId]['present_date_seen'][$date])) {
                continue;
            }
            $attendanceByClassStudent[$classId][$studentId]['present_date_seen'][$date] = true;
            $attendanceByClassStudent[$classId][$studentId]['present'] = (int)($attendanceByClassStudent[$classId][$studentId]['present'] ?? 0) + 1;
            $dateLabel = reportDateLabel($date);
            $attendanceByClassStudent[$classId][$studentId]['present_sessions'][] = [
                'date' => $dateLabel !== '' ? $dateLabel : 'Chưa có ngày cụ thể',
                'weekday' => $dateLabel !== '' ? reportWeekdayLabel($date) : '',
                'slot' => $slot,
                'source' => 'attendance',
            ];
        } elseif ($status === 'Absent') {
            $attendanceByClassStudent[$classId][$studentId]['absent'] = (int)($attendanceByClassStudent[$classId][$studentId]['absent'] ?? 0) + 1;
        }
    }

    $classAbsentCounts = [];
    $absentCountStmt = $db->prepare("SELECT class_id, COUNT(*) AS total FROM attendance WHERE status = 'Absent' AND class_id IN ($classPlaceholders) GROUP BY class_id");
    $absentCountStmt->execute($classIds);
    foreach ($absentCountStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $classAbsentCounts[(int)$row['class_id']] = (int)$row['total'];
    }

    $report = [];
    $previousPreloadedClassAbsentCounts = $GLOBALS['preloadedClassAbsentCounts'] ?? null;
    $GLOBALS['preloadedClassAbsentCounts'] = $classAbsentCounts;
    foreach ($classes as $class) {
        $classId = (int)$class['id'];
        $sessions = array_values(array_filter(buildClassSessionDates($class, $overridesByClass[$classId] ?? []), static function ($session) {
            return !empty($session['display_date']) && isValidDateString((string)$session['display_date']);
        }));
        usort($sessions, static function ($a, $b) {
            $dateCompare = strcmp((string)$a['display_date'], (string)$b['display_date']);
            if ($dateCompare !== 0) {
                return $dateCompare;
            }
            return strcmp((string)($a['display_slot'] ?? ''), (string)($b['display_slot'] ?? ''));
        });

        $students = $studentsByClass[$classId] ?? [];
        if (empty($students)) {
            continue;
        }

        $reportStudents = [];
        foreach ($students as $student) {
            $studentId = (int)$student['id'];
            $attendance = $attendanceByClassStudent[$classId][$studentId] ?? [];
            $presentCount = (int)($attendance['present'] ?? 0);
            $absentCount = (int)($attendance['absent'] ?? 0);
            $progress = $student['progress'] ?? null;
            $totalSessions = (int)($progress['total_sessions'] ?? $class['total_sessions'] ?? count($sessions));
            $studiedSessions = (int)($progress['studied_sessions'] ?? $presentCount);
            $progressPercent = (int)($progress['progress_percent'] ?? ($totalSessions > 0 ? min(100, round(($presentCount / $totalSessions) * 100)) : 0));

            $sessionValues = [];
            $attendedSessions = $attendance['present_sessions'] ?? [];
            if ($studiedSessions > 0 && count($attendedSessions) > $studiedSessions) {
                $attendedSessions = array_slice($attendedSessions, 0, $studiedSessions);
            }
            $presentCount = min($presentCount, max($studiedSessions, 0));
            $scheduledSessions = [];
            foreach ($sessions as $session) {
                $date = (string)$session['display_date'];
                $slot = (string)($session['display_slot'] ?? '');
                $status = $attendance['by_session'][reportSessionKey($date, $slot)] ?? ($attendance['by_date'][$date] ?? '');
                $dateLabel = reportDateLabel($date);
                $sessionDetail = [
                    'date' => $dateLabel !== '' ? $dateLabel : 'Chưa có ngày cụ thể',
                    'weekday' => $dateLabel !== '' ? reportWeekdayLabel($date) : '',
                    'slot' => $slot,
                    'source' => 'attendance',
                ];
                $scheduledSessions[] = $sessionDetail + ['status' => $status];

                if ($status === 'Present') {
                    $sessionValues[] = $dateLabel;
                } elseif ($status === 'Absent') {
                    $sessionValues[] = 'Vắng ' . reportDateLabel($date);
                } else {
                    $sessionValues[] = '';
                }
            }

            $missingProgressSessions = max(0, $studiedSessions - count($attendedSessions));
            if ($missingProgressSessions > 0) {
                foreach ($scheduledSessions as $sessionDetail) {
                    if ($missingProgressSessions <= 0) {
                        break;
                    }
                    if (($sessionDetail['status'] ?? '') !== '') {
                        continue;
                    }
                    $attendedSessions[] = [
                        'date' => $sessionDetail['date'],
                        'weekday' => $sessionDetail['weekday'],
                        'slot' => $sessionDetail['slot'],
                        'source' => 'progress',
                    ];
                    $missingProgressSessions--;
                }
            }

            while ($missingProgressSessions > 0) {
                $attendedSessions[] = [
                    'date' => 'Chưa có ngày cụ thể',
                    'weekday' => '',
                    'slot' => (string)($progress['slot_code'] ?? $class['slot_time'] ?? ''),
                    'source' => 'progress',
                ];
                $missingProgressSessions--;
            }

            $reportStudents[] = [
                'id' => $studentId,
                'name' => $student['student_name'],
                'phone' => $student['phone'],
                'present_count' => $presentCount,
                'absent_count' => $absentCount,
                'studied_sessions' => $studiedSessions,
                'total_sessions' => $totalSessions,
                'progress_percent' => $progressPercent,
                'session_values' => $sessionValues,
                'attended_sessions' => $attendedSessions,
            ];
        }

        $report[] = [
            'class' => $class,
            'sessions' => $sessions,
            'students' => $reportStudents,
        ];
    }
    if ($previousPreloadedClassAbsentCounts === null) {
        unset($GLOBALS['preloadedClassAbsentCounts']);
    } else {
        $GLOBALS['preloadedClassAbsentCounts'] = $previousPreloadedClassAbsentCounts;
    }

    return $report;
}

function reportWriteExcelCell($value, string $style = '', string $type = 'String'): void {
    $styleAttr = $style !== '' ? ' ss:StyleID="' . reportXml($style) . '"' : '';
    echo '<Cell' . $styleAttr . '><Data ss:Type="' . reportXml($type) . '">' . reportXml($value) . '</Data></Cell>';
}

function reportWriteExcelRow(array $values, string $style = '', array $numericIndexes = []): void {
    echo '<Row>';
    foreach ($values as $index => $value) {
        $isNumeric = in_array($index, $numericIndexes, true) && is_numeric($value);
        reportWriteExcelCell($value, $style, $isNumeric ? 'Number' : 'String');
    }
    echo '</Row>';
}

function reportExportExcel(array $report): void {
    $fileName = 'bao-cao-hoc-vien-da-hoc-' . date('Ymd-His') . '.xls';
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Cache-Control: max-age=0');

    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<?mso-application progid="Excel.Sheet"?>';
    echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">';
    echo '<Styles>';
    echo '<Style ss:ID="Title"><Font ss:Bold="1" ss:Size="14" ss:Color="#0F766E"/></Style>';
    echo '<Style ss:ID="Header"><Font ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#2F6F5B" ss:Pattern="Solid"/><Alignment ss:Horizontal="Center"/></Style>';
    echo '<Style ss:ID="Class"><Font ss:Bold="1" ss:Color="#0F172A"/><Interior ss:Color="#E0F2FE" ss:Pattern="Solid"/></Style>';
    echo '</Styles>';

    $usedNames = [];
    echo '<Worksheet ss:Name="' . reportXml(reportCleanSheetName('Tong hop', $usedNames)) . '"><Table>';
    reportWriteExcelRow(['Báo cáo học viên đã học ngày nào', date('d/m/Y H:i')], 'Title');
    reportWriteExcelRow([]);
    reportWriteExcelRow(['STT', 'Mã lớp', 'Họ và Tên', 'SĐT', 'Đã học', 'Vắng', 'Tổng buổi', 'Tiến độ'], 'Header');
    $stt = 1;
    foreach ($report as $group) {
        foreach ($group['students'] as $student) {
            reportWriteExcelRow([
                $stt++,
                $group['class']['class_name'] ?? '',
                $student['name'],
                $student['phone'],
                $student['studied_sessions'],
                $student['absent_count'],
                $student['total_sessions'],
                $student['progress_percent'] . '%',
            ], '', [0, 4, 5, 6]);
        }
    }
    echo '</Table></Worksheet>';

    foreach ($report as $group) {
        $class = $group['class'];
        $sessions = $group['sessions'];
        $sheetName = reportCleanSheetName((string)($class['class_name'] ?? 'Lop'), $usedNames);
        echo '<Worksheet ss:Name="' . reportXml($sheetName) . '"><Table>';
        reportWriteExcelRow(['Lớp', $class['class_name'] ?? '', 'Tổng buổi', (int)($class['total_sessions'] ?? count($sessions))], 'Title', [3]);
        reportWriteExcelRow([]);

        $header = ['STT', 'Mã lớp', 'Thứ', 'Ca', 'Họ và Tên', 'SĐT', 'Đã học', 'Vắng', 'Tổng buổi', 'Tiến độ'];
        foreach ($sessions as $index => $session) {
            $header[] = 'Buổi ' . ($index + 1);
        }
        reportWriteExcelRow($header, 'Header');

        $stt = 1;
        foreach ($group['students'] as $student) {
            $row = [
                $stt++,
                $class['class_name'] ?? '',
                $class['schedule_days'] ?? '',
                $class['slot_time'] ?? '',
                $student['name'],
                $student['phone'],
                $student['studied_sessions'],
                $student['absent_count'],
                $student['total_sessions'],
                $student['progress_percent'] . '%',
            ];
            foreach ($student['session_values'] as $value) {
                $row[] = $value;
            }
            reportWriteExcelRow($row, '', [0, 6, 7, 8]);
        }

        echo '</Table></Worksheet>';
    }

    echo '</Workbook>';
    exit;
}

function reportExcelColumnName(int $index): string {
    $name = '';
    while ($index > 0) {
        $index--;
        $name = chr(65 + ($index % 26)) . $name;
        $index = intdiv($index, 26);
    }
    return $name;
}

function reportXlsxCell($value, int $rowIndex, int $colIndex, int $styleIndex = 0, bool $numeric = false): string {
    $ref = reportExcelColumnName($colIndex) . $rowIndex;
    $style = $styleIndex > 0 ? ' s="' . $styleIndex . '"' : '';
    if ($numeric && is_numeric($value)) {
        return '<c r="' . $ref . '"' . $style . '><v>' . reportXml($value) . '</v></c>';
    }
    return '<c r="' . $ref . '"' . $style . ' t="inlineStr"><is><t>' . reportXml($value) . '</t></is></c>';
}

function reportXlsxRow(array $values, int $rowIndex, int $styleIndex = 0, array $numericIndexes = []): string {
    $xml = '<row r="' . $rowIndex . '">';
    foreach ($values as $index => $value) {
        $xml .= reportXlsxCell($value, $rowIndex, $index + 1, $styleIndex, in_array($index, $numericIndexes, true));
    }
    return $xml . '</row>';
}

function reportXlsxSheetXml(array $rows): string {
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
    foreach ($rows as $rowIndex => $row) {
        $xml .= reportXlsxRow($row['values'], $rowIndex + 1, (int)($row['style'] ?? 0), $row['numeric'] ?? []);
    }
    return $xml . '</sheetData></worksheet>';
}

function reportStudentIsStillStudying(array $student): bool {
    $totalSessions = (int)($student['total_sessions'] ?? 0);
    $studiedSessions = (int)($student['studied_sessions'] ?? 0);

    return $totalSessions <= 0 || $studiedSessions < $totalSessions;
}

function reportFilterExportData(array $report, string $exportMode): array {
    if ($exportMode !== 'active') {
        return $report;
    }

    $filteredReport = [];
    foreach ($report as $group) {
        $students = array_values(array_filter($group['students'] ?? [], 'reportStudentIsStillStudying'));
        if (empty($students)) {
            continue;
        }
        $group['students'] = $students;
        $filteredReport[] = $group;
    }

    return $filteredReport;
}

function reportMaxAttendedSessionCount(array $report): int {
    $max = 0;
    foreach ($report as $group) {
        foreach ($group['students'] ?? [] as $student) {
            $max = max($max, count($student['attended_sessions'] ?? []));
        }
    }

    return $max;
}

function reportAttendedSessionLabel(array $session): string {
    $parts = [];
    if (($session['date'] ?? '') !== '') {
        $parts[] = (string)$session['date'];
    }
    if (($session['slot'] ?? '') !== '') {
        $parts[] = (string)$session['slot'];
    }

    return implode(' - ', $parts);
}

function reportZipPack32(int $value): string {
    return pack('V', $value < 0 ? $value + 4294967296 : $value);
}

function reportZipBuild(array $files): string {
    $time = getdate();
    $dosTime = (($time['hours'] & 0x1F) << 11) | (($time['minutes'] & 0x3F) << 5) | (((int)($time['seconds'] / 2)) & 0x1F);
    $dosDate = ((($time['year'] - 1980) & 0x7F) << 9) | (($time['mon'] & 0x0F) << 5) | ($time['mday'] & 0x1F);
    $zip = '';
    $central = '';
    $offset = 0;

    foreach ($files as $name => $content) {
        $crc = crc32($content);
        $size = strlen($content);
        $nameLength = strlen($name);
        $local = pack('Vvvvvv', 0x04034b50, 20, 0, 0, $dosTime, $dosDate)
            . reportZipPack32($crc) . reportZipPack32($size) . reportZipPack32($size)
            . pack('vv', $nameLength, 0) . $name . $content;
        $zip .= $local;
        $central .= pack('Vvvvvvv', 0x02014b50, 20, 20, 0, 0, $dosTime, $dosDate)
            . reportZipPack32($crc) . reportZipPack32($size) . reportZipPack32($size)
            . pack('vvvvv', $nameLength, 0, 0, 0, 0)
            . reportZipPack32(0) . reportZipPack32($offset) . $name;
        $offset += strlen($local);
    }

    $centralOffset = strlen($zip);
    $centralSize = strlen($central);
    $count = count($files);
    return $zip . $central . pack('Vvvvv', 0x06054b50, 0, 0, $count, $count)
        . reportZipPack32($centralSize) . reportZipPack32($centralOffset) . pack('v', 0);
}

function reportExportXlsx(array $report, string $exportMode = 'all'): void {
    $report = reportFilterExportData($report, $exportMode);
    $modeSuffix = $exportMode === 'active' ? 'hoc-vien-con-hoc' : 'tat-ca';
    $fileName = 'bao-cao-hoc-vien-da-hoc-' . $modeSuffix . '-' . date('Ymd-His') . '.xlsx';
    $usedNames = [];
    $sheets = [];
    $maxAttendedSessions = reportMaxAttendedSessionCount($report);
    $summaryHeader = ['STT', 'Ma lop', 'Ho va Ten', 'SDT', 'Da hoc', 'Vang', 'Tong buoi', 'Tien do'];
    for ($i = 1; $i <= $maxAttendedSessions; $i++) {
        $summaryHeader[] = 'Buoi da hoc ' . $i;
    }
    $summaryRows = [
        ['values' => ['Bao cao hoc vien da hoc ngay nao', $exportMode === 'active' ? 'Hoc vien con hoc' : 'Tat ca hoc vien', date('d/m/Y H:i')], 'style' => 1],
        ['values' => []],
        ['values' => $summaryHeader, 'style' => 2],
    ];
    $stt = 1;
    foreach ($report as $group) {
        foreach ($group['students'] as $student) {
            $attendedValues = [];
            foreach ($student['attended_sessions'] ?? [] as $session) {
                $attendedValues[] = reportAttendedSessionLabel($session);
            }
            while (count($attendedValues) < $maxAttendedSessions) {
                $attendedValues[] = '';
            }
            $summaryRows[] = [
                'values' => array_merge([$stt++, $group['class']['class_name'] ?? '', $student['name'], $student['phone'], $student['studied_sessions'], $student['absent_count'], $student['total_sessions'], $student['progress_percent'] . '%'], $attendedValues),
                'numeric' => [0, 4, 5, 6],
            ];
        }
    }
    $sheets[] = ['name' => reportCleanSheetName('Tong hop', $usedNames), 'rows' => $summaryRows];

    foreach ($report as $group) {
        $class = $group['class'];
        $sessions = $group['sessions'];
        $rows = [
            ['values' => ['Lop', $class['class_name'] ?? '', 'Tong buoi', (int)($class['total_sessions'] ?? count($sessions))], 'style' => 1, 'numeric' => [3]],
            ['values' => []],
        ];
        $classMaxAttendedSessions = 0;
        foreach ($group['students'] as $student) {
            $classMaxAttendedSessions = max($classMaxAttendedSessions, count($student['attended_sessions'] ?? []));
        }

        $header = ['STT', 'Ma lop', 'Thu', 'Ca', 'Ho va Ten', 'SDT', 'Da hoc', 'Vang', 'Tong buoi', 'Tien do'];
        for ($i = 1; $i <= $classMaxAttendedSessions; $i++) {
            $header[] = 'Buoi da hoc ' . $i;
        }
        foreach ($sessions as $index => $session) {
            $header[] = 'Buoi ' . ($index + 1);
        }
        $rows[] = ['values' => $header, 'style' => 2];
        $stt = 1;
        foreach ($group['students'] as $student) {
            $row = [$stt++, $class['class_name'] ?? '', $class['schedule_days'] ?? '', $class['slot_time'] ?? '', $student['name'], $student['phone'], $student['studied_sessions'], $student['absent_count'], $student['total_sessions'], $student['progress_percent'] . '%'];
            $attendedValues = [];
            foreach ($student['attended_sessions'] ?? [] as $session) {
                $attendedValues[] = reportAttendedSessionLabel($session);
            }
            while (count($attendedValues) < $classMaxAttendedSessions) {
                $attendedValues[] = '';
            }
            $row = array_merge($row, $attendedValues);
            foreach ($student['session_values'] as $value) {
                $row[] = $value;
            }
            $rows[] = ['values' => $row, 'numeric' => [0, 6, 7, 8]];
        }
        $sheets[] = ['name' => reportCleanSheetName((string)($class['class_name'] ?? 'Lop'), $usedNames), 'rows' => $rows];
    }

    $files = [];
    $workbookSheets = '';
    $workbookRels = '';
    $contentTypes = '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
    foreach ($sheets as $index => $sheet) {
        $sheetId = $index + 1;
        $workbookSheets .= '<sheet name="' . reportXml($sheet['name']) . '" sheetId="' . $sheetId . '" r:id="rId' . $sheetId . '"/>';
        $workbookRels .= '<Relationship Id="rId' . $sheetId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $sheetId . '.xml"/>';
        $contentTypes .= '<Override PartName="/xl/worksheets/sheet' . $sheetId . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $files['xl/worksheets/sheet' . $sheetId . '.xml'] = reportXlsxSheetXml($sheet['rows']);
    }
    $styleRelId = count($sheets) + 1;
    $workbookRels .= '<Relationship Id="rId' . $styleRelId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
    $files['[Content_Types].xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>' . $contentTypes . '</Types>';
    $files['_rels/.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
    $files['xl/workbook.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $workbookSheets . '</sheets></workbook>';
    $files['xl/_rels/workbook.xml.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $workbookRels . '</Relationships>';
    $files['xl/styles.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="3"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="14"/><color rgb="FF0F766E"/><name val="Calibri"/></font><font><b/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF2F6F5B"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/><xf numFmtId="0" fontId="2" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs></styleSheet>';

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Cache-Control: max-age=0');
    echo reportZipBuild($files);
    exit;
}

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sync_ap_progress'])) {
    if (($_SESSION['role'] ?? '') !== 'admin') {
        $message = "<p class='error'>Chỉ tài khoản admin mới được đồng bộ dữ liệu AP.</p>";
    } else {
        try {
            require_once __DIR__ . '/ap_sync.php';
            $result = runApClassStudentUpdate();
            $progress = $result['progress'] ?? [];
            $message = "<p class='success'>Đã đồng bộ AP. Tiến độ: "
                . (int)($progress['progress_upserted'] ?? 0) . " dòng cập nhật, "
                . (int)($progress['fixed_attendance_inserted'] ?? 0) . " buổi học tự thêm, "
                . (int)($progress['fixed_attendance_existing_skipped'] ?? 0) . " buổi học được cập nhật/ghi đè, "
                . (int)($progress['detail_class_pages'] ?? 0) . " trang chi tiết lớp, "
                . (int)($progress['detail_class_page_errors'] ?? 0) . " trang chi tiết lỗi, "
                . (int)($progress['detail_students_with_dates'] ?? 0) . " học viên có ngày cụ thể.</p>";
        } catch (Throwable $e) {
            $message = "<p class='error'>Đồng bộ AP thất bại: " . htmlspecialchars($e->getMessage()) . "</p>";
        }
    }
}

$teachers = $db->query("
    SELECT DISTINCT u.id, COALESCE(NULLIF(u.full_name, ''), u.username) AS teacher_name
    FROM classes c
    JOIN users u ON u.id = c.assigned_user_id
    WHERE c.status = 'Active'
    ORDER BY teacher_name ASC
")->fetchAll(PDO::FETCH_ASSOC);
$classes = $db->query("SELECT id, class_name, assigned_user_id FROM classes WHERE status = 'Active' ORDER BY class_name ASC")->fetchAll(PDO::FETCH_ASSOC);
$filterClassId = isset($_GET['filter_class_id']) ? (int)$_GET['filter_class_id'] : 0;
$filterTeacherId = isset($_GET['filter_teacher_id']) ? (int)$_GET['filter_teacher_id'] : 0;
$searchStudent = isset($_GET['search_student']) ? trim((string)$_GET['search_student']) : '';
$exportMode = isset($_GET['export_mode']) && $_GET['export_mode'] === 'active' ? 'active' : 'all';
$isExportRequest = isset($_GET['export']) && $_GET['export'] === 'excel';
$hasReportFilter = $filterClassId > 0 || $filterTeacherId > 0 || $searchStudent !== '';
$shouldBuildReport = $isExportRequest
    || $hasReportFilter;
if ($filterClassId > 0 && $filterTeacherId > 0) {
    $classBelongsToTeacher = false;
    foreach ($classes as $class) {
        if ((int)$class['id'] === $filterClassId && (int)$class['assigned_user_id'] === $filterTeacherId) {
            $classBelongsToTeacher = true;
            break;
        }
    }
    if (!$classBelongsToTeacher) {
        $filterClassId = 0;
    }
}
$report = $shouldBuildReport ? reportBuildData($db, $filterClassId, $filterTeacherId, $searchStudent) : [];

if ($isExportRequest) {
    reportExportXlsx($report, $exportMode);
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Báo cáo buổi học học viên</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preload" as="style" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" onload="this.onload=null;this.rel='stylesheet'">
    <noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap"></noscript>
    <link rel="stylesheet" href="../CSS/style.css?v=student-report-1">
</head>
<body>
    <?php require_once __DIR__ . '/sidebar.php'; ?>

    <div class="main-content">
        <div class="header-wrapper">
            <div>
                <h2>Báo cáo học viên đã học ngày nào</h2>
                <span class="report-page-subtitle">Tổng hợp theo từng lớp, từng học viên và từng buổi học để xuất Excel.</span>
            </div>
        </div>

        <?= $message ?>

        <div class="card report-filter-card">
            <form method="GET" class="report-actions">
                <div class="report-filter-field">
                    <label>Chọn giáo viên</label>
                    <select name="filter_teacher_id">
                        <option value="0">-- Tất cả giáo viên --</option>
                        <?php foreach ($teachers as $teacher): ?>
                            <option value="<?= (int)$teacher['id'] ?>" <?= $filterTeacherId === (int)$teacher['id'] ? 'selected' : '' ?>><?= htmlspecialchars($teacher['teacher_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="report-filter-field">
                    <label>Lọc theo lớp đang hoạt động</label>
                    <select name="filter_class_id">
                        <option value="0">-- Tất cả lớp --</option>
                        <?php foreach ($classes as $class): ?>
                            <?php if ($filterTeacherId > 0 && (int)$class['assigned_user_id'] !== $filterTeacherId) continue; ?>
                            <option value="<?= (int)$class['id'] ?>" <?= $filterClassId === (int)$class['id'] ? 'selected' : '' ?>><?= htmlspecialchars($class['class_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="report-filter-field report-filter-field-search">
                    <label>Tìm học viên</label>
                    <input type="text" name="search_student" value="<?= htmlspecialchars($searchStudent) ?>" placeholder="Tên hoặc số điện thoại">
                </div>
                <button type="submit" class="btn">Xem báo cáo</button>
                <button type="button" class="btn btn-teal" onclick="openExportModal()">Xuất Excel</button>
            </form>
            <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
                <form method="POST" class="report-sync-form" onsubmit="return confirm('Đồng bộ dữ liệu mới nhất từ AP trước khi xem báo cáo?');">
                    <button type="submit" name="sync_ap_progress" class="btn btn-slate">Đồng bộ AP từ trang tiến độ</button>
                </form>
            <?php endif; ?>
        </div>

        <div class="report-table-wrap">
            <table class="session-report-table">
                <thead>
                    <tr>
                        <th>STT</th>
                        <th>Mã lớp</th>
                        <th>Thứ</th>
                        <th>Ca</th>
                        <th>Họ và Tên</th>
                        <th>SĐT</th>
                        <th>Đã học</th>
                        <th>Vắng</th>
                        <th>Tiến độ</th>
                        
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$shouldBuildReport): ?>
                        <tr><td colspan="9" class="report-empty-cell">Chọn bộ lọc rồi bấm Xem báo cáo để tải dữ liệu.</td></tr>
                    <?php elseif (empty($report)): ?>
                        <tr><td colspan="9" class="report-empty-cell">Không có dữ liệu phù hợp.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($report as $group): ?>
                        <tr class="class-row">
                            <td colspan="9">
                                <?= htmlspecialchars($group['class']['class_name']) ?>
                                · <?= count($group['students']) ?> học viên
                                · <?= count($group['sessions']) ?> buổi lịch
                            </td>
                        </tr>
                        <?php foreach ($group['students'] as $index => $student): ?>
                            <tr>
                                <td><?= $index + 1 ?></td>
                                <td><?= htmlspecialchars($group['class']['class_name']) ?></td>
                                <td><?= htmlspecialchars($group['class']['schedule_days']) ?></td>
                                <td><?= htmlspecialchars($group['class']['slot_time']) ?></td>
                                <td>
                                    <button
                                        type="button"
                                        class="student-detail-btn"
                                        onclick='openStudentSessionModal(<?= json_encode([
                                            'name' => $student['name'],
                                            'phone' => $student['phone'],
                                            'class_name' => $group['class']['class_name'],
                                            'studied_sessions' => (int)$student['studied_sessions'],
                                            'total_sessions' => (int)$student['total_sessions'],
                                            'attended_sessions' => $student['attended_sessions'],
                                        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'
                                    ><?= htmlspecialchars($student['name']) ?></button>
                                </td>
                                <td><?= htmlspecialchars($student['phone']) ?></td>
                                <td class="present-cell"><?= (int)$student['studied_sessions'] ?></td>
                                <td class="absent-cell"><?= (int)$student['absent_count'] ?></td>
                                <td><?= (int)$student['progress_percent'] ?>%</td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="report-modal-backdrop" id="studentSessionModal" onclick="closeStudentSessionModal(event)">
        <div class="report-modal" role="dialog" aria-modal="true" aria-labelledby="studentSessionModalTitle">
            <div class="report-modal-header">
                <div>
                    <h3 class="report-modal-title" id="studentSessionModalTitle">Buổi đã học</h3>
                    <p class="report-modal-subtitle" id="studentSessionModalSubtitle"></p>
                </div>
                <button type="button" class="report-modal-close" onclick="closeStudentSessionModal()" aria-label="Đóng">×</button>
            </div>
            <div class="report-modal-body" id="studentSessionModalBody"></div>
        </div>
    </div>
    <div class="report-modal-backdrop" id="exportReportModal" onclick="closeExportModal(event)">
        <div class="report-modal" role="dialog" aria-modal="true" aria-labelledby="exportReportModalTitle">
            <div class="report-modal-header">
                <div>
                    <h3 class="report-modal-title" id="exportReportModalTitle">Xuất Excel</h3>
                    <p class="report-modal-subtitle">Chọn giáo viên để chỉ xuất báo cáo buổi học của giáo viên đó.</p>
                </div>
                <button type="button" class="report-modal-close" onclick="closeExportModal()" aria-label="Đóng">×</button>
            </div>
            <div class="report-modal-body">
                <form method="GET" action="student_session_report.php" class="export-modal-form">
                    <input type="hidden" name="export" value="excel">
                    <input type="hidden" name="filter_class_id" value="0">
                    <input type="hidden" name="search_student" value="<?= htmlspecialchars($searchStudent) ?>">
                    <div>
                        <label>Chế độ xuất</label>
                        <select name="export_mode">
                            <option value="all" <?= $exportMode === 'all' ? 'selected' : '' ?>>Tất cả học viên</option>
                            <option value="active" <?= $exportMode === 'active' ? 'selected' : '' ?>>Chỉ học viên còn học</option>
                        </select>
                    </div>
                    <div>
                        <label>Giáo viên</label>
                        <select name="filter_teacher_id" required>
                            <option value="">-- Chọn giáo viên --</option>
                            <?php foreach ($teachers as $teacher): ?>
                                <option value="<?= (int)$teacher['id'] ?>" <?= $filterTeacherId === (int)$teacher['id'] ? 'selected' : '' ?>><?= htmlspecialchars($teacher['teacher_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="export-modal-actions">
                        <button type="button" class="btn btn-light" onclick="closeExportModal()">Hủy</button>
                        <button type="submit" class="btn btn-teal">Xuất Excel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <script>
        function escapeHtml(value) {
            return String(value ?? '').replace(/[&<>"']/g, function (char) {
                return {
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#039;'
                }[char];
            });
        }

        function openStudentSessionModal(student) {
            const modal = document.getElementById('studentSessionModal');
            const title = document.getElementById('studentSessionModalTitle');
            const subtitle = document.getElementById('studentSessionModalSubtitle');
            const body = document.getElementById('studentSessionModalBody');
            const sessions = Array.isArray(student.attended_sessions) ? student.attended_sessions : [];

            title.textContent = student.name || 'Học viên';
            subtitle.textContent = `${student.class_name || ''} · ${student.phone || ''} · Đã học ${student.studied_sessions || 0}/${student.total_sessions || 0} buổi`;

            if (sessions.length === 0) {
                body.innerHTML = '<p class="session-detail-empty">Chưa có buổi nào được ghi nhận là đã học.</p>';
            } else {
                body.innerHTML = `
                    <ul class="session-detail-list">
                        ${sessions.map(function (session, index) {
                            const weekday = session.weekday ? `${escapeHtml(session.weekday)} · ` : '';
                            const sourceLabel = session.source === 'progress' ? '<span class="session-detail-source">Theo tiến độ AP</span>' : '';
                            return `
                                <li class="session-detail-item">
                                    <div>
                                        <div class="session-detail-date">Buổi ${index + 1}: ${escapeHtml(session.date)}</div>
                                        ${sourceLabel}
                                    </div>
                                    <div class="session-detail-slot">${weekday}${escapeHtml(session.slot || '')}</div>
                                </li>
                            `;
                        }).join('')}
                    </ul>
                `;
            }

            modal.classList.add('is-open');
        }

        function closeStudentSessionModal(event) {
            if (event && event.target !== event.currentTarget) {
                return;
            }
            document.getElementById('studentSessionModal').classList.remove('is-open');
        }

        function openExportModal() {
            document.getElementById('exportReportModal').classList.add('is-open');
        }

        function closeExportModal(event) {
            if (event && event.target !== event.currentTarget) {
                return;
            }
            document.getElementById('exportReportModal').classList.remove('is-open');
        }

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeStudentSessionModal();
                closeExportModal();
            }
        });
    </script>
</body>
</html>
