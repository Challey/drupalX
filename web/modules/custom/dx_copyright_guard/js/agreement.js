/**
 * @file
 * Agreement form: immediate feedback and reliable in-app submission.
 */
(function (Drupal, once) {
  'use strict';

  function setHint(form, message, isError) {
    var hint = form.querySelector('.dxcg-hint');
    if (!hint) {
      return;
    }
    hint.textContent = message || '';
    hint.classList.toggle('is-error', !!isError);
  }

  function setLoading(button) {
    if (!button || button.dataset.dxcgLoading === '1') {
      return;
    }
    button.dataset.dxcgLoading = '1';
    button.classList.add('is-loading');
    button.setAttribute('aria-busy', 'true');
  }

  function clearLoading(button) {
    if (!button) {
      return;
    }
    delete button.dataset.dxcgLoading;
    button.classList.remove('is-loading');
    button.removeAttribute('aria-busy');
  }

  /**
   * Handwritten signature canvas (shared dx_signature storage).
   *
   * The hidden input is the single source of truth: it is prefilled with an
   * existing signature on page load, updated with a new JPEG data URL after
   * each stroke, and cleared by "重写".
   */
  function setupSignature(form) {
    var canvas = form.querySelector('[data-dxcg-sign-canvas]');
    var input = form.querySelector('input[data-dxcg-sign-value]');
    if (!canvas || !input) {
      return;
    }
    var ctx = canvas.getContext('2d');
    if (!ctx) {
      return;
    }
    var live = form.querySelector('[data-dxcg-sign-live]');
    var state = form.querySelector('[data-dxcg-sign-state]');
    // The server may prefill the hidden input with the member's race-entry
    // signature. Until they redraw, keep telling them it is already reusable.
    var reusedBox = form.querySelector('[data-dxcg-sign-reused]');
    var reused = !!reusedBox && reusedBox.getAttribute('data-dxcg-sign-reused') === '1';
    var drawing = false;
    var hasInk = !!input.value;
    var restored = false;
    var moved = false;
    var lastPoint = null;

    function pixelRatio() {
      return Math.min(window.devicePixelRatio || 1, 3);
    }

    function applyStroke() {
      var ratio = pixelRatio();
      ctx.lineWidth = Math.max(1.6, 2.2 * ratio);
      ctx.lineCap = 'round';
      ctx.lineJoin = 'round';
      ctx.strokeStyle = '#1d1d1f';
    }

    function paintBlank() {
      ctx.save();
      ctx.setTransform(1, 0, 0, 1, 0, 0);
      ctx.fillStyle = '#FFFFFF';
      ctx.fillRect(0, 0, canvas.width, canvas.height);
      ctx.restore();
      applyStroke();
    }

    function setStatus(has) {
      if (live) {
        if (!has) {
          live.textContent = '请在上方画布签名';
        }
        else if (reused) {
          live.textContent = '已复用赛事报名签名，可直接提交；如需重签请直接在画布上书写';
        }
        else {
          live.textContent = '签名已就绪，可继续提交';
        }
      }
      if (state) {
        if (has) {
          state.textContent = reused ? '已复用报名签名' : '已签名';
        }
        else {
          state.textContent = '尚未签名';
        }
        state.classList.toggle('is-on', !!has);
      }
    }

    function syncValue() {
      try {
        input.value = hasInk ? canvas.toDataURL('image/jpeg', 0.82) : '';
      }
      catch (e) {
        // Very old WebViews can throw on toDataURL; keep the last good value.
        input.value = '';
        hasInk = false;
      }
      setStatus(!!input.value);
    }

    function drawDataUrl(src) {
      var image = new Image();
      image.onload = function () {
        paintBlank();
        ctx.drawImage(image, 0, 0, canvas.width, canvas.height);
        applyStroke();
      };
      image.onerror = function () {
        paintBlank();
      };
      image.src = src;
    }

    function sizeCanvas() {
      var box = canvas.getBoundingClientRect();
      var cssWidth = Math.round(box.width || canvas.clientWidth || 0);
      var cssHeight = Math.round(box.height || canvas.clientHeight || 0);
      if (cssWidth < 10 || cssHeight < 10) {
        return false;
      }
      var ratio = pixelRatio();
      var width = Math.max(240, Math.round(cssWidth * ratio));
      var height = Math.max(100, Math.round(cssHeight * ratio));
      if (canvas.width === width && canvas.height === height) {
        return true;
      }
      // Preserve an already restored / hand-drawn signature on resize.
      var snapshot = restored && hasInk ? canvas.toDataURL('image/jpeg', 0.82) : '';
      canvas.width = width;
      canvas.height = height;
      paintBlank();
      if (snapshot) {
        drawDataUrl(snapshot);
      }
      return true;
    }

    function ready() {
      if (!sizeCanvas()) {
        return false;
      }
      if (!restored) {
        restored = true;
        if (input.value) {
          drawDataUrl(input.value);
        }
        else {
          paintBlank();
        }
        setStatus(!!input.value);
      }
      return true;
    }

    // WeChat's WKWebView can attach behaviours before layout settles, leaving
    // the canvas at 0×0 if we size it synchronously. Retry on the next frames.
    var frame = 0;
    function schedule() {
      window.cancelAnimationFrame(frame);
      frame = window.requestAnimationFrame(function () {
        if (!ready()) {
          window.setTimeout(schedule, 120);
        }
      });
    }

    if (document.readyState === 'complete') {
      schedule();
    }
    else {
      window.addEventListener('load', schedule);
      window.setTimeout(schedule, 250);
    }

    var resizeTimer = 0;
    window.addEventListener('resize', function () {
      window.clearTimeout(resizeTimer);
      resizeTimer = window.setTimeout(function () {
        sizeCanvas();
      }, 180);
    });

    function point(clientX, clientY) {
      var box = canvas.getBoundingClientRect();
      var sx = canvas.width / (box.width || 1);
      var sy = canvas.height / (box.height || 1);
      return {
        x: (clientX - box.left) * sx,
        y: (clientY - box.top) * sy
      };
    }

    function start(clientX, clientY) {
      if (!canvas.width || !canvas.height) {
        return;
      }
      drawing = true;
      moved = false;
      hasInk = true;
      // A fresh stroke replaces the reused race signature.
      reused = false;
      lastPoint = point(clientX, clientY);
      ctx.beginPath();
      ctx.moveTo(lastPoint.x, lastPoint.y);
      if (live) {
        live.textContent = '正在书写…';
      }
    }

    function move(clientX, clientY) {
      if (!drawing) {
        return;
      }
      var p = point(clientX, clientY);
      ctx.lineTo(p.x, p.y);
      ctx.stroke();
      lastPoint = p;
      moved = true;
    }

    function end() {
      if (!drawing) {
        return;
      }
      drawing = false;
      // A tap without moving should still leave a visible dot.
      if (!moved && lastPoint) {
        ctx.beginPath();
        ctx.arc(lastPoint.x, lastPoint.y, Math.max(1, ctx.lineWidth / 2), 0, Math.PI * 2);
        ctx.fillStyle = ctx.strokeStyle;
        ctx.fill();
      }
      syncValue();
    }

    // Touch first: iOS / WeChat WKWebView is the primary target. Pointer events
    // are kept for mice and pens but deliberately ignore touch pointers, so a
    // stroke is never recorded twice.
    if (('ontouchstart' in window) || navigator.maxTouchPoints > 0) {
      canvas.addEventListener('touchstart', function (event) {
        if (!event.touches.length) {
          return;
        }
        event.preventDefault();
        start(event.touches[0].clientX, event.touches[0].clientY);
      }, { passive: false });
      canvas.addEventListener('touchmove', function (event) {
        if (!event.touches.length) {
          return;
        }
        event.preventDefault();
        move(event.touches[0].clientX, event.touches[0].clientY);
      }, { passive: false });
      canvas.addEventListener('touchend', function (event) {
        event.preventDefault();
        end();
      }, { passive: false });
      canvas.addEventListener('touchcancel', end);
    }

    if (window.PointerEvent) {
      canvas.addEventListener('pointerdown', function (event) {
        if (event.pointerType === 'touch' || (event.button && event.button !== 0)) {
          return;
        }
        event.preventDefault();
        if (canvas.setPointerCapture) {
          try {
            canvas.setPointerCapture(event.pointerId);
          }
          catch (e) {
            // Ignore capture failures; pointerup still lands on the canvas.
          }
        }
        start(event.clientX, event.clientY);
      });
      canvas.addEventListener('pointermove', function (event) {
        if (event.pointerType === 'touch' || !drawing) {
          return;
        }
        event.preventDefault();
        move(event.clientX, event.clientY);
      });
      ['pointerup', 'pointercancel'].forEach(function (type) {
        canvas.addEventListener(type, end);
      });
    }
    else {
      canvas.addEventListener('mousedown', function (event) {
        if (event.button && event.button !== 0) {
          return;
        }
        event.preventDefault();
        start(event.clientX, event.clientY);
      });
      window.addEventListener('mousemove', function (event) {
        if (!drawing) {
          return;
        }
        event.preventDefault();
        move(event.clientX, event.clientY);
      });
      window.addEventListener('mouseup', end);
    }

    canvas.addEventListener('contextmenu', function (event) {
      event.preventDefault();
    });

    var clearButton = form.querySelector('[data-dxcg-sign-clear]');
    if (clearButton) {
      clearButton.addEventListener('click', function (event) {
        event.preventDefault();
        paintBlank();
        hasInk = false;
        restored = true;
        reused = false;
        input.value = '';
        setStatus(false);
      });
    }
  }

  Drupal.behaviors.dxcgAgreement = {
    attach: function (context) {
      once('dxcg-agreement', 'form.dx-copyright-guard-agreement', context).forEach(function (form) {
        var checkbox = form.querySelector('input[name="accept"]');
        var button = form.querySelector('[data-dxcg-submit]');
        setupSignature(form);
        var signatureInput = form.querySelector('input[data-dxcg-sign-value]');
        var signatureCanvas = form.querySelector('[data-dxcg-sign-canvas]');

        if (button) {
          var guard = function (event) {
            if (checkbox && !checkbox.checked) {
              // Drupal's AJAX submit listens on mousedown, so guard in the
              // capture phase before its handler runs.
              event.preventDefault();
              event.stopImmediatePropagation();
              setHint(form, '请先勾选“我已阅读并同意”后再继续。', true);
              checkbox.focus();
              if (typeof checkbox.reportValidity === 'function') {
                checkbox.reportValidity();
              }
              return;
            }
            if (signatureInput && !signatureInput.value) {
              event.preventDefault();
              event.stopImmediatePropagation();
              setHint(form, '请先在画布上完成手写签名。', true);
              if (signatureCanvas) {
                signatureCanvas.scrollIntoView({ block: 'center', behavior: 'smooth' });
              }
              return;
            }
            setHint(form, '正在提交，请稍候…', false);
            setLoading(button);
            // If the request is aborted (in-app WebView), allow a retry.
            window.setTimeout(function () {
              if (button.dataset.dxcgLoading === '1') {
                setHint(form, '网络较慢，仍在提交；如长时间无反应请再点一次。', true);
              }
            }, 9000);
          };
          ['mousedown', 'touchstart', 'click'].forEach(function (type) {
            button.addEventListener(type, guard, { capture: true, passive: false });
          });
        }

        // Non-AJAX fallback (e.g. JS partially blocked): show feedback on the
        // native submit as well, then let the browser navigate.
        form.addEventListener('submit', function (event) {
          if (checkbox && !checkbox.checked) {
            return;
          }
          if (signatureInput && !signatureInput.value) {
            event.preventDefault();
            setHint(form, '请先在画布上完成手写签名。', true);
            return;
          }
          setHint(form, '正在提交，请稍候…', false);
          setLoading(button);
        });
      });

      // Personal share link: copy to clipboard with a textarea fallback for
      // in-app WebViews that block the async clipboard API.
      once('dxcg-share', '[data-dxcg-share]', context).forEach(function (card) {
        var button = card.querySelector('[data-dxcg-copy]');
        var input = card.querySelector('.dxcg-share-input');
        var hint = card.querySelector('[data-dxcg-share-hint]');
        if (!button || !input) {
          return;
        }
        var notify = function (message, isError) {
          if (!hint) {
            return;
          }
          hint.textContent = message;
          hint.classList.toggle('is-error', !!isError);
        };
        button.addEventListener('click', function () {
          var link = button.getAttribute('data-dxcg-copy') || input.value;
          var done = function () {
            notify('链接已复制，请通过私信发送给受邀人。', false);
            button.textContent = '已复制';
            window.setTimeout(function () {
              button.textContent = '复制链接';
            }, 2000);
          };
          var fallback = function () {
            try {
              input.removeAttribute('readonly');
              input.select();
              input.setSelectionRange(0, 99999);
              var ok = document.execCommand('copy');
              input.setAttribute('readonly', 'readonly');
              if (ok) {
                done();
                return;
              }
            }
            catch (e) {
              // fall through
            }
            notify('复制失败，请长按链接手动复制。', true);
          };
          if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(link).then(done, fallback);
          }
          else {
            fallback();
          }
        });

        // Native share sheet where supported (mobile Safari/Chrome). Desktop
        // browsers without it get the copy button only.
        var nativeButton = card.querySelector('[data-dxcg-share-native]');
        if (nativeButton) {
          if (navigator.share) {
            nativeButton.addEventListener('click', function () {
              var link = input.value;
              navigator.share({
                title: 'TPST 投资人报告',
                text: '邀请您阅读 TPST 投资人报告（登录并签署保密协议后即可阅读）',
                url: link
              }).then(function () {
                notify('已打开系统分享。', false);
              }).catch(function (error) {
                if (error && error.name === 'AbortError') {
                  return;
                }
                notify('系统分享不可用，请复制链接手动发送。', true);
              });
            });
          }
          else {
            nativeButton.hidden = true;
          }
        }
      });
    },

    detach: function (context, settings, trigger) {
      if (trigger === 'unload') {
        once.remove('dxcg-agreement', 'form.dx-copyright-guard-agreement', context).forEach(function (form) {
          clearLoading(form.querySelector('[data-dxcg-submit]'));
        });
      }
    }
  };
})(Drupal, once);
