# Releasing EchoLedger for Android

EchoLedger for Android is distributed as a signed APK that you sideload. Two of its features are not allowed on
Google Play:

- call capture through the shell-UID helper;
- the special-use foreground service.

So a Play listing would have to ship without them.

## Build

Prerequisites: JDK 21 (the one bundled with Android Studio works) and Android SDK platform 37 with build-tools 37.

```powershell
# Windows
$env:JAVA_HOME = "$env:LOCALAPPDATA\Android\Sdk\jdk"   # or Android Studio's jbr folder
.\gradlew.bat assembleDebug                             # app/build/outputs/apk/debug/app-debug.apk
.\gradlew.bat assembleRelease                           # needs signing, see below
```

```bash
# macOS / Linux
./gradlew assembleRelease
```

Release builds are minified with R8 and resource-shrunk. `proguard-rules.pro` keeps the classes that are reached
by reflection:

- the AIDL stubs;
- the helper entry point `HelperMain`, launched by `app_process`;
- the Shizuku user-service class;
- the kotlinx-serialization models.

## Signing

1. Create an upload keystore once. Keep it offline and back it up: if it is lost, existing installs cannot be
   updated.

   ```bash
   keytool -genkeypair -v -keystore echoledger-release.jks -alias echoledger \
     -keyalg RSA -keysize 4096 -validity 10000
   ```

2. Give Gradle the credentials in one of two ways. Both are ignored by git (`*.jks`, `keystore.properties`).
   - **`mobile/keystore.properties`:**

     ```properties
     storeFile=C:/secure/echoledger-release.jks
     storePassword=…
     keyAlias=echoledger
     keyPassword=…
     ```

   - **Environment variables (CI):** `ECHOLEDGER_KEYSTORE`, `ECHOLEDGER_KEYSTORE_PASSWORD`,
     `ECHOLEDGER_KEY_ALIAS`, `ECHOLEDGER_KEY_PASSWORD`.

3. Run `.\gradlew.bat assembleRelease`. The output is `app/build/outputs/apk/release/app-release.apk`. If no
   keystore is configured, the build produces an unsigned APK, which Android will not install.

4. Verify the signature and record the certificate digest. Publish the digest next to the download so users can
   check it:

   ```bash
   apksigner verify --print-certs app-release.apk
   ```

Always sign updates with the same key. Android refuses an update whose signing certificate does not match the
installed app.

## Sideloading

1. Copy the APK to the phone, or serve it over HTTPS from your EchoLedger server.
2. Open it and allow **Install unknown apps** for the browser or file manager when Android asks.
3. On Android 14 and later, Play Protect may warn about an app from an unknown developer: choose **More details →
   Install anyway**. Android 15 can also ask for a one-time confirmation.
4. Open EchoLedger and pair it from **Admin → Devices → Pair a device**: enter the server address and the code, or
   paste the QR text.

ADB alternative: `adb install -r app-release.apk`.

## Call capture setup (Android 14+)

Recording the far side of WhatsApp calls needs the capture helper running as the shell user. The in-app **Setup**
screen walks through it. You can start the helper with Shizuku or with ADB.

**Option A: Shizuku (recommended)**

1. Install Shizuku from its official GitHub releases or Play listing.
2. Start Shizuku with **Wireless debugging**. Pairing uses a 6-digit code and is needed only the first time.
3. In EchoLedger, tap **Connect via Shizuku** and grant the permission.

The helper runs as a Shizuku user service until the phone reboots. After a reboot, start Shizuku again; you do not
need to pair again.

**Option B: ADB from a computer**

Run the command shown in Setup once after every reboot:

```bash
adb shell "CLASSPATH=$(pm path com.echoledger.mobile | cut -d: -f2) nohup app_process /system/bin \
  com.echoledger.mobile.capture.HelperMain com.echoledger.mobile.handoff >/dev/null 2>&1 &"
```

The helper hands its binder to the app through `BinderHandoffProvider`. The provider accepts calls only from UID
2000 (shell), UID 0 or the app itself. Reinstalling the app changes its `pm path`, so run the command again after
every update.

**Keep-alive on Samsung and other OEM builds**

- Set **Settings → Apps → EchoLedger → Battery** to **Unrestricted**.
- Remove EchoLedger from **Sleeping apps** and **Deep sleeping apps**.
- See dontkillmyapp.com for instructions specific to your phone model.

## Updates

1. Bump `versionCode` (always increasing) and `versionName` in `app/build.gradle.kts`.
2. Rebuild and re-sign with the same key.
3. Install over the existing app. Local recordings stay in `noBackupFilesDir`, encrypted with the app's Keystore
   key, so they survive updates but not uninstalls.

The Shizuku user service is versioned by `versionCode`. After an update, EchoLedger restarts the helper on the
next connect.

## Privacy checklist per release

- **No analytics or telemetry SDKs.** `.\gradlew.bat :app:dependencies --configuration releaseRuntimeClasspath`
  must not list Firebase, Crashlytics, Sentry or other analytics libraries.
- **No provider keys in the APK.** `unzip -p app-release.apk classes*.dex | strings` must not contain `sk_`,
  `xi-api-key` or AssemblyAI keys. The app receives only short-lived streaming tokens from the server.
- **Cleartext traffic is off** in release builds (`network_security_config.xml`). Only the debug build allows
  `localhost` and `10.0.2.2`.
- **Secrets are encrypted at rest.** The device token is sealed with a Tink AEAD keyset wrapped by the Android
  Keystore. Recordings are encrypted with Tink streaming AEAD in no-backup storage, and
  `data_extraction_rules.xml` excludes them from cloud backup and device transfer.
- **Capture is always visible.** A notification is shown whenever capture is armed or recording. Voice notes are
  never recorded automatically; they are imported only when you share them to EchoLedger.
