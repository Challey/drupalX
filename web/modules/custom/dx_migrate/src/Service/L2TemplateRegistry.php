<?php

declare(strict_types=1);

namespace Drupal\dx_migrate\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Declarative L1/L2 field-mapping template library (roadmap G1).
 *
 * Every template lives in a YAML file under `dx_migrate/data/templates/` (or any
 * extra directory listed in `dx_migrate.settings.template_dirs`), so a new
 * industry mapping ships as data only — `dx:migrate-l2 --template=<machine_name>`
 * never needs a PHP change. Files starting with `_` are internal parents that
 * other templates pull in with `extends`.
 *
 * A template that cannot be parsed, whose parent is missing, or that fails
 * validation is reported through invalid() and rejected on use; it never silently
 * degrades to `auto`.
 */
final class L2TemplateRegistry {

  public const CONFIG_NAME = 'dx_migrate.settings';
  public const SUPPORTED_SPEC_VERSIONS = ['1.0'];
  public const MACHINE_NAME_PATTERN = '/^[a-z_][a-z0-9_]{1,40}$/';
  public const ID_SOURCES = ['href_title', 'href', 'title'];
  public const RESOURCE_STATUSES = ['draft', 'published'];
  public const FILE_GLOBS = ['*.yml', '*.yaml', '*.json'];

  /** Placeholders usable in body parts, meta formats and the text fallback. */
  public const PLACEHOLDERS = [
    'title',
    'href',
    'source',
    'text',
    'meta',
    'published',
    'external_id',
  ];

  /** @var list<string> */
  private array $runtimeDirs = [];

  /** @var array<string, array{name: string, path: string, raw: array<string, mixed>|NULL, error: string}>|NULL */
  private ?array $parsed = NULL;

  /** @var array<string, array{ok: bool, def: array<string, mixed>, issues: list<array{field: string, issue: string}>}>|NULL */
  private ?array $resolved = NULL;

  public function __construct(
    private readonly ?ConfigFactoryInterface $config = NULL,
  ) {}

  /**
   * Bundled template directory that ships with the module.
   */
  public static function builtinDir(): string {
    return dirname(__DIR__, 2) . '/data/templates';
  }

  /**
   * Register an additional directory at runtime (tools, tests, site bundles).
   */
  public function addDirectory(string $dir): void {
    $dir = rtrim(trim($dir), '/\\');
    if ($dir === '' || in_array($dir, $this->runtimeDirs, TRUE)) {
      return;
    }
    $this->runtimeDirs[] = $dir;
    $this->invalidate();
  }

  /**
   * Forget every cached file so the next read hits the disk again.
   */
  public function refresh(): void {
    $this->invalidate();
  }

  /**
   * Existing template directories, later entries overriding earlier ones.
   *
   * @return list<string>
   */
  public function directories(): array {
    $dirs = [self::builtinDir()];
    if ($this->config !== NULL) {
      foreach ((array) ($this->config->get(self::CONFIG_NAME)->get('template_dirs') ?: []) as $dir) {
        if (is_string($dir)) {
          $dirs[] = rtrim(trim($dir), '/\\');
        }
      }
    }
    $dirs = array_merge($dirs, $this->runtimeDirs);
    $out = [];
    foreach ($dirs as $dir) {
      if ($dir !== '' && is_dir($dir) && !in_array($dir, $out, TRUE)) {
        $out[] = $dir;
      }
    }
    return $out;
  }

  /**
   * machine_name => absolute file path across all directories.
   *
   * @return array<string, string>
   */
  public function files(): array {
    $files = [];
    foreach ($this->directories() as $dir) {
      foreach (self::FILE_GLOBS as $glob) {
        foreach (glob($dir . '/' . $glob) ?: [] as $path) {
          $name = self::machineName((string) $path);
          if ($name !== NULL) {
            $files[$name] = (string) $path;
          }
        }
      }
    }
    ksort($files);
    return $files;
  }

  /**
   * Usable template names for `--template=` (internal parents excluded).
   *
   * @return list<string>
   */
  public function names(): array {
    return $this->listing(FALSE);
  }

