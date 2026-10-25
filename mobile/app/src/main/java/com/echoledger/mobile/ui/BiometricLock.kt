package com.echoledger.mobile.ui

import android.app.Activity
import android.app.KeyguardManager
import android.content.Context
import android.content.ContextWrapper
import android.content.Intent
import android.view.Window
import android.view.WindowManager
import androidx.activity.ComponentActivity
import androidx.activity.result.ActivityResultLauncher
import androidx.activity.result.contract.ActivityResultContracts
import androidx.biometric.BiometricManager
import androidx.biometric.BiometricPrompt
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.Lock
import androidx.compose.material3.Icon
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.remember
import androidx.compose.runtime.staticCompositionLocalOf
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.content.ContextCompat
import androidx.fragment.app.FragmentActivity
import com.echoledger.mobile.R
import java.util.concurrent.atomic.AtomicInteger

/**
 * What may unlock the app. The lock is a UI gate (no key is bound to it), so a Class 2 biometric such as face unlock
 * or the device PIN/pattern/password is enough. `AuthenticatorUtils.isSupportedCombination` (androidx.biometric 1.1.0)
 * accepts this pair on every API level; `BIOMETRIC_STRONG or DEVICE_CREDENTIAL` is refused on API 28-29.
 */
const val AUTHENTICATORS = BiometricManager.Authenticators.BIOMETRIC_WEAK or BiometricManager.Authenticators.DEVICE_CREDENTIAL

private const val PROMPT_TITLE = "Unlock EchoLedger"
private const val PROMPT_SUBTITLE = "Your recordings and transcripts are protected"

/**
 * How this phone can unlock the app, checked at the moment of the call (the user may add or remove a screen lock at
 * any time). `canAuthenticate` delegates to the platform on API 30+; on API 29 it reports success exactly when the
 * keyguard is secured with a credential (androidx.biometric 1.1.0 `canAuthenticateCompat`).
 */
fun lockAvailability(context: Context): LockAvailability {
    val status = BiometricManager.from(context).canAuthenticate(AUTHENTICATORS)
    val deviceSecure = context.getSystemService(KeyguardManager::class.java)?.isDeviceSecure == true
    return lockAvailability(status, deviceSecure)
}

/** True when the phone can lock with a fingerprint/face or a device PIN/pattern/password. */
fun biometricAvailable(activity: FragmentActivity): Boolean = lockAvailability(activity).method != Method.None

/** Asks the user to authenticate; `onResult(true)` on success, `false` when the prompt was cancelled or failed. */
fun interface UnlockPrompter {
    fun prompt(onResult: (Boolean) -> Unit)
}

/** The prompter of the hosting activity (MainActivity provides it), so screens can ask for an unlock. */
val LocalUnlockPrompter = staticCompositionLocalOf<UnlockPrompter?> { null }

/** A prompter for the activity hosting this composition, or null when it is not a [FragmentActivity]. */
@Composable
fun rememberUnlockPrompter(): UnlockPrompter? {
    val activity = LocalContext.current.findFragmentActivity() ?: return null
    return remember(activity) { UnlockPrompter { onResult -> promptUnlock(activity, onResult = onResult) } }
}

/**
 * Prompts for a fingerprint, face or the device credential. [onResult] fires with true on success and false when the
 * user cancels or authentication fails. When the phone has nothing to unlock with (the screen lock was removed after
 * the app lock was switched on) it reports success at once, so the user is never trapped behind the cover.
 *
 * [confirmCredential] launches the system confirm-credential screen and reports whether it returned `RESULT_OK`; it is
 * used only where BiometricPrompt cannot authenticate but a PIN, pattern or password is set.
 */
fun promptUnlock(
    activity: FragmentActivity,
    confirmCredential: (Intent, (Boolean) -> Unit) -> Unit = { intent, done -> activity.launchOnce(intent, done) },
    onResult: (Boolean) -> Unit
) {
    when (lockAvailability(activity).method) {
        Method.None -> onResult(true)

        Method.Biometric -> showBiometricPrompt(activity, onResult)

        Method.Keyguard -> {
            // Deprecated in API 29 in favour of BiometricPrompt; used only when BiometricManager reports that
            // BiometricPrompt cannot authenticate. Returns null when the device is not secure.
            @Suppress("DEPRECATION")
            val intent = activity.getSystemService(KeyguardManager::class.java)
                ?.createConfirmDeviceCredentialIntent(PROMPT_TITLE, PROMPT_SUBTITLE)
            if (intent == null) onResult(true) else confirmCredential(intent, onResult)
        }
    }
}

