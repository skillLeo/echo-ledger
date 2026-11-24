package com.echoledger.mobile.overlay

import android.content.Context
import android.content.Intent
import android.graphics.PixelFormat
import android.os.Build
import android.os.Handler
import android.os.Looper
import android.view.Gravity
import android.view.WindowInsets
import android.view.WindowManager
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.foundation.layout.padding
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.ComposeView
import androidx.lifecycle.setViewTreeLifecycleOwner
import androidx.savedstate.setViewTreeSavedStateRegistryOwner
import com.echoledger.mobile.EchoLedgerApp
import com.echoledger.mobile.MainActivity
import com.echoledger.mobile.calls.CallChoice
import com.echoledger.mobile.calls.CallChoice.Pick
import com.echoledger.mobile.calls.CallLines
import com.echoledger.mobile.calls.Decisions
import com.echoledger.mobile.calls.PostCallPrompt
import com.echoledger.mobile.core.ShareGuard
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.data.LocalRecording
import com.echoledger.mobile.notify.Notifications
import com.echoledger.mobile.ui.v2.live.CallCardScreen
import com.echoledger.mobile.ui.v2.live.CallChoiceCard
import com.echoledger.mobile.ui.v2.live.callCardTitle
import com.echoledger.mobile.ui.v2.tokens.EchoTokens
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.NonCancellable
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.coroutines.withContext
import kotlinx.coroutines.withTimeoutOrNull

/**
 * The after-call card (the call-choice plan, 2.0.5; docs/PLAN_CALL_CHOICE.md 3), at the bottom of the screen over
 * whatever app is open (SYSTEM_ALERT_WINDOW), above the navigation bar and inside the safe area. It never takes focus,
 * leaves the screen around it alone, and makes no sound.
 *
 * One window for one call at a time: the question ([Mode.ASK]: Delete, Transcribe, a swipe or 8 s for audio only).
 * Since v2.1 (I-05) a choice closes the window at once, and its confirmation with Undo ("Transcribing…", "Saved as
 * audio", "Deleted") is never a floating window: inside the app it is a snackbar ([CallLines]: one at a time, above the
 * bottom bar, 10 s, a swipe dismisses it), outside the app the after-call notification, updated in place. Undo puts the
 * question back with nothing decided: a kept call's upload waits for its line ([Decisions.keepFor]) and a deleted call's
 * file waits for it ([Decisions.discardLater]). Calls that end while the question is still asked are all kept as audio
 * only on one line ("3 calls saved as audio · Review"), never a stack of cards. Only when neither the app nor a
 * notification can carry the line (the app not on screen and notifications not allowed) does it stay on the card for
 * 5 s ([Mode.TRANSCRIBING], [Mode.AUDIO] with Transcribe, [Mode.DELETED], [Mode.GROUPED]), and a swipe dismisses it
 * there too. A "Deleted · Undo" line on the card is never replaced: a call that ends meanwhile is asked about once that
 * line is over, so a Delete is never applied while its Undo is out of reach. Every step the card asks of the store runs
 * in order on one queue. The times follow the phone's accessibility timeout (Settings › Accessibility › Time to take
 * action).
 */
object DecisionPopup {
    enum class Mode { ASK, TRANSCRIBING, AUDIO, DELETED, GROUPED }

    /** How long the question waits, and how long a confirmation line stays. */
    const val ASK_MS = CallChoice.CARD_MS
    const val DONE_MS = CallChoice.DONE_MS

    /** Kept for the delayed delete: a Delete's "Deleted · Undo" line. */
    const val DISCARDED_MS = DONE_MS

    /** How long a step's widget refresh and follow-up may take before the queue moves on (its change always ends). */
    private const val STEP_TIMEOUT_MS = 30_000L

    /** The backstop waits this much longer than the card, so the card always decides first. */
    const val BACKSTOP_GRACE_MS = 2_000L

    private val handler = Handler(Looper.getMainLooper())
    private var view: ComposeView? = null
    private var owner: OverlayOwner? = null
    private var windowManager: WindowManager? = null
    private var timeout: Runnable? = null
    private var screen by mutableStateOf<CallCardScreen?>(null)
    private var current: LocalRecording? = null
    private val grouped = mutableListOf<String>()

    /** Calls that ended while a Deleted line was showing, in order: asked about when that line is over. */
    private val waiting = java.util.concurrent.CopyOnWriteArrayList<Held>()