  /**
   * Every usable name including internal `_parent` templates.
   *
   * @return list<string>
   */
  public function allNames(): array {
    return $this->listing(TRUE);
  }

  /**
   * Usable public definitions keyed by machine name, weight-ordered.
   *
   * @return array<string, array<string, mixed>>
   */
  public function definitions(): array {
    $out = [];
    foreach ($this->names() as $name) {
      $out[$name] = $this->definition($name);
    }
    return $out;
  }

  /**
   * Names that exist on disk but are broken, with their validation issues.
   *
   * @return array<string, list<array{field: string, issue: string}>>
   */
  public function invalid(): array {
    $out = [];
    foreach ($this->resolve() as $name => $row) {
      if (!$row['ok']) {
        $out[$name] = $row['issues'];
      }
    }
    return $out;
  }

  public function has(string $name): bool {
    $row = $this->resolve()[trim($name)] ?? NULL;
    return $row !== NULL && $row['ok'] === TRUE && !str_starts_with(trim($name), '_');
  }

  /**
   * Does this name exist on disk at all (usable or broken)?
   *
   * Lets callers tell a typo (`no_such`, falls back to `auto` for backwards
   * compatibility) apart from a template file that is present but invalid
   * (always a hard error).
   */
  public function exists(string $name): bool {
    return isset($this->resolve()[trim($name)]);
  }

  /**
   * Resolved definition without the internal-parent guard, or NULL if broken.
   *
   * @return array<string, mixed>|NULL
   */
  public function resolveRow(string $name): ?array {
    $row = $this->resolve()[trim($name)] ?? NULL;
    return $row === NULL || !$row['ok'] ? NULL : $row['def'];
  }

  /**
   * Loaded + validated definition.
   *
   * @return array<string, mixed>
   *
   * @throws \Drupal\dx_migrate\Service\L2TemplateException
   */
  public function definition(string $name): array {
    $name = trim($name);
    $all = $this->resolve();
    if (!isset($all[$name])) {
      throw new L2TemplateException(sprintf(
        'Unknown migrate template "%s". Available: %s.',
        $name,
        $this->names() === [] ? '(none)' : implode(', ', $this->names()),
      ));
    }
    if (!$all[$name]['ok']) {
      throw new L2TemplateException(sprintf('Migrate template "%s" is invalid.', $name), $all[$name]['issues']);
    }
    if (str_starts_with($name, '_')) {
      throw new L2TemplateException(sprintf(
        'Migrate template "%s" is an internal parent and cannot be used directly; extend it from your own template.',
        $name,
      ));
    }
    return $all[$name]['def'];
  }

  /**
   * Rows for the Drush listing and the admin summary.
   *
   * @return list<array<string, mixed>>
   */
  public function describe(): array {
    $files = $this->files();
    $rows = [];
    foreach ($this->resolve() as $name => $row) {
      $def = $row['def'];
      $rows[] = [
        'machine_name' => $name,
        'label' => (string) ($def['label'] ?? ''),
        'weight' => (int) ($def['weight'] ?? 0),
        'internal' => str_starts_with($name, '_'),
        'ok' => $row['ok'],
        'file' => (string) ($files[$name] ?? ''),
        'list_fixture' => (string) ($def['list']['fixture'] ?? ''),
        'resource_type' => (string) ($def['resource']['type'] ?? ''),
        'issues' => $row['issues'],
      ];
    }
    usort($rows, static fn(array $a, array $b): int => ($a['weight'] <=> $b['weight']) ?: strcmp((string) $a['machine_name'], (string) $b['machine_name']));
    return $rows;
  }

