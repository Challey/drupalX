#!/usr/bin/env python3
"""L3 Phase H1 offline regression suite for the Android shell codegen.

Pure stdlib, no Android SDK, no Gradle, no network:

  python3 tools/android-packer/tests/test_shell_pack.py

Covers the backward-compatibility contract of shell 1.3.0:
  * defaults of DX-PACK-MANIFEST reproduce the shipped 1.2.x whitelist
    (python oracle vs. the rule interpreter used by the generator)
  * packing with an untouched manifest leaves the template's checked-in
    permission block / BuildConfig flags / whitelist intact
  * capability + payment-host + rationale overrides reach the right files
  * no token can ever ship unresolved (leftover scan + marker guards)
"""

from __future__ import annotations

import os
import shutil
import subprocess
import sys
import tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, "..", "..", ".."))
sys.path.insert(0, os.path.join(ROOT, "tools", "packer"))
sys.path.insert(0, os.path.join(ROOT, "tools", "android-packer", "lib"))

import manifest_lib as ml  # noqa: E402
import shell_codegen as cg  # noqa: E402

TEMPLATE = os.path.join(ROOT, "tools", "android-packer", "template")
HOSTS_FILE = os.path.join(HERE, "whitelist-hosts.txt")

FAILURES: list = []
PASSED = [0]

DEFAULT_MANIFEST = {
    "app_id": "whitelist_probe",
    "label": "Whitelist Probe",
    "application_id": "run.example.probe",
    "start_url": "https://www.topstar.run/driver",
}


def check(name, condition, detail=""):
    if condition:
        PASSED[0] += 1
        print("ok   - %s" % name)
    else:
        FAILURES.append(name)
        print("FAIL - %s %s" % (name, detail))


def resolve(manifest, overrides=None):
    schema = ml.load_schema()
    return ml.resolve_config(schema, "android", manifest, overrides,
                             app_id=str(manifest.get("app_id", "")))


def read(path):
    with open(path, encoding="utf-8") as fh:
        return fh.read()


def pack(config):
    """Run the generator over a throwaway copy of the delivered template."""
    work = tempfile.mkdtemp(prefix="dx-pack-test-")
    dest = os.path.join(work, "project")
    shutil.copytree(TEMPLATE, dest)
    report = cg.run_apply(dest, config, "20260830_000000")
    return work, dest, report


# --------------------------------------------------------------------------
# 1. defaults == shipped behaviour
# --------------------------------------------------------------------------

def test_defaults():
    cfg = resolve(dict(DEFAULT_MANIFEST))
    expected_rules = [
        {"host": "wx.tenpay.com", "match": "exact"},
        {"host": "tenpay.com", "match": "child"},
        {"host": "pay.weixin.qq.com", "match": "domain"},
        {"host": "open.weixin.qq.com", "match": "exact"},
        {"host": "alipay.com", "match": "contains"},
        {"host": "alipayobjects.com", "match": "contains"},
    ]
    check("default payment_hosts == 1.2.x hard-coded set",
          cfg["payment_hosts"] == expected_rules, repr(cfg["payment_hosts"]))
    check("default capabilities keep location+mic+photo",
          cfg["capabilities"] == ["location", "microphone", "photo_upload"])
    check("default payment_schemes == weixin/wechat/alipays/alipay",
          cfg["payment_schemes"] == ["weixin", "wechat", "alipays", "alipay"])
    check("shell_version defaults to 1.3.0", cfg["shell_version"] == "1.3.0")
    check("allowed_host falls back to the start_url host",
          cfg["allowed_host"] == "www.topstar.run")


def legacy_allowed(host, allowed_host):
    """Verbatim shell 1.2.x MainActivity#isAllowedWebViewHost expression."""
    if not host:
        return False
    h = host.lower()
    if h == allowed_host or h.endswith("." + allowed_host):
        return True
    return (h == "wx.tenpay.com" or h.endswith(".tenpay.com")
            or h == "pay.weixin.qq.com" or h.endswith(".pay.weixin.qq.com")
            or h == "open.weixin.qq.com" or "alipay.com" in h or "alipayobjects.com" in h)


