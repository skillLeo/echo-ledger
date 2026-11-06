package com.echoledger.mobile.capture

import android.annotation.SuppressLint
import android.content.AttributionSource
import android.content.Context
import android.content.ContextWrapper
import android.net.Uri
import android.os.Bundle
import android.os.Looper
import android.os.Process
import kotlin.concurrent.thread
import org.lsposed.hiddenapibypass.HiddenApiBypass

/** Builds a Context inside a bare `app_process` (no Application) — docs/research/android-capture.md §2c. */
internal object HelperContext {
    @SuppressLint("PrivateApi", "DiscouragedPrivateApi")
    @Suppress("DEPRECATION")
    fun system(): Context {
        runCatching { HiddenApiBypass.addHiddenApiExemptions("L") }
        if (Looper.myLooper() == null) runCatching { Looper.prepareMainLooper() }
        val activityThread = Class.forName("android.app.ActivityThread")
        val thread = activityThread.getMethod("systemMain").invoke(null)
        val context = activityThread.getMethod("getSystemContext").invoke(thread) as Context
        return shell(context)
    }

    /**
     * A context that is the shell package (UID 2000) through and through: Shizuku hands the helper a context of this
     * app's package, and the system context is package "android"; neither belongs to UID 2000, so the audio server
     * refused every recorder ("Cannot create AudioRecord"). The shell's own package context also makes the system
     * services (AudioManager for the far-side policy) name the shell.
     */
    fun shell(base: Context): Context {
        if (base is ShellContext) return base
        val own = runCatching { base.createPackageContext(SHELL, 0) }.getOrNull()
        return ShellContext(own ?: base)
    }

    /**
     * Identify as the shell package so content/appops checks match UID 2000. Since Android 12 the audio server checks
     * the context's attribution source, not the package name: the system context's one names package "android", which
     * does not belong to UID 2000, so recording was refused or muted. It now names the shell package.
     */
    private class ShellContext(base: Context) : ContextWrapper(base) {
        override fun getPackageName(): String = SHELL

        override fun getOpPackageName(): String = SHELL

        @androidx.annotation.RequiresApi(31)
        override fun getAttributionSource(): AttributionSource = AttributionSource.Builder(Process.myUid()).setPackageName(SHELL).build()
    }

    private const val SHELL = "com.android.shell"
}

/**
 * ADB command mode entry point:
 * `adb shell "CLASSPATH=$(pm path com.echoledger.mobile | cut -d: -f2) app_process /system/bin com.echoledger.mobile.capture.HelperMain"`
 * Starts the helper as the shell user and hands its binder to the app through [BinderHandoffProvider] (UID-checked).
 * VERIFY ON DEVICE (ContentResolver attribution from app_process differs across OEM builds).
 */
object HelperMain {
    private const val HANDOFF_EVERY_MS = 30_000L

    @JvmStatic
    fun main(args: Array<String>) {
        val context = HelperContext.system()
        if (args.firstOrNull() == "--self-test") {
            // `adb shell "CLASSPATH=… app_process /system/bin com.echoledger.mobile.capture.HelperMain --self-test"`:
            // proves the far-side path without a call and prints one line per recorder identity.
            val far = FarPartyCapture(context.getSystemService(android.content.Context.AUDIO_SERVICE) as android.media.AudioManager)
            println("sink as shell: ${far.selfTest(context)}")
            println("sink as process: ${far.selfTest(null, context)}")
            far.disarm()
            System.exit(0)
        }
        val helper = CaptureHelperService(context)
        val extras = Bundle().apply { putBinder(BinderHandoffProvider.EXTRA_BINDER, helper.asBinder()) }
        val authority = args.firstOrNull() ?: "com.echoledger.mobile.handoff"
        val uri = Uri.parse("content://$authority")
        val handOff = {
            if (!ShellProviderCall.call(authority, BinderHandoffProvider.METHOD_ATTACH, extras)) {
                context.contentResolver.call(uri, BinderHandoffProvider.METHOD_ATTACH, null, extras)
            }
        }
        // A refused hand-off is said and tried again below, never a crash that takes the helper with it (since v1.5).
        runCatching { handOff() }.onFailure { println("hand-off refused: ${it.message}") }
        // Android may stop the app at any time; a restarted app has no binder until it is handed over again.
        thread(name = "el-helper-handoff", isDaemon = true) {
            while (true) {
                Thread.sleep(HANDOFF_EVERY_MS)
                runCatching { handOff() }
            }
        }
        Looper.loop() // keep serving binder calls until destroy()
    }
}

/**
 * Calls a content provider as the shell package without a ContentResolver. From `app_process` the resolver names the
 * system context's package "android", and Android 17 refuses that for UID 2000 ("Given calling package android does
 * not match caller's uid 2000"). This asks the activity manager for the provider and calls it with an attribution
 * source naming com.android.shell (what scrcpy does). Returns false when the hidden API is not there, so the caller
 * falls back to the resolver.
 */
internal object ShellProviderCall {
    private const val SHELL_UID = 2000
    private const val SHELL = "com.android.shell"

    @SuppressLint("PrivateApi", "DiscouragedPrivateApi")
    fun call(authority: String, method: String, extras: Bundle): Boolean {
        if (android.os.Build.VERSION.SDK_INT < 31) return false
        val token = android.os.Binder()
        val am =
            runCatching { Class.forName("android.app.ActivityManager").getMethod("getService").invoke(null) }.getOrNull() ?: return false
        return runCatching {
            val holder = am.javaClass
                .getMethod(
                    "getContentProviderExternal",
                    String::class.java,
                    Int::class.javaPrimitiveType,
                    android.os.IBinder::class.java,
                    String::class.java
                )
                .invoke(am, authority, 0, token, null) ?: return false
            try {
                val provider = holder.javaClass.getField("provider").get(holder) ?: return false
                val source = AttributionSource.Builder(SHELL_UID).setPackageName(SHELL).build()
                provider.javaClass
                    .getMethod(
                        "call",
                        AttributionSource::class.java,
                        String::class.java,
                        String::class.java,
                        String::class.java,
                        Bundle::class.java
                    )
                    .invoke(provider, source, authority, method, null, extras)
                true
            } finally {
                runCatching {
                    am.javaClass.getMethod(
                        "removeContentProviderExternal",
                        String::class.java,
                        android.os.IBinder::class.java
                    ).invoke(am, authority, token)
                }
            }
        }.getOrDefault(false)
    }
}
