/**
 * @file
 * Force-bind save on /edu/profile/setup regardless of template/controller.
 */
(function (Drupal, drupalSettings) {
  'use strict';

  function qs(sel, root) {
    return (root || document).querySelector(sel);
  }

  function getEndpoint() {
    var s = (drupalSettings && drupalSettings.dxEduProfileFix) || {};
    if (s.endpoint) return s.endpoint;
    if (drupalSettings && drupalSettings.dxYouth && drupalSettings.dxYouth.eduProfileEndpoint) {
      return drupalSettings.dxYouth.eduProfileEndpoint;
    }
    return '/dx/youth/edu-profile-save';
  }

  function getToken() {
    var s = (drupalSettings && drupalSettings.dxEduProfileFix) || {};
    if (s.csrfToken) return Promise.resolve(s.csrfToken);
    if (drupalSettings && drupalSettings.dxYouth && drupalSettings.dxYouth.csrfToken) {
      return Promise.resolve(drupalSettings.dxYouth.csrfToken);
    }
    return fetch('/session/token', { credentials: 'same-origin' })
      .then(function (r) { return r.text(); })
      .catch(function () { return ''; });
  }

  function ensureMessageEl() {
    var el = qs('#profile-message') || qs('.profile-message') || qs('#dx-edu-save-msg');
    if (el) return el;
    el = document.createElement('div');
    el.id = 'dx-edu-save-msg';
    el.className = 'profile-message';
    el.style.cssText = 'margin:8px 0;padding:8px;border-radius:4px;';
    var form = qs('#edu-profile-form') || qs('form');
    if (form) form.insertBefore(el, form.firstChild);
    else document.body.appendChild(el);
    return el;
  }

  function showMsg(text, ok) {
    var el = ensureMessageEl();
    el.hidden = false;
    el.textContent = text;
    el.style.background = ok ? '#d4edda' : '#f8d7da';
    el.style.color = ok ? '#155724' : '#721c24';
  }

  function collectData() {
    var tierEl = qs('input[name="tier"]:checked');
    var tier = tierEl ? tierEl.value : 'k12';
    var data = { tier: tier };
    function val(id) {
      var n = qs(id);
      return n ? (n.value || '') : '';
    }
    if (tier === 'k12') {
      data.real_name = val('#real_name');
      var schoolVal = val('#school');
      data.school = schoolVal === '__other__' ? val('#school_other') : schoolVal;
      data.grade_year = parseInt(val('#grade_year'), 10) || 0;
      data.class_name = val('#class_name');
      data.interests = val('#interests_text');
    } else if (tier === 'highschool') {
      data.real_name = val('#hs_real_name');
      var hsVal = val('#hs_school');
      data.school = hsVal === '__other__' ? val('#hs_school_other') : hsVal;
      data.specialty = val('#hs_specialty');
      data.career_interest = val('#hs_career');
      data.interests = val('#hs_interests');
    } else {
      data.real_name = val('#col_real_name');
      var colVal = val('#col_university');
      data.university = colVal === '__other__' ? val('#col_university_other') : colVal;
      data.major = val('#major');
      data.enrollment_year = parseInt(val('#enrollment_year'), 10) || 0;
      data.career_interest = val('#col_career');
      data.bio = val('#col_bio');
    }
    if (!data.real_name) {
      var nameInput = qs('input[name="real_name"]:not([type=hidden])') || qs('input[id*="real_name"]');
      if (nameInput) data.real_name = nameInput.value || '';
    }
    return data;
  }

  function doSave(e) {
    if (e) {
      e.preventDefault();
      e.stopPropagation();
    }
    var btn = e && e.currentTarget ? e.currentTarget : null;
    if (btn) btn.disabled = true;
    showMsg('正在保存... (v1.0.8)', true);
    var data = collectData();
    if (!data.real_name) {
      showMsg('请填写姓名', false);
      if (btn) btn.disabled = false;
      return false;
    }
    getToken().then(function (token) {
      return fetch(getEndpoint(), {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-Token': token || ''
        },
        body: JSON.stringify(data)
      }).then(function (res) {
        return res.json().catch(function () { return { ok: false, message: '响应不是 JSON (HTTP ' + res.status + ')' }; });
      });
    }).then(function (result) {
      if (result && result.ok) {
        showMsg('保存成功！正在跳转...', true);
        setTimeout(function () {
          window.location.href = result.redirect || '/edu';
        }, 600);
      } else {
        showMsg((result && result.message) || '保存失败，请重试', false);
        if (btn) btn.disabled = false;
      }
    }).catch(function (err) {
      showMsg('网络错误: ' + (err && err.message ? err.message : '请重试'), false);
      if (btn) btn.disabled = false;
    });
    return false;
  }

  function findSaveButtons() {
    var list = [];
    var selectors = [
      '#edu-profile-save-btn',
      'button.edu-profile-save-btn',
      '#edu-profile-form button',
      'form.edu-profile-form button',
      'button[type="submit"]',
      'input[type="submit"]'
    ];
    selectors.forEach(function (sel) {
      document.querySelectorAll(sel).forEach(function (el) {
        var text = (el.innerText || el.value || '').trim();
        if (/保存/.test(text) || el.id === 'edu-profile-save-btn' || el.classList.contains('edu-profile-save-btn')) {
          if (list.indexOf(el) === -1) list.push(el);
        }
      });
    });
    document.querySelectorAll('button, input[type=submit], a.button').forEach(function (el) {
      var text = (el.innerText || el.value || '').trim();
      if (text.indexOf('保存并继续') !== -1 && list.indexOf(el) === -1) {
        list.push(el);
      }
    });
    return list;
  }

  function stripHiddenRequired() {
    var active = (qs('input[name="tier"]:checked') || {}).value || 'k12';
    ['#highschool-fields', '#college-fields', '#k12-fields'].forEach(function (sel) {
      var box = qs(sel);
      if (!box) return;
      var on = sel.indexOf(active) !== -1;
      box.querySelectorAll('[required]').forEach(function (el) {
        if (!on) el.removeAttribute('required');
      });
    });
    var form = qs('#edu-profile-form') || qs('form');
    if (form) form.setAttribute('novalidate', 'novalidate');
  }

  function bind() {
    stripHiddenRequired();
    var buttons = findSaveButtons();
    buttons.forEach(function (btn) {
      if (btn.getAttribute('data-dx-force-save')) return;
      btn.setAttribute('data-dx-force-save', '1');
      if (btn.tagName === 'BUTTON') {
        btn.setAttribute('type', 'button');
      }
      btn.addEventListener('click', doSave, true);
    });
    var form = qs('#edu-profile-form') || qs('form.edu-profile-form');
    if (form && !form.getAttribute('data-dx-force-save')) {
      form.setAttribute('data-dx-force-save', '1');
      form.setAttribute('novalidate', 'novalidate');
      form.addEventListener('submit', doSave, true);
    }
    if (buttons.length) {
      console.info('[dx_edu_profile_fix] bound', buttons.length, 'save button(s), v1.0.8');
    }
  }

  Drupal.behaviors.dxEduProfileForceSave = {
    attach: function () {
      bind();
      setTimeout(bind, 200);
      setTimeout(bind, 800);
      setTimeout(bind, 2000);
    }
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bind);
  } else {
    bind();
  }
})(Drupal, drupalSettings);
