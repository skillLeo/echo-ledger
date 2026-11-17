package com.echoledger.mobile.ui.v2

import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasSetTextAction
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performTextInput
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.ui.SettingsScreen
import com.echoledger.mobile.ui.v2.components.rememberEchoToastState
import com.echoledger.mobile.ui.v2.settings.CallsPage
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.robolectric.shadows.ShadowBuild

/**
 * Calls & meetings parity: today's page (SettingsScreen on "calls") and the v2 page (CallsPage) each walked through every
 * state, with their controls collected after each step: as it opens; a call app typed in (Add on); the app added (its
 * Remove); SIM calls set to Always (the Phone app's recordings appear, with a recording waiting); importing switched on
 * (Delete the copy can be switched); Import this recording (the dialog); Reset to defaults (its question). Every control
 * today's page has must be mapped to where the v2 page has it, and found there in the same step.
 */
class CallsPageParityTest : ScreenTest() {
    @Before
    fun phone() {
        // A Galaxy (its Phone app's folder is known) with a recording waiting for the person.
        ShadowBuild.setManufacturer("samsung")
        app.getSharedPreferences("phone_app_recordings", android.content.Context.MODE_PRIVATE).edit()
            .putString("ask", "1:1763366400000").commit()
    }

    private fun press(text: String) {
        val node = compose.onAllNodes((hasText(text) or hasContentDescription(text)) and hasClickAction()).onFirst()
        // Inside the page it's scrolled to; in a dialog there's nothing to scroll.
        runCatching { node.performScrollTo() }
        node.performClick()
        compose.waitForIdle()
    }

    private enum class Step { Open, Typed, Added, Always, Importing, Dialog, Reset }

    /** Each step's action on a page: the same on both (the v2 page's words where they differ). */
    private fun step(step: Step, v2: Boolean) {
        when (step) {
            Step.Open -> Unit

            Step.Typed -> compose.onAllNodes(hasSetTextAction()).onFirst().performScrollTo().performTextInput("com.example.voip")

            Step.Added -> press("Add")

            Step.Always -> press("Always")

            Step.Importing -> press("Import the Phone app's recordings")

            Step.Dialog -> press("Import the recording of your call at 08:00")

            Step.Reset -> {
                press("Cancel")
                press("Reset to defaults")
            }
        }
        compose.waitForIdle()
    }

    private fun walk(v2: Boolean, at: (Step) -> Unit) {
        Step.entries.forEach { s ->
            step(s, v2)
            at(s)
        }
    }

    private fun showToday() = show { SettingsScreen(vm, onSetup = {}, openPage = "calls") }

    private fun showNew() = show {
        val settings by vm.settings.collectAsState()
        EchoV2Theme(darkTheme = false, language = EchoLanguage.English) {
            CompositionLocalProvider(LocalEchoToasts provides rememberEchoToastState()) {
                CallsPage(settings, vm::updateSettings, highlight = null, onBack = {}, manufacturer = "samsung")
            }
        }
    }

    /** Today's control → (the step it's found in, its name on the v2 page), or why the v2 page has no such control. */
    private val where: Map<String, Pair<Step, String>?> = buildMap {
        put("button: Back", Step.Open to "Back")
        // v2 Settings searches from its first page; Back leads there.
        put("button: Search settings", null)
        put("radio: Keep automatically (default) — a small \"Saved · Undo\" shows for 6 s", Step.Open to "Keep automatically")
        put("radio: Ask me — a small after-call card to keep or discard it; kept after 10 s", Step.Open to "Ask me")
        put("radio: Never", Step.Open to "Never")
        put("radio: 5 s", Step.Open to "5 seconds")
        put("radio: 10 s (default)", Step.Open to "10 seconds (default)")
        put("radio: 20 s", Step.Open to "20 seconds")
        put("radio: 30 s", Step.Open to "30 seconds")
        put("radio: After 24 hours (default)", Step.Open to "After 24 hours (default)")
        put("radio: After 3 days", Step.Open to "After 3 days")
        listOf(
            "WhatsApp", "WhatsApp Business", "Google Meet", "Zoom", "Microsoft Teams", "Telegram", "Signal", "Messenger",
            "Instagram", "Skype", "Slack", "Discord"
        ).forEach { put("switch: Auto-record $it", Step.Open to it) }
        put("textbox: Another call app (package name)", Step.Open to "Another call app (package name)")
        put("button: Add", Step.Typed to "Add")
        put("button: Remove com.example.voip from the call apps", Step.Added to "Remove com.example.voip from the call apps")
        put("radio: Off", Step.Open to "Off")
        put("radio: Ask each time", Step.Open to "Ask each time")
        put("radio: Always", Step.Open to "Always")
        put("button: Allow", Step.Always to "Allow")
        put("switch: Import the Phone app's recordings", Step.Always to "Import the Phone app's recordings")
        put("switch: Delete the Phone app's copy after import", Step.Importing to "Delete the Phone app's copy after import")
        put("button: Import the recording of your call at 08:00", Step.Always to "Import the recording of your call at 08:00")
        put("button: Not the recording of your call at 08:00", Step.Always to "Not the recording of your call at 08:00")
        // The same dialog on both pages (ImportRecordingDialog).
        put("button: Cancel", Step.Dialog to "Cancel")
        put("button: Reset to defaults", Step.Open to "Reset to defaults")
        put("button: Reset", Step.Reset to "Reset")
    }

    @Test
    fun everyControlOfTodaysPageHasAPlaceAndTheV2PageHasItInTheSameStep() {
        val today = mutableSetOf<String>()
        showToday()
        walk(v2 = false) { ParityKit.collect(compose, today) }
        assertTrue("only ${today.size}: $today", today.size > 30)
        assertEquals("today's controls with no place on the v2 page", emptyList<String>(), today.filter { it !in where }.sorted())
        assertEquals("mapped controls today's page doesn't have", emptyList<String>(), where.keys.filter { it !in today }.sorted())
    }

    @Test
    fun theV2PageHasEveryMappedControlInItsStep() {
        val missing = mutableListOf<String>()
        showNew()
        walk(v2 = true) { s ->
            where.filterValues { it?.first == s }.forEach { (today, at) ->
                // A setting changes through the view model, so what it reveals can take a moment.
                val found = runCatching { pumpUntil(at!!.second, timeoutMs = 5_000) { ParityKit.has(compose, at.second) } }.isSuccess
                if (!found) missing += "$today → $s: ${at!!.second}"
            }
        }
        assertEquals(emptyList<String>(), missing)
    }

    @Test
    fun resetPutsThePagesChoicesBack() {
        val defaults = com.echoledger.mobile.data.AppSettings()
        vm.updateSettings { it.copy(decisionTimeoutChosen = !defaults.decisionTimeoutChosen) }
        pumpUntil("changed") { vm.settings.value.decisionTimeoutChosen != defaults.decisionTimeoutChosen }
        showNew()
        press("Reset to defaults")
        press("Reset")
        pumpUntil("reset") { vm.settings.value.decisionTimeoutChosen == defaults.decisionTimeoutChosen }
    }
}
