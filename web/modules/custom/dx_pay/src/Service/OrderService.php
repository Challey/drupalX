<?php

declare(strict_types=1);

namespace Drupal\dx_pay\Service;

use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\dx_pay\Gateway\AlipayGateway;
use Drupal\dx_pay\Gateway\PaymentGatewayInterface;
use Drupal\dx_pay\Gateway\WechatGateway;
use Drupal\node\NodeInterface;

/**
 * Creates and settles lightweight store orders.
 */
class OrderService {

  public function __construct(
    protected Connection $database,
    protected ConfigFactoryInterface $configFactory,
    protected UuidInterface $uuid,
    protected AccountProxyInterface $currentUser,
    protected WechatGateway $wechat,
    protected AlipayGateway $alipay,
  ) {}

  /**
   * Lists available gateways.
   *
   * @return array<string, \Drupal\dx_pay\Gateway\PaymentGatewayInterface>
   */
  public function gateways(): array {
    return [
      'wechat' => $this->wechat,
      'alipay' => $this->alipay,
    ];
  }

  /**
   * Returns one gateway.
   */
  public function gateway(string $id): PaymentGatewayInterface {
    $all = $this->gateways();
    if (!isset($all[$id])) {
      throw new \InvalidArgumentException("Unknown payment gateway: {$id}");
    }
    return $all[$id];
  }

  /**
   * Creates a pending order from a product node.
   *
   * @return array<string, mixed>
   */
  public function createFromProduct(NodeInterface $node, string $gatewayId, string $buyerMail = ''): array {
    if ($node->bundle() !== 'dx_product' || !$node->isPublished()) {
      throw new \InvalidArgumentException('Only published dx_product nodes can be purchased.');
    }
    $gateway = $this->gateway($gatewayId);
    if (!$gateway->isConfigured()) {
      throw new \RuntimeException($gateway->label() . ' is not configured.');
    }

    $amount = 0.0;
    if ($node->hasField('field_dx_price') && !$node->get('field_dx_price')->isEmpty()) {
      $amount = (float) $node->get('field_dx_price')->value;
    }
    if ($amount <= 0) {
      throw new \RuntimeException('Product price must be greater than zero.');
    }

    $now = \Drupal::time()->getRequestTime();
    $uuid = $this->uuid->generate();
    $fields = [
      'uuid' => $uuid,
      'product_nid' => (int) $node->id(),
      'title' => (string) $node->label(),
      'amount' => number_format($amount, 2, '.', ''),
      'currency' => (string) ($this->configFactory->get('dx_pay.settings')->get('currency') ?: 'CNY'),
      'gateway' => $gatewayId,
      'status' => 'pending',
      'buyer_mail' => $buyerMail,
      'external_id' => '',
      'payload' => json_encode(['product' => $node->id()], JSON_UNESCAPED_UNICODE),
      'uid' => (int) $this->currentUser->id(),
      'created' => $now,
      'changed' => $now,
    ];
    $id = (int) $this->database->insert('dx_pay_order')->fields($fields)->execute();
    $fields['id'] = $id;
    return $fields;
  }

  /**
   * Starts payment and returns pay URL + updated order.
   *
   * @return array{order: array<string, mixed>, pay_url: string}
   */
  public function startPayment(array $order): array {
    $gateway = $this->gateway((string) $order['gateway']);
    $notifyUrl = Url::fromRoute('dx_pay.notify', ['gateway' => $gateway->id()], ['absolute' => TRUE])->toString();
    $returnUrl = Url::fromRoute('dx_pay.return', ['uuid' => $order['uuid']], ['absolute' => TRUE])->toString();
    $result = $gateway->createPayment($order, $notifyUrl, $returnUrl);
    $this->database->update('dx_pay_order')
      ->fields([
        'external_id' => $result['external_id'],
        'payload' => json_encode($result['raw'] ?? $result, JSON_UNESCAPED_UNICODE),
        'status' => 'awaiting_payment',
        'changed' => \Drupal::time()->getRequestTime(),
      ])
      ->condition('id', (int) $order['id'])
      ->execute();
    $order['external_id'] = $result['external_id'];
    $order['status'] = 'awaiting_payment';
    return ['order' => $order, 'pay_url' => $result['pay_url']];
  }

  /**
   * Loads an order by UUID.
   *
   * @return array<string, mixed>|null
   */
  public function loadByUuid(string $uuid): ?array {
    $row = $this->database->select('dx_pay_order', 'o')
      ->fields('o')
      ->condition('uuid', $uuid)
      ->execute()
      ->fetchAssoc();
    return $row ?: NULL;
  }

  /**
   * Marks an order paid.
   */
  public function markPaid(string $uuid, string $externalId = ''): void {
    $fields = [
      'status' => 'paid',
      'changed' => \Drupal::time()->getRequestTime(),
    ];
    if ($externalId !== '') {
      $fields['external_id'] = $externalId;
    }
    $this->database->update('dx_pay_order')
      ->fields($fields)
      ->condition('uuid', $uuid)
      ->execute();
  }

  /**
   * Handles gateway notify.
   */
  public function handleNotify(string $gatewayId, array $payload): array {
    $gateway = $this->gateway($gatewayId);
    $verified = $gateway->verifyNotify($payload);
    if (empty($verified['ok'])) {
      return ['ok' => FALSE, 'message' => $verified['message'] ?? 'verify failed'];
    }
    $uuid = (string) ($payload['uuid'] ?? '');
    if ($uuid === '') {
      return ['ok' => FALSE, 'message' => 'missing uuid'];
    }
    $this->markPaid($uuid, (string) ($verified['external_id'] ?? ''));
    return ['ok' => TRUE, 'uuid' => $uuid];
  }

  /**
   * Recent orders for admin.
   *
   * @return array<int, object>
   */
  public function recent(int $limit = 30): array {
    if (!$this->database->schema()->tableExists('dx_pay_order')) {
      return [];
    }
    return $this->database->select('dx_pay_order', 'o')
      ->fields('o')
      ->orderBy('id', 'DESC')
      ->range(0, $limit)
      ->execute()
      ->fetchAll();
  }

}
