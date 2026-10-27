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
                Decisions.apply(context, id, keep = intent.action == ACTION_KEEP)
            } finally {
                pending.finish()
            }
        }
    }

    companion object {
        const val ACTION_KEEP = "com.echoledger.mobile.KEEP"
        const val ACTION_DISCARD = "com.echoledger.mobile.DISCARD"
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
}
