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
import com.echoledger.mobile.e2e.support.SignInScreen
import com.echoledger.mobile.e2e.support.Ui
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import org.junit.runner.RunWith

@RunWith(AndroidJUnit4::class)
class SettingsTest : E2eTest() {
    /** Opens the Settings tab and one of its pages (the tab opens on its sections of pages: Microphone & audio, About…). */
    private fun openSettings(page: String, name: String = "Settings phone") {
        launchPaired(name = name)
        Ui.openTab("Settings")
        Ui.awaitText("Settings")
        Ui.scrollDownTo(page)
        Ui.clickText(page)
    }

    /** Back on the Settings list, opens another of its pages. */
    private fun openPage(page: String) {
        Ui.awaitText("Settings")
        Ui.scrollDownTo(page)
        Ui.clickText(page)
    }

    @Test
    fun settingsShowThisPhoneTheServerAndThePinningState() {
        // About: the version and the server this phone is paired with; this phone by its name (since v1.8.1, P-021 no
        // certificate pins are shown to people).
        openSettings("About")
        Ui.awaitTextContaining("Server ")
        Ui.scrollDownTo("This phone")
        assertTrue(Ui.has("Settings phone"))
        assertFalse(Ui.hasContaining("Certificate pinning"))
        Ui.snapshot("settings")
        Ui.device.pressBack()
        openPage("Captions")
        Ui.awaitText("Live captions while recording")
        Ui.device.pressBack()
        openPage("Microphone & audio")
        Ui.awaitText("Your microphone")
        Ui.device.pressBack()
        openPage("Custom words")
        Ui.awaitText("Project keyterms")
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
        // Since v1.8.1 (P-022) the choice is in plain words: "Reduce echo" (recommended) or "Unprocessed".
        openSettings("Microphone & audio")
        Ui.awaitText("Reduce echo")
        Ui.clickText("Your microphone")
        Ui.clickText("Unprocessed")
        Ui.awaitCondition("the setting to be saved", Ui.NORMAL) { AppState.settings().micSource == "mic" }
        Ui.launchApp()
        Ui.openTab("Settings")
        openPage("Microphone & audio")
        Ui.awaitText("Unprocessed")
        Ui.clickText("Your microphone")
        Ui.clickText("Reduce echo (recommended)")
        Ui.awaitText("Reduce echo")
    }

    @Test
    fun theSetupGuideOpensFromSettingsAndGoesBack() {
        openSettings("About")
        Ui.scrollDownTo("Setup guide")
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
        launchPaired(name = "Settings phone")
        Ui.openTab("Library")
        Library.awaitLoaded()
        // Sign out is once, at the bottom of the Settings list (since v1.8.1, P-021), and asks first.
        Ui.openTab("Settings")
        Ui.awaitText("Settings")
        Ui.scrollDownTo("Sign out")
        Ui.clickText("Sign out")
        Ui.awaitText("Sign out of this phone?")
        // The dialog's own Sign out, drawn over the list's.
        Ui.device.findObjects(androidx.test.uiautomator.By.text("Sign out")).last().click()
        Ui.awaitText(SignInScreen.TITLE, Ui.NORMAL)
        assertNull(AppState.token())
        assertEquals(0, AppState.cachedLibrarySize())
        Ui.launchApp()
        Ui.awaitText(SignInScreen.TITLE)
        assertFalse(AppState.settings().paired)
    }
}
