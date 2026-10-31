package com.echoledger.mobile.ui

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
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Call
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.Edit
import androidx.compose.material.icons.rounded.FileUpload
import androidx.compose.material.icons.rounded.Mic
import androidx.compose.material.icons.rounded.Pause
import androidx.compose.material.icons.rounded.PlayArrow
import androidx.compose.material.icons.rounded.SportsEsports
import androidx.compose.material.icons.rounded.Tv
import androidx.compose.material.icons.rounded.Undo
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
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
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.geometry.CornerRadius
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
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
import com.echoledger.mobile.data.ClientDto
import com.echoledger.mobile.notify.Notifications
import com.echoledger.mobile.privilege.Capabilities
import com.echoledger.mobile.recording.LiveRecording
import com.echoledger.mobile.recording.RecordingService
import com.echoledger.mobile.ui.design.BannerKind
import com.echoledger.mobile.ui.design.ButtonKind
import com.echoledger.mobile.ui.design.CompactBarIcon
import com.echoledger.mobile.ui.design.EchoButton
import com.echoledger.mobile.ui.design.EchoFilterChip
import com.echoledger.mobile.ui.design.EchoTopBar
import com.echoledger.mobile.ui.design.InlineBanner
import com.echoledger.mobile.ui.design.SectionHeader
import com.echoledger.mobile.ui.design.SettingsRow
import com.echoledger.mobile.ui.design.SourceAppTile
import com.echoledger.mobile.ui.design.tabular
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/** What the Record screen shows: a voice note (record, import) or calls and meetings (auto-record, Keep/Discard). */
enum class RecordMode { VOICE, CALLS, GAME, WATCH }

/**
 * The Record sheet (since v1.3), from the centre button: four modes in a 2×2 grid, each with a one-line status.
 * Game and Watch translate (Phase B) say whether they are on.
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
                val translating by com.echoledger.mobile.translate.LiveTranslateService.state.collectAsState()
                // What Soniox said last time it refused ("Needs Soniox credit"), until a session runs again.
                val blocked = com.echoledger.mobile.translate.TranslateStatus.blocked(androidx.compose.ui.platform.LocalContext.current)
                ModeTile(
                    Icons.Rounded.SportsEsports,
                    "Game translate",
                    when {
                        translating == com.echoledger.mobile.translate.TranslateMode.GAME -> "Game: on"
                        blocked != null -> blocked
                        else -> "Game: off"
                    },
                    Modifier.weight(1f)
                ) { onPick(RecordMode.GAME) }
                ModeTile(
                    Icons.Rounded.Tv,
                    "Watch translate",
                    when {
                        translating == com.echoledger.mobile.translate.TranslateMode.WATCH -> "Watch: on"
                        blocked != null -> blocked
                        else -> "Watch: off"
                    },
                    Modifier.weight(1f)
                ) { onPick(RecordMode.WATCH) }
            }
        }
    }
}

@Composable
private fun ModeTile(icon: ImageVector, name: String, status: String, modifier: Modifier, enabled: Boolean = true, onClick: () -> Unit) {
    val c = LocalEchoColors.current
    val type = LocalEchoType.current
    // A fixed 112 dp tile (since 1.3.2: a flexible spacer stretched each tile to the whole sheet).
    Column(
        modifier
            .height(112.dp)
            .clip(RoundedCornerShape(Radius.md))
            .background(c.surface2)
            .clickable(enabled = enabled, role = Role.Button, onClick = onClick)
            .padding(horizontal = Space.s4, vertical = Space.s3),
        verticalArrangement = Arrangement.SpaceBetween
    ) {
        Box(
            Modifier.size(36.dp).clip(CircleShape).background(if (enabled) c.accentSoft else c.surface3),
            contentAlignment = Alignment.Center
        ) {
            Icon(icon, contentDescription = null, tint = if (enabled) c.accentText else c.textFaint, modifier = Modifier.size(20.dp))
        }
        Column {
            Text(name, style = type.bodyL, color = if (enabled) c.text else c.textMuted, maxLines = 1, overflow = TextOverflow.Ellipsis)
            Text(status, style = type.labelM, color = c.textMuted, maxLines = 1, overflow = TextOverflow.Ellipsis)
        }
    }
}

/**
 * Record (since v1.3). Voice: a live waveform, a large tabular timer, and discard · record or stop · pause, then the
 * live controls and transcript; Import file opens the system picker. Calls: auto-record and calls waiting for Keep or Discard.
 */
