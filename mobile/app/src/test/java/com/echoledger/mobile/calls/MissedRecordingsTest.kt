package com.echoledger.mobile.calls

import com.echoledger.mobile.calls.MissedRecordings.Sighting
import com.echoledger.mobile.calls.MissedRecordings.Span
import com.echoledger.mobile.recording.CallJournal
import com.echoledger.mobile.recording.CallJournal.Book
import com.echoledger.mobile.recording.CallJournal.Result
import com.echoledger.mobile.recording.CallJournal.Seen
import com.echoledger.mobile.recording.CallJournal.Stage
import com.echoledger.mobile.recording.CallJournal.State
import java.time.Instant
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * The missed-recording detector (v1.8 0.2): every call the phone saw (the journal, the call log, call notifications)
 * against what was recorded, with the exact reason, and never a call the person did not want recorded.
 */
class MissedRecordingsTest {
    private val t0 = Instant.parse("2026-11-16T09:00:00Z").toEpochMilli()
    private val min = 60_000L
    private val later = t0 + 60 * min

    /** Auto-record on for every app, phone calls Always, "Skip calls shorter than" 10 s, armed with the helper ready. */
    private fun ready(choices: CallJournal.Choices = CallJournal.Choices(autoRecord = true, phoneCalls = "always")): Book {
        var b = Book(since = t0 - 60 * min)
        b = CallJournal.choices(b, choices, t0 - 60 * min)
        return CallJournal.mark(b, State.ARMED, t0 - 59 * min, "helper ready")
    }

    private fun log(start: Long, seconds: Long, answered: Boolean = true) =
        Sighting("phone_call", start, start + seconds * 1000, answered, MissedRecordings.CALL_LOG)

    private fun notice(app: String, start: Long, seconds: Long?) =
        Sighting(app, start, seconds?.let { start + it * 1000 }, true, MissedRecordings.NOTIFICATION)

    private fun find(
        book: Book,
        sightings: List<Sighting> = emptyList(),
        recordings: List<Span> = emptyList(),
        known: List<MissedItem> = emptyList()
    ) = MissedRecordings.find(book, sightings, recordings, known, later)

    private fun journalCall(book: Book, id: String, app: String, at: Long, seconds: Long, change: (Book) -> Book): Book {
        var b = CallJournal.seen(book, id, app, Seen.HELPER, at)
        b = CallJournal.stage(b, id, Stage.ARMED, at + 100, "helper ready")
        b = change(b)
        return CallJournal.ended(b, id, at + seconds * 1000)
    }

    // ---- each reason ----------------------------------------------------------------------------------------------

    @Test
    fun `helper_not_armed - the call log saw a call while auto-record was on but the helper was not running`() {
        val book = CallJournal.mark(ready(), State.HELPER_LOST, t0 - min)
        val found = find(book, listOf(log(t0, 312))).single()
        assertEquals(MissedReason.HELPER_NOT_ARMED, found.reason)
        assertEquals("phone_call", found.app)
        assertEquals(t0, found.startedAtMs)
        assertEquals(312L, found.durationS)
        assertEquals("The helper was not connected when the call started.", found.detail)
    }

    @Test
    fun `helper_not_armed - auto-record was not running at all (the app restarted and did not arm)`() {
        val book = CallJournal.mark(ready(), State.PROCESS_START, t0 - min)
        val found = find(book, listOf(notice("whatsapp", t0, 200))).single()
        assertEquals(MissedReason.HELPER_NOT_ARMED, found.reason)
        assertEquals("Auto-record was not running when the call started.", found.detail)
    }

    @Test
    fun `helper_not_armed - the watchdog saw the call in the audio mode without the helper`() {
        var b = CallJournal.seen(ready(), "a1", "phone_call", Seen.AUDIO, t0)
        b = CallJournal.fail(b, "a1", "helper_not_armed", MissedReason.HELPER_NOT_ARMED.detail, t0 + 100)
        b = CallJournal.ended(b, "a1", t0 + 90_000)
        assertEquals(MissedReason.HELPER_NOT_ARMED, find(b).single().reason)
    }

    @Test
    fun `permission - Android did not let the recording start`() {
        val book = journalCall(ready(), "c1", "whatsapp", t0, 300) {
            CallJournal.fail(it, "c1", "permission", "Android did not let the recording start in the background.", t0 + 500)
        }
        val found = find(book).single()
        assertEquals(MissedReason.PERMISSION, found.reason)
        assertEquals("Android did not let the recording start in the background.", found.detail)
    }

