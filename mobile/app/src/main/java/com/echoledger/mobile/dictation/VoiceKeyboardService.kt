package com.echoledger.mobile.dictation

import android.content.Intent
import android.inputmethodservice.InputMethodService
import android.view.KeyEvent
import android.view.View
import android.view.inputmethod.EditorInfo
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.ui.platform.ComposeView
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleOwner
import androidx.lifecycle.LifecycleRegistry
import androidx.lifecycle.lifecycleScope
import androidx.lifecycle.setViewTreeLifecycleOwner
import androidx.savedstate.SavedStateRegistry
import androidx.savedstate.SavedStateRegistryController
import androidx.savedstate.SavedStateRegistryOwner
import androidx.savedstate.setViewTreeSavedStateRegistryOwner
import com.echoledger.mobile.EchoLedgerApp
import com.echoledger.mobile.MainActivity
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.ui.EchoLedgerTheme
import com.echoledger.mobile.ui.KeyboardKeys
import com.echoledger.mobile.ui.VoiceKeyboard

/**
 * The EchoLedger voice keyboard (B14): dictate into any app. The text goes into the focused field at the cursor.
 * It streams the microphone for transcription only; it never makes a recording or asks to keep one.
 */
class VoiceKeyboardService :
    InputMethodService(),
    LifecycleOwner,
    SavedStateRegistryOwner,
    KeyboardKeys {
    private val registry = LifecycleRegistry(this)
    private val savedState = SavedStateRegistryController.create(this)
    override val lifecycle: Lifecycle get() = registry
    override val savedStateRegistry: SavedStateRegistry get() = savedState.savedStateRegistry

    private lateinit var session: DictationSession

    override fun onCreate() {
        super.onCreate()
        savedState.performRestore(null)
        registry.handleLifecycleEvent(Lifecycle.Event.ON_CREATE)
        session = DictationSession(this, lifecycleScope, ::insert)
    }

    override fun onCreateInputView(): View {
        window.window?.decorView?.let {
            it.setViewTreeLifecycleOwner(this)
            it.setViewTreeSavedStateRegistryOwner(this)
        }
        val settingsFlow = (application as EchoLedgerApp).graph.settings.settings
        return ComposeView(this).apply {
            setViewTreeLifecycleOwner(this@VoiceKeyboardService)
            setViewTreeSavedStateRegistryOwner(this@VoiceKeyboardService)
            setContent {
                val ui by session.ui.collectAsState()
                val settings by settingsFlow.collectAsState(AppSettings())
                EchoLedgerTheme {
                    VoiceKeyboard(
                        ui,
                        holdToTalk = settings.dictationMode == "push_to_talk",
                        needsMic = !session.hasMicPermission(),
                        keys = this@VoiceKeyboardService
                    )
                }
            }
        }
    }

    override fun onStartInputView(info: EditorInfo?, restarting: Boolean) {
        super.onStartInputView(info, restarting)
        registry.handleLifecycleEvent(Lifecycle.Event.ON_RESUME)
    }

    override fun onFinishInputView(finishingInput: Boolean) {
        session.cancel()
        registry.handleLifecycleEvent(Lifecycle.Event.ON_PAUSE)
        super.onFinishInputView(finishingInput)
    }

    override fun onDestroy() {
        session.release()
        registry.handleLifecycleEvent(Lifecycle.Event.ON_DESTROY)
        super.onDestroy()
    }

    private fun insert(text: String) {
        val connection = currentInputConnection ?: return
        connection.commitText(DictationText.forInsertion(connection.getTextBeforeCursor(1, 0), text), 1)
    }

    override fun start() = session.start()

    override fun finish() = session.finish()

    override fun space() {
        currentInputConnection?.commitText(" ", 1)
    }

    override fun backspace() {
        val connection = currentInputConnection ?: return
        if (connection.getSelectedText(0).isNullOrEmpty()) connection.deleteSurroundingText(1, 0) else connection.commitText("", 1)
    }

    override fun enter() {
        val action = (currentInputEditorInfo?.imeOptions ?: 0) and EditorInfo.IME_MASK_ACTION
        val multiLine = ((currentInputEditorInfo?.inputType ?: 0) and EditorInfo.TYPE_TEXT_FLAG_MULTI_LINE) != 0
        if (!multiLine && action != EditorInfo.IME_ACTION_NONE && action != EditorInfo.IME_ACTION_UNSPECIFIED) {
            currentInputConnection?.performEditorAction(action)
        } else {
            sendDownUpKeyEvents(KeyEvent.KEYCODE_ENTER)
        }
    }

    override fun switchKeyboard() {
        if (!switchToPreviousInputMethod()) {
            (getSystemService(INPUT_METHOD_SERVICE) as? android.view.inputmethod.InputMethodManager)?.showInputMethodPicker()
        }
    }

    override fun openApp() {
        startActivity(Intent(this, MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
    }
}
