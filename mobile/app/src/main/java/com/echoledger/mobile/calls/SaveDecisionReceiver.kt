package com.echoledger.mobile.calls

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.os.Handler
import android.os.Looper
import com.echoledger.mobile.EchoLedgerApp
import com.echoledger.mobile.net.DiscardWorker
import com.echoledger.mobile.net.UploadWorker
import com.echoledger.mobile.notify.Notifications
import com.echoledger.mobile.overlay.DecisionPopup
import com.echoledger.mobile.widget.WidgetStateStore
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch

/** Handles the post-call Keep / Discard notification actions. */
class SaveDecisionReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        val id = intent.getStringExtra(EXTRA_ID) ?: return
        val pending = goAsync()
        CoroutineScope(Dispatchers.IO).launch {
            try {
                if (intent.action == ACTION_UNDO) {
                    Decisions.undoKeep(context, id)
                } else {
                    Decisions.apply(context, id, keep = intent.action == ACTION_KEEP)
                }
            } finally {
                pending.finish()
            }
        }
    }

    companion object {
        const val ACTION_KEEP = "com.echoledger.mobile.KEEP"
        const val ACTION_DISCARD = "com.echoledger.mobile.DISCARD"

        /** Since v1.5: Undo on "Saved · …" (a call kept automatically). */
        const val ACTION_UNDO = "com.echoledger.mobile.UNDO_KEEP"
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
     * Undo on "Saved · …" (since v1.5): the call kept automatically is deleted after all. Its upload waits until the
     * chip is gone, so normally nothing has left the phone; an upload that already started is called off, and a server
     * session it (or the captions) opened is deleted.
     */
    suspend fun undoKeep(context: Context, recordingId: String) {
        val graph = (context.applicationContext as EchoLedgerApp).graph
        UploadWorker.cancel(context, recordingId)
        Notifications.cancelDecision(context, recordingId)
        if (DecisionPopup.showingFor == recordingId) Handler(Looper.getMainLooper()).post { DecisionPopup.hide() }
        graph.recordings.get(recordingId)?.serverSessionId?.let { DiscardWorker.enqueue(context, recordingId, it) }
        graph.recordings.delete(recordingId)
        WidgetStateStore.refresh(context)
    }

    /** Restore of a skipped call (since v1.5): it is kept like any other call and uploads now. */
    suspend fun restoreSkipped(context: Context, recordingId: String) {
        val graph = (context.applicationContext as EchoLedgerApp).graph
        graph.recordings.update(recordingId) { it.copy(state = "queued", skippedReason = null, skippedAt = null) } ?: return
        UploadWorker.enqueue(context, recordingId)
        WidgetStateStore.refresh(context)
    }
}
