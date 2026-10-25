package com.echoledger.mobile.testsupport

import androidx.compose.runtime.Composable
import androidx.compose.ui.test.junit4.createComposeRule
import com.echoledger.mobile.ui.EchoLedgerTheme
import com.echoledger.mobile.ui.MainViewModel
import org.junit.Before
import org.junit.Rule
import org.robolectric.annotation.Config

/**
 * Base class for Compose screen tests. The virtual screen is phone-wide and very tall so that lazily composed lists
 * show every row without scrolling.
 */
@Config(sdk = [34], qualifiers = "w411dp-h2400dp-mdpi")
abstract class ScreenTest : AppTest() {
    @get:Rule
    val compose = createComposeRule()

    protected lateinit var vm: MainViewModel

    @Before
    fun createViewModel() {
        vm = MainViewModel(app)
    }

    protected fun show(content: @Composable () -> Unit) {
        compose.setContent { EchoLedgerTheme { content() } }
    }
}
