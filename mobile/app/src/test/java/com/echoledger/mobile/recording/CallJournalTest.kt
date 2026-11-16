package com.echoledger.mobile.recording

import com.echoledger.mobile.recording.CallJournal.Book
import com.echoledger.mobile.recording.CallJournal.Result
import com.echoledger.mobile.recording.CallJournal.Seen
import com.echoledger.mobile.recording.CallJournal.Stage
import com.echoledger.mobile.recording.CallJournal.State
import java.time.Instant
import java.time.ZoneOffset
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

/** The capture journal (v1.8 0.2b): the stages of a call in order, 7 days kept, and the lines Copy diagnostics shows. */
class CallJournalTest {
    private val t0 = Instant.parse("2026-11-16T09:12:00Z").toEpochMilli()
    private val day = 24 * 60 * 60 * 1000L

    private fun recorded(book: Book = Book(since = t0 - day), id: String = "c1", at: Long = t0): Book {
        var b = CallJournal.seen(book, id, "whatsapp", Seen.HELPER, at)
        b = CallJournal.stage(b, id, Stage.ARMED, at + 100, "helper ready")
        b = CallJournal.stage(b, id, Stage.STARTED, at + 400)
        b = CallJournal.stage(b, id, Stage.BOTH_AUDIO, at + 2_100)
        b = CallJournal.ended(b, id, at + 312_000)
        return CallJournal.finish(b, id, Result.KEPT, at + 315_000, keptMs = 304_000)
    }

    private fun stagesOf(book: Book, id: String = "c1") = book.call(id)!!.stages.map { it.stage }

    @Test
    fun `a recorded call goes through the five stages in order`() {
        val book = recorded()
        assertEquals(listOf("detected", "armed", "started", "both_audio", "saved"), stagesOf(book))
        val call = book.call("c1")!!
        assertEquals("kept", call.result)
        assertEquals(304_000L, call.keptMs)
        assertEquals(t0 + 312_000, call.endedAtMs)
        assertNull(call.failure)
    }

    @Test
    fun `stages reported out of order by the threads are kept in order, each once`() {
        var b = CallJournal.seen(Book(), "c1", "zoom", Seen.HELPER, t0)
        // The reader thread hears both sides before the main thread writes "started".
        b = CallJournal.stage(b, "c1", Stage.BOTH_AUDIO, t0 + 900)
        b = CallJournal.stage(b, "c1", Stage.STARTED, t0 + 400)
        b = CallJournal.stage(b, "c1", Stage.ARMED, t0 + 100)
        b = CallJournal.stage(b, "c1", Stage.STARTED, t0 + 5_000)
        assertEquals(listOf("detected", "armed", "started", "both_audio"), stagesOf(b))
        assertEquals("the first report counts", t0 + 400, b.call("c1")!!.stages.single { it.stage == "started" }.atMs)
    }

    @Test
    fun `a call that stops early says the stage it stopped at and why`() {
        var b = CallJournal.seen(Book(), "c1", "phone_call", Seen.HELPER, t0)
        b = CallJournal.stage(b, "c1", Stage.ARMED, t0 + 50)
        b = CallJournal.fail(b, "c1", "mic_busy", "Another app was using the microphone.", t0 + 600)
        val call = b.call("c1")!!
        assertEquals("failed", call.result)
        assertEquals(CallJournal.Failure("started", "mic_busy", "Another app was using the microphone."), call.failure)
        assertNull("the call goes on after a failure at its start", call.endedAtMs)
        // A result is final, except a failure at the start: a later start of the same call opens it again (see below).
        val discarded = CallJournal.finish(b, "c1", Result.NOT_WANTED, t0 + 1_000)
        assertEquals("not wanted is no rescue", call, discarded.call("c1"))
        assertEquals("a second failure is no rescue", call, CallJournal.fail(b, "c1", "unknown", "x", t0 + 1_100).call("c1"))
    }

