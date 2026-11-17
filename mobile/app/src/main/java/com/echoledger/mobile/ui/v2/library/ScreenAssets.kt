package com.echoledger.mobile.ui.v2.library

import com.echoledger.mobile.core.ScreenMoments
import com.echoledger.mobile.core.TranscriptText
import com.echoledger.mobile.data.ScreenDto
import com.echoledger.mobile.data.SegmentDto
import java.util.Locale

/** One line of what was said around a screen: who, when in the recording, and the words. */
data class SaidLine(val speaker: String, val atMs: Long, val text: String)

/** A screen as the v2 pages show it: the screen, its file name and what was said around it. */
data class ScreenMoment(val screen: ScreenDto, val fileName: String, val said: List<SaidLine>)

/**
 * Meeting assets (v1.8 P4, app side): names and "what was said" for a recording's screens, pure.
 *
 * The server's `file_name` and `said` (web P4) are not in the screens API yet; until they are, both are made here from
 * the screen and the transcript, the same way: "S07_14-32_orders-page.webp", and the lines within 15 s of the moment.
 * When the server sends them, pass them in and they win.
 */
object ScreenAssets {
    /** What was said within this much of a screen's moment, before and after. */
    const val AROUND_MS = 15_000L

    private const val SLUG_WORDS = 4
    private const val SLUG_MAX = 32

    /** "S07_14-32_orders-page.webp": the number (three digits past 99), the time in the recording, a short slug. */
    fun fileName(screen: ScreenDto, serverName: String? = null): String {
        serverName?.takeIf { it.isNotBlank() }?.let { return it }
        val number = if (screen.index > 99) "%03d".format(Locale.ROOT, screen.index) else "%02d".format(Locale.ROOT, screen.index)
        val time = TranscriptText.stamp(screen.atMs).replace(':', '-')
        return "S${number}_${time}_${slug(screen.description)}.${extension(screen.imageUrl)}"
    }

    /** The first few words of the description, lower case, letters and digits only; "screen" without one. */
    fun slug(description: String?): String {
        val words = description.orEmpty().lowercase(Locale.ROOT)
            .replace(Regex("['’]"), "")
            .split(Regex("[^a-z0-9]+"))
            .filter { it.isNotEmpty() && it !in SMALL_WORDS }
            .take(SLUG_WORDS)
        return words.joinToString("-").take(SLUG_MAX).trimEnd('-').ifEmpty { "screen" }
    }

    private val SMALL_WORDS = setOf("the", "a", "an", "of", "with", "and", "to", "on", "in")

    /** The picture's type from its link (the server keeps the type it was sent in); WebP when the link doesn't say. */
    fun extension(url: String?): String {
        val path = url.orEmpty().substringBefore('?').lowercase(Locale.ROOT)
        return when {
            path.endsWith(".png") -> "png"
            path.endsWith(".jpg") || path.endsWith(".jpeg") -> "jpg"
            else -> "webp"
        }
    }

    /** The lines that overlap the [AROUND_MS] window around the screen's moment, in time order. */
    fun said(screen: ScreenDto, segments: List<SegmentDto>, serverSaid: List<SaidLine>? = null): List<SaidLine> {
        serverSaid?.let { return it }
        val from = screen.atMs - AROUND_MS
        val to = screen.atMs + AROUND_MS
        return segments.filter { it.startMs <= to && maxOf(it.endMs, it.startMs) >= from }
            .sortedBy { it.startMs }
            .map { SaidLine(it.speaker, it.startMs, it.text) }
    }

    /** Every screen of a recording, in time order, with its name and what was said around it. */
    fun moments(screens: List<ScreenDto>, segments: List<SegmentDto>): List<ScreenMoment> =
        screens.sortedBy { it.atMs }.map { ScreenMoment(it, fileName(it), said(it, segments)) }

    /** Line index → the screens shown after that line (the core's rule, so the phone and Copy for AI agree). */
    fun byLine(segments: List<SegmentDto>, screens: List<ScreenDto>): Map<Int, List<ScreenDto>> = ScreenMoments.byLine(segments, screens)
}
