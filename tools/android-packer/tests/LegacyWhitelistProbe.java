import x.app.shell.PaymentHostPolicy;

import java.io.BufferedReader;
import java.io.InputStreamReader;
import java.io.FileInputStream;
import java.nio.charset.StandardCharsets;
import java.util.ArrayList;
import java.util.List;

/**
 * Offline regression probe for the shipped Android WebView whitelist
 * (L3 Phase H1). Plain JDK only — no Android SDK, no Gradle:
 *
 *   javac -d <tmp> PaymentHostPolicy.java LegacyWhitelistProbe.java
 *   java  -cp <tmp> LegacyWhitelistProbe <host=mode|...> <allowed_host> <hosts-file>
 *
 * For every host it compares the 1.2.x hard-coded expression (kept verbatim
 * in {@link #legacy}) against the generated rule table evaluated by
 * {@link PaymentHostPolicy}. Any divergence fails the build.
 */
public final class LegacyWhitelistProbe {

    /** Verbatim copy of shell 1.2.x MainActivity#isAllowedWebViewHost. */
    static boolean legacy(String host, String allowedHost) {
        if (host == null || host.isEmpty()) {
            return false;
        }
        String h = host.toLowerCase();
        if (h.equals(allowedHost) || h.endsWith("." + allowedHost)) {
            return true;
        }
        return h.equals("wx.tenpay.com")
            || h.endsWith(".tenpay.com")
            || h.equals("pay.weixin.qq.com")
            || h.endsWith(".pay.weixin.qq.com")
            || h.equals("open.weixin.qq.com")
            || h.contains("alipay.com")
            || h.contains("alipayobjects.com");
    }

    static String[][] parseRules(String csv) {
        List<String[]> rows = new ArrayList<>();
        for (String chunk : csv.split("\\|")) {
            if (chunk.trim().isEmpty()) {
                continue;
            }
            int eq = chunk.indexOf('=');
            if (eq < 0) {
                rows.add(new String[] {chunk.trim(), "domain"});
            } else {
                rows.add(new String[] {chunk.substring(0, eq).trim(), chunk.substring(eq + 1).trim()});
            }
        }
        return rows.toArray(new String[0][]);
    }

    public static void main(String[] args) throws Exception {
        if (args.length < 3) {
            System.err.println("usage: LegacyWhitelistProbe <payment-hosts-csv> <allowed_host> <hosts-file>");
            System.exit(2);
        }
        String[][] rules = parseRules(args[0]);
        String allowedHost = args[1];
        int checked = 0;
        int mismatches = 0;
        try (BufferedReader reader = new BufferedReader(
                new InputStreamReader(new FileInputStream(args[2]), StandardCharsets.UTF_8))) {
            String line;
            while ((line = reader.readLine()) != null) {
                int hash = line.indexOf('#');
                if (hash >= 0) {
                    line = line.substring(0, hash);
                }
                String host = line.trim();
                if (host.isEmpty()) {
                    continue;
                }
                checked++;
                boolean expected = legacy(host, allowedHost);
                boolean actual = allowedHost.equalsIgnoreCase(host)
                    || host.toLowerCase().endsWith("." + allowedHost.toLowerCase())
                    || PaymentHostPolicy.matchesAny(host, rules);
                if (expected != actual) {
                    mismatches++;
                    System.out.println("MISMATCH " + host + " legacy=" + expected + " generated=" + actual);
                }
            }
        }
        System.out.println("probe    : " + checked + " host(s), " + rules.length + " rule(s)");
        if (mismatches != 0) {
            System.out.println("FAIL     : " + mismatches + " divergence(s) from the shipped 1.2.x whitelist");
            System.exit(1);
        }
        System.out.println("PASS     : generated whitelist is behaviourally identical to shell 1.2.x");
    }
}
