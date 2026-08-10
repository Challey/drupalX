<?php

declare(strict_types=1);

namespace Drupal\dx_platform\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\dx_platform\Entity\Tenant;
use Symfony\Component\Process\Process;

/**
 * Provisions tenant databases, site directories, and Drupal installations.
 */
class TenantProvisioner {

  /**
   * Constructs a TenantProvisioner.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LoggerChannelInterface $logger,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Loads environment variables from the project root .env file.
   */
  public function loadEnv(): void {
    $envFile = dirname(DRUPAL_ROOT) . '/.env';
    if (!is_readable($envFile)) {
      return;
    }

    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === FALSE) {
      return;
    }

    foreach ($lines as $line) {
      $line = trim($line);
      if ($line === '' || str_starts_with($line, '#')) {
        continue;
      }
      if (!str_contains($line, '=')) {
        continue;
      }
      [$key, $value] = explode('=', $line, 2);
      $key = trim($key);
      $value = trim($value, " \t\"'");
      if ($key !== '' && getenv($key) === FALSE) {
        putenv("$key=$value");
        $_ENV[$key] = $value;
      }
    }
  }

  /**
   * Provisions a tenant end-to-end.
   */
  public function provision(Tenant $tenant): void {
    $this->loadEnv();

    $machineName = $tenant->getMachineName();
    if ($machineName === '') {
      throw new \InvalidArgumentException('Tenant machine name is required.');
    }

    $tenant->set('status', 'provisioning');
    $tenant->save();

    try {
      $databaseName = $this->createDatabase($machineName);
      $tenant->set('database_name', $databaseName);

      $suffix = getenv('DX_TENANT_SUFFIX') ?: $this->configFactory->get('dx_platform.settings')->get('default_tenant_suffix') ?: 'drupalx.local';
      $subdomain = $machineName . '.' . $suffix;
      $tenant->set('subdomain', $subdomain);
      $tenant->set('portal_url', 'https://' . $subdomain);

      $this->createSettingsFile($machineName, $databaseName);
      $this->updateSitesPhp($machineName, $suffix);

      $this->runSiteInstall($tenant, $machineName, $databaseName);
      $this->enableTenantModules($machineName, $subdomain);

      $tenant->set('status', 'active');
      $tenant->save();
    }
    catch (\Throwable $exception) {
      $tenant->set('status', 'failed');
      $tenant->save();
      $this->logger->error('Tenant provisioning failed for @machine: @message', [
        '@machine' => $machineName,
        '@message' => $exception->getMessage(),
      ]);
      throw $exception;
    }
  }

  /**
   * Creates a tenant MySQL database.
   */
  protected function createDatabase(string $machineName): string {
    $prefix = getenv('DX_DB_PREFIX') ?: 'dx_tenant_';
    $databaseName = $prefix . preg_replace('/[^a-z0-9_]/', '_', strtolower($machineName));

    $host = getenv('DX_DB_HOST') ?: '127.0.0.1';
    $port = getenv('DX_DB_PORT') ?: '3306';
    $user = getenv('DX_DB_USER') ?: 'root';
    $pass = getenv('DX_DB_PASS') ?: '';

    $dsn = sprintf('mysql:host=%s;port=%s', $host, $port);
    $pdo = new \PDO($dsn, $user, $pass, [
      \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
    ]);

    $safeDb = str_replace('`', '``', $databaseName);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$safeDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    return $databaseName;
  }

  /**
   * Writes settings.php for the tenant site directory.
   */
  protected function createSettingsFile(string $machineName, string $databaseName): void {
    $siteDir = DRUPAL_ROOT . '/sites/' . $machineName;
    if (!is_dir($siteDir)) {
      mkdir($siteDir, 0755, TRUE);
    }

    $templatePath = DRUPAL_ROOT . '/sites/example.tenant/settings.php';
    if (!is_readable($templatePath)) {
      throw new \RuntimeException('Tenant settings template not found at sites/example.tenant/settings.php');
    }

    $host = getenv('DX_DB_HOST') ?: '127.0.0.1';
    $port = getenv('DX_DB_PORT') ?: '3306';
    $user = getenv('DX_DB_USER') ?: 'root';
    $pass = getenv('DX_DB_PASS') ?: '';

    $hashSalt = bin2hex(random_bytes(32));
    $settings = str_replace(
      ['__DB_NAME__', '__DB_USER__', '__DB_PASS__', '__DB_HOST__', '__DB_PORT__', '__HASH_SALT__'],
      [$databaseName, $user, addslashes($pass), $host, $port, $hashSalt],
      file_get_contents($templatePath)
    );

    if (!is_dir($siteDir . '/files')) {
      mkdir($siteDir . '/files', 0775, TRUE);
    }
    $privateDir = dirname(DRUPAL_ROOT) . '/private/' . $machineName;
    if (!is_dir($privateDir)) {
      mkdir($privateDir, 0775, TRUE);
    }
    $configDir = dirname(DRUPAL_ROOT) . '/config/sync/' . $machineName;
    if (!is_dir($configDir)) {
      mkdir($configDir, 0775, TRUE);
    }

    file_put_contents($siteDir . '/settings.php', $settings);
  }

  /**
   * Updates sites.php with tenant host and path mappings.
   */
  protected function updateSitesPhp(string $machineName, string $suffix): void {
    $sitesPhpPath = DRUPAL_ROOT . '/sites/sites.php';

    if (!file_exists($sitesPhpPath)) {
      $header = <<<'PHP'
<?php

/**
 * @file
 * Multisite directory aliasing for DrupalX.
 */

$sites = [];

PHP;
      file_put_contents($sitesPhpPath, $header);
    }

    $content = file_get_contents($sitesPhpPath);
    if ($content === FALSE) {
      throw new \RuntimeException('Unable to read sites.php');
    }

    if (!preg_match('/\$sites\s*=\s*\[/', $content) && !preg_match('/\$sites\s*=\s*array\s*\(/', $content)) {
      $content .= "\n\$sites = [];\n";
    }

    $hostKey = $machineName . '.' . $suffix;
    $localhostKey = 'localhost.' . $machineName;
    $entries = [
      $hostKey => $machineName,
      $localhostKey => $machineName,
    ];

    foreach ($entries as $key => $dir) {
      $needle = "'{$key}'";
      if (!str_contains($content, $needle)) {
        $content .= "\$sites['{$key}'] = '{$dir}';\n";
      }
    }

    file_put_contents($sitesPhpPath, $content);
  }

  /**
   * Runs drush site:install for the tenant.
   */
  protected function runSiteInstall(Tenant $tenant, string $machineName, string $databaseName): void {
    $drush = dirname(DRUPAL_ROOT) . '/vendor/bin/drush';
    $host = getenv('DX_DB_HOST') ?: '127.0.0.1';
    $port = getenv('DX_DB_PORT') ?: '3306';
    $user = getenv('DX_DB_USER') ?: 'root';
    $pass = getenv('DX_DB_PASS') ?: '';
    $adminUser = getenv('DX_ADMIN_USER') ?: 'admin';
    $adminPass = getenv('DX_ADMIN_PASS') ?: 'admin';
    $adminMail = $tenant->get('owner_mail')->value ?: (getenv('DX_ADMIN_MAIL') ?: 'admin@drupalx.local');
    $siteName = $tenant->label();

    $dbUrl = sprintf(
      'mysql://%s:%s@%s:%s/%s',
      rawurlencode($user),
      rawurlencode($pass),
      $host,
      $port,
      $databaseName
    );

    $timeout = (int) ($this->configFactory->get('dx_platform.settings')->get('provision_timeout') ?: 600);

    $process = new Process([
      $drush,
      'site:install',
      'standard',
      '--yes',
      '--sites-subdir=' . $machineName,
      '--db-url=' . $dbUrl,
      '--account-name=' . $adminUser,
      '--account-pass=' . $adminPass,
      '--account-mail=' . $adminMail,
      '--site-name=' . $siteName,
    ], dirname(DRUPAL_ROOT));
    $process->setTimeout($timeout);
    $process->run();

    if (!$process->isSuccessful()) {
      throw new \RuntimeException('site:install failed: ' . $process->getErrorOutput());
    }
  }

  /**
   * Enables tenant modules and theme after installation.
   */
  protected function enableTenantModules(string $machineName, string $uri): void {
    $drush = dirname(DRUPAL_ROOT) . '/vendor/bin/drush';
    $timeout = (int) ($this->configFactory->get('dx_platform.settings')->get('provision_timeout') ?: 600);
    $cwd = dirname(DRUPAL_ROOT);
    $uriOpt = '--uri=http://' . $uri;

    $moduleProcess = new Process([
      $drush,
      'pm:enable',
      'dx_tenant',
      'dx_portal',
      'dx_ai_gateway',
      'pathauto',
      'token',
      'metatag',
      'key',
      'gaviasthemer',
      'gavias_view',
      'gavias_hook_themer',
      'gavias_blockbuilder',
      'gva_render_shortcode',
      'gavias_sliderlayer',
      '--yes',
      $uriOpt,
    ], $cwd);
    $moduleProcess->setTimeout($timeout);
    $moduleProcess->run();
    if (!$moduleProcess->isSuccessful()) {
      throw new \RuntimeException('Module enable failed: ' . $moduleProcess->getErrorOutput() . $moduleProcess->getOutput());
    }

    $themeProcess = new Process([
      $drush,
      'theme:enable',
      'gavias_kiamo',
      'olivero',
      '--yes',
      $uriOpt,
    ], $cwd);
    $themeProcess->setTimeout($timeout);
    $themeProcess->run();
    if (!$themeProcess->isSuccessful()) {
      throw new \RuntimeException('Theme enable failed: ' . $themeProcess->getErrorOutput() . $themeProcess->getOutput());
    }

    $configProcess = new Process([
      $drush,
      'config:set',
      'system.theme',
      'default',
      'gavias_kiamo',
      '-y',
      $uriOpt,
    ], $cwd);
    $configProcess->setTimeout(120);
    $configProcess->run();
  }

}
