package x.app.shell;

/**
 * Payment / authorisation host matcher for the WebView shell (shell 1.3.0).
 *
 * <p>The rule <em>data</em> lives in {@link MainActivity#PAYMENT_HOSTS}, which
 * {@code scripts/x-pack-android.sh} generates from the app manifest
 * ({@code payment_hosts}). This class only interprets that table, so the rule
 * set stays unit-testable with a plain JDK (no Android, no Gradle):
 * {@code tools/android-packer/tests/}.
 *
 * <p>Modes (defaults reproduce the hard-coded 1.2.x expression):
 * <ul>
 *   <li>{@code exact} – the host itself</li>
 *   <li>{@code domain} – the host and any sub-domain of it</li>
 *   <li>{@code child} – strictly sub-domains (host itself excluded)</li>
 *   <li>{@code contains} – the host appears anywhere in the request host</li>
 * </ul>
 */
public final class PaymentHostPolicy {

    public static final String EXACT = "exact";
    public static final String DOMAIN = "domain";
    public static final String CHILD = "child";
    public static final String CONTAINS = "contains";

    private PaymentHostPolicy() {
    }

    /** True when {@code host} hits at least one rule. */
    public static boolean matchesAny(String host, String[][] rules) {
        if (host == null || host.isEmpty() || rules == null) {
            return false;
        }
        String h = host.toLowerCase();
        for (String[] rule : rules) {
            if (rule == null || rule.length < 1) {
                continue;
            }
            String pattern = rule[0] == null ? "" : rule[0].toLowerCase();
            if (pattern.isEmpty()) {
                continue;
            }
            String mode = rule.length > 1 && rule[1] != null ? rule[1].toLowerCase() : DOMAIN;
            if (mode.isEmpty()) {
                mode = DOMAIN;
            }
            if (matches(h, pattern, mode)) {
                return true;
            }
        }
        return false;
    }

    public static boolean matches(String host, String pattern, String mode) {
        if (CONTAINS.equals(mode)) {
            return host.contains(pattern);
        }
        if (CHILD.equals(mode)) {
            return host.endsWith("." + pattern);
        }
        if (DOMAIN.equals(mode)) {
            return host.equals(pattern) || host.endsWith("." + pattern);
        }
        // exact, and any unrecognised mode: fail closed (never widen the
        // payment whitelist because of a typo in the manifest).
        return host.equals(pattern);
    }
}
