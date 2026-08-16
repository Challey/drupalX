#!/usr/bin/env bash
# DXEP Channel FS1 smoke: enable module, mint token, hit site + app-layout.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

DRUSH=(vendor/bin/drush)
URI="${DX_CHANNEL_SMOKE_URI:-}"
URI_ARGS=()
if [[ -n "$URI" ]]; then
  URI_ARGS=(--uri="$URI")
fi

echo "== dx_channel smoke =="

"${DRUSH[@]}" "${URI_ARGS[@]}" pm:list --status=enabled --filter=dx_theme --format=list >/dev/null 2>&1 || {
  echo "dx_theme must be enabled first" >&2
  exit 1
}

"${DRUSH[@]}" "${URI_ARGS[@]}" pm:enable dx_channel -y
TOKEN_OUT="$("${DRUSH[@]}" "${URI_ARGS[@]}" dx:channel-token-create --id=smoke --scopes=channel:read 2>&1)"
TOKEN="$(echo "$TOKEN_OUT" | awk '/^dxc_/{print; exit}')"
if [[ -z "$TOKEN" ]]; then
  echo "Failed to parse token from:" >&2
  echo "$TOKEN_OUT" >&2
  exit 1
fi

"${DRUSH[@]}" "${URI_ARGS[@]}" dx:channel-layout-status

BASE="${DX_CHANNEL_SMOKE_BASE:-http://127.0.0.1}"
# Prefer php built-in if DX_CHANNEL_SMOKE_BASE unset and we can bootstrap via drush php.
if [[ -z "${DX_CHANNEL_SMOKE_BASE:-}" ]]; then
  echo "Skipping HTTP curl (set DX_CHANNEL_SMOKE_BASE to exercise HTTP)."
  echo "Token for manual test: $TOKEN"
  # Still validate JSON via drush php:eval
  "${DRUSH[@]}" "${URI_ARGS[@]}" php:eval '
    $req = Symfony\Component\HttpFoundation\Request::create("/api/dx/v1/channel/app-layout", "GET");
    $req->headers->set("Authorization", "Bearer '"$TOKEN"'");
    $ctrl = \Drupal::classResolver()->getInstanceFromDefinition(\Drupal\dx_channel\Controller\ChannelController::class);
    $res = $ctrl->appLayout($req);
    $data = json_decode($res->getContent(), TRUE);
    if (empty($data["ok"]) || ($data["data"]["spec"] ?? "") !== "DX-APP-LAYOUT") {
      throw new \RuntimeException("app-layout failed: " . $res->getContent());
    }
    $res2 = $ctrl->site($req);
    $data2 = json_decode($res2->getContent(), TRUE);
    if (empty($data2["ok"]) || empty($data2["data"]["org_profile"])) {
      throw new \RuntimeException("site failed: " . $res2->getContent());
    }
    // 401 without token
    $bare = Symfony\Component\HttpFoundation\Request::create("/api/dx/v1/channel/site", "GET");
    $deny = $ctrl->site($bare);
    if ($deny->getStatusCode() !== 401) {
      throw new \RuntimeException("expected 401 without token");
    }
    echo "controller checks OK\n";
  '
else
  curl -fsS -H "Authorization: Bearer $TOKEN" "$BASE/api/dx/v1/channel/site" | head -c 400
  echo
  curl -fsS -H "Authorization: Bearer $TOKEN" "$BASE/api/dx/v1/channel/app-layout" | head -c 400
  echo
  code="$(curl -s -o /dev/null -w "%{http_code}" "$BASE/api/dx/v1/channel/site" || true)"
  [[ "$code" == "401" ]] || { echo "expected 401 got $code" >&2; exit 1; }
fi

"${DRUSH[@]}" "${URI_ARGS[@]}" dx:channel-token-revoke smoke
echo "OK"
