package com.echoledger.mobile.calls

import com.echoledger.mobile.core.Owner
import com.echoledger.mobile.core.PhoneCalls
import com.echoledger.mobile.core.SourceApp
import com.echoledger.mobile.recording.CallJournal
import java.time.Instant
import java.time.temporal.ChronoUnit
import kotlin.math.abs
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.buildJsonObject
import kotlinx.serialization.json.put

/**
 * The missed-recording detector (since v1.8, P0 0.2; pure, unit-tested). It compares every call the phone saw (the
 * capture journal's own calls, the call log's SIM calls in the website build, the call apps' and the dialer's call
 * notifications) with what was recorded, and turns each call that should have been recorded but was not into a
 * [Found], with the exact reason.
 *
 * A call is never "missed" when the person did not want it recorded at that moment (auto-record off, its app switched
 * off, phone calls off or "Ask each time"), when it was shorter than "Skip calls shorter than" or never answered, when
 * the person discarded it, or when it started before this phone kept a journal. Nor is it judged while it may still be
 * going on, more than [JUDGE_DAYS] days back (the journal keeps 7, so what became of each call is still in it), while
 * the Phone app's recording of a SIM call may still be imported, without an answered call notification or call-log row
 * of its own when EchoLedger never heard it answered, and an app call never without notification access.
 */
object MissedRecordings {
    /** A call seen outside the journal: [by] is [CALL_LOG] or [NOTIFICATION]; [endMs] null while its length is unknown. */
    data class Sighting(val app: String, val startMs: Long, val endMs: Long?, val answered: Boolean, val by: String)

    /** A recording that exists or existed around a call (a manual one counts too, a voice note never); [app] when known. */
    data class Span(val startMs: Long, val endMs: Long, val app: String? = null)

    /**
     * A missed call: [fix] is its button (the reason's own, or Turn on when auto-record was not running). [endedAtMs] is
     * when it ended as far as is known; [logOnly] when only the call log knew its end, which is then its start plus the
     * talk time ([durationS]): the ringing before the answer is not known.
     */
    data class Found(
        val app: String,
        val startedAtMs: Long,
        val durationS: Long?,
        val reason: MissedReason,
        val detail: String,
        val fix: MissedFix = reason.fix,
        val endedAtMs: Long? = null,
        val logOnly: Boolean = false
    )

    const val CALL_LOG = "call_log"
    const val NOTIFICATION = "notification"

    /** A call is judged this long after it ended: saving the recording takes a moment. */
    const val SETTLE_MS = 2 * 60_000L

    /** Two reports of one call (the journal, the call log, a notification) start at most this far apart. */
    const val MATCH_MS = 2 * 60_000L

    /** Calls are judged this many days back at most: a day inside the journal's, so what became of each is still known. */
    const val JUDGE_DAYS = CallJournal.KEEP_DAYS - 1
    private const val DAY_MS = 24 * 60 * 60 * 1000L

    /** The oldest call start that is judged at [nowMs]. */
    fun judgeFrom(book: CallJournal.Book, nowMs: Long): Long = maxOf(book.since, nowMs - JUDGE_DAYS * DAY_MS)

