<?php
session_start();
require_once 'config.php';

$error = '';
$success = '';

if (!empty($_SESSION['registration_success'])) {
    $success = $_SESSION['registration_success'];
    unset($_SESSION['registration_success']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    if (!empty($username) && !empty($password)) {
        $stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password'])) {
            if ((($user['role'] ?? 'user') === 'admin') || (($user['status'] ?? 'pending') === 'active')) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['display_name'] = !empty($user['full_name']) ? $user['full_name'] : $user['username'];
                $_SESSION['role'] = $user['role'];

                header('Location: ../HTML/index.php');
                exit;
            }

            if (($user['status'] ?? 'pending') === 'pending') {
                $error = 'Tài khoản đang chờ admin duyệt. Vui lòng chờ kích hoạt.';
            } else {
                $error = 'Tài khoản đã bị vô hiệu hóa.';
            }
        } else {
            $error = 'Tài khoản hoặc mật khẩu không chính xác!';
        }
    } else {
        $error = 'Vui lòng nhập đầy đủ thông tin!';
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Đăng Nhập Hệ Thống Nội Bộ</title>
    <link rel="stylesheet" href="../CSS/style.css">
</head>
<body class="login-page">
    <div class="login-box">
        <div class="login-brand">Lịch Dạy Nội Bộ</div>
        <h2>Đăng nhập</h2>
        <p class="login-subtitle">Quản lý thời khóa biểu nội bộ</p>
        <?php if(!empty($success)): ?>
            <p class="success"><?= $success ?></p>
        <?php endif; ?>
        <?php if(!empty($error)): ?>
            <p class="error"><?= $error ?></p>
        <?php endif; ?>
        <form action="login.php" method="POST">
            <input type="text" name="username" placeholder="Tên đăng nhập" required>
            <input type="password" name="password" placeholder="Mật khẩu" required>
            <button type="submit" class="btn">Đăng Nhập</button>
        </form>
        <p class="form-help">Chưa có tài khoản? <a href="register.php">Đăng ký ngay</a></p>
    </div>
</body>
</html>