/**
 * After the activity was recreated (rotation) while a prompt was showing, routes that prompt's result to [onResult].
 * androidx.biometric drops the old callback when the activity is destroyed; constructing a BiometricPrompt sets the
 * new one without showing anything.
 */
fun reconnectUnlockPrompt(activity: FragmentActivity, onResult: (Boolean) -> Unit) {
    if (lockAvailability(activity).method == Method.Biometric) biometricPrompt(activity, onResult)
}

private fun biometricPrompt(activity: FragmentActivity, onResult: (Boolean) -> Unit) = BiometricPrompt(
    activity,
    ContextCompat.getMainExecutor(activity),
    object : BiometricPrompt.AuthenticationCallback() {
        override fun onAuthenticationSucceeded(result: BiometricPrompt.AuthenticationResult) = onResult(true)

        // User cancelled, too many attempts or the system dismissed the prompt: the app stays locked. A single
        // rejected finger (onAuthenticationFailed) keeps the prompt open, so it reports nothing.
        override fun onAuthenticationError(code: Int, message: CharSequence) = onResult(false)
    }
)

private fun showBiometricPrompt(activity: FragmentActivity, onResult: (Boolean) -> Unit) {
    val prompt = biometricPrompt(activity, onResult)
    // No negative button: PromptInfo.Builder.build() throws when one is set while DEVICE_CREDENTIAL is allowed
    // (androidx.biometric 1.1.0); the system prompt then offers the PIN/pattern/password itself.
    prompt.authenticate(
        BiometricPrompt.PromptInfo.Builder()
            .setTitle(PROMPT_TITLE)
            .setSubtitle(PROMPT_SUBTITLE)
            .setAllowedAuthenticators(AUTHENTICATORS)
            .build()
    )
}

private val launchKeys = AtomicInteger()

/**
 * Starts [intent] for a result through the activity's Activity Result registry with a one-shot key. Registering
 * without a lifecycle owner is allowed at any time; the launcher must then be unregistered by hand
 * (`ActivityResultRegistry.register(key, contract, callback)`). A result that arrives after the activity was recreated
 * finds no callback and is dropped, so the app stays locked rather than unlocking on a stale answer.
 */
private fun ComponentActivity.launchOnce(intent: Intent, onResult: (Boolean) -> Unit) {
    var launcher: ActivityResultLauncher<Intent>? = null
    launcher = activityResultRegistry.register(
        "echoledger.unlock.${launchKeys.incrementAndGet()}",
        ActivityResultContracts.StartActivityForResult()
    ) { result ->
        launcher?.unregister()
        onResult(result.resultCode == Activity.RESULT_OK)
    }
    launcher.launch(intent)
}

private tailrec fun Context.findFragmentActivity(): FragmentActivity? = when (this) {
    is FragmentActivity -> this
    is ContextWrapper -> baseContext.findFragmentActivity()
    else -> null
}

/** Hides the window's content in Recents and blocks screenshots and screen recording while [secure] is true. */
fun Window.applySecureScreen(secure: Boolean) {
    if (secure) {
        setFlags(WindowManager.LayoutParams.FLAG_SECURE, WindowManager.LayoutParams.FLAG_SECURE)
    } else {
        clearFlags(WindowManager.LayoutParams.FLAG_SECURE)
    }
}

/** Neutral first frame while the stored settings load: background and brand mark, nothing else. */
@Composable
fun StartupSplash() {
    Box(Modifier.fillMaxSize().background(Brand.Background).testTag("startup-splash"), contentAlignment = Alignment.Center) {
        Box(Modifier.size(72.dp).clip(RoundedCornerShape(22.dp)).background(Brand.Gradient), contentAlignment = Alignment.Center) {
            Image(painterResource(R.drawable.ic_launcher_foreground), contentDescription = null, modifier = Modifier.size(72.dp))
        }
    }
}

/** Full-screen lock cover shown until the user authenticates. Nothing else is composed while it is shown. */
@Composable
fun LockScreen(onUnlock: () -> Unit) {
    Column(
        Modifier.fillMaxSize().background(Brand.Background).padding(32.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.Center
    ) {
        Box(Modifier.size(84.dp).clip(CircleShape).background(Brand.GradientSoft), contentAlignment = Alignment.Center) {
            Icon(Icons.Outlined.Lock, contentDescription = null, tint = Brand.Violet, modifier = Modifier.size(40.dp))
        }
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
