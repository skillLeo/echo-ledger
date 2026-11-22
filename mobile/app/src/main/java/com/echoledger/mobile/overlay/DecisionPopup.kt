package com.echoledger.mobile.overlay

import android.content.Context
import android.graphics.PixelFormat
import android.os.Handler
import android.os.Looper
import android.view.Gravity
import android.view.WindowManager
import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.Spring
import androidx.compose.animation.core.spring
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.Delete
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.platform.ComposeView
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.lifecycle.setViewTreeLifecycleOwner
import androidx.savedstate.setViewTreeSavedStateRegistryOwner
import com.echoledger.mobile.calls.Decisions
import com.echoledger.mobile.core.ShareGuard
import com.echoledger.mobile.core.SourceApp
import com.echoledger.mobile.data.LocalRecording
import com.echoledger.mobile.ui.EchoLedgerTheme
import com.echoledger.mobile.ui.LocalEchoType
import com.echoledger.mobile.ui.Motion
import com.echoledger.mobile.ui.Space
import com.echoledger.mobile.ui.design.SuccessCheck
import com.echoledger.mobile.ui.design.tabular
import com.echoledger.mobile.ui.formatDuration
import com.echoledger.mobile.ui.reduceMotion
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch

/**
 * The small chip after a call (since v1.5, replaces the v1.3 card), at the bottom of the screen over whatever app is
 * open (SYSTEM_ALERT_WINDOW). It never takes focus and leaves the screen around it alone.
 * - [Mode.SAVED] ("After a call: Keep automatically"): "Saved · Call with Omar · 4:12 · Undo" for [SAVED_MS]; the
 *   upload waits that long, so nothing has left the phone; since v1.8.1 its Undo is a Discard ("Discarded · Undo").
 * - [Mode.ASK] ("Ask me"): the title with ✓ Keep and a labelled Discard (a trash, never an "×" that reads as close);
 *   the title opens the app to rename it or pick a client; after [ASK_KEEP_MS] without a tap the call is kept.
 * - [Mode.DISCARDED] (since v1.8.1, bug P-003): after Discard, "Discarded · Call with Omar · 4:12 · Undo" for
 *   [DISCARDED_MS]. Nothing is deleted meanwhile ([Decisions.discardLater]); Undo puts the ✓ / Discard chip back.
 * VERIFY ON DEVICE (OEM overlay policies vary).
 */
object DecisionPopup {
    enum class Mode { SAVED, ASK, DISCARDED }

    const val SAVED_MS = 6_000L
    const val ASK_KEEP_MS = 10_000L
    const val DISCARDED_MS = 5_000L

    private val handler = Handler(Looper.getMainLooper())
    private var view: ComposeView? = null
    private var owner: OverlayOwner? = null
    private var windowManager: WindowManager? = null
    private var timeout: Runnable? = null

    /** The id of the recording the chip is about; null when no chip is showing. */
    var showingFor: String? = null
        private set

    var showingMode: Mode? = null
        private set

    val isShowing: Boolean get() = view != null

    /** "Call with Omar", else a phone call's number ("Call with +92 300 1234567"), else "WhatsApp call" / "Phone call". */
    fun title(rec: LocalRecording): String = rec.title?.takeIf { it.isNotBlank() }
        ?: rec.callerName?.takeIf { it.isNotBlank() }?.let { "Call with $it" }
        ?: rec.callerNumber?.takeIf { it.any(Char::isDigit) }?.let { "Call with $it" }
        ?: SourceApp.from(rec.sourceApp).let { if (it == SourceApp.PHONE_CALL) "Phone call" else "${it.label} call" }

    /** The chip on screen is the v2 after-call card (`ui_v2.after_call`). */
    var showingV2: Boolean = false
        private set

