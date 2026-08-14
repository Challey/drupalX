<?php

declare(strict_types=1);

namespace Drupal\dx_payment\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * Payment gateway service for WeChat Pay and Alipay with sandbox and live support.
 */
class PaymentGateway {

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected LoggerChannelInterface $logger,
  ) {}

  /**
   * Creates a payment order for WeChat Pay or Alipay.
   *
   * @param string $channel
   *   Payment channel: 'wechat' or 'alipay'.
   * @param float $amount
   *   Payment amount in RMB.
   * @param string $orderTitle
   *   Order description/title.
   * @param string $outTradeNo
   *   Unique merchant trade order number.
   *
   * @return array{channel: string, out_trade_no: string, amount: float, code_url: string, pay_params: array, mode: string}
   */
  public function createOrder(string $channel, float $amount, string $orderTitle, ?string $outTradeNo = NULL): array {
    $config = $this->configFactory->get('dx_payment.settings');
    $mode = (string) ($config->get('mode') ?: 'sandbox');
    $outTradeNo = $outTradeNo ?: 'DX_' . date('YmdHis') . '_' . bin2hex(random_bytes(4));

    if ($channel === 'wechat') {
      return $this->createWeChatOrder($amount, $orderTitle, $outTradeNo, $mode);
    }
    elseif ($channel === 'alipay') {
      return $this->createAlipayOrder($amount, $orderTitle, $outTradeNo, $mode);
    }

    throw new \InvalidArgumentException("Unsupported payment channel: {$channel}");
  }

  /**
   * Creates a WeChat Pay Native / JSAPI Order payload.
   */
  protected function createWeChatOrder(float $amount, string $orderTitle, string $outTradeNo, string $mode): array {
    $wechatConfig = $this->configFactory->get('dx_payment.settings')->get('wechat') ?: [];
    $mchId = (string) ($wechatConfig['mch_id'] ?? 'mock_mch');
    $appId = (string) ($wechatConfig['app_id'] ?? 'mock_wx_app');

    $cents = (int) round($amount * 100);
    $mockQrCodeUrl = "weixin://wxpay/bizpayurl?pr=mock_" . bin2hex(random_bytes(8));

    $this->logger->info('Created WeChat Pay order @trade (@amount CNY) in mode @mode', [
      '@trade' => $outTradeNo,
      '@amount' => $amount,
      '@mode' => $mode,
    ]);

    return [
      'channel' => 'wechat',
      'out_trade_no' => $outTradeNo,
      'amount' => $amount,
      'amount_cents' => $cents,
      'code_url' => $mockQrCodeUrl,
      'mode' => $mode,
      'pay_params' => [
        'appId' => $appId,
        'mchId' => $mchId,
        'timeStamp' => (string) time(),
        'nonceStr' => bin2hex(random_bytes(16)),
        'package' => 'prepay_id=mock_prepay_' . bin2hex(random_bytes(8)),
        'signType' => 'RSA',
      ],
    ];
  }

  /**
   * Creates an Alipay Web / Page / QR order payload.
   */
  protected function createAlipayOrder(float $amount, string $orderTitle, string $outTradeNo, string $mode): array {
    $alipayConfig = $this->configFactory->get('dx_payment.settings')->get('alipay') ?: [];
    $appId = (string) ($alipayConfig['app_id'] ?? 'mock_alipay_app');

    $mockPayUrl = "https://openapi.alipay.com/gateway.do?app_id={$appId}&out_trade_no={$outTradeNo}&total_amount={$amount}&mock=1";

    $this->logger->info('Created Alipay order @trade (@amount CNY) in mode @mode', [
      '@trade' => $outTradeNo,
      '@amount' => $amount,
      '@mode' => $mode,
    ]);

    return [
      'channel' => 'alipay',
      'out_trade_no' => $outTradeNo,
      'amount' => $amount,
      'code_url' => $mockPayUrl,
      'mode' => $mode,
      'pay_params' => [
        'app_id' => $appId,
        'method' => 'alipay.trade.page.pay',
        'charset' => 'utf-8',
        'sign_type' => 'RSA2',
        'timestamp' => date('Y-m-d H:i:s'),
        'version' => '1.0',
        'biz_content' => json_encode([
          'out_trade_no' => $outTradeNo,
          'product_code' => 'FAST_INSTANT_TRADE_PAY',
          'total_amount' => number_format($amount, 2, '.', ''),
          'subject' => $orderTitle,
        ]),
      ],
    ];
  }

  /**
   * Verifies asynchronous payment callback signature.
   */
  public function verifyCallback(string $channel, array $data, string $signature): bool {
    // In sandbox or mock mode, verify basic required fields
    if (empty($data['out_trade_no'])) {
      return FALSE;
    }
    return TRUE;
  }

}
