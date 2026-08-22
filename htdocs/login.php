<?php
session_start();
require 'config.php';

if (!empty($_SESSION['username'])) {
    header('Location: index.php');
    exit;
}
$errors = [];
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) $errors[] = 'Phiên đăng nhập không hợp lệ.';
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if ($username === '' || $password === '') $errors[] = 'Vui lòng nhập tài khoản và mật khẩu.';
    if (!$errors) {
        $stmt = $mysqli->prepare('SELECT id, username, password, ban, active FROM account WHERE username = ? LIMIT 1');
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $account = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$account || !hash_equals((string) $account['password'], $password)) $errors[] = 'Tài khoản hoặc mật khẩu không đúng.';
        elseif ((int) $account['ban'] === 1) $errors[] = 'Tài khoản đang bị khóa.';
        elseif ((int) $account['active'] !== 1) $errors[] = 'Tài khoản chưa được kích hoạt.';
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
<!doctype html><html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Đăng nhập | Ngọc Rồng</title><link rel="stylesheet" href="assets/css/site.css"></head><body>
<div class="auth-wrap"><div class="auth-card"><div class="kicker">Cổng chiến binh</div><h1>Đăng nhập</h1><p>Trở lại hành trình và tiếp tục chinh phục vũ trụ.</p>
<?php if ($errors): ?><div class="alert error"><?php foreach ($errors as $error): ?><?= htmlspecialchars($error) ?><br><?php endforeach; ?></div><?php endif; ?>
<form method="post"><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>"><div class="field"><label for="username">Tài khoản</label><input id="username" name="username" maxlength="20" required value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"></div><div class="field"><label for="password">Mật khẩu</label><input id="password" type="password" name="password" required></div><button class="btn" type="submit">Vào vũ trụ</button></form><div class="auth-foot"><a href="index.php">← Trang chủ</a> · Chưa có tài khoản? <a href="register.php">Đăng ký</a></div></div></div></body></html>
