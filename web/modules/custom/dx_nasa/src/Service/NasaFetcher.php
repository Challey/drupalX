<?php

/**
 * @file
 * Fetcher service: pulls the NASA universe education subset into local mirror.
 */

declare(strict_types=1);

namespace Drupal\dx_nasa\Service;

use Drupal\Component\Utility\Html;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Component\Datetime\TimeInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Fetches NASA WordPress topic content, sanitizes, localizes, translates.
 */
class NasaFetcher {

  protected const APOD_MAX_AGE = 20 * 3600;
  protected const SEED_MAX_AGE = 7 * 86400;
  protected const CRON_SEED_BATCH = 5;
  protected const MAX_IMAGE_BYTES = 8 * 1024 * 1024;

  protected const STATE_APOD = 'dx_nasa.last_apod';
  protected const STATE_SEED = 'dx_nasa.last_seed';

  protected ?array $seedConfig = NULL;

  public function __construct(
    protected Connection $database,
    protected ClientInterface $httpClient,
    protected FileSystemInterface $fileSystem,
    protected FileUrlGeneratorInterface $fileUrlGenerator,
    protected StateInterface $state,
    protected TimeInterface $time,
    protected NasaTranslator $translator,
    protected LoggerChannelInterface $logger,
  ) {}

  /**
   * Loads config/nasa-seed.json.
   */
  public function seedConfig(): array {
    if ($this->seedConfig === NULL) {
      $path = __DIR__ . '/../../config/nasa-seed.json';
      $data = is_file($path) ? json_decode((string) file_get_contents($path), TRUE) : [];
      $this->seedConfig = is_array($data) ? $data : [];
    }
    return $this->seedConfig;
  }

  /**
   * Cron entry: refresh APOD daily, seed pages weekly in small batches.
   */
  public function cronTick(): void {
    $now = $this->time->getRequestTime();

    $lastApod = (int) $this->state->get(self::STATE_APOD, 0);
    if ($now - $lastApod > self::APOD_MAX_AGE) {
      try {
        $this->fetchApod();
        $this->state->set(self::STATE_APOD, $this->time->getRequestTime());
      }
      catch (\Throwable $e) {
        $this->logger->warning('APOD cron fetch failed: @msg', [
          '@msg' => $e->getMessage(),
        ]);
      }
    }

    $allFresh = $this->fetchStaleSeed(self::CRON_SEED_BATCH);
    if ($allFresh) {
      $this->state->set(self::STATE_SEED, $this->time->getRequestTime());
    }
  }

  /**
   * Fetches the APOD topic page (current day content).
   */
  public function fetchApod(bool $force = FALSE): bool {
    return $this->fetchPage('apod', 'apod', $force);
  }

  /**
   * Fetches all seed pages from the manifest.
   */
  public function fetchSeed(bool $force = FALSE, ?array $onlyPaths = NULL): array {
    $stats = ['ok' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];
    foreach ($this->seedConfig()['sections'] ?? [] as $key => $section) {
      foreach ($section['pages'] ?? [] as $path) {
        if ($onlyPaths !== NULL && !in_array($path, $onlyPaths, TRUE)) {
          continue;
        }
        try {
          $r = $this->fetchPage($path, (string) $key, $force);
          $stats[$r ? 'ok' : 'skipped']++;
        }
        catch (\Throwable $e) {
          $stats['failed']++;
          $stats['errors'][] = $path . ': ' . $e->getMessage();
          $this->logger->warning('Seed fetch failed for @path: @msg', [
            '@path' => $path,
            '@msg' => $e->getMessage(),
          ]);
        }
      }
    }
    return $stats;
  }

