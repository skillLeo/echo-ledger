package com.echoledger.mobile.ui.v2

import android.Manifest
import android.provider.Settings
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.semantics.SemanticsActions
import androidx.compose.ui.test.assertIsEnabled
import androidx.compose.ui.test.assertIsNotEnabled
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
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleOwner
import androidx.lifecycle.LifecycleRegistry
import androidx.lifecycle.compose.LocalLifecycleOwner
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.recording.InputChoice
import com.echoledger.mobile.recording.MicTestResult
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.pumpUntil
import com.echoledger.mobile.ui.SettingsScreen
import com.echoledger.mobile.ui.v2.components.rememberEchoToastState
import com.echoledger.mobile.ui.v2.settings.MicPage
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.theme.EchoLanguage
import com.echoledger.mobile.ui.v2.theme.EchoV2Theme
import org.junit.Assert.assertArrayEquals
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Before
import org.junit.Test
import org.robolectric.Shadows.shadowOf

/**
 * Microphone & audio on the v2 components against classic's page (docs/v2/audit/parity.csv PH-SET-019 to 025): the
 * same controls; your microphone, recording quality, Record from (a connected microphone, or Automatic), the warning
 * when the other side is silent each change the same setting; the 10-second test records from the chosen microphone,
 * shows its peak and what it heard, and plays it back; each permission not allowed has Fix opening its Android page
 * (caller names: notification access; the capture helper: Setup); Reset to defaults puts them back; in Urdu too.
 */
class MicPageParityTest : ScreenTest() {
    private var page by mutableIntStateOf(1)
    private var language = EchoLanguage.English
    private var recording by mutableStateOf(false)
    private var choices by mutableStateOf(listOf(InputChoice("builtin", "Phone microphone"), InputChoice("bluetooth", "Bluetooth headset")))
    private var testedWith: String? = "none"
    private var played: ShortArray? = null
    private var setupOpened = 0
    private val samples = ShortArray(16) { (it * 100).toShort() }
    private var heard = true

    /** The activity's lifecycle as the page sees it, so coming back from Android's settings can be played. */
    private val owner = object : LifecycleOwner {
        val registry = LifecycleRegistry(this)
        override val lifecycle: Lifecycle get() = registry
    }

    @Before
    fun resumed() {
        owner.registry.currentState = Lifecycle.State.RESUMED
    }

    /** Away to Android's settings and back: the page reads the microphones and permissions again on resume. */
    private fun comeBack() {
        owner.registry.handleLifecycleEvent(Lifecycle.Event.ON_PAUSE)
        owner.registry.handleLifecycleEvent(Lifecycle.Event.ON_RESUME)
        compose.waitForIdle()
    }