    /**
     * A call held behind the line on screen: its question, or ([deleteLineMs]) its own "Deleted · Undo" line. [again]: a
     * question put back by Undo, which is asked, never folded into the grouped line.
     */
    private class Held(val rec: LocalRecording, val themeMode: String, val deleteLineMs: Long? = null, val again: Boolean = false)

    /** Where a choice's confirmation goes: the app's snackbar, the after-call notification, or (neither) the card. */
    private enum class Home { APP, NOTIFICATION, CARD }

    private fun home(app: Context): Home = when {
        CallLines.host != null -> Home.APP
        Notifications.decisionsShow(app) -> Home.NOTIFICATION
        else -> Home.CARD
    }

    /** One choice made on the card whose line is out of it (in the app or in a notification). */
    private class Chosen(val pick: Pick)

    /**
     * The choices whose line is out of the card, by call: each counts toward "Always do this?" once its line is over
     * without Undo, never after an Undo.
     */
    private val choices = java.util.concurrent.ConcurrentHashMap<String, Chosen>()

    /** Back-to-back calls kept together while their line is out of the card, and until when another call joins them. */
    private val groupedOut = mutableListOf<String>()
    private var groupOpenUntil = 0L

    /** When the line or question on screen ends ([SystemClock.uptimeMillis]). */
    private var endsAt = 0L

    /** The app the card last opened for: where a Deleted line held behind it shows once the card goes. */
    private var lastApp: Context? = null

    /** A choice made on the card, counted toward "Always do this?" only once its line closes without Undo. */
    private var picked: Pick? = null

    /** This card's question and line times: 8 s and 5 s, or longer when the phone asks for more time. */
    private var askMs = ASK_MS
    private var doneMs = DONE_MS

    /** The card's store steps, in the order they were asked for, one at a time. */
    private val steps = CoroutineScope(SupervisorJob() + Dispatchers.IO.limitedParallelism(1))
    private val order = Mutex()

    /** The id of the call the card is about; null when no card is showing (or for the grouped line). */
    @Volatile
    var showingFor: String? = null
        private set

    @Volatile
    var showingMode: Mode? = null
        private set

    /**
     * The card still answers for [id]: asking about it, or holding it behind a Deleted line. Its 10 s backstop
     * ([com.echoledger.mobile.calls.DecisionTimeoutWorker]) then waits instead of keeping it. Any thread.
     */
    fun answersFor(id: String): Boolean = (showingFor == id && showingMode == Mode.ASK) || waiting.any { it.rec.id == id }

    /**
     * How long [id]'s backstop waits: the question's time and a little more, after the line it is held behind (main
     * thread, right after [ask]).
     */
    fun backstopMs(id: String): Long {
        val behind = if (waiting.any { it.rec.id == id }) (endsAt - android.os.SystemClock.uptimeMillis()).coerceAtLeast(0) else 0L
        return behind + askMs + BACKSTOP_GRACE_MS
    }

    val isShowing: Boolean get() = view != null

    /** What the card shows now (tests). */
    internal val shown: CallCardScreen? get() = screen

    /**
     * Asks about [rec] after its call. A question still on screen for another call means back-to-back calls: both are
     * kept as audio only on the grouped line. False when the card could not be added (the caller then asks in a
     * notification).
     */
    fun ask(context: Context, rec: LocalRecording, themeMode: String, again: Boolean = false): Boolean {
        val app = context.applicationContext
        if (isShowing && showingMode == Mode.DELETED) {
            // Another call's Delete can still be undone: this call waits for its line to end (its backstop waits too,
            // [answersFor], so it is never kept before it was asked).
            waiting += Held(rec, themeMode, again = again)
            return true
        }
        val asking = isShowing && showingMode == Mode.ASK
        if (again && asking && showingFor != rec.id) {
            // Put back by Undo while another call is asked about: asked once that question is answered, never folded
            // into the grouped line.
            waiting += Held(rec, themeMode, again = true)
            return true
        }
        if (android.os.SystemClock.uptimeMillis() >= groupOpenUntil) groupedOut.clear()
        val joinsOut = !again && !isShowing && groupedOut.isNotEmpty()
        if (asking || joinsOut || (isShowing && showingMode == Mode.GROUPED)) {
            val before = current?.takeIf { asking }
            before?.let { keepQuietly(app, it.id) }
            keepQuietly(app, rec.id)
            if (showingMode != Mode.GROUPED && home(app) != Home.CARD) {
                // Their line out of the card too: the card goes, and the group's line says how many.
                if (before != null) groupedOut += before.id
                groupedOut += rec.id
                if (isShowing) close(app)
                groupedLine(app)
                return true
            }
            if (before != null) grouped += before.id
            grouped += rec.id
            current = null
            showingFor = null
            go(app, Mode.GROUPED, CallCardScreen.Grouped(grouped.size))
            return true
        }
        val settings = settings(app)
        val always = CallChoice.alwaysOffer(settings)
        val hint = !settings.callChoiceHintShown
        if (hint || always != null) {
            update(app) { if (hint) it.copy(callChoiceHintShown = true) else it.copy(callChoiceAlwaysOffered = true) }
        }
        val question = CallCardScreen.Ask(callCardTitle(app.resources, rec), rec.durationMs, rec.sourceApp, hint, always.takeIf { !hint })
        if (isShowing) {
            // A kept call's line gives way to the new question (one line at a time); its choice counts as made.
            commitPick(app)
            current = rec
            showingFor = rec.id
            go(app, Mode.ASK, question)
            return true
        }
        return open(app, rec, themeMode, question)
    }

