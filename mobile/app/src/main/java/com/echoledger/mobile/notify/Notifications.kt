package com.echoledger.mobile.notify

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import com.echoledger.mobile.MainActivity
import com.echoledger.mobile.R
import com.echoledger.mobile.calls.SaveDecisionReceiver
import com.echoledger.mobile.recording.RecordingService

/**
 * Notification channels and builders. Calm by default: the recording/armed notifications are low-importance and
 * silent; the post-call "Save this recording?" prompt appears only after a call and never makes sound.
 */
object Notifications {
    const val CHANNEL_RECORDING = "recording"
    const val CHANNEL_DECISIONS = "decisions"
    const val CHANNEL_STATUS = "status"
    const val ID_FOREGROUND = 1001

    fun createChannels(context: Context) {
        val manager = context.getSystemService(NotificationManager::class.java)
        manager.createNotificationChannels(
            listOf(
                NotificationChannel(
                    CHANNEL_RECORDING,
                    context.getString(R.string.channel_recording),
                    NotificationManager.IMPORTANCE_LOW
                ).apply {
                    description = context.getString(R.string.channel_recording_desc)
                    setShowBadge(false)
                },
                NotificationChannel(
                    CHANNEL_DECISIONS,
                    context.getString(R.string.channel_decisions),
                    NotificationManager.IMPORTANCE_DEFAULT
                ).apply {
                    description = context.getString(R.string.channel_decisions_desc)
                    setSound(null, null)
                    enableVibration(false)
                },
                NotificationChannel(CHANNEL_STATUS, context.getString(R.string.channel_status), NotificationManager.IMPORTANCE_MIN).apply {
                    description = context.getString(R.string.channel_status_desc)
                }
            )
        )
    }

    private fun openApp(context: Context): PendingIntent = PendingIntent.getActivity(
        context,
        0,
        Intent(context, MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_SINGLE_TOP),
        PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT
    )

    /** Notification-action start of the recording service: a guaranteed background-start exemption for mic FGS. */
    fun serviceAction(context: Context, action: String, requestCode: Int): PendingIntent = PendingIntent.getForegroundService(
        context,
        requestCode,
        Intent(context, RecordingService::class.java).setAction(action),
        PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT
    )

    fun recording(context: Context, title: String, detail: String): Notification = NotificationCompat.Builder(context, CHANNEL_RECORDING)
        .setSmallIcon(R.drawable.ic_stat_rec)
        .setContentTitle(title)
        .setContentText(detail)
        .setOngoing(true)
        .setOnlyAlertOnce(true)
        .setCategory(NotificationCompat.CATEGORY_SERVICE)
        .setForegroundServiceBehavior(NotificationCompat.FOREGROUND_SERVICE_IMMEDIATE)
        .setContentIntent(openApp(context))
        .addAction(0, "Stop", serviceAction(context, RecordingService.ACTION_STOP, 11))
        .build()

    fun armed(context: Context): Notification = NotificationCompat.Builder(context, CHANNEL_RECORDING)
        .setSmallIcon(R.drawable.ic_stat_rec)
        .setContentTitle("Ready to record WhatsApp calls")
        .setContentText("Calls are captured automatically; you decide after each call.")
        .setOngoing(true)
        .setOnlyAlertOnce(true)
        .setSilent(true)
        .setCategory(NotificationCompat.CATEGORY_SERVICE)
        .setForegroundServiceBehavior(NotificationCompat.FOREGROUND_SERVICE_IMMEDIATE)
        .setContentIntent(openApp(context))
        .addAction(0, "Record now", serviceAction(context, RecordingService.ACTION_TOGGLE, 12))
        .addAction(0, "Turn off", serviceAction(context, RecordingService.ACTION_DISARM, 13))
        .build()

    /** Post-call decision with Keep / Discard actions (never shown before or during a call). */
    fun showSaveDecision(context: Context, recordingId: String, appLabel: String, durationText: String) {
        fun decision(keep: Boolean, code: Int) = PendingIntent.getBroadcast(
            context,
            code,
            Intent(context, SaveDecisionReceiver::class.java)
                .setAction(if (keep) SaveDecisionReceiver.ACTION_KEEP else SaveDecisionReceiver.ACTION_DISCARD)
                .putExtra(SaveDecisionReceiver.EXTRA_ID, recordingId),
            PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT
        )
        val notification = NotificationCompat.Builder(context, CHANNEL_DECISIONS)
            .setSmallIcon(R.drawable.ic_stat_rec)
            .setContentTitle("Save this recording?")
            .setContentText("$appLabel call · $durationText")
            .setAutoCancel(true)
            .setSilent(true)
            .setContentIntent(openApp(context))
            .addAction(0, "Keep", decision(true, recordingId.hashCode()))
            .addAction(0, "Discard", decision(false, recordingId.hashCode() + 1))
            .build()
        runCatching { NotificationManagerCompat.from(context).notify(recordingId.hashCode(), notification) }
    }

    fun cancelDecision(context: Context, recordingId: String) {
        NotificationManagerCompat.from(context).cancel(recordingId.hashCode())
    }
}
