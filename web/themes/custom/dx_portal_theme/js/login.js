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

  function goAfterLogin(data) {
    var url = (data && data.redirect) || (data && data.data && data.data.redirect) || '/';
    var created = !!(data && (data.data && data.data.created));
    if (!created && data && data.msg && data.msg.indexOf('自动注册') !== -1) {
      created = true;
    }
    if (created && url.indexOf('dx_new=') === -1) {
      url += (url.indexOf('?') >= 0 ? '&' : '?') + 'dx_new=1';
    }
    window.location.href = url;
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
              goAfterLogin(data);
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
    var $nativeForm = $('#user-login-form');
    if (!$nativeForm.length || !$('#panel-account').length) {
      return false;
    }
    if ($nativeForm.data('loginRestructured')) {
      return true;
    }

    $('#panel-account').off('click.loginAccount', '.form-submit').on('click.loginAccount', '.form-submit', function (e) {
      e.preventDefault();
      e.stopImmediatePropagation();
      var nameVal = ($('#panel-edit-name').val() || '').trim();
      var passVal = ($('#panel-edit-pass').val() || '');
      if (!nameVal || !passVal) {
        alert(loginI18n('enter_user_pass', '请输入用户名和密码。'));
        return false;
      }
      try {
        sessionStorage.setItem('dxLoginTab', 'account');
      }
      catch (err) {}
      var $btn = $(this);
      if ($btn.data('busy')) {
        return false;
      }
      $btn.data('busy', true).prop('disabled', true);
      var cfg = authConfig();
      $.ajax({
        url: apiPath(cfg.accountLoginPath || 'dx/auth/account_login'),
        type: 'POST',
        headers: { 'X-CSRF-Token': cfg.csrfToken || '' },
        data: {
          name: nameVal,
          password: passVal,
          destination: getLoginDestination()
        },
        dataType: 'json',
        success: function (data) {
          if (data && data.code == 1) {
            goAfterLogin(data);
            return;
          }
          alert((data && data.msg) || loginI18n('network_error', '网络错误'));
          activateLoginTab('account');
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
          activateLoginTab('account');
        },
        complete: function () {
          $btn.data('busy', false).prop('disabled', false);
        }
      });
      return false;
    });

    $('#panel-mobile').off('click.loginSend', '.send_btn').on('click.loginSend', '.send_btn', function (e) {
      e.preventDefault();
      var cfg = authConfig();
      if (!cfg.smsEnabled) {
        alert(loginI18n('mobile_unavailable', '手机登录暂未开通，请使用企业ID或账号登录。'));
        return;
      }
      var mobile = ($('#panel-edit-mobile').val() || '').trim();
      if (!mobile) {
        alert(loginI18n('enter_mobile', '请输入手机号码'));
        return;
      }
      var $btn = $(this);
      if ($btn.hasClass('disabled')) {
        return;
      }
      $btn.addClass('disabled');
      var time = 60;
      var sendLabel = loginI18n('send_code', '发送验证码');
      var timer = setInterval(function () {
        time--;
        if (time <= 0) {
          clearInterval(timer);
          $btn.removeClass('disabled');
          $btn.text(sendLabel);
        }
        else {
          $btn.text(time + 's');
        }
      }, 1000);
      $.ajax({
        url: apiPath(cfg.smsSendPath || 'dx/auth/sms_send'),
        type: 'GET',
        data: { mobile: mobile },
        dataType: 'json',
        success: function (data) {
          if (!(data && data.code == 1)) {
            alert((data && data.msg) || loginI18n('send_failed', '发送失败，请重试'));
            clearInterval(timer);
            $btn.removeClass('disabled').text(sendLabel);
          }
        },
        error: function () {
          alert(loginI18n('send_failed', '发送失败，请重试'));
          clearInterval(timer);
          $btn.removeClass('disabled').text(sendLabel);
        }
      });
    });

    $('#panel-mobile').off('click.loginMobile', '.form-submit').on('click.loginMobile', '.form-submit', function (e) {
      e.preventDefault();
      e.stopImmediatePropagation();
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
      try {
        sessionStorage.setItem('dxLoginTab', 'mobile');
      }
      catch (err) {}
      var $btn = $(this);
      if ($btn.data('busy')) {
        return false;
      }
      $btn.data('busy', true).prop('disabled', true);
      $.ajax({
        url: apiPath(cfg.smsLoginPath || 'dx/auth/sms_login'),
        type: 'POST',
        data: {
          mobile: mobile,
          code: code,
          destination: getLoginDestination()
        },
        dataType: 'json',
        success: function (data) {
          if (data && data.code == 1) {
            goAfterLogin(data);
            return;
          }
          alert((data && data.msg) || loginI18n('network_error', '网络错误'));
          activateLoginTab('mobile');
        },
        error: function () {
          alert(loginI18n('network_error', '网络错误'));
          activateLoginTab('mobile');
        },
        complete: function () {
          $btn.data('busy', false).prop('disabled', false);
        }
      });
      return false;
    });

    $nativeForm.data('loginRestructured', true);
    return true;
  }

  var login_qrcode_timer = null;
  var login_qrcode_loaded = false;

  function isWeChatBrowser() {
    return /MicroMessenger/i.test(navigator.userAgent || '');
  }

  function clear_qrcode_poll() {
    if (login_qrcode_timer) {
      clearInterval(login_qrcode_timer);
      login_qrcode_timer = null;
    }
  }

  function setupWeChatOAuthLogin() {
    var $oauth = $('#wechat-oauth-block');
    var $scan = $('#qrcode-scan-block');
    var $btn = $('#wechat-oauth-btn');
    if (!$oauth.length || !$btn.length) {
      return false;
    }
    var cfg = authConfig();
    var dest = getLoginDestination();
    $btn.attr('href', apiPath(cfg.wechatJumpPath || 'dx/auth/wechat_jump') + '?return_to=' + encodeURIComponent(dest));
    $oauth.removeAttr('hidden').show();
    $scan.attr('hidden', true).hide();
    $('body').addClass('is-wechat-browser');
    $('.login-tab[data-tab="qrcode"] .login-tab-label').text(loginI18n('wechat_login', '微信登录'));
    return true;
  }

  function set_qrcode(forceReload) {
    var cfg = authConfig();
    if (isWeChatBrowser()) {
      clear_qrcode_poll();
      if (cfg.wechatEnabled) {
        setupWeChatOAuthLogin();
      }
      else {
        $('.login-qrcode-placeholder').text(loginI18n('wechat_unavailable', '微信登录暂未开通，请使用企业ID或账号登录。'));
      }
      return;
    }
    $('#wechat-oauth-block').attr('hidden', true).hide();
    $('#qrcode-scan-block').removeAttr('hidden').show();

    if (!cfg.wechatEnabled) {
      $('.login-qrcode-placeholder').text(loginI18n('wechat_unavailable', '微信登录暂未开通，请使用企业ID或账号登录。'));
      return;
    }
    if (login_qrcode_loaded && !forceReload) {
      return;
    }
    $('.login-qrcode-wrap').html('<div class="login-qrcode-placeholder">' + loginI18n('loading_qr', '正在加载二维码…') + '</div>');
    $.ajax({
      url: apiPath(cfg.wechatQrPath || 'dx/auth/wechat_qrcode'),
      data: { redirect_url: getLoginDestination() },
      dataType: 'json',
      success: function (data) {
        if (data && data.code == 1 && data.data) {
          clear_qrcode_poll();
          login_qrcode_timer = setInterval(function () {
            $.ajax({
              url: apiPath(cfg.wechatPollPath || 'dx/auth/wechat_poll'),
              data: { scene_id: data.data.scene_id },
              success: function (data2) {
                if (data2 && data2.code == 1) {
                  clear_qrcode_poll();
                  window.location.href = apiPath(cfg.wechatMiddlePath || 'dx/auth/wechat_middle') + '?scene_id=' + encodeURIComponent(data.data.scene_id);
                }
              }
            });
          }, 1000);
          $('.login-qrcode-wrap').html('<img src="' + data.data.url + '" alt="' + loginI18n('wechat_qr_alt', '微信扫码登录') + '" />');
          login_qrcode_loaded = true;
        }
        else {
          $('.login-qrcode-wrap').html('<div class="alert alert-danger">' + ((data && data.msg) || loginI18n('qr_fail', '二维码加载失败')) + '</div>');
        }
      },
      error: function () {
        $('.login-qrcode-wrap').html('<div class="alert alert-danger">' + loginI18n('network_error', '网络错误') + '</div>');
      }
    });
  }

  function bindGoogleButton() {
    var $btn = $('#google-oauth-btn');
    if (!$btn.length) {
      return;
    }
    var cfg = authConfig();
    var dest = getLoginDestination();
    $btn.attr('href', apiPath(cfg.googleJumpPath || 'dx/auth/google_jump') + '?return_to=' + encodeURIComponent(dest));
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
      set_qrcode(false);
    }
    else {
      clear_qrcode_poll();
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

  $(document).off('click.loginAlt', '#login-alt-methods [data-login-alt], .login-alt-icon, .login-footer-links [data-login-alt]')
    .on('click.loginAlt', '#login-alt-methods [data-login-alt], .login-alt-icon, .login-footer-links [data-login-alt]', function (e) {
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

  Drupal.behaviors.dxPortalLogin = {
    attach: function (context) {
      if (!isLoginPath()) {
        return;
      }
      once('dx-portal-login', '#login-professional-container', context).forEach(function () {
        bindGoogleButton();
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
