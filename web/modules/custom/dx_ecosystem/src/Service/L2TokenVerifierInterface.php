<?php

declare(strict_types=1);

namespace Drupal\dx_ecosystem\Service;

/**
 * Pluggable gate for `dxl2_` tokens used by the L2 Composer/Git endpoints.
 *
 * The repository layer (HTTP subscriber, dist signer, Drush) only ever talks to
 * this interface, so pointing DrupalX at a real Satis / Artifactory / Git host
 * means registering another implementation (e.g. one that asks Artifactory's
 * token API) without touching the callers. Services alias
 * `dx_ecosystem.l2_token_verifier` decides which one is live.
 */
interface L2TokenVerifierInterface {

  /**
   * The uid owning the token, or NULL when it must not be served.
   */
  public function verify(string $token): ?int;

  /**
   * Same decision plus a stable machine code and a human message.
   *
   * @param array<string, mixed> $context
   *   ip / user_agent / path of the call being authorised, for the audit trail.
   *
   * @return array{
   *   ok: bool,
   *   uid: int|null,
   *   code: string,
   *   state: string,
   *   message: string,
   *   prefix: string
   * }
   */
  public function verifyDetailed(string $token, array $context = []): array;

  /**
   * Identifier shown in reports and logs ("credential", "artifactory", …).
   */
  public function name(): string;

}
