package com.echoledger.mobile.update

import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.content.pm.PackageInstaller
import android.os.Build
import androidx.work.Constraints
import androidx.work.CoroutineWorker
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.NetworkType
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import com.echoledger.mobile.BuildConfig
import com.echoledger.mobile.EchoLedgerApp
import com.echoledger.mobile.data.AndroidReleaseDto
import com.echoledger.mobile.net.ApiClient
import java.io.File
import java.io.IOException
import java.util.concurrent.TimeUnit
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.coroutines.withContext
import okhttp3.Request

/**
 * The website build's own updates (since v1.5, docs/API.md "Public pages and app downloads"): it looks at the server's
 * /downloads/android.json at most every 12 hours, downloads a newer build from the same server only, checks its size
 * and SHA-256, and hands it to Android's installer, which asks the person and refuses a file that is not signed like
 * the installed app. The Google Play build has a stub of this object: Play updates it.
 */
object SelfUpdate {
    const val SUPPORTED = true
    private const val WORK = "update-check"

    private val _status = MutableStateFlow<UpdateStatus>(UpdateStatus.Idle)
    val status: StateFlow<UpdateStatus> = _status.asStateFlow()
    private val installing = Mutex()

    /** At start: the update found earlier is offered again without asking the server. */
    fun restore(context: Context) {
        if (_status.value != UpdateStatus.Idle) return
        AppUpdates.offer(UpdatePrefs(context).offered, BuildConfig.VERSION_CODE, Build.VERSION.SDK_INT)?.let {
            _status.value = UpdateStatus.Available(it)
        }
    }

    /** The 12-hour look while online (kept while scheduled; started after each library refresh like the activity poll). */
    fun schedule(context: Context) {
        val request = PeriodicWorkRequestBuilder<UpdateCheckWorker>(12, TimeUnit.HOURS)
            .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
            .setInitialDelay(1, TimeUnit.MINUTES)
            .build()
        WorkManager.getInstance(context).enqueueUniquePeriodicWork(WORK, ExistingPeriodicWorkPolicy.KEEP, request)
    }

    /** Asks the server for its newest build; [force] (Check for updates) ignores the 12 hours and says what it found. */
    suspend fun check(context: Context, api: ApiClient?, force: Boolean, nowMs: Long = System.currentTimeMillis()) {
        if (api == null) return
        val current = _status.value
        if (current is UpdateStatus.Downloading || current is UpdateStatus.Installing) return
        val prefs = UpdatePrefs(context)
        if (!force && !AppUpdates.due(prefs.lastCheckMs, nowMs)) return
        if (force) _status.value = UpdateStatus.Checking
        try {
            val release = AppUpdates.offer(api.androidRelease(), BuildConfig.VERSION_CODE, Build.VERSION.SDK_INT)
            prefs.lastCheckMs = nowMs
            prefs.offered = release
            _status.value = if (release != null) UpdateStatus.Available(release) else UpdateStatus.UpToDate
        } catch (e: Exception) {
            if (force) {
                _status.value = UpdateStatus.Failed(null, "Could not look for updates: ${e.message ?: "no answer"}. Try again later.")
            } else if (_status.value == UpdateStatus.Checking) {
                _status.value = UpdateStatus.Idle
            }
        }
        withContext(Dispatchers.IO) {
            AppUpdates.stale(dir(context).listFiles()?.toList().orEmpty(), BuildConfig.VERSION_CODE).forEach { it.delete() }
        }
    }

    /** Downloads (or reuses) [release], checks it and hands it to Android's installer. */
    suspend fun install(context: Context, api: ApiClient, release: AndroidReleaseDto) = installing.withLock {
        if (!api.onServer(release.url)) {
            _status.value = UpdateStatus.Failed(release, "This update is not on your EchoLedger server, so it was not downloaded.")
            return@withLock
        }
        if (!context.packageManager.canRequestPackageInstalls()) {
            _status.value = UpdateStatus.NeedsPermission(release)
            return@withLock
        }
        val file = File(dir(context), AppUpdates.fileName(release))
        try {
            val ready = withContext(Dispatchers.IO) { AppUpdates.matches(file, release) }
            if (!ready) download(api, release, file)
            if (!withContext(Dispatchers.IO) { AppUpdates.matches(file, release) }) {
                file.delete()
                _status.value =
                    UpdateStatus.Failed(release, "The download did not match the published update, so it was not installed. Try again.")
                return@withLock
            }
            _status.value = UpdateStatus.Installing(release)
            withContext(Dispatchers.IO) { commit(context, file) }
        } catch (e: Exception) {
            _status.value = UpdateStatus.Failed(release, "The update could not be downloaded: ${e.message ?: "no answer"}. Try again.")
        }
    }

