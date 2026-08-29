package x.app.shell;

import android.Manifest;
import android.annotation.SuppressLint;
import android.app.AlertDialog;
import android.content.ActivityNotFoundException;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.graphics.Bitmap;
import android.net.Uri;
import android.graphics.Color;
import android.os.Build;
import android.os.Bundle;
import android.view.KeyEvent;
import android.webkit.GeolocationPermissions;
import android.webkit.JavascriptInterface;
import android.webkit.PermissionRequest;
import android.webkit.ValueCallback;
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
import androidx.core.view.WindowCompat;
import androidx.core.view.WindowInsetsControllerCompat;
import androidx.swiperefreshlayout.widget.SwipeRefreshLayout;

/**
 * X App Shell — loads Hub H5 (read-only driver UI). Does not trigger data-source crawls.
 */
public class MainActivity extends AppCompatActivity {

    public static final String START_URL = "__START_URL__";
    public static final String ALLOWED_HOST = "__ALLOWED_HOST__";
    private static final int REQ_LOCATION = 1001;
    private static final int REQ_MIC = 1002;
    private static final int REQ_FILE_CHOOSER = 1003;
    private static final int REQ_MEDIA = 1004;

    private WebView webView;
    private ProgressBar progressBar;
    private SwipeRefreshLayout swipe;
    private String pendingGeoOrigin;
    private GeolocationPermissions.Callback pendingGeoCallback;
    private PermissionRequest pendingMicRequest;
    private ValueCallback<Uri[]> filePathCallback;
    private Intent pendingFileChooserIntent;
    private boolean fullscreen;