  /**
   * Refreshes up to $limit stale seed pages (oldest first).
   *
   * @return bool
   *   TRUE when every seed page is now fresh (<= SEED_MAX_AGE).
   */
  public function fetchStaleSeed(int $limit = 5): bool {
    $cutoff = $this->time->getRequestTime() - self::SEED_MAX_AGE;
    $paths = $this->seedPaths();
    if (!$paths) {
      return TRUE;
    }
    $rows = $this->database->select('dx_nasa_page', 'p')
      ->fields('p', ['slug', 'fetched_at'])
      ->condition('p.lang', 'en')
      ->condition('p.slug', $paths, 'IN')
      ->orderBy('p.fetched_at', 'ASC')
      ->execute()
      ->fetchAllAssoc('slug');
    $stale = [];
    foreach ($paths as $path) {
      $fetched = (int) ($rows[$path]->fetched_at ?? 0);
      if ($fetched < $cutoff) {
        $stale[] = $path;
      }
    }
    if (!$stale) {
      return TRUE;
    }
    $batch = array_slice($stale, 0, $limit);
    $sectionOf = $this->sectionOfPaths($batch);
    foreach ($batch as $path) {
      try {
        $this->fetchPage($path, $sectionOf[$path] ?? 'universe', FALSE);
      }
      catch (\Throwable $e) {
        $this->logger->warning('Stale seed refresh failed for @path: @msg', [
          '@path' => $path,
          '@msg' => $e->getMessage(),
        ]);
      }
    }
    return FALSE;
  }

  /**
   * All seed page paths, flattened across sections.
   */
  public function seedPaths(): array {
    $paths = [];
    foreach ($this->seedConfig()['sections'] ?? [] as $section) {
      foreach ($section['pages'] ?? [] as $path) {
        $paths[] = (string) $path;
      }
    }
    return $paths;
  }

  /**
   * Maps seed paths to their section keys.
   */
  protected function sectionOfPaths(array $paths): array {
    $map = [];
    foreach ($this->seedConfig()['sections'] ?? [] as $key => $section) {
      foreach ($section['pages'] ?? [] as $path) {
        if (in_array($path, $paths, TRUE)) {
          $map[$path] = (string) $key;
        }
      }
    }
    return $map;
  }

  /**
   * Fetches one NASA topic page by source path (no leading/trailing slash).
   *
   * @return bool
   *   TRUE when new EN content was stored, FALSE when unchanged (skipped).
   */
  public function fetchPage(string $path, string $section, bool $force = FALSE): bool {
    $path = trim($path, '/');
    $items = $this->queryTopic(basename($path));
    $match = NULL;
    $expected = 'https://science.nasa.gov/' . $path;
    foreach ($items as $item) {
      if (rtrim((string) ($item['link'] ?? ''), '/') === $expected) {
        $match = $item;
        break;
      }
    }
    if ($match === NULL) {
      throw new \RuntimeException('Topic not found in NASA REST: ' . $path);
    }

    $titleEn = Html::decodeEntities(trim(strip_tags((string) ($match['title']['rendered'] ?? ''))));
    $clean = $this->sanitize((string) ($match['content']['rendered'] ?? ''));
    $clean = $this->localizeImages($clean);
    $clean = $this->rewriteLinks($clean);
    if (trim($clean) === '') {
      throw new \RuntimeException('Empty content for ' . $path);
    }

    $hash = md5($clean);
    $now = $this->time->getRequestTime();
    $existing = $this->loadRow($path, 'en');
    $image = '';
    if (preg_match('/<img\b[^>]*\bsrc\s*=\s*"([^"]+)"/i', $clean, $imgMatch)) {
      $image = (string) $imgMatch[1];
    }

    if ($existing && $existing->content_hash === $hash && !$force) {
      // Touch fetched_at so freshness tracking works, keep content.
      $this->database->update('dx_nasa_page')
        ->fields(['fetched_at' => $now])
        ->condition('id', $existing->id)
        ->execute();
      $zh = $this->loadRow($path, 'zh');
      if ($zh && $zh->content_hash === $hash && (int) $zh->status === 1) {
        return FALSE;
      }
    }
    else {
      $this->upsertRow($path, 'en', $section, $titleEn, $clean, $hash, $now, 1, $image, $this->sourceUrl($path));
    }

    // Translate (or re-translate when EN changed / ZH pending).
    $zh = $this->loadRow($path, 'zh');
    if ($zh && $zh->content_hash === $hash && (int) $zh->status === 1 && !$force) {
      return TRUE;
    }
    $this->translateRow($path, $section, $titleEn, $clean, $hash, $now, $this->sourceUrl($path), $image);
    return TRUE;
  }

