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
            <a href="change-password.php">Đổi mật khẩu</a>
            <a href="logout.php">Đăng xuất</a>
        <?php else: ?>
            <a href="login.php">Đăng nhập</a><a class="btn" href="register.php">Đăng ký</a>
        <?php endif; ?>
    </nav>
</div></header>
<main><div class="shell">
    <section class="hero">
        <div class="hero-copy"><div class="kicker">Vũ trụ chiến binh</div><h1>Đấu trường<br><span>sức mạnh</span></h1><p>Ai sẽ đứng đầu hành tinh? Theo dõi những chiến binh mạnh nhất và đệ tử huyền thoại của máy chủ.</p><a class="btn" href="register.php">Tạo tài khoản</a></div>
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
