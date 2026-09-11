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

        // Cột pet lưu dạng: ["[type,gender,name,...]", "[limit,power,tiemNang,...]", body, skills]
        // Mỗi phần tử là chuỗi JSON lồng nhau, phải decode thêm một lần.
        $petRaw = json_decode((string) $row['pet'], true);
        if (!is_array($petRaw) || count($petRaw) < 2) {
            continue;
        }
        $petInfo = is_string($petRaw[0] ?? null) ? json_decode($petRaw[0], true) : ($petRaw[0] ?? null);
        $petPoint = is_string($petRaw[1] ?? null) ? json_decode($petRaw[1], true) : ($petRaw[1] ?? null);
        if (!is_array($petInfo) || !isset($petInfo[2]) || !is_array($petPoint)) {
            continue;
        }
        $petName = ltrim((string) $petInfo[2], '$');
        if ($petName === '') {
            $petName = 'Đệ tử';
        }
        $rankings['pet'][] = [
            'name' => $petName,
            'owner' => (string) $row['name'],
            'power' => (int) ($petPoint[1] ?? 0),
            'gender' => (int) ($petInfo[1] ?? -1),
        ];
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
    <title>NhimsNRO | Đấu Trường Vũ Trụ</title>
    <link rel="stylesheet" href="assets/css/site.css">
</head>
<body>
<div class="div-12">
    <span class="badge-18">18+</span>
    <span>Chơi quá 180 phút một ngày sẽ ảnh hưởng xấu đến sức khỏe.</span>
</div>
<header class="site-header">
    <div class="shell nav">
        <a class="brand" href="index.php">
            <img class="brand-icon" src="assets/images/14.png" alt="Dragon Ball">
            <span class="brand-title-nro">NhimsNRO</span>
        </a>
        <nav class="nav-links">
            <a class="active" href="index.php">Trang Chủ</a>
            <?php if (!empty($_SESSION['username'])): ?>
                <?php if ($discordLinked): ?>
                    <a href="discord-verify.php" style="background: rgba(16, 185, 129, 0.2); border-color: #10b981; color: #34d399;">✓ Đã Kết Nối Discord</a>
                <?php else: ?>
                    <a href="discord-verify.php">Liên Kết Discord</a>
                <?php endif; ?>
                <?php if ($isAdmin): ?>
                    <a class="btn gold" href="admin.php">⚡ Bảng Quản Trị</a>
                <?php endif; ?>
                <a href="change-password.php">Đổi Mật Khẩu</a>
                <a href="logout.php">Đăng Xuất (<?= htmlspecialchars($_SESSION['username']) ?>)</a>
            <?php else: ?>
                <a href="login.php">Đăng Nhập</a>
                <a class="btn" href="register.php">Đăng Ký Chơi Ngay</a>
            <?php endif; ?>
        </nav>
    </div>
