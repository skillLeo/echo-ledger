package com.echoledger.mobile.testsupport

import android.hardware.biometrics.BiometricManager
import androidx.activity.ComponentActivity
import org.robolectric.shadow.api.Shadow
import org.robolectric.shadows.ShadowBiometricManager

/** Makes the phone report that it can (or cannot) authenticate with a fingerprint, face or device credential. */
fun ComponentActivity.simulateBiometrics(available: Boolean) {
    val manager = getSystemService(BiometricManager::class.java)
    Shadow.extract<ShadowBiometricManager>(manager).setCanAuthenticate(available)
}
