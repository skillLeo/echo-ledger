/*
 * End-to-end tests: the app on an emulator or device against a disposable EchoLedger test backend. Every test here is
 * skipped unless the run names the backend with these instrumentation arguments (with Gradle:
 * -Pandroid.testInstrumentationRunnerArguments.<name>=<value>):
 *   backend.server   the test server the app pairs with, for example http://10.0.2.2:8000 (the development machine as
 *                    seen from the Android emulator)
 *   backend.flaky    the same server behind a proxy whose failure mode the tests switch
 *   backend.control  the backend's control endpoint (pairing codes, seeding, statistics; see support/Backend.kt)
 */
package com.echoledger.mobile.e2e

import androidx.test.ext.junit.runners.AndroidJUnit4
import com.echoledger.mobile.e2e.support.AppState
import com.echoledger.mobile.e2e.support.Backend
import com.echoledger.mobile.e2e.support.E2eTest
import com.echoledger.mobile.e2e.support.Library
import com.echoledger.mobile.e2e.support.SeedData
import com.echoledger.mobile.e2e.support.Ui
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import org.junit.runner.RunWith

@RunWith(AndroidJUnit4::class)
class SettingsTest : E2eTest() {
    /** Opens the Settings tab and one of its groups (the tab opens on the list of groups). */
    private fun openSettings(group: String, name: String = "Settings phone") {
        launchPaired(name = name)
        Ui.openTab("Settings")
        Ui.awaitText("Settings")
        Ui.clickText(group)
    }

    @Test
    fun settingsShowThisPhoneTheServerAndThePinningState() {
        openSettings("Devices")
        Ui.awaitText("Settings phone · ${Backend.serverUrl}")
        assertTrue(Ui.has("Certificate pinning not configured on the server"))
        Ui.snapshot("settings")
        Ui.device.pressBack()
        Ui.clickText("Captions")
        Ui.awaitText("Live captions while recording")
        Ui.device.pressBack()
        Ui.clickText("Recording")
        Ui.awaitText("Near-end microphone source")
        Ui.device.pressBack()
        Ui.clickText("General")
        Ui.awaitText("Client keyterms")
        assertTrue(Ui.has("No client"))
    }

    @Test
    fun theLiveCaptionSwitchIsRememberedAcrossARestart() {
        openSettings("Captions")
        val label = "Live captions while recording"
        assertTrue("captions are on by default", Ui.switchNear(label).isChecked)
        Ui.switchNear(label).click()
        Ui.awaitCondition("the setting to be saved", Ui.NORMAL) { !AppState.settings().captionsEnabled }
        Ui.launchApp()
        Ui.openTab("Settings")
        Ui.clickText("Captions")
        Ui.awaitText(label)
        assertFalse(Ui.switchNear(label).isChecked)
    }

    @Test
    fun theMicrophoneSourceCanBeSwitchedAndIsRemembered() {
        openSettings("Recording")
        Ui.awaitText("Voice communication (echo-cancelled)")
        Ui.clickText("Change")
        Ui.awaitText("Raw microphone")
        assertEquals("mic", AppState.settings().micSource)
        Ui.launchApp()
        Ui.openTab("Settings")
        Ui.clickText("Recording")
        Ui.awaitText("Raw microphone")
        Ui.clickText("Change")
        Ui.awaitText("Voice communication (echo-cancelled)")
    }

    @Test
    fun theSetupGuideOpensFromSettingsAndGoesBack() {
        openSettings("Devices")
        Ui.clickText("Setup guide")
        Ui.awaitText("Setup")
        assertTrue(Ui.hasContaining("Permissions"))
        assertTrue(Ui.hasContaining("Call capture"))
        Ui.snapshot("setup-guide")
        Ui.scrollDownTo("I understand")
        assertTrue(Ui.hasContaining("Consent"))
        Ui.device.pressBack()
        Ui.awaitText("Settings")
        assertTrue(Ui.has("Privacy & security"))
    }

    @Test
    fun unpairingReturnsToThePairingScreenAndForgetsTheTokenAndTheCachedLibrary() {
        SeedData.ensure()
        openSettings("Devices")
        Ui.openTab("Library")
        Library.awaitLoaded()
        Ui.openTab("Settings")
        Ui.clickText("Devices")
        Ui.clickText("Unpair")
        Ui.awaitText("Pair this phone", Ui.NORMAL)
        assertNull(AppState.token())
        assertEquals(0, AppState.cachedLibrarySize())
        Ui.launchApp()
        Ui.awaitText("Pair this phone")
        assertFalse(AppState.settings().paired)
    }
}
