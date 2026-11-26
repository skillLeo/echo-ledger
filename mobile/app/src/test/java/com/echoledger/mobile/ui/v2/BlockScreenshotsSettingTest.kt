package com.echoledger.mobile.ui.v2

import android.content.res.Configuration
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.ui.test.assertCountEquals
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.assertIsOff
import androidx.compose.ui.test.assertIsOn
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.isToggleable
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import com.echoledger.mobile.R
import com.echoledger.mobile.core.SettingsIndex
import com.echoledger.mobile.core.ShareGuard
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.testsupport.simulateScreenLock
import com.echoledger.mobile.ui.v2.settings.PrivacyPage
import com.echoledger.mobile.ui.v2.settings.SettingsTree
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import java.io.File
import java.util.Locale
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Settings › Privacy & data (the owner's decision, 2.0.5): "Block screenshots of EchoLedger" (the app's own screens, off
 * by default) and "Hide EchoLedger's live words from screenshots and screen shares" (on by default), each saved and
 * passed on at once, with no During screen sharing and no Android-version line; and every word of the call choice
 * exactly as docs/v2/copy/call-choice.md has it, in English and Urdu.
 */
class BlockScreenshotsSettingTest : ScreenTest() {
    private val label = "Block screenshots of EchoLedger"
    private val help = "Off: you can take screenshots of the app, except screens that show keys or recovery codes. " +
        "On: EchoLedger's own screens can't be captured and are hidden in Recents."
    private val live = "Hide EchoLedger's live words from screenshots and screen shares"
    private val liveHelp = "On: captions, Live Assist and Live Translate show as a black box in screenshots, screen recordings and " +
        "screen shares. The recording pill and the after-call card always show. Off: everything shows."

    private fun showPage() = show {
        val settings by vm.settings.collectAsState()
        PrivacyPage(settings, vm::updateSettings, highlight = "privacy.block", onOpenPage = {}, onBack = {})
    }

    private fun switch(name: String) = compose.onNode(isToggleable() and hasText(name), useUnmergedTree = false)

    @Test
    fun `Block screenshots is off by default, with its plain line, and is saved both ways`() {
        showPage()
        switch(label).assertIsOff()
        compose.onNodeWithText(help, useUnmergedTree = true).assertExists()

        switch(label).performClick()
        pumpUntil("Block screenshots to be saved") { vm.settings.value.blockScreens }
        switch(label).assertIsOn()
        switch(label).performClick()
        pumpUntil("allowed again") { !vm.settings.value.blockScreens }
    }

    @Test
    fun `Hide live words is on by default, with its line, and reaches the floating windows at once`() {
        showPage()
        switch(live).assertIsOn()
        compose.onNodeWithText(liveHelp, useUnmergedTree = true).assertExists()
        assertTrue(ShareGuard.hideLive.value)

        switch(live).performClick()
        pumpUntil("saved") { !vm.settings.value.hideLiveWords }
        pumpUntil("in the floating windows") { !ShareGuard.hideLive.value }
        switch(live).performClick()
        pumpUntil("on again") { vm.settings.value.hideLiveWords && ShareGuard.hideLive.value }
    }

    @Test
    fun `During screen sharing and its Android-version line are gone`() {
        showPage()
        listOf("During screen sharing", "Private (black box)", "On this Android version").forEach {
            compose.onAllNodesWithText(it, substring = true, useUnmergedTree = true).assertCountEquals(0)
        }
        compose.onAllNodesWithText("Show", useUnmergedTree = true).assertCountEquals(0)
    }

    @Test
    fun `in Urdu both switches speak the deck's words`() {
        val ur = V2Testing.resources(EchoLanguage.Urdu)
        show {
            EchoV2Theme(language = EchoLanguage.Urdu) {
                val settings by vm.settings.collectAsState()
                PrivacyPage(settings, vm::updateSettings, highlight = null, onOpenPage = {}, onBack = {})
            }
        }
        listOf(R.string.cc_block_screens, R.string.cc_block_screens_help, R.string.cc_hide_live_words, R.string.cc_hide_live_words_help)
            .forEach { id -> compose.onNodeWithText(ur.getString(id), useUnmergedTree = true).assertExists() }
        assertEquals("EchoLedger کے لائیو الفاظ اسکرین شاٹس اور اسکرین شیئر سے چھپائیں", ur.getString(R.string.cc_hide_live_words))
    }

    @Test
    fun `with the lock on a search for widget titles lands on a line that is there`() {
        app.simulateScreenLock(true)
        show {
            androidx.compose.runtime.CompositionLocalProvider(
                com.echoledger.mobile.ui.v2.shell.LocalEchoToasts provides com.echoledger.mobile.ui.v2.components.rememberEchoToastState()
            ) {
                com.echoledger.mobile.ui.v2.settings.AppLockPage(
                    AppSettings(appLockEnabled = true),
                    {},
                    {},
                    highlight = "lock.hide",
                    onBack = {}
                )
            }
        }
        compose.onNodeWithText(
            "Hide recording titles on the widget: with App lock on, the widget shows no recordings at all.",
            useUnmergedTree = true
        ).assertIsDisplayed()
    }

    @Test
    fun `search finds it by the words people use`() {
        listOf("screenshot", "block screenshots", "recents", "hide content").forEach { words ->
            assertEquals(words, "privacy.block", SettingsIndex.search(words).first().item.id)
        }
        assertEquals(R.string.cc_block_screens, SettingsTree.itemTitles["privacy.block"])
    }

    /** The deck's table rows: key, English, Urdu. */
    private fun deck(): List<Triple<String, String, String>> = File("../../docs/v2/copy/call-choice.md").readLines()
        .filter { it.startsWith("| ") && !it.startsWith("| key") }
        .map { line -> line.trim().removePrefix("|").removeSuffix("|").split(" | ").map(String::trim) }
        .map { (key, en, ur) -> Triple(key, en, ur) }

    private fun words(language: String) = app.createConfigurationContext(
        Configuration(app.resources.configuration).apply { setLocale(Locale.forLanguageTag(language)) }
    ).resources

    @Test
    fun `every word of the call choice is the copy deck's, in English and in Urdu`() {
        // The desktop's own words (key ending in _desktop) are not the phone's.
        val rows = deck().filterNot { it.first.endsWith("_desktop") }
        assertTrue("the deck has its rows", rows.size >= 35)
        val en = words("en")
        val ur = words("ur")
        rows.forEach { (key, english, urdu) ->
            val id = app.resources.getIdentifier("cc_$key", "string", app.packageName)
            assertTrue("cc_$key exists", id != 0)
            assertEquals("cc_$key in English", english, en.getString(id))
            assertEquals("cc_$key in Urdu", urdu, ur.getString(id))
        }
    }
}
