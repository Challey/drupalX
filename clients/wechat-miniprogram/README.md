# DrupalX 微信小程序（DX-APP-LAYOUT 同构）

与 Flutter 壳共用 L1/L2（拍板 F4-A）。模板：`clients/wechat-miniprogram/`。

## 本地

1. 用微信开发者工具导入本目录  
2. `config.js`：`apiBase` / `token`；开发可 `useFixtures: true`  
3. 正式：Channel Bearer + `useFixtures: false`

## 与打包工具

若已有 `scripts/x-pack-miniprogram.sh`，可将本目录登记为 portal 应用源；或直接复制本模板后改 `config.js`。

## 组件

`utils/dxep.js` 的 `known` 与 Flutter `BlockRegistry` 对齐；未知 type 跳过。