  /**
   * Canonical source URL for a mirror slug.
   */
  protected function sourceUrl(string $path): string {
    return 'https://science.nasa.gov/' . trim($path, '/') . '/';
  }

  /**
   * Queries the NASA WordPress REST API for a topic by slug.
   */
  protected function queryTopic(string $slug): array {
    $rest = (string) ($this->seedConfig()['rest_base'] ?? 'https://science.nasa.gov/wp-json/wp/v2');
    $response = $this->httpClient->request('GET', $rest . '/topic', [
      'query' => [
        'slug' => $slug,
        'per_page' => 20,
        '_fields' => 'id,slug,link,title,content',
      ],
      'timeout' => 30,
      'headers' => ['User-Agent' => 'DrupalX-Edu-Mirror/1.0 (+educational mirror)'],
    ]);
    $data = json_decode((string) $response->getBody(), TRUE);
    return is_array($data) ? $data : [];
  }

  /**
   * Strips scripts, embeds, forms, dangerous attributes from WP content.
   */
  protected function sanitize(string $html): string {
    $html = preg_replace('/<!--.*?-->/s', '', $html) ?? '';
    foreach (['script', 'style', 'noscript', 'form', 'iframe'] as $tag) {
      $html = preg_replace('/<' . $tag . '\b[^>]*>.*?<\/' . $tag . '>/is', '', $html) ?? '';
    }
    // Standalone opening tags (bad markup safety).
    foreach (['script', 'style', 'iframe'] as $tag) {
      $html = preg_replace('/<\/?' . $tag . '\b[^>]*>/i', '', $html) ?? '';
    }
    // Inline handlers, styles and responsive image attrs.
    $html = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\')/i', '', $html) ?? '';
    $html = preg_replace('/\sstyle\s*=\s*("[^"]*"|\'[^\']*\')/i', '', $html) ?? '';
    $html = preg_replace('/\ssrcset\s*=\s*("[^"]*"|\'[^\']*\')/i', '', $html) ?? '';
    $html = preg_replace('/\ssizes\s*=\s*("[^"]*"|\'[^\']*\')/i', '', $html) ?? '';
    // WP emoji / placeholder paragraphs.
    $html = preg_replace('/<p>\s*<\/p>/i', '', $html) ?? '';
    return trim($html);
  }

  /**
   * Downloads remote images into public://nasa/assets and rewrites src.
   */
  protected function localizeImages(string $html): string {
    if (!preg_match_all('/<img\b[^>]*>/i', $html, $tags)) {
      return $html;
    }
    foreach ($tags[0] as $tag) {
      if (!preg_match('/\bsrc\s*=\s*"(https?:\/\/[^"]+)"/i', $tag, $m)) {
        continue;
      }
      $remote = $m[1];
      // Skip data URIs and tiny tracking pixels.
      if (stripos($remote, 'data:') === 0) {
        continue;
      }
      $local = $this->downloadAsset($remote);
      if ($local === NULL) {
        continue;
      }
      $newTag = preg_replace('/\bsrc\s*=\s*"[^"]*"/i', 'src="' . $local . '"', $tag) ?? $tag;
      $html = str_replace($tag, $newTag, $html);
    }
    return $html;
  }

  /**
   * Downloads one asset; returns local URL or NULL on failure/oversize.
   */
  protected function downloadAsset(string $url): ?string {
    $path = parse_url($url, PHP_URL_PATH) ?: '';
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], TRUE)) {
      return NULL;
    }
    $dir = 'public://nasa/assets';
    $this->fileSystem->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $name = md5($url) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
    $uri = $dir . '/' . $name;
    if (is_file($this->fileSystem->realpath($uri) ?: '')) {
      return $this->fileUrlGenerator->generateString($uri);
    }
    try {
      $response = $this->httpClient->request('GET', $url, [
        'timeout' => 60,
        'headers' => ['User-Agent' => 'DrupalX-Edu-Mirror/1.0'],
      ]);
    }
    catch (GuzzleException $e) {
      $this->logger->warning('Asset download failed @url: @msg', [
        '@url' => $url,
        '@msg' => $e->getMessage(),
      ]);
      return NULL;
    }
    $body = (string) $response->getBody();
    if ($body === '' || strlen($body) > self::MAX_IMAGE_BYTES) {
      return NULL;
    }
    $this->fileSystem->saveData($body, $uri, FileSystemInterface::EXISTS_REPLACE);
    return $this->fileUrlGenerator->generateString($uri);
  }

  /**
   * Rewrites NASA-internal links to the /NASA_BASE/ placeholder.
   *
   * The controller replaces /NASA_BASE/ with /nasa/{lang}/ at render time so
   * EN and ZH pages link within their own language.
   */
  protected function rewriteLinks(string $html): string {
    $html = preg_replace_callback(
      '/href\s*=\s*"(https:\/\/science\.nasa\.gov\/([^"#?]+)\/?)"/i',
      function (array $m): string {
        $path = trim($m[2], '/');
        if (preg_match('/^(universe|kids|apod)(\/|$)/', $path)) {
          return 'href="/NASA_BASE/' . $path . '"';
        }
        return $m[0];
      },
      $html,
    ) ?? $html;
    return $html;
  }

  /**
   * Loads one mirror row by slug + lang.
   */
  public function loadRow(string $slug, string $lang): ?\stdClass {
    $row = $this->database->select('dx_nasa_page', 'p')
      ->fields('p')
      ->condition('p.slug', $slug)
      ->condition('p.lang', $lang)
      ->range(0, 1)
      ->execute()
      ->fetchObject();
    return $row ?: NULL;
  }

  /**
   * Inserts or updates a mirror row keyed by slug + lang.
   */
  protected function upsertRow(string $slug, string $lang, string $section, string $title, string $body, string $hash, int $now, int $status, string $image = '', string $sourceUrl = ''): void {
    $row = $this->loadRow($slug, $lang);
    $fields = [
      'section' => $section,
      'title' => mb_substr($title, 0, 500),
      'body_html' => $body,
      'content_hash' => $hash,
      'status' => $status,
      'fetched_at' => $now,
      'source_url' => $sourceUrl !== '' ? mb_substr($sourceUrl, 0, 500) : (string) ($row->source_url ?? ''),
    ];
    if ($image !== '') {
      $fields['image'] = $image;
    }
    $this->database->merge('dx_nasa_page')
      ->keys(['slug' => $slug, 'lang' => $lang])
      ->fields($fields)
      ->execute();
  }

  /**
   * Translates an EN row into the ZH counterpart.
   */
  public function translateRow(string $slug, string $section, string $titleEn, string $bodyEn, string $hash, int $now, string $sourceUrl = '', string $image = ''): bool {
    $result = $this->translator->translate($titleEn, $bodyEn);
    if ($result === NULL) {
      $this->upsertRow($slug, 'zh', $section, $titleEn, $bodyEn, $hash, $now, 2, $image, $sourceUrl);
      $this->logger->warning('Translation pending for @slug (AI unavailable or output invalid).', [
        '@slug' => $slug,
      ]);
      return FALSE;
    }
    $this->upsertRow($slug, 'zh', $section, $result['title'], $result['body'], $hash, $now, 1, $image, $sourceUrl);
    return TRUE;
  }

  /**
   * Translates any rows still in pending state (used by drush --pending).
   */
  public function translatePending(): array {
    $stats = ['ok' => 0, 'failed' => 0];
    $rows = $this->database->select('dx_nasa_page', 'p')
      ->fields('p', ['slug'])
      ->condition('p.lang', 'zh')
      ->condition('p.status', 2)
      ->execute()
      ->fetchCol();
    foreach ($rows as $slug) {
      $en = $this->loadRow((string) $slug, 'en');
      $zh = $this->loadRow((string) $slug, 'zh');
      if (!$en || !$zh) {
        continue;
      }
      $ok = $this->translateRow((string) $slug, (string) $en->section, (string) $en->title, (string) $en->body_html, (string) $en->content_hash, $this->time->getRequestTime());
      $stats[$ok ? 'ok' : 'failed']++;
    }
    return $stats;
  }

}
