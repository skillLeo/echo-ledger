package com.echoledger.mobile.ui

import androidx.biometric.BiometricManager
import androidx.biometric.BiometricPrompt
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.Lock
import androidx.compose.material3.Icon
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.content.ContextCompat
import androidx.fragment.app.FragmentActivity

/** Which biometric/credential classes count as "available" for the app lock. */
private const val AUTHENTICATORS =
    BiometricManager.Authenticators.BIOMETRIC_STRONG or BiometricManager.Authenticators.DEVICE_CREDENTIAL

/** True when the phone can lock with a fingerprint/face or a device PIN/pattern/password. */
fun biometricAvailable(activity: FragmentActivity): Boolean =
    BiometricManager.from(activity).canAuthenticate(AUTHENTICATORS) == BiometricManager.BIOMETRIC_SUCCESS

/** Prompts for a fingerprint/face/PIN. [onResult] fires with true on success, false on a hard failure or no option. */
fun promptUnlock(activity: FragmentActivity, onResult: (Boolean) -> Unit) {
    if (!biometricAvailable(activity)) {
        onResult(true) // nothing to lock with (e.g. secure lock screen removed): do not trap the user out
        return
    }
    val prompt = BiometricPrompt(
        activity,
        ContextCompat.getMainExecutor(activity),
        object : BiometricPrompt.AuthenticationCallback() {
            override fun onAuthenticationSucceeded(result: BiometricPrompt.AuthenticationResult) = onResult(true)
            override fun onAuthenticationError(code: Int, message: CharSequence) {
                // User cancelled / too many attempts: keep the app locked.
                onResult(false)
            }
        }
    )
    prompt.authenticate(
        BiometricPrompt.PromptInfo.Builder()
            .setTitle("Unlock EchoLedger")
            .setSubtitle("Your recordings and transcripts are protected")
            .setAllowedAuthenticators(AUTHENTICATORS)
            .build()
    )
}

/** Full-screen lock cover shown until the user authenticates. */
@Composable
fun LockScreen(onUnlock: () -> Unit) {
    Column(
        Modifier.fillMaxSize().background(Brand.Background).padding(32.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.Center
    ) {
        Column(Modifier.size(84.dp).clip(CircleShape).background(Brand.GradientSoft), horizontalAlignment = Alignment.CenterHorizontally) {}
        Icon(Icons.Outlined.Lock, contentDescription = null, tint = Brand.Violet, modifier = Modifier.size(40.dp).padding(top = 0.dp))
        Text(
            "EchoLedger is locked",
            color = Brand.Text,
            fontWeight = FontWeight.Bold,
            fontSize = 20.sp,
            modifier = Modifier.padding(top = 18.dp)
        )
        Text(
            "Unlock with your fingerprint, face or device PIN.",
            color = Brand.Muted,
            fontSize = 13.sp,
            textAlign = TextAlign.Center,
            modifier = Modifier.padding(top = 6.dp)
        )
        OutlinedButton(onClick = onUnlock, modifier = Modifier.padding(top = 24.dp)) { Text("Unlock") }
    }
}
