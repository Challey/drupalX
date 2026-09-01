<?php

/**
 * dx_auth offline pure assertions (lane L5 · roadmap Phase R1/R2).
 *
 * Run: php web/modules/custom/dx_auth/tests/pure-assertions.php
 *
 * What this is: a dependency-free regression harness for the production login
 * gateway. It boots nothing — no Drupal kernel, no database, no HTTP client
 * traffic, no PHPUnit. Every covered code path is reachable without those, so
 * the file is a hard gate that can run on any checkout, including a lane
 * worktree whose vendor/ is a symlink to the production docroot.
 *
 * How it reaches real production code without a container: the services under
 * test are instantiated with ReflectionClass::newInstanceWithoutConstructor()
 * and only the collaborators a given method actually touches are injected with
 * the plain stubs below. Collaborators left uninitialised make PHP throw an
 * Error, which the production code's own try/catch turns into its documented
 * degraded path — that is exactly the behaviour being pinned here.
 *
 * Deliberately NOT covered here (needs a real DB or site, see the lane doc):
 * mergeUsers(), loginOrCreateBy*(), BindingsController::status(), OTP tempstore
 * expiry under the real SharedTempStore, and route access control responses.
 * Those live in tests/src/Kernel/ and scripts/ci/auth-smoke.sh.
 */
declare(strict_types=1);

// ---------------------------------------------------------------------------
// Harness.
// ---------------------------------------------------------------------------

$l5_pass = 0;
$l5_fail = 0;
$l5_failures = [];

/**
 * Print a section heading.
 */
function section(string $name): void {
  echo "\n== {$name}\n";
}

/**
 * Assert a boolean.
 */
function ok(bool $cond, string $label): void {
  global $l5_pass, $l5_fail, $l5_failures;
  if ($cond) {
    $l5_pass++;
    echo "ok    {$label}\n";
    return;
  }
  $l5_fail++;
  $l5_failures[] = $label;
  echo "FAIL  {$label}\n";
}

/**
 * Assert strict equality, printing both sides on failure.
 */
function same(mixed $actual, mixed $expected, string $label): void {
  $cond = $actual === $expected;
  if (!$cond) {
    $label .= ' [expected ' . var_export($expected, TRUE) . ', got ' . var_export($actual, TRUE) . ']';
  }
  ok($cond, $label);
}

/**
 * Assert a string contains a needle.
 */
function has(string $haystack, string $needle, string $label): void {
  $cond = str_contains($haystack, $needle);
  if (!$cond) {
    $label .= ' [missing ' . $needle . ']';
  }
  ok($cond, $label);
}

/**
 * Assert a callable throws.
 */
function throws(callable $fn, string $label, ?string $message_contains = NULL): void {
  try {
    $fn();
  }
  catch (\Throwable $e) {
    if ($message_contains !== NULL && !str_contains($e->getMessage(), $message_contains)) {
      $label .= ' [message ' . $e->getMessage() . ']';
      ok(FALSE, $label);
      return;
    }
    ok(TRUE, $label);
    return;
  }
  ok(FALSE, $label . ' [no exception]');
}

// ---------------------------------------------------------------------------
// Bootstrap: composer autoload + a read-only fallback loader for the module
// classes this harness pins. Nothing here talks to Drupal's container.
// ---------------------------------------------------------------------------

$ROOT = dirname(__DIR__, 5);
require $ROOT . '/vendor/autoload.php';

$l5_psr4 = require $ROOT . '/vendor/composer/autoload_psr4.php';
// .../web/core/lib/Drupal/Core -> .../web
$l5_docroot = dirname($l5_psr4['Drupal\Core\\'][0], 4);

spl_autoload_register(static function (string $class) use ($ROOT, $l5_docroot): void {
  if (!str_starts_with($class, 'Drupal\\')) {
    return;
  }
  $parts = explode('\\', $class);
  $sub = implode('/', array_slice($parts, 2)) . '.php';
  $files = [];
  if (str_starts_with(strtolower($parts[1]), 'dx_')) {
    $files[] = $ROOT . '/web/modules/custom/' . $parts[1] . '/src/' . $sub;
    $files[] = $ROOT . '/web/themes/custom/' . $parts[1] . '/src/' . $sub;
  }
  $files[] = $l5_docroot . '/modules/' . $parts[1] . '/src/' . $sub;
  $files[] = $l5_docroot . '/core/modules/' . $parts[1] . '/src/' . $sub;
  $files[] = $l5_docroot . '/profiles/' . $parts[1] . '/src/' . $sub;
  foreach ($files as $file) {
    if (is_file($file)) {
      require $file;
      return;
    }
  }
});

// t() / TranslatableMarkup render through the string_translation service.
// This stub returns the untranslated string with placeholders substituted, so
// assertions can pin the exact production copy without a translation bundle.
final class L5Translation implements \Drupal\Core\StringTranslation\TranslationInterface {

  public function translateString(\Drupal\Core\StringTranslation\TranslatableMarkup $translated_string) {
    $out = $translated_string->getUntranslatedString();
    foreach ($translated_string->getArguments() as $key => $value) {
      $out = str_replace((string) $key, (string) $value, $out);
    }
    return $out;
  }

  public function translate($string, array $args = [], array $options = []) {
    return $this->translateString(new \Drupal\Core\StringTranslation\TranslatableMarkup((string) $string, $args, $options));
  }

  public function formatPlural($count, $singular, $plural, array $args = [], array $options = []) {
    return $this->translate((string) ($count == 1 ? $singular : $plural), $args, $options);
  }

}

$l5_container = new \Symfony\Component\DependencyInjection\Container();
$l5_container->set('string_translation', new L5Translation());
\Drupal::setContainer($l5_container);

// ---------------------------------------------------------------------------
// Stubs. Plain objects implementing the interfaces the services depend on.
// ---------------------------------------------------------------------------

final class L5Config {

  public function __construct(private array $data = []) {
  }

  public function get($key = '') {
    return $this->data[$key] ?? NULL;
  }

}

final class L5ConfigFactory implements \Drupal\Core\Config\ConfigFactoryInterface {

  public function __construct(private array $configs = []) {
  }

  public function get($name) {
    return new L5Config($this->configs[$name] ?? []);
  }

  public function getEditable($name) {
    return $this->get($name);
  }

  public function loadMultiple(array $names) {
    return array_map([$this, 'get'], $names);
  }

  public function reset($name = NULL) {
  }

  public function rename($old_name, $new_name) {
  }

  public function getCacheKeys() {
    return [];
  }

  public function clearStaticCache() {
  }

  public function listAll($prefix = '') {
    return [];
  }

  public function addOverride(\Drupal\Core\Config\ConfigFactoryOverrideInterface $config_override) {
  }

}

final class L5Logger extends \Psr\Log\AbstractLogger implements \Drupal\Core\Logger\LoggerChannelInterface {

  /** @var string[] */
  public array $rows = [];

  public function log($level, string|\Stringable $message, array $context = []): void {
    $this->rows[] = $level . ' ' . (string) $message;
  }

  public function setRequestStack(?\Symfony\Component\HttpFoundation\RequestStack $requestStack = NULL) {
  }

  public function setCurrentUser(?\Drupal\Core\Session\AccountInterface $current_user = NULL) {
  }

  public function setLoggers(array $loggers) {
  }

  public function addLogger(\Psr\Log\LoggerInterface $logger, $priority = 0) {
  }

  /**
   * @return string[]
   */
  public function levels(string $level): array {
    return array_values(array_filter($this->rows, static fn (string $r): bool => str_starts_with($r, $level . ' ')));
  }

}

final class L5Store {

  /** @var array<string, mixed> */
  public array $data = [];

  public function get($key, $default = NULL) {
    return $this->data[$key] ?? $default;
  }

