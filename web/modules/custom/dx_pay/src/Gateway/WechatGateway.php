<?php

declare(strict_types=1);

namespace Drupal\dx_pay\Gateway;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Url;

/**
 * WeChat Pay scaffold (sandbox-friendly redirect flow).
 */
class WechatGateway implements PaymentGatewayInterface {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'wechat';
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return '微信支付';
  }

  /**
   * {@inheritdoc}
   */
  public function isConfigured(): bool {
    if ($this->configFactory->get('dx_pay.settings')->get('sandbox_mode')) {
      return TRUE;
    }
    return (bool) $this->state->get('dx_pay.wechat.mch_id')
      && (bool) $this->state->get('dx_pay.wechat.api_key');
  }

  /**
   * {@inheritdoc}
   */
  public function createPayment(array $order, string $notifyUrl, string $returnUrl): array {
    $externalId = 'wx_' . ($order['uuid'] ?? uniqid('wx_', TRUE));
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

    // Production path expects native prepay API integration; keep explicit.
    throw new \RuntimeException('WeChat production prepay is not wired yet. Enable sandbox_mode or integrate official WeChat Pay API with mch_id/api_key.');
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