    @Test
    fun `a backup start that works replaces the start's failure, which stays as a note`() {
        var b = CallJournal.seen(Book(), "c1", "whatsapp", Seen.HELPER, t0)
        b = CallJournal.stage(b, "c1", Stage.ARMED, t0 + 100, "helper ready")
        b = CallJournal.fail(b, "c1", "helper_not_armed", "The helper was not connected when the call started.", t0 + 200)
        b = CallJournal.stage(b, "c1", Stage.STARTED, t0 + 5_200, "backup start")
        b = CallJournal.stage(b, "c1", Stage.BOTH_AUDIO, t0 + 7_000)
        b = CallJournal.ended(b, "c1", t0 + 312_000)
        b = CallJournal.finish(b, "c1", Result.KEPT, t0 + 315_000, keptMs = 304_000)
        val call = b.call("c1")!!
        assertEquals("kept", call.result)
        assertNull(call.failure)
        assertEquals(listOf("detected", "armed", "started", "both_audio", "saved"), stagesOf(b))
        val line = CallJournal.describe(b, ZoneOffset.UTC).single()
        assertFalse(line, line.contains("stopped at"))
        assertTrue(line, line.contains("first start failed: helper_not_armed") && line.endsWith("· kept (5:04)"))
    }

    @Test
    fun `a crash, a silent recording or a later failure stays final`() {
        var b = CallJournal.seen(Book(), "c1", "whatsapp", Seen.HELPER, t0)
        b = CallJournal.stage(b, "c1", Stage.STARTED, t0 + 400)
        b = CallJournal.fail(b, "c1", "unknown", "No sound was captured, so the recording was not kept.", t0 + 300_000)
        b = CallJournal.stage(b, "c1", Stage.BOTH_AUDIO, t0 + 301_000)
        b = CallJournal.finish(b, "c1", Result.KEPT, t0 + 302_000)
        assertEquals("a failure after the start is final", "failed", b.call("c1")!!.result)

        var c = CallJournal.seen(Book(), "c2", "zoom", Seen.HELPER, t0)
        c = CallJournal.processStarted(c, t0 + 60_000)
        c = CallJournal.stage(c, "c2", Stage.STARTED, t0 + 61_000)
        assertEquals("a crash is final", "crash", c.call("c2")!!.failure?.reason)
    }

    @Test
    fun `a call by hand starts at started, and the Phone app's recording is saved`() {
        var b = CallJournal.seen(Book(), "h1", "other", Seen.BY_HAND, t0)
        b = CallJournal.fail(b, "h1", "unknown", "No sound was captured.", t0 + 9_000)
        assertEquals(listOf("started"), stagesOf(b, "h1"))
        assertEquals("both_audio", b.call("h1")!!.failure!!.stage)

        b = CallJournal.seen(b, "p1", "phone_call", Seen.PHONE_APP, t0)
        b = CallJournal.finish(b, "p1", Result.KEPT, t0 + 60_000, "imported", keptMs = 58_000)
        assertEquals(listOf("started", "saved"), stagesOf(b, "p1"))
    }

    @Test
    fun `skipped calls are saved, discarded and unwanted ones are not`() {
        var b = CallJournal.seen(Book(), "s", "whatsapp", Seen.HELPER, t0)
        b = CallJournal.finish(b, "s", Result.SKIPPED, t0 + 8_000, "too short")
        b = CallJournal.seen(b, "d", "whatsapp", Seen.HELPER, t0)
        b = CallJournal.finish(b, "d", Result.DISCARDED, t0 + 2_000, "shorter than 3 s")
        b = CallJournal.seen(b, "n", "whatsapp_business", Seen.HELPER, t0)
        b = CallJournal.finish(b, "n", Result.NOT_WANTED, t0 + 1_000, "auto-record is off for WhatsApp Business")
        assertTrue(b.call("s")!!.reached(Stage.SAVED))
        assertFalse(b.call("d")!!.reached(Stage.SAVED))
        assertFalse(b.call("n")!!.reached(Stage.SAVED))
    }

    @Test
    fun `a new process closes every open call as a crash at its next stage`() {
        var b = recorded()
        b = CallJournal.seen(b, "open", "whatsapp", Seen.HELPER, t0 + 400_000)
        b = CallJournal.stage(b, "open", Stage.ARMED, t0 + 400_100)
        b = CallJournal.stage(b, "open", Stage.STARTED, t0 + 400_500)
        b = CallJournal.processStarted(b, t0 + 900_000)

        val open = b.call("open")!!
        assertEquals("failed", open.result)
        assertEquals("both_audio", open.failure!!.stage)
        assertEquals("crash", open.failure!!.reason)
        assertEquals("kept calls stay kept", "kept", b.call("c1")!!.result)
        assertTrue(b.processStartedAfter(t0 + 400_500))
        assertEquals(State.PROCESS_START, b.marks.last().kind)
    }

