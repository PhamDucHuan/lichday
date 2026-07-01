<?php
session_start();
require_once 'config.php';
require_once 'ap_sync.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$message = '';
$error = '';

function downloadApSyncHtml(string $username, string $password): array {
    if (!function_exists('curl_init')) {
        throw new RuntimeException('Máy chủ PHP chưa bật extension cURL.');
    }

    $cookieFile = tempnam(sys_get_temp_dir(), 'ap_cookie_');
    if ($cookieFile === false) {
        throw new RuntimeException('Không tạo được file cookie tạm.');
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
        $loginHtml = curl_exec($login);
        $loginError = curl_error($login);
        $loginStatus = (int)curl_getinfo($login, CURLINFO_HTTP_CODE);
        curl_close($login);

        if ($loginHtml === false || $loginStatus >= 400) {
            throw new RuntimeException('Đăng nhập AP thất bại: ' . ($loginError ?: 'HTTP ' . $loginStatus));
        }
        if ($sessionCookie === '') {
            throw new RuntimeException('Đăng nhập AP thất bại: không nhận được cookie phiên.');
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
            $html = curl_exec($curl);
            $error = curl_error($curl);
            $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);

            return [$html, $error, $status];
        };

        [$html, $classesError, $classesStatus] = $download('https://ap.tinhoccantho.vn/admin_classes.php');

        if ($html === false || $classesStatus >= 400) {
            throw new RuntimeException('Không tải được trang lớp AP: ' . ($classesError ?: 'HTTP ' . $classesStatus));
        }
        if (strpos($html, 'editClass(') === false) {
            throw new RuntimeException('AP không trả về bảng lớp. Tài khoản có thể hết quyền hoặc mật khẩu đã đổi.');
        }

        [$studentsHtml, $studentsError, $studentsStatus] = $download('https://ap.tinhoccantho.vn/admin_students.php');
        if ($studentsHtml === false || $studentsStatus >= 400) {
            throw new RuntimeException('Khong tai duoc trang hoc vien AP: ' . ($studentsError ?: 'HTTP ' . $studentsStatus));
        }
        if (strpos($studentsHtml, 'editStudent(') === false) {
            throw new RuntimeException('AP khong tra ve bang hoc vien. Tai khoan co the het quyen hoac mat khau da doi.');
        }

        [$progressHtml, $progressError, $progressStatus] = $download('https://ap.tinhoccantho.vn/admin_student_progress.php');
        if ($progressHtml === false || $progressStatus >= 400) {
            throw new RuntimeException('Khong tai duoc trang tien do hoc vien AP: ' . ($progressError ?: 'HTTP ' . $progressStatus));
        }
        if (strpos($progressHtml, 'admin_student_progress.php?class_id=') === false) {
            throw new RuntimeException('AP khong tra ve bang tien do hoc vien. Tai khoan co the het quyen hoac mat khau da doi.');
        }

        [$slotsReportHtml, $slotsReportError, $slotsReportStatus] = $download('https://ap.tinhoccantho.vn/admin_slots_report.php');
        if ($slotsReportHtml === false || $slotsReportStatus >= 400) {
            throw new RuntimeException('Khong tai duoc trang bao cao ca day AP: ' . ($slotsReportError ?: 'HTTP ' . $slotsReportStatus));
        }
        if (strpos($slotsReportHtml, 'table-sticky') === false || strpos($slotsReportHtml, 'admin_slots_report.php') === false) {
            throw new RuntimeException('AP khong tra ve bao cao ca day. Tai khoan co the het quyen hoac mat khau da doi.');
        }

        return ['classes' => $html, 'students' => $studentsHtml, 'progress' => $progressHtml, 'slots_report' => $slotsReportHtml];
    } finally {
        @unlink($cookieFile);
    }
}

