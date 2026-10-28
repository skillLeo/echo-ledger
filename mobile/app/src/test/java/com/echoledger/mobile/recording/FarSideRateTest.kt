package com.echoledger.mobile.recording

import org.junit.Assert.assertArrayEquals
import org.junit.Assert.assertEquals
import org.junit.Test

/**
 * The other side of a call is captured at 16 kHz (Android refuses a privileged call capture above it) and written
 * next to the 48 kHz microphone: raised by straight lines, queued, and taken in the microphone's chunk sizes.
 */
class FarSideRateTest {
    @Test
    fun `raising 16 kHz to 48 kHz draws straight lines between samples and joins chunks without a click`() {
        val first = Pcm.upsample(shortArrayOf(300, 600), 2, 3)
        assertArrayEquals(shortArrayOf(100, 200, 300, 400, 500, 600), first)

        val next = Pcm.upsample(shortArrayOf(0), 1, 3, previous = 600)
        assertArrayEquals(shortArrayOf(400, 200, 0), next)
        assertArrayEquals(shortArrayOf(7, 8), Pcm.upsample(shortArrayOf(7, 8, 9), 2, 1))
    }

    @Test
    fun `the queue hands out what arrived and silence after it`() {
        val queue = PcmQueue(10)
        queue.push(shortArrayOf(1, 2, 3))

        assertArrayEquals(shortArrayOf(1, 2), queue.take(2))
        assertArrayEquals(shortArrayOf(3, 0, 0), queue.take(3))
        assertEquals(0, queue.size)
    }

    @Test
    fun `a queue that runs ahead drops its oldest samples`() {
        val queue = PcmQueue(4)
        queue.push(shortArrayOf(1, 2, 3))
        queue.push(shortArrayOf(4, 5, 6))

        assertEquals(4, queue.size)
        assertArrayEquals(shortArrayOf(3, 4, 5, 6), queue.take(4))
    }
}
