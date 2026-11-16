package com.echoledger.mobile.calls

import android.Manifest
import android.annotation.SuppressLint
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import androidx.core.content.ContextCompat
import androidx.work.BackoffPolicy
import androidx.work.Constraints
import androidx.work.CoroutineWorker
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import com.echoledger.mobile.BuildConfig
import com.echoledger.mobile.EchoLedgerApp
import com.echoledger.mobile.MainActivity
import com.echoledger.mobile.R
import com.echoledger.mobile.core.NotifyCategory
import com.echoledger.mobile.net.ApiException
import com.echoledger.mobile.net.NotPairedException
import com.echoledger.mobile.notify.NotifyGate
import com.echoledger.mobile.recording.CallJournal
import java.io.IOException
import java.time.Instant
import java.util.concurrent.TimeUnit

/**
 * Looks for missed recordings (since v1.8, P0 0.2): soon after each call, every hour, and when the app opens. What it
 * finds becomes an Activity item, one notice (Call capture & readiness), and a report to the server.
 */
class MissedRecordingWorker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {
    override suspend fun doWork(): Result {
        val fresh = detect(applicationContext)
        if (fresh.isNotEmpty()) notify(applicationContext, fresh)
        if (MissedRecordingStore.all(applicationContext).any { it.waiting }) MissedUploadWorker.enqueue(applicationContext)
        return Result.success()
    }

    companion object {
        private const val PERIODIC = "missed-recordings"
        private const val SOON = "missed-recordings-soon"
        private const val NOTICE_ID = 7_460

        /**
         * Runs the detector now: the journal, the call notifications seen, the call log (website build, when allowed) and
         * the recordings on the phone. The new items, also kept in [MissedRecordingStore].
         */
        suspend fun detect(context: Context, nowMs: Long = System.currentTimeMillis()): List<MissedItem> {
            val graph = (context.applicationContext as EchoLedgerApp).graph
            val settings = graph.settings.current()
            // A journal without the person's choices yet (the first run after the update): the ones of now, from its start.
            CallJournal.update(context, nowMs) { b ->
                if (b.settings.isEmpty()) CallJournal.choices(b, CallJournal.choicesOf(settings), b.since) else b
            }
            val book = CallJournal.book(context)
            val since = maxOf(book.since, nowMs - CallJournal.KEEP_DAYS * 24 * 60 * 60 * 1000)
            val sightings = CallSightings.sightings(CallSightings.all(context)) + PhoneCallLog.calls(context, since)
            val spans = graph.recordings.all().mapNotNull { rec ->
                val start = runCatching { Instant.parse(rec.startedAt).toEpochMilli() }.getOrNull() ?: return@mapNotNull null
                val end = runCatching { Instant.parse(rec.endedAt).toEpochMilli() }.getOrNull() ?: (start + rec.durationMs)
                MissedRecordings.Span(start, maxOf(end, start + rec.durationMs))
            }
            val found = MissedRecordings.find(book, sightings, spans, MissedRecordingStore.all(context), nowMs)
            return MissedRecordingStore.add(context, found, nowMs)
        }

        /** Every hour, as long as the app is installed (WorkManager keeps one). */
        fun schedule(context: Context) {
            val request = PeriodicWorkRequestBuilder<MissedRecordingWorker>(1, TimeUnit.HOURS).build()
            WorkManager.getInstance(context).enqueueUniquePeriodicWork(PERIODIC, ExistingPeriodicWorkPolicy.KEEP, request)
        }

        /** Once, a little after a call ended (its recording is saved by then). */
        fun soon(context: Context) {
            val request = OneTimeWorkRequestBuilder<MissedRecordingWorker>()
                .setInitialDelay(MissedRecordings.SETTLE_MS + 30_000, TimeUnit.MILLISECONDS)
                .build()
            WorkManager.getInstance(context).enqueueUniqueWork(SOON, ExistingWorkPolicy.REPLACE, request)
        }

        /** "Missed recording", or "2 calls were not recorded"; a tap opens Activity. Its own switch and quiet hours apply. */
        @SuppressLint("MissingPermission") // checked first
        private suspend fun notify(context: Context, fresh: List<MissedItem>) {
            val app = context.applicationContext as EchoLedgerApp
            if (ContextCompat.checkSelfPermission(context, Manifest.permission.POST_NOTIFICATIONS) !=
                PackageManager.PERMISSION_GRANTED
            ) {
                return
            }
            if (!NotifyGate.allowed(app, NotifyCategory.READINESS)) return
            val title = if (fresh.size == 1) "Missed recording" else "${fresh.size} calls were not recorded"
            val text = MissedRecordings.body(fresh.first())
            val open = PendingIntent.getActivity(
                context,
                NOTICE_ID,
                Intent(context, MainActivity::class.java)
                    .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_SINGLE_TOP)
                    .putExtra(MainActivity.EXTRA_OPEN, MainActivity.OPEN_ACTIVITY),
                PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT
            )
            val notification = NotificationCompat.Builder(context, NotifyCategory.READINESS.channelId)
                .setSmallIcon(R.drawable.ic_stat_rec)
                .setContentTitle(title)
                .setContentText(text)
                .setStyle(NotificationCompat.BigTextStyle().bigText(text))
                .setContentIntent(open)
                .setAutoCancel(true)
                .build()
            runCatching { NotificationManagerCompat.from(context).notify(NOTICE_ID, notification) }
        }
    }
}

/**
 * Sends the missed recordings the server does not have yet (`POST /api/devices/missed-recordings`): accepted (201, or
 * 200 for one it had) → sent; refused for good (422, 404 on a server without it, 403) → never again; anything else
 * waits for the next try, offline included. Each keeps its `client_ref`, so a retry never makes a second item.
 */
class MissedUploadWorker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {
    override suspend fun doWork(): Result {
        val graph = (applicationContext as EchoLedgerApp).graph
        val api = graph.api() ?: return Result.success() // not signed in: they wait on the phone
        val now = System.currentTimeMillis()
        for (item in MissedRecordingStore.all(applicationContext).filter { it.waiting }) {
            if (now - item.startedAtMs > MissedRecordings.MAX_AGE_MS || !MissedRecordings.CLIENT_REF.matches(item.ref)) {
                MissedRecordingStore.refused(applicationContext, item.ref)
                continue
            }
            try {
                val id = api.reportMissedRecording(MissedRecordings.payload(item, BuildConfig.VERSION_NAME))
                MissedRecordingStore.sent(applicationContext, item.ref, id)
            } catch (e: NotPairedException) {
                return Result.success()
            } catch (e: ApiException) {
                if (e.status == 403 || e.status == 404 || e.status == 422) {
                    MissedRecordingStore.refused(applicationContext, item.ref)
                } else {
                    return Result.retry()
                }
            } catch (e: IOException) {
                return Result.retry()
            }
        }
        return Result.success()
    }

    companion object {
        fun enqueue(context: Context) {
            val request = OneTimeWorkRequestBuilder<MissedUploadWorker>()
                .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
                .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 60, TimeUnit.SECONDS)
                .build()
            WorkManager.getInstance(context).enqueueUniqueWork("missed-upload", ExistingWorkPolicy.APPEND_OR_REPLACE, request)
        }
    }
}
