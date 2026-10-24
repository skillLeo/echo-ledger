package com.echoledger.mobile.ui

import android.Manifest
import android.content.ClipData
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.provider.Settings
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.ArrowBack
import androidx.compose.material.icons.automirrored.outlined.LibraryBooks
import androidx.compose.material.icons.outlined.ContentCopy
import androidx.compose.material.icons.outlined.Delete
import androidx.compose.material.icons.outlined.QrCodeScanner
import androidx.compose.material.icons.outlined.Refresh
import androidx.compose.material.icons.outlined.Schedule
import androidx.compose.material.icons.outlined.Search
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.FilterChip
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.platform.LocalClipboard
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalFocusManager
import androidx.compose.ui.platform.toClipEntry
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.app.ActivityCompat
import androidx.core.content.ContextCompat
import com.echoledger.mobile.core.PairingPayload
import com.echoledger.mobile.core.SourceApp
import com.echoledger.mobile.core.SourceDevice
import com.echoledger.mobile.core.TranscriptText
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.TranscriptDto
import com.echoledger.mobile.privilege.Capabilities
import com.echoledger.mobile.recording.RecordingService
import kotlinx.coroutines.launch

@Composable
fun PairingScreen(vm: MainViewModel) {
    val context = LocalContext.current
    val focusManager = LocalFocusManager.current
    var url by rememberSaveable { mutableStateOf("https://") }
    var code by rememberSaveable { mutableStateOf("") }
    var name by rememberSaveable { mutableStateOf("") }
    var scanning by rememberSaveable { mutableStateOf(false) }
    var cameraBlocked by rememberSaveable { mutableStateOf(false) }
    var confirm by remember { mutableStateOf<PairingPayload?>(null) }
    val busy by vm.busy.collectAsState()
    val qr = code.trim().startsWith("{")
    val cameraGranted = { ContextCompat.checkSelfPermission(context, Manifest.permission.CAMERA) == PackageManager.PERMISSION_GRANTED }
    val cameraPermission = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { granted ->
        if (granted) {
            cameraBlocked = false
            scanning = true
        } else {
            // "Don't ask again": the system dialog will not appear any more, so point at app settings instead.
            val activity = context as? android.app.Activity
            cameraBlocked = activity != null && !ActivityCompat.shouldShowRequestPermissionRationale(activity, Manifest.permission.CAMERA)
            if (!cameraBlocked) vm.showMessage("Camera access is needed to scan. You can still enter the server address and code by hand.")
        }
    }
    // A process restart with the permission revoked would otherwise restore a scanner with no camera access.
    if (scanning && !cameraGranted()) scanning = false
    BackHandler(enabled = scanning) { scanning = false }

    confirm?.let { payload ->
        AlertDialog(
            onDismissRequest = { confirm = null },
            title = { Text("Pair with this server?") },
            text = {
                Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    Text(payload.url.removePrefix("https://").removePrefix("http://"), fontWeight = FontWeight.SemiBold)
                    Text(
                        "Recordings and transcripts from this phone will be sent only to this server.",
                        color = Brand.Muted,
                        fontSize = 13.sp
                    )
                }
            },
            confirmButton = {
                Button(onClick = {
                    url = payload.url
                    confirm = null
                    vm.pairWith(payload, name)
                }) { Text("Pair") }
            },
            dismissButton = { TextButton(onClick = { confirm = null }) { Text("Cancel") } }
        )
    }

    Box(Modifier.fillMaxSize()) {
        Column(
            Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(24.dp),
            verticalArrangement = Arrangement.spacedBy(14.dp)
        ) {
            Spacer(Modifier.height(40.dp))
            Box(Modifier.size(44.dp).clip(CircleShape).background(Brand.Gradient))
            Text("Pair this phone", fontSize = 26.sp, fontWeight = FontWeight.Bold)
            Text(
                "In your EchoLedger admin open Devices → Pair a device, then scan the QR code shown there.",
                color = Brand.Muted
            )
            Button(
                onClick = {
                    focusManager.clearFocus()
                    when {
                        cameraGranted() -> scanning = true

                        cameraBlocked -> context.startActivity(
                            Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS, Uri.parse("package:${context.packageName}"))
                        )

                        else -> cameraPermission.launch(Manifest.permission.CAMERA)
                    }
                },
                enabled = !busy,
                modifier = Modifier.fillMaxWidth().height(52.dp)
            ) {
                Icon(Icons.Outlined.QrCodeScanner, contentDescription = null, modifier = Modifier.size(22.dp))
                Spacer(Modifier.width(10.dp))
                Text(if (busy) "Pairing…" else "Scan QR code", fontSize = 16.sp)
            }
            if (cameraBlocked) {
                Text(
                    "Camera access is turned off for EchoLedger. Tap Scan QR code to open the app settings and allow it, or enter the details by hand.",
                    color = Brand.Muted,
                    fontSize = 12.sp
                )
            }
            Text(
                "or enter the server address and one-time code",
                color = Brand.Muted,
                fontSize = 13.sp,
                modifier = Modifier.align(Alignment.CenterHorizontally)
            )
            if (!qr) {
                OutlinedTextField(url, {
                    url = it
                }, label = { Text("Server address") }, singleLine = true, modifier = Modifier.fillMaxWidth())
            }
            OutlinedTextField(code, { code = it }, label = { Text("Pairing code") }, modifier = Modifier.fillMaxWidth())
            OutlinedTextField(name, {
                name = it
            }, label = { Text("Name for this phone (optional)") }, singleLine = true, modifier = Modifier.fillMaxWidth())
            OutlinedButton(onClick = {
                vm.pair(url, code, name)
            }, enabled = !busy && code.isNotBlank(), modifier = Modifier.fillMaxWidth()) {
                Text(if (busy) "Pairing…" else "Pair")
            }
            Text(
                "Your access token is encrypted with the Android Keystore. Speech-provider keys never leave your server.",
                color = Brand.Muted,
                fontSize = 12.sp
            )
        }
        if (scanning) {
            QrScanner(
                accept = { text -> PairingPayload.parse(text) != null },
                onResult = { text ->
                    scanning = false
                    PairingPayload.parse(text)?.let { confirm = it }
                },
                onCancel = { scanning = false },
                onError = { message ->
                    scanning = false
                    vm.showMessage(message)
                }
            )
        }
    }
}

