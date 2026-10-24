package com.echoledger.mobile.recording

import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

class PcmTest {
    @Test
    fun `interleave then deinterleave round trips equal-length channels`() {
        val left = shortArrayOf(1, 2, 3, 4)
        val right = shortArrayOf(-1, -2, -3, -4)
        val (l, r) = Pcm.deinterleave(Pcm.interleave(left, right))
        assertEquals(left.toList(), l.toList())
        assertEquals(right.toList(), r.toList())
    }

    @Test
    fun `interleave pads the shorter channel with silence`() {
        val stereo = Pcm.interleave(shortArrayOf(5, 6, 7), shortArrayOf(9))
        // frames = 3 → [5,9, 6,0, 7,0]
        assertEquals(listOf<Short>(5, 9, 6, 0, 7, 0), stereo.toList())
    }

    @Test
    fun `little-endian conversion round trips`() {
        val samples = shortArrayOf(0, 1, -1, 32767, -32768, 256)
        val bytes = Pcm.toLittleEndianBytes(samples)
        assertEquals(samples.size * 2, bytes.size)
        assertEquals(samples.toList(), Pcm.fromLittleEndianBytes(bytes).toList())
    }

    @Test
    fun `downsample 48 to 16 produces a third of the samples by averaging`() {
        val input = ShortArray(9) { (it * 3).toShort() } // 0,3,6,9,12,15,18,21,24
        val out = Pcm.downsample48To16(input)
        assertEquals(3, out.size)
        assertEquals(listOf<Short>(3, 12, 21), out.toList())
    }

    @Test
    fun `duration is computed from frame count and rate`() {
        assertEquals(1000L, Pcm.durationMs(48_000))
        assertEquals(500L, Pcm.durationMs(24_000))
        assertEquals(0L, Pcm.durationMs(0))
    }

    @Test
    fun `rms is zero for silence and positive for signal`() {
        assertEquals(0f, Pcm.rms(ShortArray(128)), 0.0001f)
        assertTrue(Pcm.rms(ShortArray(128) { Short.MAX_VALUE }) > 0.9f)
    }
}
