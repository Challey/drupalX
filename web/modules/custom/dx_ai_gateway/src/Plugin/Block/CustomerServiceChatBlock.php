<?php

declare(strict_types=1);

namespace Drupal\dx_ai_gateway\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a customer service AI chat block.
 *
 * @Block(
 *   id = "dx_customer_service_chat",
 *   admin_label = @Translation("DX Customer Service Chat"),
 *   category = @Translation("DrupalX")
 * )
 */
class CustomerServiceChatBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'welcome_message' => 'How can we help you today?',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state): array {
    $form = parent::blockForm($form, $form_state);
    $form['welcome_message'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Welcome message'),
      '#default_value' => $this->configuration['welcome_message'],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    $this->configuration['welcome_message'] = $form_state->getValue('welcome_message');
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    return [
      '#theme' => 'dx_ai_chat',
      '#messages' => [
        ['role' => 'assistant', 'content' => $this->configuration['welcome_message']],
      ],
      '#endpoint' => Url::fromRoute('dx_ai_gateway.chat')->toString(),
      '#stream_endpoint' => Url::fromRoute('dx_ai_gateway.chat_stream')->toString(),
      '#attached' => dx_ai_gateway_chat_attachments(),
      '#cache' => [
        'max-age' => 0,
        'contexts' => ['user', 'session'],
      ],
    ];
  }

}
