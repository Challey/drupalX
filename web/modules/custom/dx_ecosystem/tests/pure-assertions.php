<?php

/**
 * Pure assertions for the dx_ecosystem L2 layer (Phase I: I1–I4).
 *
 * No Drupal bootstrap, no database, no network. Run anywhere with PHP 8.3:
 *   php web/modules/custom/dx_ecosystem/tests/pure-assertions.php
 * Exit 0 = every assertion held, 1 = at least one failed.
 *
 * What is pinned here is the contract that the HTTP middleware, the Drush
 * commands, the admin report and the CI gate all share: token shape and
 * digest, the credential state machine with its stable error codes, the
 * switchable host adapter, signed dist urls, Satis metadata (including the
 * byte-exact provider hashes a real `composer install` verifies), report
 * aggregation, and the L0 visibility filter.
 */

declare(strict_types=1);

use Drupal\dx_ecosystem\Service\Composer\ComposerHostPlan;
use Drupal\dx_ecosystem\Service\Composer\DownloadUrlSigner;
use Drupal\dx_ecosystem\Service\Composer\RepositoryRequestAuth;
use Drupal\dx_ecosystem\Service\Composer\SatisMetadataBuilder;
use Drupal\dx_ecosystem\Service\CredentialAuditLog;
use Drupal\dx_ecosystem\Service\CredentialLifecycle;
use Drupal\dx_ecosystem\Service\CredentialReport;
use Drupal\dx_ecosystem\Service\DeveloperCertificationStore;
use Drupal\dx_ecosystem\Service\L2PackageManifest;
use Drupal\dx_ecosystem\Service\L2RepositoryBuilder;
use Drupal\dx_ecosystem\Service\PartnerCredentialStore;

$moduleRoot = dirname(__DIR__);
$repoRoot = dirname(__DIR__, 5);

$autoload = $repoRoot . '/vendor/autoload.php';
if (!is_file($autoload)) {
  fwrite(STDERR, "需要 composer 依赖（Symfony Yaml）：缺少 {$autoload}\n");
  exit(1);
}
require $autoload;

spl_autoload_register(static function (string $class) use ($moduleRoot): void {
  $prefix = 'Drupal\\dx_ecosystem\\';
  if (!str_starts_with($class, $prefix)) {
    return;
  }
  $path = $moduleRoot . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
  if (is_file($path)) {
    require $path;
  }
});

require $repoRoot . '/scripts/lib/l0_publish.php';

$GLOBALS['l4_pass'] = 0;
$GLOBALS['l4_fail'] = 0;

function l4_show(string $line): void {
  fwrite(STDOUT, $line . "\n");
}

function l4_section(string $title): void {
  l4_show('');
  l4_show('── ' . $title . ' ' . str_repeat('─', max(0, 58 - strlen($title))));
}

