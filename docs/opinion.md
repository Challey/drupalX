# 舆情监测（`dx_opinion`）

> D5-B 演示 + EB 合规数据源模式。

## 模式

| mode | 说明 |
|------|------|
| `demo` | 内置 `demo_items`（默认） |
| `licensed` | 授权 Endpoint JSON；`example.com` / `fixture://` 为本地 sink |

页面：`/opinion` 展示合规提示，**不进行未授权全网抓取**。

```bash
./scripts/ci/opinion-smoke.sh
```
