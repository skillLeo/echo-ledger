package com.echoledger.mobile.recording

import android.content.Context
import com.echoledger.mobile.core.SourceApp
import com.echoledger.mobile.data.ApiJson
import java.io.File
import java.time.Instant
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import kotlinx.serialization.Serializable

/**
 * The capture journal (since v1.8, P0 0.2b): one entry per call the phone saw or recorded, with the stages it reached in
 * order (detected → armed → started → both channels have audio → saved), or the stage it stopped at and why. Beside it
 * a short timeline of what auto-record was set to and whether it ran, so the missed-recording detector can tell later
 * why a call it finds elsewhere (the call log, a call notification) was not recorded.
 *
 * Kept 7 days on the phone (no-backup storage) and included in "Copy diagnostics". Never names, numbers or audio: a call
 * is its app, its times, its lengths and reasons. Safe from any thread: the file is read and written on [JournalIo]'s
 * thread only, so the caller never waits for the disk.
 */
object CallJournal {
    /** The stages of a call, in order. A call by hand starts at [STARTED]. */
    enum class Stage(val wire: String, val label: String) {
        DETECTED("detected", "detected"),
        ARMED("armed", "armed"),
        STARTED("started", "started"),
        BOTH_AUDIO("both_audio", "both channels have audio"),
        SAVED("saved", "saved");

        companion object {
            fun of(wire: String?): Stage? = entries.firstOrNull { it.wire == wire }
        }
    }

    /** What became of a call. */
    enum class Result(val wire: String) {
        /** Saved and kept (waiting for Keep or Discard, or queued to upload). */
        KEPT("kept"),

        /** Saved, then skipped as "Skip calls shorter than", unanswered or silent: it can be restored for a day. */
        SKIPPED("skipped"),

        /** Thrown away on purpose: by the person, or a call shorter than 3 s. */
        DISCARDED("discarded"),

        /** The person's settings say not to record it (auto-record off for the app, phone calls off or not chosen). */
        NOT_WANTED("not_wanted"),
        FAILED("failed");

        companion object {
            fun of(wire: String?): Result? = entries.firstOrNull { it.wire == wire }
        }
    }

    /** How the phone saw a call. */
    object Seen {
        /** The capture helper reported the call (auto-record on). */
        const val HELPER = "helper"

        /** The phone's audio mode said a call was going on while the helper was missing. */
        const val AUDIO = "audio"

        /** A recording started by hand (Record now), during a call or not. */
        const val BY_HAND = "by_hand"

        /** The Phone app's own recording of a SIM call, imported. */
        const val PHONE_APP = "phone_app"
    }

    /** What the service was doing (the timeline beside the calls). */
    object State {
        const val PROCESS_START = "process_start"
        const val ARMED = "armed"
        const val DISARMED = "disarmed"
        const val HELPER_READY = "helper_ready"
        const val HELPER_LOST = "helper_lost"

        /** Auto-record could not turn on: [Mark.value] says why ("plan", "android", "background"). */
        const val REFUSED = "refused"
    }

    @Serializable
    data class StageMark(val stage: String, val atMs: Long, val note: String? = null)

    @Serializable
    data class Failure(val stage: String, val reason: String, val detail: String)

    @Serializable
    data class Call(
        val id: String,
        val app: String,
        val seenBy: String,
        val atMs: Long,
        val endedAtMs: Long? = null,
        val stages: List<StageMark> = emptyList(),
        val result: String? = null,
        /** Why it was skipped, discarded or not wanted, in a few plain words. */
        val note: String? = null,
        val failure: Failure? = null,
        /** The length of what was kept. */
        val keptMs: Long? = null,
        /**
         * A start that failed before a later start of the same call worked (the backup start): kept for diagnostics only,
         * the call's result is what that later recording became.
         */
        val firstFailure: Failure? = null,
        /**
         * When the call was answered, as the recording's answer gate saw it (the call notification's timer, the other
         * side speaking, the call log's talk time), and what showed it; null when no answer was seen (a call that only
         * rang, or the gate gave up waiting). Since v1.8 this, not "both channels have audio", proves an answer: a
         * ringback tone and your own voice fill both channels while a call still rings.
         */
        val answeredAtMs: Long? = null,
        val answeredBy: String? = null
    ) {
        val finished: Boolean get() = result != null

        /** It failed before its recording started (the helper missing or refusing), so a later start may still save it. */
        fun startFailed(): Boolean =
            Result.of(result) == Result.FAILED && failure != null && failure.reason != CRASH && !reached(Stage.STARTED)

        fun reached(stage: Stage): Boolean = stages.any { it.stage == stage.wire }

        /** The first stage not reached: where a call that stopped early stopped. */
        fun nextStage(): Stage = Stage.entries.firstOrNull { !reached(it) && (it >= firstStage()) } ?: Stage.SAVED

        private fun firstStage(): Stage = if (seenBy == Seen.BY_HAND || seenBy == Seen.PHONE_APP) Stage.STARTED else Stage.DETECTED

        fun lastActivityMs(): Long = maxOf(atMs, endedAtMs ?: 0, stages.maxOfOrNull { it.atMs } ?: 0)
    }

