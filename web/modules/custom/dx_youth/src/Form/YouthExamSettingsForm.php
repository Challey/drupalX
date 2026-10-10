<?php

declare(strict_types=1);

namespace Drupal\dx_youth\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * DX Youth exam settings form.
 */
class YouthExamSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'dx_youth_exam_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['dx_youth.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('dx_youth.settings');

    $form['exam_anonymous_access'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('允许未登录用户参加考试'),
      '#description' => $this->t('开启后，匿名用户也可以参加教育专区的在线考试。匿名用户的成绩以 uid=0 记录，无法查看历史成绩。'),
      '#default_value' => $config->get('exam_anonymous_access') ?? TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('dx_youth.settings')
      ->set('exam_anonymous_access', (bool) $form_state->getValue('exam_anonymous_access'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
