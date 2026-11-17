package com.echoledger.mobile.net

import android.content.Context
import androidx.work.BackoffPolicy
import androidx.work.Constraints
import androidx.work.CoroutineWorker
import androidx.work.ExistingWorkPolicy
import androidx.work.ForegroundInfo
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.OutOfQuotaPolicy
import androidx.work.WorkInfo
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import androidx.work.workDataOf
import com.echoledger.mobile.EchoLedgerApp
import com.echoledger.mobile.calls.Decisions
import com.echoledger.mobile.core.Owner
import com.echoledger.mobile.core.RowProgress
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
import kotlinx.coroutines.NonCancellable
import kotlinx.coroutines.currentCoroutineContext
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.withContext
import kotlinx.coroutines.withTimeout

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
        // Discarded after a call (since v1.8.1): nothing of it is sent, and its delete is the Discard's to make.
        if (rec.state == Decisions.DISCARDING) return Result.success()
        if (!graph.recordings.encryptedFile(id).exists()) {
            // Nothing to send can ever appear again: fail at once instead of retrying for hours (A6.M1).
            keep(id) { it.copy(state = "failed", lastError = MISSING_FILE) }
            return Result.failure()
        }
        val api = graph.api() ?: return Result.retry()
        // Since v1.5: a recording made under another account waits for it (core/Owner); it is sent again at its sign-in.
        val current = graph.settings.current().account
        Owner.heldFor(rec.owner, current)?.let { owner ->
            keep(id) { it.copy(state = "queued", lastError = held(owner)) }
            return Result.success()
        }

        return try {
            val started = keep(id) { it.copy(state = "uploading", lastError = null) }
            if (started?.state == Decisions.DISCARDING) return Result.success()
            val current = bringBackServerCopy(api, started ?: rec)
            when (upload(api, current)) {
                Outcome.READY -> {
                    // The server now holds the (encrypted) copy, so the phone's goes, in one step with the check that
                    // it was not discarded meanwhile; if it was, the server's copy goes too (the phone's with the Discard).
                    if (graph.recordings.deleteIf(id) { it.state != Decisions.DISCARDING } == null) {
                        discardedMeanwhile(id)
                        return Result.success()
                    }
                    // The Phone app's copy of an imported call may now be offered for deletion (when the person chose it).
                    com.echoledger.mobile.calls.PhoneAppRecordings.uploaded(applicationContext, id)
                    runCatching { graph.library.refresh() }
                    WidgetStateStore.refresh(applicationContext)
                    Result.success()
                }

                Outcome.STILL_VERIFYING -> {
                    // Re-checked on the next run without sending the chunks again.
                    keep(id) { it.copy(state = "queued", lastError = null) }
                    if (discardedMeanwhile(id)) Result.success() else Result.retry()
                }
            }
        } catch (e: Throwable) {
            // Android stopped this run (the work was replaced, a constraint went, the system needed the resources): it
            // is not a failure, whatever the answer that arrived after the stop. The recording waits and WorkManager
            // runs it again (since v1.5; it showed "Upload failed (JobCancellationException)" when the share page was
            // left).
            if (e is kotlinx.coroutines.CancellationException || !currentCoroutineContext().isActive) {
                keep(id) { it.copy(state = "queued", lastError = null) }
                // A Discard calls the upload off: a session this run opened on the server goes with the call.
                discardedMeanwhile(id)
                throw e as? kotlinx.coroutines.CancellationException ?: kotlinx.coroutines.CancellationException("upload stopped")
            }
            if (discardedMeanwhile(id)) return Result.success()
            when (e) {
                is NotPairedException -> {
                    keep(id) { it.copy(state = "queued", lastError = e.message) }
                    Result.failure()
                }

                is ApiException -> onApiError(id, e)

                is IOException -> {
                    keep(id) { it.copy(state = "queued", lastError = friendly(e)) }
                    if (runAttemptCount < MAX_ATTEMPTS) Result.retry() else Result.failure()
                }

                // Anything unexpected (an HTML page instead of JSON, a broken answer): never left "uploading" (A6.M1).
                else -> {
                    keep(id) { it.copy(state = "failed", lastError = friendly(e)) }
                    Result.failure()
                }
            }
        }
    }

    /** Android 11 and older run expedited work as a foreground service: a quiet "Uploading" on the Uploads channel. */
    override suspend fun getForegroundInfo(): ForegroundInfo {
        val notification = androidx.core.app.NotificationCompat.Builder(
            applicationContext,
            com.echoledger.mobile.core.NotifyCategory.UPLOADS.channelId
        )
            .setSmallIcon(com.echoledger.mobile.R.drawable.ic_stat_rec)
            .setContentTitle("Uploading a recording")
            .setSilent(true)
            .setOngoing(true)
            .build()
        return ForegroundInfo(UPLOAD_NOTIFICATION_ID, notification)
    }

    private enum class Outcome { READY, STILL_VERIFYING }

    /** Changes the recording unless it is on its way out ("discarding"): a Discard is never undone by the upload. */
    private fun keep(id: String, change: (LocalRecording) -> LocalRecording): LocalRecording? =
        (applicationContext as EchoLedgerApp).graph.recordings.update(id) { if (it.state == Decisions.DISCARDING) it else change(it) }

    /**
     * After an Undo of a Discard whose upload had reached the server (since v1.8.1): a delete that may have run is undone
     * (`POST /api/sessions/{id}/restore`), and the upload then finds that session again by its reference and finishes
     * against it. A session that can no longer be restored (410 restore_expired, or not found) is sent again fresh under
     * a new reference, so nothing is lost.
     */
    private suspend fun bringBackServerCopy(api: ApiClient, rec: LocalRecording): LocalRecording {
        val copy = rec.serverCopy ?: return rec
        val fresh = if (rec.restoreServerCopy) {
            try {
                api.restoreSession(copy)
                false
            } catch (e: ApiException) {
                if (e.status == 410 || e.status == 404) true else throw e
            }
        } else {
            false
        }
        return keep(rec.id) {
            it.copy(serverCopy = null, restoreServerCopy = false, clientRef = if (fresh) UUID.randomUUID().toString() else it.clientRef)
        } ?: rec
    }

    /** The server session this run created or finished, once it has one. */
    private var session: String? = null

    /** This run asked the server for its session: the request may have arrived even when no answer did. */
    private var asked: Pair<ApiClient, LocalRecording>? = null

    /**
     * Whether the call was discarded while this run sent it (since v1.8.1). Then the session the run reached on the
     * server is deleted too ([DiscardWorker]); the phone's copy is the Discard's to delete once its Undo time is over. A
     * run stopped while it asked for its session (no answer yet) looks the session up by the call's reference first: the
     * server answers the same request with the session it made, so none is left with the call's title and caller.
     */
    private suspend fun discardedMeanwhile(id: String): Boolean {
        val graph = (applicationContext as EchoLedgerApp).graph
        val now = graph.recordings.get(id)
        if (now?.state != Decisions.DISCARDING) return false
        val known = session ?: now.serverSessionId ?: asked?.let { (api, rec) -> lookUp(api, rec) }
        known?.let { reached ->
            // Kept with the call, so an Undo can stop the delete or bring the session back.
            graph.recordings.update(id) { it.copy(serverCopy = reached) }
            DiscardWorker.enqueue(applicationContext, id, reached)
        }
        return true
    }

    /** The session the server made for [rec]'s reference, by asking again (idempotent); null when it cannot say. */
    private suspend fun lookUp(api: ApiClient, rec: LocalRecording): String? = withContext(NonCancellable) {
        runCatching {
            withTimeout(LOOKUP_TIMEOUT_MS) { api.createSession(oneShot(rec, rec.clientRef ?: rec.id, listOf(artifactOf(rec)))).session.id }
        }.getOrNull()
    }

    private suspend fun upload(api: ApiClient, rec: LocalRecording): Outcome {
        val graph = (applicationContext as EchoLedgerApp).graph
        if (rec.serverSessionId == null) asked = api to rec
        val created = createOrFinish(api, rec)
        session = created.session.id
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
            keep(rec.id) { it.copy(state = "uploading") }
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
        return api.createSession(oneShot(rec, rec.clientRef ?: rec.id, artifacts))
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
        caller = callerOf(rec),
        skipDuplicate = rec.skipDuplicate
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
        val missing = ChunkPlanner.missing(plan.size, slot.upload.receivedChunks)
        var sent = plan.size - missing.size
        // Since v1.5 (Phase 1C): "Uploading 40%" in the Library and on a shared note's page.
        RowProgress.sent(rec.id, sent, plan.size)
        try {
            for (index in missing) {
                val chunk = plan[index]
                val bytes = graph.recordings.readSlice(rec.id, chunk.offset, chunk.length)
                api.uploadChunk(slot.id, index, bytes, sha256(bytes))
                RowProgress.sent(rec.id, ++sent, plan.size)
            }
        } finally {
            RowProgress.doneSending(rec.id)
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
        if (e.code == DUPLICATE) {
            // An imported file the Library already has: nothing is sent; the row says so until it is dismissed.
            if (graph.recordings.get(id)?.state == Decisions.DISCARDING) return Result.success()
            graph.recordings.encryptedFile(id).delete()
            keep(id) { it.copy(state = "duplicate", lastError = ALREADY_THERE) }
            return Result.success()
        }
        // Since v1.6 (P2.7): an app below the server's minimum, or the server in maintenance, waits; never a failure.
        if (e.status == 426 || e.code == UPDATE_REQUIRED) {
            keep(id) { it.copy(state = "queued", lastError = WAITS_FOR_UPDATE) }
            enqueueAfter(applicationContext, id, UPDATE_RECHECK_SECONDS)
            return Result.success()
        }
        if (e.status == 503 && e.code == MAINTENANCE) {
            keep(id) { it.copy(state = "queued", lastError = WAITS_FOR_MAINTENANCE) }
            enqueueAfter(applicationContext, id, (e.retryAfterSeconds ?: MAINTENANCE_RECHECK_SECONDS).coerceIn(60, 6 * 3600))
            return Result.success()
        }
        if (e.status == 429) {
            keep(id) { it.copy(state = "queued", lastError = com.echoledger.mobile.core.Problems.text(e, FAILED)) }
            val wait = e.retryAfterSeconds ?: 0
            if (wait <= SHORT_RETRY_SECONDS) return Result.retry()
            // Honour a long Retry-After: run again only after the server's delay.
            enqueueAfter(applicationContext, id, wait)
            return Result.success()
        }
        if (isPermanent(e.status)) {
            // The server understood and refused this recording (e.g. validation): retrying the same request can
            // never succeed, so show it as failed with the reason instead of "uploading" forever.
            keep(id) { it.copy(state = "failed", lastError = com.echoledger.mobile.core.Problems.text(e, FAILED)) }
            return Result.failure()
        }
        // The server's words when they are for people; a bare status or a 5xx in plain words (since v1.8.1, P-024).
        keep(id) { it.copy(state = "queued", lastError = com.echoledger.mobile.core.Problems.text(e, FAILED)) }
        return if (runAttemptCount < MAX_ATTEMPTS) Result.retry() else Result.failure()
    }

    companion object {
        private const val KEY_ID = "recording_id"
        private const val UPLOAD_NOTIFICATION_ID = 7_450
        private const val MAX_ATTEMPTS = 12
        const val MISSING_FILE = "The recording file is missing on this phone, so it cannot be uploaded."
        const val DUPLICATE = "duplicate_recording"
        const val UPDATE_REQUIRED = "update_required"
        const val MAINTENANCE = "maintenance"
        const val WAITS_FOR_UPDATE = "Waits for an update of EchoLedger: the server no longer takes this version."
        const val WAITS_FOR_MAINTENANCE = "The server is in maintenance. It sends by itself afterwards."
        private const val UPDATE_RECHECK_SECONDS = 6 * 3600L
        private const val MAINTENANCE_RECHECK_SECONDS = 600L
        const val ALREADY_THERE = "Already in your Library"
        const val NO_CONNECTION = "No connection to your server. It uploads by itself when the phone is back online."
        const val INTERRUPTED = "The upload was interrupted. It tries again by itself; tap Retry to try now."
        const val FAILED = "The upload did not go through. Tap Retry; if it keeps failing, copy diagnostics in You → General → Help."

        /** The line a recording made under another account shows while it waits for that account. */
        fun held(owner: Owner): String = "Waits for ${owner.label()}: sign in as that account to send it."

        /**
         * What a failed upload says (since v1.3, copy pass): what happened and what to do, never a raw exception
         * ("Unable to resolve host …"). Certificate problems point to the server address; anything else to Retry.
         */
        fun friendly(e: Throwable): String = when (e) {
            is javax.net.ssl.SSLException ->
                "Your server's certificate could not be checked. Check the server address you signed in with, then tap Retry."

            is java.net.UnknownHostException, is java.net.ConnectException, is java.net.SocketTimeoutException,
            is java.net.NoRouteToHostException -> NO_CONNECTION

            // Never an exception's name or message (since v1.5): what happened and what to do.
            is IOException -> INTERRUPTED

            else -> FAILED
        }

        /** How long a stopped run may take to find the session it asked for (a discarded call's lookup). */
        private const val LOOKUP_TIMEOUT_MS = 15_000L

        /** A `Retry-After` up to this long is left to WorkManager's back-off; longer waits are scheduled exactly. */
        const val SHORT_RETRY_SECONDS = 30L

        /** How often and how long a finished upload is polled while the server verifies it. */
        @Volatile internal var pollIntervalMs = 5_000L

        @Volatile internal var verifyWaitMs = 120_000L

        /** 4xx responses are final (bad request, validation, forbidden); 408/429 and 5xx are worth retrying. */
        fun isPermanent(status: Int): Boolean = status in 400..499 && status != 408 && status != 426 && status != 429

        fun sha256(bytes: ByteArray): String = MessageDigest.getInstance("SHA-256").digest(bytes).joinToString("") { "%02x".format(it) }

        private fun request(recordingId: String, delaySeconds: Long = 0) = OneTimeWorkRequestBuilder<UploadWorker>()
            .setInputData(workDataOf(KEY_ID to recordingId))
            .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
            .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 30, TimeUnit.SECONDS)
            // Since v1.5: started at once (Android's expedited work, else ordinary work when its quota is used up); an
            // expedited job cannot have an initial delay.
            .apply {
                if (delaySeconds > 0) {
                    setInitialDelay(delaySeconds, TimeUnit.SECONDS)
                } else {
                    setExpedited(OutOfQuotaPolicy.RUN_AS_NON_EXPEDITED_WORK_REQUEST)
                }
            }
            .build()

        /**
         * Whether "Retry now" may replace the work in [states]: never a run that is going on (replacing it cancelled the
         * upload of a just-shared note), only one waiting out a back-off or finished.
         */
        fun mayReplace(states: List<WorkInfo.State>): Boolean = states.none { it == WorkInfo.State.RUNNING }

        /** Restarts the upload immediately, replacing any run that is waiting out a retry back-off. */
        fun retryNow(context: Context, recordingId: String) {
            val work = WorkManager.getInstance(context)
            val states = runCatching {
                work.getWorkInfosForUniqueWork("upload-$recordingId").get().map { it.state }
            }.getOrDefault(emptyList())
            if (!mayReplace(states)) return
            work.enqueueUniqueWork("upload-$recordingId", ExistingWorkPolicy.REPLACE, request(recordingId))
        }

        /** Calls off the upload of [recordingId] (an import cancelled before it started). */
        fun cancel(context: Context, recordingId: String) {
            WorkManager.getInstance(context).cancelUniqueWork("upload-$recordingId")
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
