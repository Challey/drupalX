<?php

declare(strict_types=1);

namespace Drupal\dx_migrate\Service;

/**
 * Parses legacy list/detail HTML into DXEP content payloads.
 *
 * Every selector, filter and body layout used to be hard-coded here. They now
 * live in declarative templates under `dx_migrate/data/templates/` (see
 * {@see \Drupal\dx_migrate\Service\L2TemplateRegistry}) so a new portal — or a
 * new industry such as the bundled `hospital_notice` sample — is onboarded with
 * a YAML file instead of a PHP change. The default `auto` template reproduces
 * the historical mapping byte for byte.
 */
final class L1HtmlAdapter {

  /** Fallback template used when a caller passes an unknown template name. */
  public const DEFAULT_TEMPLATE = 'auto';

  private L2TemplateRegistry $registryCache;

  public function __construct(
    private readonly ?L2TemplateRegistry $registry = NULL,
  ) {}

  /**
   * The backing template library (container-injected or built on demand).
   */
  public function registry(): L2TemplateRegistry {
    return $this->registryCache ??= ($this->registry ?? new L2TemplateRegistry());
  }

  /**
   * Known portal list templates → fixture + XPath.
   *
   * Kept for backwards compatibility with callers written against the old
   * hard-coded table; it is now derived from the template library.
   *
   * @return array<string, array{fixture: string, list_xpath: string, label: string}>
   */
  public function templates(): array {
    $out = [];
    foreach ($this->registry()->names() as $name) {
      $row = $this->registry()->resolveRow($name) ?? [];
      $out[$name] = [
        'fixture' => (string) ($row['list']['fixture'] ?? ''),
        'list_xpath' => (string) ($row['list']['xpath'] ?? ''),
        'label' => (string) ($row['label'] ?? $name),
      ];
    }
    return $out;
  }

  /**
   * Loaded definition for a template name.
   *
   * An unknown name falls back to {@see self::DEFAULT_TEMPLATE} exactly like
   * the pre-template code did; a *broken* template never degrades silently and
   * raises {@see \Drupal\dx_migrate\Service\L2TemplateException} instead.
   *
   * @return array<string, mixed>
   */
  public function definition(string $template): array {
    $registry = $this->registry();
    if ($template === '' || !$registry->exists($template)) {
      return $registry->definition(self::DEFAULT_TEMPLATE);
    }
    return $registry->definition($template);
  }

  /**
   * Strict variant used by Drush / form validation: no silent fallback.
   *
   * @return array<string, mixed>
   *
   * @throws \Drupal\dx_migrate\Service\L2TemplateException
   */
  public function definitionStrict(string $template): array {
    return $this->registry()->definition($template);
  }

  /**
   * Fetch HTML from URL, or load a bundled list fixture.
   */
  public function loadHtml(string $sourceUrl, bool $allowFixture = TRUE, string $template = 'auto'): string {
    $def = $this->definition($template);
    $sourceUrl = trim($sourceUrl);
    if ($sourceUrl !== '' && preg_match('#^https?://#i', $sourceUrl)) {
      $html = $this->retrieve($sourceUrl, $def);
      if (is_string($html) && $html !== '') {
        return $html;
      }
    }
    if (!$allowFixture) {
      throw new \RuntimeException('Unable to fetch source HTML and fixture disabled.');
    }
    $fixture = dirname(__DIR__, 2) . '/data/fixtures/' . (string) ($def['list']['fixture'] ?? '');
    $html = is_readable($fixture) ? file_get_contents($fixture) : FALSE;
    if ($html === FALSE || $html === '') {
      throw new \RuntimeException('Fixture missing: ' . $fixture);
    }
    return $html;
  }

