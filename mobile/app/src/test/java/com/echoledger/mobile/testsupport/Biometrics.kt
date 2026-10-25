package com.echoledger.mobile.testsupport

import android.app.KeyguardManager
import android.content.Context
import android.hardware.biometrics.BiometricManager
import org.robolectric.shadow.api.Shadow
import org.robolectric.shadows.ShadowBiometricManager
import org.robolectric.shadows.ShadowKeyguardManager

/**
 * Makes the phone report that it can (or cannot) authenticate with a fingerprint, face or device credential: the
 * platform BiometricManager answer (API 30+) is success, or `BIOMETRIC_ERROR_NO_HARDWARE` when [available] is false.
 * Robolectric keeps this in static state that is reset between tests.
 */
fun Context.simulateBiometrics(available: Boolean) {
    val manager = getSystemService(BiometricManager::class.java)
    Shadow.extract<ShadowBiometricManager>(manager).setCanAuthenticate(available)
}

/**
 * Whether a PIN, pattern or password secures the phone (`KeyguardManager.isDeviceSecure`, also per user, which
 * `createConfirmDeviceCredentialIntent` consults). Robolectric runs as user 0.
 */
fun Context.simulateScreenLock(secure: Boolean) {
    val keyguard = Shadow.extract<ShadowKeyguardManager>(getSystemService(KeyguardManager::class.java))
    keyguard.setIsDeviceSecure(secure)
    keyguard.setIsDeviceSecure(0, secure)
    keyguard.setIsKeyguardSecure(secure)
}
