package com.echoledger.mobile.recording

import org.junit.Assert.assertArrayEquals
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

class SlotPairerTest {
    private val rate = 1_000 // 1 frame = 1 ms, so times read as frames
    private val ms = 1_000_000L

    private fun ramp(from: Int, count: Int) = ShortArray(count) { (from + it).toShort() }

    private fun left(stereo: ShortArray) = ShortArray(stereo.size / 2) { stereo[2 * it] }

    private fun right(stereo: ShortArray) = ShortArray(stereo.size / 2) { stereo[2 * it + 1] }

    @Test
    fun `two sides delivered out of step are paired by capture time`() {
        val p = SlotPairer(rate)
        // My side arrives in 10 ms reads, the other side in one late 40 ms burst that started at the same time.
        p.push(0, 0, ramp(1, 10))
        p.push(0, 10 * ms, ramp(11, 10))
        p.push(0, 20 * ms, ramp(21, 10))
        p.push(0, 30 * ms, ramp(31, 10))
        p.push(1, 0, ramp(101, 40))
        val out = p.drain()
        assertEquals(40, out.size / 2)
        assertArrayEquals(ramp(1, 40), left(out))
        assertArrayEquals(ramp(101, 40), right(out))
    }

    @Test
    fun `only whole 20 ms slots are released while both sides keep up`() {
        val p = SlotPairer(rate)
        p.push(0, 0, ramp(1, 30))
        p.push(1, 0, ramp(1, 25))
        assertEquals(20, p.drain().size / 2)
        assertEquals(0, p.drain().size)
        assertEquals(10, p.flush().size / 2)
    }

    @Test
    fun `a gap in one side is filled with silence and the sides stay in sync`() {
        val p = SlotPairer(rate)
        p.push(0, 0, ramp(1, 80))
        p.push(1, 0, ramp(1, 20))
        p.push(1, 60 * ms, ramp(61, 20)) // 40 ms of the other side never came
        val out = p.drain()
        val r = right(out)
        assertEquals(80, r.size)
        assertArrayEquals(ramp(1, 20), r.copyOfRange(0, 20))
        assertTrue(r.copyOfRange(20, 60).all { it.toInt() == 0 })
        assertArrayEquals(ramp(61, 20), r.copyOfRange(60, 80))
        assertArrayEquals(ramp(1, 80), left(out))
        assertEquals(40, p.gapMs(1))
    }

    @Test
    fun `a clock jump beyond 2 s is not believed and memory stays small`() {
        val p = SlotPairer(rate)
        p.push(0, 0, ramp(1, 20))
        p.push(1, 0, ramp(1, 20))
        p.push(0, 3_600_000 * ms, ramp(21, 20)) // a glitch: an hour later
        p.push(1, 20 * ms, ramp(21, 20))
        val out = p.drain()
        assertArrayEquals(ramp(1, 40), left(out))
        assertArrayEquals(ramp(1, 40), right(out))
        assertEquals(0, p.gapMs(0))
    }

    @Test
    fun `small timing jitter keeps a side's samples back to back`() {
        val p = SlotPairer(rate)
        p.push(0, 0, ramp(1, 20))
        p.push(0, 27 * ms, ramp(21, 20)) // 7 ms late, under the jitter allowance
        p.push(1, 0, ramp(1, 40))
        val l = left(p.drain())
        assertArrayEquals(ramp(1, 40), l)
        assertEquals(0, p.gapMs(0))
    }

    @Test
    fun `a stalled side is written as silence once the other is far enough ahead`() {
        val p = SlotPairer(rate, maxLagMs = 100)
        p.push(1, 0, ramp(1, 10))
        p.push(0, 0, ramp(1, 200))
        val out = p.drain()
        assertEquals(100, out.size / 2)
        assertArrayEquals(ramp(1, 10), right(out).copyOfRange(0, 10))
        assertTrue(right(out).copyOfRange(10, 100).all { it.toInt() == 0 })
        // The stalled side's late samples for slots already written are dropped, never shifted.
        p.push(1, 10 * ms, ramp(11, 100))
        assertEquals(90, p.lateMs(1))
        val rest = p.flush()
        assertArrayEquals(ramp(101, 10), right(rest).copyOfRange(0, 10))
    }