function runApClassSync(): array {
    global $db, $apSyncUsername, $apSyncPassword;

    if (trim($apSyncUsername ?? '') === '' || trim($apSyncPassword ?? '') === '') {
        throw new RuntimeException('Chưa cấu hình tài khoản AP_SYNC_USERNAME / AP_SYNC_PASSWORD.');
    }

    $syncHtml = downloadApSyncHtml($apSyncUsername, $apSyncPassword);
    $htmlPath = tempnam(sys_get_temp_dir(), 'ap_classes_');
    $studentsHtmlPath = tempnam(sys_get_temp_dir(), 'ap_students_');
    $progressHtmlPath = tempnam(sys_get_temp_dir(), 'ap_progress_');
    $slotsReportHtmlPath = tempnam(sys_get_temp_dir(), 'ap_slots_report_');
    if ($htmlPath === false || $studentsHtmlPath === false || $progressHtmlPath === false || $slotsReportHtmlPath === false || file_put_contents($htmlPath, $syncHtml['classes']) === false || file_put_contents($studentsHtmlPath, $syncHtml['students']) === false || file_put_contents($progressHtmlPath, $syncHtml['progress']) === false || file_put_contents($slotsReportHtmlPath, $syncHtml['slots_report']) === false) {
        throw new RuntimeException('Không tạo được file HTML tạm để import.');
    }

    $previousArgv = $GLOBALS['argv'] ?? null;
    $argv = [__DIR__ . '/import_ap_classes.php', $htmlPath, $studentsHtmlPath, $progressHtmlPath, $slotsReportHtmlPath];

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
    }

    $json = preg_replace('/^\xEF\xBB\xBF/', '', trim($output));
    $result = json_decode($json, true);
    if (!is_array($result)) {
        throw new RuntimeException('Import AP không trả về JSON hợp lệ: ' . substr($output, 0, 300));
    }

    return $result;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'profile';

    if ($action === 'sync_ap_classes') {
        if (($_SESSION['role'] ?? '') !== 'admin') {
            $error = 'Chỉ tài khoản admin mới được đồng bộ lớp từ AP.';
        } else {
            try {
                $result = runApClassStudentUpdate();
                $message = 'Đồng bộ AP xong: '
                    . (int)$result['source_classes'] . ' lớp nguồn, '
                    . (int)$result['would_insert_or_inserted'] . ' lớp thêm mới, '
                    . (int)$result['would_update_or_updated'] . ' lớp cập nhật.';
                if (!empty($result['classes_without_schedule'])) {
                    $message .= ' Có ' . count($result['classes_without_schedule']) . ' lớp chưa có cấu hình lịch chi tiết.';
                }
                if (!empty($result['students'])) {
                    $studentResult = $result['students'];
                    $message .= ' Học viên: '
                        . (int)($studentResult['unique_students_by_name'] ?? 0) . ' tên duy nhất, '
                        . (int)($studentResult['students_inserted'] ?? 0) . ' thêm mới, '
                        . (int)($studentResult['student_class_links_inserted'] ?? 0) . ' lượt gắn lớp.';
                }
                if (!empty($result['slots'])) {
                    $slotsResult = $result['slots'];
                    $message .= ' Ca day: '
                        . (int)($slotsResult['source_slots'] ?? 0) . ' ca nguon, '
                        . (int)($slotsResult['slots_inserted'] ?? 0) . ' them moi, '
                        . (int)($slotsResult['slots_updated'] ?? 0) . ' cap nhat.';
                }
                if (!empty($result['progress'])) {
                    $progressResult = $result['progress'];
                    $message .= ' Tien do: '
                        . (int)($progressResult['progress_upserted'] ?? 0) . ' dong cap nhat, '
                        . (int)($progressResult['fixed_attendance_inserted'] ?? 0) . ' buoi diem danh tu them, '
                        . (int)($progressResult['flexible_classes_skipped_auto_attendance'] ?? 0) . ' lop linh hoat bo qua xep tu dong.';
                }
            } catch (Throwable $e) {
                $error = 'Đồng bộ AP thất bại: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'save_user_access') {
        if (($_SESSION['role'] ?? '') !== 'admin') {
            $error = 'Chỉ tài khoản admin mới được set quyền người dùng.';
        } else {
            $targetUserId = (int)($_POST['target_user_id'] ?? 0);
            $role = ($_POST['role'] ?? 'user') === 'admin' ? 'admin' : 'user';
            $status = $_POST['status'] ?? 'active';
            $allowedStatuses = ['active', 'pending', 'inactive'];
            if (!in_array($status, $allowedStatuses, true)) {
                $status = 'active';
            }

            $userStmt = $db->prepare('SELECT id, username FROM users WHERE id = ? LIMIT 1');
            $userStmt->execute([$targetUserId]);
            $targetUser = $userStmt->fetch(PDO::FETCH_ASSOC);

            if (!$targetUser) {
                $error = 'Không tìm thấy người dùng cần set quyền.';
            } else {
                if ($targetUserId !== (int)$_SESSION['user_id']) {
                    $db->prepare('UPDATE users SET role = ?, status = ? WHERE id = ?')->execute([$role, $status, $targetUserId]);
                }

                $db->prepare('DELETE FROM user_view_permissions WHERE viewer_id = ?')->execute([$targetUserId]);
                if (!empty($_POST['viewed_user_ids']) && is_array($_POST['viewed_user_ids'])) {
                    $insertPermission = $db->prepare('INSERT IGNORE INTO user_view_permissions (viewer_id, viewed_user_id) VALUES (?, ?)');
                    foreach ($_POST['viewed_user_ids'] as $viewedUserId) {
                        $viewedUserId = (int)$viewedUserId;
                        if ($viewedUserId > 0 && $viewedUserId !== $targetUserId) {
                            $insertPermission->execute([$targetUserId, $viewedUserId]);
                        }
                    }
                }

                $message = 'Đã lưu quyền cho tài khoản ' . $targetUser['username'] . '.';
            }
        }
    } else {
        $fullName = trim($_POST['full_name'] ?? '');
        if ($fullName === '') {
            $error = 'Vui lòng nhập tên hiển thị.';
        } else {
            $stmt = $db->prepare('UPDATE users SET full_name = ? WHERE id = ?');
            $stmt->execute([$fullName, $_SESSION['user_id']]);
            $_SESSION['display_name'] = $fullName;
            $message = 'Cập nhật tên thành công.';
            header('Location: ../HTML/index.php');
            exit;
        }
    }
}

$settingsUsers = [];
$viewPermissions = [];
if (($_SESSION['role'] ?? '') === 'admin') {
    $settingsUsers = $db->query("SELECT id, username, full_name, email, role, status FROM users ORDER BY username ASC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($db->query('SELECT viewer_id, viewed_user_id FROM user_view_permissions') as $permissionRow) {
        $viewPermissions[(int)$permissionRow['viewer_id']][] = (int)$permissionRow['viewed_user_id'];
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Cài đặt tài khoản</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../CSS/style.css?v=sidebar-fix-3">
</head>
<body>
    <?php require_once __DIR__ . '/sidebar.php'; ?>

    <div class="main-content">
        <div class="header-wrapper">
            <div>
                <h2>Cài đặt tài khoản</h2>
                <span style="font-size: 0.85rem; color: var(--text-muted);">Bạn có thể thay đổi tên hiển thị và đồng bộ dữ liệu lớp</span>
            </div>
        </div>

        <div class="card" style="max-width: 560px; margin: 0 auto;">
            <?php if (!empty($message)): ?><p class="success"><?= htmlspecialchars($message) ?></p><?php endif; ?>
            <?php if (!empty($error)): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
            <form method="POST">
                <input type="hidden" name="action" value="profile">
                <div class="form-group">
                    <label for="full_name">Tên hiển thị</label>
                    <input type="text" id="full_name" name="full_name" placeholder="Tên của bạn" value="<?= htmlspecialchars($_SESSION['display_name'] ?? $_SESSION['username'] ?? '') ?>" required>
                </div>
                <button type="submit" class="btn" style="width: 100%;">Lưu thay đổi</button>
            </form>
        </div>

        <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
            <div class="card" style="max-width: 560px; margin: 24px auto 0 auto;">
                <h3 style="margin-top:0;">Đồng bộ dữ liệu AP</h3>
                <p style="color: var(--text-muted); margin-top: 0;">Tải lại danh sách lớp, học viên từ ap.tinhoccantho.vn và tự gắn học viên vào lớp.</p>
                <form method="POST" onsubmit="return confirm('Đồng bộ lớp và học viên từ AP ngay bây giờ?');">
                    <input type="hidden" name="action" value="sync_ap_classes">
                    <button type="submit" class="btn" style="width: 100%; background:#0f766e;">Đồng bộ lớp & học viên từ AP</button>
                </form>
            </div>

            <div class="card" style="max-width: 980px; margin: 24px auto 0 auto;">
                <h3 style="margin-top:0;">Set quyền người dùng</h3>
                <p style="color: var(--text-muted); margin-top: 0;">Chọn vai trò, trạng thái tài khoản và những lịch mà từng người được phép xem.</p>

                <?php foreach ($settingsUsers as $user): ?>
                    <form method="POST" class="permission-card" style="margin-top: 14px;">
                        <input type="hidden" name="action" value="save_user_access">
                        <input type="hidden" name="target_user_id" value="<?= (int)$user['id'] ?>">

                        <div class="permission-header">
                            <div>
                                <strong><?= htmlspecialchars($user['full_name'] ?: $user['username']) ?></strong>
                                <div style="font-size:0.82rem; color:var(--text-muted); margin-top:3px;">
                                    <?= htmlspecialchars($user['username']) ?><?= !empty($user['email']) ? ' - ' . htmlspecialchars($user['email']) : '' ?>
                                </div>
                            </div>
                            <?php if ((int)$user['id'] === (int)$_SESSION['user_id']): ?>
                                <span class="badge badge-active">Tài khoản hiện tại</span>
                            <?php endif; ?>
                        </div>

                        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-top: 12px;">
                            <div class="form-group" style="margin-bottom:0;">
                                <label>Vai trò</label>
                                <select name="role" <?= (int)$user['id'] === (int)$_SESSION['user_id'] ? 'disabled' : '' ?>>
                                    <option value="user" <?= ($user['role'] ?? 'user') === 'user' ? 'selected' : '' ?>>User</option>
                                    <option value="admin" <?= ($user['role'] ?? 'user') === 'admin' ? 'selected' : '' ?>>Admin</option>
                                </select>
                            </div>
                            <div class="form-group" style="margin-bottom:0;">
                                <label>Trạng thái</label>
                                <select name="status" <?= (int)$user['id'] === (int)$_SESSION['user_id'] ? 'disabled' : '' ?>>
                                    <option value="active" <?= ($user['status'] ?? 'pending') === 'active' ? 'selected' : '' ?>>Hoạt động</option>
                                    <option value="pending" <?= ($user['status'] ?? 'pending') === 'pending' ? 'selected' : '' ?>>Chờ duyệt</option>
                                    <option value="inactive" <?= ($user['status'] ?? 'pending') === 'inactive' ? 'selected' : '' ?>>Vô hiệu hóa</option>
                                </select>
                            </div>
                        </div>

                        <div style="margin-top: 14px;">
                            <div class="permission-helper" style="margin-bottom:8px;">Được xem lịch của</div>
                            <div class="permission-group">
                                <?php foreach ($settingsUsers as $target): ?>
                                    <?php if ((int)$target['id'] === (int)$user['id']) continue; ?>
                                    <label>
                                        <input type="checkbox" name="viewed_user_ids[]" value="<?= (int)$target['id'] ?>" <?= in_array((int)$target['id'], $viewPermissions[(int)$user['id']] ?? [], true) ? 'checked' : '' ?>>
                                        <?= htmlspecialchars($target['full_name'] ?: $target['username']) ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <button type="submit" class="btn" style="margin-top: 12px;">Lưu quyền</button>
                    </form>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
