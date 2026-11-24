package com.echoledger.mobile.calls

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.os.Handler
import android.os.Looper
import com.echoledger.mobile.EchoLedgerApp
import com.echoledger.mobile.data.LocalRecording
import com.echoledger.mobile.net.DiscardWorker
import com.echoledger.mobile.net.UploadWorker
import com.echoledger.mobile.notify.Notifications
import com.echoledger.mobile.overlay.DecisionPopup
import com.echoledger.mobile.widget.WidgetStateStore
import java.time.Instant
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.NonCancellable
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

/**
 * Handles the post-call Keep / Discard notification actions. Since v1.8.1 (bug P-006) Discard deletes nothing at once:
 * the notification turns into "Discarded · … · Undo" for 5 s, and the file goes only after that ([Decisions.discardLater]).
 */
class SaveDecisionReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        val id = intent.getStringExtra(EXTRA_ID) ?: return
        val pending = goAsync()
        CoroutineScope(Dispatchers.IO).launch {
            try {
                when (intent.action) {
                    // Transcribe or Save audio only (since 2.0.5); an older notification's Keep keeps it as before. From
                    // "Saved as audio" (v2.1), Transcribe changes the choice, which then does not count toward "Always".
                    ACTION_KEEP -> {
                        withContext(Dispatchers.Main) { DecisionPopup.forgetChoice(id) }
                        Decisions.apply(
                            context,
                            id,
                            keep = true,
                            transcribe = if (intent.hasExtra(EXTRA_TRANSCRIBE)) intent.getBooleanExtra(EXTRA_TRANSCRIBE, false) else null
                        )
                    }

                    // Undo on "Saved · …" is a Discard too: "Discarded · Undo" first, the file only after that.
                    ACTION_DISCARD, ACTION_UNDO -> discard(context, id)

                    ACTION_UNDO_DISCARD -> undoDiscard(context, id)

                    ACTION_UNDO_CHOICE -> undoChoice(context, id)

                    // v2.1 UX-13: the after-call notification.
                    ACTION_AC_TRANSCRIBE -> AfterCallActions.transcribe(context, listOf(id))

                    ACTION_AC_TRANSCRIBE_ALL -> AfterCallActions.transcribe(context, ids(intent, id))

                    ACTION_AC_SWIPED -> AfterCallActions.swiped(
                        context,
                        ids(intent, id),
                        postedAt = intent.getLongExtra(EXTRA_POSTED_AT, 0L).takeIf { it > 0 }
                    )

                    ACTION_AC_ALWAYS -> CallChoice.Pick.of(intent.getStringExtra(EXTRA_PICK))?.let {
                        AfterCallActions.always(context, id, it)
                    }
                }
            } finally {
                pending.finish()
            }
        }
    }

    private fun ids(intent: Intent, id: String): List<String> = intent.getStringArrayExtra(EXTRA_IDS)?.toList() ?: listOf(id)

    private suspend fun discard(context: Context, id: String) {
        // The chip for the same call goes too, so its ten seconds never keep a call on its way out.
        withContext(Dispatchers.Main) { if (DecisionPopup.showingFor == id) DecisionPopup.hide() }
        // The after-call notification's Delete (v2.1): "Deleted · Undo" for 8 s on its own channel, where the question
        // showed, and it ends a run of choices.
        val notifies = CallChoice.notifies((context.applicationContext as EchoLedgerApp).graph.settings.current())
        val lineMs = if (notifies) CallChoice.DELETED_MS else DecisionPopup.DISCARDED_MS
        val channel = if (notifies) com.echoledger.mobile.notify.AfterCallNotice.CHANNEL else Notifications.CHANNEL_DECISIONS
        // "Deleted · Undo" and the call on its way out in one uncancellable step, before anything that can wait: the
        // delete is never due without its Undo, and a slow widget never shortens it.
        withContext(NonCancellable) { Decisions.discardWithNotification(context, id, lineMs, channel) } ?: return
        if (notifies) AfterCallActions.counted(context, CallChoice.Pick.DELETE)
        Decisions.refreshWidget(context)
    }

    /**
     * Undo on "Discarded · …": the call comes back as after it ended: the question (kept after 10 s) for a call that
     * waited, "Saved" (its upload waits for it) for a call that was kept.
     */
    private suspend fun undoDiscard(context: Context, id: String) {
        Notifications.cancelDecision(context, id)
        withContext(Dispatchers.Main) { DecisionPopup.forgetChoice(id) }
        val back = Decisions.undoDiscard(context, id) ?: return
        val settings = (context.applicationContext as EchoLedgerApp).graph.settings.current()
        withContext(Dispatchers.Main) { PostCallPrompt.again(context, back, settings.themeMode) }
    }

    /**
     * Undo on "Transcribing…" or "Saved as audio" in the after-call notification (v2.1): the call waits for the question
     * again with nothing decided, its upload called off, and is asked about again. Nothing when it was sent meanwhile.
     */
    private suspend fun undoChoice(context: Context, id: String) {
        Notifications.cancelDecision(context, id)
        withContext(Dispatchers.Main) { DecisionPopup.forgetChoice(id) }
        val back = Decisions.undoKeep(context, id) ?: return
        val settings = (context.applicationContext as EchoLedgerApp).graph.settings.current()
        withContext(Dispatchers.Main) { PostCallPrompt.again(context, back, settings.themeMode) }
    }

    companion object {
        const val ACTION_KEEP = "com.echoledger.mobile.KEEP"
        const val ACTION_DISCARD = "com.echoledger.mobile.DISCARD"

        /** Since v1.5: Undo on "Saved · …" (a call kept automatically). */
        const val ACTION_UNDO = "com.echoledger.mobile.UNDO_KEEP"

        /** Since v1.8.1: Undo on "Discarded · …" (Discard in the notification). */
        const val ACTION_UNDO_DISCARD = "com.echoledger.mobile.UNDO_DISCARD"

        /** Since v2.1: Undo on "Transcribing…" or "Saved as audio" (a choice made on the after-call card). */
        const val ACTION_UNDO_CHOICE = "com.echoledger.mobile.UNDO_CHOICE"
        const val EXTRA_ID = "recording_id"

        /** With [ACTION_KEEP] (since 2.0.5): true for Transcribe, false for Save audio only. */
        const val EXTRA_TRANSCRIBE = "transcribe"

        /** v2.1 UX-13, the after-call notification: Transcribe, Transcribe all, a swipe, and "Always …" ([EXTRA_PICK]). */
        const val ACTION_AC_TRANSCRIBE = "com.echoledger.mobile.AC_TRANSCRIBE"
        const val ACTION_AC_TRANSCRIBE_ALL = "com.echoledger.mobile.AC_TRANSCRIBE_ALL"
        const val ACTION_AC_SWIPED = "com.echoledger.mobile.AC_SWIPED"
        const val ACTION_AC_ALWAYS = "com.echoledger.mobile.AC_ALWAYS"

        /** The calls one notification is about (back-to-back calls share one). */
        const val EXTRA_IDS = "recording_ids"
        const val EXTRA_PICK = "pick"

        /** With [ACTION_AC_SWIPED]: when its notification was posted (its hour running out is not a choice). */
        const val EXTRA_POSTED_AT = "posted_at"
    }
}

