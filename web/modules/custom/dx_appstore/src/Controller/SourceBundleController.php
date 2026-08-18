<?php

declare(strict_types=1);

namespace Drupal\dx_appstore\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\dx_appstore\Entity\License;
use Drupal\dx_appstore\Service\SourceBundleService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * L3 source bundle download and audit.
 */
final class SourceBundleController extends ControllerBase {

  public function __construct(
    protected SourceBundleService $bundles,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('dx_appstore.source_bundle'));
  }

  public function download(License $dx_license): BinaryFileResponse {
    $account = $this->currentUser();
    try {
      $built = $this->bundles->buildZip($dx_license, $account);
    }
    catch (\RuntimeException $e) {
      throw new AccessDeniedHttpException($e->getMessage());
    }
    $response = new BinaryFileResponse($built['path'], 200, [
      'Content-Type' => 'application/zip',
    ]);
    $response->setContentDisposition('attachment', $built['filename']);
    $response->deleteFileAfterSend(TRUE);
    return $response;
  }

  public function myLicenses(): array {
    $storage = $this->entityTypeManager()->getStorage('dx_license');
    $ids = $storage->getQuery()->accessCheck(TRUE)->sort('id', 'DESC')->range(0, 50)->execute();
    $account = $this->currentUser();
    $rows = [];
    foreach ($storage->loadMultiple($ids) as $license) {
      /** @var \Drupal\dx_appstore\Entity\License $license */
      if (!$this->bundles->canDownload($license, $account)) {
        continue;
      }
      $app = $license->get('app_id')->entity;
      $rows[] = [
        $license->id(),
        $app ? $app->label() : '—',
        (string) $license->get('tenant_machine')->value,
        (string) $license->get('agreement_version')->value,
        [
          'data' => [
            '#type' => 'link',
            '#title' => $this->t('Download L3 source'),
            '#url' => Url::fromRoute('dx_appstore.source_download', ['dx_license' => $license->id()]),
          ],
        ],
      ];
    }
    return [
      'intro' => [
        '#markup' => '<p>' . $this->t('Source is for this tenant only. DX-RAL forbids sharing with fourth parties.') . '</p>',
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('License'),
          $this->t('App'),
          $this->t('Tenant'),
          $this->t('DX-RAL'),
          $this->t('Actions'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No downloadable licenses. Install an app and accept DX-RAL first.'),
      ],
    ];
  }

  public function audit(): JsonResponse {
    return new JsonResponse([
      'ok' => TRUE,
      'entries' => $this->bundles->auditLog(100),
    ]);
  }

}