    /**
     * Shows the chip; false when it could not be added (the caller then relies on the notification). [v2]: the v2
     * after-call card (`ui_v2.after_call`, since 2.0.0-a) with the same decisions and the same delayed delete.
     */
    fun show(context: Context, rec: LocalRecording, themeMode: String, mode: Mode, v2: Boolean = false): Boolean {
        hide()
        val app = context.applicationContext
        val wm = app.getSystemService(WindowManager::class.java) ?: return false
        val lifecycleOwner = OverlayOwner().also { it.start() }
        val visibleMs = when (mode) {
            Mode.SAVED -> SAVED_MS
            Mode.ASK -> ASK_KEEP_MS
            Mode.DISCARDED -> DISCARDED_MS
        }
        val compose = ComposeView(app).apply {
            setViewTreeLifecycleOwner(lifecycleOwner)
            setViewTreeSavedStateRegistryOwner(lifecycleOwner)
            setContent {
                // Hidden by the person (Hide EchoLedger windows): a grey dot instead of the call's name; the timeout still
                // runs. The card itself always shows, in screenshots and screen shares too (2.0.5).
                if (ShareGuard.hiddenByUser.collectAsState().value) {
                    PrivateDot(onShow = { ShareGuard.setHidden(false) })
                    return@setContent
                }
                val openWaiting = {
                    hide()
                    app.startActivity(
                        android.content.Intent(app, com.echoledger.mobile.MainActivity::class.java)
                            .addFlags(android.content.Intent.FLAG_ACTIVITY_NEW_TASK or android.content.Intent.FLAG_ACTIVITY_CLEAR_TOP)
                            .putExtra(com.echoledger.mobile.MainActivity.EXTRA_OPEN, com.echoledger.mobile.MainActivity.OPEN_PENDING)
                    )
                }
                if (v2) {
                    val actions = v2Actions(app, rec, themeMode, mode, openWaiting)
                    com.echoledger.mobile.ui.v2.shell.OverlayLook(com.echoledger.mobile.ui.v2.shell.overlaySettings(app)) {
                        com.echoledger.mobile.ui.v2.live.V2AfterCallCard(
                            title = com.echoledger.mobile.ui.v2.live.afterCallTitle(app.resources, rec),
                            durationMs = rec.durationMs,
                            sourceWire = rec.sourceApp,
                            mode = v2Mode(mode),
                            visibleMs = visibleMs,
                            onSave = actions.onSave,
                            onDelete = actions.onDelete,
                            onUndo = actions.onUndo,
                            onTitle = actions.onTitle,
                            onTranscribe = actions.onTranscribe
                        )
                    }
                    return@setContent
                }
                EchoLedgerTheme(themeMode) {
                    AfterCallChip(
                        title = title(rec),
                        durationMs = rec.durationMs,
                        mode = mode,
                        visibleMs = visibleMs,
                        onKeep = { decide(app, rec.id, keep = true) },
                        onDiscard = { discard(app, rec, themeMode) },
                        // Undo on "Saved" is a Discard too (since v1.8.1): "Discarded · Undo" first, the file after it.
                        onUndo = { if (mode == Mode.DISCARDED) undoDiscard(app, rec.id, themeMode) else discard(app, rec, themeMode) },
                        onTitle = {
                            hide()
                            app.startActivity(
                                android.content.Intent(app, com.echoledger.mobile.MainActivity::class.java)
                                    .addFlags(
                                        android.content.Intent.FLAG_ACTIVITY_NEW_TASK or android.content.Intent.FLAG_ACTIVITY_CLEAR_TOP
                                    )
                                    .putExtra(
                                        com.echoledger.mobile.MainActivity.EXTRA_OPEN,
                                        com.echoledger.mobile.MainActivity.OPEN_PENDING
                                    )
                            )
                        }
                    )
                }
            }
        }
        val metrics = app.resources.displayMetrics
        val params = WindowManager.LayoutParams(
            WindowManager.LayoutParams.WRAP_CONTENT,
            WindowManager.LayoutParams.WRAP_CONTENT,
            WindowManager.LayoutParams.TYPE_APPLICATION_OVERLAY,
            // Not focusable and not modal: touches outside the card go to the app underneath. PrivateWindows never
            // gives the after-call card FLAG_SECURE: it shows in screenshots and screen shares.
            FloatingWindow.AFTER_CALL_CARD.flags,
            PixelFormat.TRANSLUCENT
        ).apply {
            gravity = Gravity.BOTTOM or Gravity.CENTER_HORIZONTAL
            y = (BOTTOM_MARGIN_DP * metrics.density).toInt()
            title = FloatingWindow.AFTER_CALL_CARD.title
        }
        if (!PrivateWindows.add(wm, compose, params)) {
            lifecycleOwner.stop()
            return false
        }
        view = compose
        owner = lifecycleOwner
        windowManager = wm
        showingFor = rec.id
        showingMode = mode
        showingV2 = v2
        // Saved: the chip only goes (the call is kept already); Discarded too (the file goes a little later). Ask: nobody
        // chose, so the call is kept.
        val task = Runnable { if (mode == Mode.ASK) decide(app, rec.id, keep = true) else hide() }
        timeout = task
        handler.postDelayed(task, visibleMs)
        return true
    }

    /** Removes the chip. Never decides anything. */
    fun hide() {
        timeout?.let(handler::removeCallbacks)
        timeout = null
        view?.let { v -> windowManager?.let { PrivateWindows.remove(it, v) } }
        owner?.stop()
        view = null
        owner = null
        windowManager = null
        showingFor = null
        showingMode = null
        showingV2 = false
    }

    private fun decide(context: Context, id: String, keep: Boolean, transcribe: Boolean? = null) {
        hide()
        CoroutineScope(Dispatchers.IO).launch { Decisions.apply(context, id, keep, transcribe) }
    }

