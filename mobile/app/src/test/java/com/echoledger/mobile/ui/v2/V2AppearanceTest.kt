package com.echoledger.mobile.ui.v2

import androidx.compose.material3.Text
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performTextInput
import com.echoledger.mobile.core.PlanState
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.ui.v2.components.rememberEchoToastState
import com.echoledger.mobile.ui.v2.settings.AppearancePage
import com.echoledger.mobile.ui.v2.settings.SettingsGoal
import com.echoledger.mobile.ui.v2.settings.V2SettingsRoot
import com.echoledger.mobile.ui.v2.shell.AppLook
import com.echoledger.mobile.ui.v2.shell.ClassicScreens
import com.echoledger.mobile.ui.v2.shell.EchoV2Shell
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.shell.ProfileState
import com.echoledger.mobile.ui.v2.shell.UiFlags
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import org.junit.Assert.assertEquals
import org.junit.Test

/**
 * Settings v2's groups and Appearance (parity.csv PH-SET-006, 059, 060, 061, PH-LIB-064): each group's icon and one line,
 * this month's minutes in Account & plan, Wallpaper colours, Text size and App language, and the shell applying them.
 */
class V2AppearanceTest : ScreenTest() {
    private fun appearance() = show {
        EchoV2Theme(darkTheme = false, language = EchoLanguage.English) {
            CompositionLocalProvider(LocalEchoToasts provides rememberEchoToastState()) {
                val settings by vm.settings.collectAsState()
                AppearancePage(settings, vm::updateSettings, UiFlags.Off, {}, highlight = null, onBack = {})
            }
        }
    }

    private fun tap(words: String) {
        val node = compose.onNode(hasText(words) and hasClickAction())
        runCatching { node.performScrollTo() }
        node.performClick()
    }

    @Test
    fun wallpaperColoursTextSizeAndAppLanguageAreSaved() {
        appearance()
        tap("Wallpaper colours")
        pumpUntil("wallpaper") { vm.settings.value.dynamicColour }
        tap("Largest")
        pumpUntil("largest") { vm.settings.value.textSize == "largest" }
        tap("اردو")
        pumpUntil("urdu") { vm.settings.value.appLanguage == "ur" }
        // Reset to defaults puts all three back.
        tap("Reset to defaults")
        tap("Reset")
        pumpUntil("reset") { vm.settings.value.let { !it.dynamicColour && it.textSize == "system" && it.appLanguage == "system" } }
    }

    @Test
    fun textSizeScalesThePhonesOwnSize() {
        var seen = 0f
        show {
            CompositionLocalProvider(LocalDensity provides androidx.compose.ui.unit.Density(2f, 1.1f)) {
                AppLook.TextSize("largest") { seen = LocalDensity.current.fontScale }
            }
        }
        compose.waitForIdle()
        assertEquals(1.1f * 1.3f, seen, 0.001f)
        assertEquals(1f, AppLook.scale("system"))
        assertEquals(0.9f, AppLook.scale("smaller"))
    }

    @Test
    fun theAppLanguageChoosesTheShellsLanguage() {
        vm.updateSettings { it.copy(appLanguage = "ur") }
        pumpUntil("saved") { vm.settings.value.appLanguage == "ur" }
        show {
            EchoV2Shell(
                vm,
                UiFlags(mapOf("ui_v2.shell" to true)),
                darkTheme = false,
                classic = ClassicScreens({ Text("") }, { Text("") }, { Text("") }, { Text("") }, { Text("") }, {}),
                onSwitchToClassic = {},
                language = EchoLanguage.English
            )
        }
        // The bar's names in Urdu although the phone is in English.
        compose.onNodeWithText("پروجیکٹس").assertExists()
        assertEquals(EchoLanguage.English, AppLook.language("system", EchoLanguage.English))
        assertEquals(EchoLanguage.Urdu, AppLook.language("system", EchoLanguage.Urdu))
        assertEquals(EchoLanguage.English, AppLook.language("en", EchoLanguage.Urdu))
    }

    @Test
    fun everyGroupHasItsLineAndAccountHasThisMonthsMinutes() {
        var opened: Pair<String, String?>? = null
        val settings = AppSettings(plan = PlanState(usedMinutes = 120, monthlyMinutes = 300))
        show {
            EchoV2Theme(darkTheme = false, language = EchoLanguage.English) {
                V2SettingsRoot(settings, ProfileState.Loading, true, {}, {}, { p, h -> opened = p to h }, {}, {})
            }
        }
        val res = V2Testing.resources(EchoLanguage.English)
        for (goal in SettingsGoal.entries) {
            val line = res.getString(goal.description)
            val node = compose.onAllNodesWithText(line)
            runCatching { node[0].performScrollTo() }
            assertEquals(line, 1, node.fetchSemanticsNodes().size)
        }
        tap("This month")
        assertEquals("plan" to "plan.usage", opened)
        compose.onNodeWithText("120 of 300 minutes used").assertExists()
    }

    @Test
    fun searchFindsTextSizeAndOpensAppearanceAtIt() {
        var opened: Pair<String, String?>? = null
        show {
            EchoV2Theme(darkTheme = false, language = EchoLanguage.English) {
                V2SettingsRoot(AppSettings(), ProfileState.Loading, true, {}, {}, { p, h -> opened = p to h }, {}, {})
            }
        }
        compose.onNodeWithText("Search settings").performTextInput("text size")
        tap("Text size")
        assertEquals("appearance" to "appearance.textsize", opened)
    }
}
