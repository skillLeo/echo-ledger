package com.echoledger.mobile.core

import android.media.AudioManager
import com.echoledger.mobile.data.CallerDto
import com.echoledger.mobile.data.LocalRecording
import com.echoledger.mobile.notify.Notifications
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/** SIM phone calls as a source (v1.2): the setting, telling a SIM call from a WhatsApp call, and the call-log row. */
class PhoneCallsTest {
    @Test
    fun `the setting is off unless it is ask or always`() {
        assertEquals(PhoneCalls.OFF, PhoneCalls.mode(null))
        assertEquals(PhoneCalls.OFF, PhoneCalls.mode("sometimes"))
        assertEquals(PhoneCalls.ASK, PhoneCalls.mode("ask"))
        assertEquals("Ask each time", PhoneCalls.label("ask"))
        assertEquals("Always", PhoneCalls.label("always"))
    }

    @Test
    fun `a SIM call is told apart from a WhatsApp call by the audio mode`() {
        assertEquals(CallTargets.TELEPHONY, PhoneCalls.callApp(AudioManager.MODE_IN_CALL) { CallTargets.WHATSAPP })
        assertEquals(CallTargets.WHATSAPP, PhoneCalls.callApp(AudioManager.MODE_IN_COMMUNICATION) { CallTargets.WHATSAPP })
        assertNull(PhoneCalls.callApp(AudioManager.MODE_IN_COMMUNICATION) { null })
        assertNull(PhoneCalls.callApp(AudioManager.MODE_RINGTONE) { CallTargets.WHATSAPP })
        assertTrue(PhoneCalls.inCall(AudioManager.MODE_IN_CALL))
        assertFalse(PhoneCalls.inCall(AudioManager.MODE_NORMAL))
        assertEquals(SourceApp.PHONE_CALL, SourceApp.fromPackage(CallTargets.TELEPHONY))
        assertFalse(CallTargets.isTarget(CallTargets.TELEPHONY))
    }

    @Test
    fun `the call-log row of a recording is the one that began closest to its start`() {
        val start = 10_000_000L
        val rows = listOf(
            PhoneCalls.LogEntry("+92 300 1111111", null, start - 3 * 60_000), // an earlier call
            PhoneCalls.LogEntry("+92 300 1234567", "Omar Khan", start - 15_000), // rang 15 s before the recording
            PhoneCalls.LogEntry("+92 300 2222222", null, start + 10 * 60_000) // after it ended
        )

        assertEquals("Omar Khan", PhoneCalls.pick(rows, start, start + 5 * 60_000)?.name)
        assertNull(PhoneCalls.pick(rows.take(1), start, start + 60_000))
    }

    @Test
    fun `the caller is the contact and number, or the number alone`() {
        assertEquals(
            CallerDto(name = "Omar Khan", number = "+92 300 1234567", source = "contacts"),
            PhoneCalls.caller(PhoneCalls.LogEntry(" +92 300 1234567 ", " Omar Khan ", 0))
        )
        assertEquals(CallerDto(number = "03001234567", source = "phone"), PhoneCalls.caller(PhoneCalls.LogEntry("03001234567", null, 0)))
        // A private number has no digits and no name.
        assertNull(PhoneCalls.caller(PhoneCalls.LogEntry("Private", "", 0)))
    }

    @Test
    fun `the save question names the caller of a phone call`() {
        val rec = LocalRecording(
            id = "r1",
            sourceApp = "phone_call",
            clientId = null,
            startedAt = "2026-10-28T10:00:00Z",
            endedAt = "2026-10-28T10:02:05Z",
            durationMs = 125_000,
            mime = "audio/ogg",
            codec = "opus",
            channels = 2,
            sampleRate = 16_000,
            plainSize = 0,
            sha256 = "",
            state = "pending_decision"
        )

        assertEquals("Phone call · 2:05", Notifications.decisionText(rec, null))
        assertEquals("Call with +92 300 1234567 · 2:05", Notifications.decisionText(rec.copy(callerNumber = "+92 300 1234567"), null))
        assertEquals("Call with Omar Khan · 2:05 · Acme", Notifications.decisionText(rec.copy(callerName = "Omar Khan"), "Acme"))
    }
}
