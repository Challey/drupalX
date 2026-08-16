---
name: x-pack-flutter
description: Pack DrupalX Flutter configurable shell with tenant api_base and Channel bearer token. Use when user asks to package Android/iOS app, Flutter shell, x-pack-flutter, or 交钥匙 App 出包.
---

# x-pack-flutter

## When to use

User wants to package DrupalX portal as Android/iOS via the Flutter JSON shell.

## Steps

1. Ensure Channel token exists: `drush dx:channel-token-create --id=flutter --scopes=channel:read`
2. Validate: `bash scripts/x-pack-flutter.sh --validate --app=<id>`
3. Pack:

```bash
bash scripts/x-pack-flutter.sh --app=<id> \
  --api-base=https://<tenant-host> \
  --token=<bearer> \
  --tenant=<tenant_id>
```

4. Open `*-flutter-deploy-latest`, run `flutter create` if platforms missing, then `flutter pub get` / build.

## Do not

- Embed platform `.env` secrets beyond Channel token
- Allow remote code execution in the shell
- Use WebView `x-pack-android` for new tenants (legacy only)

## Docs

- `docs/flutter-pack.md`
- `docs/flutter-shell.md`
- `clients/flutter_shell/README.md`
