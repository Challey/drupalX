<?php

declare(strict_types=1);

namespace Drupal\dx_pay\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\dx_pay\Service\OrderService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Storefront and payment notify controllers.
 */
class PayController extends ControllerBase {

  public function __construct(
    protected OrderService $orders,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('dx_pay.orders'),
    );
  }

  /**
   * Product store listing.
   */
  public function store(): array {
    $storage = $this->entityTypeManager()->getStorage('node');
    $nids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'dx_product')
      ->condition('status', 1)
      ->sort('created', 'DESC')
      ->execute();
    $products = [];
    foreach ($storage->loadMultiple($nids) as $node) {
      $price = $node->hasField('field_dx_price') ? (string) $node->get('field_dx_price')->value : '';
      $products[] = [
        'title' => $node->label(),
        'price' => $price,
        'sku' => $node->hasField('field_dx_sku') ? (string) $node->get('field_dx_sku')->value : '',
        'url' => $node->toUrl()->toString(),
        'checkout' => Url::fromRoute('dx_pay.checkout', ['node' => $node->id()])->toString(),
      ];
    }
    return [
      '#theme' => 'dx_pay_store',
      '#products' => $products,
      '#cache' => ['tags' => ['node_list:dx_product']],
    ];
  }

  /**
   * Admin order list.
   */
  public function orders(): array {
    $rows = [];
    foreach ($this->orders->recent() as $order) {
      $rows[] = [
        $order->id,
        $order->title,
        $order->amount . ' ' . $order->currency,
        $order->gateway,
        $order->status,
        $order->uuid,
      ];
    }
    return [
      '#type' => 'table',
      '#header' => [
        $this->t('ID'),
        $this->t('Product'),
        $this->t('Amount'),
        $this->t('Gateway'),
        $this->t('Status'),
        $this->t('UUID'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No orders yet.'),
    ];
  }

  /**
   * Sandbox payment page that immediately marks the order paid.
   */
  public function sandboxPay(string $gateway, string $uuid, Request $request): array|RedirectResponse {
    $order = $this->orders->loadByUuid($uuid);
    if (!$order) {
      return ['#markup' => $this->t('Order not found.')];
    }
    if ($request->query->get('confirm')) {
      $this->orders->handleNotify($gateway, [
        'sandbox' => TRUE,
        'uuid' => $uuid,
        'external_id' => $order['external_id'] ?: ($gateway . '_sandbox_' . $uuid),
      ]);
      $return = $request->query->get('return');
      if (is_string($return) && $return !== '') {
        return new RedirectResponse($return);
      }
      return new RedirectResponse(Url::fromRoute('dx_pay.return', ['uuid' => $uuid])->toString());
    }

    return [
      '#type' => 'container',
      'intro' => [
        '#markup' => '<p>' . $this->t('Sandbox @gateway payment for @title (@amount @currency).', [
          '@gateway' => $gateway,
          '@title' => $order['title'],
          '@amount' => $order['amount'],
          '@currency' => $order['currency'],
        ]) . '</p>',
      ],
      'confirm' => [
        '#type' => 'link',
        '#title' => $this->t('模拟支付成功'),
        '#url' => Url::fromRoute('dx_pay.sandbox_pay', [
          'gateway' => $gateway,
          'uuid' => $uuid,
        ], [
          'query' => [
            'confirm' => 1,
            'return' => $request->query->get('return'),
          ],
        ]),
        '#attributes' => ['class' => ['button', 'button--primary']],
      ],
    ];
  }

  /**
   * Async notify endpoint.
   */
  public function notify(string $gateway, Request $request): JsonResponse {
    $payload = json_decode($request->getContent(), TRUE);
    if (!is_array($payload)) {
      $payload = $request->request->all();
    }
    $result = $this->orders->handleNotify($gateway, $payload);
    return new JsonResponse($result, !empty($result['ok']) ? 200 : 400);
  }

  /**
   * Buyer return page.
   */
  public function returnPage(string $uuid): array {
    $order = $this->orders->loadByUuid($uuid);
    if (!$order) {
      return ['#markup' => $this->t('Order not found.')];
    }
    return [
      '#theme' => 'dx_pay_checkout',
      '#product' => NULL,
      '#order' => $order,
      '#pay_url' => '',
      '#gateway' => $order['gateway'],
    ];
  }

}