  /**
   * Parse, resolve parents and validate one template file.
   *
   * Used by `dx:migrate-template-validate` on a candidate file before it is
   * dropped into a template directory.
   *
   * @return array<string, mixed>
   *
   * @throws \Drupal\dx_migrate\Service\L2TemplateException
   */
  public function loadFile(string $path): array {
    if (!is_readable($path)) {
      throw new L2TemplateException('Migrate template file is not readable: ' . $path);
    }
    $name = self::machineName($path);
    if ($name === NULL) {
      throw new L2TemplateException('Migrate template file name must be a machine name (gov_news, _base, …) with a .yml/.yaml/.json suffix: ' . $path);
    }
    try {
      $raw = $this->parseFile($path);
    }
    catch (\Throwable $e) {
      throw new L2TemplateException('Migrate template ' . basename($path) . ' cannot be parsed: ' . $e->getMessage());
    }
    $parents = [];
    foreach ($this->parseAll() as $row) {
      if ($row['raw'] !== NULL) {
        $parents[$row['name']] = $row['raw'];
      }
    }
    $def = $this->finalize($name, $this->mergeParent($name, $raw, $parents, []));
    $issues = $this->validate($def, $name);
    if ($issues !== []) {
      throw new L2TemplateException(sprintf('Migrate template "%s" is invalid.', $name), $issues);
    }
    return $def;
  }

  /**
   * Validate a (already merged) definition; returns [field, issue] rows.
   *
   * @param array<string, mixed> $def
   *
   * @return list<array{field: string, issue: string}>
   */
  public function validate(array $def, string $name = ''): array {
    $issues = [];
    $add = static function (string $field, string $issue) use (&$issues): void {
      $issues[] = ['field' => $field, 'issue' => $issue];
    };

    $knownTop = [
      'label', 'weight', 'spec_version', 'extends', 'machine_name',
      'list', 'item', 'detail', 'fetch', 'resource', 'extensions',
    ];
    foreach (array_keys($def) as $key) {
      if (!in_array((string) $key, $knownTop, TRUE)) {
        $add((string) $key, 'unknown setting (put future keys under "extensions:")');
      }
    }

    if (trim((string) ($def['label'] ?? '')) === '') {
      $add('label', 'required');
    }
    if (isset($def['weight']) && !is_int($def['weight'])) {
      $add('weight', 'must be an integer');
    }
    $spec = (string) ($def['spec_version'] ?? '1.0');
    if (!in_array($spec, self::SUPPORTED_SPEC_VERSIONS, TRUE)) {
      $add('spec_version', 'unsupported "' . $spec . '"; supported: ' . implode(', ', self::SUPPORTED_SPEC_VERSIONS));
    }
    if (isset($def['machine_name']) && (string) $def['machine_name'] !== $name) {
      $add('machine_name', '"' . (string) $def['machine_name'] . '" must equal the file name "' . $name . '"');
    }

    // -- list ---------------------------------------------------------------
    $list = is_array($def['list'] ?? NULL) ? $def['list'] : [];
    foreach (array_keys($list) as $key) {
      if (!in_array((string) $key, ['fixture', 'xpath', 'fallback_xpath'], TRUE)) {
        $add('list.' . $key, 'unknown setting (allowed: fixture, xpath, fallback_xpath)');
      }
    }
    $fixture = trim((string) ($list['fixture'] ?? ''));
    if ($fixture === '') {
      $add('list.fixture', 'required (file name under data/fixtures/)');
    }
    elseif (str_contains($fixture, '/') || str_contains($fixture, '\\') || str_contains($fixture, '..')) {
      $add('list.fixture', 'must be a plain file name, got "' . $fixture . '"');
    }
    foreach (['xpath' => TRUE, 'fallback_xpath' => FALSE] as $key => $required) {
      $value = (string) ($list[$key] ?? '');
      if ($value === '') {
        if ($required) {
          $add('list.' . $key, 'required (XPath selecting the list links)');
        }
        continue;
      }
      if (!str_starts_with($value, '/') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value)) {
        $add('list.' . $key, 'must be an XPath starting with "/" or "//"');
      }
    }

