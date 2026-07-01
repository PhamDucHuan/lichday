<?php
session_start();
require_once 'config.php';

$error = '';
$success = '';
$loginRecaptchaSiteKey = (string)($recaptchaSiteKey ?? '');
$loginRecaptchaSecretKey = (string)($recaptchaSecretKey ?? '');
$loginRecaptchaEnabled = $loginRecaptchaSiteKey !== '' && $loginRecaptchaSecretKey !== '';

if (!empty($_SESSION['registration_success'])) {
    $success = $_SESSION['registration_success'];
    unset($_SESSION['registration_success']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $loginRecaptchaToken = (string)($_POST['g-recaptcha-response'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Vui lòng nhập đầy đủ thông tin!';
    } elseif ($loginRecaptchaEnabled && !verifyRecaptcha($loginRecaptchaSecretKey, $loginRecaptchaToken, $_SERVER['REMOTE_ADDR'] ?? null)) {
        $error = 'Vui lòng xác nhận bạn không phải người máy.';
    } else {
        $stmt = $db->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $error = 'Tài khoản không tồn tại!';
        } elseif (!password_verify($password, $user['password'])) {
            $error = 'Mật khẩu không chính xác!';
        } elseif ((($user['role'] ?? 'user') === 'admin') || (($user['status'] ?? 'pending') === 'active')) {
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['display_name'] = !empty($user['full_name']) ? $user['full_name'] : $user['username'];
            $_SESSION['role'] = $user['role'] ?? 'user';

            header('Location: ../HTML/index.php');
            exit;
        } elseif (($user['status'] ?? 'pending') === 'pending') {
            $error = 'Tài khoản đang chờ admin duyệt. Vui lòng chờ kích hoạt.';
        } else {
            $error = 'Tài khoản đã bị vô hiệu hóa.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Đăng Nhập Hệ Thống Nội Bộ</title>
    <link rel="stylesheet" href="../CSS/style.css?v=sidebar-fix-3">
    <?php if ($loginRecaptchaEnabled): ?>
        <script src="https://www.google.com/recaptcha/api.js" async defer></script>
    <?php endif; ?>
</head>
<body class="login-page">
    <div class="login-box">
        <div class="login-brand">Lịch Dạy Nội Bộ</div>
        <h2>Đăng nhập</h2>
        <p class="login-subtitle">Quản lý thời khóa biểu nội bộ</p>

        <?php if (!empty($success)): ?>
            <p class="success"><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>

        <?php if (!empty($error)): ?>
            <p class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>

        <form action="login.php" method="POST">
            <input type="text" name="username" placeholder="Tên đăng nhập" required>
            <input type="password" name="password" placeholder="Mật khẩu" required>
            <?php if ($loginRecaptchaEnabled): ?>
                <div class="captcha-box">
                    <div class="g-recaptcha" data-sitekey="<?= htmlspecialchars($loginRecaptchaSiteKey, ENT_QUOTES, 'UTF-8') ?>"></div>
                </div>
            <?php endif; ?>
            <button type="submit" class="btn" id="loginButton">Đăng Nhập</button>
        </form>

        <p class="form-help">Chưa có tài khoản? <a href="register.php">Đăng ký ngay</a></p>
    </div>
</body>
</html>