function l4_is(string $label, mixed $actual, mixed $expected): void {
  if ($actual === $expected) {
    $GLOBALS['l4_pass']++;
    l4_show('  ok   ' . $label);
    return;
  }
  $GLOBALS['l4_fail']++;
  l4_show(sprintf(
    '  FAIL %s — 期望 %s，实际 %s',
    $label,
    json_encode($expected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
  ));
}

function l4_true(string $label, mixed $actual): void {
  l4_is($label, (bool) $actual, TRUE);
}

function l4_false(string $label, mixed $actual): void {
  l4_is($label, (bool) $actual, FALSE);
}

/**
 * @param list<string> $needles
 */
function l4_contains(array $needles, string $haystack, string $label): void {
  $missing = [];
  foreach ($needles as $needle) {
    if (!str_contains($haystack, $needle)) {
      $missing[] = $needle;
    }
  }
  l4_is($label, $missing, []);
}

function l4_tmp(string $prefix): string {
  $path = sys_get_temp_dir() . '/' . $prefix . '-' . bin2hex(random_bytes(5));
  if (!mkdir($path, 0775, TRUE) && !is_dir($path)) {
    throw new RuntimeException('cannot create temp dir ' . $path);
  }
  return $path;
}

function l4_put(string $root, string $rel, string $contents): void {
  $path = $root . '/' . $rel;
  $dir = dirname($path);
  if (!is_dir($dir) && !mkdir($dir, 0775, TRUE) && !is_dir($dir)) {
    throw new RuntimeException('cannot create ' . $dir);
  }
  file_put_contents($path, $contents);
}

function l4_rm(string $path): void {
  if (!file_exists($path)) {
    return;
  }
  if (!is_dir($path) || is_link($path)) {
    unlink($path);
    return;
  }
  foreach (scandir($path) ?: [] as $entry) {
    if ($entry !== '.' && $entry !== '..') {
      l4_rm($path . '/' . $entry);
    }
  }
  rmdir($path);
}

// ─────────────────────────────────────────────────────────────────────────────
l4_section('I1 凭证格式与 SHA-256 摘要');

$token = PartnerCredentialStore::mintToken();
l4_true('签发的 token 形如 dxl2_ + 48 hex', (bool) preg_match('/^dxl2_[0-9a-f]{48}$/', $token));
l4_is('token 长度', strlen($token), 53);
l4_is('格式校验通过', RepositoryRequestAuth::shapeCode($token), RepositoryRequestAuth::CODE_OK);
l4_is('大写十六进制仍算合法格式（摘要区分大小写）', RepositoryRequestAuth::shapeCode('dxl2_' . strtoupper(substr($token, 5))), RepositoryRequestAuth::CODE_OK);
l4_is('空值 = 缺少凭证', RepositoryRequestAuth::shapeCode(''), RepositoryRequestAuth::CODE_MISSING);
l4_is('只有前缀 = 格式错误', RepositoryRequestAuth::shapeCode('dxl2_'), RepositoryRequestAuth::CODE_MALFORMED);
l4_is('长度不足 = 格式错误', RepositoryRequestAuth::shapeCode('dxl2_deadbeef'), RepositoryRequestAuth::CODE_MALFORMED);
l4_is('非 dxl2_ 前缀 = 格式错误', RepositoryRequestAuth::shapeCode('dxl3_' . substr($token, 5)), RepositoryRequestAuth::CODE_MALFORMED);
l4_is('看起来像凭证', RepositoryRequestAuth::looksLikeCredential($token), TRUE);
l4_is('摘要 = SHA-256(明文)', PartnerCredentialStore::digest($token), hash('sha256', $token));
l4_is('摘要先裁空白', PartnerCredentialStore::digest("  {$token}\n"), PartnerCredentialStore::digest($token));
l4_is('摘要为 64 位十六进制', strlen(PartnerCredentialStore::digest($token)), 64);
l4_false('摘要不泄露明文', str_contains(PartnerCredentialStore::digest($token), substr($token, 5, 8)));
l4_is('大小写不同的 token 摘要不同（必须精确匹配）', PartnerCredentialStore::digest(strtoupper($token)) === PartnerCredentialStore::digest($token), FALSE);
l4_is('展示前缀 = 前 12 字符', PartnerCredentialStore::prefixOf($token), substr($token, 0, 12));
l4_false('前缀不含后段熵值', str_contains(PartnerCredentialStore::prefixOf($token), substr($token, 20, 8)));
l4_is('键值集合名不变（OE2 契约）', PartnerCredentialStore::COLLECTION, 'dx_ecosystem.credentials');
l4_is('轮换历史上限', PartnerCredentialStore::HISTORY, 5);

// ─────────────────────────────────────────────────────────────────────────────
l4_section('I1 传输层：token 从哪来');

$basic = RepositoryRequestAuth::extract(['authorization' => 'Basic ' . base64_encode('dx-uid-9:' . $token)]);
l4_is('http-basic 取到 token', $basic['token'], $token);
l4_is('http-basic 来源', $basic['source'], RepositoryRequestAuth::SOURCE_BASIC);
l4_is('http-basic 用户名携带 uid 提示', $basic['uid_hint'], 9);
l4_is('http-basic 格式合法', $basic['code'], RepositoryRequestAuth::CODE_OK);
$bearer = RepositoryRequestAuth::extract(['authorization' => 'Bearer ' . $token]);
l4_is('bearer 来源', $bearer['source'], RepositoryRequestAuth::SOURCE_BEARER);
$custom = RepositoryRequestAuth::extract(['x-drupalx-token' => $token]);
l4_is('自定义头来源', $custom['source'], RepositoryRequestAuth::SOURCE_HEADER);
l4_is('自定义头取到 token', $custom['token'], $token);
l4_is('无鉴权头 = 缺少凭证', RepositoryRequestAuth::extract([])['code'], RepositoryRequestAuth::CODE_MISSING);
l4_is('query 默认不接受 token', RepositoryRequestAuth::extract([], ['dx_token' => $token])['code'], RepositoryRequestAuth::CODE_MISSING);
l4_is('query 显式开启才接受', RepositoryRequestAuth::extract([], ['dx_token' => $token], TRUE)['source'], RepositoryRequestAuth::SOURCE_QUERY);
l4_is('坏的 base64 不崩', RepositoryRequestAuth::extract(['authorization' => 'Basic !!!'])['code'], RepositoryRequestAuth::CODE_MISSING);
l4_is('非 dx-uid-N 用户名不带提示', RepositoryRequestAuth::uidFromBasicUser('admin'), 0);
l4_is('uid 提示解析', RepositoryRequestAuth::uidFromBasicUser('DX-UID-12'), 12);
l4_is('uid 提示一致 = 通过', RepositoryRequestAuth::uidMatches($basic, 9)['ok'], TRUE);
l4_is('uid 提示不一致 = 稳定错误码', RepositoryRequestAuth::uidMatches($basic, 7)['code'], RepositoryRequestAuth::CODE_UID_MISMATCH);
l4_is('token 不属于任何人', RepositoryRequestAuth::uidMatches($basic, NULL)['code'], RepositoryRequestAuth::CODE_UNKNOWN);
$challenge = RepositoryRequestAuth::challenge(RepositoryRequestAuth::CODE_REVOKED);
l4_contains(['Basic realm="DrupalX L2"', RepositoryRequestAuth::CODE_REVOKED], $challenge['WWW-Authenticate'], '401 质询头可被 composer 读成 bad credentials');
l4_is('质询禁止缓存', $challenge['Cache-Control'], 'no-store');

// ─────────────────────────────────────────────────────────────────────────────
l4_section('I1 可切换主机适配层');

$placeholder = ComposerHostPlan::fromSettings([]);
l4_is('未配置时仍是 OE2 占位主机', $placeholder['composer_host'], 'packages.drupalx.local');
l4_is('占位主机走 http，便于本地回环', $placeholder['base_url'], 'http://packages.drupalx.local');
l4_is('默认驱动 satis', $placeholder['driver'], ComposerHostPlan::DRIVER_SATIS);
l4_is('默认 token 头', $placeholder['token_header'], 'authorization');
l4_is('默认传输 = http-basic', $placeholder['auth_mode'], ComposerHostPlan::AUTH_BASIC);
l4_is('占位配置标记为未接入', $placeholder['configured'], FALSE);
l4_true('占位主机给出警告', isset($placeholder['warnings'][ComposerHostPlan::WARN_HOST_PLACEHOLDER]));
l4_true('未配置签名密钥给出警告', isset($placeholder['warnings'][ComposerHostPlan::WARN_SIGN_KEY_WEAK]));
l4_true('未配置仓库根目录给出警告', isset($placeholder['warnings'][ComposerHostPlan::WARN_ROOT_MISSING]));
l4_is('根元数据地址', $placeholder['root_url'], 'http://packages.drupalx.local/packages.json');
l4_is('Drupal 自服地址（无 origin）', $placeholder['served_root_url'], '/dx/ecosystem/l2/packages.json');
l4_is('Drupal 自服地址（带 origin）', ComposerHostPlan::fromSettings([], 'https://portal.example.com/')['served_root_url'], 'https://portal.example.com/dx/ecosystem/l2/packages.json');
l4_is('仓库配置片段', ComposerHostPlan::repositoriesSnippet($placeholder)['repositories']['drupalx-l2']['url'], 'http://packages.drupalx.local/packages.json');
l4_is(
  'auth.json 片段与 OE2 composerSnippet 结构一致',
  ComposerHostPlan::authSnippet($placeholder, 9, $token),
  ['http-basic' => ['packages.drupalx.local' => ['username' => 'dx-uid-9', 'password' => $token]]],
);

$satis = ComposerHostPlan::fromSettings([
  'l2_composer_base_url' => 'https://packages.example.com/drupalx',
  'l2_signing_key' => str_repeat('s', 40),
]);
l4_is('真实 Satis 基址生效', $satis['base_url'], 'https://packages.example.com/drupalx');
l4_is('auth 主机跟随基址（不再指向占位）', $satis['composer_host'], 'packages.example.com');
l4_is('接入真实主机后 configured = true', $satis['configured'], TRUE);
l4_false('接入真实主机后不再报 Composer 占位警告', isset($satis['warnings'][ComposerHostPlan::WARN_HOST_PLACEHOLDER]));
l4_true('Git 占位警告独立成键（不与 Composer 互相覆盖）', isset($satis['warnings'][ComposerHostPlan::WARN_GIT_HOST_PLACEHOLDER]));
l4_is('签名密钥足够强', $satis['signing_key_configured'], TRUE);
l4_is(
  'auth.json 用真实主机名',
  array_keys(ComposerHostPlan::authSnippet($satis, 9, $token)['http-basic']),
  ['packages.example.com'],
);

$artifactory = ComposerHostPlan::fromSettings(['l2_composer_base_url' => 'https://artifact.example.com/api/storage/drupalx-l2']);
l4_is('Artifactory 路径被识别', $artifactory['driver'], ComposerHostPlan::DRIVER_ARTIFACTORY);
l4_is('Artifactory 主机', $artifactory['composer_host'], 'artifact.example.com');
l4_is('显式驱动优先', ComposerHostPlan::fromSettings(['l2_composer_base_url' => 'https://packages.example.com', 'l2_composer_driver' => 'satis'])['driver'], ComposerHostPlan::DRIVER_SATIS);
l4_is('未知驱动退回自动判定', ComposerHostPlan::fromSettings(['l2_composer_base_url' => 'https://packages.example.com', 'l2_composer_driver' => 'wat'])['driver'], ComposerHostPlan::DRIVER_SATIS);

$loopback = ComposerHostPlan::fromSettings(['l2_repository_root' => '/srv/l2']);
l4_is('仓库根目录即离线回环基址', $loopback['base_url'], 'file:///srv/l2');
l4_is('驱动 = loopback', $loopback['driver'], ComposerHostPlan::DRIVER_LOOPBACK);
l4_is('标记为回环', $loopback['loopback'], TRUE);
l4_is('回环不需要 auth.json', ComposerHostPlan::authSnippet($loopback, 9, $token), []);
l4_is('回环元数据地址', $loopback['root_url'], 'file:///srv/l2/packages.json');
l4_false('回环不报 Composer 占位警告', isset($loopback['warnings'][ComposerHostPlan::WARN_HOST_PLACEHOLDER]));
l4_is('回环不产生占位主机字样警告', count(array_filter($loopback['warnings'], static fn(string $m): bool => str_contains($m, ComposerHostPlan::PLACEHOLDER_COMPOSER_HOST))), 0);
l4_is('绝对目录 → file:// URL', ComposerHostPlan::fileUrl('/srv/l2'), 'file:///srv/l2');
l4_is('file:// 幂等', ComposerHostPlan::fileUrl('file:///srv/l2/'), 'file:///srv/l2');
l4_is('URL → 路径', ComposerHostPlan::pathFromUrl('file:///srv/l2/?x=1'), '/srv/l2');
l4_is('斜杠归一', ComposerHostPlan::pathFromUrl('C:\\srv\\l2'), 'C:/srv/l2');
l4_true('目录值识别为路径', ComposerHostPlan::looksLikePath('/srv/l2'));
l4_false('主机名不是路径', ComposerHostPlan::looksLikePath('packages.example.com'));

$headerPlan = ComposerHostPlan::fromSettings(['l2_token_header' => 'X-DrupalX-Token']);
l4_is('token 头大小写归一', $headerPlan['token_header'], 'x-drupalx-token');
l4_is('自定义头 → header 传输', $headerPlan['auth_mode'], ComposerHostPlan::AUTH_HEADER);
l4_is('自定义头 auth 片段', ComposerHostPlan::authSnippet($headerPlan, 9, $token), ['drupalx' => ['token_header' => 'x-drupalx-token', 'token' => $token]]);
$oddHeader = ComposerHostPlan::fromSettings(['l2_token_header' => 'x-weird']);
l4_is('未知头 = 不猜传输方式', $oddHeader['auth_mode'], ComposerHostPlan::AUTH_NONE);
l4_true('未知头有警告', isset($oddHeader['warnings'][ComposerHostPlan::WARN_HEADER_UNKNOWN]));
l4_is('TTL 归入下限', ComposerHostPlan::fromSettings(['l2_download_ttl' => 5])['download_ttl'], DownloadUrlSigner::MIN_TTL);
l4_is('TTL 为 0 用默认', ComposerHostPlan::fromSettings(['l2_download_ttl' => 0])['download_ttl'], DownloadUrlSigner::DEFAULT_TTL);
l4_is('TTL 归入上限', ComposerHostPlan::fromSettings(['l2_download_ttl' => 999999])['download_ttl'], DownloadUrlSigner::MAX_TTL);
l4_is('弱密钥判定', ComposerHostPlan::signingKeyState('short'), 'weak');
l4_is('强密钥判定', ComposerHostPlan::signingKeyState(str_repeat('k', 32)), 'ok');
l4_contains(['***@', 'signature=***'], ComposerHostPlan::redact('https://dx-uid-9:' . $token . '@packages.example.com/l2/dist/a.zip?expires=1&signature=' . str_repeat('a', 64)), '日志脱敏隐藏口令与签名');
l4_false('脱敏后不含 token', str_contains(ComposerHostPlan::redact('https://u:' . $token . '@h/packages.json'), $token));

// ─────────────────────────────────────────────────────────────────────────────
l4_section('I1 下载 URL 签名');

$secret = str_repeat('7', 40);
$path = 'dist/drupalx/dx_payment/1.0.0/drupalx-dx_payment-1.0.0.zip';
$now = 1800000000;
$params = DownloadUrlSigner::sign($secret, $path, $now, 900, '9');
l4_is('签名有效期', (int) $params[DownloadUrlSigner::QUERY_EXPIRES], $now + 900);
l4_is('签名绑定 uid', $params[DownloadUrlSigner::QUERY_BUNDLE], '9');
l4_is('签名长度为 64', strlen($params[DownloadUrlSigner::QUERY_SIGNATURE]), 64);
l4_is('规范串版本化', DownloadUrlSigner::canonicalString('/' . $path . '/', $now + 900, '9'), implode("\n", ['v1', $path, (string) ($now + 900), '9']));
l4_is('验签通过', DownloadUrlSigner::verify($secret, $path, $params, $now, '9')['ok'], TRUE);
l4_is('验签返回码', DownloadUrlSigner::verify($secret, $path, $params, $now, '9')['code'], DownloadUrlSigner::CODE_OK);
l4_is('换产物即失效（防替换）', DownloadUrlSigner::verify($secret, 'dist/other.zip', $params, $now, '9')['code'], DownloadUrlSigner::CODE_INVALID);
l4_is('过期即失效', DownloadUrlSigner::verify($secret, $path, $params, $now + 901, '9')['code'], DownloadUrlSigner::CODE_EXPIRED);
l4_is('到期当刻仍有效', DownloadUrlSigner::verify($secret, $path, $params, $now + 900, '9')['ok'], TRUE);
l4_is('他人凭证不能借用', DownloadUrlSigner::verify($secret, $path, $params, $now, '10')['code'], DownloadUrlSigner::CODE_UID_MISMATCH);
l4_is('缺签名', DownloadUrlSigner::verify($secret, $path, [], $now)['code'], DownloadUrlSigner::CODE_MISSING);
l4_is('弱密钥拒绝签发', DownloadUrlSigner::verify('short', $path, $params, $now, '9')['code'], DownloadUrlSigner::CODE_SECRET_WEAK);
l4_is('匿名签发的链接不能给已认证用户使用', DownloadUrlSigner::verify($secret, $path, DownloadUrlSigner::sign($secret, $path, $now), $now, '9')['code'], DownloadUrlSigner::CODE_UID_MISMATCH);
l4_true('强密钥判定', DownloadUrlSigner::strongSecret($secret));
l4_is('TTL 归一', DownloadUrlSigner::ttl(5), DownloadUrlSigner::MIN_TTL);
l4_is('拼接保留原查询', DownloadUrlSigner::appendQuery('https://h/l2/dist/a.zip?a=1', ['signature' => 'abc']), 'https://h/l2/dist/a.zip?a=1&signature=abc');
l4_is('拼接保留片段', DownloadUrlSigner::appendQuery('https://h/l2/dist/a.zip#frag', ['expires' => '12']), 'https://h/l2/dist/a.zip?expires=12#frag');
l4_is('无参数不改动', DownloadUrlSigner::appendQuery('https://h/l2/dist/a.zip', []), 'https://h/l2/dist/a.zip');
l4_is('签名错误码全部唯一', count(array_unique(DownloadUrlSigner::codes())), count(DownloadUrlSigner::codes()));

// ─────────────────────────────────────────────────────────────────────────────
l4_section('I2 凭证状态机（吊销即失效）');

l4_is('空记录 = none', CredentialLifecycle::stateOf(NULL), CredentialLifecycle::STATE_NONE);
l4_is('有摘要 = active', CredentialLifecycle::stateOf(['hash' => 'a']), CredentialLifecycle::STATE_ACTIVE);
l4_is('revoked 标记 = revoked', CredentialLifecycle::stateOf(['hash' => 'a', 'revoked' => TRUE]), CredentialLifecycle::STATE_REVOKED);
l4_is('吊销后不能回到 suspended', CredentialLifecycle::canTransition(CredentialLifecycle::STATE_REVOKED, CredentialLifecycle::STATE_SUSPENDED), FALSE);
l4_is('吊销后唯一出路是重新签发', CredentialLifecycle::allowedEvents()[CredentialLifecycle::STATE_REVOKED], [CredentialLifecycle::EVENT_ISSUE]);
l4_is('apply：吊销后签发 → active（新一代）', CredentialLifecycle::apply(CredentialLifecycle::STATE_REVOKED, CredentialLifecycle::EVENT_ISSUE), CredentialLifecycle::STATE_ACTIVE);
l4_is('apply：吊销后挂起被忽略', CredentialLifecycle::apply(CredentialLifecycle::STATE_REVOKED, CredentialLifecycle::EVENT_SUSPEND), CredentialLifecycle::STATE_REVOKED);
l4_is('apply：吊销后轮换被忽略（旧秘密不会复活）', CredentialLifecycle::apply(CredentialLifecycle::STATE_REVOKED, CredentialLifecycle::EVENT_ROTATE), CredentialLifecycle::STATE_REVOKED);
l4_is('apply：active + auto_revoke → revoked', CredentialLifecycle::apply(CredentialLifecycle::STATE_ACTIVE, CredentialLifecycle::EVENT_AUTO_REVOKE), CredentialLifecycle::STATE_REVOKED);
l4_is('apply：active + suspend → suspended', CredentialLifecycle::apply(CredentialLifecycle::STATE_ACTIVE, CredentialLifecycle::EVENT_SUSPEND), CredentialLifecycle::STATE_SUSPENDED);
l4_is('apply：suspended + resume → active', CredentialLifecycle::apply(CredentialLifecycle::STATE_SUSPENDED, CredentialLifecycle::EVENT_RESUME), CredentialLifecycle::STATE_ACTIVE);
l4_is('apply：未知事件保持原状态', CredentialLifecycle::apply(CredentialLifecycle::STATE_ACTIVE, 'party'), CredentialLifecycle::STATE_ACTIVE);
foreach (CredentialLifecycle::allowedEvents() as $state => $events) {
  foreach ($events as $event) {
    $next = CredentialLifecycle::apply((string) $state, (string) $event);
    l4_true("状态机闭合：{$state} + {$event} → {$next}", CredentialLifecycle::canTransition((string) $state, $next));
  }
}

$good = ['token_shape' => RepositoryRequestAuth::CODE_OK, 'matched' => TRUE, 'cert_status' => DeveloperCertificationStore::STATUS_CERTIFIED, 'gate_reason' => 'granted'];
l4_is('通过码', CredentialLifecycle::classify($good)['code'], RepositoryRequestAuth::CODE_OK);
l4_is('通过态', CredentialLifecycle::classify($good)['state'], CredentialLifecycle::STATE_ACTIVE);
l4_is('吊销优先于一切（matched 仍拒绝）', CredentialLifecycle::classify(array_replace($good, ['revoked' => TRUE]))['code'], RepositoryRequestAuth::CODE_REVOKED);
l4_is('吊销态标记 revoked', CredentialLifecycle::classify(array_replace($good, ['revoked' => TRUE]))['state'], CredentialLifecycle::STATE_REVOKED);
l4_is(
  '认证作废 → token 立即失效（同步点未跑也拒绝）',
  CredentialLifecycle::classify(array_replace($good, ['cert_status' => DeveloperCertificationStore::STATUS_REVOKED]))['code'],
  RepositoryRequestAuth::CODE_CERT_REVOKED,
);
l4_is('被取代的旧 token → 轮换码', CredentialLifecycle::classify(array_replace($good, ['matched' => FALSE, 'superseded' => CredentialLifecycle::SUPERSEDED_YES]))['code'], RepositoryRequestAuth::CODE_ROTATED);
l4_is('陌生 token → 未知码', CredentialLifecycle::classify(array_replace($good, ['matched' => FALSE]))['code'], RepositoryRequestAuth::CODE_UNKNOWN);
l4_is('认证未通过 → 挂起', CredentialLifecycle::classify(array_replace($good, ['cert_status' => DeveloperCertificationStore::STATUS_PENDING]))['code'], RepositoryRequestAuth::CODE_CERT_STALE);
l4_is('DPA 过期 → 挂起', CredentialLifecycle::classify(array_replace($good, ['gate_reason' => 'dpa_version_mismatch']))['code'], RepositoryRequestAuth::CODE_DPA_STALE);
l4_is('DPA 未签 → 挂起', CredentialLifecycle::classify(array_replace($good, ['gate_reason' => 'dpa_ack_missing']))['code'], RepositoryRequestAuth::CODE_DPA_STALE);
l4_is('缺少权限 → 挂起', CredentialLifecycle::classify(array_replace($good, ['gate_reason' => 'missing_permission']))['code'], RepositoryRequestAuth::CODE_CERT_STALE);
l4_is('格式错误优先于查库', CredentialLifecycle::classify(['token_shape' => RepositoryRequestAuth::CODE_MALFORMED])['code'], RepositoryRequestAuth::CODE_MALFORMED);
l4_is('缺少凭证有稳定码', CredentialLifecycle::classify(['token_shape' => ''])['code'], RepositoryRequestAuth::CODE_MISSING);
l4_is('每个码都有中文说明', count(array_filter(RepositoryRequestAuth::messages(), static fn(string $m): bool => $m !== '')), count(RepositoryRequestAuth::messages()));
l4_is('错误码不重复', count(RepositoryRequestAuth::codes()), count(array_unique(RepositoryRequestAuth::codes())));
l4_is('未知码回落为码本身', RepositoryRequestAuth::messageFor('DX.L2.NOPE'), 'DX.L2.NOPE');
l4_is('摘要记录形态', CredentialLifecycle::summary(['uid' => 9, 'prefix' => 'dxl2_aaaaaaa', 'hash' => 'a', 'uses' => 4], 0)['uses'], 4);
l4_is('摘要默认态 none', CredentialLifecycle::summary(NULL, 3)['state'], CredentialLifecycle::STATE_NONE);

// ─────────────────────────────────────────────────────────────────────────────
l4_section('I2 守卫面：哪些路径要鉴权');

l4_true('根元数据被守卫', RepositoryRequestAuth::shouldGuard('/dx/ecosystem/l2/packages.json'));
l4_true('provider 被守卫', RepositoryRequestAuth::shouldGuard('/dx/ecosystem/l2/providers/drupalx%2Fdx_payment.json'));
l4_true('dist 被守卫', RepositoryRequestAuth::shouldGuard('/dx/ecosystem/l2/dist/a.zip'));
l4_true('前缀本身被守卫', RepositoryRequestAuth::shouldGuard('/dx/ecosystem/l2'));
l4_false('伙伴页不在守卫面内', RepositoryRequestAuth::shouldGuard('/dx/ecosystem/partner'));
l4_false('应用商店不在守卫面内', RepositoryRequestAuth::shouldGuard('/appstore'));
l4_false('后台页不在守卫面内', RepositoryRequestAuth::shouldGuard('/admin/dx/ecosystem/credentials'));
l4_is('根分类', RepositoryRequestAuth::classifyPath('/dx/ecosystem/l2')['kind'], 'root');
l4_is('packages.json 分类', RepositoryRequestAuth::classifyPath('/dx/ecosystem/l2/packages.json')['kind'], 'root');
l4_is('provider 分类还原包名', RepositoryRequestAuth::classifyPath('/dx/ecosystem/l2/providers/drupalx%2Fdx_payment.json')['provider'], 'drupalx/dx_payment');
l4_is('dist 分类保留相对路径', RepositoryRequestAuth::classifyPath('/dx/ecosystem/l2/dist/drupalx/dx_payment/1.0.0/a.zip')['dist'], 'drupalx/dx_payment/1.0.0/a.zip');
l4_is('plan 分类', RepositoryRequestAuth::classifyPath('/dx/ecosystem/l2/plan')['kind'], 'plan');
l4_is('未知子路径', RepositoryRequestAuth::classifyPath('/dx/ecosystem/l2/nope')['kind'], 'unknown');
l4_is('穿越被压回仓库内', RepositoryRequestAuth::sanitizeArtifactPath('../../etc/passwd'), 'etc/passwd');
l4_is('绝对路径被剥成相对', RepositoryRequestAuth::sanitizeArtifactPath('/var/www/../etc/shadow'), 'etc/shadow');
l4_is('危险字符直接拒绝', RepositoryRequestAuth::sanitizeArtifactPath("a\x00b"), '');
l4_is('URL 编码段被拒绝', RepositoryRequestAuth::sanitizeArtifactPath('%3Cscript%3E'), '');
l4_is('空路径仍为空', RepositoryRequestAuth::sanitizeArtifactPath(''), '');

// ─────────────────────────────────────────────────────────────────────────────
l4_section('I1 Satis 元数据与本地清单');

$manifest = new L2PackageManifest();
$packages = $manifest->packages();
l4_true('清单可读且非空', $packages !== []);
foreach ($packages as $name => $entry) {
  $result = SatisMetadataBuilder::packageConstraint($entry);
  l4_is($name . ' 条目合法', $result['code'], SatisMetadataBuilder::CODE_OK);
  $declaredFiles = [];
  foreach (is_array($entry['versions'] ?? NULL) ? $entry['versions'] : [] as $raw) {
    if (is_array($raw)) {
      $declaredFiles[ltrim((string) ($raw['version'] ?? ''), 'v')] = trim((string) ($raw['dist_file'] ?? ''));
    }
  }
  foreach ($result['constraint'] as $version => $data) {
    $declared = (string) ($declaredFiles[(string) $version] ?? '');
    if ($declared !== '') {
      l4_is($name . ':' . $version . ' 产物路径 = 规范默认路径', ltrim($declared, '/'), SatisMetadataBuilder::defaultDistPath((string) $name, (string) $version));
    }
    l4_is($name . ':' . $version . ' 属于 L2 层', $data['extra']['drupalx']['layer'], 'L2');
    l4_true($name . ':' . $version . ' 有 dist', isset($data['dist']['type'], $data['dist']['reference']));
    l4_true($name . ':' . $version . ' 产物地址逐版本唯一', !str_ends_with((string) $data['dist']['url'], '/dist/'));
  }
}
$index = $manifest->versionIndex();
l4_is('版本索引已排序', $index, (function () use ($index): array { sort($index); return $index; })());
l4_is('版本索引无重复', count(array_unique($index)), count($index));
l4_true('版本索引非空', $index !== []);
l4_true('按名字与版本命中', $manifest->find('DrupalX/DX_Payment', 'v1.0.0') !== NULL);
l4_is('未知版本不命中', $manifest->find('drupalx/dx_payment', '9.9.9'), NULL);
l4_is('包名规范化', SatisMetadataBuilder::normalizeName(' DrupalX/DX_Payment '), 'drupalx/dx_payment');
l4_is('非法包名被拒', SatisMetadataBuilder::isValidName('drupalx'), FALSE);
l4_is('provider 文件名安全', str_contains(SatisMetadataBuilder::providerFileName('drupalx/dx_payment'), '/'), FALSE);
l4_is('provider 名可逆', SatisMetadataBuilder::nameFromProviderKey(SatisMetadataBuilder::providerKey('drupalx/dx_payment')), 'drupalx/dx_payment');
l4_is('坏名字 → 稳定码', SatisMetadataBuilder::packageConstraint(['name' => 'oops'])['code'], SatisMetadataBuilder::CODE_NAME_INVALID);
l4_is('无版本 → 稳定码', SatisMetadataBuilder::packageConstraint(['name' => 'drupalx/x', 'versions' => []])['code'], SatisMetadataBuilder::CODE_NO_VERSIONS);
$broken = SatisMetadataBuilder::lint([
  ['name' => 'oops', 'versions' => []],
  ['name' => 'drupalx/empty', 'versions' => [['version' => '  ']]],
]);
l4_is('lint 报告全部问题', count($broken), 3);
l4_is('lint 首条码', $broken[0]['code'], SatisMetadataBuilder::CODE_NAME_INVALID);
l4_is('lint 逐条展开到包名', $broken[2]['name'], 'drupalx/empty');
$noRoot = $manifest->lint();
l4_true('未配置仓库根时 lint 明确报错', in_array(SatisMetadataBuilder::CODE_NO_DIST, array_column($noRoot, 'code'), TRUE));
$ghost = $manifest->lint('/tmp/definitely-not-an-l2-root');
l4_is('产物不存在逐条报错', count($ghost), count($manifest->artifacts('/tmp/definitely-not-an-l2-root')));
foreach ($manifest->artifacts('/tmp/definitely-not-an-l2-root') as $row) {
  l4_is($row['name'] . ' 产物缺失被标记', $row['exists'], FALSE);
}

$constraints = $manifest->constraints(['dist_url' => 'https://packages.example.com/dist/']);
$root = SatisMetadataBuilder::rootDocument($constraints, ['driver' => 'satis', 'base_url' => 'https://packages.example.com', 'generated' => $now]);
l4_is('内联 packages 覆盖全清单（旧 composer 也能解析）', count($root['packages']), count($constraints));
l4_is('provider-includes 每个包一条', count($root['provider-includes']), count($constraints));
l4_is('元数据来源记录驱动', $root['metadata-source']['driver'], 'satis');
l4_is('元数据来源记录生成时间', $root['metadata-source']['generated'], $now);
l4_is('minified 标记为字符串', $root['minified'], 'true');
$one = ['drupalx/dx_payment' => $constraints['drupalx/dx_payment']];
l4_is('provider 文档指纹自洽', $root['provider-includes'][SatisMetadataBuilder::providerFileName('drupalx/dx_payment')]['sha256'], SatisMetadataBuilder::fingerprint(SatisMetadataBuilder::encode(SatisMetadataBuilder::providerDocument($one))));

// ─────────────────────────────────────────────────────────────────────────────
l4_section('I1 离线构建回环（临时目录）');

$build = l4_tmp('dxl2-build');
$src = l4_tmp('dxl2-src');
$builder = new L2RepositoryBuilder($manifest);
$inside = $builder->build($build . '/nested', ['repo_root' => $build]);
l4_is('拒绝建在代码库内', $inside['code'], L2RepositoryBuilder::CODE_DEST_INSIDE_REPO);
l4_is('拒绝时不写文件', is_dir($build . '/nested'), FALSE);
$relative = $builder->build('relative/path', ['repo_root' => $build]);
l4_is('必须有绝对目录', $relative['code'], L2RepositoryBuilder::CODE_DEST_NOT_WRITABLE);

$stage = $build . '/out';
foreach ($manifest->artifacts($src) as $row) {
  l4_put($src, (string) $row['path'], "zip-placeholder-" . $row['name'] . '-' . $row['version']);
}
$built = $builder->build($stage, ['src' => $src, 'repo_root' => '/does/not/matter', 'generated' => $now, 'base_url' => 'file://' . $stage]);
l4_is('构建成功码', $built['code'], L2RepositoryBuilder::CODE_OK);
l4_is('构建无问题', $built['issues'], []);
l4_true('packages.json 已写出', in_array(SatisMetadataBuilder::ROOT_FILE, $built['written'], TRUE));
l4_true('每个包一个 provider 文件', count($built['written']) >= count($constraints) + 1);
$builtRoot = json_decode((string) file_get_contents($stage . '/' . SatisMetadataBuilder::ROOT_FILE), TRUE);
l4_true('根文档含 provider-includes', is_array($builtRoot['provider-includes'] ?? NULL));
foreach ($builtRoot['provider-includes'] as $file => $meta) {
  l4_is($file . ' 落盘字节与指纹一致（composer 会校验）', hash_file('sha256', $stage . '/' . $file), (string) $meta['sha256']);
}
$builtPayment = $builtRoot['packages']['drupalx/dx_payment']['1.0.0'];
l4_true('产物绝对路径存在', is_file($stage . '/' . $builtPayment['extra']['drupalx']['artifact']));
l4_is('dist 绝对地址', $builtPayment['dist']['url'], 'file://' . $stage . '/' . $builtPayment['extra']['drupalx']['artifact']);
l4_is('dist shasum 来自磁盘', $builtPayment['dist']['shasum'], sha1_file($stage . '/' . $builtPayment['extra']['drupalx']['artifact']));
$noSrc = $builder->build($build . '/meta-only', ['repo_root' => '/does/not/matter']);
l4_is('无 --src 时明确报产物缺失', $noSrc['code'], L2RepositoryBuilder::CODE_ARTIFACT_MISSING);
l4_true('无 --src 仍生成元数据', is_file($build . '/meta-only/' . SatisMetadataBuilder::ROOT_FILE));

// The three steps `composer install` runs, replayed against the tree on disk:
// root document → provider file → artifact bytes. This is the offline half of
// the loopback case in scripts/ci/l2-credential-smoke.sh.
$pullPlan = ComposerHostPlan::fromSettings([
  'l2_composer_base_url' => $stage,
  'l2_repository_root' => $stage,
  'l2_signing_key' => $secret,
  'l2_download_ttl' => 60,
]);
l4_is('目录基址 → loopback 驱动', $pullPlan['driver'], ComposerHostPlan::DRIVER_LOOPBACK);
l4_true('回环不需要网络', $pullPlan['loopback']);
l4_is('回环基址就是磁盘目录', ComposerHostPlan::pathFromUrl((string) $pullPlan['base_url']), $stage);
l4_is('回环根地址指向磁盘上的 packages.json', ComposerHostPlan::pathFromUrl((string) $pullPlan['root_url']), $stage . '/' . SatisMetadataBuilder::ROOT_FILE);
l4_is('仓库块指向 packages.json', basename((string) ComposerHostPlan::repositoriesSnippet($pullPlan, (string) $pullPlan['root_url'])['repositories']['drupalx-l2']['url']), SatisMetadataBuilder::ROOT_FILE);
$pullName = 'drupalx/dx_payment';
$pullFile = SatisMetadataBuilder::providerFileName($pullName);
l4_true('provider 文件在树上', is_file($stage . '/' . $pullFile));
$providerDoc = json_decode((string) file_get_contents($stage . '/' . $pullFile), TRUE);
l4_is('provider 文档只含该包', array_keys($providerDoc['packages']), [$pullName]);
l4_is('provider 与根文档同一版本集', array_keys($providerDoc['packages'][$pullName]), array_keys($builtRoot['packages'][$pullName]));
l4_is('provider 与根文档同一 shasum', $providerDoc['packages'][$pullName]['1.0.0']['dist']['shasum'], $builtRoot['packages'][$pullName]['1.0.0']['dist']['shasum']);
l4_is('provider 自指指纹可复现', $providerDoc['providers'][$pullName], SatisMetadataBuilder::fingerprint(SatisMetadataBuilder::encode($builtRoot['packages'][$pullName])));
$pullArtifact = (string) $builtPayment['extra']['drupalx']['artifact'];
l4_is('清单产物路径与磁盘一致', ComposerHostPlan::pathFromUrl((string) $builtPayment['dist']['url']), $stage . '/' . $pullArtifact);
l4_is('产物 sha1 与元数据一致（composer 会校验）', sha1_file($stage . '/' . $pullArtifact), (string) $builtPayment['dist']['shasum']);
// Same artifact over the served route: the signature in the query is the only
// authority, and it is bound to the credential that got the metadata.
$servedQuery = DownloadUrlSigner::sign($secret, RepositoryRequestAuth::sanitizeArtifactPath($pullArtifact), $now, 60, '9');
parse_str((string) http_build_query($servedQuery), $roundTrip);
l4_is('签名链接可回验', DownloadUrlSigner::verify($secret, $pullArtifact, $roundTrip, $now, '9')['code'], DownloadUrlSigner::CODE_OK);
l4_is('签名链接过期即拒', DownloadUrlSigner::verify($secret, $pullArtifact, $roundTrip, $now + 61, '9')['code'], DownloadUrlSigner::CODE_EXPIRED);
l4_is('签名链接不能给别人', DownloadUrlSigner::verify($secret, $pullArtifact, $roundTrip, $now, '10')['code'], DownloadUrlSigner::CODE_UID_MISMATCH);
l4_is('产物路由仍在守卫面内', RepositoryRequestAuth::shouldGuard('/' . trim(ComposerHostPlan::SERVE_PREFIX, '/') . '/dist/' . $pullArtifact), TRUE);
l4_is('产物路由还原清单相对路径', RepositoryRequestAuth::classifyPath('/' . trim(ComposerHostPlan::SERVE_PREFIX, '/') . '/dist/' . $pullArtifact)['dist'], $pullArtifact);
// The served provider request has to survive two translations: Composer writes
// `%package%` as `vendor%2Fpackage`, and core's path_processor_decode (priority
// 1000) urldecodes the inbound path before the matcher runs. Both spellings
// must match the route requirement, or the loopback pull 404s on step two.
$l4Routing = Symfony\Component\Yaml\Yaml::parseFile($repoRoot . '/web/modules/custom/dx_ecosystem/dx_ecosystem.routing.yml');
$providerPattern = (string) ($l4Routing['dx_ecosystem.l2_provider']['requirements']['provider'] ?? '');
l4_true('provider 路由有字符需求', $providerPattern !== '');
l4_is('provider 路由 path 不变', $l4Routing['dx_ecosystem.l2_provider']['path'], '/dx/ecosystem/l2/providers/{provider}');
$rawKey = SatisMetadataBuilder::providerKey($pullName) . SatisMetadataBuilder::PROVIDER_SUFFIX;
$decodedKey = urldecode($rawKey);
l4_is('provider 键未解码形态可匹配路由', (bool) preg_match('~^' . $providerPattern . '$~', $rawKey), TRUE);
l4_is('provider 键解码形态可匹配路由', (bool) preg_match('~^' . $providerPattern . '$~', $decodedKey), TRUE);
l4_is('未解码的 provider 还原包名', SatisMetadataBuilder::nameFromProviderKey($rawKey), $pullName);
l4_is('解码后的 provider 还原包名', SatisMetadataBuilder::nameFromProviderKey($decodedKey), $pullName);
l4_is('provider 请求仍在守卫前缀内', RepositoryRequestAuth::shouldGuard('/dx/ecosystem/l2/providers/' . $decodedKey), TRUE);
l4_is('provider 请求归类为 provider', RepositoryRequestAuth::classifyPath('/dx/ecosystem/l2/providers/' . $decodedKey)['provider'], $pullName);

l4_rm($build);
l4_rm($src);

// ─────────────────────────────────────────────────────────────────────────────
l4_section('I3 报表聚合（签发者 / IP / 次数 / 最后使用）');

$credentials = [
  [
    'uid' => 9,
    'state' => CredentialLifecycle::STATE_ACTIVE,
    'prefix' => 'dxl2_aaaaaaa',
    'issued_by' => 1,
    'issued_ip' => '203.0.113.7',
    'issued_via' => 'web',
    'created' => $now - 86400 * 10,
    'rotations' => 2,
    'uses' => 3,
    'last_used' => $now - 3600,
    'revoked_at' => 0,
    'revoke_reason' => '',
    'cert_status' => DeveloperCertificationStore::STATUS_CERTIFIED,
  ],
  [
    'uid' => 12,
    'state' => CredentialLifecycle::STATE_REVOKED,
    'prefix' => 'dxl2_bbbbbbb',
    'issued_by' => 12,
    'issued_ip' => '',
    'created' => $now - 86400 * 40,
    'rotations' => 0,
    'uses' => 0,
    'last_used' => 0,
    'revoked_at' => $now - 86400,
    'revoke_reason' => 'certification_revoked',
    'cert_status' => DeveloperCertificationStore::STATUS_REVOKED,
  ],
];
$events = [
  ['uid' => 9, 'event' => CredentialAuditLog::EVENT_ISSUE, 'actor_uid' => 1, 'ip' => '203.0.113.7', 'created' => $now - 86400 * 10, 'code' => RepositoryRequestAuth::CODE_OK],
  ['uid' => 9, 'event' => CredentialAuditLog::EVENT_METADATA, 'actor_uid' => 0, 'ip' => '198.51.100.4', 'created' => $now - 7200, 'code' => RepositoryRequestAuth::CODE_OK],
  ['uid' => 9, 'event' => CredentialAuditLog::EVENT_DOWNLOAD, 'actor_uid' => 0, 'ip' => '198.51.100.4', 'created' => $now - 3600, 'code' => RepositoryRequestAuth::CODE_OK],
  ['uid' => 9, 'event' => CredentialAuditLog::EVENT_VERIFY_FAIL, 'actor_uid' => 0, 'ip' => '192.0.2.9', 'created' => $now - 1800, 'code' => RepositoryRequestAuth::CODE_REVOKED],
  ['uid' => 12, 'event' => CredentialAuditLog::EVENT_AUTO_REVOKE, 'actor_uid' => 1, 'ip' => '203.0.113.7', 'created' => $now - 86400, 'code' => RepositoryRequestAuth::CODE_CERT_REVOKED],
  ['uid' => 77, 'event' => CredentialAuditLog::EVENT_DENIED, 'actor_uid' => 0, 'ip' => '192.0.2.50', 'created' => $now - 60, 'code' => RepositoryRequestAuth::CODE_MISSING],
];
$report = CredentialReport::aggregate($credentials, $events, $now);
$row9 = array_column($report['rows'], NULL, 'uid')[9];
$row12 = array_column($report['rows'], NULL, 'uid')[12];
$row77 = array_column($report['rows'], NULL, 'uid')[77];
l4_is('报表行数 = 凭证数 + 仅有事件的 uid', count($report['rows']), 3);
l4_is('签发者来自 issue 事件', $row9['issued_by'], 1);
l4_is('签发者姓名回退', $row9['issued_by_name'], 'uid-1');
l4_is('来源 IP 跟随签发事件', $row9['issued_ip'], '203.0.113.7');
l4_is('调用次数取行内与事件的较大值', $row9['uses'], 3);
l4_is('最后使用取较大时间戳', $row9['last_used'], $now - 3600);
l4_is('闲置天数', $row9['idle_days'], 0);
l4_is('去重 IP 数', $row9['distinct_ips'], 3);
l4_is('最热 IP 排第一', $row9['top_ips'][0], '198.51.100.4');
l4_is('拒绝次数只算 verify_fail/denied', $row9['denials'], 1);
l4_is('最近事件带码', $row9['last_event_code'], RepositoryRequestAuth::CODE_REVOKED);
l4_is('事件计数按名字分桶', $row9['event_counts'][CredentialAuditLog::EVENT_METADATA], 1);
l4_is('吊销态保留', $row12['state'], CredentialLifecycle::STATE_REVOKED);
l4_is('吊销原因进入报表', $row12['revoke_reason'], 'certification_revoked');
l4_is('吊销不产生闲置天数假数据', $row12['idle_days'], NULL);
l4_is('已删除凭证的事件仍可见', $row77['state'], CredentialLifecycle::STATE_NONE);
l4_is('总计：凭证数', $report['totals']['credentials'], 3);
l4_is('总计：active', $report['totals']['active'], 1);
l4_is('总计：revoked', $report['totals']['revoked'], 1);
l4_is('总计：从未签发（仅事件）', $report['totals']['never_issued'], 1);
l4_is('总计：调用次数', $report['totals']['uses'], 3);
l4_is('总计：轮换', $report['totals']['rotations'], 2);
l4_is('总计：拒绝', $report['totals']['denials'], 2);
l4_is('总计：他人代签', $report['totals']['issued_by_others'], 1);
// Ties keep insertion order (PHP >= 8.0 stable sort): 9, then the two unused rows.
l4_is('行序按最后使用倒序', array_column($report['rows'], 'uid'), [9, 12, 77]);
l4_is('空输入不崩', CredentialReport::aggregate([], [], $now)['totals']['credentials'], 0);
l4_is('表头列数 = 列定义', count(array_unique(array_map([CredentialReport::class, 'headerLabel'], CredentialReport::columns()))), count(CredentialReport::columns()));
l4_true('报表统计的使用类事件与审计层一致', count(array_intersect(CredentialAuditLog::usageEvents(), CredentialAuditLog::events())) === count(CredentialAuditLog::usageEvents()));
l4_true('所有审计事件名唯一', count(CredentialAuditLog::events()) === count(array_unique(CredentialAuditLog::events())));
l4_is('CLI 无请求时来源记录为 cli', CredentialAuditLog::fallbackIp(), 'cli');
l4_is('审计表名固定（升级脚本与文档引用）', CredentialAuditLog::TABLE, 'dx_l2_credential_event');

// ─────────────────────────────────────────────────────────────────────────────
l4_section('I4 L0 可见性过滤与门禁');

$codes = dx_l0_exit_codes();
l4_is('门禁通过 = 0', $codes['DX.L0.OK'], 0);
l4_is('未登记文档 = 4', dx_l0_exit_code('DX.L0.UNREGISTERED'), 4);
l4_is('白名单缺失 = 3', dx_l0_exit_code('DX.L0.WHITELIST_MISSING'), 3);
l4_is('stale 规则只警告 = 0', $codes['DX.L0.STALE_RULE'], 0);
l4_is('未知码退回 1', dx_l0_exit_code('DX.L0.NOPE'), 1);
$blocking = array_flip(['DX.L0.ERROR', 'DX.L0.WHITELIST_MISSING', 'DX.L0.UNREGISTERED', 'DX.L0.VISIBILITY_UNKNOWN', 'DX.L0.PATH_UNSAFE', 'DX.L0.DEST_REFUSED', 'DX.L0.VERIFY_FAILED', 'DX.L0.INCLUDE_MISSING']);
$blockingCodes = array_values(array_intersect_key($codes, $blocking));
l4_is('阻断码各不相同', count(array_unique($blockingCodes)), count($blockingCodes));
l4_is('阻断码全部 >= 1', count(array_filter($blockingCodes, static fn(int $c): bool => $c >= 1)), count($blockingCodes));
l4_is('退出码表条目数', count($codes), 11);
l4_is('可见性枚举', dx_l0_visibilities(), ['public', 'partner', 'internal']);

$paths = [
  'docs/DEPLOY.md' => 'internal',
  'docs/skills' => 'public',
  'web/modules/custom/dx_ecosystem/data/partner' => 'partner',
];
$hit = dx_l0_visibility_lookup('docs/DEPLOY.md', $paths, 'public');
l4_is('精确键优先', $hit['visibility'], 'internal');
l4_is('精确键不算继承', $hit['inherited'], FALSE);
$inherited = dx_l0_visibility_lookup('docs/skills/x-pack-android.md', $paths, 'public');
l4_is('目录键可继承', $inherited['visibility'] . ':' . $inherited['key'], 'public:docs/skills');
l4_is('继承有标记', $inherited['inherited'], TRUE);
$longest = dx_l0_visibility_lookup('web/modules/custom/dx_ecosystem/data/partner/deep/doc.md', $paths, 'public');
l4_is('最长前缀胜出', $longest['key'], 'web/modules/custom/dx_ecosystem/data/partner');
l4_is('未命中回落默认值', dx_l0_visibility_lookup('README.md', $paths, 'public')['visibility'], 'public');
l4_is('未命中没有键', dx_l0_visibility_lookup('README.md', $paths, 'public')['key'], '');
l4_true('裸名 glob 匹配 basename', dx_l0_matches_patterns('docs/open-ecosystem.md', ['*.md']));
l4_false('裸名 glob 不吃其它扩展', dx_l0_matches_patterns('composer.json', ['*.md']));
l4_true('带斜杠的模式按整路径', dx_l0_matches_patterns('docs/lanes/L4.md', ['docs/lanes/*.md']));
l4_false('带斜杠的模式不限 basename', dx_l0_matches_patterns('other/L4.md', ['docs/lanes/*.md']));
l4_true('exclude 前缀命中', dx_l0_in_any('setup/ha/dns.sh', ['setup/ha']));
l4_false('exclude 不做子串匹配', dx_l0_in_any('setup/hack/x.sh', ['setup/ha']));

$fixture = l4_tmp('dxl0');
$export = l4_tmp('dxl0-out');
l4_put($fixture, 'docs/public-doc.md', "# public\n");
l4_put($fixture, 'docs/private-doc.md', "# internal\n");
l4_put($fixture, 'docs/unregistered.md', "# new doc\n");
l4_put($fixture, 'docs/sub/inherited.md', "# inherited\n");
l4_put($fixture, 'docs/api/keep.html', "<html></html>\n");
l4_put($fixture, 'docs/visibility.yml', "default: public\npaths:\n  docs: public\n  docs/public-doc.md: public\n  docs/private-doc.md: internal\n  docs/sub: public\n");
$gateWhitelist = [
  'version' => 9,
  'layer' => 'L0',
  'license' => 'GPL-2.0-or-later',
  'include' => ['docs'],
  'exclude' => [],
  'must_include' => [],
  'must_exclude' => [],
  'gate' => ['enforce' => TRUE, 'register' => ['*.md'], 'require_explicit' => ['docs']],
];
$plan = dx_l0_plan($fixture, $gateWhitelist);
l4_is('未登记文档 → 门禁码', $plan['code'], 'DX.L0.UNREGISTERED');
l4_is('门禁退出码 4', dx_l0_gate($plan)['exit'], 4);
l4_is('未登记清单', array_column($plan['unregistered'], 'path'), ['docs/unregistered.md']);
$entriesByPath = array_column($plan['entries'], NULL, 'path');
l4_false('严格目录里只继承目录键 = 未登记', (bool) ($entriesByPath['docs/unregistered.md']['registered'] ?? TRUE));
l4_is('未登记条目的动作标记', $entriesByPath['docs/unregistered.md']['action'] ?? '', 'undecided');
l4_true('严格目录的显式键仍算登记', (bool) ($entriesByPath['docs/public-doc.md']['registered'] ?? FALSE));
l4_true('子目录继承仍算登记', in_array('docs/sub/inherited.md', array_column($plan['published'], 'path'), TRUE));
// Only gate.register patterns (here *.md) are classified, so keep.html is absent.
l4_is('public 桶', array_values(array_column(dx_l0_filter($plan, 'public'), 'path')), ['docs/public-doc.md', 'docs/sub/inherited.md']);
l4_is('非登记模式文件不进入清单', $entriesByPath['docs/api/keep.html'] ?? NULL, NULL);
l4_is('internal 桶', array_column(dx_l0_filter($plan, 'internal'), 'path'), ['docs/private-doc.md']);
l4_is('partner 桶为空', dx_l0_filter($plan, 'partner'), []);
l4_is('未知可见性取值退回空', dx_l0_filter($plan, 'nonsense'), []);
l4_contains(['DX.L0.UNREGISTERED', 'whitelist v9'], dx_l0_render_text($plan), 'CI 日志一行可读且带码');
$lenient = dx_l0_plan($fixture, ['version' => 9, 'include' => ['docs'], 'exclude' => [], 'must_include' => [], 'must_exclude' => [], 'gate' => ['enforce' => FALSE, 'register' => ['*.md'], 'require_explicit' => ['docs']]]);
l4_is('enforce:false 降级为警告', $lenient['code'], 'DX.L0.OK');
l4_true('降级时仍列出问题', dx_l0_gate($lenient)['warnings'] !== []);
l4_is('降级时未登记项转为警告', array_column(dx_l0_gate($lenient)['warnings'], 'path'), ['docs/unregistered.md']);
l4_is('降级时没有阻断项', dx_l0_gate($lenient)['issues'], []);
l4_is('降级时退出码为 0', dx_l0_gate($lenient)['exit'], 0);
l4_contains(['DX.L0.UNREGISTERED', '不阻断'], dx_l0_render_text($lenient), '降级仍在日志里留痕');

$fixed = str_replace("  docs/sub: public\n", "  docs/sub: public\n  docs/unregistered.md: partner\n", (string) file_get_contents($fixture . '/docs/visibility.yml'));
l4_put($fixture, 'docs/visibility.yml', $fixed);
$plan2 = dx_l0_plan($fixture, $gateWhitelist);
l4_is('补登记后门禁通过', $plan2['code'], 'DX.L0.OK');
l4_is('补登记后退出 0', dx_l0_gate($plan2)['exit'], 0);
l4_is('partner 文档被剥离', in_array('docs/unregistered.md', array_column(dx_l0_filter($plan2, 'partner'), 'path'), TRUE), TRUE);

$report2 = dx_l0_publish($fixture, $export, $gateWhitelist);
l4_true('公开文档进入导出', is_file($export . '/docs/public-doc.md'));
l4_false('内部文档不进入导出', is_file($export . '/docs/private-doc.md'));
l4_false('伙伴文档不进入导出', is_file($export . '/docs/unregistered.md'));
l4_true('导出自带 API 文档页', is_file($export . '/docs/api/index.html'));
l4_true('导出标记为 L0 快照', is_file($export . '/L0-README.md'));
dx_l0_verify($export, $gateWhitelist);
l4_is('清单文件写出', basename(dx_l0_write_manifest($export, $plan2)), 'L0-MANIFEST.txt');
l4_contains(['public docs/public-doc.md', 'excluded(internal) docs/private-doc.md'], (string) file_get_contents($export . '/L0-MANIFEST.txt'), '清单同时记录发布与剥离');
try {
  dx_l0_publish($fixture, $fixture . '/inside', $gateWhitelist);
  l4_is('拒绝导出到源树内', 'no-throw', 'RuntimeException');
}
catch (RuntimeException $e) {
  l4_contains(['Refusing to publish into the source tree'], $e->getMessage(), '拒绝导出到源树内');
}
try {
  dx_l0_verify($export, ['must_include' => ['docs/never.md'], 'must_exclude' => [], 'gate' => []]);
  l4_is('must_include 缺失必须报错', 'no-throw', 'RuntimeException');
}
catch (RuntimeException $e) {
  l4_contains(['missing required path'], $e->getMessage(), 'must_include 缺失必须报错');
}
try {
  dx_l0_verify($export, ['must_include' => [], 'must_exclude' => ['docs/public-doc.md'], 'gate' => []]);
  l4_is('must_exclude 命中必须报错', 'no-throw', 'RuntimeException');
}
catch (RuntimeException $e) {
  l4_contains(['leaked excluded path'], $e->getMessage(), 'must_exclude 命中必须报错');
}
try {
  dx_l0_assert_relative('../escape');
  l4_is('不安全路径必须拒绝', 'no-throw', 'RuntimeException');
}
catch (RuntimeException $e) {
  l4_contains(['Refusing unsafe whitelist path'], $e->getMessage(), '不安全路径必须拒绝');
}
// A module's `data/` payload is not Markdown, so `gate.register` never looks at
// it — but an explicit visibility key still has to take it out of the export.
// This is the I1 L2 catalog leak: the public tree may carry the code that talks
// to the private repository, never the repository's own index.
$leakFixture = l4_tmp('dxl0-leak');
$leakExport = l4_tmp('dxl0-leak-out');
l4_put($leakFixture, 'web/modules/custom/dx_ecosystem/data/composer/manifest.yml', "packages:\n  - drupalx/dx_payment\n");
l4_put($leakFixture, 'web/modules/custom/dx_ecosystem/src/Service/L2ComposerRepository.php', "<?php\n");
l4_put($leakFixture, 'docs/visibility.yml', "default: public\npaths:\n  web/modules/custom: public\n  web/modules/custom/dx_ecosystem/data/composer: partner\n");
$leakWhitelist = [
  'version' => 1,
  'include' => ['web/modules/custom'],
  'exclude' => ['web/modules/custom/dx_ecosystem/data/composer'],
  'must_include' => ['web/modules/custom/dx_ecosystem/src/Service/L2ComposerRepository.php'],
  'must_exclude' => ['web/modules/custom/dx_ecosystem/data/composer/manifest.yml'],
  'gate' => ['enforce' => TRUE, 'register' => ['*.md']],
];
$leakPlan = dx_l0_plan($leakFixture, $leakWhitelist);
l4_is('非登记模式的载荷不参与登记', $leakPlan['counts']['unregistered'], 0);
dx_l0_publish($leakFixture, $leakExport, $leakWhitelist);
l4_false('partner 清单不进入导出', is_file($leakExport . '/web/modules/custom/dx_ecosystem/data/composer/manifest.yml'));
l4_false('partner 目录整个不进入导出', is_dir($leakExport . '/web/modules/custom/dx_ecosystem/data/composer'));
l4_true('public 代码仍进入导出', is_file($leakExport . '/web/modules/custom/dx_ecosystem/src/Service/L2ComposerRepository.php'));
$leakVerify = '';
try {
  dx_l0_verify($leakExport, $leakWhitelist);
}
catch (RuntimeException $e) {
  $leakVerify = $e->getMessage();
}
l4_is('导出过 must_exclude 验证', $leakVerify, '');
// visibility.yml is the enforcement point, not the whitelist `exclude` list:
// forgetting that line is exactly how an L2 catalog would leak.
$visibilityOnly = ['version' => 1, 'include' => ['web/modules/custom'], 'exclude' => [], 'must_include' => [], 'must_exclude' => ['web/modules/custom/dx_ecosystem/data/composer/manifest.yml'], 'gate' => ['enforce' => TRUE, 'register' => ['*.md']]];
dx_l0_publish($leakFixture, $leakExport . '-2', $visibilityOnly);
l4_false('只靠可见性键也能剥离', is_file($leakExport . '-2/web/modules/custom/dx_ecosystem/data/composer/manifest.yml'));
l4_rm($leakFixture);
l4_rm($leakExport);
l4_rm($leakExport . '-2');

l4_rm($fixture);
l4_rm($export);

// ─────────────────────────────────────────────────────────────────────────────
l4_section('I4 真实仓库的门禁状态（无 .env、无数据库）');

$realWhitelist = dx_l0_load_whitelist($repoRoot);
l4_true('白名单有 include', $realWhitelist['include'] !== []);
l4_is('门禁配置 enforce', $realWhitelist['gate']['enforce'] ?? NULL, TRUE);
l4_is('严格登记目录', $realWhitelist['gate']['require_explicit'] ?? NULL, ['docs']);
$realPlan = dx_l0_plan($repoRoot);
l4_is('当前仓库必须通过门禁（新增文档要先登记）', $realPlan['code'], 'DX.L0.OK');
l4_is('未登记数为 0', $realPlan['counts']['unregistered'], 0);
l4_true('真实仓库扫描到文档', $realPlan['files_seen'] > 100);
l4_is('导出内容与登记一致', count(dx_l0_filter($realPlan, 'public')), $realPlan['counts']['public']);
foreach (dx_l0_filter($realPlan, 'internal') as $row) {
  l4_true('internal 条目 ' . $row['path'], $row['action'] === 'strip');
}
l4_is('L2 私有清单不在公开面', in_array('web/modules/custom/dx_ecosystem/data/composer/manifest.yml', array_column(dx_l0_filter($realPlan, 'public'), 'path'), TRUE), FALSE);
$realVisibility = dx_l0_visibility_map($repoRoot);
l4_is('L2 清单目录自己占一行', $realVisibility['paths']['web/modules/custom/dx_ecosystem/data/composer'] ?? NULL, 'partner');
l4_is('L2 清单解析为 partner', dx_l0_visibility_lookup('web/modules/custom/dx_ecosystem/data/composer/manifest.yml', $realVisibility['paths'], $realVisibility['default'])['visibility'], 'partner');
l4_true('L2 清单在 exclude 面内', in_array('web/modules/custom/dx_ecosystem/data/composer', (array) $realWhitelist['exclude'], TRUE));
l4_true('must_exclude 钉住 L2 清单', in_array('web/modules/custom/dx_ecosystem/data/composer/manifest.yml', (array) $realWhitelist['must_exclude'], TRUE));

// ─────────────────────────────────────────────────────────────────────────────
$total = $GLOBALS['l4_pass'] + $GLOBALS['l4_fail'];
l4_show('');
l4_show(sprintf('PURE ASSERTIONS dx_ecosystem: %d/%d passed, %d failed', $GLOBALS['l4_pass'], $total, $GLOBALS['l4_fail']));
exit($GLOBALS['l4_fail'] > 0 ? 1 : 0);
