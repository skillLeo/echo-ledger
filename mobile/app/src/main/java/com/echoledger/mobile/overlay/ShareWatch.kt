package com.echoledger.mobile.overlay

import android.content.Context
import android.content.pm.PackageManager
import android.os.Build
import android.view.WindowManager
import androidx.annotation.RequiresApi
import com.echoledger.mobile.EchoLedgerApp
import com.echoledger.mobile.core.ShareGuard
import java.util.function.Consumer
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.flow.distinctUntilChanged
import kotlinx.coroutines.flow.map
import kotlinx.coroutines.launch

/**
 * Keeps [ShareGuard] up to date for the floating windows ([PrivateWindows] holds it while each one is open):
 * - Settings → During screen sharing and Block screenshots of EchoLedger, followed on the main thread from the first
 *   window on, so later windows start with the right secure flag;
 * - on Android 15+, while a window is open, the public `addScreenRecordingCallback` (DETECT_SCREEN_RECORDING),
 *   registered for the app's uid from the application context, so it lives while only floating windows are open. Android
 *   reports the uid as recorded only while one of its activities is visible in the recorded display or task, never for
 *   an overlay window alone (AOSP ScreenRecordingCallbackController.uidHasRecordedActivity walks forAllActivities;
 *   measured on the S25, docs/SCREEN_SHARE_MATRIX.md). The windows then shrink to a grey dot in Private, and show again
 *   when it ends. The black box (FLAG_SECURE in Private) is what keeps them out of a share over another app.
 */
object ShareWatch {
    private var holders = 0
    private var callback: Any? = null
    private var following: Job? = null

    /** A window opened. The first one starts listening for recordings. */
    fun acquire(context: Context) {
        follow(context)
        holders++
        if (holders > 1) return
        if (Build.VERSION.SDK_INT < 35) return
        if (context.checkSelfPermission(PERMISSION) != PackageManager.PERMISSION_GRANTED) return
        runCatching { start(context.applicationContext) }
    }

    /** A window closed. The last one stops listening, and the windows count as not in a screen share. */
    fun release(context: Context) {
        if (holders == 0) return
        holders--
        if (holders > 0) return
        if (Build.VERSION.SDK_INT >= 35) runCatching { stop(context.applicationContext) }
        ShareGuard.setShared(false)
    }

    /** Follows the setting for as long as the process lives, once started (a new graph in tests starts it again). */
    private fun follow(context: Context) {
        if (following?.isActive == true) return
        val graph = (context.applicationContext as? EchoLedgerApp)?.graph ?: return
        following = graph.scope.launch(Dispatchers.Main) {
            runCatching {
                graph.settings.settings.map { it.screenShareMode to it.blockScreens }.distinctUntilChanged().collect { (mode, block) ->
                    ShareGuard.setMode(ShareGuard.Mode.of(mode))
                    ShareGuard.setBlock(block)
                }
            }
        }
    }

    /** Where the screen-recording callback is registered: Android's window manager (tests put their own). */
    internal interface Registrar {
        /** Registers [callback]; returns the state now. */
        fun add(context: Context, callback: Consumer<Int>): Int

        fun remove(context: Context, callback: Consumer<Int>)
    }

    @RequiresApi(35)
    private object SystemRegistrar : Registrar {
        override fun add(context: Context, callback: Consumer<Int>): Int =
            context.getSystemService(WindowManager::class.java).addScreenRecordingCallback(context.mainExecutor, callback)

        override fun remove(context: Context, callback: Consumer<Int>) =
            context.getSystemService(WindowManager::class.java).removeScreenRecordingCallback(callback)
    }

    @androidx.annotation.VisibleForTesting
    internal var registrar: Registrar? = null

    /** What Android says: our windows are in a screen share or recording now (VISIBLE), or no longer. */
    internal fun onRecordingState(state: Int) = ShareGuard.setShared(state == WindowManager.SCREEN_RECORDING_STATE_VISIBLE)

    @RequiresApi(35)
    private fun start(context: Context) {
        val cb = Consumer<Int> { state -> onRecordingState(state) }
        val initial = (registrar ?: SystemRegistrar).add(context, cb)
        callback = cb
        onRecordingState(initial)
    }

    @RequiresApi(35)
    @Suppress("UNCHECKED_CAST")
    private fun stop(context: Context) {
        val cb = callback as? Consumer<Int> ?: return
        (registrar ?: SystemRegistrar).remove(context, cb)
        callback = null
    }

    const val PERMISSION = "android.permission.DETECT_SCREEN_RECORDING"
}
