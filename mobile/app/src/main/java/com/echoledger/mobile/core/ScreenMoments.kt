package com.echoledger.mobile.core

import com.echoledger.mobile.data.ScreenDto
import com.echoledger.mobile.data.SegmentDto

/**
 * Screen moments in the transcript (since v1.5, pure, unit-tested): each screen sits after the last line that started
 * at or before its moment (a screen before the first line goes with it), and reads "Screen 7 · 14:32" on the page and
 * "[Screen 7 at 14:32: The invoice page]" in Copy for AI.
 */
object ScreenMoments {
    /** Line index → the screens shown after that line, in time order. */
    fun byLine(segments: List<SegmentDto>, screens: List<ScreenDto>): Map<Int, List<ScreenDto>> {
        if (segments.isEmpty() || screens.isEmpty()) return emptyMap()
        val starts = segments.map { it.startMs }
        return screens.sortedBy { it.atMs }.groupBy { screen -> starts.indexOfLast { it <= screen.atMs }.coerceAtLeast(0) }
    }

    fun label(screen: ScreenDto): String = "Screen ${screen.index} · ${TranscriptText.stamp(screen.atMs)}"

    fun marker(screen: ScreenDto): String {
        val about = screen.description?.takeIf { it.isNotBlank() }?.let { ": $it" }.orEmpty()
        return "[Screen ${screen.index} at ${TranscriptText.stamp(screen.atMs)}$about]"
    }
}
