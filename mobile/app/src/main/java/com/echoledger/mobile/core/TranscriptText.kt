package com.echoledger.mobile.core

import com.echoledger.mobile.data.ProgressDto
import com.echoledger.mobile.data.SegmentDto
import java.util.Locale

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

    /** "Transcribing… part 3 of 8" while a long recording is transcribed in parts; "Transcribing…" otherwise. */
    fun transcribing(progress: ProgressDto?): String {
        if (progress == null || progress.total < 2) return "Transcribing…"
        return "Transcribing… part ${minOf(progress.total, progress.done + 1)} of ${progress.total}"
    }

    /** `m:ss`, or `h:mm:ss` from one hour. Unlike duration formatting, 0 ms renders as `0:00`. */
    fun stamp(ms: Long): String {
        val total = ms.coerceAtLeast(0) / 1000
        val h = total / 3600
        val m = (total % 3600) / 60
        val s = total % 60
        // ASCII digits whatever the phone language, so copied text reads the same everywhere.
        return if (h > 0) String.format(Locale.ROOT, "%d:%02d:%02d", h, m, s) else String.format(Locale.ROOT, "%d:%02d", m, s)
    }
}
