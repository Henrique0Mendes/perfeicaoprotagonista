<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
$user = require_login();
require_admin($user);
$pdo = db();
ensure_fuel_records_schema($pdo);
ensure_user_profile_photo_schema($pdo);

$drivers = $pdo->query("SELECT DISTINCT users.id,users.name FROM fuel_records JOIN users ON users.id=fuel_records.driver_user_id ORDER BY users.name")->fetchAll();
$selectedDriverId = filter_input(INPUT_GET, 'driver', FILTER_VALIDATE_INT) ?: 0;
$driverIds = array_map(static fn(array $driver): int => (int)$driver['id'], $drivers);
if ($selectedDriverId && !in_array($selectedDriverId, $driverIds, true)) $selectedDriverId = 0;

$perPage = 20;
$where = $selectedDriverId ? ' WHERE fuel_records.driver_user_id=:driver' : '';
$countQuery = $pdo->prepare('SELECT COUNT(*) FROM fuel_records' . $where);
if ($selectedDriverId) $countQuery->bindValue(':driver', $selectedDriverId, PDO::PARAM_INT);
$countQuery->execute();
$totalRecords = (int)$countQuery->fetchColumn();
$totalPages = max(1, (int)ceil($totalRecords / $perPage));
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1;
$page = max(1, min($totalPages, (int)$page));
$offset = ($page - 1) * $perPage;

$listQuery = $pdo->prepare('SELECT fuel_records.*,users.name AS driver_name,users.profile_photo AS driver_photo FROM fuel_records JOIN users ON users.id=fuel_records.driver_user_id' . $where . ' ORDER BY users.name ASC,purchase_date DESC,fuel_records.id DESC LIMIT :limit OFFSET :offset');
if ($selectedDriverId) $listQuery->bindValue(':driver', $selectedDriverId, PDO::PARAM_INT);
$listQuery->bindValue(':limit', $perPage, PDO::PARAM_INT);
$listQuery->bindValue(':offset', $offset, PDO::PARAM_INT);
$listQuery->execute();
$records = $listQuery->fetchAll();
$recordsByDriver = [];
foreach ($records as $record) $recordsByDriver[$record['driver_user_id']][] = $record;

$driverCountQuery = $pdo->query('SELECT driver_user_id,COUNT(*) AS total,MAX(purchase_date) AS latest FROM fuel_records GROUP BY driver_user_id');
$driverCounts = [];
foreach ($driverCountQuery as $row) $driverCounts[(int)$row['driver_user_id']] = $row;

$recentSql = "SELECT COUNT(*) AS records,COALESCE(SUM(CASE WHEN refuel_type='fuel' THEN liters ELSE 0 END),0) AS liters,COALESCE(SUM(CASE WHEN refuel_type='fuel' THEN amount ELSE 0 END),0) AS amount FROM fuel_records WHERE purchase_date >= CURDATE() - INTERVAL 30 DAY" . ($selectedDriverId ? ' AND driver_user_id=:driver' : '');
$recentQuery = $pdo->prepare($recentSql);
if ($selectedDriverId) $recentQuery->bindValue(':driver', $selectedDriverId, PDO::PARAM_INT);
$recentQuery->execute();
$recent = $recentQuery->fetch() ?: ['records'=>0,'liters'=>0,'amount'=>0];
$recentLiters = (float)$recent['liters'];
$recentAmount = (float)$recent['amount'];
$averagePrice = $recentLiters > 0 ? $recentAmount / $recentLiters : 0;