    /**
     * "Deleted · Undo" for [rec] for [lineMs] (the pill's Stop and delete still counting down when the call ended): its
     * Undo asks about the call again. A Delete is never applied without an Undo the person can see:
     * - the app on screen: the line is its snackbar (v2.1), never a floating window;
     * - else, notifications allowed: the Undo in a notification, which outlives the process;
     * - else, the card free: the line on the card (a swipe dismisses it);
     * - else, the card busy: the line waits behind the one on screen, as a held question does;
     * - else (no overlay, no notification): the call is left to the person to choose again, nothing deleted.
     * True when the line is on a screen of this process (the app's or the card).
     */
    fun deleted(context: Context, rec: LocalRecording, themeMode: String, lineMs: Long): Boolean {
        val app = context.applicationContext
        val host = CallLines.host
        if (host != null) {
            // In the app: "Deleted · Undo" as its snackbar for the time that was left; the file waits for it.
            val leftMs = host.show(
                CallLines.Line(rec.id, Pick.DELETE),
                lineMs,
                CallLines.Actions(
                    action = { undoOut(app, rec, Pick.DELETE) },
                    away = { left -> step(app, { moveOut(app, rec.id, Pick.DELETE, left) }, widget = false) }
                )
            )
            step(app, {
                Decisions.discardLater(app, rec.id, afterMs = leftMs + Decisions.DISCARD_GRACE_MS, undoHere = true, widget = false)
            }) { gone ->
                if (gone == null) handler.post { host.cancel(rec.id) }
            }
            return true
        }
        // Never over another call's question or Deleted line.
        val busy = isShowing && showingMode in setOf(Mode.ASK, Mode.GROUPED, Mode.DELETED)
        // Outside the app the Undo goes in a notification whenever one can be posted: never a floating line then.
        val notify = Notifications.decisionsShow(app)
        if (!busy && !notify) commitPick(app)
        val shown = !busy && !notify && open(app, rec, themeMode, CallCardScreen.Done(Pick.DELETE))
        if (shown) {
            go(app, Mode.DELETED, CallCardScreen.Done(Pick.DELETE), lineMs)
            step(app, {
                Decisions.discardLater(app, rec.id, afterMs = lineMs + Decisions.DISCARD_GRACE_MS, undoHere = true, widget = false)
            }) { gone ->
                if (gone == null) handler.post { if (showingFor == rec.id && showingMode == Mode.DELETED) hide() }
            }
        } else if (notify) {
            // The Undo notification goes out in the same uncancellable step as the delete it can take back, before
            // anything that can wait (the widget), so the delete is never due without its Undo.
            step(app, { deleteWithNotification(app, rec.id) })
        } else {
            // No notification can carry its Undo. Meanwhile the call is left to the person ("Choose again"), so it is
            // never kept, sent or deleted by itself; a busy card shows its line once the line on screen is over.
            if (busy) waiting += Held(rec, themeMode, deleteLineMs = lineMs)
            step(app, { Decisions.chooseAgain(app, rec.id, notDeletedYet = true) })
        }
        return shown
    }

    /** A delete whose Undo is a notification: the call on its way out and its "Deleted · Undo" posted, in one step. */
    private suspend fun deleteWithNotification(app: Context, id: String): LocalRecording? = Decisions.discardWithNotification(app, id)