@Composable
fun LibraryScreen(vm: MainViewModel, onOpen: (LibraryItem) -> Unit) {
    val items by vm.library.collectAsState()
    var filter by remember { mutableStateOf<SourceDevice?>(null) }
    var query by remember { mutableStateOf("") }
    val q = query.trim().lowercase()
    val visible = items.filter {
        !it.deleted &&
            (filter == null || SourceDevice.from(it.sourceDevice) == filter) &&
            (
                q.isEmpty() ||
                    listOfNotNull(it.title, it.transcript?.preview, it.client?.name, SourceApp.from(it.sourceApp).label).any { s ->
                        s.lowercase().contains(q)
                    }
                )
    }
    Column(Modifier.fillMaxSize().padding(horizontal = 16.dp)) {
        Spacer(Modifier.height(8.dp))
        ScreenHeader("Library", "Recordings from your phone, computer and the web") {
            IconButton(onClick = { vm.refresh() }) { Icon(Icons.Outlined.Refresh, contentDescription = "Sync", tint = Brand.Muted) }
        }
        OutlinedTextField(
            value = query,
            onValueChange = { query = it },
            placeholder = { Text("Search transcripts and titles", color = Brand.Faint) },
            leadingIcon = { Icon(Icons.Outlined.Search, contentDescription = null, tint = Brand.Faint) },
            singleLine = true,
            shape = RoundedCornerShape(14.dp),
            modifier = Modifier.fillMaxWidth().padding(top = 12.dp)
        )
        Row(Modifier.padding(vertical = 12.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            FilterChip(selected = filter == null, onClick = { filter = null }, label = { Text("All") })
            SourceDevice.entries.forEach { d ->
                FilterChip(selected = filter == d, onClick = {
                    filter = d
                }, label = { Text(d.label) }, leadingIcon = { Icon(d.icon(), null, Modifier.size(16.dp)) })
            }
        }
        if (visible.isEmpty()) {
            EmptyState(
                icon = Icons.AutoMirrored.Outlined.LibraryBooks,
                title = if (q.isNotEmpty() || filter != null) "Nothing matches" else "No recordings yet",
                body = if (q.isNotEmpty() ||
                    filter != null
                ) {
                    "Try a different search or filter."
                } else {
                    "Calls, meetings and voice notes you capture will appear here."
                }
            )
        }
        LazyColumn(verticalArrangement = Arrangement.spacedBy(10.dp)) {
            items(visible, key = { it.id }) { item -> LibraryRow(item) { onOpen(item) } }
            item { Spacer(Modifier.height(24.dp)) }
        }
    }
}

@Composable
private fun LibraryRow(item: LibraryItem, onClick: () -> Unit) {
    SectionCard(Modifier.clickable(onClick = onClick)) {
        Row(horizontalArrangement = Arrangement.spacedBy(12.dp), verticalAlignment = Alignment.CenterVertically) {
            SourceAvatar(SourceDevice.from(item.sourceDevice), SourceApp.from(item.sourceApp))
            Column(Modifier.weight(1f)) {
                Text(item.title ?: "Untitled", fontWeight = FontWeight.SemiBold, fontSize = 15.sp, maxLines = 1)
                Text(
                    listOfNotNull(
                        formatWhen(item.startedAt),
                        formatDuration(item.durationMs).ifEmpty {
                            null
                        },
                        item.client?.name
                    ).joinToString(" · "),
                    color = Brand.Muted,
                    fontSize = 12.sp,
                    maxLines = 1
                )
            }
            StatusDot(item.status, item.transcript?.finalStatus)
        }
        item.transcript?.preview?.let {
            Text(it, color = Brand.Muted, fontSize = 13.sp, maxLines = 2, lineHeight = 18.sp)
        }
        Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
            SourceDeviceBadge(SourceDevice.from(item.sourceDevice), item.sourceDeviceLabel)
            SourceAppBadge(SourceApp.from(item.sourceApp), item.sourceAppLabel)
        }
    }
}

