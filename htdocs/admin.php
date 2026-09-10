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
    action VARCHAR(20) NOT NULL DEFAULT "recall",
    player_id INT NOT NULL,
    player_name VARCHAR(255) NOT NULL,
    container VARCHAR(50) NOT NULL,
    slot INT NOT NULL,
    item_id INT NOT NULL,
    item_name VARCHAR(255) NOT NULL,
    quantity INT NOT NULL,
    options VARCHAR(255) NOT NULL DEFAULT "",
    reason VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

$mysqli->query('CREATE TABLE IF NOT EXISTS admin_command (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    type VARCHAR(50) NOT NULL,
    player_id INT NOT NULL,
    container VARCHAR(50) NOT NULL,
    slot INT NOT NULL,
    item_id INT NOT NULL,
    quantity INT NOT NULL DEFAULT 0,
    options VARCHAR(255) NOT NULL DEFAULT "",
    status VARCHAR(20) NOT NULL DEFAULT "pending",
    message VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_status (type, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

// Bổ sung cột cho các bảng đã tạo từ bản trước
foreach ([
    ['admin_command', 'quantity', 'ADD COLUMN quantity INT NOT NULL DEFAULT 0 AFTER item_id'],
    ['admin_command', 'options', 'ADD COLUMN options VARCHAR(255) NOT NULL DEFAULT "" AFTER quantity'],
    ['admin_item_log', 'action', 'ADD COLUMN action VARCHAR(20) NOT NULL DEFAULT "recall" AFTER admin_username'],
    ['admin_item_log', 'options', 'ADD COLUMN options VARCHAR(255) NOT NULL DEFAULT "" AFTER quantity'],
] as [$table, $column, $alter]) {
    $rs = $mysqli->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    if ($rs && $rs->num_rows === 0) {
        $mysqli->query("ALTER TABLE `$table` $alter");
    }
}

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

const PET_TYPES = [
    0 => 'Đệ tử thường',
    1 => 'Mabư',
    2 => 'Beerus',
    3 => 'Black Goku',
    4 => 'Black Goku Rose',
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

/** Giữ nguyên option và thời gian tạo của ô đồ, chỉ đổi số lượng còn lại */
function slotJsonWithQuantity($rawSlot, int $newQuantity): string {
    $data = is_string($rawSlot) ? json_decode($rawSlot, true) : $rawSlot;
    $data[1] = $newQuantity;
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/** Chuỗi "21:80, 47:2000" -> [[21,80],[47,2000]] */
function parseOptionInput(string $input): array {
    $options = [];
    foreach (preg_split('/[,;\n]+/', $input, -1, PREG_SPLIT_NO_EMPTY) as $part) {
        $pair = explode(':', trim($part));
        if (count($pair) !== 2 || !is_numeric($pair[0]) || !is_numeric($pair[1])) {
            return [];
        }
        $options[] = [(int) $pair[0], (int) $pair[1]];
    }
    return $options;
}

function buildSlotJson(int $templateId, int $quantity, array $options): string {
    $encoded = [];
    foreach ($options as $option) {
        $encoded[] = json_encode([$option[0], $option[1]]);
    }
    return json_encode([
        $templateId,
        $quantity,
        json_encode($encoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        (int) round(microtime(true) * 1000),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function writeLog(mysqli $mysqli, string $action, int $playerId, string $playerName, string $container,
                  int $slot, int $itemId, string $itemName, int $quantity, string $options, string $reason): void {
    $stmt = $mysqli->prepare('INSERT INTO admin_item_log
        (admin_username, action, player_id, player_name, container, slot, item_id, item_name, quantity, options, reason)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    $stmt->bind_param('ssissiisiss', $_SESSION['username'], $action, $playerId, $playerName, $container,
        $slot, $itemId, $itemName, $quantity, $options, $reason);
    $stmt->execute();
    $stmt->close();
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
    $quantity = (int) ($_POST['quantity'] ?? 0);
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
        } else {
            $slots = json_decode((string) $target['data'], true);
            $items = parseContainer($target['data']);
            if (!is_array($slots) || !isset($slots[$slot]) || empty($items[$slot])) {
                $errors[] = 'Ô đồ này đang trống hoặc không tồn tại.';
            } else {
                $item = $items[$slot];
                $online = isAccountOnline($target);
                $ok = false;

                if ($quantity < 1 || $quantity > $item['quantity']) {
                    $errors[] = 'Số lượng thu hồi phải từ 1 đến ' . $item['quantity'] . '.';
                } elseif ($online) {
                    // Người chơi đang online: gửi lệnh cho server tự xoá trong bộ nhớ,
                    // nếu sửa thẳng database thì server sẽ ghi đè khi lưu.
                    $stmt = $mysqli->prepare('INSERT INTO admin_command (type, player_id, container, slot, item_id, quantity)
                                              VALUES ("recall_item", ?, ?, ?, ?, ?)');
                    $stmt->bind_param('isiii', $playerId, $container, $slot, $item['template_id'], $quantity);
                    $ok = $stmt->execute();
                    $stmt->close();
                } else {
                    $slots[$slot] = $quantity >= $item['quantity']
                        ? emptySlotJson($item['create_time'])
                        : slotJsonWithQuantity($slots[$slot], $item['quantity'] - $quantity);
                    $newData = json_encode($slots, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                    $stmt = $mysqli->prepare('UPDATE player SET `' . $container . '` = ? WHERE id = ?');
                    $stmt->bind_param('si', $newData, $playerId);
                    $ok = $stmt->execute();
                    $stmt->close();
                }

                if ($errors) {
                    // đã báo lỗi số lượng ở trên
                } elseif (!$ok) {
                    $errors[] = 'Không thực hiện được thao tác thu hồi.';
                } else {
                    $itemName = $itemNames[$item['template_id']] ?? ('#' . $item['template_id']);
                    writeLog($mysqli, 'recall', $playerId, (string) $target['name'], $container, $slot,
                        (int) $item['template_id'], $itemName, $quantity, '', $reason);
                    $success = $online
                        ? 'Đã gửi lệnh thu hồi "' . $itemName . '" (x' . $quantity . ') của ' . $target['name'] . '. Người chơi đang online nên server sẽ áp dụng trong vài giây, xem kết quả ở mục "Lệnh gửi server".'
                        : 'Đã thu hồi "' . $itemName . '" (x' . $quantity . ') của ' . $target['name'] . '.';
                }
            }
        }
    }
}

/* --------------------------- Cấp vật phẩm --------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'give') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $errors[] = 'Phiên làm việc không hợp lệ, hãy tải lại trang.';
    }
    $playerId = (int) ($_POST['player_id'] ?? 0);
    $container = (string) ($_POST['container'] ?? 'items_bag');
    $templateId = (int) ($_POST['item_id'] ?? -1);
    $quantity = (int) ($_POST['quantity'] ?? 1);
    $optionInput = trim((string) ($_POST['options'] ?? ''));
    $reason = trim((string) ($_POST['reason'] ?? ''));

    if (!in_array($container, ['items_bag', 'items_box'], true)) {
        $errors[] = 'Chỉ cấp được vào hành trang hoặc rương đồ.';
    }
    if (!isset($itemNames[$templateId])) {
        $errors[] = 'Vật phẩm không tồn tại.';
    }
    if ($quantity < 1 || $quantity > 1000000) {
        $errors[] = 'Số lượng phải từ 1 đến 1.000.000.';
    }
    $options = $optionInput === '' ? [] : parseOptionInput($optionInput);
    if ($optionInput !== '' && !$options) {
        $errors[] = 'Chỉ số nhập sai định dạng, đúng phải là "mã:giá trị", ví dụ 21:80, 47:2000.';
    }
    foreach ($options as $option) {
        if (!isset($optionNames[$option[0]])) {
            $errors[] = 'Không có chỉ số mã ' . $option[0] . '.';
        }
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
        } else {
            $online = isAccountOnline($target);
            $optionText = implode(',', array_map(fn($o) => $o[0] . ':' . $o[1], $options));
            $ok = false;

            if ($online) {
                $stmt = $mysqli->prepare('INSERT INTO admin_command (type, player_id, container, slot, item_id, quantity, options)
                                          VALUES ("give_item", ?, ?, -1, ?, ?, ?)');
                $stmt->bind_param('isiis', $playerId, $container, $templateId, $quantity, $optionText);
                $ok = $stmt->execute();
                $stmt->close();
            } else {
                $slots = json_decode((string) $target['data'], true);
                $items = parseContainer($target['data']);
                $freeSlot = null;
                foreach ($items as $index => $existing) {
                    if (!$existing) {
                        $freeSlot = $index;
                        break;
                    }
                }
                if (!is_array($slots) || $freeSlot === null) {
                    $errors[] = 'Không còn ô trống trong ' . strtolower(CONTAINERS[$container]) . ' của nhân vật.';
                } else {
                    $slots[$freeSlot] = buildSlotJson($templateId, $quantity, $options);
                    $newData = json_encode($slots, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $stmt = $mysqli->prepare('UPDATE player SET `' . $container . '` = ? WHERE id = ?');
                    $stmt->bind_param('si', $newData, $playerId);
                    $ok = $stmt->execute();
                    $stmt->close();
                }
            }

            if (!$errors && !$ok) {
                $errors[] = 'Không thực hiện được thao tác cấp đồ.';
            } elseif (!$errors) {
                $itemName = $itemNames[$templateId];
                writeLog($mysqli, 'give', $playerId, (string) $target['name'], $container, -1,
                    $templateId, $itemName, $quantity, $optionText, $reason);
                $success = $online
                    ? 'Đã gửi lệnh cấp "' . $itemName . '" (x' . $quantity . ') cho ' . $target['name'] . '. Server sẽ áp dụng trong vài giây.'
                    : 'Đã cấp "' . $itemName . '" (x' . $quantity . ') cho ' . $target['name'] . '.';
            }
        }
    }
}

/* ---------------------------- Cấp đệ tử ---------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'give_pet') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $errors[] = 'Phiên làm việc không hợp lệ, hãy tải lại trang.';
    }
    $playerId = (int) ($_POST['player_id'] ?? 0);
    $petType = (int) ($_POST['pet_type'] ?? -1);
    $gender = (int) ($_POST['pet_gender'] ?? -1);
    $replace = isset($_POST['replace_pet']);
    $reason = trim((string) ($_POST['reason'] ?? ''));
    if (!isset(PET_TYPES[$petType])) {
        $errors[] = 'Loại đệ tử không hợp lệ.';
    }
    if ($gender < 0 || $gender > 2) {
        $errors[] = 'Hành tinh đệ tử không hợp lệ.';
    }

    if (!$errors) {
        $stmt = $mysqli->prepare('SELECT id, name, pet FROM player WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $playerId);
        $stmt->execute();
        $target = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$target) {
            $errors[] = 'Không tìm thấy nhân vật.';
        } elseif (!$replace && !empty(json_decode((string) $target['pet'], true))) {
            $errors[] = 'Nhân vật đã có đệ tử. Hãy chọn xác nhận thay thế nếu muốn cấp đệ tử mới.';
        } else {
            $stmt = $mysqli->prepare("SELECT id FROM admin_command
                WHERE type = 'give_pet' AND player_id = ? AND status = 'pending' LIMIT 1");
            $stmt->bind_param('i', $playerId);
            $stmt->execute();
            $pending = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($pending) {
                $errors[] = 'Nhân vật đã có một lệnh cấp đệ tử đang chờ xử lý.';
            } else {
                $options = $replace ? 'replace' : '';
                $stmt = $mysqli->prepare('INSERT INTO admin_command
                    (type, player_id, container, slot, item_id, quantity, options)
                    VALUES ("give_pet", ?, "pet", -1, ?, ?, ?)');
                $stmt->bind_param('iiis', $playerId, $petType, $gender, $options);
                $ok = $stmt->execute();
                $stmt->close();

                if (!$ok) {
                    $errors[] = 'Không gửi được lệnh cấp đệ tử.';
                } else {
                    writeLog($mysqli, 'give_pet', $playerId, (string) $target['name'], 'pet', -1,
                        $petType, PET_TYPES[$petType], 1, 'Hành tinh ' . $gender, $reason);
                    $success = 'Đã gửi lệnh cấp ' . PET_TYPES[$petType] . ' cho ' . $target['name']
                        . '. Nếu nhân vật offline, lệnh sẽ tự áp dụng khi đăng nhập.';
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

$commands = [];
$cmdSql = 'SELECT c.*, p.name AS player_name FROM admin_command c LEFT JOIN player p ON p.id = c.player_id'
    . ($playerId > 0 ? ' WHERE c.player_id = ' . $playerId : '') . ' ORDER BY c.id DESC LIMIT 20';
$rs = $mysqli->query($cmdSql);
while ($rs && $row = $rs->fetch_assoc()) {
    $commands[] = $row;
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
                <div class="notice">Nhân vật đang online — dữ liệu hiển thị lấy từ lần lưu gần nhất của server nên có thể trễ vài phút. Lệnh thu hồi sẽ được gửi xuống server và áp dụng trực tiếp trong game sau vài giây.</div>
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

        <section class="panel admin-block">
            <div class="panel-head"><h3>Cấp đệ tử</h3><small>Áp dụng ngay hoặc khi đăng nhập</small></div>
            <form class="give-form" method="post" onsubmit="return confirm('Xác nhận cấp đệ tử cho nhân vật này?');">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                <input type="hidden" name="action" value="give_pet">
                <input type="hidden" name="player_id" value="<?= (int) $detail['id'] ?>">
                <div class="field">
                    <label for="pet-type">Loại đệ tử</label>
                    <select id="pet-type" name="pet_type">
                        <option value="0">Đệ tử thường</option>
                        <option value="1">Mabư</option>
                        <option value="2">Beerus</option>
                        <option value="3">Black Goku</option>
                        <option value="4">Black Goku Rose</option>
                    </select>
                </div>
                <div class="field">
                    <label for="pet-gender">Hành tinh</label>
                    <select id="pet-gender" name="pet_gender">
                        <option value="0">Trái Đất</option>
                        <option value="1">Namếc</option>
                        <option value="2">Xayda</option>
                    </select>
                </div>
                <div class="field">
                    <label for="pet-reason">Lý do</label>
                    <input id="pet-reason" name="reason" maxlength="255">
                </div>
                <label class="check-field">
                    <input type="checkbox" name="replace_pet" value="1">
                    Thay thế đệ tử hiện có (đệ tử cũ và trang bị đang mặc sẽ mất)
                </label>
                <button class="btn" type="submit">Cấp đệ tử</button>
            </form>
        </section>

        <section class="panel admin-block">
            <div class="panel-head"><h3>Cấp vật phẩm</h3><small>Vào hành trang hoặc rương đồ</small></div>
            <form class="give-form" method="post">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                <input type="hidden" name="action" value="give">
                <input type="hidden" name="player_id" value="<?= (int) $detail['id'] ?>">
                <div class="field">
                    <label for="give-item">Vật phẩm</label>
                    <input id="give-item" name="item_id" list="item-list" required placeholder="Nhập ID vật phẩm">
                    <datalist id="item-list">
                        <?php foreach ($itemNames as $id => $name): ?><option value="<?= (int) $id ?>"><?= htmlspecialchars($name) ?></option><?php endforeach; ?>
                    </datalist>
                </div>
                <div class="field">
                    <label for="give-quantity">Số lượng</label>
                    <input id="give-quantity" type="number" name="quantity" min="1" max="1000000" value="1" required>
                </div>
                <div class="field">
                    <label for="give-container">Nơi nhận</label>
                    <select id="give-container" name="container">
                        <option value="items_bag">Hành trang</option>
                        <option value="items_box">Rương đồ</option>
                    </select>
                </div>
                <div class="field">
                    <label for="give-options">Chỉ số (tùy chọn)</label>
                    <input id="give-options" name="options" placeholder="VD: 21:80, 47:2000">
                </div>
                <div class="field">
                    <label for="give-reason">Lý do</label>
                    <input id="give-reason" name="reason" maxlength="255">
                </div>
                <button class="btn" type="submit">Cấp vật phẩm</button>
            </form>
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
                                    <input class="qty" type="number" name="quantity" min="1" max="<?= (int) $item['quantity'] ?>" value="<?= (int) $item['quantity'] ?>" required title="Số lượng thu hồi">
                                    <input name="reason" placeholder="Lý do" maxlength="255">
                                    <button class="btn danger" type="submit">Thu hồi</button>
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
        <div class="panel-head"><h3>Lệnh gửi server</h3><small>Thu hồi khi người chơi đang online · tự làm mới mỗi 10s</small></div>
        <table class="admin-table">
            <thead><tr><th>Thời gian</th><th>Hành động</th><th>Nhân vật</th><th>Vật phẩm</th><th>Túi</th><th>Ô</th><th>Trạng thái</th></tr></thead>
            <tbody>
            <?php if (!$commands): ?><tr><td colspan="7" class="empty">Chưa có lệnh nào.</td></tr><?php endif; ?>
            <?php foreach ($commands as $cmd): ?>
                <?php
                $status = (string) $cmd['status'];
                $statusLabel = ['pending' => 'Đang chờ server', 'done' => 'Đã thu hồi', 'offline' => 'Người chơi đã offline', 'failed' => 'Thất bại'][$status] ?? $status;
                $statusClass = $status === 'done' ? 'on' : ($status === 'pending' ? 'off' : 'ban');
                ?>
                <tr>
                    <td><?= htmlspecialchars((string) $cmd['created_at']) ?></td>
                    <td><?= $cmd['type'] === 'give_pet' ? 'Cấp đệ tử' : ($cmd['type'] === 'give_item' ? 'Cấp đồ' : 'Thu hồi') ?></td>
                    <td><?= htmlspecialchars((string) ($cmd['player_name'] ?? ('#' . $cmd['player_id']))) ?></td>
                    <td>
                        <?php if ($cmd['type'] === 'give_pet'): ?>
                            <?= htmlspecialchars(PET_TYPES[(int) $cmd['item_id']] ?? ('Loại #' . $cmd['item_id'])) ?>
                        <?php else: ?>
                            <?= htmlspecialchars($itemNames[(int) $cmd['item_id']] ?? ('#' . $cmd['item_id'])) ?> x<?= (int) $cmd['quantity'] ?>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars(CONTAINERS[$cmd['container']] ?? $cmd['container']) ?></td>
                    <td><?= $cmd['slot'] < 0 ? '—' : (int) $cmd['slot'] ?></td>
                    <td>
                        <span class="tag <?= $statusClass ?>"><?= htmlspecialchars($statusLabel) ?></span>
                        <?php if (!empty($cmd['message'])): ?><div class="rank-meta"><?= htmlspecialchars((string) $cmd['message']) ?></div><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <section class="panel admin-block">
        <div class="panel-head"><h3>Lịch sử thao tác</h3><small>20 hoạt động gần nhất</small></div>
        <table class="admin-table">
            <thead><tr><th>Thời gian</th><th>Admin</th><th>Hành động</th><th>Nhân vật</th><th>Vật phẩm</th><th>Túi</th><th>Lý do</th></tr></thead>
            <tbody>
            <?php if (!$recallLogs): ?><tr><td colspan="7" class="empty">Chưa có hoạt động nào.</td></tr><?php endif; ?>
            <?php foreach ($recallLogs as $log): ?>
                <tr>
                    <td><?= htmlspecialchars((string) $log['created_at']) ?></td>
                    <td><?= htmlspecialchars((string) $log['admin_username']) ?></td>
                    <td><?= ($log['action'] ?? 'recall') === 'give_pet' ? 'Cấp đệ tử' : (($log['action'] ?? 'recall') === 'give' ? 'Cấp đồ' : 'Thu hồi') ?></td>
                    <td><?= htmlspecialchars((string) $log['player_name']) ?></td>
                    <td>
                        <?= htmlspecialchars((string) $log['item_name']) ?> x<?= (int) $log['quantity'] ?>
                        <?php if (!empty($log['options'])): ?><div class="rank-meta"><?= htmlspecialchars((string) $log['options']) ?></div><?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars(CONTAINERS[$log['container']] ?? $log['container']) ?></td>
                    <td><?= htmlspecialchars((string) ($log['reason'] ?? '')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>
</div></main>
<footer><div class="shell">Ngọc Rồng Online · Khu vực quản trị.</div></footer>
<?php $hasPending = (bool) array_filter($commands, fn($cmd) => $cmd['status'] === 'pending'); ?>
<?php if ($hasPending): ?><script>setTimeout(() => location.replace(location.href.split('#')[0]), 10000);</script><?php endif; ?>
</body></html>
