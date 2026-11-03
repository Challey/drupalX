# NASA 宇宙镜像子站（dx_nasa）

> 索引：[README.md](README.md) · 教育专区：[strategy.md](strategy.md) · 部署：[DEPLOY.md](DEPLOY.md)

## 概述

`dx_nasa` 模块将 NASA 科学站宇宙教育内容（science.nasa.gov/universe 栏目、
kids/universe、精选 quick-reads 与 APOD 每日一图）镜像到本站 `/nasa` 子路径，
提供中英双语浏览。内容遵守 NASA public domain 政策（正文与图像自由使用，
镜像页保留原文出处链接；NASA 徽标与受管制素材已剔除）。

- 访问路径：`/nasa`（首页）、`/nasa/apod`（每日一图）、`/nasa/{lang}/{slug...}`
- 权限：与教育专区一致（`access youth zone`）
- 双语：EN 原文 + AI 中文译文（术语表统一天文名词），页内一键切换

## 架构

```
dx_nasa/
├── config/
│   ├── nasa-seed.json        # 32 页种子清单（universe/kids/quick-reads）
│   └── terminology.json      # ~100 条天文术语 EN->ZH
├── src/Service/
│   ├── NasaFetcher.php       # 采集：WP REST -> 清洗 -> 图片本地化 -> 幂等入库
│   └── NasaTranslator.php    # 翻译：行级纯文本协议（AI 不接触 HTML）
├── src/Controller/NasaMirrorController.php
├── src/Drush/Commands/       # dx-nasa:fetch 命令
└── templates/ css/           # 苹果风渲染层（库 dx_nasa/mirror）
```

数据表 `dx_nasa_page`（slug+lang 唯一）：`title / body_html / image /
source_url / section / lang / status / hash / fetched_at`。
md5 hash 幂等：内容未变不重复翻译；EN 更新后 ZH 标记 pending 由 cron 补翻。

## 关键机制

### 路由按 slug 深度分层

Drupal 的 router 表按「路径段数」筛选候选路由（`number_parts >= 请求段数`），
单个 `{slug}` 占位符即使 requirement 含 `/` 也无法承接多级路径。因此路由按
slug 深度注册为 `dx_nasa.view / view2 / view3 / view4` 四条（URL 深度 3-6 段），
控制器 `viewPage()/pageTitle()` 将可选参数重组为完整 slug 后查库。
生成镜像内链时使用控制器的 `mirrorUrl()` 字符串拼接，绕过 UrlGenerator 的
单路由 requirement 校验。

### 行级纯文本翻译

AI 模型永不接触 HTML：PHP 提取块级元素文本（figure/img 与行内 `<a>` 先替换为
`NASAASSET-n-ENDASSET` / `NASALINK-n-ENDLINK` 占位符），按 `编号|英文` 行分批
（3500 字符/40 行）发送，逐行取回 `编号|中文` 后逆向 `substr_replace` 重组，
最后还原占位符。译文缺行自动回退英文；占位符丢失时整块回退英文原文。

### 图片本地化

正文图片下载到 `public://nasa/assets/`（≤8MB），内链按前缀改写为
`/NASA_BASE/` 占位，渲染时按语言解析为 `/nasa/{lang}/`。

## 定时更新

- `hook_cron`：APOD 每 20 小时刷新；种子清单每周轮巡 5 个最旧页面
- 命令：`php vendor/drush/drush/drush.php dx-nasa:fetch [all|seed|apod]`
  `--force` 强制重采；`--pending` 仅补翻译未完成的 ZH 行
- crontab（生产，A/B 双机均可，任务幂等）：
  `30 3 * * * cd /home/wwwroot/drupalX && php vendor/drush/drush/drush.php dx-nasa:fetch >> /tmp/dx-nasa-cron.log 2>&1`

## 部署与验收

1. 部署包含模块目录；生产启用：`drush pm:enable dx_nasa -y && drush cr`
   （依赖 dx_ai_gateway；需先配置 AI Provider 与月度配额，33 页首轮翻译
   约 60-80 万字符）
2. 首次采集（后台运行，日志确认 DONE）：
   `drush dx-nasa:fetch all > /tmp/dx-nasa-seed.log 2>&1`
3. 验收脚本（本地与生产通用，26 条路径断言 200）：
   `drush php:script scripts/verify_nasa_mirror.php`

## 站内集成

教育专区宇宙板块（`dx_youth`）的外部 NASA 链接已改为指向本地镜像：

- `dx_youth/config/edu-resources-reference.json`：宇宙栏目、少儿科普、
  NASA 学习资源等条目使用 `mirror_slug` 字段
- `_dx_youth_nasa_mirror_url()`：按镜像库实际收录情况生成 `/nasa/zh/...`
  （zh 优先、en 回退、未收录则保持原外链）
- 宇宙板块 Markdown 文档（`edu-docs/universe/`）中的 NASA 引用同步本地化