    /** A point of the timeline: [kind] is one of [State], or "settings" with the settings at that moment in [value]. */
    @Serializable
    data class Mark(val atMs: Long, val kind: String, val value: String = "")

    /** The person's choices that decide whether a call should be recorded, as they were at a moment. */
    @Serializable
    data class Choices(
        val autoRecord: Boolean = false,
        val appsOff: List<String> = emptyList(),
        val phoneCalls: String = "off",
        val shorterThanS: Int = 10,
        val viewer: Boolean = false
    )

    @Serializable
    data class SettingsMark(val atMs: Long, val choices: Choices)

    @Serializable
    data class Book(
        /** When this phone started keeping the journal: calls before it are never judged. */
        val since: Long = 0,
        val calls: List<Call> = emptyList(),
        val marks: List<Mark> = emptyList(),
        val settings: List<SettingsMark> = emptyList()
    ) {
        fun call(id: String): Call? = calls.firstOrNull { it.id == id }

        /** The person's choices at [atMs]: the last ones saved before it, or null before the first. */
        fun choicesAt(atMs: Long): Choices? = settings.lastOrNull { it.atMs <= atMs }?.choices

        /** Whether auto-record was on and the helper ready at [atMs], and the last refusal, from the timeline. */
        fun serviceAt(atMs: Long): Service {
            var armed = false
            var ready = false
            var refused: String? = null
            marks.filter { it.atMs <= atMs }.forEach {
                when (it.kind) {
                    State.PROCESS_START -> {
                        armed = false
                        ready = false
                    }

                    State.ARMED -> {
                        armed = true
                        refused = null
                        ready = it.value == "helper ready"
                    }

                    State.DISARMED -> armed = false

                    State.HELPER_READY -> ready = true

                    State.HELPER_LOST -> ready = false

                    State.REFUSED -> {
                        armed = false
                        refused = it.value
                    }
                }
            }
            return Service(armed, ready, refused)
        }

        /** A process started after [atMs]: whatever was open then was lost with the app. */
        fun processStartedAfter(atMs: Long): Boolean = marks.any { it.kind == State.PROCESS_START && it.atMs > atMs }
    }

    data class Service(val armed: Boolean, val helperReady: Boolean, val refused: String?)

    const val KEEP_DAYS = 7L
    private const val KEEP_MS = KEEP_DAYS * 24 * 60 * 60 * 1000
    const val MAX_CALLS = 400
    const val MAX_MARKS = 300

    // ---- pure changes (unit-tested) -----------------------------------------------------------------------------

    /** A call seen at [atMs] (a second report of the same id changes nothing). */
    fun seen(book: Book, id: String, app: String, seenBy: String, atMs: Long): Book {
        if (book.call(id) != null) return book
        val first = if (seenBy == Seen.BY_HAND || seenBy == Seen.PHONE_APP) Stage.STARTED else Stage.DETECTED
        return book.copy(calls = book.calls + Call(id, app, seenBy, atMs, stages = listOf(StageMark(first.wire, atMs))))
    }

    /**
     * [stage] reached at [atMs]: each stage once, kept in the order of [Stage] whatever order the threads report them in,
     * and nothing after the call has its result. A start that failed and then worked (the backup start records the same
     * call under the same id) opens the call again: the first failure stays as a note.
     */
    fun stage(book: Book, id: String, stage: Stage, atMs: Long, note: String? = null): Book = change(book, id) { call ->
        when {
            !call.finished -> withStage(call, stage, atMs, note)
            stage >= Stage.STARTED && call.startFailed() -> withStage(reopened(call), stage, atMs, note)
            else -> call
        }
    }

    /** A call whose start failed, open again because a later start of it worked. */
    private fun reopened(call: Call): Call = call.copy(result = null, failure = null, firstFailure = call.failure)

