<?php

declare(strict_types=1);

namespace Drupal\dx_copyright_guard\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\dx_copyright_guard\Service\InvestorAccess;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Authorized document library for readers and guard administrators.
 */
final class DocumentLibraryController extends ControllerBase {

  public function __construct(
    private readonly InvestorAccess $investorAccess,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('dx_copyright_guard.investor_access'),
    );
  }

  /**
   * Lists documents the current account is allowed to read.
   */
  public function build(): array|RedirectResponse {
    $account = $this->currentUser();
    if (!$this->investorAccess->hasAccess($account)) {
      return new RedirectResponse(Url::fromRoute('dx_copyright_guard.access', [], [
        'query' => [
          'destination' => Url::fromRoute('dx_copyright_guard.documents')->toString(),
        ],
      ])->toString());
    }

    $documents = [
      [
        'title' => $this->t('TPST 投资人报告'),
        'desc' => $this->t('完整版 · 网页阅读，随数据更新'),
        'meta' => $this->t('机密 · 登录并签署协议后阅读'),
        'cta' => $this->t('打开报告'),
        'url' => Url::fromUri('internal:/investor/'),
        'primary' => TRUE,
      ],
      [
        'title' => $this->t('TPST 投资人简报'),
        'desc' => $this->t('10 页速览 · 适合会议与路演'),
        'meta' => $this->t('机密 · 禁止复制、截图与转发'),
        'cta' => $this->t('打开简报'),
        'url' => Url::fromUri('internal:/investor/deck.html'),
      ],
      [
        'title' => $this->t('TPST 投资人报告 PDF'),
        'desc' => $this->t('离线阅读 · 加密文档'),
        'meta' => $this->t('机密 · 禁止复制与打印'),
        'cta' => $this->t('下载 PDF'),
        'url' => Url::fromUri('internal:/investor/TPST-INVESTOR-REPORT.pdf'),
      ],
    ];

    $build = [
      '#attached' => ['library' => ['dx_copyright_guard/investor']],
      '#prefix' => '<div class="dxcg-access dxcg-documents" id="dxcg-documents">',
      '#suffix' => '</div>',
      'heading' => [
        '#markup' => '<div class="dxcg-heading">'
          . '<div class="dxcg-kicker">DOCUMENT LIBRARY</div>'
          . '<h1 class="dxcg-title">' . $this->t('我的文档') . '</h1>'
          . '<p class="dxcg-sub">' . $this->t('这里只收录你有权限阅读的机密报告与文档，全部受版权与保密协议保护。') . '</p>'
          . '</div>',
      ],
    ];

    foreach ($documents as $index => $document) {
      $classes = ['dxcg-doc-card'];
      if (!empty($document['primary'])) {
        $classes[] = 'is-primary';
      }
      $build['doc_' . $index] = [
        '#type' => 'container',
        '#attributes' => ['class' => $classes],
        'body' => [
          '#markup' => '<div class="dxcg-doc-body">'
            . '<h2 class="dxcg-doc-title">' . $document['title'] . '</h2>'
            . '<p class="dxcg-doc-desc">' . $document['desc'] . '</p>'
            . '<p class="dxcg-doc-meta">' . $document['meta'] . '</p>'
            . '</div>',
        ],
        'action' => [
          '#type' => 'link',
          '#title' => $document['cta'],
          '#url' => $document['url'],
          '#attributes' => ['class' => ['dxcg-doc-action']],
        ],
      ];
    }

    $build['footer'] = [
      '#markup' => '<p class="dxcg-doc-foot">'
        . $this->t('如需更多资料或申请访问权限，请联系 TPST 团队。')
        . '</p>',
    ];

    return $build;
  }

}
