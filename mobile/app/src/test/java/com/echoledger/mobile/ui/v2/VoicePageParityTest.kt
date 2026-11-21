package com.echoledger.mobile.ui.v2

import android.content.ClipboardManager
import android.content.Intent
import android.provider.Settings
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.semantics.SemanticsActions
import androidx.compose.ui.test.assertIsNotEnabled
import androidx.compose.ui.test.hasAnyAncestor
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasSetTextAction
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.isDialog
import androidx.compose.ui.test.isToggleable
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.onLast
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performSemanticsAction
import androidx.compose.ui.test.performTextInput
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.dictation.DictationHistory
import com.echoledger.mobile.dictation.Replacement
import com.echoledger.mobile.dictation.Snippet
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.ui.SettingsScreen
import com.echoledger.mobile.ui.v2.components.rememberEchoToastState
import com.echoledger.mobile.ui.v2.settings.DICTATION_HOLD
import com.echoledger.mobile.ui.v2.settings.DICTATION_TAP
import com.echoledger.mobile.ui.v2.settings.VoicePage
import com.echoledger.mobile.ui.v2.settings.pageValue
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test
import org.robolectric.Shadows.shadowOf

/**
 * Voice notes & dictation on the v2 components against classic's page (docs/v2/audit/parity.csv PH-SET-033 to 040):
 * the same controls; each one changes the same setting or does the same thing (the voice keyboard's Android page, the
 * dictionary and snippets added and removed, a recent dictation copied, the history cleared after asking); the
 * Settings list says Hold to talk when it is chosen (it said Tap to start whatever was chosen); in Urdu too.
 */
class VoicePageParityTest : ScreenTest() {
    private var page by mutableIntStateOf(1)
    private var language = EchoLanguage.English
    private val history by lazy { DictationHistory.of(app) }

    private fun showBoth() = show {
        if (page == 0) {
            SettingsScreen(vm, onSetup = {}, openPage = "voice")
        } else {
            EchoV2Theme(darkTheme = false, language = language) {
                CompositionLocalProvider(LocalEchoToasts provides rememberEchoToastState()) {
                    val s by vm.settings.collectAsState()
                    VoicePage(s, vm::updateSettings, history, highlight = null, onBack = {})
                }
            }
        }
    }

    private fun seed() {
        history.clear()
        history.add("2026-11-20T10:00:00Z", "Kal subah meeting hai")
        vm.updateSettings {
            it.copy(
                dictationReplacements = listOf(Replacement("eko", "EchoLedger")),
                dictationSnippets = listOf(Snippet("insert signature", "Bilal"))
            )
        }
        pumpUntil("seeded") { vm.settings.value.dictationSnippets.isNotEmpty() }
    }

    private fun press(name: String) {
        val node = compose.onAllNodes((hasContentDescription(name) or hasText(name)) and hasClickAction()).onFirst()
        runCatching { node.performScrollTo() }
        node.performSemanticsAction(SemanticsActions.OnClick)
        compose.waitForIdle()
    }

    private fun type(label: String, words: String) {
        val field = compose.onAllNodes(hasSetTextAction() and hasText(label, substring = true)).onFirst()
        runCatching { field.performScrollTo() }
        field.performTextInput(words)
        compose.waitForIdle()
    }

    private fun toggle(name: String) {
        val node = compose.onAllNodes(hasText(name) and isToggleable()).onFirst()
        runCatching { node.performScrollTo() }
        node.performSemanticsAction(SemanticsActions.OnClick)
        compose.waitForIdle()
    }

    @Test
    fun theV2PageHasEveryControlOfTodaysPage() {
        seed()
        page = 0
        showBoth()
        val today = mutableSetOf<String>()
        ParityKit.collect(compose, today)
        page = 1
        val v2 = mutableSetOf<String>()
        ParityKit.collect(compose, v2)
        val renamed = mapOf(
            "radio: Hold to talk" to "radio: Microphone: Hold to talk",
            "radio: Tap to start" to "radio: Microphone: Tap to start"
        )
        val where = (today - renamed.keys - "button: Search settings").associateWith { it } + renamed
        val reasons = mapOf("button: Search settings" to "v2 Settings searches from its first page; Back leads there")
        assertEquals(emptyList<String>(), ParityKit.problems(today.associateWith { 0 }, v2.associateWith { 0 }, where, reasons))
    }

