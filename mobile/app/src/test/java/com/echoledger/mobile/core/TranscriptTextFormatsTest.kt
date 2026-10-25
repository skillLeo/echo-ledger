package com.echoledger.mobile.core

import com.echoledger.mobile.data.SegmentDto
import java.util.Locale
import kotlin.random.Random
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Test

/** The plain-text copy formats, which must be identical on Android, desktop and the web console. */
class TranscriptTextFormatsTest {
    private fun seg(speaker: String, startMs: Long, text: String, channel: Int = if (speaker == "Client") 2 else 1) =
        SegmentDto(channel, speaker, startMs, startMs + 1000, text)

    // ---- timestamps --------------------------------------------------------------------------------------------------

    @Test
    fun `stamp rounds down to whole seconds at every boundary`() {
        mapOf(
            0L to "0:00",
            999L to "0:00",
            1_000L to "0:01",
            59_999L to "0:59",
            60_000L to "1:00",
            599_999L to "9:59",
            600_000L to "10:00",
            3_599_999L to "59:59",
            3_600_000L to "1:00:00",
            3_661_000L to "1:01:01",
            36_000_000L to "10:00:00",
            359_999_000L to "99:59:59"
        ).forEach { (ms, expected) -> assertEquals("$ms ms", expected, TranscriptText.stamp(ms)) }
    }

    @Test
    fun `negative and extreme timestamps never throw`() {
        assertEquals("0:00", TranscriptText.stamp(-1))
        assertEquals("0:00", TranscriptText.stamp(Long.MIN_VALUE))
        assertFalse(TranscriptText.stamp(Long.MAX_VALUE).isBlank())
    }

    // ---- rendering -----------------------------------------------------------------------------------------------------

    @Test
    fun `lines are joined with a single newline and nothing is added at the end`() {
        val text = TranscriptText.render(listOf(seg("Me", 0, "One."), seg("Client", 1000, "Two.")), withTimestamps = false)
        assertEquals("Me: One.\nClient: Two.", text)
        assertFalse(text.endsWith("\n"))
    }

    @Test
    fun `a single segment drops the speaker label in both modes`() {
        val one = listOf(seg("Me", 5_000, "Only line."))
        assertEquals("Only line.", TranscriptText.render(one, withTimestamps = false))
        assertEquals("[0:05] Only line.", TranscriptText.render(one, withTimestamps = true))
    }

    @Test
    fun `speaker labels are compared exactly, so differing case counts as two speakers`() {
        val text = TranscriptText.render(listOf(seg("me", 0, "a", 1), seg("Me", 1000, "b", 1)), withTimestamps = false)
        assertEquals("me: a\nMe: b", text)
    }

    @Test
    fun `text containing newlines colons brackets and unicode is copied verbatim`() {
        val segments = listOf(
            seg("Me", 0, "Line one\nLine two"),
            seg("Client", 2_000, "Total: [12:30] — café 你好 😀"),
            seg("Me", 4_000, "مرحبا שלום")
        )
        assertEquals(
            "[0:00] Me: Line one\nLine two\n[0:02] Client: Total: [12:30] — café 你好 😀\n[0:04] Me: مرحبا שלום",
            TranscriptText.render(segments, withTimestamps = true)
        )
    }

    @Test
    fun `segments keep the order they were given even when timestamps go backwards`() {
        val text = TranscriptText.render(listOf(seg("Me", 9_000, "later", 1), seg("Client", 1_000, "earlier")), withTimestamps = true)
        assertEquals("[0:09] Me: later\n[0:01] Client: earlier", text)
    }

    @Test
    fun `an empty speaker name renders as a bare colon prefix only when there are several speakers`() {
        assertEquals("hi", TranscriptText.render(listOf(seg("", 0, "hi", 1)), withTimestamps = false))
        assertEquals(": hi\nMe: yo", TranscriptText.render(listOf(seg("", 0, "hi", 1), seg("Me", 1000, "yo", 1)), withTimestamps = false))
    }

    @Test
    fun `output matches the desktop and web console algorithm on generated transcripts`() {
        val random = Random(20261024)
        repeat(200) {
            val speakers = listOf("Me", "Client").take(1 + random.nextInt(2))
            val segments = List(random.nextInt(0, 12)) {
                SegmentDto(1, speakers.random(random), random.nextLong(0, 40_000_000), 0, "text ${random.nextInt(1000)} é")
            }
            assertEquals(desktopTranscriptText(segments, true), TranscriptText.render(segments, true))
            assertEquals(desktopTranscriptText(segments, false), TranscriptText.render(segments, false))
        }
    }

    // ---- locale independence ---------------------------------------------------------------------------------------

    @Test
    fun `timestamps stay in ASCII digits whatever the phone language is`() {
        val saved = Locale.getDefault()
        try {
            listOf(
                Locale.forLanguageTag("ar-EG"),
                Locale.forLanguageTag("fa-IR"),
                Locale.forLanguageTag("hi-IN"),
                Locale.GERMANY,
                Locale.US
            ).forEach { locale ->
                Locale.setDefault(locale)
                assertEquals("0:12 in $locale", "0:12", TranscriptText.stamp(12_400))
                assertEquals("1:02:05 in $locale", "1:02:05", TranscriptText.stamp(3_725_000))
                assertEquals(
                    "copy format in $locale",
                    "[0:12] Me: a\n[1:02:05] Client: b",
                    TranscriptText.render(listOf(seg("Me", 12_400, "a", 1), seg("Client", 3_725_000, "b")), withTimestamps = true)
                )
            }
        } finally {
            Locale.setDefault(saved)
        }
    }

    /** Line-for-line port of transcriptText() and timestamp() in desktop/src/lib/format.ts. */
    private fun desktopTranscriptText(segments: List<SegmentDto>, withTimestamps: Boolean): String {
        fun timestamp(ms: Long): String {
            val total = maxOf(0L, ms / 1000)
            val h = total / 3600
            val m = (total % 3600) / 60
            val s = total % 60
            val mm = if (h > 0) m.toString().padStart(2, '0') else m.toString()
            return (if (h > 0) "$h:" else "") + mm + ":" + s.toString().padStart(2, '0')
        }
        val single = segments.map { it.speaker }.toSet().size <= 1
        return segments.joinToString("\n") { s ->
            (if (withTimestamps) "[${timestamp(s.startMs)}] " else "") + (if (single) "" else "${s.speaker}: ") + s.text
        }
    }
}
