import java.util.Properties

plugins {
    alias(libs.plugins.android.application)
    alias(libs.plugins.kotlin.compose)
    alias(libs.plugins.kotlin.serialization)
    alias(libs.plugins.ktlint)
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
        versionCode = 8
        versionName = "1.2.2"
        // The capture helper runs as a Shizuku daemon that is only replaced when this number changes, so every build
        // gets its own (minutes since 2026-01-01): an updated app never keeps talking to an old helper.
        buildConfigField("int", "HELPER_BUILD", ((System.currentTimeMillis() / 60_000L) - 29_446_560L).toString())
        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"
        // Every instrumented test runs in its own process with cleared app data (a crash fails only that test).
        testInstrumentationRunnerArguments["clearPackageData"] = "true"
        // Tests that need an outside script (see ProcessDeathTest) are not part of the default run.
        testInstrumentationRunnerArguments["notAnnotation"] = "com.echoledger.mobile.e2e.support.ScriptDriven"
    }

    signingConfigs {
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
    testImplementation(platform(libs.compose.bom))
    testImplementation(libs.compose.ui.test.junit4)
    debugImplementation(libs.compose.ui.test.manifest)

    androidTestImplementation(libs.junit4)
    androidTestImplementation(libs.androidx.test.ext.junit)
    androidTestImplementation(libs.androidx.test.runner)
    androidTestImplementation(libs.androidx.test.uiautomator)
    androidTestUtil(libs.androidx.test.orchestrator)
}