    @Test
    fun eachControlChangesTheSettingOrDoesWhatClassicDoes() {
        vm.updateSettings { AppSettings().copy(serverUrl = it.serverUrl) }
        pumpUntil("defaults") { vm.settings.value.dictationMode == DICTATION_TAP && vm.settings.value.afterShare == "open" }
        seed()
        showBoth()
        press("Stay in the app I shared from, and notify me quietly when it is ready")
        pumpUntil("stay") { vm.settings.value.afterShare == "stay" }
        press("Microphone: Hold to talk")
        pumpUntil("hold") { vm.settings.value.dictationMode == DICTATION_HOLD }
        toggle("Clean up the text")
        pumpUntil("no clean up") { !vm.settings.value.dictationCleanup }
        toggle("Start and stop sounds")
        pumpUntil("no sounds") { !vm.settings.value.dictationSounds }
        press("Turn on the voice keyboard")
        assertEquals(Settings.ACTION_INPUT_METHOD_SETTINGS, shadowOf(app).nextStartedActivity.action)
        // The dictionary: a new pair is added (not one for the same words twice), and one removed.
        type("When I say", "bilal traders")
        type("Write", "Bilal Traders")
        press("Add replacement")
        pumpUntil("added") { vm.settings.value.dictationReplacements.any { it.from == "bilal traders" && it.to == "Bilal Traders" } }
        type("When I say", "EKO")
        compose.onAllNodes(hasText("Add replacement") and hasClickAction()).onFirst().assertIsNotEnabled()
        press("Remove eko")
        pumpUntil("removed") { vm.settings.value.dictationReplacements.none { it.from == "eko" } }
        // Snippets.
        type("Phrase", "insert bank")
        type("Text to insert", "Meezan Bank, account 0123")
        press("Add snippet")
        pumpUntil("snippet") { vm.settings.value.dictationSnippets.any { it.trigger == "insert bank" } }
        press("Remove insert signature")
        pumpUntil("snippet removed") { vm.settings.value.dictationSnippets.none { it.trigger == "insert signature" } }
        // Recent dictations: Copy, then Clear history, which asks (Cancel keeps them).
        press("Copy")
        assertEquals("Kal subah meeting hai", app.getSystemService(ClipboardManager::class.java).primaryClip!!.getItemAt(0).text.toString())
        press("Clear history")
        compose.onAllNodes(hasText("Cancel") and hasClickAction() and hasAnyAncestor(isDialog())).onLast()
            .performSemanticsAction(SemanticsActions.OnClick)
        compose.waitForIdle()
        assertEquals(1, history.all().size)
        press("Clear history")
        compose.onAllNodes(hasText("Clear") and hasClickAction() and hasAnyAncestor(isDialog())).onLast()
            .performSemanticsAction(SemanticsActions.OnClick)
        pumpUntil("cleared") { history.all().isEmpty() }
        pumpUntil("said") { compose.onAllNodesWithText("Nothing dictated yet.").fetchSemanticsNodes().isNotEmpty() }
        // Reset puts the page's choices back and keeps the words and snippets (classic's rule).
        press("Reset to defaults")
        compose.onAllNodes(hasText("Reset") and hasClickAction() and hasAnyAncestor(isDialog())).onLast()
            .performSemanticsAction(SemanticsActions.OnClick)
        pumpUntil("reset") {
            val s = vm.settings.value
            s.afterShare == "open" && s.dictationMode == DICTATION_TAP && s.dictationCleanup && s.dictationSounds
        }
        assertTrue(vm.settings.value.dictationSnippets.any { it.trigger == "insert bank" })
    }

    @Test
    fun theSettingsListSaysHoldToTalkWhenItIsChosen() {
        val res = V2Testing.resources(EchoLanguage.English)
        assertEquals("Hold to talk", pageValue(res, "voice", AppSettings(dictationMode = DICTATION_HOLD)))
        assertEquals("Tap to start", pageValue(res, "voice", AppSettings(dictationMode = DICTATION_TAP)))
        // The keyboard reads the same value.
        assertEquals("push_to_talk", DICTATION_HOLD)
    }

    @Test
    fun inUrduThePageSpeaksUrdu() {
        language = EchoLanguage.Urdu
        showBoth()
        pumpUntil("Urdu") {
            listOf("وائس نوٹ شیئر کرنے کے بعد", "وائس کی بورڈ آن کریں", "لغت اور متبادل", "حالیہ ڈکٹیشنز").all {
                compose.onAllNodesWithText(it).fetchSemanticsNodes().isNotEmpty()
            }
        }
    }
}
