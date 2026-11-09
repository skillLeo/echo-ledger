package com.echoledger.mobile.core

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/** v1.6.1, docs/DESIGN_SYSTEM.md "Notices": the pure rules every notice follows. */
class NoticesTest {
    @Test
    fun `confirmations are successes, problems are errors, the rest is information`() {
        assertEquals(NoticeKind.SUCCESS, Notices.kindOf("Saved"))
        assertEquals(NoticeKind.SUCCESS, Notices.kindOf("Restored — it uploads now"))
        assertEquals(NoticeKind.ERROR, Notices.kindOf("The export failed."))
        assertEquals(NoticeKind.ERROR, Notices.kindOf("Can't reach the server. Try again."))
        assertEquals(NoticeKind.ERROR, Notices.kindOf("Shizuku is not running. Start it (Wireless debugging) and try again."))
        assertEquals(NoticeKind.INFO, Notices.kindOf("Re-transcribing in Urdu. The current transcript stays until the new one is ready."))
    }

    @Test
    fun `a message stays 6 seconds and an error 10`() {
        assertEquals(6_000L, Notice("Saved").durationMs)
        assertEquals(10_000L, Notice("The export failed.").durationMs)
    }

    @Test
    fun `a drag past a third of the width or a flick closes, a short drag springs back`() {
        assertTrue(Notices.swipeCloses(-140f, 400f))
        assertTrue(Notices.swipeCloses(140f, 400f))
        assertFalse(Notices.swipeCloses(120f, 400f))
        assertTrue("a quick flick", Notices.swipeCloses(40f, 400f, velocityPxPerS = 2_000f))
        assertFalse("a tap is not a flick", Notices.swipeCloses(5f, 400f, velocityPxPerS = 2_000f))
        assertFalse(Notices.swipeCloses(300f, 0f))
    }

    @Test
    fun `the timer bar shortens with the time that ran, never below nothing`() {
        assertEquals(1f, Notices.barFraction(6_000, 0), 0.001f)
        assertEquals(0.5f, Notices.barFraction(6_000, 3_000), 0.001f)
        assertEquals(0f, Notices.barFraction(6_000, 9_000), 0.001f)
        assertEquals(0L, Notices.remaining(6_000, 9_000))
    }
}
