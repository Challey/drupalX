#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
DRUSH=(vendor/bin/drush)

echo "== L3 source bundle smoke =="
"${DRUSH[@]}" php:eval '
$cfg=\Drupal::configFactory()->getEditable("core.extension");
$themes=$cfg->get("theme")?:[];$c=FALSE;
foreach(array_keys($themes) as $n){if(!\Drupal::service("extension.list.theme")->exists($n)){unset($themes[$n]);$c=TRUE;}}
if($c){$cfg->set("theme",$themes)->save();}
' >/dev/null || true
"${DRUSH[@]}" pm:enable dx_appstore dx_oss -y >/dev/null
"${DRUSH[@]}" updatedb -y >/dev/null
"${DRUSH[@]}" cr >/dev/null
"${DRUSH[@]}" role:perm:add administrator 'download dx app source' >/dev/null 2>&1 || true
"${DRUSH[@]}" dx:appstore-seed >/dev/null

LIC="$("${DRUSH[@]}" php:eval '
$s=\Drupal::entityTypeManager()->getStorage("dx_app_package");
$app=reset($s->loadByProperties(["machine_name"=>"dx_oss"]));
if(!$app){throw new \RuntimeException("dx_oss catalog missing");}
$ls=\Drupal::entityTypeManager()->getStorage("dx_license");
$e=$ls->create([
  "app_id"=>$app->id(),
  "tenant_machine"=>"l3smoke",
  "status"=>"active",
  "amount"=>0,
  "agreement_version"=>"",
  "license_family"=>"dual",
  "source_policy"=>"tenant_visible",
  "created"=>time(),
]);
$e->save();
echo $e->id();
')"
echo "license=$LIC"

EMPTY="$("${DRUSH[@]}" php:eval '
$e=\Drupal::entityTypeManager()->getStorage("dx_license")->load('"$LIC"');
$ok=\Drupal::service("dx_appstore.source_bundle")->canDownload($e, \Drupal\user\Entity\User::load(1));
echo ($e->get("agreement_version")->value ? "has-ral" : "no-ral").":".($ok ? "allow" : "deny");
')"
[[ "$EMPTY" == "no-ral:deny" ]]

"${DRUSH[@]}" php:eval '
$e=\Drupal::entityTypeManager()->getStorage("dx_license")->load('"$LIC"');
$e->set("agreement_version","1.0");
$e->save();
echo "ral-ok";
' >/dev/null

ZIP="/tmp/dx-l3-oss-$LIC.zip"
rm -f "$ZIP"
"${DRUSH[@]}" dx:appstore-source-bundle "$LIC" --dest="$ZIP" | tee /tmp/dx-l3-ok.out
grep -q '"ok":true' /tmp/dx-l3-ok.out
test -f "$ZIP"
unzip -l "$ZIP" | grep -q 'NOTICE-DX-RAL.txt'
unzip -p "$ZIP" NOTICE-DX-RAL.txt | grep -q 'fourth party'
unzip -l "$ZIP" | grep -q 'dx_oss/'

"${DRUSH[@]}" dx:appstore-source-audit | tee /tmp/dx-l3-audit.out
grep -q '"module": "dx_oss"' /tmp/dx-l3-audit.out

ANON="$("${DRUSH[@]}" php:eval '
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Session\AccountSwitcherInterface;
$lid='"$LIC"';
$license=\Drupal::entityTypeManager()->getStorage("dx_license")->load($lid);
$ok=\Drupal::service("dx_appstore.source_bundle")->canDownload($license, new AnonymousUserSession());
echo $ok ? "allow" : "deny";
')"
[[ "$ANON" == "deny" ]]

echo "OK L3 source license=$LIC anon=$ANON"
