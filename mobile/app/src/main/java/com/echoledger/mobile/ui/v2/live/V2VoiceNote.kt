package com.echoledger.mobile.ui.v2.live

import android.content.Context
import android.content.res.Resources
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.geometry.CornerRadius
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.hapticfeedback.HapticFeedbackType
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalHapticFeedback
import androidx.compose.ui.res.pluralStringResource
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.Dp
import com.echoledger.mobile.R
import com.echoledger.mobile.core.Owner
import com.echoledger.mobile.data.ClientDto
import com.echoledger.mobile.recording.BluetoothMic
import com.echoledger.mobile.recording.LiveRecording
import com.echoledger.mobile.recording.RecordingService
import com.echoledger.mobile.share.FileImport
import com.echoledger.mobile.ui.MainViewModel
import com.echoledger.mobile.ui.recordingAction
import com.echoledger.mobile.ui.v2.components.EchoBanner
import com.echoledger.mobile.ui.v2.components.EchoBannerKind
import com.echoledger.mobile.ui.v2.components.EchoBottomSheet
import com.echoledger.mobile.ui.v2.components.EchoButton
import com.echoledger.mobile.ui.v2.components.EchoButtonKind
import com.echoledger.mobile.ui.v2.components.EchoChip
import com.echoledger.mobile.ui.v2.components.EchoIcons
import com.echoledger.mobile.ui.v2.components.EchoTopBar
import com.echoledger.mobile.ui.v2.components.formatClock
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.tokens.EchoTokens
import kotlinx.coroutines.launch

/** How many level bars the live waveform keeps (the newest on the end side). */
private const val WAVE_BARS = 48

/**
 * The voice note screen on the v2 components (classic's Record › Voice & files): whether it records, a live waveform,
 * the timer, and Discard (then Undo) · Record or Stop · Pause or Resume; before recording, the Bluetooth headset note
 * and Import files (the system picker, then one project and language for all of them); "Uploading 2 recordings…" with
 * Retry now; and that voice notes are never recorded automatically. A viewer records nothing. The controls go to the
 * recording service, as classic's do.
 */
