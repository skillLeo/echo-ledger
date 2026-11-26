package com.echoledger.mobile.ui.v2.setup

import android.Manifest
import android.content.pm.PackageManager
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
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
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.ProgressBarRangeInfo
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.progressBarRangeInfo
import androidx.compose.ui.semantics.semantics
import androidx.core.content.ContextCompat
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.compose.LifecycleEventEffect
import com.echoledger.mobile.R
import com.echoledger.mobile.core.OnboardingStep
import com.echoledger.mobile.recording.CaptureHealth
import com.echoledger.mobile.recording.MicTestResult
import com.echoledger.mobile.ui.MainViewModel
import com.echoledger.mobile.ui.v2.components.EchoButton
import com.echoledger.mobile.ui.v2.components.EchoButtonKind
import com.echoledger.mobile.ui.v2.components.EchoCheckbox
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.tokens.EchoTokens
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.async
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import kotlinx.coroutines.withTimeoutOrNull

/**
 * Which design the first-run wizard's steps after sign-in use (a product decision, the same as the desktop's): before
 * sign-in the flags cannot be read, so the wizard is classic's; once signed in, the server's flags are read, and when
 * they say the new design the remaining steps use it. The answer is waited for at most [WAIT_MS]; no answer is classic.
 */
object WizardDesign {
    const val WAIT_MS = 3_000L

    /** Whether the steps after sign-in use the new design: the server's flags, read now, at most [waitMs]. */
    suspend fun afterSignIn(vm: MainViewModel, waitMs: Long = WAIT_MS): Boolean {
        // The read runs on its own: a request still waiting on a slow network cannot hold the wizard past [waitMs], and
        // its answer is still kept for the screens after. The wait is on the wall clock, whatever clock the screen runs on.
        val read = CoroutineScope(Dispatchers.IO).async { vm.interfaceFlags() }
        return withContext(Dispatchers.Default) { withTimeoutOrNull(waitMs) { read.await() }?.shell == true }
    }
}

/**
 * One wizard step on the new components: "Step 4 of 5" with its progress, the title, one paragraph, the step itself,
 * then Skip setup (quiet) and the step's one button within thumb reach.
 */
@Composable
private fun StepPage(
    step: OnboardingStep,
    title: String,
    body: String,
    primary: String,
    onPrimary: () -> Unit,
    onSkip: (() -> Unit)?,
    primaryEnabled: Boolean = true,
    content: @Composable ColumnScope.() -> Unit = {}
) {
    val c = EchoV2.colors
    val label = stringResource(R.string.v2_wiz_step, step.ordinal + 1, OnboardingStep.entries.size)
    val fraction = (step.ordinal + 1f) / OnboardingStep.entries.size
    Column(
        Modifier.fillMaxSize().background(c.bgCanvas).navigationBarsPadding().imePadding()
            .padding(horizontal = EchoTokens.Space.s24, vertical = EchoTokens.Space.s16),
        verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s12)
    ) {
        Text(label, style = EchoV2.type.caption, color = c.textSecondary)
        val progress = stringResource(R.string.v2_wiz_progress, label)
        Box(
            Modifier.fillMaxWidth().height(EchoTokens.Space.s4).clip(RoundedCornerShape(EchoTokens.Space.s4)).background(c.bgSubtle)
                .semantics {
                    contentDescription = progress
                    progressBarRangeInfo = ProgressBarRangeInfo(fraction, 0f..1f)
                }
        ) {
            Box(Modifier.fillMaxWidth(fraction).fillMaxHeight().background(c.accent))
        }
        Column(
            Modifier.weight(1f).verticalScroll(rememberScrollState()),
            verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s12)
        ) {
            Text(
                title,
                style = EchoV2.type.titleL,
                color = c.textPrimary,
                modifier = Modifier.padding(top = EchoTokens.Space.s16).semantics { heading() }
            )
            Text(body, style = EchoV2.type.body, color = c.textSecondary)
            content()
        }
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
            if (onSkip != null) EchoButton(stringResource(R.string.v2_wiz_skip), onSkip, kind = EchoButtonKind.Quiet)
            Box(Modifier.weight(1f))
            EchoButton(primary, onPrimary, enabled = primaryEnabled)
        }
    }
}

