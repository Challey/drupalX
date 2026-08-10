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
})(Drupal, once);
