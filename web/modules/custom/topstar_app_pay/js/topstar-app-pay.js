/**
 * Open WeChat H5 pay inside the App WebView, then poll until paid.
 */
(function (Drupal, drupalSettings, once) {
  "use strict";

  Drupal.behaviors.topstarAppPay = {
    attach: function (context) {
      const cfg = drupalSettings.topstarAppPay || {};
      if (!cfg.intentId || !cfg.statusUrl) {
        return;
      }
      once("topstar-app-pay", "body", context).forEach(function () {
        let tries = 0;
        const max = 90;
        const launchedKey = "topstarPayLaunched_" + cfg.intentId;

        const goPay = function () {
          if (!cfg.mwebUrl || sessionStorage.getItem(launchedKey)) {
            return;
          }
          sessionStorage.setItem(launchedKey, "1");
          let url = cfg.mwebUrl;
          if (url.indexOf("redirect_url=") === -1) {
            const sep = url.indexOf("?") >= 0 ? "&" : "?";
            url += sep + "redirect_url=" + encodeURIComponent(window.location.href);
          }
          window.setTimeout(function () {
            window.location.href = url;
          }, 350);
        };

        const tick = function () {
          tries += 1;
          fetch(cfg.statusUrl, { credentials: "same-origin" })
            .then(function (r) {
              return r.json();
            })
            .then(function (data) {
              if (data && data.status === "paid") {
                sessionStorage.removeItem(launchedKey);
                window.location.href = cfg.successUrl || "/driver";
                return;
              }
              if (tries === 1) {
                goPay();
              }
              if (tries < max) {
                window.setTimeout(tick, 2000);
              }
            })
            .catch(function () {
              if (tries === 1) {
                goPay();
              }
              if (tries < max) {
                window.setTimeout(tick, 2500);
              }
            });
        };
        window.setTimeout(tick, 400);
      });
    },
  };
})(Drupal, drupalSettings, once);
