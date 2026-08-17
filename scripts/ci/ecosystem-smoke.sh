#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
DRUSH=(vendor/bin/drush)

echo "== dx_ecosystem OE1 smoke =="
"${DRUSH[@]}" pm:enable dx_appstore dx_platform dx_ecosystem -y >/dev/null
"${DRUSH[@]}" updatedb -y >/dev/null
"${DRUSH[@]}" cr >/dev/null

"${DRUSH[@]}" dx:ecosystem-agreements | tee /tmp/dx-oe-agreements.out
grep -q 'dx_ral' /tmp/dx-oe-agreements.out
grep -q 'dpa' /tmp/dx-oe-agreements.out

"${DRUSH[@]}" dx:ecosystem-sign-dpa --uid=1 >/dev/null
"${DRUSH[@]}" dx:ecosystem-status | tee /tmp/dx-oe-status.out
grep -q '"personal_registration_enabled": false' /tmp/dx-oe-status.out
grep -q '"require_ral_on_install": true' /tmp/dx-oe-status.out
grep -q '"tenant_kind_field": true' /tmp/dx-oe-status.out
grep -q '"ack_count":' /tmp/dx-oe-status.out

CODE="$("${DRUSH[@]}" php:eval 'echo \Drupal::service("http_kernel")->handle(\Symfony\Component\HttpFoundation\Request::create("/dx/ecosystem/agreements/dx_ral"))->getStatusCode();')"
[[ "$CODE" == "200" ]]

# Seed catalog with OE1 fields
"${DRUSH[@]}" dx:appstore-seed >/dev/null
"${DRUSH[@]}" php:eval '
$s=\Drupal::entityTypeManager()->getStorage("dx_app_package");
$e=reset($s->loadByProperties(["machine_name"=>"pathauto"]));
if(!$e){throw new \RuntimeException("pathauto missing");}
if($e->get("license_family")->value!=="gpl"){throw new \RuntimeException("license_family");}
if($e->get("source_policy")->value!=="tenant_visible"){throw new \RuntimeException("source_policy");}
echo "catalog-fields-ok";
'

# Create a pending request with RAL accepted (no tenant install)
"${DRUSH[@]}" php:eval '
$app=reset(\Drupal::entityTypeManager()->getStorage("dx_app_package")->loadByProperties(["machine_name"=>"pathauto"]));
$r=\Drupal\dx_appstore\Entity\InstallRequest::create([
  "app_id"=>$app->id(),
  "tenant_machine"=>"oe1smoke",
  "status"=>"pending",
  "requester_uid"=>1,
  "ral_accepted"=>1,
  "ral_version"=>"1.0",
  "ral_accepted_at"=>time(),
  "ral_accepter_uid"=>1,
]);
$r->save();
echo $r->id();
' | tee /tmp/dx-oe-req.id

# Gate without accept should fail when force off
"${DRUSH[@]}" php:eval '
$app=reset(\Drupal::entityTypeManager()->getStorage("dx_app_package")->loadByProperties(["machine_name"=>"pathauto"]));
$r=\Drupal\dx_appstore\Entity\InstallRequest::create([
  "app_id"=>$app->id(),
  "tenant_machine"=>"missing-tenant-xyz",
  "status"=>"pending",
  "requester_uid"=>1,
  "ral_accepted"=>0,
]);
$r->save();
try {
  \Drupal::service("dx_appstore.installer")->approveAndInstall($r, FALSE);
  throw new \RuntimeException("expected RAL failure");
} catch (\Throwable $e) {
  if (!str_contains($e->getMessage(), "DX-RAL")) { throw $e; }
  echo "ral-gate-ok";
}
'

echo "OK ecosystem OE1 agreements=$CODE"
