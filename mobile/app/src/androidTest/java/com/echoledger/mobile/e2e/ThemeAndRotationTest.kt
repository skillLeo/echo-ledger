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

import android.graphics.Rect
import androidx.test.ext.junit.runners.AndroidJUnit4
import com.echoledger.mobile.e2e.support.E2eTest
import com.echoledger.mobile.e2e.support.Library
import com.echoledger.mobile.e2e.support.SeedData
import com.echoledger.mobile.e2e.support.Shell
import com.echoledger.mobile.e2e.support.Ui
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Ignore
import org.junit.Test
import org.junit.runner.RunWith

/** Looks of the app with the phone set to its light and dark system themes. */
@RunWith(AndroidJUnit4::class)
class ThemeTest : E2eTest() {
    /** The status bar's clock and icons, as a share of the screen (they start at the top left and top right). */
    private fun statusBarContentArea(): Rect {
        val w = Ui.device.displayWidth
        val h = Ui.device.displayHeight
        return Rect((w * 0.03).toInt(), (h * 0.017).toInt(), (w * 0.14).toInt(), (h * 0.033).toInt())
    }

    private fun backgroundLuminance(): Int {
        val shot = Ui.screenshot()
        val h = Ui.device.displayHeight
        return Ui.brightest(shot, Rect(shot.width / 2 - 20, (h * 0.07).toInt(), shot.width / 2 + 20, (h * 0.075).toInt()))
    }

    private fun clockContrast(): Int {
        val shot = Ui.screenshot()
        val pixel = shot.getPixel(shot.width / 2, (Ui.device.displayHeight * 0.01).toInt())
        val background = ((pixel shr 16 and 255) + (pixel shr 8 and 255) + (pixel and 255)) / 3
        return Ui.brightest(shot, statusBarContentArea()) - background
    }

    @Test
    fun theAppKeepsItsDarkPaletteInTheLightSystemTheme() {
        Shell.setNightMode(false)
        Ui.launchApp()
        Ui.awaitText("Pair this phone")
        assertTrue("canvas is dark in the light system theme", backgroundLuminance() < 60)
    }

    @Test
    fun theAppKeepsItsDarkPaletteInTheDarkSystemTheme() {
        Shell.setNightMode(true)
        Ui.launchApp()
        Ui.awaitText("Pair this phone")
        assertTrue("canvas is dark in the dark system theme", backgroundLuminance() < 60)
    }

    @Test
    fun statusBarClockAndIconsAreReadableWhenThePhoneUsesTheDarkSystemTheme() {
        Shell.setNightMode(true)
        Ui.launchApp()
        Ui.awaitText("Pair this phone")
        Thread.sleep(800)
        val contrast = clockContrast()
        assertTrue("status bar content contrast is $contrast / 255", contrast >= 100)
    }

    @Ignore("NEW: status bar icons are dark on the dark app background in the light system theme")
    @Test
    fun statusBarClockAndIconsAreReadableWhenThePhoneUsesTheLightSystemTheme() {
        Shell.setNightMode(false)
        Ui.launchApp()
        Ui.awaitText("Pair this phone")
        Thread.sleep(800)
        val contrast = clockContrast()
        assertTrue("status bar content contrast is $contrast / 255 (the app draws a dark canvas behind the bar)", contrast >= 100)
    }

    @Test
    fun switchingTheSystemThemeWhileTheAppIsOpenKeepsTheScreenAndItsState() {
        Ui.launchApp()
        Ui.awaitText("Pair this phone")
        Ui.fillField("Pairing code", "ABCD-EFGH")
        Shell.setNightMode(true)
        Thread.sleep(2_500)
        Ui.awaitText("Pair this phone")
        assertEquals("ABCD-EFGH", Ui.field("Pairing code").text)
    }
}

/** What survives turning the phone sideways. */
@RunWith(AndroidJUnit4::class)
class RotationTest : E2eTest() {
    @Test
    fun theTypedPairingCodeSurvivesRotation() {
        Ui.launchApp()
        Ui.awaitText("Pair this phone")
        Ui.fillField("Server address", "http://10.0.2.2:8113")
        Ui.fillField("Pairing code", "ABCD-EFGH")
        Ui.hideKeyboard()
        Ui.device.setOrientationLeft()
        Thread.sleep(2_500)
        Ui.awaitText("Pair this phone")
        assertEquals("http://10.0.2.2:8113", Ui.field("Server address").text)
        Ui.scrollDownTo("Pairing code")
        assertEquals("ABCD-EFGH", Ui.field("Pairing code").text)
        Ui.device.setOrientationNatural()
    }

    @Ignore("NEW: rotating the phone returns to the library (tab, open recording and setup page are not saved)")
    @Test
    fun theSelectedTabIsKeptWhenThePhoneIsRotated() {
        launchPaired()
        Ui.openTab("Settings")
        Ui.awaitText("Device")
        Ui.device.setOrientationLeft()
        Thread.sleep(2_500)
        assertFalse("rotating must not send the user back to the Library", Ui.has("Recordings from your phone, computer and the web"))
        Ui.device.setOrientationNatural()
    }

    @Ignore("NEW: in landscape the library header, search, banner and filters leave no room for the list")
    @Test
    fun theLibraryIsUsableInLandscape() {
        SeedData.ensure()
        launchPaired()
        Library.awaitLoaded()
        Ui.device.setOrientationLeft()
        Thread.sleep(2_500)
        assertTrue("at least the newest recording is visible in landscape", Ui.has(SeedData.SILENT))
        Ui.device.setOrientationNatural()
        Ui.awaitText("Library")
    }
}
