<?php

declare(strict_types=1);

namespace Drupal\dx_ai_gateway\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;

/**
 * Stores multi-turn chat history in the visitor private tempstore.
 */
class ChatSession {

  public function __construct(
    protected PrivateTempStoreFactory $tempStoreFactory,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Creates a new session id.
   */
  public function createId(): string {
    return bin2hex(random_bytes(16));
  }

  /**
   * Returns conversation history for a session.
   *
   * @return list<array{role: string, content: string}>
   */
  public function getHistory(string $sessionId): array {
    $sessionId = $this->normalizeId($sessionId);
    if ($sessionId === '') {
      return [];
    }
    $data = $this->store()->get($this->key($sessionId));
    if (!is_array($data) || empty($data['messages']) || !is_array($data['messages'])) {
      return [];
    }
    return array_values(array_filter($data['messages'], static function ($row): bool {
      return is_array($row)
        && isset($row['role'], $row['content'])
        && in_array($row['role'], ['user', 'assistant'], TRUE)
        && is_string($row['content'])
        && $row['content'] !== '';
    }));
  }

  /**
   * Appends a turn and trims to configured max history.
   *
   * @return list<array{role: string, content: string}>
   *   History after append (excluding the newest assistant reply if not yet added).
   */
  public function append(string $sessionId, string $role, string $content): array {
    $sessionId = $this->normalizeId($sessionId);
    if ($sessionId === '' || $content === '') {
      return [];
    }
    if (!in_array($role, ['user', 'assistant'], TRUE)) {
      return $this->getHistory($sessionId);
    }

    $messages = $this->getHistory($sessionId);
    $messages[] = ['role' => $role, 'content' => $content];
    $maxTurns = max(1, (int) ($this->configFactory->get('dx_ai_gateway.settings')->get('max_history_turns') ?: 10));
    // Keep last N * 2 messages (user+assistant pairs).
    $maxMessages = $maxTurns * 2;
    if (count($messages) > $maxMessages) {
      $messages = array_slice($messages, -$maxMessages);
    }
    $this->store()->set($this->key($sessionId), [
      'messages' => $messages,
      'updated' => time(),
    ]);
    return $messages;
  }

  /**
   * Clears a session.
   */
  public function clear(string $sessionId): void {
    $sessionId = $this->normalizeId($sessionId);
    if ($sessionId === '') {
      return;
    }
    $this->store()->delete($this->key($sessionId));
  }

  /**
   * Private tempstore handle.
   */
  protected function store() {
    return $this->tempStoreFactory->get('dx_ai_gateway.chat');
  }

  /**
   * Storage key for a session.
   */
  protected function key(string $sessionId): string {
    return 'session.' . $sessionId;
  }

  /**
   * Normalizes a client-supplied session id.
   */
  protected function normalizeId(string $sessionId): string {
    $sessionId = trim($sessionId);
    if ($sessionId === '' || !preg_match('/^[a-f0-9]{16,64}$/', $sessionId)) {
      return '';
    }
    return $sessionId;
  }

}
