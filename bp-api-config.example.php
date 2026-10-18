<?php
declare(strict_types=1);

/*
 * Copie para bp-api-config.php e preencha com credenciais emitidas no portal
 * bp Open Fleet. Nunca publique o ficheiro com as credenciais.
 */
return [
    // Use sandbox até a BP autorizar credenciais de produção.
    'environment' => 'sandbox', // sandbox | production
    'client_id' => '',
    'client_secret' => '',
    // Opcional: restringe a pesquisa à autoridade/conta fornecida pela BP.
    'authority_ids' => [],
    'parent_ids' => [],
];
