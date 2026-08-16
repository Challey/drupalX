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

  function bindEnterprise() {
    var $lookup = $('#panel-enterprise-lookup');
    var $submit = $('#panel-enterprise-submit');
    var $company = $('#panel-enterprise-company');

    $lookup.off('click.dxEntLookup').on('click.dxEntLookup', function (e) {
      e.preventDefault();
      var code = ($('#panel-edit-credit').val() || '').trim();
      if (!code) {
        alert(loginI18n('enter_enterprise', 'Please enter a valid enterprise credit ID.'));
        return;
      }
      $company.attr('hidden', true).text('');
      $.ajax({
        url: '/dx/auth/enterprise_lookup',
        type: 'GET',
        data: { credit_code: code },
        dataType: 'json',
        success: function (data) {
          if (data && data.code == 1 && data.data) {
            var name = data.data.company_name || data.data.credit_code_masked || '';
            var label = loginI18n('company_label', 'Company');
            $company.text(label + '：' + name).removeAttr('hidden');
            return;
          }
          alert((data && data.msg) || loginI18n('not_found', 'Enterprise ID not found.'));
        },
        error: function () {
          alert(loginI18n('network_error', 'Network error'));
        }
      });
    });

    $submit.off('click.dxEntLogin').on('click.dxEntLogin', function (e) {
      e.preventDefault();
      var code = ($('#panel-edit-credit').val() || '').trim();
      var pass = $('#panel-edit-enterprise-pass').val() || '';
      if (!code || !pass) {
        alert(loginI18n('enter_enterprise_pass', 'Please enter enterprise ID and password.'));
        return;
      }
      var $btn = $(this);
      if ($btn.data('busy')) {
        return;
      }
      $btn.data('busy', true).prop('disabled', true);
      $.ajax({
        url: '/dx/auth/enterprise_login',
        type: 'POST',
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
          alert((data && data.msg) || loginI18n('network_error', 'Network error'));
        },
        error: function () {
          alert(loginI18n('network_error', 'Network error'));
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
        alert(loginI18n('enter_user_pass', 'Please enter your username and password.'));
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
      alert(loginI18n('wechat_unavailable', 'WeChat login is not available on this portal yet.'));
    });

    $('#login-alt-mobile').off('click.dxAltMobile').on('click.dxAltMobile', function (e) {
      e.preventDefault();
      hideAltPanels();
      $(this).addClass('is-active');
      $('#panel-mobile').removeAttr('hidden');
      alert(loginI18n('mobile_unavailable', 'Mobile login is not available on this portal yet.'));
    });

    $('#panel-send-code, #panel-mobile-submit').off('click.dxMobileStub').on('click.dxMobileStub', function (e) {
      e.preventDefault();
      alert(loginI18n('mobile_unavailable', 'Mobile login is not available on this portal yet.'));
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

  if (isLoginPath()) {
    $(initLogin);
  }

})(jQuery, Drupal, drupalSettings, once);
