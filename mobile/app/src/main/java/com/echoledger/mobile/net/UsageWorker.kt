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
import com.echoledger.mobile.EchoLedgerApp
import com.echoledger.mobile.data.ApiJson
import com.echoledger.mobile.data.UsageReport
import java.io.File
import java.io.IOException
import java.util.concurrent.TimeUnit
import kotlinx.serialization.builtins.ListSerializer

/**
 * Streaming minutes waiting to be reported (A5.4): one entry per captions run, kept in no-backup storage and capped
 * at [CAP] so a phone that stays offline never grows the file without bound.
 */
class UsageQueue(private val file: File) {
    @Synchronized
    fun all(): List<UsageReport> = runCatching {
        ApiJson.decodeFromString(ListSerializer(UsageReport.serializer()), file.readText())
    }.getOrDefault(emptyList())

    @Synchronized
    fun add(report: UsageReport) = save((all() + report).takeLast(CAP))

    @Synchronized
    fun remove(reportId: String) = save(all().filterNot { it.reportId == reportId })

    private fun save(items: List<UsageReport>) {
        val tmp = File(file.parentFile, file.name + ".tmp")
        tmp.writeText(ApiJson.encodeToString(ListSerializer(UsageReport.serializer()), items))
        if (!tmp.renameTo(file)) {
            file.delete()
            tmp.renameTo(file)
        }
    }

    companion object {
        const val CAP = 500

        fun of(context: Context) = UsageQueue(File(context.noBackupFilesDir, "usage.json"))
    }
}

/** Sends queued usage reports: 200/201 remove, 404/422 drop (a v1 server or a report it refuses), else retry later. */
class UsageWorker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {
    override suspend fun doWork(): Result {
        val graph = (applicationContext as EchoLedgerApp).graph
        val api = graph.api() ?: return Result.success() // not paired: nothing can be sent; reports stay queued
        val queue = UsageQueue.of(applicationContext)
        for (report in queue.all()) {
            try {
                api.reportUsage(report)
                queue.remove(report.reportId)
            } catch (e: NotPairedException) {
                return Result.success()
            } catch (e: ApiException) {
                if (e.status == 404 || e.status == 422) queue.remove(report.reportId) else return Result.retry()
            } catch (e: IOException) {
                return Result.retry()
            }
        }
        return Result.success()
    }

    companion object {
        /** Queues a report and starts (or keeps) the drain. */
        fun report(context: Context, report: UsageReport) {
            UsageQueue.of(context).add(report)
            val request = OneTimeWorkRequestBuilder<UsageWorker>()
                .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
                .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 60, TimeUnit.SECONDS)
                .build()
            WorkManager.getInstance(context).enqueueUniqueWork("usage-drain", ExistingWorkPolicy.APPEND_OR_REPLACE, request)
        }
    }
}

/** Deletes the server session of a discarded recording (captions opened it): 204 or 404 = done. */
class DiscardWorker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {
    override suspend fun doWork(): Result {
        val sessionId = inputData.getString(KEY_SESSION) ?: return Result.success()
        val api = (applicationContext as EchoLedgerApp).graph.api() ?: return Result.success()
        return try {
            api.deleteSession(sessionId)
            Result.success()
        } catch (e: NotPairedException) {
            Result.success()
        } catch (e: ApiException) {
            if (e.status == 404 || UploadWorker.isPermanent(e.status)) Result.success() else Result.retry()
        } catch (e: IOException) {
            Result.retry()
        }
    }

    companion object {
        const val KEY_SESSION = "session_id"

        fun enqueue(context: Context, recordingId: String, sessionId: String) {
            val request = OneTimeWorkRequestBuilder<DiscardWorker>()
                .setInputData(androidx.work.workDataOf(KEY_SESSION to sessionId))
                .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
                .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 60, TimeUnit.SECONDS)
                .build()
            WorkManager.getInstance(context).enqueueUniqueWork("discard-$recordingId", ExistingWorkPolicy.KEEP, request)
        }
    }
}
