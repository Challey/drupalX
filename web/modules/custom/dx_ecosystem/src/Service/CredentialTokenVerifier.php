<?php

declare(strict_types=1);

namespace Drupal\dx_ecosystem\Service;

/**
 * Default L2TokenVerifierInterface: DrupalX's own credential store.
 *
 * Swap the `dx_ecosystem.l2_token_verifier` service out and the Composer/Git
 * endpoints, the download signer and the Drush lint keep working against an
 * external authority (Artifactory access token API, a GitLab patrol token, …).
 */
final class CredentialTokenVerifier implements L2TokenVerifierInterface {

  public function __construct(
    protected PartnerCredentialStore $credentials,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function verify(string $token): ?int {
    $verdict = $this->credentials->verifyDetailed($token);
    return $verdict['ok'] ? $verdict['uid'] : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function verifyDetailed(string $token, array $context = []): array {
    return $this->credentials->verifyDetailed($token, $context);
  }

  /**
   * {@inheritdoc}
   */
  public function name(): string {
    return 'credential';
  }

}
