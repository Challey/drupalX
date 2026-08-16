<?php

declare(strict_types=1);

namespace Drupal\dx_channel\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\dx_channel\Service\AppLayoutRepository;
use Drupal\dx_channel\Service\ChannelAuth;
use Drush\Commands\DrushCommands;

/**
 * Drush helpers for DXEP Channel.
 */
final class ChannelCommands extends DrushCommands {

  public function __construct(
    protected ChannelAuth $auth,
    protected AppLayoutRepository $appLayout,
    protected ConfigFactoryInterface $configFactory,
  ) {
    parent::__construct();
  }

  /**
   * Create a Channel Bearer token (plaintext printed once).
   *
   * @command dx:channel-token-create
   * @option id Token id
   * @option scopes Comma-separated scopes
   * @usage dx:channel-token-create
   * @usage dx:channel-token-create --id=flutter --scopes=channel:read
   */
  public function tokenCreate(array $options = ['id' => 'default', 'scopes' => 'channel:read']): void {
    $id = (string) ($options['id'] ?: 'default');
    $scopes = array_values(array_filter(array_map('trim', explode(',', (string) $options['scopes']))));
    if ($scopes === []) {
      $scopes = ['channel:read'];
    }
    $created = $this->auth->createToken($id, $scopes);
    $this->logger()->success(dt('Token id=@id scopes=@scopes', [
      '@id' => $created['id'],
      '@scopes' => implode(',', $created['scopes']),
    ]));
    $this->io()->writeln('Bearer token (store securely, shown once):');
    $this->io()->writeln($created['token']);
  }

  /**
   * List Channel token ids (no secrets).
   *
   * @command dx:channel-token-list
   */
  public function tokenList(): void {
    $tokens = $this->auth->listTokens();
    if ($tokens === []) {
      $this->io()->warning('No tokens. Run dx:channel-token-create');
      return;
    }
    foreach ($tokens as $token) {
      $this->io()->writeln(sprintf(
        '%s  scopes=%s  created=%s',
        $token['id'] ?? '?',
        implode(',', $token['scopes'] ?? []),
        !empty($token['created']) ? date('c', (int) $token['created']) : '—',
      ));
    }
  }

  /**
   * Revoke a Channel token by id.
   *
   * @command dx:channel-token-revoke
   * @argument id Token id
   */
  public function tokenRevoke(string $id): void {
    if ($this->auth->revokeToken($id)) {
      $this->logger()->success(dt('Revoked @id', ['@id' => $id]));
      return;
    }
    $this->logger()->warning(dt('Token @id not found', ['@id' => $id]));
  }

  /**
   * Show app-layout revision and profile.
   *
   * @command dx:channel-layout-status
   */
  public function layoutStatus(): void {
    $config = $this->configFactory->get('dx_channel.settings');
    $layout = $this->appLayout->getLayout();
    $this->io()->writeln('profile: ' . ($config->get('layout_profile') ?: 'gov_default'));
    $this->io()->writeln('revision: ' . $this->appLayout->getRevision());
    $this->io()->writeln('checksum: ' . ($layout['checksum'] ?? ''));
    $this->io()->writeln('min_shell_version: ' . ($layout['min_shell_version'] ?? ''));
    $this->io()->writeln('pages: ' . implode(',', array_keys($layout['pages'] ?? [])));
  }

  /**
   * Bump layout revision (force shell refresh).
   *
   * @command dx:channel-layout-bump
   */
  public function layoutBump(): void {
    $rev = $this->appLayout->bumpRevision();
    $this->logger()->success(dt('Layout revision is now @r', ['@r' => $rev]));
  }

}
