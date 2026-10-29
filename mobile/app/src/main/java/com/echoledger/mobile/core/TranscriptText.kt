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

    private val MARKED = Regex("""\[\?([^\]]+)]""")

    /** "[?word]" in the console's style: kept (`brackets`), "word(?)" (`suffix`), or plain (`off`). */
    fun restyle(text: String, style: String): String = when (style) {
        "suffix" -> MARKED.replace(text, "$1(?)")
        "off" -> MARKED.replace(text, "$1")
        else -> text
    }

    /** The first line of Copy for AI: what the marks mean, in [style]. */
    fun note(style: String): String =
        "Note: ${if (style == "suffix") "word(?)" else "[?word]"} = the transcription is unsure about this word; [unclear] = inaudible audio."

    /**
     * A line as copied (plan A4): the view's text when one is shown (it already carries "[?word]"), else the words with
     * the unsure ones (scored below [threshold]) marked; an edited line has no words and is copied as it is.
     */
    fun markedLine(segment: SegmentDto, inView: String?, threshold: Double, style: String): String {
        if (inView != null) return restyle(inView, style)
        // The line keeps its own text (punctuation, words without timing); each unsure word is found in order and wrapped.
        val line = segment.text
        val out = StringBuilder()
        var at = 0
        for (w in segment.words) {
            val word = w.text.trim()
            val pos = if (word.isEmpty()) -1 else line.indexOf(word, at)
            if (pos < 0) continue
            val unsure = w.confidence?.let { it < threshold } == true && !word.startsWith("[")
            out.append(line, at, pos).append(if (unsure) "[?$word]" else word)
            at = pos + word.length
        }
        return restyle(out.append(line.substring(at)).toString(), style)
    }

    /**
     * What Copy puts on the clipboard: the note line first for Copy for AI (always, with "[?word]" marks even when the
     * console turned marks off), or when the console has the note on and the text has marks.
     */
    fun copied(
        segments: List<SegmentDto>,
        inView: (Int) -> String?,
        withTimestamps: Boolean,
        style: String,
        threshold: Double,
        noteOn: Boolean,
        forAi: Boolean = false
    ): String {
        val used = if (forAi && style == "off") "brackets" else style
        val body = render(segments.mapIndexed { i, s -> s.copy(text = markedLine(s, inView(i), threshold, used)) }, withTimestamps)
        val marked = MARKED.containsMatchIn(body) || body.contains("[unclear") || body.contains("(?)")
        return if (forAi || (noteOn && used != "off" && marked)) note(used) + "\n\n" + body else body
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
