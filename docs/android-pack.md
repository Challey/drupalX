# X 项目 · Android App 打包（核心工具）

> **X 项目** = DrupalX。把已登记应用打成 **Android Studio 可打开的 WebView 工程**（可选再编 APK）。

跑车助手默认壳：加载 Hub H5 `https://www.topstar.run/driver`（只读，不触发抓取）。

## 命令

```bash
cd /home/wwwroot/drupalX

bash scripts/x-pack-android.sh --list
bash scripts/x-pack-android.sh --validate --app=car_hailing_assistant

bash scripts/x-pack-android.sh --app=car_hailing_assistant \
  --start-url=https://www.topstar.run/driver

# 本机已装 JDK 17 + Android SDK 时可尝试直接编 debug APK：
bash scripts/x-pack-android.sh --app=car_hailing_assistant --assemble
```

产物：

| 路径 | 说明 |
|------|------|
| `~/staging/drupalX/android/<app>-android-deploy-latest/` | 导入 Android Studio |
| `…/*.tar.gz` | 分发归档 |
| `upgrade/android/` | X 仓库镜像（gitignored） |

## 用 Android Studio 出 APK

1. Android Studio → Open → 上述 `*-android-deploy-latest` 目录  
2. 等待 Gradle Sync（需 JDK 17，AS 自带）  
3. Build → Build APK(s)  
4. Debug：`app/build/outputs/apk/debug/`  
5. 正式上架：配置签名 + `assembleRelease` / AAB

详见产物内 `BUILD.md`。

## 登记新应用

1. 新增 `tools/android-packer/apps/<app_id>.manifest.yml`（可复制跑车助手清单）  
2. 填写 `application_id` / `start_url` / `allowed_host` / 版本号  
3. `--validate` → `--app=<id>` 打包  

模板在 `tools/android-packer/template/`（固定 Java 包名 `x.app.shell`，仅替换 `applicationId` 与启动 URL）。

## 与小程序工具并列

| 工具 | Skill | 脚本 |
|------|-------|------|
| 微信小程序 | `x-pack-miniprogram` | `scripts/x-pack-miniprogram.sh` |
| Android App | `x-pack-android` | `scripts/x-pack-android.sh` |

## 原则

- App 壳只加载 Hub / Channel HTTPS，**禁止**在端上触发航班/高铁抓取  
- 不把 `.env`、密钥打进工程  
- 外链（非 `allowed_host`）走系统浏览器  