/** A small coloured dot summarising the recording's state. */
@Composable
private fun StatusDot(status: String?, finalStatus: String?) {
    val (color, label) = when {
        finalStatus == "failed" || status == "failed" -> Brand.Danger to "Failed"
        status == "completed" || finalStatus == "completed" -> Brand.Success to "Ready"
        else -> Brand.Warning to "Processing"
    }
    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(5.dp)) {
        Box(Modifier.size(7.dp).clip(CircleShape).background(color))
        Text(label, color = color, fontSize = 11.sp, fontWeight = FontWeight.Medium)
    }
}

@Composable
fun SessionScreen(vm: MainViewModel, item: LibraryItem, onBack: () -> Unit) {
    var transcript by remember { mutableStateOf<TranscriptDto?>(null) }
    var loaded by remember { mutableStateOf(false) }
    var playbackUrl by remember { mutableStateOf<String?>(null) }
    val scope = androidx.compose.runtime.rememberCoroutineScope()
    val clipboard = LocalClipboard.current
    val player = rememberRecordingPlayer(playbackUrl)
    LaunchedEffect(item.id) {
        val id = item.transcript?.finalTranscriptId ?: item.transcript?.liveTranscriptId
        transcript = id?.let { vm.transcript(it) }
        loaded = true
        playbackUrl = vm.playbackUrl(item.id)
    }
    val t = transcript
    val segments = t?.segments.orEmpty()
    // Highlight the segment under the playhead; binary search keeps this cheap for long calls.
    val activeIndex = if (player.playing || player.positionMs > 0) {
        segments.indexOfLast { it.startMs <= player.positionMs }
    } else {
        -1
    }
    fun copy(withTimestamps: Boolean) {
        scope.launch {
            clipboard.setClipEntry(
                ClipData.newPlainText("EchoLedger transcript", TranscriptText.render(segments, withTimestamps)).toClipEntry()
            )
            vm.showMessage(if (withTimestamps) "Copied with timestamps" else "Copied")
        }
    }

    Column(Modifier.fillMaxSize().padding(horizontal = 16.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            IconButton(onClick = onBack) { Icon(Icons.AutoMirrored.Outlined.ArrowBack, contentDescription = "Back") }
            Spacer(Modifier.weight(1f))
            IconButton(onClick = {
                scope.launch {
                    vm.delete(item)
                    onBack()
                }
            }) { Icon(Icons.Outlined.Delete, contentDescription = "Delete", tint = Brand.Danger) }
        }
        LazyColumn(verticalArrangement = Arrangement.spacedBy(6.dp), modifier = Modifier.fillMaxSize()) {
            item {
                Row(horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                    SourceDeviceBadge(SourceDevice.from(item.sourceDevice), item.sourceDeviceLabel)
                    SourceAppBadge(SourceApp.from(item.sourceApp), item.sourceAppLabel)
                }
                Text(item.title ?: "Untitled", fontSize = 22.sp, fontWeight = FontWeight.Bold, modifier = Modifier.padding(top = 10.dp))
                Text(
                    listOf(formatWhen(item.startedAt), formatDuration(item.durationMs), item.client?.name).filter {
                        !it.isNullOrBlank()
                    }.joinToString(" · "),
                    color = Brand.Muted,
                    fontSize = 13.sp
                )
                Spacer(Modifier.height(12.dp))
            }
            if (playbackUrl != null) {
                item { RecordingPlayer(player) }
                item { Spacer(Modifier.height(4.dp)) }
            }
            when {
                !loaded -> item { Text("Loading transcript…", color = Brand.Muted) }

                t == null -> item {
                    val status = item.transcript?.finalStatus
                    Text(
                        when (status) {
                            null -> "No transcript for this recording."
                            "failed" -> "Transcription failed."
                            else -> "Transcript ${status.replace('_', ' ')}. Pull to refresh in a moment."
                        },
                        color = Brand.Muted
                    )
                }

                t.status == "failed" -> item {
                    SectionCard {
                        Text("Transcription failed", fontWeight = FontWeight.SemiBold, color = Brand.Danger)
                        Text(t.error ?: "The speech provider could not process this recording.", color = Brand.Muted, fontSize = 13.sp)
                    }
                }

                segments.isEmpty() -> item { Text("The transcript is empty: no speech was detected.", color = Brand.Muted) }

                else -> {
                    item {
                        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            Text(
                                if (t.kind == "final") "Final transcript · ${t.provider}" else "Live captions (final pending)",
                                color = Brand.Muted,
                                fontSize = 12.sp,
                                modifier = Modifier.weight(1f)
                            )
                        }
                        Row(horizontalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.padding(vertical = 6.dp)) {
                            OutlinedButton(onClick = { copy(withTimestamps = false) }) {
                                Icon(Icons.Outlined.ContentCopy, contentDescription = null, modifier = Modifier.size(16.dp))
                                Spacer(Modifier.width(6.dp))
                                Text("Copy text")
                            }
                            OutlinedButton(onClick = { copy(withTimestamps = true) }) {
                                Icon(Icons.Outlined.Schedule, contentDescription = null, modifier = Modifier.size(16.dp))
                                Spacer(Modifier.width(6.dp))
                                Text("Copy with timestamps")
                            }
                        }
                    }
                    itemsIndexed(segments) { index, s ->
                        val active = index == activeIndex
                        Row(
                            Modifier
                                .fillMaxWidth()
                                .clip(RoundedCornerShape(8.dp))
                                .background(
                                    if (active) Brand.Violet.copy(alpha = 0.18f) else androidx.compose.ui.graphics.Color.Transparent
                                )
                                .clickable(enabled = player.ready) { player.seekTo(s.startMs) }
                                .padding(horizontal = 6.dp, vertical = 4.dp)
                        ) {
                            Text(TranscriptText.stamp(s.startMs), color = Brand.Muted, fontSize = 12.sp, modifier = Modifier.width(52.dp))
                            Text(
                                s.speaker,
                                color = if (s.channel == 2) Brand.Cyan else androidx.compose.ui.graphics.Color(0xFFA78BFA),
                                fontWeight = FontWeight.SemiBold,
                                fontSize = 12.sp,
                                modifier = Modifier.width(60.dp)
                            )
                            Text(s.text, fontSize = 14.sp)
                        }
                    }
                }
            }
            item { Spacer(Modifier.height(24.dp)) }
        }
    }
}

