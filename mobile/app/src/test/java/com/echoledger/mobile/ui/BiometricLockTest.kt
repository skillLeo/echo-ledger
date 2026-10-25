package com.echoledger.mobile.ui

import androidx.activity.compose.setContent
import androidx.compose.ui.test.assertIsDisplayed
import androidx.compose.ui.test.junit4.createEmptyComposeRule
import androidx.compose.ui.test.onNodeWithText
import androidx.compose.ui.test.performClick
import androidx.fragment.app.FragmentActivity
import com.echoledger.mobile.testsupport.AppTest
import com.echoledger.mobile.testsupport.simulateBiometrics
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test
import org.robolectric.annotation.Config

/** The pure parts of the app lock: availability, the no-trap rule and the cover screen. */
@Config(sdk = [34], qualifiers = "w411dp-h2400dp-mdpi")
class BiometricLockTest : AppTest() {
    @get:Rule
    val compose = createEmptyComposeRule()

    private fun activityWith(biometricsAvailable: Boolean): FragmentActivity {
        val activity = buildActivity(FragmentActivity::class.java).setup().get()
        activity.simulateBiometrics(biometricsAvailable)
        return activity
    }

    @Test
    fun `the lock is available only when the phone can authenticate`() {
        assertTrue(biometricAvailable(activityWith(biometricsAvailable = true)))
        assertFalse(biometricAvailable(activityWith(biometricsAvailable = false)))
    }

    @Test
    fun `when nothing can authenticate the prompt reports success at once so the user is not locked out`() {
        val results = mutableListOf<Boolean>()

        promptUnlock(activityWith(biometricsAvailable = false)) { results += it }

        assertEquals(listOf(true), results)
    }

    @Test
    fun `when authentication is possible the prompt waits for the user instead of unlocking`() {
        val results = mutableListOf<Boolean>()

        promptUnlock(activityWith(biometricsAvailable = true)) { results += it }

        assertTrue("no result may be reported before the user authenticates: $results", results.isEmpty())
    }

    @Test
    fun `the cover names the app and offers an Unlock button`() {
        var taps = 0
        activityWith(biometricsAvailable = true).setContent { EchoLedgerTheme { LockScreen(onUnlock = { taps++ }) } }

        compose.onNodeWithText("EchoLedger is locked").assertIsDisplayed()
        compose.onNodeWithText("Unlock with your fingerprint, face or device PIN.").assertIsDisplayed()
        compose.onNodeWithText("Unlock").performClick()
        compose.onNodeWithText("Unlock").performClick()

        assertEquals(2, taps)
    }
}