    /** Android's installer answered (see [UpdateInstallReceiver]): success replaces this app, so only failures remain. */
    internal fun finished(release: AndroidReleaseDto?, message: String?) {
        _status.value = if (message == null) UpdateStatus.Idle else UpdateStatus.Failed(release, message)
    }

    internal fun current(): AndroidReleaseDto? = when (val s = _status.value) {
        is UpdateStatus.Available -> s.release
        is UpdateStatus.Downloading -> s.release
        is UpdateStatus.NeedsPermission -> s.release
        is UpdateStatus.Installing -> s.release
        is UpdateStatus.Failed -> s.release
        else -> null
    }

    /** Tests start from nothing. */
    internal fun reset() {
        _status.value = UpdateStatus.Idle
    }

    private fun dir(context: Context): File = File(context.cacheDir, "updates").apply { mkdirs() }

    private suspend fun download(api: ApiClient, release: AndroidReleaseDto, file: File) = withContext(Dispatchers.IO) {
        val part = File(file.parentFile, "${file.name}.part")
        _status.value = UpdateStatus.Downloading(release, 0)
        // A public file: no token. Redirects may only stay on the same server.
        api.http.newCall(Request.Builder().url(release.url).get().build()).execute().use { resp ->
            if (!api.onServer(resp.request.url.toString())) throw IOException("the server sent the download elsewhere")
            if (!resp.isSuccessful) throw IOException("the server answered ${resp.code}")
            var done = 0L
            var shown = 0
            part.outputStream().use { out ->
                resp.body.byteStream().use { input ->
                    val buffer = ByteArray(64 * 1024)
                    while (true) {
                        val read = input.read(buffer)
                        if (read < 0) break
                        done += read
                        if (done > release.bytes) throw IOException("the file is larger than the published update")
                        out.write(buffer, 0, read)
                        val percent = AppUpdates.percent(done, release.bytes)
                        if (percent != shown) {
                            shown = percent
                            _status.value = UpdateStatus.Downloading(release, percent)
                        }
                    }
                }
            }
        }
        file.delete()
        if (!part.renameTo(file)) throw IOException("the download could not be saved")
    }

    private fun commit(context: Context, file: File) {
        val installer = context.packageManager.packageInstaller
        val params = PackageInstaller.SessionParams(PackageInstaller.SessionParams.MODE_FULL_INSTALL).apply {
            setAppPackageName(context.packageName)
            setSize(file.length())
            if (Build.VERSION.SDK_INT >= 31) setRequireUserAction(PackageInstaller.SessionParams.USER_ACTION_NOT_REQUIRED)
        }
        val id = installer.createSession(params)
        installer.openSession(id).use { session ->
            session.openWrite("base.apk", 0, file.length()).use { out ->
                file.inputStream().use { it.copyTo(out) }
                session.fsync(out)
            }
            val intent = Intent(context, UpdateInstallReceiver::class.java).setAction(UpdateInstallReceiver.ACTION)
            // Mutable: the installer adds its status to it. Explicit, so nothing else can receive it.
            val flags = PendingIntent.FLAG_UPDATE_CURRENT or (if (Build.VERSION.SDK_INT >= 31) PendingIntent.FLAG_MUTABLE else 0)
            session.commit(PendingIntent.getBroadcast(context, id, intent, flags).intentSender)
        }
    }
}

/** The 12-hour look for a newer website build (see [SelfUpdate.schedule]). */
class UpdateCheckWorker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {
    override suspend fun doWork(): Result {
        val api = (applicationContext as? EchoLedgerApp)?.graph?.api() ?: return Result.success()
        SelfUpdate.check(applicationContext, api, force = false)
        return Result.success()
    }
}
