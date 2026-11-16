package com.echoledger.mobile.recording

import com.echoledger.mobile.testsupport.AppTest
import java.io.File
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/** The capture journal on the phone (v1.8 0.2b): 7 days in its file, and in Copy diagnostics. */
class CallJournalPhoneTest : AppTest() {
    private val day = 24 * 60 * 60 * 1000L

    @Test
    fun `the journal on the phone keeps 7 days in its file`() {
        val now = System.currentTimeMillis()
        CallJournal.update(app, now) { b -> CallJournal.seen(b, "old", "zoom", CallJournal.Seen.HELPER, now - 6 * day) }
        CallJournal.update(app, now) { b -> CallJournal.seen(b, "new", "whatsapp", CallJournal.Seen.HELPER, now) }
        assertEquals(listOf("old", "new"), CallJournal.book(app).calls.map { it.id })

        // Two days later the first call is 8 days old.
        CallJournal.update(app, now + 2 * day) { b -> CallJournal.mark(b, CallJournal.State.ARMED, now + 2 * day, "helper ready") }
        assertEquals(listOf("new"), CallJournal.book(app).calls.map { it.id })
        val file = File(app.noBackupFilesDir, "call-journal.json").readText()
        assertFalse("rotated in the file too", file.contains("\"old\""))
    }

    @Test
    fun `Copy diagnostics holds the journal's calls and auto-record's timeline`() {
        val now = System.currentTimeMillis()
        CallJournal.update(app, now) { b ->
            var j = CallJournal.choices(b, CallJournal.Choices(autoRecord = true, phoneCalls = "always"), now - 60_000)
            j = CallJournal.mark(j, CallJournal.State.ARMED, now - 50_000, "helper ready")
            j = CallJournal.seen(j, "c1", "whatsapp", CallJournal.Seen.HELPER, now - 40_000)
            j = CallJournal.stage(j, "c1", CallJournal.Stage.ARMED, now - 39_900)
            j = CallJournal.fail(j, "c1", "mic_busy", com.echoledger.mobile.calls.MissedReason.MIC_BUSY.detail, now - 39_000)
            j
        }
        val report = CaptureLog.report(app, "not connected")
        val lines = report.lines()
        val calls = lines.indexOfFirst { it.startsWith("-- Calls, last 7 days") }
        assertTrue(report, calls > 0)
        assertTrue(lines[calls + 1], lines[calls + 1].contains("WhatsApp · ") && lines[calls + 1].contains("stopped at started: mic_busy"))
        val timeline = lines.indexOf("-- Auto-record")
        assertTrue(lines.drop(timeline + 1).any { it.endsWith("armed (helper ready)") })
        assertTrue(lines.drop(timeline + 1).any { it.contains("settings (auto-record on, phone calls always") })
    }
}