    /** Draws [mode] for [rec] at once (tests and screenshots): the question or one of its lines. */
    internal fun show(context: Context, rec: LocalRecording, themeMode: String, mode: Mode): Boolean {
        val app = context.applicationContext
        teardown()
        val shown = when (mode) {
            Mode.ASK -> CallCardScreen.Ask(callCardTitle(app.resources, rec), rec.durationMs, rec.sourceApp)
            Mode.TRANSCRIBING -> CallCardScreen.Done(Pick.TRANSCRIBE)
            Mode.AUDIO -> CallCardScreen.Done(Pick.AUDIO)
            Mode.DELETED -> CallCardScreen.Done(Pick.DELETE)
            Mode.GROUPED -> CallCardScreen.Grouped(2)
        }
        return open(app, rec, themeMode, shown)
    }

    private fun modeOf(shown: CallCardScreen): Mode = when (shown) {
        is CallCardScreen.Ask -> Mode.ASK

        is CallCardScreen.Grouped -> Mode.GROUPED

        is CallCardScreen.Done -> when (shown.pick) {
            Pick.TRANSCRIBE -> Mode.TRANSCRIBING
            Pick.AUDIO -> Mode.AUDIO
            Pick.DELETE -> Mode.DELETED
        }
    }

    private fun open(app: Context, rec: LocalRecording, themeMode: String, first: CallCardScreen): Boolean {
        teardown()
        lastApp = app
        // Without "Display over other apps" there is no card (Android would refuse the window anyway).
        if (!android.provider.Settings.canDrawOverlays(app)) return false
        val wm = app.getSystemService(WindowManager::class.java) ?: return false
        val lifecycleOwner = OverlayOwner().also { it.start() }
        askMs = recommended(app, ASK_MS)
        doneMs = recommended(app, DONE_MS)
        screen = first
        val compose = ComposeView(app).apply {
            // No click sounds: the card makes no sound and no vibration.
            isSoundEffectsEnabled = false
            isHapticFeedbackEnabled = false
            setViewTreeLifecycleOwner(lifecycleOwner)
            setViewTreeSavedStateRegistryOwner(lifecycleOwner)
            setContent {
                // Hidden by the person (Hide EchoLedger windows): a grey dot instead of the call's name; the timeout still
                // runs. The card itself always shows, in screenshots and screen shares too (2.0.5).
                if (ShareGuard.hiddenByUser.collectAsState().value) {
                    PrivateDot(onShow = { ShareGuard.setHidden(false) })
                    return@setContent
                }
                val shown = screen ?: return@setContent
                val dark = when (themeMode) {
                    "light" -> false
                    "dark" -> true
                    else -> isSystemInDarkTheme()
                }
                com.echoledger.mobile.ui.v2.shell.OverlayLook(com.echoledger.mobile.ui.v2.shell.overlaySettings(app), dark = dark) {
                    CallChoiceCard(
                        screen = shown,
                        onTranscribe = { transcribe(app) },
                        onDelete = { delete(app) },
                        onAudio = { audio(app, chosen = true) },
                        onUndo = { undo(app) },
                        onAlwaysYes = { always(app) },
                        onReview = { review(app) },
                        onDismiss = { dismissLine(app) },
                        still = com.echoledger.mobile.ui.reduceMotion(),
                        askMs = askMs,
                        modifier = Modifier.padding(horizontal = EchoTokens.Space.s16, vertical = EchoTokens.Space.s8)
                    )
                }
            }
        }
        // As wide as the card: taps beside it reach the app below, and it sits centred in landscape too.
        val params = WindowManager.LayoutParams(
            WindowManager.LayoutParams.WRAP_CONTENT,
            WindowManager.LayoutParams.WRAP_CONTENT,
            WindowManager.LayoutParams.TYPE_APPLICATION_OVERLAY,
            // Not focusable and not modal: touches outside the card go to the app underneath. PrivateWindows never
            // gives the after-call card FLAG_SECURE: it shows in screenshots and screen shares.
            FloatingWindow.AFTER_CALL_CARD.flags,
            PixelFormat.TRANSLUCENT
        ).apply {
            gravity = Gravity.BOTTOM or Gravity.CENTER_HORIZONTAL
            // Above the navigation bar (gestures or buttons) and any cutout, inside the safe area.
            y = bottomInsetPx(app, wm)
            title = FloatingWindow.AFTER_CALL_CARD.title
        }
        if (!PrivateWindows.add(wm, compose, params)) {
            lifecycleOwner.stop()
            screen = null
            return false
        }
        view = compose
        owner = lifecycleOwner
        windowManager = wm
        current = rec
        showingFor = rec.id
        go(app, modeOf(first), first)
        return true
    }

