<?php

declare(strict_types=1);

namespace Drupal\dx_pay\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\dx_pay\Service\OrderService;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Checkout form for a portal product.
 */
class CheckoutForm extends FormBase {

  public function __construct(
    protected OrderService $orders,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('dx_pay.orders'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'dx_pay_checkout_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    if (!$node || $node->bundle() !== 'dx_product') {
      $form['error'] = ['#markup' => $this->t('Invalid product.')];
      return $form;
    }
    $form_state->set('product_nid', (int) $node->id());

    $price = $node->hasField('field_dx_price') ? (string) $node->get('field_dx_price')->value : '0';
    $form['summary'] = [
      '#markup' => '<p><strong>' . $node->label() . '</strong> — ' . $price . ' CNY</p>',
    ];

    $options = [];
    foreach ($this->orders->gateways() as $id => $gateway) {
      $options[$id] = $gateway->label() . ($gateway->isConfigured() ? '' : ' (未配置)');
    }
    $default = $this->config('dx_pay.settings')->get('default_gateway') ?: 'wechat';
    $form['gateway'] = [
      '#type' => 'radios',
      '#title' => $this->t('支付方式'),
      '#options' => $options,
      '#default_value' => isset($options[$default]) ? $default : array_key_first($options),
      '#required' => TRUE,
    ];
    $form['buyer_mail'] = [
      '#type' => 'email',
      '#title' => $this->t('联系邮箱'),
      '#default_value' => $this->currentUser()->getEmail() ?: '',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('去支付'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $nid = (int) $form_state->get('product_nid');
    $node = \Drupal::entityTypeManager()->getStorage('node')->load($nid);
    if (!$node instanceof NodeInterface) {
      $this->messenger()->addError($this->t('Product missing.'));
      return;
    }
    try {
      $order = $this->orders->createFromProduct(
        $node,
        (string) $form_state->getValue('gateway'),
        (string) $form_state->getValue('buyer_mail'),
      );
      $started = $this->orders->startPayment($order);
      $form_state->setResponse(new RedirectResponse($started['pay_url']));
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($e->getMessage());
      $form_state->setRedirectUrl(Url::fromRoute('dx_pay.store'));
    }
  }

}
