package com.echoledger.mobile.overlay

import android.os.Handler
import android.os.Looper
import android.view.View
import android.view.WindowManager
import com.echoledger.mobile.core.ShareGuard

/**
 * The one way EchoLedger's floating windows reach the screen (since v1.8.1): the recording controls, captions, the
 * after-call card, the Live Assist card and Live Translate's box, grip dot and toolbar are added, changed and removed
 * here. A window with live words ([FloatingWindow.liveWords]: captions, Live Assist, Live Translate) carries FLAG_SECURE
 * from before its first frame while Hide EchoLedger's live words is on ([ShareGuard.hideLive], on by default); the
 * recording controls and the after-call card never do, so they show in screenshots and screen shares. Every later
 * change keeps the flag right, and a change of the setting reaches the windows already open at once.
 *
 * Every window also holds [ShareWatch] while it is open (on Android 15+ the live-words windows hide their text while
 * Android reports one of the app's own screens in a share). Main thread only; a setting that changes on another thread
 * is posted to it.
 */
internal object PrivateWindows {
    private class Open(val wm: WindowManager, val view: View, val params: WindowManager.LayoutParams, val liveWords: Boolean)

    private val open = ArrayList<Open>()
    private val main by lazy { Handler(Looper.getMainLooper()) }
    private var following = false

    /** Whether the window titled [title] shows live words; an unknown one is treated as one (the safe side). */
    private fun liveWords(title: CharSequence?): Boolean =
        FloatingWindow.entries.firstOrNull { it.title == title?.toString() }?.liveWords ?: true

    private fun secure(liveWords: Boolean) = ShareGuard.secureWindow(liveWords, ShareGuard.hideLive.value)

    /** Adds [view] as a floating window with [params]; false when Android refused it (no overlay permission). */
    fun add(wm: WindowManager, view: View, params: WindowManager.LayoutParams): Boolean {
        follow()
        val live = liveWords(params.title)
        params.flags = ShareGuard.windowFlags(params.flags, secure(live))
        if (runCatching { wm.addView(view, params) }.isFailure) return false
        open += Open(wm, view, params, live)
        ShareWatch.acquire(view.context)
        return true
    }

    /** Applies [params] (moved, resized, new flags) to an open window, with the secure flag the setting asks for. */
    fun update(wm: WindowManager, view: View, params: WindowManager.LayoutParams) {
        val live = open.firstOrNull { it.view === view }?.liveWords ?: liveWords(params.title)
        params.flags = ShareGuard.windowFlags(params.flags, secure(live))
        runCatching { wm.updateViewLayout(view, params) }
    }

    /** Removes a window [add] opened; nothing happens for one it does not know. */
    fun remove(wm: WindowManager, view: View) {
        val index = open.indexOfFirst { it.view === view }
        if (index < 0) return
        open.removeAt(index)
        runCatching { wm.removeView(view) }
        ShareWatch.release(view.context)
    }

    /** How many floating windows are open (tests). */
    internal val count: Int get() = open.size

    /** The setting changed: the open live-words windows get the flag set or cleared now. */
    private fun apply() {
        open.forEach { w ->
            val flags = ShareGuard.windowFlags(w.params.flags, secure(w.liveWords))
            if (flags != w.params.flags) {
                w.params.flags = flags
                runCatching { w.wm.updateViewLayout(w.view, w.params) }
            }
        }
    }

    /** Listens to the setting once per process: on the main thread at once, from elsewhere through the main queue. */
    private fun follow() {
        if (following) return
        following = true
        ShareGuard.observeHideLive {
            if (Looper.myLooper() == Looper.getMainLooper()) apply() else main.post(::apply)
        }
    }
}

/**
 * Each of EchoLedger's floating windows: its title (as `adb shell dumpsys window` lists it, next to SECURE), its flags
 * before FLAG_SECURE, and whether it shows live words ([liveWords]: [PrivateWindows] adds the flag to those while Hide
 * EchoLedger's live words is on). Live Translate's box uses [TranslateOverlay.EDIT_FLAGS] while it is being moved.
 */
internal enum class FloatingWindow(val title: String, val flags: Int, val liveWords: Boolean) {
    RECORDING_CONTROLS("EchoLedger recording controls", TOUCHABLE, liveWords = false),
    CAPTIONS("EchoLedger captions", TOUCHABLE, liveWords = true),
    AFTER_CALL_CARD(
        "EchoLedger after-call card",
        WindowManager.LayoutParams.FLAG_NOT_FOCUSABLE or WindowManager.LayoutParams.FLAG_NOT_TOUCH_MODAL or
            WindowManager.LayoutParams.FLAG_LAYOUT_IN_SCREEN,
        liveWords = false
    ),

    /** "Recording this call · Don't record" at the start of a call (2.0.5): it always shows, like the pill. */
    START_BANNER(
        "EchoLedger start banner",
        WindowManager.LayoutParams.FLAG_NOT_FOCUSABLE or WindowManager.LayoutParams.FLAG_NOT_TOUCH_MODAL or
            WindowManager.LayoutParams.FLAG_LAYOUT_IN_SCREEN,
        liveWords = false
    ),
    LIVE_TRANSLATE("EchoLedger Live Translate", TranslateOverlay.LOCKED_FLAGS, liveWords = true),

    /** This phone's Live Assist card: touchable, never takes the keyboard; touches outside go to the call underneath. */
    ASSIST_CARD(
        "EchoLedger Live Assist card",
        WindowManager.LayoutParams.FLAG_NOT_FOCUSABLE or WindowManager.LayoutParams.FLAG_NOT_TOUCH_MODAL or
            WindowManager.LayoutParams.FLAG_LAYOUT_IN_SCREEN,
        liveWords = true
    ),
    LIVE_TRANSLATE_GRIP(
        "EchoLedger Live Translate grip",
        WindowManager.LayoutParams.FLAG_NOT_FOCUSABLE or WindowManager.LayoutParams.FLAG_LAYOUT_IN_SCREEN,
        liveWords = true
    ),
    LIVE_TRANSLATE_TOOLBAR("EchoLedger Live Translate toolbar", TOUCHABLE, liveWords = true),

    /** Only while the floating windows are hidden: the grey dot that shows them again, where the grip sits. */
    LIVE_TRANSLATE_DOT(
        "EchoLedger Live Translate hidden dot",
        WindowManager.LayoutParams.FLAG_NOT_FOCUSABLE or WindowManager.LayoutParams.FLAG_LAYOUT_IN_SCREEN,
        liveWords = true
    )
}

/** Touchable, never takes the keyboard, and hears a tap outside (to close a menu). */
private const val TOUCHABLE = WindowManager.LayoutParams.FLAG_NOT_FOCUSABLE or WindowManager.LayoutParams.FLAG_LAYOUT_IN_SCREEN or
    WindowManager.LayoutParams.FLAG_WATCH_OUTSIDE_TOUCH
