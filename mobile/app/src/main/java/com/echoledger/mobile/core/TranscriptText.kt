package com.echoledger.mobile.core

import com.echoledger.mobile.data.SegmentDto

/**
 * Plain-text export of a transcript, identical on Android, desktop and the web console (pure; unit-tested):
 * one line per segment, `[m:ss] ` prefix when timestamps are requested, `Speaker: ` prefix unless only one speaker.
 */
object TranscriptText {
    fun render(segments: List<SegmentDto>, withTimestamps: Boolean): String {
        val singleSpeaker = segments.map { it.speaker }.distinct().size <= 1
        return segments.joinToString("\n") { s ->
            buildString {
                if (withTimestamps) append('[').append(stamp(s.startMs)).append("] ")
                if (!singleSpeaker) append(s.speaker).append(": ")
                append(s.text)
            }
        }
    }

    /** `m:ss`, or `h:mm:ss` from one hour. Unlike duration formatting, 0 ms renders as `0:00`. */
    fun stamp(ms: Long): String {
        val total = ms.coerceAtLeast(0) / 1000
        val h = total / 3600
        val m = (total % 3600) / 60
        val s = total % 60
        return if (h > 0) "%d:%02d:%02d".format(h, m, s) else "%d:%02d".format(m, s)
    }
}
