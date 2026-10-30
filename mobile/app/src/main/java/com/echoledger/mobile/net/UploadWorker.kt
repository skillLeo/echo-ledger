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
import com.echoledger.mobile.core.SharedNotes
import com.echoledger.mobile.data.ArtifactDto
import com.echoledger.mobile.data.CallerDto
import com.echoledger.mobile.data.CreateSessionRequest
import com.echoledger.mobile.data.CreateSessionResponse
import com.echoledger.mobile.data.DeviceTranscript
import com.echoledger.mobile.data.FinishSessionRequest
import com.echoledger.mobile.data.LocalRecording
import com.echoledger.mobile.data.RecordingUpload
import com.echoledger.mobile.widget.WidgetStateStore
import java.io.IOException
import java.security.MessageDigest
import java.util.UUID
import java.util.concurrent.TimeUnit
import kotlinx.coroutines.delay

/**
 * Resumable upload of one encrypted local recording (WorkManager; survives restarts, waits for network).
 * Chunks are decrypted slice-by-slice through Tink's seekable channel — no plaintext file is written.
 * The local copy is deleted only once the server reports the file `ready` (verified and encrypted there).
 */
class UploadWorker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {
    override suspend fun doWork(): Result {
        val id = inputData.getString(KEY_ID) ?: return Result.failure()
        val graph = (applicationContext as EchoLedgerApp).graph
        val rec = graph.recordings.get(id) ?: return Result.success()
        if (!graph.recordings.encryptedFile(id).exists()) {
            // Nothing to send can ever appear again: fail at once instead of retrying for hours (A6.M1).
            graph.recordings.update(id) { it.copy(state = "failed", lastError = MISSING_FILE) }
            return Result.failure()
        }
        val api = graph.api() ?: return Result.retry()

        return try {
            graph.recordings.update(id) { it.copy(state = "uploading", lastError = null) }
            when (upload(api, rec)) {
                Outcome.READY -> {
                    graph.recordings.delete(id) // the server now holds the (encrypted) copy
                    runCatching { graph.library.refresh() }
                    WidgetStateStore.refresh(applicationContext)
                    Result.success()
                }

                Outcome.STILL_VERIFYING -> {
                    // Re-checked on the next run without sending the chunks again.
                    graph.recordings.update(id) { it.copy(state = "queued", lastError = null) }
                    Result.retry()
                }
            }
        } catch (e: NotPairedException) {
            graph.recordings.update(id) { it.copy(state = "queued", lastError = e.message) }
            Result.failure()
        } catch (e: ApiException) {
            onApiError(id, e)
        } catch (e: IOException) {
            graph.recordings.update(id) { it.copy(state = "queued", lastError = e.message) }
            if (runAttemptCount < MAX_ATTEMPTS) Result.retry() else Result.failure()
        } catch (e: Throwable) {
            // Anything unexpected (an HTML page instead of JSON, a broken answer): never leave it "uploading" (A6.M1).
            graph.recordings.update(id) {
                it.copy(state = "failed", lastError = "The upload failed: ${e.message ?: e.javaClass.simpleName}")
            }
            Result.failure()
        }
    }

    private enum class Outcome { READY, STILL_VERIFYING }

