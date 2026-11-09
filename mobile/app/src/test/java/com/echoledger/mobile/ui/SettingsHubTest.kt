package com.echoledger.mobile.ui

import androidx.compose.ui.test.assertCountEquals
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.hasSetTextAction
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performTextInput
import com.echoledger.mobile.core.SettingsIndex
import com.echoledger.mobile.testsupport.ScreenTest
import org.junit.Test

/** Settings (since v1.7; B19 before): the account card, a search that reaches every setting, six sections of pages. */
class SettingsHubTest : ScreenTest() {
    private fun showSettings() = show { SettingsScreen(vm, onSetup = {}) }

    private fun search(text: String) {
        compose.onNodeWithText("Search settings").performClick()
        compose.onNode(hasSetTextAction()).performTextInput(text)
        compose.waitForIdle()
    }

    @Test
    fun `settings opens on a search box and the six sections with their pages`() {
        showSettings()

        compose.onNodeWithText("Settings").assertIsDisplayed()
        compose.onNodeWithText("Search settings").assertIsDisplayed()
        SettingsIndex.sections.forEach { compose.onNodeWithText(it).assertExists() }
        listOf("Calls & meetings", "Captions", "Appearance", "Notifications", "App lock", "Storage", "Help", "About").forEach {
            compose.onNodeWithText(it).assertExists()
        }
        compose.onAllNodesWithText("General").assertCountEquals(0)
    }

    @Test
    fun `a page is its own screen with a back arrow to the list`() {
        showSettings()

        compose.onNodeWithText("Calls & meetings").performClick()
        compose.onNodeWithText("Auto-record apps").assertIsDisplayed()
        compose.onNodeWithText("After a call").assertExists()
        compose.onNodeWithContentDescription("Search settings").assertExists()

        compose.onNodeWithContentDescription("Back").performClick()
        compose.onNodeWithText("Search settings").assertIsDisplayed()
        compose.onAllNodesWithText("After a call").assertCountEquals(0)
    }

    @Test
    fun `each page shows its own settings`() {
        showSettings()

        mapOf(
            "Appearance" to listOf("Wallpaper colours"),
            "Notifications" to listOf("Quiet hours"),
            "Voice notes & dictation" to listOf("After sharing a voice note", "Voice keyboard"),
            "Captions" to listOf("Live captions while recording", "Caption style"),
            "Games" to listOf("Games", "Box style", "Monthly budget"),
            "Videos" to listOf("Apps", "Subtitle style")
        ).forEach { (page, titles) ->
            compose.onNodeWithText(page).performScrollTo().performClick()
            titles.forEach {
                org.junit.Assert.assertTrue("$page shows $it", compose.onAllNodesWithText(it).fetchSemanticsNodes().isNotEmpty())
            }
            compose.onNodeWithContentDescription("Back").performClick()
        }
    }

    @Test
    fun `search finds a setting inside a page and says where it is`() {
        showSettings()

        search("quiet")

        compose.onNodeWithText("Quiet hours").assertIsDisplayed()
        compose.onNodeWithText("App › Notifications").assertIsDisplayed()
        compose.onAllNodesWithText("Storage").assertCountEquals(0)
    }

    @Test
    fun `a search result opens its page`() {
        showSettings()

        search("quiet hours")
        compose.onNodeWithText("Quiet hours").performClick()

        compose.onNodeWithText("Show notifications").assertIsDisplayed()
        compose.onNodeWithContentDescription("Back").performClick()
        compose.onNodeWithText("Search settings").assertIsDisplayed()
    }

    @Test
    fun `a word the title does not use still finds the setting`() {
        showSettings()

        search("retention")
        compose.onNodeWithText("How long recordings are kept").performClick()

        compose.onNodeWithText("Couldn't load the server's storage. Check the connection.").assertIsDisplayed()
    }

    @Test
    fun `no match says so`() {
        showSettings()

        search("zzzz")
        compose.onNodeWithText("No settings match “zzzz”. Try a shorter word, like dark, lock or plan.").assertIsDisplayed()
        compose.onNodeWithContentDescription("Clear search").performClick()
        compose.onAllNodesWithText("No settings match “zzzz”. Try a shorter word, like dark, lock or plan.").assertCountEquals(0)
    }

    @Test
    fun `about shows the version and the server the phone is paired with`() {
        runBlockingSettings { it.copy(serverUrl = "https://ledger.example.com", serverApiVersion = "1.1", deviceId = "D") }
        showSettings()

        compose.onNodeWithText("About").performScrollTo().performClick()

        compose.onNodeWithText("Server ledger.example.com · API 1.1").assertExists()
    }

    private fun runBlockingSettings(change: (com.echoledger.mobile.data.AppSettings) -> com.echoledger.mobile.data.AppSettings) {
        kotlinx.coroutines.runBlocking { app.graph.settings.update(change) }
        com.echoledger.mobile.testsupport.pumpUntil("settings") { vm.settings.value.serverUrl != null }
    }
}
