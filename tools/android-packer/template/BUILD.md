# Build Android APK (X App Shell)

## Requirements

- Android Studio Hedgehog+ (bundles JDK 17) **or** JDK 17 + Android SDK 34
- Network for first Gradle sync

## Android Studio

1. Open this directory as a project
2. Wait for Gradle sync
3. Build → Build Bundle(s) / APK(s) → Build APK(s)
4. Debug APK: `app/build/outputs/apk/debug/`

## CLI

```bash
export JAVA_HOME=/path/to/jdk-17
export ANDROID_HOME=/path/to/Android/Sdk
./gradlew :app:assembleRelease
```

Release builds use the **debug keystore** for OSS sideload (`signingConfig signingConfigs.debug`). Replace with a release keystore before Play Store upload.

If `gradlew` is missing, Android Studio will generate the wrapper on first open, or run:

```bash
gradle wrapper --gradle-version 8.2
```

## Notes

- **Shell template version: 1.3.0** (`app/build.gradle` → `SHELL_VERSION`, manifest
  meta-data `x.shell_version`). The published app version is `version_name` in the
  tenant manifest and is independent of the shell version.
- The payment host whitelist, the handed-over schemes and the three runtime
  capabilities (location / microphone / photo_upload) are **generated** into
  `DX_PAYMENT` in `MainActivity.java`, the `DX_PERMISSIONS` block in
  `AndroidManifest.xml` and the `CAP_*` BuildConfig flags. Editing them by hand in
  a packed project is fine for a hotfix, but the fix belongs in
  `tools/android-packer/apps/<app>.manifest.yml` — see `docs/android-pack.md`.
- App is a WebView shell around Hub H5 (`START_URL`). It does **not** crawl flight/train sources.
- Release signing: configure `signingConfigs` before Play upload.
