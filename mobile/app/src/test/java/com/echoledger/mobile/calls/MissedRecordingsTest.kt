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
import org.junit.Assert.assertFalse
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
    private val day = 24 * 60 * min

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
    fun `helper_not_armed - the watchdog saw the call in the audio mode without the helper, and the call log says it was answered`() {
        var b = CallJournal.seen(ready(), "a1", "phone_call", Seen.AUDIO, t0)
        b = CallJournal.fail(b, "a1", "helper_not_armed", MissedReason.HELPER_NOT_ARMED.detail, t0 + 100)
        b = CallJournal.ended(b, "a1", t0 + 90_000)
        val found = find(b, listOf(log(t0 - 10_000, 80))).single()
        assertEquals(MissedReason.HELPER_NOT_ARMED, found.reason)
        assertEquals("from the answer", 80L, found.durationS)
        assertTrue("only the audio mode saw it: that may have been ringing", find(b).isEmpty())
    }

    @Test
    fun `permission - Android did not let the recording start`() {
        val book = journalCall(ready(), "c1", "whatsapp", t0, 300) {
            CallJournal.fail(it, "c1", "permission", "Android did not let the recording start in the background.", t0 + 500)
        }
        val found = find(book, listOf(notice("whatsapp", t0 + 4_000, 296))).single()
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
        assertEquals(MissedReason.MIC_BUSY, find(book, listOf(notice("google_meet", t0 + 2_000, 598))).single().reason)
    }

    @Test
    fun `mic_busy, its twin - the same refused start is not missed when nothing says the call was answered`() {
        val book = journalCall(ready(), "c1", "google_meet", t0, 600) {
            CallJournal.fail(it, "c1", MissedReason.MIC_BUSY.wire, MissedReason.MIC_BUSY.detail, t0 + 400)
        }
        assertTrue("no notice, and the recording never started: it may only have rung", find(book).isEmpty())
        assertTrue(
            "auto-record was off for Meet then",
            find(
                CallJournal.choices(book, CallJournal.Choices(autoRecord = true, appsOff = listOf("google_meet")), t0 - 30 * min),
                listOf(notice("google_meet", t0 + 2_000, 598))
            ).isEmpty()
        )
    }

    /** A call EchoLedger recorded until the app stopped: started, [answered] (its answer gate opened) or not, both sides heard. */
    private fun crashed(answered: Boolean): Book {
        var b = journalCall(ready(), "c1", "whatsapp", t0, 240) {
            var j = CallJournal.stage(it, "c1", Stage.STARTED, t0 + 400)
            if (answered) j = CallJournal.answered(j, "c1", t0 + 1_500, "the other side spoke")
            CallJournal.stage(j, "c1", Stage.BOTH_AUDIO, t0 + 2_000)
        }
        b = CallJournal.processStarted(b, t0 + 120_000)
        return b
    }

    @Test
    fun `crash - the app stopped during the call`() {
        val found = find(crashed(answered = true)).single()
        assertEquals(MissedReason.CRASH, found.reason)
        assertEquals(240L, found.durationS)
    }

    @Test
    fun `crash, its twin - both channels with sound but no answer seen is a call that only rang (the ringback and your voice)`() {
        assertTrue(find(crashed(answered = false)).isEmpty())
        // Its call notice says it was answered after all: then it is missed.
        assertEquals(MissedReason.CRASH, find(crashed(answered = false), listOf(notice("whatsapp", t0 + 9_000, 231))).single().reason)
    }

    /** A call left open by a process that died, [answered] or not. */
    private fun leftOpen(answered: Boolean, bothAudio: Boolean = true): Book {
        var b = CallJournal.seen(ready(), "c1", "whatsapp", Seen.HELPER, t0)
        b = CallJournal.stage(b, "c1", Stage.STARTED, t0 + 300)
        if (answered) b = CallJournal.answered(b, "c1", t0 + 1_000, "call notification timer")
        if (bothAudio) b = CallJournal.stage(b, "c1", Stage.BOTH_AUDIO, t0 + 2_000)
        return CallJournal.mark(b, State.PROCESS_START, t0 + 30 * min)
    }

    @Test
    fun `crash - a call left open by a process that died is a crash even before the journal closed it`() {
        assertEquals(MissedReason.CRASH, find(leftOpen(answered = true)).single().reason)
    }

    @Test
    fun `crash, its twin - a call left open that was never seen answered is not missed`() {
        assertTrue(find(leftOpen(answered = false)).isEmpty())
    }

    @Test
    fun `a crash before both channels had audio, with no notice, stays unreported`() {
        assertTrue(find(leftOpen(answered = false, bothAudio = false)).isEmpty())
        assertTrue(
            "and so does a recording that crashed before any sound",
            find(
                CallJournal.processStarted(
                    journalCall(ready(), "c1", "whatsapp", t0, 240) {
                        CallJournal.stage(
                            it,
                            "c1",
                            Stage.STARTED,
                            t0 + 400
                        )
                    },
                    t0 + 120_000
                )
            ).isEmpty()
        )
    }

    @Test
    fun `plan - the person wanted calls recorded but the role does not allow it`() {
        val book = ready(CallJournal.Choices(autoRecord = true, viewer = true))
        assertEquals(MissedReason.PLAN, find(book, listOf(notice("whatsapp", t0, 120))).single().reason)
    }

    @Test
    fun `a call left open with no news is not judged until something proves it ended`() {
        var b = CallJournal.seen(ready(), "c1", "telegram", Seen.HELPER, t0)
        b = CallJournal.stage(b, "c1", Stage.STARTED, t0 + 300)
        assertTrue("no end, no later call, no restart: it may still be going on", find(b).isEmpty())
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
        assertEquals(MissedReason.UNKNOWN, find(book, listOf(notice("whatsapp", t0 + 3_000, 297))).single().reason)
    }

    @Test
    fun `unknown, its twin - a silent recording is not missed when the call was skipped as too short, or nothing says it was answered`() {
        val book = journalCall(ready(), "c1", "whatsapp", t0, 300) {
            CallJournal.fail(
                CallJournal.stage(it, "c1", Stage.STARTED, t0 + 300),
                "c1",
                "unknown",
                "No sound was captured, so the recording was not kept.",
                t0 + 300_000
            )
        }
        assertTrue("no notice, no answer seen", find(book).isEmpty())
        assertTrue("8 s of talk, under Skip calls shorter than 10 s", find(book, listOf(notice("whatsapp", t0 + 292_000, 8))).isEmpty())
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
        // A phone line that would not open is not the microphone in use: the phone keeps the line for its own Phone app.
        val line = "Call capture could not start: phone line (both directions): did not start; phone line: did not start"
        assertEquals(MissedReason.UNKNOWN, MissedReason.fromProblem(line))
        assertEquals(MissedReason.UNKNOWN to MissedReason.PHONE_LINE, MissedReason.ofProblem(line))
        assertEquals(MissedReason.MIC_BUSY to MissedReason.MIC_BUSY.detail, MissedReason.ofProblem("mic: did not start"))
        assertEquals(MissedReason.UNKNOWN to MissedReason.UNKNOWN.detail, MissedReason.ofProblem(null))
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
    fun `a recorded call whose file went after the upload is not found once the journal forgot it, 7 days and 1 hour on`() {
        // Recorded and uploaded (the phone's copy is gone), and its call notification was seen.
        var b = journalCall(ready(), "c1", "whatsapp", t0, 300) {
            CallJournal.finish(CallJournal.stage(it, "c1", Stage.STARTED, t0 + 400), "c1", Result.KEPT, t0 + 300_000, keptMs = 295_000)
        }
        val sightings = listOf(notice("whatsapp", t0 + 3_000, 297))
        assertTrue("recorded: not missed", MissedRecordings.find(b, sightings, emptyList(), emptyList(), t0 + 10 * min).isEmpty())
        val weekOn = t0 + 7 * day + 60 * min
        b = CallJournal.rotate(b, weekOn)
        assertTrue("the journal no longer holds the call", b.calls.isEmpty())
        assertEquals(emptyList<MissedRecordings.Found>(), MissedRecordings.find(b, sightings, emptyList(), emptyList(), weekOn))
    }

    @Test
    fun `a heavy week whose calls the journal dropped for room is never judged by the call notices it no longer explains`() {
        // 401 recorded WhatsApp calls in two days: the journal keeps the newest 400.
        var b = ready()
        for (i in 0..400) {
            val at = t0 + i * 6 * min
            b = journalCall(b, "c$i", "whatsapp", at, 60) { CallJournal.finish(it, "c$i", Result.KEPT, at + 61_000, keptMs = 58_000) }
        }
        val now = t0 + 401 * 6 * min + 60 * min
        b = CallJournal.rotate(b, now)
        assertEquals(CallJournal.MAX_CALLS, b.calls.size)
        assertEquals("the journal now starts at the oldest call it kept", t0 + 6 * min, b.since)
        // The first call's notice: what became of it is no longer known, so it is not judged.
        val firstNotice = notice("whatsapp", t0 + 2_000, 58)
        assertEquals(emptyList<MissedRecordings.Found>(), MissedRecordings.find(b, listOf(firstNotice), emptyList(), emptyList(), now))
    }

    @Test
    fun `auto-record's state where the kept marks begin is still known when marks are dropped for room`() {
        var b = ready() // armed with the helper ready, an hour before
        for (i in 1..CallJournal.MAX_MARKS) {
            b = CallJournal.mark(b, if (i % 2 == 1) State.HELPER_LOST else State.HELPER_READY, t0 + i * 1_000)
        }
        val at = t0 + CallJournal.MAX_MARKS * 1_000L + 500
        val before = b.serviceAt(at)
        val rotated = CallJournal.rotate(b, at)
        assertTrue(rotated.marks.size <= CallJournal.MAX_MARKS)
        assertEquals(before, rotated.serviceAt(at))
        assertEquals("armed, as before the dropped marks", true, rotated.serviceAt(rotated.marks.first().atMs).armed)
    }

    @Test
    fun `a call still going on is never judged, however long it lasts`() {
        // 45 minutes into its recording: no end yet, and the live recording is not on the phone's list until it is saved.
        var b = CallJournal.seen(ready(), "c1", "whatsapp", Seen.HELPER, t0)
        b = CallJournal.stage(b, "c1", Stage.ARMED, t0 + 100, "helper ready")
        b = CallJournal.stage(b, "c1", Stage.STARTED, t0 + 300)
        b = CallJournal.stage(b, "c1", Stage.BOTH_AUDIO, t0 + 2_000)
        assertTrue(MissedRecordings.find(b, emptyList(), emptyList(), emptyList(), t0 + 45 * min).isEmpty())
        assertTrue(
            "its call notification still up",
            MissedRecordings.find(b, listOf(notice("whatsapp", t0 + 1_000, null)), emptyList(), emptyList(), t0 + 3 * 60 * min).isEmpty()
        )
    }

    @Test
    fun `a start that failed and was rescued by the backup start is recorded, also when the person deleted it later`() {
        fun rescued(result: Result): Book {
            var b = CallJournal.seen(ready(), "c1", "whatsapp", Seen.HELPER, t0)
            b = CallJournal.stage(b, "c1", Stage.ARMED, t0 + 100, "helper ready")
            b = CallJournal.fail(b, "c1", "helper_not_armed", MissedReason.HELPER_NOT_ARMED.detail, t0 + 200)
            // The backup start 5 s later records the same call, under the same id.
            b = CallJournal.stage(b, "c1", Stage.STARTED, t0 + 5_200, "backup start")
            b = CallJournal.stage(b, "c1", Stage.BOTH_AUDIO, t0 + 7_000)
            b = CallJournal.ended(b, "c1", t0 + 300_000)
            return CallJournal.finish(b, "c1", result, t0 + 301_000, keptMs = 295_000)
        }
        // Uploaded (no copy on the phone), or deleted after the call: the journal is the evidence.
        val seen = listOf(notice("whatsapp", t0 + 3_000, 297))
        assertEquals(emptyList<MissedRecordings.Found>(), find(rescued(Result.KEPT), seen))
        assertEquals(emptyList<MissedRecordings.Found>(), find(rescued(Result.DISCARDED), seen))
        assertEquals("kept", rescued(Result.KEPT).call("c1")!!.result)
    }

    @Test
    fun `a call with no end yet waits while a call or a recording is on, or its notice is still up`() {
        var b = CallJournal.seen(ready(), "c1", "whatsapp", Seen.HELPER, t0)
        b = CallJournal.stage(b, "c1", Stage.STARTED, t0 + 300)
        b = CallJournal.answered(b, "c1", t0 + 1_000, "the other side spoke")
        b = CallJournal.stage(b, "c1", Stage.BOTH_AUDIO, t0 + 2_000)
        // A later call proves the first one over, but not while something is on.
        b = CallJournal.seen(b, "c2", "zoom", Seen.HELPER, t0 + 50 * min)
        assertTrue("a call is going on", MissedRecordings.find(b, emptyList(), emptyList(), emptyList(), later, ongoing = true).isEmpty())
        assertTrue("its notice is still up", find(b, listOf(notice("whatsapp", t0 + 5_000, null))).isEmpty())
        val found = find(b).single()
        assertEquals(MissedReason.UNKNOWN, found.reason)
        assertEquals("whatsapp", found.app)
    }

    @Test
    fun `an open call is judged only when a later call or a restart proves it over, never while its recording saves`() {
        var b = journalCall(ready(), "c1", "whatsapp", t0, 300) {
            val started = CallJournal.stage(it, "c1", Stage.STARTED, t0 + 300)
            CallJournal.stage(CallJournal.answered(started, "c1", t0 + 1_000, "the other side spoke"), "c1", Stage.BOTH_AUDIO, t0 + 2_000)
        }
        assertTrue("ended, still saving: no proof it was lost", find(b).isEmpty())
        b = CallJournal.seen(b, "c2", "zoom", Seen.HELPER, t0 + 30 * min)
        assertTrue(
            "a recording is being saved",
            MissedRecordings.find(b, emptyList(), emptyList(), emptyList(), later, ongoing = true).isEmpty()
        )
        assertEquals(MissedReason.UNKNOWN, find(b).single().reason)
    }

    @Test
    fun `an outgoing WhatsApp call that rang unanswered while the helper was down is not missed`() {
        var b = CallJournal.seen(ready(), "a1", "whatsapp", Seen.AUDIO, t0)
        b = CallJournal.fail(b, "a1", "helper_not_armed", MissedReason.HELPER_NOT_ARMED.detail, t0 + 100)
        b = CallJournal.ended(b, "a1", t0 + 30_000)
        assertTrue("no answered call notice: it only rang", find(b).isEmpty())
    }

    @Test
    fun `a WhatsApp redial a minute after an unanswered call does not make the first one answered`() {
        var b = CallJournal.seen(ready(), "a1", "whatsapp", Seen.AUDIO, t0)
        b = CallJournal.fail(b, "a1", "helper_not_armed", MissedReason.HELPER_NOT_ARMED.detail, t0 + 100)
        b = CallJournal.ended(b, "a1", t0 + 30_000)
        b = CallJournal.seen(b, "a2", "whatsapp", Seen.AUDIO, t0 + 60_000)
        b = CallJournal.fail(b, "a2", "helper_not_armed", MissedReason.HELPER_NOT_ARMED.detail, t0 + 60_100)
        b = CallJournal.ended(b, "a2", t0 + 300_000)
        val found = find(b, listOf(notice("whatsapp", t0 + 65_000, 235)))
        assertEquals("only the redial was answered", listOf(t0 + 60_000), found.map { it.startedAtMs })
        assertEquals(235L, found.single().durationS)
    }

    @Test
    fun `an answered call's row never answers the redial a minute later, whether the call was kept or imported`() {
        // A: answered, 25 s of talk, and recorded: by EchoLedger, or (the phone line would not open) by the Phone app.
        fun book(imported: Boolean): Book {
            var b = CallJournal.seen(ready(), "a", "phone_call", Seen.HELPER, t0)
            b = CallJournal.stage(b, "a", Stage.ARMED, t0 + 100, "helper ready")
            if (imported) {
                b = CallJournal.fail(b, "a", "unknown", MissedReason.PHONE_LINE, t0 + 200)
                b = CallJournal.ended(b, "a", t0 + 30_000)
                b = CallJournal.seen(b, "p", "phone_call", Seen.PHONE_APP, t0 + 4_000)
                b = CallJournal.finish(b, "p", Result.KEPT, t0 + 29_000, "imported from the Phone app", 25_000)
            } else {
                b = CallJournal.stage(b, "a", Stage.STARTED, t0 + 300)
                b = CallJournal.stage(b, "a", Stage.BOTH_AUDIO, t0 + 4_000)
                b = CallJournal.ended(b, "a", t0 + 30_000)
                b = CallJournal.finish(b, "a", Result.KEPT, t0 + 31_000, keptMs = 25_000)
            }
            // B: the redial 40 s after A ended; it rang 30 s and nobody answered. Its phone line would not open either.
            b = CallJournal.seen(b, "b", "phone_call", Seen.HELPER, t0 + 70_000)
            b = CallJournal.stage(b, "b", Stage.ARMED, t0 + 70_100, "helper ready")
            b = CallJournal.fail(b, "b", "unknown", MissedReason.PHONE_LINE, t0 + 70_200)
            return CallJournal.ended(b, "b", t0 + 100_000)
        }
        val rowA = log(t0 - 3_000, 25)
        val rowB = log(t0 + 69_000, 0, answered = false)
        listOf(false, true).forEach { imported ->
            assertTrue("imported: $imported", find(book(imported), listOf(rowA, rowB)).isEmpty())
            assertTrue("imported: $imported, before the redial's own row is written", find(book(imported), listOf(rowA)).isEmpty())
        }
        // A redial that was answered and not recorded is missed, with its own length.
        val answeredRedial = find(book(imported = false), listOf(rowA, log(t0 + 69_000, 20))).single()
        assertEquals(t0 + 70_000, answeredRedial.startedAtMs)
        assertEquals(20L, answeredRedial.durationS)
    }

    @Test
    fun `the ringing does not count toward Skip calls shorter than`() {
        fun call(talkS: Long): Book {
            var b = CallJournal.seen(ready(), "a1", "whatsapp", Seen.AUDIO, t0)
            b = CallJournal.fail(b, "a1", "helper_not_armed", MissedReason.HELPER_NOT_ARMED.detail, t0 + 100)
            return CallJournal.ended(b, "a1", t0 + 20_000 + talkS * 1000)
        }
        // Rang 20 s, then 5 s of talk: under the 10 s of "Skip calls shorter than".
        assertTrue(find(call(5), listOf(notice("whatsapp", t0 + 20_000, 5))).isEmpty())
        assertEquals(30L, find(call(30), listOf(notice("whatsapp", t0 + 20_000, 30))).single().durationS)
        val skippedHelper = journalCall(ready(), "c1", "whatsapp", t0, 25) {
            CallJournal.fail(it, "c1", "permission", MissedReason.PERMISSION.detail, t0 + 500)
        }
        assertTrue(
            "a helper-seen call is measured from its answer too",
            find(skippedHelper, listOf(notice("whatsapp", t0 + 20_000, 5))).isEmpty()
        )
    }

    @Test
    fun `a call EchoLedger never started recording needs its own answered notice`() {
        val book = journalCall(ready(), "c1", "whatsapp", t0, 300) {
            CallJournal.fail(it, "c1", "permission", MissedReason.PERMISSION.detail, t0 + 500)
        }
        assertTrue("no notice: it may have only rung", find(book).isEmpty())
        assertEquals(
            "a notice of a call 20 minutes later is that call's, not this one's",
            listOf(t0 + 20 * min),
            find(book, listOf(notice("whatsapp", t0 + 20 * min, 300))).map { it.startedAtMs }
        )
        assertEquals(MissedReason.PERMISSION, find(book, listOf(notice("whatsapp", t0 + 4_000, 296))).single().reason)
    }

    @Test
    fun `without notification access an app call is never reported, a SIM call still is`() {
        val book = CallJournal.mark(ready(), State.HELPER_LOST, t0 - min)
        val sightings = listOf(notice("whatsapp", t0, 300), log(t0 + 10 * min, 200))
        val found = MissedRecordings.find(book, sightings, emptyList(), emptyList(), later, notifications = false)
        assertEquals(listOf("phone_call"), found.map { it.app })
    }

    @Test
    fun `a SIM call waits while the Phone app's own recording of it may still be imported`() {
        val book = journalCall(ready(), "c1", "phone_call", t0, 120) {
            CallJournal.fail(CallJournal.stage(it, "c1", Stage.STARTED, t0 + 300), "c1", "unknown", "No sound was captured.", t0 + 121_000)
        }
        val row = listOf(log(t0 - 15_000, 105))
        assertTrue(MissedRecordings.find(book, row, emptyList(), emptyList(), later, importing = listOf(t0..t0 + 120_000)).isEmpty())
        assertEquals(MissedReason.UNKNOWN, find(book, row).single().reason)
        val unseen = CallJournal.mark(ready(), State.HELPER_LOST, t0 - min)
        assertTrue(
            "a SIM call only the call log saw waits too",
            MissedRecordings.find(
                unseen,
                listOf(log(t0, 300)),
                emptyList(),
                emptyList(),
                later,
                importing = listOf(t0..t0 + 300_000)
            ).isEmpty()
        )
    }

    @Test
    fun `auto-record that was not running is turned on from the item, and Android's refusal says so`() {
        val notRunning = CallJournal.mark(ready(), State.PROCESS_START, t0 - min)
        val off = find(notRunning, listOf(notice("whatsapp", t0, 200))).single()
        assertEquals(MissedFix.TURN_ON, off.fix)
        val refused = CallJournal.mark(ready(), State.REFUSED, t0 - min, "background")
        val background = find(refused, listOf(notice("whatsapp", t0, 200))).single()
        assertEquals(MissedReason.HELPER_NOT_ARMED, background.reason)
        assertEquals("Android did not let auto-record start in the background.", background.detail)
        assertEquals(MissedFix.TURN_ON, background.fix)
        val lost = CallJournal.mark(ready(), State.HELPER_LOST, t0 - min)
        assertEquals("the helper itself was missing", MissedFix.START_HELPER, find(lost, listOf(notice("whatsapp", t0, 200))).single().fix)
    }

    @Test
    fun `a viewer's calls that were not wanted anyway are never put on the plan`() {
        val viewer = ready(CallJournal.Choices(autoRecord = true, appsOff = listOf("whatsapp"), phoneCalls = "ask", viewer = true))
        assertTrue("its app is switched off", find(viewer, listOf(notice("whatsapp", t0, 300))).isEmpty())
        assertTrue("phone calls on Ask each time", find(viewer, listOf(log(t0, 300))).isEmpty())
        assertTrue("2 seconds", find(viewer, listOf(notice("zoom", t0, 2))).isEmpty())
        assertEquals("a wanted call is on the plan", MissedReason.PLAN, find(viewer, listOf(notice("zoom", t0, 300))).single().reason)
        val refused = CallJournal.mark(ready(CallJournal.Choices(autoRecord = true, phoneCalls = "off")), State.REFUSED, t0 - min, "plan")
        assertTrue("SIM calls off", find(refused, listOf(log(t0, 300))).isEmpty())
    }

    @Test
    fun `a report whose call turned out recorded is withdrawn, a neighbour's recording does not count`() {
        val item = MissedItem("r1", "phone_call", t0, 120, "unknown", "", t0)
        assertTrue(MissedRecordings.coveredNow(item, listOf(Span(t0 + 20_000, t0 + 140_000))))
        assertTrue(
            "the ringing before the call-log row's talk: a recording that starts in it as long as the talk",
            MissedRecordings.coveredNow(item.copy(durationS = 30), listOf(Span(t0 + 50_000, t0 + 80_000)))
        )
        assertFalse(MissedRecordings.coveredNow(item, listOf(Span(t0 + 10 * min, t0 + 12 * min))))
        val app = item.copy(app = "whatsapp")
        assertFalse(MissedRecordings.coveredNow(app, listOf(Span(t0 + 150_000, t0 + 200_000))))
    }

    @Test
    fun `a voice note 20 s after a missed SIM call neither hides it nor withdraws it`() {
        val book = CallJournal.mark(ready(), State.HELPER_LOST, t0 - min)
        // Only the call log saw the call: 2 minutes of talk. The voice note starts 20 s after the talk ended.
        val voiceNote = Span(t0 + 140_000, t0 + 170_000)
        assertEquals(listOf(t0), find(book, listOf(log(t0, 120)), recordings = listOf(voiceNote)).map { it.startedAtMs })
        val item = MissedItem("r1", "phone_call", t0, 120, "helper_not_armed", "", t0)
        assertFalse(MissedRecordings.coveredNow(item, listOf(voiceNote)))

        // A short call that rang a minute first: its Phone app recording starts after the talk time, as long as the talk.
        val short = item.copy(durationS = 20)
        assertTrue(MissedRecordings.coveredNow(short, listOf(Span(t0 + 60_000, t0 + 81_000))))
        assertFalse(
            "a recording of another length there is another one",
            MissedRecordings.coveredNow(short, listOf(Span(t0 + 60_000, t0 + 140_000)))
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
