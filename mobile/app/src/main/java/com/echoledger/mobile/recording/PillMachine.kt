package com.echoledger.mobile.recording

/**
 * The recording control pill (since v1.3, like the desktop's): what it shows and what each tap asks the recorder to
 * do. Pure and unit-tested; the overlay draws [PillState] and the service runs the [PillEffect]s.
 *
 * Discard now pauses at once (nothing more is recorded or uploaded) and deletes the recording when the 5-second undo
 * has run out; Undo puts things back as they were. Hiding the controls or closing captions never stops the recording.
 *
 * The menu lies over the call app's own buttons, so a stray touch must not end a call recording (since v1.3.1): it
 * closes by itself after [MENU_IDLE_TICKS] seconds without a touch, and Stop takes a second tap within
 * [STOP_CONFIRM_TICKS] seconds ("Tap again to stop recording").
 */
data class PillState(
    val mode: Mode = Mode.PILL,
    val paused: Boolean = false,
    val captions: Boolean = false,
    val elapsedMs: Long = 0L,
    val language: String = "Auto",
    /** While set, the recording is about to be deleted; Undo is offered until then. */
    val discardAtMs: Long? = null,
    /** Paused by the person before Discard now, so Undo keeps it paused. */
    val pausedBeforeDiscard: Boolean = false,
    /** A SIM call is holding this call (nothing is written meanwhile). */
    val held: Boolean = false,
    /** Why the recording may be one-sided, shown in the menu. */
    val warning: String? = null,
    /** Seconds (ticks) the open menu has gone without a touch. */
    val menuIdleTicks: Int = 0,
    /** Set after the first tap on Stop: seconds (ticks) since then; null when Stop is not waiting for its second tap. */
    val stopArmedTicks: Int? = null
) {
    enum class Mode { PILL, DOT, MENU, HIDDEN }

    val discarding: Boolean get() = discardAtMs != null

    /** Stop was tapped once and waits for the second tap. */
    val stopArmed: Boolean get() = stopArmedTicks != null

    /** Seconds left to undo a discard, rounded up. */
    fun undoSeconds(nowMs: Long): Int = discardAtMs?.let { ((it - nowMs + 999) / 1000).toInt().coerceAtLeast(0) } ?: 0
}

sealed interface PillEvent {
    data object Tap : PillEvent
    data object CloseMenu : PillEvent
    data object ShrinkToDot : PillEvent
    data object HideControls : PillEvent
    data object ShowControls : PillEvent
    data object TogglePause : PillEvent
    data object ToggleCaptions : PillEvent
    data object CaptionsClosed : PillEvent
    data object NextLanguage : PillEvent
    data object Stop : PillEvent
    data class DiscardNow(val nowMs: Long) : PillEvent
    data object Undo : PillEvent
    data class Tick(val elapsedMs: Long, val nowMs: Long) : PillEvent
    data class Language(val chip: String) : PillEvent
    data class Held(val held: Boolean) : PillEvent
    data class Warning(val text: String?) : PillEvent
    data class Paused(val paused: Boolean) : PillEvent
}

sealed interface PillEffect {
    data object Pause : PillEffect
    data object Resume : PillEffect
    data object ShowCaptions : PillEffect
    data object HideCaptions : PillEffect
    data object NextLanguage : PillEffect
    data object Stop : PillEffect
    data object Discard : PillEffect
}

object PillMachine {
    const val UNDO_MS = 5_000L

    /** The open menu closes after this many seconds without a touch. */
    const val MENU_IDLE_TICKS = 6

    /** The second tap on Stop must come within this many seconds. */
    const val STOP_CONFIRM_TICKS = 4

    fun reduce(s: PillState, e: PillEvent): Pair<PillState, List<PillEffect>> {
        val (next, effects) = step(s, e)
        // Any touch in the open menu keeps it open a while longer.
        val touched = next.mode == PillState.Mode.MENU && e !is PillEvent.Tick && e !is PillEvent.Warning && e !is PillEvent.Language &&
            e !is PillEvent.Held && e !is PillEvent.Paused
        return (if (touched) next.copy(menuIdleTicks = 0) else next) to effects
    }