$today = new DateTimeImmutable('today');
$minDateSql = 'SELECT MIN(purchase_date) FROM fuel_records' . ($selectedDriverId ? ' WHERE driver_user_id=?' : '');
$minDateQuery = $pdo->prepare($minDateSql);
$minDateQuery->execute($selectedDriverId ? [$selectedDriverId] : []);
$firstFuelDate = $minDateQuery->fetchColumn();
$firstFuelYear = $firstFuelDate ? (int)substr((string)$firstFuelDate, 0, 4) : (int)$today->format('Y');
$monthNames = ['jan','fev','mar','abr','mai','jun','jul','ago','set','out','nov','dez'];
$periods = ['day'=>['labels'=>[],'keys'=>[]],'week'=>['labels'=>[],'keys'=>[]],'month'=>['labels'=>[],'keys'=>[]],'year'=>['labels'=>[],'keys'=>[]],'all'=>['labels'=>[],'keys'=>[]]];
for ($i = 13; $i >= 0; $i--) { $date = $today->modify('-'.$i.' days'); $periods['day']['keys'][] = $date->format('Y-m-d'); $periods['day']['labels'][] = $date->format('d/m'); }
$weekStart = $today->modify('monday this week');
for ($i = 11; $i >= 0; $i--) { $date = $weekStart->modify('-'.$i.' weeks'); $periods['week']['keys'][] = $date->format('o-\WW'); $periods['week']['labels'][] = 'S'.$date->format('W').' · '.$date->format('y'); }
$monthStart = $today->modify('first day of this month');
for ($i = 11; $i >= 0; $i--) { $date = $monthStart->modify('-'.$i.' months'); $periods['month']['keys'][] = $date->format('Y-m'); $periods['month']['labels'][] = $monthNames[(int)$date->format('n')-1].' '.$date->format('y'); }
$yearStart = (int)$today->format('Y') - 4;
for ($year = $yearStart; $year <= (int)$today->format('Y'); $year++) { $periods['year']['keys'][] = (string)$year; $periods['year']['labels'][] = (string)$year; }
for ($year = $firstFuelYear; $year <= (int)$today->format('Y'); $year++) { $periods['all']['keys'][] = (string)$year; $periods['all']['labels'][] = (string)$year; }