    private fun showBoth() = show {
        if (page == 0) {
            SettingsScreen(vm, onSetup = {}, openPage = "mic")
        } else {
            EchoV2Theme(darkTheme = false, language = language) {
                CompositionLocalProvider(LocalEchoToasts provides rememberEchoToastState(), LocalLifecycleOwner provides owner) {
                    val s by vm.settings.collectAsState()
                    MicPage(
                        s,
                        vm::updateSettings,
                        highlight = null,
                        onBack = {},
                        helperConnected = false,
                        recording = recording,
                        onSetup = { setupOpened++ },
                        inputChoices = { choices },
                        recordTest = { _, key, onLevel ->
                            testedWith = key
                            onLevel(0.2f)
                            MicTestResult(if (heard) 0.5f else 0f, heard, samples)
                        },
                        playBack = {
                            played = it
                            null
                        }
                    )
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

    private fun shown(words: String) = compose.onAllNodesWithText(words).fetchSemanticsNodes().isNotEmpty()

    @Test
    fun theV2PageHasEveryControlOfTodaysPage() {
        page = 0
        showBoth()
        val today = mutableSetOf<String>()
        ParityKit.collect(compose, today)
        // The same permission rows: as many Fix buttons on both pages.
        val fixes = compose.onAllNodes(hasText("Fix") and hasClickAction()).fetchSemanticsNodes().size
        page = 1
        val v2 = mutableSetOf<String>()
        ParityKit.collect(compose, v2)
        val same = listOf(
            "button: Back",
            "button: Reset to defaults",
            "button: Test 10 s",
            "switch: Warn me when the other side is silent"
        )
        val where = same.associateWith { it } + mapOf(
            "radio: Automatic" to "radio: Record from: Automatic",
            "button: Your microphone" to "radio: Your microphone: Reduce echo (recommended)",
            "button: Recording quality" to "radio: Recording quality: Standard",
            "button: Fix" to "button: Fix Microphone"
        )
        val onThePage = "classic opens these choices in a dialog; v2 shows them on the page"
        val v2Only = mapOf(
            "radio: Your microphone: Unprocessed" to onThePage,
            "radio: Recording quality: High" to onThePage,
            "radio: Recording quality: Highest (larger files)" to onThePage,
            "radio: Record from: Phone microphone" to "a connected microphone (this test connects two; classic's phone has none)",
            "radio: Record from: Bluetooth headset" to "a connected microphone (this test connects two; classic's phone has none)",
            "button: Fix Notifications" to "classic's Fix buttons share one name; v2 names what each one fixes",
            "button: Fix Display over other apps" to "classic's Fix buttons share one name; v2 names what each one fixes",
            "button: Fix Unrestricted battery use" to "classic's Fix buttons share one name; v2 names what each one fixes",
            "button: Fix Call capture helper" to "classic's Fix buttons share one name; v2 names what each one fixes",
            "button: Fix Notification access" to "classic's Fix buttons share one name; v2 names what each one fixes"
        )
        assertEquals(fixes, v2.count { it.startsWith("button: Fix ") })
        val reasons = mapOf("button: Search settings" to "v2 Settings searches from its first page; Back leads there")
        assertEquals(
            emptyList<String>(),
            ParityKit.problems(today.associateWith { 0 }, v2.associateWith { 0 }, where, reasons, v2Only)
        )
    }

    @Test
    fun eachChoiceChangesTheSettingAndResetPutsThemBack() {
        vm.updateSettings { AppSettings().copy(serverUrl = it.serverUrl) }
        val d = AppSettings()
        pumpUntil("defaults") { vm.settings.value.micSource == d.micSource && vm.settings.value.inputDevice == null }
        showBoth()
        press("Your microphone: Unprocessed")
        pumpUntil("unprocessed") { vm.settings.value.micSource == "mic" }
        press("Recording quality: Highest (larger files)")
        pumpUntil("highest") { vm.settings.value.recordingKbps == 64 }
        press("Record from: Bluetooth headset")
        pumpUntil("bluetooth") { vm.settings.value.inputDevice == "bluetooth" }
        compose.onAllNodes(hasText("Warn me when the other side is silent") and isToggleable()).onFirst()
            .performSemanticsAction(SemanticsActions.OnClick)
        pumpUntil("warning flipped") { vm.settings.value.farSideWarning != d.farSideWarning }
        // The chosen microphone was unplugged: the page says the phone chooses one.
        assertTrue(!shown("That microphone is not connected, so the phone chooses one."))
        choices = listOf(InputChoice("builtin", "Phone microphone"))
        comeBack()
        pumpUntil("not connected") { shown("That microphone is not connected, so the phone chooses one.") }
        // Reset asks, then puts every microphone choice back; the SIM-call settings (Calls & meetings') stay.
        vm.updateSettings { it.copy(phoneCalls = "always", phoneAppImport = false, deletePhoneAppCopy = true) }
        pumpUntil("calls settings") { vm.settings.value.phoneCalls == "always" }
        press("Reset to defaults")
        compose.onAllNodes(hasText("Reset") and hasClickAction() and hasAnyAncestor(isDialog())).onLast()
            .performSemanticsAction(SemanticsActions.OnClick)
        pumpUntil("reset") {
            val s = vm.settings.value
            s.micSource == d.micSource && s.recordingKbps == d.recordingKbps && s.inputDevice == null &&
                s.farSideWarning == d.farSideWarning
        }
        val kept = vm.settings.value
        assertEquals("always", kept.phoneCalls)
        assertFalse(kept.phoneAppImport)
        assertTrue(kept.deletePhoneAppCopy)
    }

    @Test
    fun theTestRecordsFromTheChosenMicrophoneShowsItsPeakAndPlaysItBack() {
        vm.updateSettings { it.copy(inputDevice = "bluetooth") }
        pumpUntil("bluetooth") { vm.settings.value.inputDevice == "bluetooth" }
        // Without the microphone permission there is nothing to test with.
        shadowOf(app).denyPermissions(Manifest.permission.RECORD_AUDIO)
        showBoth()
        compose.onAllNodes(hasText("Test 10 s") and hasClickAction()).onFirst().assertIsNotEnabled()
        shadowOf(app).grantPermissions(Manifest.permission.RECORD_AUDIO)
        comeBack()
        compose.waitForIdle()
        compose.onAllNodes(hasText("Test 10 s") and hasClickAction()).onFirst().assertIsEnabled()
        press("Test 10 s")
        pumpUntil("heard") { shown("Your microphone is working. Play it back to check how you sound.") }
        assertEquals("bluetooth", testedWith)
        assertTrue(compose.onAllNodes(hasContentDescription("Peak level: ", substring = true)).fetchSemanticsNodes().isNotEmpty())
        press("Play back")
        assertArrayEquals(samples, played)
        // Nothing heard: it says so.
        heard = false
        press("Test 10 s")
        pumpUntil("not heard") {
            shown("We did not hear your microphone. Check the microphone above and the microphone permission.")
        }
        // Not while a recording runs.
        recording = true
        compose.waitForIdle()
        compose.onAllNodes(hasText("Test 10 s") and hasClickAction()).onFirst().assertIsNotEnabled()
        assertTrue(shown("Finish the current recording to run a test."))
    }

    @Test
    fun eachPermissionNotAllowedHasFixThatOpensItsPage() {
        shadowOf(app).denyPermissions(Manifest.permission.RECORD_AUDIO)
        showBoth()
        press("Fix Microphone")
        val details = shadowOf(app).nextStartedActivity
        assertEquals(Settings.ACTION_APPLICATION_DETAILS_SETTINGS, details.action)
        assertEquals("package:${app.packageName}", details.dataString)
        // Caller names: notification access, WhatsApp call notifications only.
        assertTrue(shown("Shows who each WhatsApp call was with. Only WhatsApp call notifications are read."))
        press("Fix Notification access")
        assertEquals(Settings.ACTION_NOTIFICATION_LISTENER_SETTINGS, shadowOf(app).nextStartedActivity.action)
        press("Fix Unrestricted battery use")
        assertEquals(Settings.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS, shadowOf(app).nextStartedActivity.action)
        // The capture helper is set up in Setup & health.
        press("Fix Call capture helper")
        assertEquals(1, setupOpened)
        // Allowed: no Fix, the word Allowed.
        shadowOf(app).grantPermissions(Manifest.permission.RECORD_AUDIO)
        comeBack()
        compose.waitForIdle()
        assertTrue(compose.onAllNodes(hasContentDescription("Fix Microphone")).fetchSemanticsNodes().isEmpty())
        assertTrue(shown("Allowed"))
    }

    @Test
    fun inUrduThePageSpeaksUrdu() {
        language = EchoLanguage.Urdu
        showBoth()
        pumpUntil("Urdu") {
            listOf(
                "آپ کا مائیکروفون",
                "ریکارڈنگ کا معیار",
                "کہاں سے ریکارڈ کریں",
                "آزمائشی ریکارڈنگ",
                "اجازتیں",
                "بلوٹوتھ ہیڈسیٹ"
            ).all(::shown)
        }
        assertTrue(compose.onAllNodes(hasContentDescription("کہاں سے ریکارڈ کریں: خودکار")).fetchSemanticsNodes().isNotEmpty())
    }
}