def policy_matches(host, rules):
    """Mirror of PaymentHostPolicy.java, kept for the python oracle."""
    h = (host or "").lower()
    for rule in rules:
        pattern, mode = rule["host"].lower(), rule["match"]
        if mode == "contains":
            hit = pattern in h
        elif mode == "child":
            hit = h.endswith("." + pattern)
        elif mode == "domain":
            hit = h == pattern or h.endswith("." + pattern)
        else:
            hit = h == pattern
        if hit:
            return True
    return False


def test_whitelist_matrix():
    cfg = resolve(dict(DEFAULT_MANIFEST))
    allowed = cfg["allowed_host"]
    hosts = [
        line.split("#")[0].strip()
        for line in read(HOSTS_FILE).splitlines()
    ]
    hosts = [h for h in hosts if h]
    bad = []
    for host in hosts:
        want = legacy_allowed(host, allowed)
        got = policy_matches(host, cfg["payment_hosts"]) or legacy_allowed(host, allowed)
        if want != got:
            bad.append("%s legacy=%s generated=%s" % (host, want, got))
    check("whitelist matrix (%d hosts) matches 1.2.x" % len(hosts),
          not bad, "; ".join(bad))


# --------------------------------------------------------------------------
# 2. template wiring stays in sync with the generator
# --------------------------------------------------------------------------

def test_template_parity():
    cfg = resolve(dict(DEFAULT_MANIFEST))
    manifest_xml = read(os.path.join(TEMPLATE, "app/src/main/AndroidManifest.xml"))
    start = manifest_xml.find("<!-- BEGIN DX_PERMISSIONS")
    stop = manifest_xml.find("<!-- END DX_PERMISSIONS -->")
    body = "\n".join(manifest_xml[start:stop].split("\n")[1:]).rstrip()
    check("checked-in permission block == generated default block",
          body == cg.permission_block_text(cfg["capabilities"]),
          "\n--- template ---\n%s\n--- generated ---\n%s" % (body, cg.permission_block_text(cfg["capabilities"])))
    sources = {
        "MainActivity.java": read(os.path.join(TEMPLATE, "app/src/main/java/x/app/shell/MainActivity.java")),
        "build.gradle": read(os.path.join(TEMPLATE, "app/build.gradle")),
        "strings.xml": read(os.path.join(TEMPLATE, "app/src/main/res/values/strings.xml")),
        "AndroidManifest.xml": manifest_xml,
        "settings.gradle": read(os.path.join(TEMPLATE, "settings.gradle")),
    }
    blob = "\n".join(sources.values())
    missing = [token for token in cg.KNOWN_TOKENS if token not in blob]
    check("every generator token exists in the delivered template", not missing, str(missing))
    check("__PROJECT_NAME__ is wired into settings.gradle",
          "__PROJECT_NAME__" in sources["settings.gradle"])
    check("PaymentHostPolicy ships with the template",
          os.path.isfile(os.path.join(TEMPLATE, "app/src/main/java/x/app/shell/PaymentHostPolicy.java")))


