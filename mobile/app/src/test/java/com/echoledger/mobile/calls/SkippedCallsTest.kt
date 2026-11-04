package com.echoledger.mobile.calls

import com.echoledger.mobile.data.LocalRecording
import java.time.Instant
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

class SkippedCallsTest {
    private fun rec(id: String, state: String, skippedAt: String?) = LocalRecording(
        id = id,
        sourceApp = "whatsapp",
        startedAt = "2026-11-04T10:00:00Z",
        endedAt = "2026-11-04T10:01:00Z",
        durationMs = 4_000,
        mime = "audio/ogg",
        codec = "opus",
        channels = 2,
        sampleRate = 48_000,
        plainSize = 0,
        sha256 = "",
        state = state,
        skippedReason = if (state == SkippedCalls.STATE) "too_short" else null,
        skippedAt = skippedAt
    )

    @Test
    fun `unanswered, short and silent calls are skipped, a real call and anything started by hand are kept`() {
        val talk = listOf(30_000L, 25_000L)
        assertEquals(
            SkippedCalls.Reason.NOT_ANSWERED,
            SkippedCalls.reason(true, answered = false, keptMs = 40_000, shorterThanS = 10, speechMs = talk)
        )
        assertEquals(
            SkippedCalls.Reason.TOO_SHORT,
            SkippedCalls.reason(true, answered = true, keptMs = 9_000, shorterThanS = 10, speechMs = talk)
        )
        assertEquals(SkippedCalls.Reason.NO_SPEECH, SkippedCalls.reason(true, true, 60_000, 10, listOf(200L, 900L)))
        assertNull(SkippedCalls.reason(true, true, 60_000, 10, listOf(200L, 4_000L)))
        assertNull(SkippedCalls.reason(true, true, 9_000, 0, talk))
        assertNull(SkippedCalls.reason(false, answered = false, keptMs = 2_000, shorterThanS = 10, speechMs = listOf(0L)))
    }

    @Test
    fun `skipped calls expire after a day and the line counts them`() {
        val now = Instant.parse("2026-11-05T12:00:00Z")
        val all = listOf(
            rec("old", SkippedCalls.STATE, "2026-11-04T11:59:00Z"),
            rec("fresh", SkippedCalls.STATE, "2026-11-05T09:00:00Z"),
            rec("kept", "queued", null)
        )
        assertEquals(listOf("old"), SkippedCalls.expired(all, now).map { it.id })
        assertEquals("Skipped 1 short call", SkippedCalls.line(1))
        assertEquals("Skipped 3 short calls", SkippedCalls.line(3))
        assertEquals(SkippedCalls.Reason.TOO_SHORT, SkippedCalls.reasonOf(all[0]))
    }

    @Test
    fun `the speech meter counts loud frames per side only`() {
        val meter = SpeechMeter(channels = 2)
        val loudLeft = ShortArray(48_000 * 2) { i -> if (i % 2 == 0) (if ((i / 2) % 20 < 10) 3_000 else -3_000).toShort() else 1 }
        meter.add(loudLeft)
        val (me, other) = meter.speechMs()
        assertEquals(1_000L, me)
        assertEquals(0L, other)
    }
}
