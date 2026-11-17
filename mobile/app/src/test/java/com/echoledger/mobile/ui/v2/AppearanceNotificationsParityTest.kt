package com.echoledger.mobile.ui.v2

import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.semantics.SemanticsActions
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.isToggleable
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performSemanticsAction
import com.echoledger.mobile.core.NotifyCategory
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.ui.SettingsScreen
import com.echoledger.mobile.ui.v2.components.rememberEchoToastState
import com.echoledger.mobile.ui.v2.settings.AppearancePage
import com.echoledger.mobile.ui.v2.settings.NotificationsPage
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.shell.UiFlags
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Test

/**
 * Appearance and Notifications parity: today's pages and the v2 ones walked through the same steps (as they open, a
 * choice changed, Reset to defaults asked, and confirmed), with their controls ("kind: name") collected after every
 * step; each mapped control must be on screen after exactly the same steps on both pages (ParityKit).
 */
class AppearanceNotificationsParityTest : ScreenTest() {
    private var page by mutableIntStateOf(0)

    private fun press(text: String) {
        val node = compose.onAllNodes((hasText(text) or hasContentDescription(text)) and hasClickAction()).onFirst()
        runCatching { node.performScrollTo() }
        node.performSemanticsAction(SemanticsActions.OnClick)
        compose.waitForIdle()
    }

    /** Flips the switch named one of [names] (today's by its own words, v2's by its label). */
    private fun toggle(vararg names: String) {
        val all = compose.onAllNodes(isToggleable(), useUnmergedTree = true)
        val i = all.fetchSemanticsNodes().indexOfFirst { ParityKit.nameOf(it) in names }
        assertTrue("no switch ${names.toList()}", i >= 0)
        runCatching { all[i].performScrollTo() }
        all[i].performSemanticsAction(SemanticsActions.OnClick)
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

    /** Both walks from the defaults, whatever an earlier test left. */
    private fun defaults(done: (AppSettings) -> Boolean, reset: (AppSettings) -> AppSettings) {
        vm.updateSettings(reset)
        pumpUntil("defaults") { done(vm.settings.value) }
    }

    private fun <S> compare(steps: List<S>, run: (S) -> Unit, where: Map<String, String>, reasons: Map<String, String>, again: () -> Unit) {
        val today = ParityKit.shownIn(compose, steps, run)
        again()
        page = 1
        compose.waitForIdle()
        val v2 = ParityKit.shownIn(compose, steps, run)
        val first = { m: Map<String, List<S>> -> m.mapValues { it.value.first() } }
        assertEquals(emptyList<String>(), ParityKit.problems(first(today), first(v2), where, reasons))
        assertEquals(emptyList<String>(), ParityKit.presenceProblems(today, v2, where))
    }

    private val search = mapOf("button: Search settings" to "v2 Settings searches from its first page; Back leads there")
    private val reset = mapOf(
        "button: Reset to defaults" to "button: Reset to defaults",
        "button: Reset" to "button: Reset",
        "button: Cancel" to "button: Cancel"
    )

    private enum class Step { Open, Changed, ResetAsked, ResetDone }

    @Test
    fun appearance() {
        val d = AppSettings()
        val start = { s: AppSettings -> s.themeMode == d.themeMode && s.phoneFont == d.phoneFont && s.dynamicColour == d.dynamicColour }
        defaults(start) { it.copy(themeMode = d.themeMode, phoneFont = d.phoneFont, dynamicColour = d.dynamicColour) }
        showBoth(
            today = { SettingsScreen(vm, onSetup = {}, openPage = "appearance") },
            v2 = {
                val settings by vm.settings.collectAsState()
                AppearancePage(settings, vm::updateSettings, UiFlags.Off, {}, highlight = null, onBack = {})
            }
        )
        val run = { step: Step ->
            when (step) {
                Step.Open -> Unit

                Step.Changed -> {
                    press("Dark")
                    toggle("Use phone font", "Use the phone's font")
                    pumpUntil("changed") { vm.settings.value.themeMode == "dark" && vm.settings.value.phoneFont != d.phoneFont }
                }

                Step.ResetAsked -> press("Reset to defaults")

                Step.ResetDone -> {
                    press("Reset")
                    pumpUntil("reset") { start(vm.settings.value) }
                }
            }
            compose.waitForIdle()
        }
        val where = reset + mapOf(
            "button: Back" to "button: Back",
            "radio: System" to "radio: System",
            "radio: Light" to "radio: Light",
            "radio: Dark" to "radio: Dark",
            "switch: Use phone font" to "switch: Use the phone's font"
        )
        val wallpaper = "switch: Wallpaper colours" to "v2 takes no wallpaper colours (SettingsTree.hiddenItems)"
        compare(Step.entries, run, where, search + wallpaper) {
            defaults(start) { it.copy(themeMode = d.themeMode, phoneFont = d.phoneFont) }
        }
    }

    @Test
    fun notifications() {
        val d = AppSettings()
        val start = { s: AppSettings ->
            s.activityNotifications == d.activityNotifications && s.quietHours == d.quietHours && s.notifyOff == d.notifyOff &&
                s.quietFrom == d.quietFrom && s.quietTo == d.quietTo
        }
        val toDefaults = { s: AppSettings ->
            s.copy(
                activityNotifications = d.activityNotifications,
                quietHours = d.quietHours,
                quietFrom = d.quietFrom,
                quietTo = d.quietTo,
                notifyOff = d.notifyOff
            )
        }
        defaults(start, toDefaults)
        showBoth(
            today = { SettingsScreen(vm, onSetup = {}, openPage = "notifications") },
            v2 = {
                val settings by vm.settings.collectAsState()
                NotificationsPage(settings, vm::updateSettings, highlight = null, onBack = {})
            }
        )
        val run = { step: Step ->
            when (step) {
                Step.Open -> Unit

                // Quiet hours on (its From and To appear) and a kind of notification off.
                Step.Changed -> {
                    toggle("Quiet hours")
                    toggle("Uploads")
                    pumpUntil("changed") { vm.settings.value.quietHours && "uploads" in vm.settings.value.notifyOff }
                }

                Step.ResetAsked -> press("Reset to defaults")

                Step.ResetDone -> {
                    press("Reset")
                    pumpUntil("reset") { start(vm.settings.value) }
                }
            }
            compose.waitForIdle()
        }
        val where = reset + buildMap {
            put("button: Back", "button: Back")
            put("switch: Show notifications", "switch: Activity notifications")
            put("switch: Quiet hours", "switch: Quiet hours")
            put("textbox: From", "textbox: From")
            put("textbox: To", "textbox: To")
            NotifyCategory.entries.forEach { c ->
                if (c != NotifyCategory.RECORDING) put("switch: ${c.title}", "switch: ${c.title}")
                put("button: Test ${c.title}", "button: Test ${c.title}")
                put("button: Sound and vibration for ${c.title}", "button: Sound and vibration for ${c.title}")
            }
        }
        compare(Step.entries, run, where, search) { defaults(start, toDefaults) }
    }
}
