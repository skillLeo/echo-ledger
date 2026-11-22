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
 *   is gone, so nothing has left the phone; Undo discards it with its own "Discarded · Undo" (since v1.8.1). Without the overlay permission a quiet notification with Undo does the same.
 * - "ask": the chip with ✓ and ✕ (or the Keep / Discard notification); nobody choosing keeps the call after 10 s.
 * Skipped calls (unanswered, short, silent) never reach this.
 */
object PostCallPrompt {
    const val KEEP = "keep"
    const val ASK = "ask"

    /** The state a finished call recording is stored with: kept at once, or waiting for the chip. */
    fun stateFor(afterCall: String): String = if (afterCall == ASK) "pending_decision" else "queued"

    /** [uiFlags]: the person's interface flags; `ui_v2.after_call` draws the v2 card (since 2.0.0-a). */
    fun show(
        context: Context,
        rec: LocalRecording,
        afterCall: String,
        themeMode: String,
        uiFlags: com.echoledger.mobile.ui.v2.shell.UiFlags = com.echoledger.mobile.ui.v2.shell.UiFlags.Off
    ) {
        val overlay = isCall(rec) && Settings.canDrawOverlays(context)
        val v2 = uiFlags.isOn(com.echoledger.mobile.ui.v2.shell.UiFlags.AFTER_CALL)
        if (rec.state == "pending_decision") {
            if (overlay) DecisionPopup.show(context, rec, themeMode, DecisionPopup.Mode.ASK, v2)
            Notifications.showSaveDecision(context, rec, clientName = null, decisionTimeout = KEEP)
            DecisionTimeoutWorker.schedule(context, rec.id)
        } else {
            UploadWorker.enqueueAfter(context, rec.id, DecisionPopup.SAVED_MS / 1000)
            val shown = overlay && DecisionPopup.show(context, rec, themeMode, DecisionPopup.Mode.SAVED, v2)
            if (!shown) Notifications.savedWithUndo(context, rec)
        }
    }

    /** A call back from "Discarded · Undo" (since v1.8.1): asked again if it waited, "Saved" again if it was kept. */
    fun again(
        context: Context,
        rec: LocalRecording,
        themeMode: String,
        uiFlags: com.echoledger.mobile.ui.v2.shell.UiFlags = com.echoledger.mobile.ui.v2.shell.UiFlags.Off
    ) = show(context, rec, if (rec.state == "pending_decision") ASK else KEEP, themeMode, uiFlags)

    /**
     * The recording follows "After a call" with nothing on the screen (since v1.8 0.3: a call or a recording is live,
     * or the call ended a while ago): one waiting for Keep or Discard waits in Record, a kept one uploads now (there is
     * no Undo chip to wait for).
     */
    fun quietly(context: Context, rec: LocalRecording) {
        if (rec.state == "pending_decision") DecisionTimeoutWorker.schedule(context, rec.id) else UploadWorker.enqueue(context, rec.id)
    }

    fun isCall(rec: LocalRecording): Boolean = SourceApp.from(rec.sourceApp) !in setOf(SourceApp.VOICE_NOTE, SourceApp.OTHER)
}
