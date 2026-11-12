package com.echoledger.mobile.core

import org.junit.After
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class CallNoticesTest {
    private val now = 1_000_000L

    @After fun clear() = CallNotices.clearForTest()

    @Test
    fun `an incoming call notification is incoming`() {
        val n = CallNotices.classify("com.whatsapp", true, 1, false, now, "Incoming voice call", now)
        assertEquals(CallNotices.Kind.INCOMING, n?.kind)
        assertEquals(CallNotices.Kind.INCOMING, CallNotices.classify("com.whatsapp", true, 0, false, 0, "Incoming voice call", now)?.kind)
    }

    @Test
    fun `an outgoing call is ringing until its timer runs, then answered at the timer's base`() {
        assertEquals(CallNotices.Kind.RINGING_OUT, CallNotices.classify("com.whatsapp", true, 2, false, now, "Ringing…", now)?.kind)
        assertEquals(CallNotices.Kind.RINGING_OUT, CallNotices.classify("com.whatsapp", true, 0, false, now, "Calling", now)?.kind)
        val answered = CallNotices.classify("com.whatsapp", true, 2, true, now - 4_000, "Ongoing voice call", now)
        assertEquals(CallNotices.Kind.ANSWERED, answered?.kind)
        assertEquals(now - 4_000, answered?.answeredAtMs)
    }

    @Test
    fun `other notifications and timers far in the future say nothing`() {
        assertNull(CallNotices.classify("com.whatsapp", false, 0, false, now, "Hi there", now))
        assertNull(CallNotices.classify("com.whatsapp", true, 0, true, now + 60_000, "Voice call", now))
    }

    @Test
    fun `the latest notice of the recorded app, or of a dialer for a SIM call, is found and passed on`() {
        val seen = mutableListOf<CallNotices.Notice>()
        CallNotices.onNotice = { seen += it }
        CallNotices.record(CallNotices.Notice("com.whatsapp", CallNotices.Kind.RINGING_OUT, now))
        CallNotices.record(CallNotices.Notice("com.samsung.android.incallui", CallNotices.Kind.INCOMING, now + 1))
        assertEquals(CallNotices.Kind.RINGING_OUT, CallNotices.latest("com.whatsapp", sim = false, sinceMs = now - 1)?.kind)
        assertEquals(CallNotices.Kind.INCOMING, CallNotices.latest(null, sim = true, sinceMs = now - 1)?.kind)
        // Since v1.7.1 WhatsApp Business and WhatsApp see each other's notices; any other app does not.
        assertEquals(CallNotices.Kind.RINGING_OUT, CallNotices.latest("com.whatsapp.w4b", sim = false, sinceMs = now - 1)?.kind)
        assertNull(CallNotices.latest("us.zoom.videomeetings", sim = false, sinceMs = now - 1))
        assertEquals(2, seen.size)
        assertTrue(CallNotices.matches(seen[1], pkg = null, sim = true))
    }

    @Test
    fun `WhatsApp's notice answers a call taken for WhatsApp Business, and the other way round (v1_7_1)`() {
        val answered = CallNotices.Notice("com.whatsapp", CallNotices.Kind.ANSWERED, 1_000, 1_000)
        org.junit.Assert.assertTrue(CallNotices.matches(answered, "com.whatsapp.w4b", sim = false))
        org.junit.Assert.assertTrue(CallNotices.matches(answered.copy(pkg = "com.whatsapp.w4b"), "com.whatsapp", sim = false))
        org.junit.Assert.assertFalse(CallNotices.matches(answered.copy(pkg = "us.zoom.videomeetings"), "com.whatsapp", sim = false))
        org.junit.Assert.assertFalse(CallNotices.matches(answered, "com.whatsapp", sim = true))
    }
}
