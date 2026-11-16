package com.echoledger.mobile.calls

import android.Manifest
import android.annotation.SuppressLint
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.media.AudioManager
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
import com.echoledger.mobile.core.PhoneCalls
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.net.ApiException
import com.echoledger.mobile.net.NotPairedException
import com.echoledger.mobile.notify.CallerNameListener
import com.echoledger.mobile.notify.NotifyGate
import com.echoledger.mobile.recording.CallJournal
import com.echoledger.mobile.recording.RecordingService
import java.io.IOException
import java.time.Instant
import java.util.concurrent.TimeUnit
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock

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

        /** The app opening looks again at most this often (the hourly run and the one after each call do the rest). */
        const val OPEN_EVERY_MS = 10 * 60_000L

        /** One run at a time: what it finds is compared with the items already kept before it adds its own. */
        private val detecting = Mutex()

        /**
         * Runs the detector now: the journal, the call notifications seen, the call log (website build, when allowed) and
         * the recordings on the phone. The new items, also kept in [MissedRecordingStore].
         */
        suspend fun detect(context: Context, nowMs: Long = System.currentTimeMillis()): List<MissedItem> = detecting.withLock {
            val graph = (context.applicationContext as EchoLedgerApp).graph
            val settings = graph.settings.current()
            // A journal without the person's choices yet (the first run after the update): the ones of now, from its start.
            CallJournal.update(context, nowMs) { b ->
                if (b.settings.isEmpty()) CallJournal.choices(b, CallJournal.choicesOf(settings), b.since) else b
            }
            val book = CallJournal.book(context)
            val from = MissedRecordings.judgeFrom(book, nowMs)
            // Read sightings are trimmed too, so none outlives the journal that knows what became of its call.
            CallSightings.trim(context, nowMs)
            val sightings = CallSightings.sightings(CallSightings.all(context)) + PhoneCallLog.calls(context, from - PhoneCalls.BEFORE_MS)
            val found = MissedRecordings.find(
                book,
                sightings,
                spans(context),
                MissedRecordingStore.all(context),
                nowMs,
                ongoing = ongoing(context),
                importing = PendingImports.waiting(context, nowMs),
                notifications = CallerNameListener.allowed(context)
            )
            MissedRecordingStore.add(context, found, nowMs, settings.account)
        }

        /** The recordings on the phone, as times (what the journal says was recorded is added by the detector). */
        fun spans(context: Context): List<MissedRecordings.Span> =
            (context.applicationContext as EchoLedgerApp).graph.recordings.all().mapNotNull { rec ->
                val start = runCatching { Instant.parse(rec.startedAt).toEpochMilli() }.getOrNull() ?: return@mapNotNull null
                val end = runCatching { Instant.parse(rec.endedAt).toEpochMilli() }.getOrNull() ?: (start + rec.durationMs)
                MissedRecordings.Span(start, maxOf(end, start + rec.durationMs))
            }

        /** Something may still be going on: a recording live or still saving, or a call (the phone's audio mode). */
        fun ongoing(context: Context): Boolean {
            if (RecordingService.isRecording || RecordingService.isSaving) return true
            val mode = context.getSystemService(AudioManager::class.java)?.mode
            return mode == AudioManager.MODE_IN_CALL || mode == AudioManager.MODE_IN_COMMUNICATION
        }

        /**
         * Whether looking can find anything: the full build (with the capture helper), with auto-record or phone calls
         * on. Elsewhere nothing is scheduled.
         */
        fun useful(settings: AppSettings): Boolean =
            BuildConfig.CAPTURE_HELPER && (settings.autoRecordArmed || PhoneCalls.mode(settings.phoneCalls) != PhoneCalls.OFF)

        /** The app opened: looks now, unless it looked in the last [OPEN_EVERY_MS] or there is nothing to look for. */
        suspend fun onOpen(context: Context, nowMs: Long = System.currentTimeMillis()): List<MissedItem> {
            val settings = (context.applicationContext as EchoLedgerApp).graph.settings.current()
            if (!useful(settings)) return emptyList()
            val prefs = context.getSharedPreferences("missed_recordings_detector", Context.MODE_PRIVATE)
            if (nowMs - prefs.getLong("opened_at", 0) in 0 until OPEN_EVERY_MS) return emptyList()
            prefs.edit().putLong("opened_at", nowMs).apply()
            return detect(context, nowMs)
        }

        /** Every hour while looking can find something (see [useful]); otherwise the hourly run is called off. */
        fun schedule(context: Context, settings: AppSettings) {
            val work = WorkManager.getInstance(context)
            if (!useful(settings)) {
                work.cancelUniqueWork(PERIODIC)
                return
            }
            val request = PeriodicWorkRequestBuilder<MissedRecordingWorker>(1, TimeUnit.HOURS).build()
            work.enqueueUniquePeriodicWork(PERIODIC, ExistingPeriodicWorkPolicy.KEEP, request)
        }

        /** Once, a little after a call ended (its recording is saved by then), or after [delayMs]. */
        fun soon(context: Context, delayMs: Long = MissedRecordings.SETTLE_MS + 30_000) {
            val request = OneTimeWorkRequestBuilder<MissedRecordingWorker>()
                .setInitialDelay(delayMs, TimeUnit.MILLISECONDS)
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
 * waits for the next try, offline included. Each keeps its `client_ref`, so a retry never makes a second item. Only the
 * signed-in account's items go; one whose call turned out recorded after all is withdrawn instead; one already read or
 * dismissed on the phone has its server copy marked read too. Items found while it runs go in the same run.
 */
class MissedUploadWorker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {
    override suspend fun doWork(): Result {
        val context = applicationContext
        val graph = (context as EchoLedgerApp).graph
        val api = graph.api() ?: return Result.success() // not signed in: they wait on the phone
        val current = graph.settings.current().account
        val tried = mutableSetOf<String>()
        val seenHere = mutableListOf<String>()
        try {
            while (true) {
                val batch = MissedRecordingStore.forAccount(MissedRecordingStore.all(context), current)
                    .filter { it.waiting && it.ref !in tried }
                if (batch.isEmpty()) break
                val book = CallJournal.book(context)
                val spans = MissedRecordingWorker.spans(context) + MissedRecordings.recordedSpans(book)
                for (item in batch) {
                    tried += item.ref
                    val now = System.currentTimeMillis()
                    if (now - item.startedAtMs > MissedRecordings.MAX_AGE_MS || !MissedRecordings.CLIENT_REF.matches(item.ref)) {
                        MissedRecordingStore.refused(context, item.ref)
                        continue
                    }
                    if (MissedRecordings.coveredNow(item, spans)) {
                        MissedRecordingStore.withdraw(context, item.ref)
                        continue
                    }
                    try {
                        val id = api.reportMissedRecording(MissedRecordings.payload(item, BuildConfig.VERSION_NAME))
                        MissedRecordingStore.sent(context, item.ref, id)
                        val now2 = MissedRecordingStore.all(context).firstOrNull { it.ref == item.ref }
                        if (id != null && now2 != null && (now2.read || now2.dismissed)) seenHere += id
                    } catch (e: NotPairedException) {
                        return Result.success()
                    } catch (e: ApiException) {
                        if (e.status == 403 || e.status == 404 || e.status == 422) {
                            MissedRecordingStore.refused(context, item.ref)
                        } else {
                            return Result.retry()
                        }
                    } catch (e: IOException) {
                        return Result.retry()
                    }
                }
            }
        } finally {
            if (seenHere.isNotEmpty()) markRead(api, seenHere)
        }
        return Result.success()
    }

    /** The server's Activity items of reports already read or dismissed on the phone are read there too. */
    private suspend fun markRead(api: com.echoledger.mobile.net.ApiClient, reportIds: List<String>) {
        runCatching {
            val ids = api.activity(100).data.filter { !it.read && it.missedRecordingId in reportIds }.map { it.id }
            if (ids.isNotEmpty()) api.markActivityRead(ids)
        }
    }

    companion object {
        /** Sends what waits; while a run is waiting or going on it is kept (it also sends what is found meanwhile). */
        fun enqueue(context: Context) {
            val request = OneTimeWorkRequestBuilder<MissedUploadWorker>()
                .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
                .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 60, TimeUnit.SECONDS)
                .build()
            WorkManager.getInstance(context).enqueueUniqueWork("missed-upload", ExistingWorkPolicy.KEEP, request)
        }
    }
}
