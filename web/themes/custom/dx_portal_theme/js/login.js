/**
 * @file
 * Topstar compact login behaviors — enterprise ID primary (DrupalX port).
 */
(function ($, Drupal, drupalSettings, once) {
  'use strict';

  function loginI18n(key, fallback) {
    var map = (drupalSettings && drupalSettings.dxLoginI18n) || {};
    return map[key] || fallback || key;
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

  function authConfig() {
    return (drupalSettings && drupalSettings.dxAuth) || {};
  }

  function isLoginPath() {
    return /\/user\/login\/?$/.test(window.location.pathname);
  }

  function getLoginDestination() {
    var urlParams = new URLSearchParams(window.location.search);
    var redirectUrl = urlParams.get('destination');
    if (!redirectUrl) {
      redirectUrl = document.referrer;
      if (!redirectUrl || redirectUrl.indexOf('/user/login') !== -1) {
        redirectUrl = '/';
      }
    }
    if (redirectUrl.indexOf(window.location.origin) === 0) {
      redirectUrl = redirectUrl.replace(window.location.origin, '');
    }
    if (redirectUrl.charAt(0) !== '/') {
      redirectUrl = '/' + redirectUrl;
    }
    return redirectUrl;
  }

  function normalizeCredit(raw) {
    return String(raw || '').toUpperCase().replace(/[\s\-]+/g, '');
  }

  function getLoginLayout() {
    var $box = $('#login-professional-container');
    if ($box.hasClass('login-layout--classic')) {
      return 'classic';
    }
    if ($box.hasClass('login-layout--compact') || $('#login-alt-methods').length) {
      return 'compact';
    }
    var layout = (window.drupalSettings && window.drupalSettings.dxLoginLayout) || 'compact';
    return layout === 'classic' ? 'classic' : 'compact';
  }

  var lookupTimer = null;
  var enterpriseBound = false;
  var smsBound = false;

  function doEnterpriseLookup() {
    var code = normalizeCredit($('#panel-edit-credit').val());
    var $preview = $('#panel-enterprise-preview');
    if (!code || code.length < 18) {
      $preview.attr('hidden', true);
      return;
    }
    var cfg = authConfig();
    $.ajax({
      url: apiPath(cfg.lookupPath || 'dx/auth/enterprise_lookup'),
      type: 'GET',
      data: { credit_code: code },
      dataType: 'json',
      success: function (data) {
        if (!(data && data.code == 1 && data.data)) {
          $preview.attr('hidden', true);
          return;
        }
        var d = data.data;
        $('#panel-enterprise-preview-code').text(d.credit_code_masked || code);
        $('#panel-enterprise-preview-name').text(d.company_name || '—');
        $preview.removeAttr('hidden');
      },
      error: function () {
        $preview.attr('hidden', true);
      }
    });
  }

  function scheduleLookup() {
    if (lookupTimer) {
      clearTimeout(lookupTimer);
    }
    lookupTimer = setTimeout(doEnterpriseLookup, 450);
  }

  function bindEnterpriseHandlers() {
    if (!$('#panel-enterprise').length || enterpriseBound) {
      return;
    }
    enterpriseBound = true;

    $(document).off('input.dxEntLookup', '#panel-edit-credit')
      .on('input.dxEntLookup', '#panel-edit-credit', function () {
        this.value = normalizeCredit(this.value);
        scheduleLookup();
      });

    $(document).off('click.dxEntLogin', '#panel-enterprise-submit')
      .on('click.dxEntLogin', '#panel-enterprise-submit', function (e) {
        e.preventDefault();
        e.stopImmediatePropagation();
        var code = normalizeCredit($('#panel-edit-credit').val());
        var pass = ($('#panel-edit-enterprise-pass').val() || '');
        if (!code) {
          alert(loginI18n('enter_enterprise', '请输入企业信用代码'));
          return false;
        }
        if (!pass) {
          alert(loginI18n('enter_enterprise_pass', '请输入密码'));
          return false;
        }
        try {
          sessionStorage.setItem('dxLoginTab', 'enterprise');
        }
        catch (err) {}
        var $btn = $(this);
        if ($btn.data('busy')) {
          return false;
        }
        $btn.data('busy', true).prop('disabled', true);
        var cfg = authConfig();
        $.ajax({
          url: apiPath(cfg.loginPath || 'dx/auth/enterprise_login'),
          type: 'POST',
          headers: { 'X-CSRF-Token': cfg.csrfToken || '' },
          data: {
            credit_code: code,
            password: pass,
            destination: getLoginDestination()
          },
          dataType: 'json',
          success: function (data) {
            if (data && data.code == 1) {
              window.location.href = data.redirect || '/';
              return;
            }
            if (data && data.code == 2 && data.redirect) {
              window.location.href = data.redirect;
              return;
            }
            alert((data && data.msg) || loginI18n('network_error', '网络错误'));
            activateLoginTab('enterprise');
          },
          error: function (xhr) {
            var msg = loginI18n('network_error', '网络错误');
            if (xhr && xhr.status === 403) {
              msg = loginI18n('csrf_error', '登录会话已过期，请刷新页面后重试');
            }
            else if (xhr && xhr.responseJSON && xhr.responseJSON.msg) {
              msg = xhr.responseJSON.msg;
            }
            alert(msg);
            activateLoginTab('enterprise');
          },
          complete: function () {
            $btn.data('busy', false).prop('disabled', false);
          }
        });
        return false;
      });
  }

  function bindLoginPanels() {
    bindEnterpriseHandlers();
    bindSmsHandlers();
    var $nativeForm = $('#user-login-form');
    if (!$nativeForm.length || !$('#panel-account').length) {
      return false;
    }
    if ($nativeForm.data('loginRestructured')) {
      return true;
    }

    $('#panel-account').off('click.loginAccount', '.form-submit').on('click.loginAccount', '.form-submit', function (e) {
      e.preventDefault();
      var nameVal = $('#panel-edit-name').val() || '';
      var passVal = $('#panel-edit-pass').val() || '';
      if (!nameVal || !passVal) {
        alert(loginI18n('enter_user_pass', '请输入用户名和密码。'));
        return false;
      }
      try {
        sessionStorage.setItem('dxLoginTab', 'account');
      }
      catch (err) {}
      if (typeof Drupal.antibot !== 'undefined' && Drupal.antibot.unlockForms) {
        Drupal.antibot.unlockForms();
      }
      $nativeForm.find('input[name="name"]').val(nameVal);
      $nativeForm.find('input[name="pass"]').val(passVal);
      $nativeForm.get(0).submit();
      return false;
    });

    $nativeForm.data('loginRestructured', true);
    return true;
  }

  function activateLoginTab(target) {
    target = String(target || 'enterprise');
    if (target === 'runner') {
      target = 'enterprise';
    }
    var $panel = $('#panel-' + target);
    if (!$panel.length) {
      return;
    }
    try {
      sessionStorage.setItem('dxLoginTab', target);
    }
    catch (err) {}

    var $tab = $('.login-tab[data-tab="' + target + '"]');
    $('.login-tab').removeClass('active').attr('aria-selected', 'false');
    if ($tab.length) {
      $tab.addClass('active').attr('aria-selected', 'true');
    }

    $('.login-panel').removeClass('active');
    $panel.addClass('active');

    var $container = $('#login-professional-container');
    var compactAlt = getLoginLayout() === 'compact' && (target === 'qrcode' || target === 'mobile' || target === 'account');
    if (compactAlt) {
      $container.addClass('is-alt-open');
      $panel.find('.login-panel-back').removeAttr('hidden').show();
    }
    else {
      $container.removeClass('is-alt-open');
      $('.login-panel-back').attr('hidden', true).hide();
    }

    if (target === 'qrcode') {
      startWeChatLogin();
    }
  }

  function preferredLoginTab() {
    var hash = (window.location.hash || '').replace(/^#/, '');
    if (hash === 'runner') {
      hash = 'enterprise';
    }
    if (hash && $('#panel-' + hash).length) {
      return hash;
    }
    try {
      var saved = sessionStorage.getItem('dxLoginTab');
      if (saved === 'runner') {
        saved = 'enterprise';
      }
      if (saved && $('#panel-' + saved).length) {
        return saved;
      }
    }
    catch (err) {}
    return 'enterprise';
  }

  $(document).off('click.loginTabs', '.login-tab').on('click.loginTabs', '.login-tab', function (e) {
    e.preventDefault();
    e.stopPropagation();
    activateLoginTab($(this).attr('data-tab') || $(this).data('tab'));
  });

  $(document).off('click.loginAlt', '#login-alt-methods [data-login-alt], .login-alt-icon')
    .on('click.loginAlt', '#login-alt-methods [data-login-alt], .login-alt-icon', function (e) {
      e.preventDefault();
      e.stopPropagation();
      var target = $(this).attr('data-login-alt') || $(this).closest('[data-login-alt]').attr('data-login-alt');
      if (!target) {
        if ($(this).hasClass('login-alt-icon--wechat')) {
          target = 'qrcode';
        }
        else if ($(this).hasClass('login-alt-icon--mobile')) {
          target = 'mobile';
        }
        else if ($(this).hasClass('login-alt-icon--account')) {
          target = 'account';
        }
      }
      activateLoginTab(target || 'qrcode');
      return false;
    });

  $(document).off('click.loginBack', '[data-login-back]').on('click.loginBack', '[data-login-back]', function (e) {
    e.preventDefault();
    e.stopPropagation();
    activateLoginTab('enterprise');
    return false;
  });

  var smsCountdownTimer = null;
  var wechatStartInFlight = false;

  function isWeChatUA() {
    return /MicroMessenger/i.test(navigator.userAgent || '');
  }

  function loadWxLoginScript(src, callback) {
    if (window.WxLogin) {
      callback();
      return;
    }
    var existing = document.querySelector('script[data-dx-wxlogin]');
    if (existing) {
      existing.addEventListener('load', callback);
      return;
    }
    var script = document.createElement('script');
    script.src = src || 'https://res.wx.qq.com/connect/zh_CN/htmledition/js/wxLogin.js';
    script.async = true;
    script.setAttribute('data-dx-wxlogin', '1');
    script.onload = callback;
    script.onerror = function () {
      callback(new Error('wxlogin'));
    };
    document.head.appendChild(script);
  }

  function resetWeChatPanel() {
    $('#wechat-oauth-block').attr('hidden', true);
    $('#qrcode-scan-block').removeAttr('hidden');
    $('#wx-login-container').attr('hidden', true).empty();
    $('#login-qrcode-wrap').removeClass('is-wx-live');
    $('#login-qrcode-placeholder').removeAttr('hidden').text(
      loginI18n('loading_qr', '正在加载二维码…')
    );
  }

  function startWeChatLogin() {
    var cfg = authConfig();
    if (!cfg.wechatEnabled) {
      $('#login-qrcode-placeholder').removeAttr('hidden').text(
        loginI18n('wechat_unavailable', '微信登录暂未开通，请使用企业ID或账号登录。')
      );
      $('#wx-login-container').attr('hidden', true).empty();
      $('#login-qrcode-wrap').removeClass('is-wx-live');
      $('#wechat-oauth-block').attr('hidden', true);
      return;
    }
    if (wechatStartInFlight) {
      return;
    }
    wechatStartInFlight = true;
    resetWeChatPanel();
    var inWechat = isWeChatUA() && cfg.wechatMp;
    $.ajax({
      url: apiPath(cfg.wechatStartPath || 'dx/auth/wechat/start'),
      type: 'GET',
      data: {
        in_wechat: inWechat ? '1' : '0',
        destination: getLoginDestination()
      },
      dataType: 'json',
      success: function (data) {
        if (!(data && data.code == 1 && data.data)) {
          $('#login-qrcode-placeholder').text(
            (data && data.msg) || loginI18n('wechat_unavailable', '微信登录暂未开通，请使用企业ID或账号登录。')
          );
          return;
        }
        var d = data.data;
        if (inWechat && d.authorize_url) {
          $('#qrcode-scan-block').attr('hidden', true);
          $('#wechat-oauth-block').removeAttr('hidden');
          $('#wechat-oauth-btn').attr('href', d.authorize_url);
          return;
        }
        if (d.channel === 'qr' && d.app_id && window.location.protocol !== 'file:') {
          loadWxLoginScript(d.wx_script, function (err) {
            if (err || !window.WxLogin) {
              $('#login-qrcode-placeholder').html(
                '<a href="' + String(d.authorize_url).replace(/"/g, '&quot;') + '">' +
                loginI18n('wechat_signin', '微信扫码登录') + '</a>'
              );
              return;
            }
            $('#login-qrcode-placeholder').attr('hidden', true);
            $('#wx-login-container').removeAttr('hidden').empty();
            $('#login-qrcode-wrap').addClass('is-wx-live');
            new window.WxLogin({
              self_redirect: false,
              id: 'wx-login-container',
              appid: d.app_id,
              scope: d.scope || 'snsapi_login',
              redirect_uri: encodeURIComponent(d.redirect_uri),
              state: d.state,
              style: 'black',
              href: ''
            });
          });
          return;
        }
        if (d.authorize_url) {
          $('#login-qrcode-placeholder').html(
            '<a href="' + String(d.authorize_url).replace(/"/g, '&quot;') + '">' +
            loginI18n('wechat_signin', '微信扫码登录') + '</a>'
          );
        }
      },
      error: function () {
        $('#login-qrcode-placeholder').text(loginI18n('network_error', '网络错误'));
      },
      complete: function () {
        wechatStartInFlight = false;
      }
    });
  }

  function setSmsCountdown($btn, seconds) {
    if (smsCountdownTimer) {
      clearInterval(smsCountdownTimer);
      smsCountdownTimer = null;
    }
    var remain = parseInt(seconds, 10) || 60;
    $btn.addClass('disabled').prop('disabled', true);
    var tick = function () {
      if (remain <= 0) {
        clearInterval(smsCountdownTimer);
        smsCountdownTimer = null;
        $btn.removeClass('disabled').prop('disabled', false)
          .text(loginI18n('send_code', '发送验证码'));
        return;
      }
      $btn.text(loginI18n('resend_in', '重新发送') + ' (' + remain + 's)');
      remain -= 1;
    };
    tick();
    smsCountdownTimer = setInterval(tick, 1000);
  }

  function bindSmsHandlers() {
    if (!$('#panel-mobile').length || smsBound) {
      return;
    }
    smsBound = true;
    $('#panel-mobile').off('click.loginSend', '.send_btn').on('click.loginSend', '.send_btn', function (e) {
      e.preventDefault();
      var cfg = authConfig();
      if (!cfg.smsEnabled) {
        alert(loginI18n('mobile_unavailable', '手机登录暂未开通，请使用企业ID或账号登录。'));
        return false;
      }
      var mobile = ($('#panel-edit-mobile').val() || '').trim();
      if (!mobile) {
        alert(loginI18n('enter_mobile', '请输入手机号码'));
        return false;
      }
      var $btn = $(this);
      if ($btn.hasClass('disabled') || $btn.data('busy')) {
        return false;
      }
      $btn.data('busy', true);
      $.ajax({
        url: apiPath(cfg.smsSendPath || 'dx/auth/sms/send'),
        type: 'POST',
        headers: { 'X-CSRF-Token': cfg.csrfToken || '' },
        data: { mobile: mobile },
        dataType: 'json',
        success: function (data) {
          if (!(data && data.code == 1)) {
            alert((data && data.msg) || loginI18n('network_error', '网络错误'));
            return;
          }
          var retry = (data.data && data.data.retry_after) || 60;
          setSmsCountdown($btn, retry);
          if (data.data && data.data.debug_code) {
            $('#panel-edit-code').val(data.data.debug_code);
            alert(loginI18n('code_sent_test', '测试模式验证码') + ': ' + data.data.debug_code);
          }
          else {
            alert(loginI18n('code_sent', '验证码已发送'));
          }
        },
        error: function (xhr) {
          var msg = loginI18n('network_error', '网络错误');
          if (xhr && xhr.status === 403) {
            msg = loginI18n('csrf_error', '登录会话已过期，请刷新页面后重试');
          }
          else if (xhr && xhr.responseJSON && xhr.responseJSON.msg) {
            msg = xhr.responseJSON.msg;
          }
          alert(msg);
        },
        complete: function () {
          $btn.data('busy', false);
        }
      });
      return false;
    });

    $('#panel-mobile').off('click.loginMobile', '.form-submit').on('click.loginMobile', '.form-submit', function (e) {
      e.preventDefault();
      var cfg = authConfig();
      if (!cfg.smsEnabled) {
        alert(loginI18n('mobile_unavailable', '手机登录暂未开通，请使用企业ID或账号登录。'));
        return false;
      }
      var mobile = ($('#panel-edit-mobile').val() || '').trim();
      var code = ($('#panel-edit-code').val() || '').trim();
      if (!mobile || !code) {
        alert(loginI18n('enter_mobile_code', '请输入手机号码和验证码'));
        return false;
      }
      var $btn = $(this);
      if ($btn.data('busy')) {
        return false;
      }
      $btn.data('busy', true).prop('disabled', true);
      $.ajax({
        url: apiPath(cfg.smsLoginPath || 'dx/auth/sms/login'),
        type: 'POST',
        headers: { 'X-CSRF-Token': cfg.csrfToken || '' },
        data: {
          mobile: mobile,
          code: code,
          destination: getLoginDestination()
        },
        dataType: 'json',
        success: function (data) {
          if (data && data.code == 1) {
            window.location.href = data.redirect || '/';
            return;
          }
          alert((data && data.msg) || loginI18n('network_error', '网络错误'));
        },
        error: function (xhr) {
          var msg = loginI18n('network_error', '网络错误');
          if (xhr && xhr.status === 403) {
            msg = loginI18n('csrf_error', '登录会话已过期，请刷新页面后重试');
          }
          else if (xhr && xhr.responseJSON && xhr.responseJSON.msg) {
            msg = xhr.responseJSON.msg;
          }
          alert(msg);
        },
        complete: function () {
          $btn.data('busy', false).prop('disabled', false);
        }
      });
      return false;
    });
  }

  Drupal.behaviors.dxPortalLogin = {
    attach: function (context) {
      if (!isLoginPath()) {
        return;
      }
      once('dx-portal-login', '#login-professional-container', context).forEach(function () {
        var tries = 0;
        var timer = setInterval(function () {
          tries++;
          if (bindLoginPanels() || tries >= 20) {
            clearInterval(timer);
            activateLoginTab(preferredLoginTab());
          }
        }, 50);
      });
    }
  };

})(jQuery, Drupal, drupalSettings, once);
