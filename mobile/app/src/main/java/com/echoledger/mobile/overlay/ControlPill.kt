package com.echoledger.mobile.overlay

import android.content.Context
import android.graphics.PixelFormat
import android.provider.Settings
import android.view.Gravity
import android.view.MotionEvent
import android.view.View
import android.view.WindowManager
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.gestures.detectDragGestures
import androidx.compose.foundation.gestures.detectTapGestures
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.derivedStateOf
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.platform.ComposeView
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.setViewTreeLifecycleOwner
import androidx.savedstate.setViewTreeSavedStateRegistryOwner
import com.echoledger.mobile.data.CaptionStyle
import com.echoledger.mobile.recording.PillEvent
import com.echoledger.mobile.recording.PillMachine
import com.echoledger.mobile.recording.PillState
import com.echoledger.mobile.ui.DarkColors
import com.echoledger.mobile.ui.Radius
import kotlinx.coroutines.flow.StateFlow

/** One caption line: who spoke ("Me", "Client", "Speaker") and what was said. */
data class CaptionLine(val speaker: String, val text: String)

/** The live captions of the recording in progress: final lines and the line still being heard. */
data class CaptionFeed(val lines: List<CaptionLine> = emptyList(), val partial: CaptionLine? = null, val notice: String? = null)

private val GLASS = Color(0xFF1E1F23)
private val REC = Color(0xFFE5484D)

/** A touchable overlay window that never takes the keyboard: the base of the pill and the caption panel. */
private abstract class OverlayWindow(protected val context: Context) {
    protected val wm: WindowManager = context.getSystemService(WindowManager::class.java)
    protected var view: ComposeView? = null
    private var owner: OverlayOwner? = null
    protected val density = context.resources.displayMetrics.density
    protected val params = WindowManager.LayoutParams(
        WindowManager.LayoutParams.WRAP_CONTENT,
        WindowManager.LayoutParams.WRAP_CONTENT,
        WindowManager.LayoutParams.TYPE_APPLICATION_OVERLAY,
        WindowManager.LayoutParams.FLAG_NOT_FOCUSABLE or WindowManager.LayoutParams.FLAG_LAYOUT_IN_SCREEN or
            WindowManager.LayoutParams.FLAG_WATCH_OUTSIDE_TOUCH,
        PixelFormat.TRANSLUCENT
    ).apply { gravity = Gravity.TOP or Gravity.START }

    val showing: Boolean get() = view != null

    protected fun screenWidth(): Int = context.resources.displayMetrics.widthPixels

    protected fun screenHeight(): Int = context.resources.displayMetrics.heightPixels

    protected fun dp(value: Int): Int = (value * density).toInt()

    protected fun attach(content: @Composable () -> Unit, onOutside: () -> Unit = {}): Boolean {
        if (view != null) return true
        if (!Settings.canDrawOverlays(context)) return false
        val lifecycleOwner = OverlayOwner().also { it.start() }
        val compose = ComposeView(context).apply {
            setViewTreeLifecycleOwner(lifecycleOwner)
            setViewTreeSavedStateRegistryOwner(lifecycleOwner)
            setContent { content() }
            setOnTouchListener { _, event ->
                if (event.actionMasked == MotionEvent.ACTION_OUTSIDE) onOutside()
                false
            }
            addOnLayoutChangeListener { v, _, _, _, _, _, _, _, _ -> onSized(v) }
        }
        return runCatching { wm.addView(compose, params) }.onSuccess {
            view = compose
            owner = lifecycleOwner
        }.isSuccess
    }

    protected open fun onSized(v: View) = Unit

    protected fun move(x: Int, y: Int) {
        params.x = x
        params.y = y
        view?.let { runCatching { wm.updateViewLayout(it, params) } }
    }

    open fun hide() {
        view?.let { runCatching { wm.removeView(it) } }
        owner?.stop()
        view = null
        owner = null
    }
}

/**
 * The recording control (since v1.3, replacing the old click-through "Listening" bubble): a small pill ("● 02:14") on
 * the right edge in the top third, away from a call screen's mute and end buttons. Drag it anywhere (it snaps to the
 * nearer edge and remembers where), tap it for the controls, or shrink it to a dot. Nothing in it takes the keyboard.
 */
