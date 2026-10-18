<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
$user = require_login();
if (!user_is_driver($user)) { http_response_code(403); exit('Esta conta não tem acesso à área do condutor.'); }
$pdo = db();
ensure_fuel_records_schema($pdo);
ensure_journey_pda_photo_schema($pdo);
ensure_user_profile_photo_schema($pdo);
$error = '';
$dialogToReopen = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'profile_photo') {
    verify_csrf();
    try {
        $photo = $_FILES['profile_photo'] ?? null;
        if (!is_array($photo) || (int)($photo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('Escolha uma imagem para carregar.');
        if ((int)$photo['size'] < 1 || (int)$photo['size'] > 8 * 1024 * 1024 || !is_uploaded_file($photo['tmp_name'])) throw new RuntimeException('A foto deve ter no máximo 8 MB.');
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($photo['tmp_name']);
        $extensions = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
        if (!isset($extensions[$mime])) throw new RuntimeException('Formato não suportado. Envie uma imagem JPG, PNG ou WebP.');
        $storageDir = __DIR__ . '/storage/.profile-photos';
        if (!is_dir($storageDir) && !mkdir($storageDir, 0750, true) && !is_dir($storageDir)) throw new RuntimeException('Não foi possível preparar o armazenamento da foto.');
        $fileName = bin2hex(random_bytes(24)) . '.' . $extensions[$mime];
        if (!move_uploaded_file($photo['tmp_name'], $storageDir . '/' . $fileName)) throw new RuntimeException('Não foi possível guardar a foto enviada.');
        $previous = $pdo->prepare('SELECT profile_photo FROM users WHERE id=?');
        $previous->execute([$user['id']]);
        $oldProfilePhoto = $previous->fetchColumn() ?: null;
        $save = $pdo->prepare('UPDATE users SET profile_photo=? WHERE id=?');
        $save->execute([$fileName, $user['id']]);
        if ($oldProfilePhoto) { $oldFile = $storageDir . '/' . basename((string)$oldProfilePhoto); if (is_file($oldFile)) @unlink($oldFile); }
        flash('Foto de perfil atualizada.');
        header('Location: driver.php');
        exit;
    } catch (PDOException $exception) {
        if (!empty($fileName)) { $newFile = __DIR__ . '/storage/.profile-photos/' . basename($fileName); if (is_file($newFile)) @unlink($newFile); }
        $error = 'Não foi possível guardar a foto de perfil. Tente novamente.';
    } catch (RuntimeException $exception) { $error = $exception->getMessage(); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') !== 'profile_photo') {
    verify_csrf();
    $dialogToReopen = ($_POST['action'] ?? '') === 'fuel' ? 'driver-abastecimentos' : 'driver-registo';
    try {
        $newPhotoPath = null;
        $oldPhotoPath = null;
        if (($_POST['action'] ?? '') === 'fuel') {
            $refuelType = trim((string)($_POST['refuel_type'] ?? 'fuel'));
            if (!in_array($refuelType, ['fuel', 'adblue'], true)) throw new RuntimeException('Selecione um tipo de abastecimento válido.');
            $card = null;
            if ($refuelType === 'fuel') {
                $cardId = (int)($_POST['fleet_card_id'] ?? 0);
                $cardQuery = $pdo->prepare('SELECT id,card_reference FROM fleet_cards WHERE id=? AND driver_user_id=? LIMIT 1');
                $cardQuery->execute([$cardId, $user['id']]);
                $card = $cardQuery->fetch();
                if (!$card) throw new RuntimeException('Selecione um cartão de frota atribuído à sua conta.');
            }
            $vehicleId = (int)($_POST['vehicle_id'] ?? 0);
            $vehicleQuery = $pdo->prepare('SELECT DISTINCT vehicles.id,vehicles.registration FROM routes JOIN vehicles ON vehicles.id=routes.vehicle_id WHERE routes.driver_user_id=? AND vehicles.id=? LIMIT 1');
            $vehicleQuery->execute([$user['id'], $vehicleId]);
            $vehicle = $vehicleQuery->fetch();
            if (!$vehicle) throw new RuntimeException('Selecione uma viatura associada às suas rotas.');
            $purchaseDate = trim((string)($_POST['purchase_date'] ?? ''));
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $purchaseDate);
            $dateErrors = DateTimeImmutable::getLastErrors();
            if (!$date || ($dateErrors && ($dateErrors['warning_count'] || $dateErrors['error_count'])) || $date->format('Y-m-d') !== $purchaseDate || $purchaseDate > date('Y-m-d')) throw new RuntimeException('Indique uma data válida, até hoje.');
            $litersInput = str_replace(',', '.', trim((string)($_POST['liters'] ?? '')));
            if (!preg_match('/^\d{1,6}(?:\.\d{1,2})?$/', $litersInput) || (float)$litersInput <= 0) throw new RuntimeException('Indique uma quantidade de litros superior a zero.');
            $amountInput = '0';
            if ($refuelType === 'fuel') {
                $amountInput = str_replace(',', '.', trim((string)($_POST['amount'] ?? '')));
                if (!preg_match('/^\d{1,8}(?:\.\d{1,2})?$/', $amountInput) || (float)$amountInput <= 0) throw new RuntimeException('Indique um valor total superior a zero.');
            }
            $odometerInput = trim((string)($_POST['odometer_km'] ?? ''));
            if ($odometerInput === '' || !ctype_digit($odometerInput) || (int)$odometerInput > 4294967295) throw new RuntimeException('Indique uma quilometragem inteira válida.');
            $details = trim((string)($_POST['details'] ?? ''));
            if (mb_strlen($details, 'UTF-8') > 500) throw new RuntimeException('As observações não podem ultrapassar 500 caracteres.');
            $saveFuel = $pdo->prepare('INSERT INTO fuel_records(driver_user_id,vehicle_id,vehicle_registration,refuel_type,fleet_card_id,card_reference,liters,amount,odometer_km,details,purchase_date) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
            $saveFuel->execute([$user['id'], $vehicle['id'], $vehicle['registration'], $refuelType, $card['id'] ?? null, $card['card_reference'] ?? '—', $litersInput, $amountInput, (int)$odometerInput, $details !== '' ? $details : null, $purchaseDate]);
            flash('Abastecimento registado.');
            header('Location: driver.php');
            exit;
        }
        if (($_POST['action'] ?? '') !== 'journey') throw new RuntimeException('Ação inválida.');
        $routeId = (int)($_POST['route_id'] ?? 0);
        $reportDate = trim((string)($_POST['report_date'] ?? ''));
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $reportDate);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if (!$date || ($dateErrors && ($dateErrors['warning_count'] || $dateErrors['error_count'])) || $date->format('Y-m-d') !== $reportDate) throw new RuntimeException('Indique uma data válida.');
        if ($reportDate !== date('Y-m-d')) throw new RuntimeException('Só pode registar ou alterar a jornada do próprio dia. Os dias anteriores ficam bloqueados.');
        $assigned = $pdo->prepare('SELECT id,vehicle_id FROM routes WHERE id=? AND driver_user_id=? AND vehicle_id IS NOT NULL');
        $assigned->execute([$routeId, $user['id']]);
        $route = $assigned->fetch();
        if (!$route) throw new RuntimeException('A rota selecionada não está atribuída à sua conta ou não tem viatura.');
        $kmInput = trim((string)($_POST['odometer_km'] ?? ''));
        $deliveriesInput = trim((string)($_POST['deliveries_count'] ?? ''));
        $observations = trim((string)($_POST['observations'] ?? ''));
        if ($kmInput === '' || !ctype_digit($kmInput)) throw new RuntimeException('Indique os quilómetros totais da viatura (número inteiro).');
        if ($deliveriesInput === '' || !ctype_digit($deliveriesInput)) throw new RuntimeException('Indique o número de encomendas entregues (número inteiro).');
        if (strlen($observations) > 6000) throw new RuntimeException('As observações não podem ultrapassar 6000 caracteres.');
        $existingPhotoQuery = $pdo->prepare('SELECT pda_photo FROM journeys WHERE driver_user_id=? AND report_date=? LIMIT 1');
        $existingPhotoQuery->execute([$user['id'], $reportDate]);
        $oldPhotoPath = $existingPhotoQuery->fetchColumn() ?: null;
        $photo = $_FILES['pda_photo'] ?? null;
        $hasNewPhoto = is_array($photo) && (int)($photo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        if (!$hasNewPhoto && !$oldPhotoPath) throw new RuntimeException('Anexe uma foto ou captura do PDA para comprovar as encomendas entregues.');
        if ($hasNewPhoto) {
            if ((int)$photo['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Não foi possível receber a foto. Tente uma imagem até 8 MB.');
            if ((int)$photo['size'] < 1 || (int)$photo['size'] > 8 * 1024 * 1024 || !is_uploaded_file($photo['tmp_name'])) throw new RuntimeException('A foto deve ter no máximo 8 MB.');
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($photo['tmp_name']);
            $extensions = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
            if (!isset($extensions[$mime])) throw new RuntimeException('Formato não suportado. Envie uma imagem JPG, PNG ou WebP.');
            $storageDir = __DIR__ . '/storage/.journey-proofs';
            if (!is_dir($storageDir) && !mkdir($storageDir, 0750, true) && !is_dir($storageDir)) throw new RuntimeException('Não foi possível preparar o armazenamento da foto.');
            $fileName = bin2hex(random_bytes(24)) . '.' . $extensions[$mime];
            if (!move_uploaded_file($photo['tmp_name'], $storageDir . '/' . $fileName)) throw new RuntimeException('Não foi possível guardar a foto enviada.');
            $newPhotoPath = $fileName;
        }
        $km = (int)$kmInput;
        $deliveries = (int)$deliveriesInput;
        $latest = $pdo->prepare('SELECT MAX(km) FROM (SELECT odometer_km AS km FROM vehicle_logs WHERE vehicle_id=? AND report_date<? AND odometer_km IS NOT NULL UNION ALL SELECT odometer_km AS km FROM journeys WHERE vehicle_id=? AND report_date<?) readings');
        $latest->execute([$route['vehicle_id'], $reportDate, $route['vehicle_id'], $reportDate]);
        $lastKm = $latest->fetchColumn();
        if ($lastKm !== null && $km < (int)$lastKm) throw new RuntimeException('Os quilómetros não podem ser inferiores ao registo anterior desta viatura (' . number_format((int)$lastKm,0,',',' ') . ' km).');
        $stmt = $pdo->prepare('INSERT INTO journeys(vehicle_id,driver_user_id,route_id,odometer_km,deliveries_count,observations,report_date,pda_photo) VALUES(?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE vehicle_id=VALUES(vehicle_id),route_id=VALUES(route_id),odometer_km=VALUES(odometer_km),deliveries_count=VALUES(deliveries_count),observations=VALUES(observations),pda_photo=COALESCE(VALUES(pda_photo),pda_photo)');
        $stmt->execute([$route['vehicle_id'],$user['id'],$routeId,$km,$deliveries,$observations !== '' ? $observations : null,$reportDate,$newPhotoPath]);
        if ($newPhotoPath && $oldPhotoPath && $oldPhotoPath !== $newPhotoPath) { $oldFile = __DIR__ . '/storage/.journey-proofs/' . basename($oldPhotoPath); if (is_file($oldFile)) @unlink($oldFile); }
        flash('Jornada guardada. Pode voltar a editá-la durante o dia.');
        header('Location: driver.php');
        exit;
    } catch (PDOException $exception) {
        if (!empty($newPhotoPath)) { $newFile = __DIR__ . '/storage/.journey-proofs/' . basename($newPhotoPath); if (is_file($newFile)) @unlink($newFile); }
        $error = ($_POST['action'] ?? '') === 'fuel' ? 'Não foi possível guardar o abastecimento. Confirme os valores e tente novamente.' : 'Não foi possível guardar a jornada. Confirme os valores e tente novamente.';
    } catch (RuntimeException $exception) {
        if (!empty($newPhotoPath)) { $newFile = __DIR__ . '/storage/.journey-proofs/' . basename($newPhotoPath); if (is_file($newFile)) @unlink($newFile); }
        $error = $exception->getMessage();
    }
}

$notice = take_flash();
$routesQuery = $pdo->prepare('SELECT routes.*,vehicles.registration,vehicles.model,vehicles.fuel_type,vehicles.status AS vehicle_status FROM routes LEFT JOIN vehicles ON vehicles.id=routes.vehicle_id WHERE routes.driver_user_id=? ORDER BY departure_date DESC,routes.id DESC');
$routesQuery->execute([$user['id']]);
$routes = $routesQuery->fetchAll();
$reportRoutes = array_values(array_filter($routes, static fn(array $route): bool => $route['vehicle_id'] !== null));
$cardsQuery = $pdo->prepare('SELECT id,provider,card_reference FROM fleet_cards WHERE driver_user_id=? ORDER BY provider,card_reference');
$fuelVehicleQuery = $pdo->prepare('SELECT DISTINCT vehicles.id,vehicles.registration,vehicles.model FROM routes JOIN vehicles ON vehicles.id=routes.vehicle_id WHERE routes.driver_user_id=? ORDER BY vehicles.registration');
$fuelVehicleQuery->execute([$user['id']]);
$fuelVehicles = $fuelVehicleQuery->fetchAll();
$fuelHistoryQuery = $pdo->prepare('SELECT * FROM fuel_records WHERE driver_user_id=? ORDER BY purchase_date DESC,id DESC LIMIT 10');
$fuelHistoryQuery->execute([$user['id']]);
$fuelHistory = $fuelHistoryQuery->fetchAll();
$cardsQuery->execute([$user['id']]);
$cards = $cardsQuery->fetchAll();
$logsQuery = $pdo->prepare('SELECT journeys.*,vehicles.registration FROM journeys JOIN vehicles ON vehicles.id=journeys.vehicle_id WHERE journeys.driver_user_id=? ORDER BY report_date DESC,journeys.id DESC LIMIT 12');
$logsQuery->execute([$user['id']]);
$logs = $logsQuery->fetchAll();
$todayJourneyQuery = $pdo->prepare('SELECT * FROM journeys WHERE driver_user_id=? AND report_date=? LIMIT 1');
$todayJourneyQuery->execute([$user['id'],date('Y-m-d')]);
$todayJourney = $todayJourneyQuery->fetch() ?: [];
$dayStart=(new DateTimeImmutable('today'))->modify('-9 days');
$dayRows=$pdo->prepare("SELECT report_date,SUM(deliveries_count) AS deliveries FROM journeys WHERE driver_user_id=? AND report_date>=? AND report_date<=CURDATE() GROUP BY report_date");
$dayRows->execute([$user['id'],$dayStart->format('Y-m-d')]);
$dailyDeliveryMap=[];
foreach($dayRows->fetchAll() as $dayRow)$dailyDeliveryMap[$dayRow['report_date']]=(int)$dayRow['deliveries'];
$dailyDeliveryChart=[];
for($i=0;$i<10;$i++){$day=$dayStart->modify('+'.$i.' days');$key=$day->format('Y-m-d');$dailyDeliveryChart[]=['label'=>$day->format('d/m'),'key'=>$key,'value'=>$dailyDeliveryMap[$key]??0];}
$maxDailyDeliveries=max(1,...array_column($dailyDeliveryChart,'value'));$profilePhotoQuery=$pdo->prepare('SELECT profile_photo FROM users WHERE id=?');$profilePhotoQuery->execute([$user['id']]);$profilePhoto=$profilePhotoQuery->fetchColumn()?:null;?><!doctype html>
<html lang="pt-PT"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Área do condutor · Perfeição Protagonista</title><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="styles.css"><link rel="stylesheet" href="internal.css?v=20261016-adblue"><script src="internal-ui.js?v=20261016-driver-nav-order" defer></script></head>
<body class="app-body driver-body"><header class="app-header"><a class="brand" href="index.html"><span class="brand-mark app-brand-mark" aria-hidden="true"><svg class="brand-symbol" viewBox="0 0 120 120" xmlns="http://www.w3.org/2000/svg"><path class="brand-back" fill="#101820" fill-rule="evenodd" d="M13 8h48c33 0 51 17 51 44S94 96 61 96H44v20L13 87V8Zm31 28v32h17c14 0 21-5 21-16s-7-16-21-16H44Z"/><path class="brand-front" fill="#18884a" fill-rule="evenodd" d="M7 3h48c33 0 51 17 51 44S88 91 55 91H38v20L7 82V3Zm31 28v32h17c14 0 21-5 21-16s-7-16-21-16H38Z"/></svg></span><span>Perfeição<span class="brand-light"> Protagonista</span><small>ÁREA DO CONDUTOR</small></span></a><div class="app-user"><?php if (user_is_admin($user)): ?><a class="logout-button" href="dashboard.php">Administração ↗</a><?php endif; ?><form method="post" enctype="multipart/form-data" class="driver-avatar-form" title="Adicionar ou alterar foto de perfil"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="profile_photo"><label class="driver-avatar-picker"><?php if ($profilePhoto): ?><img src="profile-photo.php?id=<?= (int)$user['id'] ?>" alt=""><?php else: ?><span aria-hidden="true"><?= e(mb_strtoupper(mb_substr($user['name'],0,1,'UTF-8'),'UTF-8')) ?></span><?php endif; ?><input name="profile_photo" type="file" accept="image/jpeg,image/png,image/webp" aria-label="Adicionar ou alterar foto de perfil" required onchange="this.form.submit()"><i aria-hidden="true">+</i></label></form><span><?= e($user['name']) ?><small><?= user_is_admin($user) ? 'Administrador e condutor' : 'Condutor' ?></small></span><form method="post" action="logout.php"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><button class="logout-button">Terminar sessão ↗</button></form></div></header>
<main class="dashboard driver-dashboard"><section class="driver-welcome"><div><span class="app-kicker">ÁREA PESSOAL DO CONDUTOR</span><h1>A sua operação,<br><em>sempre atualizada.</em></h1><p>Consulte as rotas e registe diariamente os quilómetros, as entregas e as observações da viatura.</p></div><div class="driver-symbol" aria-hidden="true"><svg class="driver-hero-logo" viewBox="0 0 120 120" xmlns="http://www.w3.org/2000/svg"><path class="brand-back" d="M13 8h48c33 0 51 17 51 44S94 96 61 96H44v20L13 87V8Zm31 28v32h17c14 0 21-5 21-16s-7-16-21-16H44Z" fill-rule="evenodd"/><path class="brand-front" d="M7 3h48c33 0 51 17 51 44S88 91 55 91H38v20L7 82V3Zm31 28v32h17c14 0 21-5 21-16s-7-16-21-16H38Z" fill-rule="evenodd"/></svg></div></section>
<?php if ($notice): ?><div class="notice <?= e($notice['kind']) ?>"><?= e($notice['message']) ?></div><?php endif; ?><?php if ($error): ?><div class="notice error"><?= e($error) ?></div><?php endif; ?>

<nav class="driver-shortcuts" aria-label="Atalhos do condutor"><a href="#driver-rotas">As minhas rotas <span>01</span></a><a href="#driver-registo">Registar jornada <span>02</span></a><a href="#driver-historico">Jornadas <span>03</span></a><a href="#driver-abastecimentos">Abastecimentos <span>04</span></a></nav><section class="panel driver-data-section" id="driver-dados"><div class="panel-heading driver-data-heading"><div><span class="app-kicker">ÁREA PESSOAL</span><h2>As suas atribuições</h2></div></div><div class="driver-route-stack"><div class="driver-data-category" id="driver-rotas"><div class="driver-assignment-card"><?php if (!$routes): ?><div class="driver-empty"><span aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3"/><path d="m15 9 4-4"/></svg></span><h3>Ainda não tem rotas atribuídas</h3><p>Quando a administração lhe atribuir uma rota, os detalhes aparecem aqui.</p></div><?php else: ?><div class="driver-route-list"><?php foreach ($routes as $route): ?><article class="driver-route-card"><div class="driver-route-top"><span class="route-date-label">DATA DA ROTA</span><span><?= e(date('d/m/Y', strtotime($route['departure_date']))) ?></span></div><div class="driver-company"><?= e($route['company']) ?></div><div class="driver-location"><small>LOCAL</small><strong><?= e($route['location']) ?></strong></div><div class="route-vehicle"><span class="vehicle-glyph" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M3 6h11v12H3zM14 10h4l3 3v5h-7z"/><circle cx="7.5" cy="18" r="2"/><circle cx="17.5" cy="18" r="2"/></svg></span><span><strong><?= e($route['registration'] ?? 'Sem viatura atribuída') ?></strong><small><?= e(($route['model'] ?? 'Contacte a administração para mais detalhes') . (!empty($route['fuel_type']) ? ' · ' . $route['fuel_type'] : '')) ?></small></span><?php if ($route['vehicle_status']): ?><span class="vehicle-state"><?= e($route['vehicle_status']) ?></span><?php endif; ?></div></article><?php endforeach; ?></div><?php endif; ?><div class="driver-cards-inline" id="driver-cartoes"><div class="panel-heading"><div><span class="app-kicker">ABASTECIMENTO</span><h3>Cartões de frota</h3></div><span class="panel-count"><?= count($cards) ?> <?= count($cards) === 1 ? 'cartão' : 'cartões' ?></span></div><?php if (!$cards): ?><p class="empty-state">Ainda não tem cartões de frota atribuídos. Contacte a administração se precisar de um.</p><?php else: ?><div class="driver-fleet-cards"><?php foreach ($cards as $card): ?><article class="driver-fleet-card"><span class="driver-fleet-card-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h4"/></svg></span><span><small><?= e($card['provider']) ?></small><strong><?= e($card['card_reference']) ?></strong></span><span class="assigned-badge">Atribuído</span></article><?php endforeach; ?></div><?php endif; ?></div></div></div></div></section>
<dialog class="panel driver-panel report-panel driver-action-dialog" id="driver-registo" data-reopen="<?= $dialogToReopen === 'driver-registo' ? 'true' : 'false' ?>"><div class="panel-heading"><div><span class="app-kicker">JORNADA DO DIA</span><h2>Registar jornada</h2></div><span class="report-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg></span></div><?php if (!$reportRoutes): ?><p class="empty-state">Não tem nenhuma rota com viatura atribuída. A administração deverá associar uma para poder registar a jornada.</p><?php else: ?><form method="post" enctype="multipart/form-data" class="compact-form"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="journey"><label>Rota / viatura<select name="route_id" required><?php foreach ($reportRoutes as $route): ?><option value="<?= (int)$route['id'] ?>" <?= (int)($todayJourney['route_id'] ?? 0) === (int)$route['id'] ? 'selected' : '' ?>><?= e($route['company'] . ' · ' . $route['location'] . ' · ' . $route['registration']) ?></option><?php endforeach; ?></select></label><label>Quilómetros totais da viatura<input name="odometer_km" min="0" step="1" value="<?= e((string)($todayJourney['odometer_km'] ?? '')) ?>" placeholder="Ex.: 125400" required></label><label>Data da jornada<input name="report_date" type="date" value="<?= e(date('Y-m-d')) ?>" readonly><small>Só é possível registar ou alterar a jornada no próprio dia.</small></label><label>Encomendas entregues<input name="deliveries_count" type="number" min="0" step="1" value="<?= e((string)($todayJourney['deliveries_count'] ?? '0')) ?>" required></label><label>Foto do PDA <span>(obrigatória · JPG, PNG ou WebP até 8 MB)</span><?php if (!empty($todayJourney['pda_photo'])): ?><a class="journey-proof-link" href="journey-photo-view.php?id=<?= (int)$todayJourney['id'] ?>&amp;from=driver">Ver foto já enviada</a><?php endif; ?><input name="pda_photo" type="file" accept="image/jpeg,image/png,image/webp" <?= empty($todayJourney['pda_photo']) ? 'required' : '' ?>></label><label>Observações <span>(opcional)</span><textarea name="observations" rows="4" maxlength="6000" placeholder="Notas sobre a jornada"><?= e($todayJourney['observations'] ?? '') ?></textarea></label><button class="button button-lime full">Guardar jornada <span>↗</span></button></form><?php endif; ?></dialog></div>
<dialog class="panel driver-panel fuel-driver-panel driver-action-dialog" id="driver-abastecimentos" data-reopen="<?= $dialogToReopen === 'driver-abastecimentos' ? 'true' : 'false' ?>"><div class="panel-heading"><div><span class="app-kicker">REGISTO DE ABASTECIMENTO</span><h2>Registar abastecimento</h2></div><span class="report-icon fuel-driver-type-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 21V5a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v16M3 21h14M7 7h6M18 8l2 2v8a2 2 0 0 0 4 0v-6l-3-3"/><path d="M8 11h4"/></svg></span></div><?php if (!$fuelVehicles): ?><p class="empty-state">Não tem viaturas associadas às suas rotas. Contacte a administração.</p><?php else: ?><form method="post" class="compact-form fuel-driver-form"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="fuel"><div class="form-row"><label class="fuel-type-field">Tipo de abastecimento<select name="refuel_type" class="fuel-refuel-type"><option value="fuel">Gasóleo</option><option value="adblue">AdBlue</option></select></label><label>Viatura<select name="vehicle_id" required><?php foreach($fuelVehicles as $vehicle): ?><option value="<?= (int)$vehicle['id'] ?>"><?= e($vehicle['registration'].' · '.$vehicle['model']) ?></option><?php endforeach; ?></select></label></div><p class="fuel-adblue-note" aria-live="polite" hidden><strong>AdBlue selecionado</strong><span>Não é necessário indicar cartão de frota nem valor.</span></p><div class="form-row"><label class="fuel-card-field">Cartão de frota<select name="fleet_card_id" required><?php if ($cards): foreach($cards as $card): ?><option value="<?= (int)$card['id'] ?>"><?= e($card['provider'].' · '.$card['card_reference']) ?></option><?php endforeach; else: ?><option value="" disabled selected>Sem cartões atribuídos</option><?php endif; ?></select></label><label>Data<input type="date" name="purchase_date" value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>" required></label></div><div class="form-row"><label>Litros<input type="number" name="liters" min="0.01" max="999999.99" step="0.01" placeholder="Ex.: 45,20" required></label><label class="fuel-amount-field">Valor total (€)<input type="number" name="amount" min="0.01" max="99999999.99" step="0.01" placeholder="Ex.: 72,50" required></label></div><label>Quilometragem (km)<input type="number" name="odometer_km" min="0" step="1" placeholder="Ex.: 125400" required></label><label>Observações <span>(opcional)</span><input name="details" maxlength="500" placeholder="Ex.: estação de serviço"></label><button class="button button-lime full fuel-submit-button" type="submit">Guardar abastecimento <span>↗</span></button></form><?php endif; ?></dialog><section class="panel driver-panel driver-deliveries-panel" id="driver-entregas"><div class="panel-heading"><div><span class="app-kicker">PROGRESSO</span><h2>Encomendas entregues</h2></div><span class="panel-count">Últimos 10 dias</span></div><p class="driver-chart-caption">Encomendas entregues por dia nos últimos 10 dias.</p><div class="driver-delivery-chart driver-chart-daily" role="img" aria-label="Encomendas entregues em cada dia dos últimos 10 dias"><?php foreach($dailyDeliveryChart as $point): $height=$point['value']>0?max(5,(int)round($point['value']/$maxDailyDeliveries*100)):0; ?><div class="driver-delivery-month" tabindex="0" title="<?= e(date('d/m/Y',strtotime($point['key']))) ?> · <?= number_format($point['value'],0,',',' ') ?> encomendas"><strong><?= number_format($point['value'],0,',',' ') ?></strong><div class="driver-delivery-track"><span style="height:<?= $height ?>%"></span></div><small><?= e($point['label']) ?></small></div><?php endforeach; ?></div><output class="driver-delivery-detail" aria-live="polite">Selecione ou passe sobre uma barra para consultar o total.</output></section>
<section class="panel driver-panel" id="driver-historico"><div class="panel-heading"><div><span class="app-kicker">HISTÓRICO</span><h2>Jornadas recentes</h2></div><div class="journey-history-actions"><span class="panel-count">Últimos 12 lançamentos</span><button type="button" class="button journey-register-button" onclick="document.getElementById('driver-registo').showModal()">Registar jornada <span>↗</span></button></div></div><?php if (!$logs): ?><p class="empty-state">As jornadas que enviou aparecem aqui.</p><?php else: ?><div class="table-wrap"><table><thead><tr><th>DATA</th><th>VIATURA</th><th>KM TOTAL</th><th>ENCOMENDAS</th><th>FOTO PDA</th><th>OBSERVAÇÕES</th></tr></thead><tbody><?php foreach ($logs as $log): ?><tr><td><?= e(date('d/m/Y', strtotime($log['report_date']))) ?></td><td><strong><?= e($log['registration']) ?></strong></td><td><?= number_format((int)$log['odometer_km'],0,',',' ') ?> km</td><td><?= number_format((int)$log['deliveries_count'],0,',',' ') ?></td><td><?php if (!empty($log['pda_photo'])): ?><a class="journey-proof-link" href="journey-photo-view.php?id=<?= (int)$log['id'] ?>&amp;from=driver">Ver foto</a><?php else: ?>—<?php endif; ?></td><td><?= e($log['observations'] ?? '—') ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
<section class="panel driver-panel fuel-driver-history-panel" id="driver-abastecimentos-historico"><div class="panel-heading"><div><span class="app-kicker">ABASTECIMENTOS</span><h2>Abastecimentos anteriores</h2></div><div class="journey-history-actions"><span class="panel-count"><?= count($fuelHistory) ?> registos</span><button type="button" class="button journey-register-button" onclick="document.getElementById('driver-abastecimentos').showModal()">Adicionar abastecimento <span>↗</span></button></div></div><?php if (!$fuelHistory): ?><p class="empty-state">Ainda não existem abastecimentos registados.</p><?php else: ?><div class="table-wrap"><table><thead><tr><th>DATA</th><th>TIPO</th><th>VIATURA</th><th>KM</th><th>CARTÃO</th><th>LITROS</th><th>VALOR</th></tr></thead><tbody><?php foreach($fuelHistory as $fuel): ?><tr><td><?= e(date('d/m/Y',strtotime($fuel['purchase_date']))) ?></td><td><span class="fuel-type-badge <?= ($fuel['refuel_type'] ?? 'fuel') === 'adblue' ? 'is-adblue' : '' ?>"><?= ($fuel['refuel_type'] ?? 'fuel') === 'adblue' ? 'AdBlue' : 'Gasóleo' ?></span></td><td><?= e($fuel['vehicle_registration']) ?></td><td><?= $fuel['odometer_km'] !== null ? number_format((int)$fuel['odometer_km'],0,',',' ').' km' : '—' ?></td><td><?= ($fuel['refuel_type'] ?? 'fuel') === 'adblue' ? '—' : e($fuel['card_reference']) ?></td><td><?= number_format((float)$fuel['liters'],2,',',' ') ?> L</td><td><?= ($fuel['refuel_type'] ?? 'fuel') === 'adblue' ? '—' : number_format((float)$fuel['amount'],2,',',' ').' €' ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section><footer class="app-footer"><span>Perfeição Protagonista · Área do condutor</span><a href="index.html">Voltar ao site ↗</a></footer></main>
<script>document.querySelectorAll('.driver-delivery-month').forEach(item=>{const show=()=>{const output=document.querySelector('.driver-delivery-detail');if(output)output.textContent=item.title};item.addEventListener('mouseenter',show);item.addEventListener('focus',show);item.addEventListener('click',show)})</script><script>(()=>{const dialog=document.getElementById('driver-abastecimentos');const form=dialog?.querySelector('.fuel-driver-form');if(!dialog||!form)return;const type=form.querySelector('.fuel-refuel-type');const card=form.querySelector('[name="fleet_card_id"]');const amount=form.querySelector('[name="amount"]');const cardField=form.querySelector('.fuel-card-field');const amountField=form.querySelector('.fuel-amount-field');const note=form.querySelector('.fuel-adblue-note');const cardRow=cardField.closest('.form-row');const amountRow=amountField.closest('.form-row');const update=()=>{const adblue=type.value==='adblue';dialog.classList.toggle('is-adblue',adblue);cardField.hidden=adblue;card.disabled=adblue;card.required=!adblue;amountField.hidden=adblue;amount.disabled=adblue;amount.required=!adblue;cardRow.classList.toggle('has-hidden-fuel-field',adblue);amountRow.classList.toggle('has-hidden-fuel-field',adblue);note.hidden=!adblue};type.addEventListener('change',update);update()})()</script></body></html>