    // -- item ---------------------------------------------------------------
    $item = is_array($def['item'] ?? NULL) ? $def['item'] : [];
    foreach (array_keys($item) as $key) {
      if (!in_array((string) $key, ['max_items', 'min_title_length', 'reject_title_pattern', 'collapse_whitespace', 'title', 'href', 'external_id', 'body'], TRUE)) {
        $add('item.' . $key, 'unknown setting');
      }
    }
    $maxItems = $item['max_items'] ?? NULL;
    if (!is_int($maxItems) || $maxItems < 1 || $maxItems > 500) {
      $add('item.max_items', 'must be an integer between 1 and 500');
    }
    $minLen = $item['min_title_length'] ?? NULL;
    if (!is_int($minLen) || $minLen < 1 || $minLen > 200) {
      $add('item.min_title_length', 'must be an integer between 1 and 200');
    }
    if (isset($item['collapse_whitespace']) && !is_bool($item['collapse_whitespace'])) {
      $add('item.collapse_whitespace', 'must be true or false');
    }
    if (!empty($item['reject_title_pattern']) && !self::isRegex(self::delimiterRegex((string) $item['reject_title_pattern']))) {
      $add('item.reject_title_pattern', 'not a usable PCRE: ' . $item['reject_title_pattern']);
    }
    foreach (['title' => ['text', 'attr'], 'href' => ['href', 'attr']] as $field => $heads) {
      $from = (string) ($item[$field]['from'] ?? '');
      if ($from === '') {
        $add('item.' . $field . '.from', 'required ("text" or "attr:<name>")');
        continue;
      }
      $head = str_contains($from, ':') ? substr($from, 0, strpos($from, ':')) : $from;
      if (!in_array($head, $heads, TRUE)) {
        $add('item.' . $field . '.from', '"' . $from . '" must start with one of: ' . implode(', ', $heads));
      }
    }
    $ext = is_array($item['external_id'] ?? NULL) ? $item['external_id'] : [];
    if (!in_array((string) ($ext['source'] ?? ''), self::ID_SOURCES, TRUE)) {
      $add('item.external_id.source', 'must be one of: ' . implode(', ', self::ID_SOURCES));
    }
    if (!isset($ext['prefix']) || !is_string($ext['prefix'])) {
      $add('item.external_id.prefix', 'must be a string (may be empty)');
    }
    $len = $ext['length'] ?? NULL;
    if (!is_int($len) || $len < 8 || $len > 64) {
      $add('item.external_id.length', 'must be an integer between 8 and 64');
    }
    $parts = $item['body']['parts'] ?? NULL;
    if (!is_array($parts) || $parts === []) {
      $add('item.body.parts', 'required non-empty list of body fragments');
    }
    else {
      foreach (array_values($parts) as $i => $part) {
        if (is_string($part)) {
          self::checkPlaceholders('item.body.parts[' . $i . ']', $part, $add);
          continue;
        }
        if (!is_array($part)) {
          $add('item.body.parts[' . $i . ']', 'must be a string or an {if, html} pair');
          continue;
        }
        foreach (array_keys($part) as $key) {
          if (!in_array((string) $key, ['if', 'html'], TRUE)) {
            $add('item.body.parts[' . $i . '].' . $key, 'unknown setting (allowed: if, html)');
          }
        }
        $cond = (string) ($part['if'] ?? '');
        if ($cond === '') {
          $add('item.body.parts[' . $i . '].if', 'required for a conditional fragment');
        }
        elseif (!in_array($cond, self::PLACEHOLDERS, TRUE)) {
          $add('item.body.parts[' . $i . '].if', 'unknown condition "' . $cond . '"; known: ' . implode(', ', self::PLACEHOLDERS));
        }
        $html = (string) ($part['html'] ?? '');
        if (trim($html) === '') {
          $add('item.body.parts[' . $i . '].html', 'required for a conditional fragment');
        }
        else {
          self::checkPlaceholders('item.body.parts[' . $i . '].html', $html, $add);
        }
      }
    }

