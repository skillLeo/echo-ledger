package com.echoledger.mobile.overlay

import android.content.Context
import android.graphics.PixelFormat
import android.os.Handler
import android.os.Looper
import android.view.Gravity
import android.view.WindowManager
import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Button
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.shadow
import androidx.compose.ui.platform.ComposeView
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.setViewTreeLifecycleOwner
import androidx.savedstate.setViewTreeSavedStateRegistryOwner
import com.echoledger.mobile.EchoLedgerApp
import com.echoledger.mobile.calls.DecisionTimeoutWorker
import com.echoledger.mobile.calls.Decisions
import com.echoledger.mobile.core.Languages
import com.echoledger.mobile.core.SourceApp
import com.echoledger.mobile.core.SourceDevice
import com.echoledger.mobile.data.LocalRecording
import com.echoledger.mobile.ui.Brand
import com.echoledger.mobile.ui.EchoLedgerTheme
import com.echoledger.mobile.ui.LocalEchoColors
import com.echoledger.mobile.ui.LocalEchoType
import com.echoledger.mobile.ui.Radius
import com.echoledger.mobile.ui.SourceAvatar
import com.echoledger.mobile.ui.Space
import com.echoledger.mobile.ui.design.ButtonKind
import com.echoledger.mobile.ui.design.EchoButton
import com.echoledger.mobile.ui.design.SourceAppTile
import com.echoledger.mobile.ui.design.tabular
import com.echoledger.mobile.ui.formatDuration
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch

/**
 * The small "Save this call recording?" card shown at the bottom of the screen when a WhatsApp call recording ends
 * (SYSTEM_ALERT_WINDOW). It never takes focus and does not block the screen around it, belongs to the app process and
 * not to the call screen, so it survives WhatsApp closing. After [AUTO_HIDE_MS] it only goes away: the recording keeps
 * waiting in the Record tab, nothing is uploaded and nothing is transcribed. VERIFY ON DEVICE (OEM overlay policies vary).
 */
object DecisionPopup {
    const val AUTO_HIDE_MS = 10_000L

    private val handler = Handler(Looper.getMainLooper())
    private var view: ComposeView? = null
    private var owner: OverlayOwner? = null
    private var windowManager: WindowManager? = null
    private val hideTask = Runnable { hide() }

    /** The id of the recording the card is asking about; null when no card is showing. */
    var showingFor: String? = null
        private set

    val isShowing: Boolean get() = view != null

    /** Shows the card; false when it could not be added (the caller then posts the notification). */
    fun show(context: Context, rec: LocalRecording, themeMode: String, afterCallTimeout: String = "wait"): Boolean {
        hide()
        val app = context.applicationContext
        val wm = app.getSystemService(WindowManager::class.java) ?: return false
        val lifecycleOwner = OverlayOwner().also { it.start() }
        val metrics = app.resources.displayMetrics
        val width = metrics.widthPixels - (2 * SIDE_MARGIN_DP * metrics.density).toInt()
        val language = mutableStateOf(rec.language)
        val compose = ComposeView(app).apply {
            setViewTreeLifecycleOwner(lifecycleOwner)
            setViewTreeSavedStateRegistryOwner(lifecycleOwner)
            setContent {
                EchoLedgerTheme(themeMode) {
                    DecisionCard(
                        sourceApp = rec.sourceApp,
                        durationMs = rec.durationMs,
                        onKeep = { decide(app, rec.id, keep = true) },
                        onDiscard = { decide(app, rec.id, keep = false) },
                        language = language.value,
                        autoDecision = afterCallTimeout.takeIf { it == "keep" || it == "discard" },
                        onLanguage = {
                            // The tap is a sign the user is deciding: the card stays its full time again.
                            language.value = Languages.next(language.value)
                            handler.removeCallbacks(hideTask)
                            handler.postDelayed(hideTask, AUTO_HIDE_MS)
                            val chosen = language.value
                            CoroutineScope(Dispatchers.IO).launch {
                                (app as EchoLedgerApp).graph.recordings.update(rec.id) { it.copy(language = chosen) }
                            }
                        }
                    )
                }
            }
        }
        val params = WindowManager.LayoutParams(
            width,
            WindowManager.LayoutParams.WRAP_CONTENT,
            WindowManager.LayoutParams.TYPE_APPLICATION_OVERLAY,
            // Not focusable and not modal: touches outside the card go to the app underneath.
            WindowManager.LayoutParams.FLAG_NOT_FOCUSABLE or WindowManager.LayoutParams.FLAG_NOT_TOUCH_MODAL or
                WindowManager.LayoutParams.FLAG_LAYOUT_IN_SCREEN,
            PixelFormat.TRANSLUCENT
        ).apply {
            gravity = Gravity.BOTTOM or Gravity.CENTER_HORIZONTAL
            y = (BOTTOM_MARGIN_DP * app.resources.displayMetrics.density).toInt()
        }
        val added = runCatching { wm.addView(compose, params) }.isSuccess
        if (!added) {
            lifecycleOwner.stop()
            return false
        }
        view = compose
        owner = lifecycleOwner
        windowManager = wm
        showingFor = rec.id
        handler.postDelayed(hideTask, AUTO_HIDE_MS)
        return true
    }

