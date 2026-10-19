(() => {
  const body = document.body;
  const header = document.querySelector('.app-header');
  const main = document.querySelector('main.dashboard');
  if (!header || !main) return;

  const isAdmin = main.classList.contains('admin-dashboard');
  const isDriver = main.classList.contains('driver-dashboard');
  const isFleet = main.classList.contains('fleet-dashboard') && !isAdmin;
  const icons = {
    home: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-6v-7h-4v7H4a1 1 0 0 1-1-1z"/></svg>',
    route: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="6" cy="18" r="2.5"/><circle cx="18" cy="6" r="2.5"/><path d="M8.5 18h3a3 3 0 0 0 3-3V9a3 3 0 0 1 3-3"/></svg>',
    users: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M20 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>',
    logs: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 4h11a2 2 0 0 1 2 2v14H7V5a1 1 0 0 1 1-1Z"/><path d="M7 7H4v14h13M11 9h6M11 13h6M11 17h4"/></svg>',
    truck: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h11v12H3zM14 10h4l3 3v5h-7z"/><circle cx="7.5" cy="18" r="2"/><circle cx="17.5" cy="18" r="2"/></svg>',
    card: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h4"/></svg>',
    fuel: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 3h6v13H8z"/><path d="M8 22V3M14 8h2.2A2.8 2.8 0 0 1 19 10.8V18a2 2 0 1 0 2 0V9l-2-2"/><path d="M10 7h2"/></svg>',
    plus: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>'
  };
  let items = [];
  if (isAdmin) items = [
    ['Visão geral', 'dashboard.php', 'home'],
    ['Rotas e condutores', 'routes.php', 'route'],
    ['Jornadas', 'logs.php', 'logs'],
    ['Abastecimentos', 'fuel-logs.php', 'fuel'],
    ['Frota', 'frota.php', 'truck'],
    ['Cartões', 'cartoes.php', 'card']
  ];
  if (isDriver) items = [
    ['Início', 'driver.php', 'home'],
    ['Registar jornada', 'driver.php#driver-registo', 'plus'],
    ['Registar abastecimento', 'driver.php#driver-abastecimentos', 'fuel']
  ];
  if (isFleet) items = [
    ['Painel', 'dashboard.php', 'home'],
    ['Rotas e condutores', 'routes.php', 'route'],
    ['Abastecimentos', 'fuel-logs.php', 'fuel'],
    ['Frota', 'frota.php', 'truck'],
    ['Cartões', 'cartoes.php', 'card']
  ];

  if (items.length && !header.querySelector('.app-navigation')) {
    const nav = document.createElement('nav');
    nav.className = 'app-navigation';
    nav.setAttribute('aria-label', 'Navegação da intranet');
    items.forEach(([label, href, icon], index) => {
      const link = document.createElement('a');
      link.href = href;
      link.innerHTML = `${icons[icon]}<b>${label}</b>`;
      const current = location.pathname.toLowerCase();
      const [targetPage, targetAnchor] = href.toLowerCase().split('#');
      const pageMatches = current.endsWith(targetPage);
      const anchorMatches = targetAnchor ? location.hash === `#${targetAnchor}` : !location.hash;
      if (pageMatches && anchorMatches) link.classList.add('is-active');
      nav.appendChild(link);
    });
    const user = header.querySelector('.app-user');
    header.insertBefore(nav, user || null);
  }

  if (isDriver) {
    const profileTrigger = header.querySelector('.driver-avatar-open');
    const profileDialog = document.getElementById('driver-profile-dialog');
    const clearProfilePasswords = () => profileDialog?.querySelectorAll('input[type="password"]').forEach((input) => { input.value = ''; });
    profileTrigger?.addEventListener('click', () => {
      if (profileDialog instanceof HTMLDialogElement && !profileDialog.open) {
        clearProfilePasswords();
        profileDialog.showModal();
      }
    });
    profileDialog?.querySelector('#driver-profile-photo')?.addEventListener('change', (event) => {
      const input = event.currentTarget;
      const label = profileDialog.querySelector('.driver-photo-copy small');
      if (label && input instanceof HTMLInputElement) {
        const file = input.files?.[0];
        label.textContent = file?.name || 'JPG, PNG ou WebP · máximo 8 MB';
        if (file) {
          let preview = profileDialog.querySelector('.driver-profile-preview');
          if (!(preview instanceof HTMLImageElement)) {
            const image = document.createElement('img');
            image.className = 'driver-profile-preview';
            image.alt = 'Pré-visualização da nova foto';
            preview?.replaceWith(image);
            preview = image;
          }
          if (preview.dataset.previewUrl) URL.revokeObjectURL(preview.dataset.previewUrl);
          preview.dataset.previewUrl = URL.createObjectURL(file);
          preview.src = preview.dataset.previewUrl;
        }
      }
    });

    document.querySelectorAll('.app-navigation a[href*="#driver-registo"],.app-navigation a[href*="#driver-abastecimentos"],.driver-shortcuts a[href*="#driver-registo"],.driver-shortcuts a[href*="#driver-abastecimentos"]').forEach((link) => {
      link.setAttribute('aria-haspopup', 'dialog');
      link.addEventListener('click', (event) => {
        event.preventDefault();
        const dialogId = link.href.includes('#driver-registo') ? 'driver-registo' : 'driver-abastecimentos';
        const dialog = document.getElementById(dialogId);
        if (dialog instanceof HTMLDialogElement && !dialog.open) dialog.showModal();
      });
    });

    main.querySelectorAll('dialog.driver-action-dialog').forEach((dialog) => {
      dialog.classList.add('is-in-view');
      const closeButton = document.createElement('button');
      closeButton.type = 'button';
      closeButton.className = 'driver-dialog-close';
      closeButton.setAttribute('aria-label', 'Fechar janela');
      closeButton.textContent = '×';
      dialog.querySelector('.panel-heading')?.appendChild(closeButton);
      closeButton.addEventListener('click', () => dialog.close());
      dialog.querySelectorAll('[data-dialog-close]').forEach((button) => button.addEventListener('click', () => dialog.close()));
      dialog.addEventListener('click', (event) => {
        if (event.target === dialog) dialog.close();
      });
      if (dialog.dataset.reopen === 'true') {
        const errorNotice = main.querySelector('.notice.error');
        if (errorNotice) dialog.prepend(errorNotice.cloneNode(true));
        if (dialog === profileDialog) clearProfilePasswords();
        dialog.showModal();
      }
    });
  }

  body.classList.add('motion-ready');
  const animated = main.querySelectorAll('.dashboard-heading,.driver-welcome,.fleet-welcome,.metric-grid,.panel,.admin-shortcuts,.driver-shortcuts');
  if (!('IntersectionObserver' in window) || matchMedia('(prefers-reduced-motion: reduce)').matches) {
    animated.forEach((item) => item.classList.add('is-in-view'));
    return;
  }
  const observer = new IntersectionObserver((entries, currentObserver) => {
    entries.forEach((entry) => {
      if (!entry.isIntersecting) return;
      entry.target.classList.add('is-in-view');
      currentObserver.unobserve(entry.target);
    });
  }, { threshold: 0.08, rootMargin: '0px 0px -24px 0px' });
  animated.forEach((item, index) => {
    item.classList.add('pp-reveal');
    item.style.setProperty('--reveal-order', Math.min(index % 5, 4));
    observer.observe(item);
  });
})();





