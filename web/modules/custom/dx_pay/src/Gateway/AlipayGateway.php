<?php

declare(strict_types=1);

namespace Drupal\dx_pay\Gateway;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;

/**
 * Alipay scaffold (sandbox-friendly redirect flow).
 */
class AlipayGateway implements PaymentGatewayInterface {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'alipay';
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return '支付宝';
  }

  /**
   * {@inheritdoc}
   */
  public function isConfigured(): bool {
    if ($this->configFactory->get('dx_pay.settings')->get('sandbox_mode')) {
      return TRUE;
    }
    return (bool) $this->state->get('dx_pay.alipay.app_id')
      && (bool) $this->state->get('dx_pay.alipay.private_key');
  }

  /**
   * {@inheritdoc}
   */
  public function createPayment(array $order, string $notifyUrl, string $returnUrl): array {
    $externalId = 'ali_' . ($order['uuid'] ?? uniqid('ali_', TRUE));
    if ($this->configFactory->get('dx_pay.settings')->get('sandbox_mode')) {
      $payUrl = Url::fromRoute('dx_pay.sandbox_pay', [
        'uuid' => $order['uuid'],
        'gateway' => $this->id(),
      ], ['absolute' => TRUE, 'query' => ['return' => $returnUrl]])->toString();
      return [
        'pay_url' => $payUrl,
        'external_id' => $externalId,
        'raw' => ['mode' => 'sandbox', 'notify_url' => $notifyUrl],
      ];
    }
    throw new \RuntimeException('Alipay production trade page is not wired yet. Enable sandbox_mode or integrate Alipay OpenAPI.');
  }

  /**
   * {@inheritdoc}
   */
  public function verifyNotify(array $payload): array {
    if (!empty($payload['sandbox']) || $this->configFactory->get('dx_pay.settings')->get('sandbox_mode')) {
      return [
        'ok' => TRUE,
        'external_id' => (string) ($payload['external_id'] ?? ''),
      ];
    }
    return ['ok' => FALSE, 'message' => 'Production notify verification not configured.'];
  }

}