    // -- detail -------------------------------------------------------------
    $detail = is_array($def['detail'] ?? NULL) ? $def['detail'] : [];
    $allowedDetail = [
      'fixture_dir', 'fixture_pattern', 'fixture_slug', 'title_xpath', 'body_xpath',
      'body_text_fallback', 'body_fallback_xpath', 'body_min_paragraph_length',
      'body_max_paragraphs', 'body_empty_html', 'published_xpath', 'source_xpath', 'meta',
    ];
    foreach (array_keys($detail) as $key) {
      if (!in_array((string) $key, $allowedDetail, TRUE)) {
        $add('detail.' . $key, 'unknown setting');
      }
    }
    foreach (['title_xpath' => TRUE, 'body_xpath' => TRUE, 'published_xpath' => FALSE, 'source_xpath' => FALSE] as $key => $required) {
      $candidates = $detail[$key] ?? [];
      if (!is_array($candidates)) {
        $add('detail.' . $key, 'must be a list of XPath candidates');
        continue;
      }
      if ($candidates === [] && $required) {
        $add('detail.' . $key, 'required non-empty list of XPath candidates');
        continue;
      }
      foreach (array_values($candidates) as $i => $expr) {
        if (!is_string($expr) || trim($expr) === '' || !str_starts_with(trim($expr), '/')) {
          $add('detail.' . $key . '[' . $i . ']', 'must be an XPath starting with "/"');
        }
      }
    }
    if (isset($detail['body_fallback_xpath']) && !str_starts_with((string) $detail['body_fallback_xpath'], '/')) {
      $add('detail.body_fallback_xpath', 'must be an XPath starting with "/"');
    }
    if (isset($detail['fixture_dir']) && (str_contains((string) $detail['fixture_dir'], '..') || str_starts_with((string) $detail['fixture_dir'], '/'))) {
      $add('detail.fixture_dir', 'must be a directory name inside data/fixtures/');
    }
    $pattern = (string) ($detail['fixture_pattern'] ?? '');
    if ($pattern !== '' && !self::isRegex($pattern)) {
      $add('detail.fixture_pattern', 'not a usable PCRE: ' . $pattern);
    }
    $slug = (string) ($detail['fixture_slug'] ?? '');
    if ($slug === '' || preg_match('/[^\w\-.{}]/u', $slug)) {
      $add('detail.fixture_slug', 'must only contain word characters, "-", "." and {n} captures, got "' . $slug . '"');
    }
    elseif (preg_match_all('/\{(\d+)\}/', $slug, $mm) && $mm[1] !== []) {
      $groups = 0;
      if (self::isRegex($pattern) && preg_match($pattern, '/news/1', $probe) === 1) {
        $groups = max(0, count($probe) - 1);
      }
      foreach ($mm[1] as $ref) {
        if ((int) $ref > $groups) {
          $add('detail.fixture_slug', 'refers to capture {' . $ref . '} but detail.fixture_pattern yields ' . $groups);
        }
      }
    }
    foreach (['body_min_paragraph_length' => 1, 'body_max_paragraphs' => 1] as $key => $unused) {
      $value = $detail[$key] ?? NULL;
      if (!is_int($value) || $value < 1 || $value > 100) {
        $add('detail.' . $key, 'must be an integer between 1 and 100');
      }
    }
    if (isset($detail['body_text_fallback'])) {
      self::checkPlaceholders('detail.body_text_fallback', (string) $detail['body_text_fallback'], $add);
    }
    if (trim((string) ($detail['body_empty_html'] ?? '')) === '') {
      $add('detail.body_empty_html', 'required (placeholder emitted when no body was found)');
    }

    // -- detail.meta --------------------------------------------------------
    $meta = is_array($detail['meta'] ?? NULL) ? $detail['meta'] : [];
    foreach (array_keys($meta) as $key) {
      if (!in_array((string) $key, ['order', 'format', 'join', 'wrapper'], TRUE)) {
        $add('detail.meta.' . $key, 'unknown setting (allowed: order, format, join, wrapper)');
      }
    }
    $order = $meta['order'] ?? [];
    if (!is_array($order)) {
      $add('detail.meta.order', 'must be a list');
      $order = [];
    }
    $format = is_array($meta['format'] ?? NULL) ? $meta['format'] : [];
    foreach (array_keys($format) as $key) {
      if (!in_array((string) $key, ['published', 'source'], TRUE)) {
        $add('detail.meta.format.' . $key, 'unknown meta field (allowed: published, source)');
      }
    }
    foreach (array_values($order) as $i => $field) {
      if (!is_string($field) || !isset($format[$field])) {
        $add('detail.meta.order[' . $i . ']', 'has no entry in detail.meta.format');
      }
    }
    foreach ($format as $field => $tpl) {
      self::checkPlaceholders('detail.meta.format.' . $field, (string) $tpl, $add);
    }
    if (isset($meta['join']) && !is_string($meta['join'])) {
      $add('detail.meta.join', 'must be a string');
    }
    if (isset($meta['order']) && is_array($meta['order']) && $meta['order'] !== []) {
      self::checkPlaceholders('detail.meta.wrapper', (string) ($meta['wrapper'] ?? ''), $add);
    }

