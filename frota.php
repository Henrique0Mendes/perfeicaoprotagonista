<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
$user = require_login();
require_admin($user);
$pdo = db();
ensure_vehicle_rental_schema($pdo);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    try {
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'create') {
            $registration = strtoupper(trim((string)($_POST['registration'] ?? '')));
            $model = trim((string)($_POST['model'] ?? ''));
            $fuelType = (string)($_POST['fuel_type'] ?? '');
            $isRented = (string)($_POST['is_rented'] ?? '0');
            if ($registration === '' || $model === '' || !in_array($fuelType, ['Gasóleo','Elétrico'], true) || !in_array($isRented, ['0','1'], true)) throw new RuntimeException('Indique matrícula, modelo, motorização e se a viatura é alugada.');
            $pdo->prepare('INSERT INTO vehicles(registration,model,fuel_type,is_rented) VALUES(?,?,?,?)')->execute([$registration, $model, $fuelType, (int)$isRented]);
            flash('Viatura adicionada à frota.');
        } elseif ($action === 'rental') {
            $isRented = (string)($_POST['is_rented'] ?? '');
            if (!in_array($isRented, ['0','1'], true)) throw new RuntimeException('Selecione se a viatura é alugada.');
            $pdo->prepare('UPDATE vehicles SET is_rented=? WHERE id=?')->execute([(int)$isRented, (int)($_POST['vehicle_id'] ?? 0)]);
            flash('Regime de propriedade da viatura atualizado.');
        } elseif ($action === 'status') {
            $status = (string)($_POST['status'] ?? '');
            if (!in_array($status, ['Disponível','Manutenção'], true)) throw new RuntimeException('O estado “Em rota” é controlado pelo início de uma rota.');
            $vehicleId = (int)($_POST['vehicle_id'] ?? 0);
            $vehicleCheck = $pdo->prepare('SELECT vehicles.id,(SELECT COUNT(*) FROM routes WHERE routes.vehicle_id=vehicles.id) AS active_routes FROM vehicles WHERE vehicles.id=?');
            $vehicleCheck->execute([$vehicleId]);
            $vehicle = $vehicleCheck->fetch();
            if (!$vehicle) throw new RuntimeException('Viatura não encontrada.');
            if ((int)$vehicle['active_routes'] > 0) throw new RuntimeException('Elimine ou reatribua a rota antes de alterar o estado da viatura.');
            $pdo->prepare('UPDATE vehicles SET status=? WHERE id=?')->execute([$status, $vehicleId]);
            flash('Estado da viatura atualizado.');
        } else { throw new RuntimeException('Ação inválida.'); }
    } catch (PDOException $exception) {
        $error = str_contains(strtolower($exception->getMessage()), 'duplicate') ? 'Já existe uma viatura com essa matrícula.' : 'Não foi possível guardar. Confirme os dados e tente novamente.';
    } catch (RuntimeException $exception) { $error = $exception->getMessage(); }
}

