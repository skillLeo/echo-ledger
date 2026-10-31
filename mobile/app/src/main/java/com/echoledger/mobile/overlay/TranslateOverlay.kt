package com.echoledger.mobile.overlay

import android.annotation.SuppressLint
import android.content.Context
import android.graphics.PixelFormat
import android.graphics.Rect
import android.os.Build
import android.os.Handler
import android.os.Looper
import android.provider.Settings
import android.view.Gravity
import android.view.MotionEvent
import android.view.View
import android.view.WindowInsets
import android.view.WindowManager
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.combinedClickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.Contrast
import androidx.compose.material.icons.rounded.Pause
import androidx.compose.material.icons.rounded.PlayArrow
import androidx.compose.material.icons.rounded.Subtitles
import androidx.compose.material.icons.rounded.TextDecrease
import androidx.compose.material.icons.rounded.TextIncrease
import androidx.compose.material.icons.rounded.Translate
import androidx.compose.material.icons.rounded.VisibilityOff
import androidx.compose.material3.Icon
import androidx.compose.runtime.mutableStateOf
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.ComposeView
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.dp
import androidx.lifecycle.setViewTreeLifecycleOwner
import androidx.savedstate.setViewTreeSavedStateRegistryOwner
import com.echoledger.mobile.translate.BoxBackground
import com.echoledger.mobile.translate.BoxGeometry
import com.echoledger.mobile.translate.BoxPlacement
import com.echoledger.mobile.translate.BoxRect
import com.echoledger.mobile.translate.BoxState
import com.echoledger.mobile.translate.TranslateFeed

/** What the person did with the box's controls (the Live Translate service acts on them). */
interface TranslateBoxActions {
    fun onGripTap()
    fun onDone()
    fun onTouched()
    fun onPause()
    fun onResume()
    fun onHide()
    fun onStop()
    fun onTextSize(step: Int)
    fun onOpacity(step: Float)
    fun onToggleOriginal()
    fun onLanguage()
    fun onPlaced(placement: BoxPlacement)
}

/**
 * The Live Translate windows (plan B2/B4): the box (or the subtitles), the 24 dp grip dot just outside its top-right
 * corner, and the toolbar the grip opens on a long-press. Main thread only.
 *
 * While locked the box window has FLAG_NOT_TOUCHABLE and an alpha of at most 0.8, so Android passes every touch to
 * the game under it. Nothing here takes focus, makes a sound, vibrates or shows a toast.
 */
class TranslateOverlay(private val context: Context, private val subtitles: Boolean, private val actions: TranslateBoxActions) {
    private val wm = context.getSystemService(WindowManager::class.java)
    private val main = Handler(Looper.getMainLooper())
    private val density = context.resources.displayMetrics.density
    private var text: TranslateTextView? = null
    private var boxParams: WindowManager.LayoutParams? = null
    private var grip: ComposeView? = null
    private var gripParams: WindowManager.LayoutParams? = null
    private var toolbar: ComposeView? = null
    private val owner = OverlayOwner()
    private val gripFaded = mutableStateOf(false)
    private val paused = mutableStateOf(false)
    private var state = BoxState.LOCKED
    private var opacity = 0.8f
    private var showGrip = true
    private var visible = true
    private val fadeGrip = Runnable { gripFaded.value = true }
    private val closeToolbar = Runnable { hideToolbar() }

    val showing: Boolean get() = text != null