@Composable
fun RecordScreen(vm: MainViewModel, capabilities: Capabilities, onSetup: () -> Unit) {
    val context = LocalContext.current
    val settings by vm.settings.collectAsState()
    val pending by vm.pending.collectAsState()
    var recording by remember { mutableStateOf(RecordingService.isRecording) }
    LaunchedEffect(Unit) {
        vm.reloadLocal()
        while (true) {
            recording = RecordingService.isRecording
            kotlinx.coroutines.delay(1_000)
        }
    }
    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Text("Record", fontSize = 24.sp, fontWeight = FontWeight.Bold)
        SectionCard {
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(16.dp)) {
                Box(
                    Modifier.size(
                        84.dp
                    ).clip(
                        CircleShape
                    ).background(
                        if (recording) {
                            androidx.compose.ui.graphics.Brush.linearGradient(
                                listOf(Brand.Danger, Brand.Danger)
                            )
                        } else {
                            Brand.Gradient
                        }
                    )
                        .clickable { context.startForegroundService(RecordingService.intent(context, RecordingService.ACTION_TOGGLE)) },
                    contentAlignment = Alignment.Center
                ) { Text(if (recording) "STOP" else "REC", fontWeight = FontWeight.Bold) }
                Column {
                    Text(if (recording) "Recording…" else "Not recording", fontWeight = FontWeight.SemiBold)
                    Text(
                        if (capabilities.canCaptureCalls) {
                            "Both sides of calls (Me + Client)"
                        } else {
                            "Microphone only — enable call capture in Setup"
                        },
                        color = Brand.Muted,
                        fontSize = 13.sp
                    )
                }
            }
        }
        SectionCard {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text("Auto-record WhatsApp calls", fontWeight = FontWeight.SemiBold)
                    Text(
                        when {
                            !capabilities.callCaptureSupportedByOs -> "Requires Android 14 or later"
                            !capabilities.helperConnected -> "Enable call capture in Setup first"
                            else -> "You'll be asked after each call whether to keep it"
                        },
                        color = Brand.Muted,
                        fontSize = 13.sp
                    )
                }
                Switch(
                    checked = settings.autoRecordArmed,
                    enabled = capabilities.canCaptureCalls,
                    onCheckedChange = { on ->
                        context.startForegroundService(
                            RecordingService.intent(context, if (on) RecordingService.ACTION_ARM else RecordingService.ACTION_DISARM)
                        )
                    }
                )
            }
            if (!capabilities.helperConnected) TextButton(onClick = onSetup) { Text("Open setup") }
        }
        if (pending.any { it.state == "pending_decision" }) {
            SectionCard {
                Text("Save these recordings?", fontWeight = FontWeight.SemiBold)
                pending.filter { it.state == "pending_decision" }.forEach { rec ->
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Column(Modifier.weight(1f)) {
                            Text("${SourceApp.from(rec.sourceApp).label} · ${formatDuration(rec.durationMs)}")
                            Text(formatWhen(rec.startedAt), color = Brand.Muted, fontSize = 12.sp)
                        }
                        TextButton(onClick = { vm.decide(rec.id, keep = false) }) { Text("Discard", color = Brand.Danger) }
                        Button(onClick = { vm.decide(rec.id, keep = true) }) { Text("Keep") }
                    }
                }
            }
        }
        val queued = pending.filter { it.state in setOf("queued", "uploading") }
        if (queued.isNotEmpty()) {
            SectionCard {
                Text("Uploading ${queued.size} recording(s)…", fontWeight = FontWeight.SemiBold)
                queued.mapNotNull { it.lastError }.firstOrNull()?.let { Text("Will retry: $it", color = Brand.Muted, fontSize = 12.sp) }
            }
        }
        Text(
            "Voice notes are never recorded automatically — share them to EchoLedger from WhatsApp.",
            color = Brand.Muted,
            fontSize = 12.sp
        )
    }
}

