package com.echoledger.mobile.calls

import android.content.Context
import android.provider.Settings
import com.echoledger.mobile.core.SourceApp
import com.echoledger.mobile.data.LocalRecording
import com.echoledger.mobile.notify.Notifications
import com.echoledger.mobile.overlay.DecisionPopup

/**
 * The question after a recording ends and waits for Keep / Discard. A call (any call app, or a SIM call, since v1.3)
 * gets the small floating card (needs the "display over other apps" permission); without the permission, or for
 * anything else, the heads-up notification is used. The notification is always posted too, so the choice stays in the
 * shade. Neither decides anything: only a tap does, and the 60-second rule of [DecisionTimeoutWorker] follows the
 * setting ("Keep waiting" by default).
 */
object PostCallPrompt {
    fun show(context: Context, rec: LocalRecording, afterCallTimeout: String, themeMode: String) {
        if (isCall(rec) && Settings.canDrawOverlays(context)) {
            DecisionPopup.show(context, rec, themeMode, afterCallTimeout)
        }
        Notifications.showSaveDecision(context, rec, clientName = null, decisionTimeout = afterCallTimeout)
        DecisionTimeoutWorker.schedule(context, rec.id)
    }

    fun isCall(rec: LocalRecording): Boolean = SourceApp.from(rec.sourceApp) !in setOf(SourceApp.VOICE_NOTE, SourceApp.OTHER)
}
