package com.echoledger.mobile.calls

import android.content.Context
import android.provider.Settings
import com.echoledger.mobile.core.SourceApp
import com.echoledger.mobile.data.LocalRecording
import com.echoledger.mobile.net.UploadWorker
import com.echoledger.mobile.notify.Notifications
import com.echoledger.mobile.overlay.DecisionPopup

/**
 * What happens when a call recording ends (since v1.5, "After a call" in Calls & meetings):
 * - "keep" (default): the call is kept; the small "Saved · … · Undo" chip shows for 6 s and the upload waits until it
 *   is gone, so Undo deletes everything. Without the overlay permission a quiet notification with Undo does the same.
 * - "ask": the chip with ✓ and ✕ (or the Keep / Discard notification); nobody choosing keeps the call after 10 s.
 * Skipped calls (unanswered, short, silent) never reach this.
 */
object PostCallPrompt {
    const val KEEP = "keep"
    const val ASK = "ask"

    /** The state a finished call recording is stored with: kept at once, or waiting for the chip. */
    fun stateFor(afterCall: String): String = if (afterCall == ASK) "pending_decision" else "queued"

    fun show(context: Context, rec: LocalRecording, afterCall: String, themeMode: String) {
        val overlay = isCall(rec) && Settings.canDrawOverlays(context)
        if (rec.state == "pending_decision") {
            if (overlay) DecisionPopup.show(context, rec, themeMode, DecisionPopup.Mode.ASK)
            Notifications.showSaveDecision(context, rec, clientName = null, decisionTimeout = KEEP)
            DecisionTimeoutWorker.schedule(context, rec.id)
        } else {
            UploadWorker.enqueueAfter(context, rec.id, DecisionPopup.SAVED_MS / 1000)
            val shown = overlay && DecisionPopup.show(context, rec, themeMode, DecisionPopup.Mode.SAVED)
            if (!shown) Notifications.savedWithUndo(context, rec, DecisionPopup.title(rec))
        }
    }

    fun isCall(rec: LocalRecording): Boolean = SourceApp.from(rec.sourceApp) !in setOf(SourceApp.VOICE_NOTE, SourceApp.OTHER)
}
