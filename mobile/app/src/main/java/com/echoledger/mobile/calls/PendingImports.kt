package com.echoledger.mobile.calls

import android.content.Context

/**
 * SIM calls whose recording may still come from the Phone app's own call recording, brought in a few minutes after the
 * call (since v1.8): the missed-recording detector leaves them alone until the import is done or has given up. Only the
 * call's times are kept, never names or numbers. A wait for the import's looks ends by itself after [MAX_WAIT_MS] in
 * case its import never reports back; a wait for the person (a recording that waits for "Import this recording") lasts
 * until they import it or say it is not this call's.
 */
object PendingImports {
    /** The import looks for a while after the call (about 8 minutes of tries); a wait for it never outlasts this. */
    const val MAX_WAIT_MS = 15 * 60_000L
    private const val MAX = 20

    /** One call's wait: its own times, until when the import may take ([person]: until the person answers). */
    data class Wait(val startMs: Long, val endMs: Long, val untilMs: Long, val person: Boolean = false)

    // ---- pure (unit-tested) -----------------------------------------------------------------------------------------

    fun parse(text: String?): List<Wait> = text.orEmpty().split(';').mapNotNull { part ->
        val n = part.split(':').mapNotNull { it.toLongOrNull() }
        when (n.size) {
            3 -> Wait(n[0], n[1], n[2])
            4 -> Wait(n[0], n[1], n[2], person = n[3] == 1L)
            else -> null
        }
    }

    /** The newest waits for the import's looks, and every wait for the person (at most one per recording offered). */
    fun format(waits: List<Wait>): String {
        val (person, looks) = waits.partition { it.person }
        return (person + looks.takeLast(MAX)).joinToString(";") {
            "${it.startMs}:${it.endMs}:${it.untilMs}" + if (it.person) ":1" else ""
        }
    }

    /** The calls still waiting at [nowMs], as their time spans. */
    fun open(waits: List<Wait>, nowMs: Long): List<LongRange> =
        waits.filter { it.person || it.untilMs > nowMs }.map { it.startMs..it.endMs }

    // ---- on the phone -----------------------------------------------------------------------------------------------

    private fun prefs(context: Context) = context.getSharedPreferences("pending_imports", Context.MODE_PRIVATE)

    private fun read(context: Context): List<Wait> = parse(prefs(context).getString(KEY, null))

    private fun write(context: Context, waits: List<Wait>) = prefs(context).edit().putString(KEY, format(waits)).apply()

    /** The Phone app's recording of the call from [startMs] to [endMs] is being looked for. */
    @Synchronized
    fun add(context: Context, startMs: Long, endMs: Long, nowMs: Long = System.currentTimeMillis()) {
        val kept = read(context).filter { it.person || (it.untilMs > nowMs && it.startMs != startMs) }
        write(context, kept + Wait(startMs, endMs, nowMs + MAX_WAIT_MS))
    }

    /**
     * The Phone app's recording of the call from [startMs] to [endMs] waits for the person: the call waits as long, with
     * no time limit, in place of the import's own wait for it.
     */
    @Synchronized
    fun waitForPerson(context: Context, startMs: Long, endMs: Long) {
        val kept = read(context).filter {
            if (it.person) it.startMs != startMs else it.endMs < startMs || it.startMs > endMs
        }
        write(context, kept + Wait(startMs, endMs, Long.MAX_VALUE, person = true))
    }

    /** The call that started at [startMs] waits no more: its import is done or gave up, or the person answered. */
    @Synchronized
    fun done(context: Context, startMs: Long) {
        val all = read(context)
        val kept = all.filter { it.startMs != startMs }
        if (kept.size != all.size) write(context, kept)
    }

    /**
     * The import settled the calls overlapping [startMs]..[endMs] (the call's own times): brought in, kept EchoLedger's
     * own, or not wanted.
     */
    @Synchronized
    fun doneOverlapping(context: Context, startMs: Long, endMs: Long) {
        val all = read(context)
        val kept = all.filter { it.endMs < startMs || it.startMs > endMs }
        if (kept.size != all.size) write(context, kept)
    }

    /**
     * The import's looks that began at [startedMs] are over: the calls that were waiting for them stop waiting. A call
     * whose recording waits for the person still waits, and so does a call that ended after those looks began (its own
     * look runs after them).
     */
    @Synchronized
    fun endLooks(context: Context, startedMs: Long = Long.MAX_VALUE) {
        val all = read(context)
        val kept = all.filter { it.person || it.untilMs - MAX_WAIT_MS > startedMs }
        if (kept.size != all.size) write(context, kept)
    }

    /** The calls whose import is still going on, or whose recording waits for the person. */
    @Synchronized
    fun waiting(context: Context, nowMs: Long = System.currentTimeMillis()): List<LongRange> = open(read(context), nowMs)

    private const val KEY = "waits"
}
