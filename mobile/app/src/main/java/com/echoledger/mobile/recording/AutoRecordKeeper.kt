package com.echoledger.mobile.recording

import android.content.Context
import android.content.Intent
import android.os.Handler
import android.os.Looper
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import com.echoledger.mobile.EchoLedgerApp
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.notify.Notifications
import java.util.concurrent.TimeUnit
import kotlinx.coroutines.launch

/**
 * Keeps auto-record on when it is on in Settings (since v1.3): after an app update, a restart, or Android ending the
 * service, it is armed again without the app being opened. The update and restart broadcasts ([RearmReceiver]) are not
 * enough on their own (Android does not always deliver the update one), so every start of the app's process (the
 * capture helper reconnecting starts it) and a 15-minute check ([KeepArmedWorker], kept by Android across updates and
 * restarts while auto-record is on) ask too. A start from the background is allowed because the app has no battery
 * restrictions (Call capture setup asks for that); if Android refuses anyway, a quiet notification offers to turn it
 * back on. Never opens a screen.
 */
object AutoRecordKeeper {
    const val PERIODIC_WORK = "keep-auto-record"

    /** How long a process start waits before checking, so a Turn off that started the process lands first. */
    private const val START_DELAY_MS = 5_000L

    /** Arms auto-record when it should be on and is not; [why] goes to the capture log. Returns whether it asked. */
    fun ensure(
        context: Context,
        settings: AppSettings,
        why: String,
        start: (Intent) -> Unit = { context.startForegroundService(it) }
    ): Boolean {
        if (RecordingService.isArmed || !settings.autoRecordArmed) return false
        return runCatching { start(RecordingService.intent(context, RecordingService.ACTION_ARM, from = why)) }
            .onSuccess { CaptureLog.add(context, "auto-record back on", why) }
            .onFailure {
                CaptureLog.add(context, "auto-record could not restart", "$why: ${it.javaClass.simpleName}")
                Notifications.autoRecordOff(context)
            }
            .isSuccess
    }

    /**
     * Schedules [startCheck] for a few seconds after the app's process started (on the main looper, so nothing of it
     * outlives a test). Returns a way to call it off.
     */
    fun onAppStart(app: EchoLedgerApp, delayMs: Long = START_DELAY_MS): () -> Unit {
        val handler = Handler(Looper.getMainLooper())
        val check = Runnable { app.graph.scope.launch { startCheck(app) } }
        handler.postDelayed(check, delayMs)
        return { handler.removeCallbacks(check) }
    }

    /** The check at a process start; also brings back the 15-minute check when it went missing. Never throws. */
    suspend fun startCheck(app: EchoLedgerApp) {
        runCatching {
            val settings = app.graph.settings.current()
            ensure(app, settings, "app started")
            if (settings.autoRecordArmed) keepChecking(app)
        }
    }

    /** The 15-minute check while auto-record is on (kept if already there). */
    fun keepChecking(context: Context) {
        runCatching {
            WorkManager.getInstance(context).enqueueUniquePeriodicWork(
                PERIODIC_WORK,
                ExistingPeriodicWorkPolicy.KEEP,
                PeriodicWorkRequestBuilder<KeepArmedWorker>(15, TimeUnit.MINUTES).setInitialDelay(15, TimeUnit.MINUTES).build()
            )
        }
    }

    /** Auto-record was turned off: nothing to keep on any more. */
    fun stopChecking(context: Context) {
        runCatching { WorkManager.getInstance(context).cancelUniqueWork(PERIODIC_WORK) }
    }
}
