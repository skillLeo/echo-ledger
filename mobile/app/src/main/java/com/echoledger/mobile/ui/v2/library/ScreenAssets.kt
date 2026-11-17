package com.echoledger.mobile.ui.v2.library

import com.echoledger.mobile.core.ScreenMoments
import com.echoledger.mobile.core.TranscriptText
import com.echoledger.mobile.data.ScreenDto
import com.echoledger.mobile.data.SegmentDto
import java.text.Normalizer
import java.util.Locale

/** One line of what was said around a screen: who, when in the recording (unknown in the server's text), the words. */
data class SaidLine(val speaker: String, val atMs: Long?, val text: String)

/** A screen as the v2 pages show it: the screen, its file name and what was said around it. */
data class ScreenMoment(val screen: ScreenDto, val fileName: String, val said: List<SaidLine>)

/**
 * Meeting assets (v1.8 P4, contract 2.0.0-e), pure: a screen's file name and what was said around it, by the server's
 * rules (web/app/Services/Screens/ScreenAssets.php), so the phone names a screen as the meeting folder does.
 *
 * TODO(phone core): ScreenDto and ProjectScreenDto don't read `file_name` and `said` yet. Until they do, both are made
 * here the same way from the screen and the transcript; once read, pass them in and they win.
 */
object ScreenAssets {
    /** What was said this long before and after the screen. */
    const val AROUND_MS = 15_000L

    private const val SLUG_CHARS = 40
    private const val SLUG_SOURCE = 120

    /**
     * "S07_14-32_orders-page.webp": the number (as many digits as the last one, [count], needs, at least two), the
     * moment (h-mm-ss past an hour) and a slug of the description of up to 40 characters ("screen" without one).
     */
    fun fileName(screen: ScreenDto, count: Int = screen.index, serverName: String? = null): String {
        serverName?.takeIf { it.isNotBlank() }?.let { return it }
        val digits = maxOf(2, maxOf(count, screen.index).toString().length)
        val number = screen.index.toString().padStart(digits, '0')
        val time = TranscriptText.stamp(screen.atMs).replace(':', '-')
        return "S${number}_${time}_${slug(screen.description)}.${extension(screen.imageUrl)}"
    }

    /** Laravel's Str::slug of the description: plain letters and digits joined by dashes, cut at a word. */
    fun slug(description: String?): String {
        val ascii = Normalizer.normalize(description.orEmpty().take(SLUG_SOURCE), Normalizer.Form.NFD).replace(Regex("\\p{M}+"), "")
        var slug = ascii.replace('_', '-').replace("@", "-at-").lowercase(Locale.ROOT)
            .replace(Regex("[^-a-z0-9\\s]+"), "")
            .replace(Regex("[-\\s]+"), "-")
            .trim('-')
        if (slug.length > SLUG_CHARS) slug = slug.take(SLUG_CHARS + 1).replace(Regex("-[^-]*$"), "").trimEnd('-')
        return slug.ifEmpty { "screen" }
    }

    /** The picture's type from its link (the server keeps the type it was sent in); WebP when the link doesn't say. */
    fun extension(url: String?): String {
        val path = url.orEmpty().substringBefore('?').lowercase(Locale.ROOT)
        return when {
            path.endsWith(".png") -> "png"
            path.endsWith(".jpg") || path.endsWith(".jpeg") -> "jpg"
            else -> "webp"
        }
    }

    /** The lines that overlap the [AROUND_MS] window around the screen's moment, in time order; the server's when sent. */
    fun said(screen: ScreenDto, segments: List<SegmentDto>, serverSaid: String? = null): List<SaidLine> {
        serverSaid?.takeIf { it.isNotBlank() }?.let { return parseSaid(it) }
        val from = screen.atMs - AROUND_MS
        val to = screen.atMs + AROUND_MS
        return segments.filter { it.startMs <= to && maxOf(it.endMs, it.startMs) >= from }
            .sortedBy { it.startMs }
            .map { SaidLine(it.speaker, it.startMs, it.text) }
    }

    /** The server's `said`: "Speaker: words", one per line (a line without a speaker keeps its words). */
    fun parseSaid(text: String): List<SaidLine> = text.lines().filter { it.isNotBlank() }.map { line ->
        val colon = line.indexOf(": ")
        if (colon > 0) SaidLine(line.take(colon).trim(), null, line.substring(colon + 2).trim()) else SaidLine("", null, line.trim())
    }

    /** Every screen of a recording, in time order, with its name and what was said around it. */
    fun moments(screens: List<ScreenDto>, segments: List<SegmentDto>): List<ScreenMoment> =
        screens.sortedBy { it.atMs }.map { ScreenMoment(it, fileName(it, screens.size), said(it, segments)) }

    /** Line index → the screens shown after that line (the core's rule, so the phone and Copy for AI agree). */
    fun byLine(segments: List<SegmentDto>, screens: List<ScreenDto>): Map<Int, List<ScreenDto>> = ScreenMoments.byLine(segments, screens)
}
