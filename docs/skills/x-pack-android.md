# X 项目 Skill 完整文档：x-pack-android

> **文档性质**：独立完整手册（人读 + Agent 查阅）  
> **Skill 机名**：`x-pack-android`  
> **所属**：X 项目（DrupalX 简称）核心功能工具  
> **配套脚本**：`scripts/x-pack-android.sh`  
> **精简 Agent 指令**：`.cursor/skills/x-pack-android/SKILL.md`

---

## 1. 概述

将 **已登记的 X 应用** 打包为可导入 **Android Studio** 的 **WebView 壳工程**（可选在具备 JDK 17 + Android SDK 时尝试编 debug APK）。

| 项 | 内容 |
|----|------|
| 定位 | X 多端交付能力之一（与 `x-pack-miniprogram`、PWA 并列） |
| 默认样例应用 | `car_hailing_assistant`（跑车助手） |
| 壳行为 | 加载 Hub H5（默认 `https://www.topstar.run/driver`） |
| 数据原则 | 端上只读展示；**禁止**在 App 内触发航班/高铁抓取 |
| 不改动 | topstar 寄宿部署（Track A）默认路径与脚本 |

### 1.1 何时使用本 Skill

- 用户说：打包安卓、打 APK、Android App、pack android、`x-pack-android`
- 为跑车助手或其它 catalog 应用出安卓壳
- 需要登记新应用的 android manifest

### 1.2 仓库与路径地图

| 角色 | 路径 |
|------|------|
| X 项目根 | `/home/wwwroot/drupalX` |
| 打包脚本 | `scripts/x-pack-android.sh` |
| 应用清单目录 | `tools/android-packer/apps/*.manifest.yml` |
| WebView 工程模板 | `tools/android-packer/template/` |
| 跑车助手说明 | `/home/wwwroot/car_hailing/clients/android-app/README.md` |
| Skill（仓库） | `.cursor/skills/x-pack-android/` |
| Skill（个人） | `~/.cursor/skills/x-pack-android/` |
| 默认产物 | `/home/challey/staging/drupalX/android/` |

### 1.3 模板技术说明

- Java 包名固定：`x.app.shell`（减少打包时搬迁源码）  
- `applicationId` 由清单注入（跑车助手：`run.topstar.driver`）  
- `MainActivity`：WebView + 下拉刷新 + 返回键历史栈  
- 非 `allowed_host` 的链接走系统浏览器  
- 仅 HTTPS（`network_security_config` 禁止明文）  
- 编译要求：JDK **17**、compileSdk **34**（本机构建机若仅有 JDK 8，则只出工程，用 Android Studio 编）

---

## 2. 命令参考

均在 X 项目根执行：

```bash
cd /home/wwwroot/drupalX
```

### 2.1 列出已登记应用

```bash
bash scripts/x-pack-android.sh --list
```

### 2.2 校验清单

```bash
bash scripts/x-pack-android.sh --validate --app=car_hailing_assistant
```

校验：`application_id` / `start_url` / `allowed_host` 非空，且模板 `MainActivity` 存在。

### 2.3 打包工程（推荐默认）

```bash
bash scripts/x-pack-android.sh \
  --app=car_hailing_assistant \
  --start-url=https://www.topstar.run/driver
```

可选参数：

| 参数 | 说明 |
|------|------|
| `--app=<id>` | 必填 |
| `--start-url=<url>` | WebView 启动 URL |
| `--allowed-host=<host>` | 允许在 WebView 内打开的主机；可从 start_url 推导 |
| `--application-id=<id>` | 覆盖清单中的 applicationId |
| `--out=<dir>` | 输出根目录 |
| `--assemble` | 尝试 `assembleDebug`（需 JDK17 + `ANDROID_HOME`） |
| `--validate` / `--list` | 校验 / 列表 |
| `X_ANDROID_OUT_DIR` | 环境变量，输出根 |

### 2.4 产物结构

| 路径 | 说明 |
|------|------|
| `~/staging/drupalX/android/<app>-android-deploy-latest/` | Android Studio 打开此目录 |
| `…/<app>-android-deploy-latest.tar.gz` | 分发归档 |
| `…/archive/<app>-android-<时间戳>.tar.gz` | 历史包 |
| `drupalX/upgrade/android/` | 镜像（通常 gitignored） |
| `x-app.json`（产物内） | 打包元数据快照 |
| `BUILD.md`（产物内） | Studio / CLI 出 APK 说明 |

跑车助手示例：`car_hailing_assistant-android-deploy-latest`。

---

## 3. Manifest 规范

文件：`tools/android-packer/apps/<app_id>.manifest.yml`

跑车助手示例：

```yaml
app_id: car_hailing_assistant
label: 跑车助手
brand_name: 跑车助手
application_id: run.topstar.driver
project_name: car-hailing-assistant
start_url: https://www.topstar.run/driver
allowed_host: www.topstar.run
version_code: 1
version_name: "1.0.0"
```

| 字段 | 说明 |
|------|------|
| `application_id` | Android `applicationId` |
| `start_url` | 首屏 H5（须 HTTPS） |
| `allowed_host` | WebView 内允许的主机；外链外开 |
| `brand_name` | 桌面显示名（`strings.xml`） |
| `version_code` / `version_name` | 版本 |

打包时模板中占位符会被替换：`__APPLICATION_ID__`、`__START_URL__`、`__ALLOWED_HOST__`、`__APP_NAME__`、`__VERSION_*__`、`__PROJECT_NAME__`。

---

## 4. 用 Android Studio 出 APK（完整）

### 4.1 环境

