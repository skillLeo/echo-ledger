package com.echoledger.mobile.ui.v2

import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.ui.test.assertIsEnabled
import androidx.compose.ui.test.assertIsNotEnabled
import androidx.compose.ui.test.assertIsNotSelected
import androidx.compose.ui.test.assertIsSelected
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.isSelectable
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import com.echoledger.mobile.core.SettingsIndex
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.ui.v2.settings.CallsPage
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Settings › Recording › After each call (the call-choice plan, 2.0.5): Ask me (the default), Transcribe every call, Save
 * audio only and Don't record calls, each with its line; under it When to ask, After the call (recommended, the default)
 * or At the start of the call, which only Ask me uses.
 */
class AfterEachCallSettingTest : ScreenTest() {
    private fun radio(label: String) = compose.onNode(hasText(label) and isSelectable())

    private fun open() = show {
        val settings by vm.settings.collectAsState()
        com.echoledger.mobile.ui.v2.theme.EchoV2Theme(
            darkTheme = false,
            language = com.echoledger.mobile.ui.v2.theme.EchoLanguage.English
        ) {
            androidx.compose.runtime.CompositionLocalProvider(
                com.echoledger.mobile.ui.v2.shell.LocalEchoToasts provides com.echoledger.mobile.ui.v2.components.rememberEchoToastState()
            ) {
                CallsPage(settings, vm::updateSettings, highlight = "calls.after", onBack = {}, manufacturer = "samsung")
            }
        }
    }

    @Test
    fun `four choices with their lines, Ask me first and chosen, then When to ask`() {
        open()
        compose.onNodeWithText("After each call").assertExists()
        radio("Ask me").assertIsSelected()
        listOf("Transcribe every call", "Save audio only", "Don't record calls").forEach { radio(it).assertIsNotSelected() }
        listOf(
            "A small card after each call.",
            "No question.",
            "No question. Transcribe later from the Library if you want.",
            "Calls are not recorded. You can still record one from the pill or Record."
        ).forEach { line -> assertTrue(line, compose.onAllNodesWithText(line, useUnmergedTree = true).fetchSemanticsNodes().isNotEmpty()) }
        compose.onNodeWithText("When to ask").assertExists()
        radio("After the call (recommended)").assertIsSelected().assertIsEnabled()
        radio("At the start of the call").assertIsNotSelected()
    }

    @Test
    fun `each choice is saved, and When to ask only counts for Ask me`() {
        open()
        radio("At the start of the call").performScrollTo().performClick()
        pumpUntil("At the start") { vm.settings.value.whenToAsk == "start" }
        listOf(
            "Transcribe every call" to "transcribe",
            "Save audio only" to "audio",
            "Don't record calls" to "off"
        ).forEach { (label, value) ->
            radio(label).performScrollTo().performClick()
            pumpUntil(label) { vm.settings.value.afterEachCall == value }
            radio("At the start of the call").assertIsNotEnabled()
        }
        radio("Ask me").performScrollTo().performClick()
        pumpUntil("Ask me") { vm.settings.value.afterEachCall == "ask" }
        radio("At the start of the call").assertIsEnabled().assertIsSelected()
    }

    @Test
    fun `search finds it by the words people use`() {
        listOf("after each call", "ask me", "transcribe every call", "don't record calls", "when to ask").forEach { words ->
            assertEquals(words, "calls.after", SettingsIndex.search(words).first().item.id)
        }
    }
}
