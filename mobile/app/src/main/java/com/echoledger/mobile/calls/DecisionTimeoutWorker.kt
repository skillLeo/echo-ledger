package com.echoledger.mobile.calls

import android.content.Context
import androidx.work.CoroutineWorker
import androidx.work.ExistingWorkPolicy
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import androidx.work.workDataOf
import com.echoledger.mobile.EchoLedgerApp
import java.util.concurrent.TimeUnit

/**
 * "If I don't choose within 60 s" (A2.5, same rule as the desktop): runs once, 60 s after the post-call question was
 * shown, and applies the setting in force then. No wake locks or alarms: WorkManager schedules it.
 */
class DecisionTimeoutWorker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {
    override suspend fun doWork(): Result {
        val id = inputData.getString(KEY_ID) ?: return Result.success()
        val graph = (applicationContext as EchoLedgerApp).graph
        val recording = graph.recordings.get(id) ?: return Result.success()
        if (recording.state != "pending_decision") return Result.success()
        when (timeoutDecision(graph.settings.current().afterCallTimeout)) {
            true -> Decisions.apply(applicationContext, id, keep = true)
            false -> Decisions.apply(applicationContext, id, keep = false)
            null -> Unit
        }
        return Result.success()
    }

    companion object {
        const val KEY_ID = "recording_id"
        const val WINDOW_SECONDS = 60L

        fun workName(recordingId: String) = "decision-timeout-$recordingId"

        /** true = keep, false = discard, null = keep waiting (unknown values keep the recording, so a call is never lost). */
        fun timeoutDecision(setting: String): Boolean? = when (setting) {
            "keep" -> true
            "discard" -> false
            "wait" -> null
            else -> true
        }

        fun schedule(context: Context, recordingId: String) {
            val request = OneTimeWorkRequestBuilder<DecisionTimeoutWorker>()
                .setInitialDelay(WINDOW_SECONDS, TimeUnit.SECONDS)
                .setInputData(workDataOf(KEY_ID to recordingId))
                .build()
            WorkManager.getInstance(context).enqueueUniqueWork(workName(recordingId), ExistingWorkPolicy.REPLACE, request)
        }

        fun cancel(context: Context, recordingId: String) {
            WorkManager.getInstance(context).cancelUniqueWork(workName(recordingId))
        }
    }
}