/** Keep → queue for upload; Discard → securely delete the local encrypted file. Shared by notification + in-app list. */
object Decisions {
    suspend fun apply(context: Context, recordingId: String, keep: Boolean, transcribe: Boolean? = null) {
        val graph = (context.applicationContext as EchoLedgerApp).graph
        Notifications.cancelDecision(context, recordingId)
        DecisionTimeoutWorker.cancel(context, recordingId)
        // A decision made anywhere (card, notification, Record tab) closes the floating card for that recording.
        if (DecisionPopup.showingFor == recordingId) Handler(Looper.getMainLooper()).post { DecisionPopup.hide() }
        // A call on its way out is kept only through its Undo, never by a late "keep after 10 s".
        if (keep && graph.recordings.get(recordingId)?.state == DISCARDING) return
        if (keep) {
            graph.recordings.update(recordingId) {
                it.copy(state = "queued", transcribe = transcribe ?: it.transcribe, restoredDelete = false, notDeletedYet = false)
            }
            UploadWorker.enqueue(context, recordingId)
        } else {
            // Nothing is uploaded; a server session that captions opened is deleted, so no transcript is ever made.
            graph.recordings.get(recordingId)?.serverSessionId?.let { DiscardWorker.enqueue(context, recordingId, it) }
            graph.recordings.delete(recordingId)
        }
        WidgetStateStore.refresh(context)
    }

