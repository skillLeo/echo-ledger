package com.echoledger.mobile.ui

import androidx.compose.ui.test.assertCountEquals
import androidx.compose.ui.test.assertIsOff
import androidx.compose.ui.test.assertIsOn
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onNodeWithContentDescription
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

/**
 * Settings → Privacy & data (the owner's decision, 2.0.5; words from docs/v2/copy/call-choice.md): Block screenshots of
 * EchoLedger (off by default) and Hide EchoLedger's live words from screenshots and screen shares (on by default). They
 * replace During screen sharing.
 */
class ScreenShareSettingTest : ScreenTest() {
    private val block = "Block screenshots of EchoLedger"
    private val live = "Hide EchoLedger's live words from screenshots and screen shares"

    private fun openPrivacy() {
        runBlocking { app.pairLocally(server) }
        show { SettingsScreen(vm, onSetup = {}) }
        compose.onNodeWithText("Privacy & data").performScrollTo().performClick()
        compose.onNodeWithText(live).assertExists()
    }

    @Test
    fun `two switches with the deck's lines, live words on and screenshots allowed, and no During screen sharing`() {
        openPrivacy()
        compose.onNodeWithContentDescription(block).performScrollTo().assertIsOff()
        compose.onNodeWithContentDescription(live).performScrollTo().assertIsOn()
        compose.onNodeWithText(
            "Off: you can take screenshots of the app, except screens that show keys or recovery codes. " +
                "On: EchoLedger's own screens can't be captured and are hidden in Recents.",
            useUnmergedTree = true
        ).assertExists()
        compose.onNodeWithText(
            "On: captions, Live Assist and Live Translate show as a black box in screenshots, screen recordings and screen shares. " +
                "The recording pill and the after-call card always show. Off: everything shows.",
            useUnmergedTree = true
        ).assertExists()
        listOf("During screen sharing", "Private (black box)", "On this Android version").forEach {
            compose.onAllNodesWithText(it, substring = true, useUnmergedTree = true).assertCountEquals(0)
        }
        compose.onAllNodesWithText("Show", useUnmergedTree = true).assertCountEquals(0)
    }

    @Test
    fun `each switch is saved and reaches the floating windows at once`() {
        openPrivacy()
        compose.onNodeWithContentDescription(live).performScrollTo().performClick()
        pumpUntil("live words shown, saved") { runBlocking { !app.currentSettings().hideLiveWords } }
        pumpUntil("and in the floating windows") { !ShareGuard.hideLive.value }
        compose.onNodeWithContentDescription(live).assertIsOff().performClick()
        pumpUntil("hidden again") { runBlocking { app.currentSettings().hideLiveWords } && ShareGuard.hideLive.value }

        compose.onNodeWithContentDescription(block).performScrollTo().performClick()
        pumpUntil("Block screenshots saved") { runBlocking { app.currentSettings().blockScreens } }
        compose.onNodeWithContentDescription(block).assertIsOn()
    }

    @Test
    fun `Show chosen before 2_0_5 shows as the switch off`() {
        runBlocking { app.graph.settings.update { it.copy(hideLiveWords = false) } }
        openPrivacy()
        compose.onNodeWithContentDescription(live).performScrollTo().assertIsOff()
    }

    @Test
    fun `search finds it by the words people use`() {
        listOf("floating windows", "black box", "screen share", "live words").forEach { words ->
            assertEquals(words, "privacy.share", SettingsIndex.search(words).first().item.id)
        }
        assertEquals("privacy.block", SettingsIndex.search("block screenshots").first().item.id)
    }
}
