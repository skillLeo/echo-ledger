package com.echoledger.mobile.recording

import kotlin.math.PI
import kotlin.math.abs
import kotlin.math.sin
import kotlin.random.Random
import org.junit.Assert.assertArrayEquals
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class ConnectGateTest {
    private val rate = Pcm.CAPTURE_RATE

    private fun samplesFor(ms: Long) = (rate * ms / 1000).toInt()

    private fun tone(ms: Long, vararg hz: Double, amp: Double = 0.2): ShortArray = ShortArray(samplesFor(ms)) { i ->
        (hz.sumOf { sin(2 * PI * it * i / rate) } / hz.size * amp * 32767).toInt().toShort()
    }

    private fun quiet(ms: Long): ShortArray = ShortArray(samplesFor(ms))

    /** Voiced speech stand-in: many harmonics of a gliding pitch, syllable-like loudness and a little noise. */
    private fun speech(ms: Long, seed: Int = 1): ShortArray {
        val random = Random(seed)
        var phase = 0.0
        return ShortArray(samplesFor(ms)) { i ->
            val t = i.toDouble() / rate
            val f0 = 130 + 40 * sin(2 * PI * 1.7 * t)
            phase += 2 * PI * f0 / rate
            var v = 0.0
            for (h in 1..24) v += sin(phase * h) / 24
            val syllable = 0.6 + 0.4 * abs(sin(2 * PI * 3 * t))
            ((v * syllable * 0.5 + random.nextDouble(-0.02, 0.02)) * 32767).toInt().toShort()
        }
    }

    private fun join(vararg parts: ShortArray): ShortArray = ShortArray(parts.sumOf { it.size }).also { out ->
        var at = 0
        for (p in parts) {
            p.copyInto(out, at)
            at += p.size
        }
    }

    private fun stereo(near: ShortArray, far: ShortArray): ShortArray =
        ShortArray(minOf(near.size, far.size) * 2) { i -> if (i % 2 == 0) near[i / 2] else far[i / 2] }

    /** Feeds [pcm] in 20 ms blocks; returns everything the gate let through, in order. */
    private fun feed(gate: ConnectGate, pcm: ShortArray, channels: Int = 2): ByteArray {
        val out = java.io.ByteArrayOutputStream()
        val block = rate / 50 * channels
        var i = 0
        while (i < pcm.size) {
            val bytes = Pcm.toLittleEndianBytes(pcm.copyOfRange(i, minOf(pcm.size, i + block)))
            gate.accept(bytes, bytes.size)?.let { out.write(it) }
            i += block
        }
        return out.toByteArray()
    }

    @Test
    fun `ringback tones are tones and voiced speech is speech`() {
        val signal = CallSignal()
        assertEquals(CallSignal.Kind.TONE, signal.classify(tone(20, 425.0)))
        assertEquals(CallSignal.Kind.TONE, signal.classify(tone(20, 440.0, 480.0)))
        assertEquals(CallSignal.Kind.TONE, signal.classify(tone(20, 400.0, 450.0)))
        assertEquals(CallSignal.Kind.QUIET, signal.classify(quiet(20)))
        val frames = speech(2_000).toList().chunked(rate / 50).map { signal.classify(it.toShortArray()) }
        assertTrue("speech frames: $frames", frames.count { it == CallSignal.Kind.SPEECH } >= frames.size * 0.8)
    }

    @Test
    fun `an outgoing call is kept from the end of the ringback once the other side speaks`() {
        val far = join(tone(1_000, 425.0), quiet(3_000), tone(1_000, 425.0), quiet(2_000), speech(4_000))
        val pcm = stereo(quiet(11_000), far)
        val gate = ConnectGate(channels = 2)
        val out = feed(gate, pcm)

        assertEquals("the other side spoke", gate.openedBy)
        val answered = gate.answeredAtMs!!
        assertTrue("answered at $answered", answered in 6_960..7_100)
        // Three seconds before the answer would reach back into the ringback, which ends at 5 s.
        assertEquals(5_000L, gate.keptFromMs)
        val expected = Pcm.toLittleEndianBytes(pcm.copyOfRange(samplesFor(5_000) * 2, pcm.size))
        assertArrayEquals(expected, out)
    }

    @Test
    fun `a call that only rang keeps everything for the skipped list and says it was never answered`() {
        val far = join(tone(1_000, 440.0, 480.0), quiet(3_000), tone(1_000, 440.0, 480.0), quiet(3_000))
        val pcm = stereo(quiet(8_000), far)
        val gate = ConnectGate(channels = 2)
        assertEquals(0, feed(gate, pcm).size)
        assertTrue(gate.waiting)

        val rest = gate.finish()
        assertEquals("never answered", gate.openedBy)
        assertNull(gate.answeredAtMs)
        assertEquals(0L, gate.keptFromMs)
        assertArrayEquals(Pcm.toLittleEndianBytes(pcm), rest)
    }

    @Test
    fun `the call notification's timer opens the gate at the answer`() {
        val gate = ConnectGate(channels = 2)
        val first = feed(gate, stereo(quiet(2_000), join(tone(1_000, 425.0), quiet(1_000))))
        assertEquals(0, first.size)
        gate.openAt(4_500, "call notification timer")
        // Not yet: the audio has not reached the answer.
        assertEquals(0, feed(gate, stereo(quiet(2_000), quiet(2_000))).size)
        val after = feed(gate, stereo(quiet(1_000), quiet(1_000)))
        assertEquals("call notification timer", gate.openedBy)
        assertEquals(4_500L, gate.answeredAtMs)
        assertEquals(1_500L, gate.keptFromMs)
        // From 1.5 s to 5 s: three and a half seconds of stereo frames.
        assertEquals(samplesFor(3_500) * 2 * 2, after.size)
    }

    @Test
    fun `a SIM call's talk time places the answer when the audio never showed it`() {
        val gate = ConnectGate(channels = 2)
        feed(gate, stereo(quiet(10_000), join(tone(1_000, 425.0), quiet(9_000))))
        val rest = gate.finish(answeredAtMs = 7_000)
        assertEquals("call log talk time", gate.openedBy)
        assertEquals(4_000L, gate.keptFromMs)
        assertEquals(samplesFor(6_000) * 2 * 2, rest.size)
    }

    @Test
    fun `this side speaking opens the gate only when no ringback was heard for a while`() {
        val gate = ConnectGate(channels = 2)
        val near = join(quiet(7_000), speech(2_000))
        feed(gate, stereo(near, quiet(9_000)))
        assertEquals("you spoke and no ringback was heard", gate.openedBy)
        assertTrue("answered at ${gate.answeredAtMs}", gate.answeredAtMs!! in 6_980..7_100)

        val ringing = ConnectGate(channels = 2)
        val far = join(tone(1_000, 425.0), quiet(2_000), tone(1_000, 425.0), quiet(5_000))
        feed(ringing, stereo(join(quiet(4_500), speech(2_000), quiet(2_500)), far))
        assertTrue("speaking over the ringback is not an answer", ringing.waiting)
    }

    @Test
    fun `past the longest ring everything is kept from the start`() {
        val gate = ConnectGate(channels = 1, maxWaitMs = 5_000)
        val pcm = join(tone(1_000, 425.0), quiet(4_200))
        val out = feed(gate, pcm, channels = 1)
        assertNotNull(gate.openedBy)
        assertNull(gate.answeredAtMs)
        assertEquals(0L, gate.keptFromMs)
        assertArrayEquals(Pcm.toLittleEndianBytes(pcm), out)
    }

    @Test
    fun `once open the gate lets every byte through unchanged`() {
        val gate = ConnectGate(channels = 2)
        gate.openAt(0, "incoming call")
        val pcm = stereo(speech(1_000), speech(1_000, seed = 2))
        assertArrayEquals(Pcm.toLittleEndianBytes(pcm), feed(gate, pcm))
        assertEquals(0L, gate.keptFromMs)
    }
}