    @Test
    fun `detection - auto-record was on and ready but the call was never reported`() {
        val found = find(ready(), listOf(notice("zoom", t0, 1_800))).single()
        assertEquals(MissedReason.DETECTION, found.reason)
        assertEquals("zoom", found.app)
        assertEquals(1_800L, found.durationS)
    }

    @Test
    fun `mic_busy - the helper's recorder would not start`() {
        val book = journalCall(ready(), "c1", "google_meet", t0, 600) {
            CallJournal.fail(
                it,
                "c1",
                MissedReason.fromProblem("Call capture could not start: mic: did not start").wire,
                MissedReason.MIC_BUSY.detail,
                t0 + 400
            )
        }
        assertEquals(MissedReason.MIC_BUSY, find(book).single().reason)
    }

    @Test
    fun `crash - the app stopped during the call`() {
        var b = journalCall(ready(), "c1", "whatsapp", t0, 240) { CallJournal.stage(it, "c1", Stage.STARTED, t0 + 400) }
        b = CallJournal.processStarted(b, t0 + 120_000)
        val found = find(b).single()
        assertEquals(MissedReason.CRASH, found.reason)
        assertEquals(240L, found.durationS)
    }

    @Test
    fun `crash - a call left open by a process that died is a crash even before the journal closed it`() {
        var b = CallJournal.seen(ready(), "c1", "whatsapp", Seen.HELPER, t0)
        b = CallJournal.stage(b, "c1", Stage.STARTED, t0 + 300)
        b = CallJournal.mark(b, State.PROCESS_START, t0 + 30 * min)
        assertEquals(MissedReason.CRASH, find(b).single().reason)
    }

    @Test
    fun `plan - the person wanted calls recorded but the role does not allow it`() {
        val book = ready(CallJournal.Choices(autoRecord = true, viewer = true))
        assertEquals(MissedReason.PLAN, find(book, listOf(notice("whatsapp", t0, 120))).single().reason)
    }

    @Test
    fun `unknown - a call left open long after its last news with no process restart`() {
        var b = CallJournal.seen(ready(), "c1", "telegram", Seen.HELPER, t0)
        b = CallJournal.stage(b, "c1", Stage.STARTED, t0 + 300)
        val found = find(b).single()
        assertEquals(MissedReason.UNKNOWN, found.reason)
        assertEquals(null, found.durationS)
    }

    @Test
    fun `unknown - a recording that captured no sound`() {
        val book = journalCall(ready(), "c1", "whatsapp", t0, 300) {
            CallJournal.fail(
                CallJournal.stage(it, "c1", Stage.STARTED, t0 + 300),
                "c1",
                "unknown",
                "No sound was captured, so the recording was not kept.",
                t0 + 300_000
            )
        }
        assertEquals(MissedReason.UNKNOWN, find(book).single().reason)
    }

    @Test
    fun `the helper's own words choose the reason`() {
        assertEquals(MissedReason.MIC_BUSY, MissedReason.fromProblem("Call capture could not start: mic: did not start; other side: x"))
        assertEquals(MissedReason.MIC_BUSY, MissedReason.fromProblem("the microphone is in use"))
        assertEquals(
            MissedReason.PERMISSION,
            MissedReason.fromProblem("other side: SecurityException: needs MODIFY_AUDIO_ROUTING permission")
        )
        assertEquals(MissedReason.UNKNOWN, MissedReason.fromProblem("Call capture stopped: the recorder returned error -38"))
        assertEquals(MissedReason.UNKNOWN, MissedReason.fromProblem(null))
    }

    // ---- what is never missed -----------------------------------------------------------------------------------

    @Test
    fun `recorded calls are never missed, by the journal, the phone or the Phone app`() {
        var b =
            journalCall(ready(), "c1", "whatsapp", t0, 300) { CallJournal.finish(it, "c1", Result.KEPT, t0 + 300_000, keptMs = 290_000) }
        b = journalCall(b, "c2", "phone_call", t0 + 10 * min, 120) {
            CallJournal.fail(it, "c2", "unknown", "No sound was captured, so the recording was not kept.", t0 + 12 * min)
        }
        // The Phone app's own recording of the second call was imported.
        b = CallJournal.finish(CallJournal.seen(b, "p2", "phone_call", Seen.PHONE_APP, t0 + 10 * min), "p2", Result.KEPT, t0 + 12 * min)
        val sightings = listOf(notice("whatsapp", t0, 300), log(t0 + 10 * min - 20_000, 120), log(t0 + 20 * min, 60))
        // A recording by hand covers the third call.
        assertEquals(
            emptyList<MissedRecordings.Found>(),
            find(b, sightings, recordings = listOf(Span(t0 + 20 * min + 5_000, t0 + 21 * min)))
        )
    }

