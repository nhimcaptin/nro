<?php
session_start();
require 'config.php';

$errors = [];
$success = '';
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $errors[] = 'Phiên đăng ký không hợp lệ.';
    }
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $repass   = trim($_POST['repass'] ?? '');

    if ($username === '' || $password === '' || $repass === '') {
        $errors[] = 'Vui lòng nhập đầy đủ thông tin.';
    }
    if ($password !== $repass) {
        $errors[] = 'Mật khẩu nhập lại không khớp.';
    }
    if (strlen($password) < 6) {
        $errors[] = 'Mật khẩu phải có ít nhất 6 ký tự.';
    }
    if (strlen($username) < 3 || strlen($username) > 20) {
        $errors[] = 'Tài khoản phải từ 3 đến 20 ký tự.';
    }

    if (!$errors) {
        $stmt = $mysqli->prepare('SELECT id FROM account WHERE username = ? LIMIT 1');
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $stmt->store_result();
        if ($stmt->num_rows > 0) {
            $errors[] = 'Tài khoản đã tồn tại.';
        }
        $stmt->close();
    }

    if (!$errors) {
        $email    = '';
        $ban      = 0;
        $is_admin = 0;
        $active   = 1;

        $stmt = $mysqli->prepare(
            'INSERT INTO account (username, password, email, ban, is_admin, active)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('sssiii', $username, $password, $email, $ban, $is_admin, $active);

        if ($stmt->execute()) {
            $success = 'Đăng ký thành công! Bạn có thể vào game và đăng nhập.';
        } else {
            $errors[] = 'Có lỗi xảy ra khi tạo tài khoản: ' . $stmt->error;
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <link rel="stylesheet" href="assets/css/site.css">
</head>
<body>
<div class="auth-wrap"><div class="auth-card">
    <div class="kicker">Khởi đầu hành trình</div><h1>Đăng ký</h1><p>Tạo tài khoản và bước vào vũ trụ chiến binh.</p>

    <?php if ($errors): ?>
        <div class="alert error">
            <?php foreach ($errors as $e) echo htmlspecialchars($e) . '<br>'; ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert success">
            <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>

    <form method="post"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
        <div class="field"><label for="username">Tài khoản</label><input id="username" type="text" name="username" maxlength="20" required value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"></div>
        <div class="field"><label for="password">Mật khẩu</label><input id="password" type="password" name="password" required></div>
        <div class="field"><label for="repass">Nhập lại mật khẩu</label><input id="repass" type="password" name="repass" required></div>
        <button class="btn" type="submit">Bắt đầu chơi</button>
    </form><div class="auth-foot"><a href="index.php">← Trang chủ</a> · Đã có tài khoản? <a href="login.php">Đăng nhập</a></div>
</div></div>
</body>
</html>