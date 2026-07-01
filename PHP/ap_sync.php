<?php

function apCurlExecWithRetry($curl, int $maxAttempts = 3): array {
    $html = false;
    $error = '';
    $status = 0;

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $html = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);

        $retryable = $html === false
            && preg_match('/HTTP\/2|CANCEL|reset|timed out|Recv failure|SSL_read/i', $error);

        if (!$retryable || $attempt === $maxAttempts) {
            break;
        }

        usleep(300000 * $attempt);
    }

    return [$html, $error, $status];
}

function downloadApSyncHtmlForClassUpdate(string $username, string $password): array {
    if (!function_exists('curl_init')) {
        throw new RuntimeException('May chu PHP chua bat extension cURL.');
    }

    $cookieFile = tempnam(sys_get_temp_dir(), 'ap_cookie_');
    if ($cookieFile === false) {
        throw new RuntimeException('Khong tao duoc file cookie tam.');
    }

    try {
        $sessionCookie = '';
        $login = curl_init('https://ap.tinhoccantho.vn/login.php');
        curl_setopt_array($login, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'username' => $username,
                'password' => $password,
            ]),
            CURLOPT_COOKIEJAR => $cookieFile,
            CURLOPT_COOKIEFILE => $cookieFile,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_USERAGENT => 'curl/8.20.0',
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$sessionCookie): int {
                if (preg_match('/^Set-Cookie:\s*([^;]+)/i', $header, $matches)) {
                    $sessionCookie = trim($matches[1]);
                }
                return strlen($header);
            },
        ]);
        [$loginHtml, $loginError, $loginStatus] = apCurlExecWithRetry($login);
        curl_close($login);

        if ($loginHtml === false || $loginStatus >= 400) {
            throw new RuntimeException('Dang nhap AP that bai: ' . ($loginError ?: 'HTTP ' . $loginStatus));
        }
        if ($sessionCookie === '') {
            throw new RuntimeException('Dang nhap AP that bai: khong nhan duoc cookie phien.');
        }

        $download = static function (string $url) use ($cookieFile, $sessionCookie): array {
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_COOKIEJAR => $cookieFile,
                CURLOPT_COOKIEFILE => $cookieFile,
                CURLOPT_COOKIE => $sessionCookie,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT => 60,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
                CURLOPT_USERAGENT => 'curl/8.20.0',
                CURLOPT_ENCODING => '',
            ]);
            [$html, $error, $status] = apCurlExecWithRetry($curl);
            curl_close($curl);

            return [$html, $error, $status];
        };

        [$classesHtml, $classesError, $classesStatus] = $download('https://ap.tinhoccantho.vn/admin_classes.php');
        if ($classesHtml === false || $classesStatus >= 400 || strpos($classesHtml, 'editClass(') === false) {
            throw new RuntimeException('Khong tai duoc trang lop AP: ' . ($classesError ?: 'HTTP ' . $classesStatus));
        }

        [$studentsHtml, $studentsError, $studentsStatus] = $download('https://ap.tinhoccantho.vn/admin_students.php');
        if ($studentsHtml === false || $studentsStatus >= 400 || strpos($studentsHtml, 'editStudent(') === false) {
            throw new RuntimeException('Khong tai duoc trang hoc vien AP: ' . ($studentsError ?: 'HTTP ' . $studentsStatus));
        }

        [$progressHtml, $progressError, $progressStatus] = $download('https://ap.tinhoccantho.vn/admin_student_progress.php');
        if ($progressHtml === false || $progressStatus >= 400 || strpos($progressHtml, 'admin_student_progress.php?class_id=') === false) {
            throw new RuntimeException('Khong tai duoc trang tien do hoc vien AP: ' . ($progressError ?: 'HTTP ' . $progressStatus));
        }

        [$slotsReportHtml, $slotsReportError, $slotsReportStatus] = $download('https://ap.tinhoccantho.vn/admin_slots_report.php');
        if ($slotsReportHtml === false || $slotsReportStatus >= 400 || strpos($slotsReportHtml, 'table-sticky') === false) {
            throw new RuntimeException('Khong tai duoc trang bao cao ca day AP: ' . ($slotsReportError ?: 'HTTP ' . $slotsReportStatus));
        }

        $scheduleStartDate = date('Y-m-d');
        $scheduleEndDate = date('Y-m-d', strtotime('+90 days'));
        $centerScheduleUrl = 'https://ap.tinhoccantho.vn/admin_lich_day.php?' . http_build_query([
            'teacher_id' => 'all',
            'start_date' => $scheduleStartDate,
            'end_date' => $scheduleEndDate,
        ]);
        [$centerScheduleHtml, $centerScheduleError, $centerScheduleStatus] = $download($centerScheduleUrl);
        if ($centerScheduleHtml === false || $centerScheduleStatus >= 400 || strpos($centerScheduleHtml, 'Lịch dạy') === false && strpos($centerScheduleHtml, 'Lá»‹ch dáº¡y') === false) {
            throw new RuntimeException('Khong tai duoc trang lich day toan trung tam AP: ' . ($centerScheduleError ?: 'HTTP ' . $centerScheduleStatus));
        }

        return [
            'classes' => $classesHtml,
            'students' => $studentsHtml,
            'progress' => $progressHtml,
            'slots_report' => $slotsReportHtml,
            'center_schedule' => $centerScheduleHtml,
        ];
    } finally {
        @unlink($cookieFile);
    }
}

