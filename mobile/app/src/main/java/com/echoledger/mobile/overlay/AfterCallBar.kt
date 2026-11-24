package com.echoledger.mobile.overlay

import android.content.Context
import android.content.Intent
import android.graphics.PixelFormat
import android.os.Handler
import android.os.Looper
import android.provider.Settings
import android.view.Gravity
import android.view.WindowManager
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.ComposeView
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.style.TextOverflow
import androidx.lifecycle.setViewTreeLifecycleOwner
import androidx.savedstate.setViewTreeSavedStateRegistryOwner
import com.echoledger.mobile.R
import com.echoledger.mobile.calls.CallChoice
import com.echoledger.mobile.calls.SaveDecisionReceiver
import com.echoledger.mobile.core.ShareGuard
import com.echoledger.mobile.core.SourceApp
import com.echoledger.mobile.data.LocalRecording
import com.echoledger.mobile.ui.design.SwipeAway
import com.echoledger.mobile.ui.v2.components.EchoButton
import com.echoledger.mobile.ui.v2.components.EchoButtonKind
import com.echoledger.mobile.ui.v2.components.EchoIconButton
import com.echoledger.mobile.ui.v2.components.EchoIcons
import com.echoledger.mobile.ui.v2.components.EchoSourceLogo
import com.echoledger.mobile.ui.v2.live.callCardTitle
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.tokens.EchoTokens

/**
 * The optional small bar after a call (v2.1 UX-13; Settings › After each call › "Show a small bar on screen", off by
 * default, needs the overlay permission): one 56 dp line under the status bar, 8 dp from the edges: the app's logo,
 * "Ali · 2:38 saved", [Transcribe] and a trash icon (Delete). A swipe left or right closes it, and it goes by itself
 * after 6 s; closing it decides nothing (the after-call notification is still in the shade). Never during Do Not
 * Disturb (no question is asked then). It always shows in screenshots and screen shares, like the pill and the card.
 */
object AfterCallBar {
    private val handler = Handler(Looper.getMainLooper())
    private var view: ComposeView? = null
    private var owner: OverlayOwner? = null
    private var windowManager: WindowManager? = null
    private var timeout: Runnable? = null

    /** The call the bar is about; null when it is not showing. */
    @Volatile
    var showingFor: String? = null
        private set

    val isShowing: Boolean get() = view != null

    /** Shows the bar for [rec] (main thread). False without the overlay permission. */
    fun show(context: Context, rec: LocalRecording, themeMode: String): Boolean {
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
                if (ShareGuard.hiddenByUser.collectAsState().value) return@setContent
                val dark = when (themeMode) {
                    "light" -> false
                    "dark" -> true
                    else -> isSystemInDarkTheme()
                }
                com.echoledger.mobile.ui.v2.shell.OverlayLook(com.echoledger.mobile.ui.v2.shell.overlaySettings(app), dark = dark) {
                    SwipeAway(onGone = ::hide) {
                        AfterCallBarContent(
                            rec,
                            onTranscribe = { answer(app, rec.id, SaveDecisionReceiver.ACTION_AC_TRANSCRIBE) },
                            onDelete = { answer(app, rec.id, SaveDecisionReceiver.ACTION_DISCARD) },
                            modifier = Modifier.padding(EchoTokens.Space.s8)
                        )
                    }
                }
            }
        }
        val params = WindowManager.LayoutParams(
            WindowManager.LayoutParams.MATCH_PARENT,
            WindowManager.LayoutParams.WRAP_CONTENT,
            WindowManager.LayoutParams.TYPE_APPLICATION_OVERLAY,
            FloatingWindow.AFTER_CALL_BAR.flags,
            PixelFormat.TRANSLUCENT
        ).apply {
            gravity = Gravity.TOP or Gravity.CENTER_HORIZONTAL
            y = StartBanner.topInsetPx(app, wm)
            title = FloatingWindow.AFTER_CALL_BAR.title
        }
        if (!PrivateWindows.add(wm, compose, params)) {
            lifecycleOwner.stop()
            return false
        }
        view = compose
        owner = lifecycleOwner
        windowManager = wm
        showingFor = rec.id
        val task = Runnable { hide() }
        timeout = task
        handler.postDelayed(task, CallChoice.BAR_MS)
        return true
    }

    /** The bar's buttons do what the notification's do (the same receiver), then the bar goes. */
    private fun answer(app: Context, id: String, action: String) {
        hide()
        app.sendBroadcast(Intent(app, SaveDecisionReceiver::class.java).setAction(action).putExtra(SaveDecisionReceiver.EXTRA_ID, id))
    }

    fun hide() {
        timeout?.let(handler::removeCallbacks)
        timeout = null
        view?.let { v -> windowManager?.let { PrivateWindows.remove(it, v) } }
        owner?.stop()
        view = null
        owner = null
        windowManager = null
        showingFor = null
    }
}