    /**
     * The after-call card's Transcribe or Save audio only (since 2.0.5): kept, with its transcript or as audio only, and
     * sent after [uploadAfterMs] so the card's Undo ([undoKeep]) still finds it here; 0 sends it now. A call on its way
     * out stays so (only its own Undo brings it back). Null when the call is gone or is no longer the person's to decide.
     */
    suspend fun keepFor(
        context: Context,
        recordingId: String,
        transcribe: Boolean,
        uploadAfterMs: Long,
        /** False: the caller refreshes the widget itself, after its own steps that must not be cut short. */
        widget: Boolean = true
    ): LocalRecording? {
        val graph = (context.applicationContext as EchoLedgerApp).graph
        Notifications.cancelDecision(context, recordingId)
        DecisionTimeoutWorker.cancel(context, recordingId)
        val kept = graph.recordings.update(recordingId) {
            val decidable = it.state == WAITING || it.state == KEPT
            if (decidable) it.copy(state = KEPT, transcribe = transcribe, restoredDelete = false, notDeletedYet = false) else it
        }?.takeIf { it.state == KEPT } ?: return null
        if (uploadAfterMs >
            0
        ) {
            UploadWorker.enqueueDelayed(context, recordingId, uploadAfterMs)
        } else {
            UploadWorker.enqueue(context, recordingId)
        }
        if (widget) WidgetStateStore.refresh(context)
        return kept
    }

    /**
     * Undo on "Transcribing…" or "Saved as audio" (since 2.0.5): the call waits for the question again, its upload called
     * off and nothing decided. Null when it was sent meanwhile or is gone.
     */
    suspend fun undoKeep(context: Context, recordingId: String, widget: Boolean = true): LocalRecording? {
        val graph = (context.applicationContext as EchoLedgerApp).graph
        UploadWorker.cancel(context, recordingId)
        val back = graph.recordings.update(recordingId) {
            if (it.state == KEPT) it.copy(state = WAITING, transcribe = null) else it
        }?.takeIf { it.state == WAITING } ?: return null
        DecisionTimeoutWorker.schedule(context, recordingId)
        if (widget) WidgetStateStore.refresh(context)
        return back
    }

