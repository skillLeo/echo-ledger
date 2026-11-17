package com.echoledger.mobile.ui.v2.settings

import android.content.Intent
import android.os.Build
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
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
import com.echoledger.mobile.EchoLedgerApp
import com.echoledger.mobile.R
import com.echoledger.mobile.calls.PhoneAppRecordings
import com.echoledger.mobile.calls.PhoneCallLog
import com.echoledger.mobile.calls.PostCallPrompt
import com.echoledger.mobile.calls.SkippedCalls
import com.echoledger.mobile.core.CallTargets
import com.echoledger.mobile.core.PhoneCalls
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.recording.AutoRecordRules
import com.echoledger.mobile.ui.ImportRecordingDialog
import com.echoledger.mobile.ui.capabilities
import com.echoledger.mobile.ui.v2.components.EchoButton
import com.echoledger.mobile.ui.v2.components.EchoButtonKind
import com.echoledger.mobile.ui.v2.components.EchoRadio
import com.echoledger.mobile.ui.v2.components.EchoSectionHeader
import com.echoledger.mobile.ui.v2.components.EchoSettingRow
import com.echoledger.mobile.ui.v2.components.EchoSwitch
import com.echoledger.mobile.ui.v2.components.EchoTextField
import com.echoledger.mobile.ui.v2.components.EchoTopBar
import com.echoledger.mobile.ui.v2.shell.v2Text
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.tokens.EchoTokens
import java.time.Instant
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import kotlinx.coroutines.launch

/** The longest package name a call app can have, and how many can be added (as today). */
private const val PACKAGE_MAX = 120
private const val EXTRA_APPS_MAX = 20

/** The Android version that lets an app ask to delete another app's file (11). */
private const val DELETE_SDK = 30

/**
 * Calls & meetings in the v2 look (Settings v2, docs/v2/IA.md 5.1), with every control of today's page (the classic
 * after-call and auto-record sections; CallsPageParityTest keeps the two lists equal): after a call (keep or ask), which
 * short calls are skipped, when undecided calls are kept, the auto-record apps (each one, apps you add, Remove and Add),
 * SIM phone calls and, when they're on, the Phone app's own recordings (its settings, Allow, import, delete the copy,
 * and recordings waiting for you with Import or Not this call).
 */
