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
import com.echoledger.mobile.data.ArtifactDto
import com.echoledger.mobile.data.CreateSessionRequest
import com.echoledger.mobile.data.DeviceTranscript
import com.echoledger.mobile.widget.WidgetStateStore
import java.io.IOException
import java.security.MessageDigest
import java.util.concurrent.TimeUnit

/**
 * Resumable upload of one encrypted local recording (WorkManager; survives restarts, waits for network).
 * Chunks are decrypted slice-by-slice through Tink's seekable channel — no plaintext file is written.
 */
class UploadWorker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {
    override suspend fun doWork(): Result {
        val id = inputData.getString(KEY_ID) ?: return Result.failure()
        val graph = (applicationContext as EchoLedgerApp).graph
        val rec = graph.recordings.get(id) ?: return Result.success()
        val api = graph.api() ?: return Result.retry()

        return try {
            graph.recordings.update(id) { it.copy(state = "uploading", lastError = null) }
            val created = api.createSession(
                CreateSessionRequest(
                    clientRef = rec.id,
                    sourceApp = rec.sourceApp,
                    clientId = rec.clientId,
                    title = null,
                    startedAt = rec.startedAt,
                    endedAt = rec.endedAt,
                    durationMs = rec.durationMs.takeIf { it > 0 },
                    language = null,
                    artifacts = listOf(
                        ArtifactDto(
                            "primary",
                            rec.mime,
                            rec.codec,
                            rec.channels,
                            rec.sampleRate.takeIf { it > 0 }, // 0 = unknown (shared voice notes): send "absent", not 0
                            rec.plainSize,
                            rec.sha256,
                            rec.durationMs.takeIf {
                                it >
                                    0
                            }
                        )
                    )
                )
            )
            val upload = created.recordings.first { it.role == "primary" }
            if (upload.upload.status !in setOf("ready", "assembling")) {
                val plan = ChunkPlanner.plan(rec.plainSize, upload.upload.chunkSizeBytes)
                for (index in ChunkPlanner.missing(plan.size, upload.upload.receivedChunks)) {
                    val chunk = plan[index]
                    val bytes = graph.recordings.readSlice(id, chunk.offset, chunk.length)
                    api.uploadChunk(upload.id, index, bytes, sha256(bytes))
                }
                api.complete(upload.id)
            }
            if (rec.liveSegments.isNotEmpty()) {
                runCatching {
                    api.postTranscript(
                        created.session.id,
                        DeviceTranscript("live", rec.liveProvider ?: "assemblyai", null, rec.liveSegments)
                    )
                }
            }
            graph.recordings.delete(id) // the server now holds the (encrypted) copy
            runCatching { graph.library.refresh() }
            WidgetStateStore.refresh(applicationContext)
            Result.success()
        } catch (e: NotPairedException) {
            graph.recordings.update(id) { it.copy(state = "queued", lastError = e.message) }
            Result.failure()
        } catch (e: ApiException) {
            if (isPermanent(e.status)) {
                // The server understood and refused this recording (e.g. validation): retrying the same request can
                // never succeed, so show it as failed with the reason instead of "uploading" forever.
                graph.recordings.update(id) { it.copy(state = "failed", lastError = e.message) }
                Result.failure()
            } else {
                graph.recordings.update(id) { it.copy(state = "queued", lastError = e.message) }
                if (runAttemptCount < 12) Result.retry() else Result.failure()
            }
        } catch (e: IOException) {
            graph.recordings.update(id) { it.copy(state = "queued", lastError = e.message) }
            if (runAttemptCount < 12) Result.retry() else Result.failure()
        }
    }

    companion object {
        private const val KEY_ID = "recording_id"

        /** 4xx responses are final (bad request, validation, forbidden); 408/429 and 5xx are worth retrying. */
        fun isPermanent(status: Int): Boolean = status in 400..499 && status != 408 && status != 429

        fun sha256(bytes: ByteArray): String = MessageDigest.getInstance("SHA-256").digest(bytes).joinToString("") { "%02x".format(it) }

        /** Restarts the upload immediately, replacing any run that is waiting out a retry back-off. */
        fun retryNow(context: Context, recordingId: String) {
            val request = OneTimeWorkRequestBuilder<UploadWorker>()
                .setInputData(workDataOf(KEY_ID to recordingId))
                .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
                .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 30, TimeUnit.SECONDS)
                .build()
            WorkManager.getInstance(context).enqueueUniqueWork("upload-$recordingId", ExistingWorkPolicy.REPLACE, request)
        }

        fun enqueue(context: Context, recordingId: String) {
            val request = OneTimeWorkRequestBuilder<UploadWorker>()
                .setInputData(workDataOf(KEY_ID to recordingId))
                .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
                .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 30, TimeUnit.SECONDS)
                .build()
            WorkManager.getInstance(context).enqueueUniqueWork("upload-$recordingId", ExistingWorkPolicy.KEEP, request)
        }
    }
}