@Composable
fun V2VoiceNoteScreen(
    vm: MainViewModel,
    onBack: () -> Unit,
    modifier: Modifier = Modifier,
    /** A Bluetooth headset with a microphone is connected (the phone's audio devices). */
    headsetConnected: (Context) -> Boolean = BluetoothMic::connected
) {
    val context = LocalContext.current
    val settings by vm.settings.collectAsState()
    val pending by vm.pending.collectAsState()
    val live by RecordingService.live.collectAsState()
    val pill by RecordingService.pillState.collectAsState()
    val haptics = LocalHapticFeedback.current
    val scope = rememberCoroutineScope()
    val toasts = LocalEchoToasts.current
    var picked by remember { mutableStateOf<List<FileImport.Picked>>(emptyList()) }
    LaunchedEffect(Unit) { vm.reloadLocal() }
    // Checked again while the page is open: when the phone comes back to it, and when a headset comes or goes.
    var headset by remember { mutableStateOf(headsetConnected(context)) }
    androidx.lifecycle.compose.LifecycleEventEffect(androidx.lifecycle.Lifecycle.Event.ON_RESUME) { headset = headsetConnected(context) }
    androidx.compose.runtime.DisposableEffect(context) {
        val audio = context.getSystemService(android.media.AudioManager::class.java)
        val callback = object : android.media.AudioDeviceCallback() {
            override fun onAudioDevicesAdded(added: Array<out android.media.AudioDeviceInfo>?) {
                headset = headsetConnected(context)
            }

            override fun onAudioDevicesRemoved(removed: Array<out android.media.AudioDeviceInfo>?) {
                headset = headsetConnected(context)
            }
        }
        audio?.registerAudioDeviceCallback(callback, null)
        onDispose { audio?.unregisterAudioDeviceCallback(callback) }
    }
    val import = rememberLauncherForActivityResult(ActivityResultContracts.OpenMultipleDocuments()) { uris ->
        if (uris.isNotEmpty()) scope.launch { picked = vm.describeFiles(uris) }
    }
    if (picked.isNotEmpty()) {
        val clients by vm.clients.collectAsState()
        V2ImportSheet(
            picked,
            clients,
            onImport = { clientId, language -> vm.importFiles(picked, clientId, language, say = false) },
            onDone = { words ->
                picked = emptyList()
                words?.let { scope.launch { toasts.show(it) } }
            }
        )
    }
    val c = EchoV2.colors
    val pad = Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s8)
    Column(modifier.fillMaxSize().background(c.bgCanvas)) {
        EchoTopBar(stringResource(R.string.v2_vn_title), onBack = onBack)
        Column(Modifier.weight(1f).verticalScroll(rememberScrollState())) {
            if (settings.plan.viewer) {
                val where = settings.plan.workspace?.takeIf { it.isNotBlank() } ?: stringResource(R.string.v2_vn_this_workspace)
                EchoBanner(stringResource(R.string.v2_vn_viewer, where), EchoBannerKind.Info, pad)
                return@Column
            }
            VoiceStage(live, discarding = pill.discarding) { action ->
                if (action == RecordingService.ACTION_TOGGLE) {
                    haptics.performHapticFeedback(if (live.active) HapticFeedbackType.ToggleOff else HapticFeedbackType.ToggleOn)
                    runCatching { context.startForegroundService(RecordingService.intent(context, action, from = "Record tab")) }
                } else {
                    recordingAction(context, action)
                }
            }
            if (!live.active) {
                // A connected headset records through its narrow-band microphone: said before the note, not after.
                if (headset) {
                    EchoBanner(stringResource(R.string.v2_vn_bluetooth), EchoBannerKind.Warning, pad)
                }
                Box(Modifier.fillMaxWidth(), contentAlignment = Alignment.Center) {
                    EchoButton(
                        stringResource(R.string.v2_vn_import),
                        { import.launch(arrayOf("audio/*", "video/*")) },
                        kind = EchoButtonKind.Quiet,
                        icon = EchoIcons.Upload
                    )
                }
            }
            // On their way to the server (a recording kept for another account waits for it and is not counted).
            val queued = pending.filter { it.state in setOf("queued", "uploading") && Owner.heldFor(it.owner, settings.account) == null }
            if (queued.isNotEmpty()) {
                val retry = queued.firstNotNullOfOrNull { it.lastError }?.let { stringResource(R.string.v2_vn_will_retry, it) }
                EchoBanner(
                    listOfNotNull(pluralStringResource(R.plurals.v2_vn_uploading, queued.size, queued.size), retry).joinToString(" "),
                    EchoBannerKind.Info,
                    pad,
                    actionLabel = stringResource(R.string.v2_vn_retry_now),
                    onAction = { vm.retryPending() }
                )
            }
            Text(
                stringResource(R.string.v2_vn_never_auto),
                style = EchoV2.type.caption,
                color = c.textSecondary,
                modifier = Modifier.padding(EchoTokens.Layout.pagePaddingMobile)
            )
        }
    }
}