@Composable
fun CallsPage(
    settings: AppSettings,
    update: ((AppSettings) -> AppSettings) -> Unit,
    highlight: String?,
    onBack: () -> Unit,
    modifier: Modifier = Modifier,
    manufacturer: String = Build.MANUFACTURER
) {
    val c = EchoV2.colors
    val text = v2Text
    Column(modifier.fillMaxSize().background(c.bgCanvas)) {
        EchoTopBar(stringResource(R.string.v2_set_page_calls), onBack = onBack)
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState())) {
            Column(Modifier.highlightedIf(highlight == "after-call")) {
                EchoSectionHeader(stringResource(R.string.v2_calls_after_call))
                EchoRadio(
                    settings.afterCall == PostCallPrompt.KEEP,
                    { update { it.copy(afterCall = PostCallPrompt.KEEP) } },
                    stringResource(R.string.v2_calls_keep_auto),
                    supporting = stringResource(R.string.v2_calls_keep_auto_help)
                )
                EchoRadio(
                    settings.afterCall == PostCallPrompt.ASK,
                    { update { it.copy(afterCall = PostCallPrompt.ASK) } },
                    stringResource(R.string.v2_calls_ask_me),
                    supporting = stringResource(R.string.v2_calls_ask_me_help)
                )
                EchoSectionHeader(stringResource(R.string.v2_calls_skip_title))
                Note(stringResource(R.string.v2_calls_skip_help))
                SkippedCalls.SHORTER_THAN_CHOICES.forEach { seconds ->
                    val words = if (seconds <=
                        0
                    ) {
                        stringResource(R.string.v2_calls_never)
                    } else {
                        stringResource(R.string.v2_calls_seconds, seconds)
                    }
                    EchoRadio(
                        settings.skipCallsShorterThanS == seconds,
                        { update { it.copy(skipCallsShorterThanS = seconds) } },
                        if (seconds == SkippedCalls.DEFAULT_SHORTER_THAN_S) stringResource(R.string.v2_calls_default, words) else words
                    )
                }
                EchoSectionHeader(stringResource(R.string.v2_calls_undecided_title))
                Note(stringResource(R.string.v2_calls_undecided_help))
                com.echoledger.mobile.calls.UndecidedKeepWorker.CHOICES.forEach { (hours, _) ->
                    val words = when (hours) {
                        24 -> stringResource(R.string.v2_calls_default, stringResource(R.string.v2_calls_und_24))
                        72 -> stringResource(R.string.v2_calls_und_72)
                        else -> stringResource(R.string.v2_calls_never)
                    }
                    EchoRadio(settings.undecidedKeepHours == hours, { update { it.copy(undecidedKeepHours = hours) } }, words)
                }
            }
            Column(Modifier.highlightedIf(highlight == "auto-apps")) {
                // The Auto-record calls switch itself, above the apps it applies to (bug P-016), as on the Record page.
                val context = LocalContext.current
                val helperState = remember {
                    (context.applicationContext as? EchoLedgerApp)?.graph?.helper?.state
                        ?: kotlinx.coroutines.flow.MutableStateFlow(com.echoledger.mobile.privilege.HelperConnection.State())
                }
                val helper by helperState.collectAsState()
                val caps = remember(helper.connected) { context.capabilities(helper.connected) }
                EchoSwitch(
                    settings.autoRecordArmed,
                    { on ->
                        context.startForegroundService(
                            com.echoledger.mobile.recording.RecordingService.intent(
                                context,
                                if (on) com.echoledger.mobile.recording.RecordingService.ACTION_ARM else com.echoledger.mobile.recording.RecordingService.ACTION_DISARM
                            )
                        )
                    },
                    stringResource(R.string.v2_calls_autorecord),
                    supporting = stringResource(
                        when {
                            !caps.helperInBuild -> R.string.v2_calls_autorecord_not_in_build
                            !caps.callCaptureSupportedByOs -> R.string.v2_calls_autorecord_needs_android
                            !caps.helperConnected -> R.string.v2_calls_autorecord_setup_first
                            settings.afterCall == com.echoledger.mobile.calls.PostCallPrompt.ASK -> R.string.v2_calls_autorecord_ask
                            else -> R.string.v2_calls_autorecord_keep
                        }
                    ),
                    enabled = caps.canCaptureCalls
                )
                EchoSectionHeader(stringResource(R.string.v2_calls_auto_title))
                if (!settings.autoRecordArmed) Note(stringResource(R.string.v2_calls_auto_off_note))
                AutoRecordRules.APPS.forEach { app ->
                    val on = AutoRecordRules.records(app, settings.autoRecordApps)
                    EchoSwitch(
                        on,
                        { value -> update { it.copy(autoRecordApps = it.autoRecordApps + (app.wire to value)) } },
                        text.sourceName(app.wire),
                        supporting = when {
                            !on -> stringResource(R.string.v2_calls_app_not)
                            settings.autoRecordArmed -> stringResource(R.string.v2_calls_app_auto)
                            else -> null
                        }
                    )
                }
                settings.extraCallApps.forEach { pkg ->
                    EchoSettingRow(pkg, subtitle = stringResource(R.string.v2_calls_extra_help), content = true, trailing = {
                        EchoButton(
                            stringResource(R.string.v2_calls_remove),
                            { update { it.copy(extraCallApps = it.extraCallApps - pkg) } },
                            Modifier.semanticsLabel(stringResource(R.string.v2_calls_remove_app, pkg)),
                            kind = EchoButtonKind.Quiet
                        )
                    })
                }
                AddCallApp(settings, update)
                Note(stringResource(R.string.v2_calls_only_voice))
            }
            PhoneCallsGroup(settings, update, manufacturer)
            PageReset("calls", stringResource(R.string.v2_set_page_calls), update)
        }
    }
}

/** A grey line under a heading. */
@Composable
private fun Note(words: String) {
    Text(
        words,
        style = EchoV2.type.meta,
        color = EchoV2.colors.textSecondary,
        modifier = Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s4)
    )
}

/** What a screen reader says for a control whose visible words are shorter ("Remove" → "Remove com.x from the call apps"). */
private fun Modifier.semanticsLabel(words: String): Modifier = semantics { contentDescription = words }

