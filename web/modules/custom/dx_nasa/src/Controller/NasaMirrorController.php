<?php

/**
 * @file
 * Controller for the /nasa mirror subsite.
 */

declare(strict_types=1);

namespace Drupal\dx_nasa\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Url;
use Drupal\dx_nasa\Service\NasaFetcher;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Renders the bilingual NASA mirror under /nasa.
 */
class NasaMirrorController extends ControllerBase {

  public function __construct(
    protected Connection $database,
    protected NasaFetcher $fetcher,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('dx_nasa.fetcher'),
    );
  }

  /**
   * /nasa — mirror home: sections overview, stats, latest APOD.
   */
  public function home(): array {
    $seed = $this->fetcher->seedConfig();
    $stats = $this->database->select('dx_nasa_page', 'p')
      ->fields('p', ['lang', 'status'])
      ->condition('p.slug', $this->fetcher->seedPaths(), 'IN')
      ->execute();
    $counts = ['en' => 0, 'zh' => 0];
    $okByLang = ['en' => 0, 'zh' => 0];
    foreach ($stats as $row) {
      $lang = (string) $row->lang;
      $counts[$lang] = ($counts[$lang] ?? 0) + 1;
      if ((int) $row->status === 1) {
        $okByLang[$lang] = ($okByLang[$lang] ?? 0) + 1;
      }
    }

    $sections = [];
    foreach ($seed['sections'] ?? [] as $key => $section) {
      $pages = [];
      foreach ($section['pages'] ?? [] as $path) {
        $en = $this->fetcher->loadRow((string) $path, 'en');
        $zh = $this->fetcher->loadRow((string) $path, 'zh');
        $pages[] = [
          'path' => (string) $path,
          'url' => $this->mirrorUrl('en', (string) $path),
          'url_zh' => ((int) ($zh->status ?? 0) === 1)
            ? $this->mirrorUrl('zh', (string) $path)
            : '',
          'title' => (string) ($en->title ?? $this->humanizeSlug((string) $path)),
          'has_en' => (bool) $en,
          'has_zh' => (int) ($zh->status ?? 0) === 1,
        ];
      }
      $sections[] = [
        'key' => (string) $key,
        'label' => (string) ($section['label'] ?? $key),
        'pages' => $pages,
      ];
    }

    $apod = $this->mirrorRow('apod', 'zh') ?? $this->mirrorRow('apod', 'en');
    $apodData = NULL;
    if ($apod) {
      $apodData = [
        'url' => Url::fromRoute('dx_nasa.apod')->toString(),
        'title' => (string) $apod->title,
        'image' => (string) ($apod->image ?? ''),
        'lang' => (string) $apod->lang,
      ];
    }

    $seedTotal = count($this->fetcher->seedPaths());
    return [
      '#theme' => 'dx_nasa_home',
      '#sections' => $sections,
      '#apod' => $apodData,
      '#total_en' => $okByLang['en'],
      '#total_zh' => $okByLang['zh'],
      '#seed_total' => $seedTotal,
      '#attached' => ['library' => ['dx_nasa/mirror']],
      '#cache' => ['max-age' => 900, 'tags' => ['dx_nasa_page']],
    ];
  }

  /**
   * /nasa/apod — astronomy picture of the day.
   */
  public function apodPage(): array {
    return $this->renderMirrorPage('apod', 'zh');
  }

  /**
   * Renders a mirror page at any slug depth.
   *
   * Multi-segment slugs are split across the dx_nasa.view / view2 / view3 /
   * view4 routes (one route per depth); reassemble them here.
   */
  public function viewPage(string $lang, string $slug, ?string $slug2 = NULL, ?string $slug3 = NULL, ?string $slug4 = NULL): array {
    $slug = implode('/', array_filter([$slug, $slug2, $slug3, $slug4], static fn(?string $s) => $s !== NULL && $s !== ''));
    $slug = trim($slug, '/');
    return $this->renderMirrorPage($slug, $lang);
  }

  /**
   * Title callback for /nasa/{lang}/{slug...}.
   */
  public function pageTitle(string $lang, string $slug, ?string $slug2 = NULL, ?string $slug3 = NULL, ?string $slug4 = NULL): string {
    $slug = implode('/', array_filter([$slug, $slug2, $slug3, $slug4], static fn(?string $s) => $s !== NULL && $s !== ''));
    $slug = trim($slug, '/');
    $row = $this->mirrorRow($slug, $lang);
    if (!$row) {
      $fallback = $this->mirrorRow($slug, 'en');
      return (string) ($fallback->title ?? 'NASA 宇宙镜像');
    }
    return (string) $row->title;
  }

  /**
   * Shared renderer for a mirror page (used by APOD and generic pages).
   */
  protected function renderMirrorPage(string $slug, string $lang): array {
    $row = $this->mirrorRow($slug, $lang);
    $isTranslated = TRUE;
    if (!$row || (int) $row->status !== 1) {
      // ZH missing/pending -> fall back to EN content with a notice.
      $en = $this->mirrorRow($slug, 'en');
      if (!$en) {
        throw new NotFoundHttpException();
      }
      $row = $en;
      $isTranslated = FALSE;
    }

    $otherLang = $lang === 'zh' ? 'en' : 'zh';
    $other = $this->mirrorRow($slug, $otherLang);
    $otherUrl = '';
    if ($other && (int) $other->status === 1) {
      $otherUrl = $this->mirrorUrl($otherLang, $slug);
    }

    $body = (string) $row->body_html;
    // Resolve the internal-link placeholder per language.
    $body = str_replace('/NASA_BASE/', '/nasa/' . $lang . '/', $body);

    $vars = [
      '#theme' => $slug === 'apod' ? 'dx_nasa_apod' : 'dx_nasa_page',
      '#lang' => $lang,
      '#other_lang' => $otherUrl !== '' ? $otherLang : '',
      '#other_url' => $otherUrl,
      '#title' => (string) $row->title,
      '#body_html' => [
        '#markup' => $body,
        '#allowed_tags' => [
          'a', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'table',
          'thead', 'tbody', 'tr', 'td', 'th', 'figure', 'figcaption', 'img', 'strong',
          'em', 'b', 'i', 'blockquote', 'hr', 'br', 'code', 'pre', 'span', 'div', 'caption',
        ],
      ],
      '#source_url' => (string) $row->source_url,
      '#section' => (string) $row->section,
      '#fetched_at' => (int) $row->fetched_at,
      '#is_translated' => $isTranslated,
      '#attached' => ['library' => ['dx_nasa/mirror']],
      '#cache' => [
        'max-age' => 900,
        'tags' => ['dx_nasa_page:' . $slug . ':' . $lang],
      ],
    ];
    if ($slug === 'apod') {
      $vars['#image_url'] = (string) ($row->image ?? '');
    }
    return $vars;
  }

  /**
   * Loads a mirror row when its content is usable.
   */
  protected function mirrorRow(string $slug, string $lang): ?\stdClass {
    $row = $this->fetcher->loadRow($slug, $lang);
    return ($row && trim((string) $row->body_html) !== '') ? $row : NULL;
  }

  /**
   * Humanizes a slug path for not-yet-fetched seed entries.
   */
  protected function humanizeSlug(string $path): string {
    $last = substr($path, (int) strrpos($path, '/') + 1);
    return ucwords(str_replace('-', ' ', $last));
  }

  /**
   * Builds a mirror page URL for a slug of any depth.
   *
   * Multi-segment slugs span the dx_nasa.view / view2 / view3 / view4 routes,
   * so URLs are assembled directly instead of through UrlGenerator (which
   * validates placeholders against a single route's requirement).
   */
  protected function mirrorUrl(string $lang, string $slug): string {
    return '/nasa/' . $lang . '/' . trim($slug, '/');
  }

}
