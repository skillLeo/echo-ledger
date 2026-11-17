package com.echoledger.mobile.ui.v2

import android.content.ComponentName
import android.content.Intent
import android.content.IntentFilter
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.setValue
import androidx.compose.ui.test.hasClickAction
import androidx.compose.ui.test.hasContentDescription
import androidx.compose.ui.test.hasSetTextAction
import androidx.compose.ui.test.hasText
import androidx.compose.ui.test.onFirst
import androidx.compose.ui.test.performClick
import androidx.compose.ui.test.performScrollTo
import androidx.compose.ui.test.performTextInput
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.ui.SettingsScreen
import com.echoledger.mobile.ui.v2.components.rememberEchoToastState
import com.echoledger.mobile.ui.v2.settings.CallsPage
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import org.junit.Assert.assertEquals
import org.junit.Before
import org.junit.Test
import org.robolectric.Shadows.shadowOf
import org.robolectric.shadows.ShadowBuild

/**
 * Calls & meetings parity. Today's page (SettingsScreen on "calls") and the v2 page (CallsPage) are each walked through
 * the same steps, and their controls ("kind: name") collected after every step: as it opens; a call app typed in (Add
 * on); the app added (its Remove); SIM calls set to Always (the Phone app's recordings appear, with a recording waiting
 * and its settings openable); importing switched on (Delete the copy can be switched); Import this recording (the
 * dialog); Reset to defaults (its question). Every control of today's page is mapped to its v2 control by kind and
 * name, and the two must first appear in the same step.
 */
class CallsPageParityTest : ScreenTest() {
    @Before
    fun phone() {
        // A Galaxy (its Phone app's folder is known) with a recording waiting, and a Phone app that has call settings.
        ShadowBuild.setManufacturer("samsung")
        app.getSharedPreferences("phone_app_recordings", android.content.Context.MODE_PRIVATE).edit()
            .putString("ask", "1:1763366400000").commit()
        val pm = shadowOf(app.packageManager)
        val dialer = ComponentName("com.samsung.android.dialer", "com.samsung.android.dialer.CallSettings")
        pm.addActivityIfNotPresent(dialer)
        pm.addIntentFilterForActivity(
            dialer,
            IntentFilter(android.telecom.TelecomManager.ACTION_SHOW_CALL_SETTINGS).apply {
                addCategory(Intent.CATEGORY_DEFAULT)
            }
        )
    }

    private fun press(text: String) {
        val node = compose.onAllNodes((hasText(text) or hasContentDescription(text)) and hasClickAction()).onFirst()
        // Inside the page it's scrolled to; in a dialog there's nothing to scroll.
        runCatching { node.performScrollTo() }
        node.performClick()
        compose.waitForIdle()
    }

    private enum class Step { Open, Typed, Added, Always, Importing, Dialog, Reset }

    /** One step, the same on both pages; a setting goes through the view model, so its effect is waited for. */
    private fun run(step: Step) {
        when (step) {
            Step.Open -> Unit

            Step.Typed -> compose.onAllNodes(hasSetTextAction()).onFirst().performScrollTo().performTextInput("com.example.voip")

            Step.Added -> {
                press("Add")
                pumpUntil("added") { "com.example.voip" in vm.settings.value.extraCallApps }
            }

            Step.Always -> {
                press("Always")
                pumpUntil("always") { vm.settings.value.phoneCalls == "always" }
            }

            Step.Importing -> {
                press("Import the Phone app's recordings")
                pumpUntil("importing") { vm.settings.value.phoneAppImport }
            }

            Step.Dialog -> press("Import the recording of your call at 08:00")

            Step.Reset -> {
                press("Cancel")
                press("Reset to defaults")
            }
        }
        compose.waitForIdle()
    }

    private fun showNew() = show {
        val settings by vm.settings.collectAsState()
        EchoV2Theme(darkTheme = false, language = EchoLanguage.English) {
            CompositionLocalProvider(LocalEchoToasts provides rememberEchoToastState()) {
                CallsPage(settings, vm::updateSettings, highlight = null, onBack = {}, manufacturer = "samsung")
            }
        }
    }

