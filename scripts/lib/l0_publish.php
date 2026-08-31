<?php

declare(strict_types=1);

/**
 * L0 public-tree publisher and CI visibility gate (no Drupal bootstrap).
 *
 * Run standalone in CI without .env or a database:
 *   php scripts/lib/l0_publish.php --gate
 *   php scripts/lib/l0_publish.php --plan --format=json --visibility=public
 *   php scripts/lib/l0_publish.php --publish=/tmp/l0-export
 */

use Symfony\Component\Yaml\Yaml;

/**
 * @return array{
 *   version: int,
 *   layer: string,
 *   include: list<string>,
 *   exclude: list<string>,
 *   must_include: list<string>,
 *   must_exclude: list<string>,
 *   gate: array<string, mixed>
 * }
 */
function dx_l0_load_whitelist(string $root): array {
  $file = $root . '/docs/l0-whitelist.yml';
  if (!is_file($file)) {
    throw new RuntimeException('Missing docs/l0-whitelist.yml');
  }
  $data = Yaml::parseFile($file);
  if (!is_array($data)) {
    throw new RuntimeException('Invalid L0 whitelist YAML');
  }
  foreach (['include', 'exclude', 'must_include', 'must_exclude'] as $key) {
    $data[$key] = array_values(array_filter(array_map('strval', $data[$key] ?? []), static fn($p) => $p !== ''));
  }
  $data['gate'] = is_array($data['gate'] ?? NULL) ? $data['gate'] : [];
  return $data;
}

function dx_l0_assert_relative(string $path): string {
  $path = str_replace('\\', '/', $path);
  if ($path === '' || $path[0] === '/' || str_contains($path, '..')) {
    throw new RuntimeException('Refusing unsafe whitelist path: ' . $path);
  }
  return $path;
}

function dx_l0_rm_tree(string $path): void {
  if (!file_exists($path)) {
    return;
  }
  if (is_file($path) || is_link($path)) {
    unlink($path);
    return;
  }
  $it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST,
  );
  foreach ($it as $item) {
    $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
  }
  rmdir($path);
}

function dx_l0_copy_tree(string $src, string $dest): void {
  if (is_file($src)) {
    $dir = dirname($dest);
    if (!is_dir($dir) && !mkdir($dir, 0775, TRUE) && !is_dir($dir)) {
      throw new RuntimeException('Cannot mkdir ' . $dir);
    }
    if (!copy($src, $dest)) {
      throw new RuntimeException('Copy failed: ' . $src);
    }
    return;
  }
  if (!is_dir($src)) {
    throw new RuntimeException('Missing include path: ' . $src);
  }
  $it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST,
  );
  if (!is_dir($dest) && !mkdir($dest, 0775, TRUE) && !is_dir($dest)) {
    throw new RuntimeException('Cannot mkdir ' . $dest);
  }
  foreach ($it as $item) {
    $rel = substr($item->getPathname(), strlen($src) + 1);
    $target = $dest . '/' . $rel;
    if ($item->isDir()) {
      if (!is_dir($target) && !mkdir($target, 0775, TRUE) && !is_dir($target)) {
        throw new RuntimeException('Cannot mkdir ' . $target);
      }
      continue;
    }
    $tdir = dirname($target);
    if (!is_dir($tdir) && !mkdir($tdir, 0775, TRUE) && !is_dir($tdir)) {
      throw new RuntimeException('Cannot mkdir ' . $tdir);
    }
    copy($item->getPathname(), $target);
  }
}

