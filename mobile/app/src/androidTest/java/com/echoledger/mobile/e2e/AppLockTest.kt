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
import com.echoledger.mobile.e2e.support.E2eTest
import com.echoledger.mobile.e2e.support.Shell
import com.echoledger.mobile.e2e.support.Ui
import org.junit.After
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test
import org.junit.runner.RunWith

/** The app lock needs a secure lock screen on the phone; these tests set (and always remove) a device PIN. */
@RunWith(AndroidJUnit4::class)
class AppLockTest : E2eTest() {
    private val pin = "2468"
    private val lockLabel = "App lock"

    @After
    fun removeDevicePin() {
        Shell.clearDevicePin(pin)
    }

    private fun enterPin() {
        Ui.awaitText("Unlock EchoLedger", Ui.NORMAL)
        Thread.sleep(800)
        Shell.typeText(pin)
        Shell.keyEvent("KEYCODE_ENTER")
    }

    /** Backs out of the system unlock prompt (the first Back may only hide its keypad). */
    private fun cancelUnlockPrompt() {
        Ui.awaitText("Unlock EchoLedger", Ui.NORMAL)
        repeat(3) {
            if (Ui.has("Unlock EchoLedger")) {
                Ui.device.pressBack()
                Thread.sleep(800)
            }
        }
    }

    /** Switching the lock on takes one successful unlock: the switch opens the prompt and only the PIN saves it. */
    private fun enableLockWithPin() {
        Shell.setDevicePin(pin)
        launchPaired()
        Ui.openTab("Settings")
        Ui.clickText("Privacy & security")
        Ui.awaitText(lockLabel)
        Ui.switchNear(lockLabel).click()
        enterPin()
        Ui.awaitCondition("the app lock to be saved", Ui.NORMAL) { AppState.settings().appLockEnabled }
    }

    @Test
    fun withoutASecureLockScreenTheSwitchIsDisabledAndSaysWhy() {
        launchPaired()
        Ui.openTab("Settings")
        Ui.clickText("Privacy & security")
        Ui.awaitText(lockLabel)
        assertTrue(Ui.has("Set a screen lock on this phone to use App lock."))
        assertTrue(Ui.has("Open security settings"))
        assertFalse("the switch cannot be turned on", Ui.switchNear(lockLabel).isEnabled)
    }

    @Test
    fun cancellingTheUnlockWhenSwitchingTheLockOnLeavesItOff() {
        Shell.setDevicePin(pin)
        launchPaired()
        Ui.openTab("Settings")
        Ui.clickText("Privacy & security")
        Ui.awaitText(lockLabel)
        Ui.switchNear(lockLabel).click()
        cancelUnlockPrompt()
        Ui.awaitText(lockLabel, Ui.NORMAL)
        assertFalse(AppState.settings().appLockEnabled)
        assertFalse(Ui.switchNear(lockLabel).isChecked)
    }

    @Test
    fun withAPinTheSwitchAppearsAndTheAppAsksToUnlockOnTheNextLaunch() {
        enableLockWithPin()
        Ui.launchApp()
        enterPin()
        Ui.awaitTextGone("Unlock EchoLedger", Ui.NORMAL)
        Ui.awaitText("Library")
        assertFalse(Ui.has("EchoLedger is locked"))
    }

    @Test
    fun cancellingTheUnlockPromptKeepsTheAppLocked() {
        enableLockWithPin()
        Ui.launchApp()
        cancelUnlockPrompt()
        Ui.awaitText("EchoLedger is locked", Ui.NORMAL)
        assertTrue(Ui.has("Unlock"))
        Ui.snapshot("lock-cover")
        assertFalse("tabs are hidden while locked", Ui.hasDescription("Settings"))
    }

    @Test
    fun theLockedAppDoesNotExposeTheLibraryBehindTheCover() {
        enableLockWithPin()
        Ui.launchApp()
        cancelUnlockPrompt()
        Ui.awaitText("EchoLedger is locked", Ui.NORMAL)
        assertFalse(
            "screen content must not be readable through accessibility while the cover is shown",
            Ui.has("Recordings from your phone, computer and the web") || Ui.has("All")
        )
    }

    @Test
    fun theLockEngagesAgainAfterTheAppWasLeftAndComesBack() {
        enableLockWithPin()
        Ui.launchApp()
        enterPin()
        Ui.awaitTextGone("Unlock EchoLedger", Ui.NORMAL)
        Ui.awaitText("Library")
        Ui.device.pressHome()
        Thread.sleep(5_000)
        Ui.resumeApp()
        Ui.awaitText("EchoLedger is locked", Ui.SHORT)
    }

    @Test
    fun switchingTheLockOffRemovesTheCoverOnTheNextLaunch() {
        enableLockWithPin()
        Ui.switchNear(lockLabel).click()
        Ui.awaitCondition("the app lock to be switched off", Ui.NORMAL) { !AppState.settings().appLockEnabled }
        Ui.launchApp()
        Ui.awaitText("Library", Ui.NORMAL)
        assertFalse(Ui.has("EchoLedger is locked"))
    }
}