@Composable
fun SetupScreen(vm: MainViewModel, capabilities: Capabilities, onBack: () -> Unit, onRecheck: () -> Unit) {
    val context = LocalContext.current
    val clipboard = LocalClipboard.current
    val scope = androidx.compose.runtime.rememberCoroutineScope()
    val helper by vm.helper.collectAsState()
    val settings by vm.settings.collectAsState()
    val micLauncher = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { onRecheck() }
    val notifLauncher = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) { onRecheck() }
    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            IconButton(onClick = onBack) { Icon(Icons.AutoMirrored.Outlined.ArrowBack, contentDescription = "Back") }
            Text("Setup", fontSize = 24.sp, fontWeight = FontWeight.Bold)
        }
        SectionCard {
            Text("1 · Permissions", fontWeight = FontWeight.SemiBold)
            StepRow("Microphone", capabilities.micPermission) { micLauncher.launch(Manifest.permission.RECORD_AUDIO) }
            if (Build.VERSION.SDK_INT >=
                33
            ) {
                StepRow("Notifications (Stop / Keep / Discard actions)", capabilities.notificationPermission) {
                    notifLauncher.launch(Manifest.permission.POST_NOTIFICATIONS)
                }
            }
            StepRow("Caption bubble (optional)", capabilities.overlayPermission) {
                context.startActivity(Intent(Settings.ACTION_MANAGE_OVERLAY_PERMISSION, Uri.parse("package:${context.packageName}")))
            }
        }
        SectionCard {
            Text("2 · Call capture (Android 14+)", fontWeight = FontWeight.SemiBold)
            if (!capabilities.callCaptureSupportedByOs) {
                Text(
                    "This phone runs Android ${Build.VERSION.RELEASE}. Recording the other side of WhatsApp calls needs Android 14 or later.",
                    color = Brand.Muted,
                    fontSize = 13.sp
                )
            } else {
                Text(
                    if (helper.connected) {
                        "Capture helper is running (${helper.source}, uid ${helper.helperUid})."
                    } else {
                        helper.error
                            ?: "Not enabled yet."
                    },
                    color = if (helper.connected) Brand.Success else Brand.Muted,
                    fontSize = 13.sp
                )
                Text(
                    "Option A — Shizuku (recommended): install Shizuku, start it with Wireless debugging (one-time 6-digit pairing), then:",
                    fontSize = 13.sp
                )
                OutlinedButton(onClick = { vm.connectShizuku() }) { Text("Connect via Shizuku") }
                Text("Option B — computer with ADB: enable USB or Wireless debugging and run once after every reboot:", fontSize = 13.sp)
                Text(vm.adbCommand(), fontSize = 11.sp, color = Brand.Cyan, modifier = Modifier.background(Brand.SurfaceAlt).padding(8.dp))
                TextButton(onClick = {
                    scope.launch { clipboard.setClipEntry(ClipData.newPlainText("adb", vm.adbCommand()).toClipEntry()) }
                }) { Text("Copy command") }
                Text(
                    "Keep Wireless/USB debugging enabled. After a reboot, re-start Shizuku (or run the command again); the 6-digit code is not needed again.",
                    color = Brand.Muted,
                    fontSize = 12.sp
                )
            }
        }
        SectionCard {
            Text("3 · Keep EchoLedger alive (Samsung / OEMs)", fontWeight = FontWeight.SemiBold)
            Text(
                "Settings → Apps → EchoLedger → Battery → Unrestricted. On Samsung also remove it from Sleeping apps. See dontkillmyapp.com for your phone.",
                color = Brand.Muted,
                fontSize = 13.sp
            )
            OutlinedButton(onClick = {
                context.startActivity(Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS, Uri.parse("package:${context.packageName}")))
            }) { Text("Open app settings") }
        }
        SectionCard {
            Text("4 · Consent", fontWeight = FontWeight.SemiBold)
            Text(
                "Call-recording laws differ by country. Tell participants you are recording where required. A recording notification is always shown.",
                color = Brand.Muted,
                fontSize = 13.sp
            )
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text("I understand", modifier = Modifier.weight(1f))
                Switch(checked = settings.consentAcknowledged, onCheckedChange = { v ->
                    vm.updateSettings { it.copy(consentAcknowledged = v) }
                })
            }
        }
    }
}

