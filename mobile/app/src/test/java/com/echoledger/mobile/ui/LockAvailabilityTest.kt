package com.echoledger.mobile.ui

import android.content.Context
import androidx.biometric.BiometricManager
import androidx.biometric.BiometricManager.Authenticators.BIOMETRIC_STRONG
import androidx.biometric.BiometricManager.Authenticators.DEVICE_CREDENTIAL
import androidx.biometric.BiometricPrompt
import androidx.test.core.app.ApplicationProvider
import com.echoledger.mobile.testsupport.simulateBiometrics
import com.echoledger.mobile.testsupport.simulateScreenLock
import org.junit.Assert.assertEquals
import org.junit.Assert.assertThrows
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/** Which way the app lock unlocks on a given phone, per Android version. */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34])
class LockAvailabilityTest {
    private val context: Context = ApplicationProvider.getApplicationContext()

    private val biometric = LockAvailability(Method.Biometric, null)
    private val keyguard = LockAvailability(Method.Keyguard, null)
    private val noScreenLock = LockAvailability(Method.None, "Set a screen lock on this phone to use App lock.")

    @Test
    fun `every BiometricManager answer maps to a way to unlock or a reason`() {
        val falls = listOf(
            BiometricManager.BIOMETRIC_ERROR_UNSUPPORTED,
            BiometricManager.BIOMETRIC_STATUS_UNKNOWN,
            BiometricManager.BIOMETRIC_ERROR_NONE_ENROLLED,
            BiometricManager.BIOMETRIC_ERROR_NO_HARDWARE,
            BiometricManager.BIOMETRIC_ERROR_HW_UNAVAILABLE
        )

        assertEquals(biometric, lockAvailability(BiometricManager.BIOMETRIC_SUCCESS, deviceSecure = true))
        assertEquals(biometric, lockAvailability(BiometricManager.BIOMETRIC_SUCCESS, deviceSecure = false))
        falls.forEach { status ->
            assertEquals("status $status with a screen lock", keyguard, lockAvailability(status, deviceSecure = true))
            assertEquals("status $status without a screen lock", noScreenLock, lockAvailability(status, deviceSecure = false))
        }
        assertEquals(keyguard, lockAvailability(BiometricManager.BIOMETRIC_ERROR_SECURITY_UPDATE_REQUIRED, deviceSecure = true))
        assertEquals(
            LockAvailability(Method.None, "Install the latest security update to use App lock."),
            lockAvailability(BiometricManager.BIOMETRIC_ERROR_SECURITY_UPDATE_REQUIRED, deviceSecure = false)
        )
    }

    // ---- Android 10: androidx answers from the keyguard ------------------------------------------------------------------------

    @Config(sdk = [29])
    @Test
    fun `on Android 10 the lock is available exactly when a screen lock is set`() {
        context.simulateScreenLock(true)
        assertEquals(biometric, lockAvailability(context))

        context.simulateScreenLock(false)
        assertEquals(noScreenLock, lockAvailability(context))
    }

    @Config(sdk = [29])
    @Test
    fun `on Android 10 the weak-or-credential pair is accepted where strong-or-credential is refused`() {
        context.simulateScreenLock(true)

        assertEquals(BiometricManager.BIOMETRIC_SUCCESS, BiometricManager.from(context).canAuthenticate(AUTHENTICATORS))
        assertEquals(
            "the combination the old lock used is unsupported on Android 10",
            BiometricManager.BIOMETRIC_ERROR_UNSUPPORTED,
            BiometricManager.from(context).canAuthenticate(BIOMETRIC_STRONG or DEVICE_CREDENTIAL)
        )
        BiometricPrompt.PromptInfo.Builder().setTitle("Unlock EchoLedger").setAllowedAuthenticators(AUTHENTICATORS).build()
        val oldPair = BIOMETRIC_STRONG or DEVICE_CREDENTIAL
        assertThrows(IllegalArgumentException::class.java) {
            BiometricPrompt.PromptInfo.Builder().setTitle("Unlock EchoLedger").setAllowedAuthenticators(oldPair).build()
        }
    }

    @Test
    fun `a negative button cannot be combined with the device credential`() {
        assertThrows(IllegalArgumentException::class.java) {
            BiometricPrompt.PromptInfo.Builder()
                .setTitle("Unlock EchoLedger")
                .setAllowedAuthenticators(AUTHENTICATORS)
                .setNegativeButtonText("Cancel")
                .build()
        }
    }

    // ---- Android 11 and later: the platform decides ----------------------------------------------------------------------------

    @Config(sdk = [30, 34])
    @Test
    fun `from Android 11 a phone that can authenticate uses the biometric prompt`() {
        context.simulateBiometrics(true)
        context.simulateScreenLock(true)

        assertEquals(biometric, lockAvailability(context))
    }

    @Config(sdk = [30, 34])
    @Test
    fun `from Android 11 a secure phone the prompt cannot serve falls back to the screen lock`() {
        context.simulateBiometrics(false)
        context.simulateScreenLock(true)

        assertEquals(keyguard, lockAvailability(context))
    }

    @Config(sdk = [30, 34])
    @Test
    fun `from Android 11 a phone without a screen lock cannot use the lock and says why`() {
        context.simulateBiometrics(false)
        context.simulateScreenLock(false)

        assertEquals(noScreenLock, lockAvailability(context))
    }
}
