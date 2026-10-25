package com.echoledger.mobile.recording

import kotlin.random.Random
import org.junit.Assert.assertArrayEquals
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/** Boundary behaviour of the PCM helpers used by capture, encoding and live captions. */
class PcmEdgeCasesTest {
    @Test
    fun `downsampling drops a trailing partial group and handles empty input`() {
        assertEquals(0, Pcm.downsample48To16(ShortArray(0)).size)
        assertEquals(0, Pcm.downsample48To16(shortArrayOf(1, 2)).size)
        assertEquals(listOf<Short>(2), Pcm.downsample48To16(shortArrayOf(1, 2, 3, 99, 98)).toList())
    }

    @Test
    fun `downsampling full-scale samples does not overflow`() {
        assertEquals(
            listOf(Short.MAX_VALUE),
            Pcm.downsample48To16(shortArrayOf(Short.MAX_VALUE, Short.MAX_VALUE, Short.MAX_VALUE)).toList()
        )
        assertEquals(
            listOf(Short.MIN_VALUE),
            Pcm.downsample48To16(shortArrayOf(Short.MIN_VALUE, Short.MIN_VALUE, Short.MIN_VALUE)).toList()
        )
    }

    @Test
    fun `downsampling a constant signal keeps its level and a sine keeps its energy`() {
        val dc = ShortArray(300) { 1_000 }
        assertTrue(Pcm.downsample48To16(dc).all { it == 1_000.toShort() })

        val tone = ShortArray(4_800) { (12_000 * Math.sin(2 * Math.PI * 440 * it / 48_000.0)).toInt().toShort() }
        val level = Pcm.rms(tone)
        val reduced = Pcm.rms(Pcm.downsample48To16(tone))
        assertTrue("440 Hz must survive decimation: $level -> $reduced", reduced > level * 0.9f)
    }

    @Test
    fun `deinterleave ignores a dangling half frame`() {
        val (left, right) = Pcm.deinterleave(shortArrayOf(1, 2, 3, 4, 5))
        assertEquals(listOf<Short>(1, 3), left.toList())
        assertEquals(listOf<Short>(2, 4), right.toList())
        assertEquals(0, Pcm.deinterleave(ShortArray(0)).first.size)
    }

    @Test
    fun `interleave of two empty channels is empty`() {
        assertEquals(0, Pcm.interleave(ShortArray(0), ShortArray(0)).size)
        assertEquals(listOf<Short>(0, 7), Pcm.interleave(ShortArray(0), shortArrayOf(7)).toList())
    }

    @Test
    fun `little-endian conversion honours the length argument and ignores a dangling byte`() {
        val bytes = byteArrayOf(0x01, 0x00, 0x02, 0x00, 0x03)
        assertEquals(listOf<Short>(1, 2), Pcm.fromLittleEndianBytes(bytes).toList())
        assertEquals(listOf<Short>(1), Pcm.fromLittleEndianBytes(bytes, length = 2).toList())
        assertEquals(0, Pcm.fromLittleEndianBytes(bytes, length = 1).size)
        assertEquals(0, Pcm.fromLittleEndianBytes(ByteArray(0)).size)
    }

    @Test
    fun `random sample buffers survive the byte round trip and the stereo round trip`() {
        val random = Random(7)
        repeat(100) {
            val samples =
                ShortArray(random.nextInt(0, 500)) { random.nextInt(Short.MIN_VALUE.toInt(), Short.MAX_VALUE.toInt() + 1).toShort() }
            assertArrayEquals(samples, Pcm.fromLittleEndianBytes(Pcm.toLittleEndianBytes(samples)))
            val (l, r) = Pcm.deinterleave(Pcm.interleave(samples, samples.reversedArray()))
            assertArrayEquals(samples, l)
            assertArrayEquals(samples.reversedArray(), r)
        }
    }

    @Test
    fun `rms stays within zero and one even for full-scale negative samples`() {
        val full = Pcm.rms(ShortArray(256) { Short.MIN_VALUE })
        assertTrue("rms of full-scale negative audio was $full", full in 0f..1f)
    }

    @Test
    fun `rms of a half-scale square wave is about one half`() {
        val level = Pcm.rms(ShortArray(1000) { if (it % 2 == 0) 16_384 else (-16_384).toShort() })
        assertEquals(0.5f, level, 0.01f)
    }

    @Test
    fun `durations round to the nearest millisecond and scale with the rate`() {
        assertEquals(1L, Pcm.durationMs(48))
        assertEquals(0L, Pcm.durationMs(23))
        assertEquals(1L, Pcm.durationMs(24))
        assertEquals(1000L, Pcm.durationMs(16_000, rate = 16_000))
        assertEquals(3_600_000L, Pcm.durationMs(48_000L * 3600))
    }

    @Test
    fun `the capture and caption rates keep their documented 3 to 1 ratio`() {
        assertEquals(3, Pcm.CAPTURE_RATE / Pcm.STT_RATE)
        assertEquals(48_000, Pcm.CAPTURE_RATE)
        assertEquals(16_000, Pcm.STT_RATE)
    }
}
