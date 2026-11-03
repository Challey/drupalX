<?php

declare(strict_types=1);

namespace Drupal\dx_copyright_guard\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Psr\Log\LoggerInterface;

/**
 * Investor role, access requests and invite codes.
 *
 * A user may read protected reports when they hold the investor role (or the
 * `view dx copyright protected content` permission). Administrators can grant
 * access from the web console, the app or the mini program, and invite links
 * grant access as soon as the recipient signs the agreement.
 */
final class InvestorAccess {

  public const ROLE = 'investor';
  public const PERMISSION_VIEW = 'view dx copyright protected content';
  public const PERMISSION_ADMIN = 'administer dx copyright guard';

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Creates the investor role when it is missing and keeps its permission.
   */
  public function ensureRole(): void {
    $storage = $this->entityTypeManager->getStorage('user_role');
    $role = Role::load(self::ROLE);
    if ($role === NULL) {
      Role::create([
        'id' => self::ROLE,
        'label' => '投资者',
        'permissions' => [self::PERMISSION_VIEW, 'access content'],
      ])->save();
      $storage->resetCache();
      return;
    }
    $permissions = $role->getPermissions();
    if (!in_array(self::PERMISSION_VIEW, $permissions, TRUE)) {
      $permissions[] = self::PERMISSION_VIEW;
      $role->setPermissions($permissions)->save();
    }
  }

  /**
   * Whether the account may read protected content.
   */
  public function hasAccess(AccountInterface $account): bool {
    if ((int) $account->id() <= 0) {
      return FALSE;
    }
    // Guard administrators (including senior admins who manage access but do
    // not carry the investor role) can read and share the documents they
    // administer. They still have to accept the confidentiality agreement.
    return $account->hasPermission(self::PERMISSION_VIEW)
      || $account->hasRole(self::ROLE)
      || $this->isAdmin($account);
  }

  /**
   * Whether the account may review investor requests.
   *
   * The `administrator` role is granted every permission by Drupal, so the
   * explicit role check keeps the review entry visible even on sites where the
   * module permission has not been rebuilt yet.
   */
  public function isAdmin(AccountInterface $account): bool {
    if ((int) $account->id() <= 0) {
      return FALSE;
    }
    return (int) $account->id() === 1
      || $account->hasRole('administrator')
      || $account->hasPermission(self::PERMISSION_ADMIN)
      || $account->hasPermission('administer users')
      || $account->hasPermission('administer tpst pi app');
  }

  /**
   * Adds the investor role and marks the access request approved.
   *
   * @param int $invitedBy
   *   Referrer uid when access came from a personal share link.
   */
  public function grant(int $uid, string $source = 'admin', string $code = '', int $reviewer = 0, string $note = '', int $invitedBy = 0): bool {
    if ($uid <= 0) {
      return FALSE;
    }
    $this->ensureRole();
    $user = User::load($uid);
    if ($user === NULL) {
      return FALSE;
    }
    if (!$user->hasRole(self::ROLE)) {
      $user->addRole(self::ROLE);
      $user->save();
    }
    $this->markRequest($uid, 'approved', $source, $code, $reviewer, $note, $invitedBy);
    $this->logger->notice('Investor access granted: uid @uid via @source @code (referrer @by)', [
      '@uid' => $uid,
      '@source' => $source,
      '@code' => $code,
      '@by' => $invitedBy,
    ]);
    return TRUE;
  }

  /**
   * Removes the investor role.
   */
  public function revoke(int $uid, int $reviewer = 0, string $note = ''): bool {
    $user = User::load($uid);
    if ($user === NULL) {
      return FALSE;
    }
    if ($user->hasRole(self::ROLE)) {
      $user->removeRole(self::ROLE);
      $user->save();
    }
    $this->markRequest($uid, 'revoked', 'admin', '', $reviewer, $note);
    $this->logger->notice('Investor access revoked: uid @uid by @reviewer', [
      '@uid' => $uid,
      '@reviewer' => $reviewer,
    ]);
    return TRUE;
  }

