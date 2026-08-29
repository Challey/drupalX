<?php

declare(strict_types=1);

namespace Drupal\topstar_app_pay;

use Drupal\Component\Utility\Crypt;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use GuzzleHttp\ClientInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared WeChat pay gateway: App/H5 uses H5, WeChat uses JSAPI, desktop Native.
 *
 * Prefers Commerce WeChat V3 (same merchant as topstar checkout), then
 * trail_run_pay, then legacy V2 unifiedorder.
 */
final class AppPayGateway {

  public function __construct(
    private readonly ClientDetector $detector,
    private readonly Connection $database,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly ClientInterface $httpClient,
    private readonly RequestStack $requestStack,
    private readonly UuidInterface $uuid,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * @return array{ok: bool, intent_id?: string, channel?: string, mweb_url?: string, code_url?: string, out_trade_no?: string, error?: string}
   */
  public function createWechatPayment(AccountInterface $account, string $product, string $plan, int $amountFen, string $subject, ?string $outTradeNo = NULL): array {
    $scene = $this->detector->scene();
    $channel = $this->detector->wechatChannel();
    $outTradeNo = $outTradeNo !== NULL && $outTradeNo !== '' ? $outTradeNo : $this->makeOutTradeNo($product);
    $intentId = str_replace('-', '', $this->uuid->generate());
    $notifyUrl = $this->notifyUrl();
    $returnUrl = $this->waitUrl($intentId);

    $unified = $this->placeOrder($channel, [
      'out_trade_no' => $outTradeNo,
      'subject' => mb_substr($subject, 0, 120),
      'amount_fen' => $amountFen,
      'notify_url' => $notifyUrl,
      'return_url' => $returnUrl,
    ]);
    if (!$unified['ok']) {
      return ['ok' => FALSE, 'error' => $unified['error'] ?? 'unifiedorder_failed'];
    }

    $now = time();
    $this->database->insert('topstar_app_pay_intent')->fields([
      'intent_id' => $intentId,
      'out_trade_no' => $outTradeNo,
      'uid' => (int) $account->id(),
      'product' => $product,
      'plan' => $plan,
      'amount_fen' => $amountFen,
      'channel' => $channel,
      'status' => 'pending',
      'transaction_id' => '',
      'payload' => json_encode([
        'scene' => $scene,
        'subject' => $subject,
        'prepay_id' => $unified['prepay_id'] ?? '',
        'mweb_url' => $unified['mweb_url'] ?? '',
        'code_url' => $unified['code_url'] ?? '',
      ], JSON_UNESCAPED_UNICODE),
      'created' => $now,
      'changed' => $now,
    ])->execute();

    $out = [
      'ok' => TRUE,
      'intent_id' => $intentId,
      'channel' => $channel,
      'out_trade_no' => $outTradeNo,
    ];
    if (!empty($unified['mweb_url'])) {
      $out['mweb_url'] = $unified['mweb_url'];
    }
    if (!empty($unified['code_url'])) {
      $out['code_url'] = $unified['code_url'];
    }
    return $out;
  }

  /**
   * WeChat V3 JSON notify or legacy V2 XML.
   */
  public function handleWechatNotify(Request $request): Response {
    $v3fail = new JsonResponse(['code' => 'ERROR', 'message' => 'ERROR'], 400);
    $v3ok = new JsonResponse(['code' => 'SUCCESS', 'message' => '成功']);
    $xmlFail = new Response('<xml><return_code><![CDATA[FAIL]]></return_code><return_msg><![CDATA[ERROR]]></return_msg></xml>', 200, ['Content-Type' => 'text/xml; charset=utf-8']);
    $xmlOk = new Response('<xml><return_code><![CDATA[SUCCESS]]></return_code><return_msg><![CDATA[OK]]></return_msg></xml>', 200, ['Content-Type' => 'text/xml; charset=utf-8']);

    if ($request->headers->get('Wechatpay-Signature')) {
      $cfg = $this->loadCommerceWechatConfig();
      if ($cfg === NULL || !class_exists('\Drupal\commerce_wechat\WechatAPI')) {
        return $v3fail;
      }
      $api = new \Drupal\commerce_wechat\WechatAPI($cfg, $this->logger);
      if (!$api->verifyNotify($request)) {
        $this->logger->warning('WeChat V3 notify signature mismatch.');
        return $v3fail;
      }
      $data = $api->decrypt($request);
      if (!is_array($data) || ($data['trade_state'] ?? '') !== 'SUCCESS') {
        return $v3fail;
      }
      return $this->settleNotify(
        (string) ($data['out_trade_no'] ?? ''),
        (string) ($data['transaction_id'] ?? ''),
      ) ? $v3ok : $v3fail;
    }

    $credentials = $this->wechatV2Credentials();
    if ($credentials === NULL) {
      return $xmlFail;
    }
    $data = $this->xmlToArray((string) $request->getContent());
    if (($data['return_code'] ?? '') !== 'SUCCESS' || ($data['result_code'] ?? '') !== 'SUCCESS') {
      return $xmlFail;
    }
    if (!$this->verifySign($data, $credentials['key'])) {
      $this->logger->warning('WeChat V2 notify signature mismatch.');
      return $xmlFail;
    }
    return $this->settleNotify(
      (string) ($data['out_trade_no'] ?? ''),
      (string) ($data['transaction_id'] ?? ''),
    ) ? $xmlOk : $xmlFail;
  }

  /**
   * @return object|null
   */
  public function loadIntent(string $intentId): ?object {
    $row = $this->database->select('topstar_app_pay_intent', 'i')
      ->fields('i')
      ->condition('intent_id', $intentId)
      ->execute()
      ->fetchObject();
    return $row ?: NULL;
  }

  /**
   * @param array{out_trade_no: string, subject: string, amount_fen: int, notify_url: string, return_url: string} $params
   *
   * @return array{ok: bool, mweb_url?: string, code_url?: string, prepay_id?: string, error?: string}
   */
  private function placeOrder(string $channel, array $params): array {
    $viaCommerce = $this->placeOrderCommerceV3($channel, $params);
    if ($viaCommerce['ok'] || ($viaCommerce['error'] ?? '') !== 'wechat_not_configured') {
      return $viaCommerce;
    }
    $viaTrail = $this->placeOrderTrailRun($channel, $params);
    if ($viaTrail['ok'] || ($viaTrail['error'] ?? '') !== 'wechat_not_configured') {
      return $viaTrail;
    }
    $credentials = $this->wechatV2Credentials();
    if ($credentials === NULL) {
      return ['ok' => FALSE, 'error' => 'wechat_not_configured'];
    }
    return $this->unifiedOrderV2($credentials, $params, $channel);
  }

  /**
   * @param array{out_trade_no: string, subject: string, amount_fen: int, notify_url: string, return_url: string} $params
   */
  private function placeOrderCommerceV3(string $channel, array $params): array {
    $cfg = $this->loadCommerceWechatConfig();
    if ($cfg === NULL || !class_exists('\Drupal\commerce_wechat\WechatAPI')) {
      return ['ok' => FALSE, 'error' => 'wechat_not_configured'];
    }
    try {
      $api = new \Drupal\commerce_wechat\WechatAPI($cfg, $this->logger);
      $order = [
        'order_number' => $params['out_trade_no'],
        'description' => $params['subject'],
        'total' => $params['amount_fen'],
        'currency' => 'CNY',
        'notify_url' => $params['notify_url'],
        'return_url' => $params['return_url'],
        'timeout_express' => time() + 7200,
      ];
      if ($channel === 'native' && method_exists($api, 'orderNative')) {
        $code = $api->orderNative($order);
        return is_string($code) && $code !== ''
          ? ['ok' => TRUE, 'code_url' => $code]
          : ['ok' => FALSE, 'error' => 'native_failed'];
      }
      $h5 = $api->orderH5($order);
      if (!is_string($h5) || $h5 === '') {
        return ['ok' => FALSE, 'error' => 'h5_failed'];
      }
      return ['ok' => TRUE, 'mweb_url' => $h5];
    }
    catch (\Throwable $e) {
      $this->logger->error('commerce_wechat H5: @m', ['@m' => $e->getMessage()]);
      return ['ok' => FALSE, 'error' => 'h5_failed'];
    }
  }

  /**
   * @param array{out_trade_no: string, subject: string, amount_fen: int, notify_url: string, return_url: string} $params
   */
  private function placeOrderTrailRun(string $channel, array $params): array {
    if (!\Drupal::hasService('trail_run_pay.router')) {
      return ['ok' => FALSE, 'error' => 'wechat_not_configured'];
    }
    $trailChannel = match ($channel) {
      'jsapi' => 'mp',
      'native' => 'native',
      default => 'h5',
    };
    try {
      $result = \Drupal::service('trail_run_pay.router')->getGateway()->createPayment([
        'order_no' => $params['out_trade_no'],
        'amount' => $params['amount_fen'] / 100,
        'subject' => $params['subject'],
        'channel' => $trailChannel,
      ]);
    }
    catch (\Throwable $e) {
      $this->logger->error('trail_run_pay: @m', ['@m' => $e->getMessage()]);
      return ['ok' => FALSE, 'error' => 'trail_run_failed'];
    }
    if (empty($result['success'])) {
      return ['ok' => FALSE, 'error' => (string) ($result['error'] ?? 'trail_run_failed')];
    }
    $out = ['ok' => TRUE];
    if (!empty($result['redirect_url'])) {
      $out['mweb_url'] = (string) $result['redirect_url'];
    }
    if (!empty($result['qr_code'])) {
      $out['code_url'] = (string) $result['qr_code'];
    }
    return $out;
  }

  /**
   * @return array<string, mixed>|null
   */
  private function loadCommerceWechatConfig(): ?array {
    try {
      if (!\Drupal::entityTypeManager()->hasDefinition('commerce_payment_gateway')) {
        return NULL;
      }
      $gateways = \Drupal::entityTypeManager()->getStorage('commerce_payment_gateway')->loadMultiple();
      foreach ($gateways as $gateway) {
        $pluginId = (string) $gateway->getPluginId();
        if ($pluginId !== 'wechat' && !str_contains($pluginId, 'wechat')) {
          continue;
        }
        $cfg = $gateway->getPluginConfiguration();
        if (!empty($cfg['appId']) && !empty($cfg['merchantId'])) {
          return $cfg;
        }
      }
    }
    catch (\Throwable $e) {
      $this->logger->notice('commerce wechat config: @m', ['@m' => $e->getMessage()]);
    }
    return NULL;
  }

  /**
   * @return array{appid: string, mchid: string, key: string}|null
   */
  private function wechatV2Credentials(): ?array {
    $pay = $this->configFactory->get('trail_run_pay.config');
    $appid = trim((string) ($pay->get('wechat_app_id') ?? ''));
    $mchid = trim((string) ($pay->get('wechat_mch_id') ?? ''));
    $key = trim((string) ($pay->get('wechat_key') ?? $pay->get('wechat_api_v2_key') ?? ''));
    if ($appid !== '' && $mchid !== '' && $key !== '') {
      return ['appid' => $appid, 'mchid' => $mchid, 'key' => $key];
    }
    return NULL;
  }

  /**
   * @param array{out_trade_no: string, subject: string, amount_fen: int, notify_url: string, return_url: string} $params
   */
  private function unifiedOrderV2(array $credentials, array $params, string $channel): array {
    $body = [
      'appid' => $credentials['appid'],
      'mch_id' => $credentials['mchid'],
      'nonce_str' => Crypt::randomBytesBase64(16),
      'body' => $params['subject'],
      'out_trade_no' => $params['out_trade_no'],
      'total_fee' => (int) $params['amount_fen'],
      'spbill_create_ip' => $this->clientIp(),
      'notify_url' => $params['notify_url'],
      'trade_type' => $channel === 'native' ? 'NATIVE' : 'MWEB',
    ];
    if ($body['trade_type'] === 'MWEB') {
      $body['scene_info'] = $this->h5SceneInfo();
    }
    $body['sign'] = $this->sign($body, $credentials['key']);
    try {
      $response = $this->httpClient->request('POST', 'https://api.mch.weixin.qq.com/pay/unifiedorder', [
        'body' => $this->arrayToXml($body),
        'headers' => ['Content-Type' => 'text/xml'],
        'timeout' => 20,
      ]);
      $data = $this->xmlToArray((string) $response->getBody());
    }
    catch (\Throwable $e) {
      $this->logger->error('unifiedorder HTTP: @m', ['@m' => $e->getMessage()]);
      return ['ok' => FALSE, 'error' => 'http_failed'];
    }
    if (($data['return_code'] ?? '') !== 'SUCCESS' || ($data['result_code'] ?? '') !== 'SUCCESS') {
      $this->logger->warning('unifiedorder fail: @m', [
        '@m' => ($data['err_code_des'] ?? $data['return_msg'] ?? 'unknown'),
      ]);
      return ['ok' => FALSE, 'error' => (string) ($data['err_code'] ?? 'unifiedorder_fail')];
    }
    return [
      'ok' => TRUE,
      'prepay_id' => (string) ($data['prepay_id'] ?? ''),
      'mweb_url' => (string) ($data['mweb_url'] ?? ''),
      'code_url' => (string) ($data['code_url'] ?? ''),
    ];
  }

  private function h5SceneInfo(): string {
    $type = $this->detector->h5SceneType();
    $request = $this->requestStack->getCurrentRequest();
    $wapUrl = $request ? $request->getSchemeAndHttpHost() . '/' : 'https://www.topstar.run/';
    $info = [
      'h5_info' => [
        'type' => $type,
        'wap_url' => $wapUrl,
        'wap_name' => '跑车助手',
      ],
    ];
    return json_encode($info, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
  }

  private function notifyUrl(): string {
    try {
      return Url::fromRoute('topstar_app_pay.wechat_notify', [], ['absolute' => TRUE])->toString();
    }
    catch (\Throwable) {
      $request = $this->requestStack->getCurrentRequest();
      $host = $request ? $request->getSchemeAndHttpHost() : 'https://www.topstar.run';
      return $host . '/pay/app/notify/wechat';
    }
  }

  private function waitUrl(string $intentId): string {
    try {
      return Url::fromRoute('topstar_app_pay.wait', ['intent_id' => $intentId], ['absolute' => TRUE])->toString();
    }
    catch (\Throwable) {
      $request = $this->requestStack->getCurrentRequest();
      $host = $request ? $request->getSchemeAndHttpHost() : 'https://www.topstar.run';
      return $host . '/pay/app/wait/' . $intentId;
    }
  }

  private function makeOutTradeNo(string $product): string {
    $prefix = match ($product) {
      'membership' => 'MEM',
      'pi' => 'PI',
      'timing' => 'TM',
      default => 'PAY',
    };
    return $prefix . date('YmdHis') . substr(bin2hex(random_bytes(4)), 0, 8);
  }

  private function clientIp(): string {
    $request = $this->requestStack->getCurrentRequest();
    $ip = $request?->getClientIp() ?: '127.0.0.1';
    return $ip === '::1' ? '127.0.0.1' : $ip;
  }

  private function sign(array $data, string $key): string {
    ksort($data);
    $buf = '';
    foreach ($data as $k => $v) {
      if ($k === 'sign' || $v === '' || $v === NULL) {
        continue;
      }
      $buf .= $k . '=' . $v . '&';
    }
    $buf .= 'key=' . $key;
    return strtoupper(md5($buf));
  }

  private function verifySign(array $data, string $key): bool {
    $sign = strtoupper((string) ($data['sign'] ?? ''));
    unset($data['sign']);
    return hash_equals($sign, $this->sign($data, $key));
  }

  private function arrayToXml(array $data): string {
    $xml = '<xml>';
    foreach ($data as $k => $v) {
      $xml .= '<' . $k . '><![CDATA[' . $v . ']]></' . $k . '>';
    }
    return $xml . '</xml>';
  }

  /**
   * @return array<string, string>
   */
  private function xmlToArray(string $xml): array {
    if ($xml === '') {
      return [];
    }
    $prev = libxml_use_internal_errors(TRUE);
    $obj = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);
    libxml_use_internal_errors($prev);
    if ($obj === FALSE) {
      return [];
    }
    $json = json_encode($obj);
    $arr = is_string($json) ? json_decode($json, TRUE) : [];
    return is_array($arr) ? $arr : [];
  }

  private function settleNotify(string $outTradeNo, string $transactionId): bool {
    if ($outTradeNo === '') {
      return FALSE;
    }
    $intent = $this->loadIntentByOutTradeNo($outTradeNo);
    if ($intent === NULL) {
      return FALSE;
    }
    if (($intent->status ?? '') === 'paid') {
      return TRUE;
    }
    $this->markPaid($intent->intent_id, $transactionId);
    $this->fulfill($intent);
    return TRUE;
  }

  private function loadIntentByOutTradeNo(string $outTradeNo): ?object {
    $row = $this->database->select('topstar_app_pay_intent', 'i')
      ->fields('i')
      ->condition('out_trade_no', $outTradeNo)
      ->execute()
      ->fetchObject();
    return $row ?: NULL;
  }

  private function markPaid(string $intentId, string $transactionId): void {
    $this->database->update('topstar_app_pay_intent')
      ->fields([
        'status' => 'paid',
        'transaction_id' => $transactionId,
        'changed' => time(),
      ])
      ->condition('intent_id', $intentId)
      ->execute();
  }

  private function fulfill(object $intent): void {
    $uid = (int) $intent->uid;
    $product = (string) $intent->product;
    $plan = (string) $intent->plan;
    if ($product === 'membership' && \Drupal::hasService('car_hailing.membership')) {
      try {
        $plan = in_array($plan, ['month', 'week'], TRUE) ? $plan : 'month';
        \Drupal::service('car_hailing.membership')->activatePaid($uid, $plan);
      }
      catch (\Throwable $e) {
        $this->logger->error('membership fulfill: @m', ['@m' => $e->getMessage()]);
      }
    }
    if ($product === 'pi' && \Drupal::hasService('tpst_pi_app.pay')) {
      try {
        \Drupal::service('tpst_pi_app.pay')->markPaid((string) $intent->out_trade_no);
      }
      catch (\Throwable $e) {
        $this->logger->error('pi fulfill: @m', ['@m' => $e->getMessage()]);
      }
    }
    \Drupal::moduleHandler()->invokeAll('topstar_app_pay_paid', [$intent]);
  }

}
