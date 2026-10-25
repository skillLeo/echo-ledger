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

import android.Manifest
import android.content.pm.PackageManager
import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import com.echoledger.mobile.e2e.support.AppState
import com.echoledger.mobile.e2e.support.Backend
import com.echoledger.mobile.e2e.support.E2eTest
import com.echoledger.mobile.e2e.support.Shell
import com.echoledger.mobile.e2e.support.Ui
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Ignore
import org.junit.Test
import org.junit.runner.RunWith

/**
 * The camera permission flow of "Scan QR code" and opening/closing the scanner. Each test starts from a fresh install
 * state (no runtime permission granted); tests that need the camera grant it through the instrumentation.
 */
@RunWith(AndroidJUnit4::class)
class QrScannerTest : E2eTest() {
    private fun cameraGranted() = Ui.context.checkSelfPermission(Manifest.permission.CAMERA) == PackageManager.PERMISSION_GRANTED

    private fun grantCamera() {
        InstrumentationRegistry.getInstrumentation().uiAutomation.grantRuntimePermission(Ui.PACKAGE, Manifest.permission.CAMERA)
    }

    private fun openPairingScreen() {
        Ui.launchApp()
        Ui.awaitText("Pair this phone")
    }

    private fun answerCameraPrompt(vararg labels: String) {
        Ui.awaitCondition("the camera permission dialog", Ui.NORMAL) { Ui.permissionDialogButton(*labels) != null }
        Ui.permissionDialogButton(*labels)!!.click()
    }

    private fun cameraInUseByTheApp(): Boolean = Shell.run("dumpsys media.camera").contains("Client Package Name: ${Ui.PACKAGE}")

    @Ignore("NEW: runtime permission requests crash the app (androidx.fragment 1.2.5 rejects the activity result request code)")
    @Test
    fun scanAsksForTheCameraAndDenyingExplainsHowToEnterTheCodeByHand() {
        assertFalse("camera permission must start out missing", cameraGranted())
        openPairingScreen()
        Ui.clickText("Scan QR code")
        answerCameraPrompt("Don't allow", "Deny")
        Ui.awaitText("Camera access is needed to scan. You can still enter the server address and code by hand.", Ui.NORMAL)
        assertTrue(Ui.has("Pair this phone") && Ui.has("Pairing code"))
        assertFalse(cameraGranted())
        assertFalse("no scanner without permission", Ui.has("Scan the pairing QR code"))
    }

    @Ignore("NEW: runtime permission requests crash the app (androidx.fragment 1.2.5 rejects the activity result request code)")
    @Test
    fun allowingTheCameraPromptOpensTheScanner() {
        openPairingScreen()
        Ui.clickText("Scan QR code")
        answerCameraPrompt("While using the app", "Only this time")
        Ui.awaitText("Scan the pairing QR code", Ui.NORMAL)
        assertTrue(cameraGranted())
    }

    @Test
    fun theScannerOpensWithTheCameraAndBackClosesItWithoutPairing() {
        grantCamera()
        openPairingScreen()
        Ui.clickText("Scan QR code")
        Ui.awaitText("Scan the pairing QR code", Ui.NORMAL)
        Ui.snapshot("scanner")
        assertTrue(Ui.has("Cancel"))
        Ui.awaitCondition("the camera to open", Ui.NORMAL) { cameraInUseByTheApp() }
        Ui.device.pressBack()
        Ui.awaitText("Pair this phone", Ui.NORMAL)
        Ui.awaitCondition("the camera to be released", Ui.NORMAL) { !cameraInUseByTheApp() }
        assertFalse(AppState.settings().paired)
    }

    @Test
    fun cancelClosesTheScannerAndReleasesTheCamera() {
        grantCamera()
        openPairingScreen()
        Ui.clickText("Scan QR code")
        Ui.awaitText("Scan the pairing QR code", Ui.NORMAL)
        Ui.clickText("Cancel")
        Ui.awaitText("Pair this phone", Ui.NORMAL)
        Ui.awaitCondition("the camera to be released", Ui.NORMAL) { !cameraInUseByTheApp() }
    }

    @Test
    fun aCameraViewWithoutAnyPairingCodeOffersNothing() {
        grantCamera()
        openPairingScreen()
        Ui.clickText("Scan QR code")
        Ui.awaitText("Scan the pairing QR code", Ui.NORMAL)
        Thread.sleep(4_000)
        assertFalse("no confirmation without a pairing code in view", Ui.has("Pair with this server?"))
        assertFalse(Ui.has("That isn't an EchoLedger pairing code"))
        Ui.device.pressBack()
        Ui.awaitText("Pair this phone", Ui.NORMAL)
    }

    @Test
    fun pairingByHandStillWorksAfterTheScannerWasUsed() {
        grantCamera()
        Backend.awaitPairingBudget()
        val code = Backend.pairingCode(viaFlaky = true)
        openPairingScreen()
        Ui.clickText("Scan QR code")
        Ui.awaitText("Scan the pairing QR code", Ui.NORMAL)
        Ui.clickText("Cancel")
        Ui.awaitText("Pair this phone", Ui.NORMAL)
        Ui.fillField("Pairing code", code.payload)
        Ui.hideKeyboard()
        Ui.clickText("Pair")
        Ui.awaitDescription("Settings", Ui.LONG)
        assertEquals(code.url, AppState.settings().serverUrl)
    }
}