    /** Removes the card. Never decides anything. */
    fun hide() {
        handler.removeCallbacks(hideTask)
        view?.let { v -> runCatching { windowManager?.removeView(v) } }
        owner?.stop()
        view = null
        owner = null
        windowManager = null
        showingFor = null
    }

    private fun decide(context: Context, id: String, keep: Boolean) {
        hide()
        CoroutineScope(Dispatchers.IO).launch { Decisions.apply(context, id, keep) }
    }

    private const val SIDE_MARGIN_DP = 16
    private const val BOTTOM_MARGIN_DP = 72
}

/**
 * The card itself (since v1.3 design): the app's icon, the question, "WhatsApp · 4:32" with the language chip (a tap
 * steps to the next language, and Keep transcribes in it), then Discard and Keep (primary). [autoDecision] ("keep" or
 * "discard") says what the after-call setting will do if nobody chooses; the thin line shows how long the card stays.
 */
@Composable
fun DecisionCard(
    sourceApp: String,
    durationMs: Long,
    onKeep: () -> Unit,
    onDiscard: () -> Unit,
    language: String? = null,
    onLanguage: (() -> Unit)? = null,
    autoDecision: String? = null,
    visibleMs: Long = DecisionPopup.AUTO_HIDE_MS
) {
    val app = SourceApp.from(sourceApp)
    val c = LocalEchoColors.current
    val type = LocalEchoType.current
    val shape = RoundedCornerShape(Radius.lg)
    val left = remember { Animatable(1f) }
    LaunchedEffect(visibleMs) { left.animateTo(0f, tween(visibleMs.toInt(), easing = LinearEasing)) }
    Column(
        Modifier
            .fillMaxWidth()
            .shadow(if (c.isDark) 0.dp else 8.dp, shape)
            .clip(shape)
            .background(c.surface)
            .border(1.dp, c.border, shape)
            .semantics { contentDescription = "Save this call recording?" }
    ) {
        Column(Modifier.padding(Space.s4), verticalArrangement = Arrangement.spacedBy(Space.s3)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                SourceAppTile(app, size = 40.dp)
                Column(Modifier.weight(1f).padding(start = Space.s3)) {
                    Text("Save this call recording?", style = type.bodyL, color = c.text, maxLines = 1)
                    Text("${app.label} · ${formatDuration(durationMs)}", style = type.labelM.tabular(), color = c.textMuted, maxLines = 1)
                }
                if (onLanguage != null) {
                    Text(
                        Languages.chip(language),
                        style = type.labelM,
                        color = c.text,
                        maxLines = 1,
                        modifier = Modifier
                            .clip(RoundedCornerShape(Radius.sm))
                            .border(1.dp, c.borderStrong, RoundedCornerShape(Radius.sm))
                            .clickable(role = Role.Button, onClickLabel = "Change the language") { onLanguage() }
                            .semantics { contentDescription = "Language: ${Languages.label(language)}" }
                            .padding(horizontal = Space.s3, vertical = Space.s1 + Space.s0)
                    )
                }
            }
            autoDecision?.let {
                val what = if (it == "keep") "Kept" else "Discarded"
                Text(
                    "$what automatically after ${DecisionTimeoutWorker.WINDOW_SECONDS} s if you don't choose.",
                    style = type.caption,
                    color = c.textMuted
                )
            }
            Row(horizontalArrangement = Arrangement.spacedBy(Space.s3)) {
                EchoButton("Discard", onDiscard, Modifier.weight(1f), kind = ButtonKind.Secondary)
                EchoButton("Keep", onKeep, Modifier.weight(1f))
            }
        }
        Box(Modifier.fillMaxWidth().height(2.dp).background(c.surface2)) {
            Box(Modifier.fillMaxWidth(left.value).height(2.dp).background(c.accentStrong))
        }
    }
}