    /** Today's control → the v2 control (kind and name). */
    private val where: Map<String, String> = buildMap {
        put("button: Back", "button: Back")
        put("radio: Keep automatically (default) — a small \"Saved · Undo\" shows for 6 s", "radio: Keep automatically")
        put("radio: Ask me — a small after-call card to keep or discard it; kept after 10 s", "radio: Ask me")
        put("radio: Never", "radio: Never")
        put("radio: 5 s", "radio: 5 seconds")
        put("radio: 10 s (default)", "radio: 10 seconds (default)")
        put("radio: 20 s", "radio: 20 seconds")
        put("radio: 30 s", "radio: 30 seconds")
        put("radio: After 24 hours (default)", "radio: After 24 hours (default)")
        put("radio: After 3 days", "radio: After 3 days")
        listOf(
            "WhatsApp", "WhatsApp Business", "Google Meet", "Zoom", "Microsoft Teams", "Telegram", "Signal", "Messenger",
            "Instagram", "Skype", "Slack", "Discord"
        ).forEach { put("switch: Auto-record $it", "switch: $it") }
        put("textbox: Another call app (package name)", "textbox: Another call app (package name)")
        put("button: Add", "button: Add")
        put("button: Remove com.example.voip from the call apps", "button: Remove com.example.voip from the call apps")
        put("radio: Off", "radio: Off")
        put("radio: Ask each time", "radio: Ask each time")
        put("radio: Always", "radio: Always")
        put("button: Open the Phone app's settings", "button: Open the Phone app's settings")
        put("button: Allow", "button: Allow")
        put("switch: Import the Phone app's recordings", "switch: Import the Phone app's recordings")
        put("switch: Delete the Phone app's copy after import", "switch: Delete the Phone app's copy after import")
        put("button: Import the recording of your call at 08:00", "button: Import the recording of your call at 08:00")
        put("button: Not the recording of your call at 08:00", "button: Not the recording of your call at 08:00")
        put("button: Cancel", "button: Cancel")
        put("button: Reset to defaults", "button: Reset to defaults")
        put("button: Reset", "button: Reset")
    }

    /** The v2 page's own controls (none today), and why. */
    private val v2Only = emptyMap<String, String>()

    /** Today's controls the v2 page doesn't have, and why. */
    private val reasons = mapOf(
        "button: Search settings" to "v2 Settings searches from its first page; Back leads there"
    )

    @Test
    fun todaysPageAndTheV2PageHaveTheSameControlsInTheSameSteps() {
        val today = mutableMapOf<String, Step>()
        val v2 = mutableMapOf<String, Step>()
        // One walk on today's page, then the same walk on the v2 page, each from the walked settings' defaults.
        startFromDefaults()
        showBoth()
        today += ParityKit.firstSeen(compose, Step.entries, ::run)
        assertEquals(today.toString(), true, today.size > 30)
        v2Walk(v2)
        assertEquals(emptyList<String>(), ParityKit.problems(today, v2, where, reasons, v2Only))
    }

    /** The v2 page's walk, in a second composition from the start (settings back to their defaults first). */
    private fun v2Walk(into: MutableMap<String, Step>) {
        startFromDefaults()
        page = 1
        compose.waitForIdle()
        into += ParityKit.firstSeen(compose, Step.entries, ::run)
    }

    private var page by androidx.compose.runtime.mutableIntStateOf(0)

    /** The walked settings at their defaults, as the view model shows them (whatever an earlier test left). */
    private fun startFromDefaults() {
        val d = AppSettings()
        vm.updateSettings {
            it.copy(
                extraCallApps = d.extraCallApps,
                phoneCalls = d.phoneCalls,
                phoneAppImport = d.phoneAppImport,
                deletePhoneAppCopy = d.deletePhoneAppCopy
            )
        }
        pumpUntil("defaults") {
            val s = vm.settings.value
            s.extraCallApps.isEmpty() && s.phoneCalls == d.phoneCalls && s.phoneAppImport == d.phoneAppImport &&
                s.deletePhoneAppCopy == d.deletePhoneAppCopy
        }
    }

    private fun showBoth() = show {
        if (page == 0) {
            SettingsScreen(vm, onSetup = {}, openPage = "calls")
        } else {
            val settings by vm.settings.collectAsState()
            EchoV2Theme(darkTheme = false, language = EchoLanguage.English) {
                CompositionLocalProvider(LocalEchoToasts provides rememberEchoToastState()) {
                    CallsPage(settings, vm::updateSettings, highlight = null, onBack = {}, manufacturer = "samsung")
                }
            }
        }
    }

    @Test
    fun resetPutsThePagesChoicesBackAndKeepsPickedApps() {
        val d = AppSettings()
        vm.updateSettings {
            it.copy(decisionTimeoutChosen = !d.decisionTimeoutChosen, decisionTimeout = "keep", extraCallApps = listOf("com.example.voip"))
        }
        pumpUntil("changed") { vm.settings.value.decisionTimeout == "keep" && vm.settings.value.extraCallApps.isNotEmpty() }
        showNew()
        press("Reset to defaults")
        press("Reset")
        pumpUntil("reset") {
            vm.settings.value.decisionTimeout == d.decisionTimeout &&
                vm.settings.value.decisionTimeoutChosen == d.decisionTimeoutChosen
        }
        assertEquals(listOf("com.example.voip"), vm.settings.value.extraCallApps)
    }
}
