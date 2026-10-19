<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

$user = require_login();
verify_csrf();
$pdo = db();
ensure_user_theme_schema($pdo);

$currentTheme = ($_SESSION['user']['theme'] ?? 'light') === 'dark' ? 'dark' : 'light';
$newTheme = $currentTheme === 'dark' ? 'light' : 'dark';
$stmt = $pdo->prepare('UPDATE users SET theme=? WHERE id=?');
$stmt->execute([$newTheme, $user['id']]);
$_SESSION['user']['theme'] = $newTheme;

$allowedPages = ['dashboard.php', 'routes.php', 'logs.php', 'fuel-logs.php', 'frota.php', 'cartoes.php', 'driver.php'];
$returnTo = basename((string)($_POST['return_to'] ?? 'dashboard.php'));
if (!in_array($returnTo, $allowedPages, true)) $returnTo = 'dashboard.php';
header('Location: ' . $returnTo);
exit;
