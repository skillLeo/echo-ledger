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
    private fun openSettings(name: String = "Settings phone") {
        launchPaired(name = name)
        Ui.openTab("Settings")
        Ui.awaitText("Settings")
        Ui.awaitText("Device")
    }

    @Test
    fun settingsShowThisPhoneTheServerAndThePinningState() {
        openSettings()
        Ui.awaitText("Settings phone · ${Backend.serverUrl}")
        assertTrue(Ui.has("Certificate pinning not configured on the server"))
        Ui.snapshot("settings")
        assertTrue(Ui.has("Live captions while recording"))
        assertTrue(Ui.has("Near-end microphone source"))
        assertTrue(Ui.has("Client keyterms"))
        assertTrue(Ui.has("No client"))
    }

    @Test
    fun theLiveCaptionSwitchIsRememberedAcrossARestart() {
        openSettings()
        val label = "Live captions while recording"
        assertTrue("captions are on by default", Ui.switchNear(label).isChecked)
        Ui.switchNear(label).click()
        Ui.awaitCondition("the setting to be saved", Ui.NORMAL) { !AppState.settings().captionsEnabled }
        Ui.launchApp()
        Ui.openTab("Settings")
        Ui.awaitText(label)
        assertFalse(Ui.switchNear(label).isChecked)
    }

    @Test
    fun theMicrophoneSourceCanBeSwitchedAndIsRemembered() {
        openSettings()
        Ui.awaitText("Voice communication (echo-cancelled)")
        Ui.clickText("Change")
        Ui.awaitText("Raw microphone")
        assertEquals("mic", AppState.settings().micSource)
        Ui.launchApp()
        Ui.openTab("Settings")
        Ui.awaitText("Raw microphone")
        Ui.clickText("Change")
        Ui.awaitText("Voice communication (echo-cancelled)")
    }

    @Test
    fun theSetupGuideOpensFromSettingsAndGoesBack() {
        openSettings()
        Ui.clickText("Setup guide")
        Ui.awaitText("Setup")
        assertTrue(Ui.hasContaining("Permissions"))
        assertTrue(Ui.hasContaining("Call capture"))
        Ui.snapshot("setup-guide")
        Ui.scrollDownTo("I understand")
        assertTrue(Ui.hasContaining("Consent"))
        Ui.device.pressBack()
        Ui.awaitText("Settings")
        assertTrue(Ui.has("Device"))
    }

    @Test
    fun unpairingReturnsToThePairingScreenAndForgetsTheTokenAndTheCachedLibrary() {
        SeedData.ensure()
        openSettings()
        Ui.openTab("Library")
        Library.awaitLoaded()
        Ui.openTab("Settings")
        Ui.clickText("Unpair")
        Ui.awaitText("Pair this phone", Ui.NORMAL)
        assertNull(AppState.token())
        assertEquals(0, AppState.cachedLibrarySize())
        Ui.launchApp()
        Ui.awaitText("Pair this phone")
        assertFalse(AppState.settings().paired)
    }
}
