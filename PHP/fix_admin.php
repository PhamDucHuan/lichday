<?php
require_once 'config.php';

$username = 'admin';
$password = '123456'; // Mật khẩu bạn muốn đặt, có thể đổi tùy ý
$role = 'admin';

// PHP tự động băm mật khẩu chuẩn mã hóa bcrypt
$hashed_password = password_hash($password, PASSWORD_BCRYPT);

try {
    // Xóa tài khoản admin cũ nếu có để tránh trùng lặp
    $db->prepare("DELETE FROM users WHERE username = ?")->execute([$username]);
    
    // Thêm tài khoản mới tinh
    $stmt = $db->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, ?)");
    $stmt->execute([$username, $hashed_password, $role]);
    
    echo "<h2 style='color: green;'>Khởi tạo tài khoản Admin thành công!</h2>";
    echo "• Tên đăng nhập: <b>$username</b><br>";
    echo "• Mật khẩu: <b>$password</b><br><br>";
    echo "<a href='login.php'>👉 Bấm vào đây để quay lại trang Đăng nhập</a>";
} catch (PDOException $e) {
    echo "<h2 style='color: red;'>Lỗi hệ thống:</h2> " . $e->getMessage();
}
?>