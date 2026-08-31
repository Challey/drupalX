<?php

declare(strict_types=1);

namespace Drupal\dx_ecosystem\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\dx_ecosystem\Service\AgreementAckStore;
use Drupal\dx_ecosystem\Service\AgreementRepository;
use Drupal\dx_ecosystem\Service\Composer\ComposerHostPlan;
use Drupal\dx_ecosystem\Service\Composer\RepositoryRequestAuth;
use Drupal\dx_ecosystem\Service\Composer\SatisMetadataBuilder;
use Drupal\dx_ecosystem\Service\CredentialAuditLog;
use Drupal\dx_ecosystem\Service\CredentialLifecycle;
use Drupal\dx_ecosystem\Service\CredentialReport;
use Drupal\dx_ecosystem\Service\DeveloperCertificationStore;
use Drupal\dx_ecosystem\Service\DeveloperGate;
use Drupal\dx_ecosystem\Service\L2ComposerRepository;
use Drupal\dx_ecosystem\Service\L2RepositoryBuilder;
use Drupal\dx_ecosystem\Service\PartnerCredentialStore;
use Drupal\dx_ecosystem\Service\PartnerDocRepository;
use Drupal\user\Entity\User;
use Drush\Commands\DrushCommands;
use Symfony\Component\HttpFoundation\Request;

/**
 * Drush helpers for open ecosystem OE1–OE3 and the Phase I L2 repository.
 */
final class EcosystemCommands extends DrushCommands {

  /**
   * Resolved on demand so the six-argument OE2 constructor keeps working.
   */
  protected ?PartnerCredentialStore $credentials = NULL;

  public function __construct(
    protected AgreementRepository $agreements,
    protected AgreementAckStore $acks,
    protected DeveloperCertificationStore $certs,
    protected DeveloperGate $gate,
    protected PartnerDocRepository $partnerDocs,
    protected ConfigFactoryInterface $configFactory,
    protected ?L2ComposerRepository $repository = NULL,
    protected ?L2RepositoryBuilder $builder = NULL,
    protected ?CredentialReport $report = NULL,
    protected ?CredentialAuditLog $audit = NULL,
  ) {
    parent::__construct();
  }

  /**
   * List agreement versions.
   *
   * @command dx:ecosystem-agreements
   * @aliases dx-oe-agreements
   */
  public function listAgreements(): void {
    foreach ($this->agreements->manifest() as $id => $meta) {
      $this->io()->writeln(sprintf('%s v%s — %s', $id, $meta['version'] ?? '?', $meta['title'] ?? ''));
    }
  }

  /**
   * List partner vault docs (no access check — CLI ops).
   *
   * @command dx:ecosystem-partner-docs
   */
  public function listPartnerDocs(): void {
    foreach ($this->partnerDocs->manifest() as $id => $meta) {
      $this->io()->writeln(sprintf('%s v%s — %s [%s]', $id, $meta['version'] ?? '?', $meta['title'] ?? '', $meta['visibility'] ?? 'partner'));
    }
  }

  /**
   * Sign current DPA and move developer to pending certification.
   *
   * @command dx:ecosystem-sign-dpa
   * @option uid User id to attribute (default 1)
   */
  public function signDpa(array $options = ['uid' => 1]): void {
    $dpa = $this->agreements->currentDpa();
    if ($dpa === NULL) {
      throw new \RuntimeException('DPA missing');
    }
    $uid = (int) $options['uid'];
    $this->acks->record('dpa', $dpa['version'], ['source' => 'drush'], $uid);
    $cert = $this->certs->markPending($uid, $dpa['version'], 'Signed via Drush');
    $this->logger()->success(sprintf(
      'Signed DPA v%s for uid %d → status=%s',
      $dpa['version'],
      $uid,
      $cert['status'],
    ));
  }

  /**
   * Certify a developer for L2 partner vault.
   *
   * @command dx:ecosystem-certify
   * @option uid User id
   * @option note Review note
   */
  public function certify(array $options = ['uid' => 1, 'note' => '']): void {
    $dpa = $this->agreements->currentDpa();
    if ($dpa === NULL) {
      throw new \RuntimeException('DPA missing');
    }
    $uid = (int) $options['uid'];
    $ack = $this->acks->latestDpaForUser($uid);
    if ($ack === NULL || ($ack['version'] ?? '') !== $dpa['version']) {
      throw new \RuntimeException('User must sign current DPA before certify');
    }
    $row = $this->certs->certify($uid, $dpa['version'], (string) $options['note'], 1);
    $this->logger()->success(sprintf('Certified uid %d (DPA v%s)', $row['uid'], $row['dpa_version']));
  }

