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
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
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
import com.echoledger.mobile.core.SourceApp
import com.echoledger.mobile.data.LocalRecording
import com.echoledger.mobile.ui.EchoLedgerTheme
import com.echoledger.mobile.ui.LocalEchoType
import com.echoledger.mobile.ui.Motion
import com.echoledger.mobile.ui.Space
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
 *   upload waits that long, so Undo still deletes everything.
 * - [Mode.ASK] ("Ask me"): the title with ✓ and ✕; the title opens the app to rename it or pick a client; after
 *   [ASK_KEEP_MS] without a tap the call is kept.
 * VERIFY ON DEVICE (OEM overlay policies vary).
 */
object DecisionPopup {
    enum class Mode { SAVED, ASK }

    const val SAVED_MS = 6_000L
    const val ASK_KEEP_MS = 10_000L

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

    /** "Call with Omar", else "WhatsApp call" / "Phone call". */
    fun title(rec: LocalRecording): String = rec.title?.takeIf { it.isNotBlank() }
        ?: rec.callerName?.takeIf { it.isNotBlank() }?.let { "Call with $it" }
        ?: SourceApp.from(rec.sourceApp).let { if (it == SourceApp.PHONE_CALL) "Phone call" else "${it.label} call" }

    /** Shows the chip; false when it could not be added (the caller then relies on the notification). */
    fun show(context: Context, rec: LocalRecording, themeMode: String, mode: Mode): Boolean {
        hide()
        val app = context.applicationContext
        val wm = app.getSystemService(WindowManager::class.java) ?: return false
        val lifecycleOwner = OverlayOwner().also { it.start() }
        val visibleMs = if (mode == Mode.SAVED) SAVED_MS else ASK_KEEP_MS
        val compose = ComposeView(app).apply {
            setViewTreeLifecycleOwner(lifecycleOwner)
            setViewTreeSavedStateRegistryOwner(lifecycleOwner)
            setContent {
                EchoLedgerTheme(themeMode) {
                    AfterCallChip(
                        title = title(rec),
                        durationMs = rec.durationMs,
                        mode = mode,
                        visibleMs = visibleMs,
                        onKeep = { decide(app, rec.id, keep = true) },
                        onDiscard = { decide(app, rec.id, keep = false) },
                        onUndo = { undo(app, rec.id) },
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
            // Not focusable and not modal: touches outside the chip go to the app underneath.
            WindowManager.LayoutParams.FLAG_NOT_FOCUSABLE or WindowManager.LayoutParams.FLAG_NOT_TOUCH_MODAL or
                WindowManager.LayoutParams.FLAG_LAYOUT_IN_SCREEN,
            PixelFormat.TRANSLUCENT
        ).apply {
            gravity = Gravity.BOTTOM or Gravity.CENTER_HORIZONTAL
            y = (BOTTOM_MARGIN_DP * metrics.density).toInt()
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
        showingMode = mode
        // Saved: the chip only goes (the call is kept already). Ask: nobody chose, so the call is kept.
        val task = Runnable { if (mode == Mode.ASK) decide(app, rec.id, keep = true) else hide() }
        timeout = task
        handler.postDelayed(task, visibleMs)
        return true
    }

    /** Removes the chip. Never decides anything. */
    fun hide() {
        timeout?.let(handler::removeCallbacks)
        timeout = null
        view?.let { v -> runCatching { windowManager?.removeView(v) } }
        owner?.stop()
        view = null
        owner = null
        windowManager = null
        showingFor = null
        showingMode = null
    }

    private fun decide(context: Context, id: String, keep: Boolean) {
        hide()
        CoroutineScope(Dispatchers.IO).launch { Decisions.apply(context, id, keep) }
    }

    private fun undo(context: Context, id: String) {
        hide()
        CoroutineScope(Dispatchers.IO).launch { Decisions.undoKeep(context, id) }
    }

    private const val BOTTOM_MARGIN_DP = 72
}

/**
 * The chip itself: pill-sized, the control pill's near-black look in both themes, one line ("Saved · Call with Omar ·
 * 4:12" with Undo, or the title and length with ✓ and ✕), and a thin line that shows how long it stays.
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
    val pop = remember { Animatable(if (still) 1f else 0.4f) }
    LaunchedEffect(Unit) { if (!still) pop.animateTo(1f, spring(dampingRatio = 0.5f, stiffness = Spring.StiffnessMediumLow)) }
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
            .semantics { contentDescription = if (mode == DecisionPopup.Mode.SAVED) "Saved: $title" else "Keep $title?" }
    ) {
        Row(
            Modifier.padding(start = Space.s4, end = Space.s1, top = Space.s1, bottom = Space.s1),
            verticalAlignment = Alignment.CenterVertically
        ) {
            val label = if (mode == DecisionPopup.Mode.SAVED) "Saved · $title · $duration" else "$title · $duration"
            if (mode == DecisionPopup.Mode.SAVED) {
                Icon(
                    Icons.Rounded.Check,
                    contentDescription = null,
                    tint = PillColors.accent,
                    modifier = Modifier.padding(end = Space.s2).size(18.dp).graphicsLayer {
                        scaleX = pop.value
                        scaleY = pop.value
                    }
                )
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
                        onClickLabel = "Rename or pick a client",
                        onClick = onTitle
                    )
                    .padding(vertical = Space.s2)
            )
            if (mode == DecisionPopup.Mode.SAVED) {
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
                ChipIcon(Icons.Rounded.Close, "Discard", onDiscard)
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

/** The control pill's colours (near-black with a hairline), the same in light and dark. */
private object PillColors {
    val background = Color(0xEB111113)
    val hairline = Color(0x33FFFFFF)
    val accent = Color(0xFF9EB1FF)
    val accentFill = Color(0xFF3E63DD)
}
