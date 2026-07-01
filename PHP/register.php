<?php
session_start();
require_once 'config.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $fullName = trim($_POST['full_name'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $confirmPassword = trim($_POST['confirm_password'] ?? '');
    $email = trim($_POST['email'] ?? '');

    if ($password !== $confirmPassword) {
        $error = 'Mật khẩu xác nhận không khớp.';
    } elseif (strlen($password) < 6) {
        $error = 'Mật khẩu phải có ít nhất 6 ký tự.';
    } elseif ($username === '' || $fullName === '' || $password === '') {
        $error = 'Vui lòng nhập đầy đủ thông tin.';
    } else {
        $check = $db->prepare('SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1');
        $check->execute([$username, $email]);
        if ($check->fetch()) {
            $error = 'Tên đăng nhập hoặc email đã tồn tại.';
        } else {
            $role = 'user';
            $status = 'pending';
            $message = 'Đăng ký thành công. Tài khoản của bạn đang chờ admin duyệt.';

            if ($username === 'admin' || strtolower($username) === 'admin') {
                $role = 'admin';
                $status = 'active';
                $message = 'Đăng ký thành công. Tài khoản admin đã được kích hoạt ngay.';
            }

            $stmt = $db->prepare('INSERT INTO users (username, full_name, password, email, role, status) VALUES (?, ?, ?, ?, ?, ?)');
            $stmt->execute([$username, $fullName, password_hash($password, PASSWORD_BCRYPT), $email, $role, $status]);
            $_SESSION['registration_success'] = $message;
            header('Location: login.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Đăng Ký Tài Khoản</title>
    <link rel="stylesheet" href="../CSS/style.css?v=sidebar-fix-3">
</head>
<body class="auth-page">
    <div class="login-box">
        <div class="login-brand">Lịch Dạy Nội Bộ</div>
        <h2>Đăng ký tài khoản</h2>
        <p class="login-subtitle">Tài khoản thường sẽ cần admin kích hoạt, còn tài khoản admin sẽ được kích hoạt ngay</p>
        <?php if(!empty($error)): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
        <form action="register.php" method="POST">
            <input type="text" name="username" placeholder="Tên đăng nhập" required>
            <input type="text" name="full_name" placeholder="Tên của bạn" required>
            <input type="email" name="email" placeholder="Email" required>
            <input type="password" name="password" placeholder="Mật khẩu" required>
            <input type="password" name="confirm_password" placeholder="Nhập lại mật khẩu" required>
            <button type="submit" class="btn">Đăng ký</button>
        </form>
        <p class="form-help"><a href="login.php">Quay lại đăng nhập</a></p>
    </div>
</body>
</html>
