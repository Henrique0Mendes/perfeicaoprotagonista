<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
$user = require_login();
$pdo = db();
ensure_journey_pda_photo_schema($pdo);
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) { http_response_code(404); exit('Comprovativo não encontrado.'); }
$stmt = $pdo->prepare('SELECT driver_user_id,pda_photo FROM journeys WHERE id=? LIMIT 1');
$stmt->execute([$id]);
$journey = $stmt->fetch();
if (!$journey || empty($journey['pda_photo'])) { http_response_code(404); exit('Comprovativo não encontrado.'); }
if (!user_is_admin($user) && (int)$journey['driver_user_id'] !== (int)$user['id']) { http_response_code(403); exit('Sem autorização.'); }
$from = (string)($_GET['from'] ?? '');
$backUrl = user_is_admin($user) ? 'logs.php#journey-history' : 'driver.php#driver-historico';
if ($from === 'driver') $backUrl = 'driver.php#driver-historico';
if ($from === 'logs' && user_is_admin($user)) { $returnPage = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT); $backUrl = 'logs.php' . ($returnPage && $returnPage > 1 ? '?page=' . $returnPage : '') . '#journey-history'; }
?><!doctype html><html lang="pt-PT"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Comprovativo PDA · Perfeição Protagonista</title><style>*{box-sizing:border-box}body{margin:0;min-height:100vh;background:#101d2c;color:#fff;font:14px Inter,Arial,sans-serif}.viewer-head{position:fixed;z-index:2;inset:0 0 auto;display:flex;align-items:center;justify-content:space-between;padding:14px 18px;background:linear-gradient(#101d2ccc,transparent)}.viewer-title{font-size:13px;font-weight:700}.viewer-close{display:grid;place-items:center;width:42px;height:42px;border:1px solid #ffffff45;border-radius:50%;background:#101d2cbb;color:#fff;text-decoration:none;font-size:29px;line-height:1;transition:background .18s,transform .18s}.viewer-close:hover{background:#18884a;transform:scale(1.05)}.viewer-main{min-height:100vh;display:grid;place-items:center;padding:74px 16px 24px}.viewer-main img{display:block;max-width:min(100%,1200px);max-height:calc(100vh - 100px);object-fit:contain;border-radius:8px;box-shadow:0 18px 65px #0006}@media(max-width:560px){.viewer-head{padding:11px 12px}.viewer-main{padding:66px 8px 12px}.viewer-main img{max-height:calc(100vh - 80px);border-radius:4px}}</style></head><body><header class="viewer-head"><span class="viewer-title">Comprovativo do PDA</span><a class="viewer-close" href="<?= e($backUrl) ?>" aria-label="Fechar e voltar ao painel" title="Voltar ao painel">×</a></header><main class="viewer-main"><img src="journey-photo.php?id=<?= (int)$id ?>" alt="Foto do PDA para comprovar as encomendas entregues"></main></body></html>
