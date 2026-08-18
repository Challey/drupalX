<?php

declare(strict_types=1);

/**
 * L0 public-tree publisher (no Drupal bootstrap required).
 */

use Symfony\Component\Yaml\Yaml;

/**
 * @return array{version:int,layer:string,include:list<string>,exclude:list<string>,must_include:list<string>,must_exclude:list<string>}
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
