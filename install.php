<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

$pdo = db();
$pdo->exec("CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    profile_photo VARCHAR(255) NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin','driver','both') NOT NULL DEFAULT 'driver',
    theme ENUM('light','dark') NOT NULL DEFAULT 'light',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
ensure_user_theme_schema($pdo);
$pdo->exec("CREATE TABLE IF NOT EXISTS vehicles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    registration VARCHAR(20) NOT NULL UNIQUE,
    model VARCHAR(100) NOT NULL,
    fuel_type ENUM('Gasóleo','Elétrico') NOT NULL DEFAULT 'Gasóleo',
    is_rented TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('Disponível','Em rota','Manutenção') NOT NULL DEFAULT 'Disponível',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE IF NOT EXISTS fleet_cards (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    provider VARCHAR(80) NOT NULL,
    card_reference VARCHAR(80) NOT NULL UNIQUE,
    driver_user_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_fleet_cards_driver (driver_user_id),
    CONSTRAINT fk_fleet_cards_driver FOREIGN KEY(driver_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("UPDATE fleet_cards SET provider='BP'");
$pdo->exec("CREATE TABLE IF NOT EXISTS fuel_records (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    driver_user_id BIGINT UNSIGNED NOT NULL,
    vehicle_id BIGINT UNSIGNED NULL,
    vehicle_registration VARCHAR(20) NOT NULL,
    refuel_type ENUM('fuel','adblue') NOT NULL DEFAULT 'fuel',
    fleet_card_id BIGINT UNSIGNED NULL,
    card_reference VARCHAR(80) NOT NULL,
    liters DECIMAL(8,2) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    odometer_km INT UNSIGNED NULL,
    details VARCHAR(500) NULL,
    purchase_date DATE NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_fuel_records_driver_date (driver_user_id, purchase_date),
    INDEX idx_fuel_records_date (purchase_date),
    CONSTRAINT fk_fuel_records_driver FOREIGN KEY(driver_user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_fuel_records_vehicle FOREIGN KEY(vehicle_id) REFERENCES vehicles(id) ON DELETE SET NULL,
    CONSTRAINT fk_fuel_records_card FOREIGN KEY(fleet_card_id) REFERENCES fleet_cards(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");


$pdo->exec("CREATE TABLE IF NOT EXISTS routes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    origin VARCHAR(190) NULL,
    destination VARCHAR(190) NULL,
    company VARCHAR(50) NOT NULL DEFAULT 'Não definida',
    location VARCHAR(190) NOT NULL,
    driver VARCHAR(150) NOT NULL,
    driver_user_id BIGINT UNSIGNED NULL,
    vehicle_id BIGINT UNSIGNED NULL,
    departure_date DATE NOT NULL,
    status ENUM('Planeada','Em curso','Concluída') NOT NULL DEFAULT 'Planeada',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_routes_driver_status (driver_user_id, status),
    INDEX idx_routes_vehicle_status (vehicle_id, status),
    INDEX idx_routes_departure (departure_date),
    CONSTRAINT fk_routes_driver FOREIGN KEY(driver_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_routes_vehicle FOREIGN KEY(vehicle_id) REFERENCES vehicles(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// Converte contas de operador da versão anterior em condutores.
$pdo->exec("ALTER TABLE users MODIFY role ENUM('admin','operator','driver','both') NOT NULL DEFAULT 'driver'");
$pdo->exec("UPDATE users SET role='driver' WHERE role='operator'");
$pdo->exec("ALTER TABLE users MODIFY role ENUM('admin','driver','both') NOT NULL DEFAULT 'driver'");

$routeColumns = $pdo->query("SHOW COLUMNS FROM routes LIKE 'driver_user_id'")->fetch();
if (!$routeColumns) {
    $pdo->exec("ALTER TABLE routes
        ADD driver_user_id BIGINT UNSIGNED NULL AFTER driver,
        ADD INDEX idx_routes_driver_status (driver_user_id, status),
        ADD CONSTRAINT fk_routes_driver FOREIGN KEY(driver_user_id) REFERENCES users(id) ON DELETE SET NULL");
}
$companyColumn = $pdo->query("SHOW COLUMNS FROM routes LIKE 'company'")->fetch();
if (!$companyColumn) {
    $pdo->exec("ALTER TABLE routes ADD company VARCHAR(50) NOT NULL DEFAULT 'Não definida' AFTER destination");
}
$locationColumn = $pdo->query("SHOW COLUMNS FROM routes LIKE 'location'")->fetch();
if (!$locationColumn) {
    $pdo->exec("ALTER TABLE routes MODIFY origin VARCHAR(190) NULL, MODIFY destination VARCHAR(190) NULL, ADD location VARCHAR(190) NULL AFTER company");
    $pdo->exec("UPDATE routes SET location=CASE WHEN origin IS NULL OR origin='' THEN COALESCE(destination,'A definir') WHEN destination IS NULL OR destination='' OR origin=destination THEN origin ELSE LEFT(CONCAT(origin,' → ',destination),190) END WHERE location IS NULL");
    $pdo->exec("ALTER TABLE routes MODIFY location VARCHAR(190) NOT NULL");
}
$vehicleFuelColumn = $pdo->query("SHOW COLUMNS FROM vehicles LIKE 'fuel_type'")->fetch();
if (!$vehicleFuelColumn) {
    $pdo->exec("ALTER TABLE vehicles ADD fuel_type ENUM('Gasóleo','Elétrico') NOT NULL DEFAULT 'Gasóleo' AFTER model");
}
$vehicleRentalColumn = $pdo->query("SHOW COLUMNS FROM vehicles LIKE 'is_rented'")->fetch();
if (!$vehicleRentalColumn) {
    $pdo->exec('ALTER TABLE vehicles ADD is_rented TINYINT(1) NOT NULL DEFAULT 0 AFTER fuel_type');
}
$pdo->exec("UPDATE routes r JOIN users u ON LOWER(r.driver)=LOWER(u.name) SET r.driver_user_id=u.id WHERE r.driver_user_id IS NULL AND u.role IN ('driver','both')");

$pdo->exec("CREATE TABLE IF NOT EXISTS vehicle_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    vehicle_id BIGINT UNSIGNED NOT NULL,
    driver_user_id BIGINT UNSIGNED NOT NULL,
    route_id BIGINT UNSIGNED NULL,
    kind ENUM('km','abastecimento','revisao','dano') NOT NULL,
    odometer_km INT UNSIGNED NULL,
    fuel_liters DECIMAL(8,2) NULL,
    amount DECIMAL(10,2) NULL,
    details TEXT NULL,
    report_date DATE NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_vehicle_logs_vehicle_date (vehicle_id, report_date),
    INDEX idx_vehicle_logs_driver_date (driver_user_id, report_date),
    CONSTRAINT fk_vehicle_logs_vehicle FOREIGN KEY(vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
    CONSTRAINT fk_vehicle_logs_driver FOREIGN KEY(driver_user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_vehicle_logs_route FOREIGN KEY(route_id) REFERENCES routes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");


$pdo->exec("CREATE TABLE IF NOT EXISTS journeys (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    vehicle_id BIGINT UNSIGNED NOT NULL,
    driver_user_id BIGINT UNSIGNED NOT NULL,
    route_id BIGINT UNSIGNED NULL,
    odometer_km INT UNSIGNED NOT NULL,
    deliveries_count INT UNSIGNED NOT NULL DEFAULT 0,
    incidents_count INT UNSIGNED NOT NULL DEFAULT 0,
    pickups_count INT UNSIGNED NOT NULL DEFAULT 0,
    observations TEXT NULL,
    pda_photo VARCHAR(255) NULL,
    report_date DATE NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_journeys_driver_day (driver_user_id, report_date),
    INDEX idx_journeys_vehicle_date (vehicle_id, report_date),
    INDEX idx_journeys_date (report_date),
    CONSTRAINT fk_journeys_vehicle FOREIGN KEY(vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
    CONSTRAINT fk_journeys_driver FOREIGN KEY(driver_user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_journeys_route FOREIGN KEY(route_id) REFERENCES routes(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$count = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$error = '';
if ($count > 0) {
    header('Location: login.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $name = trim((string)($_POST['name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 10) {
        $error = 'Preencha o nome, indique um email válido e use uma palavra-passe com pelo menos 10 caracteres.';
    } else {
        $stmt = $pdo->prepare('INSERT INTO users (name,email,password_hash,role) VALUES (?,?,?,?)');
        $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), 'admin']);
        flash('Administrador criado. Já pode iniciar sessão.');
        header('Location: login.php');
        exit;
    }
}
?><!doctype html><html lang="pt-PT"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Configurar acesso · Perfeição Protagonista</title><link rel="stylesheet" href="styles.css"><link rel="stylesheet" href="internal.css?v=20261019-dark-theme-v11"><link rel="icon" type="image/svg+xml" href="favicon.svg?v=2"></head><body class="app-body"><main class="auth-shell"><a class="brand" href="index.html"><span class="brand-mark app-brand-mark" aria-hidden="true"><svg class="brand-symbol" viewBox="0 0 120 120" xmlns="http://www.w3.org/2000/svg"><path class="brand-back" fill="#101820" fill-rule="evenodd" d="M13 8h48c33 0 51 17 51 44S94 96 61 96H44v20L13 87V8Zm31 28v32h17c14 0 21-5 21-16s-7-16-21-16H44Z"/><path class="brand-front" fill="#18884a" fill-rule="evenodd" d="M7 3h48c33 0 51 17 51 44S88 91 55 91H38v20L7 82V3Zm31 28v32h17c14 0 21-5 21-16s-7-16-21-16H38Z"/></svg></span><span>Perfeição<span class="brand-light"> Protagonista</span><small>PUBLICIDADE & ENCOMENDAS</small></span></a><section class="auth-card"><span class="app-kicker">CONFIGURAÇÃO INICIAL</span><h1>Criar administrador</h1><p>Crie a primeira conta para começar a gerir a frota e as rotas.</p><?php if ($error): ?><div class="notice error"><?= e($error) ?></div><?php endif; ?><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><label>Nome<input name="name" required autocomplete="name" value="<?= e($_POST['name'] ?? '') ?>"></label><label>Email<input name="email" type="email" required autocomplete="email" value="<?= e($_POST['email'] ?? '') ?>"></label><label>Palavra-passe <small>(mínimo 10 caracteres)</small><input name="password" type="password" required minlength="10" autocomplete="new-password"></label><button class="button button-lime full" type="submit">Criar administrador <span>↗</span></button></form><a class="auth-back" href="index.html">← Voltar ao site</a></section><p class="auth-foot">A configuração inicial fecha automaticamente depois de criar a primeira conta.</p></main></body></html>







