package com.echoledger.mobile.ui.v2.setup

import android.Manifest
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.Build
import android.provider.Settings
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.relocation.BringIntoViewRequester
import androidx.compose.foundation.relocation.bringIntoViewRequester
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.semantics.stateDescription
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.compose.LifecycleEventEffect
import com.echoledger.mobile.EchoLedgerApp
import com.echoledger.mobile.R
import com.echoledger.mobile.core.ReadyFix
import com.echoledger.mobile.recording.ReadinessWatch
import com.echoledger.mobile.recording.RecordingService
import com.echoledger.mobile.ui.MainViewModel
import com.echoledger.mobile.ui.v2.components.EchoButton
import com.echoledger.mobile.ui.v2.components.EchoButtonKind
import com.echoledger.mobile.ui.v2.components.EchoIcons
import com.echoledger.mobile.ui.v2.components.EchoSectionHeader
import com.echoledger.mobile.ui.v2.components.EchoStatus
import com.echoledger.mobile.ui.v2.components.EchoStatusKind
import com.echoledger.mobile.ui.v2.components.EchoTopBar
import com.echoledger.mobile.ui.v2.settings.highlightedIf
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.tokens.EchoTokens
import kotlinx.coroutines.launch

private const val SHIZUKU_PACKAGE = "moe.shizuku.privileged.api"

private fun globalOn(context: Context, key: String): Boolean = runCatching {
    Settings.Global.getInt(context.contentResolver, key, 0) == 1
}.getOrDefault(false)

/**
 * The live facts, read again whenever the screen comes back (from Shizuku or Android's settings), the helper connects or
 * answers, or a setting changes. Nothing is remembered across a restart except what the phone itself keeps.
 */
@Composable
fun rememberSetupFacts(vm: MainViewModel, recheck: Int): SetupFacts {
    val context = LocalContext.current
    val app = context.applicationContext as EchoLedgerApp
    val helper by vm.helper.collectAsState()
    val arm by app.graph.helper.armState.collectAsState()
    val settings by vm.settings.collectAsState()
    var resumed by remember { mutableIntStateOf(0) }
    LifecycleEventEffect(Lifecycle.Event.ON_RESUME) { resumed++ }
    LaunchedEffect(helper) { HelperMethodMemory.remember(context, helper) }
    return remember(helper, arm, settings, recheck, resumed) {
        SetupFacts(
            ready = ReadinessWatch.facts(context, settings, app.graph.helper),
            developerOptions = globalOn(context, Settings.Global.DEVELOPMENT_SETTINGS_ENABLED),
            wirelessDebugging = globalOn(context, "adb_wifi_enabled"),
            method = HelperMethodMemory.method(helper.source.takeIf { helper.connected }) ?: HelperMethodMemory.last(context)
        )
    }
}

/**
 * Setup & health (behind `ui_v2.setup_health`): where calls stand, the capture helper's wizard while it isn't running,
 * and every health check with its fix. Built on the Ready check and the existing helper actions of the view model.
 */
