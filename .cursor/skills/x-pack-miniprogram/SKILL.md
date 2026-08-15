---
name: x-pack-miniprogram
description: >-
  Packs an X project (DrupalX) registered app into a WeChat mini-program
  directory and tar.gz. Use when the user asks to 打包小程序, pack miniprogram,
  generate WeChat mini program for an X/DrupalX app, or mentions x-pack-miniprogram
  / 跑车助手小程序打包.
---

# X 项目 · 打包微信小程序

**X 项目** = DrupalX。本 skill 是 X 的核心功能工具之一：把已登记应用打成可导入微信开发者工具的小程序包。

## When to use

- 用户说：打包小程序、生成微信小程序、pack miniprogram
- 针对跑车助手 / `car_hailing_assistant` / 其它 X catalog 应用出包
- 需要登记新应用的 mini-program manifest

## Do first

1. For the full human/ops handbook, read `/home/wwwroot/drupalX/docs/skills/x-pack-miniprogram.md` (complete, standalone).
2. Prefer the script — do not hand-roll a new packer.

```bash
cd /home/wwwroot/drupalX   # or $DRUPALX_ROOT
bash scripts/x-pack-miniprogram.sh --list
bash scripts/x-pack-miniprogram.sh --validate --app=<app_id>
bash scripts/x-pack-miniprogram.sh --app=<app_id> --api-base=<https://host>
```

Default first app: **`car_hailing_assistant`**（源码常在 `/home/wwwroot/car_hailing/clients/wechat-miniprogram`）。

## Workflow

```
Task Progress:
- [ ] Confirm app_id (or --list)
- [ ] Ensure source exists (manifest source / source_fallback)
- [ ] --validate
- [ ] Pack with --api-base (Drupal Hub origin, no trailing slash)
- [ ] Tell user: open 微信开发者工具 → 导入 OUT dir
```

### Outputs

- `/home/challey/staging/drupalX/miniprogram/<app>-mp-deploy-latest/`
- same under `upgrade/miniprogram/`
- `.tar.gz` + `archive/` stamp

### Register a new app

1. Create mini-program source (`app.js`, `app.json`, `pages/`, `project.config.json`).
2. Add `tools/miniprogram-packer/apps/<app_id>.manifest.yml` (copy car_hailing sample).
3. Validate + pack.

## Hard rules

- Mini-program **read-only** APIs / Redis snapshots — never trigger crawl on request path.
- Never pack `.env`, secrets, or `session_key`.
- Do not change topstar / Track A deploy defaults when only packaging MP.
- Keep `appid: touristappid` unless user supplies a real WeChat AppID.

## Reference

- **完整文档**: `docs/skills/x-pack-miniprogram.md`
- Short entry: `docs/miniprogram-pack.md`
- Script: `scripts/x-pack-miniprogram.sh`
- Sample manifest: `tools/miniprogram-packer/apps/car_hailing_assistant.manifest.yml`
- Generic Channel template: `clients/wechat-miniprogram/`
- 跑车助手 client: `/home/wwwroot/car_hailing/clients/wechat-miniprogram/`
- Sibling: `docs/skills/x-pack-android.md`
