<?php

declare(strict_types=1);

namespace Drupal\dx_delivery\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\dx_delivery\Entity\DeliveryBlueprint;
use Drupal\dx_delivery\Service\BlueprintFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Public delivery desk pages.
 */
final class DeliveryDeskController extends ControllerBase {

  public function __construct(
    protected BlueprintFactory $factory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('dx_delivery.blueprint_factory'));
  }

  /**
   * Desk landing.
   */
  public function desk(): array {
    $storage = $this->entityTypeManager()->getStorage('dx_blueprint');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->sort('created', 'DESC')
      ->range(0, 5)
      ->execute();
    $recent = [];
    foreach ($storage->loadMultiple($ids) as $entity) {
      /** @var \Drupal\dx_delivery\Entity\DeliveryBlueprint $entity */
      $recent[] = [
        'label' => $entity->label(),
        'status' => $entity->getStatus(),
        'url' => Url::fromRoute('dx_delivery.blueprint', ['dx_blueprint' => $entity->id()])->toString(),
      ];
    }

    return [
      '#theme' => 'dx_delivery_desk',
      '#title' => $this->t('交钥匙交付台'),
      '#intro' => $this->t('说出需求或页面选型，一键生成政企门户交付物。'),
      '#wizard_url' => Url::fromRoute('dx_delivery.wizard')->toString(),
      '#chat_url' => Url::fromRoute('dx_delivery.chat')->toString(),
      '#recent' => $recent,
      '#attached' => ['library' => ['dx_delivery/dx_delivery']],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Blueprint detail + acceptance.
   */
  public function blueprint(DeliveryBlueprint $dx_blueprint): array {
    $acceptance = json_decode((string) $dx_blueprint->get('acceptance')->value, TRUE);
    if (!is_array($acceptance)) {
      $acceptance = [];
    }
    $payload = $dx_blueprint->getPayload();
    $summary = [
      ['label' => (string) $this->t('站点类型'), 'value' => (string) ($dx_blueprint->get('site_type')->value ?: '')],
      ['label' => (string) $this->t('主题包'), 'value' => (string) ($dx_blueprint->get('theme_pack')->value ?: '')],
      ['label' => (string) $this->t('布局'), 'value' => (string) ($dx_blueprint->get('layout_profile')->value ?: '')],
      ['label' => (string) $this->t('渠道'), 'value' => implode(', ', $dx_blueprint->getChannels())],
      ['label' => (string) $this->t('能力'), 'value' => implode(', ', $dx_blueprint->getCapabilities()) ?: '—'],
      ['label' => (string) $this->t('移植'), 'value' => (string) ($dx_blueprint->get('migrate_level')->value ?: 'none')],
    ];
    $steps = [];
    foreach ($acceptance['steps'] ?? [] as $step) {
      if (!is_array($step)) {
        continue;
      }
      $steps[] = [
        'id' => (string) ($step['id'] ?? ''),
        'ok' => !empty($step['ok']),
        'message' => (string) ($step['message'] ?? ''),
      ];
    }
    return [
      '#theme' => 'dx_delivery_blueprint',
      '#blueprint' => $dx_blueprint,
      '#site_type' => (string) ($dx_blueprint->get('site_type')->value ?: ''),
      '#summary_rows' => $summary,
      '#payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
      '#acceptance_json' => $acceptance === [] ? '' : json_encode($acceptance, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
      '#acceptance_steps' => $steps,
      '#acceptance_passed' => !empty($acceptance['passed']),
      '#portal_url' => (string) ($acceptance['portal_url'] ?? ''),
      '#confirm_url' => Url::fromRoute('dx_delivery.confirm', [
        'dx_blueprint' => $dx_blueprint->id(),
      ])->toString(),
      '#log' => (string) $dx_blueprint->get('log')->value,
      '#attached' => ['library' => ['dx_delivery/dx_delivery']],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * JSON chat helper for programmatic clients.
   */
  public function chatApi(Request $request): JsonResponse {
    $body = json_decode($request->getContent(), TRUE);
    if (!is_array($body) || empty($body['message']) || !is_string($body['message'])) {
      return new JsonResponse(['ok' => FALSE, 'error' => 'message required'], 400);
    }
    $built = $this->factory->fromChat($body['message'], is_array($body['overrides'] ?? NULL) ? $body['overrides'] : []);
    $entity = DeliveryBlueprint::create($built['fields']);
    $entity->setPayload($built['payload']);
    $entity->save();
    return new JsonResponse([
      'ok' => TRUE,
      'id' => (int) $entity->id(),
      'payload' => $built['payload'],
      'notes' => $built['notes'] ?? '',
      'url' => Url::fromRoute('dx_delivery.blueprint', ['dx_blueprint' => $entity->id()])->toString(),
    ]);
  }

}