    // -- fetch / resource ---------------------------------------------------
    $fetch = is_array($def['fetch'] ?? NULL) ? $def['fetch'] : [];
    foreach (array_keys($fetch) as $key) {
      if (!in_array((string) $key, ['timeout', 'user_agent'], TRUE)) {
        $add('fetch.' . $key, 'unknown setting (allowed: timeout, user_agent)');
      }
    }
    $timeout = $fetch['timeout'] ?? NULL;
    if (!is_int($timeout) || $timeout < 1 || $timeout > 120) {
      $add('fetch.timeout', 'must be an integer between 1 and 120');
    }
    if (trim((string) ($fetch['user_agent'] ?? '')) === '') {
      $add('fetch.user_agent', 'required');
    }
    $resource = is_array($def['resource'] ?? NULL) ? $def['resource'] : [];
    foreach (array_keys($resource) as $key) {
      if (!in_array((string) $key, ['type', 'status', 'review', 'detail_limit'], TRUE)) {
        $add('resource.' . $key, 'unknown setting (allowed: type, status, review, detail_limit)');
      }
    }
    $type = (string) ($resource['type'] ?? '');
    if (trim($type) === '') {
      $add('resource.type', 'required (DXEP resource type, e.g. article / notice)');
    }
    elseif (!preg_match('/^[a-z_]+$/', $type)) {
      $add('resource.type', 'must be lower-case with underscores, got "' . $type . '"');
    }
    if (!in_array((string) ($resource['status'] ?? ''), self::RESOURCE_STATUSES, TRUE)) {
      $add('resource.status', 'must be one of: ' . implode(', ', self::RESOURCE_STATUSES));
    }
    if (isset($resource['review']) && !is_bool($resource['review'])) {
      $add('resource.review', 'must be true or false');
    }
    $detailLimit = $resource['detail_limit'] ?? NULL;
    if (!is_int($detailLimit) || $detailLimit < 1 || $detailLimit > 40) {
      $add('resource.detail_limit', 'must be an integer between 1 and 40');
    }

