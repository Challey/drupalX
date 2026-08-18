# L0 公开框架树（Phase OE3）

> 白名单：[l0-whitelist.yml](l0-whitelist.yml) · 可见性：[visibility.yml](visibility.yml)  
> 发布：`bash scripts/publish-l0-tree.sh /tmp/drupalx-l0-public`  
> 在线文档：`/dx/api/docs`（OpenAPI：`/dx/api/openapi.yaml`）  
> 静态页：[api/index.html](api/index.html)

## 做什么

从本仓库导出 **Foundation 公开面**：`dx_*` 框架模块、门户主题、DXEP OpenAPI、客户端壳与打包脚本。  
**不导出** L2 伙伴金库、HA 运维脚本、`.env`。

## 命令

```bash
bash scripts/publish-l0-tree.sh /tmp/drupalx-l0-public
vendor/bin/drush dx:ecosystem-publish-l0 --dest=/tmp/drupalx-l0-public
./scripts/ci/l0-publish-smoke.sh
```

导出目录含 `L0-README.md` 与 `docs/api/index.html`（Swagger UI，源为 `docs/openapi/dxep-v1.yaml`）。

## 边界

| 进 L0 | 不进 L0 |
|--------|---------|
| `dx_platform` / `dx_tenant` / `dx_channel` / `dx_auth` 等白名单模块 | `dx_ecosystem/data/partner` |
| 公开 `docs/`、OpenAPI、客户端壳、`visibility.yml` | `setup/ha`、`scripts/ops`、`docs/DEPLOY.md`、域名切换手册 |
| `dx_portal_theme`（含统一登录与备案页脚模板） | 生产密钥、租户数据库、Gavias 厂商包 |