function runApClassStudentUpdate(): array {
    global $db, $apSyncUsername, $apSyncPassword;

    if (trim($apSyncUsername ?? '') === '' || trim($apSyncPassword ?? '') === '') {
        throw new RuntimeException('Chua cau hinh tai khoan AP_SYNC_USERNAME / AP_SYNC_PASSWORD.');
    }

    $syncHtml = downloadApSyncHtmlForClassUpdate($apSyncUsername, $apSyncPassword);
    $htmlPath = tempnam(sys_get_temp_dir(), 'ap_classes_');
    $studentsHtmlPath = tempnam(sys_get_temp_dir(), 'ap_students_');
    $progressHtmlPath = tempnam(sys_get_temp_dir(), 'ap_progress_');
    $slotsReportHtmlPath = tempnam(sys_get_temp_dir(), 'ap_slots_report_');
    $centerScheduleHtmlPath = tempnam(sys_get_temp_dir(), 'ap_center_schedule_');
    if (
        $htmlPath === false
        || $studentsHtmlPath === false
        || $progressHtmlPath === false
        || $slotsReportHtmlPath === false
        || $centerScheduleHtmlPath === false
        || file_put_contents($htmlPath, $syncHtml['classes']) === false
        || file_put_contents($studentsHtmlPath, $syncHtml['students']) === false
        || file_put_contents($progressHtmlPath, $syncHtml['progress']) === false
        || file_put_contents($slotsReportHtmlPath, $syncHtml['slots_report']) === false
        || file_put_contents($centerScheduleHtmlPath, $syncHtml['center_schedule']) === false
    ) {
        throw new RuntimeException('Khong tao duoc file HTML tam de import.');
    }

    $previousArgv = $GLOBALS['argv'] ?? null;
    $argv = [__DIR__ . '/import_ap_classes.php', $htmlPath, $studentsHtmlPath, $progressHtmlPath, $slotsReportHtmlPath, $centerScheduleHtmlPath];
    $GLOBALS['argv'] = $argv;

    ob_start();
    try {
        include __DIR__ . '/import_ap_classes.php';
        $output = ob_get_clean();
    } catch (Throwable $e) {
        ob_end_clean();
        throw $e;
    } finally {
        if ($previousArgv === null) {
            unset($GLOBALS['argv']);
        } else {
            $GLOBALS['argv'] = $previousArgv;
        }
        @unlink($htmlPath);
        @unlink($studentsHtmlPath);
        @unlink($progressHtmlPath);
        @unlink($slotsReportHtmlPath);
        @unlink($centerScheduleHtmlPath);
    }

    $json = preg_replace('/^\xEF\xBB\xBF/', '', trim($output));
    $result = json_decode($json, true);
    if (!is_array($result)) {
        throw new RuntimeException('Import AP khong tra ve JSON hop le: ' . substr($output, 0, 300));
    }

    return $result;
}

function buildApClassStudentUpdateMessage(array $result): string {
    $message = 'Cap nhat AP xong: '
        . (int)($result['source_classes'] ?? 0) . ' lop nguon, '
        . (int)($result['would_insert_or_inserted'] ?? 0) . ' lop them moi, '
        . (int)($result['would_update_or_updated'] ?? 0) . ' lop cap nhat.';

    if (!empty($result['students'])) {
        $students = $result['students'];
        $message .= ' Hoc vien: '
            . (int)($students['unique_students_by_name'] ?? 0) . ' ten duy nhat, '
            . (int)($students['students_inserted'] ?? 0) . ' them moi, '
            . (int)($students['student_class_links_inserted'] ?? 0) . ' luot gan lop.';
    }

    if (!empty($result['center_schedule'])) {
        $schedule = $result['center_schedule'];
        $message .= ' Lich trung tam: '
            . (int)($schedule['matched_schedule_rows'] ?? 0) . ' dong khop lop, '
            . (int)($schedule['class_overrides_replaced'] ?? 0) . ' lop cap nhat lich.';
    }

    return $message;
}
