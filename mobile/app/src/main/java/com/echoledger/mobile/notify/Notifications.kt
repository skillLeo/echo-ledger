package com.echoledger.mobile.notify

import android.Manifest
import android.annotation.SuppressLint
import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import android.widget.RemoteViews
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

    /** Activity centre (B18): transcript ready, upload failed, new device, monthly limit. */
    const val CHANNEL_ACTIVITY = "activity"
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
                },
                NotificationChannel(
                    CHANNEL_ACTIVITY,
                    context.getString(R.string.channel_activity),
                    NotificationManager.IMPORTANCE_DEFAULT
                ).apply {
                    description = context.getString(R.string.channel_activity_desc)
                    lockscreenVisibility = Notification.VISIBILITY_PRIVATE
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

    /**
     * The recording notification mirrors the control pill (since v1.3): "Recording call with Omar" with a running clock,
     * and Pause · Stop · Captions · Discard (four buttons, so they sit in a custom row; Android shows three actions).
     * A tap opens the Live view in the app. [discarding]: the 5-second undo of Discard now is running.
     */
    fun recording(
        context: Context,
        title: String,
        detail: String,
        paused: Boolean = false,
        startedAtMs: Long? = null,
        discarding: Boolean = false
    ): Notification {
        val buttons = RemoteViews(context.packageName, R.layout.notification_recording_actions).apply {
            setTextViewText(R.id.rec_title, if (paused) "Paused · $title" else title)
            setTextViewText(R.id.rec_detail, detail)
            setTextViewText(R.id.rec_pause, if (paused) "Resume" else "Pause")
            setOnClickPendingIntent(
                R.id.rec_pause,
                serviceAction(context, if (paused) RecordingService.ACTION_RESUME else RecordingService.ACTION_PAUSE, 14)
            )
            setOnClickPendingIntent(R.id.rec_stop, serviceAction(context, RecordingService.ACTION_STOP, 11))
            setOnClickPendingIntent(R.id.rec_captions, serviceAction(context, RecordingService.ACTION_CAPTIONS, 16))
            setTextViewText(R.id.rec_discard, if (discarding) "Undo discard" else "Discard")
            setOnClickPendingIntent(
                R.id.rec_discard,
                serviceAction(context, if (discarding) RecordingService.ACTION_UNDO_DISCARD else RecordingService.ACTION_DISCARD_NOW, 17)
            )
        }
        return NotificationCompat.Builder(context, CHANNEL_RECORDING)
            .setSmallIcon(R.drawable.ic_stat_rec)
            .setContentTitle(if (paused) "Paused · $title" else title)
            .setContentText(detail)
            .setStyle(NotificationCompat.DecoratedCustomViewStyle())
            .setCustomBigContentView(buttons)
            .setCustomHeadsUpContentView(buttons)
            .apply {
                // A running clock while recording (the system ticks it; nothing is re-posted every second).
                if (startedAtMs != null && !paused) {
                    setWhen(startedAtMs)
                    setShowWhen(true)
                    setUsesChronometer(true)
                }
            }
            .setOngoing(true)
            .setOnlyAlertOnce(true)
            .setCategory(NotificationCompat.CATEGORY_SERVICE)
            .setForegroundServiceBehavior(NotificationCompat.FOREGROUND_SERVICE_IMMEDIATE)
            .setContentIntent(openLive(context))
            .addAction(
                0,
                if (paused) "Resume" else "Pause",
                serviceAction(context, if (paused) RecordingService.ACTION_RESUME else RecordingService.ACTION_PAUSE, 14)
            )
            .addAction(0, "Stop", serviceAction(context, RecordingService.ACTION_STOP, 11))
            .addAction(
                0,
                if (discarding) "Undo discard" else "Discard",
                serviceAction(context, if (discarding) RecordingService.ACTION_UNDO_DISCARD else RecordingService.ACTION_DISCARD_NOW, 17)
            )
            .build()
    }

    /** Opens the app on the Live view of the recording in progress (the Record tab). */
    private fun openLive(context: Context): PendingIntent = PendingIntent.getActivity(
        context,
        18,
        Intent(context, MainActivity::class.java)
            .setFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_SINGLE_TOP)
            .putExtra(MainActivity.EXTRA_OPEN, MainActivity.OPEN_LIVE),
        PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_IMMUTABLE
    )

    /** Auto-record on; [ready] false: the capture helper is not running, so calls would not be recorded. */
    fun armed(context: Context, ready: Boolean = true): Notification = NotificationCompat.Builder(context, CHANNEL_RECORDING)
        .setSmallIcon(R.drawable.ic_stat_rec)
        .setContentTitle(if (ready) "Ready to record WhatsApp calls" else "Call capture isn't ready — tap to fix")
        .setContentText(
            if (ready) {
                "Calls are captured automatically; you decide after each call."
            } else {
                "Start Shizuku (or run the setup command), then open Setup."
            }
        )
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

    /** A call started while the capture helper is not running: say so during the call, with the way to fix it. */
    fun captureNotReady(context: Context) {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU &&
            ContextCompat.checkSelfPermission(context, Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED
        ) {
            return
        }
        val text = "This call is not being recorded because the capture helper is not running. Tap to open Setup and start it."
        val builder = NotificationCompat.Builder(context, CHANNEL_DECISIONS)
            .setSmallIcon(R.drawable.ic_stat_rec)
            .setContentTitle("Call capture isn't ready — tap to fix")
            .setContentText(text)
            .setStyle(NotificationCompat.BigTextStyle().bigText(text))
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setAutoCancel(true)
            .setContentIntent(openSetup(context))
        runCatching { NotificationManagerCompat.from(context).notify(NOT_READY_ID, builder.build()) }
    }

    /**
     * A recording that captured no sound is dropped: tell the person why, and where to look. [reason]: what the capture
     * helper reported, when it said anything (shown so the cause can be fixed).
     */
    fun noAudio(context: Context, reason: String? = null, callNotRecorded: Boolean = false) {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU &&
            ContextCompat.checkSelfPermission(context, Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED
        ) {
            return
        }
        val first = if (callNotRecorded) "This call could not be recorded." else "The recording had no sound, so it was not kept."
        val text = listOfNotNull(first, reason?.let { "Reason: $it" }, "Open Setup, Call capture and restart the helper, then test a call.")
            .joinToString(" ")
        val builder = NotificationCompat.Builder(context, CHANNEL_DECISIONS)
            .setSmallIcon(R.drawable.ic_stat_rec)
            .setContentTitle(if (callNotRecorded) "Call not recorded" else "No audio was captured")
            .setContentText(text)
            .setStyle(NotificationCompat.BigTextStyle().bigText(text))
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setAutoCancel(true)
            .setContentIntent(openSetup(context))
        runCatching { NotificationManagerCompat.from(context).notify(NO_AUDIO_ID, builder.build()) }
    }

    private const val NO_AUDIO_ID = 7_431
    private const val ASK_PHONE_CALL_ID = 7_433

    /** "Record phone calls: Ask each time" (since v1.2): a phone call started; offer to record it. Gone when the call ends. */
    fun askRecordPhoneCall(context: Context) {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU &&
            ContextCompat.checkSelfPermission(context, Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED
        ) {
            return
        }
        val text = "Tell the other person before you record."
        val builder = NotificationCompat.Builder(context, CHANNEL_DECISIONS)
            .setSmallIcon(R.drawable.ic_stat_rec)
            .setContentTitle("Record this phone call?")
            .setContentText(text)
            .setCategory(NotificationCompat.CATEGORY_CALL)
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setOnlyAlertOnce(true)
            .setSilent(true)
            .setAutoCancel(true)
            .addAction(0, "Record", serviceAction(context, RecordingService.ACTION_RECORD_PHONE_CALL, 16))
        runCatching { NotificationManagerCompat.from(context).notify(ASK_PHONE_CALL_ID, builder.build()) }
    }

    fun cancelAskRecordPhoneCall(context: Context) {
        NotificationManagerCompat.from(context).cancel(ASK_PHONE_CALL_ID)
    }

    /** "WhatsApp call · 4:12 · Acme", "Call with Omar Khan · 2:05" or "Voice note · 0:42". */
    fun decisionText(rec: LocalRecording, clientName: String?): String {
        val app = SourceApp.from(rec.sourceApp)
        val caller = (rec.callerName ?: rec.callerNumber)?.takeIf { it.isNotBlank() && app != SourceApp.VOICE_NOTE }
        val what = when {
            caller != null -> "Call with $caller"
            app == SourceApp.VOICE_NOTE || app == SourceApp.PHONE_CALL -> app.label
            else -> "${app.label} call"
        }
        return listOfNotNull(what, formatDuration(rec.durationMs), clientName?.takeIf { it.isNotBlank() }).joinToString(" · ")
    }

    /** The first caption turn as "Me: …" or "Client: …", at most 140 characters. */
    fun firstCaptionLine(segments: List<SegmentDto>): String? {
        val first = segments.filter { it.text.isNotBlank() }.minWithOrNull(compareBy({ it.startMs }, { it.channel })) ?: return null
        val line = "${first.speaker}: ${first.text.trim()}"
        return if (line.length <= 140) line else line.take(139) + "…"
    }

    private const val NOT_READY_ID = 7_432

    /** Opens Setup (call capture). */
    private fun openSetup(context: Context): PendingIntent = PendingIntent.getActivity(
        context,
        3,
        Intent(context, MainActivity::class.java)
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_SINGLE_TOP)
            .putExtra(MainActivity.EXTRA_OPEN, MainActivity.OPEN_SETUP),
        PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT
    )

    /** Opens the app on the Record tab's list of recordings waiting for a decision. */
    private fun openPending(context: Context): PendingIntent = PendingIntent.getActivity(
        context,
        1,
        Intent(context, MainActivity::class.java)
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_SINGLE_TOP)
            .putExtra(MainActivity.EXTRA_OPEN, MainActivity.OPEN_PENDING),
        PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT
    )

    /** One activity notification; tapping it opens [sessionId] (or the app). Hidden content on the lock screen. */
    @SuppressLint("MissingPermission") // checked by the caller (POST_NOTIFICATIONS)
    fun activity(context: Context, key: String, title: String, body: String, sessionId: String?) {
        val open = PendingIntent.getActivity(
            context,
            key.hashCode(),
            Intent(context, MainActivity::class.java)
                .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_SINGLE_TOP)
                .apply { sessionId?.let { putExtra(MainActivity.EXTRA_OPEN, MainActivity.OPEN_SESSION_PREFIX + it) } },
            PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT
        )
        val notification = NotificationCompat.Builder(context, CHANNEL_ACTIVITY)
            .setSmallIcon(R.drawable.ic_stat_rec)
            .setContentTitle(title)
            .setContentText(body)
            .setStyle(NotificationCompat.BigTextStyle().bigText(body))
            .setContentIntent(open)
            .setAutoCancel(true)
            .setVisibility(NotificationCompat.VISIBILITY_PRIVATE)
            .build()
        NotificationManagerCompat.from(context).notify(key.hashCode(), notification)
    }

    fun cancelDecision(context: Context, recordingId: String) {
        NotificationManagerCompat.from(context).cancel(recordingId.hashCode())
    }
}
