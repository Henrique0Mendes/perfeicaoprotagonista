<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
$user = require_login();
require_admin($user);
$pdo = db();
ensure_user_roles_schema($pdo);
ensure_fuel_records_schema($pdo);
ensure_user_profile_photo_schema($pdo);
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'route') {
            $company = (string)($_POST['company'] ?? '');
            $location = trim((string)($_POST['location'] ?? ''));
            $driverId = (int)($_POST['driver_user_id'] ?? 0);
            $date = (string)($_POST['departure_date'] ?? '');
            $vehicleId = (int)($_POST['vehicle_id'] ?? 0) ?: null;
            if (!in_array($company, ['CTT','CORREOS EXPRESS','DPD','NACEX'], true) || $location === '' || $driverId < 1 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new RuntimeException('Preencha empresa, local, condutor e data de início.');
            $driverQuery = $pdo->prepare("SELECT name FROM users WHERE id=? AND role IN ('driver','both')");
            $driverQuery->execute([$driverId]);
            $driverName = $driverQuery->fetchColumn();
            if (!$driverName) throw new RuntimeException('Selecione um condutor válido.');
            if ($vehicleId !== null) {
                $available = $pdo->prepare('SELECT status FROM vehicles WHERE id=?');
                $available->execute([$vehicleId]);
                if ($available->fetchColumn() !== 'Disponível') throw new RuntimeException('Escolha uma viatura disponível.');
                $reserved = $pdo->prepare("SELECT COUNT(*) FROM routes WHERE vehicle_id=?");
                $reserved->execute([$vehicleId]);
                if ((int)$reserved->fetchColumn() > 0) throw new RuntimeException('Esta viatura já está atribuída a uma rota ativa.');
            }
            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO routes(company,location,driver,driver_user_id,vehicle_id,departure_date,status) VALUES(?,?,?,?,?,?,'Em curso')")->execute([$company,$location,$driverName,$driverId,$vehicleId,$date]);
            if ($vehicleId !== null) $pdo->prepare("UPDATE vehicles SET status='Em rota' WHERE id=? AND status='Disponível'")->execute([$vehicleId]);
            $pdo->commit();
            flash('Rota adicionada como ativa.');
        } elseif ($action === 'route_delete') {
            $routeId = (int)($_POST['route_id'] ?? 0);
            if ($routeId < 1) throw new RuntimeException('Rota inválida.');
            $routeLookup = $pdo->prepare('SELECT vehicle_id FROM routes WHERE id=?');
            $routeLookup->execute([$routeId]);
            $route = $routeLookup->fetch();
            if (!$route) throw new RuntimeException('Rota não encontrada.');
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM routes WHERE id=?')->execute([$routeId]);
            if ($route['vehicle_id'] !== null) {
                $stillAssigned = $pdo->prepare('SELECT COUNT(*) FROM routes WHERE vehicle_id=?');
                $stillAssigned->execute([$route['vehicle_id']]);
                if ((int)$stillAssigned->fetchColumn() === 0) $pdo->prepare("UPDATE vehicles SET status='Disponível' WHERE id=? AND status='Em rota'")->execute([$route['vehicle_id']]);
            }
            $pdo->commit();
            flash('Rota eliminada. Os registos de jornadas associados foram preservados.');
        } elseif ($action === 'driver') {
            $name = trim((string)($_POST['name'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $accountType = (string)($_POST['account_type'] ?? '');
            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 10 || !in_array($accountType, ['driver','admin','both'], true)) throw new RuntimeException('Indique nome, email válido, tipo de conta e palavra-passe com pelo menos 10 caracteres.');
            $pdo->prepare('INSERT INTO users(name,email,password_hash,role) VALUES(?,?,?,?)')->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT),$accountType]);
            flash($accountType === 'both' ? 'Conta de administrador e condutor criada.' : ($accountType === 'admin' ? 'Conta de administrador criada.' : 'Conta de condutor criada.'));
        } elseif ($action === 'driver_update') {
            $driverId=(int)($_POST['driver_id']??0);$name=trim((string)($_POST['name']??''));$email=trim((string)($_POST['email']??''));$password=(string)($_POST['password']??'');$accountType=(string)($_POST['account_type']??'');
            if($driverId<1||$name===''||!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Indique um nome e um email válidos.');
            if(!in_array($accountType,['driver','admin','both'],true))throw new RuntimeException('Selecione um tipo de conta válido.');
            if($password!==''&&strlen($password)<10)throw new RuntimeException('A nova palavra-passe deve ter pelo menos 10 caracteres.');
            $accountQuery=$pdo->prepare("SELECT role FROM users WHERE id=? AND role IN ('driver','admin','both')");$accountQuery->execute([$driverId]);$accountRole=$accountQuery->fetchColumn();if(!$accountRole)throw new RuntimeException('Conta não encontrada.');
            $wasAdmin=user_is_admin(['role'=>$accountRole]);$willBeAdmin=user_is_admin(['role'=>$accountType]);
            $wasDriver=user_is_driver(['role'=>$accountRole]);$willBeDriver=user_is_driver(['role'=>$accountType]);
            if($wasAdmin&&!$willBeAdmin){if($driverId===(int)($user['id']??0))throw new RuntimeException('Não pode remover o seu próprio acesso de administrador.');$adminCount=(int)$pdo->query("SELECT COUNT(*) FROM users WHERE role IN ('admin','both')")->fetchColumn();if($adminCount<=1)throw new RuntimeException('Não pode remover o acesso do último administrador.');}
            if($wasDriver&&!$willBeDriver){$active=$pdo->prepare('SELECT COUNT(*) FROM routes WHERE driver_user_id=?');$active->execute([$driverId]);if((int)$active->fetchColumn()>0)throw new RuntimeException('Reatribua as rotas ativas antes de remover o acesso de condutor.');}
            if($password!=='')$pdo->prepare('UPDATE users SET name=?,email=?,password_hash=?,role=? WHERE id=?')->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT),$accountType,$driverId]);
            else $pdo->prepare('UPDATE users SET name=?,email=?,role=? WHERE id=?')->execute([$name,$email,$accountType,$driverId]);
            if($wasDriver)$pdo->prepare('UPDATE routes SET driver=? WHERE driver_user_id=?')->execute([$name,$driverId]);
            if($driverId===(int)($user['id']??0)){$_SESSION['user']['name']=$name;$_SESSION['user']['email']=$email;$_SESSION['user']['role']=$accountType;}
            flash('Dados da conta atualizados.');
        } elseif ($action === 'driver_delete') {
            $driverId=(int)($_POST['driver_id']??0);if($driverId<1)throw new RuntimeException('Conta inválida.');
            $accountQuery=$pdo->prepare("SELECT role FROM users WHERE id=? AND role IN ('driver','admin','both')");$accountQuery->execute([$driverId]);$accountRole=$accountQuery->fetchColumn();if(!$accountRole)throw new RuntimeException('Conta não encontrada.');
            if(user_is_admin(['role'=>$accountRole])){$currentAdminId=(int)($user['id']??0);if($driverId===$currentAdminId)throw new RuntimeException('Não pode remover a conta de administrador que está a utilizar.');$adminCount=(int)$pdo->query("SELECT COUNT(*) FROM users WHERE role IN ('admin','both')")->fetchColumn();if($adminCount<=1)throw new RuntimeException('Não pode remover o último administrador.');}
            if(user_is_driver(['role'=>$accountRole])){$active=$pdo->prepare("SELECT COUNT(*) FROM routes WHERE driver_user_id=?");$active->execute([$driverId]);if((int)$active->fetchColumn()>0)throw new RuntimeException('Elimine ou reatribua as rotas deste condutor antes de remover a conta.');}
            $journeys=$pdo->prepare('SELECT COUNT(*) FROM journeys WHERE driver_user_id=?');$journeys->execute([$driverId]);
            $legacyLogs=$pdo->prepare('SELECT COUNT(*) FROM vehicle_logs WHERE driver_user_id=?');$legacyLogs->execute([$driverId]);
            $fuelRecords=$pdo->prepare('SELECT COUNT(*) FROM fuel_records WHERE driver_user_id=?');$fuelRecords->execute([$driverId]);
            if((int)$journeys->fetchColumn()>0||(int)$legacyLogs->fetchColumn()>0||(int)$fuelRecords->fetchColumn()>0)throw new RuntimeException('Esta conta tem jornadas ou registos associados. Não pode ser removida para preservar o histórico.');
            $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$driverId]);flash($accountRole==='admin'?'Conta de administrador removida.':'Conta de condutor removida.');
        } elseif ($action === 'route_assignment_update') {
            $routeId = (int)($_POST['route_id'] ?? 0);
            $driverId = (int)($_POST['driver_user_id'] ?? 0);
            $vehicleId = (int)($_POST['vehicle_id'] ?? 0) ?: null;
            $routeLookup = $pdo->prepare('SELECT vehicle_id FROM routes WHERE id=?');
            $routeLookup->execute([$routeId]);
            $routeBefore = $routeLookup->fetch();
            if (!$routeBefore) throw new RuntimeException('Rota não encontrada.');
            $driverLookup = $pdo->prepare("SELECT name FROM users WHERE id=? AND role IN ('driver','both')");
            $driverLookup->execute([$driverId]);
            $driverName = $driverLookup->fetchColumn();
            if (!$driverName) throw new RuntimeException('Selecione um condutor válido.');
            $oldVehicleId = $routeBefore['vehicle_id'] === null ? null : (int)$routeBefore['vehicle_id'];
            if ($vehicleId !== null && $vehicleId !== $oldVehicleId) {
                $vehicleLookup = $pdo->prepare('SELECT status FROM vehicles WHERE id=?');
                $vehicleLookup->execute([$vehicleId]);
                if ($vehicleLookup->fetchColumn() !== 'Disponível') throw new RuntimeException('A nova viatura não está disponível.');
                $reserved = $pdo->prepare('SELECT COUNT(*) FROM routes WHERE vehicle_id=? AND id<>?');
                $reserved->execute([$vehicleId, $routeId]);
                if ((int)$reserved->fetchColumn() > 0) throw new RuntimeException('A viatura já está atribuída a outra rota ativa.');
            }
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE routes SET driver=?,driver_user_id=?,vehicle_id=? WHERE id=?')->execute([$driverName, $driverId, $vehicleId, $routeId]);
            if ($oldVehicleId !== null && $oldVehicleId !== $vehicleId) {
                $oldStillAssigned = $pdo->prepare('SELECT COUNT(*) FROM routes WHERE vehicle_id=?');
                $oldStillAssigned->execute([$oldVehicleId]);
                if ((int)$oldStillAssigned->fetchColumn() === 0) $pdo->prepare("UPDATE vehicles SET status='Disponível' WHERE id=? AND status='Em rota'")->execute([$oldVehicleId]);
            }
            if ($vehicleId !== null && $vehicleId !== $oldVehicleId) $pdo->prepare("UPDATE vehicles SET status='Em rota' WHERE id=? AND status='Disponível'")->execute([$vehicleId]);
            $pdo->commit();
            flash('Condutor e viatura da rota atualizados.');        } else throw new RuntimeException('Ação inválida.');
        header('Location: routes.php');
        exit;
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = str_contains(strtolower($exception->getMessage()), 'duplicate') ? 'Já existe um registo com esses dados.' : 'Não foi possível guardar. Confirme os dados e tente novamente.';
    } catch (RuntimeException $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $exception->getMessage();
    }
}
$notice = take_flash();
$vehicles = $pdo->query('SELECT * FROM vehicles ORDER BY registration')->fetchAll();
$drivers = $pdo->query("SELECT id,name,email,created_at,profile_photo FROM users WHERE role IN ('driver','both') ORDER BY name")->fetchAll();
$accounts = $pdo->query("SELECT id,name,email,role,created_at,profile_photo FROM users WHERE role IN ('admin','driver','both') ORDER BY role,name")->fetchAll();
$routes = $pdo->query("SELECT routes.*,vehicles.registration,vehicles.model,vehicles.fuel_type,users.name AS driver_name FROM routes LEFT JOIN vehicles ON vehicles.id=routes.vehicle_id LEFT JOIN users ON users.id=routes.driver_user_id ORDER BY departure_date DESC,routes.id DESC")->fetchAll();
$activeRoutes = count($routes);
?><!doctype html>
<html lang="pt-PT"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Rotas e condutores · Perfeição Protagonista</title><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="styles.css"><link rel="stylesheet" href="internal.css?v=20261019-white-admin-nav-v23"><script src="internal-ui.js" defer></script><link rel="icon" type="image/svg+xml" href="favicon.svg?v=2"></head>
<body class="app-body" data-page="routes" data-theme="<?= e(app_theme()) ?>"><header class="app-header"><a class="brand" href="index.html"><span class="brand-mark app-brand-mark" aria-hidden="true"><svg class="brand-symbol" viewBox="0 0 120 120" xmlns="http://www.w3.org/2000/svg"><path class="brand-back" fill="#101820" fill-rule="evenodd" d="M13 8h48c33 0 51 17 51 44S94 96 61 96H44v20L13 87V8Zm31 28v32h17c14 0 21-5 21-16s-7-16-21-16H44Z"/><path class="brand-front" fill="#18884a" fill-rule="evenodd" d="M7 3h48c33 0 51 17 51 44S88 91 55 91H38v20L7 82V3Zm31 28v32h17c14 0 21-5 21-16s-7-16-21-16H38Z"/></svg></span><span>Perfeição<span class="brand-light"> Protagonista</span><small>GESTÃO DE OPERAÇÕES</small></span></a><?php require __DIR__ . '/admin-nav.php'; ?><div class="app-user"><span><?= e($user['name']) ?><small>Administrador</small></span><form method="post" action="logout.php"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><button class="logout-button">Terminar sessão ↗</button></form></div></header>
<main class="dashboard admin-dashboard admin-routes"><section class="ops-welcome driver-welcome"><div><span class="app-kicker">PLANEAMENTO DA OPERAÇÃO</span><h1>As rotas,<br><em>numa vista clara.</em></h1><p>Organize os percursos, atribua condutores e viaturas e acompanhe cada etapa da distribuição.</p></div><div class="driver-symbol" aria-hidden="true"><svg class="driver-hero-logo" viewBox="0 0 120 120" xmlns="http://www.w3.org/2000/svg"><path class="brand-back" d="M13 8h48c33 0 51 17 51 44S94 96 61 96H44v20L13 87V8Zm31 28v32h17c14 0 21-5 21-16s-7-16-21-16H44Z" fill-rule="evenodd"/><path class="brand-front" d="M7 3h48c33 0 51 17 51 44S88 91 55 91H38v20L7 82V3Zm31 28v32h17c14 0 21-5 21-16s-7-16-21-16H38Z" fill-rule="evenodd"/></svg></div></section>
<?php if ($notice): ?><div class="notice <?= e($notice['kind']) ?>"><?= e($notice['message']) ?></div><?php endif; ?><?php if ($error): ?><div class="notice error"><?= e($error) ?></div><?php endif; ?>
<section class="panel admin-panel routes-table-panel route-history-panel" id="admin-rotas"><div class="panel-heading"><div><span class="app-kicker">DISTRIBUIÇÃO</span><h2>Rotas ativas</h2></div><div class="route-panel-actions"><span class="panel-count"><?= count($routes) ?> registos</span><button type="button" class="button button-dark route-add-button" onclick="document.getElementById('route-create-dialog').showModal()">Adicionar <span>＋</span></button></div></div><?php if (!$routes): ?><div class="empty-state"><strong>Ainda não há rotas ativas</strong><p>As rotas dos condutores vão aparecer aqui com o respetivo local.</p></div><?php else: ?><div class="table-wrap"><table class="routes-history-table"><thead><tr><th>LOCAL</th><th>CONDUTOR</th><th>VIATURA</th><th>DATA</th><th>AÇÕES</th></tr></thead><tbody><?php foreach($routes as $route): ?><tr><td><strong><?= e($route['location']) ?></strong><small><?= e($route['company']) ?></small></td><td><strong><?= e($route['driver_name'] ?? $route['driver']) ?></strong></td><td><?php if ($route['registration']): ?><strong><?= e($route['registration']) ?></strong><small><?= e($route['model'].' · '.$route['fuel_type']) ?></small><?php else: ?><span class="route-no-vehicle">Sem viatura registada</span><?php endif; ?></td><td><?= e(date('d/m/Y',strtotime($route['departure_date']))) ?></td><td><div class="driver-row-actions"><button type="button" class="driver-edit-link" onclick="document.getElementById('route-edit-row-<?= (int)$route['id'] ?>').hidden=false">Alterar</button><form method="post" onsubmit="return confirm('Eliminar esta rota? As jornadas associadas serão preservadas.');"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="route_delete"><input type="hidden" name="route_id" value="<?= (int)$route['id'] ?>"><button class="driver-delete-button" type="submit">Eliminar</button></form></div></td></tr><tr class="driver-edit-row" id="route-edit-row-<?= (int)$route['id'] ?>" hidden><td colspan="5"><form method="post" class="route-assignment-edit-form"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="route_assignment_update"><input type="hidden" name="route_id" value="<?= (int)$route['id'] ?>"><label>Condutor<select name="driver_user_id" required><?php foreach($drivers as $driver): ?><option value="<?= (int)$driver['id'] ?>" <?= (int)$driver['id']===(int)$route['driver_user_id']?'selected':'' ?>><?= e($driver['name']) ?></option><?php endforeach; ?></select></label><label>Viatura<select name="vehicle_id"><option value="">Sem viatura</option><?php foreach($vehicles as $vehicle): ?><option value="<?= (int)$vehicle['id'] ?>" <?= (int)$vehicle['id']===(int)$route['vehicle_id']?'selected':'' ?>><?= e($vehicle['registration'].' · '.$vehicle['model']) ?></option><?php endforeach; ?></select></label><button class="button button-dark" type="submit">Guardar <span>↗</span></button></form></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>

