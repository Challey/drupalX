# android-packer

X 项目（DrupalX）Android WebView 应用打包器。壳模板版本 **1.3.0**。

- 清单：`apps/*.manifest.yml`（受 `tools/packer/manifest-schema.json` 门禁约束）
- 模板：`template/`（`x.app.shell` WebView；支付域名白名单与三项能力已在 `template/app/src/main/java/x/app/shell/MainActivity.java` 顶部 token 块接线）
- 代码生成：`lib/shell_codegen.py`
- 离线回归：`tests/test_shell_pack.py`、`tests/test_payment_host_policy.sh`
- 入口：`../../scripts/x-pack-android.sh`
- 文档：`../../docs/android-pack.md`
