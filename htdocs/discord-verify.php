<?php
session_start();
require 'config.php';

$accountId = (int) ($_SESSION['user_id'] ?? $_SESSION['discord_verify_account_id'] ?? 0);
$username = $_SESSION['username'] ?? $_SESSION['discord_verify_username'] ?? '';
$error = '';
$alreadyLinked = false;
$linkedDiscordId = '';

function discordRequest(string $url, ?array $fields = null, ?string $authorization = null): array {
    $curl = curl_init($url);
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $authorization !== null
            ? ['Authorization: ' . $authorization]
            : ($fields === null ? [] : ['Content-Type: application/x-www-form-urlencoded']),
        CURLOPT_TIMEOUT => 10,
    ];
    if ($fields !== null) {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = http_build_query($fields);
    }
    curl_setopt_array($curl, $options);
    $body = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    $data = json_decode($body ?: '', true);
    if ($status < 200 || $status >= 300 || !is_array($data)) {
        $reason = is_array($data) ? ($data['error_description'] ?? $data['message'] ?? $data['error'] ?? '') : '';
        throw new RuntimeException('Discord API lỗi HTTP ' . $status . ($reason === '' ? '.' : ': ' . $reason));
    }
    return $data;
}

function isDiscordGuildMember(string $guildId, string $accessToken): bool {
    $guilds = discordRequest('https://discord.com/api/users/@me/guilds', null, 'Bearer ' . $accessToken);
    foreach ($guilds as $guild) {
        if (($guild['id'] ?? '') === $guildId) {
            return true;
        }
    }
    return false;
}

function dbStmt(mysqli $mysqli, string $sql): mysqli_stmt {
    $stmt = $mysqli->prepare($sql);
    if ($stmt === false) {
        throw new RuntimeException('Lỗi cơ sở dữ liệu: ' . $mysqli->error);
    }
    return $stmt;
}

