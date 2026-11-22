package com.echoledger.mobile.core

import android.view.WindowManager
import java.util.concurrent.CopyOnWriteArrayList
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow

/**
 * EchoLedger's floating windows during a screen share or a screenshot (since v1.8.1; the call-choice plan, 2.0.5:
 * docs/v2/copy/call-choice.md `block_screens*`).
 *
 * The app's own screens carry FLAG_SECURE only while Settings › Privacy & data › "Block screenshots of EchoLedger" is
 * on ([block], off by default), so screenshots of the app work at any time, also while recording.
 *
 * The floating windows (the recording controls, captions, the after-call card, Live Translate's box, grip and toolbar,
 * the Live Assist card) carry FLAG_SECURE in "During screen sharing: Private (black box)", the default ([Mode]), and
 * while Block screenshots is on ([windowsSecure]): a screenshot, a screen share or a recording shows a black box where
 * they are, on every Android version, while the person sees them as usual. Only Show (with Block screenshots off)
 * leaves them readable and in screenshots. The flag cannot wait for a signal: Android's screen-recording callback
 * (Android 15+, [com.echoledger.mobile.overlay.ShareWatch]) counts only an app's visible activities in the recorded
 * display or task, never its overlay windows (AOSP ScreenRecordingCallbackController.uidHasRecordedActivity; measured on
 * the S25, docs/SCREEN_SHARE_MATRIX.md), so a floating window over another app is never reported.
 *
 * On top of the flag the windows shrink to a grey dot without text while the person hid them (Hide EchoLedger windows on
 * the recording controls or the Quick Settings tile), and in Private also while Android reports the app in a screen
 * share or recording (one of its own screens is in front). Public APIs only; nothing here hides EchoLedger from other
 * software.
 *
 * The settings survive the process ([Memory], restored by EchoLedgerApp before any window or the tile can ask), so a
 * window opened before the settings store has loaded already has the right flag. The person's Hide lasts the session
 * only.
 *
 * Safe from any thread: changes are made under one lock and listeners are told in order, on the thread that made the
 * change.
 */
object ShareGuard {
    /** Settings → Privacy & data → "During screen sharing". */
    enum class Mode(val wire: String) {
        /** "Private (black box)", the default. Stored as "hide", the value every build since v1.8.1 knows. */
        PRIVATE("hide"),

        /** Everyone watching a screen share can read the floating windows. */
        SHOW("show");

        companion object {
            /** "show" is Show; anything else ("hide", the v1.8.1 "black_box", missing or unknown) is Private. */
            fun of(wire: String?): Mode = if (wire == SHOW.wire) SHOW else PRIVATE
        }
    }

    /** Whether the windows show their text: never while the person hid them, nor while shared in Private. */
    fun showsText(mode: Mode, shared: Boolean, hiddenByUser: Boolean): Boolean = !hiddenByUser && !(shared && mode == Mode.PRIVATE)

    /** Whether the floating windows carry FLAG_SECURE: in Private, or while Block screenshots of EchoLedger is on. */
    fun secureWindows(mode: Mode, block: Boolean): Boolean = block || mode == Mode.PRIVATE

    /** A floating window's [flags] with FLAG_SECURE set or cleared for [secure]; every other flag stays as it is. */
    fun windowFlags(flags: Int, secure: Boolean): Int = if (secure) {
        flags or WindowManager.LayoutParams.FLAG_SECURE
    } else {
        flags and WindowManager.LayoutParams.FLAG_SECURE.inv()
    }

    /** Where the setting is kept between processes (a small preference file in the app). */
    interface Memory {
        fun read(): Saved?

        fun write(saved: Saved)
    }

    /** The two settings as kept between processes: During screen sharing and Block screenshots of EchoLedger. */
    data class Saved(val mode: Mode, val block: Boolean = false)

    private val lock = Any()
    private var memory: Memory? = null
    private val _mode = MutableStateFlow(Mode.PRIVATE)
    private val _block = MutableStateFlow(false)
    private val _shared = MutableStateFlow(false)
    private val _hiddenByUser = MutableStateFlow(false)
    private val _textVisible = MutableStateFlow(true)
    private val listeners = CopyOnWriteArrayList<(Boolean) -> Unit>()
    private val secureListeners = CopyOnWriteArrayList<(Boolean) -> Unit>()

    /** During screen sharing; Private until it has been read. */
    val mode: StateFlow<Mode> = _mode.asStateFlow()

    /** Block screenshots of EchoLedger (off by default): the app's screens and the floating windows are secure while on. */
    val block: StateFlow<Boolean> = _block.asStateFlow()

    /** Android reported our windows visible in a screen share or recording (Android 15+, our own screens only). */
    val shared: StateFlow<Boolean> = _shared.asStateFlow()

    /** The person hid the floating windows, until they show them again or the app restarts. */
    val hiddenByUser: StateFlow<Boolean> = _hiddenByUser.asStateFlow()

    /** [showsText] for the current state. */
    val textVisible: StateFlow<Boolean> = _textVisible.asStateFlow()

    fun setMode(mode: Mode) = change { _mode.value = mode }

    fun setBlock(block: Boolean) = change { _block.value = block }

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
     * At process start: the last saved settings (Private and screenshots allowed when nothing was saved), the floating
     * windows shown and nothing in a screen share; every later change of a setting is saved to [memory].
     */
    fun restore(memory: Memory) {
        val saved = runCatching { memory.read() }.getOrNull()
        synchronized(lock) {
            this.memory = null
            change {
                _mode.value = saved?.mode ?: Mode.PRIVATE
                _block.value = saved?.block ?: false
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

    /** Whether the floating windows carry FLAG_SECURE now ([secureWindows] of the two settings). */
    val windowsSecure: Boolean get() = synchronized(lock) { secureWindows(_mode.value, _block.value) }

    /** Calls [listener] with [windowsSecure] now and on every change; returns how to stop (the floating windows' flag). */
    fun observeSecure(listener: (Boolean) -> Unit): () -> Unit {
        synchronized(lock) {
            secureListeners += listener
            listener(secureWindows(_mode.value, _block.value))
        }
        return { secureListeners -= listener }
    }

    /** Applies [block] and tells the listeners what changed, all under the lock, so no thread sees a stale result. */
    private inline fun change(block: () -> Unit) {
        synchronized(lock) {
            val before = _mode.value
            val blockedBefore = _block.value
            block()
            val mode = _mode.value
            val blocked = _block.value
            if (mode != before || blocked != blockedBefore) memory?.let { m -> runCatching { m.write(Saved(mode, blocked)) } }
            val secure = secureWindows(mode, blocked)
            if (secure != secureWindows(before, blockedBefore)) secureListeners.forEach { it(secure) }
            val visible = showsText(mode, _shared.value, _hiddenByUser.value)
            if (visible != _textVisible.value) {
                _textVisible.value = visible
                listeners.forEach { it(visible) }
            }
        }
    }

    /**
     * Back to the defaults (tests). Secure-flag listeners stay: the floating windows' own one is registered once per process
     * (PrivateWindows), and a test stops the ones it added.
     */
    internal fun resetForTest() {
        synchronized(lock) {
            memory = null
            listeners.clear()
            _shared.value = false
            _hiddenByUser.value = false
            _textVisible.value = true
        }
        setMode(Mode.PRIVATE)
        setBlock(false)
    }
}
