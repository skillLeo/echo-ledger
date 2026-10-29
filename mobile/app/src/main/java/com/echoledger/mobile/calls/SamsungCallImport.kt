package com.echoledger.mobile.calls

import android.Manifest
import android.content.ContentUris
import android.content.Context
import android.os.Build
import android.provider.MediaStore
import androidx.work.CoroutineWorker
import androidx.work.ExistingWorkPolicy
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import androidx.work.workDataOf
import com.echoledger.mobile.EchoLedgerApp
import com.echoledger.mobile.core.SourceApp
import com.echoledger.mobile.data.LocalRecording
import com.echoledger.mobile.recording.CaptureLog
import java.io.File
import java.time.Instant
import java.util.UUID
import java.util.concurrent.TimeUnit
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext

/**
 * SIM calls on phones whose maker keeps the phone line for its own dialer (since v1.3): when the helper heard only
 * silence on the line, the recording Samsung's Phone app made of the same call ("Auto record calls" in its settings) is
 * imported after the call: found in the media store under Recordings/Call, matched to the call (time, length, the
 * number or name in its file name), then kept, transcribed and expired like any EchoLedger recording. The original stays
 * where Samsung put it.
 */
object SamsungCallImport {
    /** One audio file in the media store. */
    data class MediaFile(val id: Long, val name: String, val path: String, val addedMs: Long, val durationMs: Long)

    /** A file written this long after the call ended can still be its recording (the Phone app saves it at the end). */
    const val AFTER_END_MS = 3 * 60_000L

    /**
     * The file of a call from [startMs] to [endMs] with [number] / [name] (pure, unit-tested): one in a call-recording
     * folder, saved between the start and 3 minutes after the end, at least 1 s long; the one naming the caller first,
     * then the one whose length is closest to the call's.
     */
    fun pick(files: List<MediaFile>, startMs: Long, endMs: Long, number: String?, name: String?): MediaFile? {
        val callMs = (endMs - startMs).coerceAtLeast(0)
        val digits = number?.filter(Char::isDigit)?.takeLast(7)?.takeIf { it.length >= 5 }
        val who = name?.trim()?.takeIf { it.length >= 2 }?.lowercase()
        return files
            .filter { it.path.contains("Recordings/Call", ignoreCase = true) || it.path.contains("Call recordings", ignoreCase = true) }
            .filter { it.addedMs in (startMs - 5_000)..(endMs + AFTER_END_MS) && it.durationMs >= 1_000 }
            .minWithOrNull(
                compareBy<MediaFile> { file ->
                    val named = (digits != null && file.name.filter(Char::isDigit).contains(digits)) ||
                        (who != null && file.name.lowercase().contains(who))
                    if (named) 0 else 1
                }.thenBy { kotlin.math.abs(it.durationMs - callMs) }
            )
    }

    /** Looks for the Phone app's recording of the call a little after it ended (it is saved when the call ends). */
    fun schedule(context: Context, startMs: Long, endMs: Long) {
        val request = OneTimeWorkRequestBuilder<Worker>()
            .setInitialDelay(20, TimeUnit.SECONDS)
            .setInputData(workDataOf(KEY_START to startMs, KEY_END to endMs))
            .build()
        WorkManager.getInstance(context).enqueueUniqueWork("samsung-call-$startMs", ExistingWorkPolicy.KEEP, request)
    }

    /** Samsung's Phone app setting "Auto record calls" (a system setting any app may read); false elsewhere. */
    fun phoneAppRecords(context: Context): Boolean =
        runCatching { android.provider.Settings.System.getInt(context.contentResolver, "record_calls_automatically_on_off", 0) == 1 }
            .getOrDefault(false)

    /** Whether EchoLedger may read the Phone app's recordings (audio files). */
    fun allowed(context: Context): Boolean = PhoneCallLog.granted(context, permission())

    fun permission(): String = if (Build.VERSION.SDK_INT >=
        33
    ) {
        Manifest.permission.READ_MEDIA_AUDIO
    } else {
        Manifest.permission.READ_EXTERNAL_STORAGE
    }