/** Whether it records, the live waveform, the timer and the three controls; each control names itself for TalkBack. */
@Composable
internal fun VoiceStage(live: LiveRecording, discarding: Boolean, onAction: (String) -> Unit) {
    val c = EchoV2.colors
    val levels = remember { mutableStateListOf<Float>() }
    LaunchedEffect(live.elapsedMs, live.active) {
        if (!live.active) {
            levels.clear()
        } else if (!live.paused) {
            levels += maxOf(live.meLevel, live.clientLevel).coerceIn(0f, 1f)
            while (levels.size > WAVE_BARS) levels.removeAt(0)
        }
    }
    Column(
        Modifier.fillMaxWidth().padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s16),
        horizontalAlignment = Alignment.CenterHorizontally
    ) {
        Text(
            stringResource(
                when {
                    !live.active -> R.string.v2_vn_not_recording
                    live.paused -> R.string.v2_vn_paused
                    else -> R.string.v2_vn_recording
                }
            ),
            style = EchoV2.type.label,
            // Recording, Paused, Not recording: said by TalkBack as it changes.
            modifier = Modifier.semantics { liveRegion = LiveRegionMode.Polite },
            color = when {
                !live.active -> c.textSecondary
                live.paused -> c.warning
                else -> c.record
            }
        )
        val level = stringResource(R.string.v2_vn_level)
        Waveform(
            levels,
            running = live.active && !live.paused,
            Modifier.fillMaxWidth().height(EchoTokens.Space.s64).padding(vertical = EchoTokens.Space.s8)
                .semantics { contentDescription = level }
        )
        // The timer reads left to right with tabular digits in every language.
        Text(
            formatClock(live.elapsedMs),
            style = EchoV2.type.titleL.copy(fontFamily = EchoV2.type.latin, fontFeatureSettings = "tnum"),
            color = c.textPrimary
        )
        Spacer(Modifier.height(EchoTokens.Space.s16))
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceEvenly, verticalAlignment = Alignment.Top) {
            if (live.active) {
                if (discarding) {
                    StageControl(EchoIcons.Restore, stringResource(R.string.v2_vn_undo), EchoTokens.Space.s48, c.bgSubtle, c.textPrimary) {
                        onAction(RecordingService.ACTION_UNDO_DISCARD)
                    }
                } else {
                    StageControl(EchoIcons.Close, stringResource(R.string.v2_vn_discard), EchoTokens.Space.s48, c.bgSubtle, c.danger) {
                        onAction(RecordingService.ACTION_DISCARD_NOW)
                    }
                }
            } else {
                Spacer(Modifier.size(EchoTokens.Space.s64))
            }
            StageControl(
                if (live.active) EchoIcons.Stop else EchoIcons.Mic,
                stringResource(if (live.active) R.string.v2_vn_stop else R.string.v2_vn_record),
                EchoTokens.Space.s64,
                c.record,
                c.textOnAccent
            ) { onAction(RecordingService.ACTION_TOGGLE) }
            if (live.active) {
                StageControl(
                    if (live.paused) EchoIcons.Play else EchoIcons.Pause,
                    stringResource(if (live.paused) R.string.v2_vn_resume else R.string.v2_vn_pause),
                    EchoTokens.Space.s48,
                    c.bgSubtle,
                    c.textPrimary
                ) { onAction(if (live.paused) RecordingService.ACTION_RESUME else RecordingService.ACTION_PAUSE) }
            } else {
                Spacer(Modifier.size(EchoTokens.Space.s64))
            }
        }
    }
}

/** A round control with its word underneath; the whole column is one button named by its word. */
@Composable
private fun StageControl(icon: ImageVector, label: String, size: Dp, fill: Color, tint: Color, onClick: () -> Unit) {
    Column(
        horizontalAlignment = Alignment.CenterHorizontally,
        modifier = Modifier.clip(RoundedCornerShape(EchoTokens.Component.buttonRadius))
            .clickable(role = Role.Button, onClickLabel = label, onClick = onClick)
            .padding(EchoTokens.Space.s4)
    ) {
        Box(Modifier.size(size).clip(CircleShape).background(fill), contentAlignment = Alignment.Center) {
            Icon(icon, contentDescription = null, tint = tint, modifier = Modifier.size(EchoTokens.Icon.lg))
        }
        Spacer(Modifier.height(EchoTokens.Space.s4))
        Text(label, style = EchoV2.type.caption, color = EchoV2.colors.textSecondary)
    }
}

/** The live waveform: thin bars of the latest levels, the newest on the end side; a flat line before recording. */
@Composable
private fun Waveform(levels: List<Float>, running: Boolean, modifier: Modifier) {
    val bar = if (running) EchoV2.colors.record else EchoV2.colors.borderStrong
    Canvas(modifier) {
        val step = size.width / WAVE_BARS
        val w = EchoTokens.Border.strong.toPx()
        val mid = size.height / 2
        for (i in 0 until WAVE_BARS) {
            val level = levels.getOrNull(levels.size - WAVE_BARS + i) ?: 0f
            val h = maxOf(w, level * size.height)
            drawRoundRect(bar, Offset(i * step + (step - w) / 2, mid - h / 2), Size(w, h), CornerRadius(w / 2))
        }
    }
}

