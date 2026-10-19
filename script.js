const menuButton = document.querySelector('.menu-toggle');
const navigation = document.querySelector('.nav-links');

const setMenuOpen = (isOpen) => {
  navigation?.classList.toggle('open', isOpen);
  menuButton?.setAttribute('aria-expanded', String(isOpen));
  menuButton?.setAttribute('aria-label', isOpen ? 'Fechar menu' : 'Abrir menu');
};

menuButton?.addEventListener('click', () => {
  setMenuOpen(!navigation?.classList.contains('open'));
});

navigation?.querySelectorAll('a').forEach((link) => {
  link.addEventListener('click', () => setMenuOpen(false));
});

document.querySelector('#year')?.replaceChildren(String(new Date().getFullYear()));
document.addEventListener('keydown', (event) => {
  if (event.key === 'Escape' && navigation?.classList.contains('open')) {
    setMenuOpen(false);
    menuButton?.focus();
  }
});

// Motion and small interaction enhancements for the public homepage.
const siteHeader = document.querySelector('.site-header');
let lastScrollY = window.scrollY;
const syncHeader = () => {
  if (!siteHeader) return;
  const currentScrollY = window.scrollY;
  siteHeader.classList.toggle('is-scrolled', currentScrollY > 12);

  if (currentScrollY <= 100) {
    siteHeader.classList.remove('is-hidden');
  } else if (Math.abs(currentScrollY - lastScrollY) > 4) {
    const menuIsOpen = navigation?.classList.contains('open');
    siteHeader.classList.toggle('is-hidden', !menuIsOpen);
  }

  lastScrollY = currentScrollY;
};
syncHeader();
window.addEventListener('scroll', syncHeader, { passive: true });

const revealItems = document.querySelectorAll('.stats-strip, .section-heading, .service-card, .process-step, .faq-item, .contact-card, footer');
if ('IntersectionObserver' in window && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
  const revealObserver = new IntersectionObserver((entries, observer) => {
    entries.forEach((entry) => {
      if (!entry.isIntersecting) return;
      entry.target.classList.add('is-visible');
      observer.unobserve(entry.target);
    });
  }, { threshold: 0.12, rootMargin: '0px 0px -35px 0px' });
  revealItems.forEach((item) => {
    item.classList.add('js-reveal');
    revealObserver.observe(item);
  });
}

// Parallax muito leve na ilustração da página inicial, apenas com rato/movimento reduzido desligado.
const heroParallax = document.querySelector('.hero-visual');
const finePointer = window.matchMedia('(hover: hover) and (pointer: fine)').matches;
const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
if (heroParallax && finePointer && !reducedMotion) {
  let pointerFrame = 0;
  let pointerPosition = { x: 0, y: 0 };
  heroParallax.addEventListener('pointermove', (event) => {
    const bounds = heroParallax.getBoundingClientRect();
    pointerPosition = {
      x: Math.max(-1, Math.min(1, ((event.clientX - bounds.left) / bounds.width - 0.5) * 2)),
      y: Math.max(-1, Math.min(1, ((event.clientY - bounds.top) / bounds.height - 0.5) * 2))
    };
    if (pointerFrame) return;
    pointerFrame = requestAnimationFrame(() => {
      pointerFrame = 0;
      heroParallax.style.setProperty('--pointer-x', pointerPosition.x.toFixed(3));
      heroParallax.style.setProperty('--pointer-y', pointerPosition.y.toFixed(3));
    });
  });
  heroParallax.addEventListener('pointerleave', () => {
    heroParallax.style.setProperty('--pointer-x', '0');
    heroParallax.style.setProperty('--pointer-y', '0');
  });
}
