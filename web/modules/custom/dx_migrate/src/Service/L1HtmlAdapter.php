<?php

declare(strict_types=1);

namespace Drupal\dx_migrate\Service;

/**
 * Parses legacy list/detail HTML into DXEP content payloads.
 */
final class L1HtmlAdapter {

  /**
   * Known portal list templates → fixture + XPath.
   *
   * @return array<string, array{fixture: string, list_xpath: string, label: string}>
   */
  public function templates(): array {
    return [
      'auto' => [
        'fixture' => 'legacy-list.html',
        'list_xpath' => '//*[contains(@class,"news-list") or contains(@class,"article-list") or contains(@class,"gov-news") or self::ul[contains(@class,"list")]]//a[@href]',
        'label' => 'Auto / generic',
      ],
      'gov_news' => [
        'fixture' => 'gov-news-list.html',
        'list_xpath' => '//*[contains(@class,"gov-news")]//a[@href]',
        'label' => 'Government news list',
      ],
      'ent_article' => [
        'fixture' => 'ent-article-list.html',
        'list_xpath' => '//ul[contains(@class,"article-list")]//a[@href]',
        'label' => 'Enterprise article list',
      ],
      'legacy' => [
        'fixture' => 'legacy-list.html',
        'list_xpath' => '//*[contains(@class,"news-list") or contains(@class,"article-list") or self::ul[contains(@class,"list")]]//a[@href]',
        'label' => 'Legacy news-list',
      ],
    ];
  }

  /**
   * Fetch HTML from URL, or load a bundled list fixture.
   */
  public function loadHtml(string $sourceUrl, bool $allowFixture = TRUE, string $template = 'auto'): string {
    $sourceUrl = trim($sourceUrl);
    if ($sourceUrl !== '' && preg_match('#^https?://#i', $sourceUrl)) {
      try {
        $ctx = stream_context_create([
          'http' => [
            'timeout' => 15,
            'header' => "User-Agent: DrupalX-dx_migrate/1.0\r\n",
          ],
          'ssl' => [
            'verify_peer' => TRUE,
            'verify_peer_name' => TRUE,
          ],
        ]);
        $html = @file_get_contents($sourceUrl, FALSE, $ctx);
        if (is_string($html) && $html !== '') {
          return $html;
        }
      }
      catch (\Throwable) {
        // Fall through to fixture.
      }
    }
    if (!$allowFixture) {
      throw new \RuntimeException('Unable to fetch source HTML and fixture disabled.');
    }
    $templates = $this->templates();
    $key = isset($templates[$template]) ? $template : 'auto';
    $fixture = dirname(__DIR__, 2) . '/data/fixtures/' . $templates[$key]['fixture'];
    $html = file_get_contents($fixture);
    if ($html === FALSE || $html === '') {
      throw new \RuntimeException('Fixture missing: ' . $fixture);
    }
    return $html;
  }

