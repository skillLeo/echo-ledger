package com.echoledger.mobile.ui

import android.content.Intent
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Call
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.FileUpload
import androidx.compose.material.icons.rounded.Mic
import androidx.compose.material.icons.rounded.Pause
import androidx.compose.material.icons.rounded.PlayArrow
import androidx.compose.material.icons.rounded.SportsEsports
import androidx.compose.material.icons.rounded.Tv
import androidx.compose.material.icons.rounded.Undo
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateListOf
import androidx.compose.runtime.remember
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
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.echoledger.mobile.core.SourceApp
import com.echoledger.mobile.notify.Notifications
import com.echoledger.mobile.privilege.Capabilities
import com.echoledger.mobile.recording.LiveRecording
import com.echoledger.mobile.recording.RecordingService
import com.echoledger.mobile.share.ShareImportActivity
import com.echoledger.mobile.ui.design.BannerKind
import com.echoledger.mobile.ui.design.ButtonKind
import com.echoledger.mobile.ui.design.EchoButton
import com.echoledger.mobile.ui.design.EchoTopBar
import com.echoledger.mobile.ui.design.InlineBanner
import com.echoledger.mobile.ui.design.SectionHeader
import com.echoledger.mobile.ui.design.SettingsRow
import com.echoledger.mobile.ui.design.SourceAppTile
import com.echoledger.mobile.ui.design.tabular

/** What the Record screen shows: a voice note (record, import) or calls and meetings (auto-record, Keep/Discard). */
enum class RecordMode { VOICE, CALLS }

/**
 * The Record sheet (since v1.3), from the centre button: four modes in a 2×2 grid, each with a one-line status.
 * Game and Watch translate arrive with Live Translate (Phase B) and say so.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun RecordSheet(capabilities: Capabilities, autoRecordOn: Boolean, onPick: (RecordMode) -> Unit, onDismiss: () -> Unit) {
    ModalBottomSheet(onDismissRequest = onDismiss, containerColor = Brand.Surface) {
        Column(Modifier.fillMaxWidth().navigationBarsPadding().padding(horizontal = Space.screen).padding(bottom = Space.s5)) {
            Text("New", style = LocalEchoType.current.titleM, color = Brand.Text, modifier = Modifier.padding(bottom = Space.s4))
            val calls = when {
                !capabilities.callCaptureSupportedByOs -> "Calls: needs Android 14"
                !capabilities.helperConnected -> "Calls: set up first"
                autoRecordOn -> "Calls: auto-record on"
                else -> "Calls: ready"
            }
            Row(horizontalArrangement = Arrangement.spacedBy(Space.s3)) {
                ModeTile(Icons.Rounded.Mic, "Voice notes & files", "Record or import", Modifier.weight(1f)) { onPick(RecordMode.VOICE) }
                ModeTile(Icons.Rounded.Call, "Calls & meetings", calls, Modifier.weight(1f)) { onPick(RecordMode.CALLS) }
            }
            Spacer(Modifier.height(Space.s3))
            Row(horizontalArrangement = Arrangement.spacedBy(Space.s3)) {
                ModeTile(Icons.Rounded.SportsEsports, "Game translate", "Coming soon", Modifier.weight(1f), enabled = false) {}
                ModeTile(Icons.Rounded.Tv, "Watch translate", "Coming soon", Modifier.weight(1f), enabled = false) {}
            }
        }
    }
}

@Composable
private fun ModeTile(icon: ImageVector, name: String, status: String, modifier: Modifier, enabled: Boolean = true, onClick: () -> Unit) {
    val c = LocalEchoColors.current
    val type = LocalEchoType.current
    Column(
        modifier
            .heightIn(min = 112.dp)
            .clip(RoundedCornerShape(Radius.md))
            .background(c.surface2)
            .clickable(enabled = enabled, role = Role.Button, onClick = onClick)
            .padding(Space.s4)
    ) {
        Box(
            Modifier.size(40.dp).clip(CircleShape).background(if (enabled) c.accentSoft else c.surface3),
            contentAlignment = Alignment.Center
        ) {
            Icon(icon, contentDescription = null, tint = if (enabled) c.accentText else c.textFaint, modifier = Modifier.size(22.dp))
        }
        Spacer(Modifier.weight(1f).height(Space.s3))
        Text(name, style = type.bodyL, color = if (enabled) c.text else c.textMuted, maxLines = 2)
        Text(status, style = type.labelM, color = c.textMuted, maxLines = 1, overflow = TextOverflow.Ellipsis)
    }
}

/**
 * Record (since v1.3). Voice: a live waveform, a large tabular timer, and discard · record or stop · pause, then the
 * live controls and transcript; Import file opens the system picker. Calls: auto-record and calls waiting for Keep or Discard.
 */