    /** The navigation bar and cutout below the card, in pixels: where the safe area ends. */
    internal fun bottomInsetPx(context: Context, wm: WindowManager): Int {
        if (Build.VERSION.SDK_INT >= 30) {
            val insets = wm.currentWindowMetrics.windowInsets
                .getInsetsIgnoringVisibility(WindowInsets.Type.navigationBars() or WindowInsets.Type.displayCutout())
            if (insets.bottom > 0) return insets.bottom
        }
        val id = context.resources.getIdentifier("navigation_bar_height", "dimen", "android")
        return if (id != 0) context.resources.getDimensionPixelSize(id) else 0
    }

    /** The phone's accessibility timeout for [ms] of controls and text, never shorter than [ms]. */
    private fun recommended(app: Context, ms: Long): Long {
        if (Build.VERSION.SDK_INT < 29) return ms
        val a11y = app.getSystemService(android.view.accessibility.AccessibilityManager::class.java) ?: return ms
        val flags = android.view.accessibility.AccessibilityManager.FLAG_CONTENT_CONTROLS or
            android.view.accessibility.AccessibilityManager.FLAG_CONTENT_TEXT
        return a11y.getRecommendedTimeoutMillis(ms.toInt(), flags).toLong().coerceAtLeast(ms)
    }

    /** Shows [next] in the window already open and starts its time ([lineMs] for a line, else the card's own). */
    private fun go(app: Context, mode: Mode, next: CallCardScreen, lineMs: Long? = null) {
        timeout?.let(handler::removeCallbacks)
        showingMode = mode
        screen = next
        val task = when (mode) {
            // Nobody chose: the call is kept as audio only, which the line then says.
            Mode.ASK -> Runnable { audio(app, chosen = false) }

            Mode.GROUPED -> Runnable {
                grouped.clear()
                close(app)
            }

            // A line's time is over without Undo: its choice counts, and a call that ended meanwhile is asked about.
            else -> Runnable {
                commitPick(app)
                close(app)
            }
        }
        timeout = task
        val ms = lineMs ?: if (mode == Mode.ASK || mode == Mode.GROUPED) askMs else doneMs
        endsAt = android.os.SystemClock.uptimeMillis() + ms
        handler.postDelayed(task, ms)
    }

    /** The card's time is over: it goes, then a call held back by a Deleted line is asked about. */
    private fun close(app: Context) {
        val next = waiting.toList()
        waiting.clear()
        teardown()
        showHeld(app, next)
    }

    /** The held calls, in the order they ended ([next]; all of them by default), now that the card is free. */
    private fun showHeld(app: Context, next: List<Held> = waiting.toList().also { waiting.clear() }) {
        // In the order they ended: the first shows, any later question joins it on the grouped line (back-to-back calls)
        // and any later Deleted line waits for the one before it.
        next.forEach { held ->
            val rec = held.rec
            // Still waiting (decided meanwhile in a notification or the app: nothing to show).
            step(app, { Decisions.stillWaiting(app, rec.id) }, widget = false) { still ->
                if (still == true) {
                    handler.post {
                        val lineMs = held.deleteLineMs
                        if (lineMs != null) {
                            // Its own line now, with a whole line's time for the Undo.
                            deleted(app, rec, held.themeMode, maxOf(lineMs, doneMs))
                        } else {
                            ask(app, rec, held.themeMode, again = held.again)
                            com.echoledger.mobile.calls.DecisionTimeoutWorker.schedule(app, rec.id, backstopMs(rec.id))
                        }
                    }
                }
            }
        }
    }

    private fun commitPick(app: Context) {
        picked?.let { pick -> update(app) { CallChoice.afterPick(it, pick) } }
        picked = null
    }

    /**
     * Removes the card. Never decides anything. A Deleted line held behind it (no notification could carry its Undo) is
     * not dropped: it shows next, as when the card's own time is over ([close]).
     */
    fun hide() {
        teardown()
        val app = lastApp ?: return
        if (waiting.isNotEmpty()) handler.post { if (!isShowing && waiting.isNotEmpty()) showHeld(app) }
    }

    /** Everything back as without a card (tests): held lines, choices and the grouped line out of the card too. */
    @androidx.annotation.VisibleForTesting
    internal fun resetForTest() {
        teardown()
        waiting.clear()
        choices.clear()
        groupedOut.clear()
        groupOpenUntil = 0L
    }

    /** The card goes; held questions go with it (their backstop decides), held Deleted lines stay. */
    private fun teardown() {
        timeout?.let(handler::removeCallbacks)
        timeout = null
        view?.let { v -> windowManager?.let { PrivateWindows.remove(it, v) } }
        owner?.stop()
        view = null
        owner = null
        windowManager = null
        showingFor = null
        showingMode = null
        current = null
        screen = null
        grouped.clear()
        waiting.removeAll { it.deleteLineMs == null }
        picked = null
    }

