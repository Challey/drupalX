/**
 * @file
 * Theme Studio gallery micro-interactions.
 */
(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.dxThemeStudio = {
    attach(context) {
      once('dx-theme-studio', '.dx-theme-tile', context).forEach((tile) => {
        tile.addEventListener('pointerenter', () => {
          tile.classList.add('is-hover');
        });
        tile.addEventListener('pointerleave', () => {
          tile.classList.remove('is-hover');
        });
      });
    },
  };
})(Drupal, once);