    /** Opens the windows at [placement] (or the default spot). False when "Display over other apps" is off. */
    fun show(placement: BoxPlacement?, opacity: Float, showGrip: Boolean): Boolean {
        if (text != null) return true
        if (!Settings.canDrawOverlays(context)) return false
        owner.start()
        this.opacity = opacity
        this.showGrip = showGrip && !subtitles
        val view = TranslateTextView(context, subtitles)
        val (sw, sh) = screen()
        val safe = safeArea()
        val rect = placement?.let { BoxGeometry.place(BoxGeometry.fromPlacement(it, sw, sh), safe, snapPx()) }
            ?: if (subtitles) {
                val w = (safe.width * 0.88f).toInt()
                BoxRect(safe.x + (safe.width - w) / 2, safe.bottom - (140 * density).toInt(), w, (110 * density).toInt())
            } else {
                BoxGeometry.defaultBox(safe, (84 * density).toInt(), (520 * density).toInt())
            }
        val params = WindowManager.LayoutParams(
            rect.width,
            rect.height,
            WindowManager.LayoutParams.TYPE_APPLICATION_OVERLAY,
            LOCKED_FLAGS,
            PixelFormat.TRANSLUCENT
        ).apply {
            gravity = Gravity.TOP or Gravity.START
            x = rect.x
            y = rect.y
            alpha = opacity.coerceAtMost(MAX_PASS_THROUGH_ALPHA)
            if (Build.VERSION.SDK_INT >=
                28
            ) {
                layoutInDisplayCutoutMode = WindowManager.LayoutParams.LAYOUT_IN_DISPLAY_CUTOUT_MODE_SHORT_EDGES
            }
            title = "EchoLedger Live Translate"
        }
        if (runCatching { wm.addView(view, params) }.isFailure) {
            owner.stop()
            return false
        }
        text = view
        boxParams = params
        attachDrag(view)
        if (this.showGrip) addGrip()
        return true
    }

    fun hideAll() {
        main.removeCallbacksAndMessages(null)
        hideToolbar()
        grip?.let { runCatching { wm.removeView(it) } }
        grip = null
        text?.let { runCatching { wm.removeView(it) } }
        text = null
        owner.stop()
    }

    fun style(
        textSp: Int,
        lines: Int,
        background: BoxBackground,
        showOriginal: Boolean,
        language: String?,
        opacity: Float,
        subtitleBox: Boolean = false
    ) {
        val view = text ?: return
        this.opacity = opacity
        view.style(textSp, lines, background, showOriginal, language, subtitleBox)
        val params = boxParams ?: return
        val wanted = view.preferredHeight()
        if (params.height != wanted) {
            params.height = wanted
            if (subtitles) params.y = safeArea().bottom - wanted - offsetPx
        }
        if (state != BoxState.EDIT) params.alpha = opacity.coerceAtMost(MAX_PASS_THROUGH_ALPHA)
        runCatching { wm.updateViewLayout(view, params) }
        moveGrip()
    }

    /** How far above the bottom the subtitles sit (Watch). */
    var offsetPx: Int = 0
        set(value) {
            field = value
            val view = text ?: return
            val params = boxParams ?: return
            if (subtitles) {
                params.y = safeArea().bottom - params.height - value
                runCatching { wm.updateViewLayout(view, params) }
            }
        }

    /** New text; [visibleText] false fades the box out (quiet), true fades it in. */
    fun show(feed: TranslateFeed, visibleText: Boolean, waiting: Boolean, notice: String?) {
        val view = text ?: return
        view.content(feed.text.takeLast(TAIL), feed.partial, feed.shownOriginal, feed.language, waiting, notice)
        if (visibleText != visible) {
            visible = visibleText
            view.animate().cancel()
            view.animate().alpha(if (visibleText) 1f else 0f).setDuration(if (visibleText) FADE_IN_MS else FADE_OUT_MS).start()
        }
    }

    /** Applies a state from the box machine: touchable only while editing; hidden windows leave the screen. */
    fun state(next: BoxState) {
        if (next == state) return
        state = next
        val view = text ?: return
        val params = boxParams ?: return
        paused.value = next == BoxState.PAUSED
        when (next) {
            BoxState.EDIT -> {
                params.flags = EDIT_FLAGS
                params.alpha = 1f
                view.visibility = View.VISIBLE
                view.setEditing(true)
            }

            // Hidden means nothing on screen, the dot included (since 1.3.0.1): the notification's Show brings it back.
            BoxState.HIDDEN -> {
                view.visibility = View.GONE
                view.setEditing(false)
                params.flags = LOCKED_FLAGS
                hideToolbar()
            }

            else -> {
                params.flags = LOCKED_FLAGS
                params.alpha = opacity.coerceAtMost(MAX_PASS_THROUGH_ALPHA)
                view.visibility = View.VISIBLE
                view.setEditing(false)
            }
        }
        runCatching { wm.updateViewLayout(view, params) }
        grip?.visibility = if (next == BoxState.HIDDEN) View.GONE else View.VISIBLE
        wakeGrip()
    }

