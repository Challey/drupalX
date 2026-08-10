<?php

// phpcs:ignoreFile

/**
 * @file
 * Tenant site settings template.
 *
 * Placeholders are replaced by TenantProvisioner / bootstrap during install.
 */

$databases['default']['default'] = [
  'database' => '__DB_NAME__',
  'username' => '__DB_USER__',
  'password' => '__DB_PASS__',
  'host' => '__DB_HOST__',
  'port' => '__DB_PORT__',
  'driver' => 'mysql',
  'prefix' => '',
  'collation' => 'utf8mb4_general_ci',
];

$settings['hash_salt'] = '__HASH_SALT__';

$settings['config_sync_directory'] = '../config/sync/' . basename(__DIR__);

$settings['file_public_path'] = 'sites/' . basename(__DIR__) . '/files';

$settings['file_private_path'] = '../private/' . basename(__DIR__);

$settings['trusted_host_patterns'] = [
  '.*',
];

$settings['update_free_access'] = FALSE;
