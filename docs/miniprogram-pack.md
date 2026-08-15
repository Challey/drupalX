# X 项目 · 微信小程序打包（核心工具）

> **X 项目** = DrupalX 简称。本工具把已登记应用打成可导入微信开发者工具的小程序包。

## 命令

```bash
cd /home/wwwroot/drupalX

# 列出已登记应用
bash scripts/x-pack-miniprogram.sh --list

# 校验源码
bash scripts/x-pack-miniprogram.sh --validate --app=car_hailing_assistant

# 打包（产物 + tar）
bash scripts/x-pack-miniprogram.sh --app=car_hailing_assistant \
  --api-base=https://www.topstar.run

# 自定义输出目录
X_MP_OUT_DIR=/tmp/mp bash scripts/x-pack-miniprogram.sh --app=car_hailing_assistant
```

产物：

| 路径 | 说明 |
|------|------|
| `/home/challey/staging/drupalX/miniprogram/<app>-mp-deploy-latest/` | 可直接导入微信开发者工具 |
| `…/*.tar.gz` | 分发归档 |
| `upgrade/miniprogram/` | X 仓库内镜像 |

## 登记新应用

1. 准备小程序源码（含 `app.js` / `app.json` / `pages/` / `project.config.json`）
2. 新增 `tools/miniprogram-packer/apps/<app_id>.manifest.yml`
3. `--validate` 通过后 `--app=<app_id>` 打包

示例清单字段见 `tools/miniprogram-packer/apps/car_hailing_assistant.manifest.yml`。

## 与 Channel 模板关系

- 通用门户模板：`clients/wechat-miniprogram/`（Channel bootstrap）
- 行业/业务应用：各自仓库 `clients/wechat-miniprogram/`（如跑车助手），由本脚本按 manifest 打包

## Agent Skill

Cursor skill：**`x-pack-miniprogram`**（`.cursor/skills/x-pack-miniprogram/`）。  
用户提到「打包小程序 / X 项目小程序 / pack miniprogram」时加载。

## 原则

- 小程序只读缓存 / Channel / Hub API，**禁止**请求路径触发抓取
- 不把 `.env`、密钥打进包
- `appid` 默认 `touristappid`；上架前替换为正式 AppID