    /**
     * Discard after a call (since v1.8.1, bugs P-003 and P-006): the chip's or the notification's Discard, and Undo on
     * "Saved · …" (a call kept automatically). The call leaves every list now, stops counting down to Keep and its
     * upload is called off, but its file stays until "Discarded · Undo" is over and a little longer
     * ([DISCARD_AFTER_MS]), so Undo still finds it. [DiscardLaterWorker] deletes it then, also after the app was closed
     * or killed. Null when the call is gone, was sent already or is on its way out (nothing is discarded).
     */
    suspend fun discardLater(
        context: Context,
        recordingId: String,
        nowMs: Long = System.currentTimeMillis(),
        /** How long the file waits for Undo; longer for a screen whose toasts stay longer (accessibility). */
        afterMs: Long = DISCARD_AFTER_MS,
        /**
         * The Undo is on one of this process's screens (the after-call card, an in-app toast), not in a notification:
         * if the process is gone when the time is up, the call comes back to choose again instead ([finishDiscard]).
         */
        undoHere: Boolean = false,
        /** False: the caller refreshes the widget itself, after its own steps that must not be cut short. */
        widget: Boolean = true,
        /** True: the call's notification is already its "Deleted · Undo" ([discardWithNotification]) and stays. */
        keepNotification: Boolean = false
    ): LocalRecording? {
        val graph = (context.applicationContext as EchoLedgerApp).graph
        if (!keepNotification) Notifications.cancelDecision(context, recordingId)
        DecisionTimeoutWorker.cancel(context, recordingId)
        UploadWorker.cancel(context, recordingId)
        val at = Instant.ofEpochMilli(nowMs + afterMs).toString()
        val discarding = graph.recordings.update(recordingId) {
            if (it.state in DISCARDABLE) {
                it.copy(
                    state = DISCARDING,
                    discardAt = at,
                    discardedFrom = if (it.state == WAITING) WAITING else KEPT,
                    undoIn = if (undoHere) PROCESS else null,
                    restoredDelete = false,
                    notDeletedYet = false
                )
            } else {
                it
            }
        }?.takeIf { it.state == DISCARDING } ?: return null
        DiscardLaterWorker.schedule(context, recordingId, afterMs)
        if (widget) WidgetStateStore.refresh(context)
        return discarding
    }

    /**
     * A Delete whose Undo is a notification (the post-call notification's Delete, the card's fallback when it cannot show
     * the line): "Deleted · Undo" is posted first, in place of the question, then the store is written, so a process
     * that dies in between leaves only an Undo that finds nothing. Before the post a waiting call is marked "Choose
     * again" ([chooseAgain]: its 10 s backstop off), so a death at any point before the store's write leaves it to the
     * person, never kept or sent by itself; [discardLater] and [undoDiscard] clear the mark. When the notification could
     * not be posted (no permission, Android refused it) the Undo is this process's ([PROCESS]): a lost process brings
     * the call back to choose again instead of deleting it unseen. Null when there was nothing to delete (the posted
     * Undo goes too).
     */
    suspend fun discardWithNotification(
        context: Context,
        recordingId: String,
        /** How long "Deleted · Undo" stays (8 s from the after-call notification since v2.1); the file waits 2 s more. */
        lineMs: Long = DecisionPopup.DISCARDED_MS,
        /** The channel of the notification it replaces, so it shows wherever the question showed. */
        channel: String = Notifications.CHANNEL_DECISIONS
    ): LocalRecording? {
        val graph = (context.applicationContext as EchoLedgerApp).graph
        val rec = graph.recordings.get(recordingId)?.takeIf { it.state in DISCARDABLE } ?: return null
        if (rec.state == WAITING) chooseAgain(context, recordingId)
        val posted = Notifications.discardedWithUndo(context, rec, lineMs, channel)
        afterUndoPosted()
        val discarding = discardLater(
            context,
            recordingId,
            afterMs = lineMs + DISCARD_GRACE_MS,
            undoHere = !posted,
            widget = false,
            keepNotification = posted
        )
        if (discarding == null && posted) Notifications.cancelDecision(context, recordingId)
        return discarding
    }

    /**
     * A waiting call left to the person ([LocalRecording.restoredDelete], "Choose again"), never kept or sent by itself:
     * marked first, then its 10 s backstop and any upload called off, only when it still waits (a call decided meanwhile
     * keeps its upload). [notDeletedYet]: EchoLedger keeps running ("Not deleted yet."); else the mark stays only if the
     * process dies before the delete is stored ("Deleted just before EchoLedger closed."). Null when it no longer waits.
     */
    suspend fun chooseAgain(context: Context, recordingId: String, notDeletedYet: Boolean = false): LocalRecording? {
        val graph = (context.applicationContext as EchoLedgerApp).graph
        val left = graph.recordings.update(recordingId) {
            if (it.state == WAITING) it.copy(restoredDelete = true, notDeletedYet = notDeletedYet) else it
        }?.takeIf { it.state == WAITING } ?: return null
        DecisionTimeoutWorker.cancel(context, recordingId)
        UploadWorker.cancel(context, recordingId)
        return left
    }