<dialog class="route-create-dialog" id="route-create-dialog" aria-labelledby="route-create-title"><div class="route-dialog-heading"><div><span class="app-kicker">PLANEAMENTO</span><h2 id="route-create-title">Adicionar rota</h2></div><button type="button" class="route-dialog-close" aria-label="Fechar" onclick="document.getElementById('route-create-dialog').close()">×</button></div><form method="post" class="compact-form"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="route"><label>Empresa<select name="company" required><option value="">Selecionar empresa</option><?php foreach (['CTT','CORREOS EXPRESS','DPD','NACEX'] as $company): ?><option><?= e($company) ?></option><?php endforeach; ?></select></label><label>Local<input name="location" placeholder="Local de recolha ou distribuição" required maxlength="190"></label><div class="form-row"><label>Condutor<select name="driver_user_id" required><option value="">Selecionar condutor</option><?php foreach ($drivers as $driver): ?><option value="<?= (int)$driver['id'] ?>"><?= e($driver['name']) ?></option><?php endforeach; ?></select></label><label>Data de início<input name="departure_date" type="date" required></label></div><label>Viatura<select name="vehicle_id"><option value="">Sem viatura atribuída</option><?php foreach ($vehicles as $vehicle): if ($vehicle['status'] === 'Disponível'): ?><option value="<?= (int)$vehicle['id'] ?>"><?= e($vehicle['registration'] . ' · ' . $vehicle['model'] . ' · ' . $vehicle['fuel_type']) ?></option><?php endif; endforeach; ?></select></label><div class="route-dialog-actions"><button type="button" class="button route-cancel-button" onclick="document.getElementById('route-create-dialog').close()">Cancelar</button><button class="button button-dark" type="submit">Atribuir rota <span>↗</span></button></div></form></dialog><section class="panel admin-panel drivers-panel" id="admin-condutores"><div class="panel-heading"><h2>Contas de acesso</h2><div class="route-panel-actions"><span class="panel-count"><?= count($accounts) ?> contas</span><button type="button" class="button button-dark route-add-button" onclick="document.getElementById('driver-create-dialog').showModal()">Adicionar <span>＋</span></button></div></div>
<div class="driver-management-layout"><div class="drivers-list-block"><?php if (!$accounts): ?><p class="empty-state"><strong>Ainda não há contas</strong><span>Crie a primeira conta no formulário abaixo.</span></p><?php else: ?><div class="table-wrap"><table class="driver-list-table"><thead><tr><th>NOME</th><th>TIPO DE CONTA</th><th>EMAIL DE ACESSO</th><th>CONTA CRIADA</th><th>AÇÕES</th></tr></thead><tbody><?php foreach ($accounts as $driver): ?><tr><td><span class="driver-account-name"><?php if (!empty($driver['profile_photo'])): ?><img class="driver-account-photo" src="profile-photo.php?id=<?= (int)$driver['id'] ?>" alt="Foto de <?= e($driver['name']) ?>" loading="lazy"><?php else: ?><span class="driver-account-photo driver-account-initial" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($driver['name'],0,1,'UTF-8'),'UTF-8')) ?></span><?php endif; ?><strong><?= e($driver['name']) ?></strong></span></td><td><span class="account-type-badge <?= $driver['role']==='both'?'both':($driver['role']==='admin'?'admin':'driver') ?>"><?= $driver['role']==='both'?'Administrador e condutor':($driver['role']==='admin'?'Administrador':'Condutor') ?></span></td><td><?= e($driver['email']) ?></td><td><?= e(date('d/m/Y', strtotime($driver['created_at']))) ?></td><td class="driver-row-actions"><button type="button" class="driver-edit-link" onclick="document.getElementById('driver-edit-row-<?= (int)$driver['id'] ?>').hidden=false">Alterar</button><form method="post" onsubmit="return confirm('Tem a certeza de que pretende remover esta conta? Contas com jornadas ou rotas ativas não podem ser removidas.');"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="driver_delete"><input type="hidden" name="driver_id" value="<?= (int)$driver['id'] ?>"><button class="driver-delete-button" type="submit">Remover</button></form></td></tr><tr class="driver-edit-row" id="driver-edit-row-<?= (int)$driver['id'] ?>" hidden><td colspan="5"><form method="post" class="driver-edit-form"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="driver_update"><input type="hidden" name="driver_id" value="<?= (int)$driver['id'] ?>"><label>Nome<input name="name" value="<?= e($driver['name']) ?>" required maxlength="150"></label><label>Email<input name="email" type="email" value="<?= e($driver['email']) ?>" required maxlength="190"></label><label>Tipo de conta<select name="account_type" required><option value="driver" <?= $driver['role']==='driver'?'selected':'' ?>>Condutor</option><option value="admin" <?= $driver['role']==='admin'?'selected':'' ?>>Administrador</option><option value="both" <?= $driver['role']==='both'?'selected':'' ?>>Administrador e condutor</option></select></label><label>Nova palavra-passe <small>Deixe em branco para manter a atual</small><input name="password" type="password" minlength="10" autocomplete="new-password"></label><button class="button button-dark" type="submit">Guardar alterações <span>↗</span></button></form></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div>
<div class="driver-create-block" hidden><div class="drivers-subheading"><div><span class="app-kicker">NOVO ACESSO</span><h3>Adicionar utilizador</h3><p>Crie as credenciais para entrar na área reservada.</p></div></div><form method="post" class="compact-form driver-create-form"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="driver"><label>Nome completo<input name="name" required maxlength="150" placeholder="Nome completo"></label><label>Email de acesso<input name="email" type="email" required maxlength="190" placeholder="nome@empresa.pt"></label><label>Palavra-passe inicial <small>(mínimo 10 caracteres)</small><input name="password" type="password" minlength="10" required></label><button class="button button-dark" type="submit">Criar conta <span>↗</span></button></form></div></div></section>
<dialog class="route-create-dialog" id="driver-create-dialog" aria-labelledby="driver-create-title"><div class="route-dialog-heading"><div><span class="app-kicker">NOVO ACESSO</span><h2 id="driver-create-title">Adicionar conta de acesso</h2></div><button type="button" class="route-dialog-close" aria-label="Fechar" onclick="document.getElementById('driver-create-dialog').close()">×</button></div><p class="driver-dialog-intro">Crie as credenciais para entrar na área reservada.</p><form method="post" class="compact-form"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="driver"><label>Nome completo<input name="name" required maxlength="150" placeholder="Nome completo"></label><label>Email de acesso<input name="email" type="email" required maxlength="190" placeholder="nome@empresa.pt"></label><label>Tipo de conta<select name="account_type" required><option value="driver">Condutor</option><option value="admin">Administrador</option><option value="both">Administrador e condutor</option></select></label><label>Palavra-passe inicial <small>(mínimo 10 caracteres)</small><input name="password" type="password" minlength="10" required></label><div class="route-dialog-actions"><button type="button" class="button route-cancel-button" onclick="document.getElementById('driver-create-dialog').close()">Cancelar</button><button class="button button-dark" type="submit">Criar conta <span>↗</span></button></div></form></dialog><footer class="app-footer"><span>Perfeição Protagonista · Rotas e condutores</span><a href="dashboard.php">Voltar à visão geral ↗</a></footer></main></body></html>