- Android Studio Hedgehog+（自带 JDK 17）**或** 本机 JDK 17 + Android SDK 34  
- 首次 Sync 需能访问 Google/Maven（或已配置镜像）

### 4.2 步骤

1. **Open** 产物目录 `…/car_hailing_assistant-android-deploy-latest`  
2. 等待 **Gradle Sync**  
3. 若缺少 `gradlew`：Studio 会生成，或本地 `gradle wrapper --gradle-version 8.2`  
4. **Build → Build Bundle(s) / APK(s) → Build APK(s)**  
5. Debug APK：`app/build/outputs/apk/debug/`  
6. 真机/模拟器 Run  

### 4.3 CLI（可选）

```bash
export JAVA_HOME=/path/to/jdk-17
export ANDROID_HOME=/path/to/Android/Sdk
cd /home/challey/staging/drupalX/android/car_hailing_assistant-android-deploy-latest
./gradlew :app:assembleDebug
```

或在打包时：

```bash
JAVA_HOME=… ANDROID_HOME=… \
  bash scripts/x-pack-android.sh --app=car_hailing_assistant --assemble
```

环境不足时脚本会跳过编译，仍保留完整工程。

### 4.4 正式上架

1. 配置 `signingConfigs`（勿把密钥提交进 Git）  
2. `assembleRelease` 或生成 **AAB** 上传 Play / 国内应用市场  
3. 隐私政策、权限说明：壳使用 `INTERNET` + **定位**（`ACCESS_FINE_LOCATION` / `ACCESS_COARSE_LOCATION`）。H5 地图点「定位」时先弹说明，再走系统授权；WebView 已 `setGeolocationEnabled(true)` 并实现 `onGeolocationPermissionsShowPrompt`。上架文案需披露「用于显示当前位置与最近场站」。  

---

## 5. 跑车助手默认行为

| 项 | 值 |
|----|-----|
| 启动页 | `https://www.topstar.run/driver` |
| applicationId | `run.topstar.driver` |
| allowed_host | `www.topstar.run` |
| Debug 包名后缀 | `.debug`（`applicationIdSuffix`） |

依赖服务端：Drupal Hub +（间接）Traffic API/Redis。App **不**内置爬虫。

若雷达等 API 需登录，表现与手机浏览器打开 `/driver` 一致；权限策略在 Drupal / 后端调整，不在壳内硬编码密钥。

---

## 6. 登记新应用 Checklist

```
- [ ] 复制 tools/android-packer/apps/car_hailing_assistant.manifest.yml
- [ ] 改 app_id / application_id / start_url / allowed_host / 版本
- [ ] bash scripts/x-pack-android.sh --validate --app=<app_id>
- [ ] bash scripts/x-pack-android.sh --app=<app_id> --start-url=<url>
- [ ] Android Studio 打开产物 → Sync → Run
```

一般**无需**改模板 Java；仅当要加原生能力（推送、扫码等）时再扩展 `tools/android-packer/template/`。

---

## 7. Agent 执行工作流

```
Task Progress:
- [ ] Confirm app_id（--list）
- [ ] Validate manifest（application_id / start_url / allowed_host）
- [ ] Pack 到 staging
- [ ] 告知用户：Android Studio → Open → Sync → Build APK
- [ ] 可选：环境具备时再 --assemble
```

**硬规则（Agent 必须遵守）**

1. 优先调用脚本，禁止手搓另一套 Android 树  
2. 壳只加载 Hub/Channel HTTPS；不触发抓取  
3. 不打包 `.env`、签名密钥  
4. 外链必须走系统浏览器（保持 `allowed_host` 白名单语义）  
5. 仅打包 Android 时，不改 topstar Track A 默认部署  

---

## 8. 故障排查

| 现象 | 处理 |
|------|------|
| `manifest missing` | 检查 `tools/android-packer/apps/<id>.manifest.yml` |
| `MISSING application_id/start_url` | 补清单或命令行参数 |
| Gradle Sync 失败 / JDK | 使用 Android Studio 自带 JDK 17 |
| `ANDROID_HOME` 缺失 | 仅出工程；在 Studio 内编 |
| 白屏 | 检查 `start_url` HTTPS、证书、服务器是否可达 |
| 外链打不开 / 错误内嵌 | 确认 `allowed_host` 是否过宽或过窄 |
| 与小程序数据不一致 | H5 与小程序数据源不同属正常；对齐 Hub 配置 |

---

## 9. 相关文档与并列工具

| 文档/工具 | 说明 |
|-----------|------|
| 本文 | `docs/skills/x-pack-android.md`（完整） |
| 短文入口 | `docs/android-pack.md`（指向本文） |
| Agent Skill | `.cursor/skills/x-pack-android/SKILL.md` |
| 产物内说明 | `BUILD.md` |
| 并列：微信小程序 | `docs/skills/x-pack-miniprogram.md` · Skill `x-pack-miniprogram` |
| PWA | `docs/pwa-ops.md`（若仓库中存在） |

### 并列对照

| 工具 | Skill | 脚本 | 产物用途 |
|------|-------|------|----------|
| 微信小程序 | `x-pack-miniprogram` | `x-pack-miniprogram.sh` | 微信开发者工具 |
| Android App | `x-pack-android` | `x-pack-android.sh` | Android Studio / APK |

---

## 10. 版本与维护

- 模板与脚本以 **DrupalX 仓库** 为准  
- 跑车助手 H5 体验以 **topstar/Drupal 部署的 `/driver`** 为准  
- Skill 个人副本：`~/.cursor/skills/x-pack-android/`  
- 与 `x-pack-miniprogram` 文档相互独立，可单独分发本文件
