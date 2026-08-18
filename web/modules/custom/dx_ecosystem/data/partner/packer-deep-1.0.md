# Partner vault · Packer & signing deep guide (L2)

> Visibility: **partner** — certified developers with current DPA only.  
> This is a stub for OE2 gating; replace with real packer/signing runbooks later.

## Scope

- Certificate path refs via `dx_certs` (no private keys in config)
- `scripts/x-pack-flutter.sh` and `scripts/pack-tenant-channels.sh` gates
- Hosted CI signing material locations (internal)

## Forbidden for public / L1

- Real keystore passwords, Apple API keys, provisioning profile blobs
- Tenant Channel bearer tokens from production

## Access rule

Must be `certified` + DPA version match + `access dx partner vault` permission
(or `administer dx ecosystem`).
