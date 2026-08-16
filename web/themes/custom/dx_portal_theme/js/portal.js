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
      once('dx-nav-toggle', '.dx-nav-toggle', context).forEach((btn) => {
        const shell = btn.closest('.dx-shell') || document.body;
        const navId = btn.getAttribute('aria-controls');
        const nav = navId ? document.getElementById(navId) : shell.querySelector('.dx-nav');
        if (!nav) {
          return;
        }

        const setOpen = (open) => {
          btn.setAttribute('aria-expanded', open ? 'true' : 'false');
          nav.classList.toggle('is-open', open);
          shell.classList.toggle('dx-nav-open', open);
          document.documentElement.classList.toggle('dx-nav-lock', open);
        };

        btn.addEventListener('click', () => {
          setOpen(btn.getAttribute('aria-expanded') !== 'true');
        });

        nav.querySelectorAll('a').forEach((link) => {
          link.addEventListener('click', () => setOpen(false));
        });

        document.addEventListener('keydown', (e) => {
          if (e.key === 'Escape') {
            setOpen(false);
          }
        });

        window.addEventListener('resize', () => {
          if (window.matchMedia('(min-width: 901px)').matches) {
            setOpen(false);
          }
        });
      });
    },
  };
})(Drupal, once);
