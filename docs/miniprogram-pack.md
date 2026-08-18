# X 项目 · 微信小程序打包（核心工具）

> **完整手册（独立文档）**：[skills/x-pack-miniprogram.md](skills/x-pack-miniprogram.md)  
> Agent Skill：`x-pack-miniprogram`（`.cursor/skills/x-pack-miniprogram/SKILL.md`）

**X 项目** = DrupalX。把已登记应用打成可导入微信开发者工具的小程序包。

## 快速命令

```bash
cd /home/wwwroot/drupalX
bash scripts/x-pack-miniprogram.sh --list
bash scripts/x-pack-miniprogram.sh --validate --app=car_hailing_assistant
bash scripts/x-pack-miniprogram.sh --app=car_hailing_assistant \
  --api-base=https://www.topstar.run
```

产物：`~/staging/drupalX/miniprogram/car_hailing_assistant-mp-deploy-latest/`

部署、Manifest、权限、上架与排障等 **全部细节** 见完整文档：[skills/x-pack-miniprogram.md](skills/x-pack-miniprogram.md)。

并列工具：[skills/x-pack-android.md](skills/x-pack-android.md)。
