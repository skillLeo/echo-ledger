package com.echoledger.mobile.notify

import android.annotation.SuppressLint
import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.content.res.Resources
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import androidx.core.graphics.drawable.toBitmap
import com.echoledger.mobile.EchoLedgerApp
import com.echoledger.mobile.MainActivity
import com.echoledger.mobile.R
import com.echoledger.mobile.calls.CallChoice
import com.echoledger.mobile.calls.CallChoice.Pick
import com.echoledger.mobile.calls.DecisionTimeoutWorker
import com.echoledger.mobile.calls.SaveDecisionReceiver
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.data.LocalRecording
import com.echoledger.mobile.ui.v2.live.callCardTitle
import com.echoledger.mobile.ui.v2.shell.sourceName

/**
 * After a call, a quiet notification instead of the big card (v2.1 UX-13, behind [CallChoice.NOTIFY_FLAG]; it replaces
 * section 3 of PLAN_CALL_CHOICE on the phone):
 * - channel "After a call": it pops up but makes no sound and no vibration;
 * - "Ali · call saved", "WhatsApp · 2:38", [Transcribe] [Delete]; once, the first time, the expanded text says how to keep
 *   the audio only; after the same choice 3 times in a row, one "Always transcribe" or "Always audio only" button, once;
 * - a swipe keeps the audio only and nothing else shows; nobody choosing keeps the audio only after [CallChoice.NOTICE_MS]
 *   (it stays in the shade that long), and the call then simply waits in the Library;
 * - Transcribe: the same notification says "Transcribing…" (the upload starts at once, expedited work), then
 *   "Transcript ready"; Delete: "Deleted · Undo" for 8 s, and the file goes only after that;
 * - back-to-back calls: one notification for all of them, "3 calls saved · Transcribe all";
 * - notifications not allowed (or this channel off): nothing shows, and the call is kept as audio only.
 * The call waits ("pending_decision") while its notification is in the shade, so every answer is applied on the phone
 * before anything is sent.
 */
object AfterCallNotice {
    const val CHANNEL = "after_call"

    /** The one notification for back-to-back calls. */
    const val GROUPED_ID = 7_443

    /** Every after-call notification is in this group, so a quiet copy beside the small bar never pops up. */
    private const val GROUP = "com.echoledger.mobile.AFTER_CALL"

    private const val PREFS = "after_call_notice"
    private const val TRANSCRIBING = "transcribing"

    /** The channel "After a call": high importance (it pops up), silent. */
    fun channel(words: Resources): NotificationChannel =
        NotificationChannel(CHANNEL, words.getString(R.string.c_after_call), NotificationManager.IMPORTANCE_HIGH).apply {
            description = words.getString(R.string.c_after_call_d)
            setSound(null, null)
            enableVibration(false)
            lockscreenVisibility = Notification.VISIBILITY_PRIVATE
        }

    /**
     * The channel exists only while the after-call flag is on ([Notifications.createChannels] removes it when the flag is
     * off): made here, just before the first notification, when the flag has just come on.
     */
    fun ensureChannel(context: Context) {
        val manager = context.getSystemService(NotificationManager::class.java) ?: return
        if (manager.getNotificationChannel(CHANNEL) == null) manager.createNotificationChannel(channel(AppWords.resources(context)))
    }

    /** The after-call flag is [on] or off: its channel is made, or removed so it never shows in Android's settings. */
    fun followFlag(context: Context, on: Boolean) {
        val manager = context.getSystemService(NotificationManager::class.java) ?: return
        if (on) ensureChannel(context) else manager.deleteNotificationChannel(CHANNEL)
    }

    /** Whether the notification can show: allowed on Android 13+, and neither the app's notifications nor this channel off. */
    fun canShow(context: Context): Boolean {
        if (!Notifications.canPost(context)) return false
        val manager = NotificationManagerCompat.from(context)
        if (!manager.areNotificationsEnabled()) return false
        return manager.getNotificationChannel(CHANNEL)?.importance != NotificationManager.IMPORTANCE_NONE
    }

    /** The waiting calls whose notification is in the shade: this call and the ones before it still waiting. */
    private fun waiting(context: Context, rec: LocalRecording): List<LocalRecording> {
        val graph = (context.applicationContext as EchoLedgerApp).graph
        val prefs = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val before = prefs.getStringSet(NOTICED, emptySet()).orEmpty()
        val still = (before + rec.id).mapNotNull { id -> if (id == rec.id) rec else graph.recordings.get(id) }
            .filter { it.state == WAITING && !it.restoredDelete }
            .sortedBy { it.endedAt }
        prefs.edit().putStringSet(NOTICED, still.map { it.id }.toSet()).apply()
        return still
    }

