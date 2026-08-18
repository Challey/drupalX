(function (Drupal, drupalSettings, once) {
  'use strict';

  Drupal.behaviors.dxApiDocs = {
    attach: function (context) {
      once('dx-api-docs', '#swagger-ui', context).forEach(function () {
        if (typeof SwaggerUIBundle === 'undefined') {
          return;
        }
        var spec = (drupalSettings.dxEcosystem && drupalSettings.dxEcosystem.specUrl) || '/dx/api/openapi.yaml';
        window.ui = SwaggerUIBundle({
          url: spec,
          dom_id: '#swagger-ui',
          presets: [SwaggerUIBundle.presets.apis],
          layout: 'BaseLayout'
        });
      });
    }
  };
})(Drupal, drupalSettings, once);
