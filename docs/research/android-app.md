# EchoLedger Android app platform: verified research notes

Scope: `mobile/`, the native Kotlin + Jetpack Compose + Glance app. This covers the toolchain, versions, foreground-service (FGS) rules, the widget, tile, overlay and share sheet, networking, secrets and testing.
Call-capture internals (Shizuku, the `app_process` helper, the audio policy) are **out of scope** here.
Verified on **2026-10-24**. Every fact below comes from a primary source opened during research, and its URL or source path sits next to it. **UNVERIFIED** marks anything that could not be confirmed.
Abbreviations:
- **AOSP@17r1**: `https://android.googlesource.com/platform/frameworks/base/+/refs/tags/android-17.0.0_r1/<path>`. **AOSP@main** is the same path at `refs/heads/main`.
- **AAR meta**: `META-INF/com/android/build/gradle/aar-metadata.properties` inside the published AAR on `dl.google.com`.

---

## 0. This machine (checked read-only)

| Item | Found | Evidence |
|---|---|---|
| JDK | Eclipse Temurin **21.0.12.1+1 LTS** at `%LOCALAPPDATA%\Android\Sdk\jdk` | `jdk\release` |
| SDK platforms | android-34, android-35, android-36 (API 36 rev 2, extension level 17). **android-37 is not installed** | `platforms\*\source.properties` |
| Build-tools | 35.0.0, 36.0.0 | `build-tools\*\source.properties` |
| cmdline-tools / platform-tools | 12.0 (`cmdline-tools\latest\bin\sdkmanager.bat` present) / 37.0.1 | `source.properties` |
| NDK | 27.1.12297006 (needed only for JNI) | directory listing |
| SDK licenses | `licenses\android-sdk-license`, `android-sdk-preview-license`, ... | directory listing |
| Gradle | None on PATH. `JAVA_HOME` and `ANDROID_HOME` are unset. An extracted Gradle **9.3.1** is cached at `%USERPROFILE%\.gradle\wrapper\dists\gradle-9.3.1-bin\23ovyewtku6u96viwx3xl3oks\gradle-9.3.1\bin\gradle.bat` | `Get-Command`, directory listing |

---

## 1. Versions (latest stable on 2026-10-24)

### 1.1 Toolchain

