package com.echoledger.mobile.ui.v2

import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.ui.test.assertIsOff
import androidx.compose.ui.test.assertIsOn
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import com.echoledger.mobile.calls.CallChoice
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.ui.v2.components.rememberEchoToastState
import com.echoledger.mobile.ui.v2.settings.CallsPage
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertTrue
import org.junit.Test
import org.robolectric.shadows.ShadowSettings

/**
 * v2.1 UX-13 in Settings › Calls & meetings: with the after-call flag on, Ask me says a quiet notification asks, and
 * "Show a small bar on screen" (off by default) turns the small bar on; with the flag off, the page is as before.
 */
class AfterCallSettingTest : ScreenTest() {
    private fun page(language: EchoLanguage = EchoLanguage.English) = show {
        val settings by vm.settings.collectAsState()
        EchoV2Theme(darkTheme = false, language = language) {
            CompositionLocalProvider(LocalEchoToasts provides rememberEchoToastState()) {
                CallsPage(settings, vm::updateSettings, highlight = null, onBack = {}, manufacturer = "samsung")
            }
        }
    }

    private fun flag(on: Boolean) {
        runBlocking { app.graph.settings.update { it.copy(uiFlags = mapOf(CallChoice.NOTIFY_FLAG to on)) } }
        pumpUntil("the page sees the flag") { vm.settings.value.uiFlags[CallChoice.NOTIFY_FLAG] == on }
    }

    @Test
    fun `with the flag on Ask me is a quiet notification and the small bar can be turned on`() {
        flag(true)
        ShadowSettings.setCanDrawOverlays(true)
        page()
        compose.onNodeWithText("A quiet notification after each call.").assertExists()
        compose.onNodeWithText("Show a small bar on screen").performScrollTo().assertIsOff()
        compose.onNodeWithText("Show a small bar on screen").performClick()
        pumpUntil("turned on") { vm.settings.value.afterCallBar }
        compose.onNodeWithText("Show a small bar on screen").assertIsOn()
    }

    @Test
    fun `in Urdu the switch and its line are the copy deck's`() {
        flag(true)
        page(EchoLanguage.Urdu)
        compose.onNodeWithText("اسکرین پر ایک چھوٹی پٹی دکھائیں").performScrollTo().assertExists()
        compose.onNodeWithText("ہر کال کے بعد ایک خاموش نوٹیفکیشن۔").assertExists()
    }

    @Test
    fun `with the flag off the page is as before`() {
        flag(false)
        page()
        assertTrue(compose.onAllNodesWithText("Show a small bar on screen").fetchSemanticsNodes().isEmpty())
        compose.onNodeWithText("A small card after each call.").assertExists()
    }
}