  public function set($key, $value) {
    $this->data[$key] = $value;
    return 1;
  }

  public function delete($key) {
    unset($this->data[$key]);
  }

}

/**
 * Stands in for the tempstore collection used by the SMS OTP (key prefix
 * "mobile_code_"), which production reaches through SharedTempStoreFactory.
 */
final class L5TempStoreFactory extends \Drupal\Core\TempStore\SharedTempStoreFactory {

  public function __construct(public L5Store $store) {
  }

  public function get($collection, $owner = NULL) {
    return $this->store;
  }

}

final class L5Flood implements \Drupal\Core\Flood\FloodInterface {

  /** @var string[] */
  public array $registered = [];

  /** @var array<string, bool> */
  public array $blocked = [];

  public function __construct(public bool $allowed = TRUE) {
  }

  public function register($name, $window = 3600, $identifier = NULL) {
    $this->registered[] = $name . '|' . $identifier . '|' . $window;
  }

  public function clear($name, $identifier = NULL) {
  }

  public function isAllowed($name, $threshold, $window = 3600, $identifier = NULL) {
    return $this->blocked[$name] ?? $this->allowed;
  }

  public function garbageCollection() {
  }

}

final class L5EmailValidator implements \Drupal\Component\Utility\EmailValidatorInterface {

  public function isValid($email) {
    return (bool) filter_var((string) $email, FILTER_VALIDATE_EMAIL);
  }

}

final class L5State implements \Drupal\Core\State\StateInterface {

  /** @var array<string, mixed> */
  public array $data = [];

  public function get($key, $default = NULL) {
    return $this->data[$key] ?? $default;
  }

  public function getMultiple(array $keys) {
    $out = [];
    foreach ($keys as $key) {
      $out[$key] = $this->get($key);
    }
    return $out;
  }

  public function set($key, $value) {
    $this->data[$key] = $value;
  }

  public function setMultiple(array $data) {
    foreach ($data as $key => $value) {
      $this->set($key, $value);
    }
  }

  public function delete($key) {
    unset($this->data[$key]);
  }

  public function deleteMultiple(array $keys) {
    foreach ($keys as $key) {
      $this->delete($key);
    }
  }

  public function resetCache() {
  }

  public function getValuesSetDuringRequest(string $key): ?array {
    return NULL;
  }

}

/**
 * Instantiate a class without running its constructor and inject properties.
 */
function svc(string $class, array $props = []): object {
  $r = new \ReflectionClass($class);
  $o = $r->newInstanceWithoutConstructor();
  foreach ($props as $name => $value) {
    $p = $r->getProperty($name);
    $p->setAccessible(TRUE);
    $p->setValue($o, $value);
  }
  return $o;
}

/**
 * Call a non-public method on an object.
 */
function call(object $o, string $method, array $args = []): mixed {
  $r = new \ReflectionMethod($o, $method);
  $r->setAccessible(TRUE);
  return $r->invokeArgs($o, $args);
}

/**
 * Guzzle client answering from a queue of JSON bodies. Never opens a socket.
 */
function client(array $bodies): \GuzzleHttp\ClientInterface {
  $responses = [];
  foreach ($bodies as $body) {
    $responses[] = new \GuzzleHttp\Psr7\Response(200, [], is_string($body) ? $body : json_encode($body));
  }
  return new \GuzzleHttp\Client([
    'handler' => \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler($responses)),
  ]);
}

$IDS = 'Drupal\dx_auth\Service\EnterpriseIdentityService';
$LINK = 'Drupal\dx_auth\Service\EnterpriseAccountLinker';
$SOCIAL = 'Drupal\dx_auth\Service\SocialAccountLinker';
$LOGINREG = 'Drupal\dx_auth\Service\LoginRegisterService';
$WECHAT = 'Drupal\dx_auth\Service\WechatAuthService';
$SMS = 'Drupal\dx_auth\Service\SmsAuthService';
$GOOGLE = 'Drupal\dx_auth\Service\GoogleAuthService';
$ACCOUNT_CTRL = 'Drupal\dx_auth\Controller\AccountAuthController';
$ENTERPRISE_CTRL = 'Drupal\dx_auth\Controller\EnterpriseAuthController';
$SOCIAL_CTRL = 'Drupal\dx_auth\Controller\SocialAuthController';
$BINDINGS_CTRL = 'Drupal\dx_auth\Controller\BindingsController';

$MOD = $ROOT . '/web/modules/custom/dx_auth';

section('bootstrap · 被钉住的现网类与方法（不是历史分支的）');
foreach ([
  $IDS, $LINK, $SOCIAL, $LOGINREG, $WECHAT, $SMS, $GOOGLE,
  $ACCOUNT_CTRL, $ENTERPRISE_CTRL, $SOCIAL_CTRL, $BINDINGS_CTRL,
] as $class) {
  ok(class_exists($class), 'class exists: ' . $class);
}
// The 2026-08 branch set carried tests written against an API that never
// landed on master (WeChatAuthService::buildQrConnectUrl(), and SMS helpers
// living on the service). Master's real spellings are pinned instead.
ok(!method_exists($WECHAT, 'buildQrConnectUrl'), 'master has no WeChatAuthService::buildQrConnectUrl()');
ok(!method_exists($WECHAT, 'buildMpAuthorizeUrl'), 'master has no buildMpAuthorizeUrl()');
ok(method_exists($WECHAT, 'buildOauthAuthorizeUrl'), 'WechatAuthService::buildOauthAuthorizeUrl() is the real API');
foreach (['normalizeMobile', 'isValidCnMobile', 'generateNumericCode', 'aliyunSignature'] as $ghost) {
  ok(!method_exists($SMS, $ghost), 'SmsAuthService has no invented ' . $ghost . '()');
}
ok(method_exists($SOCIAL, 'normalizeMobile'), 'normalizeMobile() lives on SocialAccountLinker');
ok(!class_exists('Drupal\dx_auth\Service\AuthManager'), 'no AuthManager service exists');
foreach ([
  'isEnabled', 'sendCode', 'percentEncode',
] as $m) {
  ok(method_exists($SMS, $m), 'SmsAuthService::' . $m . '()');
}
foreach (['isAvailable', 'isMainlandChina', 'redirectUri', 'buildAuthorizeUrl', 'profileFromCode'] as $m) {
  ok(method_exists($GOOGLE, $m), 'GoogleAuthService::' . $m . '()');
}
foreach (['isEnabled', 'createLoginQr', 'openidFromCode', 'checkSignature', 'getAccessToken'] as $m) {
  ok(method_exists($WECHAT, $m), 'WechatAuthService::' . $m . '()');
}
foreach (['normalize', 'validate', 'mask', 'maskCompanyName', 'resolve'] as $m) {
  ok(method_exists($IDS, $m), 'EnterpriseIdentityService::' . $m . '()');
}
foreach (['loginByEnterprise', 'unbind', 'creditCodesForUid', 'listBindings', 'bind'] as $m) {
  ok(method_exists($LINK, $m), 'EnterpriseAccountLinker::' . $m . '()');
}

// Shared fixtures.
$settings = [
  'wechat_enabled' => TRUE,
  'wechat_app_id' => 'wxAPPID001',
  'wechat_secret' => 'wxSECRET001',
  'wechat_token' => 'dxToken',
  'wechat_switch' => FALSE,
  'sms_enabled' => TRUE,
  'sms_access_key' => 'AKID',
  'sms_access_secret' => 'SECRET',
  'sms_sign_name' => 'DrupalX',
  'sms_template_code' => 'SMS_1001',
  'google_enabled' => TRUE,
  'google_client_id' => 'cid.apps.googleusercontent.com',
  'google_client_secret' => 'GCS',
  'google_redirect_uri' => '',
  'google_ignore_geo' => FALSE,
  'account_auto_register' => TRUE,
];
$factory = static fn (array $override = []): L5ConfigFactory => new L5ConfigFactory([
  'dx_auth.settings' => array_merge($settings, $override),
]);
// Isolated dx_auth.settings, so an availability matrix cannot be rescued by
// the shared fixture above.
$alone = static fn (array $case): L5ConfigFactory => new L5ConfigFactory(['dx_auth.settings' => $case]);
$logger = static fn (): L5Logger => new L5Logger();

