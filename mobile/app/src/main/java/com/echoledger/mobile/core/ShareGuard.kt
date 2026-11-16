package com.echoledger.mobile.core

import android.view.WindowManager
import java.util.concurrent.CopyOnWriteArrayList
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow

/**
 * EchoLedger's floating windows during a screen share (since v1.8.1, docs/research/screen-share-privacy.md; names in
 * docs/DESIGN_SYSTEM.md "Screen-share privacy names").
 *
 * The floating windows (the recording controls, captions, the after-call card, Live Translate's box, grip and toolbar,
 * later the Assist card) carry FLAG_SECURE in "Private (black box)", the default, so a screen share or recording shows
 * a black box where they are while the person sees them as usual; only "Show" leaves them readable. The flag never
 * waits for a signal: Android tells an app about a recording only while one of its own screens is in front, never for
 * a floating window over another app (measured on the S25, docs/SCREEN_SHARE_MATRIX.md).
 *
 * On top of the flag the windows shrink to a grey dot without text while the person hid them (Hide EchoLedger windows
 * on the recording controls or the Quick Settings tile), and in Private also while Android reports them in a screen
 * share or recording. Public APIs only; nothing here hides EchoLedger from other software.
 *
 * The setting and the person's Hide survive the process ([Memory], restored by EchoLedgerApp before any window or the
 * tile can ask), so a window opened before the settings store has loaded already has the right flag.
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

    /** Whether the windows carry FLAG_SECURE, so a screen share or recording shows a black box where they are. */
    fun secure(mode: Mode): Boolean = mode == Mode.PRIVATE

    /** A floating window's [flags] with FLAG_SECURE set or cleared for [mode]; every other flag stays as it is. */
    fun windowFlags(flags: Int, mode: Mode): Int = if (secure(mode)) {
        flags or WindowManager.LayoutParams.FLAG_SECURE
    } else {
        flags and WindowManager.LayoutParams.FLAG_SECURE.inv()
    }

    /** Where the setting and the person's Hide are kept between processes (a small preference file in the app). */
    interface Memory {
        fun read(): Saved?

        fun write(saved: Saved)
    }

    data class Saved(val mode: Mode, val hidden: Boolean)

    private val lock = Any()
    private var memory: Memory? = null
    private val _mode = MutableStateFlow(Mode.PRIVATE)
    private val _shared = MutableStateFlow(false)
    private val _hiddenByUser = MutableStateFlow(false)
    private val _textVisible = MutableStateFlow(true)
    private val listeners = CopyOnWriteArrayList<(Boolean) -> Unit>()
    private val modeListeners = CopyOnWriteArrayList<(Mode) -> Unit>()

    /** The setting; Private until it has been read, so a window that opens first is never readable in a screen share. */
    val mode: StateFlow<Mode> = _mode.asStateFlow()

    /** Android reported our windows visible in a screen share or recording (Android 15+, our own screens only). */
    val shared: StateFlow<Boolean> = _shared.asStateFlow()

    /** The person hid the floating windows, until they show them again (kept across restarts). */
    val hiddenByUser: StateFlow<Boolean> = _hiddenByUser.asStateFlow()

    /** [showsText] for the current state. */
    val textVisible: StateFlow<Boolean> = _textVisible.asStateFlow()

    fun setMode(mode: Mode) = change { _mode.value = mode }

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
     * At process start: the last saved setting and Hide (Private and shown when nothing was saved), nothing in a screen
     * share; every later change is saved to [memory].
     */
    fun restore(memory: Memory) {
        val saved = runCatching { memory.read() }.getOrNull()
        synchronized(lock) {
            this.memory = null
            change {
                _mode.value = saved?.mode ?: Mode.PRIVATE
                _hiddenByUser.value = saved?.hidden == true
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

    /** Calls [listener] with [mode] now and on every change; returns how to stop (the floating windows' secure flag). */
    fun observeMode(listener: (Mode) -> Unit): () -> Unit {
        synchronized(lock) {
            modeListeners += listener
            listener(_mode.value)
        }
        return { modeListeners -= listener }
    }

    /** Applies [block] and tells the listeners what changed, all under the lock, so no thread sees a stale result. */
    private inline fun change(block: () -> Unit) {
        synchronized(lock) {
            val before = _mode.value
            val wasHidden = _hiddenByUser.value
            block()
            val mode = _mode.value
            val hidden = _hiddenByUser.value
            if (mode != before || hidden != wasHidden) memory?.let { m -> runCatching { m.write(Saved(mode, hidden)) } }
            if (mode != before) modeListeners.forEach { it(mode) }
            val visible = showsText(mode, _shared.value, hidden)
            if (visible != _textVisible.value) {
                _textVisible.value = visible
                listeners.forEach { it(visible) }
            }
        }
    }

    /**
     * Back to the defaults (tests). Mode listeners stay: the floating windows' own one is registered once per process
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
    }
}