@Composable
fun RecordScreen(vm: MainViewModel, capabilities: Capabilities, onSetup: () -> Unit, mode: RecordMode = RecordMode.VOICE) {
    val context = LocalContext.current
    val settings by vm.settings.collectAsState()
    val pending by vm.pending.collectAsState()
    val learn by vm.learn.collectAsState()
    val live by RecordingService.live.collectAsState()
    LaunchedEffect(Unit) { vm.reloadLocal() }
    val haptics = LocalHapticFeedback.current
    val type = LocalEchoType.current
    val waiting = pending.filter { it.state == "pending_decision" }
    val import = rememberLauncherForActivityResult(ActivityResultContracts.OpenDocument()) { uri ->
        if (uri != null) {
            context.startActivity(
                Intent(context, ShareImportActivity::class.java)
                    .setAction(Intent.ACTION_SEND)
                    .setType(context.contentResolver.getType(uri) ?: "audio/*")
                    .putExtra(Intent.EXTRA_STREAM, uri)
                    .addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
            )
        }
    }
    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(bottom = 96.dp)) {
        EchoTopBar(if (mode == RecordMode.CALLS && !live.active) "Calls & meetings" else "Voice note")
        if (mode == RecordMode.VOICE || live.active) {
            RecordStage(live) { action ->
                if (action == RecordingService.ACTION_TOGGLE) {
                    haptics.performHapticFeedback(if (live.active) HapticFeedbackType.ToggleOff else HapticFeedbackType.ToggleOn)
                    runCatching { context.startForegroundService(RecordingService.intent(context, action, from = "Record tab")) }
                } else {
                    recordingAction(context, action)
                }
            }
            Text(
                if (capabilities.canCaptureCalls) "Both sides of calls (Me + Client)" else "Microphone only — enable call capture in Setup",
                style = type.caption,
                color = Brand.Muted,
                modifier = Modifier.fillMaxWidth().padding(horizontal = Space.screen, vertical = Space.s2),
                textAlign = androidx.compose.ui.text.style.TextAlign.Center
            )
            if (live.active) {
                Box(Modifier.padding(horizontal = Space.screen)) { LiveRecordingCard(live, settings) }
            } else {
                Box(Modifier.fillMaxWidth(), contentAlignment = Alignment.Center) {
                    EchoButton("Import file", {
                        import.launch(arrayOf("audio/*", "video/*"))
                    }, kind = ButtonKind.Text, icon = Icons.Rounded.FileUpload)
                }
            }
        }
        if (mode == RecordMode.CALLS || waiting.isNotEmpty()) {
            if (mode != RecordMode.CALLS || live.active) SectionHeader("Calls & meetings")
            SettingsRow(
                Icons.Rounded.Call,
                "Auto-record WhatsApp calls",
                summary = when {
                    !capabilities.callCaptureSupportedByOs -> "Requires Android 14 or later"
                    !capabilities.helperConnected -> "Enable call capture in Setup first"
                    else -> "You'll be asked after each call whether to keep it"
                },
                onClick = if (capabilities.helperConnected) null else onSetup,
                trailing = {
                    Switch(
                        checked = settings.autoRecordArmed,
                        enabled = capabilities.canCaptureCalls,
                        modifier = Modifier.semantics { contentDescription = "Auto-record WhatsApp calls" },
                        onCheckedChange = { on ->
                            context.startForegroundService(
                                RecordingService.intent(context, if (on) RecordingService.ACTION_ARM else RecordingService.ACTION_DISARM)
                            )
                        }
                    )
                }
            )
            if (!capabilities.helperConnected) {
                Box(Modifier.padding(horizontal = Space.screen)) { EchoButton("Open setup", onSetup, kind = ButtonKind.Secondary) }
            }
        }
        if (waiting.isNotEmpty()) {
            SectionHeader("Save these recordings?")
            waiting.forEach { rec ->
                KeepDiscardCard(
                    whenText = formatWhen(rec.startedAt),
                    title = Notifications.decisionText(rec, null),
                    app = SourceApp.from(rec.sourceApp),
                    language = rec.language,
                    onLanguage = { vm.setPendingLanguage(rec.id, it) },
                    onKeep = {
                        haptics.performHapticFeedback(HapticFeedbackType.Confirm)
                        vm.decide(rec.id, keep = true)
                    },
                    onDiscard = {
                        haptics.performHapticFeedback(HapticFeedbackType.Reject)
                        vm.decide(rec.id, keep = false)
                    },
                    question = learn?.takeIf { it.recordingId == rec.id }?.question,
                    onAnswer = { vm.answerLearn(it) }
                )
            }
        }
        val queued = pending.filter { it.state in setOf("queued", "uploading") }
        if (queued.isNotEmpty()) {
            InlineBanner(
                "Uploading ${queued.size} recording(s)…" +
                    (queued.mapNotNull { it.lastError }.firstOrNull()?.let { " Will retry: $it" } ?: ""),
                Modifier.padding(horizontal = Space.screen, vertical = Space.s2),
                action = "Retry now",
                onAction = { vm.retryPending() }
            )
        }
        val failedUploads = pending.filter { it.state == "failed" }
        if (failedUploads.isNotEmpty()) {
            SectionHeader("${failedUploads.size} recording(s) could not be uploaded")
            failedUploads.forEach { rec ->
                Column(
                    Modifier.fillMaxWidth().padding(horizontal = Space.screen, vertical = Space.s1).clip(RoundedCornerShape(Radius.md))
                        .background(LocalEchoColors.current.dangerSoft).padding(start = Space.s4, top = Space.s3, end = Space.s2)
                ) {
                    Text("${SourceApp.from(rec.sourceApp).label} · ${formatWhen(rec.startedAt)}", style = type.bodyL, color = Brand.Text)
                    rec.lastError?.let { Text(it, style = type.labelM, color = Brand.Muted) }
                    Row {
                        EchoButton("Retry", { vm.retryUpload(rec.id) }, kind = ButtonKind.Text)
                        EchoButton("Discard", { vm.decide(rec.id, keep = false) }, kind = ButtonKind.Text)
                    }
                }
            }
        }
        if (mode == RecordMode.VOICE) {
            Text(
                "Voice notes are never recorded automatically — share them to EchoLedger from WhatsApp.",
                style = type.caption,
                color = Brand.Muted,
                modifier = Modifier.padding(Space.screen)
            )
        }
    }
}

