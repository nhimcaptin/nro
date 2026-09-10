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
        $active   = 0;

        $stmt = $mysqli->prepare(
            'INSERT INTO account (username, password, email, ban, is_admin, active)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('sssiii', $username, $password, $email, $ban, $is_admin, $active);

        if ($stmt->execute()) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $mysqli->insert_id;
            $_SESSION['username'] = $username;
            $stmt->close();
            header('Location: index.php?registered=1');
            exit;
        }

        $errors[] = 'Không thể tạo tài khoản. Vui lòng thử lại.';
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Đăng Ký Tài Khoản | NhimsNRO</title>
    <link rel="stylesheet" href="assets/css/site.css">
</head>
<body>
<div class="div-12">
    <span class="badge-18">18+</span>
    <span>Chơi quá 180 phút một ngày sẽ ảnh hưởng xấu đến sức khỏe.</span>
</div>
<div class="auth-wrap">
    <div class="auth-card">
        <div class="auth-icon-header">
            <img src="assets/images/15.png" alt="Dragon Ball 2 Sao">
        </div>
        <h1>ĐĂNG KÝ TÀI KHOẢN MỚI</h1>
        <p>Gia nhập hàng ngũ chiến binh vũ trụ NhimsNRO ngay hôm nay.</p>

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

        <form method="post">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
            <div class="field">
                <label for="username">Tài khoản (3 - 20 ký tự)</label>
                <input id="username" type="text" name="username" maxlength="20" placeholder="Nhập tên tài khoản" required value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="password">Mật khẩu (tối thiểu 6 ký tự)</label>
                <input id="password" type="password" name="password" placeholder="Nhập mật khẩu" required>
            </div>
            <div class="field">
                <label for="repass">Xác nhận lại mật khẩu</label>
                <input id="repass" type="password" name="repass" placeholder="Nhập lại mật khẩu" required>
            </div>
            <button class="btn" type="submit">⚡ Khởi Tạo Tài Khoản</button>
        </form>
        <div class="auth-foot">
            <a href="index.php">← Về Trang Chủ</a> · Đã có tài khoản? <a href="login.php">Đăng nhập ngay</a>
        </div>
    </div>
</div>
</body>
</html>