@Composable
private fun AddCallApp(settings: AppSettings, update: ((AppSettings) -> AppSettings) -> Unit) {
    var adding by remember { mutableStateOf("") }
    val cleaned = CallTargets.clean(adding)
    val can = cleaned != null && cleaned !in CallTargets.DEFAULT_PACKAGES && cleaned !in settings.extraCallApps
    Row(
        Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s8),
        verticalAlignment = Alignment.Bottom,
        horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)
    ) {
        EchoTextField(
            adding,
            { adding = it.take(PACKAGE_MAX) },
            label = stringResource(R.string.v2_calls_another_app),
            modifier = Modifier.weight(1f),
            error = if (adding.isNotBlank() && cleaned == null) "" else null
        )
        EchoButton(stringResource(R.string.v2_calls_add), {
            val pkg = cleaned ?: return@EchoButton
            update { it.copy(extraCallApps = (it.extraCallApps + pkg).distinct().take(EXTRA_APPS_MAX)) }
            adding = ""
        }, enabled = can, kind = EchoButtonKind.Secondary)
    }
}

/** SIM calls, and the Phone app's own recordings while they're on. */
@Composable
private fun PhoneCallsGroup(settings: AppSettings, update: ((AppSettings) -> AppSettings) -> Unit, manufacturer: String) {
    val ask = rememberLauncherForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { }
    EchoSectionHeader(stringResource(R.string.v2_calls_phone_calls))
    Note(stringResource(R.string.v2_calls_phone_calls_help))
    Text(
        stringResource(R.string.v2_calls_record_phone_calls),
        style = EchoV2.type.label,
        color = EchoV2.colors.textPrimary,
        modifier = Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s4)
    )
    val mode = PhoneCalls.mode(settings.phoneCalls)
    PhoneCalls.MODES.forEach { m ->
        EchoRadio(
            mode == m,
            {
                update { it.copy(phoneCalls = m) }
                // The call log and contacts name the caller; audio files let the Phone app's own recordings be imported.
                if (m != PhoneCalls.OFF) ask.launch(PhoneCallLog.permissionsToAsk())
            },
            stringResource(
                when (m) {
                    PhoneCalls.ASK -> R.string.v2_calls_mode_ask
                    PhoneCalls.ALWAYS -> R.string.v2_calls_mode_always
                    else -> R.string.v2_calls_mode_off
                }
            )
        )
    }
    if (mode != PhoneCalls.OFF) PhoneAppRecordingsGroup(settings, update, manufacturer)
}

/**
 * The Phone app's own recordings (v1.8 0.3), as today's block: how to let it record, its settings, Allow, the import
 * and delete switches, recordings waiting for the person and what happened to the last call.
 */