/** The recording stage: status, a live waveform, a 48 sp tabular timer and the three controls. */
@Composable
internal fun RecordStage(live: LiveRecording, onAction: ((String) -> Unit)? = null) {
    val context = LocalContext.current
    val act: (String) -> Unit = onAction ?: { action -> recordingAction(context, action) }
    val c = LocalEchoColors.current
    val type = LocalEchoType.current
    val pill by RecordingService.pillState.collectAsState()
    val levels = remember { mutableStateListOf<Float>() }
    LaunchedEffect(live.elapsedMs, live.active) {
        if (!live.active) {
            levels.clear()
        } else if (!live.paused) {
            levels += maxOf(live.meLevel, live.clientLevel).coerceIn(0f, 1f)
            while (levels.size > 48) levels.removeAt(0)
        }
    }
    Column(Modifier.fillMaxWidth().padding(horizontal = Space.screen), horizontalAlignment = Alignment.CenterHorizontally) {
        Text(
            when {
                !live.active -> "Not recording"
                live.paused -> "Paused"
                else -> "Recording"
            },
            style = type.labelM,
            color = when {
                !live.active -> c.textMuted
                live.paused -> c.warning
                else -> c.danger
            }
        )
        Waveform(levels, live.active && !live.paused, Modifier.fillMaxWidth().height(72.dp).padding(vertical = Space.s2))
        Text(
            formatDuration(live.elapsedMs).ifEmpty { "0:00" },
            style = type.display.copy(fontSize = 48.sp, lineHeight = 56.sp).tabular(),
            color = c.text
        )
        Spacer(Modifier.height(Space.s5))
        Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.SpaceEvenly, verticalAlignment = Alignment.Top) {
            if (live.active) {
                if (pill.discarding) {
                    StageButton(Icons.Rounded.Undo, "Undo", 48.dp, c.surface2, c.text) { act(RecordingService.ACTION_UNDO_DISCARD) }
                } else {
                    StageButton(Icons.Rounded.Close, "Discard", 48.dp, c.surface2, c.danger) {
                        act(RecordingService.ACTION_DISCARD_NOW)
                    }
                }
            } else {
                Spacer(Modifier.size(64.dp))
            }
            StageButton(
                if (live.active) null else Icons.Rounded.Mic,
                if (live.active) "Stop" else "Record",
                72.dp,
                if (live.active) c.dangerStrong else c.accentStrong,
                Color.White
            ) { act(RecordingService.ACTION_TOGGLE) }
            if (live.active) {
                StageButton(
                    if (live.paused) Icons.Rounded.PlayArrow else Icons.Rounded.Pause,
                    if (live.paused) "Resume" else "Pause",
                    48.dp,
                    c.surface2,
                    c.text
                ) { act(if (live.paused) RecordingService.ACTION_RESUME else RecordingService.ACTION_PAUSE) }
            } else {
                Spacer(Modifier.size(64.dp))
            }
        }
    }
}

