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
