<?php

/**
 * @file
 * AI translation service for mirrored NASA pages.
 */

declare(strict_types=1);

namespace Drupal\dx_nasa\Service;

use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\dx_ai_gateway\Service\AiGateway;

/**
 * Translates NASA page titles and sanitized HTML bodies to Chinese.
 *
 * Strategy: the model NEVER sees or emits HTML. PHP extracts the text of each
 * block element (protecting figures/images/links behind placeholder tokens),
 * sends numbered plain-text lines, then reassembles the original structure
 * with translated text. Lines whose translation is missing fall back to EN.
 */
class NasaTranslator {

  protected const ASSET_RE = '/NASAASSET(\d+)ENDASSET/';
  protected const LINK_RE = '/NASALINK(\d+)ENDLINK/';

  /**
   * Cached terminology map (EN => ZH).
   */
  protected ?array $terminology = NULL;

  /**
   * Asset placeholders (figure/img HTML) captured during extract().
   */
  protected array $assets = [];

  /**
   * Inline-link placeholders (<a> HTML) captured during extract().
   */
  protected array $links = [];

  public function __construct(
    protected AiGateway $gateway,
    protected LoggerChannelInterface $logger,
  ) {}

  /**
   * Loads the astronomy EN->ZH glossary from module config.
   */
  protected function terminology(): array {
    if ($this->terminology === NULL) {
      $path = __DIR__ . '/../../config/terminology.json';
      $data = is_file($path) ? json_decode((string) file_get_contents($path), TRUE) : [];
      $this->terminology = is_array($data['terms'] ?? NULL) ? $data['terms'] : [];
    }
    return $this->terminology;
  }

  /**
   * Translates a page title and body HTML to Chinese.
   *
   * @return array{title: string, body: string}|null
   *   Translated parts, or NULL when nothing usable was produced.
   */
  public function translate(string $titleEn, string $bodyHtml): ?array {
    for ($attempt = 0; $attempt < 2; $attempt++) {
      try {
        $result = $this->request($titleEn, $bodyHtml);
        if ($result !== NULL) {
          return $result;
        }
      }
      catch (\Throwable $e) {
        $this->logger->warning('NASA translate attempt @n failed: @msg', [
          '@n' => $attempt + 1,
          '@msg' => $e->getMessage(),
        ]);
      }
    }
    return NULL;
  }

  /**
   * Full pipeline: extract -> translate numbered lines -> reassemble.
   */
  protected function request(string $titleEn, string $bodyHtml): ?array {
    [$work, $blocks, $lines] = $this->extract($bodyHtml);
    if (!$lines) {
      return NULL;
    }

    // Line 0 is the page title.
    $all = [0 => $titleEn] + $lines;
    $translations = $this->translateLines($all);
    if (!isset($translations[0]) || trim((string) $translations[0]) === '') {
      $this->logger->warning('NASA translate: title line missing in model output.');
      return NULL;
    }

    // Reassemble on the PROTECTED string (block offsets refer to $work),
    // walking from the end backwards so earlier offsets stay valid.
    $body = $work;
    usort($blocks, static fn(array $a, array $b) => $b['offset'] <=> $a['offset']);
    foreach ($blocks as $block) {
      $text = $translations[$block['line']] ?? $block['fallback'];
      // If the model dropped any placeholder that the block originally had,
      // prefer the untouched EN inner HTML to keep structure lossless.
      if (preg_match_all('/NASA(?:ASSET|LINK)\d+END(?:ASSET|LINK)/', $block['fallback'], $fm)) {
        foreach ($fm[0] as $token) {
          if (!str_contains($text, $token)) {
            $text = $block['fallback'];
            break;
          }
        }
      }
      $replacement = $block['prefix'] . $text . $block['suffix'];
      $body = substr_replace($body, $replacement, $block['offset'], $block['length']);
    }

    // Restore every placeholder token back to real HTML markup.
    $body = $this->restorePlaceholders($body);

    return [
      'title' => (string) $translations[0],
      'body' => $body,
    ];
  }

