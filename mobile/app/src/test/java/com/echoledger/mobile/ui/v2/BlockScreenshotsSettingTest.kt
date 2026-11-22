package com.echoledger.mobile.ui.v2

import android.content.res.Configuration
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.test.assertCountEquals
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
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.ui.v2.settings.PrivacyPage
import com.echoledger.mobile.ui.v2.settings.SCREEN_RECORDING_CALLBACK_SDK
import com.echoledger.mobile.ui.v2.settings.SettingsTree
import java.io.File
import java.util.Locale
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Settings › Privacy & data › "Block screenshots of EchoLedger" (the call-choice plan, 2.0.5): off by default, saved and
 * passed to the floating windows at once, with the line for Android 10 to 14 (no screen-recording callback there), and
 * every word of the call choice exactly as docs/v2/copy/call-choice.md has it, in English and Urdu.
 */
class BlockScreenshotsSettingTest : ScreenTest() {
    private val label = "Block screenshots of EchoLedger"
    private val help = "Off: you can take screenshots at any time, also while recording. EchoLedger's windows still hide when you " +
        "share your screen."
    private val oldAndroid = "On this Android version, EchoLedger's windows hide from screen sharing only while this is on."

    private var sdk by mutableIntStateOf(SCREEN_RECORDING_CALLBACK_SDK)

    private fun showPage() = show {
        val settings by vm.settings.collectAsState()
        PrivacyPage(settings, vm::updateSettings, highlight = "privacy.block", onOpenPage = {}, onBack = {}, sdkInt = sdk)
    }

    private fun blockSwitch() = compose.onNode(isToggleable() and hasText(label), useUnmergedTree = false)

    @Test
    fun `off by default, with its plain line, and turning it on blocks the windows at once`() {
        showPage()
        blockSwitch().assertIsOff()
        compose.onNodeWithText(help, useUnmergedTree = true).assertExists()
        assertFalse(ShareGuard.block.value)

        blockSwitch().performClick()
        pumpUntil("Block screenshots to be saved") { vm.settings.value.blockScreens }
        pumpUntil("Block screenshots to reach the floating windows") { ShareGuard.block.value }
        blockSwitch().assertIsOn()

        blockSwitch().performClick()
        pumpUntil("allowed again") { !vm.settings.value.blockScreens && !ShareGuard.block.value }
    }

    @Test
    fun `Android 10 to 14 says the windows hide from a share only while it is on, Android 15 and newer do not`() {
        showPage()
        listOf(29 to 1, 34 to 1, 35 to 0, 36 to 0).forEach { (version, lines) ->
            sdk = version
            compose.waitForIdle()
            compose.onAllNodesWithText(oldAndroid, useUnmergedTree = true).assertCountEquals(lines)
        }
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
        val rows = deck()
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