$notice = take_flash();
$vehicles = $pdo->query('SELECT vehicles.*, (SELECT COUNT(*) FROM routes WHERE routes.vehicle_id=vehicles.id) AS active_routes FROM vehicles ORDER BY registration')->fetchAll();
$summary = ['Disponível'=>0,'Em rota'=>0,'Manutenção'=>0];
foreach ($vehicles as $vehicle) $summary[$vehicle['status']]++;
?><!doctype html>
<html lang="pt-PT"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Gestão de frota · Perfeição Protagonista</title><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="styles.css"><link rel="stylesheet" href="internal.css?v=20261016-hero-icons"><script src="internal-ui.js" defer></script></head>
<body class="app-body"><header class="app-header"><a class="brand" href="index.html"><span class="brand-mark app-brand-mark" aria-hidden="true"><svg class="brand-symbol" viewBox="0 0 120 120" xmlns="http://www.w3.org/2000/svg"><path class="brand-back" fill="#101820" fill-rule="evenodd" d="M13 8h48c33 0 51 17 51 44S94 96 61 96H44v20L13 87V8Zm31 28v32h17c14 0 21-5 21-16s-7-16-21-16H44Z"/><path class="brand-front" fill="#18884a" fill-rule="evenodd" d="M7 3h48c33 0 51 17 51 44S88 91 55 91H38v20L7 82V3Zm31 28v32h17c14 0 21-5 21-16s-7-16-21-16H38Z"/></svg></span><span>Perfeição<span class="brand-light"> Protagonista</span><small>GESTÃO DE OPERAÇÕES</small></span></a><?php require __DIR__ . '/admin-nav.php'; ?><div class="app-user"><a class="logout-button" href="dashboard.php">← Painel geral</a><span><?= e($user['name']) ?><small>Administrador</small></span><form method="post" action="logout.php"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><button class="logout-button">Terminar sessão ↗</button></form></div></header>
<main class="dashboard admin-dashboard fleet-dashboard"><section class="fleet-welcome ops-welcome driver-welcome"><div><span class="app-kicker">CONTROLO DA FROTA</span><h1>As viaturas,<br><em>numa vista clara.</em></h1><p>Consulte disponibilidade, rotas ativas e estado operacional. Atualize os registos no mesmo lugar.</p></div><div class="driver-symbol" aria-hidden="true"><svg class="driver-hero-logo" viewBox="0 0 120 120" xmlns="http://www.w3.org/2000/svg"><path class="brand-back" d="M13 8h48c33 0 51 17 51 44S94 96 61 96H44v20L13 87V8Zm31 28v32h17c14 0 21-5 21-16s-7-16-21-16H44Z" fill-rule="evenodd"/><path class="brand-front" d="M7 3h48c33 0 51 17 51 44S88 91 55 91H38v20L7 82V3Zm31 28v32h17c14 0 21-5 21-16s-7-16-21-16H38Z" fill-rule="evenodd"/></svg></div></section>
<?php if ($notice): ?><div class="notice <?= e($notice['kind']) ?>"><?= e($notice['message']) ?></div><?php endif; ?><?php if ($error): ?><div class="notice error"><?= e($error) ?></div><?php endif; ?>
<section class="metric-grid fleet-metrics"><article><span>TOTAL DE VIATURAS</span><strong><?= count($vehicles) ?></strong><small>Na frota</small></article><article><span>DISPONÍVEIS</span><strong><?= $summary['Disponível'] ?></strong><small>Prontas para atribuição</small></article><article><span>EM ROTA</span><strong><?= $summary['Em rota'] ?></strong><small>Em distribuição</small></article><article><span>EM MANUTENÇÃO</span><strong><?= $summary['Manutenção'] ?></strong><small>Indisponíveis para novas rotas</small></article></section>
<div class="fleet-content"><section class="panel fleet-inventory"><div class="panel-heading fleet-heading"><div><span class="app-kicker">INVENTÁRIO</span><h2>Viaturas registadas</h2><p>Pesquise por matrícula ou modelo e atualize o estado de cada viatura.</p></div><span class="panel-count" id="fleet-count"><?= count($vehicles) ?> viaturas</span></div>
<div class="fleet-filters"><label class="fleet-search"><span aria-hidden="true">⌕</span><input id="fleet-search" type="search" placeholder="Pesquisar matrícula ou modelo" aria-label="Pesquisar frota"></label><label class="fleet-status-filter"><span>Estado</span><select id="fleet-status"><option value="">Todos os estados</option><?php foreach (['Disponível','Em rota','Manutenção'] as $status): ?><option><?= e($status) ?></option><?php endforeach; ?></select></label></div>
<?php if (!$vehicles): ?><div class="fleet-empty"><span aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M3 6h11v12H3zM14 10h4l3 3v5h-7z"/><circle cx="7.5" cy="18" r="2"/><circle cx="17.5" cy="18" r="2"/></svg></span><h3>A frota ainda está vazia</h3><p>Registe a primeira viatura no formulário ao lado.</p></div><?php else: ?><div class="table-wrap"><table class="fleet-table"><thead><tr><th>VIATURA</th><th>ALUGADA</th><th>ROTAS ATIVAS</th><th>ESTADO OPERACIONAL</th></tr></thead><tbody id="fleet-rows"><?php foreach ($vehicles as $vehicle): ?><tr data-status="<?= e($vehicle['status']) ?>" data-search="<?= e(strtolower($vehicle['registration'].' '.$vehicle['model'].' '.$vehicle['fuel_type'].' '.((int)$vehicle['is_rented'] === 1 ? 'alugada' : 'própria'))) ?>"><td><div class="fleet-vehicle-cell"><span class="fleet-vehicle-icon<?= (int)$vehicle['is_rented'] === 1 ? ' is-rented' : ($vehicle['fuel_type'] === 'Elétrico' ? ' is-electric' : '') ?>" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M3 6h11v12H3zM14 10h4l3 3v5h-7z"/><circle cx="7.5" cy="18" r="2"/><circle cx="17.5" cy="18" r="2"/></svg></span><span><strong><?= e($vehicle['registration']) ?></strong><small><?= e($vehicle['model']) ?> · <?= e($vehicle['fuel_type']) ?></small></span></div></td><td><form method="post" class="fleet-status-form"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="rental"><input type="hidden" name="vehicle_id" value="<?= (int)$vehicle['id'] ?>"><select name="is_rented" aria-label="Viatura alugada <?= e($vehicle['registration']) ?>" onchange="this.form.submit()" class="state-select"><option value="0" <?= !(int)$vehicle['is_rented'] ? 'selected' : '' ?>>Não</option><option value="1" <?= (int)$vehicle['is_rented'] ? 'selected' : '' ?>>Sim</option></select></form></td><td><span class="route-count-pill"><?= (int)$vehicle['active_routes'] ?> <?= (int)$vehicle['active_routes'] === 1 ? 'rota' : 'rotas' ?></span></td><td><form method="post" class="fleet-status-form"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="status"><input type="hidden" name="vehicle_id" value="<?= (int)$vehicle['id'] ?>"><select name="status" aria-label="Estado da viatura <?= e($vehicle['registration']) ?>" onchange="this.form.submit()" class="state-select state-<?= e(strtolower(str_replace(' ','-', $vehicle['status']))) ?>" <?= (int)$vehicle['active_routes'] > 0 ? 'disabled title="Elimine ou reatribua a rota para alterar o estado."' : '' ?>><?php foreach (['Disponível','Em rota','Manutenção'] as $status): ?><option <?= $vehicle['status'] === $status ? 'selected' : '' ?> <?= $status === 'Em rota' ? 'disabled' : '' ?>><?= e($status) ?></option><?php endforeach; ?></select></form></td></tr><?php endforeach; ?></tbody></table><p class="fleet-no-results" id="fleet-no-results" hidden>Não foram encontradas viaturas com estes filtros.</p></div><?php endif; ?></section>
<aside class="panel fleet-create"><div class="panel-heading"><div><span class="app-kicker">NOVO REGISTO</span><h2>Adicionar viatura</h2></div><span class="report-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg></span></div><p class="fleet-create-intro">Registe uma viatura para a disponibilizar na atribuição de rotas.</p><form method="post" class="compact-form"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="create"><label>Matrícula<input name="registration" placeholder="Ex.: 12-AA-34" required maxlength="20"></label><label>Marca e modelo<input name="model" placeholder="Ex.: Renault Master" required maxlength="100"></label><label>Motorização<select name="fuel_type" required><option value="">Selecionar motorização</option><option value="Gasóleo">Gasóleo</option><option value="Elétrico">Elétrico</option></select></label><label>Alugada?<select name="is_rented" required><option value="0">Não</option><option value="1">Sim</option></select></label><button class="button button-lime full">Adicionar à frota <span>↗</span></button></form><div class="fleet-legend"><span class="legend-dot available"></span><span><strong>Disponível</strong> · pode ser atribuída a uma rota</span><span class="legend-dot on-route"></span><span><strong>Em rota</strong> · em distribuição</span><span class="legend-dot maintenance"></span><span><strong>Manutenção</strong> · indisponível</span></div></aside></div>
<footer class="app-footer"><span>Perfeição Protagonista · Gestão da frota</span><a href="dashboard.php">Voltar ao painel ↗</a></footer></main>
<script>
const searchInput=document.querySelector('#fleet-search');const statusInput=document.querySelector('#fleet-status');const fleetRows=[...document.querySelectorAll('#fleet-rows tr')];const fleetCount=document.querySelector('#fleet-count');const noResults=document.querySelector('#fleet-no-results');
function filterFleet(){const query=(searchInput?.value||'').trim().toLocaleLowerCase('pt-PT');const status=statusInput?.value||'';let visible=0;fleetRows.forEach(row=>{const matchText=row.dataset.search.includes(query);const matchStatus=!status||row.dataset.status===status;const show=matchText&&matchStatus;row.hidden=!show;if(show)visible++;});if(fleetCount)fleetCount.textContent=`${visible} ${visible===1?'viatura':'viaturas'}`;if(noResults)noResults.hidden=visible!==0;}
searchInput?.addEventListener('input',filterFleet);statusInput?.addEventListener('change',filterFleet);
</script></body></html>










