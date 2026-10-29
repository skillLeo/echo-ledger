package com.echoledger.mobile.recording

import com.echoledger.mobile.recording.MicRetake.Probe
import com.echoledger.mobile.recording.MicRetake.Step
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class MicRetakeTest {
    private val rate = 1_000 // 1 frame = 1 ms
    private val notMuted = { false }

    /** Feeds [ms] of 10 ms reads starting at [from] ms; every step that was not NONE. */
    private fun MicRetake.feed(from: Long, ms: Int, zeros: Boolean, muted: () -> Boolean = notMuted): List<Step> =
        (0 until ms / 10).map { mic(10, zeros, from + it * 10L, muted) }.filter { it != Step.NONE }

    @Test
    fun `exact zeros for 1,5 s while not muted open a new recorder beside the old one`() {
        val r = MicRetake(rate)
        assertEquals(emptyList<Step>(), r.feed(0, 1490, zeros = true))
        assertEquals(listOf(Step.OPEN_CANDIDATE), r.feed(1490, 10, zeros = true))
        assertTrue(r.probing)
        // While it is probed, no second one is opened.
        assertEquals(emptyList<Step>(), r.feed(1500, 3000, zeros = true))
    }

    @Test
    fun `quiet sound is a real microphone and is never retaken`() {
        val r = MicRetake(rate)
        assertEquals(emptyList<Step>(), r.feed(0, 60_000, zeros = false))
        assertEquals(0, r.tried)
    }

    @Test
    fun `the call app's own mute is said once and never retaken, unmute is said when sound returns`() {
        val r = MicRetake(rate)
        var asked = 0
        val muted = {
            asked++
            true
        }
        val steps = r.feed(0, 20_000, zeros = true, muted = muted)
        assertEquals(listOf(Step.MUTED), steps)
        assertEquals(0, r.tried)
        assertTrue(r.muted)
        // Asked only once per 1,5 s of silence, not on every read.
        assertTrue(asked in 10..14)
        assertEquals(listOf(Step.UNMUTED), r.feed(20_000, 100, zeros = false))
        assertFalse(r.muted)
        // Muted again later: said again.
        assertEquals(listOf(Step.MUTED), r.feed(21_000, 1500, zeros = true, muted = muted))
    }

    @Test
    fun `a candidate with sound replaces the old recorder`() {
        val r = MicRetake(rate)
        r.feed(0, 1500, zeros = true)
        assertEquals(Probe.WAIT, r.candidate(0, true, 1500))
        assertEquals(Probe.WAIT, r.candidate(10, true, 1600))
        assertEquals(Probe.SWAP, r.candidate(10, false, 1700))
        assertFalse(r.probing)
    }

    @Test
    fun `a candidate that stays silent is dropped and the old recorder kept`() {
        // The Android 17 case: a capture started during the call app's capture is not made active.
        val r = MicRetake(rate)
        r.feed(0, 1500, zeros = true)
        assertEquals(Probe.WAIT, r.candidate(10, true, 2000))
        assertEquals(Probe.DROP, r.candidate(0, true, 2490))
        assertFalse(r.probing)
    }

    @Test
    fun `retakes are spaced 5 s apart and end with one give-up`() {
        val r = MicRetake(rate, maxTries = 2)
        var now = 0L
        val steps = mutableListOf<Step>()
        repeat(40) {
            steps += r.feed(now, 1000, zeros = true)
            if (r.probing) r.candidate(0, true, now + 5_000)
            now += 1000
        }
        assertEquals(listOf(Step.OPEN_CANDIDATE, Step.OPEN_CANDIDATE, Step.GIVE_UP), steps)
        assertEquals(2, r.tried)
    }

    @Test
    fun `a candidate that could not be opened lets a later retake try again`() {
        val r = MicRetake(rate, gapMs = 0)
        assertEquals(listOf(Step.OPEN_CANDIDATE), r.feed(0, 1500, zeros = true))
        r.candidateFailed()
        assertEquals(listOf(Step.OPEN_CANDIDATE), r.feed(1500, 10, zeros = true))
    }
}