    private suspend fun upload(api: ApiClient, rec: LocalRecording): Outcome {
        val graph = (applicationContext as EchoLedgerApp).graph
        val created = createOrFinish(api, rec)
        // A voice note shared from another app: its page follows the server recording once the local copy is gone.
        SharedNotes.linkSession(applicationContext, rec.id, created.session.id)
        val primary = created.recordings.firstOrNull { it.role == "primary" }
            ?: throw ApiException(422, "The server did not offer an upload for this recording.")

        var slot = primary
        var restarted = false
        while (true) {
            val status = when (slot.upload.status) {
                "ready" -> "ready"

                "assembling" -> "assembling"

                else -> {
                    sendChunks(api, rec, slot)
                    try {
                        api.complete(slot.id)
                    } catch (e: ApiException) {
                        if (e.code != "verification_failed") throw e
                        "failed"
                    }
                }
            }
            val final = if (status == "ready" || status == "failed") status else awaitVerification(api, slot.id)
            when (final) {
                "ready" -> {
                    postLiveTranscript(api, created.session.id, rec)
                    return Outcome.READY
                }

                "failed" -> {
                    if (restarted) throw ApiException(422, "The uploaded file did not match its checksum twice. Try again later.")
                    // The server discarded the parts: send the whole file once more.
                    restarted = true
                    slot = RecordingUpload(slot.id, slot.role, slot.upload.copy(receivedChunks = emptyList(), status = "pending"))
                }

                else -> return Outcome.STILL_VERIFYING
            }
            graph.recordings.update(rec.id) { it.copy(state = "uploading") }
        }
    }

    /** A recording that opened a server session for captions finishes it; otherwise one call creates the session. */
    private suspend fun createOrFinish(api: ApiClient, rec: LocalRecording): CreateSessionResponse {
        val artifacts = listOf(artifactOf(rec))
        val sessionId = rec.serverSessionId
        if (sessionId != null) {
            try {
                return api.finishSession(
                    sessionId,
                    FinishSessionRequest(
                        rec.endedAt,
                        rec.durationMs,
                        rec.clientId,
                        artifacts,
                        language = rec.language.orEmpty(),
                        title = rec.title,
                        markers = rec.markers,
                        pauses = rec.pauses,
                        languageMarks = rec.languageMarks,
                        caller = callerOf(rec)
                    )
                )
            } catch (e: ApiException) {
                // The open session was swept or deleted meanwhile: create a fresh one with a new reference.
                if (e.status != 404) throw e
                return api.createSession(oneShot(rec, UUID.randomUUID().toString(), artifacts))
            }
        }
        return api.createSession(oneShot(rec, rec.id, artifacts))
    }

    private fun oneShot(rec: LocalRecording, clientRef: String, artifacts: List<ArtifactDto>) = CreateSessionRequest(
        clientRef = clientRef,
        sourceApp = rec.sourceApp,
        clientId = rec.clientId,
        title = rec.title,
        startedAt = rec.startedAt,
        endedAt = rec.endedAt,
        durationMs = rec.durationMs.takeIf { it > 0 },
        language = rec.language,
        artifacts = artifacts,
        markers = rec.markers,
        pauses = rec.pauses,
        languageMarks = rec.languageMarks,
        caller = callerOf(rec)
    )

    /** Caller names (since v1.2): who the call was with, when the phone saw it. */
    private fun callerOf(rec: LocalRecording) = if (rec.callerName == null && rec.callerNumber == null) {
        null
    } else {
        CallerDto(name = rec.callerName, number = rec.callerNumber, source = rec.callerSource)
    }

    private fun artifactOf(rec: LocalRecording) = ArtifactDto(
        "primary",
        rec.mime,
        rec.codec,
        rec.channels,
        rec.sampleRate.takeIf { it > 0 }, // 0 = unknown (shared voice notes): send "absent", not 0
        rec.plainSize,
        rec.sha256,
        rec.durationMs.takeIf { it > 0 }
    )

    private suspend fun sendChunks(api: ApiClient, rec: LocalRecording, slot: RecordingUpload) {
        val graph = (applicationContext as EchoLedgerApp).graph
        val plan = ChunkPlanner.plan(rec.plainSize, slot.upload.chunkSizeBytes)
        for (index in ChunkPlanner.missing(plan.size, slot.upload.receivedChunks)) {
            val chunk = plan[index]
            val bytes = graph.recordings.readSlice(rec.id, chunk.offset, chunk.length)
            api.uploadChunk(slot.id, index, bytes, sha256(bytes))
        }
    }