    /**
     * Asks about [rec] (waiting) in the shade: its own notification, or, with other calls still waiting there, one for
     * all of them. [quiet]: the small bar shows too, so the notification goes in the shade without popping up.
     * The settings change it makes (the hint shown, "Always" offered) are returned for the caller to store.
     */
    @SuppressLint("MissingPermission") // canShow() first
    fun ask(context: Context, rec: LocalRecording, settings: AppSettings, quiet: Boolean = false): ((AppSettings) -> AppSettings)? {
        if (!canShow(context)) return null
        val calls = waiting(context, rec)
        val manager = NotificationManagerCompat.from(context)
        if (calls.size > 1) {
            calls.forEach { manager.cancel(it.id.hashCode()) }
            runCatching { manager.notify(GROUPED_ID, grouped(context, calls, quiet)) }
            return null
        }
        val words = AppWords.resources(context)
        val hint = !settings.callChoiceHintShown
        val always = CallChoice.alwaysOffer(settings)?.takeIf { !hint }
        runCatching { manager.notify(rec.id.hashCode(), question(context, words, rec, hint, always, quiet)) }
        return when {
            hint -> { s -> s.copy(callChoiceHintShown = true) }
            always != null -> { s -> s.copy(callChoiceAlwaysOffered = true) }
            else -> null
        }
    }

    private fun question(
        context: Context,
        words: Resources,
        rec: LocalRecording,
        hint: Boolean,
        always: Pick?,
        quiet: Boolean
    ): Notification {
        fun said(text: String) = NoticeWords.drawn(words, text)
        val title = said(words.getString(R.string.cc_ac_saved, callCardTitle(words, rec)))
        val meta = said(words.getString(R.string.n_call_line, sourceName(words, rec.sourceApp), length(rec)))
        val builder = base(context, quiet)
            .setContentTitle(title)
            .setContentText(meta)
            .setLargeIcon(sourceLogo(context, rec))
            .setCategory(NotificationCompat.CATEGORY_REMINDER)
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setPublicVersion(base(context, quiet).setContentTitle(said(words.getString(R.string.n_call_saved))).build())
            .setContentIntent(open(context, MainActivity.OPEN_PENDING, 1))
            // A swipe keeps the audio only; nothing else shows.
            .setDeleteIntent(swiped(context, listOf(rec.id), 6))
            .setTimeoutAfter(CallChoice.NOTICE_MS)
            .addAction(
                0,
                said(words.getString(R.string.cc_transcribe)),
                action(context, SaveDecisionReceiver.ACTION_AC_TRANSCRIBE, listOf(rec.id), 7)
            )
            .addAction(
                0,
                said(words.getString(R.string.cc_delete)),
                action(context, SaveDecisionReceiver.ACTION_DISCARD, listOf(rec.id), 1)
            )
        if (always != null) {
            val label = if (always == Pick.TRANSCRIBE) R.string.cc_ac_always_transcribe else R.string.cc_ac_always_audio
            builder.addAction(
                0,
                said(words.getString(label)),
                action(context, SaveDecisionReceiver.ACTION_AC_ALWAYS, listOf(rec.id), 8) {
                    putExtra(SaveDecisionReceiver.EXTRA_PICK, always.wire)
                }
            )
        }
        if (hint) builder.setStyle(NotificationCompat.BigTextStyle().bigText("$meta\n${said(words.getString(R.string.cc_ac_hint))}"))
        return builder.build()
    }

    private fun grouped(context: Context, calls: List<LocalRecording>, quiet: Boolean): Notification {
        val words = AppWords.resources(context)
        fun said(text: String) = NoticeWords.drawn(words, text)
        val ids = calls.map { it.id }
        val title = said(words.getString(R.string.cc_ac_grouped, calls.size))
        val lines = NotificationCompat.InboxStyle()
        calls.forEach { lines.addLine(said(words.getString(R.string.n_call_line, callCardTitle(words, it), length(it)))) }
        return base(context, quiet)
            .setContentTitle(title)
            .setContentText(said(calls.joinToString(", ") { callCardTitle(words, it) }))
            .setStyle(lines)
            .setNumber(calls.size)
            .setCategory(NotificationCompat.CATEGORY_REMINDER)
            .setPriority(NotificationCompat.PRIORITY_HIGH)
            .setPublicVersion(base(context, quiet).setContentTitle(title).build())
            .setContentIntent(open(context, MainActivity.OPEN_PENDING, 1))
            .setDeleteIntent(swiped(context, ids, 9))
            .setTimeoutAfter(CallChoice.NOTICE_MS)
            .addAction(
                0,
                said(words.getString(R.string.cc_ac_transcribe_all)),
                action(context, SaveDecisionReceiver.ACTION_AC_TRANSCRIBE_ALL, ids, 10)
            )
            .build()
    }

