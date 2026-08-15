---
name: x-pack-android
description: >-
  Packs an X project (DrupalX) registered app into an Android WebView Studio
  project (and optional APK). Use when the user asks to 打包安卓, 打包 Android App,
  pack android apk, generate Android app for an X/DrupalX app, or mentions
  x-pack-android / 跑车助手安卓打包.
---

# X 项目 · 打包 Android App

**X 项目** = DrupalX。本 skill 是 X 的核心功能工具之一：把已登记应用打成可导入 Android Studio 的 WebView 工程（可选 `--assemble` 编 debug APK）。

## When to use

- 用户说：打包安卓、打 APK、Android App、pack android
- 针对跑车助手 / `car_hailing_assistant` / 其它 X catalog 应用出安卓壳
- 需要登记新应用的 android manifest

## Do first

1. For the full human/ops handbook, read `/home/wwwroot/drupalX/docs/skills/x-pack-android.md` (complete, standalone).
2. Prefer the script — do not hand-roll a new Android tree.

```bash
cd /home/wwwroot/drupalX
bash scripts/x-pack-android.sh --list
bash scripts/x-pack-android.sh --validate --app=<app_id>
bash scripts/x-pack-android.sh --app=<app_id> --start-url=<https://host/path>
```

Default app: **`car_hailing_assistant`** → `https://www.topstar.run/driver`

## Workflow

```
Task Progress:
- [ ] Confirm app_id (--list)
- [ ] Validate manifest (application_id / start_url / allowed_host)
- [ ] Pack project to staging
- [ ] Tell user: Android Studio → Open → Sync → Build APK
- [ ] Optional: --assemble if JAVA_HOME=JDK17 and ANDROID_HOME set
```

### Outputs

- `/home/challey/staging/drupalX/android/<app>-android-deploy-latest/`
- `.tar.gz` + `archive/`
- mirror under `upgrade/android/` (usually gitignored)

### Register a new app

1. Copy `tools/android-packer/apps/car_hailing_assistant.manifest.yml`
2. Set `application_id`, `start_url`, `allowed_host`, versions
3. Validate + pack

## Hard rules

- Shell loads Hub H5 only — **never** trigger crawl on the request path.
- Never pack secrets / `.env`.
- Do not change topstar Track A deploy defaults when only packing Android.
- External hosts open in the system browser (`allowed_host` allowlist).

## Reference

- **完整文档**: `docs/skills/x-pack-android.md`
- Short entry: `docs/android-pack.md`
- Script: `scripts/x-pack-android.sh`
- Template: `tools/android-packer/template/`
- Sample manifest: `tools/android-packer/apps/car_hailing_assistant.manifest.yml`
- Sibling: `docs/skills/x-pack-miniprogram.md`
