package com.echoledger.mobile.calls

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.os.Handler
import android.os.Looper
import com.echoledger.mobile.EchoLedgerApp
import com.echoledger.mobile.data.LocalRecording
import com.echoledger.mobile.net.DiscardWorker
import com.echoledger.mobile.net.UploadWorker
import com.echoledger.mobile.notify.Notifications
import com.echoledger.mobile.overlay.DecisionPopup
import com.echoledger.mobile.widget.WidgetStateStore
import java.time.Instant
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

/**
 * Handles the post-call Keep / Discard notification actions. Since v1.8.1 (bug P-006) Discard deletes nothing at once:
 * the notification turns into "Discarded · … · Undo" for 5 s, and the file goes only after that ([Decisions.discardLater]).
 */
class SaveDecisionReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        val id = intent.getStringExtra(EXTRA_ID) ?: return
        val pending = goAsync()
        CoroutineScope(Dispatchers.IO).launch {
            try {
                when (intent.action) {
                    ACTION_KEEP -> Decisions.apply(context, id, keep = true)

                    // Undo on "Saved · …" is a Discard too: "Discarded · Undo" first, the file only after that.
                    ACTION_DISCARD, ACTION_UNDO -> discard(context, id)

                    ACTION_UNDO_DISCARD -> undoDiscard(context, id)
                }
            } finally {
                pending.finish()
            }
        }
    }

    private suspend fun discard(context: Context, id: String) {
        // The chip for the same call goes too, so its ten seconds never keep a call on its way out.
        withContext(Dispatchers.Main) { if (DecisionPopup.showingFor == id) DecisionPopup.hide() }
        val discarding = Decisions.discardLater(context, id) ?: return
        Notifications.discardedWithUndo(context, discarding, DecisionPopup.title(discarding))
    }

    /**
     * Undo on "Discarded · …": the call comes back as after it ended: the question (kept after 10 s) for a call that
     * waited, "Saved" (its upload waits for it) for a call that was kept.
     */
    private suspend fun undoDiscard(context: Context, id: String) {
        Notifications.cancelDecision(context, id)
        val back = Decisions.undoDiscard(context, id) ?: return
        val theme = (context.applicationContext as EchoLedgerApp).graph.settings.current().themeMode
        withContext(Dispatchers.Main) { PostCallPrompt.again(context, back, theme) }
    }

    companion object {
        const val ACTION_KEEP = "com.echoledger.mobile.KEEP"
        const val ACTION_DISCARD = "com.echoledger.mobile.DISCARD"

        /** Since v1.5: Undo on "Saved · …" (a call kept automatically). */
        const val ACTION_UNDO = "com.echoledger.mobile.UNDO_KEEP"

        /** Since v1.8.1: Undo on "Discarded · …" (Discard in the notification). */
        const val ACTION_UNDO_DISCARD = "com.echoledger.mobile.UNDO_DISCARD"
        const val EXTRA_ID = "recording_id"
    }
}

/** Keep → queue for upload; Discard → securely delete the local encrypted file. Shared by notification + in-app list. */
object Decisions {
    suspend fun apply(context: Context, recordingId: String, keep: Boolean) {
        val graph = (context.applicationContext as EchoLedgerApp).graph
        Notifications.cancelDecision(context, recordingId)
        DecisionTimeoutWorker.cancel(context, recordingId)
        // A decision made anywhere (card, notification, Record tab) closes the floating card for that recording.
        if (DecisionPopup.showingFor == recordingId) Handler(Looper.getMainLooper()).post { DecisionPopup.hide() }
        // A call on its way out is kept only through its Undo, never by a late "keep after 10 s".
        if (keep && graph.recordings.get(recordingId)?.state == DISCARDING) return
        if (keep) {
            graph.recordings.update(recordingId) { it.copy(state = "queued") }
            UploadWorker.enqueue(context, recordingId)
        } else {
            // Nothing is uploaded; a server session that captions opened is deleted, so no transcript is ever made.
            graph.recordings.get(recordingId)?.serverSessionId?.let { DiscardWorker.enqueue(context, recordingId, it) }
            graph.recordings.delete(recordingId)
        }
        WidgetStateStore.refresh(context)
    }

