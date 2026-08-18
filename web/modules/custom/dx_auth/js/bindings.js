/**
 * @file
 * Unified login bind page: SMS, account merge, WeChat QR.
 */
(function (Drupal, drupalSettings, once) {
  'use strict';

  function cfg() {
    return (drupalSettings && drupalSettings.dxAuth) || {};
  }

  function apiPath(path) {
    var base = '/';
    var prefix = '';
    if (drupalSettings && drupalSettings.path) {
      base = drupalSettings.path.baseUrl || '/';
      prefix = drupalSettings.path.pathPrefix || '';
    }
    return base.replace(/\/?$/, '/') + prefix + String(path || '').replace(/^\//, '');
  }

  function $(id) {
    return document.getElementById(id);
  }

  function setMsg(text, isError) {
    var m = $('dx-bind-msg');
    if (!m) {
      return;
    }
    m.textContent = text || '';
    m.hidden = !text;
    m.classList.toggle('is-error', !!isError);
  }

  function post(path, body) {
    var data = new URLSearchParams(body || {});
    return fetch(apiPath(path), {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        'X-CSRF-Token': cfg().csrfToken || ''
      },
      body: data.toString()
    }).then(function (r) { return r.json(); });
  }

  function getJSON(path, params) {
    var q = new URLSearchParams(params || {});
    var url = apiPath(path);
    if (q.toString()) {
      url += (url.indexOf('?') >= 0 ? '&' : '?') + q.toString();
    }
    return fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }

  var qrTimer = null;

  function loadBindQr() {
    var box = $('dx-bind-qrcode');
    if (!box || !cfg().wechatEnabled) {
      return;
    }
    getJSON(cfg().wechatQrPath || 'dx/auth/wechat_qrcode', {
      mode: 'bind',
      redirect_url: '/dx/auth/bindings'
    }).then(function (data) {
      if (!(data && data.code == 1 && data.data && data.data.url)) {
        box.innerHTML = '<div class="dx-auth-bind-qr__ph">' + ((data && data.msg) || Drupal.t('二维码加载失败')) + '</div>';
        return;
      }
      box.innerHTML = '<img src="' + data.data.url + '" alt="WeChat">';
      if (qrTimer) {
        clearInterval(qrTimer);
      }
      qrTimer = setInterval(function () {
        getJSON(cfg().wechatPollPath || 'dx/auth/wechat_poll', { scene_id: data.data.scene_id })
          .then(function (res) {
            if (res && res.code == 1) {
              clearInterval(qrTimer);
              setMsg(Drupal.t('微信绑定成功'));
              window.location.reload();
            }
            else if (res && res.code == 2) {
              clearInterval(qrTimer);
              setMsg(res.msg || Drupal.t('绑定失败'), true);
            }
          });
      }, 1200);
    }).catch(function () {
      box.innerHTML = '<div class="dx-auth-bind-qr__ph">' + Drupal.t('网络错误') + '</div>';
    });
  }

  function bindEvents(root) {
    var send = $('dx-bind-send-code');
    if (send) {
      send.addEventListener('click', function () {
        var mobile = (($('dx-bind-mobile') || {}).value || '').trim();
        if (!mobile) {
          setMsg(Drupal.t('请填写手机号'), true);
          return;
        }
        if (send.classList.contains('is-busy')) {
          return;
        }
        send.classList.add('is-busy');
        var label = send.textContent;
        var left = 60;
        send.textContent = left + 's';
        var timer = setInterval(function () {
          left--;
          if (left <= 0) {
            clearInterval(timer);
            send.classList.remove('is-busy');
            send.textContent = label;
          }
          else {
            send.textContent = left + 's';
          }
        }, 1000);
        getJSON(cfg().smsSendPath || 'dx/auth/sms_send', { mobile: mobile })
          .then(function (data) {
            if (!(data && data.code == 1)) {
              setMsg((data && data.msg) || Drupal.t('发送失败'), true);
            }
            else {
              setMsg(Drupal.t('验证码已发送'));
            }
          })
          .catch(function () {
            setMsg(Drupal.t('发送失败'), true);
          });
      });
    }

    var mobileSave = $('dx-bind-mobile-save');
    if (mobileSave) {
      mobileSave.addEventListener('click', function () {
        var mobile = (($('dx-bind-mobile') || {}).value || '').trim();
        var code = (($('dx-bind-code') || {}).value || '').trim();
        if (!mobile || !code) {
          setMsg(Drupal.t('请填写手机号和验证码'), true);
          return;
        }
        post(cfg().bindMobilePath || 'dx/auth/bind_mobile', { mobile: mobile, code: code })
          .then(function (res) {
            if (res && res.code == 1) {
              setMsg(res.msg || Drupal.t('手机绑定成功'));
              window.location.reload();
            }
            else {
              setMsg((res && res.msg) || Drupal.t('绑定失败'), true);
            }
          })
          .catch(function () {
            setMsg(Drupal.t('网络错误'), true);
          });
      });
    }

    var accountSave = $('dx-bind-account-save');
    if (accountSave) {
      accountSave.addEventListener('click', function () {
        var login = (($('dx-bind-login') || {}).value || '').trim();
        var password = ($('dx-bind-password') || {}).value || '';
        if (!login || !password) {
          setMsg(Drupal.t('请填写用户名/邮箱和密码'), true);
          return;
        }
        post(cfg().claimAccountPath || 'dx/auth/claim_account', { login: login, password: password })
          .then(function (res) {
            if (res && res.code == 1) {
              setMsg(res.msg || Drupal.t('账号归并成功'));
              window.location.reload();
            }
            else {
              setMsg((res && res.msg) || Drupal.t('验证失败'), true);
            }
          })
          .catch(function () {
            setMsg(Drupal.t('网络错误'), true);
          });
      });
    }

    loadBindQr();
  }

  Drupal.behaviors.dxAuthBindings = {
    attach: function (context) {
      once('dx-auth-bindings', '#dx-auth-bindings', context).forEach(bindEvents);
    }
  };
})(Drupal, drupalSettings, once);
