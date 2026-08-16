<?php

declare(strict_types=1);

namespace Drupal\dx_auth\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\dx_auth\Service\EnterpriseAccountLinker;
use Drupal\dx_auth\Service\EnterpriseIdentityService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * JSON endpoints for enterprise credit ID lookup and login (Topstar-style).
 */
class EnterpriseAuthController extends ControllerBase {

  public function __construct(
    protected EnterpriseIdentityService $identity,
    protected EnterpriseAccountLinker $linker,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('dx_auth.enterprise_identity'),
      $container->get('dx_auth.account_linker'),
    );
  }

  /**
   * GET /dx/auth/enterprise_lookup?credit_code=
   */
  public function lookup(Request $request): JsonResponse {
    $code = (string) $request->query->get('credit_code', '');
    $normalized = $this->identity->normalize($code);

    if ($normalized === '') {
      return $this->json(0, 'empty_credit_code');
    }
    if (!$this->identity->validate($normalized)) {
      return $this->json(0, 'invalid_credit_code');
    }

    $resolved = $this->identity->resolve($normalized);
    if (!$resolved['found']) {
      return $this->json(0, 'not_found', [
        'credit_code_masked' => $this->identity->mask($normalized),
      ]);
    }

    return $this->json(1, 'ok', [
      'credit_code_masked' => $resolved['credit_code_masked'],
      'company_name' => $resolved['company_name'],
      'bound' => !empty($resolved['uid']),
      'source' => $resolved['source'],
    ]);
  }

  /**
   * GET|POST /dx/auth/enterprise_login
   */
  public function login(Request $request): JsonResponse {
    $creditCode = (string) ($request->request->get('credit_code')
      ?? $request->query->get('credit_code')
      ?? '');
    $password = (string) ($request->request->get('password')
      ?? $request->query->get('password')
      ?? '');
    $destination = (string) ($request->request->get('destination')
      ?? $request->query->get('destination')
      ?? '/');

    $result = $this->linker->loginByEnterprise($creditCode, $password);
    if (!$result['ok']) {
      $messages = [
        'invalid_credit_code' => '企业ID格式不正确',
        'empty_password' => '请输入密码',
        'enterprise_not_bound' => '企业ID尚未绑定登录账号',
        'account_unavailable' => '绑定账号不可用',
        'bad_password' => '密码错误',
      ];
      $key = $result['msg'];
      return $this->json(0, $messages[$key] ?? $key);
    }

    user_login_finalize($result['user']);

    if ($destination === '' || !str_starts_with($destination, '/') || str_starts_with($destination, '//')) {
      $destination = '/';
    }

    return $this->json(1, 'ok', [
      'uid' => (int) $result['user']->id(),
      'redirect' => $destination,
    ], $destination);
  }

  /**
   * Builds a Topstar-compatible JSON payload: code / msg / data (+ redirect).
   */
  protected function json(int $code, string $msg, array $data = [], ?string $redirect = NULL): JsonResponse {
    $payload = [
      'code' => $code,
      'msg' => $msg,
      'data' => $data,
    ];
    if ($redirect !== NULL) {
      $payload['redirect'] = $redirect;
    }
    return new JsonResponse($payload);
  }

}