/** A level as a bar in the accent: [level] 0-1. */
@Composable
private fun LevelBar(label: String, level: Float) {
    val c = EchoV2.colors
    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
        Text(label, style = EchoV2.type.meta, color = c.textSecondary)
        Box(
            Modifier.weight(1f).height(EchoTokens.Space.s8).clip(RoundedCornerShape(EchoTokens.Space.s4)).background(c.bgSubtle)
                .semantics { progressBarRangeInfo = ProgressBarRangeInfo(level.coerceIn(0f, 1f), 0f..1f) }
        ) {
            Box(Modifier.fillMaxWidth(level.coerceIn(0f, 1f)).fillMaxHeight().background(c.accent))
        }
    }
}

/**
 * The 10-second test on the new components (classic's TestStep): Start the test while the microphone is allowed; the
 * meter while it records; then its peak and whether the microphone was heard; Test again; Continue, or Skip the test.
 */
@Composable
fun V2WizardTest(
    onNext: () -> Unit,
    onSkip: () -> Unit,
    /** The 10-second recording; the phone's microphone test unless a test passes its own. */
    record: (suspend (onLevel: (Float) -> Unit) -> MicTestResult?)? = null
) {
    val context = LocalContext.current
    val test: suspend (onLevel: (Float) -> Unit) -> MicTestResult? = record ?: { onLevel -> wizardMicTest(context, onLevel) }
    val scope = rememberCoroutineScope()
    val c = EchoV2.colors
    var refresh by remember { mutableIntStateOf(0) }
    LifecycleEventEffect(Lifecycle.Event.ON_RESUME) { refresh++ }
    val micAllowed = remember(refresh) {
        ContextCompat.checkSelfPermission(context, Manifest.permission.RECORD_AUDIO) == PackageManager.PERMISSION_GRANTED
    }
    var testing by remember { mutableStateOf(false) }
    var level by remember { mutableFloatStateOf(0f) }
    var result by remember { mutableStateOf<MicTestResult?>(null) }
    var failed by remember { mutableStateOf(false) }
    StepPage(
        OnboardingStep.Test,
        stringResource(R.string.v2_wiz_test_title),
        stringResource(R.string.v2_wiz_test_body),
        stringResource(if (result != null) R.string.v2_wiz_continue else R.string.v2_wiz_skip_test),
        onNext,
        onSkip,
        primaryEnabled = !testing
    ) {
        EchoButton(
            stringResource(
                when {
                    testing -> R.string.v2_wiz_recording
                    result != null || failed -> R.string.v2_wiz_test_again
                    else -> R.string.v2_wiz_start_test
                }
            ),
            {
                testing = true
                failed = false
                result = null
                scope.launch {
                    result = runCatching { test { level = it } }.getOrNull()
                    failed = result == null
                    testing = false
                }
            },
            enabled = micAllowed && !testing,
            kind = EchoButtonKind.Secondary
        )
        if (!micAllowed) Text(stringResource(R.string.v2_wiz_allow_mic), style = EchoV2.type.meta, color = c.textSecondary)
        if (testing) LevelBar(stringResource(R.string.v2_wiz_me), level)
        result?.let { r ->
            LevelBar(stringResource(R.string.v2_wiz_peak), r.peak)
            Text(
                stringResource(if (r.heard) R.string.v2_wiz_heard else R.string.v2_wiz_not_heard),
                style = EchoV2.type.body,
                color = c.textPrimary,
                modifier = Modifier.semantics { liveRegion = LiveRegionMode.Polite }
            )
        }
        if (failed) Text(stringResource(R.string.v2_wiz_failed), style = EchoV2.type.body, color = c.warning)
    }
}

/** The wizard's last step on the new components: the recording-laws note, the consent tick, Start using EchoLedger. */
@Composable
fun V2WizardDone(onFinish: (consent: Boolean) -> Unit) {
    var consent by rememberSaveable { mutableStateOf(false) }
    StepPage(
        OnboardingStep.Done,
        stringResource(R.string.v2_wiz_done_title),
        stringResource(R.string.v2_wiz_done_body),
        stringResource(R.string.v2_wiz_start_using),
        { onFinish(consent) },
        onSkip = null
    ) {
        EchoCheckbox(consent, { consent = it }, stringResource(R.string.v2_wiz_consent))
    }
}

/** The 10-second microphone test as the wizard runs it on a phone. */
suspend fun wizardMicTest(context: android.content.Context, onLevel: (Float) -> Unit): MicTestResult =
    CaptureHealth.record(context, null) { onLevel(it) }
