<?php

declare(strict_types=1);

namespace Drupal\dx_opinion\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Opinion settings form.
 */
final class OpinionSettingsForm extends ConfigFormBase {

  public function getFormId(): string {
    return 'dx_opinion_settings';
  }

  protected function getEditableConfigNames(): array {
    return ['dx_opinion.settings'];
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('dx_opinion.settings');
    $form['keywords'] = [
      '#type' => 'textarea',
      '#title' => $this->t('监测关键词（每行一个）'),
      '#default_value' => implode("\n", $config->get('keywords') ?? []),
      '#rows' => 4,
    ];
    $form['demo_items_json'] = [
      '#type' => 'textarea',
      '#title' => $this->t('演示条目（JSON 数组）'),
      '#default_value' => json_encode($config->get('demo_items') ?? [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
      '#rows' => 16,
      '#description' => $this->t('每项含 title / source / sentiment / url。'),
    ];
    return parent::buildForm($form, $form_state);
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $raw = trim((string) $form_state->getValue('demo_items_json'));
    $decoded = json_decode($raw, TRUE);
    if (!is_array($decoded)) {
      $form_state->setErrorByName('demo_items_json', $this->t('JSON 无效。'));
      return;
    }
    $form_state->setValue('demo_items_decoded', $decoded);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $keywords = preg_split('/\R+/', (string) $form_state->getValue('keywords')) ?: [];
    $keywords = array_values(array_filter(array_map('trim', $keywords), static fn(string $k): bool => $k !== ''));
    $this->config('dx_opinion.settings')
      ->set('keywords', $keywords)
      ->set('demo_items', $form_state->getValue('demo_items_decoded') ?? [])
      ->save();
    parent::submitForm($form, $form_state);
  }

}
