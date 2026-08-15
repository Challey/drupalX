# X 项目 Skill 完整文档：x-pack-miniprogram

> **文档性质**：独立完整手册（人读 + Agent 查阅）  
> **Skill 机名**：`x-pack-miniprogram`  
> **所属**：X 项目（DrupalX 简称）核心功能工具  
> **配套脚本**：`scripts/x-pack-miniprogram.sh`  
> **精简 Agent 指令**：`.cursor/skills/x-pack-miniprogram/SKILL.md`

---

## 1. 概述

将 **已登记的 X 应用** 打包为可导入 **微信开发者工具** 的微信小程序工程目录，并输出 `.tar.gz` 分发包。

| 项 | 内容 |
|----|------|
| 定位 | X 多端交付能力之一（与 `x-pack-android`、PWA/Channel 并列） |
| 默认样例应用 | `car_hailing_assistant`（跑车助手） |
| 数据原则 | 小程序只读 Hub / Redis 快照 API，**禁止**在请求路径触发航班/高铁抓取 |
| 不改动 | topstar 寄宿部署（Track A）默认路径与脚本 |

### 1.1 何时使用本 Skill

- 用户说：打包小程序、生成微信小程序、pack miniprogram、`x-pack-miniprogram`
- 为跑车助手或其它 catalog 应用出微信小程序包
- 需要登记新应用的 mini-program manifest

### 1.2 仓库与路径地图

| 角色 | 路径 |
|------|------|
| X 项目根 | `/home/wwwroot/drupalX` |
| 打包脚本 | `scripts/x-pack-miniprogram.sh` |
| 应用清单目录 | `tools/miniprogram-packer/apps/*.manifest.yml` |
| 跑车助手小程序源码 | `/home/wwwroot/car_hailing/clients/wechat-miniprogram/` |
| 通用 Channel 模板 | `clients/wechat-miniprogram/`（门户 bootstrap，非跑车业务） |
| Skill（仓库） | `.cursor/skills/x-pack-miniprogram/` |
| Skill（个人） | `~/.cursor/skills/x-pack-miniprogram/` |
| 默认产物 | `/home/challey/staging/drupalX/miniprogram/` |

---

## 2. 命令参考

均在 X 项目根执行：

```bash
cd /home/wwwroot/drupalX   # 或 export DRUPALX_ROOT=...
```

### 2.1 列出已登记应用

```bash
bash scripts/x-pack-miniprogram.sh --list
```

### 2.2 校验源码与清单

```bash
bash scripts/x-pack-miniprogram.sh --validate --app=car_hailing_assistant
```

校验内容包括：`app.js` / `app.json` / `project.config.json` / `pages/`，以及 manifest 中 `pages_required`。

### 2.3 打包（推荐）

```bash
bash scripts/x-pack-miniprogram.sh \
  --app=car_hailing_assistant \
  --api-base=https://www.topstar.run
```

可选参数：

| 参数 | 说明 |
|------|------|
| `--app=<id>` | 必填，对应 manifest 文件名 |
| `--api-base=<url>` | 写入 `config.js` 的 Drupal Hub 根（无尾斜杠） |
| `--traffic-api=<url>` | 可选，雷达失败时回退的 FastAPI 根 |
| `--out=<dir>` | 覆盖默认输出根目录 |
| `--validate` | 只校验不打包 |
| `--list` | 列出应用 |
| `X_MP_OUT_DIR` | 环境变量，同 `--out` 的根目录 |

### 2.4 产物结构

| 路径 | 说明 |
|------|------|
| `~/staging/drupalX/miniprogram/<app>-mp-deploy-latest/` | 可直接导入微信开发者工具 |
| `~/staging/drupalX/miniprogram/<app>-mp-deploy-latest.tar.gz` | 分发归档 |
| `~/staging/drupalX/miniprogram/archive/<app>-mp-<时间戳>.tar.gz` | 历史包 |
| `drupalX/upgrade/miniprogram/` | 仓库内镜像（通常被 `.gitignore`） |

跑车助手示例目录名：`car_hailing_assistant-mp-deploy-latest`。

---

## 3. Manifest 规范

文件：`tools/miniprogram-packer/apps/<app_id>.manifest.yml`

跑车助手示例字段：

```yaml
app_id: car_hailing_assistant
label: 跑车助手
brand_name: 跑车助手
source: ''
source_fallback:
  - /home/wwwroot/car_hailing/clients/wechat-miniprogram
project_name: car-hailing-assistant
appid: touristappid
config:
  apiBase: https://www.topstar.run
  trafficApiBase: ''
  clientId: car_hailing_mp
pages_required:
  - pages/home/home
  - pages/radar/radar
  - pages/airport/airport
  - pages/stations/stations
```

### 3.1 源码解析顺序（重要）

脚本**不依赖调用时的 cwd**，按以下顺序解析小程序源码目录：

1. `source` 为绝对路径且目录存在  
2. `source` 相对 **X 仓库根** 且目录存在  
3. `source_fallback` 列表（绝对或相对 X 根）  
4. `${CAR_HAILING_ROOT:-/home/wwwroot/car_hailing}/clients/wechat-miniprogram`

跑车助手业务源码在 **car_hailing 仓库**，不在 DrupalX 的 Channel 模板目录。

---

## 4. 跑车助手小程序说明

### 4.1 页面与 API

