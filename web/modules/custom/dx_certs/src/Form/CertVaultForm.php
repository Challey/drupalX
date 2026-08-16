<?php

declare(strict_types=1);

namespace Drupal\dx_certs\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Certificate vault admin form.
 */
final class CertVaultForm extends ConfigFormBase {

  public function getFormId(): string {
    return 'dx_certs_settings';
  }

  protected function getEditableConfigNames(): array {
    return ['dx_certs.settings'];
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $c = $this->config('dx_certs.settings');
    $form['vault_root'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Vault root（路径引用根）'),
      '#default_value' => $c->get('vault_root') ?: '~/staging/drupalX/certs',
      '#description' => $this->t('仅存路径引用，不在配置中写入私钥明文。'),
    ];
    $form['entries_json'] = [
      '#type' => 'textarea',
      '#title' => $this->t('证书条目 JSON'),
      '#default_value' => json_encode($c->get('entries') ?? [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
      '#rows' => 12,
      '#description' => $this->t('每项：id / platform(ios|android|wechat) / label / path_ref / expires_at。保存后可用 drush dx:certs-check 探测就绪。'),
    ];
    return parent::buildForm($form, $form_state);
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $decoded = json_decode((string) $form_state->getValue('entries_json'), TRUE);
    if (!is_array($decoded)) {
      $form_state->setErrorByName('entries_json', $this->t('JSON 无效'));
      return;
    }
    $form_state->setValue('entries_decoded', $decoded);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('dx_certs.settings')
      ->set('vault_root', (string) $form_state->getValue('vault_root'))
      ->set('entries', $form_state->getValue('entries_decoded') ?? [])
      ->save();
    parent::submitForm($form, $form_state);
  }

}
