package com.echoledger.mobile.ui.v2.settings

import android.Manifest
import android.content.Context
import android.media.AudioTrack
import android.os.Build
import android.provider.Settings
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableFloatStateOf
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.ProgressBarRangeInfo
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.progressBarRangeInfo
import androidx.compose.ui.semantics.semantics
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.compose.LifecycleEventEffect
import com.echoledger.mobile.BuildConfig
import com.echoledger.mobile.R
import com.echoledger.mobile.calls.PhoneCallLog
import com.echoledger.mobile.core.PhoneCalls
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.notify.CallerNameListener
import com.echoledger.mobile.recording.CaptureHealth
import com.echoledger.mobile.recording.InputChoice
import com.echoledger.mobile.recording.Levels
import com.echoledger.mobile.recording.MicTestResult
import com.echoledger.mobile.recording.PermissionItem
import com.echoledger.mobile.ui.granted
import com.echoledger.mobile.ui.permissionIntent
import com.echoledger.mobile.ui.v2.components.EchoButton
import com.echoledger.mobile.ui.v2.components.EchoButtonKind
import com.echoledger.mobile.ui.v2.components.EchoRadio
import com.echoledger.mobile.ui.v2.components.EchoSectionHeader
import com.echoledger.mobile.ui.v2.components.EchoStatus
import com.echoledger.mobile.ui.v2.components.EchoStatusKind
import com.echoledger.mobile.ui.v2.components.EchoSwitch
import com.echoledger.mobile.ui.v2.components.EchoTopBar
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.tokens.EchoTokens
import kotlinx.coroutines.launch

/** The microphone's two processings and the three recording qualities, as stored (classic's AudioChoices). */
private val MIC_SOURCES = listOf("voice_communication" to R.string.v2_mic_reduce_echo, "mic" to R.string.v2_mic_unprocessed)
private val QUALITIES = listOf(32 to R.string.v2_mic_standard, 48 to R.string.v2_mic_high, 64 to R.string.v2_mic_highest)

/** A connected microphone's name in the screen's language (classic's [InputChoice.label] is English). */
private fun inputWords(key: String): Int? = when (key) {
    "builtin" -> R.string.v2_mic_in_builtin
    "wired" -> R.string.v2_mic_in_wired
    "bluetooth" -> R.string.v2_mic_in_bluetooth
    "usb" -> R.string.v2_mic_in_usb
    else -> null
}

/** A permission row's title and why, in the screen's language (classic's [PermissionItem] words are English). */
private fun permissionWords(key: String): Pair<Int, Int>? = when (key) {
    "microphone" -> R.string.v2_mic_p_microphone to R.string.v2_mic_p_microphone_why
    "helper" -> R.string.v2_mic_p_helper to R.string.v2_mic_p_helper_why
    "notifications" -> R.string.v2_mic_p_notifications to R.string.v2_mic_p_notifications_why
    "overlay" -> R.string.v2_mic_p_overlay to R.string.v2_mic_p_overlay_why
    "battery" -> R.string.v2_mic_p_battery to R.string.v2_mic_p_battery_why
    "caller_names" -> R.string.v2_mic_p_caller_names to R.string.v2_mic_p_caller_names_why
    "call_log" -> R.string.v2_mic_p_call_log to R.string.v2_mic_p_call_log_why
    "contacts" -> R.string.v2_mic_p_contacts to R.string.v2_mic_p_contacts_why
    else -> null
}

/** The permissions centre's rows from what is granted now (the same rows as classic's CaptureHealthSection). */
fun micPermissionItems(context: Context, helperConnected: Boolean, settings: AppSettings): List<PermissionItem> {
    val phoneCalls = PhoneCalls.mode(settings.phoneCalls) != PhoneCalls.OFF
    return CaptureHealth.permissionItems(
        microphone = granted(context, Manifest.permission.RECORD_AUDIO),
        notifications = Build.VERSION.SDK_INT < Build.VERSION_CODES.TIRAMISU || granted(context, Manifest.permission.POST_NOTIFICATIONS),
        overlay = Settings.canDrawOverlays(context),
        battery = CaptureHealth.ignoresBatteryOptimisation(context),
        helper = helperConnected.takeIf { BuildConfig.CAPTURE_HELPER },
        callerNames = CallerNameListener.allowed(context).takeIf { BuildConfig.CAPTURE_HELPER },
        callLog = granted(context, Manifest.permission.READ_CALL_LOG).takeIf { phoneCalls && PhoneCallLog.available },
        contacts = granted(context, Manifest.permission.READ_CONTACTS).takeIf { phoneCalls && BuildConfig.CAPTURE_HELPER }
    )
}

/**
 * Microphone & audio on the v2 components (classic's MicrophoneEntry and CaptureHealthSection, the same settings): your
 * microphone (Reduce echo or Unprocessed), recording quality, which connected microphone to record from, the 10-second
 * test recording with its level, peak and Play back, the silent-other-side warning, the permissions and battery with
 * Fix on each one not allowed (caller names among them), and Reset to defaults.
 */
