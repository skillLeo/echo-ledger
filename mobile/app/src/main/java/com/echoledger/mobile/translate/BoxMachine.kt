package com.echoledger.mobile.translate

/**
 * The floating box's states (plan B2). LOCKED is the default while playing: the box takes no touch at all, so every
 * touch reaches the game. EDIT lets the person move and resize it and locks itself again after [EDIT_IDLE_MS] without a
 * touch. HIDDEN comes back by itself after [HIDE_MS] (or when shown). PAUSED keeps the box but stops listening.
 * STOPPED is the end.
 */
enum class BoxState { LOCKED, EDIT, HIDDEN, PAUSED, STOPPED }

sealed interface BoxEvent {
    data object GripTap : BoxEvent
    data object Done : BoxEvent
    data object Touched : BoxEvent
    data object Hide : BoxEvent
    data object Show : BoxEvent
    data object Pause : BoxEvent
    data object Resume : BoxEvent
    data object Stop : BoxEvent

    /** Time passing: [nowMs] on the elapsed clock. */
    data class Tick(val nowMs: Long) : BoxEvent
}

class BoxMachine(private val nowMs: () -> Long) {
    var state: BoxState = BoxState.LOCKED
        private set

    private var lastTouchMs = 0L
    private var hiddenUntilMs = 0L

    /** The state the box returns to after Hidden or Paused. */
    private var before: BoxState = BoxState.LOCKED

    /** Applies [event]; returns the new state. Stopped never changes again. */
    fun on(event: BoxEvent): BoxState {
        if (state == BoxState.STOPPED) return state
        val now = nowMs()
        state = when (event) {
            BoxEvent.GripTap -> when (state) {
                BoxState.LOCKED -> {
                    lastTouchMs = now
                    BoxState.EDIT
                }

                BoxState.EDIT -> BoxState.LOCKED

                BoxState.HIDDEN -> before

                BoxState.PAUSED, BoxState.STOPPED -> state
            }

            BoxEvent.Done -> if (state == BoxState.EDIT) BoxState.LOCKED else state

            BoxEvent.Touched -> {
                lastTouchMs = now
                state
            }

            BoxEvent.Hide -> {
                if (state != BoxState.HIDDEN) before = if (state == BoxState.EDIT) BoxState.LOCKED else state
                hiddenUntilMs = now + HIDE_MS
                BoxState.HIDDEN
            }

            BoxEvent.Show -> if (state == BoxState.HIDDEN) before else state

            BoxEvent.Pause -> {
                if (state != BoxState.PAUSED) before = if (state == BoxState.EDIT || state == BoxState.HIDDEN) BoxState.LOCKED else state
                BoxState.PAUSED
            }

            BoxEvent.Resume -> if (state == BoxState.PAUSED) before else state

            BoxEvent.Stop -> BoxState.STOPPED

            is BoxEvent.Tick -> when {
                state == BoxState.EDIT && event.nowMs - lastTouchMs >= EDIT_IDLE_MS -> BoxState.LOCKED
                state == BoxState.HIDDEN && event.nowMs >= hiddenUntilMs -> before
                else -> state
            }
        }
        return state
    }

    /** Whether the box window should take touches (only while being moved or resized). */
    val touchable: Boolean get() = state == BoxState.EDIT

    /** Whether audio is being listened to and translated. */
    val listening: Boolean get() = state == BoxState.LOCKED || state == BoxState.EDIT || state == BoxState.HIDDEN

    companion object {
        const val EDIT_IDLE_MS = 5_000L
        const val HIDE_MS = 5 * 60_000L
    }
}

/** A rectangle in pixels. */
data class BoxRect(val x: Int, val y: Int, val width: Int, val height: Int) {
    val right: Int get() = x + width
    val bottom: Int get() = y + height
}

/**
 * Where the box may sit (plan B2): inside the safe area (the cutout and system bars left out), snapped to an edge or
 * to one of nine preset spots when it is dropped close to one. Pure, so it is tested without a screen.
 */
object BoxGeometry {
    const val SNAP_PX_DP = 24

    /** The nine preset spots (top/centre/bottom × start/centre/end) for a box of this size in [safe]. */
    fun presets(width: Int, height: Int, safe: BoxRect): List<Pair<Int, Int>> {
        val xs = listOf(safe.x, safe.x + (safe.width - width) / 2, safe.right - width)
        val ys = listOf(safe.y, safe.y + (safe.height - height) / 2, safe.bottom - height)
        return ys.flatMap { y -> xs.map { x -> x to y } }
    }

    /** The box kept inside [safe] (shrunk if it is larger), snapped when within [snapPx] of a preset spot or an edge. */
    fun place(box: BoxRect, safe: BoxRect, snapPx: Int): BoxRect {
        val w = box.width.coerceIn(1, safe.width)
        val h = box.height.coerceIn(1, safe.height)
        var x = box.x.coerceIn(safe.x, safe.right - w)
        var y = box.y.coerceIn(safe.y, safe.bottom - h)
        presets(w, h, safe).minByOrNull { (px, py) -> (px - x) * (px - x) + (py - y) * (py - y) }?.let { (px, py) ->
            if (kotlin.math.abs(px - x) <= snapPx && kotlin.math.abs(py - y) <= snapPx) {
                x = px
                y = py
            }
        }
        if (x - safe.x <= snapPx) x = safe.x
        if (safe.right - (x + w) <= snapPx) x = safe.right - w
        if (y - safe.y <= snapPx) y = safe.y
        if (safe.bottom - (y + h) <= snapPx) y = safe.bottom - h
        return BoxRect(x, y, w, h)
    }

    /** The default game box: 60 % of the width (at most [maxWidthPx]), top centre. */
    fun defaultBox(safe: BoxRect, heightPx: Int, maxWidthPx: Int): BoxRect {
        val w = minOf((safe.width * 0.6f).toInt(), maxWidthPx)
        return BoxRect(safe.x + (safe.width - w) / 2, safe.y, w, heightPx)
    }

    /** A remembered placement (fractions of the screen) back in pixels, or null when it does not fit any more. */
    fun fromPlacement(p: BoxPlacement, screenW: Int, screenH: Int): BoxRect = BoxRect(
        kotlin.math.round(p.x * screenW).toInt(),
        kotlin.math.round(p.y * screenH).toInt(),
        kotlin.math.round(p.width * screenW).toInt(),
        kotlin.math.round(p.height * screenH).toInt()
    )

    fun toPlacement(r: BoxRect, screenW: Int, screenH: Int): BoxPlacement =
        BoxPlacement(r.x / screenW.toFloat(), r.y / screenH.toFloat(), r.width / screenW.toFloat(), r.height / screenH.toFloat())

    /** The key a placement is remembered under: per app and per orientation. */
    fun key(packageName: String?, landscape: Boolean): String = "${packageName ?: "any"}|${if (landscape) "land" else "port"}"
}