/** A round control with its word underneath; a null [icon] draws the stop square. */
@Composable
private fun StageButton(icon: ImageVector?, label: String, size: Dp, fill: Color, tint: Color, onClick: () -> Unit) {
    Column(
        horizontalAlignment = Alignment.CenterHorizontally,
        modifier = Modifier.clickable(role = Role.Button, onClick = onClick).padding(Space.s1)
    ) {
        Box(Modifier.size(size).clip(CircleShape).background(fill), contentAlignment = Alignment.Center) {
            if (icon == null) {
                Box(Modifier.size(size * 0.3f).clip(RoundedCornerShape(4.dp)).background(tint))
            } else {
                Icon(icon, contentDescription = null, tint = tint, modifier = Modifier.size(size * 0.42f))
            }
        }
        Spacer(Modifier.height(Space.s1))
        Text(label, style = LocalEchoType.current.labelM, color = Brand.Muted)
    }
}

/** The live waveform: 2 dp bars of the latest levels, newest on the right; a flat line before recording. */
@Composable
private fun Waveform(levels: List<Float>, running: Boolean, modifier: Modifier) {
    val bar = if (running) LocalEchoColors.current.danger else LocalEchoColors.current.borderStrong
    Canvas(modifier) {
        val count = 48
        val step = size.width / count
        val w = 2.dp.toPx()
        val mid = size.height / 2
        for (i in 0 until count) {
            val level = levels.getOrNull(levels.size - count + i) ?: 0f
            val h = maxOf(w, level * size.height)
            drawRoundRect(bar, Offset(i * step + (step - w) / 2, mid - h / 2), Size(w, h), CornerRadius(w / 2))
        }
    }
}

/**
 * Keep or discard a finished call (since v1.3 look): the call, its language, then Keep (primary) and Discard; an
 * optional learning question underneath. The floating card after a call (DecisionPopup) uses the same words.
 */
@Composable
fun KeepDiscardCard(
    whenText: String?,
    title: String,
    app: SourceApp,
    language: String?,
    onLanguage: (String?) -> Unit,
    onKeep: () -> Unit,
    onDiscard: () -> Unit,
    question: String? = null,
    onAnswer: (Boolean) -> Unit = {}
) {
    val c = LocalEchoColors.current
    Column(
        Modifier.fillMaxWidth().padding(
            horizontal = Space.screen,
            vertical = Space.s2
        ).clip(RoundedCornerShape(Radius.lg)).background(c.surface)
            .border(1.dp, c.border, RoundedCornerShape(Radius.lg)).padding(Space.s4),
        verticalArrangement = Arrangement.spacedBy(Space.s3)
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            SourceAppTile(app, size = 40.dp)
            Column(Modifier.padding(start = Space.s3).weight(1f)) {
                Text(title, style = LocalEchoType.current.bodyL, color = c.text)
                whenText?.let { Text(it, style = LocalEchoType.current.labelM, color = c.textMuted) }
            }
        }
        LanguageChips(language, onLanguage)
        Row(horizontalArrangement = Arrangement.spacedBy(Space.s3)) {
            EchoButton("Discard", onDiscard, Modifier.weight(1f), kind = ButtonKind.Secondary)
            EchoButton("Keep", onKeep, Modifier.weight(1f))
        }
        question?.let { q ->
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(q, style = LocalEchoType.current.labelM, color = c.textMuted, modifier = Modifier.weight(1f))
                EchoButton("No", { onAnswer(false) }, kind = ButtonKind.Text)
                EchoButton("Yes", { onAnswer(true) }, kind = ButtonKind.Text)
            }
        }
    }
}