    @Test
    fun `a call the person did not want recorded is never missed`() {
        val apps = ready(CallJournal.Choices(autoRecord = true, appsOff = listOf("whatsapp_business"), phoneCalls = "ask"))
        assertTrue("its app is switched off", find(apps, listOf(notice("whatsapp_business", t0, 300))).isEmpty())
        assertTrue("phone calls on Ask each time", find(apps, listOf(log(t0, 300))).isEmpty())
        val off = ready(CallJournal.Choices(autoRecord = false, phoneCalls = "always"))
        assertTrue("auto-record off", find(off, listOf(notice("zoom", t0, 300), log(t0 + 10 * min, 300))).isEmpty())
        val phoneOff = ready(CallJournal.Choices(autoRecord = true, phoneCalls = "off"))
        assertTrue("phone calls off", find(phoneOff, listOf(log(t0, 300))).isEmpty())
        // The journal says so too, when the helper saw it.
        val notWanted = journalCall(ready(), "c1", "whatsapp", t0, 300) {
            CallJournal.finish(it, "c1", Result.NOT_WANTED, t0 + 300_000, "auto-record is off for WhatsApp")
        }
        assertTrue(find(notWanted, listOf(notice("whatsapp", t0 + 5_000, 300))).isEmpty())
    }

    @Test
    fun `the choices of the moment count, not today's`() {
        var b = ready(CallJournal.Choices(autoRecord = false))
        b = CallJournal.choices(b, CallJournal.Choices(autoRecord = true), t0 + 30 * min)
        assertTrue("off when the call happened", find(b, listOf(notice("zoom", t0, 300))).isEmpty())
        assertEquals(1, find(b, listOf(notice("zoom", t0 + 35 * min, 300))).size)
    }

    @Test
    fun `short calls, unanswered calls, discarded and skipped calls are never missed`() {
        val skipUnder20 = ready(CallJournal.Choices(autoRecord = true, phoneCalls = "always", shorterThanS = 20))
        assertTrue("under Skip calls shorter than", find(skipUnder20, listOf(log(t0, 15))).isEmpty())
        assertTrue(
            "under 3 s even with Never",
            find(ready(CallJournal.Choices(autoRecord = true, shorterThanS = 0)), listOf(notice("zoom", t0, 2))).isEmpty()
        )
        assertTrue("never answered", find(ready(), listOf(log(t0, 0, answered = false))).isEmpty())
        var b = journalCall(ready(), "d", "whatsapp", t0, 300) { CallJournal.finish(it, "d", Result.DISCARDED, t0 + 300_000, "by you") }
        b = journalCall(b, "s", "whatsapp", t0 + 10 * min, 300) { CallJournal.finish(it, "s", Result.SKIPPED, t0 + 15 * min, "no speech") }
        assertTrue(find(b, listOf(notice("whatsapp", t0, 300), notice("whatsapp", t0 + 10 * min, 300))).isEmpty())
    }

    @Test
    fun `a call that failed early but was never answered is not missed`() {
        val book = journalCall(ready(), "c1", "phone_call", t0, 40) {
            CallJournal.fail(it, "c1", "mic_busy", MissedReason.MIC_BUSY.detail, t0 + 300)
        }
        assertTrue(find(book, listOf(log(t0 - 5_000, 0, answered = false))).isEmpty())
    }

    @Test
    fun `calls from before the journal began, still going on or just ended wait or are left alone`() {
        val book = ready()
        assertTrue("before the journal", find(book, listOf(notice("zoom", book.since - 10 * min, 300))).isEmpty())
        assertTrue("its notification is still up", find(book, listOf(notice("zoom", t0, null))).isEmpty())
        assertTrue(
            "ended a moment ago: its recording may still be saving",
            MissedRecordings.find(book, listOf(notice("zoom", t0, 300)), emptyList(), emptyList(), t0 + 300_000 + 30_000).isEmpty()
        )
        assertTrue(
            "Android cannot record calls on this phone",
            find(CallJournal.mark(book, State.REFUSED, t0 - min, "android"), listOf(log(t0, 300))).isEmpty()
        )
    }