    /** "Transcribing…" in place of the call's notification, until "Transcript ready" takes its place. */
    @SuppressLint("MissingPermission") // canShow() first
    fun transcribing(context: Context, rec: LocalRecording) {
        markTranscribing(context, rec.id, true)
        if (!canShow(context)) return
        val words = AppWords.resources(context)
        fun said(text: String) = NoticeWords.drawn(words, text)
        val title = said(words.getString(R.string.cc_done_transcribing))
        val notification = base(context, quiet = true)
            .setContentTitle(title)
            .setContentText(said(words.getString(R.string.n_call_line, callCardTitle(words, rec), length(rec))))
            .setLargeIcon(sourceLogo(context, rec))
            .setCategory(NotificationCompat.CATEGORY_PROGRESS)
            .setPublicVersion(base(context, quiet = true).setContentTitle(title).build())
            .setContentIntent(open(context, MainActivity.OPEN_PENDING, 1))
            .setProgress(0, 0, true)
            .setTimeoutAfter(CallChoice.NOTICE_MS)
            .build()
        runCatching { NotificationManagerCompat.from(context).notify(rec.id.hashCode(), notification) }
    }

    /** Back-to-back calls all sent to be transcribed: their one notification says so. */
    @SuppressLint("MissingPermission") // canShow() first
    fun transcribingAll(context: Context, recs: List<LocalRecording>) {
        recs.forEach { markTranscribing(context, it.id, true) }
        // The group's one notification follows them all: it ends once the last one is ready ([groupMemberDone]).
        synchronized(groupLock) {
            context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit()
                .putStringSet(GROUP_LEFT, recs.map { it.id }.toSet())
                .putInt(GROUP_READY, 0)
                .commit()
        }
        if (!canShow(context)) return
        val words = AppWords.resources(context)
        val title = NoticeWords.drawn(words, words.getString(R.string.cc_done_transcribing))
        val notification = base(context, quiet = true)
            .setContentTitle(title)
            .setContentText(NoticeWords.drawn(words, words.getString(R.string.cc_ac_grouped, recs.size)))
            .setCategory(NotificationCompat.CATEGORY_PROGRESS)
            .setPublicVersion(base(context, quiet = true).setContentTitle(title).build())
            .setContentIntent(open(context, MainActivity.OPEN_LIBRARY, 42))
            .setProgress(0, 0, true)
            .setTimeoutAfter(CallChoice.NOTICE_MS)
            .build()
        runCatching { NotificationManagerCompat.from(context).notify(GROUPED_ID, notification) }
    }

