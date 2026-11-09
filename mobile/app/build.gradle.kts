import java.util.Properties

plugins {
    alias(libs.plugins.android.application)
    alias(libs.plugins.kotlin.compose)
    alias(libs.plugins.kotlin.serialization)
    alias(libs.plugins.ktlint)
    alias(libs.plugins.roborazzi)
}

// Release signing (mobile/RELEASE.md): mobile/keystore.properties, or ECHOLEDGER_* environment variables in CI.
// Neither is committed; without them the release APK is built unsigned.
val keystoreProperties = Properties().apply {
    val file = rootProject.file("keystore.properties")
    if (file.exists()) file.inputStream().use { load(it) }
}

fun signingValue(property: String, env: String): String? = keystoreProperties.getProperty(property) ?: System.getenv(env)

android {
    namespace = "com.echoledger.mobile"
    compileSdk = 37

    defaultConfig {
        applicationId = "com.echoledger.mobile"
        minSdk = 29
        targetSdk = 37
        versionCode = 17
        versionName = "1.6.0"
        // The capture helper runs as a Shizuku daemon that is only replaced when this number changes, so every build
        // gets its own (minutes since 2026-01-01): an updated app never keeps talking to an old helper.
        buildConfigField("int", "HELPER_BUILD", ((System.currentTimeMillis() / 60_000L) - 29_446_560L).toString())
        // Since v1.5: the server whose /s/, /p/ and /r/ links open in the app (App Links). From the gradle property
        // echoledger.appLinkHost (in the user's gradle.properties or -P, never in the repository); the reserved
        // "applinks.invalid" (RFC 2606: never resolves) matches nothing and is still a domain lint accepts.
        manifestPlaceholders["appLinkHost"] =
            (project.findProperty("echoledger.appLinkHost") as String?)?.takeIf { it.isNotBlank() } ?: "applinks.invalid"
        // Since v1.6: the server the sign-in screen uses without asking (the production one), from the gradle property
        // echoledger.defaultServer (the user's gradle.properties, -P, or ECHOLEDGER_DEFAULT_SERVER; never in the
        // repository). Empty: the sign-in screen asks for the server, as before.
        buildConfigField(
            "String",
            "DEFAULT_SERVER",
            "\"" +
                ((project.findProperty("echoledger.defaultServer") as String?) ?: System.getenv("ECHOLEDGER_DEFAULT_SERVER") ?: "").trim() +
                "\""
        )
        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"
        // Every instrumented test runs in its own process with cleared app data (a crash fails only that test).
        testInstrumentationRunnerArguments["clearPackageData"] = "true"
        // Tests that need an outside script (see ProcessDeathTest) are not part of the default run.
        testInstrumentationRunnerArguments["notAnnotation"] = "com.echoledger.mobile.e2e.support.ScriptDriven"
    }

    // Since v1.5 (mobile/DISTRIBUTION.md): "full" is the website build with every feature and its own updates; "play" is
    // the Google Play build, without the call log (Play allows it only for the default phone app) and without updating
    // itself (Play does that). Same application id: one phone has one of them.
    flavorDimensions += "store"
    productFlavors {
        create("full") {
            dimension = "store"
            isDefault = true
            buildConfigField("boolean", "CALL_LOG", "true")
            buildConfigField("boolean", "CAPTURE_HELPER", "true")
            // Since v1.7: upgrades by bank transfer with a receipt (Google Play's billing rules leave them out of the play build).
            buildConfigField("boolean", "MANUAL_PAYMENTS", "true")
        }
        create("play") {
            dimension = "store"
            buildConfigField("boolean", "CALL_LOG", "false")
            // Play allows neither a shell-UID capture helper nor the special-use foreground service it needs.
            buildConfigField("boolean", "CAPTURE_HELPER", "false")
            buildConfigField("boolean", "MANUAL_PAYMENTS", "false")
        }
    }

    signingConfigs {
        // Debug builds normally use this computer's own debug key. A phone that already has EchoLedger takes an update
        // only when it is signed with the same key, so another computer can sign with the first one's debug keystore:
        // gradle property echoledger.debugKeystore (user gradle.properties or -P, never in the repository).
        getByName("debug") {
            (project.findProperty("echoledger.debugKeystore") as String?)?.takeIf { it.isNotBlank() }?.let { path ->
                storeFile = file(path)
                storePassword = "android"
                keyAlias = "androiddebugkey"
                keyPassword = "android"
            }
        }
        create("release") {
            signingValue("storeFile", "ECHOLEDGER_KEYSTORE")?.let { path ->
                storeFile = file(path)
                storePassword = signingValue("storePassword", "ECHOLEDGER_KEYSTORE_PASSWORD")
                keyAlias = signingValue("keyAlias", "ECHOLEDGER_KEY_ALIAS")
                keyPassword = signingValue("keyPassword", "ECHOLEDGER_KEY_PASSWORD")
            }
        }
    }

    buildTypes {
        release {
            isMinifyEnabled = true
            isShrinkResources = true
            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")
            signingConfig = signingConfigs.getByName("release").takeIf { it.storeFile != null }
        }
    }

    buildFeatures {
        compose = true
        aidl = true
        buildConfig = true
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    testOptions {
        animationsDisabled = true
        execution = "ANDROIDX_TEST_ORCHESTRATOR"
        unitTests {
            isIncludeAndroidResources = true
            isReturnDefaultValues = true
        }
    }

    packaging {
        resources.excludes += setOf("META-INF/{AL2.0,LGPL2.1}", "META-INF/LICENSE*", "META-INF/NOTICE*")
    }
}

kotlin {
    compilerOptions {
        jvmTarget.set(org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17)
    }
}

ktlint {
    version.set("1.8.0")
    android.set(true)
}

dependencies {
    implementation(libs.androidx.core.ktx)
    implementation(libs.androidx.activity.compose)
    implementation(libs.androidx.lifecycle.runtime.compose)
    implementation(libs.androidx.lifecycle.viewmodel.compose)
    implementation(libs.androidx.lifecycle.service)
    implementation(platform(libs.compose.bom))
    implementation(libs.compose.ui)
    implementation(libs.compose.ui.tooling.preview)
    implementation(libs.compose.material3)
    implementation(libs.compose.material.icons)
    implementation(libs.glance.appwidget)
    implementation(libs.glance.material3)
    implementation(libs.datastore.preferences)
    implementation(libs.work.runtime.ktx)
    implementation(libs.kotlinx.coroutines.android)
    implementation(libs.kotlinx.serialization.json)
    implementation(libs.okhttp)
    implementation(libs.tink.android)
    implementation(libs.shizuku.api)
    implementation(libs.shizuku.provider)
    implementation(libs.hiddenapibypass)
    implementation(libs.androidx.camera.camera2)
    implementation(libs.androidx.camera.lifecycle)
    implementation(libs.androidx.camera.view)
    implementation(libs.zxing.core)
    implementation(libs.media3.exoplayer)
    implementation(libs.media3.common)
    implementation(libs.androidx.biometric)
    implementation(libs.androidx.fragment)
    debugImplementation(libs.compose.ui.tooling)

    testImplementation(libs.junit4)
    testImplementation(libs.robolectric)
    testImplementation(libs.turbine)
    testImplementation(libs.mockito.core)
    testImplementation(libs.mockito.kotlin)
    testImplementation(libs.kotlinx.coroutines.test)
    testImplementation(libs.okhttp.mockwebserver3)
    testImplementation(libs.okhttp.tls)
    testImplementation(libs.work.testing)
    testImplementation(libs.androidx.test.core)
    testImplementation(libs.androidx.test.ext.junit)
    testImplementation(libs.glance.appwidget.testing)
    testImplementation(libs.roborazzi)
    testImplementation(libs.roborazzi.compose)
    testImplementation(platform(libs.compose.bom))
    testImplementation(libs.compose.ui.test.junit4)
    debugImplementation(libs.compose.ui.test.manifest)

    androidTestImplementation(libs.junit4)
    androidTestImplementation(libs.androidx.test.ext.junit)
    androidTestImplementation(libs.androidx.test.runner)
    androidTestImplementation(libs.androidx.test.uiautomator)
    androidTestUtil(libs.androidx.test.orchestrator)
}

// Instrumented tests clear this app's data before every test (clearPackageData above), so they must never run on a
// phone someone uses: a connected test run stops while a real device is attached, unless -PallowConnectedPhone=true
// says that phone is free to be wiped. Emulators are always fine.
tasks.matching { it.name.startsWith("connected") && it.name.endsWith("AndroidTest") }.configureEach {
    doFirst {
        if (project.findProperty("allowConnectedPhone") == "true") return@doFirst
        val adb = androidComponents.sdkComponents.adb.get().asFile
        val listed = ProcessBuilder(adb.absolutePath, "devices").redirectErrorStream(true).start().inputStream.bufferedReader().readText()
        val phones = listed.lines().drop(1).map { it.trim() }.filter { it.endsWith("\tdevice") && !it.startsWith("emulator-") }
        if (phones.isNotEmpty()) {
            throw GradleException(
                "Instrumented tests wipe the app's data, so they do not run on a real phone (${phones.joinToString {
                    it.substringBefore('\t')
                }}). " +
                    "Use an emulator, or pass -PallowConnectedPhone=true when that phone is free to be wiped."
            )
        }
    }
}

// Design screenshots (docs/DESIGN_SYSTEM.md), rendered on the laptop by Robolectric: `recordRoborazziFullDebug` writes
// them to docs/design/screens, `verifyRoborazziFullDebug` fails when a screen changed, `compareRoborazziFullDebug` writes the
// differences. A plain test run renders them too but writes nothing.
val designScreens = rootProject.file("../docs/design/screens")
roborazzi {
    outputDir.set(designScreens)
}
tasks.withType<Test>().configureEach {
    systemProperty("screenshots.dir", designScreens.absolutePath)
    // The Google Play build runs its own tests (its merged manifest and switches) and the shared update rules; all
    // other code is shared and tested once, in the website build, whose behaviour those tests describe.
    if (name.startsWith("testPlay")) {
        filter {
            includeTestsMatching("com.echoledger.mobile.update.PlayBuildTest")
            includeTestsMatching("com.echoledger.mobile.update.AppUpdatesTest")
        }
    }
}