    internal fun transcribe(app: Context) {
        val rec = current ?: return
        val counted = showingMode == Mode.ASK
        if (home(app) != Home.CARD) return out(app, rec, Pick.TRANSCRIBE, counted)
        picked = if (counted) Pick.TRANSCRIBE else null
        go(app, Mode.TRANSCRIBING, CallCardScreen.Done(Pick.TRANSCRIBE))
        step(app, { Decisions.keepFor(app, rec.id, transcribe = true, uploadAfterMs = doneMs, widget = false) })
    }

    internal fun audio(app: Context, chosen: Boolean) {
        val rec = current ?: return
        if (home(app) != Home.CARD) return out(app, rec, Pick.AUDIO, counted = chosen)
        picked = if (chosen) Pick.AUDIO else null
        go(app, Mode.AUDIO, CallCardScreen.Done(Pick.AUDIO))
        step(app, { Decisions.keepFor(app, rec.id, transcribe = false, uploadAfterMs = doneMs, widget = false) })
    }

    internal fun delete(app: Context) {
        val rec = current ?: return
        if (home(app) != Home.CARD) return out(app, rec, Pick.DELETE, counted = true)
        picked = Pick.DELETE
        go(app, Mode.DELETED, CallCardScreen.Done(Pick.DELETE))
        // The file waits for the line and a little longer; this process applies it only while its Undo is reachable.
        val afterMs = doneMs + Decisions.DISCARD_GRACE_MS
        step(app, { Decisions.discardLater(app, rec.id, afterMs = afterMs, undoHere = true, widget = false) }) { gone ->
            // Decided meanwhile (a notification, the app): nothing was deleted, so the line does not say so.
            if (gone == null) handler.post { if (showingFor == rec.id && showingMode == Mode.DELETED) hide() }
        }
    }

    /** Undo on any line: the question again, with nothing decided and its 8 s from the start; the choice is not counted. */
    internal fun undo(app: Context) {
        val rec = current ?: return
        val was = showingMode
        picked = null
        // A moved line's choice (the rest of an app's Deleted line on the card) no longer counts either.
        choices.remove(rec.id)
        val question = CallCardScreen.Ask(callCardTitle(app.resources, rec), rec.durationMs, rec.sourceApp)
        go(app, Mode.ASK, question)
        step(app, {
            if (was == Mode.DELETED) Decisions.undoDiscard(app, rec.id, widget = false) else Decisions.undoKeep(app, rec.id, widget = false)
        }) { back ->
            // Too late (sent already, or gone): the question has nothing left to ask.
            if (back == null) handler.post { if (showingFor == rec.id && showingMode == Mode.ASK) hide() }
        }
    }

    /**
     * [pick] made for [rec] on the card, confirmed out of it (v2.1, I-05): the card goes at once (a call held behind it
     * is asked about next), and "Transcribing…", "Saved as audio" or "Deleted" with Undo goes to the app's snackbar when
     * the app is on screen, else in place of the after-call notification. The call's upload or delete waits for that
     * line, so its Undo always finds the call. [counted]: the person chose it (not the 8 s), so it counts toward
     * "Always do this?" once the line is over without Undo.
     */
    private fun out(app: Context, rec: LocalRecording, pick: Pick, counted: Boolean) {
        close(app)
        val chosen = Chosen(pick).takeIf { counted }
        if (chosen != null) choices[rec.id] = chosen else choices.remove(rec.id)
        val host = CallLines.host
        if (host == null) return notified(app, rec, pick, chosen)
        val lineMs = recommended(app, CallLines.LONG_MS)
        val leftMs = host.show(
            CallLines.Line(rec.id, pick),
            lineMs,
            CallLines.Actions(
                action = { undoOut(app, rec, pick) },
                ended = { count(app, rec.id, chosen) },
                // The app left the screen: the rest of the line in the notification, then the choice counts.
                away = { left ->
                    step(app, { moveOut(app, rec.id, pick, left) }, widget = false)
                    countAfter(app, rec.id, chosen, left)
                }
            )
        )
        step(app, { store(app, rec.id, pick, leftMs) }) { done ->
            // Decided meanwhile (a notification, the app): nothing was kept or deleted here, so the line does not say so.
            if (done == null) handler.post { host.cancel(rec.id) }
        }
    }