/** The bar itself: logo 24 dp · "Ali · 2:38 saved" · Transcribe · trash; 56 dp tall, a solid surface. */
@Composable
internal fun AfterCallBarContent(rec: LocalRecording, onTranscribe: () -> Unit, onDelete: () -> Unit, modifier: Modifier = Modifier) {
    val c = EchoV2.colors
    val res = androidx.compose.ui.platform.LocalContext.current.resources
    val length = com.echoledger.mobile.ui.formatDuration(rec.durationMs).ifEmpty { "0:00" }
    Surface(
        shape = RoundedCornerShape(EchoTokens.Radius.sheet),
        color = c.bgOverlay.copy(alpha = 1f),
        contentColor = c.textPrimary,
        border = androidx.compose.foundation.BorderStroke(EchoTokens.Border.hairline, c.borderDefault),
        shadowElevation = EchoTokens.Space.s4,
        modifier = modifier
            .widthIn(max = EchoTokens.Space.s64 * 7)
            .fillMaxWidth()
            .heightIn(min = EchoTokens.Space.s48 + EchoTokens.Space.s8)
            .semantics { liveRegion = LiveRegionMode.Polite }
    ) {
        val words = stringResource(R.string.cc_ac_bar_saved, callCardTitle(res, rec), length)
        val buttons: @Composable () -> Unit = {
            EchoButton(stringResource(R.string.cc_transcribe), onTranscribe, kind = EchoButtonKind.Quiet)
            EchoIconButton(EchoIcons.Delete, stringResource(R.string.cc_delete), onDelete)
        }
        // One line; at the largest text sizes the buttons go under the words, so the name is never cut.
        if (LocalDensity.current.fontScale >= LARGE_TEXT) {
            Column(Modifier.padding(start = EchoTokens.Space.s12, end = EchoTokens.Space.s4, top = EchoTokens.Space.s8)) {
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
                    EchoSourceLogo(SourceApp.from(rec.sourceApp), Modifier.size(EchoTokens.Space.s24))
                    Text(words, style = EchoV2.type.bodyStrong, modifier = Modifier.weight(1f))
                }
                Row(Modifier.align(Alignment.End), verticalAlignment = Alignment.CenterVertically) { buttons() }
            }
        } else {
            Row(
                Modifier.padding(start = EchoTokens.Space.s12, end = EchoTokens.Space.s4),
                verticalAlignment = Alignment.CenterVertically,
                horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)
            ) {
                EchoSourceLogo(SourceApp.from(rec.sourceApp), Modifier.size(EchoTokens.Space.s24))
                Text(
                    words,
                    style = EchoV2.type.bodyStrong,
                    maxLines = 2,
                    overflow = TextOverflow.Ellipsis,
                    modifier = Modifier.weight(1f).padding(vertical = EchoTokens.Space.s8)
                )
                buttons()
            }
        }
    }
}

/** From this font scale on (the largest text sizes) the bar's buttons go under its words. */
private const val LARGE_TEXT = 1.5f