    /** The grip dot on or off at once (Settings → Game translate → Show the grip dot), without restarting. */
    fun showGrip(on: Boolean) {
        if (subtitles || on == (grip != null)) return
        showGrip = on
        if (on) {
            if (text != null) addGrip()
        } else {
            hideToolbar()
            grip?.let { runCatching { wm.removeView(it) } }
            grip = null
        }
    }

    // ---- moving and resizing (Edit) -------------------------------------------------------------------------------

    @SuppressLint("ClickableViewAccessibility")
    private fun attachDrag(view: View) {
        var downX = 0f
        var downY = 0f
        var startX = 0
        var startY = 0
        var startW = 0
        var startH = 0
        var resizing = false
        view.setOnTouchListener { _, e ->
            val params = boxParams ?: return@setOnTouchListener false
            if (state != BoxState.EDIT) return@setOnTouchListener false
            actions.onTouched()
            when (e.actionMasked) {
                MotionEvent.ACTION_DOWN -> {
                    downX = e.rawX
                    downY = e.rawY
                    startX = params.x
                    startY = params.y
                    startW = params.width
                    startH = params.height
                    val corner = 40 * density
                    resizing = e.x > params.width - corner && e.y > params.height - corner
                }

                MotionEvent.ACTION_MOVE -> {
                    val dx = (e.rawX - downX).toInt()
                    val dy = (e.rawY - downY).toInt()
                    if (resizing) {
                        params.width = (startW + dx).coerceAtLeast((160 * density).toInt())
                    } else {
                        params.x = startX + dx
                        params.y = startY + dy
                    }
                    runCatching { wm.updateViewLayout(view, params) }
                    moveGrip()
                }

                MotionEvent.ACTION_UP, MotionEvent.ACTION_CANCEL -> {
                    val placed = BoxGeometry.place(BoxRect(params.x, params.y, params.width, params.height), safeArea(), snapPx())
                    params.x = placed.x
                    params.y = placed.y
                    params.width = placed.width
                    runCatching { wm.updateViewLayout(view, params) }
                    moveGrip()
                    val (sw, sh) = screen()
                    actions.onPlaced(BoxGeometry.toPlacement(placed, sw, sh))
                    if (e.actionMasked == MotionEvent.ACTION_UP && kotlin.math.abs(e.rawX - downX) < 8 * density &&
                        kotlin.math.abs(e.rawY - downY) < 8 * density && startH == params.height
                    ) {
                        // A tap on the box while editing: Done.
                        actions.onDone()
                    }
                }
            }
            true
        }
    }

    // ---- grip dot and toolbar -------------------------------------------------------------------------------------

    private fun addGrip() {
        val size = (GRIP_DP * density).toInt()
        val view = ComposeView(context).apply {
            setViewTreeLifecycleOwner(owner)
            setViewTreeSavedStateRegistryOwner(owner)
            setContent {
                Box(
                    Modifier.size(GRIP_DP.dp).alpha(if (gripFaded.value) 0.25f else 1f).clip(CircleShape)
                        .background(Color(0xE6111113)).border(1.dp, Color(0x33FFFFFF), CircleShape)
                        .semantics { contentDescription = "Live Translate box: tap to move it, hold for controls" }
                        .combinedClickable(
                            role = Role.Button,
                            onClick = {
                                wakeGrip()
                                actions.onGripTap()
                            },
                            onLongClick = {
                                wakeGrip()
                                showToolbar()
                            }
                        ),
                    contentAlignment = Alignment.Center
                ) {
                    Box(Modifier.size(8.dp).clip(CircleShape).background(Color(0xFF9EB1FF)))
                }
            }
        }
        val params = WindowManager.LayoutParams(
            size,
            size,
            WindowManager.LayoutParams.TYPE_APPLICATION_OVERLAY,
            WindowManager.LayoutParams.FLAG_NOT_FOCUSABLE or WindowManager.LayoutParams.FLAG_LAYOUT_IN_SCREEN,
            PixelFormat.TRANSLUCENT
        ).apply { gravity = Gravity.TOP or Gravity.START }
        if (runCatching { wm.addView(view, params) }.isFailure) return
        grip = view
        gripParams = params
        moveGrip()
        wakeGrip()
    }

