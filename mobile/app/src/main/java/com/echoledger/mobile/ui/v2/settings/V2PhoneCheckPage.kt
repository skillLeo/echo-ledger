package com.echoledger.mobile.ui.v2.settings

import android.Manifest
import android.content.ClipData
import android.content.ClipboardManager
import android.content.Context
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.compose.LifecycleEventEffect
import com.echoledger.mobile.R
import com.echoledger.mobile.core.CheckFix
import com.echoledger.mobile.core.CheckRow
import com.echoledger.mobile.core.CheckState
import com.echoledger.mobile.core.DeviceCheck
import com.echoledger.mobile.core.PhoneBrand
import com.echoledger.mobile.core.PhoneFacts
import com.echoledger.mobile.ui.openBrandSettings
import com.echoledger.mobile.ui.permissionIntent
import com.echoledger.mobile.ui.v2.components.EchoButton
import com.echoledger.mobile.ui.v2.components.EchoButtonKind
import com.echoledger.mobile.ui.v2.components.EchoSectionHeader
import com.echoledger.mobile.ui.v2.components.EchoStatus
import com.echoledger.mobile.ui.v2.components.EchoStatusKind
import com.echoledger.mobile.ui.v2.components.EchoTopBar
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.tokens.EchoTokens
import kotlinx.coroutines.launch

/** What the phone check does (the view model's, or a test's). */
class PhoneCheckActions(
    /** What the phone says about itself now (read again each time the page comes back). */
    val facts: (Context) -> PhoneFacts,
    val diagnostics: suspend () -> String,
    /** Sends the report (English, as the server keeps it) to the admin; the shell says how it went. */
    val send: suspend (PhoneFacts, List<CheckRow>) -> Unit,
    val signedIn: Boolean
)

/**
 * Help's phone check on the v2 components (classic's Help & diagnostics and Device check, the same actions): the
 * capture test (in a build with call capture), the audio probe (engineering builds), Copy diagnostics; what works on
 * this phone and why, each row's one-tap fix, the maker's own steps with its settings screen, Copy report and Send to my
 * admin. The page is in the screen's language; the report keeps the English the server reads.
 */