  /**
   * Load detail HTML for a list href (HTTP or local fixture map).
   *
   * `$template` is a trailing optional argument: existing three-argument
   * callers keep the historical `news|article` fixture map.
   */
  public function loadDetailHtml(string $href, string $baseUrl = '', bool $allowFixture = TRUE, string $template = 'auto'): ?string {
    $def = $this->definition($template);
    $href = trim($href);
    if ($href === '') {
      return NULL;
    }

    $absolute = $this->absolutize($href, $baseUrl);
    if ($absolute !== '' && preg_match('#^https?://#i', $absolute)) {
      $html = $this->retrieve($absolute, $def);
      if (is_string($html) && $html !== '') {
        return $html;
      }
    }

    if (!$allowFixture) {
      return NULL;
    }

    $slug = $this->fixtureSlugFromHref($href, $def);
    if ($slug === NULL || $slug === '') {
      return NULL;
    }
    $path = dirname(__DIR__, 2) . '/data/fixtures/'
      . trim((string) ($def['detail']['fixture_dir'] ?? 'details'), '/\\') . '/'
      . $slug . '.html';
    if (!is_readable($path)) {
      return NULL;
    }
    $html = file_get_contents($path);
    return is_string($html) && $html !== '' ? $html : NULL;
  }

  /**
   * Extract list items.
   *
   * @return list<array{external_id: string, title: string, href: string, body: array{html: string}, status: string}>
   */
  public function parseList(string $html, string $sourceHint = '', string $template = 'auto'): array {
    $def = $this->definition($template);
    $itemDef = is_array($def['item'] ?? NULL) ? $def['item'] : [];
    $xpathExpr = (string) ($def['list']['xpath'] ?? '');
    $fallbackExpr = (string) ($def['list']['fallback_xpath'] ?? '//a[@href]');

    $xpath = $this->xpath($html);
    $nodes = $xpath->query($xpathExpr);
    if ($nodes === FALSE || $nodes->length === 0) {
      $nodes = $xpath->query($fallbackExpr);
    }

    $maxItems = (int) ($itemDef['max_items'] ?? 40);
    $minTitle = (int) ($itemDef['min_title_length'] ?? 4);
    $collapse = (bool) ($itemDef['collapse_whitespace'] ?? TRUE);
    $reject = (string) ($itemDef['reject_title_pattern'] ?? '');
    $rejectPattern = $reject === '' ? '' : L2TemplateRegistry::delimiterRegex($reject);
    $idSource = (string) ($itemDef['external_id']['source'] ?? 'href_title');
    $idPrefix = (string) ($itemDef['external_id']['prefix'] ?? 'l1_');
    $idLength = (int) ($itemDef['external_id']['length'] ?? 16);
    $status = (string) ($def['resource']['status'] ?? 'draft');
    $parts = is_array($itemDef['body']['parts'] ?? NULL) ? $itemDef['body']['parts'] : [];

    $items = [];
    $seen = [];
    if ($nodes !== FALSE) {
      foreach ($nodes as $node) {
        if (!$node instanceof \DOMElement) {
          continue;
        }
        $title = $this->readField($node, (string) ($itemDef['title']['from'] ?? 'text'), $collapse);
        $href = $this->readField($node, (string) ($itemDef['href']['from'] ?? 'href'), FALSE);
        if ($title === '' || mb_strlen($title) < $minTitle) {
          continue;
        }
        if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'javascript:')) {
          continue;
        }
        if ($rejectPattern !== '' && preg_match($rejectPattern, $title)) {
          continue;
        }
        $idSourceValue = match ($idSource) {
          'href' => $href,
          'title' => $title,
          default => $href . '|' . $title,
        };
        $externalId = $idPrefix . substr(hash('sha256', $idSourceValue), 0, $idLength);
        if (isset($seen[$externalId])) {
          continue;
        }
        $seen[$externalId] = TRUE;
        $body = L2TemplateRegistry::renderParts($parts, [
          'title' => $title,
          'href' => $href,
          'source' => $sourceHint,
          'external_id' => $externalId,
        ]);
        $items[] = [
          'external_id' => $externalId,
          'title' => $title,
          'href' => $href,
          'body' => ['html' => $body],
          'status' => $status,
        ];
        if (count($items) >= $maxItems) {
          break;
        }
      }
    }
    return $items;
  }

  /**
   * Parse a detail page into title/body/meta fields.
   *
   * @return array{title: string, body_html: string, published_at: string, source: string}
   */
  public function parseDetail(string $html, string $template = 'auto'): array {
    $def = $this->definition($template);
    $detail = is_array($def['detail'] ?? NULL) ? $def['detail'] : [];
    $xpath = $this->xpath($html);
    $dom = $xpath->document;

    $title = $this->firstText($xpath, $this->candidates($detail['title_xpath'] ?? []));

    $bodyNode = $this->firstNode($xpath, $this->candidates($detail['body_xpath'] ?? []));
    $textFallback = (string) ($detail['body_text_fallback'] ?? '<p>{text_esc}</p>');
    $bodyHtml = '';
    if ($bodyNode instanceof \DOMNode) {
      $inner = '';
      foreach ($bodyNode->childNodes as $child) {
        $inner .= $dom->saveHTML($child);
      }
      // Prefer paragraphs only if we grabbed a huge wrapper with h1.
      $bodyHtml = trim($inner);
      if ($bodyHtml === '') {
        $bodyHtml = L2TemplateRegistry::renderTemplate($textFallback, ['text' => trim($bodyNode->textContent)]);
      }
    }
    if ($bodyHtml === '') {
      $paras = $xpath->query((string) ($detail['body_fallback_xpath'] ?? '//p'));
      $chunks = [];
      $minParagraph = (int) ($detail['body_min_paragraph_length'] ?? 8);
      $maxParagraphs = (int) ($detail['body_max_paragraphs'] ?? 8);
      if ($paras !== FALSE) {
        foreach ($paras as $p) {
          $text = trim($p->textContent);
          if ($text !== '' && mb_strlen($text) > $minParagraph) {
            $chunks[] = L2TemplateRegistry::renderTemplate($textFallback, ['text' => $text]);
          }
          if (count($chunks) >= $maxParagraphs) {
            break;
          }
        }
      }
      $bodyHtml = implode('', $chunks);
    }

    $published = $this->firstText($xpath, $this->candidates($detail['published_xpath'] ?? []));
    $source = $this->firstText($xpath, $this->candidates($detail['source_xpath'] ?? []));
    $emptyHtml = (string) ($detail['body_empty_html'] ?? '<p></p>');

    return [
      'title' => $title,
      'body_html' => $bodyHtml !== '' ? $bodyHtml : $emptyHtml,
      'published_at' => $published,
      'source' => $source,
    ];
  }

  /**
   * Render the `<p><em>Published: … · Source: …</em></p>` meta line of a detail
   * page from the template's `detail.meta` block. Returns '' when no field hit.
   *
   * @param array{published_at?: string, source?: string} $detail
   */
  public function detailMetaHtml(array $detail, string $template = 'auto'): string {
    $def = $this->definition($template);
    $meta = is_array($def['detail']['meta'] ?? NULL) ? $def['detail']['meta'] : [];
    $order = $this->candidates($meta['order'] ?? []);
    $format = is_array($meta['format'] ?? NULL) ? $meta['format'] : [];
    $join = (string) ($meta['join'] ?? ' · ');
    $wrapper = (string) ($meta['wrapper'] ?? '');
    $vars = [
      'published' => (string) ($detail['published_at'] ?? ''),
      'source' => (string) ($detail['source'] ?? ''),
    ];
    $bits = [];
    foreach ($order as $field) {
      $value = (string) ($vars[$field] ?? '');
      if ($value === '') {
        continue;
      }
      $text = L2TemplateRegistry::renderTemplate((string) ($format[$field] ?? ''), $vars);
      if (trim($text) !== '') {
        $bits[] = $text;
      }
    }
    if ($bits === []) {
      return '';
    }
    $line = implode($join, $bits);
    return $wrapper === '' ? $line : L2TemplateRegistry::renderTemplate($wrapper, ['meta' => $line]);
  }

  /**
   * The DXEP resource type a template ingests as (article, notice, …).
   */
  public function resourceType(string $template): string {
    $def = $this->definition($template);
    return (string) ($def['resource']['type'] ?? 'article');
  }

  /**
   * Default detail-enrichment limit carried by a template.
   */
  public function detailLimit(string $template): int {
    $def = $this->definition($template);
    return (int) ($def['resource']['detail_limit'] ?? 10);
  }

  protected function absolutize(string $href, string $baseUrl): string {
    if (preg_match('#^https?://#i', $href)) {
      return $href;
    }
    $baseUrl = trim($baseUrl);
    if ($baseUrl === '' || !preg_match('#^https?://#i', $baseUrl)) {
      return $href;
    }
    $parts = parse_url($baseUrl);
    if ($parts === FALSE || empty($parts['scheme']) || empty($parts['host'])) {
      return $href;
    }
    $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    if (str_starts_with($href, '//')) {
      return $parts['scheme'] . ':' . $href;
    }
    if (str_starts_with($href, '/')) {
      return $origin . $href;
    }
    $dir = isset($parts['path']) ? preg_replace('#/[^/]*$#', '/', $parts['path']) : '/';
    return $origin . $dir . $href;
  }

  /**
   * Map a list href onto a bundled detail fixture name.
   *
   * @param array<string, mixed> $def
   */
  protected function fixtureSlugFromHref(string $href, array $def = []): ?string {
    $path = parse_url($href, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
      $path = $href;
    }
    $pattern = (string) ($def['detail']['fixture_pattern'] ?? '#/(news|article)/(\d+)#');
    $slugTpl = (string) ($def['detail']['fixture_slug'] ?? '{1}-{2}');
    if ($pattern === '' || !preg_match($pattern, $path, $m)) {
      return NULL;
    }
    $slug = L2TemplateRegistry::renderSlug($slugTpl, array_map('strval', $m));
    return preg_replace('/[^\w\-.]/u', '', $slug) ?? '';
  }

  /**
   * @param list<string> $queries
   */
  protected function firstText(\DOMXPath $xpath, array $queries): string {
    $node = $this->firstNode($xpath, $queries);
    if (!$node) {
      return '';
    }
    return trim(preg_replace('/\s+/u', ' ', $node->textContent) ?? '');
  }

  /**
   * @param list<string> $queries
   */
  protected function firstNode(\DOMXPath $xpath, array $queries): ?\DOMNode {
    foreach ($queries as $q) {
      if ($q === '') {
        continue;
      }
      $nodes = @$xpath->query($q);
      if ($nodes !== FALSE && $nodes->length > 0) {
        $node = $nodes->item(0);
        if ($node instanceof \DOMNode) {
          return $node;
        }
      }
    }
    return NULL;
  }

  /**
   * Read a list-item field: node text, the `href` attribute or `attr:<name>`.
   */
  private function readField(\DOMElement $node, string $from, bool $collapse): string {
    if (str_starts_with($from, 'attr:')) {
      $value = $node->getAttribute(substr($from, 5));
    }
    elseif ($from === 'href') {
      $value = $node->getAttribute('href');
    }
    else {
      $value = $node->textContent;
    }
    if ($collapse) {
      $value = preg_replace('/\s+/u', ' ', $value) ?? '';
    }
    return trim($value);
  }

  /**
   * @param array<string, mixed> $def
   */
  private function retrieve(string $url, array $def): string|false|null {
    $timeout = (int) ($def['fetch']['timeout'] ?? 15);
    $agent = (string) ($def['fetch']['user_agent'] ?? 'DrupalX-dx_migrate/1.0');
    try {
      $ctx = stream_context_create([
        'http' => [
          'timeout' => $timeout > 0 ? $timeout : 15,
          'header' => "User-Agent: " . $agent . "\r\n",
        ],
        'ssl' => [
          'verify_peer' => TRUE,
          'verify_peer_name' => TRUE,
        ],
      ]);
      return @file_get_contents($url, FALSE, $ctx);
    }
    catch (\Throwable) {
      // Fall through to the fixture.
      return NULL;
    }
  }

  private function xpath(string $html): \DOMXPath {
    $prev = libxml_use_internal_errors(TRUE);
    $dom = new \DOMDocument();
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    return new \DOMXPath($dom);
  }

  /**
   * Coerce a YAML list of XPath candidates into a list<string>.
   *
   * @param mixed $value
   *
   * @return list<string>
   */
  private function candidates(mixed $value): array {
    if (!is_array($value)) {
      return [];
    }
    $out = [];
    foreach ($value as $item) {
      if (is_string($item) && trim($item) !== '') {
        $out[] = trim($item);
      }
    }
    return $out;
  }

}
