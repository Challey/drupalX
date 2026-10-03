/**
 * @file
 * Education profile setup form logic.
 * Uses Drupal.behaviors for reliable initialization (works in WeChat WebView).
 * School data is read from a hidden JSON element embedded in the page.
 */
(function ($, Drupal, drupalSettings) {
  'use strict';

  Drupal.behaviors.eduProfileSetup = {
    attach: function (context, settings) {
      var $form = $('#edu-profile-form', context);
      if (!$form.length || $form.attr('data-edu-init') === '1') return;
      $form.attr('data-edu-init', '1');

      var dxSettings = settings.dxYouth || {};
      var existing = dxSettings.existingProfile;
      var endpoint = dxSettings.eduProfileEndpoint || '/dx/youth/edu-profile-save';

      var allDistricts = [];
      var allSchools = [];
      var $schoolData = $('#edu-school-data');
      if ($schoolData.length) {
        try {
          var data = JSON.parse($schoolData.text());
          allDistricts = data.districts || [];
          allSchools = data.schools || [];
        } catch (e) {
          console.error('Failed to parse school data:', e);
        }
      }
      if (!allDistricts.length && dxSettings.schoolDistricts) {
        allDistricts = dxSettings.schoolDistricts;
        allSchools = dxSettings.schoolList || [];
      }

      function getCsrfToken() {
        if (dxSettings.csrfToken) {
          return $.Deferred().resolve(dxSettings.csrfToken).promise();
        }
        return $.get('/session/token');
      }

      function populateDistrictFilters() {
        var selects = ['school-district', 'hs-school-district', 'col-school-district'];
        for (var i = 0; i < selects.length; i++) {
          var $select = $('#' + selects[i]);
          if (!$select.length) continue;
          for (var j = 0; j < allDistricts.length; j++) {
            $select.append($('<option>', {
              value: allDistricts[j].id,
              text: allDistricts[j].name
            }));
          }
        }
      }

      function updateSchoolList(districtId, levelId, selectId, searchId) {
        var $sel = $('#' + selectId);
        if (!$sel.length) return;
        var district = $('#' + districtId).val() || '';
        var level = $('#' + levelId).val() || '';
        var $searchEl = searchId ? $('#' + searchId) : null;
        var keyword = $searchEl && $searchEl.length ? $.trim($searchEl.val()).toLowerCase() : '';
        $sel.empty().append('<option value="">请选择学校</option>');
        var filtered = allSchools;
        if (district) {
          filtered = filtered.filter(function (s) { return s.district === district; });
        }
        if (level) {
          filtered = filtered.filter(function (s) { return s.level === level; });
        }
        if (keyword) {
          filtered = filtered.filter(function (s) { return s.name.toLowerCase().indexOf(keyword) !== -1; });
        }
        for (var i = 0; i < filtered.length; i++) {
          $sel.append($('<option>', {
            value: filtered[i].name,
            text: filtered[i].name
          }));
        }
        $sel.append('<option value="__other__">其他（手动输入）</option>');
        if ($searchEl && $searchEl.length) {
          $searchEl.attr('placeholder', filtered.length + ' 所学校匹配，输入继续搜索...');
        }
      }

      function bindSchoolOtherToggle(selectId, otherInputId) {
        var $sel = $('#' + selectId);
        var $input = $('#' + otherInputId);
        if (!$sel.length || !$input.length) return;
        $sel.on('change', function () {
          if (this.value === '__other__') {
            $(this).hide();
            $input.show().prop('required', true);
            $(this).prop('required', false);
            $input.focus();
          } else {
            $input.hide().prop('required', false);
            if ($(this).is(':hidden')) {
              $(this).show().prop('required', true);
            }
          }
        });
      }

      function switchTierFields(tier) {
        $('#k12-fields').toggle(tier === 'k12');
        $('#highschool-fields').toggle(tier === 'highschool');
        $('#college-fields').toggle(tier === 'college');
        var map = {
          k12: '#k12-fields',
          highschool: '#highschool-fields',
          college: '#college-fields'
        };
        Object.keys(map).forEach(function (key) {
          $(map[key]).find('input, select, textarea').each(function () {
            var $el = $(this);
            if (!$el.is('[required]') && !$el.is('[data-edu-required]')) {
              return;
            }
            $el.attr('data-edu-required', '1');
            if (key === tier) {
              $el.attr('required', 'required');
            } else {
              $el.removeAttr('required');
            }
          });
        });
      }

      function prefillExisting() {
        if (!existing || typeof existing !== 'object') {
          return;
        }
        var tier = existing.tier || 'k12';
        $('input[name="tier"][value="' + tier + '"]').prop('checked', true);
        switchTierFields(tier);
        if (tier === 'k12') {
          if (existing.real_name) $('#real_name').val(existing.real_name);
          if (existing.grade_year) $('#grade_year').val(existing.grade_year);
          if (existing.class_name) $('#class_name').val(existing.class_name);
          if (existing.interests) $('#interests_text').val(existing.interests);
        } else if (tier === 'highschool') {
          if (existing.real_name) $('#hs_real_name').val(existing.real_name);
          if (existing.specialty) $('#hs_specialty').val(existing.specialty);
          if (existing.career_interest) $('#hs_career').val(existing.career_interest);
          if (existing.interests) $('#hs_interests').val(existing.interests);
        } else {
          if (existing.real_name) $('#col_real_name').val(existing.real_name);
          if (existing.major) $('#major').val(existing.major);
          if (existing.enrollment_year) $('#enrollment_year').val(existing.enrollment_year);
          if (existing.career_interest) $('#col_career').val(existing.career_interest);
          if (existing.bio) $('#col_bio').val(existing.bio);
        }
      }

      populateDistrictFilters();
      updateSchoolList('school-district', 'school-level', 'school', 'school-search');
      updateSchoolList('hs-school-district', 'hs-school-level', 'hs_school', 'hs-school-search');
      updateSchoolList('col-school-district', 'col-school-level', 'col_university', 'col-school-search');
      bindSchoolOtherToggle('school', 'school_other');
      bindSchoolOtherToggle('hs_school', 'hs_school_other');
      bindSchoolOtherToggle('col_university', 'col_university_other');

      $('#school-district, #school-level').on('change', function () {
        updateSchoolList('school-district', 'school-level', 'school', 'school-search');
      });
      $('#hs-school-district, #hs-school-level').on('change', function () {
        updateSchoolList('hs-school-district', 'hs-school-level', 'hs_school', 'hs-school-search');
      });
      $('#col-school-district').on('change', function () {
        updateSchoolList('col-school-district', 'col-school-level', 'col_university', 'col-school-search');
      });

      function bindSearch(searchId, dId, lId, sId) {
        var $inp = $('#' + searchId);
        if (!$inp.length) return;
        var timer;
        $inp.on('input', function () {
          clearTimeout(timer);
          timer = setTimeout(function () {
            updateSchoolList(dId, lId, sId, searchId);
          }, 200);
        });
      }
      bindSearch('school-search', 'school-district', 'school-level', 'school');
      bindSearch('hs-school-search', 'hs-school-district', 'hs-school-level', 'hs_school');
      bindSearch('col-school-search', 'col-school-district', 'col-school-level', 'col_university');

      $('input[name="tier"]').on('change', function () {
        switchTierFields(this.value);
      });

      prefillExisting();

      function handleFormSave(e) {
        if (e) e.preventDefault();
        var $msgEl = $('#profile-message');
        var $btn = $form.find('#edu-profile-save-btn, button.edu-profile-save-btn, button[type="submit"]').first();
        $btn.prop('disabled', true);
        $msgEl.text('正在保存...').attr('class', 'profile-message');

        var tier = $('input[name="tier"]:checked').val();
        if (!tier) {
          $msgEl.text('请选择学段').attr('class', 'profile-message error');
          $btn.prop('disabled', false);
          return;
        }
        var data = { tier: tier };
        if (tier === 'k12') {
          data.real_name = $('#real_name').val();
          var schoolVal = $('#school').val();
          data.school = schoolVal === '__other__' ? $('#school_other').val() : schoolVal;
          data.grade_year = parseInt($('#grade_year').val()) || 0;
          data.class_name = $('#class_name').val();
          data.interests = $('#interests_text').val();
        } else if (tier === 'highschool') {
          data.real_name = $('#hs_real_name').val();
          var hsVal = $('#hs_school').val();
          data.school = hsVal === '__other__' ? $('#hs_school_other').val() : hsVal;
          data.specialty = $('#hs_specialty').val();
          data.career_interest = $('#hs_career').val();
          data.interests = $('#hs_interests').val();
        } else {
          data.real_name = $('#col_real_name').val();
          var colVal = $('#col_university').val();
          data.university = colVal === '__other__' ? $('#col_university_other').val() : colVal;
          data.major = $('#major').val();
          data.enrollment_year = parseInt($('#enrollment_year').val()) || 0;
          data.career_interest = $('#col_career').val();
          data.bio = $('#col_bio').val();
        }

        getCsrfToken().then(function (token) {
          return $.ajax({
            url: endpoint,
            method: 'POST',
            contentType: 'application/json',
            headers: { 'X-CSRF-Token': token },
            data: JSON.stringify(data)
          });
        }).done(function (result) {
          if (result.ok) {
            $msgEl.text('保存成功！正在跳转...').attr('class', 'profile-message success');
            setTimeout(function () {
              window.location.href = result.redirect || '/edu';
            }, 800);
          } else {
            $msgEl.text(result.message || '保存失败').attr('class', 'profile-message error');
            $btn.prop('disabled', false);
          }
        }).fail(function () {
          $msgEl.text('网络错误，请重试。').attr('class', 'profile-message error');
          $btn.prop('disabled', false);
        });
      }

      $form.attr('novalidate', 'novalidate');
      switchTierFields($('input[name="tier"]:checked').val() || 'k12');

      $form.on('submit', handleFormSave);
      $form.find('#edu-profile-save-btn, button.edu-profile-save-btn, button[type="submit"], button.profile-save-btn').on('click', function (e) {
        e.preventDefault();
        handleFormSave(e);
      });
    }
  };

})(jQuery, Drupal, drupalSettings);