    /**
     * Each call the phone saw that should have been recorded but was not, oldest first, and not already in [known] (the
     * items found before, by their app and start time). [ongoing]: a recording is live or still saving, or a call is
     * going on; [importing]: SIM calls whose recording the Phone app's import may still bring in, or that waits for the
     * person to import it; [notifications]: EchoLedger may read call notifications (without it an app call is never
     * reported).
     */
    fun find(
        book: CallJournal.Book,
        sightings: List<Sighting>,
        recordings: List<Span>,
        known: List<MissedItem>,
        nowMs: Long,
        ongoing: Boolean = false,
        importing: List<LongRange> = emptyList(),
        notifications: Boolean = true
    ): List<Found> {
        val from = judgeFrom(book, nowMs)
        val calls = book.calls.filter { (it.seenBy == CallJournal.Seen.HELPER || it.seenBy == CallJournal.Seen.AUDIO) && it.atMs >= from }
        val recent = sightings.filter { it.startMs >= from }
        val spans = recordings + recordedSpans(book)
        // Each call-log row is one call: it goes with the one journal entry of its call, never also with the next call's.
        val rows = recent.filter { it.by == CALL_LOG }
        val rowOf = pairRows(book, rows, nowMs)
        val notices = recent.filter { it.by != CALL_LOG }
        val fromJournal = calls.mapNotNull { call ->
            val end = windowEnd(book, call, nowMs)
            val match = listOfNotNull(rowOf[call.id]) + notices.filter { same(it.app, call.app) && answers(it, call.atMs, end) }
            judgeJournal(book, call, match, spans, nowMs, ongoing)
        }
        val paired = rowOf.values.toSet()
        val unseen = rows.filter { it !in paired } +
            notices.filter { s -> calls.none { same(s.app, it.app) && overlapsCall(s, it.atMs, windowEnd(book, it, nowMs)) } }
        val fromSightings = merge(unseen).mapNotNull { judgeSighting(book, it, spans, nowMs) }
        val all = (fromJournal + fromSightings)
            .filter { it.startedAtMs >= from }
            .filter { notifications || it.app == SourceApp.PHONE_CALL.wire }
        // A SIM call whose Phone app recording is still being looked for, or waits for the person, waits too: each wait
        // holds the one call it is about (the closest start among those its times overlap), never the call after it.
        val held = importing.mapNotNull { range ->
            all.filter { it.app == SourceApp.PHONE_CALL.wire && overlaps(range, it) }.minByOrNull { abs(it.startedAtMs - range.first) }
        }
        val seen = mutableListOf<Found>()
        return all.filter { f -> held.none { it === f } }
            .sortedBy { it.startedAtMs }
            .filter { f ->
                val again = known.any { sameApp(it.app, f.app) && abs(it.startedAtMs - f.startedAtMs) <= MATCH_MS } ||
                    seen.any { same(it.app, f.app) && abs(it.startedAtMs - f.startedAtMs) <= MATCH_MS }
                if (!again) seen += f
                !again
            }
    }

    private val RECORDED = setOf(CallJournal.Result.KEPT, CallJournal.Result.SKIPPED, CallJournal.Result.DISCARDED)

    /**
     * What the journal says was recorded (kept, skipped or thrown away on purpose): the evidence once the file is gone. A
     * voice note is never a call's recording.
     */
    fun recordedSpans(book: CallJournal.Book): List<Span> = book.calls
        .filter { CallJournal.Result.of(it.result) in RECORDED && it.app != SourceApp.VOICE_NOTE.wire }
        .map { Span(it.atMs, it.endedAtMs ?: (it.atMs + (it.keptMs ?: 0)), it.app) }

    /**
     * A recording of [item]'s call turned up after it was found (the Phone app's, imported late): it is withdrawn, also
     * when it was sent already. Only a recording of the call's own times counts (see [covered]); an item kept before its
     * end was known is taken as the call log's: its start plus its length.
     */
    fun coveredNow(item: MissedItem, spans: List<Span>): Boolean {
        val talkMs = item.durationS?.let { it * 1000 }
        val end = item.endedAtMs ?: (item.startedAtMs + (talkMs ?: 0))
        return covered(spans, item.app, item.startedAtMs, end, talkMs.takeIf { item.logOnly || item.endedAtMs == null })
    }