    /** Between the posted Undo and the store's write (tests stand a process's death in here). */
    @androidx.annotation.VisibleForTesting
    internal var afterUndoPosted: () -> Unit = {}

    /**
     * Undo on "Discarded · …" (since v1.8.1): the call is back as before the tap, waiting for Keep or Discard, or kept
     * ("queued"; the caller shows "Saved" again, whose time the upload waits for). Null when its time was over and it
     * is gone.
     */
    suspend fun undoDiscard(
        context: Context,
        recordingId: String,
        widget: Boolean = true,
        /**
         * True: nobody saw an Undo for it, so it goes back to the person ("Choose again") in the same write, whatever it
         * was before its Delete: waiting on the phone, with no "kept after 10 s" ever started and nothing sent until they
         * choose. The server follows the person's last choice, Delete: a copy an upload reached is deleted there as
         * queued, and a later keep brings it back from the server's bin, or sends the phone's file fresh.
         */
        leftToPerson: Boolean = false,
        /** With [leftToPerson]: EchoLedger kept running ("Not deleted yet"), else it closed ("Deleted just before…"). */
        notDeletedYet: Boolean = true
    ): LocalRecording? {
        val graph = (context.applicationContext as EchoLedgerApp).graph
        DiscardLaterWorker.cancel(context, recordingId)
        val serverCopy = graph.recordings.get(recordingId)?.serverCopy
        // A server copy an upload reached meanwhile: on an Undo its delete is stopped if it has not run, else the next
        // upload restores it first ([com.echoledger.mobile.net.UploadWorker]); left to the person, it goes ahead.
        val serverDelete = if (leftToPerson) null else serverCopy?.let { DiscardWorker.cancelBeforeItRuns(context, recordingId) }
        val back = graph.recordings.update(recordingId) {
            when {
                it.state == DISCARDING && leftToPerson -> it.copy(
                    state = WAITING,
                    transcribe = null,
                    discardAt = null,
                    discardedFrom = null,
                    undoIn = null,
                    restoredDelete = true,
                    notDeletedYet = notDeletedYet,
                    // Only for a later keep the person chooses: the server copy, deleted meanwhile, comes back first.
                    restoreServerCopy = it.serverCopy != null
                )

                it.state == DISCARDING -> it.copy(
                    state = it.discardedFrom ?: WAITING,
                    discardAt = null,
                    discardedFrom = null,
                    undoIn = null,
                    restoredDelete = false,
                    notDeletedYet = false,
                    restoreServerCopy = serverDelete == DiscardWorker.Stopped.MAYBE_RAN
                )

                // A delete whose store was never written (the process died after its Undo was posted): taken back too.
                it.state == WAITING && it.restoredDelete && !leftToPerson -> it.copy(restoredDelete = false, notDeletedYet = false)

                else -> it
            }
        }?.takeIf { it.state == WAITING || it.state == KEPT } ?: return null
        // Waiting again: its "kept after 10 s" runs again too, whoever shows the question, so it never waits without one;
        // left to the person, nothing keeps or sends it by itself.
        if (leftToPerson) {
            UploadWorker.cancel(context, recordingId)
        } else if (back.state == WAITING) {
            DecisionTimeoutWorker.schedule(context, recordingId)
        }
        if (widget) WidgetStateStore.refresh(context)
        return back
    }

