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
 * "Ask me" after a call: nobody choosing keeps the call as audio only after [WINDOW_SECONDS] (since 2.0.5; it was
 * kept and transcribed until then), as the after-call card does after its 8 s. The card does this itself; this run is
 * the backstop for the notification (no overlay permission, or the card was cleared). No wake locks or alarms:
 * WorkManager schedules it.
 */
class DecisionTimeoutWorker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {
    override suspend fun doWork(): Result {
        val id = inputData.getString(KEY_ID) ?: return Result.success()
        val graph = (applicationContext as EchoLedgerApp).graph
        val recording = graph.recordings.get(id) ?: return Result.success()
        if (recording.state != "pending_decision") return Result.success()
        Decisions.apply(applicationContext, id, keep = true, transcribe = false)
        return Result.success()
    }

    companion object {
        const val KEY_ID = "recording_id"
        const val WINDOW_SECONDS = 10L

        fun workName(recordingId: String) = "decision-timeout-$recordingId"

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
