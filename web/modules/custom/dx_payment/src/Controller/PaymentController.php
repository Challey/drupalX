<?php

declare(strict_types=1);

namespace Drupal\dx_payment\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\dx_payment\Service\PaymentGateway;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Checkout and payment controller.
 */
class PaymentController extends ControllerBase {

  public function __construct(
    protected PaymentGateway $paymentGateway,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('dx_payment.gateway'),
    );
  }

  /**
   * Product checkout page with WeChat Pay and Alipay selection.
   */
  public function checkout(NodeInterface $node): array {
    $title = $node->label();
    $price = $node->hasField('field_dx_price') ? (float) $node->get('field_dx_price')->value : 0.00;
    $sku = $node->hasField('field_dx_sku') ? (string) $node->get('field_dx_sku')->value : '';

    return [
      '#theme' => 'dx_payment_checkout',
      '#title' => $this->t('Purchase: @product', ['@product' => $title]),
      '#product' => [
        'id' => $node->id(),
        'title' => $title,
        'sku' => $sku,
        'price' => number_format($price, 2),
      ],
      '#endpoint' => '/dx/payment/create',
    ];
  }

  /**
   * API endpoint to create a payment session.
   */
  public function createPayment(Request $request): JsonResponse {
    $payload = json_decode($request->getContent(), TRUE);
    if (!is_array($payload)) {
      return new JsonResponse(['error' => 'Invalid payload.'], 400);
    }

    $channel = (string) ($payload['channel'] ?? 'wechat');
    $amount = (float) ($payload['amount'] ?? 0.00);
    $title = (string) ($payload['title'] ?? 'DrupalX Product Order');

    if ($amount <= 0) {
      return new JsonResponse(['error' => 'Amount must be greater than 0.'], 400);
    }

    try {
      $order = $this->paymentGateway->createOrder($channel, $amount, $title);
      return new JsonResponse([
        'success' => TRUE,
        'order' => $order,
      ]);
    }
    catch (\Throwable $e) {
      return new JsonResponse(['error' => $e->getMessage()], 500);
    }
  }

}
