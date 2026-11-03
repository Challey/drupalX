(function () {
  'use strict';

  var cfg = window.__dxCopyrightGuard || {};
  var html = document.documentElement;
  html.classList.add('dxcg-on');

  // Quick-read agreement card: visible on arrival, collapsible so it never
  // blocks reading. A small pill brings it back.
  var quickPanel = document.getElementById('dxcg-quick');
  if (quickPanel) {
    var quickToggle = quickPanel.querySelector('[data-dxcg-quick-toggle]');
    // The overlay variant collapses to a fixed bar, so it needs no extra pill.
    var quickOverlay = quickPanel.classList.contains('dxcg-quick--overlay');
    var quickPill = null;
    var setQuickCollapsed = function (collapsed) {
      quickPanel.classList.toggle('is-collapsed', collapsed);
      if (quickToggle) {
        quickToggle.textContent = collapsed ? '展开协议' : '收起协议';
        quickToggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
      }
      if (quickOverlay) {
        return;
      }
      if (!quickPill) {
        quickPill = document.createElement('button');
        quickPill.type = 'button';
        quickPill.className = 'dxcg-quick-pill';
        quickPill.textContent = '查看协议';
        quickPill.hidden = true;
        quickPill.addEventListener('click', function () {
          setQuickCollapsed(false);
          quickPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
        document.body.appendChild(quickPill);
      }
      quickPill.hidden = !collapsed;
    };
    if (quickToggle) {
      quickToggle.addEventListener('click', function () {
        setQuickCollapsed(!quickPanel.classList.contains('is-collapsed'));
      });
    }
  }

  if (cfg.blockCopy) {
    ['copy', 'cut', 'contextmenu', 'dragstart', 'selectstart'].forEach(function (eventName) {
      document.addEventListener(eventName, function (event) {
        event.preventDefault();
        return false;
      }, true);
    });
  }

  function ensureShield() {
    if (ensureShield.node) {
      return ensureShield.node;
    }
    var shield = document.createElement('div');
    shield.className = 'dxcg-shield';
    shield.textContent = '内容已临时隐藏 · 点击恢复阅读';
    shield.hidden = true;
    shield.addEventListener('click', hideShield);
    document.body.appendChild(shield);
    ensureShield.node = shield;
    return shield;
  }

  function hideShield() {
    if (ensureShield.node) {
      ensureShield.node.hidden = true;
    }
    html.classList.remove('dxcg-blurred');
  }

  function showShield(milliseconds) {
    html.classList.add('dxcg-blurred');
    ensureShield().hidden = false;
    window.clearTimeout(showShield.timer);
    if (milliseconds) {
      showShield.timer = window.setTimeout(hideShield, milliseconds);
    }
  }

  if (cfg.blockKeys) {
    document.addEventListener('keydown', function (event) {
      var key = (event.key || '').toLowerCase();
      if (key === 'printscreen') {
        event.preventDefault();
        showShield(1800);
      }
      // macOS screenshot shortcuts (Cmd+Shift+3/4/5) cannot be blocked, but the
      // content can be hidden before the capture completes.
      if (event.metaKey && event.shiftKey && ['3', '4', '5'].indexOf(key) !== -1) {
        showShield(1800);
      }
      if (event.key === 'F12') {
        event.preventDefault();
      }
      if ((event.ctrlKey || event.metaKey) && ['c', 'x', 's', 'p', 'u', 'a'].indexOf(key) !== -1) {
        event.preventDefault();
      }
      if ((event.ctrlKey || event.metaKey) && event.shiftKey && ['i', 'j', 'c'].indexOf(key) !== -1) {
        event.preventDefault();
      }
    }, true);
    document.addEventListener('keyup', function (event) {
      if ((event.key || '').toLowerCase() === 'printscreen') {
        showShield(1800);
      }
    }, true);
  }

  if (cfg.blurOnFocusLoss) {
    window.addEventListener('blur', function () {
      showShield(0);
    });
    window.addEventListener('focus', hideShield);
    document.addEventListener('visibilitychange', function () {
      if (document.hidden) {
        showShield(0);
      }
    });
  }

  if (cfg.scrollGuard) {
    var lastY = window.scrollY || 0;
    var lastTime = Date.now();
    window.addEventListener('scroll', function () {
      var y = window.scrollY || 0;
      var now = Date.now();
      var deltaY = Math.abs(y - lastY);
      var deltaTime = now - lastTime;
      // Normal reading is gradual; full-page capture tools jump multiple screens.
      if (deltaTime < 120 && deltaY > window.innerHeight * 0.9) {
        html.classList.add('dxcg-scrolling');
        window.clearTimeout(window.__dxcgScrollTimer);
        window.__dxcgScrollTimer = window.setTimeout(function () {
          html.classList.remove('dxcg-scrolling');
        }, 700);
      }
      lastY = y;
      lastTime = now;
    }, { passive: true });
  }

  if (cfg.watermark && cfg.watermarkText) {
    var watermark = document.createElement('div');
    watermark.className = 'dxcg-watermark';
    watermark.setAttribute('aria-hidden', 'true');
    var count = Math.max(24, Math.ceil((window.innerWidth * window.innerHeight) / 70000));
    for (var i = 0; i < count; i++) {
      var cell = document.createElement('span');
      cell.textContent = cfg.watermarkText;
      watermark.appendChild(cell);
    }
    document.body.appendChild(watermark);
  }

  if (cfg.disablePrint) {
    window.addEventListener('beforeprint', function () {
      html.classList.add('dxcg-printing');
    });
    window.addEventListener('afterprint', function () {
      html.classList.remove('dxcg-printing');
    });
  }
})();