function ensureDiscordSchema(mysqli $mysqli): void {
    $column = $mysqli->query("SHOW COLUMNS FROM account LIKE 'discord_id'");
    if ($column === false) {
        throw new RuntimeException('Lỗi cơ sở dữ liệu: ' . $mysqli->error);
    }
    if ($column->num_rows === 0) {
        $altered = $mysqli->query(
            "ALTER TABLE `account`
             ADD COLUMN `discord_id` varchar(32) DEFAULT NULL AFTER `active`,
             ADD KEY `idx_account_discord_id` (`discord_id`)"
        );
        if ($altered === false) {
            throw new RuntimeException('Lỗi cơ sở dữ liệu: ' . $mysqli->error);
        }
    }
    $created = $mysqli->query(
        "CREATE TABLE IF NOT EXISTS `discord_identity` (
          `discord_id` varchar(32) NOT NULL,
          `create_time` timestamp NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`discord_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
    if ($created === false) {
        throw new RuntimeException('Lỗi cơ sở dữ liệu: ' . $mysqli->error);
    }
}

if ($accountId <= 0 || $username === '') {
    header('Location: login.php');
    exit;
}

try {
    ensureDiscordSchema($mysqli);
    $stmt = dbStmt($mysqli, 'SELECT discord_id FROM account WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $accountId);
    $stmt->execute();
    $current = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$current) {
        throw new RuntimeException('Tài khoản không tồn tại.');
    }
    $linkedDiscordId = (string) ($current['discord_id'] ?? '');
    $alreadyLinked = $linkedDiscordId !== '';
} catch (Throwable $exception) {
    $error = $exception->getMessage();
}

$discordClientId = $discordClientId ?? '';
$discordClientSecret = $discordClientSecret ?? '';
$discordRedirectUri = $discordRedirectUri ?? '';
$discordGuildId = $discordGuildId ?? '';

if (!$alreadyLinked && $error === '') {
    if (isset($_GET['code'])) {
        $code = (string) $_GET['code'];
        $state = (string) ($_GET['state'] ?? '');
        $savedState = (string) ($_SESSION['discord_oauth_state'] ?? '');
        unset($_SESSION['discord_oauth_state']);

        try {
            if ($state === '' || !hash_equals($savedState, $state)) {
                throw new RuntimeException('Yêu cầu xác thực Discord không hợp lệ.');
            }
            if ($discordClientId === '' || $discordClientSecret === '' || $discordRedirectUri === '') {
                throw new RuntimeException('Chưa cấu hình thông số Discord OAuth.');
            }

            $tokenData = discordRequest(
                'https://discord.com/api/oauth2/token',
                [
                    'client_id' => $discordClientId,
                    'client_secret' => $discordClientSecret,
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $discordRedirectUri,
                ]
            );

            $accessToken = (string) ($tokenData['access_token'] ?? '');
            if ($accessToken === '') {
                throw new RuntimeException('Không nhận được Discord Access Token.');
            }

            $profile = discordRequest('https://discord.com/api/users/@me', null, 'Bearer ' . $accessToken);
            $discordUserId = (string) ($profile['id'] ?? '');
            if ($discordUserId === '') {
                throw new RuntimeException('Không thể lấy Discord User ID.');
            }

            if ($discordGuildId !== '' && !isDiscordGuildMember($discordGuildId, $accessToken)) {
                throw new RuntimeException('Bạn cần tham gia máy chủ Discord của game trước khi liên kết.');
            }

            $mysqli->begin_transaction();
            $stmt = dbStmt($mysqli, 'SELECT discord_id FROM discord_identity WHERE discord_id = ? FOR UPDATE');
            $stmt->bind_param('s', $discordUserId);
            $stmt->execute();
            $exists = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($exists) {
                $mysqli->rollback();
                throw new RuntimeException('Tài khoản Discord này đã được liên kết với một tài khoản game khác.');
            }

            $stmt = dbStmt($mysqli, 'INSERT INTO discord_identity (discord_id) VALUES (?)');
            $stmt->bind_param('s', $discordUserId);
            $stmt->execute();
            $stmt->close();

            $stmt = dbStmt($mysqli, 'UPDATE account SET discord_id = ? WHERE id = ?');
            $stmt->bind_param('si', $discordUserId, $accountId);
            $stmt->execute();
            $stmt->close();

            $mysqli->commit();
            $alreadyLinked = true;
            $linkedDiscordId = $discordUserId;

            if (!empty($_SESSION['discord_verify_account_id'])) {
                $_SESSION['user_id'] = (int) $_SESSION['discord_verify_account_id'];
                $_SESSION['username'] = (string) $_SESSION['discord_verify_username'];
                unset($_SESSION['discord_verify_account_id'], $_SESSION['discord_verify_username']);
            }
            header('Location: index.php?linked=1');
            exit;
        } catch (Throwable $exception) {
            if ($mysqli->connect_errno === 0) {
                @$mysqli->rollback();
            }
            $error = $exception->getMessage();
        }
    } elseif (isset($_GET['connect'])) {
        if ($discordClientId === '' || $discordClientSecret === '' || $discordRedirectUri === '') {
            $error = 'Chưa cấu hình thông số Discord OAuth trong config.php.';
        } else {
            $_SESSION['discord_oauth_state'] = bin2hex(random_bytes(16));
            $query = http_build_query([
                'client_id' => $discordClientId,
                'redirect_uri' => $discordRedirectUri,
                'response_type' => 'code',
                'scope' => 'identify guilds',
                'state' => $_SESSION['discord_oauth_state'],
            ]);
            header('Location: https://discord.com/oauth2/authorize?' . $query);
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
    <title>Liên Kết Discord | NhimsNRO</title>
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
            <img src="assets/images/17.png" alt="Dragon Ball 4 Sao">
        </div>
        <h1>LIÊN KẾT DISCORD</h1>
        <?php if ($alreadyLinked): ?>
            <p>Tài khoản <strong style="color: #ffbe0b;"><?= htmlspecialchars($username) ?></strong> đã liên kết thành công với tài khoản Discord.</p>
            <div class="alert success">⚡ Discord ID: <?= htmlspecialchars($linkedDiscordId) ?></div>
        <?php else: ?>
            <p>Tài khoản <strong style="color: #ffbe0b;"><?= htmlspecialchars($username) ?></strong> chưa liên kết Discord. Hãy liên kết để bảo vệ tài khoản và nhận các đặc quyền chiến binh.</p>
            <?php if ($error): ?>
                <div class="alert error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <a class="btn secondary" style="width: 100%;" href="discord-verify.php?connect=1">
                <?= $error ? 'Thử Lại Liên Kết Discord' : '⚡ Kết Nối Tài Khoản Discord Ngay' ?>
            </a>
        <?php endif; ?>
        <div class="auth-foot">
            <a href="index.php">← Về Trang Chủ</a><?php if (empty($_SESSION['user_id'])): ?> · <a href="login.php">Đăng nhập</a><?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