@Composable
fun V2SetupHealthScreen(vm: MainViewModel, highlight: String?, onClassicSetup: () -> Unit, onBack: () -> Unit) {
    val context = LocalContext.current
    var recheck by remember { mutableIntStateOf(0) }
    val facts = rememberSetupFacts(vm, recheck)
    val permission = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { recheck++ }
    fun open(intent: Intent) = runCatching { context.startActivity(intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) }.isSuccess
    fun openShizuku() = ReadinessWatch.openShizuku(context)
    V2SetupHealthContent(
        facts = facts,
        highlight = highlight,
        onStep = { action ->
            when (action) {
                StepAction.OpenSettings -> open(Intent(Settings.ACTION_DEVICE_INFO_SETTINGS))

                StepAction.GetShizuku -> open(Intent(Intent.ACTION_VIEW, Uri.parse("market://details?id=$SHIZUKU_PACKAGE"))) ||
                    open(Intent(Intent.ACTION_VIEW, Uri.parse("https://play.google.com/store/apps/details?id=$SHIZUKU_PACKAGE")))

                StepAction.OpenWireless -> open(Intent(Settings.ACTION_APPLICATION_DEVELOPMENT_SETTINGS))

                StepAction.OpenShizuku -> openShizuku()

                StepAction.AllowShizuku, StepAction.StartHelper -> vm.connectShizuku()

                // The computer method's command and its copy button live on the classic setup screen.
                StepAction.ComputerMethod -> onClassicSetup()
            }
            recheck++
        },
        onFix = { fix ->
            when (fix) {
                ReadyFix.START_HELPER, ReadyFix.ALLOW_SHIZUKU -> vm.connectShizuku()

                ReadyFix.OPEN_SHIZUKU -> openShizuku()

                ReadyFix.RESTART_HELPER -> vm.restartHelper()

                // The content brings the wizard into view itself, or sends the computer method's step here.
                ReadyFix.SET_UP -> Unit

                ReadyFix.SIGN_IN, ReadyFix.TEST -> onClassicSetup()

                ReadyFix.MICROPHONE -> permission.launch(Manifest.permission.RECORD_AUDIO)

                ReadyFix.NOTIFICATIONS -> if (Build.VERSION.SDK_INT >= 33) {
                    permission.launch(Manifest.permission.POST_NOTIFICATIONS)
                } else {
                    open(Intent(Settings.ACTION_APP_NOTIFICATION_SETTINGS).putExtra(Settings.EXTRA_APP_PACKAGE, context.packageName))
                }

                ReadyFix.BATTERY -> open(Intent(Settings.ACTION_IGNORE_BATTERY_OPTIMIZATION_SETTINGS))

                ReadyFix.CALLER_NAMES -> open(Intent(Settings.ACTION_NOTIFICATION_LISTENER_SETTINGS))

                ReadyFix.TURN_ON -> runCatching {
                    context.startForegroundService(RecordingService.intent(context, RecordingService.ACTION_ARM, from = "setup and health"))
                }

                ReadyFix.STORAGE -> open(Intent(Settings.ACTION_INTERNAL_STORAGE_SETTINGS))
            }
            recheck++
        },
        onRecheck = { recheck++ },
        onBack = onBack
    )
}

/** Setup & health without the phone (tests and screenshots). */
@Composable
fun V2SetupHealthContent(
    facts: SetupFacts,
    highlight: String?,
    onStep: (StepAction) -> Unit,
    onFix: (ReadyFix) -> Unit,
    onRecheck: () -> Unit,
    onBack: () -> Unit,
    modifier: Modifier = Modifier
) {
    val c = EchoV2.colors
    val checks = HealthChecks.of(facts)
    val steps = ShizukuWizard.steps(facts)
    val showWizard = facts.callsPossible && !facts.helperReady
    val wizard = remember { BringIntoViewRequester() }
    val scope = rememberCoroutineScope()
    // "Set up" on a check opens the wizard: brought into view, or the computer method's step when that is how this
    // phone was set up.
    val fix: (ReadyFix) -> Unit = { f ->
        if (f == ReadyFix.SET_UP && showWizard) {
            if (facts.method == HelperMethod.Computer) onStep(StepAction.ComputerMethod) else scope.launch { wizard.bringIntoView() }
        } else {
            onFix(f)
        }
    }
    Column(modifier.fillMaxSize().background(c.bgCanvas)) {
        EchoTopBar(stringResource(R.string.v2_setup_title), onBack = onBack)
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState())) {
            val status = HealthChecks.status(facts)
            Box(Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s8)) {
                EchoStatus(
                    stringResource(status),
                    when (status) {
                        R.string.v2_setup_status_ready -> EchoStatusKind.Success
                        R.string.v2_setup_status_needs -> EchoStatusKind.Warning
                        else -> EchoStatusKind.Neutral
                    }
                )
            }
            if (!facts.callsPossible) {
                Text(
                    stringResource(R.string.v2_setup_not_available),
                    style = EchoV2.type.meta,
                    color = c.textSecondary,
                    modifier = Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile)
                )
            }
            if (showWizard) {
                Column(Modifier.bringIntoViewRequester(wizard).highlightedIf(highlight == "setup.helper")) {
                    EchoSectionHeader(stringResource(R.string.v2_setup_helper))
                    Text(
                        stringResource(R.string.v2_setup_helper_intro),
                        style = EchoV2.type.meta,
                        color = c.textSecondary,
                        modifier = Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s4)
                    )
                    steps.forEachIndexed { i, step -> StepRow(i + 1, step, onStep) }
                }
            }
            Column(Modifier.highlightedIf(highlight == "setup.checks")) {
                EchoSectionHeader(stringResource(R.string.v2_setup_checks))
                checks.forEach { CheckRow(it, fix) }
            }
            EchoButton(
                stringResource(R.string.v2_setup_check_again),
                onRecheck,
                Modifier.padding(EchoTokens.Layout.pagePaddingMobile),
                kind = EchoButtonKind.Secondary
            )
        }
    }
}