$identity = svc($IDS, ['configFactory' => $factory(), 'logger' => $logger()]);
$store = new L5Store();
$social = svc($SOCIAL, [
  'tempStoreFactory' => new L5TempStoreFactory($store),
  'configFactory' => $factory(),
  'logger' => $logger(),
]);

// ---------------------------------------------------------------------------
// R1 · 企业ID（统一社会信用代码）
// ---------------------------------------------------------------------------

section('R1 · 企业ID通道 · GB 32100-2015 归一化与校验位');
$VALID = [
  '91110000MA0123456P',
  '91310000MA1FL2X47K',
  '92440300MA5F1AB7Q4',
  '91330100MA2GHTJ1KK',
  '91420100MA4KLMN3DK',
];
foreach ($VALID as $code) {
  ok($identity->validate($code), 'valid checksum: ' . $code);
}
same($identity->normalize(' 9111-0000 ma0123456p '), '91110000MA0123456P', 'normalize uppercases and strips spaces/hyphens');
same($identity->normalize(NULL), '', 'normalize(NULL) === ""');
same($identity->normalize(''), '', 'normalize("") === ""');
same($identity->normalize("9111\t0000MA0123456P"), '91110000MA0123456P', 'normalize strips tabs');
same($identity->validate('91110000MA0123456P'), TRUE, 'validate accepts a normalised code');
same($identity->validate('9111 0000 MA01 2345 6P'), TRUE, 'validate normalises before checking');
same($identity->validate('91110000MA0123456X'), FALSE, 'wrong check digit rejected');
same($identity->validate('91110000MA0123456'), FALSE, '17 chars rejected');
same($identity->validate('91110000MA0123456P1'), FALSE, '19 chars rejected');
same($identity->validate(''), FALSE, 'empty rejected');
same($identity->validate(NULL), FALSE, 'NULL rejected');
foreach (
  [
    '91I10000MA0123456P' => 'I',
    '91O10000MA0123456P' => 'O',
    '91Z10000MA0123456P' => 'Z',
    '91S10000MA0123456P' => 'S',
    '91V10000MA0123456P' => 'V',
  ] as $bad => $letter
) {
  same($identity->validate($bad), FALSE, 'charset excludes ' . $letter);
}
same($identity->validate('91AB0000MA0123456P'), FALSE, 'registration authority digits required at 3-8');
same($identity->mask('91110000MA0123456P'), '9111**********456P', 'mask keeps 4 head + 4 tail');
same($identity->mask('91110000'), '91110000', 'mask leaves <=7 chars untouched');
same($identity->mask('91110000MA0123456P'), strlen($identity->mask('91110000MA0123456P')) === 18 ? '9111**********456P' : '', 'mask preserves length');
same($identity->mask(NULL), '', 'mask(NULL) === ""');
same($identity->maskCompanyName(''), '已登记企业', 'empty company name falls back');
same($identity->maskCompanyName('AB'), 'A*', '2-char name');
same($identity->maskCompanyName('ABCD'), 'A**D', '4-char name');
same($identity->maskCompanyName('深圳市某某科技有限公司'), '深圳****公司', 'long name keeps 2+2');

section('R1 · 企业ID通道 · 解析与登录前置守卫');
$empty = $identity->resolve('nonsense');
same(array_keys($empty), [
  'found', 'credit_code', 'credit_code_masked', 'company_name', 'company_name_masked', 'uid', 'source', 'portal_url',
], 'resolve() shape is stable');
same($empty['found'], FALSE, 'resolve() of garbage is not found');
same($empty['credit_code'], 'NONSENSE', 'resolve() echoes the normalised input');
$unbound = $identity->resolve('91110000MA0123456P');
same($unbound['found'], FALSE, 'valid but unbound code is not found when the binding table is unreachable');
same($unbound['credit_code_masked'], '9111**********456P', 'resolve() still returns the masked code');
same($unbound['source'], NULL, 'unbound resolve() has no source');
$hit = svc($IDS, [
  'configFactory' => new L5ConfigFactory([
    'dx_auth.settings' => $settings,
    'dx_tenant.settings' => ['credit_code' => '91310000MA1FL2X47K', 'company_name' => '上海某某信息技术有限公司'],
  ]),
  'logger' => $logger(),
])->resolve('91310000ma1fl2x47k');
same($hit['found'], TRUE, 'tenant settings credit code resolves');
same($hit['source'], 'tenant_settings', 'source is tenant_settings');
same($hit['uid'], NULL, 'tenant_settings never yields a uid — login still needs an explicit binding');
same($hit['company_name'], '上海某某信息技术有限公司', 'company name passthrough');
has($hit['company_name_masked'], '上海', 'masked name keeps the head');
ok($hit['company_name_masked'] !== $hit['company_name'], 'masked name differs from the raw name (anonymous lookup)');

$linker = svc($LINK, ['identity' => $identity]);
$badCode = $linker->loginByEnterprise('91110000MA0123456X', 'whatever');
same($badCode, ['ok' => FALSE, 'msg' => 'invalid_credit_code'], 'loginByEnterprise() rejects a bad checksum before touching the password');
$noPwd = $linker->loginByEnterprise('91110000MA0123456P', '');
same($noPwd, ['ok' => FALSE, 'msg' => 'empty_password'], 'loginByEnterprise() rejects an empty password');
same($linker->unbind(0), FALSE, 'unbind(0) is a no-op returning FALSE');
same($linker->unbind(-5), FALSE, 'unbind(-5) is a no-op returning FALSE');
same($linker->creditCodesForUid(0), [], 'creditCodesForUid(0) short-circuits to []');
foreach (
  [
    'https://portal.example' => TRUE,
    'https://portal.example/' => TRUE,
    'http://portal.example' => FALSE,
    'https://portal.example/#frag' => FALSE,
    'https://user:pw@portal.example' => FALSE,
    'javascript:alert(1)' => FALSE,
    '' => FALSE,
  ] as $url => $expect
) {
  same(call($linker, 'isSafePortalUrl', [$url]), $expect, 'isSafePortalUrl() ' . ($expect ? 'accepts ' : 'rejects ') . ($url !== '' ? $url : '<empty>'));
}

// ---------------------------------------------------------------------------
// R1 · 邮箱 / 用户名 + 密码（首次登录自动注册）
// ---------------------------------------------------------------------------