def test_default_pack_is_a_no_op():
    cfg = resolve(dict(DEFAULT_MANIFEST))
    work, dest, report = pack(cfg)
    try:
        check("default pack leaves no unresolved token",
              not report["leftover_tokens"], str(report["leftover_tokens"]))
        java = read(os.path.join(dest, "app/src/main/java/x/app/shell/MainActivity.java"))
        check("MainActivity gets the 6 default payment rules",
              java.count('", "') >= 6 and '{"wx.tenpay.com", "exact"}' in java)
        check("MainActivity carries SHELL_VERSION 1.3.0",
              'SHELL_VERSION = "1.3.0"' in java)
        check("isAllowedWebViewHost delegates to PaymentHostPolicy",
              "PaymentHostPolicy.matchesAny(h, PAYMENT_HOSTS)" in java)
        gradle = read(os.path.join(dest, "app/build.gradle"))
        check("all three capability flags default to true",
              'buildConfigField "boolean", "CAP_LOCATION", "true"' in gradle
              and 'CAP_MICROPHONE", "true"' in gradle
              and 'CAP_PHOTO_UPLOAD", "true"' in gradle)
        check("pack snapshot records the whitelist",
              '"payment_hosts"' in read(os.path.join(dest, "x-app.json")))
        check("pack snapshot keeps the legacy keys ops scripts read",
              all(('"%s"' % key) in read(os.path.join(dest, "x-app.json"))
                  for key in ("app_id", "application_id", "start_url", "version_code", "packed_at")))
    finally:
        shutil.rmtree(work)


def test_capability_trimming():
    cfg = resolve(dict(DEFAULT_MANIFEST), {"capabilities": "location"})
    check("list override parses comma separated capabilities",
          cfg["capabilities"] == ["location"], repr(cfg["capabilities"]))
    work, dest, report = pack(cfg)
    try:
        manifest_xml = read(os.path.join(dest, "app/src/main/AndroidManifest.xml"))
        check("microphone permission removed when capability off",
              "RECORD_AUDIO" not in manifest_xml)
        check("photo permission removed when capability off",
              "READ_MEDIA_IMAGES" not in manifest_xml)
        check("location permission kept",
              "ACCESS_FINE_LOCATION" in manifest_xml)
        check("always-present INTERNET permission untouched",
              "android.permission.INTERNET" in manifest_xml)
        gradle = read(os.path.join(dest, "app/build.gradle"))
        check("CAP_MICROPHONE flips to false",
              'buildConfigField "boolean", "CAP_MICROPHONE", "false"' in gradle)
        check("capability pack leaves no token behind", not report["leftover_tokens"],
              str(report["leftover_tokens"]))
        # the whitelist must be unaffected by capability trimming
        java = read(os.path.join(dest, "app/src/main/java/x/app/shell/MainActivity.java"))
        check("payment whitelist untouched by capability trimming",
              '{"alipayobjects.com", "contains"}' in java)
    finally:
        shutil.rmtree(work)


def test_payment_host_override():
    cfg = resolve(dict(DEFAULT_MANIFEST))
    rules = ml.normalize_host_rules([{"host": "pay.other.cn"}, {"host": "x.test", "match": "exact"}])
    cfg["payment_hosts"] = cfg["payment_hosts"] + rules
    work, dest, report = pack(cfg)
    try:
        java = read(os.path.join(dest, "app/src/main/java/x/app/shell/MainActivity.java"))
        check("appended rule reaches MainActivity table",
              '{"pay.other.cn", "domain"}' in java and '{"x.test", "exact"}' in java)
        manifest_xml = read(os.path.join(dest, "app/src/main/AndroidManifest.xml"))
        check("appended rule reaches the audit meta-data",
              "pay.other.cn=domain" in manifest_xml)
        strings = read(os.path.join(dest, "app/src/main/res/values/strings.xml"))
        check("privacy summary describes the added domain",
              "pay.other.cn 及其子域" in strings)
        check("override pack leaves no token behind", not report["leftover_tokens"])
    finally:
        shutil.rmtree(work)


def test_rationale_override():
    cfg = resolve(dict(DEFAULT_MANIFEST))
    cfg["permission_rationales"] = {"location": "仅用于派单距离计算 <测试> & 校验"}
    work, dest, report = pack(cfg)
    try:
        strings = read(os.path.join(dest, "app/src/main/res/values/strings.xml"))
        check("rationale override lands in strings.xml",
              "仅用于派单距离计算 &lt;测试&gt; &amp; 校验" in strings)
        check("other rationale strings keep the shipped copy",
              "用于 AI 问道语音输入" in strings)
        check("rationale pack leaves no token behind", not report["leftover_tokens"])
    finally:
        shutil.rmtree(work)


