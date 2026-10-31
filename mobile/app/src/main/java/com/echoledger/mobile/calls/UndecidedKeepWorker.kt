package com.echoledger.mobile.calls

import android.content.Context
import androidx.work.CoroutineWorker
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import com.echoledger.mobile.EchoLedgerApp
import com.echoledger.mobile.data.LocalRecording
import java.time.Instant
import java.util.concurrent.TimeUnit

/**
 * "Undecided calls: keep automatically after 24 h / 3 days / never" (since 1.3.2): an hourly check keeps every call
 * still waiting for Keep or Discard once it is older than the setting, so a forgotten call is never lost.
 */
class UndecidedKeepWorker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {
    override suspend fun doWork(): Result {
        val graph = (applicationContext as EchoLedgerApp).graph
        val hours = graph.settings.current().undecidedKeepHours
        due(graph.recordings.pendingDecisions(), hours, Instant.now()).forEach {
            Decisions.apply(applicationContext, it, keep = true)
        }
        return Result.success()
    }

    companion object {
        const val WORK = "keep-undecided-calls"

        /** The choices offered in Settings: hours, 0 = never. */
        val CHOICES = listOf(24 to "After 24 hours (default)", 72 to "After 3 days", 0 to "Never")

        /** Ids of the waiting calls that started more than [hours] ago; none when [hours] is 0 (never). */
        fun due(waiting: List<LocalRecording>, hours: Int, now: Instant): List<String> {
            if (hours <= 0) return emptyList()
            val cutoff = now.minusSeconds(hours * 3_600L)
            return waiting.filter { it.state == "pending_decision" }
                .filter { runCatching { Instant.parse(it.startedAt) }.getOrNull()?.isBefore(cutoff) == true }
                .map { it.id }
        }

        /** The hourly check (kept if already there); never throws. */
        fun schedule(context: Context) {
            runCatching {
                WorkManager.getInstance(context).enqueueUniquePeriodicWork(
                    WORK,
                    ExistingPeriodicWorkPolicy.KEEP,
                    PeriodicWorkRequestBuilder<UndecidedKeepWorker>(1, TimeUnit.HOURS).build()
                )
            }
        }
    }
}
