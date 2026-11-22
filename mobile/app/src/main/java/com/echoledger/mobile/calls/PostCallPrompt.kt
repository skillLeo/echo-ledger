package com.echoledger.mobile.calls

import android.content.Context
import android.provider.Settings
import com.echoledger.mobile.core.SourceApp
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.data.LocalRecording
import com.echoledger.mobile.net.UploadWorker
import com.echoledger.mobile.notify.Notifications
import com.echoledger.mobile.overlay.DecisionPopup

/**
 * What happens when a call recording ends (since 2.0.5, Settings › Recording › After each call; [CallChoice]):
 * - Ask me (the default): the after-call card ([DecisionPopup]); without the overlay permission a notification asks
 *   with Transcribe, Save audio only and Delete. Nobody choosing keeps the audio only.
 * - Transcribe every call, Save audio only, a choice made on the pill during the call, "At the start of the call", and
 *   quiet hours or Do Not Disturb: no question; the call is kept as [CallChoice.outcome] says and sent now.
 * Skipped calls (unanswered, short, silent) never reach this.
 */
object PostCallPrompt {
    /** The values of "After a call" until 2.0.5, read once to give After each call its first value. */
    const val KEEP = "keep"
    const val ASK = "ask"

    /** What a call that just ended becomes ([duringCall]: chosen on the pill). Quiet hours count only for Ask me. */
    fun outcome(context: Context, settings: AppSettings, duringCall: CallChoice.Pick? = null): CallChoice.Outcome = CallChoice.outcome(
        settings,
        duringCall,
        quiet =
            CallChoice.setting(settings) == CallChoice.ASK && CallChoice.quietNow(context, settings)
    )

    /** [rec] as [outcome] stores it: waiting for the card, or kept with or without its transcript. */
    fun stored(rec: LocalRecording, outcome: CallChoice.Outcome): LocalRecording {
        val (state, transcribe) = CallChoice.stored(outcome)
        return rec.copy(state = state, transcribe = transcribe ?: rec.transcribe)
    }

    /** Asks about a waiting call, or sends a kept one. [themeMode]: the card's light or dark look. */
    fun show(context: Context, rec: LocalRecording, themeMode: String) {
        if (rec.state != "pending_decision") {
            UploadWorker.enqueue(context, rec.id)
            return
        }
        val shown = isCall(rec) && Settings.canDrawOverlays(context) && DecisionPopup.ask(context, rec, themeMode)
        // One question at a time: the notification only when the card cannot be drawn.
        if (!shown) Notifications.showSaveDecision(context, rec, clientName = null)
        DecisionTimeoutWorker.schedule(context, rec.id)
    }

    /** A call back from "Deleted · Undo" in a notification: asked again if it waited, sent if it was kept. */
    fun again(context: Context, rec: LocalRecording, themeMode: String) = show(context, rec, themeMode)

    /**
     * The recording follows After each call with nothing on the screen (since v1.8 0.3: a call or a recording is live,
     * or the call ended a while ago): one waiting for the question waits in Record, a kept one uploads now.
     */
    fun quietly(context: Context, rec: LocalRecording) {
        if (rec.state == "pending_decision") DecisionTimeoutWorker.schedule(context, rec.id) else UploadWorker.enqueue(context, rec.id)
    }

    fun isCall(rec: LocalRecording): Boolean = SourceApp.from(rec.sourceApp) !in setOf(SourceApp.VOICE_NOTE, SourceApp.OTHER)
}