def test_failure_modes():
    cfg = resolve(dict(DEFAULT_MANIFEST))
    work = tempfile.mkdtemp(prefix="dx-pack-test-")
    dest = os.path.join(work, "project")
    shutil.copytree(TEMPLATE, dest)
    try:
        broken = os.path.join(dest, "app/src/main/AndroidManifest.xml")
        text = read(broken).replace("<!-- BEGIN DX_PERMISSIONS (generated from manifest capabilities; default = shipped 1.2.x set) -->",
                                    "<!-- permissions -->")
        with open(broken, "w", encoding="utf-8") as fh:
            fh.write(text)
        try:
            cg.run_apply(dest, cfg, "T")
            check("missing DX_PERMISSIONS markers abort the pack", False, "no exception raised")
        except SystemExit as exc:
            check("missing DX_PERMISSIONS markers abort the pack",
                  "missing DX_PERMISSIONS markers" in str(exc))
    finally:
        shutil.rmtree(work)

    stray = tempfile.mkdtemp(prefix="dx-pack-test-")
    try:
        with open(os.path.join(stray, "leftover.txt"), "w", encoding="utf-8") as fh:
            fh.write("__PAYMENT_HOSTS__ still here\n")
        check("leftover token scan detects an unreplaced template token",
              cg.scan_leftover_tokens(stray) == ["leftover.txt:__PAYMENT_HOSTS__"])
    finally:
        shutil.rmtree(stray)


def test_schema_gate_rejects_bad_input():
    """The H4 gate must fail closed on whitelist / capability typos."""
    cases = [
        ({"payment_hosts": [{"host": "pay.ok.cn", "match": "prefix"}]}, "bad match mode"),
        ({"payment_hosts": [{"match": "exact"}]}, "rule without host"),
        ({"capabilities": ["vibration"]}, "unknown capability"),
        ({"shell_version": "1.3"}, "malformed shell_version"),
        ({"version_code": "19"}, "string version_code is coerced, must pass"),
    ]
    schema = ml.load_schema()
    for manifest, label in cases:
        data = dict(DEFAULT_MANIFEST)
        data.update(manifest)
        cfg = ml.resolve_config(schema, "android", data, app_id=data["app_id"])
        report = ml.validate_config(schema, "android", cfg)
        if label.endswith("must pass"):
            check("gate accepts %s" % label, report["ok"], str(report["errors"]))
        else:
            check("gate rejects %s" % label, not report["ok"], str(report["errors"]))


def test_cli_end_to_end():
    """scripts/x-pack-manifest.sh must run the gate for all three platforms."""
    proc = subprocess.run(
        ["bash", os.path.join(ROOT, "scripts", "x-pack-manifest.sh"), "--all"],
        cwd=ROOT, capture_output=True, text=True, check=False)
    check("x-pack-manifest.sh --all exits 0", proc.returncode == 0,
          proc.stdout + proc.stderr)
    check("x-pack-manifest.sh --all reports every registered manifest",
          proc.stdout.count("OK  ") >= 3, proc.stdout)


def main() -> int:
    test_defaults()
    test_whitelist_matrix()
    test_template_parity()
    test_default_pack_is_a_no_op()
    test_capability_trimming()
    test_payment_host_override()
    test_rationale_override()
    test_failure_modes()
    test_schema_gate_rejects_bad_input()
    test_cli_end_to_end()
    print("\n%d check(s) passed, %d failed" % (PASSED[0], len(FAILURES)))
    for name in FAILURES:
        print("  FAILED: %s" % name)
    return 1 if FAILURES else 0


if __name__ == "__main__":
    raise SystemExit(main())