    return $issues;
  }

  /**
   * Recursive merge where the override wins; lists are replaced whole.
   *
   * @param array<string, mixed> $base
   * @param array<string, mixed> $override
   *
   * @return array<string, mixed>
   */
  public static function mergeArrays(array $base, array $override): array {
    foreach ($override as $key => $value) {
      if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && self::isAssoc($value) && self::isAssoc($base[$key])) {
        $base[$key] = self::mergeArrays($base[$key], $value);
        continue;
      }
      $base[$key] = $value;
    }
    return $base;
  }

  /**
   * Wrap a bare PCRE body from a template in the runtime delimiter + /u.
   *
   * Templates store patterns without delimiters (`reject_title_pattern`), so
   * the loader that validates them and the adapter that runs them must agree on
   * exactly one wrapping rule.
   */
  public static function delimiterRegex(string $pattern): string {
    return '/' . str_replace('/', '\/', $pattern) . '/u';
  }

  /**
   * htmlspecialchars with the same flags the legacy adapter used.
   */
  public static function escape(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  }

  /**
   * Replace {name} / {name_esc} placeholders; unknown placeholders emit nothing.
   *
   * @param array<string, string> $vars
   */
  public static function renderTemplate(string $template, array $vars): string {
    return (string) preg_replace_callback('/\{([a-zA-Z_]+)\}/', static function (array $m) use ($vars): string {
      $name = $m[1];
      $escape = FALSE;
      if (str_ends_with($name, '_esc')) {
        $name = substr($name, 0, -4);
        $escape = TRUE;
      }
      $value = $vars[$name] ?? NULL;
      if ($value === NULL) {
        return '';
      }
      return $escape ? self::escape((string) $value) : (string) $value;
    }, $template);
  }

  /**
   * Render the declarative body parts for one list item.
   *
   * @param list<array{if?: string, html?: string}>|list<string> $parts
   * @param array<string, string> $vars
   */
  public static function renderParts(array $parts, array $vars): string {
    $html = '';
    foreach ($parts as $part) {
      if (is_string($part)) {
        $html .= self::renderTemplate($part, $vars);
        continue;
      }
      if (!is_array($part)) {
        continue;
      }
      $cond = (string) ($part['if'] ?? '');
      if ($cond !== '' && trim($vars[$cond] ?? '') === '') {
        continue;
      }
      $html .= self::renderTemplate((string) ($part['html'] ?? ''), $vars);
    }
    return $html;
  }

  /**
   * "{1}-{2}" style slug built from preg captures.
   *
   * @param array<int, string> $matches
   */
  public static function renderSlug(string $template, array $matches): string {
    return (string) preg_replace_callback('/\{(\d+)\}/', static fn(array $m): string => (string) ($matches[(int) $m[1]] ?? ''), $template);
  }

  /**
   * Machine name implied by a template file path, or NULL when unusable.
   */
  public static function machineName(string $path): ?string {
    $base = basename($path);
    $name = preg_replace('/\.(yml|yaml|json)$/i', '', $base) ?? '';
    if ($name === '' || !preg_match(self::MACHINE_NAME_PATTERN, $name)) {
      return NULL;
    }
    return $name;
  }

  /**
   * Resolve the `{name}` placeholder form of a configured body part list into
   * the variables the adapter can supply for one list item.
   *
   * @param array<string, mixed> $def
   * @param array<string, string> $vars
   */
  public function renderItemBody(array $def, array $vars): string {
    $parts = is_array($def['item']['body']['parts'] ?? NULL) ? $def['item']['body']['parts'] : [];
    /** @var list<string|array{if?: string, html?: string}> $parts */
    return self::renderParts($parts, $vars);
  }

  private static function isRegex(string $pattern): bool {
    if ($pattern === '') {
      return FALSE;
    }
    return @preg_match($pattern, '') !== FALSE;
  }

  /**
   * @param array<array-key, mixed> $value
   */
  private static function isAssoc(array $value): bool {
    if ($value === []) {
      return TRUE;
    }
    return array_keys($value) !== range(0, count($value) - 1);
  }

  /**
   * @param callable(string, string): void $add
   */
  private static function checkPlaceholders(string $field, string $template, callable $add): void {
    if ($template === '') {
      $add($field, 'required');
      return;
    }
    if (!preg_match_all('/\{([^{}]*)\}/', $template, $mm)) {
      return;
    }
    foreach ($mm[1] as $raw) {
      $raw = (string) $raw;
      if (preg_match('/^\d+$/', $raw)) {
        // Numeric captures belong to detail.fixture_slug, checked separately.
        continue;
      }
      $name = $raw;
      if (str_ends_with($name, '_esc')) {
        $name = substr($name, 0, -4);
      }
      if (!preg_match('/^[a-z_]+$/', $name)) {
        $add($field, 'malformed placeholder {' . $raw . '}');
      }
      elseif (!in_array($name, self::PLACEHOLDERS, TRUE)) {
        $add($field, 'unknown placeholder {' . $raw . '}; known names: ' . implode(', ', self::PLACEHOLDERS) . ' (append "_esc" to escape)');
      }
    }
  }

  /**
   * @return list<string>
   */
  private function listing(bool $includeInternal): array {
    $rows = [];
    foreach ($this->resolve() as $name => $row) {
      if (!$row['ok']) {
        continue;
      }
      if (!$includeInternal && str_starts_with($name, '_')) {
        continue;
      }
      $rows[$name] = (int) ($row['def']['weight'] ?? 0);
    }
    uksort($rows, static fn(string $a, string $b): int => ($rows[$a] <=> $rows[$b]) ?: strcmp($a, $b));
    return array_keys($rows);
  }

  private function invalidate(): void {
    $this->parsed = NULL;
    $this->resolved = NULL;
  }

  /**
   * Every template file on disk, parsed once (raw, no parent resolution).
   *
   * @return array<string, array{name: string, path: string, raw: array<string, mixed>|NULL, error: string}>
   */
  private function parseAll(): array {
    if ($this->parsed !== NULL) {
      return $this->parsed;
    }
    $out = [];
    foreach ($this->files() as $name => $path) {
      try {
        $out[$name] = ['name' => $name, 'path' => $path, 'raw' => $this->parseFile($path), 'error' => ''];
      }
      catch (\Throwable $e) {
        $out[$name] = ['name' => $name, 'path' => $path, 'raw' => NULL, 'error' => $e->getMessage()];
      }
    }
    $this->parsed = $out;
    return $out;
  }

  /**
   * @return array<string, array{ok: bool, def: array<string, mixed>, issues: list<array{field: string, issue: string}>}>
   */
  private function resolve(): array {
    if ($this->resolved !== NULL) {
      return $this->resolved;
    }
    $parsed = $this->parseAll();
    $parents = [];
    foreach ($parsed as $name => $row) {
      if ($row['raw'] !== NULL) {
        $parents[$name] = $row['raw'];
      }
    }
    $out = [];
    foreach ($parsed as $name => $row) {
      if ($row['raw'] === NULL) {
        $out[$name] = [
          'ok' => FALSE,
          'def' => [],
          'issues' => [['field' => 'file', 'issue' => 'cannot parse: ' . $row['error']]],
        ];
        continue;
      }
      $issues = [];
      try {
        $def = $this->finalize($name, $this->mergeParent($name, $row['raw'], $parents, []));
      }
      catch (L2TemplateException $e) {
        $def = [];
        $issues = $e->issues() !== [] ? $e->issues() : [['field' => 'extends', 'issue' => $e->getMessage()]];
      }
      if ($issues === []) {
        $issues = $this->validate($def, $name);
      }
      $out[$name] = ['ok' => $issues === [], 'def' => $def, 'issues' => $issues];
    }
    ksort($out);
    $this->resolved = $out;
    return $out;
  }

  /**
   * @param array<string, mixed> $def
   * @param array<string, array<string, mixed>> $parents
   * @param list<string> $stack
   *
   * @return array<string, mixed>
   *
   * @throws \Drupal\dx_migrate\Service\L2TemplateException
   */
  private function mergeParent(string $name, array $def, array $parents, array $stack): array {
    $parent = (string) ($def['extends'] ?? '');
    if ($parent === '') {
      return $def;
    }
    unset($def['extends']);
    if ($parent === $name || in_array($parent, $stack, TRUE)) {
      throw new L2TemplateException(sprintf(
        'Migrate template "%s" extends "%s" in a cycle (%s).',
        $name,
        $parent,
        implode(' -> ', array_merge($stack, [$name])),
      ), [['field' => 'extends', 'issue' => 'cycle through ' . $parent]]);
    }
    if (!isset($parents[$parent])) {
      throw new L2TemplateException(sprintf(
        'Migrate template "%s" extends "%s", which is not a readable template file.',
        $name,
        $parent,
      ), [['field' => 'extends', 'issue' => 'unknown parent "' . $parent . '"']]);
    }
    $inherited = $this->mergeParent($parent, $parents[$parent], $parents, array_merge($stack, [$name]));
    return self::mergeArrays($inherited, $def);
  }

  /**
   * Strip loader-only keys and apply the hard defaults.
   *
   * @param array<string, mixed> $def
   *
   * @return array<string, mixed>
   */
  private function finalize(string $name, array $def): array {
    unset($def['extends']);
    if (!isset($def['machine_name'])) {
      $def['machine_name'] = $name;
    }
    $def['weight'] = (int) ($def['weight'] ?? 0);
    $def['spec_version'] = (string) ($def['spec_version'] ?? '1.0');
    return $def;
  }

  /**
   * @return array<string, mixed>
   */
  private function parseFile(string $path): array {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($ext === 'json') {
      $decoded = json_decode((string) file_get_contents($path), TRUE);
      if (!is_array($decoded)) {
        throw new \InvalidArgumentException('not a JSON object');
      }
      return $decoded;
    }
    $parsed = Yaml::parseFile($path);
    if (!is_array($parsed)) {
      throw new \InvalidArgumentException('not a YAML mapping');
    }
    return $parsed;
  }

}