    /** Whether the call [id] was sent from its notification to be transcribed (its "Transcript ready" goes in its place). */
    fun isTranscribing(context: Context, id: String): Boolean =
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).getStringSet(TRANSCRIBING, emptySet()).orEmpty().contains(id)

    fun markTranscribing(context: Context, id: String, on: Boolean) {
        val prefs = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val now = prefs.getStringSet(TRANSCRIBING, emptySet()).orEmpty()
        prefs.edit().putStringSet(TRANSCRIBING, if (on) (now + id).toList().takeLast(KEEP).toSet() else now - id).apply()
    }

    /** The upload of [localId] reached the server as [sessionId]: once its transcript is ready, the notification says so. */
    fun uploaded(context: Context, localId: String, sessionId: String) {
        if (isTranscribing(context, localId)) ReadyWatchWorker.enqueue(context, sessionId, notice = localId)
    }

    fun cancelGrouped(context: Context) {
        NotificationManagerCompat.from(context).cancel(GROUPED_ID)
    }

    /** Whether the call [id] is one of the back-to-back calls sent with Transcribe all, still being made. */
    fun inGroup(context: Context, id: String): Boolean =
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).getStringSet(GROUP_LEFT, emptySet()).orEmpty().contains(id)

    /**
     * One of the calls sent with Transcribe all is done ([ready]: its transcript is there). Once the last one is, the
     * group's "Transcribing…" becomes "3 transcripts ready" in place (or goes, when none is), never left up for the hour.
     */
    @SuppressLint("MissingPermission") // canShow() first
    fun groupMemberDone(context: Context, id: String, ready: Boolean) {
        // One at a time: the ready watchers run side by side, and each one's count must reach the next.
        val readyCount = synchronized(groupLock) {
            val prefs = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            val left = prefs.getStringSet(GROUP_LEFT, emptySet()).orEmpty()
            if (id !in left) return
            val count = prefs.getInt(GROUP_READY, 0) + if (ready) 1 else 0
            prefs.edit().putStringSet(GROUP_LEFT, left - id).putInt(GROUP_READY, count).commit()
            if ((left - id).isNotEmpty()) return
            count
        }
        if (readyCount == 0 || !canShow(context)) {
            cancelGrouped(context)
            return
        }
        val words = AppWords.resources(context)
        val title = NoticeWords.drawn(words, words.getQuantityString(R.plurals.cc_ac_ready_many, readyCount, readyCount))
        val notification = base(context, quiet = true)
            .setContentTitle(title)
            .setCategory(NotificationCompat.CATEGORY_STATUS)
            .setPublicVersion(base(context, quiet = true).setContentTitle(title).build())
            .setContentIntent(open(context, MainActivity.OPEN_LIBRARY, 42))
            .setTimeoutAfter(CallChoice.NOTICE_MS)
            .build()
        runCatching { NotificationManagerCompat.from(context).notify(GROUPED_ID, notification) }
    }

    /**
     * The swipe's answer, with when the notification was posted: Android sends it on a swipe, on Clear all, and also
     * when the hour is over ([CallChoice.NOTICE_MS]). Only a swipe or a Clear all within the hour counts as choosing
     * audio only ([com.echoledger.mobile.calls.AfterCallActions.swiped]).
     */
    private fun swiped(context: Context, ids: List<String>, offset: Int): PendingIntent =
        action(context, SaveDecisionReceiver.ACTION_AC_SWIPED, ids, offset) {
            // The time since boot, as the other end reads it: a clock set back never makes the hour a swipe.
            putExtra(SaveDecisionReceiver.EXTRA_POSTED_AT, android.os.SystemClock.elapsedRealtime())
        }

    /**
     * The call's app's own icon, as the notification's large icon ("(WA) EchoLedger · now"); none when the app is not on
     * this phone (a SIM call, an app used elsewhere): then the small icon alone, never a placeholder.
     */
    private fun sourceLogo(context: Context, rec: LocalRecording): android.graphics.Bitmap? {
        val app = com.echoledger.mobile.core.SourceApp.from(rec.sourceApp)
        val px = (LARGE_ICON_DP * context.resources.displayMetrics.density).toInt()
        return com.echoledger.mobile.core.CallTargets.KNOWN.filterValues { it == app }.keys.firstNotNullOfOrNull { pkg ->
            runCatching { context.packageManager.getApplicationIcon(pkg).toBitmap(px, px) }.getOrNull()
        }
    }

    /** Forgets every call this notification knew (tests). */
    @androidx.annotation.VisibleForTesting
    internal fun resetForTest(context: Context) {
        context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit().clear().commit()
    }

    /**
     * The after-call look: private, in the group, never a sound or vibration (the channel has none). [quiet] (the small
     * bar shows, or the notification only changes): in the shade without popping up. Not NotificationCompat's setSilent
     * for the question: it silences by "alert the summary only", which also stops the pop-up.
     */
    private fun base(context: Context, quiet: Boolean) = NotificationCompat.Builder(context, CHANNEL)
        .setSmallIcon(R.drawable.ic_stat_rec)
        .setVisibility(NotificationCompat.VISIBILITY_PRIVATE)
        .setOnlyAlertOnce(true)
        .setAutoCancel(true)
        .setGroup(GROUP)
        .setGroupAlertBehavior(if (quiet) NotificationCompat.GROUP_ALERT_SUMMARY else NotificationCompat.GROUP_ALERT_ALL)
        .apply { if (quiet) setSilent(true) }

    private fun length(rec: LocalRecording) = com.echoledger.mobile.ui.formatDuration(rec.durationMs).ifEmpty { "0:00" }

    private fun open(context: Context, what: String, code: Int): PendingIntent = PendingIntent.getActivity(
        context,
        code,
        Intent(context, MainActivity::class.java)
            .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_SINGLE_TOP)
            .putExtra(MainActivity.EXTRA_OPEN, what),
        PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT
    )

    private fun action(context: Context, what: String, ids: List<String>, offset: Int, extras: Intent.() -> Unit = {}): PendingIntent =
        PendingIntent.getBroadcast(
            context,
            ids.joinToString(",").hashCode() + offset,
            Intent(context, SaveDecisionReceiver::class.java)
                .setAction(what)
                .putExtra(SaveDecisionReceiver.EXTRA_ID, ids.first())
                .putExtra(SaveDecisionReceiver.EXTRA_IDS, ids.toTypedArray())
                .apply(extras),
            PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT
        )

    /** The backstop: nobody choosing keeps the audio only once the notification has left the shade. */
    fun scheduleKeep(context: Context, id: String) = DecisionTimeoutWorker.schedule(context, id, CallChoice.NOTICE_MS)

    private const val NOTICED = "noticed"
    private const val GROUP_LEFT = "group_left"
    private val groupLock = Any()
    private const val GROUP_READY = "group_ready"

    /** The large icon's size, as Android's own notification templates draw it. */
    private const val LARGE_ICON_DP = 48
    private const val KEEP = 50
    private const val WAITING = "pending_decision"
}