| Component | Value | Evidence |
|---|---|---|
| Android Gradle Plugin | **9.4.1**, the latest stable. 9.5.0-rc01 and 9.6.0-alpha01 are pre-releases | [Google Maven metadata](https://dl.google.com/dl/android/maven2/com/android/tools/build/gradle/maven-metadata.xml) |
| AGP 9.4 compatibility | Gradle min/default **9.6.0**. Build Tools min/default **36.0.0** (installed). NDK default 28.2.13676358. JDK min 17. **Max API 37**. Notes dated September 2026 | [AGP 9.4 release notes](https://developer.android.com/build/releases/gradle-plugin) |
| AGP to minimum Gradle | 9.4 needs 9.6.0. 9.3 needs 9.5.0. 9.2 needs 9.4.1. 9.1 needs 9.3.1. 9.0 needs 9.1.0 | [About AGP](https://developer.android.com/build/releases/about-agp) |
| API level to minimum AGP | API 37.0 needs AGP 9.1.1 (Studio Panda 3 Patch 1). API 36 needs 8.9.1 | [About AGP](https://developer.android.com/build/releases/about-agp) |
| Gradle | **9.8.2**: current, final, built 2026-10-19. Zip `https://services.gradle.org/distributions/gradle-9.8.2-bin.zip`, SHA-256 `21bd5da2de49b642904077f38c0efdc7778e92b3ed0889392dca61b97e389607` | [versions/current](https://services.gradle.org/versions/current), [.sha256](https://downloads.gradle.org/distributions/gradle-9.8.2-bin.zip.sha256), [releases](https://gradle.org/releases/) |
| Gradle JVM and AGP support | "A JVM version between 17 and 27 is required to execute Gradle". Java 21 can run Gradle from 8.5. "Gradle is tested with Android Gradle Plugin 9.0 through 9.5.0-alpha02" | [compatibility](https://docs.gradle.org/current/userguide/compatibility.html) |
| Kotlin | **2.4.21**, a bug-fix release from 2026-10-08. 2.5.0 is planned for December 2026; 2.5.0-Beta2 is on Maven Central | [Kotlin releases](https://kotlinlang.org/docs/releases.html), [KGP metadata](https://repo1.maven.org/maven2/org/jetbrains/kotlin/kotlin-gradle-plugin/maven-metadata.xml) |
| Compose compiler plugin | `org.jetbrains.kotlin.plugin.compose` **2.4.21**. "this version matches your Kotlin version". The Plugin Portal's newest is 2.5.0-Beta2, a pre-release | [Compose compiler setup](https://developer.android.com/develop/ui/compose/compiler), [Plugin Portal](https://plugins.gradle.org/plugin/org.jetbrains.kotlin.plugin.compose) |
| AGP 9 built-in Kotlin | "you no longer have to apply the `org.jetbrains.kotlin.android` (or `kotlin-android`) plugin". AGP has a runtime dependency on KGP 2.2.10; AGP **9.4.1's POM still pins `kotlin-gradle-plugin` 2.2.10** | [AGP 9.0 notes](https://developer.android.com/build/releases/agp-9-0-0-release-notes), [migration guide](https://developer.android.com/build/migrate-to-built-in-kotlin), [AGP 9.4.1 POM](https://dl.google.com/dl/android/maven2/com/android/tools/build/gradle/9.4.1/gradle-9.4.1.pom) |
| How to get Kotlin 2.4.21 | The documented route is `buildscript { dependencies { classpath("org.jetbrains.kotlin:kotlin-gradle-plugin:KGP_VERSION") } }`. Also, `compose-compiler-gradle-plugin:2.4.21` has a compile dependency on `kotlin-gradle-plugin:2.4.21`. So declaring the Compose plugin at 2.4.21 in the root `plugins {}` lifts KGP from 2.2.10. R8 needs AGP 8.5.2+ (R8 9.1.29) for Kotlin 2.4 | [AGP 9.0 notes](https://developer.android.com/build/releases/agp-9-0-0-release-notes), [compose plugin POM](https://repo1.maven.org/maven2/org/jetbrains/kotlin/compose-compiler-gradle-plugin/2.4.21/compose-compiler-gradle-plugin-2.4.21.pom), [Kotlin/R8 table](https://developer.android.com/build/kotlin-support) |
| Proof the setup works in practice | Google's nowinandroid uses AGP 9.3.2 and Gradle 9.7.1. It has **no `kotlin-android`**, declares `org.jetbrains.kotlin.plugin.compose` and `...plugin.serialization` with `version.ref = "kotlin"`, and sets `buildFeatures.compose = true` | [libs.versions.toml](https://raw.githubusercontent.com/android/nowinandroid/main/gradle/libs.versions.toml), [wrapper props](https://raw.githubusercontent.com/android/nowinandroid/main/gradle/wrapper/gradle-wrapper.properties), [AndroidCompose.kt](https://raw.githubusercontent.com/android/nowinandroid/main/build-logic/convention/src/main/kotlin/com/google/samples/apps/nowinandroid/AndroidCompose.kt) |
| Android 17 / API 37 | The AOSP tag `android-17.0.0_r1` exists, and AOSP's "What's new in the Android 17 release" page was updated 2026-06-17. compileSdk 37 builds use `compileSdk = 37` | [AOSP what's new](https://source.android.com/docs/whatsnew/android-17-release), [Android 17 SDK setup](https://developer.android.com/about/versions/17/setup-sdk) |

### 1.2 Libraries

| Coordinates | Version | Notes and evidence |
|---|---|---|
| `androidx.compose:compose-bom` | **2026.09.00** | Maps to ui/foundation/runtime/animation/ui-test-* **1.12.1**, material3 **1.4.0**, material-icons-* 1.7.8 ([metadata](https://dl.google.com/dl/android/maven2/androidx/compose/compose-bom/maven-metadata.xml), [BOM POM](https://dl.google.com/dl/android/maven2/androidx/compose/compose-bom/2026.09.00/compose-bom-2026.09.00.pom)). Compose UI 1.12.1 is stable as of 2026-09-09 ([compose-ui](https://developer.android.com/jetpack/androidx/releases/compose-ui)) |
| ↳ fallback BOM | 2026.06.01 | Compose 1.11.4, material3 1.4.0 ([POM](https://dl.google.com/dl/android/maven2/androidx/compose/compose-bom/2026.06.01/compose-bom-2026.06.01.pom)) |
| `androidx.compose.material3:material3` | 1.4.0 (from the BOM) | 1.5.0-beta02 is the newest pre-release ([material3](https://developer.android.com/jetpack/androidx/releases/compose-material3)) |
| `androidx.glance:glance-appwidget`, `glance-material3`, `glance-testing`, `glance-appwidget-testing` | **1.2.0** (2026-08-26) | 1.3.0-alpha02 is the latest alpha. glance-appwidget uses compileSdk 35 and has defaulted to minSdk 23 since 1.2.0-beta01 ([Glance releases](https://developer.android.com/jetpack/androidx/releases/glance), metadata: [appwidget](https://dl.google.com/dl/android/maven2/androidx/glance/glance-appwidget/maven-metadata.xml), [testing](https://dl.google.com/dl/android/maven2/androidx/glance/glance-testing/maven-metadata.xml), [appwidget-testing](https://dl.google.com/dl/android/maven2/androidx/glance/glance-appwidget-testing/maven-metadata.xml), [material3](https://dl.google.com/dl/android/maven2/androidx/glance/glance-material3/maven-metadata.xml)) |
| `androidx.activity:activity-compose` | 1.13.0 (2026-03-11) | [activity](https://developer.android.com/jetpack/androidx/releases/activity) |
| `androidx.lifecycle:lifecycle-*` (runtime-compose, viewmodel-compose, service) | 2.11.0 (2026-06-17) | "Updates to the Compose `compileSdk` require a minimum AGP version of `9.2.0`" ([lifecycle](https://developer.android.com/jetpack/androidx/releases/lifecycle)) |
| `androidx.core:core` / `core-ktx` | 1.19.1 (2026-09-23) | Since 1.19.0-alpha01 `core-ktx` is an empty artifact; its APIs merged into `core` ([core](https://developer.android.com/jetpack/androidx/releases/core)) |
| `androidx.datastore:datastore`, `datastore-preferences`, `datastore-tink` | **1.3.0** (2026-10-21) | New `datastore-tink` with `AeadSerializer`. Its Android variant depends on `tink-android:1.20.0` ([DataStore](https://developer.android.com/jetpack/androidx/releases/datastore), [datastore-tink-android POM](https://dl.google.com/dl/android/maven2/androidx/datastore/datastore-tink-android/1.3.0/datastore-tink-android-1.3.0.pom)) |
| `androidx.work:work-runtime-ktx` | 2.12.0 (2026-09-23) | minSdk 24, compileSdk 33 or higher ([work](https://developer.android.com/jetpack/androidx/releases/work)). Glance 1.2.0 already pulls `work-runtime-ktx` 2.7.1 transitively ([POM](https://dl.google.com/dl/android/maven2/androidx/glance/glance-appwidget/1.2.0/glance-appwidget-1.2.0.pom)) |
| `org.jetbrains.kotlinx:kotlinx-coroutines-android` / `-test` | 1.11.0 | [core metadata](https://repo1.maven.org/maven2/org/jetbrains/kotlinx/kotlinx-coroutines-core/maven-metadata.xml), [test metadata](https://repo1.maven.org/maven2/org/jetbrains/kotlinx/kotlinx-coroutines-test/maven-metadata.xml) |
| `org.jetbrains.kotlinx:kotlinx-serialization-json` | 1.12.0 | "based on Kotlin 2.4.10" ([releases](https://github.com/Kotlin/kotlinx.serialization/releases)). The plugin `org.jetbrains.kotlin.plugin.serialization` takes the Kotlin version ([get started](https://kotlinlang.org/docs/serialization-get-started.html)) |
| `com.squareup.okhttp3:okhttp`, `mockwebserver3` | **5.5.1** (2026-10-22) | A security fix that blocks 8 compromised eMudhra intermediates. "works on Android 5.0+ (API level 21+) and Java 8+". The docs moved to https://lysine.dev/okhttp/ ([README](https://raw.githubusercontent.com/square/okhttp/master/README.md), [CHANGELOG](https://raw.githubusercontent.com/square/okhttp/master/CHANGELOG.md), [mockwebserver3 metadata](https://repo1.maven.org/maven2/com/squareup/okhttp3/mockwebserver3/maven-metadata.xml)) |
| `com.google.crypto.tink:tink-android` | **1.24.0** (2026-10-16) | The jar is **3,508,938 bytes** before R8. Dependencies: annotation-jvm, jsr305, gson, error_prone_annotations ([releases](https://github.com/tink-crypto/tink-java/releases), [Maven dir](https://repo1.maven.org/maven2/com/google/crypto/tink/tink-android/1.24.0/), [POM](https://repo1.maven.org/maven2/com/google/crypto/tink/tink-android/1.24.0/tink-android-1.24.0.pom)) |
| `androidx.security:security-crypto` | 1.1.0 (2025-07-30) | **"Deprecated all APIs in favour of existing platform APIs and direct use of Android Keystore."** ([security](https://developer.android.com/jetpack/androidx/releases/security)). The sentence sits under 1.1.0-alpha07 and 1.1.0-beta01; stable 1.1.0 ships that change (corrected by verification: same page) |
| `org.robolectric:robolectric` | **4.17** | "Robolectric 4.17 supports SDK 37". SDK 36 and 37 require Java 21 ([release](https://github.com/robolectric/robolectric/releases/tag/robolectric-4.17), [compatibility table](https://robolectric.org/compatibility_table/)) |
| `junit:junit` / `app.cash.turbine:turbine` | 4.13.2 / 1.2.1 | [junit metadata](https://repo1.maven.org/maven2/junit/junit/maven-metadata.xml), [turbine metadata](https://repo1.maven.org/maven2/app/cash/turbine/turbine/maven-metadata.xml) |
| androidx.test | core 1.7.0, ext.junit 1.3.0, runner/rules 1.7.0, espresso 3.7.0 | [test](https://developer.android.com/jetpack/androidx/releases/test) |
| `org.jlleitschuh.gradle.ktlint` | **14.2.0** (2026-03-12) | 14.1.0 added "support for Android projects with new DSL i.e. built-in Kotlin". The plugin's default ktlint is `set("1.5.0")` ([Plugin Portal](https://plugins.gradle.org/plugin/org.jlleitschuh.gradle.ktlint), [releases](https://github.com/JLLeitschuh/ktlint-gradle/releases), [KtlintExtension.kt](https://raw.githubusercontent.com/JLLeitschuh/ktlint-gradle/main/plugin/src/main/kotlin/org/jlleitschuh/gradle/ktlint/KtlintExtension.kt)) |
| ktlint | **1.8.0** | 2.0.0-ALPHA-4 is a pre-release ([ktlint-cli metadata](https://repo1.maven.org/maven2/com/pinterest/ktlint/ktlint-cli/maven-metadata.xml), [releases](https://github.com/pinterest/ktlint/releases)) |

### 1.3 The compileSdk gate: the build-breaking constraint

These values come from each AAR's `aar-metadata.properties`, read from the published files:

| Artifact | minCompileSdk | min AGP |
|---|---|---|
| `androidx.core:core` 1.19.1 | **37** | 9.1.0 |
| `androidx.core:core` 1.18.0 (and 1.17.0, which is in the local cache) | 36 | 8.9.1 |
| `androidx.compose.ui:ui-android` 1.12.1 (BOM 2026.09.00) | **37** | 9.1.0 |
| `androidx.compose.ui:ui-android` 1.11.4 (BOM 2026.06.01) | 35 | 8.6.0 |
| `androidx.compose.material3:material3-android` 1.4.0 | 35 | 8.6.0 |
| `androidx.lifecycle:lifecycle-runtime-compose-android` 2.11.0 | **37** | 9.1.0 |
| `androidx.lifecycle:lifecycle-runtime-compose-android` 2.10.0 | 35 | 8.6.0 |
| `lifecycle-runtime-android` / `lifecycle-service` 2.11.0 | 34 | 8.1.1 |
| `androidx.activity:activity-compose` 1.13.0 (depends on `core-ktx` 1.18.0) | 36 | 8.9.1 |
| `androidx.navigationevent:navigationevent-compose-android` 1.0.0 (transitive dependency of activity-compose) | 36 | 8.9.1 |
| `androidx.glance:glance-appwidget` 1.2.0 | 35 | 8.6.0 |
| `datastore-preferences-android` / `datastore-tink-android` 1.3.0 | 34 | 8.1.1 |

The docs agree: "starting with Compose 1.12.0, projects are required to use `compileSdk 37` and Android Gradle Plugin (AGP) 9" ([Compose compiler](https://developer.android.com/develop/ui/compose/compiler)). Compose UI 1.13.0-alpha03 already moves to compileSdk **37.1** ([compose-ui](https://developer.android.com/jetpack/androidx/releases/compose-ui)). work-runtime 2.12.0 pulls only core 1.12.0 and lifecycle 2.6.2 ([POM](https://dl.google.com/dl/android/maven2/androidx/work/work-runtime/2.12.0/work-runtime-2.12.0.pom)), so it is safe in both profiles below.

**Profile A (recommended, "target latest")**: `compileSdk = 37`, `targetSdk = 37`, with every library at its latest stable version. This needs `platforms;android-37`, which is **not installed**.
**Profile B (no SDK install)**: `compileSdk = 36`, `targetSdk = 36`. Pin BOM **2026.06.01**, core **1.18.0** and lifecycle **2.10.0**; everything else as in Profile A. All of those AARs have minCompileSdk 36 or lower (table above).

### 1.4 `gradle/libs.versions.toml` (Profile A; Profile B overrides in comments)

```toml
[versions]
agp = "9.4.1"
kotlin = "2.4.21"                 # drives the compose + serialization plugins (and so KGP)
composeBom = "2026.09.00"         # B: "2026.06.01"
coreKtx = "1.19.1"                # B: "1.18.0"
lifecycle = "2.11.0"              # B: "2.10.0"
activityCompose = "1.13.0"
glance = "1.2.0"
datastore = "1.3.0"
work = "2.12.0"
coroutines = "1.11.0"
serialization = "1.12.0"
okhttp = "5.5.1"
tink = "1.24.0"
junit4 = "4.13.2"
robolectric = "4.17"
turbine = "1.2.1"
androidxTestCore = "1.7.0"
androidxTestExtJunit = "1.3.0"
ktlintGradle = "14.2.0"

[libraries]
androidx-core-ktx = { module = "androidx.core:core-ktx", version.ref = "coreKtx" }
androidx-activity-compose = { module = "androidx.activity:activity-compose", version.ref = "activityCompose" }
androidx-lifecycle-runtime-compose = { module = "androidx.lifecycle:lifecycle-runtime-compose", version.ref = "lifecycle" }
androidx-lifecycle-viewmodel-compose = { module = "androidx.lifecycle:lifecycle-viewmodel-compose", version.ref = "lifecycle" }
androidx-lifecycle-service = { module = "androidx.lifecycle:lifecycle-service", version.ref = "lifecycle" }
compose-bom = { module = "androidx.compose:compose-bom", version.ref = "composeBom" }
compose-ui = { module = "androidx.compose.ui:ui" }
compose-ui-tooling-preview = { module = "androidx.compose.ui:ui-tooling-preview" }
compose-ui-tooling = { module = "androidx.compose.ui:ui-tooling" }
compose-material3 = { module = "androidx.compose.material3:material3" }
compose-ui-test-junit4 = { module = "androidx.compose.ui:ui-test-junit4" }
compose-ui-test-manifest = { module = "androidx.compose.ui:ui-test-manifest" }
glance-appwidget = { module = "androidx.glance:glance-appwidget", version.ref = "glance" }
glance-material3 = { module = "androidx.glance:glance-material3", version.ref = "glance" }
glance-testing = { module = "androidx.glance:glance-testing", version.ref = "glance" }
glance-appwidget-testing = { module = "androidx.glance:glance-appwidget-testing", version.ref = "glance" }
datastore = { module = "androidx.datastore:datastore", version.ref = "datastore" }
datastore-preferences = { module = "androidx.datastore:datastore-preferences", version.ref = "datastore" }
datastore-tink = { module = "androidx.datastore:datastore-tink", version.ref = "datastore" }
work-runtime-ktx = { module = "androidx.work:work-runtime-ktx", version.ref = "work" }
kotlinx-coroutines-android = { module = "org.jetbrains.kotlinx:kotlinx-coroutines-android", version.ref = "coroutines" }
kotlinx-coroutines-test = { module = "org.jetbrains.kotlinx:kotlinx-coroutines-test", version.ref = "coroutines" }
kotlinx-serialization-json = { module = "org.jetbrains.kotlinx:kotlinx-serialization-json", version.ref = "serialization" }
okhttp = { module = "com.squareup.okhttp3:okhttp", version.ref = "okhttp" }
okhttp-mockwebserver3 = { module = "com.squareup.okhttp3:mockwebserver3", version.ref = "okhttp" }
tink-android = { module = "com.google.crypto.tink:tink-android", version.ref = "tink" }
junit4 = { module = "junit:junit", version.ref = "junit4" }
robolectric = { module = "org.robolectric:robolectric", version.ref = "robolectric" }
turbine = { module = "app.cash.turbine:turbine", version.ref = "turbine" }
androidx-test-core = { module = "androidx.test:core", version.ref = "androidxTestCore" }
androidx-test-ext-junit = { module = "androidx.test.ext:junit", version.ref = "androidxTestExtJunit" }

[plugins]
android-application = { id = "com.android.application", version.ref = "agp" }
kotlin-compose = { id = "org.jetbrains.kotlin.plugin.compose", version.ref = "kotlin" }
kotlin-serialization = { id = "org.jetbrains.kotlin.plugin.serialization", version.ref = "kotlin" }
ktlint = { id = "org.jlleitschuh.gradle.ktlint", version.ref = "ktlintGradle" }
# NO kotlin-android entry: AGP 9 built-in Kotlin (migrate-to-built-in-kotlin, step 1)
```

### 1.5 Build scripts (minimal)

```kotlin
// settings.gradle.kts
pluginManagement { repositories { google(); mavenCentral(); gradlePluginPortal() } }
dependencyResolutionManagement {
    repositoriesMode = RepositoriesMode.FAIL_ON_PROJECT_REPOS   // docs.gradle.org centralizing_repositories
    repositories { google(); mavenCentral() }                    // mavenCentral also serves ktlint + Robolectric jars
}
rootProject.name = "EchoLedger"
include(":app")

// build.gradle.kts (root)
plugins {
    alias(libs.plugins.android.application) apply false
    alias(libs.plugins.kotlin.compose) apply false      // puts KGP 2.4.21 on the classpath
    alias(libs.plugins.kotlin.serialization) apply false
    alias(libs.plugins.ktlint) apply false
}

// app/build.gradle.kts
plugins {
    alias(libs.plugins.android.application)
    alias(libs.plugins.kotlin.compose)
    alias(libs.plugins.kotlin.serialization)
    alias(libs.plugins.ktlint)
}
android {
    namespace = "com.echoledger.mobile"
    compileSdk = 37                                   // Profile B: 36
    defaultConfig {
        applicationId = "com.echoledger.mobile"
        minSdk = 29                                   // requirements: min SDK 29+
        targetSdk = 37                                // Profile B: 36. Always set it (AGP 9 defaults it to compileSdk)
        versionCode = 1; versionName = "0.1.0"
    }
    compileOptions { sourceCompatibility = JavaVersion.VERSION_17; targetCompatibility = JavaVersion.VERSION_17 }
    buildFeatures { compose = true }
    testOptions {
        unitTests.isIncludeAndroidResources = true                     // robolectric.org/getting-started
        unitTests.all { it.jvmArgs("--add-opens=java.base/java.lang=ALL-UNNAMED", "--add-opens=java.base/java.util=ALL-UNNAMED",
            "--add-opens=java.base/java.io=ALL-UNNAMED", "--add-opens=java.base/java.net=ALL-UNNAMED",
            "--add-opens=java.base/java.security=ALL-UNNAMED", "--add-opens=java.base/java.text=ALL-UNNAMED",
            "--add-opens=java.base/jdk.internal.access=ALL-UNNAMED", "--add-opens=java.desktop/java.awt.font=ALL-UNNAMED",
            "--add-opens=jdk.compiler/com.sun.tools.javac.api=ALL-UNNAMED") }
    }
}
kotlin { compilerOptions { /* jvmTarget defaults to android.compileOptions.targetCompatibility */ } }
ktlint { version.set("1.8.0") }                       // plugin default is 1.5.0
dependencies {
    implementation(platform(libs.compose.bom)); testImplementation(platform(libs.compose.bom))
    implementation(libs.androidx.core.ktx); implementation(libs.androidx.activity.compose)
    implementation(libs.compose.ui); implementation(libs.compose.material3); implementation(libs.compose.ui.tooling.preview)
    debugImplementation(libs.compose.ui.tooling); debugImplementation(libs.compose.ui.test.manifest)
    implementation(libs.androidx.lifecycle.runtime.compose); implementation(libs.androidx.lifecycle.service)
    implementation(libs.glance.appwidget); implementation(libs.glance.material3)
    implementation(libs.datastore.preferences); implementation(libs.datastore); implementation(libs.datastore.tink)
    implementation(libs.tink.android); implementation(libs.work.runtime.ktx)
    implementation(libs.okhttp); implementation(libs.kotlinx.serialization.json); implementation(libs.kotlinx.coroutines.android)
    testImplementation(libs.junit4); testImplementation(libs.robolectric); testImplementation(libs.turbine)
    testImplementation(libs.kotlinx.coroutines.test); testImplementation(libs.okhttp.mockwebserver3)
    testImplementation(libs.androidx.test.core); testImplementation(libs.androidx.test.ext.junit)
    testImplementation(libs.compose.ui.test.junit4)
    testImplementation(libs.glance.testing); testImplementation(libs.glance.appwidget.testing)
}
```

AGP 9 default changes to keep in mind ([AGP 9.0 notes](https://developer.android.com/build/releases/agp-9-0-0-release-notes)):
- `android.newDsl` and `android.builtInKotlin` now default to `true`. `kotlin-kapt` is incompatible: use KSP, or `com.android.legacy-kapt` at the AGP version (that rule is stated in the migration guide, not the AGP 9.0 notes) (corrected by verification: https://developer.android.com/build/migrate-to-built-in-kotlin).
- `android.kotlinOptions{}` moves to `kotlin.compilerOptions{}`. `kotlin.sourceSets` is unsupported; use `android.sourceSets.named("main") { kotlin.directories += ... }` ([migration guide](https://developer.android.com/build/migrate-to-built-in-kotlin)).
- `android.onlyEnableUnitTestForTheTestedBuildType` is now `true`, so unit tests run for debug only.
- `android.r8.strictFullModeForKeepRules` is now `true`.
- `android.defaults.buildfeatures.resvalues` is now `false`.
- Opting out with `android.builtInKotlin=false` + `android.newDsl=false` disappears in AGP 10.

---

## 2. Bootstrapping without a global Gradle (Windows, PowerShell)

1. Use the JDK that ships with the SDK. Command-line Gradle uses `JAVA_HOME` if it is set, otherwise `java` on PATH ([JDK docs](https://developer.android.com/build/jdks)):
   `$env:JAVA_HOME = "$env:LOCALAPPDATA\Android\Sdk\jdk"; $env:Path = "$env:JAVA_HOME\bin;$env:Path"`
2. Generate the wrapper, which needs an installed Gradle ([wrapper docs](https://docs.gradle.org/current/userguide/gradle_wrapper.html)). Use either of these:
   - (a) The **already extracted** 9.3.1: `& "$env:USERPROFILE\.gradle\wrapper\dists\gradle-9.3.1-bin\23ovyewtku6u96viwx3xl3oks\gradle-9.3.1\bin\gradle.bat" :wrapper --gradle-version 9.8.2 --distribution-type bin --gradle-distribution-sha256-sum 21bd5da2de49b642904077f38c0efdc7778e92b3ed0889392dca61b97e389607`
   - (b) Download `https://services.gradle.org/distributions/gradle-9.8.2-bin.zip` (pattern `https://services.gradle.org/distributions/gradle-<X>-bin.zip`, checksum at `<url>.sha256`), extract it to a scratch folder and run its `bin\gradle.bat` the same way.

   **Run step 2 while `settings.gradle.kts` holds only `rootProject.name = ...`.** AGP 9.4 needs Gradle ≥ 9.6.0, so configuring a build that applies AGP 9.4.1 under 9.3.1 is expected to fail. Whether `:wrapper` works in a directory with no settings file at all is UNVERIFIED; the docs only say that `gradle init` generates the wrapper.
3. This generates `gradlew`, `gradlew.bat`, `gradle/wrapper/gradle-wrapper.jar` and `gradle/wrapper/gradle-wrapper.properties`. The last contains `distributionUrl=https\://services.gradle.org/distributions/gradle-9.8.2-bin.zip`, and `distributionSha256Sum` is checked only on first download ([wrapper docs](https://docs.gradle.org/current/userguide/gradle_wrapper.html)).
4. `mobile/local.properties` (do not commit it). `sdk.dir` is the "Path to the Android SDK" ([build overview](https://developer.android.com/build)). `Properties.load` **silently drops a backslash before a non-valid escape** ([Javadoc](https://docs.oracle.com/en/java/javase/21/docs/api/java.base/java/util/Properties.html)), so write either
   `sdk.dir=C:/Users/Admin/AppData/Local/Android/Sdk` or `sdk.dir=C\:\\Users\\Admin\\AppData\\Local\\Android\\Sdk`.
   `ANDROID_HOME` is the supported environment variable; `ANDROID_SDK_ROOT` is deprecated ([variables](https://developer.android.com/tools/variables)).
5. Profile A only: install API 37 with `& "$env:LOCALAPPDATA\Android\Sdk\cmdline-tools\latest\bin\sdkmanager.bat" "platforms;android-37"`. Package paths look like `"platforms;android-36"`; the page marks `sdkmanager` as deprecated in favour of the `android sdk` CLI ([sdkmanager](https://developer.android.com/tools/sdkmanager)).
   Alternatively, Gradle "can automatically download missing SDK packages ... as long as the corresponding SDK license agreements have already been accepted". The `licenses/` directory is present here; disable this with `android.builder.sdkDownload=false` ([update docs](https://developer.android.com/studio/intro/update)).
6. The first build needs network access to Google Maven and Maven Central. Robolectric also fetches its `android-all` jars from a repository you can override with `robolectric.dependency.repo.url` ([configuring](https://robolectric.org/configuring/)).

---

## 3. Microphone foreground service

### 3.1 Manifest and start sequence

Manifest declarations, per [service types](https://developer.android.com/develop/background-work/services/fgs/service-types) and [declare](https://developer.android.com/develop/background-work/services/fgs/declare):
- Type `microphone`.
- Permission `FOREGROUND_SERVICE_MICROPHONE`.
- Constant `FOREGROUND_SERVICE_TYPE_MICROPHONE`.
- Runtime prerequisite: `RECORD_AUDIO` granted.
- Multiple types are joined with `|`.
- A type missing from the manifest throws `MissingForegroundServiceTypeException`.

AOSP source adds two details ([ServiceInfo.java](https://android.googlesource.com/platform/frameworks/base/+/refs/heads/main/core/java/android/content/pm/ServiceInfo.java)):
- `FOREGROUND_SERVICE_TYPE_MICROPHONE = 1 << 7`.
- Apps targeting 34+ need `FOREGROUND_SERVICE_MICROPHONE` plus one of `CAPTURE_AUDIO_OUTPUT` or `RECORD_AUDIO`.

```xml
<uses-permission android:name="android.permission.RECORD_AUDIO" />
<uses-permission android:name="android.permission.FOREGROUND_SERVICE" />
<uses-permission android:name="android.permission.FOREGROUND_SERVICE_MICROPHONE" />
<uses-permission android:name="android.permission.POST_NOTIFICATIONS" />
<application ...>
  <service android:name=".capture.RecordingService"
      android:foregroundServiceType="microphone" android:exported="false" />
</application>
```

```kotlin
class RecordingService : LifecycleService() {            // androidx.lifecycle:lifecycle-service
    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        super.onStartCommand(intent, flags, startId)
        if (ContextCompat.checkSelfPermission(this, Manifest.permission.RECORD_AUDIO) != PackageManager.PERMISSION_GRANTED) {
            stopSelf(); return START_NOT_STICKY            // the widget/notification must route the user to the app instead
        }
        val n = NotificationCompat.Builder(this, CHANNEL_CAPTURE)   // channel created at app start (API 26+)
            .setSmallIcon(R.drawable.ic_rec).setContentTitle("EchoLedger is recording")
            .setOngoing(true).setCategory(NotificationCompat.CATEGORY_SERVICE)
            .setForegroundServiceBehavior(NotificationCompat.FOREGROUND_SERVICE_IMMEDIATE) // no 10 s deferral (Android 12+)
            .addAction(R.drawable.ic_stop, "Stop", stopIntent(this))
            .build()
        try {
            ServiceCompat.startForeground(this, NOTIF_ID, n, ServiceInfo.FOREGROUND_SERVICE_TYPE_MICROPHONE)
        } catch (e: SecurityException) {                    // while-in-use denied: started from background without exemption
            stopSelf(); return START_NOT_STICKY
        } catch (e: Exception) {
            if (Build.VERSION.SDK_INT >= 31 && e is ForegroundServiceStartNotAllowedException) { stopSelf(); return START_NOT_STICKY }
            throw e
        }
        // ...start capture
        return START_NOT_STICKY
    }
}
```

Sources for the API calls above:
- `ServiceCompat.startForeground(Service, int id, Notification, int type)` passes the type on API 29+, masked with `FOREGROUND_SERVICE_TYPE_ALLOWED_SINCE_Q` on 29–33 and `FOREGROUND_SERVICE_TYPE_ALLOWED_SINCE_U` on 34+ (`MICROPHONE` is in both masks); below 29 the type is ignored (corrected by verification: same ServiceCompat.java) ([ServiceCompat.java](https://raw.githubusercontent.com/androidx/androidx/androidx-main/core/core/src/main/java/androidx/core/app/ServiceCompat.java)).
- The `id` cannot be 0, and the docs' sample catches `ForegroundServiceStartNotAllowedException` ([launch](https://developer.android.com/develop/background-work/services/fgs/launch)).
- `FOREGROUND_SERVICE_IMMEDIATE` means "display of the notification will be immediate even if the default behavior would be to defer visibility for a short time". The `addAction(int, CharSequence, PendingIntent)`, `setOngoing` and `setForegroundServiceBehavior` builders are in [NotificationCompat.java](https://raw.githubusercontent.com/androidx/androidx/androidx-main/core/core/src/main/java/androidx/core/app/NotificationCompat.java).

`startForegroundService()` carries an "implicit promise" that the service calls `startForeground()` within "an amount of time comparable to the ANR interval". Otherwise the process crashes with `ForegroundServiceDidNotStartInTimeException` ([AOSP@main Context.java](https://android.googlesource.com/platform/frameworks/base/+/refs/heads/main/core/java/android/content/Context.java)). AOSP's default is `DEFAULT_SERVICE_START_FOREGROUND_TIMEOUT_MS = 30 * 1000` plus a 10 s ANR delay ([ActivityManagerConstants.java](https://android.googlesource.com/platform/frameworks/base/+/refs/heads/main/services/core/java/com/android/server/am/ActivityManagerConstants.java)). Call `startForeground()` first and do the heavy work after.

### 3.2 Background-start rules, quoted ([restrictions-bg-start](https://developer.android.com/develop/background-work/services/fgs/restrictions-bg-start))

> "In addition, if an app wants to launch a foreground service that needs _while-in-use_ permissions (for example, body sensor, camera, microphone, or location permissions), it cannot _create_ the service while the app is in the background, even if the app falls into one of the exemptions from background start restrictions."

**General exemptions** (apps targeting 31+). "In the following situations, your app can start foreground services even while your app runs in the background:" (link targets removed, inline notes condensed):
> - Your app transitions from a user-visible state, such as an activity.
> - Your app can start an activity from the background, except for the case where the app has an activity in the back stack of an existing task.
> - Your app receives a high priority message using Firebase Cloud Messaging. *(note: priority may be downgraded)*
> - The user performs an action on a UI element related to your app. For example, they might interact with a bubble, notification, widget, or activity.
> - Your app invokes an exact alarm to complete an action that the user requests.
> - Your app is the device's current input method.
> - Your app receives an event that's related to geofencing or activity recognition transition.
> - After the device reboots and receives the `ACTION_BOOT_COMPLETED`, `ACTION_LOCKED_BOOT_COMPLETED`, or `ACTION_MY_PACKAGE_REPLACED` intent action in a broadcast receiver. *(note: type restrictions for target 14+)*
> - Your app receives the `ACTION_TIMEZONE_CHANGED`, `ACTION_TIME_CHANGED`, or `ACTION_LOCALE_CHANGED` intent action in a broadcast receiver.
> - Your app receives the `ACTION_TRANSACTION_DETECTED` event from `NfcService`.
> - Apps with certain system roles or permission, such as device owners and profile owners.
> - Your app uses the Companion Device Manager and declares the `REQUEST_COMPANION_START_FOREGROUND_SERVICES_FROM_BACKGROUND` permission or the `REQUEST_COMPANION_RUN_IN_BACKGROUND` permission. ...
> - The user turns off battery optimizations for your app.
> - Your app holds the `SYSTEM_ALERT_WINDOW` permission. Note: If your app targets Android 15 or higher, it must have the `SYSTEM_ALERT_WINDOW` permission _and_ the app must currently have a visible overlay window.

**While-in-use exemptions.** "The following list contains these situations:"
> - A system component starts the service.
> - The service starts by interacting with app widgets.
> - The service starts by interacting with a notification.
> - The service starts as a `PendingIntent` that is sent from a different, visible app.
> - The service starts by an app that is a device policy controller that runs in device owner mode.
> - The service starts by an app which provides the `VoiceInteractionService`.
> - The service starts by an app that has the `START_ACTIVITIES_FROM_BACKGROUND` privileged permission.

There is also a trap: `checkSelfPermission(RECORD_AUDIO)` "returns `PERMISSION_GRANTED` even if the app is in the background". Even so, creating a `microphone` FGS from the background "throws a `SecurityException`" on 14+. On older versions the service starts but has no microphone access. You "must call `Context.startForegroundService()` or `Context.bindService()` while your app has a visible activity, unless the service falls into one of the defined exemptions." To detect it, look for the Logcat line `Foreground service started from background can not have location/camera/microphone access`.

### 3.3 Verdicts on planning assumptions

| Planning assumption | Verdict | Evidence |
|---|---|---|
| A while-in-use (mic) FGS cannot be created from the background even with a general exemption | **CONFIRMED** (verbatim sentence above) | restrictions-bg-start |
| Notification actions work | **CONFIRMED**: "starts by interacting with a notification" | WIU list |
| Widgets work | **CONFIRMED**: "starts by interacting with app widgets" | WIU list |
| A PendingIntent from a visible app works | **CONFIRMED with nuance**: the PendingIntent must come from a **different**, visible app, such as the launcher sending a widget's PendingIntent. Your own visible activity is simply the normal foreground case | WIU list |
| A visible SAW overlay is NOT guaranteed for the mic | **CONFIRMED (docs)**: SAW plus a visible overlay is only a *general* start exemption, not a WIU one. **AOSP nuance (§3.4)**: AOSP 16/17 treat a visible `TYPE_APPLICATION_OVERLAY` as "UID visible", which grants WIU. This is undocumented, so verify on the device | lists above, AOSP |
| A QS tile is NOT guaranteed | **CONFIRMED, version-dependent (§3.4)**: in AOSP@17r1 a tile click grants WIU only behind the `fgs_wiu_qs_tile_clicked` flag. That flag is ENABLED in release config `cp2a` and `trunk_staging`, but has no value file in `cp1a`. Use a trampoline activity | AOSP |

### 3.4 What the platform actually checks (AOSP source, read at release tags)

`ActiveServices.shouldAllowFgsWhileInUsePermissionLocked()` grants WIU when any of these hold ([AOSP@17r1 ActiveServices.java](https://android.googlesource.com/platform/frameworks/base/+/refs/tags/android-17.0.0_r1/services/core/java/com/android/server/am/ActiveServices.java); the same logic exists at `android-16.0.0_r1`):
1. **Tile clicks** (17r1 only): `if (com.android.server.am.Flags.fgsWiuQsTileClicked())`, the caller is on the FGS temp-allowlist with `mReasonCode == REASON_TILE_ONCLICK`. The flag is defined in [am/flags.aconfig@17r1](https://android.googlesource.com/platform/frameworks/base/+/refs/tags/android-17.0.0_r1/services/core/java/com/android/server/am/flags.aconfig): `name: "fgs_wiu_qs_tile_clicked"`, "Whether to allow while-in-use permissions in FGS when QS tile is clicked", `PURPOSE_BUGFIX`.
   - Its value is `state: ENABLED, permission: READ_ONLY` in [build/release@17r1 aconfig/cp2a](https://android.googlesource.com/platform/build/release/+/refs/tags/android-17.0.0_r1/aconfig/cp2a/com.android.server.am/fgs_wiu_qs_tile_clicked_flag_values.textproto), and ENABLED in `trunk_staging`. There is no file in `cp1a`, `bp*` or `ap*`. `cp1a` inherits `bp4a`, and `cp2a` inherits `mainline_2026_04` + `cp1a`, so only `cp2a` (and `trunk_staging`) turn the flag on (added by verification: https://android.googlesource.com/platform/build/release/+/refs/tags/android-17.0.0_r1/release_configs/).
   - Which public release `cp1a` and `cp2a` correspond to is **UNVERIFIED**.
   - SystemUI temp-allowlists the tile's app on click: `addPowerSaveTempWhitelistApp(packageName, mTempAllowFgsLaunchDuration, ..., REASON_TILE_ONCLICK, "tile onclick")`, default `15_000` ms ([TileLifecycleManager@17r1](https://android.googlesource.com/platform/frameworks/base/+/refs/tags/android-17.0.0_r1/packages/SystemUI/src/com/android/systemui/qs/external/TileLifecycleManager.java)).
2. The caller's process state is `<= PROCESS_STATE_TOP`.
3. `mAm.mAtmInternal.isUidForeground(callingUid)` gives `REASON_UID_VISIBLE`.
   - `isUidForeground` calls `hasActiveVisibleWindow(uid)`: "A uid is considered to be foreground if it has a visible non-toast window". That is either a visible activity or `mActiveUids.hasNonAppVisibleWindow(uid)` ([ATMS@17r1](https://android.googlesource.com/platform/frameworks/base/+/refs/tags/android-17.0.0_r1/services/core/java/com/android/server/wm/ActivityTaskManagerService.java)).
   - Non-app windows are counted when `mAttrs.type >= FIRST_SYSTEM_WINDOW && != TYPE_TOAST && != TYPE_PRIVATE_PRESENTATION`, and the window is not a `TYPE_PRESENTATION` on a virtual display (corrected by verification: WindowState.java @ android-17.0.0_r1, ?format=TEXT) ([WindowState@17r1](https://android.googlesource.com/platform/frameworks/base/+/refs/tags/android-17.0.0_r1/services/core/java/com/android/server/wm/WindowState.java)).
   - `TYPE_APPLICATION_OVERLAY = FIRST_SYSTEM_WINDOW + 38` with `FIRST_SYSTEM_WINDOW = 2000` ([WindowManager.java](https://android.googlesource.com/platform/frameworks/base/+/refs/heads/main/core/java/android/view/WindowManager.java)). **So a visible overlay counts as visible on AOSP.**
4. The start carries background-activity-start privileges: `backgroundStartPrivileges.allowsBackgroundActivityStarts()` gives `REASON_START_ACTIVITY_FLAG`. My reading is that these privileges arrive with PendingIntents sent by visible senders or the system, but I did not trace the call sites (**interpretation**).
5. The caller is a system UID, or WindowManager allows background FGS starts (the "10 seconds grace period" after an activity starts or finishes), or the UID is on the `mFgsWhileInUseTempAllowList` (MediaSession buttons, Telephony/VoIP), and so on.
6. Bindings: the app is "bound by a TOP app" or "bound by a persistent process with BIND_ALLOW_BACKGROUND_ACTIVITY_STARTS". For a tile the client is SystemUI, which adds that flag **only when** `START_ACTIVITY_NEEDS_PENDING_INTENT` is *not* enabled. That change is `@EnabledSince(targetSdkVersion = UPSIDE_DOWN_CAKE)` ([TileService.java](https://android.googlesource.com/platform/frameworks/base/+/refs/heads/main/core/java/android/service/quicksettings/TileService.java)). So apps targeting 34+ get no WIU through the tile binding.

Caveats:
- OEM builds such as Samsung One UI may differ.
- **AOSP@main lags the release tags.** Main's `ActiveServices` lacks the tile flag code, and main's `core/res/AndroidManifest.xml` has no `ACCESS_LOCAL_NETWORK`. Read platform code at release tags.

### 3.5 Other FGS rules that matter here

- **Boot**: a `BOOT_COMPLETED` receiver cannot launch a `microphone` FGS ("this restriction has been in place for `microphone` since Android 14"). Since Android 15 the same applies to `dataSync`, `camera`, `mediaPlayback`, `phoneCall` and `mediaProjection` ([Android 15 changes](https://developer.android.com/about/versions/15/behavior-changes-15)).
- **Timeouts**: for target 35+, `dataSync` and `mediaProcessing` FGS get **6 h per 24 h** and must handle `Service.onTimeout(int, int)` ([same page](https://developer.android.com/about/versions/15/behavior-changes-15)). Do not put uploads in a dataSync FGS.
- **Notification permission**: on Android 13+, if POST_NOTIFICATIONS is denied, users "still see notices related to foreground services in the Task Manager but don't see them in the notification drawer" ([notification permission](https://developer.android.com/develop/ui/views/notifications/notification-permission)). That **removes the notification-action entry point**.
- **Dismissable notifications**: from Android 14, `setOngoing(true)` notifications are user-dismissable, except when the phone is locked or on Clear all, and except CallStyle and media notifications ([14 all-apps changes](https://developer.android.com/about/versions/14/behavior-changes-all)).
- **Task Manager Stop**: on Android 13+, Stop kills the **whole app** with no callback. Detect it on the next start with `ApplicationExitInfo.REASON_USER_REQUESTED` ([handle-user-stopping](https://developer.android.com/develop/background-work/services/fgs/handle-user-stopping)). The API is `ActivityManager.getHistoricalProcessExitReasons(String packageName, int pid, int maxNum)` with `REASON_USER_REQUESTED = 10` ([ActivityManager.java](https://android.googlesource.com/platform/frameworks/base/+/refs/heads/main/core/java/android/app/ActivityManager.java), [ApplicationExitInfo.java](https://android.googlesource.com/platform/frameworks/base/+/refs/heads/main/core/java/android/app/ApplicationExitInfo.java)).
- **Android 17 (target 37) background audio hardening**: a backgrounded app that interacts with audio "must have a foreground service running" with "while-in-use (WIU) capabilities" ([17 changes](https://developer.android.com/about/versions/17/behavior-changes-17)).

---

## 4. Notification action and Glance widget

### 4.1 Notification action → FGS

```kotlin
fun startRecordingAction(ctx: Context): PendingIntent =
    PendingIntentCompat.getForegroundService(                  // @RequiresApi(26); adds the mutability flag for you
        ctx, REQ_START, Intent(ctx, RecordingService::class.java).setAction(ACTION_START),
        PendingIntent.FLAG_UPDATE_CURRENT, /* isMutable = */ false)
// NotificationCompat.Builder(...).addAction(R.drawable.ic_mic, "Record", startRecordingAction(ctx))
```

- `PendingIntent.getForegroundService(Context, int, @NonNull Intent, @Flags int)` "will start a foreground service, like calling Context.startForegroundService()" ([PendingIntent.java](https://android.googlesource.com/platform/frameworks/base/+/refs/heads/main/core/java/android/app/PendingIntent.java)).
- `PendingIntentCompat.getForegroundService(Context, int, Intent, int flags, boolean isMutable)` ([PendingIntentCompat.java](https://raw.githubusercontent.com/androidx/androidx/androidx-main/core/core/src/main/java/androidx/core/app/PendingIntentCompat.java)).
- On target 34+, a *mutable* PendingIntent with an implicit Intent throws ([14 changes](https://developer.android.com/about/versions/14/behavior-changes-14)). Keep the Intent explicit and immutable.

### 4.2 Glance widget

The setup below follows [create-app-widget](https://developer.android.com/develop/ui/compose/glance/create-app-widget):

```xml
<receiver android:name=".widget.EchoWidgetReceiver" android:exported="true">
  <intent-filter><action android:name="android.appwidget.action.APPWIDGET_UPDATE" /></intent-filter>
  <meta-data android:name="android.appwidget.provider" android:resource="@xml/echo_widget_info" />
</receiver>
<!-- res/xml/echo_widget_info.xml -->
<appwidget-provider xmlns:android="http://schemas.android.com/apk/res/android"
    android:minWidth="110dp" android:minHeight="40dp" android:targetCellWidth="2" android:targetCellHeight="1"
    android:resizeMode="horizontal|vertical" android:widgetCategory="home_screen"
    android:updatePeriodMillis="0" android:initialLayout="@layout/glance_default_loading_layout"
    android:description="@string/widget_desc" />
```

```kotlin
class EchoWidgetReceiver : GlanceAppWidgetReceiver() { override val glanceAppWidget: GlanceAppWidget = EchoWidget() }

class EchoWidget : GlanceAppWidget() {
    override val sizeMode = SizeMode.Responsive(setOf(DpSize(110.dp, 40.dp), DpSize(250.dp, 110.dp)))
    // stateDefinition defaults to PreferencesGlanceStateDefinition
    override suspend fun provideGlance(context: Context, id: GlanceId) {
        val last = withContext(Dispatchers.IO) { LibraryRepo.lastTranscriptSnippet(context) }  // runs on main: offload I/O
        provideContent { GlanceTheme { EchoWidgetContent(currentState<Preferences>(), last) } }
    }
}

val KEY_RECORDING = booleanPreferencesKey("recording")        // datastore-preferences-core PreferencesKeys.kt

@Composable fun EchoWidgetContent(prefs: Preferences, last: String?) {
    val recording = prefs[KEY_RECORDING] ?: false
    Row(modifier = GlanceModifier.padding(8.dp)) {               // add a background/theme once tokens are confirmed
        // NOT inside LazyColumn: lazy items use a trampoline (see below)
        Button(text = if (recording) "Stop" else "Record",
            onClick = actionStartService<RecordingService>(isForegroundService = true))
        Text(last ?: "No transcripts yet", modifier = GlanceModifier.semantics { testTag = "last" })
    }
}
// From the app/service when state changes:
//   updateAppWidgetState(ctx, glanceId) { it[KEY_RECORDING] = true }; EchoWidget().update(ctx, glanceId)
//   or EchoWidget().updateAll(ctx)
```

Signatures, from [GlanceAppWidget.kt](https://raw.githubusercontent.com/androidx/androidx/androidx-main/glance/glance-appwidget/src/main/java/androidx/glance/appwidget/GlanceAppWidget.kt) and [GlanceAppWidgetState.kt](https://raw.githubusercontent.com/androidx/androidx/androidx-main/glance/glance-appwidget/src/main/java/androidx/glance/appwidget/state/GlanceAppWidgetState.kt):
- `abstract suspend fun provideGlance(context, id: GlanceId)`
- `open val sizeMode: SizeMode = SizeMode.Single`
- `open val stateDefinition: GlanceStateDefinition<*>? = PreferencesGlanceStateDefinition`
- `suspend fun GlanceAppWidget.provideContent(content: @Composable @GlanceComposable () -> Unit): Nothing`
- `suspend fun update(context, id)`, `suspend fun GlanceAppWidget.updateAll(context)` and `updateIf<State>(context, predicate)`
- `open fun onCompositionError(context, glanceId, appWidgetId, throwable)`
- `suspend fun updateAppWidgetState(context, glanceId, updateState: suspend (MutablePreferences) -> Unit)`

`GlanceTheme`, `GlanceTheme.colors` and `androidx.glance.material3.ColorProviders(light, dark)` are documented on [Glance theme](https://developer.android.com/develop/ui/compose/glance/theme). The page shows only the `secondary` colour token, so any other token name (for example a widget-background token) is **UNVERIFIED** until it compiles. `@Composable inline fun <reified T> currentState(): T = LocalState.current as T`, and `LocalSize` / `LocalContext` / `LocalGlanceId` are in [CompositionLocals.kt](https://raw.githubusercontent.com/androidx/androidx/androidx-main/glance/glance/src/main/java/androidx/glance/CompositionLocals.kt).

Key behaviour:
- **`actionStartService(isForegroundService = true)`** maps to `ApplyActionApi26Impl.getForegroundServicePendingIntent`, which calls `PendingIntent.getForegroundService(..., FLAG_IMMUTABLE or FLAG_UPDATE_CURRENT)`. The *launcher* sends it on click. In **lazy lists**, Glance instead uses fill-in intents with `applyTrampolineIntent(... ActionTrampolineType.FOREGROUND_SERVICE)` ([ApplyAction.kt](https://raw.githubusercontent.com/androidx/androidx/androidx-main/glance/glance-appwidget/src/main/java/androidx/glance/appwidget/action/ApplyAction.kt)).
  - Overloads: `actionStartService<T : Service>(isForegroundService: Boolean = false)`, plus `(Intent, ...)`, `(ComponentName, ...)` and `(Class<T>, ...)` ([StartServiceAction.kt](https://raw.githubusercontent.com/androidx/androidx/androidx-main/glance/glance-appwidget/src/main/java/androidx/glance/appwidget/action/StartServiceAction.kt)).
- **Lambda clicks** "run in the context of a `WorkManager` worker", and **`actionRunCallback<T : ActionCallback>()`** runs via "a custom async `BroadcastReceiver`" ([user interaction](https://developer.android.com/develop/ui/compose/glance/user-interaction)). Use these for refresh and toggles, **not** for starting the mic FGS. That start would then come from your own background code, not from the widget PendingIntent.
- Calling `updateAppWidgetState` does not recompose; call `update()` or `updateAll()`. `provideGlance` "runs on the main thread". "Receivers have a 10-second background limit". With Responsive or Exact modes, decode bitmaps once in `provideGlance` ([create-app-widget](https://developer.android.com/develop/ui/compose/glance/create-app-widget)).
- Periodic updates: `updatePeriodMillis` updates "up to once every 30 minutes" ([manage](https://developer.android.com/develop/ui/compose/glance/glance-app-widget)). Push updates from the app instead.
- `SizeMode.Responsive` calls the content once per declared `DpSize`, and `LocalSize.current` gives the bucket ([build UI](https://developer.android.com/develop/ui/compose/glance/build-ui)).
- **Target 37**: a `RemoteViews` update over `6 * width * height` bytes of bitmap memory throws `IllegalArgumentException` ([17 changes](https://developer.android.com/about/versions/17/behavior-changes-17)). Keep the widget text and vector based.

### 4.3 Glance unit tests (JVM, no device)

Dependencies: `testImplementation` of `androidx.glance:glance-testing` and `glance-appwidget-testing` (docs show 1.1.1; 1.2.0 is published). Add Robolectric only "if your test needs to set a LocalContext" ([Glance testing](https://developer.android.com/develop/ui/compose/glance/testing)).

```kotlin
@Test fun recordButtonStartsForegroundService() = runGlanceAppWidgetUnitTest {
    setAppWidgetSize(DpSize(250.dp, 110.dp))
    setState(preferencesOf(KEY_RECORDING to false))
    provideComposable { EchoWidgetContent(currentState<Preferences>(), last = "hello") }
    onNode(hasStartServiceAction<RecordingService>(isForegroundService = true)).assertExists()
    onNode(hasTestTag("last")).assertHasText("hello")
}
```

API, from [GlanceAppWidgetUnitTest.kt](https://raw.githubusercontent.com/androidx/androidx/androidx-main/glance/glance-appwidget-testing/src/main/java/androidx/glance/appwidget/testing/unit/GlanceAppWidgetUnitTest.kt), [UnitTestFilters.kt](https://raw.githubusercontent.com/androidx/androidx/androidx-main/glance/glance-appwidget-testing/src/main/java/androidx/glance/appwidget/testing/unit/UnitTestFilters.kt) and [UnitTestAssertionExtensions.kt](https://raw.githubusercontent.com/androidx/androidx/androidx-main/glance/glance-appwidget-testing/src/main/java/androidx/glance/appwidget/testing/unit/UnitTestAssertionExtensions.kt):
- Entry point: `runGlanceAppWidgetUnitTest(timeout: Duration = DEFAULT_TIMEOUT, block: GlanceAppWidgetUnitTest.() -> Unit): TestResult`.
- Setup: `setAppWidgetSize(DpSize)`, `setState(state)`, `setContext(Context)`, `provideComposable { }`, `awaitIdle()`.
- Matchers: `hasStartServiceAction<T>(isForegroundService)`, `hasRunCallbackClickAction<T>()`, `hasStartActivityClickAction(intent, ...)`, `hasSendBroadcastAction(...)`.
- Assertions: `assertHasStartServiceClickAction<T>(isForegroundService)` and similar. These are listed in `api/1.2.0-beta01.txt`.
- Defaults: size 349.dp × 455.dp, timeout 1 s. "The test doesn't render the composables under test, so it doesn't let you perform clicks."
- Other APIs used: `GlanceNodeAssertion.assertExists()` / `assertDoesNotExist()` ([GlanceNodeAssertion.kt](https://raw.githubusercontent.com/androidx/androidx/androidx-main/glance/glance-testing/src/main/java/androidx/glance/testing/GlanceNodeAssertion.kt)) and `preferencesOf(vararg pairs: Preferences.Pair<*>)` ([PreferencesFactory.kt](https://raw.githubusercontent.com/androidx/androidx/androidx-main/datastore/datastore-preferences-core/src/commonMain/kotlin/androidx/datastore/preferences/core/PreferencesFactory.kt)).
- The `key to value` infix that builds a `Preferences.Pair` was not opened during research (**UNVERIFIED**, low risk).

---

## 5. Quick Settings tile

```xml
<service android:name=".tile.RecordTileService" android:exported="true"
    android:label="@string/tile_label" android:icon="@drawable/ic_tile_mic"
    android:permission="android.permission.BIND_QUICK_SETTINGS_TILE">
  <intent-filter><action android:name="android.service.quicksettings.action.QS_TILE" /></intent-filter>
  <meta-data android:name="android.service.quicksettings.TOGGLEABLE_TILE" android:value="true" />
</service>
```

```kotlin
class RecordTileService : TileService() {
    override fun onStartListening() { super.onStartListening()
        qsTile.state = if (RecordingState.isActive) Tile.STATE_ACTIVE else Tile.STATE_INACTIVE; qsTile.updateTile() }
    override fun onClick() { super.onClick()
        val i = Intent(this, RecordTrampolineActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
        val pi = PendingIntent.getActivity(this, 0, i, PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT)
        startActivityAndCollapse(pi)   // visible activity -> startForegroundService() there -> finish()
    }
}
```

Facts:
- The manifest form and `android:label` (up to 18 characters) come from the [QS tiles guide](https://developer.android.com/develop/ui/views/quicksettings-tiles). So do the 24×24 dp white vector icon, `ACTIVE_TILE`/`TOGGLEABLE_TILE` meta-data, `onStartListening`/`onClick`/`onStopListening`, `updateTile()`, `isLocked()`/`isSecure()`/`unlockAndRun(Runnable)`, and the rule that from API 28 the PendingIntent "must have the `Intent.FLAG_ACTIVITY_NEW_TASK`".
- `startActivityAndCollapse(Intent)` is deprecated, and for target 34+ it "now throws an exception". Use `startActivityAndCollapse(PendingIntent)` ([14 changes](https://developer.android.com/about/versions/14/behavior-changes-14)). In source this is `UnsupportedOperationException` behind `START_ACTIVITY_NEEDS_PENDING_INTENT = 241766793L` ([TileService.java](https://android.googlesource.com/platform/frameworks/base/+/refs/heads/main/core/java/android/service/quicksettings/TileService.java)).
- Constants: `ACTION_QS_TILE = "android.service.quicksettings.action.QS_TILE"`, `META_DATA_ACTIVE_TILE = "android.service.quicksettings.ACTIVE_TILE"`, `META_DATA_TOGGLEABLE_TILE = "android.service.quicksettings.TOGGLEABLE_TILE"`, `ACTION_QS_TILE_PREFERENCES` (same file).
- **Mic FGS from a tile**: the docs say nothing. AOSP (§3.4) grants a 15 s FGS-*start* allowlist on click. WIU comes only on builds with `fgs_wiu_qs_tile_clicked` enabled. Hence the trampoline. Make it a translucent, `excludeFromRecents`, no-animation activity that calls `ContextCompat.startForegroundService(...)` in `onCreate` and then `finish()`s. `ContextCompat.startForegroundService(Context, Intent)` is in [ContextCompat.java](https://raw.githubusercontent.com/androidx/androidx/androidx-main/core/core/src/main/java/androidx/core/content/ContextCompat.java). **Verify on the device.**

---

## 6. Floating caption bubble (SYSTEM_ALERT_WINDOW)

Permission flow:
- `Settings.canDrawOverlays(Context)`. To prompt, send `Settings.ACTION_MANAGE_OVERLAY_PERMISSION`; the docs note "a matching Activity may not exist", so guard the launch ([AOSP Settings.java](https://android.googlesource.com/platform/frameworks/base/+/refs/heads/main/core/java/android/provider/Settings.java)).
- From Android 11 this intent "always bring[s] the user to the top-level Settings screen ... Any `package:` data in the intent is ignored" ([11 permissions](https://developer.android.com/about/versions/11/privacy/permissions)). Show an explainer first.

Window type and flags ([WindowManager.java](https://android.googlesource.com/platform/frameworks/base/+/refs/heads/main/core/java/android/view/WindowManager.java)):
- `TYPE_APPLICATION_OVERLAY` sits above activities but "below critical system windows like the status bar or IME". The "system may change the position, size, or visibility". The system will "adjust the importance of processes with this window type to reduce the chance of the low-memory-killer killing them".
- `FLAG_NOT_FOCUSABLE` (0x8) means the window never gets key focus and "will also enable FLAG_NOT_TOUCH_MODAL". `FLAG_NOT_TOUCH_MODAL` (0x20) lets "pointer events outside of the window ... be sent to the windows behind it".
- `FLAG_NOT_TOUCHABLE` (0x10): since Android 12, touches pass through a `TYPE_APPLICATION_OVERLAY` only if its `alpha` is **≤ 0.8**, the maximum obscuring opacity (or it is invisible, fully transparent, or same-UID). Otherwise "the touch will not be delivered".
- Constructor: `LayoutParams(int w, int h, int _type, int _flags, int _format)`.
- Apps targeting 26+ must use `TYPE_APPLICATION_OVERLAY`, and users can block these windows from the notification shade ([8.0 changes](https://developer.android.com/about/versions/oreo/android-8.0-changes)).

Android 15 rule: to use the SAW FGS-start exemption, "the app needs to first launch a `TYPE_APPLICATION_OVERLAY` window _and_ the window needs to be visible". Check with `View.getWindowVisibility()` or `onWindowVisibilityChanged()`; test with `adb shell am compat enable FGS_SAW_RESTRICTIONS <pkg>` ([15 changes](https://developer.android.com/about/versions/15/behavior-changes-15)).

Hosting a ComposeView from a service:

```kotlin
class OverlayOwner : LifecycleOwner, SavedStateRegistryOwner {        // own lifecycle: see gotcha below
    private val registry = LifecycleRegistry(this)                     // must be declared first
    private val controller = SavedStateRegistryController.create(this)
    override val lifecycle: Lifecycle get() = registry
    override val savedStateRegistry: SavedStateRegistry get() = controller.savedStateRegistry
    init { controller.performRestore(null) }                           // attaches while INITIALIZED
    fun show() { registry.currentState = Lifecycle.State.RESUMED }
    fun destroy() { registry.currentState = Lifecycle.State.DESTROYED }
}

fun RecordingService.showBubble(): ComposeView {
    val owner = OverlayOwner()
    val view = ComposeView(this).apply {
        setViewTreeLifecycleOwner(owner); setViewTreeSavedStateRegistryOwner(owner)
        setViewCompositionStrategy(ViewCompositionStrategy.DisposeOnDetachedFromWindow)
        setContent { CaptionBubble(captions) }
    }
    val lp = WindowManager.LayoutParams(WindowManager.LayoutParams.WRAP_CONTENT, WindowManager.LayoutParams.WRAP_CONTENT,
        WindowManager.LayoutParams.TYPE_APPLICATION_OVERLAY,
        WindowManager.LayoutParams.FLAG_NOT_FOCUSABLE or WindowManager.LayoutParams.FLAG_NOT_TOUCH_MODAL,
        PixelFormat.TRANSLUCENT).apply { gravity = Gravity.TOP or Gravity.END }
    owner.show(); getSystemService(WindowManager::class.java).addView(view, lp)
    return view            // later: removeView(view); owner.destroy()
}
```

Sources for the code above:
- Without an owner, Compose throws `"ViewTreeLifecycleOwner not found from $this"`. The window recomposer resumes on `ON_START`, pauses on `ON_STOP` and cancels on `ON_DESTROY` ([WindowRecomposer.android.kt](https://raw.githubusercontent.com/androidx/androidx/androidx-main/compose/ui/ui/src/androidMain/kotlin/androidx/compose/ui/platform/WindowRecomposer.android.kt)).
- `@JvmName("set") fun View.setViewTreeLifecycleOwner(LifecycleOwner?)` is in [ViewTreeLifecycleOwner.android.kt](https://raw.githubusercontent.com/androidx/androidx/androidx-main/lifecycle/lifecycle-runtime/src/androidMain/kotlin/androidx/lifecycle/ViewTreeLifecycleOwner.android.kt). `fun View.setViewTreeSavedStateRegistryOwner(SavedStateRegistryOwner?)` is in [ViewTreeSavedStateRegistryOwner.android.kt](https://raw.githubusercontent.com/androidx/androidx/androidx-main/savedstate/savedstate/src/androidMain/kotlin/androidx/savedstate/ViewTreeSavedStateRegistryOwner.android.kt).
- `performAttach()` runs `check(owner.lifecycle.currentState == INITIALIZED) { "Restarter must be created only during owner's initialization stage" }`, and `performRestore` refuses once the lifecycle is `STARTED` ([SavedStateRegistryControllerImpl.kt](https://raw.githubusercontent.com/androidx/androidx/androidx-main/savedstate/savedstate/src/commonMain/kotlin/androidx/savedstate/internal/SavedStateRegistryControllerImpl.kt)). That is why the service's own lifecycle, already CREATED or STARTED, cannot be reused as the SavedStateRegistryOwner.
- `LifecycleRegistry(provider)` enforces the main thread ([LifecycleRegistry.kt](https://raw.githubusercontent.com/androidx/androidx/androidx-main/lifecycle/lifecycle-runtime/src/commonMain/kotlin/androidx/lifecycle/LifecycleRegistry.kt)). `LifecycleService` must call `super` in all lifecycle overrides (`@CallSuper`) ([LifecycleService.kt](https://raw.githubusercontent.com/androidx/androidx/androidx-main/lifecycle/lifecycle-service/src/main/java/androidx/lifecycle/LifecycleService.kt)).
- The built-in strategies are `DisposeOnDetachedFromWindowOrReleasedFromPool` (the default), `DisposeOnDetachedFromWindow`, `DisposeOnLifecycleDestroyed` and `DisposeOnViewTreeLifecycleDestroyed` ([ViewCompositionStrategy.android.kt](https://raw.githubusercontent.com/androidx/androidx/androidx-main/compose/ui/ui/src/androidMain/kotlin/androidx/compose/ui/platform/ViewCompositionStrategy.android.kt)).

Starting capture from the bubble:
- Call `startForegroundService` directly while the bubble is visible (AOSP: `REASON_UID_VISIBLE`).
- On `SecurityException` or `ForegroundServiceStartNotAllowedException`, start the trampoline activity instead. Apps with the user-granted SAW permission may start activities from the background ([background starts](https://developer.android.com/guide/components/activities/background-starts)).
- Mark this path "verify on device", as the product requirements requires.

---

## 7. Share-sheet import (voice notes)

```xml
<activity android:name=".share.ShareImportActivity" android:exported="true" android:theme="@style/Theme.EchoLedger.Translucent">
  <intent-filter><action android:name="android.intent.action.SEND" /><category android:name="android.intent.category.DEFAULT" />
    <data android:mimeType="audio/*" /></intent-filter>
  <intent-filter><action android:name="android.intent.action.SEND" /><category android:name="android.intent.category.DEFAULT" />
    <data android:mimeType="application/ogg" /></intent-filter>
  <intent-filter><action android:name="android.intent.action.SEND_MULTIPLE" /><category android:name="android.intent.category.DEFAULT" />
    <data android:mimeType="audio/*" /></intent-filter>
  <intent-filter><action android:name="android.intent.action.SEND_MULTIPLE" /><category android:name="android.intent.category.DEFAULT" />
    <data android:mimeType="application/ogg" /></intent-filter>
</activity>
```

```kotlin
val uris: List<Uri> = when (intent.action) {
    Intent.ACTION_SEND -> listOfNotNull(IntentCompat.getParcelableExtra(intent, Intent.EXTRA_STREAM, Uri::class.java))
    Intent.ACTION_SEND_MULTIPLE -> IntentCompat.getParcelableArrayListExtra(intent, Intent.EXTRA_STREAM, Uri::class.java).orEmpty()
    else -> emptyList()
}
fun meta(uri: Uri): Pair<String?, Long?> = contentResolver.query(uri, null, null, null, null)?.use { c ->
    val n = c.getColumnIndex(OpenableColumns.DISPLAY_NAME); val s = c.getColumnIndex(OpenableColumns.SIZE)
    if (c.moveToFirst()) (if (n >= 0) c.getString(n) else null) to (if (s >= 0 && !c.isNull(s)) c.getLong(s) else null) else null
} ?: (null to null)
// then: contentResolver.getType(uri); contentResolver.openInputStream(uri) -> copy (Dispatchers.IO, size cap) into app-private encrypted file
```

Sources:
- The filter pattern, the `IntentCompat` usage and the warning "the wrong MIME type might be set, or an image being sent might be extremely large" (do I/O off the main thread; avoid `*/*`) come from [receive](https://developer.android.com/training/sharing/receive).
- `IntentCompat.getParcelableExtra(Intent, String, Class<T>)` uses the typed API on SDK 34+. On 33 and below it does a deserialize-then-`isInstance` check. `getParcelableArrayListExtra` does **not** check element types on 33 and below ([IntentCompat.java](https://raw.githubusercontent.com/androidx/androidx/androidx-main/core/core/src/main/java/androidx/core/content/IntentCompat.java)).
- `OpenableColumns.DISPLAY_NAME = "_display_name"` and `SIZE = "_size"` ([OpenableColumns.java](https://android.googlesource.com/platform/frameworks/base/+/refs/heads/main/core/java/android/provider/OpenableColumns.java)). The query pattern is from [retrieve-info](https://developer.android.com/training/secure-file-sharing/retrieve-info).

WhatsApp format and labelling:
- Voice messages are "`.ogg` files encoded with the **OPUS** codec", MIME `audio/ogg` ([Meta audio messages](https://developers.facebook.com/documentation/business-messaging/whatsapp/messages/audio-messages)).
- The exact MIME type WhatsApp puts on its `ACTION_SEND` (`audio/ogg`, `audio/ogg; codecs=opus`, `application/ogg`, ...) is **UNVERIFIED**; log `intent.type` on a device.
- `source_app` for shared files cannot be inferred reliably from the intent (**UNVERIFIED**). Default to `voice_note` and let the user change it.

---

## 8. Networking (OkHttp 5.5.1)

### 8.1 Certificate pinning (Laravel host only)

```kotlin
val http: OkHttpClient = OkHttpClient.Builder()          // one shared instance ("OkHttpClients Should Be Shared")
    .certificatePinner(CertificatePinner.Builder()
        .add("ledger.example.com", "sha256/<current-SPKI-base64>=", "sha256/<backup-key-SPKI-base64>=")
        .build())
    .build()
```

From [CertificatePinner.kt](https://raw.githubusercontent.com/square/okhttp/master/okhttp/src/commonJvmAndroid/kotlin/okhttp3/CertificatePinner.kt) and the [5.x docs](https://lysine.dev/okhttp/5.x/okhttp/okhttp3/-certificate-pinner/index.html):
- It "pins a certificate's Subject Public Key Info". Pins are "base64 SHA-256 hashes", written `sha256/...`.
- Signatures: `fun add(pattern: String, vararg pins: String)` and `@JvmStatic fun pin(certificate: Certificate): String`.
- Patterns are an exact host, `**.host` (any depth) or `*.host` (exactly one label). "Pinning is per-hostname and/or per-wildcard pattern."
- To bootstrap, configure a broken pin and copy the hashes from the failure message. Do this on a trusted network.
- "Do not use certificate pinning without the blessing of your server's TLS administrator!" and it "can not be used to pin self-signed certificate if such certificate is not accepted by [javax.net.ssl.TrustManager]".

Android's guidance is to "always include a backup key" ([network security config](https://developer.android.com/privacy-and-security/security-config)). Builder methods such as `certificatePinner(...)`, `pingInterval(...)`, `readTimeout(...)`, `callTimeout(...)`, `retryOnConnectionFailure(...)` and `addInterceptor(...)` are in [OkHttpClient.kt](https://raw.githubusercontent.com/square/okhttp/master/okhttp/src/commonJvmAndroid/kotlin/okhttp3/OkHttpClient.kt).

### 8.2 `res/xml/network_security_config.xml` ([security-config](https://developer.android.com/privacy-and-security/security-config))

```xml
<network-security-config>
  <base-config cleartextTrafficPermitted="false">
    <trust-anchors><certificates src="system" /></trust-anchors>
  </base-config>
  <domain-config>
    <domain includeSubdomains="false">ledger.example.com</domain>
    <pin-set expiration="2027-06-30">                    <!-- optional second layer; keep in sync with OkHttp pins -->
      <pin digest="SHA-256">CURRENT_SPKI_BASE64=</pin>
      <pin digest="SHA-256">BACKUP_SPKI_BASE64=</pin>
    </pin-set>
  </domain-config>
  <debug-overrides><trust-anchors><certificates src="@raw/debug_cas" /></trust-anchors></debug-overrides>
</network-security-config>
```

- The manifest attribute is `android:networkSecurityConfig="@xml/network_security_config"`. Cleartext is disabled by default for target 28+.
- `debug-overrides` CAs are "trusted _only_ when android:debuggable is `true`". CAs there default to `overridePins="true"`.
- A pin-set `expiration` (yyyy-MM-dd) disables pinning after that date, and the docs warn it "may enable attackers to bypass".
- Certificate transparency is **on by default for target 37**, off on 36, and "isn't performed on connections that use custom trust anchors".
- For **plain-HTTP LAN dev** (XAMPP on `192.168.x.x`), place a copy of this XML in `src/debug/res/xml/` with a `domain-config cleartextTrafficPermitted="true"` for that IP. Build-type source sets override `main` resources ([build variants](https://developer.android.com/build/build-variants)).
- **Target 37** blocks traffic "to and from a local network address" until `ACCESS_LOCAL_NETWORK` (NEARBY_DEVICES group) is granted at runtime. Do not declare it for target ≤ 36, where INTERNET implicitly grants it ([local network permission](https://developer.android.com/privacy-and-security/local-network-permission)). This matters for dev builds only.
- Target 37 also enables ECH. OkHttp 5.5.0 added **opt-in** ECH, which "Today ... is only Android 17" ([CHANGELOG](https://raw.githubusercontent.com/square/okhttp/master/CHANGELOG.md), [17 changes](https://developer.android.com/about/versions/17/behavior-changes-17)).

### 8.3 Streaming STT over WebSocket (provider URL comes from `POST /api/stt/token`)

```kotlin
val ws = http.newWebSocket(Request.Builder().url(streamUrlFromServer).build(), object : WebSocketListener() {
    override fun onOpen(webSocket: WebSocket, response: Response) { /* start pumping PCM */ }
    override fun onMessage(webSocket: WebSocket, text: String) { captions.tryEmit(parseProviderEvent(text)) }
    override fun onClosing(webSocket: WebSocket, code: Int, reason: String) { webSocket.close(1000, null) }
    override fun onFailure(webSocket: WebSocket, t: Throwable, response: Response?) { scheduleReconnectWithFreshToken() }
})
// per audio frame (e.g. 50-100 ms): if (!ws.send(pcm.toByteString(0, n))) backOffOrStop()
```

API notes:
- `fun newWebSocket(request: Request, listener: WebSocketListener): WebSocket`, `send(text)`, `send(bytes: ByteString): Boolean`, `close(code: Int, reason: String?)`, `cancel()`, `queueSize()` ([WebSocket.kt](https://raw.githubusercontent.com/square/okhttp/master/okhttp/src/commonJvmAndroid/kotlin/okhttp3/WebSocket.kt)).
- The outgoing queue "has a 16 MiB limit". On overflow `send` returns `false` and the socket closes gracefully with 1001. "No exception is thrown and no [WebSocketListener.onFailure] callback is triggered."
- Listener callbacks: `onOpen`, `onMessage` (String and ByteString), `onClosing`, `onClosed` and `onFailure(webSocket, t, response: Response?)` ([WebSocketListener.kt](https://raw.githubusercontent.com/square/okhttp/master/okhttp/src/commonJvmAndroid/kotlin/okhttp3/WebSocketListener.kt)).
- Okio: `fun ByteArray.toByteString(offset: Int = 0, byteCount: Int = size)` ([ByteString.kt](https://raw.githubusercontent.com/square/okio/master/okio/src/commonMain/kotlin/okio/ByteString.kt)).
- Provider message formats belong to the STT research note, not this one.

### 8.4 Chunked upload of file slices (5 MB) to `POST /api/recordings/{id}/chunks`

```kotlin
class SliceBody(private val read: (offset: Long, buf: ByteArray, len: Int) -> Int,
                private val offset: Long, private val length: Long) : RequestBody() {
    override fun contentType() = "application/octet-stream".toMediaType()
    override fun contentLength() = length
    override fun writeTo(sink: BufferedSink) {
        val buf = ByteArray(64 * 1024); var done = 0L
        while (done < length) { val n = read(offset + done, buf, minOf(buf.size.toLong(), length - done).toInt())
            if (n <= 0) throw java.io.EOFException(); sink.write(buf, 0, n); done += n }
    }
}
val body = MultipartBody.Builder().setType(MultipartBody.FORM)
    .addFormDataPart("index", i.toString())                         // field names: per web API contract (UNVERIFIED here)
    .addFormDataPart("chunk", "part-$i", SliceBody(reader, i * CHUNK, len)).build()
http.newCall(Request.Builder().url("$base/api/recordings/$id/chunks")
    .header("Authorization", "Bearer $deviceToken").post(body).build()).execute().use { check(it.isSuccessful) }
```

Signatures:
- `RequestBody`: `abstract fun contentType(): MediaType?`, `open fun contentLength(): Long = -1L`, `abstract fun writeTo(sink: BufferedSink)`, `isOneShot()`, `isDuplex()`. Helpers: `ByteArray.toRequestBody(contentType, offset, byteCount)`, `File.asRequestBody(contentType)` ([RequestBody.kt](https://raw.githubusercontent.com/square/okhttp/master/okhttp/src/commonJvmAndroid/kotlin/okhttp3/RequestBody.kt)).
- `MultipartBody.Builder().setType(...)`, `addFormDataPart(name, value)`, `addFormDataPart(name, filename, body)`; `MultipartBody.FORM = "multipart/form-data".toMediaType()` ([MultipartBody.kt](https://raw.githubusercontent.com/square/okhttp/master/okhttp/src/commonJvmAndroid/kotlin/okhttp3/MultipartBody.kt)).
- `Request.Builder` `url`, `header`, `post` and `build` are in [Request.kt](https://raw.githubusercontent.com/square/okhttp/master/okhttp/src/commonJvmAndroid/kotlin/okhttp3/Request.kt).

The `reader` callback reads plaintext at an offset. For Tink-encrypted files, use `newSeekableDecryptingChannel` (§9). Run uploads in a WorkManager `CoroutineWorker` with a network constraint, keeping progress in DataStore. Do not use a dataSync FGS (§3.5).

---

## 9. Secrets and file encryption

- **Do not use `androidx.security:security-crypto` / `EncryptedSharedPreferences`.** 1.1.0 "Deprecated all APIs in favour of existing platform APIs and direct use of Android Keystore" ([security](https://developer.android.com/jetpack/androidx/releases/security)).
- **Device token and settings: DataStore 1.3.0 + `datastore-tink`.**
  - `class AeadSerializer<T>(aead: Aead, wrappedSerializer: Serializer<T>, associatedData: ByteArray = byteArrayOf()) : Serializer<T>` ([AeadSerializer.kt](https://raw.githubusercontent.com/androidx/androidx/androidx-main/datastore/datastore-tink/src/commonMain/kotlin/androidx/datastore/tink/AeadSerializer.kt)).
  - In 1.3.0-alpha10, "`GeneralSecurityException` in AeadSerializer is now wrapped in `CorruptionException`" ([DataStore releases](https://developer.android.com/jetpack/androidx/releases/datastore)).
  - `DataStoreFactory.create(serializer, corruptionHandler = null, migrations = listOf(), scope = CoroutineScope(Dispatchers.IO + SupervisorJob()), produceFile: () -> File)` ([DataStoreFactory.jvm.kt](https://raw.githubusercontent.com/androidx/androidx/androidx-main/datastore/datastore-core/src/jvmMain/kotlin/androidx/datastore/core/DataStoreFactory.jvm.kt)). Also `fun Context.dataStoreFile(fileName: String): File` ([DataStoreFile.android.kt](https://raw.githubusercontent.com/androidx/androidx/androidx-main/datastore/datastore/src/androidMain/kotlin/androidx/datastore/DataStoreFile.android.kt)).

```kotlin
// once, off the main thread (AndroidKeysetManager reads/writes SharedPreferences)
AeadConfig.register(); StreamingAeadConfig.register()
val aead = AndroidKeysetManager.Builder()
    .withSharedPref(ctx, "el_aead_keyset", "el_keysets")
    .withKeyTemplate(KeyTemplate.createFrom(PredefinedAeadParameters.AES256_GCM))
    .withMasterKeyUri("android-keystore://el_master")
    .build().keysetHandle.getPrimitive(RegistryConfiguration.get(), Aead::class.java)
val secureStore: DataStore<DeviceSecrets> = DataStoreFactory.create(
    serializer = AeadSerializer(aead, DeviceSecretsSerializer, associatedData = "device_secrets.pb".encodeToByteArray()),
    produceFile = { ctx.dataStoreFile("device_secrets.pb") })
```

The pattern follows the 1.3.0-alpha07 example in the [DataStore release notes](https://developer.android.com/jetpack/androidx/releases/datastore). Builder methods `withSharedPref(Context, keysetName, prefFileName)`, `withKeyTemplate(KeyTemplate)`, `withMasterKeyUri(String)` and `build()`, and `getKeysetHandle()`, are verified in [AndroidKeysetManager.java @ v1.24.0](https://raw.githubusercontent.com/tink-crypto/tink-java/v1.24.0/src/main/java/com/google/crypto/tink/integration/android/AndroidKeysetManager.java). That file also says:
- **"Warning: because Android Keystore is unreliable, we strongly recommend disabling it by not setting any master key URI."** Tink "runs a self-test ... and disables Android Keystore accordingly"; then "keysets will be stored in cleartext" in private preferences. Check `isUsingKeystore`.
- A keyset that cannot be decrypted is "an irrecoverable error. You'd have to delete the keyset in Shared Preferences and all existing data encrypted with it". If the master key exists but is unusable, it throws `KeyStoreException`. The master key URI "must start with `android-keystore://`".
- **Choice for EchoLedger**: the product requirements mandates the Keystore, so keep `withMasterKeyUri`. Treat a decrypt failure as "wipe local secrets and recordings, then re-register the device". Server copies are authoritative. Surface `isUsingKeystore()` in diagnostics.

`KeyTemplate.createFrom(Parameters)`, `AeadConfig.register()`, `StreamingAeadConfig.register()` and `RegistryConfiguration.get()` are verified in the v1.24.0 sources.

**Recordings (large files): Tink StreamingAead.**
- [PredefinedStreamingAeadParameters](https://raw.githubusercontent.com/tink-crypto/tink-java/main/src/main/java/com/google/crypto/tink/streamingaead/PredefinedStreamingAeadParameters.java) includes **`AES256_GCM_HKDF_4KB`**: key 32 B, derived key 32 B, HKDF-SHA256, 4096-byte segments. It also has `AES128_GCM_HKDF_4KB/1MB`, `AES256_GCM_HKDF_1MB` and the CTR-HMAC variants. The template name `"AES256_GCM_HKDF_4KB"` is registered in `AesGcmHkdfStreamingKeyManager` @v1.24.0.
- Tink docs: "We recommend AES128_GCM_HKDF_1MB for most uses". 4 KB variants suit low memory. "Associated data is authenticated but _NOT_ encrypted" ([Streaming AEAD](https://developers.google.com/tink/streaming-aead)).
- API: `newEncryptingStream(OutputStream, byte[])`, `newDecryptingStream(InputStream, byte[])`, `newEncryptingChannel`, `newDecryptingChannel` and **`newSeekableDecryptingChannel(SeekableByteChannel, byte[])`**, which gives random-access plaintext for upload slices ([StreamingAead.java](https://raw.githubusercontent.com/tink-crypto/tink-java/main/src/main/java/com/google/crypto/tink/StreamingAead.java)). The example uses `handle.getPrimitive(RegistryConfiguration.get(), StreamingAead.class)` ([large files guide](https://developers.google.com/tink/encrypt-large-files-or-data-streams)).
- Gradle: `implementation 'com.google.crypto.tink:tink-android:1.24.0'`. "Tink Android requires no proguard configuration". It is fully supported from API 24, and some streamingaead APIs need 24+ ([Java setup](https://developers.google.com/tink/setup/java)).
- Use a **separate keyset** for recordings and the recording UUID as associated data. Write the encrypting stream on the capture thread.

**Alternative without Tink: direct Keystore AES-GCM.** This means per-chunk segments with a random IV per segment. The builder is `KeyGenParameterSpec.Builder(String alias, int purposes).setBlockModes(BLOCK_MODE_GCM).setEncryptionPaddings(ENCRYPTION_PADDING_NONE).setKeySize(256)` ([KeyGenParameterSpec.java](https://android.googlesource.com/platform/frameworks/base/+/refs/heads/main/keystore/java/android/security/keystore/KeyGenParameterSpec.java), [KeyProperties.java](https://android.googlesource.com/platform/frameworks/base/+/refs/heads/main/keystore/java/android/security/keystore/KeyProperties.java)).
- With IND-CPA required by default, "caller-provided IVs are rejected when encrypting". Let the `Cipher` generate the IV and read `Cipher#getIV()`.
- `KeyGenerator.getInstance(algorithm, "AndroidKeyStore")`. Avoid the Keystore on the main thread, and check `getSecurityLevel()` on 29+ ([Keystore](https://developer.android.com/privacy-and-security/keystore)).
- This is more code to own; prefer Tink.

---

## 10. Testing and lint

- **Robolectric 4.17**:
  - Set `isIncludeAndroidResources = true` plus the JDK 17+ `--add-opens` list from §1.5 ([getting started](https://robolectric.org/getting-started/)). It runs against `targetSdk` by default; override with `@Config(sdk = [36])` or `src/test/resources/<package>/robolectric.properties` containing `sdk=36` ([configuring](https://robolectric.org/configuring/)).
  - SDK 36 and 37 need **Java 21**. The local JDK is 21, so this works. 4.16 dropped SDK 21–22 ([compatibility table](https://robolectric.org/compatibility_table/), [releases](https://github.com/robolectric/robolectric/releases)).
  - Robolectric can run "UI tests such as Espresso or Compose tests" ([Robolectric local tests](https://developer.android.com/training/testing/local-tests/robolectric)).
- **Compose UI tests on the JVM**:
  - Use `testImplementation(compose ui-test-junit4)` and `debugImplementation(ui-test-manifest)`. The manifest artifact is "Needed for createComposeRule(), but not for createAndroidComposeRule<YourActivity>()" ([Compose testing](https://developer.android.com/develop/ui/compose/testing)).
  - Use the **v2** imports `androidx.compose.ui.test.junit4.v2.createComposeRule` and `androidx.compose.ui.test.v2.runComposeUiTest`. "The v1 APIs are deprecated". v2 uses `StandardTestDispatcher`, so call `waitForIdle()` or `mainClock.advanceTimeBy()`. v2 is available from 1.11.0-alpha03 ([migrate v2](https://developer.android.com/develop/ui/compose/testing/migrate-v2)).

```kotlin
@RunWith(AndroidJUnit4::class)            // androidx.test.ext:junit 1.3.0 (Robolectric under the hood)
class SaveAfterCallDialogTest {
    @get:Rule val rule = androidx.compose.ui.test.junit4.v2.createComposeRule()
    @Test fun keepCallsCallback() { var kept = false
        rule.setContent { SaveRecordingDialog(onKeep = { kept = true }, onDiscard = {}) }
        rule.onNodeWithText("Keep").performClick(); rule.waitForIdle(); assertTrue(kept) }
}
```

- **Flows**: `flow.test { assertEquals(x, awaitItem()); awaitComplete() }`, plus `awaitError()`, `cancelAndIgnoreRemainingEvents()` and `turbineScope`/`testIn` ([Turbine README](https://raw.githubusercontent.com/cashapp/turbine/trunk/README.md)). Pair it with `kotlinx-coroutines-test` 1.11.0.
- **HTTP fakes**: use `com.squareup.okhttp3:mockwebserver3:5.5.1` for the token-fetch and upload tests ([README](https://raw.githubusercontent.com/square/okhttp/master/README.md)). The detailed MockWebServer API was **not** checked during research.
- **ktlint**: apply plugin `org.jlleitschuh.gradle.ktlint` 14.2.0 and set `ktlint { version.set("1.8.0") }`. Tasks are `ktlintCheck` and `ktlintFormat`, and `mavenCentral()` is needed to download ktlint ([README](https://github.com/JLLeitschuh/ktlint-gradle)). Root `.editorconfig`:

```ini
[*.{kt,kts}]
ktlint_code_style = android_studio
ktlint_function_naming_ignore_when_annotated_with = Composable
```

The values `android_studio`, `intellij_idea` and `ktlint_official` (the default) are defined in [CodeStyleEditorConfigProperty.kt@1.8.0](https://raw.githubusercontent.com/pinterest/ktlint/1.8.0/ktlint-rule-engine-core/src/main/kotlin/com/pinterest/ktlint/rule/engine/core/api/editorconfig/CodeStyleEditorConfigProperty.kt). The `ktlint_function_naming_ignore_when_annotated_with` property, a comma-separated list without `@`, is in [FunctionNamingRule.kt@1.8.0](https://raw.githubusercontent.com/pinterest/ktlint/1.8.0/ktlint-ruleset-standard/src/main/kotlin/com/pinterest/ktlint/ruleset/standard/rules/FunctionNamingRule.kt).

---

## 11. OEM background reliability (Samsung One UI and others)

- **dontkillmyapp.com** ranks Samsung 4th worst ("especially after Android P update"), behind Huawei, Xiaomi and OnePlus. It lists "No known solution on dev end" for Samsung ([home](https://dontkillmyapp.com/), [Samsung](https://dontkillmyapp.com/samsung)).
- User steps on Android 11 One UI:
  - Battery → Background usage limits: check the Sleeping, Deep sleeping and **Never sleeping apps** lists.
  - Make sure **Put unused apps to sleep** is disabled.
  - Battery → More battery settings → disable **Adaptive battery**.
  - Apps → EchoLedger → Battery → Battery optimization → All apps → **Don't optimize**.
  - Exact labels on newer One UI are **UNVERIFIED**; the site shows Android 13 only as screenshots.
- **Platform exemption** (sideloaded, so Play policy does not apply):
  - Declare `REQUEST_IGNORE_BATTERY_OPTIMIZATIONS` (protection level `normal` ([framework manifest](https://android.googlesource.com/platform/frameworks/base/+/refs/heads/main/core/res/AndroidManifest.xml))).
  - Send `Settings.ACTION_REQUEST_IGNORE_BATTERY_OPTIMIZATIONS` with `package:com.echoledger.mobile` data. Check with `PowerManager.isIgnoringBatteryOptimizations(String)` ([Settings.java](https://android.googlesource.com/platform/frameworks/base/+/refs/heads/main/core/java/android/provider/Settings.java), [PowerManager.java](https://android.googlesource.com/platform/frameworks/base/+/refs/heads/main/core/java/android/os/PowerManager.java)).
  - Exempt apps "can use the network and hold partial wake locks during Doze". The Play prohibition is noted on the same page ([Doze](https://developer.android.com/training/monitoring-device-state/doze-standby)).
  - Turning battery optimisation off is also a *general* FGS start exemption (§3.2), but **not** a WIU one.
- Explain all of this on an in-app "Background reliability" screen with deep links. After a reboot or a user force-stop, show one notification with a Record action (a notification-action WIU exemption), not a popup.

---

## 12. Sideload signing (brief)

- Keep keystore secrets out of build files with a `keystore.properties` loaded in Gradle, used in `signingConfigs { create("config") { keyAlias/keyPassword/storeFile/storePassword } }`. Keep that file out of version control ([app signing](https://developer.android.com/studio/publish/app-signing)).
- Make the key valid for at least 25 years. The debug keystore lives at `$HOME/.android/debug.keystore`.
- Signature schemes are documented at https://source.android.com/security/apksigning. Exact `apksigner` flags were not checked during research.

---

## Recommendations for EchoLedger

1. **Toolchain**: AGP **9.4.1**, Gradle wrapper **9.8.2** with `distributionSha256Sum`, JDK = SDK-bundled Temurin 21 (`JAVA_HOME`), Kotlin **2.4.21** through AGP built-in Kotlin. Apply `org.jetbrains.kotlin.plugin.compose` and `plugin.serialization` at the Kotlin version, set `buildFeatures.compose = true`, Java target 17, `minSdk 29`.
2. **SDK level**: use **Profile A** (`compileSdk 37`, `targetSdk 37`, latest libraries) and install `platforms;android-37` once. If installing is not allowed, use **Profile B** (36/36, BOM 2026.06.01, core 1.18.0, lifecycle 2.10.0). Either way, record the choice in PLAN.md. Never mix Profile A libraries with compileSdk 36.
3. **Start-recording entry points**, in order of guarantee:
   - (a) the in-app button;
   - (b) a **notification action** → `PendingIntentCompat.getForegroundService(...)`, immutable;
   - (c) the **widget** → `actionStartService<RecordingService>(isForegroundService = true)` on a non-lazy element;
   - (d) the **QS tile** → `startActivityAndCollapse(PendingIntent)` → translucent trampoline activity → `startForegroundService`;
   - (e) the **bubble** → direct start while visible, falling back to the trampoline.
   - Mark (d) and (e) "verify on device" in MANUAL_QA.md, citing §3.4.
4. **One `RecordingService : LifecycleService`** with `foregroundServiceType="microphone"`. It calls `ServiceCompat.startForeground(..., FOREGROUND_SERVICE_TYPE_MICROPHONE)` first thing, uses `FOREGROUND_SERVICE_IMMEDIATE`, offers a Stop action, and catches `SecurityException`. Host the caption bubble from this service during sessions only, with its own `OverlayOwner` lifecycle (§6).
5. **Onboarding (once, then never nag)**: RECORD_AUDIO, POST_NOTIFICATIONS (without it the notification action is gone), the overlay permission if the bubble is wanted, the battery exemption, and Samsung tips. Re-check these on resume and reflect the state in the widget, for example by opening the app when the mic permission is missing.
6. **"Save this recording?"**: show it as a post-call **notification with Keep and Discard actions**, not an activity popup. That matches priority #3 ("no disturbance").
7. **Uploads**: a WorkManager `CoroutineWorker` (already a transitive dependency via Glance; pin 2.12.0) with a network constraint, 5 MB slices via the custom `RequestBody`, idempotent chunk indexes, and resume state in DataStore. Never use a dataSync FGS.
8. **Networking**: one shared `OkHttpClient` with a `CertificatePinner` for the Laravel host only, carrying the current and **backup** SPKI pins. Use the same client for the provider WebSocket; pins apply per host pattern. Set `cleartextTrafficPermitted="false"`, and add a debug-only source-set XML for LAN HTTP. Under target 37, LAN dev also needs the `ACCESS_LOCAL_NETWORK` runtime grant.
9. **Secrets**: DataStore 1.3.0 + `datastore-tink` `AeadSerializer`, using a Tink AEAD keyset wrapped by `android-keystore://` (satisfies the product requirements).
   - Recordings: Tink StreamingAead with a separate keyset (`AES256_GCM_HKDF_4KB` as requested, or `AES128_GCM_HKDF_1MB` per Tink's recommendation) and the recording UUID as associated data. Read upload slices via `newSeekableDecryptingChannel`.
   - Declare `tink-android:1.24.0` explicitly; it overrides datastore-tink's 1.20.0.
   - Design a "keys lost → wipe and re-register" path.
10. **Tests (`./gradlew test`, no device)**: pure Kotlin state machines (save-after-call, upload resume), and share-intent parsing under Robolectric (build Intents with `EXTRA_STREAM`). Glance content via `runGlanceAppWidgetUnitTest` with `hasStartServiceAction<RecordingService>(true)`. The notification PendingIntent target via Robolectric (inspect the built Notification's actions; method names **UNVERIFIED**). Token fetch and chunk upload against MockWebServer. Compose v2 rules for the dialog.
11. **Lint**: ktlint 1.8.0 through plugin 14.2.0 with the `.editorconfig` above. Wire `ktlintCheck` into `./gradlew check`.

## Gotchas

- Compose ≥ 1.12, core ≥ 1.19 and lifecycle-compose ≥ 2.11 all need **compileSdk 37 / AGP ≥ 9.1–9.2**. This machine has only android-36, so the latest libraries plus compileSdk 36 fails the build.
- With AGP 9, do **not** apply `org.jetbrains.kotlin.android`. `kotlin-kapt` is incompatible. Move `kotlinOptions` to `kotlin.compilerOptions`. `kotlin.sourceSets` is unsupported.
- AGP 9.4.1 drags KGP 2.2.10 onto the classpath. Without the Compose or serialization plugin at 2.4.21, or an explicit `classpath(...)`, you silently compile with Kotlin 2.2.10.
- AGP 9.4 needs Gradle ≥ 9.6.0. The cached 9.3.1 can only *generate* the wrapper, so do that before adding AGP plugin declarations.
- In `local.properties`, single backslashes are silently dropped (`C:Users...`). Use `/` or `\\`.
- Robolectric for SDK 36/37 needs a **JDK 21** runtime, and its first run downloads android-all jars.
- `checkSelfPermission(RECORD_AUDIO)` returns GRANTED in the background, yet `startForeground(type=microphone)` throws `SecurityException` without a WIU exemption. Catch it.
- A microphone FGS cannot start from `BOOT_COMPLETED` (Android 14+). Post a notification with a Record action instead.
- dataSync and mediaProcessing FGS have a 6 h / 24 h cap at target 35+.
- When POST_NOTIFICATIONS is denied, the FGS notification shows only in Task Manager, so there is no action button. On Android 14+, ongoing notifications can be swiped away. Task Manager "Stop" kills the whole app with no callback.
- Glance lambda and `actionRunCallback` clicks run through WorkManager or a broadcast receiver, so they are not the "widget interaction" start. Keep the mic button outside `LazyColumn`; lazy items go through a trampoline.
- Glance: `updateAppWidgetState` does not redraw (call `update`). `provideGlance` runs on the main thread. Receivers have a 10 s limit. Target 37 caps RemoteViews bitmap memory.
- `TileService.startActivityAndCollapse(Intent)` throws `UnsupportedOperationException` at target 34+.
- `ACTION_MANAGE_OVERLAY_PERMISSION` ignores `package:` data on 11+. The SAW FGS exemption (target 35+) needs the overlay **visible first**.
- Overlay click-through (`FLAG_NOT_TOUCHABLE`) works only with `alpha ≤ 0.8`. `FLAG_NOT_FOCUSABLE` already implies `FLAG_NOT_TOUCH_MODAL`.
- A `ComposeView` in a Service needs a ViewTree LifecycleOwner and SavedStateRegistryOwner. `performAttach` needs an **INITIALIZED** lifecycle, so the service's own lifecycle cannot be reused.
- OkHttp pinning cannot rescue a self-signed certificate the TrustManager rejects. Always ship a backup pin, and coordinate key rotation with the server.
- `WebSocket.send` returns `false` silently (queue > 16 MiB, or closing). Check it.
- Target 37: LAN traffic needs the `ACCESS_LOCAL_NETWORK` runtime permission. CT is on by default, but not for custom trust anchors. Target ≤ 36: do not declare `ACCESS_LOCAL_NETWORK`.
- security-crypto is deprecated. Tink's own docs call the Android Keystore "unreliable", so plan for an unrecoverable keyset. Never build keysets on the main thread.
- `datastore-tink` pulls `tink-android` 1.20.0; pin 1.24.0. Its JVM variant depends on `com.google.crypto.tink:tink`, so do not add the JVM `tink` artifact to the Android app. The risk of duplicate classes when both are present is UNVERIFIED.
- ktlint-gradle defaults to ktlint 1.5.0; set 1.8.0.
- The AOSP `main` mirror lags the release tags. Read platform behaviour at `android-16.0.0_r1` / `android-17.0.0_r1`.

## Load-bearing claims

| # | Claim | Source | Confidence |
|---|---|---|---|
| 1 | A WIU (microphone) FGS cannot be created from the background even with a general exemption. Exemptions: system component, widget interaction, notification interaction, PendingIntent from a different visible app (+ DPC, VoiceInteractionService, START_ACTIVITIES_FROM_BACKGROUND) | https://developer.android.com/develop/background-work/services/fgs/restrictions-bg-start | high |
| 2 | The microphone FGS needs the `microphone` type, `FOREGROUND_SERVICE_MICROPHONE` and a granted `RECORD_AUDIO`. It cannot start from `BOOT_COMPLETED` | https://developer.android.com/develop/background-work/services/fgs/service-types ; https://developer.android.com/about/versions/15/behavior-changes-15 | high |
| 3 | Glance `actionStartService(isForegroundService = true)` on a non-lazy element produces `PendingIntent.getForegroundService(FLAG_IMMUTABLE or FLAG_UPDATE_CURRENT)`. Lazy items use a trampoline | https://raw.githubusercontent.com/androidx/androidx/androidx-main/glance/glance-appwidget/src/main/java/androidx/glance/appwidget/action/ApplyAction.kt | high |
| 4 | `TileService.startActivityAndCollapse(Intent)` throws at target 34+; use the PendingIntent overload. In AOSP@17r1 a tile click grants WIU only behind `fgs_wiu_qs_tile_clicked` (ENABLED in `cp2a`/`trunk_staging`, absent in `cp1a`) | https://developer.android.com/about/versions/14/behavior-changes-14 ; https://android.googlesource.com/platform/frameworks/base/+/refs/tags/android-17.0.0_r1/services/core/java/com/android/server/am/ActiveServices.java ; https://android.googlesource.com/platform/build/release/+/refs/tags/android-17.0.0_r1/aconfig/cp2a/com.android.server.am/fgs_wiu_qs_tile_clicked_flag_values.textproto | high (docs) / medium (which release enables it) |
| 5 | SAW FGS start at target 35+ needs a visible `TYPE_APPLICATION_OVERLAY`. AOSP counts a visible overlay as "UID visible", which grants WIU, but that is undocumented and OEM behaviour is unknown | https://developer.android.com/about/versions/15/behavior-changes-15 ; https://android.googlesource.com/platform/frameworks/base/+/refs/tags/android-17.0.0_r1/services/core/java/com/android/server/wm/ActivityTaskManagerService.java | high (docs) / medium (AOSP to device) |
| 6 | The latest stable libraries need compileSdk 37 (AAR `minCompileSdk=37` for core 1.19.1, compose-ui 1.12.1, lifecycle-runtime-compose 2.11.0). android-37 is not installed | AAR metadata from https://dl.google.com/dl/android/maven2/ ; https://developer.android.com/develop/ui/compose/compiler | high |
| 7 | AGP 9.4.1 needs Gradle ≥ 9.6.0 (use 9.8.2), compiles Kotlin itself (no kotlin-android) and pins KGP 2.2.10. The Compose plugin at 2.4.21 pulls KGP 2.4.21 | https://developer.android.com/build/releases/gradle-plugin ; https://developer.android.com/build/releases/agp-9-0-0-release-notes ; https://repo1.maven.org/maven2/org/jetbrains/kotlin/compose-compiler-gradle-plugin/2.4.21/compose-compiler-gradle-plugin-2.4.21.pom | high (KGP lift via the Compose plugin: medium) |
| 8 | Robolectric 4.17 supports SDK 36 and 37 and needs Java 21 for them | https://robolectric.org/compatibility_table/ | high |
| 9 | security-crypto deprecated all its APIs. DataStore 1.3.0 `datastore-tink` `AeadSerializer` plus Tink `AndroidKeysetManager` (`android-keystore://`) is the replacement; Tink warns the Keystore is unreliable | https://developer.android.com/jetpack/androidx/releases/security ; https://developer.android.com/jetpack/androidx/releases/datastore ; https://raw.githubusercontent.com/tink-crypto/tink-java/v1.24.0/src/main/java/com/google/crypto/tink/integration/android/AndroidKeysetManager.java | high |
| 10 | Tink StreamingAead offers `AES256_GCM_HKDF_4KB` (4096-byte segments) and `newSeekableDecryptingChannel` for random-access upload slices. tink-android is 1.24.0 | https://raw.githubusercontent.com/tink-crypto/tink-java/main/src/main/java/com/google/crypto/tink/streamingaead/PredefinedStreamingAeadParameters.java ; https://raw.githubusercontent.com/tink-crypto/tink-java/main/src/main/java/com/google/crypto/tink/StreamingAead.java | high |
| 11 | OkHttp `CertificatePinner` pins SPKI SHA-256 per host pattern, cannot pin self-signed certificates the TrustManager rejects, and backup pins are advised | https://raw.githubusercontent.com/square/okhttp/master/okhttp/src/commonJvmAndroid/kotlin/okhttp3/CertificatePinner.kt ; https://developer.android.com/privacy-and-security/security-config | high |
| 12 | Target 37: LAN traffic needs the `ACCESS_LOCAL_NETWORK` runtime permission, CT is on by default (not for custom anchors), and RemoteViews bitmap memory is capped | https://developer.android.com/about/versions/17/behavior-changes-17 ; https://developer.android.com/privacy-and-security/local-network-permission | high |

## Open questions

1. Can the build machine run `sdkmanager "platforms;android-37"`, or rely on AGP auto-download? If so, use Profile A; if not, Profile B. And will we target 37 (requirements: "target latest") or 36 for v1, given `ACCESS_LOCAL_NETWORK` for LAN dev and the RemoteViews cap?
2. Which Android and One UI build does the test S25 Ultra run, and does that build's release config enable `fgs_wiu_qs_tile_clicked`? Does One UI honour the AOSP "visible overlay = UID visible" WIU path? Both need device tests.
3. What MIME type does WhatsApp or WhatsApp Business actually set on its voice-note `ACTION_SEND`? Can `source_app` be inferred at all, for example from the referrer? This needs a device log.
4. Is the Laravel host's certificate publicly trusted (Let's Encrypt), and who owns SPKI backup-key rotation? This decides the pin set and the `pin-set expiration` policy.
5. Does `POST /api/recordings/{id}/chunks` take multipart (field names) or a raw body with `Content-Range`? The web research note owns this contract.
6. Is the Compose compiler plugin plus AGP 9.4.1 built-in Kotlin plus ktlint-gradle 14.2.0 green on the first build? The evidence is nowinandroid (AGP 9.3.2) and the ktlint-gradle 14.1.0 changelog; nothing was built during research.
7. Should the bubble exist when idle, meaning a persistent overlay outside a recording session? That would need its own FGS (for example `specialUse`, with a `PROPERTY_SPECIAL_USE_FGS_SUBTYPE` property ([service types](https://developer.android.com/develop/background-work/services/fgs/service-types))). The simpler v1 shows it only during capture.
8. Which `cp*` release config ships as Android 17 QPR1 on Pixel versus OEM builds? Mapping these codenames to public releases is UNVERIFIED.
