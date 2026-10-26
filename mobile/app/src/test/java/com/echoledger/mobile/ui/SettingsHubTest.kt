package com.echoledger.mobile.ui

import androidx.compose.ui.test.assertCountEquals
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.hasSetTextAction
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performTextInput
import com.echoledger.mobile.core.SettingsGroup
import com.echoledger.mobile.testsupport.ScreenTest
import org.junit.Test

/** The Settings tab laid out like the system settings app: a search box, eight groups, one screen per group (B19). */
class SettingsHubTest : ScreenTest() {
    private fun showSettings() = show { SettingsScreen(vm, onSetup = {}) }

    private fun search(text: String) {
        compose.onNode(hasSetTextAction()).performTextInput(text)
        compose.waitForIdle()
    }

    @Test
    fun `settings opens on a search box and the eight groups with their summaries`() {
        showSettings()

        compose.onNodeWithText("Settings").assertIsDisplayed()
        compose.onNodeWithText("Search settings").assertIsDisplayed()
        SettingsGroup.entries.forEach { group ->
            compose.onNodeWithText(group.label).assertIsDisplayed()
            compose.onNodeWithText(group.summary).assertIsDisplayed()
        }
    }

    @Test
    fun `a group is its own screen with a back arrow to the list`() {
        showSettings()

        compose.onNodeWithText("Recording").performClick()
        compose.onNodeWithText("After a call").assertIsDisplayed()
        compose.onNodeWithText("Auto-record apps").assertIsDisplayed()
        compose.onAllNodesWithText("Microphone")[0].assertIsDisplayed()
        compose.onAllNodesWithText("Search settings").assertCountEquals(0)

        compose.onNodeWithContentDescription("Back").performClick()
        compose.onNodeWithText("Search settings").assertIsDisplayed()
        compose.onAllNodesWithText("After a call").assertCountEquals(0)
    }

    @Test
    fun `each group shows its own settings`() {
        showSettings()

        mapOf(
            "General" to listOf("Transcription", "Client keyterms", "Notifications", "Privacy & consent"),
            "Captions" to listOf("Live captions while recording", "Caption style"),
            "Dictation" to listOf("Voice keyboard"),
            "Storage" to listOf("Storage and retention"),
            "About" to listOf("Export settings", "Import settings")
        ).forEach { (group, titles) ->
            compose.onNodeWithText(group).performClick()
            titles.forEach { compose.onNodeWithText(it).assertIsDisplayed() }
            compose.onNodeWithContentDescription("Back").performClick()
        }
    }

    @Test
    fun `searching lists the matching settings with their group and hides the group list`() {
        showSettings()

        search("quiet")

        compose.onNodeWithText("Notifications").assertIsDisplayed()
        compose.onNodeWithText("General").assertIsDisplayed()
        compose.onAllNodesWithText("Storage").assertCountEquals(0)
        compose.onAllNodesWithText(SettingsGroup.Recording.summary).assertCountEquals(0)
    }

    @Test
    fun `a search result jumps into its group`() {
        showSettings()

        search("quiet hours")
        compose.onNodeWithText("Notifications").performClick()

        compose.onNodeWithText("Show notifications").assertIsDisplayed()
        compose.onNodeWithText("Quiet hours").assertIsDisplayed()
        compose.onNodeWithText("Client keyterms").assertIsDisplayed()
        compose.onNodeWithContentDescription("Back").performClick()
        compose.onNodeWithText("Search settings").assertIsDisplayed()
    }

    @Test
    fun `a keyword finds a setting that its name does not mention`() {
        showSettings()

        search("retention")
        compose.onNodeWithText("Storage and retention").performClick()

        compose.onNodeWithText("Storage and retention").assertIsDisplayed()
        compose.onNodeWithText("Couldn't load the server's storage. Check the connection.").assertIsDisplayed()
        compose.onNodeWithText("Retry").assertIsDisplayed()
    }

    @Test
    fun `no match says so and clearing the search brings the groups back`() {
        showSettings()

        search("zzzz")
        compose.onNodeWithText("No settings match “zzzz”.").assertIsDisplayed()
        compose.onAllNodesWithText("Recording").assertCountEquals(0)

        compose.onNodeWithContentDescription("Clear search").performClick()
        compose.onNodeWithText("Recording").assertIsDisplayed()
        compose.onAllNodesWithText("No settings match “zzzz”.").assertCountEquals(0)
    }

    @Test
    fun `about shows the version and the server the phone is paired with`() {
        runBlockingSettings { it.copy(serverUrl = "https://ledger.example.com", serverApiVersion = "1.1", deviceId = "D") }
        showSettings()

        compose.onNodeWithText("About").performClick()

        compose.onNodeWithText("Server ledger.example.com · API 1.1").assertIsDisplayed()
    }

    private fun runBlockingSettings(change: (com.echoledger.mobile.data.AppSettings) -> com.echoledger.mobile.data.AppSettings) {
        kotlinx.coroutines.runBlocking { app.graph.settings.update(change) }
        com.echoledger.mobile.testsupport.pumpUntil("settings") { vm.settings.value.serverUrl != null }
    }
}
