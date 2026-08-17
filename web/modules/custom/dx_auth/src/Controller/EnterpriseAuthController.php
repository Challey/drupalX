<?php

declare(strict_types=1);

namespace Drupal\dx_auth\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
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
    protected FloodInterface $flood,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('dx_auth.enterprise_identity'),
      $container->get('dx_auth.account_linker'),
      $container->get('flood'),
    );
  }

  /**
   * GET /dx/auth/enterprise_lookup?credit_code=
   */
  public function lookup(Request $request): JsonResponse {
    $ip = $request->getClientIp() ?: '0.0.0.0';
    if (!$this->flood->isAllowed('dx_auth.enterprise_lookup', 40, 3600, $ip)) {
      return $this->json(0, '尝试过多，请稍后再试');
    }
    $this->flood->register('dx_auth.enterprise_lookup', 3600, $ip);

    $code = (string) $request->query->get('credit_code', '');
    $normalized = $this->identity->normalize($code);

    if ($normalized === '') {
      return $this->json(0, '请输入企业信用代码');
    }
    if (!$this->identity->validate($normalized)) {
      return $this->json(0, '企业信用代码格式不正确');
    }

    $resolved = $this->identity->resolve($normalized);
    if (!$resolved['found']) {
      return $this->json(0, '未找到该企业，请核对市场监督局登记的信用代码', [
        'credit_code_masked' => $this->identity->mask($normalized),
      ]);
    }

    return $this->json(1, 'ok', [
      'credit_code_masked' => $resolved['credit_code_masked'],
      'company_name' => $resolved['company_name_masked'],
    ]);
  }

  /**
   * POST /dx/auth/enterprise_login
   */
  public function login(Request $request): JsonResponse {
    $creditCode = (string) ($request->request->get('credit_code') ?? '');
    $password = (string) ($request->request->get('password') ?? '');
    $destination = (string) ($request->request->get('destination') ?? '/');

    $ip = $request->getClientIp() ?: '0.0.0.0';
    $idKey = $this->identity->normalize($creditCode) ?: 'unknown';
    if (!$this->flood->isAllowed('dx_auth.enterprise_login_ip', 20, 3600, $ip)) {
      return $this->json(0, '尝试次数过多，请一小时后再试');
    }
    if (!$this->flood->isAllowed('dx_auth.enterprise_login_id', 8, 3600, $idKey)) {
      return $this->json(0, '该企业尝试次数过多，请稍后再试');
    }

    $result = $this->linker->loginByEnterprise($creditCode, $password);
    if (!empty($result['action']) && $result['action'] === 'portal_redirect' && !empty($result['redirect'])) {
      return $this->json(2, '请前往企业专属门户登录', [], $result['redirect']);
    }

    if (empty($result['ok'])) {
      $this->flood->register('dx_auth.enterprise_login_ip', 3600, $ip);
      $this->flood->register('dx_auth.enterprise_login_id', 3600, $idKey);
      $messages = [
        'invalid_credit_code' => '企业ID格式不正确',
        'empty_password' => '请输入密码',
        'enterprise_not_bound' => '企业ID尚未绑定登录账号',
        'account_unavailable' => '绑定账号不可用',
        'bad_password' => '密码错误',
        'portal_unavailable' => '企业门户尚未开通，请联系平台管理员',
      ];
      $key = $result['msg'] ?? 'login_failed';
      return $this->json(0, $messages[$key] ?? $key);
    }

    user_login_finalize($result['user']);
    \Drupal::service('session')->save();

    if ($destination === '' || !str_starts_with($destination, '/') || str_starts_with($destination, '//') || str_contains($destination, '/user/login')) {
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
