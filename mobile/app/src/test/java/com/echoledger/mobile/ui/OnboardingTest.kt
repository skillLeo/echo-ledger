package com.echoledger.mobile.ui

import android.Manifest
import androidx.compose.ui.test.assertCountEquals
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.assertIsEnabled
import androidx.compose.ui.test.assertIsNotEnabled
import androidx.compose.ui.test.isToggleable
import androidx.compose.ui.test.onAllNodesWithText
import androidx.compose.ui.test.onNodeWithContentDescription
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import com.echoledger.mobile.testsupport.ScreenTest
import com.echoledger.mobile.testsupport.pairLocally
import com.echoledger.mobile.testsupport.pumpUntil
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test
import org.robolectric.Shadows.shadowOf

/** The first-run wizard: welcome → permissions → pairing → a 10-second test → done (B19). */
class OnboardingTest : ScreenTest() {
    private fun settings() = runBlocking { app.graph.settings.current() }

    private fun showWizard() = show { OnboardingScreen(vm) }

    private fun next(label: String) = compose.onNodeWithText(label).performClick()

    @Test
    fun `the wizard starts with a welcome and says which step it is on`() {
        showWizard()

        compose.onNodeWithText("Welcome to EchoLedger").assertIsDisplayed()
        compose.onNodeWithText("Step 1 of 5").assertIsDisplayed()
        compose.onNodeWithText("Get started").assertIsDisplayed()
    }

    @Test
    fun `the permissions step lists what the phone needs and what is still missing`() {
        shadowOf(app).grantPermissions(Manifest.permission.RECORD_AUDIO)
        showWizard()

        next("Get started")

        compose.onNodeWithText("Allow what EchoLedger needs").assertIsDisplayed()
        compose.onNodeWithText("Step 2 of 5").assertIsDisplayed()
        listOf("Microphone", "Call capture helper", "Notifications", "Display over other apps", "Unrestricted battery use").forEach {
            compose.onNodeWithText(it).assertIsDisplayed()
        }
        compose.onNodeWithText("Allowed").assertIsDisplayed()
        compose.onNodeWithText("Optional. Set it up later in Settings → Devices.").assertIsDisplayed()
    }

    @Test
    fun `a microphone that is not allowed yet offers the Allow button`() {
        showWizard()

        next("Get started")

        compose.onAllNodesWithText("Allow")[0].assertIsEnabled()
    }

    @Test
    fun `an unpaired phone is asked to pair and can skip that step`() {
        showWizard()
        next("Get started")
        next("Continue")

        compose.onNodeWithText("Pair this phone").assertIsDisplayed()
        compose.onNodeWithText("Step 3 of 5").assertIsDisplayed()

        next("Skip for now")
        compose.onNodeWithText("Try a 10-second test").assertIsDisplayed()
        assertFalse("skipping a step does not end the wizard", settings().onboardingDone)
    }

    @Test
    fun `a phone that is already paired goes straight past the pairing step`() {
        runBlocking { app.pairLocally(server) }
        pumpUntil("paired") { vm.settings.value.paired }
        showWizard()
        next("Get started")
        next("Continue")

        compose.onNodeWithText("Try a 10-second test").assertIsDisplayed()
        compose.onAllNodesWithText("Pair this phone").assertCountEquals(0)
    }

    @Test
    fun `the test needs the microphone and can be skipped`() {
        runBlocking { app.pairLocally(server) }
        pumpUntil("paired") { vm.settings.value.paired }
        showWizard()
        next("Get started")
        next("Continue")

        compose.onNodeWithText("Start the test").assertIsNotEnabled()
        compose.onNodeWithText("Allow the microphone on the previous step to run the test.").assertIsDisplayed()

        next("Skip the test")
        compose.onNodeWithText("You are set").assertIsDisplayed()
    }

    @Test
    fun `with the microphone allowed the test can be started`() {
        shadowOf(app).grantPermissions(Manifest.permission.RECORD_AUDIO)
        runBlocking { app.pairLocally(server) }
        pumpUntil("paired") { vm.settings.value.paired }
        showWizard()
        next("Get started")
        next("Continue")

        compose.onNodeWithText("Start the test").assertIsEnabled()
        compose.onAllNodesWithText("Allow the microphone on the previous step to run the test.").assertCountEquals(0)
    }

    @Test
    fun `the last step saves the flag and the consent that was ticked`() {
        runBlocking { app.pairLocally(server) }
        pumpUntil("paired") { vm.settings.value.paired }
        runBlocking { app.graph.settings.update { it.copy(onboardingDone = false) } }
        showWizard()
        next("Get started")
        next("Continue")
        next("Skip the test")

        compose.onNodeWithText("You are set").assertIsDisplayed()
        compose.onNode(isToggleable()).performClick()
        next("Start using EchoLedger")

        pumpUntil("wizard finished") { settings().onboardingDone }
        assertTrue(settings().consentAcknowledged)
    }

    @Test
    fun `finishing without ticking consent leaves it off`() {
        runBlocking { app.pairLocally(server) }
        pumpUntil("paired") { vm.settings.value.paired }
        runBlocking { app.graph.settings.update { it.copy(onboardingDone = false) } }
        showWizard()
        next("Get started")
        next("Continue")
        next("Skip the test")

        next("Start using EchoLedger")

        pumpUntil("wizard finished") { settings().onboardingDone }
        assertFalse(settings().consentAcknowledged)
    }

    @Test
    fun `skip setup ends the wizard for good`() {
        showWizard()

        next("Skip setup")

        pumpUntil("wizard skipped") { settings().onboardingDone }
    }

    @Test
    fun `the progress bar is described for screen readers`() {
        showWizard()

        compose.onNodeWithContentDescription("Setup progress, Step 1 of 5").assertIsDisplayed()
    }
}
