package com.echoledger.mobile.core

import com.echoledger.mobile.data.SegmentDto
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
}