/** A step: its number (a check when done), what to do, and the one button on the current step only. */
@Composable
private fun StepRow(number: Int, step: WizardStep, onStep: (StepAction) -> Unit) {
    val c = EchoV2.colors
    val current = step.state == StepState.Current
    val stateWords = stringResource(
        when (step.state) {
            StepState.Done -> R.string.v2_setup_done
            StepState.Current -> R.string.v2_setup_next
            StepState.Unknown -> R.string.v2_setup_step_unknown
            StepState.Todo -> R.string.v2_set_value_off
        }
    )
    Row(
        Modifier
            .fillMaxWidth()
            .semantics(mergeDescendants = true) { if (step.state != StepState.Todo) stateDescription = stateWords }
            .padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s12),
        horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s16)
    ) {
        Box(
            Modifier
                .size(EchoTokens.Icon.lg + EchoTokens.Space.s8)
                .background(if (current) c.accent else c.bgSubtle, CircleShape),
            contentAlignment = Alignment.Center
        ) {
            when (step.state) {
                StepState.Done -> Icon(EchoIcons.Check, null, Modifier.size(EchoTokens.Icon.md), tint = c.success)
                StepState.Unknown -> Text("?", style = EchoV2.type.mono, color = c.textSecondary)
                else -> Text(number.toString(), style = EchoV2.type.mono, color = if (current) c.textOnAccent else c.textSecondary)
            }
        }
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s4)) {
            Text(
                stringResource(step.title),
                style = EchoV2.type.bodyStrong,
                color = if (step.state ==
                    StepState.Todo
                ) {
                    c.textSecondary
                } else {
                    c.textPrimary
                }
            )
            if (step.state == StepState.Unknown) {
                Text(stateWords, style = EchoV2.type.meta, color = c.textSecondary)
            }
            if (current) {
                Text(stringResource(step.detail), style = EchoV2.type.meta, color = c.textSecondary)
                EchoButton(stringResource(step.actionLabel), { onStep(step.action) }, Modifier.padding(top = EchoTokens.Space.s4))
            }
        }
    }
}

/** A health check: a check mark or the problem, why it matters, and its fix. */
@Composable
private fun CheckRow(check: HealthCheck, onFix: (ReadyFix) -> Unit) {
    val c = EchoV2.colors
    Row(
        Modifier.fillMaxWidth().padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s12),
        horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s16),
        verticalAlignment = Alignment.Top
    ) {
        Icon(
            if (check.ok) EchoIcons.Check else EchoIcons.Error,
            null,
            Modifier.size(EchoTokens.Icon.lg),
            tint = if (check.ok) c.success else c.warning
        )
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s4)) {
            Text(stringResource(check.title), style = EchoV2.type.body, color = c.textPrimary)
            check.detail?.let {
                Text(
                    if (check.key == "storage") stringResource(it, check.freeMb ?: 0L) else stringResource(it),
                    style = EchoV2.type.meta,
                    color = c.textSecondary
                )
            }
        }
        check.fix?.takeIf { !check.ok }?.let { fix ->
            EchoButton(stringResource(HealthChecks.fixLabel(fix)), { onFix(fix) }, kind = EchoButtonKind.Secondary)
        }
    }
}
