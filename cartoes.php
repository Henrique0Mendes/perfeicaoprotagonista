<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
require __DIR__ . '/bp-fleet-api.php';
$user = require_login();
require_admin($user);
$pdo = db();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'create') {
            $provider = 'BP';
            $reference = trim((string)($_POST['card_reference'] ?? ''));
            if ($reference === '' || !preg_match('/^[0-9 ]+$/', $reference)) throw new RuntimeException('Indique um número de cartão válido.');
            $pdo->prepare('INSERT INTO fleet_cards(provider,card_reference) VALUES(?,?)')->execute([$provider, $reference]);
            flash('Cartão de frota adicionado.');
        } elseif ($action === 'import_bp') {
            $startDate = trim((string)($_POST['start_date'] ?? ''));
            $pdo->beginTransaction();
            try {
                $result = bp_fleet_import_cards($pdo, $startDate);
                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $exception;
            }
            flash($result['imported'] . ' cartão(ões) BP novo(s) importado(s).' . ($result['ignored'] ? ' ' . $result['ignored'] . ' registo(s) sem número foram ignorados.' : ''));
        } elseif ($action === 'assign') {
            $cardId = (int)($_POST['card_id'] ?? 0);
            $driverId = (int)($_POST['driver_user_id'] ?? 0);
            if ($driverId > 0) {
                $check = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id=? AND role IN ('driver','both')");
                $check->execute([$driverId]);
                if (!(int)$check->fetchColumn()) throw new RuntimeException('Selecione um condutor válido.');
            }
            $pdo->prepare('UPDATE fleet_cards SET driver_user_id=? WHERE id=?')->execute([$driverId ?: null, $cardId]);
            flash($driverId ? 'Cartão atribuído ao condutor.' : 'Atribuição do cartão removida.');
        } elseif ($action === 'delete') {
            $pdo->prepare('DELETE FROM fleet_cards WHERE id=?')->execute([(int)($_POST['card_id'] ?? 0)]);
            flash('Cartão removido da frota.');
        } else {
            throw new RuntimeException('Ação inválida.');
        }
        header('Location: cartoes.php');
        exit;
    } catch (PDOException $exception) {
        $error = str_contains(strtolower($exception->getMessage()), 'duplicate') ? 'Já existe um cartão com essa referência.' : 'Não foi possível guardar. Confirme os dados e tente novamente.';
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        $error = 'Não foi possível importar cartões da BP. Confirme a configuração da API e tente novamente.';
    }
}

