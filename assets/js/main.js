// assets/js/main.js — FuelControl Latin Equipment

document.addEventListener('DOMContentLoaded', () => {

  // ── Auto-hide alerts ──────────────────────────────────────
  document.querySelectorAll('.alert').forEach(el => {
    setTimeout(() => {
      el.style.transition = 'opacity .5s';
      el.style.opacity = '0';
      setTimeout(() => el.remove(), 500);
    }, 5000);
  });

  // ── Confirm delete ────────────────────────────────────────
  document.querySelectorAll('[data-confirm]').forEach(el => {
    el.addEventListener('click', e => {
      if (!confirm(el.dataset.confirm || '¿Estás seguro?')) e.preventDefault();
    });
  });

  // ── Hamburger / Sidebar drawer (mobile) ──────────────────
  const hamburger = document.querySelector('.hamburger');
  const sidebar   = document.querySelector('.sidebar');
  const overlay   = document.querySelector('.sidebar-overlay');

  function openSidebar() {
    sidebar?.classList.add('open');
    overlay?.classList.add('open');
    document.body.style.overflow = 'hidden';
  }
  function closeSidebar() {
    sidebar?.classList.remove('open');
    overlay?.classList.remove('open');
    document.body.style.overflow = '';
  }

  hamburger?.addEventListener('click', () => {
    sidebar?.classList.contains('open') ? closeSidebar() : openSidebar();
  });
  overlay?.addEventListener('click', closeSidebar);

  // Cerrar sidebar al navegar (mobile)
  sidebar?.querySelectorAll('.nav-item').forEach(link => {
    link.addEventListener('click', () => {
      if (window.innerWidth < 900) closeSidebar();
    });
  });

  // Cerrar con ESC
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') closeSidebar();
  });

  // ── Marcar nav item activo en mobile bottom nav ───────────
  const path = window.location.pathname;
  document.querySelectorAll('.mobile-nav-btn').forEach(btn => {
    if (btn.getAttribute('href') && path.includes(btn.getAttribute('href').split('?')[0].split('/').pop())) {
      btn.classList.add('active');
    }
  });

  // ── Input number: prevenir valores negativos ──────────────
  document.querySelectorAll('input[type="number"][min="0"]').forEach(inp => {
    inp.addEventListener('blur', () => {
      if (parseFloat(inp.value) < 0) inp.value = 0;
    });
  });

});
