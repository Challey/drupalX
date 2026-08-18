# X 项目 · Android App 打包（核心工具）

> **完整手册（独立文档）**：[skills/x-pack-android.md](skills/x-pack-android.md)  
> Agent Skill：`x-pack-android`（`.cursor/skills/x-pack-android/SKILL.md`）

**X 项目** = DrupalX。把已登记应用打成 Android Studio 可打开的 WebView 工程（可选再编 APK）。

## 快速命令

```bash
cd /home/wwwroot/drupalX
bash scripts/x-pack-android.sh --list
bash scripts/x-pack-android.sh --validate --app=car_hailing_assistant
bash scripts/x-pack-android.sh --app=car_hailing_assistant \
  --start-url=https://www.topstar.run/driver
```

产物：`~/staging/drupalX/android/car_hailing_assistant-android-deploy-latest/`  
用 Android Studio 打开 → Sync → Build APK。

Manifest、Studio/CLI 出包、上架与排障等 **全部细节** 见完整文档：[skills/x-pack-android.md](skills/x-pack-android.md)。

并列工具：[skills/x-pack-miniprogram.md](skills/x-pack-miniprogram.md)。
