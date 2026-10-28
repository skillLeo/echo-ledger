package com.echoledger.mobile.notify

import android.app.Notification
import android.content.ComponentName
import android.content.Context
import android.provider.Settings
import android.service.notification.NotificationListenerService
import android.service.notification.StatusBarNotification
import com.echoledger.mobile.core.CallerNames

/**
 * Caller names (since v1.2): reads only WhatsApp and WhatsApp Business call notifications to learn who a call is with.
 * Every other notification is ignored. Works only after the user allows notification access in Android's settings.
 */
class CallerNameListener : NotificationListenerService() {
    override fun onListenerConnected() {
        runCatching { activeNotifications?.forEach(::read) }
    }

    override fun onNotificationPosted(sbn: StatusBarNotification) = read(sbn)

    private fun read(sbn: StatusBarNotification) {
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

    companion object {
        /** Whether the user allowed this app to read notifications. */
        fun allowed(context: Context): Boolean {
            val enabled = Settings.Secure.getString(context.contentResolver, "enabled_notification_listeners").orEmpty()
            val me = ComponentName(context, CallerNameListener::class.java)
            return enabled.split(':').any { ComponentName.unflattenFromString(it) == me }
        }
    }
}