    /** A call the app itself saw: what the journal says, unless it was recorded after all (the Phone app's recording). */
    private fun judgeJournal(
        book: CallJournal.Book,
        call: CallJournal.Call,
        sightings: List<Sighting>,
        spans: List<Span>,
        nowMs: Long,
        ongoing: Boolean
    ): Found? {
        val result = CallJournal.Result.of(call.result)
        if (result != null && result != CallJournal.Result.FAILED) return null
        val knownEnd = call.endedAtMs ?: sightings.mapNotNull { it.endMs }.maxOrNull()
        if (knownEnd == null) {
            // No end known, so it may still be going on: never while a recording or a call is on or one of its notices is
            // still up, and only when something proves it ended (a later call in the journal, or the app starting again).
            if (ongoing || sightings.any { it.endMs == null } || endProof(book, call) == null) return null
        } else if (nowMs - knownEnd < SETTLE_MS || (result == null && ongoing)) {
            return null
        }
        val (reason, detail) = when {
            call.failure != null -> MissedReason.of(call.failure.reason) to call.failure.detail
            book.processStartedAfter(call.lastActivityMs()) -> MissedReason.CRASH to MissedReason.CRASH.detail
            laterCall(book, call) -> MissedReason.UNKNOWN to MissedReason.UNKNOWN.detail
            else -> return null
        }
        // Answered? A notice or call-log row of this very call says so; without one, only when EchoLedger's own answer
        // gate saw the answer (a call that never started recording, or that only the audio mode saw, may have only rung).
        val answered = sightings.filter { it.answered }
        if (answered.isEmpty() && (sightings.isNotEmpty() || !heardAnswered(call))) return null
        // Its length counts from the answer: the ringing is not the call.
        val lengthMs = answered.mapNotNull { answeredLength(it, knownEnd) }.maxOrNull() ?: knownEnd?.let { it - call.atMs }
        if (!wanted(book, call.app, call.atMs, lengthMs)) return null
        // Its own end: the journal's, else a notice's; a call-log row's alone leaves out the ringing before the answer.
        val exactEnd = call.endedAtMs ?: sightings.filter { it.by != CALL_LOG }.mapNotNull { it.endMs }.maxOrNull()
        val logOnly = exactEnd == null && knownEnd != null
        val end = maxOf(exactEnd ?: knownEnd ?: call.lastActivityMs(), call.atMs)
        if (covered(spans, call.app, call.atMs, end, lengthMs.takeIf { logOnly })) return null
        return Found(call.app, call.atMs, lengthMs?.let { it / 1000 }, reason, detail, endedAtMs = end, logOnly = logOnly)
    }

    /**
     * EchoLedger itself saw the call answered: its recording's answer gate opened on an answer (never in the audio mode
     * alone). "Both channels have audio" is no proof: while an outgoing call rings, the ringback tone and your own voice
     * fill both channels.
     */
    private fun heardAnswered(call: CallJournal.Call): Boolean = call.seenBy != CallJournal.Seen.AUDIO && call.answeredAtMs != null

    /** When something proved [call] over: the next call the journal saw, or the app starting again; null without either. */
    private fun endProof(book: CallJournal.Book, call: CallJournal.Call): Long? {
        val after = call.lastActivityMs()
        val restart = book.marks.filter { it.kind == CallJournal.State.PROCESS_START && it.atMs > after }.minOfOrNull { it.atMs }
        val next = book.calls.filter { it.id != call.id && it.atMs > after }.minOfOrNull { it.atMs }
        return listOfNotNull(restart, next).minOrNull()
    }

    private fun laterCall(book: CallJournal.Book, call: CallJournal.Call): Boolean =
        book.calls.any { it.id != call.id && it.atMs > call.lastActivityMs() }

    /** Up to when [call]'s own notices and call-log rows can be: its end, else what proved it over, else now. */
    private fun windowEnd(book: CallJournal.Book, call: CallJournal.Call, nowMs: Long): Long =
        call.endedAtMs ?: endProof(book, call) ?: nowMs

    /**
     * Each call-log row with the one journal entry of its call: of the phone-call entries (the helper's, the audio
     * mode's, an import's, one by hand) whose call it can be, rung at most 2 minutes before the entry began and not after
     * it ended, the one that began closest to it; each entry has one row at most. So an answered call's row never also
     * answers the redial a minute later, nor gives it its length.
     */
    private fun pairRows(book: CallJournal.Book, rows: List<Sighting>, nowMs: Long): Map<String, Sighting> {
        val entries = book.calls.filter { it.app == SourceApp.PHONE_CALL.wire }
        val pairs = rows.flatMap { row ->
            entries.filter { row.startMs in (it.atMs - PhoneCalls.BEFORE_MS)..(windowEnd(book, it, nowMs) + SLACK_MS) }
                .map { Triple(row, it, abs(row.startMs - it.atMs)) }
        }
        val out = LinkedHashMap<String, Sighting>()
        pairs.sortedBy { it.third }.forEach { (row, entry, _) ->
            if (entry.id !in out && out.values.none { it === row }) out[entry.id] = row
        }
        return out
    }