/** What an import came to, in the screen's words: importing N, N already there, N could not be read. */
fun importWords(res: Resources, summary: FileImport.Summary): String = listOfNotNull(
    summary.imported.size.takeIf { it > 0 }?.let { res.getQuantityString(R.plurals.v2_imp_done, it, it) },
    summary.duplicates.takeIf { it > 0 }?.let { res.getQuantityString(R.plurals.v2_imp_dupes, it, it) },
    summary.unreadable.takeIf { it > 0 }?.let { res.getQuantityString(R.plurals.v2_imp_unreadable, it, it) }
).joinToString(" ").ifEmpty { res.getString(R.string.v2_imp_nothing) }

/** The languages an import offers (classic's chips): automatic, then English, Urdu, Arabic. */
private val IMPORT_LANGUAGES = listOf(null, "en", "ur", "ar")

/**
 * Import files (classic's ImportSheet on the v2 components): the files picked with their sizes, one project and one
 * language for all of them, then Import; the outcome is said in the screen's words.
 */
@OptIn(ExperimentalLayoutApi::class)
@Composable
fun V2ImportSheet(
    files: List<FileImport.Picked>,
    clients: List<ClientDto>,
    onImport: suspend (clientId: String?, language: String?) -> FileImport.Summary,
    /** Closed: with the outcome in the screen's words once imported, null when closed without importing. */
    onDone: (String?) -> Unit
) {
    val res = LocalContext.current.resources
    val c = EchoV2.colors
    val scope = rememberCoroutineScope()
    var clientId by remember { mutableStateOf<String?>(null) }
    var language by remember { mutableStateOf<String?>(null) }
    var busy by remember { mutableStateOf(false) }
    var failed by remember { mutableStateOf(false) }
    EchoBottomSheet(onDismiss = {
        if (!busy) onDone(null)
    }, title = pluralStringResource(R.plurals.v2_imp_heading, files.size, files.size)) {
        Column(
            Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile),
            verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)
        ) {
            files.forEach { file ->
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(
                        FileImport.titleOf(file.name),
                        style = EchoV2.type.content(file.name, EchoV2.type.body),
                        color = c.textPrimary,
                        maxLines = 1,
                        modifier = Modifier.weight(1f)
                    )
                    file.size?.let {
                        Text(com.echoledger.mobile.ui.v2.library.LibraryModel.size(it), style = EchoV2.type.mono, color = c.textSecondary)
                    }
                }
            }
            Text(stringResource(R.string.v2_imp_for_all), style = EchoV2.type.label, color = c.textSecondary)
            if (clients.isNotEmpty()) {
                FlowRow(horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
                    EchoChip(stringResource(R.string.v2_imp_no_project), clientId == null, { clientId = null })
                    clients.forEach { p -> EchoChip(p.name, clientId == p.id, { clientId = p.id }) }
                }
            }
            FlowRow(horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
                IMPORT_LANGUAGES.forEach { code ->
                    EchoChip(com.echoledger.mobile.ui.v2.library.languageWords(code), language == code, { language = code })
                }
            }
            Text(stringResource(R.string.v2_imp_help), style = EchoV2.type.caption, color = c.textSecondary)
            if (failed) EchoBanner(stringResource(R.string.v2_imp_failed), EchoBannerKind.Error)
            EchoButton(
                pluralStringResource(R.plurals.v2_imp_heading, files.size, files.size),
                onClick = {
                    busy = true
                    failed = false
                    scope.launch {
                        // A failure (a file the phone can't read or store) says so; the sheet stays to try again.
                        val summary = try {
                            onImport(clientId, language)
                        } catch (e: kotlinx.coroutines.CancellationException) {
                            throw e
                        } catch (e: Exception) {
                            null
                        } finally {
                            busy = false
                        }
                        if (summary == null) failed = true else onDone(importWords(res, summary))
                    }
                },
                modifier = Modifier.fillMaxWidth(),
                loading = busy,
                loadingText = stringResource(R.string.v2_imp_importing),
                enabled = files.isNotEmpty()
            )
        }
    }
}
