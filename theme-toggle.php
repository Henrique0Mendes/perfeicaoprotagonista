<?php $activeTheme = ($_SESSION['user']['theme'] ?? 'light') === 'dark' ? 'dark' : 'light'; ?>
<form method="post" action="theme.php" class="theme-toggle-form">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="return_to" value="<?= e(basename($_SERVER['SCRIPT_NAME'] ?? 'dashboard.php')) ?>">
  <button class="theme-toggle-button" type="submit" aria-label="Ativar tema <?= $activeTheme === 'dark' ? 'claro' : 'escuro' ?>" aria-pressed="<?= $activeTheme === 'dark' ? 'true' : 'false' ?>" title="Ativar tema <?= $activeTheme === 'dark' ? 'claro' : 'escuro' ?>">
    <?php if ($activeTheme === 'dark'): ?><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M4.93 4.93l1.42 1.42m11.3 11.3 1.42 1.42M2 12h2m16 0h2M4.93 19.07l1.42-1.42m11.3-11.3 1.42-1.42"/></svg><?php else: ?><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.4 15.2A8.5 8.5 0 0 1 8.8 3.6 8.5 8.5 0 1 0 20.4 15.2Z"/></svg><?php endif; ?>
    <span class="theme-toggle-label"><?= $activeTheme === 'dark' ? 'Escuro' : 'Claro' ?></span>
    <span class="theme-toggle-track" aria-hidden="true"><i></i></span>
  </button>
</form>
