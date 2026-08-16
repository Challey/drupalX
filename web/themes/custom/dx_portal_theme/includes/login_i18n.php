<?php

/**
 * @file
 * Login UI string map for dx_portal_theme (zh-hans / zh-hant / en).
 */

/**
 * Returns translated login UI strings for the current language.
 *
 * @param string|null $langcode
 *   Optional language ID. Defaults to the current interface language.
 *
 * @return array
 *   Associative array of UI strings.
 */
function dx_portal_theme_login_i18n_strings($langcode = NULL) {
  if ($langcode === NULL) {
    $langcode = \Drupal::languageManager()->getCurrentLanguage()->getId();
  }

  $catalog = [
    'en' => [
      'sign_in' => 'Sign in',
      'choose_method' => 'Sign in with your enterprise credit ID',
      'login_methods' => 'Login methods',
      'enterprise_login' => 'Enterprise ID',
      'enterprise_brand' => 'Enterprise credit ID',
      'enterprise_lead' => 'Sign in with the unified social credit code from the market supervision bureau.',
      'enterprise_header_sub' => 'Sign in with market-supervision unified social credit code',
      'enterprise_your_id' => 'Enterprise credit ID',
      'enterprise_password' => 'Password',
      'enterprise_pass_hint' => 'Password of the account bound to this enterprise.',
      'enterprise_submit' => 'Sign in with enterprise ID',
      'enterprise_footnote' => 'Admins can bind credit IDs under Configuration → Enterprise login.',
      'wechat_short' => 'WeChat',
      'mobile_short' => 'Mobile',
      'account_short' => 'Account',
      'back_to_methods' => 'Back',
      'phone_hint' => 'On mobile: scan with another phone, or long-press the QR code',
      'in_wechat' => 'You are in WeChat',
      'wechat_signin' => 'Sign in with WeChat',
      'wechat_authorize' => 'Authorize once to sign in instantly',

      'enterprise_id' => 'Enterprise credit ID',
      'enterprise_placeholder' => 'Unified social credit code (18 characters)',
      'enterprise_hint' => 'Market supervision bureau unified social credit code',
      'lookup' => 'Look up',
      'company_label' => 'Company',
      'account_login' => 'Account login',
      'other_methods' => 'Other login methods',
      'qr_login' => 'WeChat',
      'mobile_login' => 'Mobile',
      'loading_qr' => 'Loading QR code…',
      'scan_wechat' => 'Scan with WeChat to sign in',
      'wechat_unavailable' => 'WeChat login is not available on this portal yet.',
      'mobile_unavailable' => 'Mobile login is not available on this portal yet.',
      'network_error' => 'Network error',
      'csrf_error' => 'Session expired. Refresh the page and try again.',
      'company_label' => 'Company',
      'sign_up' => 'Sign up',
      'reset_password' => 'Reset password',
      'login_alt' => 'Login',
      'log_in' => 'Log in',
      'username_email' => 'Username or email address',
      'password' => 'Password',
      'enter_user_pass' => 'Please enter your username and password.',
      'enter_enterprise_pass' => 'Please enter enterprise ID and password.',
      'enter_enterprise' => 'Please enter a valid enterprise credit ID.',
      'not_found' => 'Enterprise ID not found.',
      'mobile_number' => 'Mobile number',
      'mobile_placeholder' => 'Mobile number',
      'verification_code' => 'Verification code',
      'send_code' => 'Send code',
    ],
    'zh-hans' => [
      'sign_in' => '登录',
      'choose_method' => '使用企业ID（统一社会信用代码）登录',
      'login_methods' => '登录方式',
      'enterprise_login' => '企业ID',
      'enterprise_brand' => '企业信用 ID',
      'enterprise_lead' => '使用市场监督管理局分配的统一社会信用代码登录。',
      'enterprise_header_sub' => '使用市场监督管理局统一社会信用代码登录',
      'enterprise_your_id' => '企业信用代码',
      'enterprise_password' => '登录密码',
      'enterprise_pass_hint' => '使用已绑定该企业的账号密码。',
      'enterprise_submit' => '企业ID登录',
      'enterprise_footnote' => '管理员可在「配置 → 企业登录」绑定信用代码与账号。',
      'wechat_short' => '微信',
      'mobile_short' => '手机',
      'account_short' => '账号',
      'back_to_methods' => '返回',
      'phone_hint' => '手机端可用其他手机扫码登录，也可长按二维码本机登录',
      'in_wechat' => '检测到微信环境',
      'wechat_signin' => '微信一键登录',
      'wechat_authorize' => '授权后即可快速登录',

      'enterprise_id' => '企业ID',
      'enterprise_placeholder' => '市场监督局统一社会信用代码（18位）',
      'enterprise_hint' => '市场监督管理局统一社会信用代码',
      'lookup' => '查询',
      'company_label' => '企业名称',
      'account_login' => '账号登录',
      'other_methods' => '其他登录方式',
      'qr_login' => '微信',
      'mobile_login' => '手机',
      'loading_qr' => '正在加载二维码…',
      'scan_wechat' => '使用微信扫码登录',
      'wechat_unavailable' => '本门户暂未开通微信登录。',
      'mobile_unavailable' => '本门户暂未开通手机登录。',
      'network_error' => '网络错误',
      'csrf_error' => '登录会话已过期，请刷新页面后重试',
      'company_label' => '企业',
      'sign_up' => '注册新账号',
      'reset_password' => '重置密码',
      'login_alt' => '登录',
      'log_in' => '登录',
      'username_email' => '用户名或电子邮件地址',
      'password' => '密码',
      'enter_user_pass' => '请输入用户名和密码。',
      'enter_enterprise_pass' => '请输入企业ID和密码。',
      'enter_enterprise' => '请输入有效的企业统一社会信用代码。',
      'not_found' => '未找到该企业ID。',
      'mobile_number' => '手机号码',
      'mobile_placeholder' => '手机号码',
      'verification_code' => '验证码',
      'send_code' => '发送验证码',
    ],
    'zh-hant' => [
      'sign_in' => '登入',
      'choose_method' => '使用企業ID（統一社會信用代碼）登入',
      'login_methods' => '登入方式',
      'enterprise_login' => '企業ID',
      'enterprise_brand' => '企業信用 ID',
      'enterprise_lead' => '使用市場監督管理局分配的統一社會信用代碼登入。',
      'enterprise_id' => '企業ID',
      'enterprise_placeholder' => '市場監督局統一社會信用代碼（18位）',
      'enterprise_hint' => '市場監督管理局統一社會信用代碼',
      'lookup' => '查詢',
      'company_label' => '企業名稱',
      'account_login' => '帳號登入',
      'other_methods' => '其他登录方式',
      'qr_login' => '微信',
      'mobile_login' => '手機',
      'loading_qr' => '正在載入二維碼…',
      'scan_wechat' => '使用微信掃碼登入',
      'wechat_unavailable' => '本門戶暫未開通微信登入。',
      'mobile_unavailable' => '本門戶暫未開通手機登入。',
      'network_error' => '網路錯誤',
      'sign_up' => '註冊新帳號',
      'reset_password' => '重設密碼',
      'login_alt' => '登入',
      'log_in' => '登入',
      'username_email' => '使用者名稱或電子郵件地址',
      'password' => '密碼',
      'enter_user_pass' => '請輸入使用者名稱和密碼。',
      'enter_enterprise_pass' => '請輸入企業ID和密碼。',
      'enter_enterprise' => '請輸入有效的企業統一社會信用代碼。',
      'not_found' => '未找到該企業ID。',
      'mobile_number' => '手機號碼',
      'mobile_placeholder' => '手機號碼',
      'verification_code' => '驗證碼',
      'send_code' => '發送驗證碼',
    ],
  ];

  if (isset($catalog[$langcode])) {
    return $catalog[$langcode];
  }
  if (strpos($langcode, 'zh-hans') === 0 || $langcode === 'zh-cn' || $langcode === 'cn') {
    return $catalog['zh-hans'];
  }
  if (strpos($langcode, 'zh-hant') === 0 || $langcode === 'zh-tw' || $langcode === 'zh-hk') {
    return $catalog['zh-hant'];
  }
  return $catalog['en'];
}