  /**
   * Extracts translatable block texts from sanitized HTML.
   *
   * @return array{0: string, 1: array<int, array{offset:int,length:int,prefix:string,suffix:string,line:int,fallback:string}>, 2: array<int, string>}
   *   The protected string (placeholders for figures/images), block
   *   descriptors (offsets refer to the protected string) and the line map.
   */
  protected function extract(string $bodyHtml): array {
    $this->assets = [];
    $this->links = [];

    // Protect whole figures / standalone images.
    $work = preg_replace_callback(
      '/(<figure\b.*?<\/figure>|<img\b[^>]*>)/is',
      function (array $m): string {
        $idx = count($this->assets);
        $this->assets[$idx] = $m[0];
        return "NASAASSET{$idx}ENDASSET";
      },
      $bodyHtml,
    ) ?? $bodyHtml;

    $blockRe = '/<(p|h[1-6]|li|td|th|blockquote|figcaption|dd|dt|caption)\b([^>]*)>(.*?)<\/\1>/is';
    preg_match_all($blockRe, $work, $matches, PREG_OFFSET_CAPTURE);

    $blocks = [];
    $lines = [];
    $lineNo = 0;
    foreach ($matches[0] as $i => $tagMatch) {
      [$full, $offset] = $tagMatch;
      $attrs = $matches[2][$i][0];
      $inner = $matches[3][$i][0];

      // Protect inline anchors; the surrounding text stays translatable.
      $inner = preg_replace_callback(
        '/<a\b[^>]*>.*?<\/a>/is',
        function (array $m): string {
          $idx = count($this->links);
          $this->links[$idx] = $m[0];
          return "NASALINK{$idx}ENDLINK";
        },
        $inner,
      ) ?? $inner;

      $text = trim(strip_tags($inner));
      if ($text === '') {
        continue;
      }
      $lineNo++;
      $lines[$lineNo] = $text;
      $tagName = $matches[1][$i][0];
      $blocks[] = [
        'offset' => $offset,
        'length' => strlen($full),
        'prefix' => '<' . $tagName . $attrs . '>',
        'suffix' => '</' . strtolower($tagName) . '>',
        'line' => $lineNo,
        'fallback' => $inner,
      ];
    }
    return [$work, $blocks, $lines];
  }

  /**
   * Replaces placeholder tokens back with their original HTML.
   */
  protected function restorePlaceholders(string $text): string {
    $text = preg_replace_callback(self::ASSET_RE, fn(array $m) => $this->assets[(int) $m[1]] ?? '', $text) ?? $text;
    $text = preg_replace_callback(self::LINK_RE, fn(array $m) => $this->links[(int) $m[1]] ?? '', $text) ?? $text;
    return $text;
  }

  /**
   * Translates numbered plain-text lines in batches.
   *
   * @return array<int, string>
   *   Line number => translated text (missing entries fall back to EN).
   */
  protected function translateLines(array $lines): array {
    $terms = '';
    foreach ($this->terminology() as $en => $zh) {
      $terms .= $en . ' = ' . $zh . "\n";
    }

    $batches = [];
    $batch = [];
    $weight = 0;
    foreach ($lines as $no => $text) {
      $batch[$no] = $text;
      $weight += max(8, mb_strlen($text));
      if ($weight > 3500 || count($batch) >= 40) {
        $batches[] = $batch;
        $batch = [];
        $weight = 0;
      }
    }
    if ($batch) {
      $batches[] = $batch;
    }

    $translations = [];
    foreach ($batches as $batch) {
      $payload = '';
      foreach ($batch as $no => $text) {
        $payload .= $no . '|' . str_replace("\n", ' ', $text) . "\n";
      }
      $system = <<<SYSTEM
你是专业的天文科普译者。用户消息是多行编号文本，每行格式 "编号|英文句子"。请把它们翻译成中文，按同样格式逐行输出：编号保持不变，只输出 "编号|中文"，一行不少、顺序一致，不要添加任何解释、编号之外的文字或 Markdown 围栏。
- 文本中的 NASAASSET数字ENDASSET 与 NASALINK数字ENDLINK 是不可见的结构占位符，必须原样保留在译文中。
- 使用给出的天文术语表保持一致。
- 面向中小学生：语言自然流畅；专有名词首次出现可附英文原文。
- 编号 0 是页面标题。

天文术语表：
{$terms}
SYSTEM;

      $resp = $this->gateway->chat($payload, NULL, [['role' => 'system', 'content' => $system]]);
      $out = trim((string) ($resp['content'] ?? ''));
      $out = (string) preg_replace('/^```[a-zA-Z]*\n|\n```$/', '', $out);
      foreach (explode("\n", (string) $out) as $line) {
        if (preg_match('/^\s*(\d+)\s*\|\s*(.+)$/u', $line, $m)) {
          $translations[(int) $m[1]] = trim($m[2]);
        }
      }
    }

    $expected = count($lines);
    $got = count(array_intersect_key($translations, $lines));
    if ($got < $expected) {
      $this->logger->info('NASA translate: @n line(s) fell back to EN.', [
        '@n' => $expected - $got,
      ]);
    }
    return $translations;
  }

}
