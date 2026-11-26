package com.echoledger.mobile.ui.v2.library

import com.echoledger.mobile.core.ScreenMoments
import com.echoledger.mobile.data.ScreenDto
import com.echoledger.mobile.data.SegmentDto

/** One line of what was said around a screen: who (as shown), when in the recording (unknown in the server's text), the words. */
data class SaidLine(val speaker: String, val atMs: Long?, val text: String)

/** A screen as the v2 pages show it: the screen, its file name (only the server's) and what was said around it. */
data class ScreenMoment(val screen: ScreenDto, val fileName: String?, val said: List<SaidLine>)

/**
 * Meeting assets (v1.8 P4, contract 2.0.0-e), pure. The file name is the server's `file_name` only, never made up here
 * (as on the desktop). What was said is the server's `said` once it comes; until then it's made here the server's way
 * (web/app/Services/Screens/ScreenAssets.php): the lines within 15 s, "Speaker: words" with the names people see, at
 * most 600 characters ending in "…".
 *
 * TODO(phone core): ScreenDto and ProjectScreenDto don't read `file_name` and `said` yet; once they do, pass them in.
 */
object ScreenAssets {
    /** What was said this long before and after the screen. */
    const val AROUND_MS = 15_000L

    /** At most this many characters of it, as on the server. */
    const val SAID_CHARS = 600

    /** The lines said around the screen: the server's when sent, else the transcript's within [AROUND_MS], capped. */
    fun said(screen: ScreenDto, segments: List<SegmentDto>, serverSaid: String? = null, name: (String) -> String = { it }): List<SaidLine> {
        serverSaid?.takeIf { it.isNotBlank() }?.let { return parseSaid(it) }
        val from = screen.atMs - AROUND_MS
        val to = screen.atMs + AROUND_MS
        val lines = segments.filter { it.startMs <= to && maxOf(it.endMs, it.startMs) >= from }
            .sortedBy { it.startMs }
            .map { SaidLine(name(it.speaker), it.startMs, it.text.trim()) }
        return cap(lines)
    }

    /** At most [SAID_CHARS] characters as "Speaker: words" lines joined by new lines, cut with "…" as the server cuts. */
    fun cap(lines: List<SaidLine>): List<SaidLine> {
        fun shown(line: SaidLine) = if (line.speaker.isBlank()) line.text else "${line.speaker}: ${line.text}"
        val joined = lines.joinToString("\n", transform = ::shown)
        if (joined.length <= SAID_CHARS) return lines
        val pieces = (joined.take(SAID_CHARS - 1).trimEnd() + "…").split("\n")
        return pieces.mapIndexed { i, piece ->
            val line = lines[i]
            val prefix = if (line.speaker.isBlank()) "" else "${line.speaker}: "
            line.copy(text = if (piece.startsWith(prefix)) piece.removePrefix(prefix) else "…")
        }
    }

    /** The server's `said`: "Speaker: words", one per line (a line without a speaker keeps its words). */
    fun parseSaid(text: String): List<SaidLine> = text.lines().filter { it.isNotBlank() }.map { line ->
        val colon = line.indexOf(": ")
        if (colon > 0) SaidLine(line.take(colon).trim(), null, line.substring(colon + 2).trim()) else SaidLine("", null, line.trim())
    }

    /** Every screen of a recording, in time order, with what was said around it (speakers by [name]). */
    fun moments(screens: List<ScreenDto>, segments: List<SegmentDto>, name: (String) -> String = { it }): List<ScreenMoment> =
        screens.sortedBy { it.atMs }.map { ScreenMoment(it, fileName = null, said = said(it, segments, name = name)) }

    /** Line index → the screens shown after that line (the core's rule, so the phone and Copy for AI agree). */
    fun byLine(segments: List<SegmentDto>, screens: List<ScreenDto>): Map<Int, List<ScreenDto>> = ScreenMoments.byLine(segments, screens)

    /**
     * Screens by the line they show after, once some lines are skipped: a skipped line's screens move to the last line
     * shown before it, or to the first line shown when none is before it; none is lost while any line shows.
     */
    fun keptAfter(after: Map<Int, List<ScreenDto>>, all: List<Int>, kept: List<Int>): Map<Int, List<ScreenDto>> {
        if (kept.isEmpty()) return emptyMap()
        val keptSet = kept.toSet()
        val out = linkedMapOf<Int, MutableList<ScreenDto>>()
        var last: Int? = null
        val leading = mutableListOf<ScreenDto>()
        for (i in all) {
            val screens = after[i].orEmpty()
            if (i in keptSet) {
                last = i
                out.getOrPut(i) { mutableListOf() }.addAll(screens)
            } else {
                last?.let { out.getOrPut(it) { mutableListOf() }.addAll(screens) } ?: leading.addAll(screens)
            }
        }
        if (leading.isNotEmpty()) out.getOrPut(kept.first()) { mutableListOf() }.addAll(0, leading)
        return out
    }
}