| Tab | 页面 | API |
|-----|------|-----|
| 首页 | `pages/home` | `GET /driver/api/radar` |
| 去哪跑 | `pages/radar` | `GET /driver/api/radar` |
| 机场 | `pages/airport` | `GET /driver/api/airport` |
| 车站 | `pages/stations` | `GET /driver/api/station/{id}` |

`config.js` 关键字段：

| 字段 | 含义 |
|------|------|
| `apiBase` | Drupal Hub（如 `https://www.topstar.run`） |
| `trafficApiBase` | 可选 FastAPI；雷达 Drupal 失败时回退 `/api/v1/radar` |
| `brandName` | 展示品牌名 |
| `clientId` | 预留客户端标识 |

### 4.2 后端权限注意

| 接口 | 路由权限 | 影响 |
|------|----------|------|
| `/driver/api/airport` | 公开 | 小程序可直接调 |
| `/driver/api/station/{id}` | 公开 | `szb\|ft\|sz\|sze` |
| `/driver/api/radar` | 需 `access car hailing` | 匿名可能 **403**；开发期可用 `trafficApiBase` 回退 |

---

## 5. 部署与上架流程（完整）

### 5.1 前置

1. Drupal Hub 可访问，`car_hailing` 已启用  
2. Traffic API / Redis 有缓存数据（抓取在服务端，不在小程序）  
3. 安装微信开发者工具  

### 5.2 打包

```bash
cd /home/wwwroot/drupalX
bash scripts/x-pack-miniprogram.sh --validate --app=car_hailing_assistant
bash scripts/x-pack-miniprogram.sh --app=car_hailing_assistant \
  --api-base=https://www.topstar.run
```

### 5.3 微信公众平台

1. [mp.weixin.qq.com](https://mp.weixin.qq.com/) 注册/登录小程序，取得 **AppID**  
2. **开发管理 → 开发设置 → 服务器域名 → request 合法域名**  
   - 必加：`www.topstar.run`（与 `apiBase` 主机一致，HTTPS）  
   - 若用 FastAPI 回退：再加 Traffic API 域名  
3. 域名须备案（国内）+ 有效证书  

开发期可在开发者工具勾选「不校验合法域名」（仅模拟器；真机预览仍要配域名）。

### 5.4 导入开发者工具

1. 导入目录：`…/car_hailing_assistant-mp-deploy-latest`  
2. 填正式 AppID 或游客模式  
3. 检查/修改包内 `config.js`  
4. 编译 → 调试 Network 请求  
5. 预览 → 上传 → 公众平台提交审核 → 发布  

### 5.5 日常更新

改 `car_hailing/clients/wechat-miniprogram` → 再执行 X 打包脚本 → 开发者工具打开新产物 → 上传新版本。  
**不**改变 Track A topstar 的 `deploy.sh` 默认行为。

---

## 6. 登记新应用 Checklist

```
- [ ] 准备小程序源码（app.js / app.json / pages / project.config.json）
- [ ] 新增 tools/miniprogram-packer/apps/<app_id>.manifest.yml
- [ ] 配置 source 或 source_fallback
- [ ] bash scripts/x-pack-miniprogram.sh --validate --app=<app_id>
- [ ] bash scripts/x-pack-miniprogram.sh --app=<app_id> --api-base=<hub>
- [ ] 微信开发者工具导入产物目录验证
```

---

## 7. Agent 执行工作流

```
Task Progress:
- [ ] Confirm app_id（或 --list）
- [ ] 确认源码可解析（manifest source / fallback）
- [ ] --validate
- [ ] Pack（--api-base 无尾斜杠）
- [ ] 告知用户：微信开发者工具 → 导入 OUT 目录
```

**硬规则（Agent 必须遵守）**

1. 优先调用脚本，禁止手搓另一套 packer  
2. 只读 API；不在请求路径触发抓取  
3. 不把 `.env`、密钥、`session_key` 打进包  
4. 默认 `appid: touristappid`，除非用户提供正式 AppID  
5. 仅打包小程序时，不改 topstar Track A 默认部署  

---

## 8. 故障排查

| 现象 | 处理 |
|------|------|
| `manifest missing` | 检查 `tools/miniprogram-packer/apps/<id>.manifest.yml` |
| `cannot resolve source` | 检查 fallback 路径或 `CAR_HAILING_ROOT` |
| `MISSING page` | 补齐 `pages_required` 对应文件 |
| 开发者工具 url not in domain list | 配合法域名或本地不校验 |
| 雷达空白 / 403 | 配 `trafficApiBase` 或调整 Drupal 权限 |
| 机场有数据雷达无 | 确认 Redis 雷达快照与 `/api/v1/radar` |

---

## 9. 相关文档与并列工具

| 文档/工具 | 说明 |
|-----------|------|
| 本文 | `docs/skills/x-pack-miniprogram.md`（完整） |
| 短文入口 | `docs/miniprogram-pack.md`（指向本文） |
| Agent Skill | `.cursor/skills/x-pack-miniprogram/SKILL.md` |
| 并列：Android | `docs/skills/x-pack-android.md` · Skill `x-pack-android` |
| 跑车助手双轨 | car_hailing `docs/DUAL_TRACK.md` |
| Channel API | `docs/channel-api.md`（通用门户小程序模板） |

---

## 10. 版本与维护

- 清单与脚本以 **DrupalX 仓库** 为准  
- 跑车助手小程序业务代码以 **car_hailing** 仓库 `clients/wechat-miniprogram` 为准  
- Skill 个人副本：`~/.cursor/skills/x-pack-miniprogram/`（与仓库同步时需手动或脚本复制）
