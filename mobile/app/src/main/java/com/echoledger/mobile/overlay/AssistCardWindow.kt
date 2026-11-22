package com.echoledger.mobile.overlay

import android.content.Context
import android.graphics.PixelFormat
import android.provider.Settings
import android.view.Gravity
import android.view.WindowManager
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.widthIn
import androidx.compose.runtime.collectAsState
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.ComposeView
import androidx.compose.ui.unit.dp
import androidx.lifecycle.setViewTreeLifecycleOwner
import androidx.savedstate.setViewTreeSavedStateRegistryOwner
import com.echoledger.mobile.assist.AssistHub
import com.echoledger.mobile.core.ShareGuard
import com.echoledger.mobile.ui.v2.assist.AssistAdapter
import com.echoledger.mobile.ui.v2.assist.V2AssistHost
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.collectLatest
import kotlinx.coroutines.launch

/**
 * The Live Assist card over other apps while this phone's session runs. It reaches the screen only through
 * [PrivateWindows], so it carries FLAG_SECURE (hidden in a screen share or a screenshot) like the other floating windows,
 * and while EchoLedger's text is hidden it is the grey dot. Text only; it never reads another app's screen.
 */
internal object AssistCardWindow {
    private var view: ComposeView? = null
    private var owner: OverlayOwner? = null
    private var windowManager: WindowManager? = null

    /** The adapter on screen (tests). */
    var showing: AssistAdapter? = null
        private set

    /**
     * Shows the card whenever this phone's session starts and takes it away a moment after it ends; runs for the
     * process ([scope] on the main thread).
     */
    fun follow(context: Context, scope: CoroutineScope) {
        val app = context.applicationContext
        scope.launch(Dispatchers.Main) {
            AssistHub.active.collectLatest { assist ->
                if (assist != null) {
                    show(app, assist)
                } else if (showing != null) {
                    // The session ended: "Live Assist ended" stays a moment.
                    delay(ENDED_VISIBLE_MS)
                    hide()
                }
            }
        }
    }

    /** Shows the card for [adapter]; false without the overlay permission (the session goes on, the app shows it). */
    fun show(context: Context, adapter: AssistAdapter): Boolean {
        if (showing === adapter && view != null) return true
        hide()
        val app = context.applicationContext
        if (!Settings.canDrawOverlays(app)) return false
        val wm = app.getSystemService(WindowManager::class.java) ?: return false
        val lifecycleOwner = OverlayOwner().also { it.start() }
        val compose = ComposeView(app).apply {
            setViewTreeLifecycleOwner(lifecycleOwner)
            setViewTreeSavedStateRegistryOwner(lifecycleOwner)
            setContent {
                if (!ShareGuard.textVisible.collectAsState().value) {
                    PrivateDot(onShow = { ShareGuard.setHidden(false) })
                    return@setContent
                }
                com.echoledger.mobile.ui.v2.shell.OverlayLook(com.echoledger.mobile.ui.v2.shell.overlaySettings(app)) {
                    V2AssistHost(adapter, Modifier.widthIn(max = MAX_WIDTH_DP.dp).padding(horizontal = 12.dp))
                }
            }
        }
        val params = WindowManager.LayoutParams(
            WindowManager.LayoutParams.WRAP_CONTENT,
            WindowManager.LayoutParams.WRAP_CONTENT,
            WindowManager.LayoutParams.TYPE_APPLICATION_OVERLAY,
            FloatingWindow.ASSIST_CARD.flags,
            PixelFormat.TRANSLUCENT
        ).apply {
            gravity = Gravity.TOP or Gravity.CENTER_HORIZONTAL
            y = (TOP_MARGIN_DP * app.resources.displayMetrics.density).toInt()
            title = FloatingWindow.ASSIST_CARD.title
        }
        if (!PrivateWindows.add(wm, compose, params)) {
            lifecycleOwner.stop()
            return false
        }
        view = compose
        owner = lifecycleOwner
        windowManager = wm
        showing = adapter
        return true
    }

    /** Removes the card; the session itself goes on until it is ended. */
    fun hide() {
        view?.let { v -> windowManager?.let { PrivateWindows.remove(it, v) } }
        owner?.stop()
        view = null
        owner = null
        windowManager = null
        showing = null
    }

    /** How long "Live Assist ended" stays before the card goes. */
    const val ENDED_VISIBLE_MS = 4_000L
    private const val TOP_MARGIN_DP = 48
    private const val MAX_WIDTH_DP = 420
}
