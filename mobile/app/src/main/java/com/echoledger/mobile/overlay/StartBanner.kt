package com.echoledger.mobile.overlay

import android.content.Context
import android.graphics.PixelFormat
import android.os.Build
import android.os.Handler
import android.os.Looper
import android.provider.Settings
import android.view.Gravity
import android.view.WindowInsets
import android.view.WindowManager
import androidx.compose.foundation.background
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.ComposeView
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import androidx.lifecycle.setViewTreeLifecycleOwner
import androidx.savedstate.setViewTreeSavedStateRegistryOwner
import com.echoledger.mobile.R
import com.echoledger.mobile.calls.CallChoice
import com.echoledger.mobile.core.ShareGuard
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.ui.v2.components.EchoButton
import com.echoledger.mobile.ui.v2.components.EchoButtonKind
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.tokens.EchoTokens

/**
 * "Recording this call · [Don't record]" for 5 s at the top of the screen when a call connects, with After each call:
 * Ask me and When to ask: At the start of the call (the call-choice plan, 2.0.5). Doing nothing records the call. It
 * shows only once the call has connected ([due]), never while it rings, and sits just under the status bar, far from
 * the call screen's answer and hang-up buttons at the bottom. No sound, no vibration; never focused.
 */
object StartBanner {
    const val SHOW_MS = 5_000L

    private val handler = Handler(Looper.getMainLooper())
    private var view: ComposeView? = null
    private var owner: OverlayOwner? = null
    private var windowManager: WindowManager? = null
    private var timeout: Runnable? = null

    val isShowing: Boolean get() = view != null

    /** The banner is due for this recording: an automatic call recording, connected (answered), and not shown yet. */
    fun due(settings: AppSettings, fromCall: Boolean, connected: Boolean, shown: Boolean): Boolean =
        fromCall && connected && !shown && CallChoice.asksAtStart(settings)

    /** Shows the banner; [onDontRecord] runs on Don't record. False without the overlay permission. */
    fun show(context: Context, themeMode: String, onDontRecord: () -> Unit): Boolean {
        hide()
        val app = context.applicationContext
        if (!Settings.canDrawOverlays(app)) return false
        val wm = app.getSystemService(WindowManager::class.java) ?: return false
        val lifecycleOwner = OverlayOwner().also { it.start() }
        val compose = ComposeView(app).apply {
            isSoundEffectsEnabled = false
            isHapticFeedbackEnabled = false
            setViewTreeLifecycleOwner(lifecycleOwner)
            setViewTreeSavedStateRegistryOwner(lifecycleOwner)
            setContent {
                // It always shows, in screenshots and screen shares too; only the person's own Hide takes it away.
                if (ShareGuard.hiddenByUser.collectAsState().value) return@setContent
                val dark = when (themeMode) {
                    "light" -> false
                    "dark" -> true
                    else -> isSystemInDarkTheme()
                }
                com.echoledger.mobile.ui.v2.shell.OverlayLook(com.echoledger.mobile.ui.v2.shell.overlaySettings(app), dark = dark) {
                    StartBannerContent(
                        onDontRecord = {
                            hide()
                            onDontRecord()
                        },
                        modifier = Modifier.padding(horizontal = EchoTokens.Space.s16, vertical = EchoTokens.Space.s8)
                    )
                }
            }
        }
        // As wide as the banner: taps beside it reach the call screen, and it sits centred in landscape too.
        val params = WindowManager.LayoutParams(
            WindowManager.LayoutParams.WRAP_CONTENT,
            WindowManager.LayoutParams.WRAP_CONTENT,
            WindowManager.LayoutParams.TYPE_APPLICATION_OVERLAY,
            FloatingWindow.START_BANNER.flags,
            PixelFormat.TRANSLUCENT
        ).apply {
            gravity = Gravity.TOP or Gravity.CENTER_HORIZONTAL
            y = topInsetPx(app, wm)
            title = FloatingWindow.START_BANNER.title
        }
        if (!PrivateWindows.add(wm, compose, params)) {
            lifecycleOwner.stop()
            return false
        }
        view = compose
        owner = lifecycleOwner
        windowManager = wm
        val task = Runnable { hide() }
        timeout = task
        handler.postDelayed(task, SHOW_MS)
        return true
    }

    /** The status bar and any cutout above the banner, in pixels. */
    internal fun topInsetPx(context: Context, wm: WindowManager): Int {
        if (Build.VERSION.SDK_INT >= 30) {
            val insets = wm.currentWindowMetrics.windowInsets
                .getInsetsIgnoringVisibility(WindowInsets.Type.statusBars() or WindowInsets.Type.displayCutout())
            if (insets.top > 0) return insets.top
        }
        val id = context.resources.getIdentifier("status_bar_height", "dimen", "android")
        return if (id != 0) context.resources.getDimensionPixelSize(id) else 0
    }

    fun hide() {
        timeout?.let(handler::removeCallbacks)
        timeout = null
        view?.let { v -> windowManager?.let { PrivateWindows.remove(it, v) } }
        owner?.stop()
        view = null
        owner = null
        windowManager = null
    }
}

/** The banner itself: a red dot, "Recording this call", and Don't record; a solid surface with radius 16. */
@Composable
internal fun StartBannerContent(onDontRecord: () -> Unit, modifier: Modifier = Modifier) {
    val c = EchoV2.colors
    Surface(
        shape = RoundedCornerShape(EchoTokens.Radius.sheet),
        color = c.bgOverlay.copy(alpha = 1f),
        contentColor = c.textPrimary,
        border = androidx.compose.foundation.BorderStroke(EchoTokens.Border.hairline, c.borderDefault),
        shadowElevation = EchoTokens.Space.s4,
        modifier = modifier.widthIn(max = EchoTokens.Space.s64 * 7).fillMaxWidth().semantics { liveRegion = LiveRegionMode.Polite }
    ) {
        Row(
            Modifier.padding(
                start = EchoTokens.Space.s16,
                end = EchoTokens.Space.s4,
                top = EchoTokens.Space.s4,
                bottom = EchoTokens.Space.s4
            ),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)
        ) {
            Box(Modifier.size(EchoTokens.Space.s8).background(c.record, CircleShape))
            Text(stringResource(R.string.cc_start_banner), style = EchoV2.type.bodyStrong, modifier = Modifier.weight(1f))
            EchoButton(stringResource(R.string.cc_dont_record), onDontRecord, kind = EchoButtonKind.Quiet)
        }
    }
}
