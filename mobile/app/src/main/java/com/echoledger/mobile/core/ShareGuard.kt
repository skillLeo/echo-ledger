package com.echoledger.mobile.core

import android.view.WindowManager
import java.util.concurrent.CopyOnWriteArrayList
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow

/**
 * EchoLedger's floating windows in screenshots and screen shares (since v1.8.1; the owner's decision of 2.0.5 in
 * docs/PLAN_CALL_CHOICE.md, words in docs/v2/copy/call-choice.md `hide_live_words`).
 *
 * - The recording controls (the pill and its menu) and the after-call card never carry FLAG_SECURE: they show in every
 *   screenshot and screen share.
 * - The windows with live words (captions, the Live Assist card, Live Translate's box, grip, dot and toolbar) carry it
 *   while Settings › Privacy & data › "Hide EchoLedger's live words from screenshots and screen shares" is on
 *   ([hideLive], on by default), on every Android version: a screenshot, recording or share shows a black box where they
 *   are, while the person sees them as usual. Off, every floating window is plain. The flag cannot wait for a signal:
 *   Android's screen-recording callback (Android 15+, [com.echoledger.mobile.overlay.ShareWatch]) counts only an app's
 *   visible activities, never its overlay windows (AOSP ScreenRecordingCallbackController.uidHasRecordedActivity;
 *   measured on the S25, docs/SCREEN_SHARE_MATRIX.md).
 * - Where that callback does fire (one of the app's own screens is in the share), the live-words windows also hide their
 *   text ([textVisible]) while the setting is on.
 * - The person's own Hide EchoLedger windows (the pill's menu, the Quick Settings tile) shrinks every floating window to
 *   a grey dot until they show them again; it lasts the session only.
 *
 * The setting survives the process ([Memory], restored by EchoLedgerApp before any window or the tile can ask), so a
 * window opened before the settings store has loaded already has the right flag. Public APIs only; nothing here hides
 * EchoLedger from other software.
 *
 * Safe from any thread: changes are made under one lock and listeners are told in order, on the thread that made the
 * change.
 */
object ShareGuard {
    /** The live-words windows show their text: never while the person hid them, nor while shared with the setting on. */
    fun showsText(hideLive: Boolean, shared: Boolean, hiddenByUser: Boolean): Boolean = !hiddenByUser && !(shared && hideLive)

    /** Whether a floating window carries FLAG_SECURE: one with live words, while Hide live words is on. */
    fun secureWindow(liveWords: Boolean, hideLive: Boolean): Boolean = liveWords && hideLive

    /** A floating window's [flags] with FLAG_SECURE set or cleared for [secure]; every other flag stays as it is. */
    fun windowFlags(flags: Int, secure: Boolean): Int = if (secure) {
        flags or WindowManager.LayoutParams.FLAG_SECURE
    } else {
        flags and WindowManager.LayoutParams.FLAG_SECURE.inv()
    }

    /** Where the setting is kept between processes. */
    interface Memory {
        fun read(): Saved?

        fun write(saved: Saved)
    }

    /** The setting as kept between processes: Hide EchoLedger's live words. */
    data class Saved(val hideLive: Boolean)

    private val lock = Any()
    private var memory: Memory? = null
    private val _hideLive = MutableStateFlow(true)
    private val _shared = MutableStateFlow(false)
    private val _hiddenByUser = MutableStateFlow(false)
    private val _textVisible = MutableStateFlow(true)
    private val listeners = CopyOnWriteArrayList<(Boolean) -> Unit>()
    private val secureListeners = CopyOnWriteArrayList<(Boolean) -> Unit>()

    /** Hide EchoLedger's live words; on until it has been read, so a window that opens first is never readable in a share. */
    val hideLive: StateFlow<Boolean> = _hideLive.asStateFlow()

    /** Android reported the app in a screen share or recording (Android 15+, its own screens only). */
    val shared: StateFlow<Boolean> = _shared.asStateFlow()

    /** The person hid every floating window (the grey dot). Starts false in every process. */
    val hiddenByUser: StateFlow<Boolean> = _hiddenByUser.asStateFlow()

    /** Whether the live-words windows show their text now ([showsText]). */
    val textVisible: StateFlow<Boolean> = _textVisible.asStateFlow()

    fun setHideLive(hide: Boolean) = change { _hideLive.value = hide }

    fun setShared(shared: Boolean) = change { _shared.value = shared }

    fun setHidden(hidden: Boolean) = change { _hiddenByUser.value = hidden }

    /** Hides the windows, or shows them again (the tile); returns whether they are hidden now. One step, so taps never mix. */
    fun toggleHidden(): Boolean {
        var hidden = false
        change {
            hidden = !_hiddenByUser.value
            _hiddenByUser.value = hidden
        }
        return hidden
    }

    /**
     * At process start: the last saved setting (on when nothing was saved), the floating windows shown and nothing in a
     * screen share; every later change of the setting is saved to [memory].
     */
    fun restore(memory: Memory) {
        val saved = runCatching { memory.read() }.getOrNull()
        synchronized(lock) {
            this.memory = null
            change {
                _hideLive.value = saved?.hideLive ?: true
                _hiddenByUser.value = false
                _shared.value = false
            }
            this.memory = memory
        }
    }

    /** Calls [listener] with [textVisible] now and on every change; returns how to stop. For views that are not Compose. */
    fun observe(listener: (Boolean) -> Unit): () -> Unit {
        synchronized(lock) {
            listeners += listener
            listener(_textVisible.value)
        }
        return { listeners -= listener }
    }

    /** Calls [listener] with [hideLive] now and on every change; returns how to stop (the live-words windows' flag). */
    fun observeHideLive(listener: (Boolean) -> Unit): () -> Unit {
        synchronized(lock) {
            secureListeners += listener
            listener(_hideLive.value)
        }
        return { secureListeners -= listener }
    }

    /** Applies [block] and tells the listeners what changed, all under the lock, so no thread sees a stale result. */
    private inline fun change(block: () -> Unit) {
        synchronized(lock) {
            val before = _hideLive.value
            block()
            val hide = _hideLive.value
            if (hide != before) {
                memory?.let { m -> runCatching { m.write(Saved(hide)) } }
                secureListeners.forEach { it(hide) }
            }
            val visible = showsText(hide, _shared.value, _hiddenByUser.value)
            if (visible != _textVisible.value) {
                _textVisible.value = visible
                listeners.forEach { it(visible) }
            }
        }
    }

    /**
     * Back to the defaults (tests). Secure-flag listeners stay: the floating windows' own one is registered once per
     * process (PrivateWindows), and a test stops the ones it added.
     */
    internal fun resetForTest() {
        synchronized(lock) {
            memory = null
            listeners.clear()
            _shared.value = false
            _hiddenByUser.value = false
            _textVisible.value = true
        }
        setHideLive(true)
    }
}
