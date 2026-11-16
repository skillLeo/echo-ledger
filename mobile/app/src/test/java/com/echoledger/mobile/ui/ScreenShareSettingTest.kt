package com.echoledger.mobile.ui

import androidx.compose.ui.semantics.SemanticsProperties
import androidx.compose.ui.test.SemanticsMatcher
import androidx.compose.ui.test.assertCountEquals
import androidx.compose.ui.test.assertIsNotSelected
import androidx.compose.ui.test.assertIsSelected
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import com.echoledger.mobile.core.SettingsIndex
import com.echoledger.mobile.core.ShareGuard
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.currentSettings
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Test

/** Settings → Privacy & data → During screen sharing (since v1.8.1): two choices, in the names of docs/DESIGN_SYSTEM.md. */
class ScreenShareSettingTest : ScreenTest() {
    private fun openPrivacy() {
        show { SettingsScreen(vm, onSetup = {}) }
        compose.onNodeWithText("Privacy & data").performScrollTo().performClick()
        compose.onNodeWithText("During screen sharing").assertExists()
    }

    private fun stored(): String = runBlocking { app.currentSettings().screenShareMode }

    @Test
    fun `two choices, Private first and chosen, with the plain line under them`() {
        runBlocking { app.pairLocally(server) }
        openPrivacy()

        compose.onNodeWithText("Private (black box)").assertIsSelected()
        compose.onNodeWithText("Show").assertIsNotSelected()
        compose.onAllNodes(SemanticsMatcher.keyIsDefined(SemanticsProperties.Selected)).assertCountEquals(2)
        compose.onNodeWithText(
            "In a screen share, recording or screenshot, the floating windows are a black box. You see them as usual.",
            useUnmergedTree = true
        ).assertExists()
        compose.onNodeWithText("Everyone watching can read them.", useUnmergedTree = true).assertExists()
        compose.onNodeWithText(
            "Android doesn't tell apps when a floating window is in a screen share, so the black box is always on unless you choose Show."
        ).assertExists()
        listOf("Hide cards", "Black box").forEach { compose.onAllNodesWithText(it, useUnmergedTree = true).assertCountEquals(0) }
    }

    @Test
    fun `Show and Private are stored as show and hide, and reach the floating windows at once`() {
        runBlocking { app.pairLocally(server) }
        openPrivacy()

        compose.onNodeWithText("Show").performClick()
        pumpUntil("Show to be saved") { stored() == "show" }
        // The store commits on its own thread; the save's own continuation tells the floating windows on the main one.
        pumpUntil("Show to reach the floating windows") { ShareGuard.mode.value == ShareGuard.Mode.SHOW }
        compose.onNodeWithText("Show").assertIsSelected()

        compose.onNodeWithText("Private (black box)").performClick()
        pumpUntil("Private to be saved") { stored() == "hide" }
        pumpUntil("Private to reach the floating windows") { ShareGuard.mode.value == ShareGuard.Mode.PRIVATE }
    }

    @Test
    fun `a Black box chosen in v1_8_1 shows as Private`() {
        runBlocking {
            app.pairLocally(server)
            app.graph.settings.update { it.copy(screenShareMode = "black_box") }
        }
        openPrivacy()
        compose.onNodeWithText("Private (black box)").assertIsSelected()
        assertEquals("black_box", stored())
    }

    @Test
    fun `search finds it by the words people use`() {
        listOf("floating windows", "black box", "screen share", "private").forEach { words ->
            assertEquals(words, "privacy.share", SettingsIndex.search(words).first().item.id)
        }
    }
}
