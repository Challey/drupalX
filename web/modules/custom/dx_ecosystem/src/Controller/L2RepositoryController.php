<?php

declare(strict_types=1);

namespace Drupal\dx_ecosystem\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\dx_ecosystem\Service\CredentialAuditLog;
use Drupal\dx_ecosystem\Service\L2ComposerRepository;
use Drupal\dx_ecosystem\Service\Composer\DownloadUrlSigner;
use Drupal\dx_ecosystem\Service\Composer\RepositoryRequestAuth;
use Drupal\dx_ecosystem\Service\Composer\SatisMetadataBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the L2 Composer metadata and signed artifacts (I1).
 *
 * Authentication already happened in L2RepositoryAuthSubscriber by the time we
 * get here; the request carries the verified uid as `._dx_l2_uid`. `_access` on
 * the routes is TRUE because the token — not the Drupal session — is the
 * credential Composer clients present.
 */
final class L2RepositoryController extends ControllerBase {

  public const UID_ATTRIBUTE = '_dx_l2_uid';

  public function __construct(
    protected L2ComposerRepository $repository,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('dx_ecosystem.l2_repository'));
  }

  /**
   * GET /dx/ecosystem/l2/packages.json
   */
  public function root(Request $request): JsonResponse {
    $uid = (int) $request->attributes->get(self::UID_ATTRIBUTE, 0);
    $document = $this->repository->rootMetadata($uid, (string) $request->headers->get('authorization', ''));
    $this->audit($uid, CredentialAuditLog::EVENT_METADATA, SatisMetadataBuilder::ROOT_FILE, RepositoryRequestAuth::CODE_OK, $request);
    return $this->json($document);
  }

  /**
   * GET /dx/ecosystem/l2/providers/%package%.json
   */
  public function provider(Request $request, string $provider): Response {
    $uid = (int) $request->attributes->get(self::UID_ATTRIBUTE, 0);
    $name = SatisMetadataBuilder::nameFromProviderKey($provider);
    $document = $this->repository->providerMetadata($name, $uid);
    if ($document === NULL) {
      return $this->deny(RepositoryRequestAuth::CODE_PATH_FOREIGN, '目录中没有 ' . $name);
    }
    $this->audit($uid, CredentialAuditLog::EVENT_METADATA, 'providers/' . $name, RepositoryRequestAuth::CODE_OK, $request);
    return $this->json($document);
  }

  /**
   * GET /dx/ecosystem/l2/dist/{artifact}?expires&signature&bundle
   */
  public function dist(Request $request, string $artifact): Response {
    $uid = (int) $request->attributes->get(self::UID_ATTRIBUTE, 0);
    $query = $request->query->all();
    $result = $this->repository->resolveDownload($artifact, $query, $uid);
    if (!$result['ok']) {
      $this->audit($uid, CredentialAuditLog::EVENT_DENIED, $artifact, $result['code'], $request);
      return $this->deny($result['code'], RepositoryRequestAuth::messageFor($result['code']));
    }
    $this->audit($uid, CredentialAuditLog::EVENT_DOWNLOAD, $artifact, DownloadUrlSigner::CODE_OK, $request);
    $response = new BinaryFileResponse($result['path'], 200, [
      'Content-Type' => 'application/zip',
      'Content-Length' => (string) $result['size'],
      'Cache-Control' => 'private, no-store',
      'X-DrupalX-Layer' => 'L2',
    ]);
    $response->prepare($request);
    return $response;
  }

  /**
   * GET /dx/ecosystem/l2/plan — what a partner's composer.json should point at.
   */
  public function plan(Request $request): JsonResponse {
    $uid = (int) $request->attributes->get(self::UID_ATTRIBUTE, 0);
    return $this->json($this->repository->report() + ['uid' => $uid]);
  }

  protected function json(array $document): JsonResponse {
    return new JsonResponse($document, Response::HTTP_OK, [
      'Cache-Control' => 'no-store',
      'X-DrupalX-Layer' => 'L2',
    ], TRUE);
  }

  protected function deny(string $code, string $message): Response {
    return new JsonResponse([
      'ok' => FALSE,
      'code' => $code,
      'message' => $message,
    ], Response::HTTP_FORBIDDEN, RepositoryRequestAuth::challenge($code) + [
      'X-DrupalX-Error-Message' => rawurlencode($message),
    ], TRUE);
  }

  /**
   * Counts the hit and mirrors it into the audit table (I3).
   */
  protected function audit(int $uid, string $event, string $path, string $code, Request $request): void {
    $this->repository->track($uid, $event, $code, [
      'ip' => (string) $request->getClientIp(),
      'user_agent' => (string) $request->headers->get('user-agent', ''),
      'path' => substr($path, 0, 255),
    ]);
  }

}
