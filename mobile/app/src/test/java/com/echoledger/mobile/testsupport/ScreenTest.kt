package com.echoledger.mobile.testsupport

import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.test.junit4.v2.createComposeRule
import com.echoledger.mobile.ui.EchoLedgerTheme
import com.echoledger.mobile.ui.MainViewModel
import org.junit.After
import org.junit.Before
import org.junit.Rule
import org.robolectric.annotation.Config

/**
 * Base class for Compose screen tests. The virtual screen is phone-wide and very tall so that lazily composed lists
 * show every row without scrolling.
 */
@Config(sdk = [34], qualifiers = "w411dp-h2400dp-mdpi")
abstract class ScreenTest : AppTest() {
    /**
     * Effects (a LaunchedEffect's load, a rememberCoroutineScope's call) resume on the main thread, as in the app. The
     * first rule's test dispatcher let an effect resume on the background thread its `withContext(Dispatchers.IO)`
     * call finished on, and the screen state it wrote there sometimes never reached the screen (P-078, P-100). Its
     * effect context could not change that: the rule puts its own dispatcher after it. This rule queues the resumed
     * effect with the test clock instead, and [pumpUntil] runs that queue on the main thread.
     */
    @get:Rule
    val compose = createComposeRule()

    protected lateinit var vm: MainViewModel
    private var showing by mutableStateOf(true)

    @Before
    fun createViewModel() {
        vm = MainViewModel(app).clearedAfterTest()
        pumpStep = { compose.waitForIdle() }
    }

    protected fun show(content: @Composable () -> Unit) {
        compose.setContent { EchoLedgerTheme { if (showing) content() } }
    }

    /**
     * The account's language for screens drawn in the shell (BE-06): signed in, the phone speaks the account's, never
     * the phone's own, so a test that draws a signed-in shell in Urdu sets it as the server would.
     */
    protected fun speak(language: com.echoledger.mobile.ui.v2.theme.EchoLanguage) {
        val code = if (language == com.echoledger.mobile.ui.v2.theme.EchoLanguage.Urdu) "ur" else "en"
        // Through the view model, with the main thread pumped: a blocking write on the main thread could wait for one of
        // the shell's own (which go on the main thread) and never end.
        vm.updateSettings { it.copy(accountLanguage = code) }
        pumpUntil("speaking $code") { vm.settings.value.accountLanguage == code }
    }

    /** Removes the screen before the app is released, so its effects (transcript and playback requests) stop with the test. */
    @After
    fun closeScreen() {
        showing = false
        compose.waitForIdle()
        pumpStep = null
    }
}