class ControlPill(
    context: Context,
    private val state: StateFlow<PillState>,
    private val onEvent: (PillEvent) -> Unit,
    private val onMoved: (edge: String, yFraction: Float) -> Unit,
    edge: String,
    yFraction: Float
) {
    private val window = PillWindow(context, edge, yFraction)

    fun show() = window.open()

    fun hide() = window.hide()

    /** Keeps the pill below [bottomPx] (the caption panel's lower edge) while the panel is open; null lets it go back. */
    fun avoid(bottomPx: Int?) = window.avoid(bottomPx)

    private inner class PillWindow(context: Context, private var edge: String, private var yFraction: Float) : OverlayWindow(context) {
        private var avoidBelow: Int? = null

        fun open() {
            attach({ PillContent() }, onOutside = { onEvent(PillEvent.CloseMenu) })
            place(view?.width ?: dp(110))
        }

        fun avoid(bottomPx: Int?) {
            avoidBelow = bottomPx
            place(view?.width ?: dp(110))
        }

        private fun minY() = dp(56)

        /** Never lower than 45 % of the screen: a call screen's mute, speaker and end buttons sit below that. */
        private fun maxY() = (screenHeight() * 0.45f).toInt()

        private fun place(width: Int) {
            val wanted = (yFraction * screenHeight()).toInt()
            val y = avoidBelow?.let { maxOf(wanted, it + dp(8)) } ?: wanted
            val (_, x, clamped) = PillMachine.snap(
                if (edge == "right") screenWidth() else 0,
                y,
                width,
                screenWidth(),
                minY(),
                if (avoidBelow != null) (screenHeight() * 0.62f).toInt() else maxY(),
                dp(8)
            )
            move(x, clamped)
        }

        /** The menu grows from the pill, so the window keeps hugging its edge whatever its width. */
        override fun onSized(v: View) {
            val x = if (edge == "right") screenWidth() - v.width - dp(8) else dp(8)
            if (x != params.x) move(x, params.y)
        }

        fun dragBy(dx: Float, dy: Float) = move((params.x + dx).toInt(), (params.y + dy).toInt())

        fun dropped() {
            val width = view?.width ?: return
            val (newEdge, x, y) = PillMachine.snap(params.x, params.y, width, screenWidth(), minY(), maxY(), dp(8))
            edge = newEdge
            yFraction = y / screenHeight().toFloat()
            move(x, y)
            onMoved(edge, yFraction)
        }

        @Composable
        private fun PillContent() {
            val s by state.collectAsState()
            val drag = Modifier
                .pointerInput(Unit) { detectTapGestures(onTap = { onEvent(PillEvent.Tap) }) }
                .pointerInput(Unit) {
                    detectDragGestures(onDragEnd = { dropped() }) { change, amount ->
                        change.consume()
                        dragBy(amount.x, amount.y)
                    }
                }
            when (s.mode) {
                PillState.Mode.HIDDEN -> Unit

                PillState.Mode.DOT -> Box(
                    drag
                        .size(28.dp)
                        .background(if (s.paused) DarkColors.textMuted else REC, CircleShape)
                        .border(2.dp, GLASS, CircleShape)
                        .semantics { contentDescription = "Recording. Tap for the controls." }
                )

                PillState.Mode.PILL -> Pill(s, drag)

                PillState.Mode.MENU -> Menu(s, drag)
            }
        }
    }

    @Composable
    private fun Pill(s: PillState, drag: Modifier) {
        val now = System.currentTimeMillis()
        Row(
            drag
                .background(GLASS.copy(alpha = 0.94f), RoundedCornerShape(18.dp))
                .border(1.dp, DarkColors.border, RoundedCornerShape(18.dp))
                .padding(horizontal = 12.dp, vertical = 8.dp)
                .semantics { contentDescription = "Recording ${PillMachine.clock(s.elapsedMs)}. Tap for the controls." },
            verticalAlignment = Alignment.CenterVertically
        ) {
            if (s.discarding) {
                Text("Discarded", color = DarkColors.text, fontSize = 13.sp)
                Spacer(Modifier.width(10.dp))
                Text(
                    "Undo ${s.undoSeconds(now)}",
                    color = DarkColors.warning,
                    fontWeight = FontWeight.SemiBold,
                    fontSize = 13.sp,
                    modifier = Modifier.clickable(role = Role.Button, onClickLabel = "Undo the discard") { onEvent(PillEvent.Undo) }
                )
            } else {
                Box(Modifier.size(9.dp).background(if (s.paused || s.held) DarkColors.textMuted else REC, CircleShape))
                Spacer(Modifier.width(8.dp))
                Text(
                    if (s.held) "On hold" else PillMachine.clock(s.elapsedMs),
                    color = DarkColors.text,
                    fontFamily = FontFamily.Monospace,
                    fontSize = 14.sp
                )
                if (s.warning != null) {
                    Spacer(Modifier.width(6.dp))
                    Text("!", color = DarkColors.warning, fontWeight = FontWeight.Bold, fontSize = 14.sp)
                }
            }
        }
    }

    @Composable
    private fun Menu(s: PillState, drag: Modifier) {
        Column(
            Modifier
                .width(248.dp)
                .background(GLASS.copy(alpha = 0.97f), RoundedCornerShape(Radius.lg))
                .border(1.dp, DarkColors.border, RoundedCornerShape(Radius.lg))
                .padding(vertical = 6.dp)
        ) {
            Row(drag.fillMaxWidth().padding(horizontal = 14.dp, vertical = 8.dp), verticalAlignment = Alignment.CenterVertically) {
                Box(Modifier.size(9.dp).background(if (s.paused) DarkColors.textMuted else REC, CircleShape))
                Spacer(Modifier.width(8.dp))
                Text(
                    if (s.paused) "Paused · ${PillMachine.clock(s.elapsedMs)}" else PillMachine.clock(s.elapsedMs),
                    color = DarkColors.text,
                    fontFamily = FontFamily.Monospace,
                    modifier = Modifier.weight(1f)
                )
                Text(
                    s.language,
                    color = DarkColors.text,
                    fontSize = 12.sp,
                    fontWeight = FontWeight.SemiBold,
                    modifier = Modifier
                        .border(1.dp, DarkColors.border, RoundedCornerShape(12.dp))
                        .clickable(role = Role.Button, onClickLabel = "Change the language") { onEvent(PillEvent.NextLanguage) }
                        .padding(horizontal = 10.dp, vertical = 4.dp)
                )
            }
            s.warning?.let {
                Text(it, color = DarkColors.warning, fontSize = 12.sp, modifier = Modifier.padding(horizontal = 14.dp, vertical = 4.dp))
            }
            MenuRow(if (s.captions) "Hide captions" else "Show captions") { onEvent(PillEvent.ToggleCaptions) }
            MenuRow(if (s.paused) "Resume" else "Pause") { onEvent(PillEvent.TogglePause) }
            MenuRow("Stop") { onEvent(PillEvent.Stop) }
            MenuRow("Discard now", DarkColors.warning) { onEvent(PillEvent.DiscardNow(System.currentTimeMillis())) }
            MenuRow("Shrink to a dot") { onEvent(PillEvent.ShrinkToDot) }
            MenuRow("Hide controls (keeps recording)") { onEvent(PillEvent.HideControls) }
        }
    }

    @Composable
    private fun MenuRow(label: String, colour: Color = DarkColors.text, onTap: () -> Unit) {
        Text(
            label,
            color = colour,
            fontSize = 15.sp,
            modifier = Modifier
                .fillMaxWidth()
                .heightIn(min = 44.dp)
                .clickable(role = Role.Button) { onTap() }
                .padding(horizontal = 14.dp, vertical = 12.dp)
        )
    }
}

