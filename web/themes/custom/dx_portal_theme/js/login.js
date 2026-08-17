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

  function setPanelMessage($panel, message, kind) {
    var $msg = $panel.find('.login-panel-message').first();
    if (!$msg.length) {
      $msg = $('<p class="login-panel-message" role="status" aria-live="polite"></p>');
      $panel.prepend($msg);
    }
    if (!message) {
      $msg.attr('hidden', true).text('').removeClass('is-error is-info is-ok');
      return;
    }
    $msg
      .text(message)
      .removeClass('is-error is-info is-ok')
      .addClass(kind === 'error' ? 'is-error' : (kind === 'ok' ? 'is-ok' : 'is-info'))
      .removeAttr('hidden');
  }

  function setEnterpriseStatus(message, kind) {
    setPanelMessage($('#panel-enterprise'), message, kind || 'info');
  }

  var lookupTimer = null;
  var enterpriseBound = false;

  function doEnterpriseLookup() {
    var code = normalizeCredit($('#panel-edit-credit').val());
    var $preview = $('#panel-enterprise-preview');
    if (!code || code.length < 18) {
      $preview.attr('hidden', true);
      setEnterpriseStatus('', 'info');
      return;
    }
    var cfg = authConfig();
    $.ajax({
      url: apiPath(cfg.lookupPath || 'dx/auth/enterprise_lookup'),
      type: 'GET',
      data: { credit_code: code },
      dataType: 'json',
      success: function (data) {
        if (!(data && data.data)) {
          $preview.attr('hidden', true);
          setEnterpriseStatus(
            (data && data.msg) || loginI18n('not_found', '未找到该企业ID。'),
            'error'
          );
          return;
        }
        var d = data.data;
        if (!(data.code == 1)) {
          $preview.attr('hidden', true);
          $('#panel-enterprise-preview-code').text(d.credit_code_masked || code);
          setEnterpriseStatus(
            (data && data.msg) || loginI18n('not_found', '未找到该企业ID。'),
            'error'
          );
          return;
        }
        $('#panel-enterprise-preview-code').text(d.credit_code_masked || code);
        $('#panel-enterprise-preview-name').text(d.company_name || '—');
        $preview.removeAttr('hidden');

        if (d.has_portal) {
          setEnterpriseStatus(
            loginI18n('portal_hint', '该企业有专属门户，登录后将引导前往门户。'),
            'info'
          );
        }
        else if (d.bound === false) {
          setEnterpriseStatus(
            loginI18n('unbound_hint', '该企业尚未绑定登录账号，请联系管理员绑定。'),
            'error'
          );
        }
        else {
          setEnterpriseStatus(
            loginI18n('lookup_ok', '已识别企业，请输入绑定账号密码登录。'),
            'ok'
          );
        }
      },
      error: function () {
        $preview.attr('hidden', true);
        setEnterpriseStatus(loginI18n('network_error', '网络错误'), 'error');
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
          setEnterpriseStatus(loginI18n('enter_enterprise', '请输入企业信用代码'), 'error');
          return false;
        }
        if (!pass) {
          setEnterpriseStatus(loginI18n('enter_enterprise_pass', '请输入密码'), 'error');
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
        setEnterpriseStatus(loginI18n('signing_in', '正在登录…'), 'info');
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
            setEnterpriseStatus((data && data.msg) || loginI18n('network_error', '网络错误'), 'error');
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
            setEnterpriseStatus(msg, 'error');
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
      var nameVal = $('#panel-edit-name').val() || '';
      var passVal = $('#panel-edit-pass').val() || '';
      var $panel = $('#panel-account');
      if (!nameVal || !passVal) {
        setPanelMessage($panel, loginI18n('enter_user_pass', '请输入用户名和密码。'), 'error');
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

    $('#panel-mobile').off('click.loginSend', '.send_btn').on('click.loginSend', '.send_btn', function (e) {
      e.preventDefault();
      setPanelMessage(
        $('#panel-mobile'),
        loginI18n('mobile_unavailable', '手机登录暂未开通，请使用企业ID或账号登录。'),
        'info'
      );
    });

    $('#panel-mobile').off('click.loginMobile', '.form-submit').on('click.loginMobile', '.form-submit', function (e) {
      e.preventDefault();
      setPanelMessage(
        $('#panel-mobile'),
        loginI18n('mobile_unavailable', '手机登录暂未开通，请使用企业ID或账号登录。'),
        'info'
      );
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
      $('.login-qrcode-placeholder').text(
        loginI18n('wechat_unavailable', '微信登录暂未开通，请使用企业ID或账号登录。')
      );
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
