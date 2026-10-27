package com.echoledger.mobile.ui

import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.assertIsNotEnabled
import androidx.compose.ui.test.assertIsOff
import androidx.compose.ui.test.assertIsOn
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performTextInput
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.dictation.DictationHistory
import com.echoledger.mobile.dictation.DictationPhase
import com.echoledger.mobile.dictation.DictationUi
import com.echoledger.mobile.dictation.Replacement
import com.echoledger.mobile.dictation.Snippet
import com.echoledger.mobile.testsupport.ScreenTest
import java.io.File
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/** The phone's dictation screens (B14): the voice keyboard and Settings → Dictation. */
class DictationScreenTest : ScreenTest() {
    private class Keys : KeyboardKeys {
        val pressed = mutableListOf<String>()

        override fun start() {
            pressed += "start"
        }

        override fun finish() {
            pressed += "finish"
        }

        override fun space() {
            pressed += "space"
        }

        override fun backspace() {
            pressed += "backspace"
        }

        override fun enter() {
            pressed += "enter"
        }

        override fun switchKeyboard() {
            pressed += "switch"
        }

        override fun openApp() {
            pressed += "openApp"
        }
    }

    private fun historyFile() = File(app.cacheDir, "dictation-history-${System.nanoTime()}.json")

    // ---- the voice keyboard ------------------------------------------------------------------------------------------------

    @Test
    fun `the keyboard says what to do while idle, in either mode`() {
        assertEquals("Tap the microphone and speak", keyboardStatus(DictationUi(), holdToTalk = false))
        assertEquals("Hold the microphone while you speak", keyboardStatus(DictationUi(), holdToTalk = true))
    }

    @Test
    fun `the keyboard shows the phrase so far, then Inserting, and the reason when dictation could not start`() {
        val listening = DictationUi(DictationPhase.Listening, preview = "send the invoice")
        assertEquals("send the invoice", keyboardStatus(listening, holdToTalk = false))
        assertEquals("Listening… tap again to insert", keyboardStatus(DictationUi(DictationPhase.Listening), holdToTalk = false))
        assertEquals("Connecting…", keyboardStatus(DictationUi(DictationPhase.Connecting), holdToTalk = false))
        assertEquals("Inserting…", keyboardStatus(DictationUi(DictationPhase.Finishing), holdToTalk = false))
        assertEquals("No network", keyboardStatus(DictationUi(message = "No network"), holdToTalk = false))
    }

    @Test
    fun `tapping the microphone starts and a second tap inserts`() {
        val keys = Keys()
        show { VoiceKeyboard(DictationUi(), holdToTalk = false, needsMic = false, keys = keys) }

        compose.onNodeWithContentDescription("Start dictation").performClick()

        assertEquals(listOf("start"), keys.pressed)
    }

    @Test
    fun `while listening the microphone button stops and inserts`() {
        val keys = Keys()
        show { VoiceKeyboard(DictationUi(DictationPhase.Listening, preview = "hello"), holdToTalk = false, needsMic = false, keys = keys) }

        compose.onNodeWithContentDescription("Stop and insert").performClick()

        assertEquals(listOf("finish"), keys.pressed)
    }

    @Test
    fun `the editing keys reach the focused field`() {
        val keys = Keys()
        show { VoiceKeyboard(DictationUi(), holdToTalk = false, needsMic = false, keys = keys) }

        compose.onNodeWithContentDescription("Space").performClick()
        compose.onNodeWithContentDescription("Delete").performClick()
        compose.onNodeWithContentDescription("Enter").performClick()
        compose.onNodeWithContentDescription("Switch keyboard").performClick()

        assertEquals(listOf("space", "backspace", "enter", "switch"), keys.pressed)
    }

    @Test
    fun `without the microphone permission the keyboard offers to open the app and the microphone is off`() {
        val keys = Keys()
        show { VoiceKeyboard(DictationUi(), holdToTalk = false, needsMic = true, keys = keys) }

        compose.onNodeWithText("Allow the microphone in EchoLedger").performClick()

        assertEquals(listOf("openApp"), keys.pressed)
        compose.onNodeWithContentDescription("Start dictation").performClick()
        assertEquals("the microphone does nothing without permission", listOf("openApp"), keys.pressed)
    }