    private fun withStage(call: Call, stage: Stage, atMs: Long, note: String?): Call = if (call.reached(stage)) {
        call
    } else {
        call.copy(stages = (call.stages + StageMark(stage.wire, atMs, note?.take(NOTE))).sortedBy { Stage.of(it.stage)?.ordinal ?: 0 })
    }

    /** The call stopped before [Stage.SAVED]: at its first stage not reached, for [reason] (a missed-recording reason). */
    fun fail(book: Book, id: String, reason: String, detail: String, atMs: Long): Book = change(book, id) { call ->
        if (call.finished) {
            call
        } else {
            // The call goes on after a failure at its start: its end comes from [ended], not from here.
            call.copy(result = Result.FAILED.wire, failure = Failure(call.nextStage().wire, reason, detail.take(DETAIL)))
        }
    }

    /**
     * What became of the call ([Result.FAILED] goes through [fail]); a result is final, except a start's failure: a later
     * recording of the same call that was saved or thrown away (the backup start's) is what became of it.
     */
    fun finish(book: Book, id: String, result: Result, atMs: Long, note: String? = null, keptMs: Long? = null): Book =
        change(book, id) { call ->
            val open = when {
                !call.finished -> call
                result in RESCUES && call.startFailed() -> reopened(call)
                else -> return@change call
            }
            val saved = if (result == Result.KEPT || result == Result.SKIPPED) withStage(open, Stage.SAVED, atMs, null) else open
            // A recording that ended without the call's own end (one by hand) ends with it; a call not recorded goes on.
            val end = open.endedAtMs ?: atMs.takeIf { result != Result.NOT_WANTED }
            saved.copy(result = result.wire, note = note?.take(NOTE), keptMs = keptMs, endedAtMs = end)
        }

    private val RESCUES = setOf(Result.KEPT, Result.SKIPPED, Result.DISCARDED)

    /** The call itself ended at [atMs] (the recording may still be saving). */
    fun ended(book: Book, id: String, atMs: Long): Book = change(book, id) { if (it.endedAtMs == null) it.copy(endedAtMs = atMs) else it }

    /**
     * The recording's answer gate saw the call answered at [atMs], shown by [by]: the first answer counts. A fact about
     * the call, so it is kept also when it arrives with the call's end (the call log's talk time).
     */
    fun answered(book: Book, id: String, atMs: Long, by: String?): Book = change(book, id) {
        if (it.answeredAtMs == null) it.copy(answeredAtMs = atMs, answeredBy = by?.take(NOTE)) else it
    }

    fun mark(book: Book, kind: String, atMs: Long, value: String = ""): Book = book.copy(marks = book.marks + Mark(atMs, kind, value))

    /** The person's choices at [atMs], kept only when they changed. */
    fun choices(book: Book, choices: Choices, atMs: Long): Book =
        if (book.settings.lastOrNull()?.choices == choices) book else book.copy(settings = book.settings + SettingsMark(atMs, choices))

    /** A new process: every call still open was lost with the old one ("crash"), and auto-record is off until it arms. */
    fun processStarted(book: Book, atMs: Long): Book {
        val closed = book.calls.fold(book) { b, call ->
            if (call.finished) b else fail(b, call.id, CRASH, "EchoLedger stopped during the call, so the recording was lost.", atMs)
        }
        return mark(closed, State.PROCESS_START, atMs)
    }

    /**
     * Older than [KEEP_DAYS] goes. The newest mark and settings from before what is kept stay, so the state where the kept
     * part begins is still known; each list is capped so a phone with very many calls never grows the file without
     * bound. Calls dropped for room rather than for age (a heavy week: since v1.8 0.3 a SIM call can be two entries)
     * take the journal's start with them: what became of them is no longer known, so no call before the oldest one kept
     * is judged ([Book.since]).
     */
    fun rotate(book: Book, nowMs: Long): Book {
        val from = nowMs - KEEP_MS
        val sortedMarks = book.marks.sortedBy { it.atMs }
        // Room for the baseline: the newest marks of the window, one fewer than the cap.
        val marks = sortedMarks.filter { it.atMs >= from }.takeLast(MAX_MARKS - 1)
        val oldMarks = sortedMarks.take(sortedMarks.size - marks.size)
        // One mark in place of the ones that go, saying how auto-record stood where the kept ones begin.
        val baseline = oldMarks.lastOrNull()?.let { last ->
            val state = book.serviceAt(last.atMs)
            when {
                state.armed -> Mark(last.atMs, State.ARMED, if (state.helperReady) "helper ready" else "helper not running")
                state.refused != null -> Mark(last.atMs, State.REFUSED, state.refused)
                else -> Mark(last.atMs, State.DISARMED)
            }
        }
        val sortedSettings = book.settings.sortedBy { it.atMs }
        val settings = sortedSettings.filter { it.atMs >= from }.takeLast(MAX_MARKS - 1)
        val oldSettings = sortedSettings.take(sortedSettings.size - settings.size)
        val recent = book.calls.filter { it.atMs >= from }.sortedBy { it.atMs }
        val calls = recent.takeLast(MAX_CALLS)
        val since = if (calls.size < recent.size) maxOf(book.since, calls.first().atMs) else book.since
        return book.copy(
            since = since,
            calls = calls,
            marks = listOfNotNull(baseline) + marks,
            settings = listOfNotNull(oldSettings.lastOrNull()) + settings
        )
    }