</header>
<main>
    <div class="shell">
        <div class="main-box-nro">
            <div class="hero-nro">
                <div class="dragon-balls-row">
                    <img class="db-ball" src="assets/images/14.png" alt="1 Sao" title="Ngọc Rồng 1 Sao">
                    <img class="db-ball" src="assets/images/15.png" alt="2 Sao" title="Ngọc Rồng 2 Sao">
                    <img class="db-ball" src="assets/images/16.png" alt="3 Sao" title="Ngọc Rồng 3 Sao">
                    <img class="db-ball" src="assets/images/17.png" alt="4 Sao" title="Ngọc Rồng 4 Sao">
                    <img class="db-ball" src="assets/images/18.png" alt="5 Sao" title="Ngọc Rồng 5 Sao">
                    <img class="db-ball" src="assets/images/19.png" alt="6 Sao" title="Ngọc Rồng 6 Sao">
                    <img class="db-ball" src="assets/images/20.png" alt="7 Sao" title="Ngọc Rồng 7 Sao">
                </div>
                <h1>Đấu Trường Sức Mạnh Siêu Cấp</h1>
                <p>Khám phá thế giới 7 viên ngọc rồng, khẳng định sức mạnh chiến binh vô địch và đồng hành cùng những đệ tử thần thoại.</p>
                <?php if ($justRegistered): ?>
                    <div class="notice">🎉 Chúc mừng bạn đã gia nhập vũ trụ Ngọc Rồng! Đăng nhập ngay để bắt đầu cuộc hành trình.</div>
                <?php endif; ?>
                <?php if ($justLinked): ?>
                    <div class="notice">✨ Liên kết Discord thành công! Tài khoản của bạn đã được bảo vệ tối đa.</div>
                <?php endif; ?>
                <div style="margin-top: 10px;">
                    <?php if (!empty($_SESSION['username'])): ?>
                        <?php if (!$discordLinked): ?>
                            <a class="btn secondary" href="discord-verify.php">Liên Kết Discord Ngay</a>
                        <?php endif; ?>
                    <?php else: ?>
                        <a class="btn" href="register.php" style="font-size: 14px; padding: 10px 22px;">⚡ Tham Gia Chiến Trận Ngay</a>
                    <?php endif; ?>
                </div>
            </div>

            <section class="rankings">
                <div class="panel">
                    <div class="panel-head">
                        <h3>
                            <img class="panel-head-icon" src="assets/images/4028.png" alt="Trophy">
                            Top 10 Cao Thủ Sư Phụ
                        </h3>
                        <small>BXH Chiến Binh</small>
                    </div>
                    <?php if (!$rankings['master']): ?>
                        <div class="empty">Chưa có chiến binh nào ghi danh.</div>
                    <?php endif; ?>
                    <?php foreach ($rankings['master'] as $i => $player): 
                        $rankClass = $i === 0 ? 'top-1' : ($i === 1 ? 'top-2' : ($i === 2 ? 'top-3' : ''));
                        $planetClass = $player['gender'] === 0 ? 'planet-earth' : ($player['gender'] === 1 ? 'planet-namec' : 'planet-saiyan');
                        $planetName = $player['gender'] === 0 ? 'Trái Đất' : ($player['gender'] === 1 ? 'Namếc' : 'Xayda');
                        $avatar = $player['gender'] === 0 ? '538.png' : ($player['gender'] === 1 ? '539.png' : '540.png');
                    ?>
                        <div class="rank-row <?= $rankClass ?>">
                            <div class="rank-number">
                                <?= $i === 0 ? '🥇' : ($i === 1 ? '🥈' : ($i === 2 ? '🥉' : '#' . ($i + 1))) ?>
                            </div>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <img src="assets/images/<?= $avatar ?>" alt="Avatar" style="width: 32px; height: 32px; object-fit: contain;">
                                <div>
                                    <div class="rank-name"><?= htmlspecialchars($player['name']) ?></div>
                                    <div class="rank-meta">
                                        <span class="planet-badge <?= $planetClass ?>"><?= $planetName ?></span>
                                    </div>
                                </div>
                            </div>
                            <div class="power">⚡ <?= formatPower($player['power']) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="panel">
                    <div class="panel-head">
                        <h3>
                            <img class="panel-head-icon" src="assets/images/77.png" alt="Pet">
                            Top 10 Đệ Tử Thần Thoại
                        </h3>
                        <small>BXH Đệ Tử</small>
                    </div>
                    <?php if (!$rankings['pet']): ?>
                        <div class="empty">Chưa có đệ tử nào xuất hiện.</div>
                    <?php endif; ?>
                    <?php foreach ($rankings['pet'] as $i => $pet): 
                        $rankClass = $i === 0 ? 'top-1' : ($i === 1 ? 'top-2' : ($i === 2 ? 'top-3' : ''));
                    ?>
                        <div class="rank-row <?= $rankClass ?>">
                            <div class="rank-number">
                                <?= $i === 0 ? '🥇' : ($i === 1 ? '🥈' : ($i === 2 ? '🥉' : '#' . ($i + 1))) ?>
                            </div>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <img src="assets/images/77.png" alt="Pet" style="width: 28px; height: 28px; object-fit: contain;">
                                <div>
                                    <div class="rank-name"><?= htmlspecialchars($pet['name']) ?></div>
                                    <div class="rank-meta" style="color: #a78bfa;">✦ Đệ của <?= htmlspecialchars($pet['owner']) ?></div>
                                </div>
                            </div>
                            <div class="power">⚡ <?= formatPower($pet['power']) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>
    </div>
</main>
<footer>
    <div class="shell">
        NhimsNRO · Máy chủ chiến trường 7 Viên Ngọc Rồng huyền thoại trên PC & Mobile.
    </div>
</footer>
</body></html>