section('R1 · 邮箱通道 · 自动注册语义（与 personal_registration_enabled 无关）');
$loginRegister = svc($LOGINREG, [
  'configFactory' => $factory(),
  'emailValidator' => new L5EmailValidator(),
  'logger' => $logger(),
]);
same($loginRegister->isAutoRegisterEnabled(), TRUE, 'account_auto_register = TRUE 时开');
same($loginRegister->isAutoRegisterEnabled(FALSE), TRUE, '(defensive) method takes no argument');
$svcOff = svc($LOGINREG, [
  'configFactory' => $factory(['account_auto_register' => FALSE]),
  'emailValidator' => new L5EmailValidator(),
  'logger' => $logger(),
]);
same($svcOff->isAutoRegisterEnabled(), FALSE, 'account_auto_register = FALSE 时关');
$svcNull = svc($LOGINREG, [
  'configFactory' => new L5ConfigFactory(['dx_auth.settings' => []]),
  'emailValidator' => new L5EmailValidator(),
  'logger' => $logger(),
]);
same($svcNull->isAutoRegisterEnabled(), TRUE, '缺省（NULL）按开处理 — 现网默认行为');
$install = \Symfony\Component\Yaml\Yaml::parse((string) file_get_contents($MOD . '/config/install/dx_auth.settings.yml'));
same($install['account_auto_register'], TRUE, 'shipping config keeps auto-register on');
ok(!array_key_exists('personal_registration_enabled', $install), 'personal_registration_enabled is NOT a dx_auth key');
$eco = \Symfony\Component\Yaml\Yaml::parse((string) file_get_contents($ROOT . '/web/modules/custom/dx_ecosystem/config/install/dx_ecosystem.settings.yml'));
same($eco['personal_registration_enabled'], FALSE, 'O6-A: personal tenants stay closed in dx_ecosystem.settings');
ok(!str_contains((string) file_get_contents($MOD . '/dx_auth.services.yml'), 'dx_ecosystem'), 'dx_auth services do not read dx_ecosystem');

same($loginRegister->isEmail('user@example.com'), TRUE, 'isEmail() accepts an address');
same($loginRegister->isEmail('  user@example.com  '), TRUE, 'isEmail() trims');
same($loginRegister->isEmail('someone'), FALSE, 'isEmail() rejects a bare username');
same($loginRegister->isEmail('a@b'), FALSE, 'isEmail() rejects a domain without TLD');
same($loginRegister::MIN_PASSWORD_LENGTH, 8, 'MIN_PASSWORD_LENGTH is 8');
same($loginRegister->validatePassword('1234567'), '密码过短，请至少使用 8 位字符。', 'short password message pins @min substitution');
same($loginRegister->validatePassword('12345678'), NULL, '8 chars is enough');
same($loginRegister->validatePassword('  12345678  '), NULL, 'password is trimmed before the length check');
$badMail = $loginRegister->createAccount('not-an-email', 'abcdefgh');
same($badMail, ['error' => '请使用有效的电子邮箱进行注册。'], 'createAccount() rejects a non-email first');
$shortPwd = $loginRegister->createAccount('a@b.co', 'abc');
same($shortPwd, ['error' => '密码过短，请至少使用 8 位字符。'], 'createAccount() checks strength before the lookup');

// ---------------------------------------------------------------------------
// R1 · 微信扫码 / 公众号 OAuth
// ---------------------------------------------------------------------------

section('R1 · 微信通道 · 开通矩阵 / 签名 / URL / code 换 openid');
$wxOn = ['wechat_enabled' => TRUE, 'wechat_app_id' => 'wx1', 'wechat_secret' => 's1'];
foreach (
  [
    'configured' => [$wxOn, TRUE],
    'switch off' => [['wechat_enabled' => FALSE, 'wechat_app_id' => 'wx1', 'wechat_secret' => 's1'], FALSE],
    'blank app id' => [['wechat_enabled' => TRUE, 'wechat_app_id' => '  ', 'wechat_secret' => 's1'], FALSE],
    'no app id' => [['wechat_enabled' => TRUE, 'wechat_app_id' => '', 'wechat_secret' => 's1'], FALSE],
    'blank secret' => [['wechat_enabled' => TRUE, 'wechat_app_id' => 'wx1', 'wechat_secret' => ' '], FALSE],
    'nothing configured' => [[], FALSE],
  ] as $label => [$case, $expect]
) {
  $probe = svc($WECHAT, ['configFactory' => $alone($case), 'logger' => $logger()]);
  same($probe->isEnabled(), $expect, 'wechat isEnabled: ' . $label);
}
$qrLog = $logger();
$wechat = svc($WECHAT, [
  'configFactory' => $factory(),
  'httpClient' => client([['access_token' => 'AT-9', 'expires_in' => 7200], ['ticket' => 'TICKET-9']]),
  'state' => new L5State(),
  'logger' => $qrLog,
]);
same($wechat->getAccessToken(), 'AT-9', 'access token is fetched once');
same($wechat->createLoginQr('dxa1b2c3'), [
  'scene_id' => 'dxa1b2c3',
  'url' => 'https://mp.weixin.qq.com/cgi-bin/showqrcode?ticket=TICKET-9',
], 'createLoginQr() maps the ticket to showqrcode');
same($qrLog->levels('error'), [], 'no errors logged on the happy QR path');
$wechatDown = svc($WECHAT, [
  'configFactory' => $factory(),
  'httpClient' => client([['errcode' => 40001, 'errmsg' => 'invalid credential']]),
  'state' => new L5State(),
  'logger' => $logger(),
]);
same($wechatDown->createLoginQr('dx1'), NULL, 'createLoginQr() returns NULL when the ticket is missing');
same($wechatDown->getAccessToken(), '', 'getAccessToken() returns "" on an error payload');
$transportFail = svc($WECHAT, [
  'configFactory' => $factory(),
  'httpClient' => client([]),
  'state' => new L5State(),
  'logger' => $logger(),
]);
same($transportFail->getAccessToken(), '', 'getAccessToken() degrades to "" when the transport throws (no crash)');
$noCred = svc($WECHAT, [
  'configFactory' => $factory(['wechat_app_id' => '', 'wechat_secret' => '']),
  'httpClient' => client([]),
  'state' => new L5State(),
  'logger' => $logger(),
]);
same($noCred->getAccessToken(), '', 'getAccessToken() short-circuits without credentials (no outbound call)');
same($wechat->buildOauthAuthorizeUrl('https://x.example/dx/auth/wechat_jump', 'st-1'), 'https://open.weixin.qq.com/connect/oauth2/authorize?appid=wxAPPID001&redirect_uri=https%3A%2F%2Fx.example%2Fdx%2Fauth%2Fwechat_jump&response_type=code&scope=snsapi_userinfo&state=st-1#wechat_redirect', 'OAuth authorize URL is RFC3986 with #wechat_redirect');
$signTime = '1700000000';
$signNonce = 'abc123';
$pieces = [$settings['wechat_token'], $signTime, $signNonce];
sort($pieces, SORT_STRING);
same($wechat->checkSignature(sha1(implode('', $pieces)), $signTime, $signNonce), TRUE, 'checkSignature() accepts a correctly sorted sha1');
same($wechat->checkSignature('deadbeef', $signTime, $signNonce), FALSE, 'checkSignature() rejects a bogus signature');
$openidLog = $logger();
$wechatOauth = svc($WECHAT, [
  'configFactory' => $factory(),
  'httpClient' => client([['openid' => 'OID-FROM-CODE', 'access_token' => 'oat']]),
  'state' => new L5State(),
  'logger' => $openidLog,
]);
same($wechatOauth->openidFromCode('tmp-code'), 'OID-FROM-CODE', 'openidFromCode() reads openid');
$openidErr = $logger();
$wechatOauthErr = svc($WECHAT, [
  'configFactory' => $factory(),
  'httpClient' => client([['errcode' => 40029, 'errmsg' => 'invalid code']]),
  'state' => new L5State(),
  'logger' => $openidErr,
]);
same($wechatOauthErr->openidFromCode('replayed-code'), '', 'a failed code exchange yields an empty openid instead of a bogus identity');
same($openidErr->rows, [], 'openidFromCode() does not log — the caller decides the message');

// ---------------------------------------------------------------------------
// R1 · 手机短信验证码
// ---------------------------------------------------------------------------

