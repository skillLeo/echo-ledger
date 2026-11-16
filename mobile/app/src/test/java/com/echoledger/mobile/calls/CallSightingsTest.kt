package com.echoledger.mobile.calls

import android.provider.CallLog
import com.echoledger.mobile.testsupport.AppTest
import java.io.File
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * The calls the phone sees outside its own recorder (v1.8 0.2): call notifications and the call log, by app and time
 * only.
 */
class CallSightingsTest : AppTest() {
    private val t0 = 1_763_283_600_000L // 2026-11-16 09:00 UTC
    private val day = 24 * 60 * 60 * 1000L

    @Test
    fun `an answered call notification starts a sighting and its removal ends it, for call apps and dialers only`() {
        var list = CallSightings.answered(emptyList(), "com.whatsapp", t0, t0)
        list = CallSightings.answered(list, "com.whatsapp", t0 + 5_000, t0 + 5_000) // the same call's notice again
        list = CallSightings.answered(list, "com.samsung.android.incallui", t0 + 60_000, t0 + 60_000)
        list = CallSightings.answered(list, "com.example.game", t0, t0)
        assertEquals(listOf("whatsapp" to t0, "phone_call" to t0 + 60_000), list.map { it.app to it.startMs })

        list = CallSightings.ended(list, "com.whatsapp", t0 + 300_000)
        assertEquals(listOf(t0 + 300_000, null), list.map { it.endMs })
        val seen = CallSightings.sightings(list)
        assertTrue(seen.all { it.answered && it.by == MissedRecordings.NOTIFICATION })
    }

    @Test
    fun `sightings are kept 7 days and hold no names or numbers`() {
        var list = CallSightings.answered(emptyList(), "us.zoom.videomeetings", t0 - 6 * day, t0 - 6 * day)
        list = CallSightings.answered(list, "com.whatsapp", t0, t0)
        assertEquals(listOf("zoom", "whatsapp"), list.map { it.app })
        assertEquals("two days later the first is 8 days old", listOf("whatsapp"), CallSightings.rotate(list, t0 + 2 * day).map { it.app })
        CallSightings.update(app) { list }
        com.echoledger.mobile.recording.JournalIo.flush()
        val stored = File(app.noBackupFilesDir, "call-sightings.json").readText()
        val keys = Regex("\"([a-zA-Z]+)\":").findAll(stored).map { it.groupValues[1] }.toSet()
        assertTrue("only the app, the package and the times: $keys", setOf("app", "startMs", "endMs", "pkg").containsAll(keys))
    }

    @Test
    fun `a call-log row is answered only when it was picked up or made, with talk time`() {
        val incoming = PhoneCallLog.sighting(t0, 312, CallLog.Calls.INCOMING_TYPE)
        assertEquals(MissedRecordings.Sighting("phone_call", t0, t0 + 312_000, true, MissedRecordings.CALL_LOG), incoming)
        assertTrue(PhoneCallLog.sighting(t0, 40, CallLog.Calls.OUTGOING_TYPE).answered)
        assertFalse(PhoneCallLog.sighting(t0, 0, CallLog.Calls.OUTGOING_TYPE).answered)
        assertFalse(PhoneCallLog.sighting(t0, 0, CallLog.Calls.MISSED_TYPE).answered)
        assertFalse(PhoneCallLog.sighting(t0, 0, CallLog.Calls.REJECTED_TYPE).answered)
        assertFalse("answered on another device", PhoneCallLog.sighting(t0, 120, CallLog.Calls.ANSWERED_EXTERNALLY_TYPE).answered)
    }

    @Test
    fun `without the call log permission no SIM call is read`() {
        org.robolectric.Shadows.shadowOf(app).denyPermissions(android.Manifest.permission.READ_CALL_LOG)
        assertEquals(emptyList<MissedRecordings.Sighting>(), PhoneCallLog.calls(app, 0))
    }
}
