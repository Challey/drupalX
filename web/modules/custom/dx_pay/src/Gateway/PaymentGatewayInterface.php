<?php

declare(strict_types=1);

namespace Drupal\dx_pay\Gateway;

/**
 * Payment gateway plugin contract.
 */
interface PaymentGatewayInterface {

  /**
   * Machine name.
   */
  public function id(): string;

  /**
   * Human label.
   */
  public function label(): string;

  /**
   * Whether credentials appear configured.
   */
  public function isConfigured(): bool;

  /**
   * Creates a payment session / redirect URL for an order row.
   *
   * @param array<string, mixed> $order
   *
   * @return array{pay_url: string, external_id: string, raw?: array}
   */
  public function createPayment(array $order, string $notifyUrl, string $returnUrl): array;

  /**
   * Verifies an asynchronous notify payload.
   *
   * @param array<string, mixed> $payload
   *
   * @return array{ok: bool, external_id?: string, message?: string}
   */
  public function verifyNotify(array $payload): array;

}