    private const val KEY_START = "start"
    private const val KEY_END = "end"
    private const val TRIES = 5

    class Worker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {
        override suspend fun doWork(): Result = withContext(Dispatchers.IO) {
            val start = inputData.getLong(KEY_START, 0)
            val end = inputData.getLong(KEY_END, 0)
            if (!allowed(applicationContext)) {
                CaptureLog.add(applicationContext, "phone call", "Phone app recording not imported: no permission to read audio files")
                return@withContext Result.success()
            }
            val caller = PhoneCallLog.find(applicationContext, start, end)
            val file = pick(files(start), start, end, caller?.number, caller?.name)
            if (file == null) {
                if (runAttemptCount + 1 < TRIES) return@withContext Result.retry()
                CaptureLog.add(applicationContext, "phone call", "no Phone app recording found (is Auto record calls on?)")
                return@withContext Result.success()
            }
            val copy = File(applicationContext.cacheDir, "import-${UUID.randomUUID()}.m4a")
            val uri = ContentUris.withAppendedId(MediaStore.Audio.Media.EXTERNAL_CONTENT_URI, file.id)
            val copied = runCatching {
                applicationContext.contentResolver.openInputStream(uri)?.use { input -> copy.outputStream().use { input.copyTo(it) } } !=
                    null
            }.getOrDefault(false)
            if (!copied) {
                copy.delete()
                return@withContext if (runAttemptCount + 1 < TRIES) Result.retry() else Result.success()
            }
            val graph = (applicationContext as EchoLedgerApp).graph
            val settings = graph.settings.current()
            val meta = LocalRecording(
                id = UUID.randomUUID().toString(),
                sourceApp = SourceApp.PHONE_CALL.wire,
                clientId = settings.clientId,
                startedAt = Instant.ofEpochMilli(start).toString(),
                endedAt = Instant.ofEpochMilli(end).toString(),
                durationMs = file.durationMs,
                mime = "audio/mp4",
                codec = "aac",
                channels = 1,
                sampleRate = 48_000,
                plainSize = 0,
                sha256 = "",
                state = "pending_decision",
                language = settings.transcriptionLanguage,
                callerName = caller?.name,
                callerSource = caller?.source,
                callerNumber = caller?.number,
                captureWarning = "Imported from the Phone app's own call recording (the phone line gave EchoLedger no sound)."
            )
            val stored = runCatching { graph.recordings.importPlainFile(meta, copy) }.getOrNull()
            copy.delete()
            CaptureLog.add(
                applicationContext,
                "phone call",
                if (stored !=
                    null
                ) {
                    "imported the Phone app's recording (${file.durationMs / 1000} s)"
                } else {
                    "import failed"
                }
            )
            if (stored != null) {
                withContext(Dispatchers.Main) {
                    PostCallPrompt.show(applicationContext, stored, settings.afterCallTimeout, settings.themeMode)
                }
            }
            Result.success()
        }

        private fun files(sinceMs: Long): List<MediaFile> = runCatching {
            val columns = arrayOf(
                MediaStore.Audio.Media._ID,
                MediaStore.Audio.Media.DISPLAY_NAME,
                MediaStore.Audio.Media.RELATIVE_PATH,
                MediaStore.Audio.Media.DATE_ADDED,
                MediaStore.Audio.Media.DURATION
            )
            applicationContext.contentResolver.query(
                MediaStore.Audio.Media.EXTERNAL_CONTENT_URI,
                columns,
                "${MediaStore.Audio.Media.DATE_ADDED} >= ?",
                arrayOf(((sinceMs - 10_000) / 1000).toString()),
                "${MediaStore.Audio.Media.DATE_ADDED} DESC"
            )?.use { c ->
                buildList {
                    while (c.moveToNext() && size < 50) {
                        add(MediaFile(c.getLong(0), c.getString(1).orEmpty(), c.getString(2).orEmpty(), c.getLong(3) * 1000, c.getLong(4)))
                    }
                }
            }.orEmpty()
        }.getOrDefault(emptyList())
    }
}
