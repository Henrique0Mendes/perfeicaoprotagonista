<?php
declare(strict_types=1);

/** @return array<string, mixed> */
function bp_fleet_config(): array
{
    $path = __DIR__ . '/bp-api-config.php';
    if (!is_file($path)) {
        throw new RuntimeException('Falta configurar a API BP. Copie bp-api-config.example.php para bp-api-config.php e preencha as credenciais.');
    }
    $config = require $path;
    if (!is_array($config) || trim((string)($config['client_id'] ?? '')) === '' || trim((string)($config['client_secret'] ?? '')) === '') {
        throw new RuntimeException('Configure o client ID e o client secret da BP em bp-api-config.php.');
    }
    return $config;
}

/** @return array{status:int, body:string} */
function bp_fleet_http(string $url, array $headers, ?string $formBody = null): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('A extensão cURL do PHP é necessária para ligar à API BP.');
    }
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    if ($formBody !== null) {
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $formBody);
    }
    $body = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);
    if ($body === false) {
        throw new RuntimeException('Não foi possível contactar a API BP' . ($curlError !== '' ? ': ' . $curlError : '.') );
    }
    return ['status' => $status, 'body' => (string)$body];
}

/**
 * Importa os cartões alterados no período pedido (a API BP limita a janela a 90 dias).
 * Mantém as atribuições aos condutores já feitas nesta aplicação.
 *
 * @return array{imported:int, ignored:int}
 */
function bp_fleet_import_cards(PDO $pdo, string $startDate): array
{
    $config = bp_fleet_config();
    $environment = (string)($config['environment'] ?? 'sandbox');
    if (!in_array($environment, ['sandbox', 'production'], true)) {
        throw new RuntimeException('O ambiente da API BP deve ser sandbox ou production.');
    }
    $base = $environment === 'production' ? 'https://api.fleet.bp.com' : 'https://api.sandbox.fleet.bp.com';
    $today = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $start = DateTimeImmutable::createFromFormat('!Y-m-d', $startDate, new DateTimeZone('UTC'));
    $dateErrors = DateTimeImmutable::getLastErrors();
    if (!$start || ($dateErrors !== false && ($dateErrors['warning_count'] || $dateErrors['error_count'])) || $start->format('Y-m-d') !== $startDate) {
        throw new RuntimeException('A data inicial indicada não é válida.');
    }
    if ($start > $today || $start < $today->modify('-89 days')) {
        throw new RuntimeException('A BP só permite pesquisar períodos até 90 dias. Escolha uma data entre ' . $today->modify('-89 days')->format('d/m/Y') . ' e hoje.');
    }

    $tokenResponse = bp_fleet_http($base . '/authentication/v1.0/token', [
        'Accept: application/json',
        'Content-Type: application/x-www-form-urlencoded',
    ], http_build_query([
        'grant_type' => 'client_credentials',
        'client_id' => (string)$config['client_id'],
        'client_secret' => (string)$config['client_secret'],
    ], '', '&', PHP_QUERY_RFC3986));
    $tokenData = json_decode($tokenResponse['body'], true);
    if ($tokenResponse['status'] < 200 || $tokenResponse['status'] >= 300 || !is_array($tokenData) || empty($tokenData['access_token'])) {
        throw new RuntimeException('A autenticação na BP falhou. Confirme as credenciais, o ambiente e as permissões concedidas pela BP.');
    }
    $token = (string)$tokenData['access_token'];

    $import = $pdo->prepare("INSERT INTO fleet_cards(provider, card_reference) VALUES('BP', ?) ON DUPLICATE KEY UPDATE provider='BP'");
    $imported = 0;
    $ignored = 0;
    $page = 1;
    do {
        $query = [
            'PageSize' => 1000,
            'Page' => $page,
            'StartDateTime' => $start->format('Y-m-d\T00:00:00\Z'),
            'EndDateTime' => $today->format('Y-m-d\TH:i:s\Z'),
        ];
        foreach (['authority_ids' => 'AuthorityIds', 'parent_ids' => 'ParentIds'] as $key => $parameter) {
            $values = $config[$key] ?? [];
            if (is_array($values) && $values !== []) $query[$parameter] = implode(',', array_map('strval', $values));
        }
        $url = $base . '/card-management/v1.0/cards?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $response = bp_fleet_http($url, ['Accept: application/json', 'Authorization: Bearer ' . $token]);
        $data = json_decode($response['body'], true);
        if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($data)) {
            if ($response['status'] === 401 || $response['status'] === 403) {
                throw new RuntimeException('A BP recusou a consulta. Confirme se o client ID tem acesso de produção à gestão de cartões.');
            }
            if ($response['status'] === 429) throw new RuntimeException('A API BP atingiu o limite de pedidos. Aguarde um minuto e tente novamente.');
            throw new RuntimeException('A consulta dos cartões na BP falhou (HTTP ' . $response['status'] . '). Confirme o período e as permissões da conta.');
        }
        $cards = $data['cards'] ?? [];
        if (!is_array($cards)) $cards = [];
        foreach ($cards as $card) {
            if (!is_array($card)) { $ignored++; continue; }
            $reference = trim((string)($card['fullCardNumber'] ?? ''));
            if ($reference === '') $reference = trim((string)($card['cardSerialNumber'] ?? ''));
            if ($reference === '' || strlen($reference) > 80) { $ignored++; continue; }
            $import->execute([$reference]);
            if ($import->rowCount() > 0) $imported++;
        }
        $more = !empty($data['moreCardsAvailable']);
        $page++;
        if ($page > 100) throw new RuntimeException('A importação foi interrompida por exceder 100 páginas. Reduza o período e tente novamente.');
    } while ($more);

    return ['imported' => $imported, 'ignored' => $ignored];
}