    @SuppressLint("SetJavaScriptEnabled")
    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_main);

        progressBar = findViewById(R.id.progress);
        swipe = findViewById(R.id.swipe);
        webView = findViewById(R.id.webview);
        webView.addJavascriptInterface(new NativeBridge(), "CarHailingNative");

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

            @Override
            public void onPermissionRequest(final PermissionRequest request) {
                if (request == null) {
                    return;
                }
                String[] resources = request.getResources();
                boolean wantsAudio = false;
                if (resources != null) {
                    for (String r : resources) {
                        if (PermissionRequest.RESOURCE_AUDIO_CAPTURE.equals(r)) {
                            wantsAudio = true;
                            break;
                        }
                    }
                }
                if (!wantsAudio) {
                    request.deny();
                    return;
                }
                if (hasMicPermission()) {
                    request.grant(request.getResources());
                    return;
                }
                pendingMicRequest = request;
                new AlertDialog.Builder(MainActivity.this)
                    .setTitle(R.string.mic_title)
                    .setMessage(R.string.mic_rationale)
                    .setPositiveButton(R.string.mic_allow, (d, w) -> askSystemMicPermission())
                    .setNegativeButton(R.string.mic_deny, (d, w) -> finishMicPrompt(false))
                    .setOnCancelListener(d -> finishMicPrompt(false))
                    .show();
            }

            @Override
            public boolean onShowFileChooser(
                WebView webView,
                ValueCallback<Uri[]> filePathCallback,
                FileChooserParams fileChooserParams
            ) {
                if (MainActivity.this.filePathCallback != null) {
                    MainActivity.this.filePathCallback.onReceiveValue(null);
                }
                MainActivity.this.filePathCallback = filePathCallback;
                Intent intent = fileChooserParams.createIntent();
                if (!hasMediaPermission()) {
                    pendingFileChooserIntent = intent;
                    new AlertDialog.Builder(MainActivity.this)
                        .setTitle(R.string.photo_title)
                        .setMessage(R.string.photo_rationale)
                        .setPositiveButton(R.string.photo_allow, (d, w) -> requestMediaPermission())
                        .setNegativeButton(R.string.photo_deny, (d, w) -> cancelFileChooser())
                        .setOnCancelListener(d -> cancelFileChooser())
                        .show();
                    return true;
                }
                return launchFileChooser(intent);
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

        swipe.setOnRefreshListener(() -> {
            // Never reload from pull — Hub/AI scroll must stay smooth.
            if (swipe != null) {
                swipe.setRefreshing(false);
            }
        });
        // Pull-to-refresh drags the whole WebView and leaves a blank gap above
        // the Hub title bar. Keep permanently disabled — H5 inner scroll (AI
        // history / lists) reports WebView scrollY=0, so enabling swipe steals
        // vertical gestures and feels like "can't scroll / can't switch".
        swipe.setEnabled(false);
        swipe.setRefreshing(false);
        swipe.setOnChildScrollUpCallback((parent, child) -> true);
        webView.setOverScrollMode(android.view.View.OVER_SCROLL_NEVER);

        String url = START_URL;
        Intent intent = getIntent();
        if (intent != null && intent.getData() != null) {
            url = intent.getData().toString();
        }
        webView.loadUrl(url);
    }

    private final class NativeBridge {
        @JavascriptInterface
        public void setFullscreen(boolean on) {
            runOnUiThread(() -> applyMapFullscreen(on));
        }

        @JavascriptInterface
        public void requestMicPermission() {
            runOnUiThread(() -> {
                if (MainActivity.this.hasMicPermission()) {
                    return;
                }
                new AlertDialog.Builder(MainActivity.this)
                    .setTitle(R.string.mic_title)
                    .setMessage(R.string.mic_rationale)
                    .setPositiveButton(R.string.mic_allow, (d, w) -> askSystemMicPermission())
                    .setNegativeButton(R.string.mic_deny, (d, w) -> { })
                    .show();
            });
        }

        @JavascriptInterface
        public boolean hasMicPermission() {
            return MainActivity.this.hasMicPermission();
        }
    }

    private void applyMapFullscreen(boolean on) {
        fullscreen = on;
        // Never re-enable SwipeRefresh after map fullscreen — see onCreate.
        if (swipe != null) {
            swipe.setEnabled(false);
            swipe.setRefreshing(false);
            // Residual pull translation leaves a blank gap above the Hub title bar.
            swipe.setTranslationY(0f);
            if (webView != null) {
                webView.setTranslationY(0f);
            }
        }
        // Keep decorFits=true always. Toggling edge-to-edge for map-fs leaves
        // env(safe-area-inset-top) stuck in WebView and creates a top gap after exit.
        WindowCompat.setDecorFitsSystemWindows(getWindow(), true);
        if (on) {
            getWindow().setStatusBarColor(Color.TRANSPARENT);
            getWindow().setNavigationBarColor(Color.TRANSPARENT);
            WindowInsetsControllerCompat bars =
                WindowCompat.getInsetsController(getWindow(), getWindow().getDecorView());
            bars.setAppearanceLightStatusBars(true);
            bars.setAppearanceLightNavigationBars(true);
        } else {
            getWindow().setStatusBarColor(Color.parseColor("#0B1F17"));
            getWindow().setNavigationBarColor(Color.parseColor("#0B1F17"));
            WindowInsetsControllerCompat bars =
                WindowCompat.getInsetsController(getWindow(), getWindow().getDecorView());
            bars.setAppearanceLightStatusBars(false);
            bars.setAppearanceLightNavigationBars(false);
            if (webView != null) {
                webView.requestLayout();
                webView.post(() -> {
                    webView.requestLayout();
                    webView.evaluateJavascript(
                        "(function(){try{"
                            + "document.documentElement.classList.remove('dh-map-fs');"
                            + "document.body.classList.remove('dh-map-fs');"
                            + "var t=document.querySelector('.dh-top');"
                            + "if(t){t.style.removeProperty('padding-top');t.style.removeProperty('margin-top');}"
                            + "var s=document.querySelector('.dh-shell');"
                            + "if(s){s.style.removeProperty('margin-top');s.style.removeProperty('transform');}"
                            + "window.dispatchEvent(new Event('resize'));"
                            + "}catch(e){}})();",
                        null
                    );
                });
            }
        }
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

    private boolean hasMicPermission() {
        return ContextCompat.checkSelfPermission(this, Manifest.permission.RECORD_AUDIO)
            == PackageManager.PERMISSION_GRANTED;
    }

    private void askSystemMicPermission() {
        ActivityCompat.requestPermissions(
            this,
            new String[] { Manifest.permission.RECORD_AUDIO },
            REQ_MIC
        );
    }

    private boolean hasMediaPermission() {
        if (Build.VERSION.SDK_INT >= 33) {
            return ContextCompat.checkSelfPermission(this, Manifest.permission.READ_MEDIA_IMAGES)
                == PackageManager.PERMISSION_GRANTED;
        }
        if (Build.VERSION.SDK_INT >= 23) {
            return ContextCompat.checkSelfPermission(this, Manifest.permission.READ_EXTERNAL_STORAGE)
                == PackageManager.PERMISSION_GRANTED;
        }
        return true;
    }

    private void requestMediaPermission() {
        if (Build.VERSION.SDK_INT >= 33) {
            ActivityCompat.requestPermissions(
                this,
                new String[] { Manifest.permission.READ_MEDIA_IMAGES },
                REQ_MEDIA
            );
            return;
        }
        ActivityCompat.requestPermissions(
            this,
            new String[] { Manifest.permission.READ_EXTERNAL_STORAGE },
            REQ_MEDIA
        );
    }

    private boolean launchFileChooser(Intent intent) {
        try {
            startActivityForResult(intent, REQ_FILE_CHOOSER);
            return true;
        } catch (ActivityNotFoundException e) {
            cancelFileChooser();
            return false;
        }
    }

    private void cancelFileChooser() {
        pendingFileChooserIntent = null;
        if (filePathCallback != null) {
            filePathCallback.onReceiveValue(null);
            filePathCallback = null;
        }
    }

    private void finishMicPrompt(boolean allow) {
        if (pendingMicRequest == null) {
            return;
        }
        if (allow) {
            pendingMicRequest.grant(pendingMicRequest.getResources());
        } else {
            pendingMicRequest.deny();
        }
        pendingMicRequest = null;
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
        if (requestCode == REQ_LOCATION) {
            boolean ok = false;
            for (int r : grantResults) {
                if (r == PackageManager.PERMISSION_GRANTED) {
                    ok = true;
                    break;
                }
            }
            finishGeoPrompt(ok);
            return;
        }
        if (requestCode == REQ_MIC) {
            boolean ok = grantResults.length > 0 && grantResults[0] == PackageManager.PERMISSION_GRANTED;
            finishMicPrompt(ok);
            return;
        }
        if (requestCode == REQ_MEDIA) {
            boolean ok = false;
            for (int r : grantResults) {
                if (r == PackageManager.PERMISSION_GRANTED) {
                    ok = true;
                    break;
                }
            }
            if (ok && pendingFileChooserIntent != null) {
                Intent intent = pendingFileChooserIntent;
                pendingFileChooserIntent = null;
                launchFileChooser(intent);
            } else {
                cancelFileChooser();
            }
        }
    }

    @Override
    protected void onActivityResult(int requestCode, int resultCode, Intent data) {
        super.onActivityResult(requestCode, resultCode, data);
        if (requestCode != REQ_FILE_CHOOSER || filePathCallback == null) {
            return;
        }
        Uri[] results = WebChromeClient.FileChooserParams.parseResult(resultCode, data);
        filePathCallback.onReceiveValue(results);
        filePathCallback = null;
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
    protected void onResume() {
        super.onResume();
        if (webView != null) {
            webView.onResume();
            webView.resumeTimers();
            // WebView often stays blank until first tap after backgrounding.
            webView.post(() -> {
                webView.invalidate();
                webView.requestLayout();
                webView.evaluateJavascript(
                    "(function(){try{"
                        + "var r=document.documentElement;"
                        + "r.style.transform='translateZ(0)';"
                        + "requestAnimationFrame(function(){"
                        + "r.style.transform='';"
                        + "window.dispatchEvent(new Event('resize'));"
                        + "});"
                        + "}catch(e){}})();",
                    null);
            });
        }
    }

    @Override
    protected void onPause() {
        if (webView != null) {
            webView.onPause();
        }
        super.onPause();
    }

    @Override
    protected void onDestroy() {
        if (filePathCallback != null) {
            filePathCallback.onReceiveValue(null);
            filePathCallback = null;
        }
        if (webView != null) {
            webView.destroy();
        }
        super.onDestroy();
    }
}
