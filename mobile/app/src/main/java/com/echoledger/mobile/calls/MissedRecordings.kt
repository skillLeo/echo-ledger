package com.echoledger.mobile.calls

import com.echoledger.mobile.core.PhoneCalls
import com.echoledger.mobile.core.SourceApp
import com.echoledger.mobile.recording.CallJournal
import java.time.Instant
import java.time.temporal.ChronoUnit
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
 * the person discarded it, or when it started before this phone kept a journal.
 */
object MissedRecordings {
    /** A call seen outside the journal: [by] is [CALL_LOG] or [NOTIFICATION]; [endMs] null while its length is unknown. */
    data class Sighting(val app: String, val startMs: Long, val endMs: Long?, val answered: Boolean, val by: String)

    /** A recording that exists or existed around a call (a manual one counts too). */
    data class Span(val startMs: Long, val endMs: Long)

    data class Found(val app: String, val startedAtMs: Long, val durationS: Long?, val reason: MissedReason, val detail: String)

    const val CALL_LOG = "call_log"
    const val NOTIFICATION = "notification"

    /** A call is judged this long after it ended: saving the recording takes a moment. */
    const val SETTLE_MS = 2 * 60_000L

    /** Two reports of one call (the journal, the call log, a notification) start at most this far apart. */
    const val MATCH_MS = 2 * 60_000L

    /** A call still open in the journal this long after its last news is judged as it stands. */
    const val STALE_MS = 30 * 60_000L

    /**
     * Each call the phone saw since the journal began that should have been recorded but was not, oldest first, and not
     * already in [known] (the items found before, by their app and start time).
     */
    fun find(book: CallJournal.Book, sightings: List<Sighting>, recordings: List<Span>, known: List<MissedItem>, nowMs: Long): List<Found> {
        val calls = book.calls.filter { it.seenBy == CallJournal.Seen.HELPER || it.seenBy == CallJournal.Seen.AUDIO }
        val spans = recordings + book.calls.filter { CallJournal.Result.of(it.result) in RECORDED }.map {
            Span(it.atMs, it.endedAtMs ?: it.atMs)
        }
        val fromJournal = calls.mapNotNull { call ->
            val match = sightings.filter { same(it.app, call.app) && near(it.startMs, it.endMs, call.atMs, call.endedAtMs) }
            judgeJournal(book, call, match, spans, nowMs)
        }
        val fromSightings = merge(sightings)
            .filter { s -> calls.none { same(s.app, it.app) && near(s.startMs, s.endMs, it.atMs, it.endedAtMs) } }
            .mapNotNull { judgeSighting(book, it, spans, nowMs) }
        val seen = mutableListOf<Found>()
        return (fromJournal + fromSightings)
            .filter { it.startedAtMs >= book.since }
            .sortedBy { it.startedAtMs }
            .filter { f ->
                val again = known.any { same(it.app, f.app) && kotlin.math.abs(it.startedAtMs - f.startedAtMs) <= MATCH_MS } ||
                    seen.any { same(it.app, f.app) && kotlin.math.abs(it.startedAtMs - f.startedAtMs) <= MATCH_MS }
                if (!again) seen += f
                !again
            }
    }

    private val RECORDED = setOf(CallJournal.Result.KEPT, CallJournal.Result.SKIPPED, CallJournal.Result.DISCARDED)

    /** A call the app itself saw: what the journal says, unless it was recorded after all (the Phone app's recording). */
    private fun judgeJournal(
        book: CallJournal.Book,
        call: CallJournal.Call,
        sightings: List<Sighting>,
        spans: List<Span>,
        nowMs: Long
    ): Found? {
        val result = CallJournal.Result.of(call.result)
        if (result != null && result != CallJournal.Result.FAILED) return null
        val endMs = call.endedAtMs ?: sightings.mapNotNull { it.endMs }.maxOrNull()
        if (endMs != null && nowMs - endMs < SETTLE_MS) return null
        val (reason, detail) = when {
            call.failure != null -> MissedReason.of(call.failure.reason) to call.failure.detail
            book.processStartedAfter(call.lastActivityMs()) -> MissedReason.CRASH to MissedReason.CRASH.detail
            nowMs - call.lastActivityMs() >= STALE_MS -> MissedReason.UNKNOWN to MissedReason.UNKNOWN.detail
            else -> return null
        }
        if (sightings.isNotEmpty() && sightings.none { it.answered }) return null
        val lengthMs = endMs?.let { it - call.atMs }
        if (!wanted(book, call.app, call.atMs, lengthMs)) return null
        if (recorded(spans, call.atMs, endMs ?: call.atMs)) return null
        return Found(call.app, call.atMs, lengthMs?.let { it / 1000 }, reason, detail)
    }