    private inline fun change(book: Book, id: String, transform: (Call) -> Call): Book =
        book.copy(calls = book.calls.map { if (it.id == id) transform(it) else it })

    /** The person's choices in [settings] that decide whether a call should be recorded. */
    fun choicesOf(settings: com.echoledger.mobile.data.AppSettings): Choices = Choices(
        autoRecord = settings.autoRecordArmed,
        appsOff = settings.autoRecordApps.filterValues { !it }.keys.sorted(),
        phoneCalls = com.echoledger.mobile.core.PhoneCalls.mode(settings.phoneCalls),
        shorterThanS = settings.skipCallsShorterThanS,
        viewer = settings.plan.viewer
    )

    /** The "crash" missed-recording reason (the detector's own list holds the rest). */
    const val CRASH = "crash"
    private const val NOTE = 120
    private const val DETAIL = 300

    // ---- diagnostics ----------------------------------------------------------------------------------------------

    /**
     * The journal for "Copy diagnostics", newest call last: "11-16 09:12 WhatsApp · 5:12 · detected → armed → started 0.4 s
     * → both channels have audio 2.1 s → saved · kept 5:04", or where a call stopped and why.
     */
    fun describe(book: Book, zone: ZoneId = ZoneId.systemDefault()): List<String> {
        val clock = DateTimeFormatter.ofPattern("MM-dd HH:mm").withZone(zone)
        return book.calls.sortedBy { it.atMs }.map { call ->
            val app = SourceApp.from(call.app).label
            val length = call.endedAtMs?.let { " · ${clockText(it - call.atMs)}" }.orEmpty()
            val stages = call.stages.joinToString(" → ") { s ->
                val label = Stage.of(s.stage)?.label ?: s.stage
                val after = (s.atMs - call.atMs).takeIf { it > 0 && Stage.of(s.stage) != Stage.SAVED }?.let { " ${seconds(it)}" }.orEmpty()
                label + after + s.note?.let { " ($it)" }.orEmpty()
            }
            val first = call.firstFailure?.let { " (first start failed: ${it.reason})" }.orEmpty()
            val answer = call.answeredAtMs?.let { at ->
                " · answered " + seconds((at - call.atMs).coerceAtLeast(0)) + call.answeredBy?.let { " ($it)" }.orEmpty()
            }.orEmpty()
            val end = when (Result.of(call.result)) {
                Result.FAILED -> call.failure?.let {
                    " · stopped at ${Stage.of(it.stage)?.label ?: it.stage}: ${it.reason}, ${it.detail}"
                }.orEmpty()

                Result.NOT_WANTED -> " · not recorded" + call.note?.let { ": $it" }.orEmpty()

                null -> " · open"

                else -> " · ${call.result}" + call.note?.let { ": $it" }.orEmpty() + call.keptMs?.let { " (${clockText(it)})" }.orEmpty()
            }
            "${clock.format(Instant.ofEpochMilli(call.atMs))} $app$length · ${call.seenBy} · $stages$first$answer$end"
        }
    }

