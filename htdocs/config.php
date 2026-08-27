<?php
$host = 'localhost';
$db   = 'nro';   // đúng tên DB bạn đã import nro.sql
$user = 'root';  // user MySQL của bạn
$pass = '';      // mật khẩu MySQL (nếu có thì điền vào đây)

$mysqli = new mysqli($host, $user, $pass, $db);
if ($mysqli->connect_errno) {
    die('Không kết nối được MySQL: ' . $mysqli->connect_error);
}

$mysqli->set_charset('utf8mb4');

$discordGuildId = '1542423954017489017';
$discordClientId = '1542423037737963540';
$discordClientSecret = '78LenIy2kMcNitooYFBXEs8ZD2bvzk1U';
$discordRedirectUri = 'http://localhost/discord-verify.php';
