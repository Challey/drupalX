<?php

declare(strict_types=1);

namespace Drupal\dx_ecosystem\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\dx_ecosystem\Service\DeveloperGate;
use Drupal\dx_ecosystem\Service\PartnerDocRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Partner (L2) documentation — OE2 gated.
 */
final class PartnerDocsController extends ControllerBase {

  public function __construct(
    protected PartnerDocRepository $docs,
    protected DeveloperGate $gate,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('dx_ecosystem.partner_docs'),
      $container->get('dx_ecosystem.gate'),
    );
  }

  /**
   * Lists partner docs when vault access is allowed.
   */
  public function list(): array {
    if (!$this->gate->canAccessPartnerVault($this->currentUser())) {
      throw new AccessDeniedHttpException();
    }
    $items = [];
    foreach ($this->docs->manifest() as $id => $meta) {
      $items[] = Link::fromTextAndUrl(
        ($meta['title'] ?? $id) . ' v' . ($meta['version'] ?? ''),
        Url::fromRoute('dx_ecosystem.partner_doc', ['doc_id' => $id]),
      );
    }
    $explain = $this->gate->explain($this->currentUser());
    return [
      'banner' => [
        '#markup' => '<p><em>' . $this->t('Partner vault (L2). Status: @status · @reason', [
          '@status' => $explain['status'],
          '@reason' => $explain['reason'],
        ]) . '</em></p>',
      ],
      'list' => [
        '#theme' => 'item_list',
        '#title' => $this->t('Partner documentation'),
        '#items' => $items,
        '#empty' => $this->t('No partner docs published.'),
      ],
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['dx_ecosystem:certs', 'dx_ecosystem:acks'],
        'max-age' => 0,
      ],
    ];
  }

  /**
   * Views one partner doc.
   */
  public function view(string $doc_id): array {
    if (!$this->gate->canAccessPartnerVault($this->currentUser())) {
      throw new AccessDeniedHttpException();
    }
    $doc = $this->docs->load($doc_id);
    if ($doc === NULL || ($doc['visibility'] ?? '') !== 'partner') {
      throw new NotFoundHttpException();
    }
    return [
      '#type' => 'container',
      'title' => [
        '#type' => 'html_tag',
        '#tag' => 'h1',
        '#value' => $doc['title'] . ' v' . $doc['version'],
      ],
      'body' => [
        '#type' => 'html_tag',
        '#tag' => 'pre',
        '#value' => $doc['body'],
        '#attributes' => ['style' => 'white-space:pre-wrap;'],
      ],
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['dx_ecosystem:certs', 'dx_ecosystem:acks'],
        'max-age' => 0,
      ],
    ];
  }

  /**
   * Title callback.
   */
  public function title(string $doc_id): string {
    $doc = $this->docs->load($doc_id);
    return $doc ? ($doc['title'] . ' v' . $doc['version']) : $doc_id;
  }

}
