package com.echoledger.mobile.net

import android.content.Context
import androidx.work.BackoffPolicy
import androidx.work.Constraints
import androidx.work.CoroutineWorker
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import androidx.work.workDataOf
import com.echoledger.mobile.EchoLedgerApp
import com.echoledger.mobile.core.Owner
import java.io.IOException
import java.time.Instant
import java.util.concurrent.TimeUnit

/**
 * Sends the end of a call to its server session (contract 2.0.0-z): `PATCH /api/sessions/{id}` with `ended_at` and
 * `duration_ms`, so every device shows the call waiting at once instead of after 4 hours. Queued when a capture stops
 * with a session open (captions opened it), before the after-call card shows; it waits for a connection and survives a
 * restart. 404, 409 (`not_recording`: uploaded or decided already), 422 and any other lasting refusal (a 4xx but 408,
 * 426 and 429) drop it; anything else is tried again later. The server keeps the first end, so a retry is safe. A
 * recording deleted on this phone, one whose session went, or one made under another account (its session is that
 * account's: never sent with this one's token; its upload carries the end) sends nothing.
 */
class CallEndWorker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {
    override suspend fun doWork(): Result {
        val sessionId = inputData.getString(KEY_SESSION) ?: return Result.success()
        val endedAt = inputData.getString(KEY_ENDED_AT) ?: return Result.success()
        val durationMs = inputData.getLong(KEY_DURATION_MS, 0L)
        val graph = (applicationContext as EchoLedgerApp).graph
        // Deleted here meanwhile (its session goes with it), or its session already removed: nothing to end.
        val rec = inputData.getString(KEY_RECORDING)?.let { graph.recordings.get(it) }
        if (rec == null || rec.serverSessionId != sessionId) return Result.success()
        // Made under another account (core/Owner): not this token's session, so it is dropped, never sent with it. When
        // that account signs in again its upload is sent, and the upload's finish carries ended_at; if it was uploaded and
        // finished already, the end was carried then. Either way nothing is lost by not sending it here.
        if (Owner.heldFor(rec.owner, graph.settings.current().account) != null) return Result.success()
        val api = graph.api() ?: return Result.success()
        return try {
            api.endCall(sessionId, endedAt, durationMs)
            Result.success()
        } catch (e: NotPairedException) {
            Result.success()
        } catch (e: ApiException) {
            if (e.status in DROPPED || UploadWorker.isPermanent(e.status)) Result.success() else Result.retry()
        } catch (e: IOException) {
            Result.retry()
        }
    }

    companion object {
        const val KEY_SESSION = "session_id"
        const val KEY_RECORDING = "recording_id"
        const val KEY_ENDED_AT = "ended_at"
        const val KEY_DURATION_MS = "duration_ms"

        /** The answers that end the job without a retry: gone, no longer recording, or refused. */
        val DROPPED = setOf(404, 409, 422)

        fun workName(sessionId: String) = "call-end-$sessionId"

        /** Queues the end of [recordingId]'s call: once per session (a second one keeps the first). */
        fun enqueue(context: Context, recordingId: String, sessionId: String, endedAtMs: Long, durationMs: Long) {
            val request = OneTimeWorkRequestBuilder<CallEndWorker>()
                .setInputData(
                    workDataOf(
                        KEY_SESSION to sessionId,
                        KEY_RECORDING to recordingId,
                        KEY_ENDED_AT to Instant.ofEpochMilli(endedAtMs).toString(),
                        KEY_DURATION_MS to durationMs
                    )
                )
                .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
                .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 60, TimeUnit.SECONDS)
                .build()
            WorkManager.getInstance(context).enqueueUniqueWork(workName(sessionId), ExistingWorkPolicy.KEEP, request)
        }
    }
}
