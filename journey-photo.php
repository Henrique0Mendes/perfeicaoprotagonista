<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
$user = require_login();
$pdo = db();
ensure_journey_pda_photo_schema($pdo);
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) { http_response_code(404); exit; }
$stmt = $pdo->prepare('SELECT driver_user_id,pda_photo FROM journeys WHERE id=? LIMIT 1');
$stmt->execute([$id]);
$journey = $stmt->fetch();
if (!$journey || empty($journey['pda_photo'])) { http_response_code(404); exit; }
if (!user_is_admin($user) && (int)$journey['driver_user_id'] !== (int)$user['id']) { http_response_code(403); exit('Sem autorização.'); }
$name = basename((string)$journey['pda_photo']);
$path = __DIR__ . '/storage/.journey-proofs/' . $name;
if (!is_file($path)) { http_response_code(404); exit; }
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
if (!in_array($mime, ['image/jpeg','image/png','image/webp'], true)) { http_response_code(415); exit; }
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string)filesize($path));
header('Content-Disposition: inline; filename="comprovativo-pda-' . (int)$id . '.' . pathinfo($name, PATHINFO_EXTENSION) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