    /** The chip's Discard: "Discarded · … · Undo" at once; the file goes only when that is over. */
    internal fun discard(context: Context, rec: LocalRecording, themeMode: String, v2: Boolean = false) {
        show(context, rec, themeMode, Mode.DISCARDED, v2)
        CoroutineScope(Dispatchers.IO).launch {
            // Decided meanwhile (a notification, the app): nothing was discarded, so the chip does not say so.
            if (Decisions.discardLater(context, rec.id) == null) {
                handler.post { if (showingFor == rec.id && showingMode == Mode.DISCARDED) hide() }
            }
        }
    }

    /**
     * Undo on "Discarded": the call is back on its chip as after the call: asked again (kept by itself after 10 s), or
     * "Saved" again for a call that was kept.
     */
    internal fun undoDiscard(context: Context, id: String, themeMode: String, v2: Boolean = false) {
        hide()
        CoroutineScope(Dispatchers.IO).launch {
            val back = Decisions.undoDiscard(context, id) ?: return@launch
            val flags = if (v2) {
                com.echoledger.mobile.ui.v2.shell.UiFlags(
                    mapOf(com.echoledger.mobile.ui.v2.shell.UiFlags.AFTER_CALL to true)
                )
            } else {
                null
            }
            handler.post {
                com.echoledger.mobile.calls.PostCallPrompt.again(
                    context,
                    back,
                    themeMode,
                    flags ?: com.echoledger.mobile.ui.v2.shell.UiFlags.Off
                )
            }
        }
    }

    /** What the v2 card's buttons do: the chip's own decisions, Delete through the delayed discard. */
    internal class CardActions(
        val onSave: () -> Unit,
        val onTranscribe: () -> Unit,
        val onDelete: () -> Unit,
        val onUndo: () -> Unit,
        val onTitle: () -> Unit
    )

    internal fun v2Actions(app: Context, rec: LocalRecording, themeMode: String, mode: Mode, onTitle: () -> Unit) = CardActions(
        // Save audio (2.0.0-j): kept as audio only, transcribed later from the Library; Transcribe: kept and transcribed.
        onSave = { decide(app, rec.id, keep = true, transcribe = false) },
        onTranscribe = { decide(app, rec.id, keep = true, transcribe = true) },
        // Delete is the delayed discard: "Deleted · Undo", the file only after that.
        onDelete = { discard(app, rec, themeMode, v2 = true) },
        onUndo = {
            if (mode ==
                Mode.DISCARDED
            ) {
                undoDiscard(app, rec.id, themeMode, v2 = true)
            } else {
                discard(app, rec, themeMode, v2 = true)
            }
        },
        onTitle = onTitle
    )

    /** The v2 card's mode for the chip's: Ask, Saved, and Discarded as "Deleted". */
    internal fun v2Mode(mode: Mode): com.echoledger.mobile.ui.v2.live.AfterCallMode = when (mode) {
        Mode.ASK -> com.echoledger.mobile.ui.v2.live.AfterCallMode.Ask
        Mode.SAVED -> com.echoledger.mobile.ui.v2.live.AfterCallMode.Saved
        Mode.DISCARDED -> com.echoledger.mobile.ui.v2.live.AfterCallMode.Deleted
    }

    private const val BOTTOM_MARGIN_DP = 72
}

/**
 * The chip itself: pill-sized, the control pill's near-black look in both themes, one line ("Saved · Call with Omar ·
 * 4:12" or "Discarded · …" with Undo, or the title and length with Discard and ✓), and a thin line that shows how long
 * it stays.
 */
