# 舆情监测（`dx_opinion`）

> D5-B 演示 + EB 合规数据源模式。  
> 索引：[README.md](README.md) · 交付台：[delivery.md](delivery.md)

## 模式

| mode | 说明 |
|------|------|
| `demo` | 内置 `demo_items`（默认） |
| `licensed` | 授权 Endpoint JSON；`example.com` 为在线 sink；`fixture://licensed-sample.json` 读模块内文件 |

页面：`/opinion` 展示合规提示，**不进行未授权全网抓取**。

```bash
vendor/bin/drush dx:opinion-status
# 切到本地文件源
vendor/bin/drush php:eval '\Drupal::configFactory()->getEditable("dx_opinion.settings")->set("data_source_mode","licensed")->set("licensed_endpoint","fixture://licensed-sample.json")->save();'
./scripts/ci/opinion-smoke.sh
```