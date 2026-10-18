<?php
declare(strict_types=1);

const APP_NAME = 'Perfeição Protagonista — Área reservada';
const DB_CONFIG_PATH = __DIR__ . '/db-config.php';
date_default_timezone_set('Europe/Lisbon');

ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', '1');
}
session_start();

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    if (!is_file(DB_CONFIG_PATH)) {
        http_response_code(503);
        exit('Falta configurar o MySQL. Copie db-config.example.php para db-config.php e introduza os dados da sua base de dados.');
    }
    $settings = require DB_CONFIG_PATH;
    $host = (string)($settings['host'] ?? '127.0.0.1');
    $port = (int)($settings['port'] ?? 3306);
    $database = (string)($settings['database'] ?? 'perfeicao_protagonista');
    $username = (string)($settings['username'] ?? 'root');
    $password = (string)($settings['password'] ?? '');
    $charset = (string)($settings['charset'] ?? 'utf8mb4');
    try {
        $pdo = new PDO("mysql:host={$host};port={$port};dbname={$database};charset={$charset}", $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $exception) {
        http_response_code(503);
        exit('Não foi possível ligar ao MySQL. Confirme se o serviço está ativo, se os dados em db-config.php estão corretos e se a extensão PDO MySQL está ativa no PHP.');
    }
    return $pdo;
}

function ensure_journey_pda_photo_schema(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;
    $column = $pdo->query("SHOW COLUMNS FROM journeys LIKE 'pda_photo'")->fetch();
    if (!$column) $pdo->exec('ALTER TABLE journeys ADD COLUMN pda_photo VARCHAR(255) NULL AFTER observations');
    $checked = true;
}

function ensure_fuel_records_schema(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;
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
    $typeColumn = $pdo->query("SHOW COLUMNS FROM fuel_records LIKE 'refuel_type'")->fetch();
    if (!$typeColumn) $pdo->exec("ALTER TABLE fuel_records ADD COLUMN refuel_type ENUM('fuel','adblue') NOT NULL DEFAULT 'fuel' AFTER vehicle_registration");
    $checked = true;
}

function ensure_user_profile_photo_schema(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;
    $column = $pdo->query("SHOW COLUMNS FROM users LIKE 'profile_photo'")->fetch();
    if (!$column) $pdo->exec('ALTER TABLE users ADD COLUMN profile_photo VARCHAR(255) NULL AFTER email');
    $checked = true;
}

function ensure_vehicle_rental_schema(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;
    $column = $pdo->query("SHOW COLUMNS FROM vehicles LIKE 'is_rented'")->fetch();
    if (!$column) $pdo->exec('ALTER TABLE vehicles ADD COLUMN is_rented TINYINT(1) NOT NULL DEFAULT 0 AFTER fuel_type');
    $checked = true;
}

function ensure_user_roles_schema(PDO $pdo): void
{
    static $checked = false;
    if ($checked) return;
    $column = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch();
    if ($column && !str_contains((string)$column['Type'], "'both'")) {
        $pdo->exec("ALTER TABLE users MODIFY role ENUM('admin','driver','both','operator') NOT NULL DEFAULT 'driver'");
        $pdo->exec("UPDATE users SET role='driver' WHERE role='operator'");
        $pdo->exec("ALTER TABLE users MODIFY role ENUM('admin','driver','both') NOT NULL DEFAULT 'driver'");
    }
    $checked = true;
}

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function verify_csrf(): void
{
    $token = $_POST['csrf'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf'] ?? '', $token)) {
        http_response_code(419);
        exit('O formulário expirou. Volte atrás e tente novamente.');
    }
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function user_is_admin(array $user): bool
{
    return in_array($user['role'] ?? '', ['admin', 'both'], true);
}

function user_is_driver(array $user): bool
{
    return in_array($user['role'] ?? '', ['driver', 'both'], true);
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        header('Location: login.php');
        exit;
    }
    return $user;
}

function require_admin(array $user): void
{
    if (!user_is_admin($user)) {
        http_response_code(403);
        exit('Não tem permissões para esta ação.');
    }
}

function flash(string $message, string $kind = 'success'): void
{
    $_SESSION['flash'] = ['message' => $message, 'kind' => $kind];
}

function take_flash(): ?array
{
    $message = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $message;
}
