package com.echoledger.mobile.recording

/**
 * The recording control pill (since v1.3, like the desktop's): what it shows and what each tap asks the recorder to
 * do. Pure and unit-tested; the overlay draws [PillState] and the service runs the [PillEffect]s.
 *
 * Discard now pauses at once (nothing more is recorded or uploaded) and deletes the recording when the 5-second undo
 * has run out; Undo puts things back as they were. Hiding the controls or closing captions never stops the recording.
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
    val warning: String? = null
) {
    enum class Mode { PILL, DOT, MENU, HIDDEN }

    val discarding: Boolean get() = discardAtMs != null

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

    fun reduce(s: PillState, e: PillEvent): Pair<PillState, List<PillEffect>> = when (e) {
        PillEvent.Tap -> when (s.mode) {
            PillState.Mode.PILL, PillState.Mode.DOT -> s.copy(mode = PillState.Mode.MENU) to emptyList()
            PillState.Mode.MENU -> s.copy(mode = PillState.Mode.PILL) to emptyList()
            PillState.Mode.HIDDEN -> s to emptyList()
        }

        PillEvent.CloseMenu -> (if (s.mode == PillState.Mode.MENU) s.copy(mode = PillState.Mode.PILL) else s) to emptyList()

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

        PillEvent.Stop -> if (s.discarding) s to emptyList() else s.copy(mode = PillState.Mode.HIDDEN) to listOf(PillEffect.Stop)

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
                s.copy(elapsedMs = e.elapsedMs, mode = PillState.Mode.HIDDEN, discardAtMs = null) to listOf(PillEffect.Discard)
            } else {
                s.copy(elapsedMs = e.elapsedMs) to emptyList()
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