    private fun step(s: PillState, e: PillEvent): Pair<PillState, List<PillEffect>> = when (e) {
        PillEvent.Tap -> when (s.mode) {
            PillState.Mode.PILL, PillState.Mode.DOT -> s.copy(mode = PillState.Mode.MENU, stopArmedTicks = null) to emptyList()
            PillState.Mode.MENU -> s.copy(mode = PillState.Mode.PILL, stopArmedTicks = null) to emptyList()
            PillState.Mode.HIDDEN -> s to emptyList()
        }

        PillEvent.CloseMenu -> (if (s.mode == PillState.Mode.MENU) s.copy(mode = PillState.Mode.PILL, stopArmedTicks = null) else s) to
            emptyList()

        PillEvent.ShrinkToDot -> (if (s.mode == PillState.Mode.HIDDEN) s else s.copy(mode = PillState.Mode.DOT)) to emptyList()

        PillEvent.HideControls -> s.copy(mode = PillState.Mode.HIDDEN) to emptyList()

        PillEvent.ShowControls -> (if (s.mode == PillState.Mode.HIDDEN) s.copy(mode = PillState.Mode.PILL) else s) to emptyList()

        PillEvent.TogglePause -> when {
            s.discarding -> s to emptyList()
            s.paused -> s.copy(paused = false) to listOf(PillEffect.Resume)
            else -> s.copy(paused = true) to listOf(PillEffect.Pause)
        }

        PillEvent.ToggleCaptions -> if (s.captions) {
            s.copy(captions = false) to listOf(PillEffect.HideCaptions)
        } else {
            s.copy(captions = true) to listOf(PillEffect.ShowCaptions)
        }

        PillEvent.CaptionsClosed -> s.copy(captions = false) to emptyList()

        PillEvent.NextLanguage -> s to if (s.discarding) emptyList() else listOf(PillEffect.NextLanguage)

        PillEvent.Stop -> when {
            s.discarding -> s to emptyList()
            s.stopArmed -> s.copy(mode = PillState.Mode.HIDDEN, stopArmedTicks = null) to listOf(PillEffect.Stop)
            else -> s.copy(stopArmedTicks = 0) to emptyList()
        }

        is PillEvent.DiscardNow -> if (s.discarding) {
            s to emptyList()
        } else {
            s.copy(
                mode = PillState.Mode.PILL,
                discardAtMs = e.nowMs + UNDO_MS,
                pausedBeforeDiscard = s.paused,
                paused = true
            ) to if (s.paused) emptyList() else listOf(PillEffect.Pause)
        }

        PillEvent.Undo -> if (!s.discarding) {
            s to emptyList()
        } else {
            s.copy(discardAtMs = null, paused = s.pausedBeforeDiscard) to
                if (s.pausedBeforeDiscard) emptyList() else listOf(PillEffect.Resume)
        }

        is PillEvent.Tick -> {
            val due = s.discardAtMs?.let { e.nowMs >= it } == true
            if (due) {
                s.copy(elapsedMs = e.elapsedMs, mode = PillState.Mode.HIDDEN, discardAtMs = null, stopArmedTicks = null) to
                    listOf(PillEffect.Discard)
            } else {
                val idle = if (s.mode == PillState.Mode.MENU) s.menuIdleTicks + 1 else 0
                val armed = s.stopArmedTicks?.plus(1)?.takeIf { it < STOP_CONFIRM_TICKS }
                if (idle >= MENU_IDLE_TICKS) {
                    s.copy(elapsedMs = e.elapsedMs, mode = PillState.Mode.PILL, menuIdleTicks = 0, stopArmedTicks = null) to emptyList()
                } else {
                    s.copy(elapsedMs = e.elapsedMs, menuIdleTicks = idle, stopArmedTicks = armed) to emptyList()
                }
            }
        }

        is PillEvent.Language -> s.copy(language = e.chip) to emptyList()

        is PillEvent.Held -> s.copy(held = e.held) to emptyList()

        is PillEvent.Warning -> s.copy(warning = e.text) to emptyList()

        is PillEvent.Paused -> (if (s.discarding) s else s.copy(paused = e.paused)) to emptyList()
    }

    /** "02:14", or "1:02:14" past an hour. */
    fun clock(ms: Long): String {
        val total = (ms / 1000).coerceAtLeast(0)
        val h = total / 3600
        val m = (total % 3600) / 60
        val sec = total % 60
        return if (h > 0) "%d:%02d:%02d".format(h, m, sec) else "%02d:%02d".format(m, sec)
    }

    /** Where the pill may sit: snapped to the nearer edge, its top kept between [minY] and [maxY] (pixels). */
    fun snap(x: Int, y: Int, width: Int, screenWidth: Int, minY: Int, maxY: Int, margin: Int): Triple<String, Int, Int> {
        val edge = if (x + width / 2 >= screenWidth / 2) "right" else "left"
        val snappedX = if (edge == "right") screenWidth - width - margin else margin
        return Triple(edge, snappedX, y.coerceIn(minY, maxOf(minY, maxY)))
    }
}