@Composable
fun AfterCallChip(
    title: String,
    durationMs: Long,
    mode: DecisionPopup.Mode,
    visibleMs: Long,
    onKeep: () -> Unit,
    onDiscard: () -> Unit,
    onUndo: () -> Unit,
    onTitle: () -> Unit
) {
    val type = LocalEchoType.current
    val shape = RoundedCornerShape(50)
    val left = remember { Animatable(1f) }
    LaunchedEffect(visibleMs) { left.animateTo(0f, tween(visibleMs.toInt(), easing = LinearEasing)) }
    // Since v1.5 (Phase 7): the chip rises into place and a kept call's check pops in; with Remove animations on,
    // both are simply there.
    val still = reduceMotion()
    val shown = remember { Animatable(if (still) 1f else 0f) }
    LaunchedEffect(Unit) { if (!still) shown.animateTo(1f, tween(Motion.SLOW, easing = Motion.EaseOut)) }
    val duration = formatDuration(durationMs).ifEmpty { "0:00" }
    Column(
        Modifier
            .graphicsLayer {
                alpha = shown.value
                translationY = (1f - shown.value) * 24.dp.toPx()
            }
            .widthIn(max = 420.dp)
            .clip(shape)
            .background(PillColors.background)
            .border(1.dp, PillColors.hairline, shape)
            .semantics {
                contentDescription = when (mode) {
                    DecisionPopup.Mode.SAVED -> "Saved: $title"
                    DecisionPopup.Mode.ASK -> "Keep $title?"
                    DecisionPopup.Mode.DISCARDED -> "Discarded: $title"
                }
            }
    ) {
        Row(
            Modifier.padding(start = Space.s4, end = Space.s1, top = Space.s1, bottom = Space.s1),
            verticalAlignment = Alignment.CenterVertically
        ) {
            val label = when (mode) {
                DecisionPopup.Mode.SAVED -> "Saved · $title · $duration"
                DecisionPopup.Mode.ASK -> "$title · $duration"
                DecisionPopup.Mode.DISCARDED -> "Discarded · $title · $duration"
            }
            when (mode) {
                DecisionPopup.Mode.SAVED -> SuccessCheck(PillColors.accent, Modifier.padding(end = Space.s2))

                DecisionPopup.Mode.DISCARDED -> Icon(
                    Icons.Rounded.Delete,
                    contentDescription = null,
                    tint = PillColors.muted,
                    modifier = Modifier.padding(end = Space.s2).size(18.dp)
                )

                DecisionPopup.Mode.ASK -> Unit
            }
            Text(
                label,
                style = type.labelL.tabular(),
                color = Color.White,
                maxLines = 1,
                overflow = TextOverflow.Ellipsis,
                modifier = Modifier
                    .weight(1f, fill = false)
                    // Asking: the title opens the waiting call to rename it or pick a client (a kept call is in the Library).
                    .clickable(
                        enabled = mode == DecisionPopup.Mode.ASK,
                        role = Role.Button,
                        onClickLabel = "Rename or pick a project",
                        onClick = onTitle
                    )
                    .padding(vertical = Space.s2)
            )
            if (mode != DecisionPopup.Mode.ASK) {
                Text(
                    "Undo",
                    style = type.labelL,
                    color = PillColors.accent,
                    modifier = Modifier
                        .padding(start = Space.s2)
                        .clip(RoundedCornerShape(50))
                        .clickable(role = Role.Button, onClick = onUndo)
                        .padding(horizontal = Space.s3, vertical = Space.s2)
                )
            } else {
                // A trash with its word (since v1.8.1): an "×" read as close, yet it deleted the call.
                ChipTextButton(Icons.Rounded.Delete, "Discard", onDiscard)
                ChipIcon(Icons.Rounded.Check, "Keep", onKeep, primary = true)
            }
        }
        Box(Modifier.fillMaxWidth().height(2.dp).background(PillColors.hairline)) {
            Box(Modifier.fillMaxWidth(left.value).height(2.dp).background(PillColors.accent))
        }
    }
}

@Composable
private fun ChipIcon(icon: androidx.compose.ui.graphics.vector.ImageVector, label: String, onClick: () -> Unit, primary: Boolean = false) {
    Box(
        Modifier
            .padding(start = Space.s1)
            .size(40.dp)
            .clip(CircleShape)
            .background(if (primary) PillColors.accentFill else Color.Transparent)
            .clickable(role = Role.Button, onClickLabel = label, onClick = onClick)
            .semantics { contentDescription = label },
        contentAlignment = Alignment.Center
    ) {
        Icon(icon, contentDescription = null, tint = Color.White, modifier = Modifier.size(22.dp))
    }
}

/** A chip button with its word beside its icon ("Discard"), white on the pill, 40 dp high. */
@Composable
private fun ChipTextButton(icon: androidx.compose.ui.graphics.vector.ImageVector, label: String, onClick: () -> Unit) {
    Row(
        Modifier
            .padding(start = Space.s1)
            .heightIn(min = 40.dp)
            .clip(RoundedCornerShape(50))
            .clickable(role = Role.Button, onClick = onClick)
            .padding(horizontal = Space.s3),
        verticalAlignment = Alignment.CenterVertically
    ) {
        Icon(icon, contentDescription = null, tint = Color.White, modifier = Modifier.size(18.dp))
        Text(label, style = LocalEchoType.current.labelL, color = Color.White, maxLines = 1, modifier = Modifier.padding(start = Space.s1))
    }
}

/** The control pill's colours (near-black with a hairline), the same in light and dark. */
private object PillColors {
    val background = Color(0xEB111113)
    val hairline = Color(0x33FFFFFF)
    val muted = Color(0xB3FFFFFF)
    val accent = Color(0xFF9EB1FF)
    val accentFill = Color(0xFF3E63DD)
}