    @Test
    fun `a side that started earlier than the first one seen moves the start back`() {
        val p = SlotPairer(rate)
        p.push(0, 50 * ms, ramp(1, 20))
        p.push(1, 30 * ms, ramp(101, 40)) // captured 20 ms before my first sample
        val out = p.flush()
        assertEquals(40, out.size / 2)
        assertArrayEquals(ramp(101, 40), right(out))
        assertTrue(left(out).copyOfRange(0, 20).all { it.toInt() == 0 })
        assertArrayEquals(ramp(1, 20), left(out).copyOfRange(20, 40))
    }

    @Test
    fun `an hour at 48 kHz never overflows the timeline`() {
        val p = SlotPairer(48_000)
        val hour = 3_600L * 1_000_000_000L
        p.push(0, 0, ShortArray(960))
        p.push(1, 0, ShortArray(960))
        p.drain()
        p.push(0, hour, ShortArray(960) { 7 })
        p.push(1, hour, ShortArray(960) { 9 })
        val out = p.drain()
        assertTrue(out.isNotEmpty())
        assertEquals(7, left(out).last().toInt())
        assertEquals(9, right(out).last().toInt())
    }
}

class SilenceWatchTest {
    private val rate = 48_000

    private fun block(ms: Int, me: Int, other: Int) = ShortArray(rate / 1000 * ms * 2) {
        if (it % 2 ==
            0
        ) {
            me.toShort()
        } else {
            other.toShort()
        }
    }

    @Test
    fun `a microphone giving exact zeros for 1,5 s is dead, any noise is alive`() {
        val w = SilenceWatch(rate)
        repeat(70) { w.feed(block(20, 0, 50), micOpen = true) }
        assertTrue(!w.micDead())
        repeat(10) { w.feed(block(20, 0, 50), micOpen = true) }
        assertTrue(w.micDead())
        w.micRetaken()
        assertTrue(!w.micDead())
        repeat(100) { w.feed(block(20, 1, 50), micOpen = true) }
        assertTrue(!w.micDead())
    }

    @Test
    fun `a closed microphone is never called dead`() {
        val w = SilenceWatch(rate)
        repeat(200) { w.feed(block(20, 0, 50), micOpen = false) }
        assertTrue(!w.micDead())
    }

    @Test
    fun `the other side silent while I talk 8 s is reported once`() {
        val w = SilenceWatch(rate)
        repeat(350) { w.feed(block(20, 2000, 0), micOpen = true) }
        assertTrue(!w.farSilent())
        repeat(60) { w.feed(block(20, 2000, 0), micOpen = true) }
        assertTrue(w.farSilent())
        assertTrue(!w.farSilent())
    }

    @Test
    fun `an other side that was heard once is never reported, nor is a quiet me`() {
        val heard = SilenceWatch(rate)
        heard.feed(block(20, 2000, 300), micOpen = true)
        repeat(500) { heard.feed(block(20, 2000, 0), micOpen = true) }
        assertTrue(!heard.farSilent())
        val quiet = SilenceWatch(rate)
        repeat(500) { quiet.feed(block(20, 40, 0), micOpen = true) }
        assertTrue(!quiet.farSilent())
    }
}

class CaptureLevelsTest {
    @Test
    fun `levels are per channel in dBFS`() {
        val levels = CaptureLevels(2)
        levels.add(ShortArray(2000) { if (it % 2 == 0) 16384 else 0 })
        assertEquals(-6.0, levels.rmsDb(0), 0.1)
        assertEquals(-6.0, levels.peakDb(0), 0.1)
        assertEquals(-120.0, levels.rmsDb(1), 0.1)
        assertEquals("me rms -6.0 peak -6.0 dBFS; other rms -120.0 peak -120.0 dBFS", levels.describe())
    }

    @Test
    fun `a recording is silent only when every side is digital silence`() {
        val silent = CaptureLevels(2)
        silent.add(ShortArray(4800) { if (it % 7 == 0) 1 else 0 })
        assertTrue(silent.silent())
        val oneSided = CaptureLevels(2)
        oneSided.add(ShortArray(4800) { if (it % 2 == 0) 0 else 900 })
        assertTrue(!oneSided.silent())
    }

    @Test
    fun `a debug WAV header describes 16-bit PCM`() {
        val h = DebugWav.header(9600, 2, 48_000)
        assertEquals(44, h.size)
        assertEquals("RIFF", String(h, 0, 4))
        assertEquals("WAVE", String(h, 8, 4))
        assertEquals("data", String(h, 36, 4))
    }
}
