<?php

declare(strict_types=1);

namespace Drupal\dx_delivery\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\dx_delivery\Entity\Blueprint;
use Drupal\dx_delivery\Service\DeliveryOrchestrator;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders acceptance reports for completed deliveries.
 */
class AcceptanceReportController extends ControllerBase {

  public function __construct(
    protected DeliveryOrchestrator $orchestrator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('dx_delivery.orchestrator'),
    );
  }

  /**
   * Builds the acceptance report page.
   */
  public function report(Blueprint $dx_blueprint): array {
    $report = $dx_blueprint->getAcceptanceReport();
    $steps = $this->orchestrator->getSteps($dx_blueprint);

    $checklistRows = [];
    foreach ($report['checklist'] ?? [] as $item) {
      $checklistRows[] = [
        $item['label'] ?? '',
        !empty($item['passed']) ? $this->t('Pass') : $this->t('Pending'),
        $item['detail'] ?? '',
      ];
    }

    $stepRows = [];
    foreach ($steps as $step) {
      $stepRows[] = [
        $step->get('stage')->value,
        $step->get('status')->value,
        $step->get('message')->value,
        $step->get('started')->value ? date('Y-m-d H:i:s', (int) $step->get('started')->value) : '',
      ];
    }

    $portalUrl = (string) ($report['portal_url'] ?? '');
    $portalLink = $portalUrl !== ''
      ? Link::fromTextAndUrl($portalUrl, Url::fromUri($portalUrl))->toString()
      : $this->t('Not available');

    return [
      '#theme' => 'item_list',
      '#title' => $this->t('Delivery summary'),
      '#items' => [
        $this->t('Blueprint: @label (@machine)', [
          '@label' => $dx_blueprint->label(),
          '@machine' => $dx_blueprint->getMachineName(),
        ]),
        $this->t('Status: @status', ['@status' => $dx_blueprint->getStatus()]),
        $this->t('Portal: @url', ['@url' => $portalLink]),
        $this->t('Theme: @skin', ['@skin' => $report['theme_skin'] ?? $dx_blueprint->get('theme_skin')->value]),
      ],
      'checklist' => [
        '#type' => 'table',
        '#header' => [$this->t('Item'), $this->t('Result'), $this->t('Detail')],
        '#rows' => $checklistRows,
        '#empty' => $this->t('No checklist generated yet.'),
        '#caption' => $this->t('Acceptance checklist'),
      ],
      'steps' => [
        '#type' => 'table',
        '#header' => [$this->t('Stage'), $this->t('Status'), $this->t('Message'), $this->t('Started')],
        '#rows' => $stepRows,
        '#empty' => $this->t('No pipeline steps recorded.'),
        '#caption' => $this->t('Pipeline audit log'),
      ],
      'actions' => [
        '#type' => 'container',
        'back' => [
          '#type' => 'link',
          '#title' => $this->t('Back to delivery orders'),
          '#url' => Url::fromRoute('entity.dx_blueprint.collection'),
          '#attributes' => ['class' => ['button']],
        ],
      ],
    ];
  }

}
