(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.dxPortalMotion = {
    attach(context) {
      once('dx-features', '.dx-feature', context).forEach((el) => {
        if (!('IntersectionObserver' in window)) {
          el.classList.add('is-in');
          return;
        }
        const io = new IntersectionObserver(
          (entries) => {
            entries.forEach((entry) => {
              if (entry.isIntersecting) {
                entry.target.classList.add('is-in');
                io.unobserve(entry.target);
              }
            });
          },
          { threshold: 0.2 }
        );
        io.observe(el);
      });
    },
  };

  Drupal.behaviors.dxPortalNav = {
    attach(context) {
      once('dx-nav-toggle', '[data-dx-nav-toggle]', context).forEach((btn) => {
        btn.addEventListener('click', () => {
          const open = document.body.classList.toggle('is-nav-open');
          btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        // Close after in-page nav tap (mobile).
        const nav = document.getElementById('dx-primary-nav');
        if (nav) {
          nav.querySelectorAll('a').forEach((link) => {
            link.addEventListener('click', () => {
              if (window.matchMedia('(max-width: 860px)').matches) {
                document.body.classList.remove('is-nav-open');
                btn.setAttribute('aria-expanded', 'false');
              }
            });
          });
        }
      });
    },
  };
})(Drupal, once);
