package com.echoledger.mobile.share

import android.content.Context
import androidx.work.Constraints
import androidx.work.CoroutineWorker
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import androidx.work.workDataOf
import com.echoledger.mobile.EchoLedgerApp
import com.echoledger.mobile.core.SharedNotes
import com.echoledger.mobile.notify.Notifications
import java.util.concurrent.TimeUnit

/**
 * "Stay in the app I shared from" (since v1.3): checks every 20 s, for up to half an hour, whether the shared voice
 * notes are transcribed, and says so once each with a quiet notification that opens the note. It never opens a
 * screen by itself.
 */
class SharedNotesWorker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {
    override suspend fun doWork(): Result {
        val graph = (applicationContext as EchoLedgerApp).graph
        val ids = inputData.getStringArray(KEY_IDS)?.toList().orEmpty()
        val round = inputData.getInt(KEY_ROUND, 0)
        if (ids.isEmpty()) return Result.success()
        runCatching { graph.library.refresh() }
        val local = graph.recordings.all()
        val library = graph.library.items.value
        val waiting = ids.filter { id ->
            val session = SharedNotes.sessionFor(applicationContext, id)
            val status = SharedNotes.status(
                local.firstOrNull {
                    it.id == id
                },
                session,
                session?.let { s -> library.firstOrNull { it.id == s } }
            )
            if (!SharedNotes.done(status.stage)) return@filter true
            val preview = library.firstOrNull { it.id == status.sessionId }?.transcript?.preview?.takeIf { it.isNotBlank() }
            Notifications.activity(
                applicationContext,
                key = "shared:$id",
                title = when (status.stage) {
                    SharedNotes.Stage.READY -> "Voice note transcribed"
                    SharedNotes.Stage.NO_AUDIO -> "No audio in the shared voice note"
                    else -> "The shared voice note could not be transcribed"
                },
                body = preview ?: status.text,
                sessionId = status.sessionId,
                silent = true
            )
            false
        }
        if (waiting.isNotEmpty() && round + 1 < MAX_ROUNDS) enqueue(applicationContext, waiting, round + 1)
        return Result.success()
    }

    companion object {
        private const val KEY_IDS = "ids"
        private const val KEY_ROUND = "round"
        private const val ROUND_SECONDS = 20L
        private const val MAX_ROUNDS = 90

        fun enqueue(context: Context, localIds: List<String>, round: Int = 0) {
            val request = OneTimeWorkRequestBuilder<SharedNotesWorker>()
                .setInputData(workDataOf(KEY_IDS to localIds.toTypedArray(), KEY_ROUND to round))
                .setInitialDelay(if (round == 0) 5L else ROUND_SECONDS, TimeUnit.SECONDS)
                .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
                .build()
            WorkManager.getInstance(
                context
            ).enqueueUniqueWork("shared-notes:" + localIds.sorted().joinToString(","), ExistingWorkPolicy.REPLACE, request)
        }
    }
}
