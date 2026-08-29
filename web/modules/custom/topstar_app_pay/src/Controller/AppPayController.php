<?php

declare(strict_types=1);

namespace Drupal\topstar_app_pay\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\topstar_app_pay\AppPayGateway;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * WeChat pay notify, wait page, and intent status for App/H5.
 */
final class AppPayController extends ControllerBase {

  public function __construct(
    private readonly AppPayGateway $gateway,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('topstar_app_pay.gateway'),
    );
  }

  public function wechatNotify(Request $request): Response {
    return $this->gateway->handleWechatNotify($request);
  }

  public function status(string $intent_id): JsonResponse {
    $intent = $this->gateway->loadIntent($intent_id);
    if ($intent === NULL) {
      return new JsonResponse(['ok' => FALSE, 'error' => 'not_found'], 404);
    }
    $uid = (int) $this->currentUser()->id();
    if ((int) $intent->uid > 0 && $uid > 0 && (int) $intent->uid !== $uid && !$this->currentUser()->hasPermission('administer site configuration')) {
      return new JsonResponse(['ok' => FALSE, 'error' => 'forbidden'], 403);
    }
    return new JsonResponse([
      'ok' => TRUE,
      'intent_id' => $intent->intent_id,
      'status' => $intent->status,
      'product' => $intent->product,
      'plan' => $intent->plan,
    ]);
  }

  /**
   * In-App wait page: open WeChat H5 then poll until paid.
   *
   * @return array<string, mixed>
   */
  public function wait(string $intent_id): array {
    $intent = $this->gateway->loadIntent($intent_id);
    if ($intent === NULL) {
      throw new NotFoundHttpException();
    }
    $uid = (int) $this->currentUser()->id();
    if ((int) $intent->uid > 0 && $uid > 0 && (int) $intent->uid !== $uid) {
      throw new NotFoundHttpException();
    }
    $payload = json_decode((string) $intent->payload, TRUE);
    if (!is_array($payload)) {
      $payload = [];
    }
    $mweb = (string) ($payload['mweb_url'] ?? '');
    $success = match ((string) $intent->product) {
      'pi' => '/',
      'timing' => '/',
      default => '/driver',
    };
    try {
      if ((string) $intent->product === 'membership') {
        $success = Url::fromRoute('car_hailing.home')->toString();
      }
    }
    catch (\Throwable) {
      $success = '/driver';
    }

    return [
      '#theme' => 'topstar_app_pay_wait',
      '#intent_id' => $intent_id,
      '#status' => (string) $intent->status,
      '#attached' => [
        'library' => ['topstar_app_pay/app'],
        'drupalSettings' => [
          'topstarAppPay' => [
            'intentId' => $intent_id,
            'statusUrl' => Url::fromRoute('topstar_app_pay.status', ['intent_id' => $intent_id])->toString(),
            'successUrl' => $success,
            'mwebUrl' => $mweb,
          ],
        ],
      ],
      '#cache' => ['max-age' => 0],
    ];
  }

}
