package x.app.shell;

import android.Manifest;
import android.annotation.SuppressLint;
import android.app.AlertDialog;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.graphics.Bitmap;
import android.net.Uri;
import android.os.Bundle;
import android.view.KeyEvent;
import android.webkit.GeolocationPermissions;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.ProgressBar;

import androidx.annotation.NonNull;
import androidx.appcompat.app.AppCompatActivity;
import androidx.core.app.ActivityCompat;
import androidx.core.content.ContextCompat;
import androidx.swiperefreshlayout.widget.SwipeRefreshLayout;

/**
 * X App Shell — loads Hub H5 (read-only driver UI). Does not trigger data-source crawls.
 */
public class MainActivity extends AppCompatActivity {

    public static final String START_URL = "__START_URL__";
    public static final String ALLOWED_HOST = "__ALLOWED_HOST__";
    private static final int REQ_LOCATION = 1001;

    private WebView webView;
    private ProgressBar progressBar;
    private SwipeRefreshLayout swipe;
    private String pendingGeoOrigin;
    private GeolocationPermissions.Callback pendingGeoCallback;

    @SuppressLint("SetJavaScriptEnabled")
    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_main);

        progressBar = findViewById(R.id.progress);
        swipe = findViewById(R.id.swipe);
        webView = findViewById(R.id.webview);

        WebSettings settings = webView.getSettings();
        settings.setJavaScriptEnabled(true);
        settings.setDomStorageEnabled(true);
        settings.setLoadWithOverviewMode(true);
        settings.setUseWideViewPort(true);
        settings.setCacheMode(WebSettings.LOAD_DEFAULT);
        settings.setMediaPlaybackRequiresUserGesture(true);
        settings.setGeolocationEnabled(true);
        settings.setGeolocationDatabasePath(getFilesDir().getPath());
        String ua = settings.getUserAgentString();
        if (ua == null) {
            ua = "";
        }
        if (!ua.contains("CarHailingAndroid")) {
            settings.setUserAgentString(ua + " CarHailingAndroid/" + BuildConfig.VERSION_NAME);
        }

        webView.setWebChromeClient(new WebChromeClient() {
            @Override
            public void onProgressChanged(WebView view, int newProgress) {
                progressBar.setProgress(newProgress);
                progressBar.setVisibility(newProgress >= 100 ? ProgressBar.GONE : ProgressBar.VISIBLE);
            }

            @Override
            public void onGeolocationPermissionsShowPrompt(String origin, GeolocationPermissions.Callback callback) {
                if (hasLocationPermission()) {
                    callback.invoke(origin, true, false);
                    return;
                }
                pendingGeoOrigin = origin;
                pendingGeoCallback = callback;
                new AlertDialog.Builder(MainActivity.this)
                    .setTitle(R.string.location_title)
                    .setMessage(R.string.location_rationale)
                    .setPositiveButton(R.string.location_allow, (d, w) -> requestLocationPermission())
                    .setNegativeButton(R.string.location_deny, (d, w) -> finishGeoPrompt(false))
                    .setOnCancelListener(d -> finishGeoPrompt(false))
                    .show();
            }
        });

        webView.setWebViewClient(new WebViewClient() {
            @Override
            public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
                Uri uri = request.getUrl();
                String host = uri.getHost() == null ? "" : uri.getHost();
                String scheme = uri.getScheme() == null ? "" : uri.getScheme().toLowerCase();
                if ("weixin".equals(scheme) || "wechat".equals(scheme)
                    || "alipays".equals(scheme) || "alipay".equals(scheme)) {
                    try {
                        startActivity(new Intent(Intent.ACTION_VIEW, uri));
                    } catch (Exception ignored) {
                        // Payment app missing — stay in WebView.
                    }
                    return true;
                }
                if (isAllowedWebViewHost(host)) {
                    return false;
                }
                startActivity(new Intent(Intent.ACTION_VIEW, uri));
                return true;
            }

            @Override
            public void onPageStarted(WebView view, String url, Bitmap favicon) {
                progressBar.setVisibility(ProgressBar.VISIBLE);
            }

            @Override
            public void onPageFinished(WebView view, String url) {
                progressBar.setVisibility(ProgressBar.GONE);
                swipe.setRefreshing(false);
            }
        });

        swipe.setOnRefreshListener(() -> webView.reload());
        // Only claim vertical gesture when WebView is at top — otherwise inner
        // pages (ad intro, long lists) feel sticky / insensitive when scrolling.
        swipe.setOnChildScrollUpCallback((parent, child) ->
            webView != null && webView.getScrollY() > 0);

        String url = START_URL;
        Intent intent = getIntent();
        if (intent != null && intent.getData() != null) {
            url = intent.getData().toString();
        }
        webView.loadUrl(url);
    }

    private boolean hasLocationPermission() {
        return ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_FINE_LOCATION)
                == PackageManager.PERMISSION_GRANTED
            || ContextCompat.checkSelfPermission(this, Manifest.permission.ACCESS_COARSE_LOCATION)
                == PackageManager.PERMISSION_GRANTED;
    }

    private void requestLocationPermission() {
        ActivityCompat.requestPermissions(
            this,
            new String[] {
                Manifest.permission.ACCESS_FINE_LOCATION,
                Manifest.permission.ACCESS_COARSE_LOCATION,
            },
            REQ_LOCATION
        );
    }

    private void finishGeoPrompt(boolean allow) {
        if (pendingGeoCallback != null) {
            pendingGeoCallback.invoke(pendingGeoOrigin == null ? "" : pendingGeoOrigin, allow, false);
            pendingGeoCallback = null;
            pendingGeoOrigin = null;
        }
    }

    @Override
    public void onRequestPermissionsResult(int requestCode, @NonNull String[] permissions, @NonNull int[] grantResults) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults);
        if (requestCode != REQ_LOCATION) {
            return;
        }
        boolean ok = false;
        for (int r : grantResults) {
            if (r == PackageManager.PERMISSION_GRANTED) {
                ok = true;
                break;
            }
        }
        finishGeoPrompt(ok);
    }

    static boolean isAllowedWebViewHost(String host) {
        if (host == null || host.isEmpty()) {
            return false;
        }
        String h = host.toLowerCase();
        if (h.equals(ALLOWED_HOST) || h.endsWith("." + ALLOWED_HOST)) {
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

    @Override
    public boolean onKeyDown(int keyCode, KeyEvent event) {
        if (keyCode == KeyEvent.KEYCODE_BACK && webView != null && webView.canGoBack()) {
            webView.goBack();
            return true;
        }
        return super.onKeyDown(keyCode, event);
    }

    @Override
    protected void onDestroy() {
        if (webView != null) {
            webView.destroy();
        }
        super.onDestroy();
    }
}