    /** Polls the recording while the server verifies it: `ready`, `failed`, or still `assembling` after the wait. */
    private suspend fun awaitVerification(api: ApiClient, recordingId: String): String {
        val deadline = System.currentTimeMillis() + verifyWaitMs
        while (true) {
            val status = api.recordingStatus(recordingId).upload.status
            if (status == "ready" || status == "failed") return status
            if (System.currentTimeMillis() >= deadline) return status
            delay(pollIntervalMs)
        }
    }

    private suspend fun postLiveTranscript(api: ApiClient, sessionId: String, rec: LocalRecording) {
        if (rec.liveSegments.isEmpty()) return
        runCatching { api.postTranscript(sessionId, DeviceTranscript("live", rec.liveProvider ?: "assemblyai", null, rec.liveSegments)) }
    }

    private fun onApiError(id: String, e: ApiException): Result {
        val graph = (applicationContext as EchoLedgerApp).graph
        if (e.status == 429) {
            graph.recordings.update(id) { it.copy(state = "queued", lastError = e.message) }
            val wait = e.retryAfterSeconds ?: 0
            if (wait <= SHORT_RETRY_SECONDS) return Result.retry()
            // Honour a long Retry-After: run again only after the server's delay.
            enqueueAfter(applicationContext, id, wait)
            return Result.success()
        }
        if (isPermanent(e.status)) {
            // The server understood and refused this recording (e.g. validation): retrying the same request can
            // never succeed, so show it as failed with the reason instead of "uploading" forever.
            graph.recordings.update(id) { it.copy(state = "failed", lastError = e.message) }
            return Result.failure()
        }
        graph.recordings.update(id) { it.copy(state = "queued", lastError = e.message) }
        return if (runAttemptCount < MAX_ATTEMPTS) Result.retry() else Result.failure()
    }

    companion object {
        private const val KEY_ID = "recording_id"
        private const val MAX_ATTEMPTS = 12
        const val MISSING_FILE = "The recording file is missing on this phone, so it cannot be uploaded."

        /** A `Retry-After` up to this long is left to WorkManager's back-off; longer waits are scheduled exactly. */
        const val SHORT_RETRY_SECONDS = 30L

        /** How often and how long a finished upload is polled while the server verifies it. */
        @Volatile internal var pollIntervalMs = 5_000L

        @Volatile internal var verifyWaitMs = 120_000L

        /** 4xx responses are final (bad request, validation, forbidden); 408/429 and 5xx are worth retrying. */
        fun isPermanent(status: Int): Boolean = status in 400..499 && status != 408 && status != 429

        fun sha256(bytes: ByteArray): String = MessageDigest.getInstance("SHA-256").digest(bytes).joinToString("") { "%02x".format(it) }

        private fun request(recordingId: String, delaySeconds: Long = 0) = OneTimeWorkRequestBuilder<UploadWorker>()
            .setInputData(workDataOf(KEY_ID to recordingId))
            .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
            .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 30, TimeUnit.SECONDS)
            .apply { if (delaySeconds > 0) setInitialDelay(delaySeconds, TimeUnit.SECONDS) }
            .build()

        /** Restarts the upload immediately, replacing any run that is waiting out a retry back-off. */
        fun retryNow(context: Context, recordingId: String) {
            WorkManager.getInstance(context).enqueueUniqueWork("upload-$recordingId", ExistingWorkPolicy.REPLACE, request(recordingId))
        }

        fun enqueue(context: Context, recordingId: String) {
            WorkManager.getInstance(context).enqueueUniqueWork("upload-$recordingId", ExistingWorkPolicy.KEEP, request(recordingId))
        }

        /** Runs the upload again after [delaySeconds] (a long `Retry-After`), after the current run ends. */
        fun enqueueAfter(context: Context, recordingId: String, delaySeconds: Long) {
            WorkManager.getInstance(context)
                .enqueueUniqueWork("upload-$recordingId", ExistingWorkPolicy.APPEND_OR_REPLACE, request(recordingId, delaySeconds))
        }
    }
}
