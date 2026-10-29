package com.echoledger.mobile.recording

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import com.echoledger.mobile.EchoLedgerApp
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch

/**
 * After a restart or an app update, auto-record comes back on by itself when it was on: the service reconnects the
 * capture helper (Shizuku) or shows "Call capture isn't ready — tap to fix" until it can. One of several ways back
 * ([AutoRecordKeeper]): Android does not always deliver the update broadcast.
 */
class RearmReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action != Intent.ACTION_BOOT_COMPLETED && intent.action != Intent.ACTION_MY_PACKAGE_REPLACED) return
        val app = context.applicationContext as? EchoLedgerApp ?: return
        val pending = goAsync()
        CoroutineScope(Dispatchers.IO).launch {
            try {
                val why = if (intent.action == Intent.ACTION_BOOT_COMPLETED) "after restart" else "after update"
                AutoRecordKeeper.ensure(context, app.graph.settings.current(), why)
            } finally {
                pending.finish()
            }
        }
    }
}
