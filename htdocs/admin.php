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

/* ------------------------- Khóa / Mở Khóa Tài Khoản (Ban / Unban) ------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_ban') {
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $errors[] = 'Phiên làm việc không hợp lệ, hãy tải lại trang.';
    }
    $targetAccountId = (int) ($_POST['account_id'] ?? 0);
    $banStatus = (int) ($_POST['ban_status'] ?? 0); // 1: Ban, 0: Unban
    $reason = trim((string) ($_POST['reason'] ?? ''));

    if ($targetAccountId <= 0) {
        $errors[] = 'Tài khoản không hợp lệ.';
    } elseif ($targetAccountId === (int) $_SESSION['user_id']) {
        $errors[] = 'Không thể tự khóa tài khoản của chính mình!';
    }

    if (!$errors) {
        $stmt = $mysqli->prepare('SELECT id, username, is_admin, ban FROM account WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $targetAccountId);
        $stmt->execute();
        $targetAcc = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$targetAcc) {
            $errors[] = 'Không tìm thấy tài khoản cần thao tác.';
        } elseif ((int) $targetAcc['is_admin'] === 1 && $banStatus === 1) {
            $errors[] = 'Không thể khóa tài khoản có quyền Quản Trị Viên (Admin)!';
        } else {
            $stmt = $mysqli->prepare('UPDATE account SET ban = ? WHERE id = ?');
            $stmt->bind_param('ii', $banStatus, $targetAccountId);
            if ($stmt->execute()) {
                $actLabel = $banStatus === 1 ? 'Khóa tài khoản (Ban)' : 'Mở khóa tài khoản (Unban)';
                writeLog($mysqli, $banStatus === 1 ? 'ban_account' : 'unban_account', 0, $targetAcc['username'], 'account', -1,
                    $targetAccountId, 'Tài khoản: ' . $targetAcc['username'], 1, $actLabel, $reason);
                $success = ($banStatus === 1 ? 'Đã khóa thành công' : 'Đã mở khóa thành công') . ' tài khoản: ' . htmlspecialchars($targetAcc['username']);
            } else {
                $errors[] = 'Có lỗi xảy ra khi cập nhật trạng thái tài khoản.';
            }
            $stmt->close();
        }
    }
}

$currentTab = $_GET['tab'] ?? ($playerId > 0 ? 'inventory' : 'players');
$currentBag = $_GET['bag'] ?? 'overview';
if ($currentBag !== 'overview' && !isset(CONTAINERS[$currentBag])) {
    $currentBag = 'overview';
}
$search = trim((string) ($_GET['q'] ?? ''));
$players = [];
$sql = 'SELECT p.id, p.account_id, p.name, p.gender, p.data_point, a.id AS acc_id, a.username, a.ban, a.is_admin, a.last_time_login, a.last_time_logout
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
    . ($playerId > 0 ? ' WHERE c.player_id = ' . $playerId : '') . ' ORDER BY c.id DESC LIMIT 50';
$rs = $mysqli->query($cmdSql);
while ($rs && $row = $rs->fetch_assoc()) {
    $commands[] = $row;
}

$recallLogs = [];
$logSql = 'SELECT * FROM admin_item_log' . ($playerId > 0 ? ' WHERE player_id = ' . $playerId : '') . ' ORDER BY id DESC LIMIT 50';
$rs = $mysqli->query($logSql);
while ($rs && $row = $rs->fetch_assoc()) {
    $recallLogs[] = $row;
}

$pendingCount = count(array_filter($commands, fn($c) => $c['status'] === 'pending'));

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
    <title>Quản Trị Hệ Thống | NhimsNRO</title>
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
            <a href="index.php">Trang Chủ</a>
            <a class="active" href="admin.php">Quản Trị Viên</a>
            <a href="logout.php">Đăng Xuất (<?= htmlspecialchars($_SESSION['username']) ?>)</a>
        </nav>
    </div>
</header>
<main>
    <div class="shell">
        <div class="main-box-nro">
            <div class="admin-header-bar">
                <div class="admin-title-wrap">
                    <h2>Bảng Điều Khiển Quản Trị Máy Chủ</h2>
                    <p>Quản lý tài khoản người chơi, chỉ số sức mạnh, túi đồ trang bị, cấp phát vật phẩm & lệnh máy chủ.</p>
                </div>
                <?php if ($detail): ?>
                    <div style="text-align: right;">
                        <span style="color: #4a2818; font-size: 11px;">Đang chọn nhân vật:</span>
                        <div style="font-size: 15px; font-weight: 900; color: #a82400;">
                            ⚡ <?= htmlspecialchars((string) $detail['name']) ?> (ID: #<?= (int) $detail['id'] ?>)
                        </div>
                    </div>
                <?php endif; ?>
            </div>

        <?php if ($errors): ?>
            <div class="alert error">
                <?php foreach ($errors as $error): ?><?= htmlspecialchars($error) ?><br><?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="notice">
                <span>✨</span> <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>

        <!-- HỆ THỐNG MENU TABS ĐIỀU HƯỚNG -->
        <nav class="admin-tabs-nav">
            <a class="tab-link <?= $currentTab === 'players' ? 'active' : '' ?>" href="admin.php?tab=players<?= $playerId > 0 ? '&player=' . $playerId : '' ?><?= $search !== '' ? '&q=' . urlencode($search) : '' ?>">
                <span class="tab-icon">👥</span> Quản Lý Nhân Vật
            </a>
            <a class="tab-link <?= $currentTab === 'inventory' ? 'active' : '' ?>" href="admin.php?tab=inventory<?= $playerId > 0 ? '&player=' . $playerId : '' ?>">
                <span class="tab-icon">🎒</span> Túi Đồ & Thu Hồi
                <?php if ($detail): ?><span class="tab-badge"><?= htmlspecialchars((string) $detail['name']) ?></span><?php endif; ?>
            </a>
            <a class="tab-link <?= $currentTab === 'give' ? 'active' : '' ?>" href="admin.php?tab=give<?= $playerId > 0 ? '&player=' . $playerId : '' ?>">
                <span class="tab-icon">🎁</span> Cấp Đồ & Đệ Tử
                <?php if ($detail): ?><span class="tab-badge"><?= htmlspecialchars((string) $detail['name']) ?></span><?php endif; ?>
            </a>
            <a class="tab-link <?= $currentTab === 'commands' ? 'active' : '' ?>" href="admin.php?tab=commands<?= $playerId > 0 ? '&player=' . $playerId : '' ?>">
                <span class="tab-icon">⚡</span> Hàng Đợi Server
                <?php if ($pendingCount > 0): ?><span class="tab-badge" style="background: var(--db-orange); color: #fff;"><?= $pendingCount ?> chờ</span><?php endif; ?>
            </a>
            <a class="tab-link <?= $currentTab === 'logs' ? 'active' : '' ?>" href="admin.php?tab=logs<?= $playerId > 0 ? '&player=' . $playerId : '' ?>">
                <span class="tab-icon">📜</span> Lịch Sử Thao Tác
            </a>
        </nav>

        <!-- ====================================================================
             TAB 1: QUẢN LÝ NHÂN VẬT & TÀI KHOẢN (PLAYERS)
             ==================================================================== -->
        <?php if ($currentTab === 'players'): ?>
            <section class="panel admin-block">
                <div class="panel-head">
                    <h3>👥 DANH SÁCH CHIẾN BINH MÁY CHỦ</h3>
                    <small><?= count($players) ?> nhân vật hiển thị</small>
                </div>
                <form class="admin-search" method="get">
                    <input type="hidden" name="tab" value="players">
                    <input name="q" placeholder="🔍 Tìm kiếm theo tên nhân vật hoặc tên tài khoản..." value="<?= htmlspecialchars($search) ?>">
                    <button class="btn" type="submit">Tìm Kiếm</button>
                    <?php if ($search !== ''): ?>
                        <a class="btn secondary" href="admin.php?tab=players">Xóa Lọc</a>
                    <?php endif; ?>
                </form>
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nhân Vật</th>
                            <th>Hành Tinh</th>
                            <th>Tài Khoản</th>
                            <th>Sức Mạnh</th>
                            <th>Trạng Thái</th>
                            <th>Thao Tác</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$players): ?>
                        <tr><td colspan="7" class="empty">Không tìm thấy nhân vật nào phù hợp.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($players as $row): 
                        $planet = (int) $row['gender'] === 0 ? 'Trái Đất' : ((int) $row['gender'] === 1 ? 'Namếc' : 'Xayda');
                    ?>
                        <tr style="<?= $playerId === (int)$row['id'] ? 'background: rgba(255, 102, 0, 0.08);' : '' ?>">
                            <td><strong style="color: var(--text-gold);">#<?= (int) $row['id'] ?></strong></td>
                            <td><strong style="font-size: 17px; color: #fff;"><?= htmlspecialchars((string) $row['name']) ?></strong></td>
                            <td><span style="color: var(--text-muted);"><?= $planet ?></span></td>
                            <td><?= htmlspecialchars((string) ($row['username'] ?? '—')) ?></td>
                            <td><strong style="font-family: 'Orbitron'; color: var(--db-yellow);"><?= formatNumber($row['power']) ?></strong></td>
                            <td>
                                <span class="tag <?= $row['online'] ? 'on' : 'off' ?>">
                                    <?= $row['online'] ? '● ON' : '○ OFF' ?>
                                </span>
                                <?php if ((int) ($row['ban'] ?? 0) === 1): ?>
                                    <span class="tag ban">Bị Khóa</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="display: flex; gap: 6px; flex-wrap: wrap; align-items: center;">
                                    <a class="btn secondary" style="padding: 4px 8px; font-size: 11px;" href="admin.php?tab=inventory&player=<?= (int) $row['id'] ?>&bag=overview">⚡ Chỉ Số</a>
                                    <a class="btn secondary" style="padding: 4px 8px; font-size: 11px;" href="admin.php?tab=inventory&player=<?= (int) $row['id'] ?>&bag=items_body">🎒 Túi Đồ</a>
                                    <a class="btn" style="padding: 4px 8px; font-size: 11px;" href="admin.php?tab=give&player=<?= (int) $row['id'] ?>">🎁 Cấp Đồ</a>
                                    <?php if (!empty($row['acc_id']) && (int)$row['acc_id'] !== (int)$_SESSION['user_id'] && (int)($row['is_admin'] ?? 0) !== 1): ?>
                                        <form method="post" style="display: inline; margin: 0;" onsubmit="return confirm('<?= (int)($row['ban'] ?? 0) === 1 ? 'Mở khóa cho tài khoản này?' : 'Bạn có chắc chắn muốn KHÓA tài khoản này?' ?>');">
                                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                                            <input type="hidden" name="action" value="toggle_ban">
                                            <input type="hidden" name="account_id" value="<?= (int) $row['acc_id'] ?>">
                                            <input type="hidden" name="ban_status" value="<?= (int)($row['ban'] ?? 0) === 1 ? '0' : '1' ?>">
                                            <input type="hidden" name="reason" value="Thao tác từ danh sách nhân vật">
                                            <?php if ((int)($row['ban'] ?? 0) === 1): ?>
                                                <button class="btn" style="padding: 4px 8px; font-size: 11px; background: linear-gradient(135deg, #10b981 0%, #059669 100%); border-color: #047857;" type="submit">🔓 Mở Khóa</button>
                                            <?php else: ?>
                                                <button class="btn danger" style="padding: 4px 8px; font-size: 11px;" type="submit">🔒 Khóa (Ban)</button>
                                            <?php endif; ?>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        <?php endif; ?>

        <!-- ====================================================================
             TAB 2: TÚI ĐỒ & THU HỒI VẬT PHẨM (INVENTORY)
             ==================================================================== -->
        <?php if ($currentTab === 'inventory'): ?>
            <?php if (!$detail): ?>
                <div class="panel empty" style="padding: 50px 20px;">
                    <div style="font-size: 40px; margin-bottom: 12px;">🎒</div>
                    <h3>Chưa chọn nhân vật để xem túi đồ!</h3>
                    <p style="color: var(--text-muted); margin-top: 6px;">Vui lòng chuyển qua tab "Quản Lý Nhân Vật" và bấm <strong>"Túi Đồ"</strong> vào một người chơi bất kỳ.</p>
                    <a class="btn" href="admin.php?tab=players" style="margin-top: 18px;">👥 Mở Danh Sách Nhân Vật</a>
                </div>
            <?php else: ?>
                <?php
                $point = json_decode((string) $detail['data_point'], true) ?: [];
                $inventory = json_decode((string) $detail['data_inventory'], true) ?: [];
                $online = isAccountOnline($detail);
                
                // Đếm số lượng item trong từng túi
                $bagCounts = [];
                foreach (CONTAINERS as $col => $lbl) {
                    $cItems = parseContainer($detail[$col] ?? null);
                    $bagCounts[$col] = count(array_filter($cItems));
                }
                ?>

                <!-- THANH SUB-TABS TÚI ĐỒ & CHỈ SỐ -->
                <div class="sub-tabs">
                    <a class="sub-tab-link <?= $currentBag === 'overview' ? 'active' : '' ?>" href="admin.php?tab=inventory&player=<?= (int) $detail['id'] ?>&bag=overview">
                        ⚡ Bảng Chỉ Số Nhân Vật
                    </a>
                    <?php foreach (CONTAINERS as $column => $label): ?>
                        <a class="sub-tab-link <?= $currentBag === $column ? 'active' : '' ?>" href="admin.php?tab=inventory&player=<?= (int) $detail['id'] ?>&bag=<?= $column ?>">
                            📦 <?= htmlspecialchars($label) ?>
                            <span style="font-size: 12px; opacity: 0.85; margin-left: 3px;">(<?= $bagCounts[$column] ?>)</span>
                        </a>
                    <?php endforeach; ?>
                </div>

                <?php if ($online): ?>
                    <div class="notice">
                        <span>ℹ️</span> Nhân vật <strong><?= htmlspecialchars((string) $detail['name']) ?></strong> đang Online. Lệnh thu hồi sẽ được gửi trực tiếp tới máy chủ và cập nhật vào game sau vài giây!
                    </div>
                <?php endif; ?>

                <?php if ($currentBag === 'overview'): ?>
                    <!-- TAB CON: CHỈ SỐ CƠ BẢN CỦA NHÂN VẬT -->
                    <section class="panel admin-block">
                        <div class="panel-head" style="flex-wrap: wrap; gap: 10px;">
                            <div>
                                <h3>⚡ THÔNG TIN CHỈ SỐ CHI TIẾT: <?= htmlspecialchars((string) $detail['name']) ?></h3>
                                <small style="margin-top: 4px; display: inline-block;">Tài khoản: <?= htmlspecialchars((string) ($detail['username'] ?? '—')) ?> · <?= $online ? '🟢 Đang Online' : '⚪ Offline' ?> · Trạng thái: <?= (int)($detail['ban'] ?? 0) === 1 ? '<span style="color:#ef4444; font-weight:bold;">Bị Khóa</span>' : '<span style="color:#10b981; font-weight:bold;">Bình Thường</span>' ?></small>
                            </div>
                            <?php if (!empty($detail['account_id']) && (int)$detail['account_id'] !== (int)$_SESSION['user_id'] && (int)($detail['is_admin'] ?? 0) !== 1): ?>
                                <form method="post" style="display: flex; gap: 6px; align-items: center; margin: 0;" onsubmit="return confirm('<?= (int)($detail['ban'] ?? 0) === 1 ? 'Mở khóa cho tài khoản này?' : 'Bạn có chắc chắn muốn KHÓA tài khoản này?' ?>');">
                                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                                    <input type="hidden" name="action" value="toggle_ban">
                                    <input type="hidden" name="account_id" value="<?= (int) $detail['account_id'] ?>">
                                    <input type="hidden" name="ban_status" value="<?= (int)($detail['ban'] ?? 0) === 1 ? '0' : '1' ?>">
                                    <input class="qty" style="width: 140px; padding: 4px 8px; font-size: 11px;" type="text" name="reason" placeholder="Lý do khóa / mở..." value="">
                                    <?php if ((int)($detail['ban'] ?? 0) === 1): ?>
                                        <button class="btn" style="padding: 5px 12px; font-size: 12px; background: linear-gradient(135deg, #10b981 0%, #059669 100%); border-color: #047857;" type="submit">🔓 Mở Khóa Tài Khoản</button>
                                    <?php else: ?>
                                        <button class="btn danger" style="padding: 5px 12px; font-size: 12px;" type="submit">🔒 Khóa Tài Khoản (Ban)</button>
                                    <?php endif; ?>
                                </form>
                            <?php endif; ?>
                        </div>
                        <div class="stat-grid">
                            <?php foreach (POINT_LABELS as $index => $label): ?>
                                <div class="stat">
                                    <span><?= htmlspecialchars($label) ?></span>
                                    <strong><?= formatNumber($point[$index] ?? 0) ?></strong>
                                </div>
                            <?php endforeach; ?>
                            <div class="stat"><span>Vàng 💰</span><strong style="color: #ffd700;"><?= formatNumber($inventory[0] ?? 0) ?></strong></div>
                            <div class="stat"><span>Ngọc Xanh 💎</span><strong style="color: #00d4ff;"><?= formatNumber($inventory[1] ?? 0) ?></strong></div>
                            <div class="stat"><span>Hồng Ngọc 🔮</span><strong style="color: #ff3399;"><?= formatNumber($inventory[2] ?? 0) ?></strong></div>
                            <div class="stat"><span>Cấp VIP ⭐</span><strong style="color: #ffaa00;"><?= formatNumber($detail['vip'] ?? 0) ?></strong></div>
                            <div class="stat"><span>Tổng Nạp 💳</span><strong><?= formatNumber($detail['tongnap'] ?? 0) ?>đ</strong></div>
                        </div>
                    </section>
                <?php else: ?>
                    <!-- TAB CON: TÚI ĐỒ ĐƯỢC CHỌN -->
                    <?php
                    $items = parseContainer($detail[$currentBag] ?? null);
                    $selectedLabel = CONTAINERS[$currentBag] ?? $currentBag;
                    ?>
                    <section class="panel admin-block">
                        <div class="panel-head">
                            <h3>📦 <?= htmlspecialchars($selectedLabel) ?> — <?= htmlspecialchars((string) $detail['name']) ?></h3>
                            <small><?= count(array_filter($items)) ?> vật phẩm có sẵn</small>
                        </div>
                        <table class="admin-table">
                            <thead>
                                <tr>
                                    <th style="width: 70px;">Ô Số</th>
                                    <th>Vật Phẩm</th>
                                    <th style="width: 90px;">Số Lượng</th>
                                    <th>Chỉ Số & Thuộc Tính</th>
                                    <th style="width: 320px;">Hành Động Thu Hồi</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php $hasItem = false; ?>
                            <?php foreach ($items as $slot => $item): ?>
                                <?php if (!$item) { continue; } $hasItem = true; ?>
                                <tr>
                                    <td><strong style="color: var(--text-gold);">Ô #<?= (int) $slot ?></strong></td>
                                    <td>
                                        <strong style="color: #fff; font-size: 16px;"><?= htmlspecialchars($itemNames[$item['template_id']] ?? ('#' . $item['template_id'])) ?></strong>
                                        <div class="rank-meta">ID: <?= (int) $item['template_id'] ?></div>
                                    </td>
                                    <td><strong style="color: var(--db-yellow); font-size: 16px;">x<?= (int) $item['quantity'] ?></strong></td>
                                    <td class="opt-cell">
                                        <?php if (!$item['options']): ?>
                                            <span class="rank-meta">Không có chỉ số</span>
                                        <?php else: ?>
                                            <div class="item-options-list">
                                                <?php foreach ($item['options'] as $option): ?>
                                                    <div>✦ <?= renderOption($option, $optionNames) ?></div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <form method="post" onsubmit="return confirm('Bạn có chắc chắn muốn thu hồi vật phẩm này không?');">
                                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                                            <input type="hidden" name="action" value="recall">
                                            <input type="hidden" name="player_id" value="<?= (int) $detail['id'] ?>">
                                            <input type="hidden" name="container" value="<?= htmlspecialchars($currentBag) ?>">
                                            <input type="hidden" name="slot" value="<?= (int) $slot ?>">
                                            <input class="qty" type="number" name="quantity" min="1" max="<?= (int) $item['quantity'] ?>" value="<?= (int) $item['quantity'] ?>" required title="Số lượng muốn thu hồi">
                                            <input name="reason" placeholder="Lý do thu hồi" maxlength="255">
                                            <button class="btn danger" type="submit">Thu Hồi</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$hasItem): ?>
                                <tr><td colspan="5" class="empty">Túi này hiện đang trống.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </section>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>

        <!-- ====================================================================
             TAB 3: CẤP VẬT PHẨM & CẤP ĐỆ TỬ (GIVE)
             ==================================================================== -->
        <?php if ($currentTab === 'give'): ?>
            <?php if (!$detail): ?>
                <div class="panel empty" style="padding: 50px 20px;">
                    <div style="font-size: 40px; margin-bottom: 12px;">🎁</div>
                    <h3>Chưa chọn nhân vật để cấp phát đồ!</h3>
                    <p style="color: var(--text-muted); margin-top: 6px;">Vui lòng chuyển qua tab "Quản Lý Nhân Vật" và chọn <strong>"Cấp Đồ"</strong> cho người chơi bạn muốn trao thưởng.</p>
                    <a class="btn" href="admin.php?tab=players" style="margin-top: 18px;">👥 Mở Danh Sách Nhân Vật</a>
                </div>
            <?php else: ?>
                <!-- CẤP ĐỆ TỬ -->
                <section class="panel admin-block">
                    <div class="panel-head">
                        <h3>🥋 CẤP ĐỆ TỬ CHO: <?= htmlspecialchars((string) $detail['name']) ?></h3>
                        <small>Hỗ trợ đệ tử Super & VIP</small>
                    </div>
                    <form class="give-form" method="post" onsubmit="return confirm('Xác nhận cấp đệ tử cho chiến binh <?= htmlspecialchars((string) $detail['name']) ?>?');">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                        <input type="hidden" name="action" value="give_pet">
                        <input type="hidden" name="player_id" value="<?= (int) $detail['id'] ?>">
                        <div class="field">
                            <label for="pet-type">Loại Đệ Tử</label>
                            <select id="pet-type" name="pet_type">
                                <option value="0">Đệ tử thường</option>
                                <option value="1">Mabư</option>
                                <option value="2">Beerus (Thần Hủy Diệt)</option>
                                <option value="3">Black Goku</option>
                                <option value="4">Black Goku Rose (Super Saiyan Rose)</option>
                            </select>
                        </div>
                        <div class="field">
                            <label for="pet-gender">Hành Tinh Xuất Thân</label>
                            <select id="pet-gender" name="pet_gender">
                                <option value="0">Trái Đất 🌍</option>
                                <option value="1">Namếc 🟢</option>
                                <option value="2">Xayda 🔴</option>
                            </select>
                        </div>
                        <div class="field">
                            <label for="pet-reason">Lý Do Cấp</label>
                            <input id="pet-reason" name="reason" placeholder="VD: Thưởng sự kiện, đền bù..." maxlength="255">
                        </div>
                        <label class="check-field">
                            <input type="checkbox" name="replace_pet" value="1">
                            <span>⚠️ Thay thế đệ tử hiện có (nếu nhân vật đã có đệ tử cũ thì đệ tử cũ và đồ đệ tử sẽ bị xóa để nhận đệ tử mới)</span>
                        </label>
                        <div>
                            <button class="btn gold" type="submit">⚡ CẤP ĐỆ TỬ NGAY</button>
                        </div>
                    </form>
                </section>

                <!-- CẤP VẬT PHẨM -->
                <section class="panel admin-block">
                    <div class="panel-head">
                        <h3>🎁 CẤP VẬT PHẨM & TRANG BỊ CHO: <?= htmlspecialchars((string) $detail['name']) ?></h3>
                        <small>Cấp trực tiếp vào Hành trang hoặc Rương đồ</small>
                    </div>
                    <form class="give-form" method="post">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf']) ?>">
                        <input type="hidden" name="action" value="give">
                        <input type="hidden" name="player_id" value="<?= (int) $detail['id'] ?>">
                        <div class="field">
                            <label for="give-item">Vật Phẩm (Gõ tên hoặc nhập ID)</label>
                            <input id="give-item" name="item_id" list="item-list" required placeholder="Nhập ID hoặc chọn tên vật phẩm">
                            <datalist id="item-list">
                                <?php foreach ($itemNames as $id => $name): ?>
                                    <option value="<?= (int) $id ?>"><?= htmlspecialchars($name) ?></option>
                                <?php endforeach; ?>
                            </datalist>
                        </div>
                        <div class="field">
                            <label for="give-quantity">Số Lượng</label>
                            <input id="give-quantity" type="number" name="quantity" min="1" max="1000000" value="1" required>
                        </div>
                        <div class="field">
                            <label for="give-container">Nơi Nhận Vật Phẩm</label>
                            <select id="give-container" name="container">
                                <option value="items_bag">Hành Trang (Túi Đồ Nhân Vật)</option>
                                <option value="items_box">Rương Đồ (Rương Tại Nhà)</option>
                            </select>
                        </div>
                        <div class="field">
                            <label for="give-options">Chỉ Số / Option (Tùy Chọn)</label>
                            <input id="give-options" name="options" placeholder="VD: 30:0 (khóa GD), 50:10, 77:15">
                        </div>
                        <div class="field">
                            <label for="give-reason">Lý Do Cấp</label>
                            <input id="give-reason" name="reason" placeholder="VD: Quà nạp đầu, sự kiện..." maxlength="255">
                        </div>
                        <div>
                            <button class="btn" type="submit">⚡ CẤP VẬT PHẨM NGAY</button>
                        </div>
                    </form>
                </section>
            <?php endif; ?>
        <?php endif; ?>

        <!-- ====================================================================
             TAB 4: HÀNG ĐỢI LỆNH SERVER (COMMANDS)
             ==================================================================== -->
        <?php if ($currentTab === 'commands'): ?>
            <section class="panel admin-block">
                <div class="panel-head">
                    <h3>⚡ HÀNG ĐỢI LỆNH REAL-TIME GỬI MÁY CHỦ</h3>
                    <small>Tự động làm mới mỗi 10 giây</small>
                </div>
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Thời Gian</th>
                            <th>Hành Động</th>
                            <th>Nhân Vật</th>
                            <th>Nội Dung</th>
                            <th>Nơi Nhận</th>
                            <th>Ô</th>
                            <th>Trạng Thái</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$commands): ?>
                        <tr><td colspan="7" class="empty">Hiện tại chưa có lệnh nào trong hàng đợi.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($commands as $cmd): ?>
                        <?php
                        $status = (string) $cmd['status'];
                        $statusLabel = ['pending' => '⏳ Đang chờ server', 'done' => '✓ Đã hoàn thành', 'offline' => '○ Người chơi offline', 'failed' => '✗ Thất bại'][$status] ?? $status;
                        $statusClass = $status === 'done' ? 'on' : ($status === 'pending' ? 'off' : 'ban');
                        ?>
                        <tr>
                            <td><?= htmlspecialchars((string) $cmd['created_at']) ?></td>
                            <td>
                                <strong style="color: #fff;">
                                    <?= $cmd['type'] === 'give_pet' ? '🥋 Cấp đệ tử' : ($cmd['type'] === 'give_item' ? '🎁 Cấp đồ' : '🗑️ Thu hồi') ?>
                                </strong>
                            </td>
                            <td><strong style="color: var(--text-gold);"><?= htmlspecialchars((string) ($cmd['player_name'] ?? ('#' . $cmd['player_id']))) ?></strong></td>
                            <td>
                                <?php if ($cmd['type'] === 'give_pet'): ?>
                                    <span style="color: var(--db-yellow);"><?= htmlspecialchars(PET_TYPES[(int) $cmd['item_id']] ?? ('Loại #' . $cmd['item_id'])) ?></span>
                                <?php else: ?>
                                    <?= htmlspecialchars($itemNames[(int) $cmd['item_id']] ?? ('#' . $cmd['item_id'])) ?> 
                                    <strong style="color: var(--db-yellow);">x<?= (int) $cmd['quantity'] ?></strong>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars(CONTAINERS[$cmd['container']] ?? $cmd['container']) ?></td>
                            <td><?= $cmd['slot'] < 0 ? '—' : ('#' . (int) $cmd['slot']) ?></td>
                            <td>
                                <span class="tag <?= $statusClass ?>"><?= htmlspecialchars($statusLabel) ?></span>
                                <?php if (!empty($cmd['message'])): ?>
                                    <div class="rank-meta"><?= htmlspecialchars((string) $cmd['message']) ?></div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        <?php endif; ?>

        <!-- ====================================================================
             TAB 5: LỊCH SỬ THAO TÁC (LOGS)
             ==================================================================== -->
        <?php if ($currentTab === 'logs'): ?>
            <section class="panel admin-block">
                <div class="panel-head">
                    <h3>📜 LỊCH SỬ HOẠT ĐỘNG ADMIN</h3>
                    <small><?= count($recallLogs) ?> hoạt động gần nhất</small>
                </div>
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Thời Gian</th>
                            <th>Admin Thực Hiện</th>
                            <th>Thao Tác</th>
                            <th>Chiến Binh</th>
                            <th>Chi Tiết</th>
                            <th>Nơi Thao Tác</th>
                            <th>Lý Do Ghi Nhận</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$recallLogs): ?>
                        <tr><td colspan="7" class="empty">Chưa có nhật ký hoạt động nào được ghi lại.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($recallLogs as $log): ?>
                        <tr>
                            <td><?= htmlspecialchars((string) $log['created_at']) ?></td>
                            <td><strong style="color: #66ccff;">@<?= htmlspecialchars((string) $log['admin_username']) ?></strong></td>
                            <td>
                                <?php
                                $act = $log['action'] ?? 'recall';
                                $badgeClass = in_array($act, ['recall', 'ban_account']) ? 'ban' : 'on';
                                $actName = [
                                    'recall' => 'Thu hồi đồ',
                                    'give' => 'Cấp đồ',
                                    'give_pet' => 'Cấp đệ tử',
                                    'ban_account' => 'Khóa (Ban)',
                                    'unban_account' => 'Mở khóa (Unban)',
                                ][$act] ?? $act;
                                ?>
                                <span class="tag <?= $badgeClass ?>">
                                    <?= htmlspecialchars($actName) ?>
                                </span>
                            </td>
                            <td><strong style="color: var(--text-gold);"><?= htmlspecialchars((string) $log['player_name']) ?></strong></td>
                            <td>
                                <div><strong><?= htmlspecialchars((string) $log['item_name']) ?></strong> x<?= (int) $log['quantity'] ?></div>
                                <?php if (!empty($log['options'])): ?>
                                    <div class="rank-meta" style="color: #66ccff;">✦ <?= htmlspecialchars((string) $log['options']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars(CONTAINERS[$log['container']] ?? $log['container']) ?></td>
                            <td><em><?= htmlspecialchars((string) ($log['reason'] ?? '—')) ?></em></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </section>
        <?php endif; ?>
        </div>
    </div>
</main>
<footer>
    <div class="shell">
        NhimsNRO · Hệ Thống Quản Trị Trung Tâm Admin.
    </div>
</footer>
<?php if ($pendingCount > 0): ?>
    <script>setTimeout(() => location.replace(location.href.split('#')[0]), 10000);</script>
<?php endif; ?>
</body>
</html>
