package com.echoledger.mobile.notify

import android.Manifest
import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import androidx.core.content.ContextCompat
import com.echoledger.mobile.MainActivity
import com.echoledger.mobile.R
import com.echoledger.mobile.calls.DecisionTimeoutWorker
import com.echoledger.mobile.calls.SaveDecisionReceiver
import com.echoledger.mobile.core.SourceApp
import com.echoledger.mobile.data.LocalRecording
import com.echoledger.mobile.data.SegmentDto
import com.echoledger.mobile.recording.RecordingService
import com.echoledger.mobile.ui.formatDuration

/**
 * Notification channels and builders. Calm by default: the recording/armed notifications are low-importance and
 * silent; the post-call "Save this recording?" prompt appears only after a call and never makes sound.
 */
object Notifications {
    const val CHANNEL_RECORDING = "recording"

    /** Heads-up channel for the post-call question. Replaces the old "decisions" channel, whose importance could not be raised. */
    const val CHANNEL_DECISIONS = "decisions_headsup"
    private const val OLD_CHANNEL_DECISIONS = "decisions"
    const val CHANNEL_STATUS = "status"
    const val ID_FOREGROUND = 1001

    fun createChannels(context: Context) {
        val manager = context.getSystemService(NotificationManager::class.java)
        manager.deleteNotificationChannel(OLD_CHANNEL_DECISIONS)
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
                    NotificationManager.IMPORTANCE_HIGH
                ).apply {
                    description = context.getString(R.string.channel_decisions_desc)
                    setSound(null, null)
                    enableVibration(false)
                    lockscreenVisibility = Notification.VISIBILITY_PRIVATE
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

    /** The ongoing recording: Pause / Resume, Mark (a bookmark at this moment, B3) and Stop. */
    fun recording(context: Context, title: String, detail: String, paused: Boolean = false): Notification =
        NotificationCompat.Builder(context, CHANNEL_RECORDING)
            .setSmallIcon(R.drawable.ic_stat_rec)
            .setContentTitle(if (paused) "Paused · $title" else title)
            .setContentText(detail)
            .setOngoing(true)
            .setOnlyAlertOnce(true)
            .setCategory(NotificationCompat.CATEGORY_SERVICE)
            .setForegroundServiceBehavior(NotificationCompat.FOREGROUND_SERVICE_IMMEDIATE)
            .setContentIntent(openApp(context))
            .addAction(
                0,
                if (paused) "Resume" else "Pause",
                serviceAction(context, if (paused) RecordingService.ACTION_RESUME else RecordingService.ACTION_PAUSE, 14)
            )
            .addAction(0, "Mark", serviceAction(context, RecordingService.ACTION_MARK, 15))
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

    /**
     * Post-call question with Keep / Discard (A2.7): a heads-up notification with the same preview as the desktop
     * review card. Never shown before or during a call. With "Keep waiting" it stays until the user decides.
     */
    fun showSaveDecision(context: Context, rec: LocalRecording, clientName: String?, decisionTimeout: String) {
        fun decision(keep: Boolean, code: Int) = PendingIntent.getBroadcast(
            context,
            code,
            Intent(context, SaveDecisionReceiver::class.java)
                .setAction(if (keep) SaveDecisionReceiver.ACTION_KEEP else SaveDecisionReceiver.ACTION_DISCARD)
                .putExtra(SaveDecisionReceiver.EXTRA_ID, rec.id),
            PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT
        )
        val text = decisionText(rec, clientName)
        val public = NotificationCompat.Builder(context, CHANNEL_DECISIONS)
            .setSmallIcon(R.drawable.ic_stat_rec)
            .setContentTitle("Save this recording?")
            .build()
        val builder = NotificationCompat.Builder(context, CHANNEL_DECISIONS)
            .setSmallIcon(R.drawable.ic_stat_rec)
            .setContentTitle("Save this recording?")
            .setContentText(text)
            .setCategory(NotificationCompat.CATEGORY_REMINDER)
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setVisibility(NotificationCompat.VISIBILITY_PRIVATE)
            .setPublicVersion(public)
            .setOnlyAlertOnce(true)
            .setSilent(true)
            .setAutoCancel(true)
            .setContentIntent(openPending(context))
            .addAction(0, "Keep", decision(true, rec.id.hashCode()))
            .addAction(0, "Discard", decision(false, rec.id.hashCode() + 1))
        firstCaptionLine(rec.liveSegments)?.let { line ->
            builder.setStyle(NotificationCompat.BigTextStyle().bigText("$text\n$line"))
        }
        if (decisionTimeout != "wait") builder.setTimeoutAfter(DecisionTimeoutWorker.WINDOW_SECONDS * 1000)
        // Android 13+ needs the notification permission; without it the question is in the app's Record tab only.
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU &&
            ContextCompat.checkSelfPermission(context, Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED
        ) {
            return
        }
        runCatching { NotificationManagerCompat.from(context).notify(rec.id.hashCode(), builder.build()) }
    }

    /** "WhatsApp call · 4:12 · Acme" or "Voice note · 0:42". */
    fun decisionText(rec: LocalRecording, clientName: String?): String {
        val app = SourceApp.from(rec.sourceApp)
        val what = if (app == SourceApp.VOICE_NOTE) app.label else "${app.label} call"
        return listOfNotNull(what, formatDuration(rec.durationMs), clientName?.takeIf { it.isNotBlank() }).joinToString(" · ")
    }

    /** The first caption turn as "Me: …" or "Client: …", at most 140 characters. */
    fun firstCaptionLine(segments: List<SegmentDto>): String? {
        val first = segments.filter { it.text.isNotBlank() }.minWithOrNull(compareBy({ it.startMs }, { it.channel })) ?: return null
        val line = "${first.speaker}: ${first.text.trim()}"
        return if (line.length <= 140) line else line.take(139) + "…"
    }

    /** Opens the app on the Record tab's list of recordings waiting for a decision. */
    private fun openPending(context: Context): PendingIntent = PendingIntent.getActivity(
        context,
        1,
        Intent(context, MainActivity::class.java)
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_SINGLE_TOP)
            .putExtra(MainActivity.EXTRA_OPEN, MainActivity.OPEN_PENDING),
        PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT
    )

    fun cancelDecision(context: Context, recordingId: String) {
        NotificationManagerCompat.from(context).cancel(recordingId.hashCode())
    }
}
