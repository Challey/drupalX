<?php

declare(strict_types=1);

namespace Drupal\dx_channel\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\dx_channel\Service\AppLayoutRepository;
use Drupal\dx_channel\Service\ChannelAuth;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Channel settings: layout profile and token list (hashes only).
 */
final class ChannelSettingsForm extends ConfigFormBase {

  public function __construct(
    protected ChannelAuth $auth,
    protected AppLayoutRepository $appLayout,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('dx_channel.auth'),
      $container->get('dx_channel.app_layout'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'dx_channel_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['dx_channel.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('dx_channel.settings');

    $form['layout_profile'] = [
      '#type' => 'select',
      '#title' => $this->t('Layout profile'),
      '#options' => [
        'gov_default' => $this->t('Government default'),
        'ent_default' => $this->t('Enterprise default'),
      ],
      '#default_value' => $config->get('layout_profile') ?: 'gov_default',
      '#description' => $this->t('L1 app-layout template for Flutter / mini-program shells.'),
    ];

    $form['min_shell_version'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Minimum shell version'),
      '#default_value' => $config->get('min_shell_version') ?: '1.0.0',
      '#required' => TRUE,
    ];

    $form['capabilities'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Capabilities (comma-separated)'),
      '#default_value' => implode(',', $config->get('capabilities') ?: ['share']),
      '#description' => $this->t('Pre-built shell capabilities to enable, e.g. share,push.'),
    ];

    $form['revision_info'] = [
      '#type' => 'item',
      '#title' => $this->t('Current layout revision'),
      '#markup' => '<code>' . (int) $this->appLayout->getRevision() . '</code>',
    ];

    $form['bump_revision'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Bump revision on save'),
      '#default_value' => TRUE,
      '#description' => $this->t('Clients poll with since_revision; bump so shells refresh L1.'),
    ];

    $rows = [];
    foreach ($this->auth->listTokens() as $token) {
      $rows[] = [
        $token['id'] ?? '',
        implode(', ', $token['scopes'] ?? []),
        !empty($token['created']) ? date('c', (int) $token['created']) : '—',
      ];
    }
    $form['tokens'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Token id'),
        $this->t('Scopes'),
        $this->t('Created'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No tokens. Run: drush dx:channel-token-create'),
    ];

    $form['help'] = [
      '#type' => 'item',
      '#markup' => '<p>' . $this->t('Channel APIs require <code>Authorization: Bearer &lt;token&gt;</code> (D10-B). Create tokens with Drush; plaintext is shown once.') . '</p>',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $caps = array_values(array_filter(array_map('trim', explode(',', (string) $form_state->getValue('capabilities')))));
    $this->config('dx_channel.settings')
      ->set('layout_profile', $form_state->getValue('layout_profile'))
      ->set('min_shell_version', $form_state->getValue('min_shell_version'))
      ->set('capabilities', $caps)
      ->save();

    if ($form_state->getValue('bump_revision')) {
      $this->appLayout->bumpRevision();
    }

    parent::submitForm($form, $form_state);
  }

}
