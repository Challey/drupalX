<?php

declare(strict_types=1);

namespace Drupal\dx_ecosystem\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\dx_ecosystem\Service\Composer\ComposerHostPlan;
use Drupal\dx_ecosystem\Service\Composer\DownloadUrlSigner;
use Drupal\dx_ecosystem\Service\Composer\SatisMetadataBuilder;
use Drupal\dx_ecosystem\Service\L2ComposerRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Product switches for open ecosystem (personal registration off by default).
 */
final class EcosystemSettingsForm extends ConfigFormBase {

  public function __construct(
    protected ?L2ComposerRepository $repository = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('dx_ecosystem.l2_repository'));
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['dx_ecosystem.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'dx_ecosystem_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('dx_ecosystem.settings');
    $form['personal_registration_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable personal tenant registration (Wave P)'),
      '#description' => $this->t('O6-A: architecture reserved; keep disabled until personal platform opens.'),
      '#default_value' => (bool) $config->get('personal_registration_enabled'),
    ];
    $form['require_ral_on_install'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Require DX-RAL acknowledgment on App Store install'),
      '#default_value' => (bool) $config->get('require_ral_on_install'),
    ];
    $form['l2_composer_host'] = [
      '#type' => 'textfield',
      '#title' => $this->t('L2 Composer host'),
      '#default_value' => (string) ($config->get('l2_composer_host') ?: 'packages.drupalx.local'),
    ];
    $form['l2_git_host'] = [
      '#type' => 'textfield',
      '#title' => $this->t('L2 Git host'),
      '#default_value' => (string) ($config->get('l2_git_host') ?: 'git.drupalx.local'),
    ];

