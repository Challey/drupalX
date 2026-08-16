<?php

declare(strict_types=1);

namespace Drupal\dx_channel\Service;

use Drupal\Core\State\StateInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Bearer token authentication for DXEP Channel (D10-B: always required).
 */
final class ChannelAuth {

  public const STATE_KEY = 'dx_channel.tokens';

  public function __construct(
    protected StateInterface $state,
  ) {}

  /**
   * Authenticate request; return token record or NULL.
   *
   * @return array{id: string, scopes: string[]}|null
   */
  public function authenticate(Request $request): ?array {
    $header = (string) $request->headers->get('Authorization', '');
    if (!preg_match('/^Bearer\s+(\S+)$/i', $header, $matches)) {
      return NULL;
    }
    $plain = $matches[1];
    foreach ($this->listTokens() as $token) {
      if (!empty($token['hash']) && hash_equals((string) $token['hash'], $this->hash($plain))) {
        return [
          'id' => (string) $token['id'],
          'scopes' => array_values(array_map('strval', $token['scopes'] ?? [])),
        ];
      }
    }
    return NULL;
  }

  /**
   * Whether token has a scope.
   *
   * @param array{id: string, scopes: string[]} $token
   */
  public function hasScope(array $token, string $scope): bool {
    return in_array($scope, $token['scopes'], TRUE);
  }

  /**
   * Create a token; returns plaintext once.
   *
   * @param string[] $scopes
   *
   * @return array{id: string, token: string, scopes: string[]}
   */
  public function createToken(string $id, array $scopes = ['channel:read']): array {
    $plain = 'dxc_' . bin2hex(random_bytes(24));
    $tokens = $this->listTokens();
    // Replace same id.
    $tokens = array_values(array_filter($tokens, static fn(array $t): bool => ($t['id'] ?? '') !== $id));
    $tokens[] = [
      'id' => $id,
      'hash' => $this->hash($plain),
      'scopes' => array_values($scopes),
      'created' => time(),
    ];
    $this->state->set(self::STATE_KEY, $tokens);
    return [
      'id' => $id,
      'token' => $plain,
      'scopes' => array_values($scopes),
    ];
  }

  /**
   * Revoke by id.
   */
  public function revokeToken(string $id): bool {
    $before = $this->listTokens();
    $after = array_values(array_filter($before, static fn(array $t): bool => ($t['id'] ?? '') !== $id));
    $this->state->set(self::STATE_KEY, $after);
    return count($after) < count($before);
  }

  /**
   * @return list<array<string, mixed>>
   */
  public function listTokens(): array {
    $tokens = $this->state->get(self::STATE_KEY, []);
    return is_array($tokens) ? array_values($tokens) : [];
  }

  /**
   * Ensure at least one default token exists; return plaintext if newly created.
   */
  public function ensureDefaultToken(): ?string {
    foreach ($this->listTokens() as $token) {
      if (($token['id'] ?? '') === 'default') {
        return NULL;
      }
    }
    $created = $this->createToken('default', ['channel:read']);
    return $created['token'];
  }

  protected function hash(string $plain): string {
    return hash('sha256', $plain);
  }

}
