package com.echoledger.mobile.core

import com.echoledger.mobile.data.ProgressDto
import com.echoledger.mobile.data.SegmentDto
import com.echoledger.mobile.data.WordDto
import org.junit.Assert.assertEquals
import org.junit.Test

class TranscriptTextTest {
    private val call = listOf(
        SegmentDto(channel = 1, speaker = "Me", startMs = 0, endMs = 900, text = "Hello there."),
        SegmentDto(channel = 2, speaker = "Client", startMs = 12_400, endMs = 15_000, text = "Hi, thanks for calling."),
        SegmentDto(channel = 1, speaker = "Me", startMs = 3_725_000, endMs = 3_726_000, text = "Bye.")
    )

    @Test
    fun `without timestamps keeps speaker labels for a two-party call`() {
        assertEquals("Me: Hello there.\nClient: Hi, thanks for calling.\nMe: Bye.", TranscriptText.render(call, withTimestamps = false))
    }

    @Test
    fun `with timestamps prefixes each line and switches to hours when needed`() {
        assertEquals(
            "[0:00] Me: Hello there.\n[0:12] Client: Hi, thanks for calling.\n[1:02:05] Me: Bye.",
            TranscriptText.render(call, withTimestamps = true)
        )
    }

    @Test
    fun `a single speaker drops the redundant label`() {
        val note = listOf(
            SegmentDto(1, "Speaker", 0, 1000, "A short voice note."),
            SegmentDto(1, "Speaker", 1500, 2500, "Second sentence.")
        )
        assertEquals("A short voice note.\nSecond sentence.", TranscriptText.render(note, withTimestamps = false))
        assertEquals("[0:00] A short voice note.\n[0:01] Second sentence.", TranscriptText.render(note, withTimestamps = true))
    }

    @Test
    fun `empty transcripts render as an empty string`() {
        assertEquals("", TranscriptText.render(emptyList(), withTimestamps = true))
    }

    @Test
    fun `names the part of a long recording being transcribed`() {
        assertEquals("Transcribing… part 3 of 8", TranscriptText.transcribing(ProgressDto(2, 8)))
        assertEquals("Transcribing… part 8 of 8", TranscriptText.transcribing(ProgressDto(8, 8)))
        assertEquals("Transcribing…", TranscriptText.transcribing(ProgressDto(0, 1)))
        assertEquals("Transcribing…", TranscriptText.transcribing(null))
    }

    private val unsureLine = SegmentDto(
        channel = 1,
        speaker = "Me",
        startMs = 0,
        endMs = 2_000,
        text = "Send the invoice, [unclear].",
        words = listOf(
            WordDto("Send", confidence = 0.98),
            WordDto("the", confidence = 0.95),
            WordDto("invoice", confidence = 0.31),
            WordDto("[unclear]", confidence = 0.1)
        )
    )

    @Test
    fun `unsure words are marked in the console's style, and an edited line is copied as it is`() {
        assertEquals("Send the [?invoice], [unclear].", TranscriptText.markedLine(unsureLine, null, 0.5, "brackets"))
        val repeated = unsureLine.copy(
            text = "the cat saw the dog.",
            words = listOf(WordDto("the", confidence = 0.9), WordDto("cat", confidence = 0.9), WordDto("the", confidence = 0.2))
        )
        assertEquals("the cat saw [?the] dog.", TranscriptText.markedLine(repeated, null, 0.5, "brackets"))
        assertEquals("Send the invoice(?), [unclear].", TranscriptText.markedLine(unsureLine, null, 0.5, "suffix"))
        assertEquals("Send the invoice, [unclear].", TranscriptText.markedLine(unsureLine, null, 0.2, "brackets"))
        assertEquals("Edited", TranscriptText.markedLine(unsureLine.copy(text = "Edited", words = emptyList()), null, 0.5, "brackets"))
        // A view's text already carries the marks.
        assertEquals("kal meeting hai", TranscriptText.markedLine(unsureLine, "kal [?meeting] hai", 0.5, "off"))
    }

    @Test
    fun `copy for AI always starts with the note, copy only when the note is on and there are marks`() {
        val lines = listOf(unsureLine, unsureLine.copy(speaker = "Client", startMs = 1_000, text = "Fine", words = emptyList()))
        val note = TranscriptText.note("brackets")
        val marked = "Me: Send the [?invoice], [unclear].\nClient: Fine"
        val noLines = { _: Int -> null }
        assertEquals("$note\n\n$marked", TranscriptText.copied(lines, noLines, false, "brackets", 0.5, true))
        assertEquals(marked, TranscriptText.copied(lines, noLines, false, "brackets", 0.5, false))
        assertEquals("Me: Send the invoice, [unclear].\nClient: Fine", TranscriptText.copied(lines, noLines, false, "off", 0.5, true))
        assertEquals("$note\n\n$marked", TranscriptText.copied(lines, noLines, false, "off", 0.5, true, forAi = true))
    }
}
