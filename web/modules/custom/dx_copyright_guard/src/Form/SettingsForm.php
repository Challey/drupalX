<?php

declare(strict_types=1);

namespace Drupal\dx_copyright_guard\Form;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Copyright guard settings.
 */
final class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'dx_copyright_guard_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['dx_copyright_guard.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('dx_copyright_guard.settings');

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('启用版权保护'),
      '#default_value' => (bool) $config->get('enabled'),
      '#description' => $this->t('关闭后不拦截任何路径，配置保留。'),
    ];

    $form['paths'] = [
      '#type' => 'details',
      '#title' => $this->t('保护范围与内容目录'),
      '#open' => TRUE,
    ];
    $form['paths']['protected_paths'] = [
      '#type' => 'textarea',
      '#title' => $this->t('受保护路径'),
      '#default_value' => (string) ($config->get('protected_paths') ?? ''),
      '#rows' => 4,
      '#description' => $this->t('每行一个，例如 <code>investor</code> 与 <code>investor/*</code>。支持 <code>*</code> 通配符；匹配路径前缀。'),
    ];
    $form['paths']['source_directory'] = [
      '#type' => 'textfield',
      '#title' => $this->t('内容源目录'),
      '#default_value' => (string) ($config->get('source_directory') ?? 'private://dx_copyright'),
      '#description' => $this->t('必须是 Web 不可直接访问的目录（推荐 private:// 流包装器）。请求路径会映射到该目录下的同名文件；目录请求映射到 index.html。'),
      '#maxlength' => 512,
    ];

    $form['gate'] = [
      '#type' => 'details',
      '#title' => $this->t('访问门槛'),
      '#open' => TRUE,
    ];
    $form['gate']['login_required'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('必须登录'),
      '#default_value' => (bool) $config->get('login_required'),
    ];
    $form['gate']['acceptance_required'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('必须签署协议'),
      '#default_value' => (bool) $config->get('acceptance_required'),
    ];
    $form['gate']['quick_read_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('快速阅读（邀请链接直接阅读）'),
      '#default_value' => $config->get('quick_read_enabled') !== FALSE,
      '#description' => $this->t('开启后，持有效邀请码的分享链接可快速打开 HTML 报告；是否还需登录或签署由下面两个开关决定。页面顶部始终展示完整协议，阅读页保留专属水印。PDF 等二进制文件不适用快速阅读。'),
    ];
    $form['gate']['quick_read_require_login'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('邀请链接阅读需要登录'),
      '#default_value' => (bool) $config->get('quick_read_require_login'),
      '#description' => $this->t('开启后，通过邀请码打开报告前需先登录；登录成功会自动回到原邀请链接。默认关闭。'),
    ];
    $form['gate']['quick_read_require_signature'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('邀请链接阅读需要签署协议'),
      '#default_value' => (bool) $config->get('quick_read_require_signature'),
      '#description' => $this->t('开启后，受邀读者需先登录并签署当前版本协议（已启用签名模块时同时需要手写签名）才能阅读。此开关会同时要求登录。默认关闭。'),
    ];
    $form['gate']['quick_read_days'] = [
      '#type' => 'number',
      '#title' => $this->t('快速阅读凭证有效天数'),
      '#default_value' => (int) ($config->get('quick_read_days') ?? 7),
      '#min' => 1,
      '#max' => 90,
      '#description' => $this->t('首次打开邀请链接后，浏览器会记住该邀请码，用于继续加载报告内的 CSS/JS/图片和子页面。默认 7 天。'),
    ];
    $form['gate']['quick_read_intro'] = [
      '#type' => 'textarea',
      '#title' => $this->t('快速阅读协议引言'),
      '#default_value' => (string) ($config->get('quick_read_intro') ?? ''),
      '#rows' => 3,
      '#description' => $this->t('展示在快速阅读页面顶部的协议卡片中，说明受邀阅读与留痕方式。'),
    ];
    $form['gate']['agreement_version'] = [
      '#type' => 'textfield',
      '#title' => $this->t('协议版本'),
      '#default_value' => (string) ($config->get('agreement_version') ?? '1.0'),
      '#required' => TRUE,
      '#description' => $this->t('修改版本号后，所有访问者需重新签署。'),
    ];
    $form['gate']['agreement_title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('协议标题'),
      '#default_value' => (string) ($config->get('agreement_title') ?? ''),
      '#required' => TRUE,
    ];
    $form['gate']['agreement_intro'] = [
      '#type' => 'textarea',
      '#title' => $this->t('协议引言'),
      '#default_value' => (string) ($config->get('agreement_intro') ?? ''),
      '#rows' => 3,
    ];
    $form['gate']['agreement_terms'] = [
      '#type' => 'textarea',
      '#title' => $this->t('协议正文'),
      '#default_value' => (string) ($config->get('agreement_terms') ?? ''),
      '#rows' => 18,
      '#description' => $this->t('纯文本，换行会保留。'),
    ];
    $form['gate']['checkbox_label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('确认勾选文案'),
      '#default_value' => (string) ($config->get('checkbox_label') ?? ''),
      '#maxlength' => 512,
    ];

    $form['deterrence'] = [
      '#type' => 'details',
      '#title' => $this->t('防拷贝与水印'),
      '#open' => FALSE,
    ];
    $form['deterrence']['watermark_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('叠加身份水印'),
      '#default_value' => (bool) $config->get('watermark_enabled'),
      '#description' => $this->t('水印覆盖全页，任何截图均可追溯到访问者身份。'),
    ];
    $form['deterrence']['watermark_template'] = [
      '#type' => 'textfield',
      '#title' => $this->t('水印模板'),
      '#default_value' => (string) ($config->get('watermark_template') ?? ''),
      '#description' => $this->t('可用占位符：{name} {uid} {invite} {owner} {date} {ip} {path} {site}'),
      '#maxlength' => 255,
    ];
    $form['deterrence']['quick_watermark_template'] = [
      '#type' => 'textfield',
      '#title' => $this->t('快速阅读水印模板'),
      '#default_value' => (string) ($config->get('quick_watermark_template') ?? '{invite} · {date}'),
      '#description' => $this->t('邀请链接阅读时使用。可用占位符：{invite} {owner} {name} {uid} {date} {ip} {path} {site}。建议保留 {invite}，以便追溯分享来源。'),
      '#maxlength' => 255,
    ];
    $form['deterrence']['block_copy'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('禁止选择、复制与右键菜单'),
      '#default_value' => (bool) $config->get('block_copy'),
    ];
    $form['deterrence']['block_keys'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('拦截复制/打印/源码快捷键'),
      '#default_value' => (bool) $config->get('block_keys'),
      '#description' => $this->t('拦截 Ctrl/Cmd+C/X/S/P/U/A、F12 与 PrintScreen。'),
    ];
    $form['deterrence']['blur_on_focus_loss'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('失焦时隐藏内容'),
      '#default_value' => (bool) $config->get('blur_on_focus_loss'),
      '#description' => $this->t('切换到截图工具或其他窗口时自动模糊并遮挡，点击恢复。'),
    ];
    $form['deterrence']['scroll_guard'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('干扰自动化滚动截图'),
      '#default_value' => (bool) $config->get('scroll_guard'),
      '#description' => $this->t('检测到异常大幅滚动时短暂隐藏内容，正常阅读不受影响。'),
    ];
    $form['deterrence']['disable_print'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('禁止打印'),
      '#default_value' => (bool) $config->get('disable_print'),
    ];

    $form['investor'] = [
      '#type' => 'details',
      '#title' => $this->t('投资者访问与邀请码'),
      '#open' => TRUE,
    ];
    $form['investor']['invite_landing_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('邀请链接落地路径'),
      '#default_value' => (string) ($config->get('invite_landing_path') ?? '/investor/'),
      '#description' => $this->t('邀请链接会附加 <code>?invite=CODE</code>，例如 <code>/investor/</code>。'),
      '#maxlength' => 255,
    ];
    $form['investor']['invite_auto_count'] = [
      '#type' => 'number',
      '#title' => $this->t('自动保留的可用邀请码数量'),
      '#default_value' => (int) ($config->get('invite_auto_count') ?? 3),
      '#min' => 0,
      '#max' => 20,
      '#description' => $this->t('进入审核台时若可用邀请码少于该数量，会自动补足。'),
    ];

    $form['notice'] = [
      '#markup' => '<p class="description">' . $this->t('说明：浏览器端无法 100% 阻止操作系统级截图或录屏。本模块提供访问控制、身份水印与操作干扰，使泄露可追溯；如需更强控制，请结合水印、日志审计与法律协议。') . '</p>',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $directory = trim((string) $form_state->getValue('source_directory'));
    if ($directory === '') {
      $form_state->setErrorByName('source_directory', $this->t('内容源目录不能为空。'));
    }
    elseif ($this->fileSystem()->realpath($directory) === FALSE) {
      // A missing private:// subdirectory is created on submit; only reject
      // paths whose scheme/root itself does not exist.
      $scheme = str_contains($directory, '://') ? substr($directory, 0, strpos($directory, '://') + 3) : '';
      if ($scheme === '' || $this->fileSystem()->realpath($scheme) === FALSE) {
        $form_state->setErrorByName('source_directory', $this->t('内容源目录不存在或不可读：@dir', ['@dir' => $directory]));
      }
    }
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $directory = trim((string) $form_state->getValue('source_directory'));
    if ($directory !== '') {
      $this->fileSystem()->prepareDirectory(
        $directory,
        FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS,
      );
    }
    $this->config('dx_copyright_guard.settings')
      ->set('enabled', (bool) $form_state->getValue('enabled'))
      ->set('login_required', (bool) $form_state->getValue('login_required'))
      ->set('acceptance_required', (bool) $form_state->getValue('acceptance_required'))
      ->set('quick_read_enabled', (bool) $form_state->getValue('quick_read_enabled'))
      ->set('quick_read_require_login', (bool) $form_state->getValue('quick_read_require_login'))
      ->set('quick_read_require_signature', (bool) $form_state->getValue('quick_read_require_signature'))
      ->set('quick_read_days', max(1, min(90, (int) $form_state->getValue('quick_read_days'))))
      ->set('quick_read_intro', (string) $form_state->getValue('quick_read_intro'))
      ->set('agreement_version', (string) $form_state->getValue('agreement_version'))
      ->set('agreement_title', (string) $form_state->getValue('agreement_title'))
      ->set('agreement_intro', (string) $form_state->getValue('agreement_intro'))
      ->set('agreement_terms', (string) $form_state->getValue('agreement_terms'))
      ->set('checkbox_label', (string) $form_state->getValue('checkbox_label'))
      ->set('protected_paths', trim((string) $form_state->getValue('protected_paths')))
      ->set('source_directory', trim((string) $form_state->getValue('source_directory')))
      ->set('invite_landing_path', trim((string) $form_state->getValue('invite_landing_path')))
      ->set('invite_auto_count', (int) $form_state->getValue('invite_auto_count'))
      ->set('watermark_enabled', (bool) $form_state->getValue('watermark_enabled'))
      ->set('watermark_template', (string) $form_state->getValue('watermark_template'))
      ->set('quick_watermark_template', (string) $form_state->getValue('quick_watermark_template'))
      ->set('block_copy', (bool) $form_state->getValue('block_copy'))
      ->set('block_keys', (bool) $form_state->getValue('block_keys'))
      ->set('blur_on_focus_loss', (bool) $form_state->getValue('blur_on_focus_loss'))
      ->set('scroll_guard', (bool) $form_state->getValue('scroll_guard'))
      ->set('disable_print', (bool) $form_state->getValue('disable_print'))
      ->save();
    parent::submitForm($form, $form_state);
  }

  /**
   * File system service.
   */
  private function fileSystem(): \Drupal\Core\File\FileSystemInterface {
    return \Drupal::service('file_system');
  }

}
