package com.echoledger.mobile.notify

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import androidx.core.content.ContextCompat
import androidx.work.Constraints
import androidx.work.CoroutineWorker
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.NetworkType
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import com.echoledger.mobile.EchoLedgerApp
import com.echoledger.mobile.core.Activity
import java.io.File
import java.time.LocalTime
import java.util.concurrent.TimeUnit

/**
 * The activity centre on the phone (B18): every 15 minutes, when online, new notifications from the server become
 * phone notifications that open the recording. Never twice, nothing from before the first run, nothing in the quiet
 * hours. WorkManager batches it with other work; there is no wake lock or polling of our own.
 */
class ActivityWorker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {
    override suspend fun doWork(): Result {
        val graph = (applicationContext as EchoLedgerApp).graph
        val api = graph.api() ?: return Result.success()
        val answer = runCatching { api.activity(20) }.getOrElse { return Result.success() }
        val fresh = seen(applicationContext).fresh(answer.data)
        val settings = graph.settings.current()
        val now = LocalTime.now().let { it.hour * 60 + it.minute }
        val allowed =
            settings.activityNotifications && !Activity.inQuietHours(settings.quietHours, settings.quietFrom, settings.quietTo, now)
        val canPost =
            ContextCompat.checkSelfPermission(applicationContext, Manifest.permission.POST_NOTIFICATIONS) ==
                PackageManager.PERMISSION_GRANTED
        if (fresh.isNotEmpty() && allowed && canPost) {
            val texts = Activity.notificationText(fresh)
            if (texts.size == 1 && fresh.size > 1) {
                Notifications.activity(applicationContext, "activity-summary", texts[0].first, texts[0].second, null)
            } else {
                fresh.zip(texts).forEach { (item, text) ->
                    Notifications.activity(applicationContext, item.id, text.first, text.second, item.sessionId)
                }
            }
        }
        return Result.success()
    }

    companion object {
        private const val NAME = "activity-poll"

        fun seen(context: Context) = Activity.SeenStore(File(context.noBackupFilesDir, "activity_seen.json"))

        /** Starts (or keeps) the 15-minute poll while online. */
        fun schedule(context: Context) {
            val request = PeriodicWorkRequestBuilder<ActivityWorker>(15, TimeUnit.MINUTES)
                .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
                .build()
            WorkManager.getInstance(context).enqueueUniquePeriodicWork(NAME, ExistingPeriodicWorkPolicy.KEEP, request)
        }
    }
}