    /** A call only the call log or a notification saw: why the app missed it, from auto-record's timeline of then. */
    private fun judgeSighting(book: CallJournal.Book, s: Sighting, spans: List<Span>, nowMs: Long): Found? {
        val endMs = s.endMs ?: return null
        if (!s.answered || nowMs - endMs < SETTLE_MS) return null
        val choices = book.choicesAt(s.startMs) ?: return null
        val service = book.serviceAt(s.startMs)
        val reason = when {
            choices.viewer && choices.autoRecord -> MissedReason.PLAN
            service.refused == "plan" && choices.autoRecord -> MissedReason.PLAN
            !wanted(book, s.app, s.startMs, endMs - s.startMs) -> return null
            service.refused == "android" -> return null
            service.armed && service.helperReady -> MissedReason.DETECTION
            else -> MissedReason.HELPER_NOT_ARMED
        }
        if (recorded(spans, s.startMs, endMs)) return null
        return Found(s.app, s.startMs, (endMs - s.startMs) / 1000, reason, detailOf(reason, service))
    }

    /** The sentence for [reason]; auto-record that was not running says so instead of naming the helper. */
    private fun detailOf(reason: MissedReason, service: CallJournal.Service): String = when {
        reason == MissedReason.HELPER_NOT_ARMED && !service.armed -> "Auto-record was not running when the call started."
        else -> reason.detail
    }

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

    /** A recording that really overlaps the call (a little slack for the answer and the clocks); a neighbour does not count. */
    private fun recorded(spans: List<Span>, startMs: Long, endMs: Long): Boolean =
        spans.any { it.startMs <= endMs + SLACK_MS && it.endMs >= startMs - SLACK_MS }

    private const val SLACK_MS = 10_000L

    /** The call-log row and the dialer's notification of one SIM call (or two notices of one app call) are one call. */
    private fun merge(sightings: List<Sighting>): List<Sighting> {
        val out = mutableListOf<Sighting>()
        sightings.sortedBy { it.startMs }.forEach { s ->
            val i = out.indexOfFirst { same(it.app, s.app) && near(it.startMs, it.endMs, s.startMs, s.endMs) }
            if (i < 0) {
                out += s
            } else {
                val a = out[i]
                // The call log knows the talk time; a notification without an end learns it from the other.
                val end = listOfNotNull(a.endMs, s.endMs).maxOrNull()
                out[i] = a.copy(endMs = end, answered = a.answered || s.answered)
            }
        }
        return out
    }

    /** Twins installed side by side (WhatsApp and WhatsApp Business) are easily taken for each other: one family. */
    private fun family(app: String): String = if (app == SourceApp.WHATSAPP_BUSINESS.wire) SourceApp.WHATSAPP.wire else app

    private fun same(a: String, b: String) = family(a) == family(b)

    /** Two reports of one call: they start close together, or their times really overlap. */
    private fun near(aStart: Long, aEnd: Long?, bStart: Long, bEnd: Long?): Boolean {
        if (kotlin.math.abs(aStart - bStart) <= MATCH_MS) return true
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
     * `POST /api/devices/missed-recordings` (contract 1.8.0-c): only its fields, never names, numbers, audio or free text
     * (the phone shows its own words for the reason).
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
 * or refused). [ref] is the report's `client_ref`, so a retry never makes a second item.
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
    val refused: Boolean = false
) {
    val waiting: Boolean get() = !sent && !refused
}