    /** Whether the call notice [s] reports the call EchoLedger saw from [atMs] to [endMs]: its answer-to-end overlaps it. */
    private fun overlapsCall(s: Sighting, atMs: Long, endMs: Long): Boolean =
        s.startMs <= endMs + SLACK_MS && (s.endMs ?: Long.MAX_VALUE) >= atMs - SLACK_MS

    /**
     * Whether the call notice [s] may say that the call EchoLedger saw from [atMs] to [endMs] was answered: it overlaps
     * it and was answered no earlier than that call began. A long call's notice that ends a few seconds into the redial
     * after it is that call's, never the redial's.
     */
    private fun answers(s: Sighting, atMs: Long, endMs: Long): Boolean = overlapsCall(s, atMs, endMs) && s.startMs >= atMs - SLACK_MS

    /** From the answer: a call-log row's talk time, a notice's time from the answer to its end (or the call's). */
    private fun answeredLength(s: Sighting, callEndMs: Long?): Long? = when (s.by) {
        CALL_LOG -> s.endMs?.let { it - s.startMs }
        else -> (s.endMs ?: callEndMs)?.let { it - s.startMs }
    }?.coerceAtLeast(0)

    /**
     * A call only the call log or a notification saw: why the app missed it, from auto-record's timeline of then. Its
     * length is the call log's talk time when it has a row (the ringing is not the call), else its notice's.
     */
    private fun judgeSighting(book: CallJournal.Book, s: Unseen, spans: List<Span>, nowMs: Long): Found? {
        val endMs = s.endMs ?: return null
        if (!s.answered || nowMs - endMs < SETTLE_MS) return null
        val choices = book.choicesAt(s.startMs) ?: return null
        val lengthMs = s.talkMs ?: (endMs - s.startMs)
        // A call the person did not want recorded is never missed, whatever the plan or the role said then.
        if (!wanted(book, s.app, s.startMs, lengthMs)) return null
        val service = book.serviceAt(s.startMs)
        val reason = when {
            choices.viewer || service.refused == "plan" -> MissedReason.PLAN
            service.refused == "android" -> return null
            service.armed && service.helperReady -> MissedReason.DETECTION
            else -> MissedReason.HELPER_NOT_ARMED
        }
        if (covered(spans, s.app, s.startMs, endMs, s.talkMs.takeIf { s.logOnly })) return null
        return Found(
            s.app,
            s.startMs,
            lengthMs / 1000,
            reason,
            detailOf(reason, service),
            fixOf(reason, service),
            endedAtMs = endMs,
            logOnly = s.logOnly
        )
    }

    /** The sentence for [reason]: auto-record that was not running, or that Android kept from starting, says so. */
    private fun detailOf(reason: MissedReason, service: CallJournal.Service): String = when {
        reason == MissedReason.HELPER_NOT_ARMED && service.refused == "background" ->
            "Android did not let auto-record start in the background."

        reason == MissedReason.HELPER_NOT_ARMED && !service.armed -> "Auto-record was not running when the call started."

        else -> reason.detail
    }

    /** Auto-record that was not running is turned on from the item; the helper's own problems open the Ready check. */
    private fun fixOf(reason: MissedReason, service: CallJournal.Service): MissedFix =
        if (reason == MissedReason.HELPER_NOT_ARMED && !service.armed) MissedFix.TURN_ON else reason.fix

    /**
     * Whether the person wanted [app]'s call at [atMs] recorded: auto-record on, its app on, phone calls set to Always,
     * and at least as long as "Skip calls shorter than" (and the 3 s below which a call is a missed one).
     */
    private fun wanted(book: CallJournal.Book, app: String, atMs: Long, lengthMs: Long?): Boolean {
        val choices = book.choicesAt(atMs) ?: return false
        if (!choices.autoRecord) return false
        if (app == SourceApp.PHONE_CALL.wire) {
            if (PhoneCalls.mode(choices.phoneCalls) != PhoneCalls.ALWAYS) return false
        } else if (app in choices.appsOff) {
            return false
        }
        val shortest = maxOf(CallStateMachine.MIN_DURATION_MS, choices.shorterThanS * 1000L)
        return lengthMs == null || lengthMs >= shortest
    }