    /**
     * The end of a Discard: the call is deleted, unless Undo brought it back meanwhile. The check and the delete are
     * one step of the store ([com.echoledger.mobile.data.RecordingStore.deleteIf]), so an Undo can never lose to it
     * halfway.
     */
    suspend fun finishDiscard(context: Context, recordingId: String) {
        val graph = (context.applicationContext as EchoLedgerApp).graph
        // Its Undo was on a screen of a process that is gone (closed, killed, updated within those seconds): never
        // deleted unseen; it waits again, "Deleted just before EchoLedger closed. Choose again."
        val undoGone =
            graph.recordings.get(recordingId)?.let { it.state == DISCARDING && it.undoIn != null && it.undoIn != PROCESS } == true
        if (undoGone) {
            restoreDelete(context, recordingId)
            return
        }
        val gone =
            graph.recordings.deleteIf(recordingId) { it.state == DISCARDING && (it.undoIn == null || it.undoIn == PROCESS) } ?: return
        Notifications.cancelDecision(context, recordingId)
        // Nothing was uploaded; a server session that captions opened is deleted, so no transcript is ever made.
        gone.serverSessionId?.let { DiscardWorker.enqueue(context, recordingId, it) }
        WidgetStateStore.refresh(context)
    }

    /** A Delete whose Undo was lost with its process: the call waits again, marked [LocalRecording.restoredDelete]. */
    private suspend fun restoreDelete(context: Context, recordingId: String) {
        // Waiting for the person, not for a timer, in one write: nobody gets a call kept or sent that they had chosen to
        // delete, whenever the process stops.
        undoDiscard(context, recordingId, leftToPerson = true, notDeletedYet = false)
    }

    /** Whether [recordingId] still waits for its choice (a question held back can still be asked). */
    fun stillWaiting(context: Context, recordingId: String): Boolean =
        (context.applicationContext as EchoLedgerApp).graph.recordings.get(recordingId)?.state == WAITING

    /** The calls discarded from the chip whose Undo time is over at [now] (the hourly check finishes any left). */
    fun discardsDue(recordings: List<LocalRecording>, now: Instant): List<String> = recordings
        .filter { it.state == DISCARDING }
        .filter { (runCatching { Instant.parse(it.discardAt) }.getOrNull() ?: Instant.EPOCH) <= now }
        .map { it.id }

    /** The home-screen widget's refresh after a decision (tests stand in a slow one). */
    @androidx.annotation.VisibleForTesting
    internal var refreshWidget: suspend (Context) -> Unit = { WidgetStateStore.refresh(it) }

    /** Restore of a skipped call (since v1.5): it is kept like any other call and uploads now. */
    suspend fun restoreSkipped(context: Context, recordingId: String) {
        val graph = (context.applicationContext as EchoLedgerApp).graph
        graph.recordings.update(recordingId) { it.copy(state = "queued", skippedReason = null, skippedAt = null) } ?: return
        UploadWorker.enqueue(context, recordingId)
        WidgetStateStore.refresh(context)
    }

    private const val WAITING = "pending_decision"
    private const val KEPT = "queued"

    /** What Discard can still take back: waiting, or kept and not sent yet (an upload that failed counts). */
    private val DISCARDABLE = setOf(WAITING, KEPT, "uploading", "failed")

    /** A call discarded from the after-call chip, its file kept for Undo until [LocalRecording.discardAt]. */
    const val DISCARDING = "discarding"

    /** A Delete's file outlives its Undo line by this much, so a late Undo still finds it. */
    const val DISCARD_GRACE_MS = 2_000L

    /** How long a call discarded from the chip keeps its file: "Discarded · Undo" and 2 s more, so a late Undo finds it. */
    const val DISCARD_AFTER_MS = DecisionPopup.DISCARDED_MS + DISCARD_GRACE_MS

    /** This process, as [LocalRecording.undoIn] names it: a new one each time the app's process starts. */
    val PROCESS: String = java.util.UUID.randomUUID().toString()
}
