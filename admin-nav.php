<?php
$currentAdminPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
$adminNavigation = [
    ['Visão geral', 'dashboard.php', 'home', '<path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-6v-7h-4v7H4a1 1 0 0 1-1-1z"/>'],
    ['Rotas e condutores', 'routes.php', 'route', '<circle cx="6" cy="18" r="2.5"/><circle cx="18" cy="6" r="2.5"/><path d="M8.5 18h3a3 3 0 0 0 3-3V9a3 3 0 0 1 3-3"/>'],
    ['Jornadas', 'logs.php', 'logs', '<path d="M8 4h11a2 2 0 0 1 2 2v14H7V5a1 1 0 0 1 1-1Z"/><path d="M7 7H4v14h13M11 9h6M11 13h6M11 17h4"/>'],
    ['Abastecimentos', 'fuel-logs.php', 'fuel', '<path d="M8 3h6v13H8z"/><path d="M8 22V3M14 8h2.2A2.8 2.8 0 0 1 19 10.8V18a2 2 0 1 0 2 0V9l-2-2"/><path d="M10 7h2"/>'],
    ['Frota', 'frota.php', 'truck', '<path d="M3 6h11v12H3zM14 10h4l3 3v5h-7z"/><circle cx="7.5" cy="18" r="2"/><circle cx="17.5" cy="18" r="2"/>'],
    ['Cartões', 'cartoes.php', 'card', '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h4"/>'],
];
?>
<nav class="app-navigation" aria-label="Navegação da intranet">
  <?php foreach ($adminNavigation as [$label, $href, $iconName, $iconPath]): $isCurrent = $currentAdminPage === $href; ?>
    <a href="<?= e($href) ?>" class="<?= $isCurrent ? 'is-active' : '' ?>"<?= $isCurrent ? ' aria-current="page"' : '' ?>><svg viewBox="0 0 24 24" aria-hidden="true"><?= $iconPath ?></svg><b><?= e($label) ?></b></a>
  <?php endforeach; ?>
  <?php if (isset($user) && user_is_driver($user)): ?><a href="driver.php"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="3"/><path d="M5 20a7 7 0 0 1 14 0"/></svg><b>Área do condutor</b></a><?php endif; ?>
</nav>
