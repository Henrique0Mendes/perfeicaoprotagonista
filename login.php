<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
if ($existingUser = current_user()) {
    header('Location: ' . (user_is_admin($existingUser) ? 'dashboard.php' : 'driver.php'));
    exit;
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $pdo = db();
    ensure_user_theme_schema($pdo);
    $stmt = $pdo->prepare('SELECT id,name,email,password_hash,role,theme FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if ($user && password_verify($password, $user['password_hash'])) {
        session_regenerate_id(true);
        unset($user['password_hash']);
        $_SESSION['user'] = $user;
        header('Location: ' . (user_is_admin($user) ? 'dashboard.php' : 'driver.php'));
        exit;
    }
    $error = 'Email ou palavra-passe incorretos.';
}
$notice = take_flash();
?><!doctype html><html lang="pt-PT"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Iniciar sessão · Perfeição Protagonista</title><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="styles.css"><link rel="stylesheet" href="internal.css?v=20261019-dark-theme-v11"><script src="internal-ui.js" defer></script><script src="login-password.js" defer></script><link rel="icon" type="image/svg+xml" href="favicon.svg?v=2"></head><body class="app-body"><main class="auth-shell"><a class="brand" href="index.html"><span class="brand-mark app-brand-mark" aria-hidden="true"><svg class="brand-symbol" viewBox="0 0 120 120" xmlns="http://www.w3.org/2000/svg"><path class="brand-back" fill="#101820" fill-rule="evenodd" d="M13 8h48c33 0 51 17 51 44S94 96 61 96H44v20L13 87V8Zm31 28v32h17c14 0 21-5 21-16s-7-16-21-16H44Z"/><path class="brand-front" fill="#18884a" fill-rule="evenodd" d="M7 3h48c33 0 51 17 51 44S88 91 55 91H38v20L7 82V3Zm31 28v32h17c14 0 21-5 21-16s-7-16-21-16H38Z"/></svg></span><span>Perfeição<span class="brand-light"> Protagonista</span><small>PUBLICIDADE & ENCOMENDAS</small></span></a><section class="auth-card"><span class="app-kicker">ÁREA RESERVADA</span><h1>Bom tê-lo de volta.</h1><p>Entre para consultar a operação e gerir as suas rotas.</p><?php if ($notice): ?><div class="notice <?= e($notice['kind']) ?>"><?= e($notice['message']) ?></div><?php endif; ?><?php if ($error): ?><div class="notice error"><?= e($error) ?></div><?php endif; ?><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><label>Email<input name="email" type="email" required autocomplete="username" value="<?= e($_POST['email'] ?? '') ?>"></label><label>Palavra-passe<span class="password-field"><input name="password" type="password" required autocomplete="current-password" id="login-password"><button class="password-toggle" type="button" aria-controls="login-password" aria-pressed="false">Mostrar</button></span></label><button class="button button-lime full" type="submit">Iniciar sessão <span>↗</span></button></form><a class="auth-back" href="index.html">← Voltar ao site</a></section><p class="auth-foot">Acesso destinado à equipa Perfeição Protagonista.</p></main></body></html>


