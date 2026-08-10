<?php
/**
 * One-shot: remap enabled modules/themes/config/tables from dcn_* to dx_*.
 * Usage: cd /home/wwwroot/drupalX && php scripts/migrate-dcn-to-dx-db.php
 * Or: vendor/bin/drush php:script scripts/migrate-dcn-to-dx-db.php
 */
use Drupal\Core\DrupalKernel;
use Symfony\Component\HttpFoundation\Request;

$autoloader = require __DIR__ . '/../vendor/autoload.php';
$request = Request::createFromGlobals();
$kernel = DrupalKernel::createFromRequest($request, $autoloader, 'prod');
$kernel->boot();
$container = $kernel->getContainer();
$container->get('request_stack')->push($request);

$db = \Drupal::database();
$configFactory = \Drupal::configFactory();

$map = [
  'dcn_platform' => 'dx_platform',
  'dcn_tenant' => 'dx_tenant',
  'dcn_portal' => 'dx_portal',
  'dcn_ai_gateway' => 'dx_ai_gateway',
  'dcn_appstore' => 'dx_appstore',
  'dcn_portal_theme' => 'dx_portal_theme',
  'dcn_admin' => 'dx_admin',
];

$entityTables = [
  'dcn_tenant' => 'dx_tenant',
  'dcn_app_package' => 'dx_app_package',
  'dcn_install_request' => 'dx_install_request',
  'dcn_license' => 'dx_license',
  'dcn_revenue_share' => 'dx_revenue_share',
  'dcn_ai_usage' => 'dx_ai_usage',
];

$schema = $db->schema();
foreach ($entityTables as $old => $new) {
  if ($schema->tableExists($old) && !$schema->tableExists($new)) {
    $db->query('RENAME TABLE {' . $old . '} TO {' . $new . '}');
    echo "Renamed table $old -> $new\n";
  }
}

// core.extension
$ext = $configFactory->getEditable('core.extension');
$modules = $ext->get('module') ?: [];
$themes = $ext->get('theme') ?: [];
$changed = FALSE;
foreach ($map as $old => $new) {
  if (isset($modules[$old])) {
    $modules[$new] = $modules[$old];
    unset($modules[$old]);
    $changed = TRUE;
  }
  if (isset($themes[$old])) {
    $themes[$new] = $themes[$old];
    unset($themes[$old]);
    $changed = TRUE;
  }
}
if ($changed) {
  $ext->set('module', $modules)->set('theme', $themes)->save();
  echo "Updated core.extension\n";
}

// system.theme
$st = $configFactory->getEditable('system.theme');
foreach (['default', 'admin'] as $key) {
  $v = $st->get($key);
  if ($v && isset($map[$v])) {
    $st->set($key, $map[$v]);
    echo "system.theme.$key -> {$map[$v]}\n";
  }
}
$st->save();

// Rename config objects dcn_* -> dx_*
$names = $configFactory->listAll('dcn_');
foreach ($names as $name) {
  $new = preg_replace('/^dcn_/', 'dx_', $name);
  $oldCfg = $configFactory->getEditable($name);
  $data = $oldCfg->getRawData();
  // Deep replace string values
  array_walk_recursive($data, static function (&$val) use ($map) {
    if (is_string($val)) {
      foreach ($map as $o => $n) {
        $val = str_replace($o, $n, $val);
      }
      $val = str_replace('dcn_', 'dx_', $val);
      $val = str_replace('/admin/dcn/', '/admin/dx/', $val);
    }
  });
  $configFactory->getEditable($new)->setData($data)->save();
  $oldCfg->delete();
  echo "Config $name -> $new\n";
}

// system.schema key_value
$kv = \Drupal::keyValue('system.schema');
foreach ($map as $old => $new) {
  if ($kv->has($old)) {
    $kv->set($new, $kv->get($old));
    $kv->delete($old);
    echo "schema $old -> $new\n";
  }
}

// State keys (key_value collection)
$kvState = $db->select('key_value', 'k')->fields('k', ['name', 'value'])->condition('collection', 'state')->condition('name', $db->escapeLike('dcn_') . '%', 'LIKE');
foreach ($kvState->execute() as $row) {
  $newKey = preg_replace('/^dcn_/', 'dx_', $row->name);
  $db->merge('key_value')->keys(['collection' => 'state', 'name' => $newKey])->fields(['value' => $row->value])->execute();
  $db->delete('key_value')->condition('collection', 'state')->condition('name', $row->name)->execute();
  echo "state {$row->name} -> $newKey\n";
}

// Role permissions
foreach (\Drupal\user\Entity\Role::loadMultiple() as $role) {
  $perms = $role->getPermissions();
  $dirty = FALSE;
  foreach ($perms as $perm) {
    if (str_contains($perm, 'dcn ')) {
      $role->revokePermission($perm);
      $role->grantPermission(str_replace('dcn ', 'dx ', $perm));
      $dirty = TRUE;
    }
  }
  if ($dirty) {
    $role->save();
    echo "role {$role->id()} permissions updated\n";
  }
}

drupal_flush_all_caches();
echo "DONE migrate-dcn-to-dx-db\n";
