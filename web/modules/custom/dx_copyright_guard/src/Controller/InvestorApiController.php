<?php

declare(strict_types=1);

namespace Drupal\dx_copyright_guard\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\dx_copyright_guard\Service\AcceptanceStorage;
use Drupal\dx_copyright_guard\Service\InvestorAccess;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * JSON endpoints used by the app/mini program admin entry.
 */
final class InvestorApiController implements ContainerInjectionInterface {

  public function __construct(
    private readonly InvestorAccess $investorAccess,
    private readonly AcceptanceStorage $acceptance,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('dx_copyright_guard.investor_access'),
      $container->get('dx_copyright_guard.acceptance'),
      $container->get('config.factory'),
      $container->get('current_user'),
    );
  }

  /**
   * Current user's access state.
   */
  public function status(Request $request): JsonResponse {
    $uid = $this->uid($request);
    if ($uid <= 0) {
      return $this->error('login_required', 401);
    }
    $user = User::load($uid);
    if ($user === NULL) {
      return $this->error('user_not_found', 404);
    }
    return $this->ok([
      'access' => $this->investorAccess->hasAccess($user),
      'admin' => $this->investorAccess->isAdmin($user),
      'can_share' => $this->investorAccess->canShare($user),
      'request' => $this->investorAccess->status($uid),
    ]);
  }

  /**
   * Personal share link for readers (admins included).
   */
  public function share(Request $request): JsonResponse {
    $uid = $this->uid($request);
    $user = $uid > 0 ? User::load($uid) : NULL;
    if ($user === NULL) {
      return $this->error('login_required', 401);
    }
    if (!$this->investorAccess->canShare($user)) {
      return $this->error('forbidden', 403);
    }
    $invite = $this->investorAccess->shareInvite($uid);
    if ($invite === NULL) {
      return $this->error('share_unavailable', 500);
    }
    return $this->ok([
      'code' => (string) $invite->code,
      'link' => $this->investorAccess->inviteUrl((string) $invite->code),
      'uses' => (int) $invite->uses,
      'max_uses' => (int) $invite->max_uses,
      'created_at' => (int) $invite->created_at,
      'owner' => $user->getDisplayName(),
      'norms' => [
        '仅分享给确有阅读需求的合作方或投资人，请勿公开发布或转贴到公开渠道。',
        '对方打开邀请链接即可阅读；页面会展示完整保密协议与专属水印。',
        '请勿截图、录屏或二次转发受保护内容；发现泄露请立即通知管理员。',
        '管理员可随时停用分享码并撤销访问权限。',
      ],
    ]);
  }

  /**
   * Submits an access request.
   */
  public function requestAccess(Request $request): JsonResponse {
    $uid = $this->uid($request);
    if ($uid <= 0) {
      return $this->error('login_required', 401);
    }
    $body = $this->body($request);
    $this->investorAccess->request($uid, (string) ($body['message'] ?? ''));
    return $this->ok(['request' => $this->investorAccess->status($uid)]);
  }

  /**
   * Redeems an invite code (or returns the agreement URL when unsigned).
   */
  public function redeem(Request $request): JsonResponse {
    $uid = $this->uid($request);
    if ($uid <= 0) {
      return $this->error('login_required', 401);
    }
    $body = $this->body($request);
    $code = trim((string) ($body['code'] ?? ''));
    if ($this->investorAccess->findValidInvite($code) === NULL) {
      return $this->error('invalid_invite', 400);
    }
    if (!$this->acceptance->hasAccepted($uid)) {
      $destination = (string) ($this->configFactory->get('dx_copyright_guard.settings')->get('invite_landing_path') ?? '/investor/');
      return $this->ok([
        'access' => FALSE,
        'needs_agreement' => TRUE,
        'agreement_url' => Url::fromRoute('dx_copyright_guard.agreement', [], [
          'absolute' => TRUE,
          'query' => [
            'destination' => $destination,
            'invite' => InvestorAccess::normalizeCode($code),
            'embed' => '1',
          ],
        ])->toString(),
      ]);
    }
    $result = $this->investorAccess->redeemInvite($uid, $code);
    return $this->ok([
      'access' => $result['ok'],
      'needs_agreement' => FALSE,
      'message' => $result['message'],
    ]);
  }

  /**
   * Review console data (admin only).
   */
  public function admin(Request $request): JsonResponse {
    $uid = $this->uid($request);
    $user = $uid > 0 ? User::load($uid) : NULL;
    if ($user === NULL) {
      return $this->error('login_required', 401);
    }
    if (!$this->investorAccess->isAdmin($user)) {
      return $this->error('forbidden', 403);
    }
    $this->investorAccess->ensureInvites(
      (int) ($this->configFactory->get('dx_copyright_guard.settings')->get('invite_auto_count') ?? 3),
      $uid,
    );
    return $this->ok([
      'requests' => array_map([$this, 'mapRequest'], $this->investorAccess->pending()),
      'recent' => array_map([$this, 'mapRequest'], $this->investorAccess->recent(30)),
      'invites' => array_map([$this, 'mapInvite'], $this->investorAccess->invites()),
      'landing' => (string) ($this->configFactory->get('dx_copyright_guard.settings')->get('invite_landing_path') ?? '/investor/'),
    ]);
  }

  /**
   * Approves/denies a pending request (admin only).
   */
  public function review(Request $request): JsonResponse {
    $uid = $this->uid($request);
    $user = $uid > 0 ? User::load($uid) : NULL;
    if ($user === NULL) {
      return $this->error('login_required', 401);
    }
    if (!$this->investorAccess->isAdmin($user)) {
      return $this->error('forbidden', 403);
    }
    $body = $this->body($request);
    $id = (int) ($body['id'] ?? 0);
    $action = (string) ($body['action'] ?? '');
    if ($id <= 0 || !in_array($action, ['approve', 'deny'], TRUE)) {
      return $this->error('invalid_request', 400);
    }
    if (!$this->investorAccess->review($id, $action, $uid, (string) ($body['note'] ?? ''))) {
      return $this->error('request_not_pending', 409);
    }
    return $this->ok(['id' => $id, 'action' => $action]);
  }

  /**
   * Creates invite codes (admin only).
   */
  public function createInvites(Request $request): JsonResponse {
    $uid = $this->uid($request);
    $user = $uid > 0 ? User::load($uid) : NULL;
    if ($user === NULL) {
      return $this->error('login_required', 401);
    }
    if (!$this->investorAccess->isAdmin($user)) {
      return $this->error('forbidden', 403);
    }
    $body = $this->body($request);
    $created = $this->investorAccess->createInvites(
      (int) ($body['count'] ?? 3),
      (string) ($body['label'] ?? 'App 生成'),
      $uid,
    );
    return $this->ok([
      'invites' => array_map([$this, 'mapInvite'], $created),
    ]);
  }

  /**
   * Enables/disables an invite code (admin only).
   */
  public function toggleInvite(Request $request): JsonResponse {
    $uid = $this->uid($request);
    $user = $uid > 0 ? User::load($uid) : NULL;
    if ($user === NULL) {
      return $this->error('login_required', 401);
    }
    if (!$this->investorAccess->isAdmin($user)) {
      return $this->error('forbidden', 403);
    }
    $body = $this->body($request);
    $id = (int) ($body['id'] ?? 0);
    if ($id <= 0) {
      return $this->error('invalid_request', 400);
    }
    $this->investorAccess->setInviteActive($id, (bool) ($body['active'] ?? FALSE));
    return $this->ok(['id' => $id]);
  }

  /**
   * Resolves the acting uid from the Drupal session or a bearer token.
   */
  private function uid(Request $request): int {
    $uid = (int) $this->currentUser->id();
    if ($uid > 0) {
      return $uid;
    }
    $authorization = (string) ($request->headers->get('Authorization') ?? '');
    if ($authorization !== '' && \Drupal::hasService('tpst_pi_app.auth_token')) {
      try {
        return (int) (\Drupal::service('tpst_pi_app.auth_token')->uidFromBearer($authorization) ?? 0);
      }
      catch (\Throwable) {
        return 0;
      }
    }
    return 0;
  }

  /**
   * Parses a JSON body.
   *
   * @return array<string, mixed>
   *   Request payload.
   */
  private function body(Request $request): array {
    $decoded = json_decode((string) $request->getContent(), TRUE);
    return is_array($decoded) ? $decoded : [];
  }

  /**
   * Maps a request row for JSON.
   */
  private function mapRequest(object $row): array {
    return [
      'id' => (int) $row->id,
      'uid' => (int) $row->uid,
      'name' => (string) ($row->name ?? ''),
      'mail' => (string) ($row->mail ?? ''),
      'status' => (string) $row->status,
      'message' => (string) ($row->message ?? ''),
      'source' => (string) ($row->source ?? ''),
      'invite_code' => (string) ($row->invite_code ?? ''),
      'invited_by' => (int) ($row->invited_by ?? 0),
      'requested_at' => (int) $row->requested_at,
      'reviewed_at' => (int) $row->reviewed_at,
      'note' => (string) ($row->note ?? ''),
    ];
  }

  /**
   * Maps an invite row for JSON.
   */
  private function mapInvite(object $row): array {
    return [
      'id' => (int) $row->id,
      'code' => (string) $row->code,
      'label' => (string) ($row->label ?? ''),
      'active' => (bool) $row->active,
      'uses' => (int) $row->uses,
      'max_uses' => (int) $row->max_uses,
      'created_at' => (int) $row->created_at,
      'expires_at' => (int) $row->expires_at,
      'owner_uid' => (int) ($row->owner_uid ?? 0),
      'owner_name' => (string) ($row->owner_name ?? ''),
      'url' => $this->investorAccess->inviteUrl((string) $row->code),
    ];
  }

  /**
   * Success envelope.
   *
   * @param array<string, mixed> $data
   *   Payload.
   */
  private function ok(array $data): JsonResponse {
    return new JsonResponse(['ok' => TRUE, 'data' => $data], 200, [
      'Cache-Control' => 'no-store, no-cache, must-revalidate',
    ]);
  }

  /**
   * Error envelope.
   */
  private function error(string $message, int $status): JsonResponse {
    return new JsonResponse(['ok' => FALSE, 'error' => $message], $status, [
      'Cache-Control' => 'no-store, no-cache, must-revalidate',
    ]);
  }

}
