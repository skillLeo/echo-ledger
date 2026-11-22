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
import kotlinx.coroutines.launch

/**
 * The after-call card (the call-choice plan, 2.0.5; docs/PLAN_CALL_CHOICE.md 3), at the bottom of the screen over
 * whatever app is open (SYSTEM_ALERT_WINDOW), above the navigation bar and inside the safe area. It never takes focus,
 * leaves the screen around it alone, and makes no sound.
 *
 * One window for one call at a time ([Mode]): the question ([Mode.ASK]: Delete, Transcribe, a swipe or 8 s for audio
 * only), then one line with Undo for 5 s ([Mode.TRANSCRIBING], [Mode.AUDIO] with Transcribe, [Mode.DELETED]). Undo
 * puts the question back with nothing decided: a kept call's upload waits for the line ([Decisions.keepFor]) and a
 * deleted call's file waits for it ([Decisions.discardLater]). Calls that end while the question is still asked are all
 * kept as audio only on one line ([Mode.GROUPED], "3 calls saved as audio · Review"), never a stack of cards.
 */
object DecisionPopup {
    enum class Mode { ASK, TRANSCRIBING, AUDIO, DELETED, GROUPED }

    /** How long the question waits, and how long a confirmation line stays. */
    const val ASK_MS = CallChoice.CARD_MS
    const val DONE_MS = CallChoice.DONE_MS

    /** Kept for the delayed delete: a Delete's "Deleted · Undo" line. */
    const val DISCARDED_MS = DONE_MS

    private val handler = Handler(Looper.getMainLooper())
    private var view: ComposeView? = null
    private var owner: OverlayOwner? = null
    private var windowManager: WindowManager? = null
    private var timeout: Runnable? = null
    private var screen by mutableStateOf<CallCardScreen?>(null)
    private var current: LocalRecording? = null
    private val grouped = mutableListOf<String>()

    /** The id of the call the card is about; null when no card is showing (or for the grouped line). */
    var showingFor: String? = null
        private set

    var showingMode: Mode? = null
        private set

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
            // A confirmation for an earlier call gives way to the new question (one line at a time).
            current = rec
            showingFor = rec.id
            go(app, Mode.ASK, question)
            return true
        }
        return open(app, rec, themeMode, question)
    }

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
                        modifier = Modifier.padding(horizontal = EchoTokens.Space.s16, vertical = EchoTokens.Space.s8)
                    )
                }
            }
        }
        val params = WindowManager.LayoutParams(
            WindowManager.LayoutParams.MATCH_PARENT,
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

    /** Shows [next] in the window already open and starts its time. */
    private fun go(app: Context, mode: Mode, next: CallCardScreen) {
        timeout?.let(handler::removeCallbacks)
        showingMode = mode
        screen = next
        val task = when (mode) {
            // Nobody chose: the call is kept as audio only, which the line then says.
            Mode.ASK -> Runnable { audio(app, chosen = false) }

            Mode.GROUPED -> Runnable {
                grouped.clear()
                hide()
            }

            else -> Runnable { hide() }
        }
        timeout = task
        handler.postDelayed(task, if (mode == Mode.ASK || mode == Mode.GROUPED) ASK_MS else DONE_MS)
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
    }

    internal fun transcribe(app: Context) {
        val rec = current ?: return
        if (showingMode == Mode.ASK) streak(app, Pick.TRANSCRIBE)
        go(app, Mode.TRANSCRIBING, CallCardScreen.Done(Pick.TRANSCRIBE))
        io { Decisions.keepFor(app, rec.id, transcribe = true, uploadAfterMs = DONE_MS) }
    }

    internal fun audio(app: Context, chosen: Boolean) {
        val rec = current ?: return
        if (chosen) streak(app, Pick.AUDIO)
        go(app, Mode.AUDIO, CallCardScreen.Done(Pick.AUDIO))
        io { Decisions.keepFor(app, rec.id, transcribe = false, uploadAfterMs = DONE_MS) }
    }

    internal fun delete(app: Context) {
        val rec = current ?: return
        streak(app, Pick.DELETE)
        go(app, Mode.DELETED, CallCardScreen.Done(Pick.DELETE))
        io {
            // Decided meanwhile (a notification, the app): nothing was deleted, so the line does not say so.
            if (Decisions.discardLater(app, rec.id) ==
                null
            ) {
                handler.post { if (showingFor == rec.id && showingMode == Mode.DELETED) hide() }
            }
        }
    }

    /** Undo on any line: the question again, with nothing decided and its 8 s from the start. */
    internal fun undo(app: Context) {
        val rec = current ?: return
        val was = showingMode
        val question = CallCardScreen.Ask(callCardTitle(app.resources, rec), rec.durationMs, rec.sourceApp)
        go(app, Mode.ASK, question)
        io {
            val back = if (was == Mode.DELETED) Decisions.undoDiscard(app, rec.id) else Decisions.undoKeep(app, rec.id)
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

    private fun keepQuietly(app: Context, id: String) = io { Decisions.keepFor(app, id, transcribe = false, uploadAfterMs = 0) }

    private fun streak(app: Context, pick: Pick) = update(app) { CallChoice.afterPick(it, pick) }

    private fun settings(app: Context): AppSettings = (app as? EchoLedgerApp)?.graph?.liveSettings?.value ?: AppSettings()

    private fun update(app: Context, change: (AppSettings) -> AppSettings) {
        val graph = (app as? EchoLedgerApp)?.graph ?: return
        io { graph.settings.update(change) }
    }

    private fun io(block: suspend () -> Unit) {
        CoroutineScope(Dispatchers.IO).launch { runCatching { block() } }
    }
}