    /** The call kept, or on its way out, for a line of [lineMs] whose Undo is this process's. */
    private suspend fun store(app: Context, id: String, pick: Pick, lineMs: Long): LocalRecording? = when (pick) {
        Pick.DELETE -> Decisions.discardLater(app, id, afterMs = lineMs + Decisions.DISCARD_GRACE_MS, undoHere = true, widget = false)
        else -> Decisions.keepFor(app, id, transcribe = pick == Pick.TRANSCRIBE, uploadAfterMs = lineMs, widget = false)
    }

    /** [pick]'s line in place of the after-call notification. */
    private fun notified(app: Context, rec: LocalRecording, pick: Pick, chosen: Chosen?) {
        if (pick == Pick.DELETE) {
            // "Deleted · Undo" posted first, then the store, in one uncancellable step: never deleted without its Undo.
            step(app, { deleteWithNotification(app, rec.id) })
            countAfter(app, rec.id, chosen, DISCARDED_MS)
            return
        }
        val transcribe = pick == Pick.TRANSCRIBE
        step(app, {
            Decisions.keepFor(app, rec.id, transcribe = transcribe, uploadAfterMs = doneMs, widget = false)
                ?.also { Notifications.choiceLine(app, it, transcribe, timeoutMs = doneMs) }
        })
        countAfter(app, rec.id, chosen, doneMs)
    }

    /**
     * An app line still running when the app left the screen: the rest of it in the after-call notification. A Delete's
     * Undo must stay reachable all the while ([deleted]'s rule):
     * - posted: its Undo is in the shade, which outlives the process, so the call no longer names this process
     *   ([LocalRecording.undoIn]) and the delete is applied at its time even if EchoLedger is closed meanwhile;
     * - not posted (notifications not allowed, switched off, or their channel blocked): the rest of the line on the card
     *   when it can show it;
     * - else the delete is taken back and the call is left to the person to choose again, nothing deleted unseen; with
     *   the card busy, its line shows on the card once the one there is over.
     * A kept call's line that cannot be posted just ends: the call stays kept, as chosen.
     */
    private suspend fun moveOut(app: Context, id: String, pick: Pick, leftMs: Long) {
        val graph = (app as? EchoLedgerApp)?.graph ?: return
        val rec = graph.recordings.get(id) ?: return
        when {
            pick == Pick.DELETE && rec.state == Decisions.DISCARDING -> {
                if (Notifications.discardedWithUndo(app, rec, leftMs)) {
                    graph.recordings.update(id) { if (it.state == Decisions.DISCARDING) it.copy(undoIn = null) else it }
                    return
                }
                val onCard = withContext(Dispatchers.Main) { deletedOnCard(app, rec, leftMs) }
                if (onCard) return
                choices.remove(id)
                // Not deleted meanwhile: the call waits for the person ("Not deleted yet"); with the card busy (another
                // call's question or line), its own "Deleted · Undo" shows once that one is over, as [deleted] holds one.
                val back = Decisions.undoDiscard(app, id, widget = false) ?: return
                if (back.state != "pending_decision") return
                withContext(Dispatchers.Main) {
                    if (isShowing && android.provider.Settings.canDrawOverlays(app)) {
                        waiting += Held(back, settings(app).themeMode, deleteLineMs = leftMs)
                    }
                }
                Decisions.chooseAgain(app, id, notDeletedYet = true)
            }

            pick != Pick.DELETE && rec.state == "queued" -> Notifications.choiceLine(app, rec, pick == Pick.TRANSCRIBE, leftMs)
        }
    }

    /**
     * The rest of an app's "Deleted · Undo" line on the card, for [leftMs] (main thread): the call is already on its way
     * out, with its file kept until after that time, and the card's Undo takes it back ([undo]). False when the card
     * cannot show it (no overlay permission, or another call's question or line on it).
     */
    private fun deletedOnCard(app: Context, rec: LocalRecording, leftMs: Long): Boolean {
        if (isShowing && showingMode in setOf(Mode.ASK, Mode.GROUPED, Mode.DELETED)) return false
        commitPick(app)
        if (!open(app, rec, settings(app).themeMode, CallCardScreen.Done(Pick.DELETE))) return false
        go(app, Mode.DELETED, CallCardScreen.Done(Pick.DELETE), leftMs)
        return true
    }

    /** Undo on a line out of the card: nothing decided, and the question again (after one already on the card). */
    private fun undoOut(app: Context, rec: LocalRecording, pick: Pick) {
        choices.remove(rec.id)
        step(app, {
            if (pick == Pick.DELETE) Decisions.undoDiscard(app, rec.id, widget = false) else Decisions.undoKeep(app, rec.id, widget = false)
        }) { back ->
            // Too late (sent already, or gone): there is nothing left to ask.
            if (back != null) handler.post { PostCallPrompt.again(app, back, settings(app).themeMode) }
        }
    }

