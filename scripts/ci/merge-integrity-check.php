<?php

/**
 * Merge integrity check: catches the failure modes a mass branch merge creates.
 *
 * Drupal blows up at bootstrap when two providers declare the same entity type
 * id, when two files declare the same class, when a routing yml path is reused,
 * or when a controller @Route duplicates an existing route name. A cross-branch
 * merge produces exactly these, silently, because each side is valid alone.
 *
 * Usage: php scripts/ci/merge-integrity-check.php [dir ...]
 * Exit 0 = clean, 1 = duplicates found.
 */
declare(strict_types=1);

$roots = array_slice($argv, 1);
if ($roots === []) {
  $roots = ['web/modules/custom', 'web/themes/custom'];
}

$failures = 0;

/** @return list<string> */
function mic_files(string $root, string $pattern): array {
  if (!is_dir($root)) {
    return [];
  }
  $out = [];
  $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
  foreach ($it as $file) {
    /** @var SplFileInfo $file */
    if ($file->isFile() && preg_match($pattern, $file->getFilename())) {
      $out[] = $file->getPathname();
    }
  }
  sort($out);
  return $out;
}

function mic_report(string $label, array $groups): void {
  global $failures;
  foreach ($groups as $key => $where) {
    if (count($where) < 2) {
      continue;
    }
    $failures++;
    echo "DUPLICATE $label: $key\n";
    foreach ($where as $path) {
      echo "    $path\n";
    }
  }
}

$entities = [];
$classes = [];
$routeNames = [];
$routePaths = [];
$serviceIds = [];

foreach ($roots as $root) {
  foreach (mic_files($root, '/\.php$/') as $path) {
    $src = (string) file_get_contents($path);
    if (preg_match('/^namespace\s+([^;]+);/m', $src, $m)) {
      $ns = trim($m[1]);
      if (preg_match_all('/^\s*(?:final\s+|abstract\s+)?(?:class|interface|trait|enum)\s+(\w+)/m', $src, $mm)) {
        foreach ($mm[1] as $name) {
          $classes[strtolower($ns . '\\' . $name)][] = $path;
        }
      }
    }
    if (preg_match_all('/@(?:Content)?EntityType\(|#\[(?:Content)?EntityType\(/', $src)) {
      if (preg_match('/(?:id:|id =)\s*[\'"]([a-z0-9_]+)[\'"]/', $src, $m)) {
        $entities[$m[1]][] = $path;
      }
    }
    if (preg_match_all('/#\[Route\(\s*path:\s*[\'"]([^\'"]+)[\'"][^)]*name:\s*[\'"]([^\'"]+)[\'"]/s', $src, $mm, PREG_SET_ORDER)) {
      foreach ($mm as $r) {
        $routeNames[$r[2]][] = $path;
        $routePaths['GET ' . $r[1]][] = $path;
      }
    }
    if (preg_match_all('/#\[Route\(\s*path:\s*[\'"]([^\'"]+)[\'"][^)]*methods:\s*\[([^\]]+)\][^)]*name:\s*[\'"]([^\'"]+)[\'"]/s', $src, $mm, PREG_SET_ORDER)) {
      foreach ($mm as $r) {
        $verbs = array_map(static fn (string $v): string => strtoupper(trim($v, " \'\"")), explode(',', $r[2]));
        $routePaths[implode('+', $verbs) . ' ' . $r[1]][] = $path;
      }
    }
  }

  foreach (mic_files($root, '/\.routing\.yml$/') as $path) {
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    $current = NULL;
    $routes = [];
    foreach ($lines as $line) {
      if (preg_match('/^([a-zA-Z0-9_.]+):\s*$/', $line, $m)) {
        $current = $m[1];
        $routes[$current] = ['path' => NULL, 'methods' => ['GET']];
        $routeNames[$current][] = $path;
        continue;
      }
      if ($current === NULL) {
        continue;
      }
      if (preg_match('/^\s+path:\s*[\'"]?([^\'"\s#]+)[\'"]?\s*$/', $line, $m)) {
        $routes[$current]['path'] = $m[1];
      }
      elseif (preg_match('/^\s+methods:\s*\[?([^\]\#]+)\]?\s*$/', $line, $m)) {
        $methods = array_values(array_filter(array_map('strtoupper', array_map('trim', explode(',', $m[1])))));
        if ($methods !== []) {
          $routes[$current]['methods'] = $methods;
        }
      }
    }
    foreach ($routes as $name => $route) {
      if ($route['path'] !== NULL) {
        $routePaths[implode('+', $route['methods']) . ' ' . $route['path']][] = $path . ':' . $name;
      }
    }
  }

  foreach (mic_files($root, '/\.services\.yml$/') as $path) {
    $module = explode('/', $path)[count(explode('/', $path)) - 2] ?? $path;
    foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
      // Service ids are indented one level under the "services:" root key.
      if (preg_match('/^  ([a-zA-Z0-9_.]+):\s*$/', $line, $m)) {
        $serviceIds[$module . ':' . $m[1]][] = $path;
      }
    }
  }
}

mic_report('entity type id', $entities);
mic_report('class name', $classes);
mic_report('route name', $routeNames);
// Parameterised paths legitimately share a prefix, so only flag plain clashes.
mic_report('route path', array_filter(
  $routePaths,
  static fn (array $where): bool => count(array_unique($where)) > 1
));
mic_report('service id', $serviceIds);

printf("%s: %d file(s) scanned, %d duplicate(s)\n", $failures === 0 ? 'OK' : 'FAIL',
  count(mic_files('web/modules/custom', '/\.php$/')) + count(mic_files('web/themes/custom', '/\.php$/')),
  $failures);
exit($failures === 0 ? 0 : 1);
