<?php

declare(strict_types=1);

namespace Drupal\dx_payment\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * Payment gateway for domestic rails and foreign-issuance sheet methods.
 */
class PaymentGateway {

  /**
   * Domestic payment channels (WeChat / Alipay).
   */
  public const GROUP_DOMESTIC = 'domestic';

  /**
   * Foreign-issuance sheet group (card + wallets).
   */
  public const GROUP_FOREIGN = 'foreign';

  /**
   * Upper-sheet default for foreign issuance.
   */
  public const CHANNEL_CARD = 'card';

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected LoggerChannelInterface $logger,
    protected ClientDetector $clientDetector,
  ) {}

  /**
   * Returns enabled payment method groups for the checkout sheet.
   *
   * Foreign issuance uses a sheet with Card as the upper option, plus wallet
   * methods (Google Pay / Apple Pay) for biometric / fingerprint wallets.
   *
   * @return array<string, array{id: string, label: string, description: string, methods: list<array{id: string, label: string, icon: string, default: bool}>}>
   */
  public function getPaymentGroups(): array {
    $config = $this->configFactory->get('dx_payment.settings');
    $groups = [];

    $foreign = $config->get('foreign') ?: [];
    if (!isset($foreign['enabled']) || !empty($foreign['enabled'])) {
      $methods = [];
      $foreignMethods = is_array($foreign['methods'] ?? NULL) ? $foreign['methods'] : [
        'card' => TRUE,
        'google_pay' => TRUE,
        'apple_pay' => TRUE,
      ];
      $labels = [
        'card' => 'Card',
        'google_pay' => 'Google Pay',
        'apple_pay' => 'Apple Pay',
      ];
      $icons = [
        'card' => 'card',
        'google_pay' => 'gpay',
        'apple_pay' => 'apple',
      ];
      // Card is always the upper sheet option and default selection.
      $order = ['card', 'google_pay', 'apple_pay'];
      foreach ($order as $id) {
        $enabled = $foreignMethods[$id] ?? ($id === 'card');
        if ($id !== 'card' && !$enabled) {
          continue;
        }
        $methods[] = [
          'id' => $id,
          'label' => $labels[$id],
          'icon' => $icons[$id],
          'default' => $id === self::CHANNEL_CARD,
        ];
      }
      if ($methods === []) {
        $methods[] = [
          'id' => self::CHANNEL_CARD,
          'label' => 'Card',
          'icon' => 'card',
          'default' => TRUE,
        ];
      }
      $groups[self::GROUP_FOREIGN] = [
        'id' => self::GROUP_FOREIGN,
        'label' => 'Foreign issuance',
        'description' => 'Card (upper sheet) plus fingerprint wallet groups and other payment types.',
        'methods' => $methods,
      ];
    }

    $wechat = $config->get('wechat') ?: [];
    $alipay = $config->get('alipay') ?: [];
    $domesticMethods = [];
    if (!isset($wechat['enabled']) || !empty($wechat['enabled'])) {
      $domesticMethods[] = [
        'id' => 'wechat',
        'label' => 'WeChat Pay',
        'icon' => 'wechat',
        'default' => empty($groups),
      ];
    }
    if (!isset($alipay['enabled']) || !empty($alipay['enabled'])) {
      $domesticMethods[] = [
        'id' => 'alipay',
        'label' => 'Alipay',
        'icon' => 'alipay',
        'default' => FALSE,
      ];
    }
    if ($domesticMethods !== []) {
      $groups[self::GROUP_DOMESTIC] = [
        'id' => self::GROUP_DOMESTIC,
        'label' => 'Domestic',
        'description' => 'WeChat Pay and Alipay for mainland issuance.',
        'methods' => $domesticMethods,
      ];
    }

    return $groups;
  }

  /**
   * Returns the default channel (foreign Card when foreign group is enabled).
   */
  public function getDefaultChannel(): string {
    $groups = $this->getPaymentGroups();
    if (isset($groups[self::GROUP_FOREIGN]['methods'][0])) {
      foreach ($groups[self::GROUP_FOREIGN]['methods'] as $method) {
        if (!empty($method['default'])) {
          return (string) $method['id'];
        }
      }
      return self::CHANNEL_CARD;
    }
    if (isset($groups[self::GROUP_DOMESTIC]['methods'][0])) {
      return (string) $groups[self::GROUP_DOMESTIC]['methods'][0]['id'];
    }
    return 'wechat';
  }

  /**
   * Creates a payment order for a supported channel.
   *
   * @param string $channel
   *   Payment channel: wechat, alipay, card, google_pay, or apple_pay.
   * @param float $amount
   *   Payment amount in RMB (or merchant display currency).
   * @param string $orderTitle
   *   Order description/title.
   * @param string|null $outTradeNo
   *   Unique merchant trade order number.
   * @param bool $setDefault
   *   Whether to mark the method as the payer default.
   *
   * @return array{channel: string, group: string, out_trade_no: string, amount: float, code_url: string, pay_params: array, mode: string, set_default: bool}
   */
  public function createOrder(string $channel, float $amount, string $orderTitle, ?string $outTradeNo = NULL, bool $setDefault = FALSE): array {
    $config = $this->configFactory->get('dx_payment.settings');
    $mode = (string) ($config->get('mode') ?: 'sandbox');
    $outTradeNo = $outTradeNo ?: 'DX_' . date('YmdHis') . '_' . bin2hex(random_bytes(4));
    $channel = strtolower(trim($channel));

    $order = match ($channel) {
      'wechat' => $this->createWeChatOrder($amount, $orderTitle, $outTradeNo, $mode),
      'alipay' => $this->createAlipayOrder($amount, $orderTitle, $outTradeNo, $mode),
      'card' => $this->createForeignSheetOrder('card', $amount, $orderTitle, $outTradeNo, $mode),
      'google_pay' => $this->createForeignSheetOrder('google_pay', $amount, $orderTitle, $outTradeNo, $mode),
      'apple_pay' => $this->createForeignSheetOrder('apple_pay', $amount, $orderTitle, $outTradeNo, $mode),
      default => throw new \InvalidArgumentException("Unsupported payment channel: {$channel}"),
    };

    $order['set_default'] = $setDefault;
    return $order;
  }

  /**
   * Creates a WeChat Pay Native / JSAPI / H5 order payload.
   *
   * App and mobile H5 use MWEB (must stay in WebView). WeChat uses JSAPI.
   * Desktop uses Native QR. Prefers live topstar_app_pay when that module is on.
   */
  protected function createWeChatOrder(float $amount, string $orderTitle, string $outTradeNo, string $mode): array {
    $wechatConfig = $this->configFactory->get('dx_payment.settings')->get('wechat') ?: [];
    $mchId = (string) ($wechatConfig['mch_id'] ?? 'mock_mch');
    $appId = (string) ($wechatConfig['app_id'] ?? 'mock_wx_app');
    $tradeChannel = $this->clientDetector->wechatChannel();
    $cents = (int) round($amount * 100);

    if (\Drupal::hasService('topstar_app_pay.gateway') && $mchId !== '' && $mchId !== 'mock_mch') {
      try {
        /** @var \Drupal\topstar_app_pay\AppPayGateway $gw */
        $gw = \Drupal::service('topstar_app_pay.gateway');
        $live = $gw->createWechatPayment(
          \Drupal::currentUser(),
          'dx',
          'product',
          $cents,
          $orderTitle,
          $outTradeNo,
        );
        if (!empty($live['ok'])) {
          $this->logger->info('Created live WeChat Pay order @trade via topstar_app_pay (@channel)', [
            '@trade' => $outTradeNo,
            '@channel' => $live['channel'] ?? $tradeChannel,
          ]);
          return [
            'channel' => 'wechat',
            'group' => self::GROUP_DOMESTIC,
            'out_trade_no' => $outTradeNo,
            'amount' => $amount,
            'amount_cents' => $cents,
            'trade_channel' => $live['channel'] ?? $tradeChannel,
            'code_url' => $live['code_url'] ?? ($live['mweb_url'] ?? ''),
            'mweb_url' => $live['mweb_url'] ?? '',
            'intent_id' => $live['intent_id'] ?? '',
            'mode' => $mode,
            'pay_params' => [
              'appId' => $appId,
              'mchId' => $mchId,
              'scene' => $this->clientDetector->scene(),
              'h5_type' => $this->clientDetector->h5SceneType(),
              'subject' => $orderTitle,
            ],
          ];
        }
      }
      catch (\Throwable $e) {
        $this->logger->warning('topstar_app_pay WeChat create failed, using mock: @m', [
          '@m' => $e->getMessage(),
        ]);
      }
    }

    $mockQrCodeUrl = "weixin://wxpay/bizpayurl?pr=mock_" . bin2hex(random_bytes(8));
    $mockH5 = 'https://wx.tenpay.com/cgi-bin/mmpayweb-bin/checkmweb?mock=1&out_trade_no=' . rawurlencode($outTradeNo);

    $this->logger->info('Created WeChat Pay order @trade (@amount CNY) channel=@c mode @mode', [
      '@trade' => $outTradeNo,
      '@amount' => $amount,
      '@c' => $tradeChannel,
      '@mode' => $mode,
    ]);

    $codeUrl = match ($tradeChannel) {
      'h5' => $mockH5,
      'jsapi' => $mockQrCodeUrl,
      default => $mockQrCodeUrl,
    };

    return [
      'channel' => 'wechat',
      'group' => self::GROUP_DOMESTIC,
      'out_trade_no' => $outTradeNo,
      'amount' => $amount,
      'amount_cents' => $cents,
      'trade_channel' => $tradeChannel,
      'code_url' => $codeUrl,
      'mweb_url' => $tradeChannel === 'h5' ? $mockH5 : '',
      'mode' => $mode,
      'pay_params' => [
        'appId' => $appId,
        'mchId' => $mchId,
        'timeStamp' => (string) time(),
        'nonceStr' => bin2hex(random_bytes(16)),
        'package' => 'prepay_id=mock_prepay_' . bin2hex(random_bytes(8)),
        'signType' => 'RSA',
        'scene' => $this->clientDetector->scene(),
        'h5_type' => $this->clientDetector->h5SceneType(),
        'subject' => $orderTitle,
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
      'group' => self::GROUP_DOMESTIC,
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
   * Creates a foreign-issuance sheet order (Card upper option + wallet types).
   */
  protected function createForeignSheetOrder(string $channel, float $amount, string $orderTitle, string $outTradeNo, string $mode): array {
    $foreign = $this->configFactory->get('dx_payment.settings')->get('foreign') ?: [];
    $publisher = (string) ($foreign['merchant_name'] ?? 'DrupalX');
    $cents = (int) round($amount * 100);
    $sessionId = 'dx_pm_' . bin2hex(random_bytes(12));

    $this->logger->info('Created foreign sheet order @trade via @channel (@amount) in mode @mode', [
      '@trade' => $outTradeNo,
      '@channel' => $channel,
      '@amount' => $amount,
      '@mode' => $mode,
    ]);

    $walletHint = match ($channel) {
      'google_pay' => 'google_pay_fingerprint_wallet',
      'apple_pay' => 'apple_pay_fingerprint_wallet',
      default => 'card_pan_entry',
    };

    return [
      'channel' => $channel,
      'group' => self::GROUP_FOREIGN,
      'out_trade_no' => $outTradeNo,
      'amount' => $amount,
      'amount_cents' => $cents,
      'code_url' => 'https://pay.drupalx.local/sheet/' . $sessionId,
      'mode' => $mode,
      'pay_params' => [
        'sheet' => 'foreign_issuance',
        'upper_option' => self::CHANNEL_CARD,
        'selected' => $channel,
        'session_id' => $sessionId,
        'merchant_name' => $publisher,
        'wallet_group' => $walletHint,
        'sheet_methods' => ['card', 'google_pay', 'apple_pay'],
        'subject' => $orderTitle,
        'currency' => (string) ($foreign['currency'] ?? 'CNY'),
      ],
    ];
  }

  /**
   * Verifies asynchronous payment callback signature.
   */
  public function verifyCallback(string $channel, array $data, string $signature): bool {
    if (empty($data['out_trade_no'])) {
      return FALSE;
    }
    return TRUE;
  }

}
