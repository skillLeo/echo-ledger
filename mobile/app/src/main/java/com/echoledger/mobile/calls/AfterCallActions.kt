package com.echoledger.mobile.calls

import android.content.Context
import com.echoledger.mobile.EchoLedgerApp
import com.echoledger.mobile.calls.CallChoice.Pick
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.data.LocalRecording
import com.echoledger.mobile.notify.AfterCallNotice
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch

/**
 * What the after-call notification and its optional small bar do (v2.1 UX-13, [CallChoice.NOTIFY_FLAG]). The call waits
 * while the notification is in the shade; every answer is applied here, on the phone, before anything is sent:
 * - [ask]: the notification (or nothing, with the call kept as audio only, when notifications can't show), the small
 *   bar beside it when the person turned it on, and the backstop that keeps the audio only once it leaves the shade;
 * - [transcribe]: kept with its transcript and sent at once (expedited work), "Transcribing…" in its place;
 * - [swiped]: a swipe keeps the audio only, and nothing else shows;
 * - [always]: "Always transcribe" or "Always audio only" sets After each call, and this call gets it too.
 * A choice made here counts toward the "Always" offer ([counted]); nobody choosing does not.
 */
object AfterCallActions {
    private const val WAITING = "pending_decision"
    private const val KEPT = "queued"

    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO.limitedParallelism(1))

    private fun graph(context: Context) = (context.applicationContext as EchoLedgerApp).graph

    /** Asks about [rec], which waits, after its call (main thread). */
    fun ask(context: Context, rec: LocalRecording, settings: AppSettings) {
        val app = context.applicationContext
        if (!AfterCallNotice.canShow(app)) {
            // Notifications off (or this channel): nothing shows, and the call is kept as audio only.
            scope.launch { Decisions.apply(app, rec.id, keep = true, transcribe = false) }
            return
        }
        // The small bar (Settings › After each call, off by default): then the notification goes in the shade quietly.
        val bar = settings.afterCallBar && com.echoledger.mobile.overlay.AfterCallBar.show(app, rec, settings.themeMode)
        AfterCallNotice.scheduleKeep(app, rec.id)
        scope.launch {
            AfterCallNotice.ask(app, rec, settings, quiet = bar)?.let { change -> graph(app).settings.update(change) }
        }
    }

    /** Transcribe (one call) or Transcribe all (back-to-back calls): each still undecided is kept and sent at once. */
    suspend fun transcribe(context: Context, ids: List<String>) {
        val recs = ids.mapNotNull { graph(context).recordings.get(it) }.filter { it.state == WAITING || it.state == KEPT }
        if (recs.isEmpty()) return
        recs.forEach { Decisions.apply(context, it.id, keep = true, transcribe = true) }
        if (ids.size > 1) AfterCallNotice.transcribingAll(context, recs) else AfterCallNotice.transcribing(context, recs.single())
        counted(context, Pick.TRANSCRIBE)
    }

    /** A swipe: the calls still waiting are kept as audio only. */
    suspend fun swiped(context: Context, ids: List<String>) {
        val waiting = ids.mapNotNull { graph(context).recordings.get(it) }.filter { it.state == WAITING }
        if (waiting.isEmpty()) return
        waiting.forEach { Decisions.apply(context, it.id, keep = true, transcribe = false) }
        counted(context, Pick.AUDIO)
    }

    /** "Always transcribe" or "Always audio only": After each call becomes that choice, and this call gets it too. */
    suspend fun always(context: Context, id: String, pick: Pick) {
        if (pick == Pick.DELETE) return
        graph(context).settings.update { it.copy(afterEachCall = CallChoice.settingFor(pick)) }
        if (pick == Pick.TRANSCRIBE) {
            transcribe(context, listOf(id))
        } else if (graph(context).recordings.get(id)?.state == WAITING) {
            Decisions.apply(context, id, keep = true, transcribe = false)
        }
    }

    /** A choice made in the notification: the run of identical choices grows or starts again. */
    suspend fun counted(context: Context, pick: Pick) {
        graph(context).settings.update { CallChoice.afterPick(it, pick) }
    }

    /** Waits until every step asked for so far has run (tests). */
    @androidx.annotation.VisibleForTesting
    internal fun drainForTest() {
        val done = java.util.concurrent.CountDownLatch(1)
        scope.launch { done.countDown() }
        done.await(15, java.util.concurrent.TimeUnit.SECONDS)
    }
}