    /**
     * Whether a recording covers the call of [app] from [startMs] to [endMs]: one that really overlaps it (a little slack
     * for the answer and the clocks). When only the call log knows the call ([talkMs] given), its end is its start plus
     * the talk time and the ringing before the answer is not known: a recording of the same app that starts in that
     * ringing counts too, but only when it is as long as the talk. A neighbour (the next call, a voice note) never does.
     */
    private fun covered(spans: List<Span>, app: String, startMs: Long, endMs: Long, talkMs: Long?): Boolean = spans.any { s ->
        val overlaps = s.startMs <= endMs + SLACK_MS && s.endMs >= startMs - SLACK_MS
        val afterRinging = talkMs != null &&
            s.startMs in startMs..(startMs + PhoneCalls.BEFORE_MS + SLACK_MS) &&
            (s.app == null || same(s.app, app)) &&
            abs(s.endMs - s.startMs - talkMs) <= maxOf(LENGTH_SLACK_MS, talkMs * 15 / 100)
        overlaps || afterRinging
    }

    /** Whether the wait over [range] is about [f]'s call: their own times overlap. */
    private fun overlaps(range: LongRange, f: Found): Boolean {
        val end = f.endedAtMs ?: (f.startedAtMs + (f.durationS ?: 0) * 1000)
        return range.first <= end + SLACK_MS && range.last >= f.startedAtMs - SLACK_MS
    }

    private const val SLACK_MS = 10_000L

    /** A recording as long as a call's talk: within 10 s, or 15 % of a long call. */
    private const val LENGTH_SLACK_MS = 10_000L

    /**
     * One call only the call log or notifications saw: [talkMs] the call log's talk time when it has a row, [logOnly]
     * when only the call log saw it (its end is then its start plus the talk time, without the ringing).
     */
    private data class Unseen(
        val app: String,
        val startMs: Long,
        val endMs: Long?,
        val answered: Boolean,
        val talkMs: Long?,
        val logOnly: Boolean
    )

    /**
     * The call-log row and the dialer's notification of one SIM call (or two notices of one app call) are one call: each
     * report joins the call that started closest to it. Two call-log rows are always two calls.
     */
    private fun merge(sightings: List<Sighting>): List<Unseen> {
        val out = mutableListOf<Unseen>()
        sightings.sortedBy { it.startMs }.forEach { s ->
            val row = s.by == CALL_LOG
            val talk = if (row) s.endMs?.let { it - s.startMs } else null
            val i = out.indices
                .filter { j ->
                    out[j].let {
                        same(it.app, s.app) && !(row && it.talkMs != null) &&
                            near(it.startMs, it.endMs, s.startMs, s.endMs)
                    }
                }
                .minByOrNull { j -> abs(out[j].startMs - s.startMs) }
            if (i == null) {
                out += Unseen(s.app, s.startMs, s.endMs, s.answered, talk, logOnly = row)
            } else {
                val a = out[i]
                // The call log knows the talk time; a notification knows the end (and one without an end learns it).
                out[i] = a.copy(
                    endMs = listOfNotNull(a.endMs, s.endMs).maxOrNull(),
                    answered = a.answered || s.answered,
                    talkMs = a.talkMs ?: talk,
                    logOnly = a.logOnly && row
                )
            }
        }
        return out
    }

    /** Twins installed side by side (WhatsApp and WhatsApp Business) are easily taken for each other: one family. */
    private fun family(app: String): String = if (app == SourceApp.WHATSAPP_BUSINESS.wire) SourceApp.WHATSAPP.wire else app

    private fun same(a: String, b: String) = family(a) == family(b)

    /** Two items of one app family (WhatsApp and WhatsApp Business are one). */
    fun sameApp(a: String, b: String): Boolean = same(a, b)