    /**
     * Discard after a call (since v1.8.1, bugs P-003 and P-006): the chip's or the notification's Discard, and Undo on
     * "Saved · …" (a call kept automatically). The call leaves every list now, stops counting down to Keep and its
     * upload is called off, but its file stays until "Discarded · Undo" is over and a little longer
     * ([DISCARD_AFTER_MS]), so Undo still finds it. [DiscardLaterWorker] deletes it then, also after the app was closed
     * or killed. Null when the call is gone, was sent already or is on its way out (nothing is discarded).
     */
    suspend fun discardLater(context: Context, recordingId: String, nowMs: Long = System.currentTimeMillis()): LocalRecording? {
        val graph = (context.applicationContext as EchoLedgerApp).graph
        Notifications.cancelDecision(context, recordingId)
        DecisionTimeoutWorker.cancel(context, recordingId)
        UploadWorker.cancel(context, recordingId)
        val at = Instant.ofEpochMilli(nowMs + DISCARD_AFTER_MS).toString()
        val discarding = graph.recordings.update(recordingId) {
            if (it.state in DISCARDABLE) {
                it.copy(state = DISCARDING, discardAt = at, discardedFrom = if (it.state == WAITING) WAITING else KEPT)
            } else {
                it
            }
        }?.takeIf { it.state == DISCARDING } ?: return null
        DiscardLaterWorker.schedule(context, recordingId, DISCARD_AFTER_MS)
        WidgetStateStore.refresh(context)
        return discarding
    }

    /**
     * Undo on "Discarded · …" (since v1.8.1): the call is back as before the tap, waiting for Keep or Discard, or kept
     * ("queued"; the caller shows "Saved" again, whose time the upload waits for). Null when its time was over and it
     * is gone.
     */
    suspend fun undoDiscard(context: Context, recordingId: String): LocalRecording? {
        val graph = (context.applicationContext as EchoLedgerApp).graph
        DiscardLaterWorker.cancel(context, recordingId)
        val back = graph.recordings.update(recordingId) {
            if (it.state == DISCARDING) it.copy(state = it.discardedFrom ?: WAITING, discardAt = null, discardedFrom = null) else it
        }?.takeIf { it.state == WAITING || it.state == KEPT } ?: return null
        WidgetStateStore.refresh(context)
        return back
    }

    /**
     * The end of a Discard: the call is deleted, unless Undo brought it back meanwhile. The check and the delete are
     * one step of the store ([com.echoledger.mobile.data.RecordingStore.deleteIf]), so an Undo can never lose to it
     * halfway.
     */
    suspend fun finishDiscard(context: Context, recordingId: String) {
        val graph = (context.applicationContext as EchoLedgerApp).graph
        val gone = graph.recordings.deleteIf(recordingId) { it.state == DISCARDING } ?: return
        Notifications.cancelDecision(context, recordingId)
        // Nothing was uploaded; a server session that captions opened is deleted, so no transcript is ever made.
        gone.serverSessionId?.let { DiscardWorker.enqueue(context, recordingId, it) }
        WidgetStateStore.refresh(context)
    }

    /** The calls discarded from the chip whose Undo time is over at [now] (the hourly check finishes any left). */
    fun discardsDue(recordings: List<LocalRecording>, now: Instant): List<String> = recordings
        .filter { it.state == DISCARDING }
        .filter { (runCatching { Instant.parse(it.discardAt) }.getOrNull() ?: Instant.EPOCH) <= now }
        .map { it.id }

    /** Restore of a skipped call (since v1.5): it is kept like any other call and uploads now. */
    suspend fun restoreSkipped(context: Context, recordingId: String) {
        val graph = (context.applicationContext as EchoLedgerApp).graph
        graph.recordings.update(recordingId) { it.copy(state = "queued", skippedReason = null, skippedAt = null) } ?: return
        UploadWorker.enqueue(context, recordingId)
        WidgetStateStore.refresh(context)
    }

    private const val WAITING = "pending_decision"
    private const val KEPT = "queued"

    /** What Discard can still take back: waiting, or kept and not sent yet (an upload that failed counts). */
    private val DISCARDABLE = setOf(WAITING, KEPT, "uploading", "failed")

    /** A call discarded from the after-call chip, its file kept for Undo until [LocalRecording.discardAt]. */
    const val DISCARDING = "discarding"

    /** How long a call discarded from the chip keeps its file: "Discarded · Undo" and 2 s more, so a late Undo finds it. */
    const val DISCARD_AFTER_MS = DecisionPopup.DISCARDED_MS + 2_000L
}