    /** The timeline for diagnostics: "11-16 09:10 armed (helper ready)". */
    fun describeMarks(book: Book, zone: ZoneId = ZoneId.systemDefault()): List<String> {
        val clock = DateTimeFormatter.ofPattern("MM-dd HH:mm:ss").withZone(zone)
        val marks = book.marks.map { it.atMs to (it.kind + it.value.takeIf { v -> v.isNotBlank() }?.let { v -> " ($v)" }.orEmpty()) }
        val settings = book.settings.map { s ->
            val c = s.choices
            s.atMs to "settings (auto-record ${if (c.autoRecord) "on" else "off"}, phone calls ${c.phoneCalls}, " +
                "skip under ${c.shorterThanS} s${if (c.appsOff.isEmpty()) "" else ", off for ${c.appsOff.joinToString()}"}" +
                "${if (c.viewer) ", viewer" else ""})"
        }
        return (marks + settings).sortedBy { it.first }.map { (at, text) -> "${clock.format(Instant.ofEpochMilli(at))} $text" }
    }

    private fun clockText(ms: Long): String {
        val s = (ms / 1000).coerceAtLeast(0)
        return if (s >= 3600) "%d:%02d:%02d".format(s / 3600, s / 60 % 60, s % 60) else "%d:%02d".format(s / 60, s % 60)
    }

    private fun seconds(ms: Long): String = if (ms < 60_000) "%.1f s".format(ms / 1000.0) else clockText(ms)

    // ---- on the phone -----------------------------------------------------------------------------------------------

    private var file: File? = null
    private var book = Book()

    /** The journal of [context]'s app (a test's new app gets its own), with every change made before this call. */
    fun book(context: Context): Book {
        val app = context.applicationContext ?: context
        return JournalIo.read {
            synchronized(this) {
                load(app)
                book
            }
        }
    }

    /** Changes the journal on its I/O thread: the caller never waits for the file. */
    fun update(context: Context, nowMs: Long = System.currentTimeMillis(), change: (Book) -> Book) {
        val app = context.applicationContext ?: context
        JournalIo.post {
            synchronized(this) {
                load(app)
                val next = rotate(change(book), nowMs)
                if (next != book) {
                    book = next
                    save()
                }
            }
        }
    }

    // Each one takes its time when it is called, not when the I/O thread gets to it.

    fun seen(context: Context, id: String, app: String, seenBy: String) {
        val at = System.currentTimeMillis()
        update(context, at) { seen(it, id, app, seenBy, at) }
    }

    fun stage(context: Context, id: String, stage: Stage, note: String? = null) {
        val at = System.currentTimeMillis()
        update(context, at) { stage(it, id, stage, at, note) }
    }

    fun fail(context: Context, id: String, reason: String, detail: String) {
        val at = System.currentTimeMillis()
        update(context, at) { fail(it, id, reason, detail, at) }
    }

    fun finish(context: Context, id: String, result: Result, note: String? = null, keptMs: Long? = null) {
        val at = System.currentTimeMillis()
        update(context, at) { finish(it, id, result, at, note, keptMs) }
    }

    fun ended(context: Context, id: String) {
        val at = System.currentTimeMillis()
        update(context, at) { ended(it, id, at) }
    }

    /** The answer gate saw the call answered at [atMs] (from the captured audio's own clock, so passed in). */
    fun answered(context: Context, id: String, atMs: Long, by: String?) {
        update(context) { answered(it, id, atMs, by) }
    }

    fun mark(context: Context, kind: String, value: String = "") {
        val at = System.currentTimeMillis()
        update(context, at) { mark(it, kind, at, value) }
    }

    fun choices(context: Context, choices: Choices) {
        val at = System.currentTimeMillis()
        update(context, at) { choices(it, choices, at) }
    }

    /** Called once per process (EchoLedgerApp): closes what the last process left open. */
    fun processStarted(context: Context) {
        val at = System.currentTimeMillis()
        update(context, at) { processStarted(it, at) }
    }

    fun clear(context: Context) {
        val app = context.applicationContext ?: context
        JournalIo.read {
            synchronized(this) {
                file = File(app.noBackupFilesDir, FILE)
                file?.delete()
                book = Book(since = System.currentTimeMillis())
                save()
            }
        }
    }

    private fun load(context: Context) {
        val target = File(context.noBackupFilesDir, FILE)
        if (file == target) return
        file = target
        val stored = runCatching { ApiJson.decodeFromString(Book.serializer(), target.readText()) }.getOrNull()
        book = stored ?: Book(since = System.currentTimeMillis())
        if (stored == null) save()
    }

    private fun save() {
        val target = file ?: return
        runCatching {
            val tmp = File(target.parentFile, target.name + ".tmp")
            tmp.writeText(ApiJson.encodeToString(Book.serializer(), book))
            if (!tmp.renameTo(target)) {
                target.delete()
                tmp.renameTo(target)
            }
        }
    }

    private const val FILE = "call-journal.json"
}
