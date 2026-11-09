package com.echoledger.mobile.notify

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import androidx.core.content.ContextCompat
import androidx.work.Constraints
import androidx.work.CoroutineWorker
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import androidx.work.workDataOf
import com.echoledger.mobile.EchoLedgerApp
import com.echoledger.mobile.core.NotifyCategory
import com.echoledger.mobile.core.RecordingSteps
import java.util.concurrent.TimeUnit

/**
 * "Notify me when ready" on a recording page (since v1.6, P5.4, as on the desktop): checks every 20 s, for up to an
 * hour, whether that recording's transcript is ready, and then says so once, whatever the Transcription switch says
 * (the person asked for this one). Android's own channel setting still applies. The 15-minute activity poll does not
 * repeat a notice given here.
 */
class ReadyWatchWorker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {
    override suspend fun doWork(): Result {
        val graph = (applicationContext as EchoLedgerApp).graph
        val id = inputData.getString(KEY_ID) ?: return Result.success()
        val round = inputData.getInt(KEY_ROUND, 0)
        runCatching { graph.library.refresh() }
        val item = graph.library.items.value.firstOrNull { it.id == id && !it.deleted } ?: return Result.success()
        val notice = RecordingSteps.readyNotice(item)
        if (notice == null) {
            if (round + 1 < MAX_ROUNDS) enqueue(applicationContext, id, round + 1)
            return Result.success()
        }
        val canPost = ContextCompat.checkSelfPermission(applicationContext, Manifest.permission.POST_NOTIFICATIONS) ==
            PackageManager.PERMISSION_GRANTED
        if (canPost) {
            Notifications.activity(
                applicationContext,
                "ready:$id",
                notice.first,
                notice.second,
                id,
                category = NotifyCategory.TRANSCRIPTION
            )
            markNotified(applicationContext, id)
        }
        return Result.success()
    }

    companion object {
        private const val KEY_ID = "session"
        private const val KEY_ROUND = "round"
        private const val ROUND_SECONDS = 20L
        private const val MAX_ROUNDS = 180
        private const val PREFS = "ready_watch"
        private const val NOTIFIED = "notified"
        private const val KEEP = 50

        fun enqueue(context: Context, sessionId: String, round: Int = 0) {
            val request = OneTimeWorkRequestBuilder<ReadyWatchWorker>()
                .setInputData(workDataOf(KEY_ID to sessionId, KEY_ROUND to round))
                .setInitialDelay(if (round == 0) 5L else ROUND_SECONDS, TimeUnit.SECONDS)
                .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
                .build()
            WorkManager.getInstance(context).enqueueUniqueWork("ready-watch:$sessionId", ExistingWorkPolicy.REPLACE, request)
        }

        /** Recordings whose ready notice was given here (the latest [KEEP]), so the activity poll leaves them out. */
        fun notified(context: Context): List<String> = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).getString(NOTIFIED, null)
            ?.split(',')?.filter { it.isNotBlank() }.orEmpty()

        private fun markNotified(context: Context, sessionId: String) {
            val list = (notified(context) - sessionId + sessionId).takeLast(KEEP)
            context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit().putString(NOTIFIED, list.joinToString(",")).apply()
        }
    }
}
