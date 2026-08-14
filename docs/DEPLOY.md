# DrupalX 生产部署

## 打包（开发机）

```bash
/home/challey/ops/bin/pack drupalX
```

产物：`/home/challey/staging/drupalX/drupalX-deploy-latest.tar.gz`

## 一键双机 / 指定主机

```bash
/home/challey/ops/bin/deploy drupalX --pack
```

仅服务器 B（在 `hosts.env` 只保留 B，或使用 `DRUPALX_PROD_HOSTS` 覆盖）。

## 单机手动

1. 上传 tar 到 `$DRUPAL/upgrade/drupalX-deploy-latest.tar.gz`
2. 执行：

```bash
cd /home/wwwroot/drupalX/upgrade && ./drupalX-update.sh
```

**不会**覆盖生产 `.env` / `settings.php` / `vendor/` / 用户上传文件。

## AI Provider 升级

已有站点首次升级到 Drupal AI Provider 管理器时，需在执行数据库更新前启用依赖：

```bash
vendor/bin/drush pm:enable key ai ai_provider_openai -y
vendor/bin/drush updb -y
vendor/bin/drush cr
```

每个 multisite 租户均需按对应 `--uri` 执行；新开通站点已由 bootstrap /
provision 流程自动启用。