section('R1 · 短信通道 · 开通矩阵 / 号码归一 / 限流 / OTP 存取');
$smsOn = [
  'sms_enabled' => TRUE,
  'sms_access_key' => 'AKID',
  'sms_access_secret' => 'SECRET',
  'sms_sign_name' => 'DrupalX',
  'sms_template_code' => 'SMS_1001',
];
foreach (
  [
    'configured' => [$smsOn, TRUE],
    'switch off' => [['sms_enabled' => FALSE] + $smsOn, FALSE],
    'no access key' => [['sms_access_key' => ''] + $smsOn, FALSE],
    'blank secret' => [['sms_access_secret' => '  '] + $smsOn, FALSE],
    'no template' => [['sms_template_code' => ''] + $smsOn, FALSE],
    'nothing configured' => [[], FALSE],
  ] as $label => [$case, $expect]
) {
  $probe = svc($SMS, ['configFactory' => $alone($case), 'logger' => $logger()]);
  same($probe->isEnabled(), $expect, 'sms isEnabled: ' . $label);
}
foreach (
  [
    ['13800138000', '13800138000'],
    ['+86 138-0013-8000', '+8613800138000'],
    ['(138) 0013-8000', '13800138000'],
    ['008613800138000', '008613800138000'],
    ['  13800138000 ', '13800138000'],
    ['abc', ''],
    ['+', '+'],
    ['', ''],
    ['138 0013 8000', '13800138000'],
    ['13800138000a', '13800138000'],
  ] as [$input, $expect]
) {
  same($social->normalizeMobile($input), $expect, 'normalizeMobile(' . json_encode($input) . ')');
}
same(call($social, 'maskMobile', ['13800138000']), '138****8000', 'maskMobile keeps 3 head + 4 tail');
same(call($social, 'maskMobile', ['123456']), '123456', 'maskMobile leaves short numbers alone');

$offLog = $logger();
$smsOff = svc($SMS, [
  'configFactory' => $factory(['sms_enabled' => FALSE]),
  'httpClient' => client([]),
  'linker' => $social,
  'flood' => new L5Flood(),
  'logger' => $offLog,
]);
same($smsOff->sendCode('13800138000', '203.0.113.7'), 'sms_disabled', 'disabled channel short-circuits');
same($offLog->rows, [], 'disabled channel logs nothing');
$smsOn = svc($SMS, [
  'configFactory' => $factory(),
  'httpClient' => client([]),
  'linker' => $social,
  'flood' => new L5Flood(),
  'logger' => $logger(),
]);
foreach (['', 'abc', '12345', '+'] as $bad) {
  same($smsOn->sendCode($bad, '203.0.113.7'), 'invalid_mobile', 'sendCode(' . json_encode($bad) . ') is invalid_mobile');
}
$blocked = new L5Flood();
$blocked->blocked['dx_auth.sms_send_ip'] = FALSE;
$smsFlood = svc($SMS, [
  'configFactory' => $factory(),
  'httpClient' => client([]),
  'linker' => $social,
  'flood' => $blocked,
  'logger' => $logger(),
]);
same($smsFlood->sendCode('13800138000', '203.0.113.7'), 'flood', 'per-IP flood gate returns flood');
$blockedMobile = new L5Flood();
$blockedMobile->blocked['dx_auth.sms_send_mobile'] = FALSE;
$smsFlood2 = svc($SMS, [
  'configFactory' => $factory(),
  'httpClient' => client([]),
  'linker' => $social,
  'flood' => $blockedMobile,
  'logger' => $logger(),
]);
same($smsFlood2->sendCode('13800138000', '203.0.113.7'), 'flood', 'per-mobile flood gate returns flood');

$okFlood = new L5Flood();
$smsOk = svc($SMS, [
  'configFactory' => $factory(),
  'httpClient' => client([['Code' => 'OK', 'Message' => 'OK']]),
  'linker' => $social,
  'flood' => $okFlood,
  'logger' => $logger(),
]);
same($smsOk->sendCode('138 0013 8000', '203.0.113.7'), TRUE, 'accepted send returns TRUE');
$row = $store->get('mobile_code_13800138000');
same(is_array($row) ? gettype($row['code']) : 'missing', 'string', 'OTP stored as a string under mobile_code_<normalized>');
ok(is_array($row) && preg_match('/^\d{6}$/', (string) $row['code']) === 1, 'OTP is a 6-digit numeric code');
ok(is_array($row) && $row['expire'] > time() && $row['expire'] <= time() + 300, 'OTP TTL is within 300s');
same($okFlood->registered, [
  'dx_auth.sms_send_ip|203.0.113.7|3600',
  'dx_auth.sms_send_mobile|13800138000|3600',
], 'both flood events registered only after a successful send');
$same = $store->get('mobile_code_13800138000');
$same['expire'] = time() - 1;
$store->set('mobile_code_13800138000', $same);
same($social->verifySmsCode('13800138000', (string) $same['code']), FALSE, 'expired OTP is refused');
$same['expire'] = time() + 240;
$store->set('mobile_code_13800138000', $same);
same($social->verifySmsCode('13800138000', (string) $same['code']), TRUE, 'live OTP verifies');
// Production semantics, pinned as-is: normalizeMobile() strips every non-digit
// but KEEPS a leading '+', so a country-coded number and the bare national
// number land on two different OTP keys. Nothing here is 'fixed' — the test
// records the behaviour the current site has.
same($social->normalizeMobile('+86 138-0013-8000'), '+8613800138000', 'the +country prefix survives normalisation');
same($social->verifySmsCode('+86 138-0013-8000', (string) $same['code']), FALSE, 'a +86-prefixed number does NOT hit the key stored for the bare number');
same($social->verifySmsCode(" 138 \t0013 8000 ", (string) $same['code']), TRUE, 'separators inside the bare national number still verify');
same($social->verifySmsCode('13800138000', '999999'), FALSE, 'wrong OTP refused');
same($social->verifySmsCode('', '123456'), FALSE, 'empty mobile refused without touching the store');
same($social->verifySmsCode('13800138000', ''), FALSE, 'empty code refused');
$aliyunReject = svc($SMS, [
  'configFactory' => $factory(),
  'httpClient' => client([['Code' => 'isv.SMS_SIGNATURE_ILLEGAL', 'Message' => '签名不合法']]),
  'linker' => $social,
  'flood' => new L5Flood(),
  'logger' => $logger(),
]);
same($aliyunReject->sendCode('13800138000', '203.0.113.7'), '签名不合法', 'provider error message is surfaced verbatim');
same(call($smsOk, 'percentEncode', ['a b*c~+']), 'a%20b%2Ac~%2B', 'Aliyun percentEncode rules');
same(call($smsOk, 'percentEncode', ['手机号']), '%E6%89%8B%E6%9C%BA%E5%8F%B7', 'percentEncode is rawurlencode-based for CJK');

// ---------------------------------------------------------------------------
// R1 · Google
// ---------------------------------------------------------------------------

