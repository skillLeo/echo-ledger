package com.echoledger.mobile.core

import java.util.concurrent.CopyOnWriteArrayList
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow

/**
 * Private cards during screen sharing (since v1.8.1, docs/research/screen-share-privacy.md): EchoLedger's floating
 * windows (the recording pill, captions, the after-call card, Live Translate, later the Assist card) collapse to a dot
 * without text while Android says they are visible in a screen share or recording, or while the person hid them (the
 * Hide button on a card or the Quick Settings tile). "Black box" marks the windows secure instead (FLAG_SECURE), and
 * "Show" leaves them as they are. Public APIs only; nothing here hides EchoLedger from other software.
 */
object ShareGuard {
    /** Settings → Privacy & data → "During screen sharing". */
    enum class Mode(val wire: String) {
        HIDE("hide"),
        BLACK_BOX("black_box"),
        SHOW("show");

        companion object {
            fun of(wire: String?): Mode = entries.firstOrNull { it.wire == wire } ?: HIDE
        }
    }

    /** Whether the cards show their text: never while the person hid them, nor while shared in "Hide cards" mode. */
    fun showsText(mode: Mode, shared: Boolean, hiddenByUser: Boolean): Boolean = !hiddenByUser && !(shared && mode == Mode.HIDE)

    /** Whether the windows are marked secure, so shares and recordings show a black box where they are. */
    fun secure(mode: Mode): Boolean = mode == Mode.BLACK_BOX

    private val _mode = MutableStateFlow(Mode.HIDE)
    private val _shared = MutableStateFlow(false)
    private val _hiddenByUser = MutableStateFlow(false)
    private val _textVisible = MutableStateFlow(true)
    private val listeners = CopyOnWriteArrayList<(Boolean) -> Unit>()

    val mode: StateFlow<Mode> = _mode.asStateFlow()

    /** Android reported our windows visible in a screen share or recording (Android 15+). */
    val shared: StateFlow<Boolean> = _shared.asStateFlow()

    /** The person hid the cards (until they show them again or the app restarts). */
    val hiddenByUser: StateFlow<Boolean> = _hiddenByUser.asStateFlow()

    /** [showsText] for the current state. */
    val textVisible: StateFlow<Boolean> = _textVisible.asStateFlow()

    fun setMode(mode: Mode) {
        _mode.value = mode
        update()
    }

    fun setShared(shared: Boolean) {
        _shared.value = shared
        update()
    }

    fun setHidden(hidden: Boolean) {
        _hiddenByUser.value = hidden
        update()
    }

    fun toggleHidden() = setHidden(!_hiddenByUser.value)

    /** Calls [listener] with [textVisible] now and on every change; returns how to stop. For windows that are not Compose. */
    fun observe(listener: (Boolean) -> Unit): () -> Unit {
        listeners += listener
        listener(_textVisible.value)
        return { listeners -= listener }
    }

    private fun update() {
        val visible = showsText(_mode.value, _shared.value, _hiddenByUser.value)
        if (visible != _textVisible.value) {
            _textVisible.value = visible
            listeners.forEach { it(visible) }
        }
    }

    /** Back to the defaults (tests). */
    internal fun resetForTest() {
        listeners.clear()
        _mode.value = Mode.HIDE
        _shared.value = false
        _hiddenByUser.value = false
        _textVisible.value = true
    }
}