    // ---- Settings → Dictation ---------------------------------------------------------------------------------------------

    @Test
    fun `the settings cards show the voice keyboard, the clean-up switch and the sounds switch`() {
        show {
            DictationSettingsContent(vm, AppSettings(dictationCleanup = true, dictationSounds = false), DictationHistory(historyFile()))
        }

        compose.onNodeWithText("Voice keyboard").assertIsDisplayed()
        compose.onNodeWithText("Dictation is never saved as a recording.", substring = true).assertIsDisplayed()
        compose.onNodeWithContentDescription("Clean up the text").assertIsOn()
        compose.onNodeWithContentDescription("Start and stop sounds").assertIsOff()
    }

    @Test
    fun `adding a replacement saves it cleaned and clears the fields`() {
        var saved: List<Replacement>? = null
        show { ReplacementsSection(listOf(Replacement("acme", "Acme Ltd"))) { saved = it } }

        compose.onNodeWithText("Acme Ltd", substring = true).assertIsDisplayed()
        compose.onNodeWithText("Add replacement").assertIsNotEnabled()
        compose.onNodeWithText("When I say").performTextInput("  farah ")
        compose.onNodeWithText("Write").performTextInput("Farah Khan ")
        compose.onNodeWithText("Add replacement").performClick()

        assertEquals(listOf(Replacement("acme", "Acme Ltd"), Replacement("farah", "Farah Khan")), saved)
    }

    @Test
    fun `a replacement can be removed and a duplicate trigger cannot be added`() {
        var saved: List<Replacement>? = null
        show { ReplacementsSection(listOf(Replacement("acme", "Acme Ltd"))) { saved = it } }

        compose.onNodeWithText("When I say").performTextInput("ACME")
        compose.onNodeWithText("Add replacement").assertIsNotEnabled()
        compose.onNodeWithContentDescription("Remove acme").performClick()

        assertEquals(emptyList<Replacement>(), saved)
    }

    @Test
    fun `adding a snippet needs a phrase and a text, and removing one saves the rest`() {
        var saved: List<Snippet>? = null
        show { SnippetsSection(listOf(Snippet("insert signature", "Best, Sam"))) { saved = it } }

        compose.onNodeWithText("Add snippet").assertIsNotEnabled()
        compose.onNodeWithText("Phrase").performTextInput("insert address")
        compose.onNodeWithText("Add snippet").assertIsNotEnabled()
        compose.onNodeWithText("Text to insert").performTextInput("1 High Street")
        compose.onNodeWithText("Add snippet").performClick()
        assertEquals(listOf(Snippet("insert signature", "Best, Sam"), Snippet("insert address", "1 High Street")), saved)

        compose.onNodeWithContentDescription("Remove insert signature").performClick()
        assertEquals(emptyList<Snippet>(), saved)
    }

    @Test
    fun `the history lists the latest dictations, copies one and clears them all`() {
        val history = DictationHistory(historyFile())
        history.add("2026-10-24T09:00:00Z", "first thing I said")
        history.add("2026-10-24T10:00:00Z", "second thing I said")
        show { HistorySection(history) }

        compose.onNodeWithText("second thing I said").assertIsDisplayed()
        compose.onNodeWithText("first thing I said").assertIsDisplayed()
        compose.onNodeWithText("The last ${DictationHistory.LIMIT}, kept on this phone only.").assertIsDisplayed()

        compose.onNodeWithText("Clear history").performClick()

        compose.onNodeWithText("Nothing dictated yet.").assertIsDisplayed()
        assertTrue(history.all().isEmpty())
    }

    @Test
    fun `an empty history says so and offers nothing to clear`() {
        show { HistorySection(DictationHistory(historyFile())) }

        compose.onNodeWithText("Nothing dictated yet.").assertIsDisplayed()
        assertEquals(0, compose.onAllNodesWithTextCount("Clear history"))
    }

    private fun androidx.compose.ui.test.junit4.ComposeContentTestRule.onAllNodesWithTextCount(text: String) =
        onAllNodes(androidx.compose.ui.test.hasText(text)).fetchSemanticsNodes().size
}
