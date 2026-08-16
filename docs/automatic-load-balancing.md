# DrupalX 双机自动负载平衡模式

本文独立说明 DrupalX 生产环境的 A/B LNMPA 自动负载平衡、故障接管、
备份与恢复方式。

## 1. 目标与角色

| 节点 | 地址 | 正常职责 | 故障职责 |
|---|---|---|---|
| A | 公网 `47.113.227.103` / 内网 `172.16.34.121` | 公网入口、TLS、静态资源、转发业务请求 | B 不可用时由本机 Apache 处理业务 |
| B | 公网 `47.113.217.2` / 内网 `172.16.34.126` | Drupal/PHP 主业务节点 | A 不可用时成为公网入口并全面接管 |

两台均保留 LNMPA 模式：

```text
Nginx :80/:443 -> Apache :88 -> DrupalX
```

正常链路：

```text
用户 -> DNS(A) -> A Nginx
                     ├─ 静态资源：A 本地返回
                     └─ Drupal/PHP：B Apache:88
                                         └─ B 不可用：A Apache:88
```

A 故障时：

```text
B 上 systemd 健康检查 -> 阿里云 DNS 改为 B -> B Nginx -> B Apache:88
```

A 连续恢复后，DNS 自动回到 A，业务请求继续由 B 处理。

## 2. 严格 B 优先

A 的 Nginx upstream 使用主后端与 `backup`，不是权重分流：

```nginx
upstream dxBusinessApache {
    server 172.16.34.126:88 max_fails=2 fail_timeout=10s;
    server 127.0.0.1:88 backup;
    keepalive 32;
}
```

因此两台健康时 A 不参与 PHP 计算。旧配置的 B `weight=3`、A `weight=1`
仍会把一部分业务交给 A，不属于本模式。

## 3. 状态一致性要求

自动接管成立的前提：

1. 两台运行相同 DrupalX 代码。
2. 两台使用同一数据库。
3. 自定义模块/主题、`settings.php`、`sites.php` 与公开文件保持一致。
4. 会话应存放在共享数据库或 Redis，不能依赖单机临时状态。
5. B 的 Apache 88 只允许 A 内网及 B 本机访问，不对公网开放。
6. A/B 都持有有效的 `www.drupal.org.cn` / `drupal.org.cn` TLS 证书。

生产部署脚本以当前提供业务的 B 为基准，同步 A 的自定义模块/主题、recipes、
settings、sites 映射、files 和当前双域名证书。

## 4. 健康检查与 DNS 接管

B 上的 `dx-dns-failover.timer` 每 15 秒执行一次：

- A 连续失败 3 次：`www`、`@`、`x` 一起切到 B。
- A 连续成功 3 次：全部记录切回 A。
- TTL：600 秒（当前阿里云 DNS 套餐允许的最小值）。
- 使用文件锁防止任务重叠。
- 只有全部 DNS 记录更新成功才改变本地状态。
- 状态文件：`/var/lib/dx-ha/dns-state`。
- 日志：`/var/log/dx-dns-failover.log` 和 systemd journal。

检查：

```bash
systemctl status dx-dns-failover.timer
systemctl status dx-dns-failover.service
journalctl -u dx-dns-failover.service
cat /var/lib/dx-ha/dns-state
```

DNS 方式仍受客户端和递归解析器缓存影响，不能保证零秒切换。当前套餐下客户端
最多可能保留旧地址 600 秒。若要求近乎无感，应升级 DNS 套餐或在两台前增加
阿里云 ALB/SLB；本文模式可继续作为源站级灾备。

## 5. 生产部署

部署入口：

```bash
cd /home/wwwroot/drupalX
./scripts/ops/deploy-ha-production.sh --apply
```

脚本按以下顺序执行：

1. 验证两台 Nginx、Apache、A→B:88 与阿里云身份。
2. 分别生成带 UTC 时间戳的完整配置备份。
3. 先部署 B 本地处理配置，保持公网服务可用。
4. 从 B 同步 A 所需的 Drupal settings/files 与证书。
5. 部署 A 的 B-first upstream、DrupalX vhost 和 Apache vhost。
6. 验证 A 直连站点和健康检查。
7. 停用旧 DNS cron，初始化新状态，DNS 切至 A。
8. 启用 15 秒 systemd timer。

任一步失败，脚本会自动调用本次备份恢复已改动节点，并将 DNS 恢复到部署前值。

## 6. 备份内容

备份位于：

```text
/var/backups/drupalx-ha/<UTC时间>-node-a
/var/backups/drupalx-ha/<UTC时间>-node-b
```

以及快捷链接：

```text
/var/backups/drupalx-ha/latest-node-a
/var/backups/drupalx-ha/latest-node-b
```

内容包括：

- Nginx 主配置、upstream、PHP 代理、DrupalX vhost 和证书。
- Apache DrupalX vhost。
- DNS failover 脚本、环境文件和 systemd units。
- DrupalX 自定义模块、主题和 recipes。
- Drupal `settings.php`、`sites.php` 和公开 files。
- root crontab、服务启用状态。
- B 上部署前的阿里云 DNS 记录。
- SHA-256 完整性清单。

## 7. 一键恢复

A 恢复最近备份：

```bash
ssh root@47.113.227.103 \
  '/usr/local/sbin/dx-ha-restore latest-node-a'
```

B 恢复最近备份，同时恢复部署前 DNS：

```bash
ssh root@47.113.217.2 \
  '/usr/local/sbin/dx-ha-restore latest-node-b --restore-dns'
```

也可把 `latest-node-*` 换成指定的时间戳目录。恢复脚本先校验 SHA-256，
再恢复文件、crontab 和 timer 状态，随后执行 `nginx -t`、
`apachectl -t` 并重载服务。

## 8. 验收

自动测试：

```bash
./scripts/ci/ha-smoke.sh
```

生产检查：

```bash
# A 入口健康
curl -fsS -H 'Host: www.drupal.org.cn' \
  http://172.16.34.121/healthz

# A 能访问 B 业务后端
curl -fsS -H 'Host: www.drupal.org.cn' \
  http://172.16.34.126:88/user/login

# 权威 DNS 应指向 A（正常态）
aliyun alidns DescribeDomainRecords \
  --DomainName drupal.org.cn --Type A --PageSize 100
```

故障演练必须在维护窗口内进行。不要直接停止 B Apache 来测试 A fallback，
因为旧 DNS 缓存可能仍把部分用户送到 B；应只临时阻断 A→B:88，验证 A 的
本机 Apache 接管后立即解除。