    private fun moveGrip() {
        val g = grip ?: return
        val gp = gripParams ?: return
        val bp = boxParams ?: return
        val size = (GRIP_DP * density).toInt()
        gp.x = (bp.x + bp.width - size / 2).coerceAtMost(screen().first - size)
        gp.y = (bp.y - size / 2).coerceAtLeast(0)
        runCatching { wm.updateViewLayout(g, gp) }
    }

    private fun wakeGrip() {
        gripFaded.value = false
        main.removeCallbacks(fadeGrip)
        main.postDelayed(fadeGrip, GRIP_FADE_MS)
    }

    private fun showToolbar() {
        if (toolbar != null) return
        val view = ComposeView(context).apply {
            setViewTreeLifecycleOwner(owner)
            setViewTreeSavedStateRegistryOwner(owner)
            setContent {
                // Stop comes first and the buttons wrap onto a second row on narrow screens (since 1.3.0.1: on a
                // 360 dp screen the last button, Stop, was cut off).
                FlowRow(
                    Modifier.clip(
                        RoundedCornerShape(22.dp)
                    ).background(Color(0xEB111113)).border(1.dp, Color(0x33FFFFFF), RoundedCornerShape(22.dp))
                        .padding(4.dp),
                    horizontalArrangement = Arrangement.spacedBy(2.dp),
                    verticalArrangement = Arrangement.spacedBy(2.dp)
                ) {
                    ToolButton(Icons.Rounded.Close, "Stop Live Translate", danger = true) { actions.onStop() }
                    ToolButton(
                        if (paused.value) Icons.Rounded.PlayArrow else Icons.Rounded.Pause,
                        if (paused.value) "Resume" else "Pause"
                    ) {
                        if (paused.value) actions.onResume() else actions.onPause()
                    }
                    ToolButton(Icons.Rounded.VisibilityOff, "Hide the box and the dot") { actions.onHide() }
                    ToolButton(Icons.Rounded.TextDecrease, "Smaller text") { actions.onTextSize(-2) }
                    ToolButton(Icons.Rounded.TextIncrease, "Larger text") { actions.onTextSize(2) }
                    ToolButton(Icons.Rounded.Contrast, "Less opaque") { actions.onOpacity(-0.1f) }
                    ToolButton(Icons.Rounded.Contrast, "More opaque", bright = true) { actions.onOpacity(0.1f) }
                    ToolButton(Icons.Rounded.Subtitles, "Show or hide what was said") { actions.onToggleOriginal() }
                    ToolButton(Icons.Rounded.Translate, "Next language") { actions.onLanguage() }
                }
            }
        }
        val (screenW, _) = screen()
        val margin = (8 * density).toInt()
        val width = minOf((TOOLBAR_DP * density).toInt(), screenW - 2 * margin)
        val params = WindowManager.LayoutParams(
            width,
            WindowManager.LayoutParams.WRAP_CONTENT,
            WindowManager.LayoutParams.TYPE_APPLICATION_OVERLAY,
            WindowManager.LayoutParams.FLAG_NOT_FOCUSABLE or WindowManager.LayoutParams.FLAG_WATCH_OUTSIDE_TOUCH or
                WindowManager.LayoutParams.FLAG_LAYOUT_IN_SCREEN,
            PixelFormat.TRANSLUCENT
        ).apply {
            gravity = Gravity.TOP or Gravity.START
            val bp = boxParams
            x = ((bp?.x ?: 0) + (bp?.width ?: 0) - width).coerceIn(margin, (screenW - width - margin).coerceAtLeast(margin))
            y = (bp?.y ?: 0) + (bp?.height ?: 0) + margin
        }
        view.setOnTouchListener { _, e ->
            if (e.actionMasked == MotionEvent.ACTION_OUTSIDE) hideToolbar()
            false
        }
        if (runCatching { wm.addView(view, params) }.isFailure) return
        toolbar = view
        main.removeCallbacks(closeToolbar)
        main.postDelayed(closeToolbar, TOOLBAR_IDLE_MS)
    }

