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
        return ShellContext(context)
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
        val helper = CaptureHelperService(context)
        val extras = Bundle().apply { putBinder(BinderHandoffProvider.EXTRA_BINDER, helper.asBinder()) }
        val authority = args.firstOrNull() ?: "com.echoledger.mobile.handoff"
        val uri = Uri.parse("content://$authority")
        context.contentResolver.call(uri, BinderHandoffProvider.METHOD_ATTACH, null, extras)
        // Android may stop the app at any time; a restarted app has no binder until it is handed over again.
        thread(name = "el-helper-handoff", isDaemon = true) {
            while (true) {
                Thread.sleep(HANDOFF_EVERY_MS)
                runCatching { context.contentResolver.call(uri, BinderHandoffProvider.METHOD_ATTACH, null, extras) }
            }
        }
        Looper.loop() // keep serving binder calls until destroy()
    }
}