$notice = take_flash();
$drivers = $pdo->query("SELECT id,name FROM users WHERE role IN ('driver','both') ORDER BY name")->fetchAll();
$cards = $pdo->query('SELECT fleet_cards.*, users.name AS driver_name FROM fleet_cards LEFT JOIN users ON users.id=fleet_cards.driver_user_id ORDER BY fleet_cards.provider, fleet_cards.card_reference')->fetchAll();
?><!doctype html>
<html lang="pt-PT"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Cartões de frota · Perfeição Protagonista</title><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="styles.css"><link rel="stylesheet" href="internal.css?v=20261019-white-admin-nav-v23"><script src="internal-ui.js" defer></script><link rel="icon" type="image/svg+xml" href="favicon.svg?v=2"></head>
<body class="app-body" data-page="cards" data-theme="<?= e(app_theme()) ?>"><header class="app-header"><a class="brand" href="index.html"><span class="brand-mark app-brand-mark" aria-hidden="true"><svg class="brand-symbol" viewBox="0 0 120 120" xmlns="http://www.w3.org/2000/svg"><path class="brand-back" fill="#101820" fill-rule="evenodd" d="M13 8h48c33 0 51 17 51 44S94 96 61 96H44v20L13 87V8Zm31 28v32h17c14 0 21-5 21-16s-7-16-21-16H44Z"/><path class="brand-front" fill="#18884a" fill-rule="evenodd" d="M7 3h48c33 0 51 17 51 44S88 91 55 91H38v20L7 82V3Zm31 28v32h17c14 0 21-5 21-16s-7-16-21-16H38Z"/></svg></span><span>Perfeição<span class="brand-light"> Protagonista</span><small>GESTÃO DE OPERAÇÕES</small></span></a><?php require __DIR__ . '/admin-nav.php'; ?><div class="app-user"><span><?= e($user['name']) ?><small>Administrador</small></span><form method="post" action="logout.php"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><button class="logout-button">Terminar sessão ↗</button></form></div></header>
<main class="dashboard admin-dashboard fleet-dashboard cards-dashboard"><section class="fleet-welcome driver-welcome"><div><span class="app-kicker">MEIOS DE ABASTECIMENTO</span><h1>Cartões de frota,<br><em>sob controlo.</em></h1><p>Registe cartões, atribua-os aos condutores e consulte rapidamente quem tem cada cartão.</p></div><div class="driver-symbol" aria-hidden="true"><svg class="driver-hero-logo" viewBox="0 0 120 120" xmlns="http://www.w3.org/2000/svg"><path class="brand-back" d="M13 8h48c33 0 51 17 51 44S94 96 61 96H44v20L13 87V8Zm31 28v32h17c14 0 21-5 21-16s-7-16-21-16H44Z" fill-rule="evenodd"/><path class="brand-front" d="M7 3h48c33 0 51 17 51 44S88 91 55 91H38v20L7 82V3Zm31 28v32h17c14 0 21-5 21-16s-7-16-21-16H38Z" fill-rule="evenodd"/></svg></div></section>
<?php if ($notice): ?><div class="notice <?= e($notice['kind']) ?>"><?= e($notice['message']) ?></div><?php endif; ?><?php if ($error): ?><div class="notice error"><?= e($error) ?></div><?php endif; ?>
<section class="metric-grid fleet-metrics card-metrics"><article><span>CARTÕES REGISTADOS</span><strong><?= count($cards) ?></strong><small>Na gestão da frota</small></article><article><span>ATRIBUÍDOS</span><strong><?= count(array_filter($cards, static fn(array $card): bool => !empty($card['driver_user_id']))) ?></strong><small>Associados a condutores</small></article><article><span>POR ATRIBUIR</span><strong><?= count(array_filter($cards, static fn(array $card): bool => empty($card['driver_user_id']))) ?></strong><small>Disponíveis para utilização</small></article></section>
<div class="cards-layout"><section class="panel cards-list"><div class="panel-heading"><div><span class="app-kicker">INVENTÁRIO</span><h2>Cartões registados</h2></div><span class="panel-count"><?= count($cards) ?> cartões</span></div><?php if (!$cards): ?><div class="fleet-empty"><span aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h4"/></svg></span><h3>Sem cartões registados</h3><p>Adicione um cartão para começar a atribuí-lo à equipa.</p></div><?php else: ?><div class="cards-items"><?php foreach ($cards as $card): ?><article class="fleet-card-row"><div class="fleet-card-symbol" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h4"/></svg></div><div class="fleet-card-info"><span class="fleet-card-provider"><?= e($card['provider']) ?></span><strong><?= e($card['card_reference']) ?></strong><small><?= $card['driver_name'] ? 'Atribuído a ' . e($card['driver_name']) : 'Disponível para atribuição' ?></small></div><form method="post" class="fleet-card-assign"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="assign"><input type="hidden" name="card_id" value="<?= (int)$card['id'] ?>"><label class="sr-only" for="driver-<?= (int)$card['id'] ?>">Atribuir cartão a condutor</label><select id="driver-<?= (int)$card['id'] ?>" name="driver_user_id"><option value="0">Sem atribuição</option><?php foreach ($drivers as $driver): ?><option value="<?= (int)$driver['id'] ?>" <?= (int)$card['driver_user_id'] === (int)$driver['id'] ? 'selected' : '' ?>><?= e($driver['name']) ?></option><?php endforeach; ?></select><button class="button button-dark" type="submit">Guardar</button></form><form method="post" class="fleet-card-delete" onsubmit="return confirm('Remover este cartão da frota?')"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="card_id" value="<?= (int)$card['id'] ?>"><button class="remove-card-button" type="submit" aria-label="Remover cartão <?= e($card['card_reference']) ?>">Remover</button></form></article><?php endforeach; ?></div><?php endif; ?></section>
<aside class="panel fleet-create" id="novo-cartao"><div class="panel-heading"><div><span class="app-kicker">SINCRONIZAÇÃO BP</span><h2>Importar cartões</h2></div><span class="report-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M20 7v5h-5M4 17v-5h5"/><path d="M5.6 9A7 7 0 0 1 18 6l2 6M4 12l2 6a7 7 0 0 0 12.4-3"/></svg></span></div><p class="fleet-create-intro">Importa cartões atualizados na BP para a base de dados. A janela máxima permitida pela BP é de 90 dias.</p><form method="post" class="compact-form"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="import_bp"><label>Alterados desde<input name="start_date" type="date" min="<?= e(gmdate('Y-m-d', strtotime('-89 days'))) ?>" max="<?= e(gmdate('Y-m-d')) ?>" value="<?= e(gmdate('Y-m-d', strtotime('-89 days'))) ?>" required></label><button class="button button-lime full" type="submit">Importar da BP <span>↗</span></button></form><p class="card-privacy-note">A sincronização é manual. Os cartões existentes e as atribuições mantêm-se; são acrescentados os números recebidos da BP.</p><hr><div class="panel-heading"><div><span class="app-kicker">REGISTO MANUAL</span><h2>Adicionar cartão</h2></div></div><form method="post" class="compact-form"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="create"><div class="fixed-card-provider"><span>EMPRESA</span><strong>BP</strong></div><label>Número do cartão<input name="card_reference" inputmode="numeric" pattern="[0-9 ]+" placeholder="Ex.: 1234 5678 9012" maxlength="80" required></label><button class="button button-dark full" type="submit">Adicionar manualmente <span>↗</span></button></form><p class="card-privacy-note">Apenas os administradores podem alterar cartões e atribuições.</p></aside></div>
<footer class="app-footer"><span>Perfeição Protagonista · Cartões de frota</span><a href="dashboard.php">Voltar ao painel ↗</a></footer></main></body></html>






