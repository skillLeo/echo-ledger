package com.echoledger.mobile.ui

import android.Manifest
import android.os.Build
import android.provider.Settings
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.Checkbox
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableFloatStateOf
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.compose.LifecycleEventEffect
import com.echoledger.mobile.core.OnboardingStep
import com.echoledger.mobile.recording.CaptureHealth
import com.echoledger.mobile.recording.MicTestResult
import kotlinx.coroutines.launch

/**
 * The first-run wizard (B19): welcome → permissions → pairing → a 10-second test recording → done. Every step can be
 * skipped, and the same controls stay in Settings. Finishing or skipping saves a flag, so it is never shown again.
 */
@Composable
fun OnboardingScreen(vm: MainViewModel) {
    var saved by rememberSaveable { mutableStateOf(OnboardingStep.Welcome.name) }
    val step = OnboardingStep.of(saved)
    val settings by vm.settings.collectAsState()
    BackHandler(enabled = step != OnboardingStep.Welcome) { saved = step.previous.name }
    // Pairing finished (here or before the process was restarted): carry on with the test.
    LaunchedEffect(settings.paired, step) {
        if (step == OnboardingStep.Pair && settings.paired) saved = OnboardingStep.Test.name
    }
    Column(Modifier.fillMaxSize().padding(horizontal = 20.dp, vertical = 12.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
            Text(step.label, color = Brand.Muted, fontSize = 12.sp)
            LinearProgressIndicator(
                progress = { (step.ordinal + 1f) / OnboardingStep.entries.size },
                modifier = Modifier.fillMaxWidth().semantics { contentDescription = "Setup progress, ${step.label}" }
            )
        }
        when (step) {
            OnboardingStep.Welcome -> WelcomeStep(onNext = { saved = step.next.name }, onSkip = { vm.finishOnboarding() })

            OnboardingStep.Permissions -> PermissionsStep(vm, onNext = { saved = step.next.name }, onSkip = { vm.finishOnboarding() })

            OnboardingStep.Pair -> Column(Modifier.weight(1f)) {
                Box(Modifier.weight(1f)) { PairingScreen(vm) }
                Row(Modifier.fillMaxWidth()) {
                    TextButton(onClick = { saved = step.next.name }) { Text("Skip for now") }
                }
            }

            OnboardingStep.Test -> TestStep(onNext = { saved = step.next.name }, onSkip = { vm.finishOnboarding() })

            OnboardingStep.Done -> DoneStep(onFinish = { consent -> vm.finishOnboarding(consent) })
        }
    }
}

@Composable
private fun ColumnScope.StepLayout(
    title: String,
    body: String,
    primary: String,
    onPrimary: () -> Unit,
    onSkip: (() -> Unit)?,
    primaryEnabled: Boolean = true,
    content: @Composable () -> Unit = {}
) {
    Column(Modifier.weight(1f)) {
        Column(
            Modifier.weight(1f).verticalScroll(rememberScrollState()),
            verticalArrangement = Arrangement.spacedBy(12.dp)
        ) {
            Text(title, fontSize = 26.sp, fontWeight = FontWeight.Bold)
            Text(body, color = Brand.Muted)
            content()
        }
        Row(Modifier.fillMaxWidth().padding(top = 8.dp), verticalAlignment = Alignment.CenterVertically) {
            if (onSkip != null) TextButton(onClick = onSkip) { Text("Skip setup") }
            Spacer(Modifier.weight(1f))
            Button(onClick = onPrimary, enabled = primaryEnabled) { Text(primary) }
        }
    }
}

@Composable
private fun ColumnScope.WelcomeStep(onNext: () -> Unit, onSkip: () -> Unit) {
    StepLayout(
        title = "Welcome to EchoLedger",
        body = "EchoLedger records your calls, voice notes and dictation on this phone and keeps them in your own library. " +
            "Setup takes a minute.",
        primary = "Get started",
        onPrimary = onNext,
        onSkip = onSkip
    ) {
        Spacer(Modifier.height(8.dp))
        Box(Modifier.size(56.dp).clip(CircleShape).background(Brand.Gradient))
    }
}

