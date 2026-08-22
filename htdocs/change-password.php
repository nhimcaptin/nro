<?php
session_start();
require 'config.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$errors = [];
$success = '';
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $errors[] = 'Phiên đổi mật khẩu không hợp lệ.';
    }

    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
        $errors[] = 'Vui lòng nhập đầy đủ thông tin.';
    }
    if (strlen($newPassword) < 6) {
        $errors[] = 'Mật khẩu mới phải có ít nhất 6 ký tự.';
    }
    if ($newPassword !== $confirmPassword) {
        $errors[] = 'Mật khẩu mới nhập lại không khớp.';
    }

    if (!$errors) {
        $stmt = $mysqli->prepare('SELECT password FROM account WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $_SESSION['user_id']);
        $stmt->execute();
        $account = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$account || !hash_equals((string) $account['password'], $currentPassword)) {
            $errors[] = 'Mật khẩu hiện tại không đúng.';
        }
    }

    if (!$errors) {
        $stmt = $mysqli->prepare('UPDATE account SET password = ?, update_time = CURRENT_TIMESTAMP WHERE id = ?');
        $stmt->bind_param('si', $newPassword, $_SESSION['user_id']);
        if ($stmt->execute()) {
            $success = 'Đổi mật khẩu thành công.';
        } else {
            $errors[] = 'Không thể đổi mật khẩu lúc này.';
        }
        $stmt->close();
    }
}
?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Đổi mật khẩu | Ngọc Rồng</title>
    <link rel="stylesheet" href="assets/css/site.css">
</head>
<body>
<div class="auth-wrap"><div class="auth-card">
    <div class="kicker">Bảo vệ tài khoản</div>
    <h1>Đổi mật khẩu</h1>
    <p>Xin chào <?= htmlspecialchars($_SESSION['username']) ?>. Hãy chọn một mật khẩu mới.</p>
    <?php if ($errors): ?><div class="alert error"><?php foreach ($errors as $error): ?><?= htmlspecialchars($error) ?><br><?php endforeach; ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <form method="post">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
        <div class="field"><label for="current_password">Mật khẩu hiện tại</label><input id="current_password" type="password" name="current_password" required></div>
        <div class="field"><label for="new_password">Mật khẩu mới</label><input id="new_password" type="password" name="new_password" minlength="6" required></div>
        <div class="field"><label for="confirm_password">Nhập lại mật khẩu mới</label><input id="confirm_password" type="password" name="confirm_password" minlength="6" required></div>
        <button class="btn" type="submit">Lưu mật khẩu</button>
    </form>
    <div class="auth-foot"><a href="index.php">← Trang chủ</a> · <a href="logout.php">Đăng xuất</a></div>
</div></div>
</body>
</html>
