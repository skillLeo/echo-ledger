package com.echoledger.mobile.recording

import android.content.Context
import androidx.work.CoroutineWorker
import androidx.work.WorkerParameters
import com.echoledger.mobile.EchoLedgerApp

/**
 * The 15-minute [AutoRecordKeeper] check: arms auto-record again when it is on in Settings but not armed, and since v1.5
 * starts the capture helper again when the phone maker's system stopped it while Shizuku stays up.
 */
class KeepArmedWorker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {
    override suspend fun doWork(): Result {
        val graph = (applicationContext as EchoLedgerApp).graph
        val settings = graph.settings.current()
        when {
            settings.autoRecordArmed -> {
                graph.helper.reconnect()
                AutoRecordKeeper.ensure(applicationContext, settings, "periodic check")
                // Phase 1C: a permission taken away, a full phone or a helper that did not come back alerts here.
                runCatching { ReadinessWatch.check(applicationContext as EchoLedgerApp, "periodic check") }
            }

            // Off in Settings (an arm still saving "on" counts as on): nothing to keep on any more.
            !RecordingService.isArmed -> AutoRecordKeeper.stopChecking(applicationContext)
        }
        return Result.success()
    }
}