/**
 * Live captions (since v1.3): a compact panel, at most 30 % of the screen high, that scrolls (with "Jump to latest"
 * when you scroll back), moves by its title bar and closes with its own ×. Closing it never stops the recording.
 */
class CaptionPanel(
    context: Context,
    private val feed: StateFlow<CaptionFeed>,
    private val language: StateFlow<PillState>,
    style: CaptionStyle,
    private val onClose: () -> Unit,
    private val onNextLanguage: () -> Unit,
    private val onMoved: (bottomPx: Int) -> Unit
) {
    private val window = PanelWindow(context)

    /** The caption style (B4): text size, compact (no speaker labels) and speaker colours. */
    private val style = style.sanitized()

    fun show() = window.open()

    fun hide() = window.hide()

    val showing: Boolean get() = window.showing

    private inner class PanelWindow(context: Context) : OverlayWindow(context) {
        fun open() {
            if (showing) return
            params.width = minOf(screenWidth() - dp(24), dp(440))
            attach({ PanelContent() })
            move((screenWidth() - params.width) / 2, dp(48))
        }

        override fun onSized(v: View) = onMoved(params.y + v.height)

        fun dragBy(dx: Float, dy: Float) {
            val maxY = screenHeight() - (view?.height ?: 0) - dp(24)
            move(params.x + dx.toInt(), (params.y + dy.toInt()).coerceIn(dp(24), maxOf(dp(24), maxY)))
            view?.let { onMoved(params.y + it.height) }
        }

        @Composable
        private fun PanelContent() {
            val f by feed.collectAsState()
            val s by language.collectAsState()
            val maxHeight = (screenHeight() * 0.30f / density).dp
            val list = rememberLazyListState()
            val atEnd by remember { derivedStateOf { !list.canScrollForward } }
            val follow = remember { mutableStateOf(true) }
            val count = f.lines.size + (if (f.partial != null) 1 else 0)
            LaunchedEffect(count, f.partial?.text) { if (follow.value && count > 0) list.scrollToItem(count - 1) }
            LaunchedEffect(atEnd) { follow.value = atEnd }
            Column(
                Modifier
                    .fillMaxWidth()
                    .heightIn(max = maxHeight)
                    .background(GLASS.copy(alpha = 0.95f), RoundedCornerShape(Radius.lg))
                    .border(1.dp, DarkColors.border, RoundedCornerShape(Radius.lg))
            ) {
                Row(
                    Modifier
                        .fillMaxWidth()
                        .pointerInput(Unit) {
                            detectDragGestures { change, amount ->
                                change.consume()
                                dragBy(amount.x, amount.y)
                            }
                        }
                        .padding(start = 14.dp, end = 4.dp, top = 4.dp),
                    verticalAlignment = Alignment.CenterVertically,
                    horizontalArrangement = Arrangement.spacedBy(8.dp)
                ) {
                    Text("Captions", color = DarkColors.textMuted, fontSize = 12.sp, modifier = Modifier.weight(1f))
                    Text(
                        s.language,
                        color = DarkColors.text,
                        fontSize = 12.sp,
                        fontWeight = FontWeight.SemiBold,
                        modifier = Modifier
                            .border(1.dp, DarkColors.border, RoundedCornerShape(12.dp))
                            .clickable(role = Role.Button, onClickLabel = "Change the language") { onNextLanguage() }
                            .padding(horizontal = 10.dp, vertical = 4.dp)
                    )
                    Text(
                        "×",
                        color = DarkColors.text,
                        fontSize = 22.sp,
                        modifier = Modifier
                            .size(44.dp)
                            .clickable(role = Role.Button, onClickLabel = "Close captions (the recording continues)") { onClose() }
                            .padding(top = 6.dp),
                        textAlign = androidx.compose.ui.text.style.TextAlign.Center
                    )
                }
                Box {
                    LazyColumn(state = list, modifier = Modifier.fillMaxWidth().padding(start = 14.dp, end = 14.dp, bottom = 10.dp)) {
                        f.notice?.let { notice -> item { Text(notice, color = DarkColors.warning, fontSize = 13.sp) } }
                        if (f.lines.isEmpty() && f.partial == null && f.notice == null) {
                            item { Text("Captions appear here as people speak.", color = DarkColors.textMuted, fontSize = 13.sp) }
                        }
                        val shown =
                            bubbleLines(f.lines.map { it.speaker to it.text }, f.partial?.let { it.speaker to it.text }, style.compact)
                        items(shown) { CaptionText(it, style) }
                    }
                    if (!atEnd && count > 0) {
                        Text(
                            "Jump to latest",
                            color = DarkColors.text,
                            fontSize = 12.sp,
                            fontWeight = FontWeight.SemiBold,
                            modifier = Modifier
                                .align(Alignment.BottomEnd)
                                .padding(10.dp)
                                .background(GLASS, RoundedCornerShape(12.dp))
                                .border(1.dp, DarkColors.border, RoundedCornerShape(12.dp))
                                .clickable(role = Role.Button) { follow.value = true }
                                .padding(horizontal = 10.dp, vertical = 6.dp)
                        )
                        LaunchedEffect(follow.value) { if (follow.value && count > 0) list.animateScrollToItem(count - 1) }
                    }
                }
            }
        }
    }
}

@Composable
private fun CaptionText(line: BubbleLine, style: CaptionStyle) {
    Text(
        line.text,
        color = if (line.client) bubbleColour(style.clientColor, DarkColors.client) else bubbleColour(style.meColor, DarkColors.text),
        fontSize = style.textSp.sp,
        fontWeight = if (line.partial) FontWeight.Normal else FontWeight.Medium,
        modifier = Modifier.padding(vertical = 3.dp).widthIn(max = 420.dp)
    )
}