@Composable
private fun ColumnScope.PermissionsStep(vm: MainViewModel, onNext: () -> Unit, onSkip: () -> Unit) {
    val context = LocalContext.current
    val helper by vm.helper.collectAsState()
    var refresh by remember { mutableIntStateOf(0) }
    var micDenied by rememberSaveable { mutableStateOf(false) }
    var notificationsDenied by rememberSaveable { mutableStateOf(false) }
    LifecycleEventEffect(Lifecycle.Event.ON_RESUME) { refresh++ }
    val micLauncher = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { ok ->
        micDenied = !ok
        refresh++
    }
    val notificationLauncher = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { ok ->
        notificationsDenied = !ok
        refresh++
    }
    val items = remember(refresh, helper.connected) {
        CaptureHealth.permissionItems(
            microphone = granted(context, Manifest.permission.RECORD_AUDIO),
            notifications = Build.VERSION.SDK_INT < 33 || granted(context, Manifest.permission.POST_NOTIFICATIONS),
            overlay = Settings.canDrawOverlays(context),
            battery = CaptureHealth.ignoresBatteryOptimisation(context),
            helper = helper.connected
        )
    }
    StepLayout(
        title = "Allow what EchoLedger needs",
        body = "Only the microphone is required. You can change all of this later in Settings.",
        primary = "Continue",
        onPrimary = onNext,
        onSkip = onSkip
    ) {
        items.forEach { item ->
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text(item.title)
                    Text(
                        when {
                            item.granted -> "Allowed"
                            item.key == "helper" -> "Optional. Set it up later in Settings → Devices."
                            else -> item.why
                        },
                        color = if (item.granted) Brand.Success else Brand.Muted,
                        fontSize = 12.sp
                    )
                }
                if (!item.granted && item.key != "helper") {
                    val blocked = (item.key == "microphone" && micDenied) || (item.key == "notifications" && notificationsDenied)
                    OutlinedButton(onClick = {
                        when {
                            blocked -> permissionIntent(context, item.key)?.let { runCatching { context.startActivity(it) } }

                            item.key == "microphone" -> micLauncher.launch(Manifest.permission.RECORD_AUDIO)

                            item.key == "notifications" && Build.VERSION.SDK_INT >= 33 ->
                                notificationLauncher.launch(Manifest.permission.POST_NOTIFICATIONS)

                            else -> permissionIntent(context, item.key)?.let { runCatching { context.startActivity(it) } }
                        }
                    }) { Text(if (blocked) "Open settings" else "Allow") }
                }
            }
        }
    }
}

@Composable
private fun ColumnScope.TestStep(onNext: () -> Unit, onSkip: () -> Unit) {
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var refresh by remember { mutableIntStateOf(0) }
    LifecycleEventEffect(Lifecycle.Event.ON_RESUME) { refresh++ }
    val micAllowed = remember(refresh) { granted(context, Manifest.permission.RECORD_AUDIO) }
    var testing by remember { mutableStateOf(false) }
    var level by remember { mutableFloatStateOf(0f) }
    var result by remember { mutableStateOf<MicTestResult?>(null) }
    var failed by remember { mutableStateOf(false) }
    StepLayout(
        title = "Try a 10-second test",
        body = "Say a few words. The meter shows what the microphone hears.",
        primary = if (result != null) "Continue" else "Skip the test",
        onPrimary = onNext,
        onSkip = onSkip,
        primaryEnabled = !testing
    ) {
        Button(
            enabled = micAllowed && !testing,
            onClick = {
                testing = true
                failed = false
                result = null
                scope.launch {
                    result = runCatching { CaptureHealth.record(context, null) { level = it } }.getOrNull()
                    failed = result == null
                    testing = false
                }
            }
        ) {
            Text(
                if (testing) {
                    "Recording 10 seconds…"
                } else if (result != null || failed) {
                    "Test again"
                } else {
                    "Start the test"
                }
            )
        }
        if (!micAllowed) Text("Allow the microphone on the previous step to run the test.", color = Brand.Muted, fontSize = 12.sp)
        if (testing) LevelBar("Me", level, Brand.Violet)
        result?.let { r ->
            LevelBar("Peak", r.peak, Brand.Violet)
            Text(micTestVerdict(r), fontSize = 13.sp)
        }
        if (failed) {
            Text(
                "The test could not record. Check the microphone permission and try again.",
                color = Brand.Warning,
                fontSize = 13.sp
            )
        }
    }
}

@Composable
private fun ColumnScope.DoneStep(onFinish: (Boolean) -> Unit) {
    var consent by rememberSaveable { mutableStateOf(false) }
    StepLayout(
        title = "You are set",
        body = "Call-recording laws differ by country. Tell participants you are recording where required. " +
            "A recording notification is always shown.",
        primary = "Start using EchoLedger",
        onPrimary = { onFinish(consent) },
        onSkip = null
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Checkbox(checked = consent, onCheckedChange = { consent = it })
            Text("I understand and will get consent where required", modifier = Modifier.weight(1f))
        }
    }
}
