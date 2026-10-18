<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
$user = require_login();
$pdo = db();
ensure_user_profile_photo_schema($pdo);
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) { http_response_code(404); exit; }
$stmt = $pdo->prepare('SELECT id,role,profile_photo FROM users WHERE id=? LIMIT 1');
$stmt->execute([$id]);
$profile = $stmt->fetch();
if (!$profile || empty($profile['profile_photo'])) { http_response_code(404); exit; }
if ((int)$profile['id'] !== (int)$user['id'] && !user_is_admin($user)) { http_response_code(403); exit('Sem autorização.'); }
$name = basename((string)$profile['profile_photo']);
$path = __DIR__ . '/storage/.profile-photos/' . $name;
if (!is_file($path)) { http_response_code(404); exit; }
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
if (!in_array($mime, ['image/jpeg','image/png','image/webp'], true)) { http_response_code(415); exit; }
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($path));
header('Content-Disposition: inline; filename="foto-perfil-' . (int)$id . '.' . pathinfo($name, PATHINFO_EXTENSION) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