    private fun hideToolbar() {
        main.removeCallbacks(closeToolbar)
        toolbar?.let { runCatching { wm.removeView(it) } }
        toolbar = null
    }

    @androidx.compose.runtime.Composable
    private fun ToolButton(icon: ImageVector, label: String, bright: Boolean = false, danger: Boolean = false, onClick: () -> Unit) {
        Box(
            Modifier.size(40.dp).clip(CircleShape)
                .combinedClickable(
                    role = Role.Button,
                    onClick = {
                        main.removeCallbacks(closeToolbar)
                        main.postDelayed(closeToolbar, TOOLBAR_IDLE_MS)
                        onClick()
                    },
                    onLongClick = {}
                )
                .semantics { contentDescription = label },
            contentAlignment = Alignment.Center
        ) {
            Icon(
                icon,
                contentDescription = null,
                tint = when {
                    danger -> Color(0xFFFF9592)
                    bright -> Color.White
                    else -> Color(0xFFB0B4BA)
                },
                modifier = Modifier.size(20.dp)
            )
        }
    }

    // ---- screen ----------------------------------------------------------------------------------------------------

    private fun screen(): Pair<Int, Int> {
        if (Build.VERSION.SDK_INT >= 30) {
            val b = wm.currentWindowMetrics.bounds
            return b.width() to b.height()
        }
        val m = context.resources.displayMetrics
        return m.widthPixels to m.heightPixels
    }

    /** The screen minus the cutout and the system bars, where the box may sit. */
    private fun safeArea(): BoxRect {
        val (w, h) = screen()
        if (Build.VERSION.SDK_INT >= 30) {
            val insets = wm.currentWindowMetrics.windowInsets
                .getInsetsIgnoringVisibility(WindowInsets.Type.systemBars() or WindowInsets.Type.displayCutout())
            return BoxRect(insets.left, insets.top, w - insets.left - insets.right, h - insets.top - insets.bottom)
        }
        val r = Rect(0, (24 * density).toInt(), w, h)
        return BoxRect(r.left, r.top, r.width(), r.height())
    }

    private fun snapPx() = (BoxGeometry.SNAP_PX_DP * density).toInt()

    companion object {
        /** Android 12+ passes touches through another app's overlay only while its alpha is at most 0.8. */
        const val MAX_PASS_THROUGH_ALPHA = 0.8f
        const val GRIP_DP = 24
        const val GRIP_FADE_MS = 3_000L
        const val TOOLBAR_IDLE_MS = 6_000L

        /** Nine 40 dp buttons with 2 dp gaps and 4 dp padding: one row when the screen is wide enough. */
        const val TOOLBAR_DP = 9 * 40 + 8 * 2 + 8
        const val FADE_IN_MS = 120L
        const val FADE_OUT_MS = 250L
        const val TAIL = 400

        val LOCKED_FLAGS = WindowManager.LayoutParams.FLAG_NOT_FOCUSABLE or WindowManager.LayoutParams.FLAG_NOT_TOUCHABLE or
            WindowManager.LayoutParams.FLAG_LAYOUT_IN_SCREEN or WindowManager.LayoutParams.FLAG_HARDWARE_ACCELERATED
        val EDIT_FLAGS = WindowManager.LayoutParams.FLAG_NOT_FOCUSABLE or WindowManager.LayoutParams.FLAG_LAYOUT_IN_SCREEN or
            WindowManager.LayoutParams.FLAG_HARDWARE_ACCELERATED
    }
}
