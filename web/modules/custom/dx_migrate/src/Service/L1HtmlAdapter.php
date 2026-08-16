<?php

declare(strict_types=1);

namespace Drupal\dx_migrate\Service;

/**
 * Parses a simple legacy list/detail HTML page into DXEP content payloads.
 */
final class L1HtmlAdapter {

  /**
   * Fetch HTML from URL, or load bundled fixture when URL empty / unreachable.
   */
  public function loadHtml(string $sourceUrl, bool $allowFixture = TRUE): string {
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
    $fixture = dirname(__DIR__, 2) . '/data/fixtures/legacy-list.html';
    $html = file_get_contents($fixture);
    if ($html === FALSE || $html === '') {
      throw new \RuntimeException('Fixture missing: ' . $fixture);
    }
    return $html;
  }

  /**
   * Extract content items from list-style HTML.
   *
   * @return list<array{external_id: string, title: string, body: array{html: string}, status: string}>
   */
  public function parseList(string $html, string $sourceHint = ''): array {
    $prev = libxml_use_internal_errors(TRUE);
    $dom = new \DOMDocument();
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    $xpath = new \DOMXPath($dom);
    $nodes = $xpath->query('//*[contains(@class,"news-list") or contains(@class,"article-list") or self::ul[contains(@class,"list")]]//a[@href]');
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

}
