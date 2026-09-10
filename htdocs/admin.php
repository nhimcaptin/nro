<?php
session_start();
require 'config.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$stmt = $mysqli->prepare('SELECT is_admin FROM account WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $_SESSION['user_id']);
$stmt->execute();
$me = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$me || (int) $me['is_admin'] !== 1) {
    http_response_code(403);
    exit('403 - Bạn không có quyền truy cập trang này.');
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

$mysqli->query('CREATE TABLE IF NOT EXISTS admin_item_log (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    admin_username VARCHAR(255) NOT NULL,
    player_id INT NOT NULL,
    player_name VARCHAR(255) NOT NULL,
    container VARCHAR(50) NOT NULL,
    slot INT NOT NULL,
    item_id INT NOT NULL,
    item_name VARCHAR(255) NOT NULL,
    quantity INT NOT NULL,
    reason VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

/** Các cột chứa vật phẩm của nhân vật, khớp với PlayerDAO.updatePlayer */
const CONTAINERS = [
    'items_body' => 'Trang bị đang mặc',
    'items_bag' => 'Hành trang',
    'items_box' => 'Rương đồ',
    'items_box_lucky_round' => 'Rương vòng quay',
    'items_daban' => 'Đại bàn',
];

const POINT_LABELS = [
    0 => 'Giới hạn sức mạnh', 1 => 'Sức mạnh', 2 => 'Tiềm năng', 3 => 'Thể lực', 4 => 'Thể lực tối đa',
    5 => 'HP gốc', 6 => 'KI gốc', 7 => 'Sức đánh gốc', 8 => 'Giáp gốc', 9 => 'Chí mạng',
    10 => 'Chí mạng rồng', 12 => 'HP hiện tại', 13 => 'KI hiện tại',
];

function loadTemplates(mysqli $mysqli): array {
    $names = [];
    $rs = $mysqli->query('SELECT id, NAME FROM item_template');
    while ($rs && $row = $rs->fetch_assoc()) {
        $names[(int) $row['id']] = $row['NAME'];
    }
    return $names;
}

function loadOptionTemplates(mysqli $mysqli): array {
    $names = [];
    $rs = $mysqli->query('SELECT id, NAME FROM item_option_template');
    while ($rs && $row = $rs->fetch_assoc()) {
        $names[(int) $row['id']] = $row['NAME'];
    }
    return $names;
}

/** Mỗi ô là chuỗi JSON "[templateId, quantity, optionsJson, createTime]" */
function parseContainer(?string $raw): array {
    $slots = json_decode((string) $raw, true);
    if (!is_array($slots)) {
        return [];
    }
    $items = [];
    foreach ($slots as $i => $slot) {
        $data = is_string($slot) ? json_decode($slot, true) : $slot;
        if (!is_array($data)) {
            $items[$i] = null;
            continue;
        }
        $templateId = (int) ($data[0] ?? -1);
        if ($templateId === -1) {
            $items[$i] = null;
            continue;
        }
        $options = [];
        $rawOptions = is_string($data[2] ?? null) ? json_decode($data[2], true) : ($data[2] ?? []);
        if (is_array($rawOptions)) {
            foreach ($rawOptions as $option) {
                $pair = is_string($option) ? json_decode($option, true) : $option;
                if (is_array($pair) && count($pair) >= 2) {
                    $options[] = [(int) $pair[0], (int) $pair[1]];
                }
            }
        }
        $items[$i] = [
            'template_id' => $templateId,
            'quantity' => (int) ($data[1] ?? 0),
            'options' => $options,
            'create_time' => $data[3] ?? 0,
        ];
    }
    return $items;
}

function emptySlotJson($createTime): string {
    return json_encode([-1, 0, '[]', $createTime], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function isAccountOnline(?array $account): bool {
    if (!$account) {
        return false;
    }
    return strtotime((string) $account['last_time_login']) > strtotime((string) $account['last_time_logout']);
}

function formatNumber($value): string {
    return number_format((float) $value, 0, ',', '.');
}

$itemNames = loadTemplates($mysqli);
$optionNames = loadOptionTemplates($mysqli);

$errors = [];
$success = null;
$playerId = isset($_GET['player']) ? (int) $_GET['player'] : 0;

/* ------------------------- Thu hồi vật phẩm ------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'recall') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $errors[] = 'Phiên làm việc không hợp lệ, hãy tải lại trang.';
    }
    $playerId = (int) ($_POST['player_id'] ?? 0);
    $container = (string) ($_POST['container'] ?? '');
    $slot = (int) ($_POST['slot'] ?? -1);
    $reason = trim((string) ($_POST['reason'] ?? ''));

    if (!isset(CONTAINERS[$container])) {
        $errors[] = 'Loại túi đồ không hợp lệ.';
    }
    if (!$errors) {
        $stmt = $mysqli->prepare('SELECT p.id, p.name, p.`' . $container . '` AS data, a.last_time_login, a.last_time_logout
                                  FROM player p LEFT JOIN account a ON a.id = p.account_id WHERE p.id = ? LIMIT 1');
        $stmt->bind_param('i', $playerId);
        $stmt->execute();
        $target = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$target) {
            $errors[] = 'Không tìm thấy nhân vật.';
        } elseif (isAccountOnline($target)) {
            $errors[] = 'Nhân vật đang online. Server sẽ ghi đè dữ liệu khi lưu, hãy yêu cầu thoát game rồi thu hồi lại.';
        } else {
            $slots = json_decode((string) $target['data'], true);
            $items = parseContainer($target['data']);
            if (!is_array($slots) || !isset($slots[$slot]) || empty($items[$slot])) {
                $errors[] = 'Ô đồ này đang trống hoặc không tồn tại.';
            } else {
                $item = $items[$slot];
                $slots[$slot] = emptySlotJson($item['create_time']);
                $newData = json_encode($slots, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                $stmt = $mysqli->prepare('UPDATE player SET `' . $container . '` = ? WHERE id = ?');
                $stmt->bind_param('si', $newData, $playerId);
                $ok = $stmt->execute();
                $stmt->close();

                if (!$ok) {
                    $errors[] = 'Không cập nhật được dữ liệu nhân vật.';
                } else {
                    $itemName = $itemNames[$item['template_id']] ?? ('#' . $item['template_id']);
                    $stmt = $mysqli->prepare('INSERT INTO admin_item_log
                        (admin_username, player_id, player_name, container, slot, item_id, item_name, quantity, reason)
                        VALUES (?,?,?,?,?,?,?,?,?)');
                    $stmt->bind_param('sissiisis', $_SESSION['username'], $playerId, $target['name'], $container,
                        $slot, $item['template_id'], $itemName, $item['quantity'], $reason);
                    $stmt->execute();
                    $stmt->close();
                    $success = 'Đã thu hồi "' . $itemName . '" (x' . $item['quantity'] . ') của ' . $target['name'] . '.';
                }
            }
        }
    }
}

/* ------------------------- Dữ liệu hiển thị ------------------------- */
$search = trim((string) ($_GET['q'] ?? ''));
$players = [];
$sql = 'SELECT p.id, p.name, p.gender, p.data_point, a.username, a.ban, a.last_time_login, a.last_time_logout
        FROM player p LEFT JOIN account a ON a.id = p.account_id';
if ($search !== '') {
    $sql .= ' WHERE p.name LIKE ? OR a.username LIKE ?';
}
$sql .= ' ORDER BY p.id ASC LIMIT 200';
$stmt = $mysqli->prepare($sql);
if ($search !== '') {
    $like = '%' . $search . '%';
    $stmt->bind_param('ss', $like, $like);
}
$stmt->execute();
$rs = $stmt->get_result();
while ($row = $rs->fetch_assoc()) {
    $point = json_decode((string) $row['data_point'], true);
    $row['power'] = is_array($point) && isset($point[1]) ? (int) $point[1] : 0;
    $row['online'] = isAccountOnline($row);
    $players[] = $row;
}
$stmt->close();

$detail = null;
if ($playerId > 0) {
    $columns = 'p.*, a.username, a.ban, a.is_admin, a.vnd, a.tongnap, a.vip, a.last_time_login, a.last_time_logout';
    $stmt = $mysqli->prepare('SELECT ' . $columns . ' FROM player p LEFT JOIN account a ON a.id = p.account_id WHERE p.id = ? LIMIT 1');
    $stmt->bind_param('i', $playerId);
    $stmt->execute();
    $detail = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

$recallLogs = [];
$logSql = 'SELECT * FROM admin_item_log' . ($playerId > 0 ? ' WHERE player_id = ' . $playerId : '') . ' ORDER BY id DESC LIMIT 20';
$rs = $mysqli->query($logSql);
while ($rs && $row = $rs->fetch_assoc()) {
    $recallLogs[] = $row;
}

function renderOption(array $option, array $optionNames): string {
    $name = $optionNames[$option[0]] ?? ('Option #' . $option[0]);
    return htmlspecialchars(str_replace('#', (string) $option[1], $name));
}
?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Quản trị | Ngọc Rồng</title>
    <link rel="stylesheet" href="assets/css/site.css">
</head>
<body>
<header class="site-header"><div class="shell nav">
    <a class="brand" href="index.php"><span class="brand-mark">★</span>Ngọc Rồng</a>
    <nav class="nav-links">
        <a href="index.php">Bảng xếp hạng</a>
        <a href="admin.php">Quản trị</a>
        <a href="logout.php">Đăng xuất</a>
    </nav>
</div></header>
<main><div class="shell">
    <div class="section-heading">
        <div><div class="kicker">Bảng điều khiển</div><h2>Quản lý nhân vật</h2></div>
        <p>Xem chỉ số, trang bị và thu hồi vật phẩm</p>
    </div>

    <?php if ($errors): ?><div class="alert error"><?php foreach ($errors as $error): ?><?= htmlspecialchars($error) ?><br><?php endforeach; ?></div><?php endif; ?>
    <?php if ($success): ?><div class="notice"><?= htmlspecialchars($success) ?></div><?php endif; ?>

    <section class="panel admin-block">
        <div class="panel-head"><h3>Danh sách nhân vật</h3><small>Tối đa 200 kết quả</small></div>
        <form class="admin-search" method="get">
            <input name="q" placeholder="Tìm theo tên nhân vật hoặc tài khoản" value="<?= htmlspecialchars($search) ?>">
            <button class="btn" type="submit">Tìm</button>
        </form>
        <table class="admin-table">
            <thead><tr><th>ID</th><th>Nhân vật</th><th>Tài khoản</th><th>Sức mạnh</th><th>Trạng thái</th><th></th></tr></thead>
            <tbody>
            <?php if (!$players): ?><tr><td colspan="6" class="empty">Không có nhân vật nào.</td></tr><?php endif; ?>
            <?php foreach ($players as $row): ?>
                <tr>
                    <td><?= (int) $row['id'] ?></td>
                    <td><strong><?= htmlspecialchars((string) $row['name']) ?></strong></td>
                    <td><?= htmlspecialchars((string) ($row['username'] ?? '—')) ?></td>
                    <td><?= formatNumber($row['power']) ?></td>
                    <td>
                        <span class="tag <?= $row['online'] ? 'on' : 'off' ?>"><?= $row['online'] ? 'Online' : 'Offline' ?></span>
                        <?php if ((int) ($row['ban'] ?? 0) === 1): ?><span class="tag ban">Bị khóa</span><?php endif; ?>
                    </td>
                    <td><a class="btn secondary" href="admin.php?player=<?= (int) $row['id'] ?><?= $search !== '' ? '&q=' . urlencode($search) : '' ?>">Xem</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <?php if ($detail): ?>
        <?php
        $point = json_decode((string) $detail['data_point'], true) ?: [];
        $inventory = json_decode((string) $detail['data_inventory'], true) ?: [];
        $online = isAccountOnline($detail);
        ?>
        <section class="panel admin-block">
            <div class="panel-head">
                <h3><?= htmlspecialchars((string) $detail['name']) ?></h3>
                <small>Tài khoản: <?= htmlspecialchars((string) ($detail['username'] ?? '—')) ?> · <?= $online ? 'Đang online' : 'Offline' ?></small>
            </div>
            <?php if ($online): ?>
                <div class="alert error">Nhân vật đang online — không thể thu hồi vật phẩm vì server sẽ ghi đè dữ liệu khi lưu.</div>
            <?php endif; ?>
            <div class="stat-grid">
                <?php foreach (POINT_LABELS as $index => $label): ?>
                    <div class="stat"><span><?= htmlspecialchars($label) ?></span><strong><?= formatNumber($point[$index] ?? 0) ?></strong></div>
                <?php endforeach; ?>
                <div class="stat"><span>Vàng</span><strong><?= formatNumber($inventory[0] ?? 0) ?></strong></div>
                <div class="stat"><span>Ngọc</span><strong><?= formatNumber($inventory[1] ?? 0) ?></strong></div>
                <div class="stat"><span>Hồng ngọc</span><strong><?= formatNumber($inventory[2] ?? 0) ?></strong></div>
                <div class="stat"><span>Vip</span><strong><?= formatNumber($detail['vip'] ?? 0) ?></strong></div>
                <div class="stat"><span>Tổng nạp</span><strong><?= formatNumber($detail['tongnap'] ?? 0) ?></strong></div>
            </div>
        </section>

        <?php foreach (CONTAINERS as $column => $label): ?>
            <?php $items = parseContainer($detail[$column] ?? null); ?>
            <section class="panel admin-block">
                <div class="panel-head"><h3><?= htmlspecialchars($label) ?></h3><small><?= count(array_filter($items)) ?> vật phẩm</small></div>
                <table class="admin-table">
                    <thead><tr><th>Ô</th><th>Vật phẩm</th><th>SL</th><th>Chỉ số</th><th></th></tr></thead>
                    <tbody>
                    <?php $hasItem = false; ?>
                    <?php foreach ($items as $slot => $item): ?>
                        <?php if (!$item) { continue; } $hasItem = true; ?>
                        <tr>
                            <td><?= (int) $slot ?></td>
                            <td>
                                <strong><?= htmlspecialchars($itemNames[$item['template_id']] ?? ('#' . $item['template_id'])) ?></strong>
                                <div class="rank-meta">ID <?= (int) $item['template_id'] ?></div>
                            </td>
                            <td><?= (int) $item['quantity'] ?></td>
                            <td class="opt-cell">
                                <?php if (!$item['options']): ?><span class="rank-meta">Không có</span><?php endif; ?>
                                <?php foreach ($item['options'] as $option): ?><div><?= renderOption($option, $optionNames) ?></div><?php endforeach; ?>
                            </td>
                            <td>
                                <form method="post" onsubmit="return confirm('Thu hồi vật phẩm này?');">
                                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                                    <input type="hidden" name="action" value="recall">
                                    <input type="hidden" name="player_id" value="<?= (int) $detail['id'] ?>">
                                    <input type="hidden" name="container" value="<?= htmlspecialchars($column) ?>">
                                    <input type="hidden" name="slot" value="<?= (int) $slot ?>">
                                    <input name="reason" placeholder="Lý do" maxlength="255">
                                    <button class="btn danger" type="submit" <?= $online ? 'disabled' : '' ?>>Thu hồi</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$hasItem): ?><tr><td colspan="5" class="empty">Trống.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>

    <section class="panel admin-block">
        <div class="panel-head"><h3>Lịch sử thu hồi</h3><small>20 hoạt động gần nhất</small></div>
        <table class="admin-table">
            <thead><tr><th>Thời gian</th><th>Admin</th><th>Nhân vật</th><th>Vật phẩm</th><th>Túi</th><th>Lý do</th></tr></thead>
            <tbody>
            <?php if (!$recallLogs): ?><tr><td colspan="6" class="empty">Chưa có hoạt động nào.</td></tr><?php endif; ?>
            <?php foreach ($recallLogs as $log): ?>
                <tr>
                    <td><?= htmlspecialchars((string) $log['created_at']) ?></td>
                    <td><?= htmlspecialchars((string) $log['admin_username']) ?></td>
                    <td><?= htmlspecialchars((string) $log['player_name']) ?></td>
                    <td><?= htmlspecialchars((string) $log['item_name']) ?> x<?= (int) $log['quantity'] ?></td>
                    <td><?= htmlspecialchars(CONTAINERS[$log['container']] ?? $log['container']) ?></td>
                    <td><?= htmlspecialchars((string) ($log['reason'] ?? '')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
</div></main>
<footer><div class="shell">Ngọc Rồng Online · Khu vực quản trị.</div></footer>
</body></html>