    /** The choice [chosen] for [id] counts now, unless it was taken back or made again since. */
    private fun count(app: Context, id: String, chosen: Chosen?) {
        if (chosen == null || !choices.remove(id, chosen)) return
        update(app) { CallChoice.afterPick(it, chosen.pick) }
    }

    private fun countAfter(app: Context, id: String, chosen: Chosen?, ms: Long) {
        if (chosen != null) handler.postDelayed({ count(app, id, chosen) }, ms)
    }

    /** An Undo in the notification ([com.echoledger.mobile.calls.SaveDecisionReceiver]): the choice no longer counts. */
    fun forgetChoice(id: String) {
        choices.remove(id)
    }

    /** "3 calls saved as audio · Review" out of the card; another call that ends within the question's time joins it. */
    private fun groupedLine(app: Context) {
        val count = groupedOut.size
        groupOpenUntil = android.os.SystemClock.uptimeMillis() + askMs
        val host = CallLines.host
        if (host == null) {
            Notifications.groupedLine(app, count, askMs)
            return
        }
        host.show(
            CallLines.Line(null, null, count),
            askMs,
            CallLines.Actions(action = { review(app) }, away = { left -> Notifications.groupedLine(app, count, left) })
        )
    }

    /** A swipe on a line on the card: it goes now, as when its time is over (its choice stands). */
    internal fun dismissLine(app: Context) {
        if (!isShowing || showingMode == Mode.ASK) return
        val end = timeout ?: return
        handler.removeCallbacks(end)
        end.run()
    }

    /** "Always do this? Yes": After each call becomes that choice, and this call gets it too. */
    internal fun always(app: Context) {
        val pick = (screen as? CallCardScreen.Ask)?.always ?: return
        update(app) { it.copy(afterEachCall = CallChoice.settingFor(pick)) }
        if (pick == Pick.TRANSCRIBE) transcribe(app) else audio(app, chosen = false)
    }

    internal fun review(app: Context) {
        hide()
        app.startActivity(
            Intent(app, MainActivity::class.java)
                .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP)
                .putExtra(MainActivity.EXTRA_OPEN, MainActivity.OPEN_LIBRARY)
        )
    }

    private fun keepQuietly(app: Context, id: String) =
        step(app, { Decisions.keepFor(app, id, transcribe = false, uploadAfterMs = 0, widget = false) })

    private fun settings(app: Context): AppSettings = (app as? EchoLedgerApp)?.graph?.liveSettings?.value ?: AppSettings()

    private fun update(app: Context, change: (AppSettings) -> AppSettings) {
        val graph = (app as? EchoLedgerApp)?.graph ?: return
        step(app, { graph.settings.update(change) }, widget = false)
    }

    /**
     * One step on the queue, in the order the card asked for them. Its [change] (the store, the workers, the Undo
     * notification) always runs to its end, uncancellable. Then the card's own follow-up ([after]), and the widget only
     * last: each is given up after [stepTimeoutMs], so a slow widget never delays or skips the follow-up, nor holds up
     * the next step.
     */
    private fun <T> step(app: Context, change: suspend () -> T, widget: Boolean = true, after: suspend (T?) -> Unit = {}) {
        steps.launch {
            order.withLock {
                val result = runCatching { withContext(NonCancellable) { change() } }.getOrNull()
                runCatching { withTimeoutOrNull(stepTimeoutMs) { after(result) } }
                if (widget) runCatching { withTimeoutOrNull(stepTimeoutMs) { refreshWidget(app) } }
            }
        }
    }

    /** The home-screen widget's refresh after a card step ([Decisions.refreshWidget]; tests stand in a slow one). */
    private suspend fun refreshWidget(app: Context) = Decisions.refreshWidget(app)

    @androidx.annotation.VisibleForTesting
    internal var stepTimeoutMs = STEP_TIMEOUT_MS

    /** Waits until every step asked for so far has run (tests: none reaches the next test's card). */
    @androidx.annotation.VisibleForTesting
    internal fun drainForTest(timeoutMs: Long = 15_000) {
        val done = java.util.concurrent.CountDownLatch(1)
        steps.launch { order.withLock { done.countDown() } }
        done.await(timeoutMs, java.util.concurrent.TimeUnit.MILLISECONDS)
    }
}
