/**
 * @file
 * Compact enterprise login behaviors for DrupalX portal.
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

  function activateLoginTab(target) {
    var $tab = $('.login-tab[data-tab="' + target + '"]');
    if (!$tab.length) {
      return;
    }
    try {
      sessionStorage.setItem('dxLoginTab', target);
    }
    catch (err) {}
    $('.login-tab').removeClass('active').attr('aria-selected', 'false');
    $tab.addClass('active').attr('aria-selected', 'true');
    $('.login-panel').removeClass('active');
    $('#panel-' + target).addClass('active');
  }

  function preferredLoginTab() {
    var hash = (window.location.hash || '').replace(/^#/, '');
    if (hash === 'runner') {
      hash = 'enterprise';
    }
    if (hash && $('.login-tab[data-tab="' + hash + '"]').length) {
      return hash;
    }
    try {
      var saved = sessionStorage.getItem('dxLoginTab');
      if (saved && $('.login-tab[data-tab="' + saved + '"]').length) {
        return saved;
      }
    }
    catch (err) {}
    return 'enterprise';
  }

  function hideAltPanels() {
    $('#panel-qrcode, #panel-mobile').attr('hidden', true);
    $('.login-alt-btn').removeClass('is-active');
  }

  var lookupTimer = null;

  function doEnterpriseLookup() {
    var code = normalizeCredit($('#panel-edit-credit').val());
    var $company = $('#panel-enterprise-company');
    if (!code || code.length < 18) {
      $company.attr('hidden', true).text('');
      return;
    }
    var cfg = authConfig();
    $.ajax({
      url: apiPath(cfg.lookupPath || 'dx/auth/enterprise_lookup'),
      type: 'GET',
      data: { credit_code: code },
      dataType: 'json',
      success: function (data) {
        if (data && data.code == 1 && data.data) {
          var name = data.data.company_name || data.data.credit_code_masked || '';
          var label = loginI18n('company_label', '企业');
          $company.text(label + '：' + name).removeAttr('hidden');
          return;
        }
        $company.attr('hidden', true).text('');
      },
      error: function () {
        $company.attr('hidden', true).text('');
      }
    });
  }

  function scheduleLookup() {
    if (lookupTimer) {
      clearTimeout(lookupTimer);
    }
    lookupTimer = setTimeout(doEnterpriseLookup, 450);
  }

  function bindEnterprise() {
    var $lookup = $('#panel-enterprise-lookup');
    var $submit = $('#panel-enterprise-submit');

    $('#panel-edit-credit').off('input.dxEnt').on('input.dxEnt', function () {
      this.value = normalizeCredit(this.value);
      scheduleLookup();
    });

    $lookup.off('click.dxEntLookup').on('click.dxEntLookup', function (e) {
      e.preventDefault();
      var code = normalizeCredit($('#panel-edit-credit').val());
      if (!code) {
        alert(loginI18n('enter_enterprise', '请输入企业信用代码'));
        return;
      }
      doEnterpriseLookup();
    });

    $submit.off('click.dxEntLogin').on('click.dxEntLogin', function (e) {
      e.preventDefault();
      var code = normalizeCredit($('#panel-edit-credit').val());
      var pass = $('#panel-edit-enterprise-pass').val() || '';
      if (!code || !pass) {
        alert(loginI18n('enter_enterprise_pass', '请输入企业ID和密码'));
        return;
      }
      var $btn = $(this);
      if ($btn.data('busy')) {
        return;
      }
      $btn.data('busy', true).prop('disabled', true);
      var cfg = authConfig();
      $.ajax({
        url: apiPath(cfg.loginPath || 'dx/auth/enterprise_login'),
        type: 'POST',
        headers: {
          'X-CSRF-Token': cfg.csrfToken || ''
        },
        data: {
          credit_code: code,
          password: pass,
          destination: getLoginDestination()
        },
        dataType: 'json',
        success: function (data) {
          if (data && data.code == 1) {
            window.location.href = data.redirect || (data.data && data.data.redirect) || '/';
            return;
          }
          if (data && data.code == 2 && data.redirect) {
            window.location.href = data.redirect;
            return;
          }
          alert((data && data.msg) || loginI18n('network_error', '网络错误'));
        },
        error: function (xhr) {
          var msg = loginI18n('network_error', '网络错误');
          if (xhr && xhr.responseJSON && xhr.responseJSON.msg) {
            msg = xhr.responseJSON.msg;
          }
          else if (xhr && xhr.status === 403) {
            msg = loginI18n('csrf_error', '登录会话已过期，请刷新页面后重试');
          }
          alert(msg);
        },
        complete: function () {
          $btn.data('busy', false).prop('disabled', false);
        }
      });
    });
  }

  function bindAccount() {
    var $nativeForm = $('#user-login-form');
    if (!$nativeForm.length) {
      return false;
    }
    $('#panel-account-submit').off('click.dxAccount').on('click.dxAccount', function (e) {
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
    return true;
  }

  function bindAltMethods() {
    $('#login-alt-wechat').off('click.dxAltWechat').on('click.dxAltWechat', function (e) {
      e.preventDefault();
      hideAltPanels();
      $(this).addClass('is-active');
      $('#panel-qrcode').removeAttr('hidden');
      $('.login-qrcode-placeholder').text(loginI18n('wechat_unavailable', '微信登录暂未开通，请使用企业 ID 或账号登录。'));
    });

    $('#login-alt-mobile').off('click.dxAltMobile').on('click.dxAltMobile', function (e) {
      e.preventDefault();
      hideAltPanels();
      $(this).addClass('is-active');
      $('#panel-mobile').removeAttr('hidden');
    });

    $('#panel-send-code, #panel-mobile-submit').off('click.dxMobileStub').on('click.dxMobileStub', function (e) {
      e.preventDefault();
      alert(loginI18n('mobile_unavailable', '手机登录暂未开通，请使用企业 ID 或账号登录。'));
    });
  }

  function initLogin() {
    if (!isLoginPath() || !$('#login-professional-container').length) {
      return;
    }
    bindEnterprise();
    bindAccount();
    bindAltMethods();
    activateLoginTab(preferredLoginTab());

    $(document).off('click.dxLoginTabs', '.login-tab').on('click.dxLoginTabs', '.login-tab', function (e) {
      e.preventDefault();
      hideAltPanels();
      activateLoginTab($(this).data('tab'));
    });
  }

  Drupal.behaviors.dxPortalLogin = {
    attach: function (context) {
      once('dx-portal-login', '#login-professional-container', context).forEach(function () {
        initLogin();
      });
    }
  };

})(jQuery, Drupal, drupalSettings, once);