    // ---- comparing the sources ------------------------------------------------------------------------------------

    @Test
    fun `the call log row and the dialer's notification of one SIM call are one missed call`() {
        val book = CallJournal.mark(ready(), State.HELPER_LOST, t0 - min)
        val sightings = listOf(log(t0, 300), notice("phone_call", t0 + 25_000, null), notice("phone_call", t0 + 26_000, 280))
        assertEquals(1, find(book, sightings).size)
    }

    @Test
    fun `a journal call and its notification are one call, and WhatsApp and Business count as one app`() {
        val book = journalCall(ready(), "c1", "whatsapp_business", t0, 300) {
            CallJournal.fail(it, "c1", "permission", MissedReason.PERMISSION.detail, t0 + 500)
        }
        val found = find(book, listOf(notice("whatsapp", t0 + 2_000, 298)))
        assertEquals(1, found.size)
        assertEquals(MissedReason.PERMISSION, found.single().reason)
    }

    @Test
    fun `back-to-back calls are judged one by one`() {
        var b =
            journalCall(ready(), "c1", "whatsapp", t0, 600) { CallJournal.finish(it, "c1", Result.KEPT, t0 + 600_000, keptMs = 590_000) }
        b = CallJournal.mark(b, State.HELPER_LOST, t0 + 601_000)
        val second = notice("whatsapp", t0 + 630_000, 300)
        val found = find(b, listOf(notice("whatsapp", t0, 600), second))
        assertEquals("the recording of the first call does not cover the second", listOf(t0 + 630_000), found.map { it.startedAtMs })
        assertEquals(MissedReason.HELPER_NOT_ARMED, found.single().reason)
    }

    @Test
    fun `a call already found is not found again, and the results come oldest first`() {
        val book = ready()
        val sightings = listOf(notice("zoom", t0 + 20 * min, 300), notice("whatsapp", t0, 300))
        val first = find(book, sightings)
        assertEquals(listOf("whatsapp", "zoom"), first.map { it.app })
        val known = listOf(MissedItem("r1", "whatsapp", t0 + 30_000, 300, "detection", "", t0))
        assertEquals(listOf("zoom"), find(book, sightings, known = known).map { it.app })
    }

    // ---- what the person sees and what the server gets -------------------------------------------------------------

    @Test
    fun `the item's line is the server's body`() {
        val item = MissedItem("r1", "whatsapp", t0, 312, "helper_not_armed", "", t0)
        assertEquals("WhatsApp call · 5:12 · The helper was not connected", MissedRecordings.body(item))
        assertEquals(
            "Phone call · 1:00:05 · Another app was using the microphone",
            MissedRecordings.body(item.copy(app = "phone_call", durationS = 3_605, reason = "mic_busy"))
        )
        assertEquals(
            "Zoom call · The reason is not known",
            MissedRecordings.body(item.copy(app = "zoom", durationS = null, reason = "nonsense"))
        )
    }

    @Test
    fun `every reason has its phrase, a plain sentence and a fix`() {
        val phrases = mapOf(
            "helper_not_armed" to "The helper was not connected",
            "permission" to "A permission was off",
            "detection" to "The call was not detected",
            "mic_busy" to "Another app was using the microphone",
            "crash" to "The app stopped during the call",
            "plan" to "Your plan did not allow the recording",
            "unknown" to "The reason is not known"
        )
        assertEquals(phrases, MissedReason.entries.associate { it.wire to it.phrase })
        MissedReason.entries.forEach {
            assertTrue(it.detail, it.detail.endsWith(".") && it.detail.length <= 300)
        }
        assertEquals(MissedFix.START_HELPER, MissedReason.HELPER_NOT_ARMED.fix)
        assertEquals(MissedFix.PERMISSIONS, MissedReason.PERMISSION.fix)
        assertEquals(MissedFix.TEST, MissedReason.DETECTION.fix)
        assertEquals(MissedFix.TEST, MissedReason.MIC_BUSY.fix)
        assertEquals(MissedFix.PLANS, MissedReason.PLAN.fix)
        assertEquals(MissedFix.CHECK, MissedReason.CRASH.fix)
        assertEquals(MissedFix.CHECK, MissedReason.UNKNOWN.fix)
    }
}
