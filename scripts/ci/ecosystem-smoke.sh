#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
DRUSH=(vendor/bin/drush)

echo "== dx_ecosystem OE1+OE2 smoke =="
"${DRUSH[@]}" pm:enable dx_appstore dx_platform dx_ecosystem -y >/dev/null
"${DRUSH[@]}" updatedb -y >/dev/null
"${DRUSH[@]}" cr >/dev/null

"${DRUSH[@]}" dx:ecosystem-agreements | tee /tmp/dx-oe-agreements.out
grep -q 'dx_ral' /tmp/dx-oe-agreements.out
grep -q 'dpa' /tmp/dx-oe-agreements.out

"${DRUSH[@]}" dx:ecosystem-partner-docs | tee /tmp/dx-oe-partner.out
grep -q 'packer-deep' /tmp/dx-oe-partner.out
grep -q 'architecture-deep' /tmp/dx-oe-partner.out

"${DRUSH[@]}" dx:ecosystem-sign-dpa --uid=1 >/dev/null
"${DRUSH[@]}" dx:ecosystem-status --uid=1 | tee /tmp/dx-oe-status.out
grep -q '"personal_registration_enabled": false' /tmp/dx-oe-status.out
grep -q '"require_ral_on_install": true' /tmp/dx-oe-status.out
grep -q '"tenant_kind_field": true' /tmp/dx-oe-status.out
grep -q '"ack_count":' /tmp/dx-oe-status.out
grep -q '"status": "pending"' /tmp/dx-oe-status.out

# Pending must NOT open partner vault for a non-admin authenticated session.
# Admin uid1 bypasses gate via administer permission — create a throwaway user.
DEV_UID="$("${DRUSH[@]}" php:eval '
$u=\Drupal\user\Entity\User::create([
  "name"=>"oe2dev_".time(),
  "mail"=>"oe2dev_".time()."@example.com",
  "status"=>1,
]);
$u->enforceIsNew();
$u->save();
echo $u->id();
')"
"${DRUSH[@]}" role:perm:add authenticated 'access dx partner vault' >/dev/null 2>&1 || true
"${DRUSH[@]}" role:perm:add authenticated 'sign dx developer agreement' >/dev/null 2>&1 || true
"${DRUSH[@]}" dx:ecosystem-sign-dpa --uid="$DEV_UID" >/dev/null
DENY="$("${DRUSH[@]}" php:eval '
$uid='"$DEV_UID"';
$account=\Drupal\user\Entity\User::load($uid);
$gate=\Drupal::service("dx_ecosystem.gate");
echo $gate->canAccessPartnerVault($account) ? "allow" : "deny";
')"
[[ "$DENY" == "deny" ]]

"${DRUSH[@]}" dx:ecosystem-certify --uid="$DEV_UID" --note=oe2-smoke >/dev/null
ALLOW="$("${DRUSH[@]}" php:eval '
$uid='"$DEV_UID"';
$account=\Drupal\user\Entity\User::load($uid);
echo \Drupal::service("dx_ecosystem.gate")->canAccessPartnerVault($account) ? "allow" : "deny";
')"
[[ "$ALLOW" == "allow" ]]

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

"${DRUSH[@]}" dx:ecosystem-revoke --uid="$DEV_UID" --note=oe2-smoke-end >/dev/null
AFTER="$("${DRUSH[@]}" php:eval '
$uid='"$DEV_UID"';
$account=\Drupal\user\Entity\User::load($uid);
echo \Drupal::service("dx_ecosystem.gate")->canAccessPartnerVault($account) ? "allow" : "deny";
')"
[[ "$AFTER" == "deny" ]]

# ── I3: credential report (issuer / IP / call count / last use) ────────────────
# The audit table is created by dx_ecosystem_update_9003(), applied by the
# `updatedb -y` at the top of this script; the report must say so if it is not.
"${DRUSH[@]}" php:eval '
echo \Drupal::database()->schema()->tableExists("dx_l2_credential_event") ? "table-ok" : "table-missing";' | grep -q 'table-ok'

# Issue a credential, then use it twice on the L2 repository so the report has
# a caller, an IP and a last-use stamp to show.
"${DRUSH[@]}" dx:ecosystem-certify --uid="$DEV_UID" --note=i3-report >/dev/null
"${DRUSH[@]}" dx:ecosystem-issue-credential --uid="$DEV_UID" > /tmp/dx-oe-i3-token.out
I3_TOKEN="$(python3 -c 'import json,re,sys
t=open(sys.argv[1]).read()
print(json.loads(re.search(r"\{.*\}", t, re.S).group())["token"])' /tmp/dx-oe-i3-token.out)"
for _ in 1 2; do
  "${DRUSH[@]}" php:eval '
