#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"
DRUSH=(vendor/bin/drush)

json_field() {
  python3 -c 'import json,re,sys
t=open(sys.argv[1]).read()
m=re.search(r"\{.*\}", t, re.S)
if not m:
  raise SystemExit("no JSON in "+sys.argv[1])
print(json.loads(m.group())[sys.argv[2]])' "$1" "$2"
}

json_ok() {
  python3 -c 'import json,re,sys
t=sys.stdin.read()
m=re.search(r"\{.*\}", t, re.S)
if not m:
  raise SystemExit("no JSON")
d=json.loads(m.group())
want=sys.argv[1]=="true"
sys.exit(0 if bool(d.get("ok")) is want else 1)' "$1"
}

echo "== OE2 L2 credential smoke =="
chmod +x "$0" || true
"${DRUSH[@]}" pm:enable dx_ecosystem -y >/dev/null
"${DRUSH[@]}" cr >/dev/null

ANON="$("${DRUSH[@]}" php:eval 'echo \Drupal::service("http_kernel")->handle(\Symfony\Component\HttpFoundation\Request::create("/dx/ecosystem/credentials"))->getStatusCode();')"
[[ "$ANON" == "403" || "$ANON" == "302" ]]

DEV_UID="$("${DRUSH[@]}" php:eval '
$u=\Drupal\user\Entity\User::create([
  "name"=>"l2cred_".time(),
  "mail"=>"l2cred_".time()."@example.com",
  "status"=>1,
]);
$u->enforceIsNew();
$u->save();
echo $u->id();
')"
DEV_UID="$(echo "$DEV_UID" | tr -cd '0-9')"
[[ -n "$DEV_UID" ]]
"${DRUSH[@]}" role:perm:add authenticated 'access dx partner vault' >/dev/null 2>&1 || true
"${DRUSH[@]}" role:perm:add authenticated 'sign dx developer agreement' >/dev/null 2>&1 || true
"${DRUSH[@]}" dx:ecosystem-sign-dpa --uid="$DEV_UID" >/dev/null

set +e
"${DRUSH[@]}" dx:ecosystem-issue-credential --uid="$DEV_UID" >/tmp/dx-l2-pending.out 2>&1
PENDING=$?
set -e
[[ "$PENDING" != "0" ]]
grep -qi 'not certified' /tmp/dx-l2-pending.out

"${DRUSH[@]}" dx:ecosystem-certify --uid="$DEV_UID" --note=l2-cred >/dev/null
"${DRUSH[@]}" dx:ecosystem-issue-credential --uid="$DEV_UID" | tee /tmp/dx-l2-issue.out
TOKEN="$(json_field /tmp/dx-l2-issue.out token)"
[[ "$TOKEN" == dxl2_* ]]
grep -q 'packages.drupalx.local' /tmp/dx-l2-issue.out

"${DRUSH[@]}" dx:ecosystem-verify-credential --token="$TOKEN" | tee /tmp/dx-l2-verify.out
json_ok true < /tmp/dx-l2-verify.out

"${DRUSH[@]}" dx:ecosystem-issue-credential --uid="$DEV_UID" >/tmp/dx-l2-rotate.out
OLD="$("${DRUSH[@]}" dx:ecosystem-verify-credential --token="$TOKEN")"
echo "$OLD" | json_ok false

NEW_TOKEN="$(json_field /tmp/dx-l2-rotate.out token)"
"${DRUSH[@]}" dx:ecosystem-revoke --uid="$DEV_UID" --note=l2-cred-end >/dev/null
AFTER="$("${DRUSH[@]}" dx:ecosystem-verify-credential --token="$NEW_TOKEN")"
echo "$AFTER" | json_ok false

echo "OK L2 credential uid=$DEV_UID rotate+revoke anon=$ANON"
