<?php

declare(strict_types=1);

namespace Drupal\dx_oss\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * OSS / COS one-click settings form.
 */
class OssSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['dx_oss.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'dx_oss_settings_form';
  }

  /**
   * OSS manager service.
   */
  protected function manager(): \Drupal\dx_oss\Service\OssManager {
    return \Drupal::service('dx_oss.manager');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('dx_oss.settings');

    $form['checklist'] = [
      '#type' => 'details',
      '#title' => $this->t('Enablement checklist'),
      '#open' => TRUE,
    ];
    $items = [];
    foreach ($this->manager()->checklist() as $row) {
      $items[] = ($row['done'] ? '[x] ' : '[ ] ') . $row['label'] . ' — ' . $row['detail'];
    }
    $form['checklist']['list'] = [
      '#theme' => 'item_list',
      '#items' => $items,
    ];

    $form['provider'] = [
      '#type' => 'select',
      '#title' => $this->t('Provider'),
      '#options' => [
        'aliyun' => $this->t('Aliyun OSS'),
        'tencent' => $this->t('Tencent COS'),
      ],
      '#default_value' => $config->get('provider') ?: 'aliyun',
    ];
    $form['bucket'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Bucket'),
      '#default_value' => $config->get('bucket') ?: '',
    ];
    $form['endpoint'] = [
      '#type' => 'url',
      '#title' => $this->t('Endpoint'),
      '#default_value' => $config->get('endpoint') ?: '',
      '#description' => $this->t('Example: https://oss-cn-hangzhou.aliyuncs.com or https://cos.ap-guangzhou.myqcloud.com'),
    ];
    $form['prefix'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Object key prefix'),
      '#default_value' => $config->get('prefix') ?: 'drupalx/',
    ];
    $form['access_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Access key'),
      '#default_value' => '',
      '#description' => $this->t('Stored in State (not config export). Leave blank to keep existing.'),
    ];
    $form['secret_key'] = [
      '#type' => 'password',
      '#title' => $this->t('Secret key'),
    ];
    $form['use_for_public'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Prefer OSS for public files (when stream wrapper available)'),
      '#default_value' => (bool) $config->get('use_for_public'),
    ];
    $form['use_for_private'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Prefer OSS for private files (when stream wrapper available)'),
      '#default_value' => (bool) $config->get('use_for_private'),
    ];

    $form['actions']['test'] = [
      '#type' => 'submit',
      '#value' => $this->t('Test connection'),
      '#submit' => ['::submitTest'],
      '#limit_validation_errors' => [],
      '#weight' => 20,
    ];
    $form['actions']['enable'] = [
      '#type' => 'submit',
      '#value' => $this->t('Enable pack'),
      '#submit' => ['::submitEnable'],
      '#weight' => 21,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('dx_oss.settings')
      ->set('provider', (string) $form_state->getValue('provider'))
      ->set('bucket', (string) $form_state->getValue('bucket'))
      ->set('endpoint', rtrim((string) $form_state->getValue('endpoint'), '/'))
      ->set('prefix', (string) $form_state->getValue('prefix'))
      ->set('use_for_public', (bool) $form_state->getValue('use_for_public'))
      ->set('use_for_private', (bool) $form_state->getValue('use_for_private'))
      ->save();
    $this->manager()->setCredentials(
      (string) $form_state->getValue('access_key'),
      (string) $form_state->getValue('secret_key'),
    );
    parent::submitForm($form, $form_state);
  }

  /**
   * Tests connectivity.
   */
  public function submitTest(array &$form, FormStateInterface $form_state): void {
    $this->submitForm($form, $form_state);
    $result = $this->manager()->testConnection();
    if ($result['ok']) {
      $this->messenger()->addStatus($result['message']);
    }
    else {
      $this->messenger()->addError($result['message']);
    }
  }

  /**
   * Enables the pack when checklist basics pass.
   */
  public function submitEnable(array &$form, FormStateInterface $form_state): void {
    $this->submitForm($form, $form_state);
    try {
      $this->manager()->enablePack();
      $this->messenger()->addStatus($this->t('OSS pack enabled. Install s3fs/flysystem via App Store to bind stream wrappers.'));
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($e->getMessage());
    }
  }

}