  /**
   * Records (or re-opens) an access request for a user.
   */
  public function request(int $uid, string $message = ''): void {
    if ($uid <= 0 || $this->hasAccess(User::load($uid) ?? User::getAnonymousUser())) {
      return;
    }
    $now = $this->time->getRequestTime();
    $existing = $this->requestRow($uid);
    if ($existing !== NULL && $existing->status === 'pending') {
      $this->database->update('dx_copyright_access_request')
        ->fields([
          'message' => substr($message, 0, 1000),
          'requested_at' => $now,
        ])
        ->condition('uid', $uid)
        ->execute();
      return;
    }
    $this->database->merge('dx_copyright_access_request')
      ->keys(['uid' => $uid])
      ->fields([
        'status' => 'pending',
        'message' => substr($message, 0, 1000),
        'source' => 'request',
        'invite_code' => '',
        'invited_by' => 0,
        'requested_at' => $now,
        'reviewed_at' => 0,
        'reviewed_by' => 0,
        'note' => '',
      ])
      ->execute();
  }

  /**
   * Returns the current access state of a user.
   *
   * @return array{status:string,message:string,source:string,invite_code:string,invited_by:int,requested_at:int,reviewed_at:int,note:string}
   *   Request state, or `none`.
   */
  public function status(int $uid): array {
    $row = $this->requestRow($uid);
    return [
      'status' => $row?->status ?? 'none',
      'message' => (string) ($row?->message ?? ''),
      'source' => (string) ($row?->source ?? ''),
      'invite_code' => (string) ($row?->invite_code ?? ''),
      'invited_by' => (int) ($row?->invited_by ?? 0),
      'requested_at' => (int) ($row?->requested_at ?? 0),
      'reviewed_at' => (int) ($row?->reviewed_at ?? 0),
      'note' => (string) ($row?->note ?? ''),
    ];
  }

  /**
   * Pending requests, oldest first.
   *
   * @return array<int, object>
   *   Rows with `name` and `mail` from the user table.
   */
  public function pending(int $limit = 200): array {
    $query = $this->database->select('dx_copyright_access_request', 'r');
    $query->join('users_field_data', 'u', 'u.uid = r.uid');
    $query->fields('r')->fields('u', ['name', 'mail']);
    $query->condition('r.status', 'pending');
    $query->orderBy('r.requested_at', 'ASC');
    $query->range(0, $limit);
    return $query->execute()->fetchAll();
  }

  /**
   * Recently reviewed requests, newest first.
   *
   * @return array<int, object>
   *   Rows with `name` and `mail` from the user table.
   */
  public function recent(int $limit = 50): array {
    $query = $this->database->select('dx_copyright_access_request', 'r');
    $query->join('users_field_data', 'u', 'u.uid = r.uid');
    $query->fields('r')->fields('u', ['name', 'mail']);
    $query->condition('r.status', 'pending', '<>');
    $query->orderBy('r.reviewed_at', 'DESC');
    $query->range(0, $limit);
    return $query->execute()->fetchAll();
  }

