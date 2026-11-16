package com.echoledger.mobile.calls

import android.content.Context

/**
 * SIM calls whose recording may still come from the Phone app's own call recording, brought in a few minutes after the
 * call (since v1.8): the missed-recording detector leaves them alone until the import is done or has given up. Only the
 * call's times are kept, never names or numbers; a wait ends by itself after [MAX_WAIT_MS] in case its import never
 * reports back.
 */
object PendingImports {
    /** The import looks for a while after the call (about 8 minutes of tries); a wait never outlasts this. */
    const val MAX_WAIT_MS = 15 * 60_000L
    private const val MAX = 20

    data class Wait(val startMs: Long, val endMs: Long, val untilMs: Long)

    // ---- pure (unit-tested) -----------------------------------------------------------------------------------------

    fun parse(text: String?): List<Wait> = text.orEmpty().split(';').mapNotNull { part ->
        val n = part.split(':').mapNotNull { it.toLongOrNull() }
        if (n.size == 3) Wait(n[0], n[1], n[2]) else null
    }

    fun format(waits: List<Wait>): String = waits.takeLast(MAX).joinToString(";") { "${it.startMs}:${it.endMs}:${it.untilMs}" }

    /** The calls still waiting at [nowMs], as their time spans. */
    fun open(waits: List<Wait>, nowMs: Long): List<LongRange> = waits.filter { it.untilMs > nowMs }.map { it.startMs..it.endMs }

    // ---- on the phone -----------------------------------------------------------------------------------------------

    private fun prefs(context: Context) = context.getSharedPreferences("pending_imports", Context.MODE_PRIVATE)

    /** The Phone app's recording of the call from [startMs] to [endMs] is being looked for. */
    @Synchronized
    fun add(context: Context, startMs: Long, endMs: Long, nowMs: Long = System.currentTimeMillis()) {
        val kept = parse(prefs(context).getString(KEY, null)).filter { it.untilMs > nowMs && it.startMs != startMs }
        prefs(context).edit().putString(KEY, format(kept + Wait(startMs, endMs, nowMs + MAX_WAIT_MS))).apply()
    }

    /** The import of the call that started at [startMs] is done: imported, or it gave up. */
    @Synchronized
    fun done(context: Context, startMs: Long) {
        val kept = parse(prefs(context).getString(KEY, null)).filter { it.startMs != startMs }
        prefs(context).edit().putString(KEY, format(kept)).apply()
    }

    /** The import settled the calls overlapping [startMs]..[endMs]: brought in, kept EchoLedger's own, or not wanted. */
    @Synchronized
    fun doneOverlapping(context: Context, startMs: Long, endMs: Long) {
        val all = parse(prefs(context).getString(KEY, null))
        val kept = all.filter { it.endMs < startMs || it.startMs > endMs }
        if (kept.size != all.size) prefs(context).edit().putString(KEY, format(kept)).apply()
    }

    /** The looks after the calls are over: nothing waits any more. */
    @Synchronized
    fun clear(context: Context) = prefs(context).edit().remove(KEY).apply()

    /** The calls whose import is still going on. */
    @Synchronized
    fun waiting(context: Context, nowMs: Long = System.currentTimeMillis()): List<LongRange> =
        open(parse(prefs(context).getString(KEY, null)), nowMs)

    private const val KEY = "waits"
}
