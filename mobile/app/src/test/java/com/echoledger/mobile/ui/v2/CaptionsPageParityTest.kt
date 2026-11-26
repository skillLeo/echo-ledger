package com.echoledger.mobile.ui.v2

import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.semantics.SemanticsActions
import androidx.compose.ui.semantics.SemanticsProperties
import androidx.compose.ui.test.SemanticsMatcher
import androidx.compose.ui.test.assert
import androidx.compose.ui.test.hasAnyAncestor
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.isDialog
import androidx.compose.ui.test.isToggleable
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.onLast
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performSemanticsAction
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.data.CaptionStyle
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.ui.SettingsScreen
import com.echoledger.mobile.ui.v2.components.rememberEchoToastState
import com.echoledger.mobile.ui.v2.settings.CaptionsPage
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import org.junit.Assert.assertEquals
import org.junit.Test

/**
 * Captions on the v2 components against classic's page (docs/v2/audit/parity.csv PH-SET-026 to 032): the same
 * controls, and each one changes the same setting (Live captions, Text size, Lines, Position, Hide when nobody speaks,
 * Background opacity, Compact captions, Colour for Me and for Client), Reset to defaults puts them back; in Urdu too.
 */
class CaptionsPageParityTest : ScreenTest() {
    private var page by mutableIntStateOf(1)
    private var language = EchoLanguage.English

    private fun showBoth() = show {
        if (page == 0) {
            SettingsScreen(vm, onSetup = {}, openPage = "captions")
        } else {
            EchoV2Theme(darkTheme = false, language = language) {
                CompositionLocalProvider(LocalEchoToasts provides rememberEchoToastState()) {
                    val s by vm.settings.collectAsState()
                    CaptionsPage(s, vm::updateSettings, highlight = null, onBack = {})
                }
            }
        }
    }

    private fun press(name: String) {
        val node = compose.onAllNodes((hasContentDescription(name) or hasText(name)) and hasClickAction()).onFirst()
        runCatching { node.performScrollTo() }
        node.performSemanticsAction(SemanticsActions.OnClick)
        compose.waitForIdle()
    }

    private fun style(check: (CaptionStyle) -> Boolean) = pumpUntil("the caption style") { check(vm.settings.value.captionStyle) }

    @Test
    fun theV2PageHasEveryControlOfTodaysPage() {
        page = 0
        showBoth()
        val today = mutableSetOf<String>()
        ParityKit.collect(compose, today)
        page = 1
        val v2 = mutableSetOf<String>()
        ParityKit.collect(compose, v2)
        val same = listOf("button: Back", "button: Reset to defaults", "switch: Compact captions", "switch: Live captions while recording")
        val where = same.associateWith { it } + mapOf(
            "radio: Small" to "radio: Text size: Small",
            "radio: Medium" to "radio: Text size: Medium",
            "radio: Large" to "radio: Text size: Large",
            "radio: 2" to "radio: Lines: 2",
            "radio: 3" to "radio: Lines: 3",
            "radio: 5" to "radio: Lines: 5",
            "radio: Top" to "radio: Position: Top",
            "radio: Bottom" to "radio: Position: Bottom",
            "radio: Never" to "radio: Hide when nobody speaks: Never",
            "radio: 5 s" to "radio: Hide when nobody speaks: 5 s",
            "radio: 10 s" to "radio: Hide when nobody speaks: 10 s",
            "radio: 30 s" to "radio: Hide when nobody speaks: 30 s"
        ) +
            listOf("Theme colour", "Violet", "Cyan", "Green", "Amber", "Rose", "White").associate {
                "radio: $it" to
                    "radio: Colour for Me: $it"
            }
        val v2Only = listOf("Theme colour", "Violet", "Cyan", "Green", "Amber", "Rose", "White").associate {
            "radio: Colour for Client: $it" to "today's two swatch rows share their names; v2 names whose colour it is"
        }
        val reasons = mapOf("button: Search settings" to "v2 Settings searches from its first page; Back leads there")
        assertEquals(
            emptyList<String>(),
            ParityKit.problems(today.associateWith { 0 }, v2.associateWith { 0 }, where, reasons, v2Only)
        )
    }

    @Test
    fun eachControlChangesTheSettingAndResetPutsThemBack() {
        vm.updateSettings { it.copy(captionsEnabled = false, captionStyle = CaptionStyle()) }
        pumpUntil("defaults") { !vm.settings.value.captionsEnabled }
        showBoth()
        compose.onAllNodes(hasText("Live captions while recording") and isToggleable()).onFirst()
            .performSemanticsAction(SemanticsActions.OnClick)
        pumpUntil("captions on") { vm.settings.value.captionsEnabled }
        press("Text size: Large")
        style { it.size == "large" }
        press("Lines: 5")
        style { it.lines == 5 }
        press("Position: Top")
        style { it.position == "top" }
        press("Hide when nobody speaks: 10 s")
        style { it.autoHideSeconds == 10 }
        compose.onAllNodes(hasText("Compact captions") and isToggleable()).onFirst().performSemanticsAction(SemanticsActions.OnClick)
        style { it.compact }
        press("Colour for Me: Cyan")
        style { it.meColor == "#22d3ee" }
        press("Colour for Client: Amber")
        style { it.clientColor == "#fbbf24" }
        // The chosen swatch says it is chosen.
        compose.onNode(hasContentDescription("Colour for Me: Cyan"))
            .assert(SemanticsMatcher.expectValue(SemanticsProperties.Selected, true))
        // Background opacity: 40 % to 100 %, saved when the slider is let go.
        val slider = compose.onNode(SemanticsMatcher.keyIsDefined(SemanticsActions.SetProgress))
        runCatching { slider.performScrollTo() }
        slider.performSemanticsAction(SemanticsActions.SetProgress) { it(60f) }
        style { it.opacity == 60 }
        pumpUntil("the opacity words") { compose.onAllNodesWithText("Background opacity 60%").fetchSemanticsNodes().isNotEmpty() }
        // Reset to defaults asks, then puts every caption choice back.
        press("Reset to defaults")
        compose.onAllNodes(hasText("Reset") and hasClickAction() and hasAnyAncestor(isDialog())).onLast()
            .performSemanticsAction(SemanticsActions.OnClick)
        pumpUntil("reset") {
            vm.settings.value.captionStyle == CaptionStyle() &&
                vm.settings.value.captionsEnabled == AppSettings().captionsEnabled
        }
    }

    @Test
    fun inUrduThePageSpeaksUrdu() {
        language = EchoLanguage.Urdu
        showBoth()
        pumpUntil("Urdu") {
            listOf("ریکارڈنگ کے دوران لائیو کیپشنز", "متن کا سائز", "جب کوئی نہ بولے تو چھپائیں", "میرا رنگ").all {
                compose.onAllNodesWithText(it).fetchSemanticsNodes().isNotEmpty()
            }
        }
    }
}