@Composable
private fun StepRow(title: String, done: Boolean, onFix: () -> Unit) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        Text(
            (if (done) "✓  " else "•  ") + title,
            color = if (done) Brand.Success else Brand.Text,
            modifier = Modifier.weight(1f),
            fontSize = 14.sp
        )
        if (!done) TextButton(onClick = onFix) { Text("Allow") }
    }
}

@Composable
fun SettingsScreen(vm: MainViewModel, onSetup: () -> Unit) {
    val settings by vm.settings.collectAsState()
    val clients by vm.clients.collectAsState()
    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Text("Settings", fontSize = 24.sp, fontWeight = FontWeight.Bold)
        SectionCard {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text("Live captions while recording")
                    Text("Floating bubble; needs the overlay permission", color = Brand.Muted, fontSize = 12.sp)
                }
                Switch(checked = settings.captionsEnabled, onCheckedChange = { v -> vm.updateSettings { it.copy(captionsEnabled = v) } })
            }
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text("Near-end microphone source")
                    Text(
                        if (settings.micSource ==
                            "mic"
                        ) {
                            "Raw microphone"
                        } else {
                            "Voice communication (echo-cancelled)"
                        },
                        color = Brand.Muted,
                        fontSize = 12.sp
                    )
                }
                TextButton(onClick = {
                    vm.updateSettings {
                        it.copy(
                            micSource = if (it.micSource ==
                                "mic"
                            ) {
                                "voice_communication"
                            } else {
                                "mic"
                            }
                        )
                    }
                }) { Text("Change") }
            }
        }
        val activity = LocalContext.current as? androidx.fragment.app.FragmentActivity
        if (activity != null && biometricAvailable(activity)) {
            SectionCard {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Column(Modifier.weight(1f)) {
                        Text("App lock", fontWeight = FontWeight.SemiBold)
                        Text("Require fingerprint, face or PIN to open EchoLedger", color = Brand.Muted, fontSize = 12.sp)
                    }
                    Switch(checked = settings.appLockEnabled, onCheckedChange = { v -> vm.updateSettings { it.copy(appLockEnabled = v) } })
                }
            }
        }
        SectionCard {
            Text("Client keyterms", fontWeight = FontWeight.SemiBold)
            Text("Names and jargon of the selected client improve accuracy.", color = Brand.Muted, fontSize = 12.sp)
            (listOf(null) + clients).forEach { client ->
                Row(Modifier.fillMaxWidth().clickable { vm.updateSettings { it.copy(clientId = client?.id) } }.padding(vertical = 6.dp)) {
                    Text(if (settings.clientId == client?.id) "●  " else "○  ", color = Brand.Violet)
                    Text(client?.name ?: "No client")
                }
            }
        }
        SectionCard {
            Text("Device", fontWeight = FontWeight.SemiBold)
            Text("${settings.deviceName ?: ""} · ${settings.serverUrl ?: ""}", color = Brand.Muted, fontSize = 13.sp)
            Text(
                if (settings.pins.isNotEmpty()) {
                    "Server certificate pinned (${settings.pins.size} pins)"
                } else {
                    "Certificate pinning not configured on the server"
                },
                color = Brand.Muted,
                fontSize = 12.sp
            )
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedButton(onClick = onSetup) { Text("Setup guide") }
                Button(onClick = { vm.unpair() }, colors = ButtonDefaults.buttonColors(containerColor = Brand.Danger)) { Text("Unpair") }
            }
        }
    }
}

fun Context.capabilities(helperConnected: Boolean): Capabilities = Capabilities(
    sdkInt = Build.VERSION.SDK_INT,
    helperConnected = helperConnected,
    micPermission = checkSelfPermission(Manifest.permission.RECORD_AUDIO) == android.content.pm.PackageManager.PERMISSION_GRANTED,
    notificationPermission =
        Build.VERSION.SDK_INT < 33 ||
            checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS) == android.content.pm.PackageManager.PERMISSION_GRANTED,
    overlayPermission = Settings.canDrawOverlays(this)
)
