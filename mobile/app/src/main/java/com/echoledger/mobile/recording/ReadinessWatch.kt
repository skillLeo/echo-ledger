package com.echoledger.mobile.recording

import android.content.Context
import android.os.StatFs
import androidx.work.CoroutineWorker
import androidx.work.ExistingWorkPolicy
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import androidx.work.workDataOf
import com.echoledger.mobile.EchoLedgerApp
import com.echoledger.mobile.core.Readiness
import com.echoledger.mobile.core.ReadyFacts
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.notify.Notifications
import com.echoledger.mobile.privilege.HelperConnection
import com.echoledger.mobile.ui.capabilities
import java.util.concurrent.TimeUnit

/**
 * The Ready check on this phone (since v1.5, Phase 1C): reads the facts for [Readiness], and raises the critical alert
 * the moment recording calls stops working by itself (the helper was killed, the phone restarted, a permission was taken
 * away, the phone is full). Checked when the helper dies, after a restart or an update, every 15 minutes while
 * auto-record is on, and when the app starts. Each problem alerts once; the alert goes away when all is well again.
 */
object ReadinessWatch {
    private const val PREFS = "readiness"
    private const val KEY_HELPER_SEEN = "helper_seen"
    private const val KEY_ALERTED = "alerted"
    private const val KEY_CHIP = "chip"
    private const val SHIZUKU_PACKAGE = "moe.shizuku.privileged.api"
    private const val WORK = "readiness-check"
    private const val KEY_WHY = "why"
    private const val MB = 1024L * 1024L

    private fun prefs(context: Context) = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)

    /** The helper connected once on this phone: from now on a missing helper "stopped" rather than "was never set up". */
    fun helperSeen(context: Context) {
        prefs(context).edit().putBoolean(KEY_HELPER_SEEN, true).apply()
    }

    fun shizukuInstalled(context: Context): Boolean = runCatching { context.packageManager.getPackageInfo(SHIZUKU_PACKAGE, 0) }.isSuccess

    /** Opens the Shizuku app, where Start brings it back after a restart. */
    fun openShizuku(context: Context): Boolean = context.packageManager.getLaunchIntentForPackage(SHIZUKU_PACKAGE)
        ?.let { runCatching { context.startActivity(it.addFlags(android.content.Intent.FLAG_ACTIVITY_NEW_TASK)) }.isSuccess } ?: false

    fun facts(context: Context, settings: AppSettings, helper: HelperConnection): ReadyFacts {
        val state = helper.state.value
        val caps = context.capabilities(state.connected)
        val shizukuRunning = helper.shizukuAvailable()
        val shizukuAllowed = shizukuRunning && helper.shizukuPermissionGranted()
        val free = runCatching { StatFs(context.filesDir.path).availableBytes / MB }.getOrDefault(Long.MAX_VALUE)
        return ReadyFacts(
            capabilities = caps,
            autoRecordOn = settings.autoRecordArmed,
            shizukuInstalled = shizukuInstalled(context),
            shizukuRunning = shizukuRunning,
            shizukuAllowed = shizukuAllowed,
            // Installs from before v1.5 never wrote the flag: Shizuku allowed or auto-record on means it was set up.
            helperEverConnected = prefs(context).getBoolean(KEY_HELPER_SEEN, false) || shizukuAllowed || settings.autoRecordArmed,
            helperStale = state.stale,
            armState = helper.armState.value,
            signedIn = settings.paired,
            freeStorageMb = free,
            batteryExempt = CaptureHealth.ignoresBatteryOptimisation(context),
            lastCallOneSided = OneSidedCalls.last(context)
        )
    }

    /** The Ready check's last chip (`READY`, `NOT_READY`, `OFF`), or null when this phone records no calls (the widget's dot). */
    fun lastChip(context: Context): String? = prefs(context).getString(KEY_CHIP, null)

    /** Keeps the chip for the widget and redraws the widget when it changed (since v1.6, Phase 1C I4). */
    suspend fun remember(context: Context, readiness: Readiness) {
        val chip = readiness.chip.name.takeIf { readiness.chip != com.echoledger.mobile.core.ReadyChip.NONE }
        if (chip == lastChip(context)) return
        prefs(context).edit().putString(KEY_CHIP, chip).apply()
        com.echoledger.mobile.widget.WidgetStateStore.refresh(context)
    }

    /** Reads the facts, alerts once per new critical problem, and clears the alert when nothing critical is left. */
    suspend fun check(app: EchoLedgerApp, why: String): Readiness {
        val readiness = Readiness.evaluate(facts(app, app.graph.settings.current(), app.graph.helper))
        val critical = readiness.critical
        val alerted = prefs(app).getStringSet(KEY_ALERTED, emptySet()).orEmpty()
        val fresh = critical.firstOrNull { it.key !in alerted }
        if (fresh != null) {
            CaptureLog.add(app, "not ready", "$why: ${fresh.key}")
            if (com.echoledger.mobile.notify.NotifyGate.allowed(app, com.echoledger.mobile.core.NotifyCategory.READINESS)) {
                Notifications.readinessAlert(app, fresh)
            }
        }
        if (critical.isEmpty() && alerted.isNotEmpty()) {
            CaptureLog.add(app, "ready again", why)
            Notifications.cancelReadinessAlert(app)
        }
        prefs(app).edit().putStringSet(KEY_ALERTED, critical.map { it.key }.toSet()).apply()
        remember(app, readiness)
        return readiness
    }

    /** A check in [delayMs] (replacing one already waiting), so a helper that comes back by itself raises no alert. */
    fun checkSoon(context: Context, delayMs: Long, why: String) {
        runCatching {
            WorkManager.getInstance(context).enqueueUniqueWork(
                WORK,
                ExistingWorkPolicy.REPLACE,
                OneTimeWorkRequestBuilder<ReadinessWorker>()
                    .setInitialDelay(delayMs, TimeUnit.MILLISECONDS)
                    .setInputData(workDataOf(KEY_WHY to why))
                    .build()
            )
        }
    }

    class ReadinessWorker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {
        override suspend fun doWork(): Result {
            val app = applicationContext as? EchoLedgerApp ?: return Result.success()
            runCatching { check(app, inputData.getString(KEY_WHY) ?: "check") }
            return Result.success()
        }
    }
}
