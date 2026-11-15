package com.echoledger.mobile.overlay

import android.content.Context
import android.content.pm.PackageManager
import android.os.Build
import android.view.WindowManager
import androidx.annotation.RequiresApi
import com.echoledger.mobile.EchoLedgerApp
import com.echoledger.mobile.core.ShareGuard
import java.util.function.Consumer
import kotlinx.coroutines.Job
import kotlinx.coroutines.flow.distinctUntilChanged
import kotlinx.coroutines.flow.map
import kotlinx.coroutines.launch

/**
 * Listens, while any EchoLedger card is on screen, for Android saying our windows are visible in a screen share or a
 * screen recording (Android 15+, the public `addScreenRecordingCallback` with DETECT_SCREEN_RECORDING), and passes it to
 * [ShareGuard]. Earlier versions report nothing, so the Hide button and the Quick Settings tile are the way there.
 */
object ShareWatch {
    private var holders = 0
    private var callback: Any? = null
    private var following: Job? = null

    /** A card was attached. The first one starts listening, and follows Settings → During screen sharing. */
    fun acquire(context: Context) {
        holders++
        if (holders > 1) return
        following = (context.applicationContext as? EchoLedgerApp)?.graph?.let { graph ->
            graph.scope.launch {
                runCatching {
                    graph.settings.settings.map { it.screenShareMode }.distinctUntilChanged().collect {
                        ShareGuard.setMode(ShareGuard.Mode.of(it))
                    }
                }
            }
        }
        if (Build.VERSION.SDK_INT < 35) return
        if (context.checkSelfPermission(PERMISSION) != PackageManager.PERMISSION_GRANTED) return
        runCatching { start(context.applicationContext) }
    }

    /** A card was removed. The last one stops listening, and the cards count as not shared. */
    fun release(context: Context) {
        if (holders == 0) return
        holders--
        if (holders > 0) return
        following?.cancel()
        following = null
        if (Build.VERSION.SDK_INT >= 35) runCatching { stop(context.applicationContext) }
        ShareGuard.setShared(false)
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