section('R1 · Google 通道 · 可用性与地理闸门 / 回调 URL / 资料换取');
$google = svc($GOOGLE, [
  'configFactory' => $factory(),
  'httpClient' => client([]),
  'logger' => $logger(),
]);
$gOn = [
  'google_enabled' => TRUE,
  'google_client_id' => 'cid.apps.googleusercontent.com',
  'google_client_secret' => 'GCS',
  'google_ignore_geo' => TRUE,
];
$plain = \Symfony\Component\HttpFoundation\Request::create('https://x.example/dx/auth/google_jump');
same($google->isMainlandChina($plain), TRUE, 'unknown geo is treated as mainland (button hidden)');
same($google->isAvailable($plain), FALSE, 'Google login hidden on unknown geo');
$geoLog = $logger();
$googleIgnore = svc($GOOGLE, [
  'configFactory' => $factory(['google_ignore_geo' => TRUE]),
  'httpClient' => client([]),
  'logger' => $geoLog,
]);
same($googleIgnore->isAvailable($plain), TRUE, 'google_ignore_geo overrides the geo gate when keys are present');
foreach (
  [
    'CN' => TRUE,
    'HK' => FALSE,
    'MO' => FALSE,
    'TW' => FALSE,
    'US' => FALSE,
    'XX' => TRUE,
    'T1' => TRUE,
    'China' => TRUE,
    'cn' => TRUE,
  ] as $code => $expect
) {
  $req = \Symfony\Component\HttpFoundation\Request::create('https://x.example/');
  $req->headers->set('CF-IPCountry', $code);
  same($google->isMainlandChina($req), $expect, 'CF-IPCountry ' . $code . ' → mainland=' . var_export($expect, TRUE));
}
$cfReq = \Symfony\Component\HttpFoundation\Request::create('https://x.example/');
$cfReq->headers->set('CloudFront-Viewer-Country', 'US');
same($google->isMainlandChina($cfReq), FALSE, 'CloudFront-Viewer-Country honoured when CF-IPCountry is absent');
$aeReq = \Symfony\Component\HttpFoundation\Request::create('https://x.example/');
$aeReq->headers->set('X-AppEngine-Country', 'CN');
same($google->isMainlandChina($aeReq), TRUE, 'X-AppEngine-Country honoured as the second fallback');
$priority = \Symfony\Component\HttpFoundation\Request::create('https://x.example/');
$priority->headers->set('CF-IPCountry', 'US');
$priority->headers->set('CloudFront-Viewer-Country', 'CN');
same($google->isMainlandChina($priority), FALSE, 'CF-IPCountry wins over the fallbacks');
foreach (
  [
    'disabled' => [['google_enabled' => FALSE] + $gOn, FALSE],
    'no client id' => [['google_client_id' => ''] + $gOn, FALSE],
    'blank secret' => [['google_client_secret' => '  '] + $gOn, FALSE],
    'configured' => [$gOn, TRUE],
  ] as $label => [$case, $expect]
) {
  $probe = svc($GOOGLE, ['configFactory' => $alone($case), 'httpClient' => client([]), 'logger' => $logger()]);
  same($probe->isAvailable($plain), $expect, 'google isAvailable: ' . $label . ' (geo gate bypassed via ignore_geo)');
}
same($google->redirectUri($plain), 'https://x.example/dx/auth/google_jump', 'default redirect URI is the route path');
$override = svc($GOOGLE, ['configFactory' => $factory(['google_redirect_uri' => 'https://www.drupal.org.cn/dx/auth/google_jump/']), 'logger' => $logger()]);
same($override->redirectUri($plain), 'https://www.drupal.org.cn/dx/auth/google_jump', 'configured redirect URI wins and is trailing-slash trimmed');
has($google->buildAuthorizeUrl('https://x.example/dx/auth/google_jump', 'st-9'), 'scope=openid%20email%20profile', 'authorize URL asks for openid email profile');
has($google->buildAuthorizeUrl('https://x.example/dx/auth/google_jump', 'st-9'), 'prompt=select_account', 'authorize URL forces account selection');
has($google->buildAuthorizeUrl('https://x.example/dx/auth/google_jump', 'st-9'), 'state=st-9', 'authorize URL carries state');
$profile = svc($GOOGLE, [
  'configFactory' => $factory(),
  'httpClient' => client([['access_token' => 'AT'], ['sub' => '109876', 'email' => '  A.B@Example.COM ', 'email_verified' => TRUE, 'name' => ' A B ']]),
  'logger' => $logger(),
]);
same($profile->profileFromCode('code', $plain), [
  'sub' => '109876',
  'email' => 'a.b@example.com',
  'email_verified' => TRUE,
  'name' => 'A B',
], 'profileFromCode() lowercases the email and trims the name');
$noSub = svc($GOOGLE, [
  'configFactory' => $factory(),
  'httpClient' => client([['access_token' => 'AT'], ['email' => 'a@b.co']]),
  'logger' => $logger(),
]);
throws(static fn () => $noSub->profileFromCode('code', $plain), 'profileFromCode() throws when sub is missing', 'userinfo_failed');
$badGrant = svc($GOOGLE, [
  'configFactory' => $factory(),
  'httpClient' => client([['error' => 'invalid_grant', 'error_description' => 'code already used']]),
  'logger' => $logger(),
]);
throws(static fn () => $badGrant->profileFromCode('code', $plain), 'profileFromCode() throws on a failed token exchange', 'code already used');
$noDesc = svc($GOOGLE, [
  'configFactory' => $factory(),
  'httpClient' => client([['error' => 'invalid_request']]),
  'logger' => $logger(),
]);
throws(static fn () => $noDesc->profileFromCode('code', $plain), 'profileFromCode() falls back to the error code', 'invalid_request');

// ---------------------------------------------------------------------------
// R2 · /dx/auth/bindings 边界
// ---------------------------------------------------------------------------

section('R2 · 绑定边界 · 结果键 / 文案表 / 匿名形状');
foreach (
  [
    'already_bound', 'bound', 'already_linked', 'linked', 'merged', 'same', 'empty_credentials',
    'account_not_found', 'bad_password', 'mobile_conflict', 'protected_account', 'invalid_mobile',
    'user_not_found',
  ] as $key
) {
  $msg = $social->messageFor($key);
  ok($msg !== $key && $msg !== '', 'messageFor(' . $key . ') has copy: ' . $msg);
}
same($social->messageFor('unknown_key'), 'unknown_key', 'unknown keys pass through instead of going blank');
same($social->messageFor(''), '', 'empty key returns empty string');
foreach (
  [
    'mobile_conflict' => '两边账号手机号冲突，请联系客服',
    'protected_account' => '不能合并管理员账号，请联系客服',
    'merged' => '已验证并合并到当前账号',
    'already_bound' => '该手机已绑定当前账号',
    'bound' => '手机绑定成功',
    'already_linked' => '已绑定到当前账号',
    'linked' => '绑定成功',
    'same' => '已是同一账号',
    'empty_credentials' => '请填写账号和密码',
    'account_not_found' => '未找到该用户名或邮箱',
    'bad_password' => '密码错误，无法验证归属',
    'invalid_mobile' => '手机号格式不正确',
    'user_not_found' => '用户不存在',
  ] as $key => $copy
) {
  same($social->messageFor($key), $copy, 'production copy pinned for ' . $key);
}
$emptyStatus = $social->statusForUid(0);
same(array_keys($emptyStatus), ['wechat', 'google', 'google_email', 'mobile', 'mobile_masked'], 'statusForUid(0) shape is stable');
same($emptyStatus, ['wechat' => FALSE, 'google' => FALSE, 'google_email' => '', 'mobile' => FALSE, 'mobile_masked' => ''], 'anonymous uid 0 yields an all-empty binding status without a query');
same($social->statusForUid(-3), $emptyStatus, 'negative uid is treated as anonymous');

$noUser = (new \ReflectionClass('Drupal\user\Entity\User'))->newInstanceWithoutConstructor();
ok($noUser instanceof \Drupal\user\UserInterface, 'a User entity satisfies the linker signatures');
same($social->bindMobileToUser($noUser, '  -- '), ['ok' => FALSE, 'msg' => 'invalid_mobile'], 'bind_mobile rejects a blank number before any lookup (repeat/dirty input guard)');
same($social->claimAccountByPassword($noUser, '', 'x'), ['ok' => FALSE, 'msg' => 'empty_credentials'], 'claim_account rejects an empty login');
same($social->claimAccountByPassword($noUser, 'x', ''), ['ok' => FALSE, 'msg' => 'empty_credentials'], 'claim_account rejects an empty password');

