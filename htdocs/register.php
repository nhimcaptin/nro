<?php
require 'config.php';

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $repass   = trim($_POST['repass'] ?? '');

    if ($username === '' || $password === '' || $repass === '') {
        $errors[] = 'Vui lòng nhập đầy đủ thông tin.';
    }
    if ($password !== $repass) {
        $errors[] = 'Mật khẩu nhập lại không khớp.';
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
    <title>Đăng ký tài khoản NRO</title>
    <style>
        body { font-family: Arial, sans-serif; background: #0b1220; color: #e5e7eb; }
        .container { max-width: 420px; margin: 60px auto; background: #111827; padding: 24px; border-radius: 8px; box-shadow: 0 10px 25px rgba(0,0,0,.5); }
        h1 { text-align: center; margin-bottom: 20px; color: #38bdf8; }
        label { display:block; margin-top:10px; font-size:14px; }
        input[type=text], input[type=password] {
            width:100%; padding:8px 10px; margin-top:4px;
            border-radius:4px; border:1px solid #374151; background:#020617; color:#e5e7eb;
        }
        button {
            margin-top:18px; width:100%; padding:10px;
            background:#22c55e; border:none; border-radius:4px;
            color:#022c22; font-weight:bold; cursor:pointer;
        }
        button:hover { background:#16a34a; }
        .msg-error { background:#7f1d1d; padding:8px; margin-top:10px; border-radius:4px; font-size:13px; }
        .msg-success { background:#064e3b; padding:8px; margin-top:10px; border-radius:4px; font-size:13px; }
    </style>
</head>
<body>
<div class="container">
    <h1>Đăng ký tài khoản</h1>

    <?php if ($errors): ?>
        <div class="msg-error">
            <?php foreach ($errors as $e) echo htmlspecialchars($e) . '<br>'; ?>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="msg-success">
            <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>

    <form method="post">
        <label>Tài khoản</label>
        <input type="text" name="username" required>

        <label>Mật khẩu</label>
        <input type="password" name="password" required>

        <label>Nhập lại mật khẩu</label>
        <input type="password" name="repass" required>

        <button type="submit">Đăng ký</button>
    </form>
</div>
</body>
</html>