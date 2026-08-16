# DrupalX A/B LNMPA policy

This profile is based on the existing `/home/wwwroot/balanceConf` deployment
from the Drupal 10→11 upgrade. It preserves the LNMPA chain
`Nginx :80/:443 -> Apache :88` and changes the old weighted split into strict
B-first processing.

## Resulting traffic policy

```text
Normal:
DNS -> A Nginx (public/static)
          `-> B Apache:88 (all Drupal/PHP business requests)
                `-> A Apache:88 only when B is unavailable

A unavailable:
DNS -> B Nginx -> B Apache:88

A recovered:
DNS -> A Nginx -> B Apache:88
```

The important change from the old `balanceServers.conf` is:

```nginx
server 172.16.34.126:88;
server 127.0.0.1:88 backup;
```

Nginx's `backup` flag guarantees that A does not receive normal business
traffic. The old B `weight=3` + A `weight=1` setup still sent a share to A and
did not meet this policy.

## Shared-state prerequisites

Both nodes must use the same Drupal code/config release, database, Redis/session
store, and uploaded files (shared object storage or reliable synchronization).
Without shared state, traffic failover can expose stale code/files or lose
sessions even when the web tier itself switches correctly.

The copied upgrade configuration uses these private addresses:

- A: `172.16.34.121`
- B: `172.16.34.126`

Verify them before deployment. B's TCP 88 should accept only A's private IP and
B localhost/private traffic, never the public Internet.

## Install LNMPA configuration

On A:

```bash
sudo cp setup/ha/lnmpa/node-a-balance-servers.conf \
  /usr/local/nginx/conf/balanceServers.conf
sudo cp setup/ha/lnmpa/node-a-proxy-pass-php.conf \
  /usr/local/nginx/conf/proxy-pass-php.conf
```

Ensure `/usr/local/nginx/conf/nginx.conf` includes
`balanceServers.conf` once inside its `http {}` block. Do not also include the
old `balanceServers_sticky.conf`.

On B:

```bash
sudo cp setup/ha/lnmpa/node-b-proxy-pass-php.conf \
  /usr/local/nginx/conf/proxy-pass-php.conf
```

B intentionally proxies only to `127.0.0.1:88`; proxying back to A would create
a loop during takeover.

Install `setup/ha/lnmpa/www.drupal.org.cn.conf` and the Apache vhost from
`setup/apache/` on both nodes. Keep the TLS certificate/key synchronized.
Validate and reload one node at a time:

```bash
sudo nginx -t
sudo /usr/local/apache/bin/apachectl -t
sudo /etc/init.d/httpd restart
sudo /etc/init.d/nginx reload
```

## Install automatic public-entry failover on B

The inherited deployment uses Aliyun DNS rather than a movable VIP. Install
the checker only on B:

```bash
sudo install -m 0755 setup/ha/dns-failover.sh \
  /usr/local/sbin/dx-dns-failover
sudo cp setup/ha/dns-failover.env.example /root/dx-dns-failover.env
sudo chmod 600 /root/dx-dns-failover.env
# Edit all example public IPs and verify RR_LIST before continuing.

sudo cp setup/ha/systemd/dx-dns-failover.service /etc/systemd/system/
sudo cp setup/ha/systemd/dx-dns-failover.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now dx-dns-failover.timer
```

Configure the Aliyun CLI on B with a least-privilege RAM identity. The checker
requires read/update access to the listed records. It switches `www`, apex,
and `x` together, uses a lock to prevent overlap, requires three consecutive
failures/recoveries, and treats a partial DNS update as failure.

Inspect it with:

```bash
sudo systemctl start dx-dns-failover.service
sudo systemctl status dx-dns-failover.service
sudo journalctl -u dx-dns-failover.service
sudo cat /var/lib/dx-ha/dns-state
```

## Acceptance sequence

1. Both healthy: DNS resolves to A; B Apache access logs grow for Drupal/PHP
   requests; A Apache logs do not.
2. Stop B Apache: requests continue and A Apache logs begin growing.
3. Restore B Apache: after the 10-second fail timeout, new business requests
   return to B and A becomes idle.
4. Stop all LNMPA services on A: after three failed checks, all configured DNS
   records move to B; B Nginx serves through local Apache.
5. Restore A: after three successful checks, records return to A, which again
   forwards business requests to B.

DNS failover is not instantaneous: recursive resolvers and clients may retain
the old A record up to its TTL. The current Aliyun plan enforces a 600-second
minimum. If near-zero cutover is required, upgrade the DNS plan or put an
Aliyun ALB/SLB in front of both nodes; a local DNS script cannot eliminate
cache delay.

Production backup, deployment, automatic rollback, and one-command restore are
documented in
[`docs/automatic-load-balancing.md`](../../docs/automatic-load-balancing.md).