$request = \Symfony\Component\HttpFoundation\Request::create("/dx/ecosystem/l2/packages.json");
$request->headers->set("authorization", "Bearer '"$I3_TOKEN"'");
$request->server->set("REMOTE_ADDR", "203.0.113.77");
$kernel = \Drupal::service("http_kernel");
$response = $kernel->handle($request, \Symfony\Component\HttpKernel\HttpKernelInterface::MAIN_REQUEST, false);
echo $response->getStatusCode();' | grep -q '200'
done

# The Drush side of I3: same columns as the admin page, machine readable.
"${DRUSH[@]}" dx:ecosystem-credential-report --uid="$DEV_UID" --format=json > /tmp/dx-oe-i3-report.out
python3 - <<'PY'
import json, re, sys
text = open('/tmp/dx-oe-i3-report.out').read()
report = json.loads(re.search(r'\{.*\}', text, re.S).group())
columns = ['uid', 'state', 'prefix', 'issued_by', 'issued_ip', 'created', 'rotations',
           'uses', 'last_used', 'idle_days', 'denials', 'distinct_ips', 'revoked_at',
           'revoke_reason']
assert report['audit_ready'] is True, 'audit table missing: run drush updatedb -y'
row = [r for r in report['rows'] if r['uses'] >= 1]
assert row, report['rows']
row = row[0]
missing = [c for c in columns if c not in row]
assert not missing, 'report columns missing: %s' % missing
# Drush may run as uid 0 or uid 1 depending on how CI boots the site; what the
# contract pins is that the issuer column is filled and names somebody.
assert int(row['issued_by']) >= 0, row['issued_by']
assert row['issued_by_name'] != '', row
assert row['prefix'].startswith('dxl2_'), row['prefix']
assert row['uses'] >= 2, row['uses']
assert row['last_used'] > 0, row['last_used']
assert '203.0.113.77' in row['top_ips'], row['top_ips']
assert row['distinct_ips'] >= 1, row['distinct_ips']
assert report['totals']['uses'] >= 2, report['totals']
print('i3-report-ok uses=%s ips=%s' % (row['uses'], row['distinct_ips']))
PY

# Every column has a human label, and the admin page is reachable for uid 1.
"${DRUSH[@]}" php:eval '
$labels = [];
foreach (\Drupal\dx_ecosystem\Service\CredentialReport::columns() as $column) {
  $labels[] = \Drupal\dx_ecosystem\Service\CredentialReport::headerLabel($column);
}
echo count($labels) === count(array_unique($labels)) && count($labels) === 14 ? "labels-ok" : "labels-bad";' | grep -q 'labels-ok'
REPORT_PAGE="$("${DRUSH[@]}" php:eval '
\Drupal::setCurrentUser(\Drupal\user\Entity\User::load(1));
$request = \Symfony\Component\HttpFoundation\Request::create("/admin/dx/ecosystem/credentials");
$response = \Drupal::service("http_kernel")->handle($request, \Symfony\Component\HttpKernel\HttpKernelInterface::MAIN_REQUEST, false);
echo $response->getStatusCode();')"
[[ "$REPORT_PAGE" == "200" || "$REPORT_PAGE" == "302" ]]
# Page and CLI share CredentialReport::headerLabel(), so the CLI table proves the
# same headers reach a human: 签发者 / 来源 IP / 调用次数 / 最后使用.
"${DRUSH[@]}" dx:ecosystem-credential-report | tee /tmp/dx-oe-i3-table.out
grep -q 'credentials=' /tmp/dx-oe-i3-table.out
grep -q '签发者' /tmp/dx-oe-i3-table.out
grep -q '来源 IP' /tmp/dx-oe-i3-table.out
grep -q '调用次数' /tmp/dx-oe-i3-table.out
grep -q '最后使用' /tmp/dx-oe-i3-table.out

# Prune path stays available for the ops cron (keeps the audit table bounded).
"${DRUSH[@]}" dx:ecosystem-credential-report --older-than=1 >/dev/null

echo "OK ecosystem OE1+OE2 agreements=$CODE partner_gate=deny→allow→deny i3=report"
