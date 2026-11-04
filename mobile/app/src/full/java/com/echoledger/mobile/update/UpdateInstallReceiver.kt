package com.echoledger.mobile.update

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.pm.PackageInstaller
import androidx.core.content.IntentCompat

/**
 * Android's installer reports here on an update handed over by [SelfUpdate]: first it asks for the person's
 * confirmation (its screen is started from here), then it says how the install went. A success replaces this app,
 * so the app is restarted and never hears it.
 */
class UpdateInstallReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action != ACTION) return
        val status = intent.getIntExtra(PackageInstaller.EXTRA_STATUS, PackageInstaller.STATUS_FAILURE)
        if (status == PackageInstaller.STATUS_PENDING_USER_ACTION) {
            val confirm = IntentCompat.getParcelableExtra(intent, Intent.EXTRA_INTENT, Intent::class.java)
            if (confirm == null || runCatching { context.startActivity(confirm.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) }.isFailure) {
                SelfUpdate.finished(SelfUpdate.current(), "Android did not show the install screen. Tap Update again.")
            }
            return
        }
        SelfUpdate.finished(SelfUpdate.current(), message(status, intent.getStringExtra(PackageInstaller.EXTRA_STATUS_MESSAGE)))
    }

    companion object {
        const val ACTION = "com.echoledger.mobile.action.UPDATE_INSTALLED"

        /** In words for people; null = installed. */
        fun message(status: Int, detail: String?): String? = when (status) {
            PackageInstaller.STATUS_SUCCESS -> null

            PackageInstaller.STATUS_FAILURE_ABORTED -> "The update was cancelled. Tap Update to try again."

            PackageInstaller.STATUS_FAILURE_CONFLICT, PackageInstaller.STATUS_FAILURE_INCOMPATIBLE ->
                "Android refused the update: it is not signed like the installed app. Get it from your server's downloads page."

            PackageInstaller.STATUS_FAILURE_STORAGE -> "There is not enough free space for the update. Free some space and try again."

            PackageInstaller.STATUS_FAILURE_BLOCKED -> "This phone blocks installing apps. Ask the phone's administrator."

            else -> "Android could not install the update" + (detail?.takeIf { it.isNotBlank() }?.let { " ($it)" } ?: "") + ". Try again."
        }
    }
}