    @Test
    fun `the timeline says whether auto-record ran with the helper at a moment`() {
        var b = Book()
        b = CallJournal.mark(b, State.ARMED, t0, "helper ready")
        b = CallJournal.mark(b, State.HELPER_LOST, t0 + 60_000)
        b = CallJournal.mark(b, State.HELPER_READY, t0 + 120_000)
        b = CallJournal.mark(b, State.PROCESS_START, t0 + 180_000)
        b = CallJournal.mark(b, State.REFUSED, t0 + 240_000, "plan")
        b = CallJournal.mark(b, State.ARMED, t0 + 300_000, "helper not running")

        assertEquals(CallJournal.Service(armed = false, helperReady = false, refused = null), b.serviceAt(t0 - 1))
        assertEquals(CallJournal.Service(true, true, null), b.serviceAt(t0 + 30_000))
        assertEquals(CallJournal.Service(true, false, null), b.serviceAt(t0 + 90_000))
        assertEquals(CallJournal.Service(true, true, null), b.serviceAt(t0 + 150_000))
        assertEquals(CallJournal.Service(false, false, null), b.serviceAt(t0 + 200_000))
        assertEquals(CallJournal.Service(false, false, "plan"), b.serviceAt(t0 + 250_000))
        assertEquals(CallJournal.Service(true, false, null), b.serviceAt(t0 + 310_000))
    }

    @Test
    fun `the person's choices are kept only when they change, and read at a moment`() {
        val on = CallJournal.Choices(autoRecord = true, appsOff = listOf("zoom"), phoneCalls = "always", shorterThanS = 20)
        var b = CallJournal.choices(Book(), on, t0)
        b = CallJournal.choices(b, on, t0 + 1_000)
        b = CallJournal.choices(b, on.copy(autoRecord = false), t0 + 2_000)
        assertEquals(2, b.settings.size)
        assertNull(b.choicesAt(t0 - 1))
        assertEquals(on, b.choicesAt(t0 + 1_500))
        assertFalse(b.choicesAt(t0 + 2_000)!!.autoRecord)
    }

    @Test
    fun `seven days are kept, and the state from before the window stays known`() {
        var b = Book(since = t0 - 10 * day)
        b = CallJournal.choices(b, CallJournal.Choices(autoRecord = true), t0 - 9 * day)
        b = CallJournal.mark(b, State.ARMED, t0 - 9 * day, "helper ready")
        b = CallJournal.mark(b, State.HELPER_LOST, t0 - 8 * day)
        b = recorded(b, "old", t0 - 8 * day)
        b = recorded(b, "edge", t0 - 7 * day + 1)
        b = recorded(b, "new", t0)

        val kept = CallJournal.rotate(b, nowMs = t0 + 1)
        assertEquals(listOf("edge", "new"), kept.calls.map { it.id })
        assertEquals(
            "one mark says how auto-record stood when the window begins",
            listOf(CallJournal.Mark(t0 - 8 * day, State.ARMED, "helper not running")),
            kept.marks
        )
        assertEquals(1, kept.settings.size)
        assertEquals(CallJournal.Service(armed = true, helperReady = false, refused = null), kept.serviceAt(t0))
        assertTrue(kept.choicesAt(t0)!!.autoRecord)
    }

    @Test
    fun `a phone with very many calls keeps only the newest`() {
        var b = Book()
        repeat(CallJournal.MAX_CALLS + 25) { i -> b = recorded(b, "c$i", t0 + i * 60_000L) }
        val kept = CallJournal.rotate(b, t0 + (CallJournal.MAX_CALLS + 25) * 60_000L)
        assertEquals(CallJournal.MAX_CALLS, kept.calls.size)
        assertEquals("c25", kept.calls.first().id)
    }

    @Test
    fun `a phone with very many calls starts its journal at the oldest call it kept`() {
        var b = Book(since = t0 - day)
        repeat(CallJournal.MAX_CALLS + 1) { i -> b = recorded(b, "c$i", t0 + i * 60_000L) }
        val kept = CallJournal.rotate(b, t0 + (CallJournal.MAX_CALLS + 1) * 60_000L)
        assertEquals(t0 + 60_000L, kept.since)
        assertEquals("nothing dropped for room: the start stays", t0 - day, CallJournal.rotate(recorded(), t0 + day).since)
    }

