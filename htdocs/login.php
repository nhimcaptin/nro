<?php
session_start();
require 'config.php';

if (!empty($_SESSION['username'])) {
    header('Location: index.php');
    exit;
}
$errors = [];
$verified = ($_GET['verified'] ?? '') === '1';
if ($verified) {
    echo '<script>window.addEventListener("DOMContentLoaded", function () { alert("Liên kết Discord thành công! Tài khoản đã được kích hoạt."); history.replaceState({}, document.title, "login.php"); });</script>';
}
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) $errors[] = 'Phiên đăng nhập không hợp lệ.';
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if ($username === '' || $password === '') $errors[] = 'Vui lòng nhập tài khoản và mật khẩu.';
    if (!$errors) {
        $stmt = $mysqli->prepare('SELECT id, username, password, ban FROM account WHERE username = ? LIMIT 1');
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $account = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$account || !hash_equals((string) $account['password'], $password)) $errors[] = 'Tài khoản hoặc mật khẩu không đúng.';
        elseif ((int) $account['ban'] === 1) $errors[] = 'Tài khoản đang bị khóa.';
        else {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $account['id'];
            $_SESSION['username'] = $account['username'];
            header('Location: index.php');
            exit;
        }
    }
}
?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Đăng Nhập | NhimsNRO</title>
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
            <img src="assets/images/14.png" alt="Dragon Ball">
        </div>
        <h1>ĐĂNG NHẬP CHIẾN BINH</h1>
        <p>Đăng nhập tài khoản để bước vào thế giới NhimsNRO.</p>

        <?php if ($errors): ?>
            <div class="alert error">
                <?php foreach ($errors as $error): ?>
                    <?= htmlspecialchars($error) ?><br>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="post">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
            <div class="field">
                <label for="username">Tên tài khoản</label>
                <input id="username" name="username" maxlength="20" placeholder="Nhập tài khoản" required value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="password">Mật khẩu</label>
                <input id="password" type="password" name="password" placeholder="Nhập mật khẩu" required>
            </div>
            <button class="btn" type="submit">⚡ Đăng Nhập Ngay</button>
        </form>

        <div class="auth-foot">
            <a href="index.php">← Về Trang Chủ</a> · Chưa có tài khoản? <a href="register.php">Đăng ký ngay</a>
        </div>
    </div>
</div>
</body>
</html>
