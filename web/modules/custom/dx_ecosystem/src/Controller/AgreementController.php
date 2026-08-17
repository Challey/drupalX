<?php

declare(strict_types=1);

namespace Drupal\dx_ecosystem\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\dx_ecosystem\Service\AgreementRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Public agreement listing / view.
 */
final class AgreementController extends ControllerBase {

  public function __construct(
    protected AgreementRepository $agreements,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('dx_ecosystem.agreements'));
  }

  /**
   * Lists published agreements.
   */
  public function list(): array {
    $items = [];
    foreach ($this->agreements->manifest() as $id => $meta) {
      $items[] = [
        '#type' => 'link',
        '#title' => ($meta['title'] ?? $id) . ' v' . ($meta['version'] ?? ''),
        '#url' => \Drupal\Core\Url::fromRoute('dx_ecosystem.agreement_view', ['agreement_id' => $id]),
      ];
    }
    return [
      '#theme' => 'item_list',
      '#title' => $this->t('Ecosystem agreements'),
      '#items' => $items,
      '#empty' => $this->t('No agreements found.'),
    ];
  }

  /**
   * Views one agreement body.
   */
  public function view(string $agreement_id): array {
    $doc = $this->agreements->loadBody($agreement_id);
    if ($doc === NULL) {
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
      '#cache' => ['max-age' => 300],
    ];
  }

  /**
   * Title callback.
   */
  public function title(string $agreement_id): string {
    $doc = $this->agreements->loadBody($agreement_id);
    return $doc ? ($doc['title'] . ' v' . $doc['version']) : $agreement_id;
  }

}