    /** Two reports of one call: they start close together, or their times really overlap. */
    private fun near(aStart: Long, aEnd: Long?, bStart: Long, bEnd: Long?): Boolean {
        if (abs(aStart - bStart) <= MATCH_MS) return true
        val aTo = aEnd ?: aStart
        val bTo = bEnd ?: bStart
        return aStart < bTo && bStart < aTo
    }

    // ---- what the person sees and what the server gets ------------------------------------------------------------

    /** "WhatsApp call · 5:12 · The helper was not connected", the server's body for the same item. */
    fun body(item: MissedItem): String {
        val app = SourceApp.from(item.app)
        val what = if (app == SourceApp.PHONE_CALL) "Phone call" else "${app.label} call"
        val length = item.durationS?.let { s ->
            if (s >=
                3600
            ) {
                "%d:%02d:%02d".format(s / 3600, s / 60 % 60, s % 60)
            } else {
                "%d:%02d".format(s / 60, s % 60)
            }
        }
        return listOfNotNull(what, length, MissedReason.of(item.reason).phrase).joinToString(" · ")
    }

    /**
     * `POST /api/devices/missed-recordings` (contract 1.8.0-e): only its fields, never names, numbers, audio or free text
     * (the phone shows its own words for the reason; [MissedItem.detail] stays on the phone).
     */
    fun payload(item: MissedItem, appVersion: String): JsonObject = buildJsonObject {
        put("client_ref", item.ref)
        put("platform", "android")
        put("app_version", appVersion.take(32))
        put("source_app", item.app)
        put("call_started_at", Instant.ofEpochMilli(item.startedAtMs).truncatedTo(ChronoUnit.SECONDS).toString())
        put("call_duration_s", item.durationS?.coerceIn(0, MAX_DURATION_S))
        put("reason", item.reason)
    }

    /** The server takes calls up to this old. */
    const val MAX_AGE_MS = 30L * 24 * 60 * 60 * 1000
    private const val MAX_DURATION_S = 86_400L

    /** `client_ref` as the contract wants it. */
    val CLIENT_REF = Regex("^[A-Za-z0-9._:-]{1,64}$")
}

/**
 * A missed recording on this phone: its Activity item (until dismissed) and its report to the server (until accepted
 * or refused). [ref] is the report's `client_ref`, so a retry never makes a second item. [detail] is shown on the phone
 * only, never sent.
 */
@Serializable
data class MissedItem(
    val ref: String,
    val app: String,
    val startedAtMs: Long,
    val durationS: Long? = null,
    val reason: String,
    val detail: String,
    val foundAtMs: Long,
    val read: Boolean = false,
    val dismissed: Boolean = false,
    /** The server has it ([serverId] is its `missed_recording_id`). */
    val sent: Boolean = false,
    val serverId: String? = null,
    /** The server refused it for good (an old server, a call too old): it is never sent again. */
    val refused: Boolean = false,
    /** The account it was found under: shown and sent only while that account is the one here (as recordings wait). */
    val owner: Owner? = null,
    /** Its button, when not the reason's own ([MissedFix] name; Turn on when auto-record was not running). */
    val fix: String? = null,
    /** A recording of the call turned up after all (the Phone app's, imported late): it left Activity and is never sent. */
    val withdrawn: Boolean = false,
    /** When the call ended as far as is known (null on items found before it was kept: their start plus their length). */
    val endedAtMs: Long? = null,
    /** Only the call log knew the call: its end is its start plus the talk time, without the ringing before the answer. */
    val logOnly: Boolean = false,
    /** Its server copy was marked read, after it was read, dismissed or withdrawn here. */
    val serverRead: Boolean = false
) {
    val waiting: Boolean get() = !sent && !refused && !withdrawn

    /** The server has it, and it was read, dismissed or withdrawn here: its server copy is to be marked read too. */
    val serverCopyToRead: Boolean get() = serverId != null && !serverRead && (read || dismissed || withdrawn)

    /** The button: Turn on, or the reason's own. */
    val button: MissedFix get() = MissedFix.of(fix) ?: MissedReason.of(reason).fix
}