  /**
   * Revoke developer certification.
   *
   * @command dx:ecosystem-revoke
   * @option uid User id
   * @option note Review note
   */
  public function revoke(array $options = ['uid' => 1, 'note' => '']): void {
    $uid = (int) $options['uid'];
    $row = $this->certs->revoke($uid, (string) $options['note'], 1);
    $this->logger()->success(sprintf('Revoked uid %d → %s', $row['uid'], $row['status']));
  }

  /**
   * Show personal registration switch, tenant_kind, and OE2 gate status.
   *
   * @command dx:ecosystem-status
   * @option uid User id for gate explain (default 1)
   */
  public function status(array $options = ['uid' => 1]): void {
    $cfg = $this->configFactory->get('dx_ecosystem.settings');
    $fields = \Drupal::service('entity_field.manager')->getFieldDefinitions('dx_tenant', 'dx_tenant');
    $ral = $this->agreements->currentRal();
    $dpa = $this->agreements->currentDpa();
    $uid = (int) $options['uid'];
    $loaded = User::load($uid);
    $account = $loaded ?: new AnonymousUserSession();
    $payload = [
      'personal_registration_enabled' => (bool) $cfg->get('personal_registration_enabled'),
      'require_ral_on_install' => (bool) $cfg->get('require_ral_on_install'),
      'ral' => $ral ? [
        'id' => $ral['id'],
        'version' => $ral['version'],
        'title' => $ral['title'],
      ] : NULL,
      'dpa' => $dpa ? [
        'id' => $dpa['id'],
        'version' => $dpa['version'],
        'title' => $dpa['title'],
      ] : NULL,
      'tenant_kind_field' => isset($fields['tenant_kind']),
      'ack_count' => count($this->acks->listAll()),
      'cert' => $this->certs->get($uid),
      'partner_gate' => $this->gate->explain($account),
      'partner_doc_count' => count($this->partnerDocs->manifest()),
      'pending_developers' => count($this->certs->listByStatus(DeveloperCertificationStore::STATUS_PENDING)),
      'certified_developers' => count($this->certs->listByStatus(DeveloperCertificationStore::STATUS_CERTIFIED)),
      'l0_whitelist' => \Drupal::service('dx_ecosystem.public_tree')->whitelistPath(),
      'openapi' => \Drupal::service('dx_ecosystem.public_tree')->openapiPath(),
      'l2_credential' => \Drupal::hasService('dx_ecosystem.credentials')
        ? \Drupal::service('dx_ecosystem.credentials')->status($uid)
        : NULL,
    ];
    $this->io()->writeln(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  }

  /**
   * Publish the L0 public-framework tree from the whitelist.
   *
   * The CI gate runs first: an unregistered document aborts the export with a
   * stable DX.L0.* code, so a whitelist change can never leak a new file.
   *
   * @command dx:ecosystem-publish-l0
   * @option dest Destination directory (must be outside the repo)
   * @option dry-run Plan and gate only; write nothing
   * @option skip-gate Publish even when the gate reports findings
   */
  public function publishL0(array $options = [
    'dest' => '/tmp/drupalx-l0-public',
    'dry-run' => FALSE,
    'skip-gate' => FALSE,
  ]): void {
    $publisher = \Drupal::service('dx_ecosystem.public_tree');
    $plan = $publisher->plan();
    $this->io()->writeln($publisher->renderPlan($plan));
    foreach (array_slice($plan['unregistered'] ?? [], 0, 20) as $row) {
      $this->io()->writeln('  [DX.L0.UNREGISTERED] ' . $row['path']);
    }
    if ($options['dry-run']) {
      $this->io()->writeln('Dry run: nothing written. Gate code ' . $plan['code'] . '.');
      return;
    }
    $gate = $publisher->gate();
    if (!$gate['ok'] && !$options['skip-gate']) {
      throw new \RuntimeException(sprintf(
        'L0 gate refused the export (%s, exit %d). Register the docs above in docs/visibility.yml, or pass --skip-gate.',
        $gate['code'],
        $gate['exit'],
      ));
    }
    $report = $publisher->publish((string) $options['dest']);
    $this->logger()->success(sprintf(
      'Published L0 tree to %s (copied %d includes, removed %d excludes)',
      $report['dest'],
      $report['copied'],
      $report['removed'],
    ));
  }

  /**
   * Issue or rotate an L2 Composer/Git credential (plaintext once).
   *
   * @command dx:ecosystem-issue-credential
   * @option uid Certified developer uid
   */
  public function issueCredential(array $options = ['uid' => 1]): void {
    $issued = \Drupal::service('dx_ecosystem.credentials')->issue((int) $options['uid']);
    $this->io()->writeln(json_encode($issued, JSON_UNESCAPED_SLASHES));
  }

  /**
   * Verify an L2 credential token (CLI smoke).
   *
   * The `ok` / `uid` keys are the OE2 contract; Phase I appends the stable
   * machine code so a revocation can be asserted, not just eyeballed.
   *
   * @command dx:ecosystem-verify-credential
   * @option token dxl2_… token
   */
  public function verifyCredential(array $options = ['token' => '']): void {
    $store = $this->credentialStore();
    $verdict = $store->verifyDetailed((string) $options['token'], ['path' => 'drush:verify']);
    $uid = $verdict['uid'];
    $this->io()->writeln(json_encode([
      'ok' => $uid !== NULL && $verdict['ok'],
      'uid' => $verdict['ok'] ? $uid : NULL,
      'code' => $verdict['code'],
      'state' => $verdict['state'],
      'prefix' => $verdict['prefix'],
      'message' => $verdict['message'],
      'status' => $uid !== NULL ? $store->status((int) $uid) : NULL,
    ], JSON_UNESCAPED_UNICODE));
  }

  /**
   * Revoke an L2 credential now; the token dies on the next request (I2).
   *
   * @command dx:ecosystem-revoke-credential
   * @option uid Credential owner
   * @option reason Stored on the credential row and in the audit trail
   * @option actor Admin uid performing the revoke (0 = current request user)
   */
  public function revokeCredential(array $options = [
    'uid' => 0,
    'reason' => 'manual_revocation',
    'actor' => 0,
  ]): void {
    $uid = (int) $options['uid'];
    if ($uid <= 0) {
      throw new \RuntimeException('--uid is required');
    }
    $store = $this->credentialStore();
    if ($store->status($uid) === NULL) {
      throw new \RuntimeException(sprintf('uid %d 没有 L2 凭证', $uid));
    }
    $store->revoke($uid, (string) $options['reason'], (int) $options['actor']);
    $this->io()->writeln(json_encode([
      'ok' => FALSE,
      'uid' => $uid,
      'code' => RepositoryRequestAuth::CODE_REVOKED,
      'state' => CredentialLifecycle::STATE_REVOKED,
      'status' => $store->status($uid),
      'lifecycle' => $store->lifecycle($uid),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  }

  /**
   * Show the resolved L2 host plan (I1): driver, urls, header, warnings.
   *
   * @command dx:ecosystem-l2-plan
   * @option uid Render the partner-facing snippets for this uid
   */
  public function l2Plan(array $options = ['uid' => 0]): void {
    $repository = $this->repositoryService();
    $payload = [
      'enabled' => $repository->isEnabled(),
      'verifier' => $repository->verifier()->name(),
      'signing' => $repository->signingSecret() !== '' ? 'set' : 'empty',
      'plan' => $repository->plan(),
      'routes' => [
        'packages' => ComposerHostPlan::SERVE_PREFIX . '/' . SatisMetadataBuilder::ROOT_FILE,
        'provider' => ComposerHostPlan::SERVE_PREFIX . '/providers/{provider}',
        'dist' => ComposerHostPlan::SERVE_PREFIX . '/dist/{artifact}',
        'plan' => ComposerHostPlan::SERVE_PREFIX . '/plan',
      ],
      'client' => [],
    ];
    $uid = (int) $options['uid'];
    if ($uid > 0) {
      $payload['client'] = $repository->clientConfig($uid, 'dxl2_' . str_repeat('0', 48));
    }
    $this->io()->writeln(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
  }

  /**
   * Lint or build the on-disk L2 Composer repository (I1 loopback half).
   *
   * @command dx:ecosystem-l2-repo
   * @option build Absolute directory to materialise the tree into
   * @option src Directory of pre-built zips to import next to the metadata
   * @option lint Only validate the manifest and the declared artifacts
   */
  public function l2Repo(array $options = ['build' => '', 'src' => '', 'lint' => FALSE]): void {
    $builder = $this->builderService();
    if ($options['lint']) {
      $this->io()->writeln(json_encode($builder->lint(NULL), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
      return;
    }
    $dest = trim((string) $options['build']);
    if ($dest === '') {
      $this->io()->writeln(json_encode($this->repositoryService()->report(), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
      return;
    }
    $result = $builder->build($dest, [
      'src' => trim((string) $options['src']),
      'base_url' => (string) $this->repositoryService()->plan()['base_url'],
      'driver' => (string) $this->repositoryService()->plan()['driver'],
    ]);
    $this->io()->writeln(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    if (!$result['ok']) {
      throw new \RuntimeException('L2 仓库构建失败：' . $result['code']);
    }
  }

  /**
   * Credential issue / rotation / usage report (I3) — same data as the admin page.
   *
   * @command dx:ecosystem-credential-report
   * @option window Seconds to look back; 0 means everything
   * @option uid Restrict the report to one developer
   * @option format table|json
   * @option older-than Prune audit events older than this many seconds (0 = keep)
   */
  public function credentialReport(array $options = [
    'window' => CredentialReport::DEFAULT_WINDOW,
    'uid' => 0,
    'format' => 'table',
    'older-than' => 0,
  ]): void {
    if ((int) $options['older-than'] > 0) {
      $pruned = $this->auditService()->prune((int) $options['older-than']);
      $this->io()->writeln(sprintf('pruned %d audit event(s)', $pruned));
    }
    $uid = (int) $options['uid'];
    $data = $this->reportService()->build((int) $options['window'], $uid > 0 ? $uid : NULL);
    if ((string) $options['format'] === 'json') {
      $this->io()->writeln(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
      return;
    }
    $this->io()->writeln(sprintf(
      'credentials=%d active=%d suspended=%d revoked=%d uses=%d rotations=%d denials=%d audit=%s',
      $data['totals']['credentials'],
      $data['totals'][CredentialLifecycle::STATE_ACTIVE] ?? 0,
      $data['totals'][CredentialLifecycle::STATE_SUSPENDED] ?? 0,
      $data['totals'][CredentialLifecycle::STATE_REVOKED] ?? 0,
      $data['totals']['uses'],
      $data['totals']['rotations'],
      $data['totals']['denials'],
      $data['audit_ready'] ? 'ready' : 'table missing (drush updatedb)',
    ));
    $headers = array_map(
      static fn(string $column): string => CredentialReport::headerLabel($column),
      CredentialReport::columns(),
    );
    $formatter = \Drupal::service('date.formatter');
    $rows = [];
    foreach ($data['rows'] as $row) {
      $rows[] = [
        (string) $row['uid'],
        (string) $row['state'],
        (string) $row['prefix'],
        (string) $row['issued_by_name'],
        (string) ($row['issued_ip'] !== '' ? $row['issued_ip'] : '-'),
        $row['created'] > 0 ? $formatter->format((int) $row['created'], 'short') : '-',
        (string) $row['rotations'],
        (string) $row['uses'],
        $row['last_used'] > 0 ? $formatter->format((int) $row['last_used'], 'short') : 'never',
        $row['idle_days'] === NULL ? '-' : (string) $row['idle_days'],
        (string) $row['denials'],
        (string) $row['distinct_ips'],
        $row['revoked_at'] > 0 ? $formatter->format((int) $row['revoked_at'], 'short') : '-',
        (string) ($row['revoke_reason'] !== '' ? $row['revoke_reason'] : '-'),
      ];
    }
    if ($rows === []) {
      $this->io()->writeln('还没有 L2 凭证。');
      return;
    }
    $this->io()->table($headers, $rows);
  }

  /**
   * Run the HTTP token middleware decision without a web server (I1 + I2).
   *
   * @command dx:ecosystem-l2-auth-check
   * @option token dxl2_… token to present
   * @option path Request path to authorise against
   */
  public function l2AuthCheck(array $options = [
    'token' => '',
    'path' => ComposerHostPlan::SERVE_PREFIX . '/' . SatisMetadataBuilder::ROOT_FILE,
  ]): void {
    $request = Request::create((string) $options['path']);
    $request->headers->set('authorization', 'Bearer ' . (string) $options['token']);
    $verdict = \Drupal::service('dx_ecosystem.l2_auth_subscriber')->decide($request);
    $this->io()->writeln(json_encode($verdict + [
      'guarded' => RepositoryRequestAuth::shouldGuard((string) $request->getPathInfo()),
    ], JSON_UNESCAPED_UNICODE));
    if (!$verdict['ok']) {
      throw new \RuntimeException('L2 鉴权失败：' . $verdict['code']);
    }
  }

  protected function repositoryService(): L2ComposerRepository {
    return $this->repository ??= \Drupal::service('dx_ecosystem.l2_repository');
  }

  protected function credentialStore(): PartnerCredentialStore {
    return $this->credentials ??= \Drupal::service('dx_ecosystem.credentials');
  }

  protected function builderService(): L2RepositoryBuilder {
    return $this->builder ??= \Drupal::service('dx_ecosystem.l2_builder');
  }

  protected function reportService(): CredentialReport {
    return $this->report ??= \Drupal::service('dx_ecosystem.credential_report');
  }

  protected function auditService(): CredentialAuditLog {
    return $this->audit ??= \Drupal::service('dx_ecosystem.audit');
  }

}
