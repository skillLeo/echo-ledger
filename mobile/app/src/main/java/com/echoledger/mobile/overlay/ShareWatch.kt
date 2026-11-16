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
 * - Settings → During screen sharing, followed on the main thread from the first window on, so later windows start
 *   with the right secure flag;
 * - on Android 15+, while a window is open, the public `addScreenRecordingCallback` (DETECT_SCREEN_RECORDING): Android
 *   says our windows are in a screen share or recording, though only while one of EchoLedger's own screens is in front
 *   (measured on the S25). The windows then shrink to a grey dot in Private; the secure flag never waits for it.
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
                graph.settings.settings.map { it.screenShareMode }.distinctUntilChanged().collect {
                    ShareGuard.setMode(ShareGuard.Mode.of(it))
                }
            }
        }
    }

    @RequiresApi(35)
    private fun start(context: Context) {
        val wm = context.getSystemService(WindowManager::class.java)
        val cb = Consumer<Int> { state -> ShareGuard.setShared(state == WindowManager.SCREEN_RECORDING_STATE_VISIBLE) }
        val initial = wm.addScreenRecordingCallback(context.mainExecutor, cb)
        callback = cb
        ShareGuard.setShared(initial == WindowManager.SCREEN_RECORDING_STATE_VISIBLE)
    }

    @RequiresApi(35)
    @Suppress("UNCHECKED_CAST")
    private fun stop(context: Context) {
        val cb = callback as? Consumer<Int> ?: return
        context.getSystemService(WindowManager::class.java).removeScreenRecordingCallback(cb)
        callback = null
    }

    const val PERMISSION = "android.permission.DETECT_SCREEN_RECORDING"
}
