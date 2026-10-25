package com.echoledger.mobile.overlay

import android.content.Context
import android.graphics.PixelFormat
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

/**
 * Floating live-caption bubble (SYSTEM_ALERT_WINDOW). Not focusable and not touchable (alpha ≤ 0.8), so it never takes
 * input from the app underneath. Shown only while recording. VERIFY ON DEVICE (OEM overlay policies vary).
 */
class CaptionBubble(private val context: Context) {
    private val windowManager = context.getSystemService(WindowManager::class.java)
    private var view: ComposeView? = null
    private var owner: OverlayOwner? = null
    private val lines = mutableStateListOf<Pair<String, String>>()
    private val partialLine = mutableStateOf<Pair<String, String>?>(null)
    private val noticeLine = mutableStateOf<String?>(null)

    fun show() {
        if (view != null || !Settings.canDrawOverlays(context)) return
        val lifecycleOwner = OverlayOwner().also { it.start() }
        val compose = ComposeView(context).apply {
            setViewTreeLifecycleOwner(lifecycleOwner)
            setViewTreeSavedStateRegistryOwner(lifecycleOwner)
            setContent { Bubble(lines, partialLine.value, noticeLine.value) }
        }
        val params = WindowManager.LayoutParams(
            WindowManager.LayoutParams.WRAP_CONTENT,
            WindowManager.LayoutParams.WRAP_CONTENT,
            WindowManager.LayoutParams.TYPE_APPLICATION_OVERLAY,
            WindowManager.LayoutParams.FLAG_NOT_FOCUSABLE or WindowManager.LayoutParams.FLAG_NOT_TOUCHABLE or
                WindowManager.LayoutParams.FLAG_LAYOUT_IN_SCREEN,
            PixelFormat.TRANSLUCENT
        ).apply {
            gravity = Gravity.TOP or Gravity.CENTER_HORIZONTAL
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
    }

    fun final(speaker: String, text: String) {
        partialLine.value = null
        lines += speaker to text
        while (lines.size > 2) lines.removeAt(0)
    }

    /** A short status line instead of captions, e.g. why captions stopped while the recording continues. */
    fun notice(text: String) {
        noticeLine.value = text
    }

    fun hide() {
        view?.let { runCatching { windowManager.removeView(it) } }
        owner?.stop()
        view = null
        owner = null
    }
}

@androidx.compose.runtime.Composable
private fun Bubble(lines: List<Pair<String, String>>, partial: Pair<String, String>?, notice: String?) {
    Column(
        Modifier
            .widthIn(max = 340.dp)
            .background(Color(0xE60C0E14), RoundedCornerShape(16.dp))
            .padding(horizontal = 14.dp, vertical = 10.dp)
    ) {
        notice?.let { Text(it, color = Color(0xFFF59E0B), fontSize = 13.sp) }
        if (lines.isEmpty() && partial == null && notice == null) Text("Listening…", color = Color(0xFF9AA3B2), fontSize = 13.sp)
        (lines + listOfNotNull(partial)).forEachIndexed { index, (speaker, text) ->
            val isPartial = partial != null && index == lines.size
            Text(
                text = "${speaker.uppercase()}  $text",
                color = if (speaker == "Client") Color(0xFF22D3EE) else Color(0xFFE6E8EE),
                fontSize = 14.sp,
                fontWeight = if (isPartial) FontWeight.Normal else FontWeight.Medium,
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
