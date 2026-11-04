package com.echoledger.mobile.notify

import android.app.Notification
import android.content.ComponentName
import android.content.Context
import android.provider.Settings
import android.service.notification.NotificationListenerService
import android.service.notification.StatusBarNotification
import com.echoledger.mobile.core.CallNotices
import com.echoledger.mobile.core.CallTargets
import com.echoledger.mobile.core.CallerNames

/**
 * Call notifications only. Caller names (since v1.2): WhatsApp and WhatsApp Business call notifications say who a call
 * is with. Answered or ringing (since v1.5): the call notifications of the call apps and the phone's dialer say whether
 * a call is ringing or was answered, and when ([CallNotices]), so a recording starts at the answer. Every other
 * notification is ignored. Works only after the user allows notification access in Android's settings.
 */
class CallerNameListener : NotificationListenerService() {
    override fun onListenerConnected() {
        runCatching { activeNotifications?.forEach(::read) }
    }

    override fun onNotificationPosted(sbn: StatusBarNotification) = read(sbn)

    private fun read(sbn: StatusBarNotification) {
        readCallState(sbn)
        if (sbn.packageName !in CallerNames.PACKAGES) return
        val extras = sbn.notification.extras
        val name = CallerNames.callerOf(
            sbn.packageName,
            sbn.notification.category,
            extras.getCharSequence(Notification.EXTRA_TITLE)?.toString(),
            extras.getCharSequence(Notification.EXTRA_TEXT)?.toString()
        ) ?: return
        CallerNames.record(sbn.packageName, name, System.currentTimeMillis())
    }

    /** Ringing, incoming or answered, from a call app's or the dialer's call notification. */
    private fun readCallState(sbn: StatusBarNotification) {
        val pkg = sbn.packageName
        if (pkg !in CallTargets.KNOWN.keys && pkg !in CallNotices.DIALERS) return
        val n = sbn.notification
        val extras = n.extras
        CallNotices.classify(
            pkg = pkg,
            isCallCategory = n.category == Notification.CATEGORY_CALL,
            callType = extras.getInt("android.callType", 0),
            chronometer = extras.getBoolean(Notification.EXTRA_SHOW_CHRONOMETER, false),
            whenMs = n.`when`,
            text = extras.getCharSequence(Notification.EXTRA_TEXT)?.toString(),
            nowMs = System.currentTimeMillis()
        )?.let(CallNotices::record)
    }

    companion object {
        /** Whether the user allowed this app to read notifications. */
        fun allowed(context: Context): Boolean {
            val enabled = Settings.Secure.getString(context.contentResolver, "enabled_notification_listeners").orEmpty()
            val me = ComponentName(context, CallerNameListener::class.java)
            return enabled.split(':').any { ComponentName.unflattenFromString(it) == me }
        }
    }
}