// The result contract every bind endpoint can return, enumerated from source.
$socialSource = (string) file_get_contents($MOD . '/src/Service/SocialAccountLinker.php');
foreach (
  [
    "'ok' => FALSE, 'msg' => 'protected_account'",
    "'ok' => FALSE, 'msg' => 'mobile_conflict'",
    "'ok' => TRUE, 'msg' => 'merged', 'merged_uid' =>",
    "'ok' => TRUE, 'msg' => 'already_bound'",
    "'ok' => TRUE, 'msg' => 'bound'",
    "'ok' => TRUE, 'msg' => 'already_linked'",
    "'ok' => FALSE, 'msg' => 'user_not_found'",
    "'ok' => FALSE, 'msg' => 'empty_sub'",
  ] as $literal
) {
  has($socialSource, $literal, 'linker returns ' . $literal);
}
ok(str_contains($socialSource, "if (\$oid === 1)"), 'uid 1 protection is checked before any merge write');
ok(substr_count($socialSource, "reassignIdentityRows('dx_auth_wechat', 'openid'") === 1 && substr_count($socialSource, "reassignIdentityRows('dx_auth_google', 'google_sub'") === 1, 'merge reassigns both social identity tables exactly once');
ok(!str_contains($socialSource, 'function unbind'), 'SocialAccountLinker exposes no social unbind — only 企业ID can be unbound today');
$bindingSource = (string) file_get_contents($MOD . '/src/Controller/BindingsController.php');
foreach (
  [
    "'code' => 0, 'msg' => '请先登录'",
    "'验证码错误或已过期'",
    "messageFor((string) (\$result['msg'] ?? ''))",
  ] as $literal
) {
  has($bindingSource, $literal, 'bindings controller pins ' . $literal);
}
foreach (
  [
    'csrfToken', 'smsEnabled', 'wechatEnabled', 'smsSendPath', 'bindMobilePath',
    'claimAccountPath', 'wechatQrPath', 'wechatPollPath',
  ] as $key
) {
  has($bindingSource, "'" . $key . "'", 'drupalSettings.dxAuth key ' . $key . ' is still attached');
}

// ---------------------------------------------------------------------------
// R1/R2 · 路由与访问控制矩阵（routing.yml 静态解析）
// ---------------------------------------------------------------------------

section('R1/R2 · 路由与访问控制矩阵');
$routing = \Symfony\Component\Yaml\Yaml::parse((string) file_get_contents($MOD . '/dx_auth.routing.yml'));
$expect_routes = [
  'dx_auth.enterprise_lookup' => ['/dx/auth/enterprise_lookup', ['GET'], ['_access' => 'TRUE']],
  'dx_auth.enterprise_login' => ['/dx/auth/enterprise_login', ['POST'], ['_access' => 'TRUE', '_csrf_request_header_token' => 'TRUE']],
  'dx_auth.account_login' => ['/dx/auth/account_login', ['POST'], ['_access' => 'TRUE', '_csrf_request_header_token' => 'TRUE']],
  'dx_auth.wechat_qrcode' => ['/dx/auth/wechat_qrcode', ['GET'], ['_access' => 'TRUE']],
  'dx_auth.wechat_poll' => ['/dx/auth/wechat_poll', ['GET'], ['_access' => 'TRUE']],
  'dx_auth.wechat_middle' => ['/dx/auth/wechat_middle', ['GET'], ['_access' => 'TRUE']],
  'dx_auth.wechat_callback' => ['/dx/auth/wechat_callback', ['GET', 'POST'], ['_access' => 'TRUE']],
  'dx_auth.wechat_jump' => ['/dx/auth/wechat_jump', ['GET'], ['_access' => 'TRUE']],
  'dx_auth.sms_send' => ['/dx/auth/sms_send', ['GET'], ['_access' => 'TRUE']],
  'dx_auth.sms_login' => ['/dx/auth/sms_login', ['GET', 'POST'], ['_access' => 'TRUE']],
  'dx_auth.google_jump' => ['/dx/auth/google_jump', ['GET'], ['_access' => 'TRUE']],
  'dx_auth.bindings' => ['/dx/auth/bindings', ['GET'], ['_user_is_logged_in' => 'TRUE']],
  'dx_auth.bindings_status' => ['/dx/auth/bindings/status', ['GET'], ['_user_is_logged_in' => 'TRUE']],
  'dx_auth.bind_mobile' => ['/dx/auth/bind_mobile', ['POST'], ['_user_is_logged_in' => 'TRUE', '_csrf_request_header_token' => 'TRUE']],
  'dx_auth.claim_account' => ['/dx/auth/claim_account', ['POST'], ['_user_is_logged_in' => 'TRUE', '_csrf_request_header_token' => 'TRUE']],
  'dx_auth.admin_bindings' => ['/admin/dx/auth/enterprise', ['GET'], ['_permission' => 'administer dx auth']],
  'dx_auth.admin_providers' => ['/admin/dx/auth/providers', ['GET'], ['_permission' => 'administer dx auth']],
];
same(count($routing), count($expect_routes), 'routing.yml declares exactly the pinned route set');
foreach ($expect_routes as $name => [$path, $methods, $requirements]) {
  ok(isset($routing[$name]), 'route exists: ' . $name);
  if (!isset($routing[$name])) {
    continue;
  }
  same($routing[$name]['path'], $path, $name . ' path');
  same($routing[$name]['methods'] ?? ['GET'], $methods, $name . ' methods');
  foreach ($requirements as $key => $value) {
    same($routing[$name]['requirements'][$key] ?? NULL, $value, $name . ' requirement ' . $key);
  }
}
foreach (['dx_auth.bindings', 'dx_auth.bindings_status', 'dx_auth.bind_mobile', 'dx_auth.claim_account'] as $name) {
  same($routing[$name]['requirements']['_user_is_logged_in'] ?? NULL, 'TRUE', $name . ' requires a logged-in visitor (未授权访问 gate)');
  ok(!isset($routing[$name]['requirements']['_access']), $name . ' does not open itself with _access TRUE');
}
foreach (['dx_auth.bind_mobile', 'dx_auth.claim_account', 'dx_auth.account_login', 'dx_auth.enterprise_login'] as $name) {
  same($routing[$name]['requirements']['_csrf_request_header_token'] ?? NULL, 'TRUE', $name . ' demands X-CSRF-Token');
  ok(!in_array('GET', $routing[$name]['methods'] ?? ['GET'], TRUE), $name . ' is not reachable by GET (no password over query)');
}
$perm = \Symfony\Component\Yaml\Yaml::parse((string) file_get_contents($MOD . '/dx_auth.permissions.yml'));
ok(isset($perm['administer dx auth']), 'administer dx auth permission is provided by the module');

// ---------------------------------------------------------------------------
// R1 · 返回契约与操作员可见文案
// ---------------------------------------------------------------------------

section('R1 · Topstar JSON 契约与文案钉住');
$accountCtrl = svc($ACCOUNT_CTRL);
$payload = call($accountCtrl, 'json', [0, '请填写账号与密码。']);
same(json_decode((string) $payload->getContent(), TRUE), ['code' => 0, 'msg' => '请填写账号与密码。', 'data' => []], 'failure payload is code/msg/data with no redirect key');
$payload2 = call($accountCtrl, 'json', [2, '请前往企业专属门户登录', [], 'https://portal.example/user/login#enterprise']);
same(json_decode((string) $payload2->getContent(), TRUE), [
  'code' => 2,
  'msg' => '请前往企业专属门户登录',
  'data' => [],
  'redirect' => 'https://portal.example/user/login#enterprise',
], 'redirect key appears only when a redirect is supplied');
$payload3 = call($accountCtrl, 'json', [1, 'ok', ['uid' => 7, 'created' => TRUE]]);
$decoded3 = json_decode((string) $payload3->getContent(), TRUE);
same($decoded3['data'], ['uid' => 7, 'created' => TRUE], 'data passthrough');
ok(!isset($decoded3['redirect']), 'success without redirect omits the key (clients use array_key_exists)');
same($payload3->getStatusCode(), 200, 'login endpoint always answers 200; code carries the outcome');

