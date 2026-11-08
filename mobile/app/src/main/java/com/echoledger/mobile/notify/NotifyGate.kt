package com.echoledger.mobile.notify

import com.echoledger.mobile.EchoLedgerApp
import com.echoledger.mobile.core.NotifyCategory
import java.time.LocalTime

/** Whether a notification of a category may be shown now: its switch in Settings → Notifications and the quiet hours. */
object NotifyGate {
    suspend fun allowed(app: EchoLedgerApp, category: NotifyCategory, now: LocalTime = LocalTime.now()): Boolean {
        val s = app.graph.settings.current()
        // Before v1.5 one switch held back everything from the server; it still does for those kinds.
        if (!s.activityNotifications && !category.critical && category != NotifyCategory.RECORDING) return false
        return NotifyCategory.allowed(category, s.notifyOff, s.quietHours, s.quietFrom, s.quietTo, now.hour * 60 + now.minute)
    }
}
