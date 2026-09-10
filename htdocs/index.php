<?php
session_start();
require 'config.php';

function formatPower($power) {
    $power = (int) $power;
    if ($power >= 1000000000) return number_format($power / 1000000000, 1, ',', '.') . ' Tỷ';
    if ($power >= 1000000) return number_format($power / 1000000, 1, ',', '.') . ' Tr';
    if ($power >= 1000) return number_format($power / 1000, 1, ',', '.') . ' K';
    return number_format($power, 0, ',', '.');
}

$rankings = ['master' => [], 'pet' => []];
$result = $mysqli->query('SELECT id, account_id, name, gender, data_point, pet FROM player');
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $point = json_decode($row['data_point'], true);
        $power = isset($point[1]) ? (int) $point[1] : 0;
        $entry = ['name' => $row['name'], 'power' => $power, 'gender' => (int) $row['gender']];
        $rankings['master'][] = $entry;
        $pet = json_decode($row['pet'], true);
        if (is_array($pet) && isset($pet[0][2])) {
            $petPoint = isset($pet[1]) && is_array($pet[1]) ? $pet[1] : [];
            $rankings['pet'][] = ['name' => (string) $pet[0][2], 'power' => isset($petPoint[1]) ? (int) $petPoint[1] : 0, 'gender' => -1];
        }
    }
}
foreach ($rankings as &$list) {
    usort($list, fn($a, $b) => $b['power'] <=> $a['power']);
    $list = array_slice($list, 0, 10);
}
unset($list);

$discordLinked = false;
$isAdmin = false;
$justRegistered = ($_GET['registered'] ?? '') === '1';
$justLinked = ($_GET['linked'] ?? '') === '1';
if (!empty($_SESSION['user_id'])) {
    $stmt = $mysqli->prepare('SELECT is_admin FROM account WHERE id = ? LIMIT 1');
    if ($stmt) {
        $userId = (int) $_SESSION['user_id'];
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $isAdmin = (int) ($stmt->get_result()->fetch_assoc()['is_admin'] ?? 0) === 1;
        $stmt->close();
    }
    $column = $mysqli->query("SHOW COLUMNS FROM account LIKE 'discord_id'");
    if ($column && $column->num_rows > 0) {
        $stmt = $mysqli->prepare('SELECT discord_id FROM account WHERE id = ? LIMIT 1');
        if ($stmt) {
            $userId = (int) $_SESSION['user_id'];
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $account = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $discordLinked = !empty($account['discord_id']);
        }
    }
}
?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ngọc Rồng | Bảng xếp hạng</title>
    <link rel="stylesheet" href="assets/css/site.css">
</head>
<body>
<header class="site-header"><div class="shell nav">
    <a class="brand" href="index.php"><span class="brand-mark">★</span>Ngọc Rồng</a>
    <nav class="nav-links">
        <a href="index.php">Bảng xếp hạng</a>
        <?php if (!empty($_SESSION['username'])): ?>
            <?php if ($discordLinked): ?>
                <a href="discord-verify.php">Đã liên kết Discord</a>
            <?php else: ?>
                <a class="btn" href="discord-verify.php">Liên kết Discord</a>
            <?php endif; ?>
            <?php if ($isAdmin): ?><a href="admin.php">Quản trị</a><?php endif; ?>
            <a href="change-password.php">Đổi mật khẩu</a>
            <a href="logout.php">Đăng xuất</a>
        <?php else: ?>
            <a href="login.php">Đăng nhập</a><a class="btn" href="register.php">Đăng ký</a>
        <?php endif; ?>
    </nav>
</div></header>
<main><div class="shell">
    <section class="hero">
        <div class="hero-copy">
            <div class="kicker">Vũ trụ chiến binh</div>
            <h1>Đấu trường<br><span>sức mạnh</span></h1>
            <p>Ai sẽ đứng đầu hành tinh? Theo dõi những chiến binh mạnh nhất và đệ tử huyền thoại của máy chủ.</p>
            <?php if ($justRegistered): ?><div class="notice">Đăng ký thành công. Bạn có thể vào game ngay; liên kết Discord là tùy chọn.</div><?php endif; ?>
            <?php if ($justLinked): ?><div class="notice">Liên kết Discord thành công. Tài khoản đã được kích hoạt.</div><?php endif; ?>
            <?php if (!empty($_SESSION['username'])): ?>
                <?php if (!$discordLinked): ?><a class="btn" href="discord-verify.php">Liên kết Discord</a><?php endif; ?>
            <?php else: ?>
                <a class="btn" href="register.php">Tạo tài khoản</a>
            <?php endif; ?>
        </div>
        <div class="hero-orbit"><div class="stars">✦　✧　✦<br>　✧　✦　✧</div><div class="sun">★</div></div>
    </section>
    <div class="section-heading"><div><div class="kicker">Bảng vàng chiến binh</div><h2>Xếp hạng sức mạnh</h2></div><p>Cập nhật theo dữ liệu nhân vật trong máy chủ</p></div>
    <section class="rankings">
        <div class="panel"><div class="panel-head"><h3>Sư phụ</h3><small>Top 10</small></div>
            <?php if (!$rankings['master']): ?><div class="empty">Chưa có chiến binh nào.</div><?php endif; ?>
            <?php foreach ($rankings['master'] as $i => $player): ?><div class="rank-row"><div class="rank-number">#<?= $i + 1 ?></div><div><div class="rank-name"><?= htmlspecialchars($player['name']) ?></div><div class="rank-meta">Chiến binh hành tinh <?= $player['gender'] === 0 ? 'Trái Đất' : ($player['gender'] === 1 ? 'Namếc' : 'Xayda') ?></div></div><div class="power"><?= formatPower($player['power']) ?></div></div><?php endforeach; ?>
        </div>
        <div class="panel"><div class="panel-head"><h3>Đệ tử</h3><small>Top 10</small></div>
            <?php if (!$rankings['pet']): ?><div class="empty">Chưa có đệ tử nào.</div><?php endif; ?>
            <?php foreach ($rankings['pet'] as $i => $pet): ?><div class="rank-row"><div class="rank-number">#<?= $i + 1 ?></div><div><div class="rank-name"><?= htmlspecialchars($pet['name']) ?></div><div class="rank-meta">Đệ tử đang tu luyện</div></div><div class="power"><?= formatPower($pet['power']) ?></div></div><?php endforeach; ?>
        </div>
    </section>
</div></main>
<footer><div class="shell">Ngọc Rồng Online · Hành trình của bạn bắt đầu từ đây.</div></footer>
</body></html>