  /**
   * Approves or denies a pending request.
   */
  public function review(int $requestId, string $action, int $reviewer, string $note = ''): bool {
    $row = $this->database->select('dx_copyright_access_request', 'r')
      ->fields('r')
      ->condition('id', $requestId)
      ->execute()
      ->fetchObject();
    if (!$row || $row->status !== 'pending') {
      return FALSE;
    }
    if ($action === 'approve') {
      return $this->grant((int) $row->uid, 'review', '', $reviewer, $note);
    }
    if ($action === 'deny') {
      $this->markRequest((int) $row->uid, 'denied', 'review', '', $reviewer, $note);
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Creates invite codes.
   *
   * @return array<int, object>
   *   The created invite rows.
   */
  public function createInvites(int $count, string $label, int $createdBy, int $maxUses = 0, int $expiresAt = 0, int $ownerUid = 0): array {
    $count = max(1, min(50, $count));
    $now = $this->time->getRequestTime();
    $created = [];
    for ($i = 0; $i < $count; $i++) {
      $code = $this->generateCode();
      $id = $this->database->insert('dx_copyright_invite')
        ->fields([
          'code' => $code,
          'label' => substr($label, 0, 128),
          'active' => 1,
          'max_uses' => max(0, $maxUses),
          'uses' => 0,
          'created_at' => $now,
          'created_by' => $createdBy,
          'owner_uid' => max(0, $ownerUid),
          'expires_at' => max(0, $expiresAt),
          'last_used_at' => 0,
        ])
        ->execute();
      $created[] = (object) [
        'id' => (int) $id,
        'code' => $code,
        'label' => $label,
        'active' => 1,
        'max_uses' => max(0, $maxUses),
        'uses' => 0,
        'created_at' => $now,
        'created_by' => $createdBy,
        'owner_uid' => max(0, $ownerUid),
        'expires_at' => max(0, $expiresAt),
        'last_used_at' => 0,
      ];
    }
    return $created;
  }

  /**
   * Keeps at least a few usable invite codes around.
   *
   * @return int
   *   Number of codes created.
   */
  public function ensureInvites(int $minimum = 3, int $createdBy = 0): int {
    $minimum = max(0, min(20, $minimum));
    if ($minimum === 0) {
      return 0;
    }
    $now = $this->time->getRequestTime();
    $active = (int) $this->database->select('dx_copyright_invite', 'i')
      ->condition('active', 1)
      ->condition('owner_uid', 0)
      ->where('(expires_at = 0 OR expires_at > :now)', [':now' => $now])
      ->where('(max_uses = 0 OR uses < max_uses)')
      ->countQuery()
      ->execute()
      ->fetchField();
    if ($active >= $minimum) {
      return 0;
    }
    return count($this->createInvites($minimum - $active, '自动生成', $createdBy));
  }

  /**
   * Whether the account may hand out its personal share link.
   *
   * Readers (investor role/permission) and guard administrators qualify.
   */
  public function canShare(AccountInterface $account): bool {
    return $this->hasAccess($account);
  }

  /**
   * Returns the account's personal, unlimited invite code, creating one once.
   */
  public function shareInvite(int $uid): ?object {
    if ($uid <= 0) {
      return NULL;
    }
    $now = $this->time->getRequestTime();
    $existing = $this->database->select('dx_copyright_invite', 'i')
      ->fields('i')
      ->condition('owner_uid', $uid)
      ->condition('active', 1)
      ->where('(expires_at = 0 OR expires_at > :now)', [':now' => $now])
      ->condition('max_uses', 0)
      ->orderBy('id', 'ASC')
      ->range(0, 1)
      ->execute()
      ->fetchObject();
    if ($existing) {
      return $existing;
    }
    $created = $this->createInvites(1, '个人分享', $uid, 0, 0, $uid);
    return $created[0] ?? NULL;
  }

  /**
   * Personal share link of an account, or NULL when it may not share.
   */
  public function shareUrl(int $uid): ?string {
    $invite = $this->shareInvite($uid);
    return $invite === NULL ? NULL : $this->inviteUrl((string) $invite->code);
  }

  /**
   * Display name of the account behind a personal share code.
   */
  public function shareOwnerName(int $uid): string {
    if ($uid <= 0) {
      return '';
    }
    $user = User::load($uid);
    return $user === NULL ? '' : $user->getDisplayName();
  }

  /**
   * All invite codes, newest first.
   *
   * @return array<int, object>
   *   Invite rows.
   */
  public function invites(int $limit = 200): array {
    $query = $this->database->select('dx_copyright_invite', 'i');
    $query->fields('i');
    $query->leftJoin('users_field_data', 'u', 'u.uid = i.owner_uid');
    $query->addField('u', 'name', 'owner_name');
    $query->orderBy('i.created_at', 'DESC');
    $query->range(0, $limit);
    return $query->execute()->fetchAll();
  }

  /**
   * Enables or disables an invite code.
   */
  public function setInviteActive(int $id, bool $active): bool {
    return (bool) $this->database->update('dx_copyright_invite')
      ->fields(['active' => $active ? 1 : 0])
      ->condition('id', $id)
      ->execute();
  }

  /**
   * Finds a usable invite row, or NULL when invalid/expired/exhausted.
   */
  public function findValidInvite(string $code): ?object {
    $code = self::normalizeCode($code);
    if ($code === '') {
      return NULL;
    }
    $row = $this->database->select('dx_copyright_invite', 'i')
      ->fields('i')
      ->condition('code', $code)
      ->condition('active', 1)
      ->execute()
      ->fetchObject();
    if (!$row) {
      return NULL;
    }
    $now = $this->time->getRequestTime();
    if ((int) $row->expires_at > 0 && (int) $row->expires_at < $now) {
      return NULL;
    }
    if ((int) $row->max_uses > 0 && (int) $row->uses >= (int) $row->max_uses) {
      return NULL;
    }
    return $row;
  }

  /**
   * Grants access through an invite and consumes one use.
   *
   * @return array{ok:bool,message:string}
   *   Result for the UI.
   */
  public function redeemInvite(int $uid, string $code): array {
    if ($this->hasAccess(User::load($uid) ?? User::getAnonymousUser())) {
      return ['ok' => TRUE, 'message' => '您已拥有投资者访问权限。'];
    }
    $invite = $this->findValidInvite($code);
    if ($invite === NULL) {
      return ['ok' => FALSE, 'message' => '邀请码无效、已停用或已过期。'];
    }
    $ownerUid = (int) ($invite->owner_uid ?? 0);
    if (!$this->grant($uid, 'invite', (string) $invite->code, 0, '', $ownerUid)) {
      return ['ok' => FALSE, 'message' => '账号不存在，无法开通访问权限。'];
    }
    $this->database->update('dx_copyright_invite')
      ->expression('uses', 'uses + 1')
      ->fields(['last_used_at' => $this->time->getRequestTime()])
      ->condition('id', $invite->id)
      ->execute();
    return ['ok' => TRUE, 'message' => '邀请码已生效，已加入投资者访问权限。'];
  }

  /**
   * Absolute invite link for a code.
   */
  public function inviteUrl(string $code): string {
    $path = (string) ($this->configFactory->get('dx_copyright_guard.settings')->get('invite_landing_path') ?? '/investor/');
    if ($path === '') {
      $path = '/investor/';
    }
    try {
      return Url::fromUserInput($path, [
        'query' => ['invite' => $code],
        'absolute' => TRUE,
      ])->toString();
    }
    catch (\Exception) {
      return '/' . ltrim($path, '/') . '?invite=' . rawurlencode($code);
    }
  }

  /**
   * Normalizes a typed code (accepts the code without the TPST- prefix).
   */
  public static function normalizeCode(string $code): string {
    $code = strtoupper(preg_replace('/\s+/', '', trim($code)) ?? '');
    if (preg_match('/^[A-Z0-9]{8}$/', $code)) {
      $code = 'TPST-' . substr($code, 0, 4) . '-' . substr($code, 4, 4);
    }
    return $code;
  }

  /**
   * Upserts the request row for a user.
   */
  private function markRequest(int $uid, string $status, string $source, string $code, int $reviewer, string $note, int $invitedBy = 0): void {
    $now = $this->time->getRequestTime();
    $this->database->merge('dx_copyright_access_request')
      ->keys(['uid' => $uid])
      ->fields([
        'status' => $status,
        'source' => substr($source, 0, 32),
        'invite_code' => substr($code, 0, 32),
        'requested_at' => $now,
        'reviewed_at' => $reviewer > 0 ? $now : 0,
        'reviewed_by' => $reviewer,
        'note' => substr($note, 0, 255),
        'invited_by' => max(0, $invitedBy),
      ])
      ->execute();
  }

  /**
   * Loads the request row for a user.
   */
  private function requestRow(int $uid): ?object {
    if ($uid <= 0) {
      return NULL;
    }
    $row = $this->database->select('dx_copyright_access_request', 'r')
      ->fields('r')
      ->condition('uid', $uid)
      ->execute()
      ->fetchObject();
    return $row ?: NULL;
  }

  /**
   * Generates a unique, human-friendly code.
   */
  private function generateCode(): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $max = strlen($alphabet) - 1;
    do {
      $part = static function () use ($alphabet, $max): string {
        $out = '';
        for ($i = 0; $i < 4; $i++) {
          $out .= $alphabet[random_int(0, $max)];
        }
        return $out;
      };
      $code = 'TPST-' . $part() . '-' . $part();
      $exists = (bool) $this->database->select('dx_copyright_invite', 'i')
        ->condition('code', $code)
        ->countQuery()
        ->execute()
        ->fetchField();
    } while ($exists);
    return $code;
  }

}
