<?php

declare(strict_types=1);

namespace Drupal\dx_ecosystem\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\Site\Settings;
use Drupal\dx_ecosystem\Service\Composer\ComposerHostPlan;
use Drupal\dx_ecosystem\Service\Composer\DownloadUrlSigner;
use Drupal\dx_ecosystem\Service\Composer\RepositoryRequestAuth;
use Drupal\dx_ecosystem\Service\Composer\SatisMetadataBuilder;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The L2 repository adapter layer (I1).
 *
 * One place turns `dx_ecosystem.settings` into
 *  - a switchable host plan (Satis / Artifactory / offline loopback),
 *  - Satis-shaped metadata whose dist urls are signed per credential,
 *  - the access decision every endpoint shares.
 *
 * Nothing here knows *which* host is configured, so pointing DrupalX at a real
 * Satis or Artifactory install is a config change plus (optionally) a different
 * L2TokenVerifierInterface implementation — not a code change.
 */
final class L2ComposerRepository {

  public const DIST_SERVED = 'served';
  public const DIST_ARTIFACT = 'artifact';

  /** Set when the whole adapter is switched off. */
  public const CODE_DISABLED = 'DX.L2.REPO_DISABLED';

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected L2PackageManifest $manifest,
    protected L2TokenVerifierInterface $verifier,
    protected RequestStack $requestStack,
    protected UrlGeneratorInterface $urlGenerator,
    protected TimeInterface $time,
    protected ?PartnerCredentialStore $credentials = NULL,
  ) {}

  /**
   * Counts one repository use (metadata read or artifact download) for I3.
   *
   * @param array<string, mixed> $context
   */
  public function track(int $uid, string $event, string $code, array $context = []): void {
    $this->credentials?->recordUsage($uid, $code, $context + ['event' => $event]);
  }

  /**
   * Raw `dx_ecosystem.settings` as an array (defaults keep OE2 behaviour).
   *
   * @return array<string, mixed>
   */
  public function settings(): array {
    $config = $this->configFactory->get('dx_ecosystem.settings');
    return [
      'l2_composer_host' => (string) ($config->get('l2_composer_host') ?: ComposerHostPlan::PLACEHOLDER_COMPOSER_HOST),
      'l2_git_host' => (string) ($config->get('l2_git_host') ?: ComposerHostPlan::PLACEHOLDER_GIT_HOST),
      'l2_composer_base_url' => (string) ($config->get('l2_composer_base_url') ?? ''),
      'l2_repository_root' => (string) ($config->get('l2_repository_root') ?? ''),
      'l2_composer_driver' => (string) ($config->get('l2_composer_driver') ?? 'auto'),
      'l2_token_header' => (string) ($config->get('l2_token_header') ?? 'authorization'),
      'l2_signing_key' => (string) ($config->get('l2_signing_key') ?? ''),
      'l2_download_ttl' => (int) ($config->get('l2_download_ttl') ?? DownloadUrlSigner::DEFAULT_TTL),
      'l2_dist_mode' => (string) ($config->get('l2_dist_mode') ?? self::DIST_SERVED),
      'l2_repository_enabled' => $config->get('l2_repository_enabled') === NULL ? TRUE : (bool) $config->get('l2_repository_enabled'),
    ];
  }

  /**
   * The switchable host plan, resolved against the current request origin.
   *
   * @return array<string, mixed>
   */
  public function plan(): array {
    $request = $this->requestStack->getCurrentRequest();
    $origin = $request === NULL ? '' : (string) $request->getSchemeAndHttpHost();
    return ComposerHostPlan::fromSettings($this->settings(), $origin);
  }

  public function isEnabled(): bool {
    return (bool) ($this->settings()['l2_repository_enabled'] ?? FALSE);
  }

  public function verifier(): L2TokenVerifierInterface {
    return $this->verifier;
  }

  /**
   * Secret used to sign dist urls.
   *
   * Falls back to a hash-salt derived value so an unconfigured site still emits
   * verifiable links; ::plan() flags that as DX.L2.SIGN_KEY_WEAK, and the links
   * die with the salt (re-install) which is exactly the safe failure mode.
   */
  public function signingSecret(): string {
    $configured = trim((string) ($this->settings()['l2_signing_key'] ?? ''));
    if (DownloadUrlSigner::strongSecret($configured)) {
      return $configured;
    }
    $salt = (string) Settings::get('hash_salt', '');
    if ($salt === '') {
      return $configured;
    }
    return hash_hmac('sha256', 'drupalx-l2-dist', $salt);
  }

  /**
   * Access decision for an incoming token (delegates to the pluggable verifier).
   *
   * @param array<string, mixed> $context
   *   ip / user_agent / path, forwarded to the audit trail.
   *
   * @return array{ok: bool, uid: int|null, code: string, state: string, message: string, prefix: string}
   */
  public function authorize(string $token, array $context = []): array {
    if (!$this->isEnabled()) {
      return [
        'ok' => FALSE,
        'uid' => NULL,
        'code' => self::CODE_DISABLED,
        'state' => CredentialLifecycle::STATE_NONE,
        'message' => 'L2 仓库适配层已关闭（l2_repository_enabled=false）。',
        'prefix' => '',
      ];
    }
    return $this->verifier->verifyDetailed($token, $context);
  }

  /**
   * The root `packages.json` document, bound to one credential.
   *
   * @return array<string, mixed>
   */
  public function rootMetadata(int $uid, string $token = ''): array {
    $plan = $this->plan();
    $options = $this->metadataOptions($plan, $uid);
    $document = SatisMetadataBuilder::rootDocument($this->manifest->constraints($options), $options);
    $document['packages'] = $this->signDistUrls($document['packages'], $uid, $token);
    return $document;
  }

  /**
   * A single-package provider document, bound to one credential.
   *
   * @return array<string, mixed>|null
   */
  public function providerMetadata(string $name, int $uid, string $token = ''): ?array {
    $name = SatisMetadataBuilder::normalizeName($name);
    $constraints = $this->manifest->constraints($this->metadataOptions($this->plan(), $uid));
    if (!isset($constraints[$name])) {
      return NULL;
    }
    $signed = $this->signDistUrls([$name => $constraints[$name]], $uid, $token);
    return SatisMetadataBuilder::providerDocument($signed);
  }

  /**
   * Options handed to the metadata builder for one plan.
   *
   * @param array<string, mixed> $plan
   *
   * @return array<string, mixed>
   */
  public function metadataOptions(array $plan, int $uid = 0): array {
    $served = (string) $plan['served_root_url'] !== ''
      ? (string) $plan['served_root_url']
      : (string) $plan['root_url'];
    $dir = (string) preg_replace('#/' . preg_quote(SatisMetadataBuilder::ROOT_FILE, '#') . '$#', '', $served);
    return [
      'driver' => (string) $plan['driver'],
      'base_url' => (string) $plan['base_url'],
      'generated' => $this->time->getRequestTime(),
      'providers_url' => $dir . '/providers/%package%.json',
      // This root is generated per request; no provider-<name>.json exists at a
      // stable url, so Composer must look packages up through providers_url.
      'provider_includes' => FALSE,
      'dist_url' => $dir . '/dist/',
      'dist_mode' => (string) ($this->settings()['l2_dist_mode'] ?? self::DIST_SERVED),
      'repository_root' => (string) $plan['repository_root'],
      'uid' => $uid,
    ];
  }

  /**
   * Rewrites every `dist.url` into a signed, credential-bound download link.
   *
   * @param array<string, array<string, mixed>> $packages
   *
   * @return array<string, array<string, mixed>>
   */
  public function signDistUrls(array $packages, int $uid, string $token = ''): array {
    $secret = $this->signingSecret();
    $ttl = (int) ($this->plan()['download_ttl'] ?? DownloadUrlSigner::DEFAULT_TTL);
    $now = $this->time->getRequestTime();
    foreach ($packages as $name => $versions) {
      foreach ($versions as $version => $data) {
        if (!is_array($data) || !isset($data['dist']) || !is_array($data['dist'])) {
          continue;
        }
        $artifact = (string) ($data['extra']['drupalx']['artifact'] ?? SatisMetadataBuilder::defaultDistPath((string) $name, (string) $version));
        $data['dist']['url'] = $this->downloadUrl($artifact, $uid, $now, $ttl, $secret);
        $packages[$name][$version] = $data;
      }
    }
    return $packages;
  }

  /**
   * Absolute (or file://) url for one artifact, with signature parameters.
   */
  public function downloadUrl(string $artifact, int $uid, ?int $now = NULL, ?int $ttl = NULL, ?string $secret = NULL): string {
    $plan = $this->plan();
    $artifact = RepositoryRequestAuth::sanitizeArtifactPath($artifact);
    $now ??= $this->time->getRequestTime();
    if ((string) ($plan['driver'] ?? '') === ComposerHostPlan::DRIVER_LOOPBACK
      || (string) ($this->settings()['l2_dist_mode'] ?? self::DIST_SERVED) === self::DIST_ARTIFACT) {
      // Pure offline loopback: composer reads the zip straight from the shared
      // directory. No HTTP hop, hence nothing to sign.
      return ComposerHostPlan::fileUrl($artifact !== '' && $plan['repository_root'] !== ''
        ? rtrim((string) $plan['repository_root'], '/') . '/' . $artifact
        : $artifact);
    }
    $base = $this->urlGenerator->generate(
      'dx_ecosystem.l2_dist',
      ['artifact' => $artifact],
      UrlGeneratorInterface::ABSOLUTE_URL,
    );
    $secret ??= $this->signingSecret();
    $params = DownloadUrlSigner::sign(
      $secret,
      $artifact,
      $now,
      $ttl ?? (int) $plan['download_ttl'],
      $uid > 0 ? (string) $uid : '',
    );
    return DownloadUrlSigner::appendQuery($base, $params);
  }

  /**
   * Checks a signed download link and resolves the file on disk.
   *
   * @return array{ok: bool, code: string, path: string, size: int}
   */
  public function resolveDownload(string $artifact, array $query, int $uid): array {
    $artifact = RepositoryRequestAuth::sanitizeArtifactPath($artifact);
    if ($artifact === '') {
      return ['ok' => FALSE, 'code' => SatisMetadataBuilder::CODE_NO_DIST, 'path' => '', 'size' => 0];
    }
    if (!in_array($artifact, $this->knownArtifacts(), TRUE)) {
      // Never let the signature alone vouch for what is on disk.
      return ['ok' => FALSE, 'code' => SatisMetadataBuilder::CODE_NO_DIST, 'path' => '', 'size' => 0];
    }
    $result = DownloadUrlSigner::verify(
      $this->signingSecret(),
      $artifact,
      array_map('strval', $query),
      $this->time->getRequestTime(),
      (string) $uid,
    );
    $root = (string) ($this->plan()['repository_root'] ?? '');
    $path = $root === '' ? '' : rtrim($root, '/') . '/' . $artifact;
    if (!$result['ok']) {
      return ['ok' => FALSE, 'code' => $result['code'], 'path' => '', 'size' => 0];
    }
    if ($path === '' || !is_file($path) || !is_readable($path)) {
      return ['ok' => FALSE, 'code' => SatisMetadataBuilder::CODE_DIST_UNREADABLE, 'path' => '', 'size' => 0];
    }
    return [
      'ok' => TRUE,
      'code' => DownloadUrlSigner::CODE_OK,
      'path' => $path,
      'size' => (int) filesize($path),
    ];
  }

  /**
   * Repository-relative paths the manifest declares (the allow-list on disk).
   *
   * @return list<string>
   */
  public function knownArtifacts(): array {
    $out = [];
    foreach ($this->manifest->artifacts((string) ($this->plan()['repository_root'] ?? '/')) as $row) {
      $out[] = (string) $row['path'];
    }
    return array_values(array_unique($out));
  }

  /**
   * The `repositories` + `auth.json` blocks a partner needs for this plan.
   *
   * @return array<string, mixed>
   */
  public function clientConfig(int $uid, string $token): array {
    $plan = $this->plan();
    $rootUrl = (string) ($plan['loopback'] ? $plan['root_url'] : $plan['served_root_url']);
    return [
      'driver' => (string) $plan['driver'],
      'base_url' => ComposerHostPlan::redact((string) $plan['base_url']),
      'repositories' => ComposerHostPlan::repositoriesSnippet($plan, $rootUrl),
      'auth' => ComposerHostPlan::authSnippet($plan, $uid, $token),
      'token_header' => (string) $plan['token_header'],
      'git_clone' => sprintf(
        'git clone https://dx-uid-%d:<dxl2_token>@%s/partner/dx_oss.git',
        $uid,
        (string) $plan['git_host'],
      ),
      'warnings' => array_values($plan['warnings']),
    ];
  }

  /**
   * Machine-readable repo state for Drush / the admin page.
   *
   * @return array<string, mixed>
   */
  public function report(): array {
    $plan = $this->plan();
    return [
      'enabled' => $this->isEnabled(),
      'plan' => $plan,
      'verifier' => $this->verifier->name(),
      'signing' => DownloadUrlSigner::strongSecret($this->signingSecret()) ? 'ok' : 'weak',
      'packages' => $this->manifest->versionIndex(),
      'artifacts' => $this->manifest->artifacts((string) $plan['repository_root']),
      'issues' => $this->manifest->lint((string) $plan['repository_root']),
    ];
  }

}