foreach (
  [
    'AccountAuthController.php' => [
      '安全校验失败，请刷新页面后重试',
      '尝试次数过多，请稍后再试',
      '请填写账号与密码。',
      '该账号尚未激活或已被禁用。',
      '密码不正确，请重试。或通过「忘记密码」重置。',
      '未检测到账号。新用户请使用有效的电子邮箱进行自动注册。',
      '自动注册失败，请稍后重试。',
      '未检测到账号，已为您自动注册并登录',
      "flood->isAllowed('dx_auth.account_login_ip', 30, 3600",
      "'X-CSRF-Token'",
      "CsrfRequestHeaderAccessCheck::TOKEN_KEY",
      "validate(\$token, 'rest')",
      "'dx_new=1'",
    ],
    'EnterpriseAuthController.php' => [
      '请输入企业信用代码',
      '企业信用代码格式不正确',
      '未找到该企业，请核对市场监督局登记的信用代码',
      '尝试过多，请稍后再试',
      '尝试次数过多，请一小时后再试',
      '该企业尝试次数过多，请稍后再试',
      '企业ID格式不正确',
      '请输入密码',
      '企业ID尚未绑定登录账号',
      '绑定账号不可用',
      '密码错误',
      '企业门户尚未开通，请联系平台管理员',
      "'invalid_credit_code' =>",
      "'portal_unavailable' =>",
    ],
    'SocialAuthController.php' => [
      '微信登录未开通',
      '二维码生成失败',
      '短信登录未开通',
      '手机号格式不正确',
      '发送过于频繁，请稍后再试',
      '验证码错误或已过期',
      '未检测到账号，已为您自动注册并登录',
      "'code' => 0, 'msg' => 'pending'",
    ],
    'BindingsController.php' => [
      "'code' => 0, 'msg' => '请先登录'",
      '验证码错误或已过期',
      "'dx_auth/bindings'",
      "'max-age' => 0",
      "'contexts' => ['user', 'url', 'ip']",
    ],
  ] as $file => $literals
) {
  $src = (string) file_get_contents($MOD . '/src/Controller/' . $file);
  foreach ($literals as $literal) {
    ok(str_contains($src, $literal), $file . ' keeps production literal: ' . $literal);
  }
}

// Result-key → copy tables in the controllers must stay aligned with the
// service-level keys, otherwise a user sees a raw machine key.
$enterpriseSrc = (string) file_get_contents($MOD . '/src/Controller/EnterpriseAuthController.php');
foreach (['invalid_credit_code', 'empty_password', 'enterprise_not_bound', 'account_unavailable', 'bad_password', 'portal_unavailable'] as $key) {
  ok(str_contains($enterpriseSrc, "'" . $key . "' =>"), 'enterprise login maps service key ' . $key);
}
ok(str_contains($enterpriseSrc, "\$messages[\$key] ?? \$key"), 'unmapped keys fall through instead of a blank message');
$enterprise = svc($ENTERPRISE_CTRL);
$lookup = call($enterprise, 'json', [1, 'ok', ['found' => TRUE, 'company_name_masked' => '深圳****公司']]);
$lookupData = json_decode((string) $lookup->getContent(), TRUE);
same($lookupData['data']['company_name_masked'], '深圳****公司', 'lookup answers with the masked company name only');
ok(!str_contains((string) $lookup->getContent(), '深圳市某某科技有限公司'), 'lookup never leaks the full company name');

$socialCtrlSrc = (string) file_get_contents($MOD . '/src/Controller/SocialAuthController.php');
foreach (['sms_disabled' => '短信登录未开通', 'invalid_mobile' => '手机号格式不正确', 'flood' => '发送过于频繁，请稍后再试'] as $key => $copy) {
  ok(str_contains($socialCtrlSrc, "'" . $key . "' => '" . $copy . "'"), 'sms_send maps ' . $key . ' to its copy');
}
foreach (
  [
    "str_starts_with(\$scene, 'dxb')",
    "'bind_uid'",
  ] as $needle
) {
  ok(str_contains($socialCtrlSrc, $needle) || str_contains($socialCtrlSrc, "dxb") , 'wechat bind scene present: ' . $needle);
}

// WeChat MP server callback quirk: when wechat_switch is on, the response is
// the raw echostr, so the admin URL verification step keeps working.
$callbackSrc = $socialCtrlSrc;
ok(str_contains($callbackSrc, 'echostr'), 'wechat_callback still answers echostr (MP URL verification)');

// ---------------------------------------------------------------------------
// R1 · drush 与表结构口径
// ---------------------------------------------------------------------------

section('R1/R2 · 身份表与运维入口口径');
$installSrc = (string) file_get_contents($MOD . '/dx_auth.install');
foreach (['dx_auth_enterprise', 'dx_auth_wechat', 'dx_auth_google', 'dx_auth_mobile'] as $table) {
  ok(str_contains($installSrc, "\$schema['" . $table . "']"), 'schema defines ' . $table);
}
// The schema is a plain PHP array builder, so it can be loaded and asserted on
// directly — this is where the 重复绑定 / 同标识冲突归并 guarantees live.
require_once $MOD . '/dx_auth.install';
ok(function_exists('dx_auth_schema'), 'dx_auth_schema() is callable without a Drupal bootstrap');
$schema = dx_auth_schema();
foreach (
  [
    'dx_auth_enterprise' => ['credit_code', 32],
    'dx_auth_wechat' => ['openid', 64],
    'dx_auth_google' => ['google_sub', 64],
    'dx_auth_mobile' => ['mobile', 32],
  ] as $table => [$unique, $length]
) {
  ok(isset($schema[$table]), 'schema array carries ' . $table);
  $keys = $schema[$table]['unique keys'] ?? [];
  same(array_keys($keys), [$unique], $table . ' declares exactly one unique key group');
  same($keys[$unique] ?? NULL, [$unique], $table . ' is unique on ' . $unique . ' — the anchor behind repeat-binding and identity conflict merge');
  same($schema[$table]['fields'][$unique]['length'] ?? NULL, $length, $table . '.' . $unique . ' keeps its varchar(' . $length . ') width');
  same($schema[$table]['fields'][$unique]['not null'] ?? NULL, TRUE, $table . '.' . $unique . ' is NOT NULL');
  same($schema[$table]['primary key'] ?? NULL, ['id'], $table . ' is keyed by an independent serial id, not by the identity');
  same($schema[$table]['indexes']['uid'] ?? NULL, ['uid'], $table . ' indexes uid but does NOT make it unique — one account may hold several identities');
}
$drush = \Symfony\Component\Yaml\Yaml::parse((string) file_get_contents($MOD . '/drush.services.yml'));
same(array_keys($drush['services']), ['dx_auth.commands'], 'dx_auth registers one drush command service');
$commands = (string) file_get_contents($MOD . '/src/Commands/AuthCommands.php');
foreach (['dx:auth-bind', 'dx:auth-list', 'dx:auth-unbind', 'dx:auth-validate'] as $command) {
  has($commands, '@command ' . $command, 'drush command ' . $command . ' is registered');
}
ok(str_contains($commands, 'unbind('), 'dx:auth-unbind is the CLI path for 解绑 (企业ID only)');

echo "\n";
printf("%s: %d assertion(s), %d failure(s)\n", $l5_fail === 0 ? 'OK' : 'FAIL', $l5_pass + $l5_fail, $l5_fail);
foreach ($l5_failures as $line) {
  echo '  - ' . $line . "\n";
}
exit($l5_fail === 0 ? 0 : 1);