@Composable
fun MicPage(
    settings: AppSettings,
    update: ((AppSettings) -> AppSettings) -> Unit,
    highlight: String?,
    onBack: () -> Unit,
    modifier: Modifier = Modifier,
    /** The capture helper is connected (its permission row) and a recording runs (no test meanwhile). */
    helperConnected: Boolean = false,
    recording: Boolean = false,
    /** Settings › Setup & health, where the capture helper is set up (its row's Fix). */
    onSetup: () -> Unit = {},
    /** The connected microphones, read again each time the page comes back. */
    inputChoices: (Context) -> List<InputChoice> = CaptureHealth::inputChoices,
    /** The test recording: the microphone to prefer and the level as it records. */
    recordTest: suspend (Context, String?, (Float) -> Unit) -> MicTestResult = { context, key, onLevel ->
        CaptureHealth.record(context, key, onLevel = onLevel)
    },
    playBack: (ShortArray) -> AudioTrack? = CaptureHealth::play
) {
    val c = EchoV2.colors
    val context = LocalContext.current
    var refresh by remember { mutableIntStateOf(0) }
    LifecycleEventEffect(Lifecycle.Event.ON_RESUME) { refresh++ }
    val choices = remember(refresh) { inputChoices(context) }
    val items = remember(refresh, helperConnected, settings.phoneCalls) { micPermissionItems(context, helperConnected, settings) }
    // Read again on resume, as the permission rows are: back from Android's settings, the test can start.
    val micAllowed = remember(refresh) { granted(context, Manifest.permission.RECORD_AUDIO) }
    val pad = Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile)
    Column(modifier.fillMaxSize().background(c.bgCanvas)) {
        EchoTopBar(stringResource(R.string.v2_set_page_mic), onBack = onBack)
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState())) {
            Column(Modifier.highlightedIf(highlight == "mic.source")) {
                Group(stringResource(R.string.v2_mic_yours), stringResource(R.string.v2_mic_yours_help))
                val current = MIC_SOURCES.firstOrNull { it.first == settings.micSource }?.first ?: MIC_SOURCES.first().first
                Choices(stringResource(R.string.v2_mic_yours), MIC_SOURCES, current) { v -> update { it.copy(micSource = v) } }
            }
            Column(Modifier.highlightedIf(highlight == "mic.quality")) {
                Group(stringResource(R.string.v2_mic_quality), stringResource(R.string.v2_mic_quality_help))
                val current = QUALITIES.firstOrNull { it.first == settings.recordingKbps }?.first ?: QUALITIES.first().first
                Choices(stringResource(R.string.v2_mic_quality), QUALITIES, current) { v -> update { it.copy(recordingKbps = v) } }
            }
            Column(Modifier.highlightedIf(highlight == "mic.input")) {
                val from = stringResource(R.string.v2_mic_record_from)
                Group(from, null)
                val automatic = stringResource(R.string.v2_mic_automatic)
                (
                    listOf<Pair<String?, String>>(null to automatic) +
                        choices.map { ch -> ch.key to (inputWords(ch.key)?.let { stringResource(it) } ?: ch.label) }
                    )
                    .forEach { (key, label) ->
                        EchoRadio(
                            settings.inputDevice == key,
                            { update { it.copy(inputDevice = key) } },
                            label,
                            pad.semantics { contentDescription = "$from: $label" }
                        )
                    }
                if (settings.inputDevice != null && choices.none { it.key == settings.inputDevice }) {
                    Note(stringResource(R.string.v2_mic_not_connected))
                }
                Note(stringResource(R.string.v2_mic_helper_note))
            }
            Column(Modifier.highlightedIf(highlight == "mic.test")) {
                MicTest(settings.inputDevice, recording, micAllowed, recordTest, playBack)
            }
            EchoSwitch(
                settings.farSideWarning,
                { on -> update { it.copy(farSideWarning = on) } },
                stringResource(R.string.v2_mic_silent),
                supporting = stringResource(R.string.v2_mic_silent_help),
                modifier = Modifier.highlightedIf(highlight == "mic.silent")
            )
            Column(Modifier.highlightedIf(highlight == "mic.permissions")) {
                EchoSectionHeader(stringResource(R.string.v2_mic_permissions))
                items.forEach { item ->
                    // Caller names is notification access: its own setting, lit on its own.
                    Box(Modifier.highlightedIf(item.key == "caller_names" && highlight == "mic.callers")) {
                        PermissionRow(item) {
                            if (item.key == "helper") {
                                onSetup()
                            } else {
                                permissionIntent(context, item.key)?.let { runCatching { context.startActivity(it) } }
                            }
                        }
                    }
                }
            }
            PageReset("mic", stringResource(R.string.v2_set_page_mic), update)
        }
    }
}

/** A group's heading and the grey line under it. */
@Composable
private fun Group(title: String, help: String?) {
    EchoSectionHeader(title)
    help?.let { Note(it) }
}

