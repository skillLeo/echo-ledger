package com.echoledger.mobile.recording

import android.content.Context
import androidx.work.CoroutineWorker
import androidx.work.WorkerParameters
import com.echoledger.mobile.EchoLedgerApp

/** The 15-minute [AutoRecordKeeper] check: arms auto-record again when it is on in Settings but not armed. */
class KeepArmedWorker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {
    override suspend fun doWork(): Result {
        val settings = (applicationContext as EchoLedgerApp).graph.settings.current()
        when {
            settings.autoRecordArmed -> AutoRecordKeeper.ensure(applicationContext, settings, "periodic check")

            // Off in Settings (an arm still saving "on" counts as on): nothing to keep on any more.
            !RecordingService.isArmed -> AutoRecordKeeper.stopChecking(applicationContext)
        }
        return Result.success()
    }
}