    // --- Phase I / I1: the host adapter. Everything below is switchable without
    // touching code: point it at Satis, Artifactory or a local directory.
    $form['l2_repository'] = [
      '#type' => 'details',
      '#title' => $this->t('L2 私有 Composer 仓库（Phase I / I1）'),
      '#open' => TRUE,
      '#description' => $this->t('关闭时仅保留凭证签发；开启后 Drupal 在 @path 提供 Satis 风格的 packages.json、provider 元数据与签名下载链接。', [
        '@path' => ComposerHostPlan::SERVE_PREFIX,
      ]),
    ];
    $form['l2_repository']['l2_repository_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('开启 L2 仓库适配层'),
      '#default_value' => $config->get('l2_repository_enabled') === NULL ? TRUE : (bool) $config->get('l2_repository_enabled'),
    ];
    $form['l2_repository']['l2_composer_driver'] = [
      '#type' => 'select',
      '#title' => $this->t('驱动'),
      '#options' => [
        'auto' => $this->t('自动（根据 base url 判定）'),
        ComposerHostPlan::DRIVER_SATIS => $this->t('Satis / Spress（静态 packages.json）'),
        ComposerHostPlan::DRIVER_ARTIFACTORY => $this->t('Artifactory / 企业源'),
        ComposerHostPlan::DRIVER_LOOPBACK => $this->t('本地回环（file:// 或本地目录，无网络）'),
      ],
      '#default_value' => (string) ($config->get('l2_composer_driver') ?: 'auto'),
    ];
    $form['l2_repository']['l2_composer_base_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('仓库 base url'),
      '#description' => $this->t('例：https://packages.example.com/l2、file:///srv/l2 或 /srv/l2。留空则继续用上面的占位主机（仅展示，不可下载）。'),
      '#default_value' => (string) ($config->get('l2_composer_base_url') ?? ''),
      '#maxlength' => 255,
    ];
    $form['l2_repository']['l2_repository_root'] = [
      '#type' => 'textfield',
      '#title' => $this->t('仓库根目录'),
      '#description' => $this->t('存放 packages.json 与 dist/ 的绝对路径，签名下载从这里取文件。可用 `drush dx:ecosystem-l2-repo --build=` 生成。'),
      '#default_value' => (string) ($config->get('l2_repository_root') ?? ''),
      '#maxlength' => 255,
    ];
    $form['l2_repository']['l2_token_header'] = [
      '#type' => 'select',
      '#title' => $this->t('Token 请求头'),
      '#options' => array_combine(ComposerHostPlan::TOKEN_HEADERS, ComposerHostPlan::TOKEN_HEADERS),
      '#default_value' => (string) ($config->get('l2_token_header') ?: 'authorization'),
    ];
    $form['l2_repository']['l2_dist_mode'] = [
      '#type' => 'select',
      '#title' => $this->t('Dist 交付方式'),
      '#options' => [
        L2ComposerRepository::DIST_SERVED => $this->t('served：由 Drupal 验证签名后流式返回'),
        L2ComposerRepository::DIST_ARTIFACT => $this->t('artifact：元数据里直接给 file:// 路径（离线）'),
      ],
      '#default_value' => (string) ($config->get('l2_dist_mode') ?: L2ComposerRepository::DIST_SERVED),
    ];
    $form['l2_repository']['l2_download_ttl'] = [
      '#type' => 'number',
      '#title' => $this->t('签名链接有效期（秒）'),
      '#description' => $this->t('限制在 @min–@max，0 表示默认 @default。', [
        '@min' => DownloadUrlSigner::MIN_TTL,
        '@max' => DownloadUrlSigner::MAX_TTL,
        '@default' => DownloadUrlSigner::DEFAULT_TTL,
      ]),
      '#default_value' => (int) ($config->get('l2_download_ttl') ?: DownloadUrlSigner::DEFAULT_TTL),
      '#min' => 0,
      '#max' => DownloadUrlSigner::MAX_TTL,
    ];
    $form['l2_repository']['l2_signing_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Dist 签名密钥'),
      '#description' => $this->t('至少 @n 字符。留空则从 hash_salt 推导（报表会提示“弱”）；生产建议放在 settings.php 的环境变量里。', [
        '@n' => DownloadUrlSigner::MIN_SECRET_LENGTH,
      ]),
      '#default_value' => (string) ($config->get('l2_signing_key') ?? ''),
      '#maxlength' => 128,
    ];

    $plan = $this->repository?->plan() ?? [];
    if ($plan !== []) {
      $warnings = (array) ($plan['warnings'] ?? []);
      $form['l2_repository']['plan'] = [
        '#type' => 'item',
        '#title' => $this->t('当前生效的宿主方案'),
        '#description' => $this->t('@driver · @base · @root · 元数据 @served · 审计警告 @warnings', [
          '@driver' => (string) ($plan['driver'] ?? '?'),
          '@base' => (string) ($plan['base_url'] ?? ''),
          '@root' => (string) ($plan['repository_root'] ?? '') ?: '—',
          '@served' => rtrim((string) ($plan['served_root_url'] ?? ''), '/') . '/' . SatisMetadataBuilder::ROOT_FILE,
          '@warnings' => $warnings === [] ? (string) $this->t('无') : implode('、', array_map('strval', array_keys($warnings))),
        ]),
      ];
    }
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    $key = trim((string) $form_state->getValue('l2_signing_key'));
    if ($key !== '' && strlen($key) < DownloadUrlSigner::MIN_SECRET_LENGTH) {
      $form_state->setErrorByName('l2_signing_key', $this->t('签名密钥至少 @n 字符，否则不如不填。', [
        '@n' => DownloadUrlSigner::MIN_SECRET_LENGTH,
      ]));
    }
    $root = trim((string) $form_state->getValue('l2_repository_root'));
    if ($root !== '' && (!str_starts_with($root, '/') || str_contains($root, '..'))) {
      $form_state->setErrorByName('l2_repository_root', $this->t('仓库根目录需是不含 .. 的绝对路径。'));
    }
    $baseUrl = trim((string) $form_state->getValue('l2_composer_base_url'));
    if ($baseUrl !== ''
      && !preg_match('#^(https?://|file://|/)#', $baseUrl)) {
      $form_state->setErrorByName('l2_composer_base_url', $this->t('base url 需以 http(s):// 、file:// 或 / 开头。'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('dx_ecosystem.settings')
      ->set('personal_registration_enabled', (bool) $form_state->getValue('personal_registration_enabled'))
      ->set('require_ral_on_install', (bool) $form_state->getValue('require_ral_on_install'))
      ->set('l2_composer_host', (string) $form_state->getValue('l2_composer_host'))
      ->set('l2_git_host', (string) $form_state->getValue('l2_git_host'))
      ->set('l2_repository_enabled', (bool) $form_state->getValue('l2_repository_enabled'))
      ->set('l2_composer_driver', (string) $form_state->getValue('l2_composer_driver'))
      ->set('l2_composer_base_url', trim((string) $form_state->getValue('l2_composer_base_url')))
      ->set('l2_repository_root', trim((string) $form_state->getValue('l2_repository_root')))
      ->set('l2_token_header', strtolower(trim((string) $form_state->getValue('l2_token_header'))))
      ->set('l2_dist_mode', (string) $form_state->getValue('l2_dist_mode'))
      ->set('l2_download_ttl', (int) $form_state->getValue('l2_download_ttl'))
      ->set('l2_signing_key', trim((string) $form_state->getValue('l2_signing_key')))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
