<?php

declare(strict_types=1);

namespace Drupal\dx_delivery\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\dx_delivery\Entity\DeliveryBlueprint;
use Drupal\dx_delivery\Service\HandoffTodoService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Sign off one L3 handoff todo from the board (Phase F / F2).
 *
 * Writes through HandoffTodoService exactly like the JSON endpoint and the
 * drush command do, so all three surfaces keep one persistence path. Re-opening
 * the form for an already signed todo is a no-op instead of an error.
 */
final class DeliveryTodoCompleteForm extends ConfirmFormBase {

  protected ?DeliveryBlueprint $blueprint = NULL;

  protected string $todoId = '';

  /**
   * @var array<string, mixed>|null
   */
  protected ?array $todo = NULL;

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected HandoffTodoService $handoffTodos,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('dx_delivery.handoff_todos'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'dx_delivery_todo_complete';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?DeliveryBlueprint $dx_blueprint = NULL, string $todo_id = ''): array {
    $this->blueprint = $dx_blueprint;
    $this->todoId = $todo_id;
    $this->todo = NULL;

    if ($this->blueprint instanceof DeliveryBlueprint) {
      $this->todo = HandoffTodoService::find(
        $this->handoffTodos->listFromBlueprint($this->blueprint),
        $this->todoId,
      );
    }
    if ($this->todo === NULL) {
      throw new NotFoundHttpException(sprintf('Blueprint %s has no handoff todo %s', $dx_blueprint?->id(), $todo_id));
    }

    if (!HandoffTodoService::isOpen($this->todo)) {
      $form['done_note'] = [
        '#type' => 'item',
        '#markup' => $this->t('该工单已于 @at 签核，再次提交不会改动记录。', [
          '@at' => (string) ($this->todo['done_at'] ?? ''),
        ]),
      ];
    }

    $form['note'] = [
      '#type' => 'textarea',
      '#title' => $this->t('签核备注'),
      '#description' => $this->t('写进工单数组的 remark 字段，不改动蓝图表结构。'),
      '#rows' => 3,
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('确认签核工单「@todo」？', [
      '@todo' => (string) ($this->todo['title'] ?? $this->todoId),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    $lines = [];
    $lines[] = $this->t('蓝图：@blueprint（@status）', [
      '@blueprint' => (string) $this->blueprint?->label(),
      '@status' => $this->blueprint instanceof DeliveryBlueprint ? $this->blueprint->getStatus() : '',
    ]);
    $owner = trim((string) ($this->todo['owner'] ?? ''));
    $due = trim((string) ($this->todo['due'] ?? ''));
    if ($owner !== '' || $due !== '') {
      $lines[] = $this->t('负责人：@owner · 到期：@due', [
        '@owner' => $owner !== '' ? $owner : '—',
        '@due' => $due !== '' ? $due : '—',
      ]);
    }
    $notes = trim((string) ($this->todo['notes'] ?? ''));
    if ($notes !== '') {
      $lines[] = $this->t('交接说明：@notes', ['@notes' => $notes]);
    }
    return implode(' · ', $lines);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('签核完成');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('dx_delivery.todo_board', [], [
      'query' => $this->blueprint ? ['blueprint' => (string) $this->blueprint->id()] : [],
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if (!$this->blueprint instanceof DeliveryBlueprint) {
      return;
    }
    $todos = $this->handoffTodos->listFromBlueprint($this->blueprint);
    $result = $this->handoffTodos->completeBatch($todos, [$this->todoId]);
    $todos = $result['todos'];

    $note = trim((string) $form_state->getValue('note'));
    if ($note !== '') {
      $existing = trim((string) (HandoffTodoService::find($todos, $this->todoId)['remark'] ?? ''));
      $todos = $this->handoffTodos->applySla($todos, [$this->todoId], [
        'remark' => $existing === '' ? $note : $existing . ' / ' . $note,
      ])['todos'];
    }

    if ($result['completed'] !== []) {
      $this->handoffTodos->saveOnBlueprint($this->blueprint, $todos);
      $this->messenger()->addStatus($this->t('工单 @todo 已签核。', ['@todo' => $this->todoId]));
    }
    elseif ($note !== '') {
      $this->handoffTodos->saveOnBlueprint($this->blueprint, $todos);
      $this->messenger()->addStatus($this->t('工单 @todo 此前已签核，已补记备注。', ['@todo' => $this->todoId]));
    }
    else {
      $this->messenger()->addMessage($this->t('工单 @todo 此前已签核，未重复写入。', ['@todo' => $this->todoId]));
    }

    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