@Composable
fun RecordScreen(
    vm: MainViewModel,
    capabilities: Capabilities,
    onSetup: () -> Unit,
    mode: RecordMode = RecordMode.VOICE,
    onReviewWaiting: () -> Unit = {}
) {
    // Game and Watch translate have their own pages (Phase B).
    if (mode == RecordMode.GAME || mode == RecordMode.WATCH) {
        LiveTranslatePage(
            vm,
            if (mode ==
                RecordMode.GAME
            ) {
                com.echoledger.mobile.translate.TranslateMode.GAME
            } else {
                com.echoledger.mobile.translate.TranslateMode.WATCH
            },
            onSetup
        )
        return
    }
    val context = LocalContext.current
    val settings by vm.settings.collectAsState()
    val pending by vm.pending.collectAsState()
    val learn by vm.learn.collectAsState()
    val clients by vm.clients.collectAsState()
    val live by RecordingService.live.collectAsState()
    LaunchedEffect(Unit) { vm.reloadLocal() }
    val haptics = LocalHapticFeedback.current
    val type = LocalEchoType.current
    val waiting = pending.filter { it.state == "pending_decision" }
    // Import (since 1.4): pick one file or many, then one client and language for all of them in the sheet.
    var picked by remember { mutableStateOf<List<com.echoledger.mobile.share.FileImport.Picked>>(emptyList()) }
    val scope = rememberCoroutineScope()
    val import = rememberLauncherForActivityResult(ActivityResultContracts.OpenMultipleDocuments()) { uris ->
        if (uris.isNotEmpty()) scope.launch { picked = vm.describeFiles(uris) }
    }
    if (picked.isNotEmpty()) ImportSheet(vm, picked, onDone = { picked = emptyList() })
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
            Spacer(Modifier.height(Space.s3))
            if (live.active) {
                Box(Modifier.padding(horizontal = Space.screen)) { LiveRecordingCard(live, settings) }
            } else {
                Box(Modifier.fillMaxWidth(), contentAlignment = Alignment.Center) {
                    EchoButton("Import files", {
                        import.launch(arrayOf("audio/*", "video/*"))
                    }, kind = ButtonKind.Text, icon = Icons.Rounded.FileUpload)
                }
            }
        }
        if (mode == RecordMode.CALLS) {
            if (live.active) SectionHeader("Calls & meetings")
            SettingsRow(
                Icons.Rounded.Call,
                "Auto-record calls",
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
                        modifier = Modifier.semantics { contentDescription = "Auto-record calls" },
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
            // Calls waiting for a decision: one card, the list is one tap away (since 1.3.2).
            WaitingSummaryCard(waiting, onReview = onReviewWaiting)
            // The apps auto-record listens to, right under its switch (since 1.3.2).
            Box(Modifier.padding(horizontal = Space.screen, vertical = Space.s2)) { AutoRecordSection(vm, settings) }
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
 * Keep or discard a finished call (since v1.3 look): the call's title (typed here, or "WhatsApp call · 2:05" left to the
 * server), when it was, the client and the language, then Keep (primary) and Discard; an optional learning question
 * underneath. The floating card after a call (DecisionPopup) uses the same words.
 */
@Composable
fun KeepDiscardCard(
    whenText: String?,
    title: String,
    app: SourceApp,
    language: String?,
    onLanguage: (String?) -> Unit,
    onKeep: (title: String?) -> Unit,
    onDiscard: () -> Unit,
    question: String? = null,
    onAnswer: (Boolean) -> Unit = {},
    typedTitle: String? = null,
    onTitle: (String) -> Unit = {},
    clients: List<ClientDto> = emptyList(),
    clientId: String? = null,
    onClient: (String?) -> Unit = {}
) {
    val c = LocalEchoColors.current
    val type = LocalEchoType.current
    var text by remember { mutableStateOf(typedTitle.orEmpty()) }
    val titleFocus = remember { FocusRequester() }
    // Kept when leaving without a decision; Keep itself always carries the words typed so far.
    LaunchedEffect(text) {
        if (text != typedTitle.orEmpty()) {
            delay(600)
            onTitle(text)
        }
    }
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
                Box {
                    if (text.isEmpty()) Text(title, style = type.bodyL, color = c.text, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    BasicTextField(
                        value = text,
                        onValueChange = { text = it.take(200) },
                        singleLine = true,
                        textStyle = type.bodyL.copy(color = c.text),
                        cursorBrush = SolidColor(c.accentText),
                        modifier = Modifier.fillMaxWidth().focusRequester(titleFocus).semantics { contentDescription = "Title" }
                    )
                }
                whenText?.let { Text(it, style = type.labelM, color = c.textMuted) }
            }
            // The title is typed in place; the pencil says so and puts the cursor there.
            CompactBarIcon(Icons.Rounded.Edit, "Rename", { runCatching { titleFocus.requestFocus() } })
        }
        if (clients.isNotEmpty()) ClientChip(clients, clientId, onClient)
        LanguageChips(language, onLanguage)
        Row(horizontalArrangement = Arrangement.spacedBy(Space.s3)) {
            EchoButton("Discard", onDiscard, Modifier.weight(1f), kind = ButtonKind.Secondary)
            EchoButton("Keep", { onKeep(text.takeIf { it != typedTitle.orEmpty() }) }, Modifier.weight(1f))
        }
        question?.let { q ->
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(q, style = type.labelM, color = c.textMuted, modifier = Modifier.weight(1f))
                EchoButton("No", { onAnswer(false) }, kind = ButtonKind.Text)
                EchoButton("Yes", { onAnswer(true) }, kind = ButtonKind.Text)
            }
        }
    }
}

/** "Client ▾" (or the client's name): picks the client a waiting call is filed under. */
@Composable
internal fun ClientChip(clients: List<ClientDto>, clientId: String?, onClient: (String?) -> Unit) {
    var open by remember { mutableStateOf(false) }
    val chosen = clients.firstOrNull { it.id == clientId }
    Box {
        EchoFilterChip(
            "${chosen?.name ?: "Client"} ▾",
            active = chosen != null,
            onClick = { open = true },
            modifier = Modifier.semantics { contentDescription = "Client: ${chosen?.name ?: "none"}" }
        )
        DropdownMenu(expanded = open, onDismissRequest = { open = false }) {
            DropdownMenuItem(text = { Text("No client") }, onClick = {
                open = false
                onClient(null)
            })
            clients.sortedBy { it.name.lowercase() }.forEach { client ->
                DropdownMenuItem(text = { Text(client.name) }, onClick = {
                    open = false
                    onClient(client.id)
                })
            }
        }
    }
}
