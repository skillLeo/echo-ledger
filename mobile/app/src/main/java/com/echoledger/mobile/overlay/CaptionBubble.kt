package com.echoledger.mobile.overlay

import android.content.Context
import android.graphics.PixelFormat
import android.os.Handler
import android.os.Looper
import android.provider.Settings
import android.view.Gravity
import android.view.WindowManager
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Text
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.ComposeView
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleOwner
import androidx.lifecycle.LifecycleRegistry
import androidx.lifecycle.setViewTreeLifecycleOwner
import androidx.savedstate.SavedStateRegistry
import androidx.savedstate.SavedStateRegistryController
import androidx.savedstate.SavedStateRegistryOwner
import androidx.savedstate.setViewTreeSavedStateRegistryOwner
import com.echoledger.mobile.data.CaptionStyle

/**
 * Floating live-caption bubble (SYSTEM_ALERT_WINDOW). Not focusable and not touchable (alpha ≤ 0.8), so it never takes
 * input from the app underneath. Shown only while recording. VERIFY ON DEVICE (OEM overlay policies vary).
 * Styled by the caption settings (B4): size, opacity, lines, top or bottom, compact, auto-hide and speaker colours.
 */
class CaptionBubble(private val context: Context, style: CaptionStyle = CaptionStyle()) {
    private val windowManager = context.getSystemService(WindowManager::class.java)
    private var view: ComposeView? = null
    private var owner: OverlayOwner? = null
    private val lines = mutableStateListOf<Pair<String, String>>()
    private val partialLine = mutableStateOf<Pair<String, String>?>(null)
    private val noticeLine = mutableStateOf<String?>(null)
    private val styleState = mutableStateOf(style.sanitized())
    private val idle = mutableStateOf(false)
    private val handler = Handler(Looper.getMainLooper())
    private val goIdle = Runnable { idle.value = true }

    fun show() {
        if (view != null || !Settings.canDrawOverlays(context)) return
        val lifecycleOwner = OverlayOwner().also { it.start() }
        val compose = ComposeView(context).apply {
            setViewTreeLifecycleOwner(lifecycleOwner)
            setViewTreeSavedStateRegistryOwner(lifecycleOwner)
            setContent { if (!idle.value) Bubble(styleState.value, lines, partialLine.value, noticeLine.value) }
        }
        val params = WindowManager.LayoutParams(
            WindowManager.LayoutParams.WRAP_CONTENT,
            WindowManager.LayoutParams.WRAP_CONTENT,
            WindowManager.LayoutParams.TYPE_APPLICATION_OVERLAY,
            WindowManager.LayoutParams.FLAG_NOT_FOCUSABLE or WindowManager.LayoutParams.FLAG_NOT_TOUCHABLE or
                WindowManager.LayoutParams.FLAG_LAYOUT_IN_SCREEN,
            PixelFormat.TRANSLUCENT
        ).apply {
            gravity = (if (styleState.value.position == "top") Gravity.TOP else Gravity.BOTTOM) or Gravity.CENTER_HORIZONTAL
            y = 140
            alpha = 0.8f // required for click-through overlays on Android 12+
        }
        runCatching { windowManager.addView(compose, params) }.onSuccess {
            view = compose
            owner = lifecycleOwner
        }
    }

    fun partial(speaker: String, text: String) {
        partialLine.value = speaker to text
        active()
    }

    fun final(speaker: String, text: String) {
        partialLine.value = null
        lines += speaker to text
        while (lines.size > maxOf(1, styleState.value.lines - 1)) lines.removeAt(0)
        active()
    }

    /** A short status line instead of captions, e.g. why captions stopped while the recording continues. */
    fun notice(text: String) {
        noticeLine.value = text
        active()
    }

    /** New captions show the bubble again; with auto-hide it fades after the chosen quiet time. */
    private fun active() {
        idle.value = false
        handler.removeCallbacks(goIdle)
        val seconds = styleState.value.autoHideSeconds
        if (seconds > 0) handler.postDelayed(goIdle, seconds * 1_000L)
    }

    fun hide() {
        handler.removeCallbacks(goIdle)
        view?.let { runCatching { windowManager.removeView(it) } }
        owner?.stop()
        view = null
        owner = null
    }
}

/** One line of the bubble: the text as shown (with or without the speaker label) and whose colour it takes. */
data class BubbleLine(val text: String, val client: Boolean, val partial: Boolean)

/** The bubble's lines (pure): the finals then the partial; compact drops the speaker labels. */
fun bubbleLines(lines: List<Pair<String, String>>, partial: Pair<String, String>?, compact: Boolean): List<BubbleLine> =
    (lines.map { it to false } + listOfNotNull(partial).map { it to true }).map { (line, isPartial) ->
        val (speaker, text) = line
        BubbleLine(if (compact) text else "${speaker.uppercase()}  $text", speaker == "Client", isPartial)
    }

/** `#rrggbb` as a colour, else [fallback]. */
fun bubbleColour(hex: String?, fallback: Color): Color {
    if (hex == null || !CaptionStyle.isHexColour(hex)) return fallback
    return Color(0xFF000000 or hex.substring(1).toLong(16))
}

private val ME_COLOUR = Color(0xFFE6E8EE)
private val CLIENT_COLOUR = Color(0xFF22D3EE)

@androidx.compose.runtime.Composable
private fun Bubble(style: CaptionStyle, lines: List<Pair<String, String>>, partial: Pair<String, String>?, notice: String?) {
    val me = bubbleColour(style.meColor, ME_COLOUR)
    val client = bubbleColour(style.clientColor, CLIENT_COLOUR)
    Column(
        Modifier
            .widthIn(max = 340.dp)
            .background(Color(0xFF0C0E14).copy(alpha = style.opacity / 100f), RoundedCornerShape(16.dp))
            .padding(horizontal = 14.dp, vertical = 10.dp)
    ) {
        notice?.let { Text(it, color = Color(0xFFF59E0B), fontSize = 13.sp) }
        if (lines.isEmpty() && partial == null && notice == null) Text("Listening…", color = Color(0xFF9AA3B2), fontSize = 13.sp)
        bubbleLines(lines, partial, style.compact).forEach { line ->
            Text(
                text = line.text,
                color = if (line.client) client else me,
                fontSize = style.textSp.sp,
                fontWeight = if (line.partial) FontWeight.Normal else FontWeight.Medium,
                maxLines = 2
            )
        }
    }
}

/** Minimal lifecycle + saved-state owner so a ComposeView can live in a window owned by a Service. */
private class OverlayOwner :
    LifecycleOwner,
    SavedStateRegistryOwner {
    private val registry = LifecycleRegistry(this)
    private val controller = SavedStateRegistryController.create(this)
    override val lifecycle: Lifecycle get() = registry
    override val savedStateRegistry: SavedStateRegistry get() = controller.savedStateRegistry

    fun start() {
        controller.performAttach()
        controller.performRestore(null)
        registry.currentState = Lifecycle.State.RESUMED
    }

    fun stop() {
        registry.currentState = Lifecycle.State.DESTROYED
    }
}