function dx_l0_api_docs_html(string $spec_url, string $title = 'DrupalX DXEP API'): string {
  $spec = htmlspecialchars($spec_url, ENT_QUOTES | ENT_HTML5);
  $h = htmlspecialchars($title, ENT_QUOTES | ENT_HTML5);
  $spec_json = json_encode($spec_url, JSON_UNESCAPED_SLASHES);
  return <<<HTML
<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{$h}</title>
  <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5.17.14/swagger-ui.css">
  <style>
    body { margin: 0; background: #0b1220; }
    .dx-api-banner { font: 14px/1.4 system-ui, sans-serif; color: #e8eefc; padding: 12px 20px; }
    .dx-api-banner a { color: #9ec1ff; }
  </style>
</head>
<body>
  <div class="dx-api-banner">DrupalX public API (L0) · DXEP v1 · <a href="{$spec}">OpenAPI source</a></div>
  <div id="swagger-ui"></div>
  <script src="https://unpkg.com/swagger-ui-dist@5.17.14/swagger-ui-bundle.js"></script>
  <script>
    window.onload = function () {
      window.ui = SwaggerUIBundle({
        url: {$spec_json},
        dom_id: '#swagger-ui',
        presets: [SwaggerUIBundle.presets.apis],
        layout: 'BaseLayout'
      });
    };
  </script>
</body>
</html>
HTML;
}

function dx_l0_apply_visibility(string $root, string $dest): int {
  $file = $root . '/docs/visibility.yml';
  if (!is_file($file)) {
    return 0;
  }
  $map = Yaml::parseFile($file);
  $paths = is_array($map['paths'] ?? NULL) ? $map['paths'] : [];
  $removed = 0;
  foreach ($paths as $rel => $vis) {
    if ((string) $vis === 'public') {
      continue;
    }
    $rel = dx_l0_assert_relative((string) $rel);
    $target = $dest . '/' . $rel;
    if (file_exists($target)) {
      dx_l0_rm_tree($target);
      $removed++;
    }
  }
  return $removed;
}

/**
 * @param array<string,mixed> $whitelist
 * @return array{dest:string,copied:int,removed:int}
 */
function dx_l0_publish(string $root, string $dest, array $whitelist): array {
  $root = rtrim($root, '/');
  $dest = rtrim($dest, '/');
  if ($dest === '' || $dest === $root || str_starts_with($dest, $root . '/')) {
    throw new RuntimeException('Refusing to publish into the source tree: ' . $dest);
  }
  dx_l0_rm_tree($dest);
  if (!mkdir($dest, 0775, TRUE) && !is_dir($dest)) {
    throw new RuntimeException('Cannot create dest ' . $dest);
  }

  $copied = 0;
  foreach ($whitelist['include'] as $rel) {
    $rel = dx_l0_assert_relative($rel);
    $src = $root . '/' . $rel;
    dx_l0_copy_tree($src, $dest . '/' . $rel);
    $copied++;
  }

  $removed = 0;
  foreach ($whitelist['exclude'] as $rel) {
    $rel = dx_l0_assert_relative($rel);
    $target = $dest . '/' . $rel;
    if (file_exists($target)) {
      dx_l0_rm_tree($target);
      $removed++;
    }
  }
  $removed += dx_l0_apply_visibility($root, $dest);

  $apiDir = $dest . '/docs/api';
  if (!is_dir($apiDir) && !mkdir($apiDir, 0775, TRUE) && !is_dir($apiDir)) {
    throw new RuntimeException('Cannot mkdir ' . $apiDir);
  }
  $html = dx_l0_api_docs_html('../openapi/dxep-v1.yaml');
  file_put_contents($apiDir . '/index.html', $html);

  $readme = <<<MD
# DrupalX L0 public tree

This directory is a **public Foundation** snapshot (open-ecosystem L0).

- OpenAPI: `docs/openapi/dxep-v1.yaml`
- API docs: `docs/api/index.html`
- Whitelist: `docs/l0-whitelist.yml`

L2 partner vault files are not included. Do not copy production `.env`,
keys, or HA failover scripts into a public clone.

MD;
  file_put_contents($dest . '/L0-README.md', $readme);

  return ['dest' => $dest, 'copied' => $copied, 'removed' => $removed];
}

/**
 * Write the in-repo static API docs page (source tree).
 */
function dx_l0_write_repo_api_docs(string $root): string {
  $dir = $root . '/docs/api';
  if (!is_dir($dir) && !mkdir($dir, 0775, TRUE) && !is_dir($dir)) {
    throw new RuntimeException('Cannot mkdir ' . $dir);
  }
  $path = $dir . '/index.html';
  file_put_contents($path, dx_l0_api_docs_html('../openapi/dxep-v1.yaml'));
  return $path;
}

/**
 * @param array<string,mixed> $whitelist
 */
function dx_l0_verify(string $dest, array $whitelist): void {
  foreach ($whitelist['must_include'] as $rel) {
    $rel = dx_l0_assert_relative($rel);
    $path = $dest . '/' . $rel;
    if (!file_exists($path)) {
      throw new RuntimeException('L0 export missing required path: ' . $rel);
    }
  }
  if (!is_file($dest . '/docs/api/index.html')) {
    throw new RuntimeException('L0 export missing docs/api/index.html');
  }
  $html = (string) file_get_contents($dest . '/docs/api/index.html');
  if (!str_contains($html, 'swagger-ui') || !str_contains($html, 'dxep-v1.yaml')) {
    throw new RuntimeException('API docs HTML did not embed Swagger UI / OpenAPI');
  }
  foreach ($whitelist['must_exclude'] as $rel) {
    $rel = dx_l0_assert_relative($rel);
    if (file_exists($dest . '/' . $rel)) {
      throw new RuntimeException('L0 export leaked excluded path: ' . $rel);
    }
  }
}

/**
 * Stable exit codes for CI. Scripts and docs quote these numbers.
 *
 * @return array<string, int>
 */
function dx_l0_exit_codes(): array {
  return [
    'DX.L0.OK' => 0,
    'DX.L0.ERROR' => 1,
    'DX.L0.USAGE' => 2,
    'DX.L0.WHITELIST_MISSING' => 3,
    'DX.L0.UNREGISTERED' => 4,
    'DX.L0.VISIBILITY_UNKNOWN' => 5,
    'DX.L0.PATH_UNSAFE' => 6,
    'DX.L0.DEST_REFUSED' => 7,
    'DX.L0.VERIFY_FAILED' => 8,
    'DX.L0.INCLUDE_MISSING' => 9,
    // Warnings carry their own code but never fail a run.
    'DX.L0.STALE_RULE' => 0,
  ];
}

function dx_l0_exit_code(string $code): int {
  return dx_l0_exit_codes()[$code] ?? 1;
}

/**
 * @return list<string>
 */
function dx_l0_visibilities(): array {
  return ['public', 'partner', 'internal'];
}

/**
 * One finding: which file, which stable code, does it block the export?
 *
 * @return array{code: string, path: string, message: string, severity: string}
 */
function dx_l0_issue(string $code, string $path, string $message, string $severity = 'error'): array {
  return ['code' => $code, 'path' => $path, 'message' => $message, 'severity' => $severity];
}

/**
 * The findings that make `--gate` fail.
 *
 * @param list<array<string,mixed>> $issues
 *
 * @return list<array<string,mixed>>
 */
function dx_l0_errors(array $issues): array {
  return array_values(array_filter(
    $issues,
    static fn(array $i): bool => ($i['severity'] ?? 'error') === 'error' && ($i['code'] ?? 'DX.L0.OK') !== 'DX.L0.OK',
  ));
}

/**
 * Gate settings, with the defaults that keep today's behaviour reproducible.
 *
 * @param array<string,mixed> $whitelist
 *
 * @return array{
 *   enforce: bool,
 *   register: list<string>,
 *   scan: list<string>,
 *   require_explicit: list<string>,
 *   skip_dirs: list<string>
 * }
 */
function dx_l0_gate_config(array $whitelist): array {
  $gate = is_array($whitelist['gate'] ?? NULL) ? $whitelist['gate'] : [];
  $list = static function (array $source, string $key, array $fallback): array {
    $values = $source[$key] ?? $fallback;
    if (!is_array($values)) {
      return $fallback;
    }
    return array_values(array_filter(array_map('strval', $values), static fn(string $v): bool => $v !== ''));
  };
  return [
    'enforce' => !array_key_exists('enforce', $gate) ? TRUE : (bool) $gate['enforce'],
    'register' => $list($gate, 'register', ['*.md']),
    'scan' => $list($gate, 'scan', $whitelist['include'] ?? []),
    'require_explicit' => $list($gate, 'require_explicit', []),
    'skip_dirs' => $list($gate, 'skip_dirs', ['vendor', 'node_modules', '.git']),
  ];
}

/**
 * Translate a shell-ish pattern (`*.md`, or `docs/` + double-star globs) into a regex.
 */
function dx_l0_pattern_regex(string $pattern): string {
  $quoted = preg_quote($pattern, '#');
  $quoted = str_replace(
    ['\*\*/', '\*\*', '\*'],
    ['(?:[^/]+/)*', '.*', '[^/]*'],
    $quoted,
  );
  return '#^' . $quoted . '$#';
}

/**
 * Does this repository-relative path need an explicit visibility decision?
 */
function dx_l0_matches_patterns(string $rel, array $patterns): bool {
  $base = basename($rel);
  foreach ($patterns as $pattern) {
    $subject = str_contains($pattern, '/') ? $rel : $base;
    if (preg_match(dx_l0_pattern_regex($pattern), $subject) === 1) {
      return TRUE;
    }
  }
  return FALSE;
}

/**
 * `docs/visibility.yml` as `{default: string, paths: array<string,string>}`.
 *
 * @return array{default: string, paths: array<string, string>, file: string}
 */
function dx_l0_visibility_map(string $root): array {
  $file = $root . '/docs/visibility.yml';
  if (!is_file($file)) {
    return ['default' => 'public', 'paths' => [], 'file' => $file];
  }
  $map = Yaml::parseFile($file);
  if (!is_array($map)) {
    throw new RuntimeException('Invalid docs/visibility.yml');
  }
  $paths = [];
  foreach ((array) ($map['paths'] ?? []) as $rel => $visibility) {
    $paths[str_replace('\\', '/', (string) $rel)] = strtolower(trim((string) $visibility));
  }
  return [
    'default' => strtolower(trim((string) ($map['default'] ?? 'public'))) ?: 'public',
    'paths' => $paths,
    'file' => $file,
  ];
}

/**
 * Resolve one path against the visibility map: exact key first, then the
 * longest directory key (inherited), then the map's default (which still counts
 * as *unregistered*, because nobody looked at the file).
 *
 * @param array<string, string> $paths
 *
 * @return array{visibility: string, key: string, inherited: bool}
 */
function dx_l0_visibility_lookup(string $rel, array $paths, string $default = 'public'): array {
  if (isset($paths[$rel])) {
    return ['visibility' => $paths[$rel], 'key' => $rel, 'inherited' => FALSE];
  }
  $best = '';
  foreach (array_keys($paths) as $key) {
    if (!str_starts_with($rel, $key . '/')) {
      continue;
    }
    if (strlen($key) > strlen($best)) {
      $best = $key;
    }
  }
  if ($best !== '') {
    return ['visibility' => $paths[$best], 'key' => $best, 'inherited' => TRUE];
  }
  return ['visibility' => $default, 'key' => '', 'inherited' => FALSE];
}

/**
 * Is `$rel` inside one of these whitelist entries (file or directory)?
 */
function dx_l0_in_any(string $rel, array $prefixes): bool {
  foreach ($prefixes as $prefix) {
    if ($rel === $prefix || str_starts_with($rel, $prefix . '/')) {
      return TRUE;
    }
  }
  return FALSE;
}

/**
 * Every file under one include entry, repository-relative, sorted.
 *
 * @param list<string> $skipDirs
 *
 * @return list<string>
 */
function dx_l0_list_entry(string $root, string $rel, array $skipDirs = []): array {
  $abs = $root . '/' . $rel;
  if (is_file($abs) || is_link($abs)) {
    return [$rel];
  }
  if (!is_dir($abs)) {
    return [];
  }
  $out = [];
  $items = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($abs, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST,
  );
  /** @var SplFileInfo $item */
  foreach ($items as $item) {
    $path = str_replace('\\', '/', substr($item->getPathname(), strlen($root) + 1));
    // A skipped directory anywhere in the chain takes its subtree with it.
    if ($skipDirs !== [] && array_intersect($skipDirs, explode('/', $path)) !== []) {
      continue;
    }
    if ($item->isDir() || is_link($item->getPathname())) {
      continue;
    }
    $out[] = $path;
  }
  sort($out);
  return $out;
}

/**
 * The full classification of the tree the whitelist would publish (I4).
 *
 * Reads only: no writes, no Drupal, no database, no network.
 *
 * @return array{
 *   ok: bool,
 *   code: string,
 *   root: string,
 *   version: int,
 *   layer: string,
 *   license: string,
 *   default_visibility: string,
 *   gate: array<string, mixed>,
 *   entries: list<array<string, mixed>>,
 *   published: list<array<string, mixed>>,
 *   stripped: list<array<string, mixed>>,
 *   unregistered: list<array<string, mixed>>,
 *   stale: list<array<string, mixed>>,
 *   issues: list<array<string, mixed>>,
 *   counts: array<string, int>,
 *   files_seen: int
 * }
 */
function dx_l0_plan(string $root, ?array $whitelist = NULL): array {
  $root = rtrim($root, '/');
  $issues = [];
  try {
    $whitelist = $whitelist ?? dx_l0_load_whitelist($root);
  }
  catch (RuntimeException $e) {
    return [
      'ok' => FALSE,
      'code' => str_contains($e->getMessage(), 'Missing') ? 'DX.L0.WHITELIST_MISSING' : 'DX.L0.ERROR',
      'root' => $root,
      'version' => 0,
      'layer' => '',
      'license' => '',
      'default_visibility' => '',
      'gate' => dx_l0_gate_config([]),
      'entries' => [],
      'published' => [],
      'stripped' => [],
      'unregistered' => [],
      'stale' => [],
      'issues' => [dx_l0_issue('DX.L0.WHITELIST_MISSING', 'docs/l0-whitelist.yml', $e->getMessage())],
      'counts' => ['public' => 0, 'partner' => 0, 'internal' => 0, 'unregistered' => 0, 'stale' => 0],
      'files_seen' => 0,
    ];
  }
  $gate = dx_l0_gate_config($whitelist);
  $visibility = dx_l0_visibility_map($root);
  $issues = [];
  $entries = [];
  $stale = [];
  $filesSeen = 0;

  foreach ($visibility['paths'] as $key => $value) {
    if (!in_array($value, dx_l0_visibilities(), TRUE)) {
      $issues[] = dx_l0_issue('DX.L0.VISIBILITY_UNKNOWN', $key, 'docs/visibility.yml 中的取值必须是 public|partner|internal，当前为 ' . ($value === '' ? '(空)' : $value));
    }
  }

  foreach ($gate['scan'] as $entry) {
    try {
      $entry = dx_l0_assert_relative($entry);
    }
    catch (RuntimeException $e) {
      $issues[] = dx_l0_issue('DX.L0.PATH_UNSAFE', $entry, $e->getMessage());
      continue;
    }
    if (!file_exists($root . '/' . $entry)) {
      $issues[] = dx_l0_issue('DX.L0.INCLUDE_MISSING', $entry, '白名单条目在仓库里不存在，include 与文档树已经不一致');
      continue;
    }
    foreach (dx_l0_list_entry($root, $entry, $gate['skip_dirs']) as $path) {
      $filesSeen++;
      if (dx_l0_in_any($path, $whitelist['exclude'])) {
        // Never exported: exclude already wins over include.
        continue;
      }
      if (!dx_l0_matches_patterns($path, $gate['register'])) {
        continue;
      }
      $hit = dx_l0_visibility_lookup($path, $visibility['paths'], $visibility['default']);
      // In a `require_explicit` directory, inheriting the directory rule is not a
      // decision about *this* file: it needs its own visibility key.
      $strictDir = in_array(dirname($path), $gate['require_explicit'], TRUE);
      $registered = $hit['key'] !== '' && !($strictDir && $hit['inherited']);
      $row = [
        'path' => $path,
        'visibility' => $registered ? $hit['visibility'] : 'unregistered',
        'declared_by' => $hit['key'],
        'inherited' => $hit['inherited'],
        'registered' => $registered,
      ];
      $row['action'] = !$registered
        ? 'undecided'
        : ($row['visibility'] === 'public' ? 'publish' : 'strip');
      if (!$registered) {
        $issues[] = dx_l0_issue('DX.L0.UNREGISTERED', $path, $strictDir && $hit['key'] !== ''
          ? '新文档只继承了目录规则：在 docs/visibility.yml 里为它单独写一行'
          : '新文档未登记可见性：在 docs/visibility.yml 里写明 public / partner / internal 后再提交');
      }
      $entries[] = $row;
    }
  }

  foreach (array_keys($visibility['paths']) as $key) {
    if (!file_exists($root . '/' . $key)) {
      $stale[] = ['path' => $key];
      $issues[] = dx_l0_issue('DX.L0.STALE_RULE', $key, 'visibility.yml 条目在仓库里已不存在（拼写错误或文件已删除）', 'warning');
    }
  }

  $published = $stripped = $unregistered = [];
  foreach ($entries as $row) {
    if (!$row['registered']) {
      $unregistered[] = $row;
      continue;
    }
    if ($row['action'] === 'publish') {
      $published[] = $row;
    }
    else {
      $stripped[] = $row;
    }
  }

  $code = 'DX.L0.OK';
  foreach (dx_l0_errors($issues) as $issue) {
    $code = (string) $issue['code'];
    break;
  }
  if ($gate['enforce'] === FALSE && $code === 'DX.L0.UNREGISTERED') {
    // `gate.enforce: false` downgrades missing registrations to warnings.
    $code = 'DX.L0.OK';
  }

  return [
    'ok' => $code === 'DX.L0.OK',
    'code' => $code,
    'root' => $root,
    'version' => (int) ($whitelist['version'] ?? 0),
    'layer' => (string) ($whitelist['layer'] ?? 'L0'),
    'license' => (string) ($whitelist['license'] ?? ''),
    'default_visibility' => $visibility['default'],
    'gate' => $gate,
    'entries' => $entries,
    'published' => $published,
    'stripped' => $stripped,
    'unregistered' => $unregistered,
    'stale' => $stale,
    'issues' => $issues,
    'counts' => [
      'public' => count($published),
      'partner' => count(array_filter($stripped, static fn(array $r): bool => $r['visibility'] === 'partner')),
      'internal' => count(array_filter($stripped, static fn(array $r): bool => $r['visibility'] === 'internal')),
      'unregistered' => count($unregistered),
      'stale' => count($stale),
    ],
    'files_seen' => $filesSeen,
  ];
}

/**
 * Pick one visibility bucket out of a plan: public | partner | internal.
 *
 * @param array<string,mixed> $plan
 *
 * @return list<array<string, mixed>>
 */
function dx_l0_filter(array $plan, string $visibility): array {
  $visibility = strtolower(trim($visibility));
  return match ($visibility) {
    'public' => array_values(array_filter(
      $plan['entries'] ?? [],
      static fn(array $r): bool => ($r['action'] ?? '') === 'publish',
    )),
    'partner', 'internal' => array_values(array_filter(
      $plan['entries'] ?? [],
      static fn(array $r): bool => ($r['action'] ?? '') === 'strip' && $r['visibility'] === $visibility,
    )),
    'unregistered' => array_values($plan['unregistered'] ?? []),
    default => [],
  };
}

/**
 * Gate verdict for a plan: OK, or the first blocking issue's code.
 *
 * @param array<string,mixed> $plan
 *
 * @return array{ok: bool, code: string, exit: int, issues: list<array<string,mixed>>}
 */
function dx_l0_gate(array $plan): array {
  $code = (string) ($plan['code'] ?? 'DX.L0.ERROR');
  $issues = dx_l0_errors($plan['issues'] ?? []);
  return [
    'ok' => $code === 'DX.L0.OK',
    'code' => $code,
    'exit' => $code === 'DX.L0.OK' ? 0 : dx_l0_exit_code($code),
    'issues' => $issues,
    'warnings' => array_values(array_filter(
      $plan['issues'] ?? [],
      static fn(array $i): bool => ($i['severity'] ?? 'error') === 'warning',
    )),
  ];
}

/**
 * Human-readable plan summary (CI logs).
 *
 * @param array<string,mixed> $plan
 */
function dx_l0_render_text(array $plan, string $visibility = ''): string {
  $gate = dx_l0_gate($plan);
  $counts = (array) ($plan['counts'] ?? []);
  $lines = [];
  $lines[] = sprintf(
    'L0 gate %s · whitelist v%d · layer %s · 扫描 %d 文件 · public %d / partner %d / internal %d / 未登记 %d',
    $gate['code'],
    (int) ($plan['version'] ?? 0),
    (string) ($plan['layer'] ?? ''),
    (int) ($plan['files_seen'] ?? 0),
    (int) ($counts['public'] ?? 0),
    (int) ($counts['partner'] ?? 0),
    (int) ($counts['internal'] ?? 0),
    (int) ($counts['unregistered'] ?? 0),
  );
  if ($visibility !== '') {
    $lines[] = sprintf('  %s 清单（%d）:', $visibility, count(dx_l0_filter($plan, $visibility)));
    foreach (dx_l0_filter($plan, $visibility) as $row) {
      $lines[] = '    ' . $row['path'];
    }
  }
  if (!$gate['ok']) {
    foreach ($gate['issues'] as $issue) {
      $lines[] = sprintf('  [%s] %s — %s', $issue['code'], $issue['path'], $issue['message']);
    }
  }
  foreach ($gate['warnings'] as $warning) {
    $lines[] = sprintf('  [%s] %s — %s（不阻断）', $warning['code'], $warning['path'], $warning['message']);
  }
  return implode("\n", $lines) . "\n";
}

/**
 * Writes the publish manifest next to the export so reviewers see the list.
 *
 * @param array<string,mixed> $plan
 */
function dx_l0_write_manifest(string $dest, array $plan): string {
  $rows = dx_l0_filter($plan, 'public');
  $lines = ['# DrupalX L0 publish manifest', '# generated by scripts/lib/l0_publish.php', '# code: ' . ($plan['code'] ?? '')];
  foreach ($rows as $row) {
    $lines[] = 'public ' . $row['path'];
  }
  foreach (array_merge(dx_l0_filter($plan, 'partner'), dx_l0_filter($plan, 'internal')) as $row) {
    $lines[] = 'excluded(' . $row['visibility'] . ') ' . $row['path'];
  }
  $path = rtrim($dest, '/') . '/L0-MANIFEST.txt';
  file_put_contents($path, implode("\n", $lines) . "\n");
  return $path;
}

function dx_l0_usage(): string {
  $codes = json_encode(dx_l0_exit_codes(), JSON_UNESCAPED_SLASHES);
  return <<<TXT
Usage: php scripts/lib/l0_publish.php [options]

  --root=PATH       Repository root (default: parent of scripts/).
  --gate            Classify the tree and exit with the gate code (default).
  --plan            Same classification, informational only: always exits 0.
  --publish=DEST    Gate, then build the export tree into DEST and verify it.
  --format=json     Machine-readable plan.
  --visibility=V    Also list one bucket: public | partner | internal | unregistered.
  --help            This text.

Exit codes (DX.L0.* → number): $codes
TXT;
}

/**
 * CLI entry point. Returns the process exit code.
 *
 * @param list<string> $argv
 */
function dx_l0_main(array $argv): int {
  // Standalone CI run: the library itself never autoloads, the callers do.
  if (!class_exists(Yaml::class)) {
    $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
    if (!is_file($autoload)) {
      fwrite(STDERR, "L0: 缺少 vendor/autoload.php（CI 镜像需要先 composer install）\n");
      return dx_l0_exit_code('DX.L0.ERROR');
    }
    require $autoload;
  }
  $options = [];
  foreach (array_slice($argv, 1) as $arg) {
    if (!str_starts_with($arg, '--')) {
      fwrite(STDERR, 'L0: 不认识的参数 ' . $arg . "\n");
      return dx_l0_exit_code('DX.L0.USAGE');
    }
    [$name, $value] = array_pad(explode('=', substr($arg, 2), 2), 2, TRUE);
    $options[(string) $name] = $value;
  }
  if (isset($options['help'])) {
    fwrite(STDOUT, dx_l0_usage() . "\n");
    return 0;
  }
  $root = (string) ($options['root'] ?? dirname(dirname(__DIR__)));
  $plan = dx_l0_plan($root);
  $gate = dx_l0_gate($plan);
  $visibility = (string) ($options['visibility'] ?? '');
  $isJson = isset($options['format']) && (string) $options['format'] === 'json';

  if (isset($options['publish'])) {
    $dest = (string) $options['publish'];
    if (!$gate['ok']) {
      fwrite(STDERR, dx_l0_render_text($plan, $visibility));
      return $gate['exit'];
    }
    try {
      $whitelist = dx_l0_load_whitelist($root);
      $report = dx_l0_publish($root, $dest, $whitelist);
      dx_l0_verify($dest, $whitelist);
      dx_l0_write_manifest($dest, $plan);
    }
    catch (RuntimeException $e) {
      $message = $e->getMessage();
      $code = str_contains($message, 'Refusing to publish into the source tree')
        ? 'DX.L0.DEST_REFUSED'
        : (str_contains($message, 'excluded path') || str_contains($message, 'required path') || str_contains($message, 'Swagger')
          ? 'DX.L0.VERIFY_FAILED'
          : (str_contains($message, 'whitelist path') ? 'DX.L0.PATH_UNSAFE' : 'DX.L0.ERROR'));
      fwrite(STDERR, 'L0 publish 失败 [' . $code . ']: ' . $message . "\n");
      return dx_l0_exit_code($code);
    }
    fwrite(STDOUT, sprintf("L0 export 已写入 %s（include %d / removed %d）\n", $report['dest'], $report['copied'], $report['removed']));
  }

  if ($isJson) {
    $payload = $plan;
    if ($visibility !== '') {
      $payload['filtered'] = dx_l0_filter($plan, $visibility);
    }
    fwrite(STDOUT, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
  }
  else {
    fwrite(STDOUT, dx_l0_render_text($plan, $visibility));
  }
  if (isset($options['plan'])) {
    // Informational: the caller only wants the list, not the verdict.
    return 0;
  }
  return $gate['exit'];
}

if (PHP_SAPI === 'cli' && isset($_SERVER['argv'][0]) && realpath($_SERVER['argv'][0]) === realpath(__FILE__)) {
  exit(dx_l0_main(array_map('strval', $_SERVER['argv'])));
}