    @Test
    fun `the answer the recording's gate saw is kept once, also when it comes with the call's end`() {
        var b = CallJournal.seen(Book(), "c1", "phone_call", Seen.HELPER, t0)
        b = CallJournal.stage(b, "c1", Stage.STARTED, t0 + 400)
        assertNull(b.call("c1")!!.answeredAtMs)
        b = CallJournal.answered(b, "c1", t0 + 8_000, "the other side spoke")
        b = CallJournal.answered(b, "c1", t0 + 9_000, "call log talk time")
        assertEquals(t0 + 8_000, b.call("c1")!!.answeredAtMs)
        assertEquals("the other side spoke", b.call("c1")!!.answeredBy)
        var late = CallJournal.finish(CallJournal.seen(Book(), "c2", "phone_call", Seen.HELPER, t0), "c2", Result.KEPT, t0 + 60_000)
        late = CallJournal.answered(late, "c2", t0 + 7_000, "call log talk time")
        assertEquals(t0 + 7_000, late.call("c2")!!.answeredAtMs)
        assertTrue(
            CallJournal.describe(b, ZoneOffset.UTC).single().contains("· answered 8.0 s (the other side spoke)")
        )
    }

    @Test
    fun `the diagnostics lines say each stage with its time, the result, and where a call stopped`() {
        var b = recorded()
        b = CallJournal.seen(b, "c2", "phone_call", Seen.AUDIO, t0 + 3_600_000)
        b = CallJournal.fail(b, "c2", "helper_not_armed", "The helper was not connected when the call started.", t0 + 3_600_100)
        b = CallJournal.ended(b, "c2", t0 + 3_660_000)
        b = CallJournal.seen(b, "c3", "whatsapp_business", Seen.HELPER, t0 + 7_200_000)
        b = CallJournal.finish(b, "c3", Result.NOT_WANTED, t0 + 7_200_100, "auto-record is off for WhatsApp Business")
        b = CallJournal.ended(b, "c3", t0 + 7_210_000)
        b = CallJournal.seen(b, "c4", "zoom", Seen.HELPER, t0 + 9_000_000)
        b = CallJournal.finish(b, "c4", Result.SKIPPED, t0 + 9_008_000, "too short", keptMs = 6_000)

        assertEquals(
            listOf(
                "11-16 09:12 WhatsApp · 5:12 · helper · detected → armed 0.1 s (helper ready) → started 0.4 s → " +
                    "both channels have audio 2.1 s → saved · kept (5:04)",
                "11-16 10:12 Phone call · 1:00 · audio · detected · stopped at armed: helper_not_armed, The helper was not " +
                    "connected when the call started.",
                "11-16 11:12 WhatsApp Business · 0:10 · helper · detected · not recorded: auto-record is off for WhatsApp Business",
                "11-16 11:42 Zoom · 0:08 · helper · detected → saved · skipped: too short (0:06)"
            ),
            CallJournal.describe(b, ZoneOffset.UTC)
        )
    }

    @Test
    fun `the timeline lines for diagnostics hold no names or numbers`() {
        var b = CallJournal.choices(Book(), CallJournal.Choices(autoRecord = true, appsOff = listOf("zoom"), phoneCalls = "ask"), t0)
        b = CallJournal.mark(b, State.ARMED, t0 + 1_000, "helper ready")
        b = CallJournal.mark(b, State.REFUSED, t0 + 2_000, "plan")
        assertEquals(
            listOf(
                "11-16 09:12:00 settings (auto-record on, phone calls ask, skip under 10 s, off for zoom)",
                "11-16 09:12:01 armed (helper ready)",
                "11-16 09:12:02 refused (plan)"
            ),
            CallJournal.describeMarks(b, ZoneOffset.UTC)
        )
    }

    @Test
    fun `the person's settings become the choices the detector reads`() {
        val settings = com.echoledger.mobile.data.AppSettings(
            autoRecordArmed = true,
            autoRecordApps = mapOf("zoom" to false, "whatsapp" to true, "slack" to false),
            phoneCalls = "nonsense",
            skipCallsShorterThanS = 20
        )
        assertEquals(
            CallJournal.Choices(autoRecord = true, appsOff = listOf("slack", "zoom"), phoneCalls = "off", shorterThanS = 20),
            CallJournal.choicesOf(settings)
        )
    }
}