$chartData = [];
foreach ($periods as $periodName => $period) {
    $chartData[$periodName] = [
        'labels' => $period['labels'],
        'keys' => $period['keys'],
        'liters' => array_fill(0, count($period['keys']), 0),
        'amount' => array_fill(0, count($period['keys']), 0),
    ];
}
$chartRowsSql = "SELECT purchase_date,liters,amount FROM fuel_records WHERE refuel_type='fuel'" . ($selectedDriverId ? ' AND driver_user_id=?' : '');
$chartRows = $pdo->prepare($chartRowsSql);
$chartRows->execute($selectedDriverId ? [$selectedDriverId] : []);
foreach ($chartRows as $row) {
    $date = new DateTimeImmutable((string)$row['purchase_date']);
    $keys = ['day'=>$date->format('Y-m-d'),'week'=>$date->format('o-\WW'),'month'=>$date->format('Y-m'),'year'=>$date->format('Y'),'all'=>$date->format('Y')];
    foreach ($keys as $periodName => $key) {
        $index = array_search($key, $periods[$periodName]['keys'], true);
        if ($index === false) continue;
        $chartData[$periodName]['liters'][$index] += (float)$row['liters'];
        $chartData[$periodName]['amount'][$index] += (float)$row['amount'];
    }
}
$chartJson = json_encode($chartData, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
$notice = take_flash();
$filterNote = $selectedDriverId ? 'Do condutor selecionado' : 'Todos os condutores';

function fuel_initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $letters = array_map(static fn(string $part): string => mb_substr($part, 0, 1, 'UTF-8'), array_slice($parts, 0, 2));
    return mb_strtoupper(implode('', $letters), 'UTF-8');
}
function fuel_unit_price(float $liters, float $amount): string
{
    if ($liters <= 0) return '—';
    return number_format($amount / $liters, 3, ',', ' ') . ' €/L';
}
?><!doctype html>
<html lang="pt-PT"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Abastecimentos · Perfeição Protagonista</title><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="styles.css"><link rel="stylesheet" href="internal.css?v=20261016-adblue"><script src="internal-ui.js" defer></script>
<style>
.fuel-charts{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:1rem;margin:.6rem 0 1.4rem}.fuel-chart{min-width:0}.fuel-chart h2{font-size:1.1rem;margin:.2rem 0}.fuel-chart>p{color:#687780;font-size:.85rem;margin:.25rem 0 1rem}.fuel-bars{width:100%;min-width:0;overflow-x:auto;overscroll-behavior-x:contain;scrollbar-width:thin;scrollbar-color:#b8d2bd transparent;height:220px;display:grid;gap:.45rem;align-items:end;border-bottom:1px solid #d8e0e4;padding:.5rem .35rem 0}.fuel-bars .journey-month{height:100%;display:flex;flex-direction:column;justify-content:end;align-items:center;gap:.45rem;min-width:0}.fuel-bars .journey-bar-track{height:176px;width:100%;display:flex;align-items:end;justify-content:center}.fuel-bars .journey-bar{display:block;width:min(100%,24px);min-height:3px;border-radius:5px 5px 0 0;background:linear-gradient(180deg,#8bd3aa,#18884a)}.fuel-bars .journey-bar.km{background:linear-gradient(180deg,#82939e,#25343d)}.fuel-bars .journey-month small{font-size:.65rem;color:#687780;white-space:nowrap}.fuel-bars .journey-month strong{font-size:.62rem;color:#25343d;line-height:1.1}.journey-chart-key{font-size:.75rem;color:#687780;margin-top:.8rem}.journey-chart-detail{display:block;min-height:18px;margin-top:9px;color:#1d7543;font-size:.78rem;font-weight:700}.journey-month[role=button]{cursor:pointer;border-radius:7px;outline:none}.journey-month[role=button]:hover .journey-bar,.journey-month[role=button]:focus-visible .journey-bar,.journey-month.is-selected .journey-bar{filter:brightness(.88);box-shadow:0 0 0 3px #18884a20}.journey-chart-average{display:flex;align-items:center;justify-content:space-between;gap:10px;margin:10px 0 4px;padding:9px 11px;border:1px solid #e3eee1;border-radius:8px;background:#f5f9f3}.journey-chart-average span{color:#748477;font-size:8px;font-weight:800;letter-spacing:.65px}.journey-chart-average strong{color:#197343;font:750 12px 'Plus Jakarta Sans',sans-serif}.journey-period-bar{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:1.35rem 0 .7rem}.journey-period-bar>span{font-size:8px;font-weight:800;letter-spacing:1px;color:#78877d}.journey-period-bar>div{display:flex;gap:4px;padding:4px;border:1px solid #e2e9e0;border-radius:10px;background:#f7f9f6}.journey-period-bar button{border:0;border-radius:7px;padding:8px 12px;background:transparent;color:#66756b;font:700 9px 'Plus Jakarta Sans',sans-serif;cursor:pointer;white-space:nowrap}.journey-period-bar button:hover{color:#1d7543}.journey-period-bar button.is-active{background:#fff;color:#1b7542;box-shadow:0 1px 4px #18321a12}.journey-driver-list{display:grid;gap:9px}.journey-driver-group{overflow:hidden;border:1px solid #e4eae3;border-radius:11px;background:#fff}.journey-driver-group>summary{display:flex;align-items:center;gap:12px;min-height:68px;padding:12px 15px;list-style:none;cursor:pointer}.journey-driver-group>summary::-webkit-details-marker{display:none}.journey-driver-group>summary:hover,.journey-driver-group[open]>summary{background:#f6f9f5}.journey-driver-avatar{display:grid;place-items:center;flex:none;width:38px;height:38px;border-radius:12px;background:#e8f2e7;color:#237444;font:800 12px 'Plus Jakarta Sans',sans-serif}.journey-driver-name{display:grid;gap:3px;min-width:150px}.journey-driver-name strong{color:#203b2b;font-size:12px}.journey-driver-name small,.journey-driver-latest{color:#7e8a82;font-size:9px}.journey-driver-latest{margin-left:auto}.journey-driver-chevron{display:grid;place-items:center;width:27px;height:27px;border-radius:8px;background:#edf3eb;color:#277447;font-size:17px;transition:transform .18s}.journey-driver-group[open] .journey-driver-chevron{transform:rotate(180deg)}.journey-driver-group .table-wrap{border:0;border-top:1px solid #edf1ed;border-radius:0;margin:0 12px 12px}.fuel-records-table{min-width:860px}@media(max-width:760px){.fuel-charts{grid-template-columns:1fr}}@media(max-width:560px){.journey-period-bar{align-items:flex-start;flex-direction:column}.journey-period-bar>div{width:100%;justify-content:space-between}.journey-driver-latest{display:none}}
</style>
</head>
<body class="app-body"><header class="app-header"><a class="brand" href="index.html"><span class="brand-mark app-brand-mark" aria-hidden="true"><svg class="brand-symbol" viewBox="0 0 120 120" xmlns="http://www.w3.org/2000/svg"><path class="brand-back" fill="#101820" fill-rule="evenodd" d="M13 8h48c33 0 51 17 51 44S94 96 61 96H44v20L13 87V8Zm31 28v32h17c14 0 21-5 21-16s-7-16-21-16H44Z"/><path class="brand-front" fill="#18884a" fill-rule="evenodd" d="M7 3h48c33 0 51 17 51 44S88 91 55 91H38v20L7 82V3Zm31 28v32h17c14 0 21-5 21-16s-7-16-21-16H38Z"/></svg></span><span>Perfeição<span class="brand-light"> Protagonista</span><small>GESTÃO DE OPERAÇÕES</small></span></a><?php require __DIR__ . '/admin-nav.php'; ?><div class="app-user"><span><?= e($user['name']) ?><small>Administrador</small></span><form method="post" action="logout.php"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><button class="logout-button">Terminar sessão ↗</button></form></div></header>
<main class="dashboard admin-dashboard admin-fuel-dashboard">
<section class="ops-welcome driver-welcome"><div><span class="app-kicker">CONTROLO DE ABASTECIMENTOS</span><h1>Abastecimentos,<br><em>com tudo registado.</em></h1><p>Acompanhe litros, custos, cartões BP e o histórico enviado pelos condutores, no mesmo formato das jornadas.</p></div><div class="driver-symbol" aria-hidden="true"><svg class="driver-hero-logo" viewBox="0 0 120 120" xmlns="http://www.w3.org/2000/svg"><path class="brand-back" d="M13 8h48c33 0 51 17 51 44S94 96 61 96H44v20L13 87V8Zm31 28v32h17c14 0 21-5 21-16s-7-16-21-16H44Z" fill-rule="evenodd"/><path class="brand-front" d="M7 3h48c33 0 51 17 51 44S88 91 55 91H38v20L7 82V3Zm31 28v32h17c14 0 21-5 21-16s-7-16-21-16H38Z" fill-rule="evenodd"/></svg></div></section>
<?php if ($notice): ?><div class="notice <?= e($notice['kind']) ?>"><?= e($notice['message']) ?></div><?php endif; ?>
<section class="metric-grid log-metrics">
<article><span>ABASTECIMENTOS · 30 DIAS</span><strong><?= (int)$recent['records'] ?></strong><small><?= e($filterNote) ?></small></article>
<article><span>LITROS · 30 DIAS</span><strong><?= number_format($recentLiters, 1, ',', ' ') ?> L</strong><small>Gasóleo registado</small></article>
<article><span>CUSTO · 30 DIAS</span><strong><?= number_format($recentAmount, 2, ',', ' ') ?> €</strong><small>Gasóleo · AdBlue excluído</small></article>
<article><span>PREÇO MÉDIO</span><strong><?= $recentLiters > 0 ? number_format($averagePrice, 3, ',', ' ').' €' : '—' ?></strong><small>Euro por litro · 30 dias</small></article>
</section>
<section class="panel admin-panel journey-filter fuel-admin-filter"><div class="journey-filter-heading"><div><span class="app-kicker">FILTRO DOS GRÁFICOS</span><h2>Escolha o condutor</h2><p>Os gráficos e o histórico acompanham esta seleção.</p></div></div>
<form method="get" id="fuel-driver-filter" class="journey-filter-selects"><label class="journey-select-field"><span>Condutores</span><select name="driver" id="fuel-driver-select" aria-label="Filtrar por condutor"><option value="0" <?= !$selectedDriverId?'selected':'' ?>>Todos os condutores</option><?php foreach ($drivers as $driver): ?><option value="<?= (int)$driver['id'] ?>" <?= (int)$driver['id']===$selectedDriverId?'selected':'' ?>><?= e($driver['name']) ?></option><?php endforeach; ?></select></label></form>
</section>
<div class="journey-period-bar"><span>PERÍODO DOS GRÁFICOS</span><div role="group" aria-label="Selecionar período"><button type="button" data-period="day" aria-pressed="false">Dia</button><button type="button" data-period="week" aria-pressed="false">Semana</button><button type="button" data-period="month" aria-pressed="true" class="is-active">Mês</button><button type="button" data-period="year" aria-pressed="false">Ano</button><button type="button" data-period="all" aria-pressed="false">Todo o tempo</button></div></div>
<section class="fuel-charts" id="fuel-charts" aria-label="Gráficos de abastecimentos" data-chart="<?= e($chartJson) ?>">
<article class="panel admin-panel fuel-chart"><span class="app-kicker">CONSUMO REGISTADO</span><h2>Litros por mês</h2><p>Litros de gasóleo no período selecionado (AdBlue excluído).</p><div class="journey-chart-average"><span>MÉDIA POR PERÍODO</span><strong>—</strong></div><div class="fuel-bars fuel-liter-bars" role="img" aria-label="Litros abastecidos"></div><output class="journey-chart-detail" aria-live="polite">Clique numa barra para ver o valor exato.</output><div class="journey-chart-key">Soma dos litros de gasóleo · AdBlue excluído</div></article>
<article class="panel admin-panel fuel-chart"><span class="app-kicker">CUSTO DA FROTA</span><h2>Valor por mês</h2><p>Valor total dos abastecimentos no período selecionado.</p><div class="journey-chart-average"><span>MÉDIA POR PERÍODO</span><strong>—</strong></div><div class="fuel-bars fuel-amount-bars" role="img" aria-label="Valor dos abastecimentos"></div><output class="journey-chart-detail" aria-live="polite">Clique numa barra para ver o valor exato.</output><div class="journey-chart-key">Valores de gasóleo · AdBlue excluído</div></article>
</section>
<section class="panel admin-panel logs-table-panel fuel-records-panel" id="fuel-history"><div class="panel-heading logs-heading"><div><span class="app-kicker">HISTÓRICO DE ABASTECIMENTOS</span><h2>Registos por condutor</h2><p>Abra um condutor para consultar a data, o tipo, a viatura e os dados de cada abastecimento.</p></div><span class="panel-count"><?= $totalRecords ?> registos<?= $selectedDriverId ? '' : ' · '.count($drivers).' condutores' ?></span></div>
<?php if (!$records): ?><div class="empty-state"><strong>Ainda não existem abastecimentos</strong><p>Os registos enviados pelos condutores na área pessoal vão aparecer aqui e alimentar os gráficos.</p></div>
<?php else: ?><div class="journey-driver-list"><?php foreach ($recordsByDriver as $driverId => $driverRecords): $driverName = $driverRecords[0]['driver_name']; $totals = $driverCounts[(int)$driverId] ?? ['total'=>count($driverRecords),'latest'=>$driverRecords[0]['purchase_date']]; ?>
<details class="journey-driver-group"<?= $selectedDriverId ? ' open' : '' ?>><summary><?php if (!empty($driverRecords[0]['driver_photo'])): ?><img class="journey-driver-avatar journey-driver-avatar-photo" src="profile-photo.php?id=<?= (int)$driverId ?>" alt=""><?php else: ?><span class="journey-driver-avatar" aria-hidden="true"><?= e(fuel_initials($driverName)) ?></span><?php endif; ?><span class="journey-driver-name"><strong><?= e($driverName) ?></strong><small><?= (int)$totals['total'] ?> <?= (int)$totals['total'] === 1 ? 'abastecimento registado' : 'abastecimentos registados' ?></small></span><span class="journey-driver-latest">Último: <?= e(date('d/m/Y', strtotime((string)$totals['latest']))) ?></span><span class="journey-driver-chevron" aria-hidden="true">⌄</span></summary>
<div class="table-wrap"><table class="fuel-records-table"><thead><tr><th>DATA</th><th>TIPO</th><th>VIATURA</th><th>CARTÃO BP</th><th>LITROS</th><th>VALOR</th><th>€ / L</th><th>KM</th><th>OBSERVAÇÕES</th></tr></thead><tbody>
<?php foreach ($driverRecords as $record): $liters = (float)$record['liters']; $amount = (float)$record['amount']; ?>
<tr><td><?= e(date('d/m/Y', strtotime($record['purchase_date']))) ?></td><td><span class="fuel-type-badge <?= ($record['refuel_type'] ?? 'fuel') === 'adblue' ? 'is-adblue' : '' ?>"><?= ($record['refuel_type'] ?? 'fuel') === 'adblue' ? 'AdBlue' : 'Gasóleo' ?></span></td><td><strong><?= e($record['vehicle_registration']) ?></strong></td><td><?= ($record['refuel_type'] ?? 'fuel') === 'adblue' ? '—' : e($record['card_reference']) ?></td><td><?= number_format($liters, 2, ',', ' ') ?> L</td><td><?= ($record['refuel_type'] ?? 'fuel') === 'adblue' ? '—' : number_format($amount, 2, ',', ' ').' €' ?></td><td><?= ($record['refuel_type'] ?? 'fuel') === 'adblue' ? '—' : e(fuel_unit_price($liters, $amount)) ?></td><td><?= $record['odometer_km'] !== null ? number_format((int)$record['odometer_km'], 0, ',', ' ').' km' : '—' ?></td><td><?= e($record['details'] ?: '—') ?></td></tr>
<?php endforeach; ?></tbody></table></div></details>
<?php endforeach; ?></div>
<?php if ($totalPages > 1): ?><nav class="journey-pagination" aria-label="Páginas dos abastecimentos"><a href="?driver=<?= (int)$selectedDriverId ?>&amp;page=<?= max(1,$page-1) ?>#fuel-history" <?= $page===1?'aria-disabled="true"':'' ?>>Anterior</a><div><?php for ($p = max(1,$page-2); $p <= min($totalPages,$page+2); $p++): ?><a href="?driver=<?= (int)$selectedDriverId ?>&amp;page=<?= $p ?>#fuel-history" <?= $p===$page?'aria-current="page"':'' ?>><?= $p ?></a><?php endfor; ?></div><span>Página <?= $page ?> de <?= $totalPages ?></span><a href="?driver=<?= (int)$selectedDriverId ?>&amp;page=<?= min($totalPages,$page+1) ?>#fuel-history" <?= $page===$totalPages?'aria-disabled="true"':'' ?>>Seguinte</a></nav><?php endif; ?>
<?php endif; ?></section>
<footer class="app-footer"><span>Perfeição Protagonista · Abastecimentos</span><a href="cartoes.php">Cartões de frota ↗</a></footer>
</main>
<script>
const fuelCharts=document.querySelector('.fuel-charts');
const fuelData=fuelCharts?JSON.parse(fuelCharts.dataset.chart||'{}'):{};
const litersFormat=new Intl.NumberFormat('pt-PT',{maximumFractionDigits:1});
const euroFormat=new Intl.NumberFormat('pt-PT',{minimumFractionDigits:2,maximumFractionDigits:2});
let currentPeriod='month';
const periodLabels={day:'dia',week:'semana',month:'mês',year:'ano',all:'ano (todo o tempo)'};
const periodCaps={day:'DIA',week:'SEMANA',month:'MÊS',year:'ANO',all:'ANO'};
function drawFuelChart(selector,key,suffix,isMoney){
  const target=document.querySelector(selector);
  const card=target.closest('.fuel-chart');
  const period=fuelData[currentPeriod]||{labels:[],keys:[],liters:[],amount:[]};
  const values=period[key]||[];
  const labels=period.labels||[];
  const keys=period.keys||[];
  const periodName=periodLabels[currentPeriod];
  target.replaceChildren();
  target.style.gridTemplateColumns='repeat('+Math.max(labels.length,1)+',minmax('+(labels.length>12?'28px':'0')+',1fr))';
  const detail=card.querySelector('.journey-chart-detail');
  detail.textContent='Clique numa barra para ver o valor exato.';
  const format=isMoney?euroFormat:litersFormat;
  const average=values.reduce((sum,value)=>sum+Number(value||0),0)/(values.length||1);
  card.querySelector('.journey-chart-average strong').textContent=format.format(average)+suffix;
  card.querySelector('.journey-chart-average span').textContent='MÉDIA POR '+periodCaps[currentPeriod];
  card.querySelector('h2').textContent=(isMoney?'Valor por ':'Litros por ')+periodName;
  const max=Math.max(1,...values.map(Number));
  labels.forEach((label,index)=>{
    const value=Number(values[index]||0);
    const item=document.createElement('div');
    item.className='journey-month';
    item.title=label+' · '+format.format(value)+suffix;
    item.setAttribute('role','button');
    item.tabIndex=0;
    const amount=document.createElement('strong');
    amount.textContent=isMoney?euroFormat.format(value):litersFormat.format(value);
    const track=document.createElement('div');
    track.className='journey-bar-track';
    const bar=document.createElement('span');
    bar.className='journey-bar'+(isMoney?' km':'');
    bar.style.height=(value?Math.max(5,Math.round(value/max*100)):0)+'%';
    track.append(bar);
    const caption=document.createElement('small');
    caption.textContent=currentPeriod==='day'?new Date((keys[index]||'')+'T12:00:00').toLocaleDateString('pt-PT',{weekday:'short'}):label;
    item.append(amount,track,caption);
    const showExact=()=>{
      target.querySelectorAll('.journey-month.is-selected').forEach(selected=>selected.classList.remove('is-selected'));
      item.classList.add('is-selected');
      detail.textContent=(currentPeriod==='day'?new Date((keys[index]||'')+'T12:00:00').toLocaleDateString('pt-PT'):label)+' · '+format.format(value)+suffix+' ('+periodName+')';
    };
    item.addEventListener('click',showExact);
    item.addEventListener('keydown',event=>{if(event.key==='Enter'||event.key===' '){event.preventDefault();showExact();}});
    target.append(item);
  });
}
function updateFuelCharts(){drawFuelChart('.fuel-liter-bars','liters',' L',false);drawFuelChart('.fuel-amount-bars','amount',' €',true);}
document.querySelectorAll('[data-period]').forEach(button=>button.addEventListener('click',()=>{currentPeriod=button.dataset.period;document.querySelectorAll('[data-period]').forEach(item=>{item.classList.toggle('is-active',item===button);item.setAttribute('aria-pressed',item===button?'true':'false');});updateFuelCharts();}));
document.querySelector('#fuel-driver-select')?.addEventListener('change',()=>document.querySelector('#fuel-driver-filter').submit());
updateFuelCharts();
</script></body></html>