@OptIn(ExperimentalLayoutApi::class)
@Composable
fun PhoneCheckPage(
    actions: PhoneCheckActions,
    onSetup: () -> Unit,
    onBack: () -> Unit,
    modifier: Modifier = Modifier,
    captureInBuild: Boolean = com.echoledger.mobile.BuildConfig.CAPTURE_HELPER,
    engineering: Boolean = com.echoledger.mobile.BuildConfig.DEBUG,
    /** The engineering audio probe (its own sheet), in builds for the people who build the app only. */
    audioProbe: @Composable (onDismiss: () -> Unit) -> Unit = {}
) {
    val context = LocalContext.current
    val res = context.resources
    val c = EchoV2.colors
    val toasts = LocalEchoToasts.current
    val scope = rememberCoroutineScope()
    var refresh by remember { mutableIntStateOf(0) }
    LifecycleEventEffect(Lifecycle.Event.ON_RESUME) { refresh++ }
    val mic = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { refresh++ }
    val facts = remember(refresh) { actions.facts(context) }
    val rows = remember(facts) { DeviceCheck.rows(facts) }
    val brand = remember(facts) { PhoneBrand.of(facts.manufacturer, facts.brand) }
    var probing by remember { mutableStateOf(false) }
    var sending by remember { mutableStateOf(false) }
    val diagnosticsCopied = stringResource(R.string.v2_pc_diagnostics_copied)
    val reportCopied = stringResource(R.string.v2_pc_report_copied)
    val pad = Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s4)
    Column(modifier.fillMaxSize().background(c.bgCanvas)) {
        EchoTopBar(stringResource(R.string.v2_pc_title), onBack = onBack)
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState())) {
            EchoSectionHeader(stringResource(R.string.v2_pc_help))
            FlowRow(pad, horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
                if (captureInBuild) EchoButton(stringResource(R.string.v2_pc_capture_test), onSetup, kind = EchoButtonKind.Secondary)
                if (engineering) EchoButton(stringResource(R.string.v2_pc_audio_probe), { probing = true }, kind = EchoButtonKind.Secondary)
                EchoButton(stringResource(R.string.v2_pc_copy_diagnostics), {
                    scope.launch {
                        copy(context, "diagnostics", actions.diagnostics())
                        toasts.show(diagnosticsCopied)
                    }
                }, kind = EchoButtonKind.Quiet)
            }
            Note(stringResource(R.string.v2_pc_diagnostics_note))
            EchoSectionHeader(stringResource(R.string.v2_pc_check))
            Note(stringResource(R.string.v2_pc_check_help))
            rows.forEach { row ->
                CheckLine(row, PhoneCheckWords.title(res, row), PhoneCheckWords.detail(res, row, brand, facts.memoryGb)) { fix ->
                    when (fix) {
                        CheckFix.MICROPHONE -> mic.launch(Manifest.permission.RECORD_AUDIO)
                        CheckFix.HELPER -> onSetup()
                        CheckFix.OVERLAY -> permissionIntent(context, "overlay")?.let { runCatching { context.startActivity(it) } }
                        CheckFix.BATTERY -> permissionIntent(context, "battery")?.let { runCatching { context.startActivity(it) } }
                    }
                }
            }
            EchoSectionHeader(stringResource(R.string.v2_pc_keep_running, PhoneCheckWords.brand(res, brand)))
            PhoneCheckWords.steps(res, brand).forEach { Note(it, EchoV2.type.body) }
            EchoButton(
                stringResource(R.string.v2_pc_open_settings),
                { openBrandSettings(context, brand) },
                Modifier.padding(horizontal = EchoTokens.Space.s8),
                kind = EchoButtonKind.Quiet
            )
            Row(pad, horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
                EchoButton(stringResource(R.string.v2_pc_copy_report), {
                    copy(context, "device check", DeviceCheck.report(facts, rows))
                    scope.launch { toasts.show(reportCopied) }
                }, kind = EchoButtonKind.Secondary)
                EchoButton(
                    stringResource(R.string.v2_pc_send),
                    {
                        sending = true
                        scope.launch {
                            actions.send(facts, rows)
                            sending = false
                        }
                    },
                    kind = EchoButtonKind.Secondary,
                    enabled = actions.signedIn,
                    loading = sending,
                    loadingText = stringResource(R.string.v2_pc_sending)
                )
            }
            Note(stringResource(if (actions.signedIn) R.string.v2_pc_report_note else R.string.v2_pc_report_note_signed_out))
        }
    }
    if (probing) audioProbe { probing = false }
}

/** One row: what it is, Works / Limited / Off with the plain reason, and its fix (named with the row for TalkBack). */
@Composable
private fun CheckLine(row: CheckRow, title: String, detail: String, onFix: (CheckFix) -> Unit) {
    val c = EchoV2.colors
    val res = LocalContext.current.resources
    Row(
        Modifier.fillMaxWidth().padding(start = EchoTokens.Layout.pagePaddingMobile, end = EchoTokens.Space.s8, top = EchoTokens.Space.s8),
        verticalAlignment = Alignment.CenterVertically
    ) {
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s4)) {
            Text(title, style = EchoV2.type.body, color = c.textPrimary)
            EchoStatus(
                PhoneCheckWords.state(res, row.state),
                when (row.state) {
                    CheckState.OK -> EchoStatusKind.Success
                    CheckState.LIMITED -> EchoStatusKind.Warning
                    CheckState.OFF -> EchoStatusKind.Neutral
                }
            )
            Text(detail, style = EchoV2.type.meta, color = c.textSecondary)
        }
        row.fix?.let { fix ->
            val label = PhoneCheckWords.fix(res, fix)
            val named = stringResource(R.string.v2_pc_fix_named, label, title)
            EchoButton(label, { onFix(fix) }, Modifier.semantics { contentDescription = named }, kind = EchoButtonKind.Quiet)
        }
    }
}

@Composable
private fun Note(words: String, style: androidx.compose.ui.text.TextStyle = EchoV2.type.meta) {
    Text(
        words,
        style = style,
        color = EchoV2.colors.textSecondary,
        modifier = Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s4)
    )
}

private fun copy(context: Context, label: String, text: String) {
    context.getSystemService(ClipboardManager::class.java)?.setPrimaryClip(ClipData.newPlainText(label, text))
}
