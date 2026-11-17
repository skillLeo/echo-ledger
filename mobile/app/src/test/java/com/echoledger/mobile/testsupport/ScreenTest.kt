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

    /** Removes the screen before the app is released, so its effects (transcript and playback requests) stop with the test. */
    @After
    fun closeScreen() {
        showing = false
        compose.waitForIdle()
        pumpStep = null
    }
}
