<?php

declare(strict_types=1);

namespace Drupal\dx_ecosystem\EventSubscriber;

use Drupal\dx_ecosystem\Controller\L2RepositoryController;
use Drupal\dx_ecosystem\Service\L2ComposerRepository;
use Drupal\dx_ecosystem\Service\Composer\DownloadUrlSigner;
use Drupal\dx_ecosystem\Service\Composer\RepositoryRequestAuth;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Token middleware for the /dx/ecosystem/l2 surface (I1 + I2).
 *
 * Two credential flows meet here:
 *  - metadata (`packages.json`, providers, plan): Composer sends the `dxl2_`
 *    token as Basic/Bearer/custom header → checked through the pluggable
 *    L2TokenVerifierInterface, which also re-reads the developer certification,
 *    so revoking certification kills the token on the very next request;
 *  - artifacts (`dist/...`): authorised by the short-lived HMAC signature the
 *    metadata carried, so a big zip download does not need a second credential.
 *
 * A rejected request gets 401 + WWW-Authenticate, which is what makes `composer
 * install` print "Could not fetch ... bad credentials" instead of a confusing
 * HTML parse error.
 */
final class L2RepositoryAuthSubscriber implements EventSubscriberInterface {

  public function __construct(
    protected L2ComposerRepository $repository,
    protected \Psr\Log\LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::REQUEST => [['authenticate', 64]]];
  }

  /**
   * Authenticates a request inside the guarded prefix, or answers 401.
   */
  public function authenticate(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    $request = $event->getRequest();
    if (!RepositoryRequestAuth::shouldGuard((string) $request->getPathInfo())) {
      return;
    }
    $verdict = $this->decide($request);
    if ($verdict['ok']) {
      $request->attributes->set(L2RepositoryController::UID_ATTRIBUTE, (int) $verdict['uid']);
      return;
    }
    $event->setResponse($this->unauthorized((string) $verdict['code'], (string) $verdict['message']));
  }

  /**
   * The single decision path, reused by the Drush dry-run command.
   *
   * @return array{ok: bool, uid: int, code: string, message: string}
   */
  public function decide(Request $request): array {
    $path = RepositoryRequestAuth::classifyPath((string) $request->getPathInfo());
    $headers = [];
    foreach ($request->headers->all() as $name => $values) {
      $headers[strtolower((string) $name)] = is_array($values) ? (string) ($values[0] ?? '') : (string) $values;
    }
    $query = array_map(static fn($v): string => is_array($v) ? (string) ($v[0] ?? '') : (string) $v, $request->query->all());

    // Signed artifact links carry their own authority.
    if ($path['kind'] === 'dist' && isset($query[DownloadUrlSigner::QUERY_SIGNATURE])) {
      $bundle = (string) ($query[DownloadUrlSigner::QUERY_BUNDLE] ?? '');
      $signature = $this->repository->resolveDownload(
        $path['dist'],
        $query,
        $bundle === 'anon' ? 0 : (int) $bundle,
      );
      if (!$signature['ok']) {
        return [
          'ok' => FALSE,
          'uid' => 0,
          'code' => $signature['code'],
          'message' => RepositoryRequestAuth::messageFor($signature['code']),
        ];
      }
      return ['ok' => TRUE, 'uid' => (int) $bundle, 'code' => DownloadUrlSigner::CODE_OK, 'message' => ''];
    }

    $extract = RepositoryRequestAuth::extract($headers, $query, TRUE);
    if ($extract['code'] !== RepositoryRequestAuth::CODE_OK) {
      return [
        'ok' => FALSE,
        'uid' => 0,
        'code' => $extract['code'],
        'message' => RepositoryRequestAuth::messageFor($extract['code']),
      ];
    }
    $result = $this->repository->authorize($extract['token'], [
      'ip' => (string) $request->getClientIp(),
      'user_agent' => (string) $request->headers->get('user-agent', ''),
      'path' => $path['kind'] . ':' . $extract['source'],
    ]);
    $match = RepositoryRequestAuth::uidMatches($extract, $result['uid']);
    if (!$result['ok'] || !$match['ok']) {
      $code = $result['ok'] ? (string) $match['code'] : (string) $result['code'];
      return [
        'ok' => FALSE,
        'uid' => 0,
        'code' => $code,
        'message' => RepositoryRequestAuth::messageFor($code),
      ];
    }
    return ['ok' => TRUE, 'uid' => (int) $result['uid'], 'code' => RepositoryRequestAuth::CODE_OK, 'message' => ''];
  }

  /**
   * @return array<string, string>
   */
  public function contextFor(Request $request): array {
    return [
      'ip' => (string) $request->getClientIp(),
      'user_agent' => (string) $request->headers->get('user-agent', ''),
      'actor_uid' => 0,
    ];
  }

  protected function unauthorized(string $code, string $message): Response {
    $this->logger->warning('L2 仓库访问被拒（@code）：@message', [
      '@code' => $code,
      '@message' => $message === '' ? $code : $message,
    ]);
    return new Response($message === '' ? $code : $message, Response::HTTP_UNAUTHORIZED, RepositoryRequestAuth::challenge($code));
  }

}
