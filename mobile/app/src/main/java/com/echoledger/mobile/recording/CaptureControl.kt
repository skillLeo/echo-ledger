package com.echoledger.mobile.recording

import com.echoledger.mobile.core.SourceApp
import com.echoledger.mobile.data.MarkerDto
import com.echoledger.mobile.data.PauseDto
import kotlin.math.sqrt

/**
 * Bookmarks and pause gaps of one recording (B2, B3). Times are recorded time (paused stretches excluded), so they
 * line up with the file and the transcript; a pause sits at the recorded time where it began and keeps its wall
 * length (docs/API.md "Bookmarks and pauses"). Thread-safe: the notification, the app and the audio thread use it.
 */
class RecordingNotes {
    private val markers = mutableListOf<MarkerDto>()
    private val pauses = mutableListOf<PauseDto>()
    private var pausedAtMs: Long? = null

    /** Adds a bookmark; null once the recording holds [MAX_MARKERS]. */
    @Synchronized
    fun mark(atMs: Long, tag: String? = null, note: String? = null): MarkerDto? {
        if (markers.size >= MAX_MARKERS) return null
        return MarkerDto(atMs.coerceAtLeast(0), clean(tag, MAX_TAG_CHARS), clean(note, MAX_NOTE_CHARS)).also { markers += it }
    }

    /** Sets the tag of the latest bookmark (blank clears it); false when there is none. */
    @Synchronized
    fun tagLast(tag: String?): Boolean {
        val last = markers.lastOrNull() ?: return false
        markers[markers.lastIndex] = last.copy(tag = clean(tag, MAX_TAG_CHARS))
        return true
    }

    /** Removes the latest bookmark ("Undo"). */
    @Synchronized
    fun undo(): MarkerDto? = markers.removeLastOrNull()

    @Synchronized
    fun count(): Int = markers.size

    @Synchronized
    fun last(): MarkerDto? = markers.lastOrNull()

    /** The recording paused at recorded time [atMs]; pausing twice keeps the first point. */
    @Synchronized
    fun paused(atMs: Long) {
        if (pausedAtMs == null) pausedAtMs = atMs.coerceAtLeast(0)
    }

    /**
     * The recording resumed after [pausedMs] of wall time: the gap is kept. Zero-length pauses are dropped; past
     * [MAX_PAUSES] the last gap absorbs the rest so the total paused time stays right.
     */
    @Synchronized
    fun resumed(pausedMs: Long) {
        val at = pausedAtMs ?: return
        pausedAtMs = null
        if (pausedMs <= 0) return
        if (pauses.size >= MAX_PAUSES) {
            val last = pauses.removeAt(pauses.lastIndex)
            pauses += last.copy(pausedMs = last.pausedMs + pausedMs)
            return
        }
        pauses += PauseDto(at, pausedMs)
    }

    /** Bookmarks in time order and the closed pauses; a pause still open at the end leaves no gap and is dropped. */
    @Synchronized
    fun finish(): Pair<List<MarkerDto>, List<PauseDto>> = markers.sortedBy { it.atMs } to pauses.toList()

    companion object {
        const val MAX_MARKERS = 500
        const val MAX_PAUSES = 200
        const val MAX_TAG_CHARS = 40
        const val MAX_NOTE_CHARS = 200
        const val MAX_PRESETS = 12

        /** Offered when the server sends no presets (the console's defaults). */
        val DEFAULT_TAGS = listOf("Requirement", "Budget", "Deadline", "Decision", "Follow-up")

        /** Trimmed, inner whitespace collapsed, cut to [max] characters (code points); blank is none. */
        fun clean(text: String?, max: Int): String? {
            val joined = text?.trim()?.split(Regex("\\s+"))?.filter { it.isNotEmpty() }?.joinToString(" ") ?: return null
            val points = joined.codePoints().limit(max.toLong()).toArray()
            val cut = String(points, 0, points.size).trimEnd()
            return cut.ifEmpty { null }
        }

        /** The presets to offer: the server's (cleaned, unique, at most 12), else [DEFAULT_TAGS]. */
        fun presets(server: List<String>?): List<String> {
            val tags = server.orEmpty().mapNotNull { clean(it, MAX_TAG_CHARS) }.distinct().take(MAX_PRESETS)
            return tags.ifEmpty { DEFAULT_TAGS }
        }
    }
}

/** Per-app auto-record switches (B1): the apps whose calls the phone detects, and what auto mode does with each. */
object AutoRecordRules {
    val APPS = listOf(SourceApp.WHATSAPP, SourceApp.WHATSAPP_BUSINESS)

    /** Apps without a stored switch are on. */
    fun records(app: SourceApp, switches: Map<String, Boolean>): Boolean = switches[app.wire] != false

    /** The explainer line for one app. */
    fun explain(app: SourceApp, switches: Map<String, Boolean>, armed: Boolean): String = when {
        !armed -> "Auto-record is off. ${app.label} calls are not recorded unless you start a recording."
        !records(app, switches) -> "Off for ${app.label}: its calls are ignored unless you start a recording."
        else -> "${app.label} calls are recorded automatically and you decide after the call."
    }
}

/** `paused`/`silent` changes of the "not hearing the other side" warning (B5). */
data class FarSideChange(val silent: Boolean, val seconds: Long)

/** One warning per silent stretch of the other side, one all-clear when it is heard again (or pauses / ends). */
class FarSideMonitor {
    private var warned = false

    /** [silentMs] is null while no two-sided recording runs or it is paused. */
    fun tick(silentMs: Long?, enabled: Boolean): FarSideChange? = when {
        silentMs != null && enabled && silentMs >= SILENT_MS && !warned -> {
            warned = true
            FarSideChange(true, silentMs / 1_000)
        }

        warned && (silentMs == null || silentMs < SILENT_MS || !enabled) -> {
            warned = false
            FarSideChange(false, 0)
        }

        else -> null
    }

    companion object {
        const val SILENT_MS = 30_000L
    }
}

/** Signal levels for meters, the test recording and the far-side check (B5). */
object Levels {
    /** RMS (0-1) above which audio counts as heard (about -54 dBFS). */
    const val HEARD = 0.002f

    fun rms(samples: ShortArray, from: Int = 0, to: Int = samples.size): Float {
        if (to <= from) return 0f
        var sum = 0.0
        for (i in from until to) {
            val s = samples[i] / 32768.0
            sum += s * s
        }
        return sqrt(sum / (to - from)).toFloat()
    }

    /** The loudest window of [window] samples. */
    fun peak(samples: ShortArray, window: Int): Float {
        var best = 0f
        var start = 0
        while (start < samples.size) {
            best = maxOf(best, rms(samples, start, minOf(samples.size, start + window)))
            start += window
        }
        return best
    }

    /** Meter fill (0-1); RMS 0.25 fills the bar, as on the desktop. */
    fun meter(rms: Float): Float = (rms * 4).coerceIn(0f, 1f)
}

/** The recording as the Record tab shows it (published by the recording service once a second and on changes). */
data class LiveRecording(
    val active: Boolean = false,
    val paused: Boolean = false,
    val elapsedMs: Long = 0,
    val twoSided: Boolean = false,
    val meLevel: Float = 0f,
    val clientLevel: Float = 0f,
    val markers: Int = 0,
    val lastMarker: MarkerDto? = null,
    /** Seconds the other side has been silent, while the warning shows. */
    val farSideSilentSeconds: Long? = null,
    /** The recording's language chip (null = automatic); changing it reconnects live captions. */
    val language: String? = null
)
