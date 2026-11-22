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
import com.echoledger.mobile.core.NotifyCategory
import com.echoledger.mobile.core.ReadyProblem
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

    /** An empty "Status" channel of earlier versions: nothing was ever posted to it (removed in v1.3). */
    const val OLD_CHANNEL_STATUS = "status"

    /** Activity of earlier versions in one channel; since v1.5 each kind has its own ([NotifyCategory]). */
    const val CHANNEL_ACTIVITY = NotifyCategory.OLD_ACTIVITY_CHANNEL

    /** Call capture & readiness (critical): recording calls stopped working (since v1.5, Phase 1C). */
    val CHANNEL_READINESS = NotifyCategory.READINESS.channelId
    const val ID_FOREGROUND = 1001

    /**
     * One channel per kind of notification ([NotifyCategory], since v1.5), plus the question after a call, which is part
     * of Recording but pops up. The old "Transcripts ready" channel is gone: what it carried has a channel of its own.
     */
    fun createChannels(context: Context) {
        val manager = context.getSystemService(NotificationManager::class.java)
        val oldActivityBlocked = manager.getNotificationChannel(CHANNEL_ACTIVITY)?.importance == NotificationManager.IMPORTANCE_NONE
        manager.deleteNotificationChannel(OLD_CHANNEL_DECISIONS)
        manager.deleteNotificationChannel(OLD_CHANNEL_STATUS)
        manager.deleteNotificationChannel(CHANNEL_ACTIVITY)
        val serverKinds = setOf(
            NotifyCategory.TRANSCRIPTION,
            NotifyCategory.ENHANCEMENT,
            NotifyCategory.UPLOADS,
            NotifyCategory.SHARING,
            NotifyCategory.ACCOUNT,
            NotifyCategory.USAGE,
            NotifyCategory.OTHER
        )
        // In the app language (re-made when it changes: Android keeps each channel's settings, only its name changes).
        val words = com.echoledger.mobile.notify.AppWords.resources(context)
        val channels = NotifyCategory.entries.map { category ->
            val (name, line) = com.echoledger.mobile.notify.NoticeWords.channel(words, category)
            val importance = when (category.level) {
                NotifyCategory.Level.LOW -> NotificationManager.IMPORTANCE_LOW
                NotifyCategory.Level.DEFAULT -> NotificationManager.IMPORTANCE_DEFAULT
                NotifyCategory.Level.HIGH -> NotificationManager.IMPORTANCE_HIGH
            }
            // Someone who had switched off the old channel in Android's settings keeps those kinds off.
            val blocked = oldActivityBlocked && category in serverKinds
            NotificationChannel(
                category.channelId,
                name,
                if (blocked) NotificationManager.IMPORTANCE_NONE else importance
            ).apply {
                description = line
                lockscreenVisibility = Notification.VISIBILITY_PRIVATE
                if (category == NotifyCategory.RECORDING || category == NotifyCategory.LIVE_TRANSLATE) setShowBadge(false)
            }
        } + NotificationChannel(
            CHANNEL_DECISIONS,
            words.getString(R.string.c_decisions),
            NotificationManager.IMPORTANCE_HIGH
        ).apply {
            description = words.getString(R.string.c_decisions_d)
            setSound(null, null)
            enableVibration(false)
            lockscreenVisibility = Notification.VISIBILITY_PRIVATE
        }
        manager.createNotificationChannels(channels)
    }

    /** Whether Android shows [category] (the person may have switched its channel off in Android's own settings). */
    fun channelOn(context: Context, category: NotifyCategory): Boolean {
        val manager = context.getSystemService(NotificationManager::class.java) ?: return false
        if (!manager.areNotificationsEnabled()) return false
        return manager.getNotificationChannel(category.channelId)?.importance != NotificationManager.IMPORTANCE_NONE
    }

    /** Android's own page of one channel, where sound, vibration and pop-up are set. */
    fun channelSettings(context: Context, category: NotifyCategory): Intent =
        Intent(android.provider.Settings.ACTION_CHANNEL_NOTIFICATION_SETTINGS)
            .putExtra(android.provider.Settings.EXTRA_APP_PACKAGE, context.packageName)
            .putExtra(android.provider.Settings.EXTRA_CHANNEL_ID, category.channelId)
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)

    private fun canPost(context: Context): Boolean = Build.VERSION.SDK_INT < Build.VERSION_CODES.TIRAMISU ||
        ContextCompat.checkSelfPermission(context, Manifest.permission.POST_NOTIFICATIONS) == PackageManager.PERMISSION_GRANTED

    /** Settings → Notifications → Test: one notification of that kind, marked as a test. */
    @SuppressLint("MissingPermission") // checked by canPost() first
    fun test(context: Context, category: NotifyCategory): Boolean {
        if (!canPost(context)) return false
        val (title, body) = com.echoledger.mobile.notify.NoticeWords.test(
            com.echoledger.mobile.notify.AppWords.resources(context),
            category
        )
        val builder = NotificationCompat.Builder(context, category.channelId)
            .setSmallIcon(R.drawable.ic_stat_rec)
            .setContentTitle(title)
            .setContentText(body)
            .setSubText(com.echoledger.mobile.notify.NoticeWords.say(context, "Test"))
            .setAutoCancel(true)
            .setContentIntent(openApp(context))
            .setTimeoutAfter(TEST_TIMEOUT_MS)
        return runCatching { NotificationManagerCompat.from(context).notify(TEST_ID + category.ordinal, builder.build()) }.isSuccess
    }

    private const val TEST_ID = 7_500
    private const val TEST_TIMEOUT_MS = 60_000L
    private const val READINESS_ID = 7_440

    /**
     * The critical alert (since v1.5, Phase 1C A3): recording calls just stopped working. It pops up with sound, comes
     * through in quiet hours, says what broke, and its button is the one-tap fix (it opens the Ready check).
     */
    @SuppressLint("MissingPermission") // checked by canPost() first
    fun readinessAlert(context: Context, problem: ReadyProblem) {
        if (!canPost(context)) return
        // In the app language: Setup & health's words for the problem (English: the Ready check's own).
        val (title, text, fix) = com.echoledger.mobile.notify.NoticeWords.readiness(context, problem)
        val builder = NotificationCompat.Builder(context, CHANNEL_READINESS)
            .setSmallIcon(R.drawable.ic_stat_rec)
            .setContentTitle(title)
            .setContentText(text)
            .setStyle(NotificationCompat.BigTextStyle().bigText(text))
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setCategory(NotificationCompat.CATEGORY_ERROR)
            .setOnlyAlertOnce(true)
            .setAutoCancel(true)
            .setContentIntent(openReady(context))
        problem.fix?.let { builder.addAction(0, fix ?: it.label, openReady(context, it.name)) }
        runCatching { NotificationManagerCompat.from(context).notify(READINESS_ID, builder.build()) }
    }

    /** A5: a kept call recorded only your voice; says why, and opens the Ready check. */
    @SuppressLint("MissingPermission") // checked by canPost() first
    fun onlyYourVoice(context: Context, why: String) {
        if (!canPost(context)) return
        val builder = NotificationCompat.Builder(context, CHANNEL_READINESS)
            .setSmallIcon(R.drawable.ic_stat_rec)
            .setContentTitle(com.echoledger.mobile.notify.NoticeWords.say(context, "Only your voice was recorded"))
            .setContentText(com.echoledger.mobile.notify.NoticeWords.say(context, why))
            .setStyle(NotificationCompat.BigTextStyle().bigText(com.echoledger.mobile.notify.NoticeWords.say(context, why)))
            .setAutoCancel(true)
            .setContentIntent(openReady(context))
        runCatching { NotificationManagerCompat.from(context).notify(ONE_SIDED_ID, builder.build()) }
    }

    private const val ONE_SIDED_ID = 7_441

    fun cancelReadinessAlert(context: Context) {
        NotificationManagerCompat.from(context).cancel(READINESS_ID)
    }

    /** Opens the Ready check; with [fix], the app runs that fix as it opens. */
    private fun openReady(context: Context, fix: String? = null): PendingIntent = PendingIntent.getActivity(
        context,
        if (fix == null) 40 else 41,
        Intent(context, MainActivity::class.java)
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_SINGLE_TOP)
            .putExtra(MainActivity.EXTRA_OPEN, MainActivity.OPEN_READY)
            .apply { fix?.let { putExtra(MainActivity.EXTRA_FIX, it) } },
        PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT
    )

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
        RecordingService.intent(context, action, from = "notification"),
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
            setTextViewText(R.id.rec_title, com.echoledger.mobile.notify.NoticeWords.say(context, if (paused) "Paused · $title" else title))
            setTextViewText(R.id.rec_detail, com.echoledger.mobile.notify.NoticeWords.say(context, detail))
            setTextViewText(R.id.rec_pause, com.echoledger.mobile.notify.NoticeWords.say(context, if (paused) "Resume" else "Pause"))
            setOnClickPendingIntent(
                R.id.rec_pause,
                serviceAction(context, if (paused) RecordingService.ACTION_RESUME else RecordingService.ACTION_PAUSE, 14)
            )
            setTextViewText(R.id.rec_stop, com.echoledger.mobile.notify.NoticeWords.say(context, "Stop"))
            setTextViewText(R.id.rec_captions, com.echoledger.mobile.notify.NoticeWords.say(context, "Captions"))
            setOnClickPendingIntent(R.id.rec_stop, serviceAction(context, RecordingService.ACTION_STOP, 11))
            setOnClickPendingIntent(R.id.rec_captions, serviceAction(context, RecordingService.ACTION_CAPTIONS, 16))
            setTextViewText(
                R.id.rec_discard,
                com.echoledger.mobile.notify.AppWords.resources(context).let {
                    com.echoledger.mobile.notify.NoticeWords.drawn(
                        it,
                        it.getString(if (discarding) R.string.cc_undo else R.string.cc_pill_stop_delete)
                    )
                }
            )
            setOnClickPendingIntent(
                R.id.rec_discard,
                serviceAction(context, if (discarding) RecordingService.ACTION_UNDO_DISCARD else RecordingService.ACTION_DISCARD_NOW, 17)
            )
        }
        // This view is the app's own layout: its text follows Settings › Appearance's text size (Android's own templates
        // can't; they keep the phone's).
        // In sp, so the phone's own font size still applies when the shade draws it (a later font change too).
        recordingTextSize(context)?.let { scale ->
            recordingTextSp(context).forEach { (id, sp) ->
                buttons.setTextViewTextSize(
                    id,
                    android.util.TypedValue.COMPLEX_UNIT_SP,
                    sp * scale
                )
            }
        }
        // In Urdu the view reads right to left on any phone: the title and detail at the right, Pause on the right.
        if (com.echoledger.mobile.notify.NoticeWords.isUrdu(com.echoledger.mobile.notify.AppWords.resources(context))) {
            buttons.setInt(R.id.rec_root, "setLayoutDirection", android.view.View.LAYOUT_DIRECTION_RTL)
        }
        return NotificationCompat.Builder(context, CHANNEL_RECORDING)
            .setSmallIcon(R.drawable.ic_stat_rec)
            .setContentTitle(com.echoledger.mobile.notify.NoticeWords.say(context, if (paused) "Paused · $title" else title))
            .setContentText(com.echoledger.mobile.notify.NoticeWords.say(context, detail))
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
                com.echoledger.mobile.notify.NoticeWords.say(context, if (paused) "Resume" else "Pause"),
                serviceAction(context, if (paused) RecordingService.ACTION_RESUME else RecordingService.ACTION_PAUSE, 14)
            )
            .addAction(
                0,
                com.echoledger.mobile.notify.NoticeWords.say(context, "Stop"),
                serviceAction(context, RecordingService.ACTION_STOP, 11)
            )
            .addAction(
                0,
                com.echoledger.mobile.notify.AppWords.resources(context).let {
                    com.echoledger.mobile.notify.NoticeWords.drawn(
                        it,
                        it.getString(if (discarding) R.string.cc_undo else R.string.cc_pill_stop_delete)
                    )
                },
                serviceAction(context, if (discarding) RecordingService.ACTION_UNDO_DISCARD else RecordingService.ACTION_DISCARD_NOW, 17)
            )
            .build()
    }

    /** The recording view's texts, each scaled by the person's text size. */
    internal val RECORDING_TEXT_IDS =
        listOf(R.id.rec_title, R.id.rec_detail, R.id.rec_pause, R.id.rec_stop, R.id.rec_captions, R.id.rec_discard)

    private class TextSp(val config: android.content.res.Configuration, val sp: Map<Int, Float>)

    @Volatile private var textSp: TextSp? = null

    /**
     * The recording view's text sizes in sp as its layout's styles give them (the notification styles): the layout
     * inflated once per configuration and read, never assumed; at font size 1, as Android adds the phone's font size again.
     */
    internal fun recordingTextSp(context: Context): Map<Int, Float> {
        val config = context.resources.configuration
        textSp?.takeIf { it.config == config }?.let { return it.sp }
        // Read at the phone's font size 1 (sp = px / density there; Android scales larger fonts non-linearly).
        val plain = context.createConfigurationContext(android.content.res.Configuration(config).apply { fontScale = 1f })
        val view = android.view.LayoutInflater.from(plain).inflate(R.layout.notification_recording_actions, null)
        val density = plain.resources.displayMetrics.density
        val sp = RECORDING_TEXT_IDS.associateWith { view.findViewById<android.widget.TextView>(it).textSize / density }
        textSp = TextSp(android.content.res.Configuration(config), sp)
        return sp
    }

    /** Settings › Appearance's text size for the recording view; null at the phone's own size (the layout's styles stay). */
    internal fun recordingTextSize(context: Context): Float? {
        val size = (context.applicationContext as? com.echoledger.mobile.EchoLedgerApp)?.graph?.liveSettings?.value?.textSize
        return com.echoledger.mobile.ui.v2.shell.AppLook.scale(size ?: "system").takeIf { it != 1f }
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
    fun armed(context: Context, ready: Boolean = true, askAfterCall: Boolean = false): Notification =
        NotificationCompat.Builder(context, CHANNEL_RECORDING)
            .setSmallIcon(R.drawable.ic_stat_rec)
            .setContentTitle(
                com.echoledger.mobile.notify.NoticeWords.say(
                    context,
                    if (ready) "Ready to record WhatsApp calls" else "Call capture isn't ready — tap to fix"
                )
            )
            .setContentText(
                com.echoledger.mobile.notify.NoticeWords.say(
                    context,
                    if (ready) {
                        // Since v1.6: what happens after a call follows the After a call setting (Keep automatically by default).
                        if (askAfterCall) {
                            "Calls are recorded automatically; you decide after each call."
                        } else {
                            "Calls are recorded and kept automatically."
                        }
                    } else {
                        "Start Shizuku (or run the setup command), then open Setup."
                    }
                )
            )
            .setOngoing(true)
            .setOnlyAlertOnce(true)
            .setSilent(true)
            .setCategory(NotificationCompat.CATEGORY_SERVICE)
            .setForegroundServiceBehavior(NotificationCompat.FOREGROUND_SERVICE_IMMEDIATE)
            .setContentIntent(openApp(context))
            .addAction(
                0,
                com.echoledger.mobile.notify.NoticeWords.say(context, "Record now"),
                serviceAction(context, RecordingService.ACTION_TOGGLE, 12)
            )
            .addAction(
                0,
                com.echoledger.mobile.notify.NoticeWords.say(context, "Turn off"),
                serviceAction(context, RecordingService.ACTION_DISARM, 13)
            )
            .build()

    /**
     * The after-call question when the card cannot be drawn (A2.7; words since 2.0.5 from docs/v2/copy/call-choice.md):
     * Transcribe, Save audio only and Delete, with the same preview as the desktop review card. Never shown before or
     * during a call. Nobody choosing keeps the audio only after [DecisionTimeoutWorker.WINDOW_SECONDS].
     */
    fun showSaveDecision(context: Context, rec: LocalRecording, clientName: String?) {
        fun decision(keep: Boolean, transcribe: Boolean?, code: Int) = PendingIntent.getBroadcast(
            context,
            code,
            Intent(context, SaveDecisionReceiver::class.java)
                .setAction(if (keep) SaveDecisionReceiver.ACTION_KEEP else SaveDecisionReceiver.ACTION_DISCARD)
                .putExtra(SaveDecisionReceiver.EXTRA_ID, rec.id)
                .apply { transcribe?.let { putExtra(SaveDecisionReceiver.EXTRA_TRANSCRIBE, it) } },
            PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT
        )
        // In the app language; the person's own words (names, the project) go in as they are.
        val words = com.echoledger.mobile.notify.AppWords.resources(context)
        val text = com.echoledger.mobile.notify.NoticeWords.drawn(words, decisionText(words, rec, clientName))
        fun choice(id: Int) = com.echoledger.mobile.notify.NoticeWords.drawn(words, words.getString(id))
        val public = NotificationCompat.Builder(context, CHANNEL_DECISIONS)
            .setSmallIcon(R.drawable.ic_stat_rec)
            .setContentTitle(com.echoledger.mobile.notify.NoticeWords.say(context, "Save this recording?"))
            .build()
        val builder = NotificationCompat.Builder(context, CHANNEL_DECISIONS)
            .setSmallIcon(R.drawable.ic_stat_rec)
            .setContentTitle(com.echoledger.mobile.notify.NoticeWords.say(context, "Save this recording?"))
            .setContentText(text)
            .setCategory(NotificationCompat.CATEGORY_REMINDER)
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setVisibility(NotificationCompat.VISIBILITY_PRIVATE)
            .setPublicVersion(public)
            .setOnlyAlertOnce(true)
            .setSilent(true)
            .setAutoCancel(true)
            .setContentIntent(openPending(context))
            .addAction(0, choice(R.string.cc_transcribe), decision(true, true, rec.id.hashCode()))
            .addAction(0, choice(R.string.cc_save_audio_only), decision(true, false, rec.id.hashCode() + 3))
            .addAction(0, choice(R.string.cc_delete), decision(false, null, rec.id.hashCode() + 1))
        firstCaptionLine(words, rec.liveSegments)?.let { line ->
            builder.setStyle(NotificationCompat.BigTextStyle().bigText("$text\n$line"))
        }
        builder.setTimeoutAfter(DecisionTimeoutWorker.WINDOW_SECONDS * 1000)
        // Android 13+ needs the notification permission; without it the question is in the app's Record tab only.
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU &&
            ContextCompat.checkSelfPermission(context, Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED
        ) {
            return
        }
        runCatching { NotificationManagerCompat.from(context).notify(rec.id.hashCode(), builder.build()) }
    }

    /**
     * "Saved · Call with Omar · 4:12" with Undo (since v1.5, a call kept automatically, when the chip cannot be shown):
     * quiet, gone after the same 6 s the upload waits.
     */
    fun savedWithUndo(context: Context, rec: LocalRecording) {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU &&
            ContextCompat.checkSelfPermission(context, Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED
        ) {
            return
        }
        val undo = PendingIntent.getBroadcast(
            context,
            rec.id.hashCode() + 2,
            Intent(context, SaveDecisionReceiver::class.java)
                .setAction(SaveDecisionReceiver.ACTION_UNDO)
                .putExtra(SaveDecisionReceiver.EXTRA_ID, rec.id),
            PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT
        )
        val duration = com.echoledger.mobile.ui.formatDuration(rec.durationMs).ifEmpty { "0:00" }
        val builder = NotificationCompat.Builder(context, CHANNEL_DECISIONS)
            .setSmallIcon(R.drawable.ic_stat_rec)
            // The title as the person named it, or the app's words for the call, in the app language.
            .setContentTitle(
                com.echoledger.mobile.notify.AppWords.resources(context).let {
                    com.echoledger.mobile.notify.NoticeWords.drawn(it, it.getString(R.string.n_saved, recordingTitle(it, rec), duration))
                }
            )
            .setCategory(NotificationCompat.CATEGORY_STATUS)
            .setPriority(NotificationCompat.PRIORITY_DEFAULT)
            .setVisibility(NotificationCompat.VISIBILITY_PRIVATE)
            .setPublicVersion(
                NotificationCompat.Builder(
                    context,
                    CHANNEL_DECISIONS
                ).setSmallIcon(
                    R.drawable.ic_stat_rec
                ).setContentTitle(com.echoledger.mobile.notify.NoticeWords.say(context, "Call saved")).build()
            )
            .setSilent(true)
            .setAutoCancel(true)
            .setTimeoutAfter(com.echoledger.mobile.overlay.DecisionPopup.DONE_MS)
            .setContentIntent(openApp(context))
            .addAction(0, com.echoledger.mobile.notify.NoticeWords.say(context, "Undo"), undo)
        runCatching { NotificationManagerCompat.from(context).notify(rec.id.hashCode(), builder.build()) }
    }

    /**
     * "Discarded · Call with Omar · 4:12" with Undo (since v1.8.1, bug P-006), in place of the question after its
     * Discard: quiet, gone after the 5 s the file waits for ([com.echoledger.mobile.calls.Decisions.discardLater]).
     */
    @SuppressLint("MissingPermission") // checked by canPost() first
    fun discardedWithUndo(context: Context, rec: LocalRecording) {
        if (!canPost(context)) return
        val undo = PendingIntent.getBroadcast(
            context,
            rec.id.hashCode() + 3,
            Intent(context, SaveDecisionReceiver::class.java)
                .setAction(SaveDecisionReceiver.ACTION_UNDO_DISCARD)
                .putExtra(SaveDecisionReceiver.EXTRA_ID, rec.id),
            PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT
        )
        val duration = com.echoledger.mobile.ui.formatDuration(rec.durationMs).ifEmpty { "0:00" }
        val builder = NotificationCompat.Builder(context, CHANNEL_DECISIONS)
            .setSmallIcon(R.drawable.ic_stat_rec)
            // The title as the person named it, or the app's words for the call, in the app language.
            .setContentTitle(
                com.echoledger.mobile.notify.AppWords.resources(context).let {
                    com.echoledger.mobile.notify.NoticeWords.drawn(
                        it,
                        it.getString(R.string.n_discarded, recordingTitle(it, rec), duration)
                    )
                }
            )
            .setCategory(NotificationCompat.CATEGORY_STATUS)
            .setPriority(NotificationCompat.PRIORITY_DEFAULT)
            .setVisibility(NotificationCompat.VISIBILITY_PRIVATE)
            .setPublicVersion(
                NotificationCompat.Builder(
                    context,
                    CHANNEL_DECISIONS
                ).setSmallIcon(
                    R.drawable.ic_stat_rec
                ).setContentTitle(
                    com.echoledger.mobile.notify.AppWords.resources(context).let {
                        com.echoledger.mobile.notify.NoticeWords.drawn(it, it.getString(R.string.cc_done_deleted))
                    }
                ).build()
            )
            .setSilent(true)
            .setAutoCancel(true)
            .setTimeoutAfter(com.echoledger.mobile.overlay.DecisionPopup.DISCARDED_MS)
            .setContentIntent(openApp(context))
            .addAction(0, com.echoledger.mobile.notify.NoticeWords.say(context, "Undo"), undo)
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
        val builder = NotificationCompat.Builder(context, CHANNEL_READINESS)
            .setSmallIcon(R.drawable.ic_stat_rec)
            .setContentTitle(com.echoledger.mobile.notify.NoticeWords.say(context, "Call capture isn't ready — tap to fix"))
            .setContentText(com.echoledger.mobile.notify.NoticeWords.say(context, text))
            .setStyle(NotificationCompat.BigTextStyle().bigText(com.echoledger.mobile.notify.NoticeWords.say(context, text)))
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
        // Each sentence in the app language (they are joined by a space, not said as one line).
        val first = if (callNotRecorded) "This call could not be recorded." else "The recording had no sound, so it was not kept."
        val text = listOfNotNull(first, reason?.let { "Reason: $it" }, "Open Setup, Call capture and restart the helper, then test a call.")
            .joinToString(" ") { com.echoledger.mobile.notify.NoticeWords.say(context, it) }
        val builder = NotificationCompat.Builder(context, CHANNEL_READINESS)
            .setSmallIcon(R.drawable.ic_stat_rec)
            .setContentTitle(
                com.echoledger.mobile.notify.NoticeWords.say(context, if (callNotRecorded) "Call not recorded" else "No audio was captured")
            )
            .setContentText(com.echoledger.mobile.notify.NoticeWords.say(context, text))
            .setStyle(NotificationCompat.BigTextStyle().bigText(com.echoledger.mobile.notify.NoticeWords.say(context, text)))
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setAutoCancel(true)
            .setContentIntent(openSetup(context))
        runCatching { NotificationManagerCompat.from(context).notify(NO_AUDIO_ID, builder.build()) }
    }

    private const val NO_AUDIO_ID = 7_431

    /**
     * The Phone app recorded a call, but the phone does not say which app made the file (since v1.8, 0.3): it waits for
     * the person in Settings, where they import it or not. Quiet; a tap opens that page.
     */
    fun phoneAppRecordingToCheck(context: Context, callAtMs: Long) {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU &&
            ContextCompat.checkSelfPermission(context, Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED
        ) {
            return
        }
        val time = java.time.format.DateTimeFormatter.ofPattern("HH:mm").withZone(java.time.ZoneId.systemDefault())
            .format(java.time.Instant.ofEpochMilli(callAtMs))
        val text = "Your call at $time: the phone doesn't say which app made its recording file. " +
            "Open Settings to import it if it is that call's."
        val open = PendingIntent.getActivity(
            context,
            TO_CHECK_ID,
            Intent(context, MainActivity::class.java)
                .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_SINGLE_TOP)
                .putExtra(MainActivity.EXTRA_OPEN, MainActivity.OPEN_SETTINGS_PREFIX + "calls"),
            PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT
        )
        val builder = NotificationCompat.Builder(context, CHANNEL_READINESS)
            .setSmallIcon(R.drawable.ic_stat_rec)
            .setContentTitle(com.echoledger.mobile.notify.NoticeWords.say(context, "Import the Phone app's recording?"))
            .setContentText(com.echoledger.mobile.notify.NoticeWords.say(context, text))
            .setStyle(NotificationCompat.BigTextStyle().bigText(com.echoledger.mobile.notify.NoticeWords.say(context, text)))
            .setAutoCancel(true)
            .setContentIntent(open)
        runCatching { NotificationManagerCompat.from(context).notify(TO_CHECK_ID, builder.build()) }
    }

    private const val TO_CHECK_ID = 7_437

    /**
     * Call recordings brought in from the Phone app without the after-call card (since v1.8 0.3: a call or recording was
     * live, or the calls ended a while ago): one quiet notification, adding up while it is still showing. They are kept:
     * on "Ask me" one nobody answers is kept 10 s later (the rule for an unanswered question), and none is asked here.
     */
    @SuppressLint("MissingPermission") // checked by canPost() first
    fun phoneAppImported(context: Context, count: Int) {
        if (!canPost(context) || count <= 0) return
        val prefs = context.getSharedPreferences("phone_app_recordings", Context.MODE_PRIVATE)
        val showing = runCatching {
            context.getSystemService(android.app.NotificationManager::class.java).activeNotifications.any { it.id == IMPORTED_ID }
        }.getOrDefault(false)
        val total = count + if (showing) prefs.getInt(IMPORTED_COUNT, 0) else 0
        prefs.edit().putInt(IMPORTED_COUNT, total).apply()
        val title = if (total == 1) "Call recording brought in" else "$total call recordings brought in"
        val text = "From the Phone app. They're in your Library."
        val builder = NotificationCompat.Builder(context, NotifyCategory.RECORDING.channelId)
            .setSmallIcon(R.drawable.ic_stat_rec)
            .setContentTitle(com.echoledger.mobile.notify.NoticeWords.say(context, title))
            .setContentText(com.echoledger.mobile.notify.NoticeWords.say(context, text))
            .setCategory(NotificationCompat.CATEGORY_STATUS)
            .setPriority(NotificationCompat.PRIORITY_LOW)
            .setVisibility(NotificationCompat.VISIBILITY_PRIVATE)
            .setSilent(true)
            .setAutoCancel(true)
            .setContentIntent(openApp(context))
        runCatching { NotificationManagerCompat.from(context).notify(IMPORTED_ID, builder.build()) }
    }

    private const val IMPORTED_ID = 7_438

    /**
     * The Phone app's two-sided copy of a call EchoLedger already kept with your voice only (since v1.8 0.3): it waits in
     * Record for the person, quietly, so the call is never transcribed twice by itself.
     */
    @SuppressLint("MissingPermission") // checked by canPost() first
    fun phoneAppCopyWaiting(context: Context, callAtMs: Long) {
        if (!canPost(context)) return
        val time = java.time.format.DateTimeFormatter.ofPattern("HH:mm").withZone(java.time.ZoneId.systemDefault())
            .format(java.time.Instant.ofEpochMilli(callAtMs))
        val text = "Your call at $time was kept with your voice only. Keep or discard the Phone app's copy in Record."
        val builder = NotificationCompat.Builder(context, NotifyCategory.RECORDING.channelId)
            .setSmallIcon(R.drawable.ic_stat_rec)
            .setContentTitle(com.echoledger.mobile.notify.NoticeWords.say(context, "The Phone app's copy has both sides"))
            .setContentText(com.echoledger.mobile.notify.NoticeWords.say(context, text))
            .setStyle(NotificationCompat.BigTextStyle().bigText(com.echoledger.mobile.notify.NoticeWords.say(context, text)))
            .setPriority(NotificationCompat.PRIORITY_LOW)
            .setVisibility(NotificationCompat.VISIBILITY_PRIVATE)
            .setSilent(true)
            .setAutoCancel(true)
            .setContentIntent(openApp(context))
        runCatching { NotificationManagerCompat.from(context).notify(COPY_WAITING_ID, builder.build()) }
    }

    private const val COPY_WAITING_ID = 7_439
    private const val IMPORTED_COUNT = "quiet_imports"

    /**
     * A SIM call EchoLedger could not record (the phone line gave no sound, or would not open), when the Phone app's own
     * recording is not on its way (since v1.8 0.3): said once, quietly, with what to do; never the helper advice, which
     * cannot help with a line the phone keeps for its own Phone app.
     */
    @SuppressLint("MissingPermission") // checked by canPost() first
    fun phoneLineNotRecorded(context: Context, text: String) {
        if (!canPost(context)) return
        val builder = NotificationCompat.Builder(context, NotifyCategory.RECORDING.channelId)
            .setSmallIcon(R.drawable.ic_stat_rec)
            .setContentTitle(com.echoledger.mobile.notify.NoticeWords.say(context, "Phone call not recorded"))
            .setContentText(com.echoledger.mobile.notify.NoticeWords.say(context, text))
            .setStyle(NotificationCompat.BigTextStyle().bigText(com.echoledger.mobile.notify.NoticeWords.say(context, text)))
            .setPriority(NotificationCompat.PRIORITY_DEFAULT)
            .setSilent(true)
            .setAutoCancel(true)
            .setContentIntent(
                PendingIntent.getActivity(
                    context,
                    NO_AUDIO_ID,
                    Intent(context, MainActivity::class.java)
                        .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_SINGLE_TOP)
                        .putExtra(MainActivity.EXTRA_OPEN, MainActivity.OPEN_SETTINGS_PREFIX + "calls"),
                    PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT
                )
            )
        runCatching { NotificationManagerCompat.from(context).notify(NO_AUDIO_ID, builder.build()) }
    }
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
            .setContentTitle(com.echoledger.mobile.notify.NoticeWords.say(context, "Record this phone call?"))
            .setContentText(com.echoledger.mobile.notify.NoticeWords.say(context, text))
            .setCategory(NotificationCompat.CATEGORY_CALL)
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setOnlyAlertOnce(true)
            .setSilent(true)
            .setAutoCancel(true)
            .addAction(
                0,
                com.echoledger.mobile.notify.NoticeWords.say(context, "Record"),
                serviceAction(context, RecordingService.ACTION_RECORD_PHONE_CALL, 16)
            )
        runCatching { NotificationManagerCompat.from(context).notify(ASK_PHONE_CALL_ID, builder.build()) }
    }

    fun cancelAskRecordPhoneCall(context: Context) {
        NotificationManagerCompat.from(context).cancel(ASK_PHONE_CALL_ID)
    }

    /** "WhatsApp call · 4:12 · Acme", "Call with Omar Khan · 2:05" or "Voice note · 0:42". */
    fun decisionText(rec: LocalRecording, clientName: String?): String = decisionText(null, rec, clientName)

    /**
     * The same in [res]'s language (null: English, as it always read). The caller's name, the app's name and the
     * project are the person's own words: they go in as they are.
     */
    fun decisionText(res: android.content.res.Resources?, rec: LocalRecording, clientName: String?): String {
        val app = SourceApp.from(rec.sourceApp)
        val caller = (rec.callerName ?: rec.callerNumber)?.takeIf { it.isNotBlank() && app != SourceApp.VOICE_NOTE }
        val what = when {
            caller != null -> res?.getString(R.string.n_call_with, caller) ?: "Call with $caller"
            app == SourceApp.VOICE_NOTE -> res?.getString(R.string.n_voice_note) ?: app.label
            app == SourceApp.PHONE_CALL -> res?.getString(R.string.n_phone_call) ?: app.label
            else -> res?.getString(R.string.n_app_call, app.label) ?: "${app.label} call"
        }
        return listOfNotNull(what, formatDuration(rec.durationMs), clientName?.takeIf { it.isNotBlank() }).joinToString(" · ")
    }

    /**
     * A recording's title for the saved and discarded lines: the person's own title as it is, else the app's words for
     * the call in [res]'s language ("Call with Omar", "WhatsApp call"), as [com.echoledger.mobile.overlay.DecisionPopup.title].
     */
    fun recordingTitle(res: android.content.res.Resources, rec: LocalRecording): String = rec.title?.takeIf { it.isNotBlank() }
        ?: rec.callerName?.takeIf { it.isNotBlank() }?.let { res.getString(R.string.n_call_with, it) }
        ?: rec.callerNumber?.takeIf { it.any(Char::isDigit) }?.let { res.getString(R.string.n_call_with, it) }
        ?: SourceApp.from(rec.sourceApp).let {
            if (it == SourceApp.PHONE_CALL) res.getString(R.string.n_phone_call) else res.getString(R.string.n_app_call, it.label)
        }

    /** The first caption turn as "Me: …" or "Client: …", at most 140 characters. */
    fun firstCaptionLine(segments: List<SegmentDto>): String? = firstCaptionLine(null, segments)

    /** The same with the speaker in [res]'s language; what was said stays as it was said. */
    fun firstCaptionLine(res: android.content.res.Resources?, segments: List<SegmentDto>): String? {
        val first = segments.filter { it.text.isNotBlank() }.minWithOrNull(compareBy({ it.startMs }, { it.channel })) ?: return null
        val speaker = when (first.speaker) {
            "Me" -> res?.getString(R.string.n_me)
            "Client" -> res?.getString(R.string.n_client)
            else -> null
        } ?: first.speaker
        val line = "$speaker: ${first.text.trim()}"
        return if (line.length <= 140) line else line.take(139) + "…"
    }

    private const val NOT_READY_ID = 7_432
    private const val AUTO_RECORD_OFF_ID = 7_434

    /**
     * Auto-record is on in Settings but Android would not let it start by itself (after an update or a restart): a
     * quiet notification whose tap turns it back on. The tap starts the service, never a screen.
     */
    fun autoRecordOff(context: Context) {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU &&
            ContextCompat.checkSelfPermission(context, Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED
        ) {
            return
        }
        val text = "Android stopped auto-record, so calls are not being recorded. Tap to turn it back on."
        val builder = NotificationCompat.Builder(context, CHANNEL_READINESS)
            .setSmallIcon(R.drawable.ic_stat_rec)
            .setContentTitle(com.echoledger.mobile.notify.NoticeWords.say(context, "Auto-record is off — tap to turn it on"))
            .setContentText(com.echoledger.mobile.notify.NoticeWords.say(context, text))
            .setStyle(NotificationCompat.BigTextStyle().bigText(com.echoledger.mobile.notify.NoticeWords.say(context, text)))
            .setSilent(true)
            .setOnlyAlertOnce(true)
            .setAutoCancel(true)
            .setContentIntent(serviceAction(context, RecordingService.ACTION_ARM, 30))
        runCatching { NotificationManagerCompat.from(context).notify(AUTO_RECORD_OFF_ID, builder.build()) }
    }

    fun cancelAutoRecordOff(context: Context) {
        NotificationManagerCompat.from(context).cancel(AUTO_RECORD_OFF_ID)
    }

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
    fun activity(
        context: Context,
        key: String,
        title: String,
        body: String,
        sessionId: String?,
        silent: Boolean = false,
        category: NotifyCategory = NotifyCategory.OTHER
    ) {
        val open = PendingIntent.getActivity(
            context,
            key.hashCode(),
            Intent(context, MainActivity::class.java)
                .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_SINGLE_TOP)
                .apply { sessionId?.let { putExtra(MainActivity.EXTRA_OPEN, MainActivity.OPEN_SESSION_PREFIX + it) } },
            PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT
        )
        val notification = NotificationCompat.Builder(context, category.channelId)
            .setSmallIcon(R.drawable.ic_stat_rec)
            .setContentTitle(com.echoledger.mobile.notify.NoticeWords.say(context, title))
            .setContentText(com.echoledger.mobile.notify.NoticeWords.say(context, body))
            .setStyle(NotificationCompat.BigTextStyle().bigText(com.echoledger.mobile.notify.NoticeWords.say(context, body)))
            .setContentIntent(open)
            .setSilent(silent)
            .setAutoCancel(true)
            .setVisibility(NotificationCompat.VISIBILITY_PRIVATE)
            .build()
        NotificationManagerCompat.from(context).notify(key.hashCode(), notification)
    }

    /**
     * A shared voice note on its way (since v1.6, P5.4): "Transcribing · part 2 of 3", silent, with a bar when the
     * percent is known; a tap opens the note. The "ready" notice (key `shared:<id>`) replaces it.
     */
    @SuppressLint("MissingPermission") // checked by canPost() first
    fun sharedProgress(context: Context, localId: String, text: String, percent: Int?) {
        if (!canPost(context)) return
        val open = PendingIntent.getActivity(
            context,
            ("shared:$localId").hashCode(),
            Intent(context, MainActivity::class.java)
                .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_SINGLE_TOP)
                .putExtra(MainActivity.EXTRA_OPEN, MainActivity.OPEN_SHARED_PREFIX + localId),
            PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT
        )
        val builder = NotificationCompat.Builder(context, NotifyCategory.UPLOADS.channelId)
            .setSmallIcon(R.drawable.ic_stat_rec)
            .setContentTitle(com.echoledger.mobile.notify.NoticeWords.say(context, "Shared voice note"))
            .setContentText(com.echoledger.mobile.notify.NoticeWords.say(context, text))
            .setSilent(true)
            .setOnlyAlertOnce(true)
            .setContentIntent(open)
            .setProgress(100, percent ?: 0, percent == null)
        runCatching { NotificationManagerCompat.from(context).notify(("shared:$localId").hashCode(), builder.build()) }
    }

    fun cancelDecision(context: Context, recordingId: String) {
        NotificationManagerCompat.from(context).cancel(recordingId.hashCode())
    }
}