  /**
   * Load detail HTML for a list href (HTTP or local fixture map).
   */
  public function loadDetailHtml(string $href, string $baseUrl = '', bool $allowFixture = TRUE): ?string {
    $href = trim($href);
    if ($href === '') {
      return NULL;
    }

    $absolute = $this->absolutize($href, $baseUrl);
    if ($absolute !== '' && preg_match('#^https?://#i', $absolute)) {
      try {
        $ctx = stream_context_create([
          'http' => [
            'timeout' => 15,
            'header' => "User-Agent: DrupalX-dx_migrate/1.0\r\n",
          ],
          'ssl' => [
            'verify_peer' => TRUE,
            'verify_peer_name' => TRUE,
          ],
        ]);
        $html = @file_get_contents($absolute, FALSE, $ctx);
        if (is_string($html) && $html !== '') {
          return $html;
        }
      }
      catch (\Throwable) {
        // Fall through.
      }
    }

    if (!$allowFixture) {
      return NULL;
    }

    $slug = $this->fixtureSlugFromHref($href);
    if ($slug === NULL) {
      return NULL;
    }
    $path = dirname(__DIR__, 2) . '/data/fixtures/details/' . $slug . '.html';
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
    $templates = $this->templates();
    $key = isset($templates[$template]) ? $template : 'auto';
    $xpathExpr = $templates[$key]['list_xpath'];

    $prev = libxml_use_internal_errors(TRUE);
    $dom = new \DOMDocument();
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    $xpath = new \DOMXPath($dom);
    $nodes = $xpath->query($xpathExpr);
    if ($nodes === FALSE || $nodes->length === 0) {
      $nodes = $xpath->query('//a[@href]');
    }

    $items = [];
    $seen = [];
    if ($nodes !== FALSE) {
      foreach ($nodes as $node) {
        if (!$node instanceof \DOMElement) {
          continue;
        }
        $title = trim(preg_replace('/\s+/u', ' ', $node->textContent) ?? '');
        $href = trim($node->getAttribute('href'));
        if ($title === '' || mb_strlen($title) < 4) {
          continue;
        }
        if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'javascript:')) {
          continue;
        }
        if (preg_match('/首页|登录|注册|关于我们|联系我们/u', $title)) {
          continue;
        }
        $externalId = 'l1_' . substr(hash('sha256', $href . '|' . $title), 0, 16);
        if (isset($seen[$externalId])) {
          continue;
        }
        $seen[$externalId] = TRUE;
        $body = '<p>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        if ($sourceHint !== '') {
          $body .= '<p>Source: ' . htmlspecialchars($sourceHint, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        }
        $body .= '<p><a href="' . htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">' . htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a></p>';
        $items[] = [
          'external_id' => $externalId,
          'title' => $title,
          'href' => $href,
          'body' => ['html' => $body],
          'status' => 'draft',
        ];
        if (count($items) >= 40) {
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
  public function parseDetail(string $html): array {
    $prev = libxml_use_internal_errors(TRUE);
    $dom = new \DOMDocument();
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    $xpath = new \DOMXPath($dom);

    $title = $this->firstText($xpath, [
      '//*[@class="title" or contains(@class,"entry-title")]',
      '//h1',
      '//title',
    ]);

    $bodyNode = $this->firstNode($xpath, [
      '//*[contains(@class,"article-body")]',
      '//*[contains(@class,"entry-content")]',
      '//*[contains(@class,"post-content")]',
      '//*[contains(@class,"Custom_UnionStyle")]',
      '//*[@id="zoom"]',
      '//article//div[contains(@class,"content")]',
      '//main//div[contains(@class,"content")]',
      '//article',
    ]);
    $bodyHtml = '';
    if ($bodyNode instanceof \DOMNode) {
      $inner = '';
      foreach ($bodyNode->childNodes as $child) {
        $inner .= $dom->saveHTML($child);
      }
      // Prefer paragraphs only if we grabbed a huge wrapper with h1.
      $bodyHtml = trim($inner);
      if ($bodyHtml === '') {
        $bodyHtml = '<p>' . htmlspecialchars(trim($bodyNode->textContent), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
      }
    }
    if ($bodyHtml === '') {
      $paras = $xpath->query('//p');
      $chunks = [];
      if ($paras !== FALSE) {
        foreach ($paras as $p) {
          $text = trim($p->textContent);
          if ($text !== '' && mb_strlen($text) > 8) {
            $chunks[] = '<p>' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
          }
          if (count($chunks) >= 8) {
            break;
          }
        }
      }
      $bodyHtml = implode('', $chunks);
    }

    $published = $this->firstText($xpath, [
      '//*[contains(@class,"pubtime") or contains(@class,"time") or contains(@class,"date")]',
      '//*[contains(@class,"meta")]//span',
    ]);
    $source = $this->firstText($xpath, [
      '//*[contains(@class,"source")]',
    ]);

    return [
      'title' => $title,
      'body_html' => $bodyHtml !== '' ? $bodyHtml : '<p></p>',
      'published_at' => $published,
      'source' => $source,
    ];
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

  protected function fixtureSlugFromHref(string $href): ?string {
    $path = parse_url($href, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
      $path = $href;
    }
    if (preg_match('#/(news|article)/(\d+)#', $path, $m)) {
      return $m[1] . '-' . $m[2];
    }
    return NULL;
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
      $nodes = $xpath->query($q);
      if ($nodes !== FALSE && $nodes->length > 0) {
        $node = $nodes->item(0);
        if ($node instanceof \DOMNode) {
          return $node;
        }
      }
    }
    return NULL;
  }

}
