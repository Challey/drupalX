#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
require $root . '/scripts/lib/l0_publish.php';

$dest = $argv[1] ?? '/tmp/drupalx-l0-public';
$whitelist = dx_l0_load_whitelist($root);
dx_l0_write_repo_api_docs($root);
$report = dx_l0_publish($root, $dest, $whitelist);
dx_l0_verify($dest, $whitelist);

fwrite(STDOUT, json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
