package com.echoledger.mobile.calls

import android.content.Context
import androidx.work.CoroutineWorker
import androidx.work.ExistingWorkPolicy
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import androidx.work.workDataOf
import java.util.concurrent.TimeUnit

/**
 * The after-call chip's Discard (since v1.8.1): the call's file is deleted only once "Discarded · Undo" is over
 * ([Decisions.discardLater]). WorkManager keeps this run when the app is closed or killed meanwhile, so the file goes
 * then and never sooner; Undo cancels it. The hourly check ([UndecidedKeepWorker]) is the backstop.
 */
class DiscardLaterWorker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {
    override suspend fun doWork(): Result {
        val id = inputData.getString(KEY_ID) ?: return Result.success()
        Decisions.finishDiscard(applicationContext, id)
        return Result.success()
    }

    companion object {
        const val KEY_ID = "recording_id"

        fun workName(recordingId: String) = "discard-later-$recordingId"

        fun schedule(context: Context, recordingId: String, afterMs: Long) {
            val request = OneTimeWorkRequestBuilder<DiscardLaterWorker>()
                .setInitialDelay(afterMs, TimeUnit.MILLISECONDS)
                .setInputData(workDataOf(KEY_ID to recordingId))
                .build()
            WorkManager.getInstance(context).enqueueUniqueWork(workName(recordingId), ExistingWorkPolicy.REPLACE, request)
        }

        fun cancel(context: Context, recordingId: String) {
            WorkManager.getInstance(context).cancelUniqueWork(workName(recordingId))
        }
    }
}