@Composable
private fun PhoneAppRecordingsGroup(settings: AppSettings, update: ((AppSettings) -> AppSettings) -> Unit, manufacturer: String) {
    val c = EchoV2.colors
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var looked by remember { mutableIntStateOf(0) }
    LifecycleEventEffect(Lifecycle.Event.ON_RESUME) { looked++ }
    val canRead = remember(looked) { PhoneAppRecordings.allowed(context) }
    LaunchedEffect(settings.phoneAppImport, settings.phoneCalls, canRead) { PhoneAppRecordings.sync(context, settings, manufacturer) }
    EchoSectionHeader(stringResource(R.string.v2_calls_pa_title))
    if (!PhoneAppRecordings.supported(manufacturer)) {
        Note(stringResource(R.string.v2_calls_pa_unsupported))
        return
    }
    val autoRecord = remember(looked) { PhoneAppRecordings.samsungAutoRecord(context, manufacturer) }
    val note = remember(looked, settings.phoneAppImport) { PhoneAppRecordings.lastNote(context) }
    val waiting = remember(looked) { PhoneAppRecordings.candidates(context) }
    var confirm by remember { mutableStateOf<PhoneAppRecordings.Candidate?>(null) }
    val allow = rememberLauncherForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { looked++ }
    val callSettings = remember { Intent(android.telecom.TelecomManager.ACTION_SHOW_CALL_SETTINGS) }
    val canOpen = remember { callSettings.resolveActivity(context.packageManager) != null }
    val clock = remember { DateTimeFormatter.ofPattern("HH:mm").withZone(ZoneId.systemDefault()) }
    Note(stringResource(R.string.v2_calls_pa_help))
    // The Phone app's own menu path, as it reads on the phone.
    val guide = PhoneAppRecordings.guide(manufacturer)
    Text(
        guide,
        style = EchoV2.type.content(guide, EchoV2.type.body),
        color = c.textPrimary,
        modifier = Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s4)
    )
    when (autoRecord) {
        true -> Text(
            stringResource(R.string.v2_calls_pa_auto_on),
            style = EchoV2.type.meta,
            color = c.success,
            modifier = Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile)
        )

        false -> Text(
            stringResource(R.string.v2_calls_pa_auto_off),
            style = EchoV2.type.meta,
            color = c.warning,
            modifier = Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile)
        )

        null -> Unit
    }
    Column(
        Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s8),
        verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)
    ) {
        if (canOpen) {
            EchoButton(stringResource(R.string.v2_calls_pa_open), {
                runCatching { context.startActivity(Intent(callSettings).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)) }
            }, kind = EchoButtonKind.Secondary)
        }
        if (!canRead) {
            Text(stringResource(R.string.v2_calls_pa_needs), style = EchoV2.type.meta, color = c.warning)
            EchoButton(stringResource(R.string.v2_calls_pa_allow), {
                allow.launch(PhoneCallLog.permissionsToAsk())
            }, kind = EchoButtonKind.Secondary)
        }
    }
    EchoSwitch(
        settings.phoneAppImport,
        { on -> update { it.copy(phoneAppImport = on) } },
        stringResource(R.string.v2_calls_pa_import),
        supporting = stringResource(R.string.v2_calls_pa_import_help)
    )
    val canDelete = Build.VERSION.SDK_INT >= DELETE_SDK
    EchoSwitch(
        settings.deletePhoneAppCopy && canDelete,
        { on -> update { it.copy(deletePhoneAppCopy = on) } },
        stringResource(R.string.v2_calls_pa_delete),
        supporting = stringResource(if (canDelete) R.string.v2_calls_pa_delete_help else R.string.v2_calls_pa_delete_old),
        enabled = canDelete && settings.phoneAppImport
    )
    // A recording the phone does not say the maker of: only the person can tell it is their call's.
    waiting.forEach { candidate ->
        val time = clock.format(Instant.ofEpochMilli(candidate.callAtMs))
        Note(stringResource(R.string.v2_calls_pa_waiting, time))
        Row(
            Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s4),
            horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)
        ) {
            EchoButton(
                stringResource(R.string.v2_calls_pa_import_one),
                { confirm = candidate },
                Modifier.semanticsLabel(stringResource(R.string.v2_calls_pa_import_one_desc, time)),
                kind = EchoButtonKind.Secondary
            )
            EchoButton(
                stringResource(R.string.v2_calls_pa_not),
                {
                    PhoneAppRecordings.dropCandidate(context, candidate.fileId)
                    looked++
                },
                Modifier.semanticsLabel(stringResource(R.string.v2_calls_pa_not_desc, time)),
                kind = EchoButtonKind.Quiet
            )
        }
    }
    // The last call's outcome in the screen's language; a note with no code isn't shown in other words.
    // The core's code, read back through the stopgap's sentences until the page maps the code itself.
    note?.let { (at, core) ->
        LastCallNote.code(core.english.replaceFirstChar { it.uppercase() })?.let { code -> at to code }
    }?.let { (at, code) ->
        val said = stringResource(code.words, *code.args.toTypedArray())
        Note(stringResource(R.string.v2_calls_pa_last, clock.format(Instant.ofEpochMilli(at)), said))
    }
    confirm?.let { candidate ->
        // Today's dialog (the file's name and length, and Play to listen first).
        ImportRecordingDialog(candidate, clock.format(Instant.ofEpochMilli(candidate.callAtMs)), null, onDismiss = { confirm = null }) {
            confirm = null
            // The import runs in the app's own scope: leaving this screen never stops it half-way.
            val app = context.applicationContext as EchoLedgerApp
            val job = app.graph.scope.launch(kotlinx.coroutines.Dispatchers.IO) {
                PhoneAppRecordings.importCandidate(app, candidate.fileId)
            }
            scope.launch {
                job.join()
                looked++
            }
        }
    }
}
