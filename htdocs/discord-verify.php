<?php
session_start();
require 'config.php';

$accountId = (int) ($_SESSION['discord_verify_account_id'] ?? 0);
$username = $_SESSION['discord_verify_username'] ?? '';
$error = '';

function discordRequest(string $url, ?array $fields = null, ?string $authorization = null): array {
    $curl = curl_init($url);
    $options = [CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $authorization !== null ? ['Authorization: ' . $authorization] : ($fields === null ? [] : ['Content-Type: application/x-www-form-urlencoded']), CURLOPT_TIMEOUT => 10];
    if ($fields !== null) {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = http_build_query($fields);
    }
    curl_setopt_array($curl, $options);
    $body = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl);
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
        if (($guild['id'] ?? '') === $guildId) return true;
    }
    return false;
}

if ($accountId <= 0 || $username === '') {
    $error = 'Phiên xác thực không hợp lệ. Hãy đăng ký lại.';
} elseif (isset($_GET['code'])) {
    if (!hash_equals($_SESSION['discord_oauth_state'] ?? '', $_GET['state'] ?? '')) $error = 'Yêu cầu xác thực không hợp lệ. Vui lòng thử lại.';
    else try {
        $token = discordRequest('https://discord.com/api/oauth2/token', ['client_id' => $discordClientId, 'client_secret' => $discordClientSecret, 'grant_type' => 'authorization_code', 'code' => $_GET['code'], 'redirect_uri' => $discordRedirectUri]);
        $accessToken = $token['access_token'] ?? '';
        $discordUser = discordRequest('https://discord.com/api/users/@me', null, 'Bearer ' . $accessToken);
        $discordId = $discordUser['id'] ?? '';
        if (!preg_match('/^\d{15,25}$/', $discordId)) throw new RuntimeException('Không lấy được Discord ID.');
        if (!isDiscordGuildMember($discordGuildId, $accessToken)) {
            throw new RuntimeException('Bạn cần tham gia Discord server của game trước khi xác thực.');
        }
        $mysqli->begin_transaction();
        $stmt = $mysqli->prepare('SELECT discord_id FROM account WHERE id = ? FOR UPDATE'); $stmt->bind_param('i', $accountId); $stmt->execute(); $account = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if (!$account) throw new RuntimeException('Tài khoản không tồn tại.');
        if ($account['discord_id'] !== null && $account['discord_id'] !== $discordId) throw new RuntimeException('Tài khoản đã gắn với Discord khác.');
        // This identity row locks concurrent verification attempts for one Discord ID.
        $stmt = $mysqli->prepare('INSERT IGNORE INTO discord_identity (discord_id) VALUES (?)'); $stmt->bind_param('s', $discordId); $stmt->execute(); $stmt->close();
        $stmt = $mysqli->prepare('SELECT discord_id FROM discord_identity WHERE discord_id = ? FOR UPDATE'); $stmt->bind_param('s', $discordId); $stmt->execute(); $stmt->close();
        $stmt = $mysqli->prepare('SELECT COUNT(*) AS total FROM account WHERE discord_id = ?'); $stmt->bind_param('s', $discordId); $stmt->execute(); $total = (int) $stmt->get_result()->fetch_assoc()['total']; $stmt->close();
        if ($account['discord_id'] === null && $total >= 2) throw new RuntimeException('Discord này đã xác thực tối đa 2 tài khoản game.');
        $stmt = $mysqli->prepare('UPDATE account SET discord_id = ?, active = 1, update_time = CURRENT_TIMESTAMP WHERE id = ?'); $stmt->bind_param('si', $discordId, $accountId); $stmt->execute(); $stmt->close();
        $mysqli->commit(); unset($_SESSION['discord_verify_account_id'], $_SESSION['discord_verify_username'], $_SESSION['discord_oauth_state']); header('Location: login.php?verified=1'); exit;
    } catch (Throwable $exception) { $mysqli->rollback(); $error = $exception->getMessage(); }
} elseif (isset($_GET['connect'])) {
    if ($discordClientId === '' || $discordClientSecret === '' || $discordGuildId === '' || strpos($discordRedirectUri, 'YOUR-DOMAIN') !== false) $error = 'Discord OAuth hoặc kiểm tra server Discord chưa được cấu hình. Hãy liên hệ quản trị viên.';
    else { $_SESSION['discord_oauth_state'] = bin2hex(random_bytes(32)); $query = http_build_query(['client_id' => $discordClientId, 'redirect_uri' => $discordRedirectUri, 'response_type' => 'code', 'scope' => 'identify guilds', 'state' => $_SESSION['discord_oauth_state']]); header('Location: https://discord.com/oauth2/authorize?' . $query); exit; }
}
?>
<!doctype html><html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Xác thực Discord | Ngọc Rồng</title><link rel="stylesheet" href="assets/css/site.css"></head><body><div class="auth-wrap"><div class="auth-card"><div class="kicker">Bảo vệ cộng đồng</div><h1>Xác thực Discord</h1><p>Tài khoản <strong><?= htmlspecialchars($username) ?></strong> chưa kích hoạt. Kết nối Discord để kích hoạt; mỗi Discord xác thực tối đa 2 tài khoản game.</p><?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?><?php if ($accountId > 0 && !$error): ?><a class="btn" href="discord-verify.php?connect=1">Kết nối Discord</a><?php endif; ?><div class="auth-foot"><a href="register.php">← Đăng ký</a> · <a href="login.php">Đăng nhập</a></div></div></div></body></html>