/** A setting's few choices on the page, each named with its setting for TalkBack ("Recording quality: High"). */
@Composable
private fun <T> Choices(setting: String, options: List<Pair<T, Int>>, selected: T, onPick: (T) -> Unit) {
    options.forEach { (value, words) ->
        val label = stringResource(words)
        EchoRadio(
            selected == value,
            { onPick(value) },
            label,
            Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile).semantics { contentDescription = "$setting: $label" }
        )
    }
}

/** The 10-second test: Test 10 s (not while recording or without the microphone), the level, the peak, Play back. */
@Composable
private fun MicTest(
    inputDevice: String?,
    recording: Boolean,
    micAllowed: Boolean,
    recordTest: suspend (Context, String?, (Float) -> Unit) -> MicTestResult,
    playBack: (ShortArray) -> AudioTrack?
) {
    val c = EchoV2.colors
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var testing by remember { mutableStateOf(false) }
    var level by remember { mutableFloatStateOf(0f) }
    var result by remember { mutableStateOf<MicTestResult?>(null) }
    var track by remember { mutableStateOf<AudioTrack?>(null) }
    DisposableEffect(Unit) { onDispose { track?.release() } }
    Row(
        Modifier.fillMaxWidth().padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s8),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s12)
    ) {
        Column(Modifier.weight(1f)) {
            Text(stringResource(R.string.v2_mic_test), style = EchoV2.type.body, color = c.textPrimary)
            Text(stringResource(R.string.v2_mic_test_help, CaptureHealth.TEST_SECONDS), style = EchoV2.type.meta, color = c.textSecondary)
        }
        EchoButton(
            stringResource(R.string.v2_mic_test_start, CaptureHealth.TEST_SECONDS),
            {
                testing = true
                result = null
                scope.launch {
                    result = runCatching { recordTest(context, inputDevice) { level = it } }.getOrNull()
                    testing = false
                }
            },
            enabled = !recording && micAllowed,
            loading = testing,
            loadingText = stringResource(R.string.v2_mic_testing),
            kind = EchoButtonKind.Secondary
        )
    }
    if (testing) Level(stringResource(R.string.v2_mic_me), level)
    result?.let { r ->
        Level(stringResource(R.string.v2_mic_peak), r.peak)
        Note(stringResource(if (r.heard) R.string.v2_mic_heard else R.string.v2_mic_not_heard))
        EchoButton(
            stringResource(R.string.v2_mic_play_back),
            {
                track?.release()
                track = playBack(r.samples)
            },
            Modifier.padding(horizontal = EchoTokens.Space.s8),
            kind = EchoButtonKind.Quiet
        )
    }
    if (recording) Note(stringResource(R.string.v2_mic_finish_first))
}

/** A thin level bar on the meter's scale, read as a progress bar with its name ("Peak level: 40 percent"). */
@Composable
private fun Level(label: String, rms: Float) {
    val c = EchoV2.colors
    val fill = Levels.meter(rms).coerceIn(0f, 1f)
    val words = stringResource(R.string.v2_mic_level, label, (fill * 100).toInt())
    Row(
        Modifier.fillMaxWidth().padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s4),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)
    ) {
        Text(label, style = EchoV2.type.meta, color = c.textSecondary, modifier = Modifier.widthIn(min = EchoTokens.Space.s48))
        Box(
            Modifier.weight(1f).height(EchoTokens.Space.s4)
                .background(c.bgSubtle, RoundedCornerShape(EchoTokens.Radius.full))
                .semantics {
                    progressBarRangeInfo = ProgressBarRangeInfo(fill, 0f..1f)
                    contentDescription = words
                }
        ) {
            Box(Modifier.fillMaxHeight().fillMaxWidth(fill).background(c.accent, RoundedCornerShape(EchoTokens.Radius.full)))
        }
    }
}

/** A permission: its name, Allowed or why it is needed, and Fix (named for TalkBack by what it fixes) while it is not. */
@Composable
private fun PermissionRow(item: PermissionItem, onFix: () -> Unit) {
    val c = EchoV2.colors
    val words = permissionWords(item.key)
    val title = words?.let { stringResource(it.first) } ?: item.title
    Row(
        Modifier.fillMaxWidth().padding(start = EchoTokens.Layout.pagePaddingMobile, end = EchoTokens.Space.s8, top = EchoTokens.Space.s8),
        verticalAlignment = Alignment.CenterVertically
    ) {
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s4)) {
            Text(title, style = EchoV2.type.body, color = c.textPrimary)
            if (item.granted) {
                EchoStatus(stringResource(R.string.v2_mic_allowed), EchoStatusKind.Success)
            } else {
                Text(words?.let { stringResource(it.second) } ?: item.why, style = EchoV2.type.meta, color = c.textSecondary)
            }
        }
        if (!item.granted) {
            val named = stringResource(R.string.v2_mic_fix_named, title)
            EchoButton(
                stringResource(R.string.v2_mic_fix),
                onFix,
                Modifier.semantics { contentDescription = named },
                kind = EchoButtonKind.Quiet
            )
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
