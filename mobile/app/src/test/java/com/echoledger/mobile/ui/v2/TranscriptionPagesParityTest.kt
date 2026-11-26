package com.echoledger.mobile.ui.v2

import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasSetTextAction
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performTextInput
import com.echoledger.mobile.core.PlanState
import com.echoledger.mobile.data.FeatureDto
import com.echoledger.mobile.data.TranscriptionKeyDto
import com.echoledger.mobile.testsupport.FakeServer
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.ServerFixtures
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.ui.SettingsScreen
import com.echoledger.mobile.ui.v2.components.rememberEchoToastState
import com.echoledger.mobile.ui.v2.settings.KeyPage
import com.echoledger.mobile.ui.v2.settings.LanguagePage
import com.echoledger.mobile.ui.v2.settings.WordsPage
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Transcription & language parity: today's Language, Custom words and Transcription key pages and the v2 ones, each
 * walked through the same steps with their controls ("kind: name") collected after every step; every control of today's
 * pages is mapped to its v2 control and both must first appear in the same step (ParityKit).
 */
class TranscriptionPagesParityTest : ScreenTest() {
    private var page by mutableIntStateOf(0)

    private fun press(text: String) {
        val node = compose.onAllNodes((hasText(text) or hasContentDescription(text)) and hasClickAction()).onFirst()
        runCatching { node.performScrollTo() }
        node.performClick()
        compose.waitForIdle()
    }

    private fun showBoth(today: @Composable () -> Unit, v2: @Composable () -> Unit) = show {
        if (page == 0) {
            today()
        } else {
            EchoV2Theme(darkTheme = false, language = EchoLanguage.English) {
                CompositionLocalProvider(LocalEchoToasts provides rememberEchoToastState()) { v2() }
            }
        }
    }

    private fun <S> compare(
        steps: List<S>,
        run: (S) -> Unit,
        where: Map<String, String>,
        reasons: Map<String, String>,
        v2Only: Map<String, String> = emptyMap(),
        reset: () -> Unit
    ) {
        val today = ParityKit.firstSeen(compose, steps, run)
        assertTrue(today.toString(), today.isNotEmpty())
        reset()
        page = 1
        compose.waitForIdle()
        val v2 = ParityKit.firstSeen(compose, steps, run)
        assertEquals(emptyList<String>(), ParityKit.problems(today, v2, where, reasons, v2Only))
    }

    private val search = mapOf("button: Search settings" to "v2 Settings searches from its first page; Back leads there")

    private enum class LanguageStep { Open, More, Typed, Reset }

    @Test
    fun language() {
        showBoth(
            today = { SettingsScreen(vm, onSetup = {}, openPage = "language") },
            v2 = {
                val settings by vm.settings.collectAsState()
                LanguagePage(vm, settings, highlight = null, onBack = {})
            }
        )
        val where = buildMap {
            put("button: Back", "button: Back")
            put("radio: Language: Auto (English / Urdu / Arabic)", "radio: Auto (English / Urdu / Arabic)")
            put("radio: Language: English only", "radio: English only")
            put("radio: Language: Urdu (with English words)", "radio: Urdu (with English words)")
            put("radio: Language: Arabic (with English words)", "radio: Arabic (with English words)")
            put("radio: More languages", "button: More languages")
            com.echoledger.mobile.core.Languages.MORE.forEach { put("button: ${it.label}", "radio: ${it.label}") }
            put("textbox: A name or term the engine gets wrong", "textbox: Add to vocabulary")
            put("button: Add", "button: Add")
            put("button: Reset to defaults", "button: Reset to defaults")
            put("button: Reset", "button: Reset")
            put("button: Cancel", "button: Cancel")
        }
        compare(
            LanguageStep.entries,
            { s ->
                when (s) {
                    LanguageStep.Open -> Unit

                    LanguageStep.More -> press("More languages")

                    LanguageStep.Typed -> {
                        androidx.test.espresso.Espresso.pressBack()
                        compose.waitForIdle()
                        compose.onAllNodes(hasSetTextAction()).onFirst().performScrollTo().performTextInput("Qureshi")
                    }

                    LanguageStep.Reset -> press("Reset to defaults")
                }
                compose.waitForIdle()
            },
            where,
            search + mapOf("textbox: Add to vocabulary" to "today's field once typed in (its placeholder gone, named by the line above)"),
            mapOf(
                "radio: None of these" to "the v2 picker's way back from a More language to the main ones (today: tap a main chip)"
            )
        ) {}
    }

    private enum class WordsStep { Open }

    @Test
    fun customWords() {
        runBlocking { app.pairLocally(server) }
        server.on("GET", "/api/clients", FakeServer.json(200, ServerFixtures.clients()))
        server.on("GET", "/api/library", FakeServer.json(200, ServerFixtures.libraryPage(emptyList())))
        vm.refresh()
        pumpUntil("projects") { vm.clients.value.size == 2 }
        showBoth(
            today = { SettingsScreen(vm, onSetup = {}, openPage = "words") },
            v2 = {
                val settings by vm.settings.collectAsState()
                WordsPage(vm, settings, highlight = null, onBack = {})
            }
        )
        // Today's project rows are radios named by their project (P-052), as v2's: each maps to its own.
        val where = mapOf(
            "button: Back" to "button: Back",
            "radio: No project" to "radio: No project",
            "radio: Acme Holdings" to "radio: Acme Holdings",
            "radio: Zed Logistics" to "radio: Zed Logistics"
        )
        compare(WordsStep.entries, { compose.waitForIdle() }, where, search, emptyMap()) {}
    }

    private enum class KeyStep { Open, Typed, Saved, Removing }

    @Test
    fun transcriptionKey() {
        val free = PlanState(features = mapOf("platform_transcription" to FeatureDto(enabled = false)))
        vm.updateSettings { it.copy(plan = free) }
        pumpUntil("free plan") { vm.settings.value.plan.needsOwnKey }
        showBoth(
            today = { SettingsScreen(vm, onSetup = {}, openPage = "key") },
            v2 = {
                val settings by vm.settings.collectAsState()
                KeyPage(vm, settings.plan, highlight = null, onBack = {})
            }
        )
        val where = mapOf(
            "button: Back" to "button: Back",
            "button: Open console.groq.com" to "button: Open console.groq.com",
            "textbox: Groq API key" to "textbox: Groq API key",
            "button: Save" to "button: Save",
            "button: Remove key" to "button: Remove key",
            "textbox: Replace with a new key" to "textbox: Replace with a new key",
            "button: Remove" to "button: Remove",
            "button: Cancel" to "button: Cancel"
        )
        compare(KeyStep.entries, { s ->
            when (s) {
                KeyStep.Open -> Unit

                KeyStep.Typed -> compose.onAllNodes(hasSetTextAction()).onFirst().performTextInput("gsk_" + "x".repeat(40))

                KeyStep.Saved -> {
                    vm.updateSettings { it.copy(plan = free.copy(transcriptionKey = TranscriptionKeyDto(set = true, last4 = "a1b2"))) }
                    pumpUntil("saved") { vm.settings.value.plan.transcriptionKey?.set == true }
                }

                KeyStep.Removing -> press("Remove key")
            }
            compose.waitForIdle()
        }, where, search) {
            vm.updateSettings { it.copy(plan = free) }
            pumpUntil("free again") { vm.settings.value.plan.transcriptionKey == null }
        }
    }
}
