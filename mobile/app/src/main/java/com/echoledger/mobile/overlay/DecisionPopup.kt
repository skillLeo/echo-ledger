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
import com.echoledger.mobile.calls.Decisions
import com.echoledger.mobile.core.ShareGuard
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.data.LocalRecording
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
 * One window for one call at a time ([Mode]): the question ([Mode.ASK]: Delete, Transcribe, a swipe or 8 s for audio
 * only), then one line with Undo for 5 s ([Mode.TRANSCRIBING], [Mode.AUDIO] with Transcribe, [Mode.DELETED]). Undo
 * puts the question back with nothing decided: a kept call's upload waits for the line ([Decisions.keepFor]) and a
 * deleted call's file waits for it ([Decisions.discardLater]). Calls that end while the question is still asked are all
 * kept as audio only on one line ([Mode.GROUPED], "3 calls saved as audio · Review"), never a stack of cards. A
 * "Deleted · Undo" line is never replaced: a call that ends meanwhile is asked about once that line is over, so a Delete
 * is never applied while its Undo is out of reach. Every step the card asks of the store runs in order on one queue.
 * The times follow the phone's accessibility timeout (Settings › Accessibility › Time to take action).
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
    private val waiting = java.util.concurrent.CopyOnWriteArrayList<Pair<LocalRecording, String>>()

    /** When the line or question on screen ends ([SystemClock.uptimeMillis]). */
    private var endsAt = 0L

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
    fun answersFor(id: String): Boolean = (showingFor == id && showingMode == Mode.ASK) || waiting.any { it.first.id == id }

    /**
     * How long [id]'s backstop waits: the question's time and a little more, after the line it is held behind (main
     * thread, right after [ask]).
     */
    fun backstopMs(id: String): Long {
        val behind = if (waiting.any { it.first.id == id }) (endsAt - android.os.SystemClock.uptimeMillis()).coerceAtLeast(0) else 0L
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
    fun ask(context: Context, rec: LocalRecording, themeMode: String): Boolean {
        val app = context.applicationContext
        if (isShowing && showingMode == Mode.DELETED) {
            // Another call's Delete can still be undone: this call waits for its line to end (its backstop waits too,
            // [answersFor], so it is never kept before it was asked).
            waiting += rec to themeMode
            return true
        }
        if (isShowing && (showingMode == Mode.ASK || showingMode == Mode.GROUPED)) {
            val before = current?.takeIf { showingMode == Mode.ASK }
            before?.let { keepQuietly(app, it.id) }
            keepQuietly(app, rec.id)
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
     * Undo asks about the call again.
     */
    fun deleted(context: Context, rec: LocalRecording, themeMode: String, lineMs: Long): Boolean {
        val app = context.applicationContext
        // Never over another call's question or Deleted line: then the Undo goes in a notification, which outlives the
        // process, and the delete follows that notification's time.
        val busy = isShowing && showingMode in setOf(Mode.ASK, Mode.GROUPED, Mode.DELETED)
        if (!busy) commitPick(app)
        val shown = !busy && open(app, rec, themeMode, CallCardScreen.Done(Pick.DELETE))
        if (shown) {
            go(app, Mode.DELETED, CallCardScreen.Done(Pick.DELETE), lineMs)
            step(app, {
                Decisions.discardLater(app, rec.id, afterMs = lineMs + Decisions.DISCARD_GRACE_MS, undoHere = true, widget = false)
            }) { gone ->
                if (gone == null) handler.post { if (showingFor == rec.id && showingMode == Mode.DELETED) hide() }
            }
        } else {
            // The Undo notification goes out in the same uncancellable step as the delete it can take back, before
            // anything that can wait (the widget), so the delete is never due without its Undo.
            step(app, { deleteWithNotification(app, rec.id) })
        }
        return shown
    }

    /** A delete whose Undo is a notification: the call on its way out and its "Deleted · Undo" posted, in one step. */
    private suspend fun deleteWithNotification(app: Context, id: String): LocalRecording? =
        Decisions.discardLater(app, id, widget = false)?.also { com.echoledger.mobile.notify.Notifications.discardedWithUndo(app, it) }

    /** Draws [mode] for [rec] at once (tests and screenshots): the question or one of its lines. */
    internal fun show(context: Context, rec: LocalRecording, themeMode: String, mode: Mode): Boolean {
        val app = context.applicationContext
        hide()
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
        hide()
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
        hide()
        // In the order they ended: the first is asked, any later one joins it on the grouped line (back-to-back calls).
        next.forEach { (rec, themeMode) ->
            // Still waiting (decided meanwhile in a notification or the app: nothing to ask).
            step(app, { Decisions.stillWaiting(app, rec.id) }, widget = false) { still ->
                if (still == true) {
                    handler.post {
                        ask(app, rec, themeMode)
                        com.echoledger.mobile.calls.DecisionTimeoutWorker.schedule(app, rec.id, backstopMs(rec.id))
                    }
                }
            }
        }
    }

    private fun commitPick(app: Context) {
        picked?.let { pick -> update(app) { CallChoice.afterPick(it, pick) } }
        picked = null
    }

    /** Removes the card. Never decides anything. */
    fun hide() {
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
        waiting.clear()
        picked = null
    }

    internal fun transcribe(app: Context) {
        val rec = current ?: return
        picked = if (showingMode == Mode.ASK) Pick.TRANSCRIBE else null
        go(app, Mode.TRANSCRIBING, CallCardScreen.Done(Pick.TRANSCRIBE))
        step(app, { Decisions.keepFor(app, rec.id, transcribe = true, uploadAfterMs = doneMs, widget = false) })
    }

    internal fun audio(app: Context, chosen: Boolean) {
        val rec = current ?: return
        picked = if (chosen) Pick.AUDIO else null
        go(app, Mode.AUDIO, CallCardScreen.Done(Pick.AUDIO))
        step(app, { Decisions.keepFor(app, rec.id, transcribe = false, uploadAfterMs = doneMs, widget = false) })
    }

    internal fun delete(app: Context) {
        val rec = current ?: return
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
        val question = CallCardScreen.Ask(callCardTitle(app.resources, rec), rec.durationMs, rec.sourceApp)
        go(app, Mode.ASK, question)
        step(app, {
            if (was == Mode.DELETED) Decisions.undoDiscard(app, rec.id, widget = false) else Decisions.undoKeep(app, rec.id, widget = false)
        }) { back ->
            // Too late (sent already, or gone): the question has nothing left to ask.
            if (back == null) handler.post { if (showingFor == rec.id && showingMode == Mode.ASK) hide() }
        }
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
}
