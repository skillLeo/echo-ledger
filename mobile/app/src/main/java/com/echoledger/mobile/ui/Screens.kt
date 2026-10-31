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
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.combinedClickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.ArrowBack
import androidx.compose.material.icons.automirrored.outlined.LibraryBooks
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.outlined.Checklist
import androidx.compose.material.icons.outlined.Close
import androidx.compose.material.icons.outlined.ContentCopy
import androidx.compose.material.icons.outlined.Delete
import androidx.compose.material.icons.outlined.DeleteOutline
import androidx.compose.material.icons.outlined.Notifications
import androidx.compose.material.icons.outlined.QrCodeScanner
import androidx.compose.material.icons.outlined.Refresh
import androidx.compose.material.icons.outlined.Schedule
import androidx.compose.material.icons.outlined.Search
import androidx.compose.material.icons.rounded.Cancel
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.ContentCopy
import androidx.compose.material.icons.rounded.MoreVert
import androidx.compose.material.icons.rounded.RadioButtonUnchecked
import androidx.compose.material.icons.rounded.RemoveCircleOutline
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Badge
import androidx.compose.material3.BadgedBox
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.FilterChip
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.RadioButton
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.derivedStateOf
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.hapticfeedback.HapticFeedbackType
import androidx.compose.ui.platform.LocalClipboard
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalFocusManager
import androidx.compose.ui.platform.LocalHapticFeedback
import androidx.compose.ui.platform.toClipEntry
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.selected
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.semantics.stateDescription
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.app.ActivityCompat
import androidx.core.content.ContextCompat
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.compose.LifecycleEventEffect
import com.echoledger.mobile.core.Bookmarks
import com.echoledger.mobile.core.ItemStatus
import com.echoledger.mobile.core.LibraryFilters
import com.echoledger.mobile.core.LibraryQuery
import com.echoledger.mobile.core.LibrarySort
import com.echoledger.mobile.core.Organise
import com.echoledger.mobile.core.PairingPayload
import com.echoledger.mobile.core.SmartView
import com.echoledger.mobile.core.SourceApp
import com.echoledger.mobile.core.SourceDevice
import com.echoledger.mobile.core.TranscriptText
import com.echoledger.mobile.data.AppSettings
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.SearchItemDto
import com.echoledger.mobile.data.SessionMarkerDto
import com.echoledger.mobile.data.TranscriptDto
import com.echoledger.mobile.data.TranscriptViewsDto
import com.echoledger.mobile.privilege.Capabilities
import com.echoledger.mobile.recording.RecordingService
import com.echoledger.mobile.ui.design.BannerKind
import com.echoledger.mobile.ui.design.BarIcon
import com.echoledger.mobile.ui.design.ButtonKind
import com.echoledger.mobile.ui.design.EchoButton
import com.echoledger.mobile.ui.design.EchoTopBar
import com.echoledger.mobile.ui.design.InlineBanner
import com.echoledger.mobile.ui.design.sharedElement
import com.echoledger.mobile.ui.design.tabular
import kotlinx.coroutines.delay
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
fun SessionScreen(vm: MainViewModel, item: LibraryItem, onBack: () -> Unit, startAtMs: Long? = null, onCaptureTest: () -> Unit = {}) {
    var transcript by remember { mutableStateOf<TranscriptDto?>(null) }
    var loaded by remember { mutableStateOf(false) }
    var loadFailed by remember { mutableStateOf(false) }
    var attempt by remember { mutableStateOf(0) }
    var playbackUrl by remember { mutableStateOf<String?>(null) }
    var bookmarks by remember { mutableStateOf<List<SessionMarkerDto>>(emptyList()) }
    var speakerNames by remember { mutableStateOf<Map<String, String>>(emptyMap()) }
    var pendingSeek by remember { mutableStateOf(startAtMs) }
    val scope = androidx.compose.runtime.rememberCoroutineScope()
    val clipboard = LocalClipboard.current
    val player = rememberRecordingPlayer(playbackUrl)
    // The newest version is shown first; the previous one (after Re-transcribe as) is one tap away.
    var version by remember(item.id) { mutableStateOf(TranscriptVersion.CURRENT) }
    // The recording's details are read once per load; switching the version reads only the other transcript.
    var detailFor by remember { mutableStateOf<Pair<String, Int>?>(null) }
    // Opening the page counts as opening the recording (the unopened dot goes away here and on the server).
    LaunchedEffect(item.id) { vm.markOpened(item) }
    LaunchedEffect(item.id, attempt, version) {
        val previous = previousVersionId(item).takeIf { version == TranscriptVersion.PREVIOUS }
        val id = previous ?: item.transcript?.finalTranscriptId ?: item.transcript?.liveTranscriptId
        transcript = id?.let { vm.transcript(it) }
        // Only a finished transcript that does not arrive is a load failure; otherwise its status says more.
        loadFailed = id != null && transcript == null && (previous != null || item.transcript?.finalStatus == "completed")
        loaded = true
        if (detailFor == item.id to attempt) return@LaunchedEffect
        detailFor = item.id to attempt
        val detail = vm.sessionDetail(item.id)
        playbackUrl = detail?.playbackUrl
        bookmarks = detail?.markers.orEmpty().sortedBy { it.atMs }
        speakerNames = detail?.speakerNames.orEmpty()
    }
    // Opened from a search match: play from that moment once the recording is ready.
    LaunchedEffect(player.ready, pendingSeek) {
        val target = pendingSeek
        if (player.ready && target != null) {
            player.seekTo(target)
            pendingSeek = null
        }
    }
    val t = transcript
    // Nothing was heard: no player, no sharing, no failure card; say what to do and offer delete in one tap.
    val noAudio = ItemStatus.of(item) == ItemStatus.NO_AUDIO || t?.errorCode == "no_audio"
    val segments = t?.segments.orEmpty()
    val speakerList = segments.map { it.speaker }
    // Highlight the segment under the playhead; binary search keeps this cheap for long calls.
    val activeIndex = if (player.playing || player.positionMs > 0) {
        segments.indexOfLast { it.startMs <= player.positionMs }
    } else {
        -1
    }
    // Show as (since v1.2): the Roman Urdu or English view of the transcript, once the server has written it.
    var showAs by remember(item.id) { mutableStateOf(ShowAs.ORIGINAL) }
    var views by remember(item.id) { mutableStateOf<TranscriptViewsDto?>(null) }
    LaunchedEffect(showAs, views?.status, t?.id) {
        val id = t?.id ?: return@LaunchedEffect
        if (showAs != ShowAs.ORIGINAL && views?.status == "making") {
            delay(ShowAs.POLL_MS)
            views = vm.transcriptViews(id) ?: views
        }
    }
    fun chooseView(view: ShowAs) {
        showAs = view
        val id = t?.id ?: return
        if (view == ShowAs.ORIGINAL || views?.status == "ready") return
        scope.launch {
            var state = vm.transcriptViews(id)
            if (state == null || state.status == "none" || state.status == "failed") state = vm.makeTranscriptViews(id) ?: state
            views = state
            if (state == null) showAs = ShowAs.ORIGINAL
        }
    }
    // Open in the default view (console setting) when it is made for this recording.
    LaunchedEffect(t?.id) {
        val summary = t?.views ?: return@LaunchedEffect
        val default = ShowAs.of(summary.default)
        if (summary.status == "ready" && default != ShowAs.ORIGINAL && default.key in summary.available) chooseView(default)
    }
    val shown = ShowAs.lines(segments, showAs, views)
    val appSettings by vm.settings.collectAsState()

    // Unsure words go into the copy as "[?word]" (or the console's style); Copy for AI starts with the note (plan A4).
    fun copy(withTimestamps: Boolean, forAi: Boolean = false) {
        scope.launch {
            val text = TranscriptText.copied(
                segments,
                { i -> shown?.getOrNull(i) },
                withTimestamps,
                appSettings.unsureStyle,
                appSettings.unsureThreshold,
                appSettings.unsureNote,
                forAi
            )
            clipboard.setClipEntry(ClipData.newPlainText("EchoLedger transcript", text).toClipEntry())
            vm.showMessage(
                when {
                    forAi -> "Copied for AI, with the note on unsure words"
                    withTimestamps -> "Copied with timestamps"
                    else -> "Copied"
                }
            )
        }
    }

    // The page (since v1.3): top bar (back, Copy, more), the title with its source, the transcript as the hero with
    // speaker blocks, the tools, and the player docked at the bottom.
    val c = LocalEchoColors.current
    val type = LocalEchoType.current
    var confirmDelete by remember { mutableStateOf(false) }
    var menuOpen by remember { mutableStateOf(false) }
    val clientList by vm.clients.collectAsState()
    val library by vm.library.collectAsState()
    val live = library.firstOrNull { it.id == item.id } ?: item
    val canCopy = t != null && t.status !in IN_PROGRESS && t.status != "failed" && segments.isNotEmpty()
    val listState = rememberLazyListState()
    // Find in transcript (since v1.3): matches in the view on screen; the current one is tinted and scrolled to.
    var finding by remember(item.id) { mutableStateOf(false) }
    var findQuery by remember(item.id) { mutableStateOf("") }
    var findAt by remember(item.id) { mutableStateOf(0) }
    val findHits = remember(findQuery, shown, segments) {
        TranscriptFind.matches(segments.mapIndexed { i, s -> shown?.getOrNull(i) ?: s.text }, findQuery)
    }
    val findLine = findHits.getOrNull(findAt.coerceIn(0, (findHits.size - 1).coerceAtLeast(0)))
    LaunchedEffect(findLine) {
        // The transcript lines come after the page head and the transcript head.
        if (findLine != null) listState.animateScrollToItem(findLine + 2)
    }
    var lineMenu by remember(item.id) { mutableStateOf<Int?>(null) }
    val haptics = LocalHapticFeedback.current
    val context = LocalContext.current
    // The title moves into the bar once the page head has scrolled away (a collapsing header).
    val scrolled by remember { derivedStateOf { listState.firstVisibleItemIndex > 0 } }
    Column(Modifier.fillMaxSize()) {
        EchoTopBar(
            if (scrolled) item.title ?: "Untitled" else "",
            large = false,
            navigation = { BarIcon(Icons.AutoMirrored.Rounded.ArrowBack, "Back", onBack, tint = c.text) },
            actions = {
                if (canCopy && !finding) BarIcon(Icons.Rounded.Search, "Find in transcript", { finding = true })
                if (canCopy) BarIcon(Icons.Rounded.ContentCopy, "Copy", { copy(withTimestamps = false) })
                Box {
                    BarIcon(Icons.Rounded.MoreVert, "More", { menuOpen = true })
                    DropdownMenu(expanded = menuOpen, onDismissRequest = { menuOpen = false }) {
                        fun pick(action: () -> Unit) {
                            menuOpen = false
                            action()
                        }
                        if (canCopy) {
                            DropdownMenuItem(text = {
                                Text("Copy for AI")
                            }, onClick = { pick { copy(withTimestamps = false, forAi = true) } })
                        }
                        DropdownMenuItem(text = { Text(if (live.pinned) "Unpin" else "Pin") }, onClick = {
                            pick { scope.launch { vm.organise(item.id, pinned = !live.pinned) } }
                        })
                        DropdownMenuItem(text = { Text(if (live.keep) "Stop keeping" else "Keep forever") }, onClick = {
                            pick { scope.launch { vm.organise(item.id, keep = !live.keep) } }
                        })
                        if (playbackUrl != null && !noAudio) {
                            DropdownMenuItem(text = { Text(if (player.skipSilence) "Play silences" else "Skip silences") }, onClick = {
                                pick { player.changeSkipSilence(!player.skipSilence) }
                            })
                        }
                        DropdownMenuItem(text = { Text("Delete", color = c.danger) }, onClick = { pick { confirmDelete = true } })
                    }
                }
            }
        )
        if (confirmDelete) {
            ConfirmDialog(
                text = "Delete this recording on every device? You can restore it from Recently deleted for 48 hours.",
                confirmLabel = "Delete",
                onConfirm = {
                    confirmDelete = false
                    scope.launch {
                        vm.delete(item)
                        onBack()
                    }
                },
                onDismiss = { confirmDelete = false }
            )
        }
        if (finding && canCopy) {
            FindBar(
                findQuery,
                {
                    findQuery = it
                    findAt = 0
                },
                current = findAt,
                total = findHits.size,
                onStep = { step -> if (findHits.isNotEmpty()) findAt = Math.floorMod(findAt + step, findHits.size) },
                onClose = {
                    finding = false
                    findQuery = ""
                }
            )
        }
        LazyColumn(Modifier.weight(1f), state = listState, contentPadding = PaddingValues(bottom = Space.s5)) {
            item(key = "head") {
                Column(Modifier.padding(horizontal = Space.screen)) {
                    Text(
                        item.title ?: "Untitled",
                        style = type.titleL,
                        color = c.text,
                        modifier = Modifier.sharedElement("title-${item.id}")
                    )
                    Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.padding(top = Space.s2)) {
                        val app = SourceApp.from(item.sourceApp)
                        val device = SourceDevice.from(item.sourceDevice)
                        Box(Modifier.sharedElement("tile-${item.id}")) { com.echoledger.mobile.ui.design.SourceAppTile(app, size = 20.dp) }
                        Spacer(Modifier.width(Space.s2))
                        Text(item.sourceAppLabel ?: app.label, style = type.labelM, color = c.textMuted)
                        Text("  ·  ", style = type.labelM, color = c.textFaint)
                        Icon(device.icon(), contentDescription = null, tint = c.textMuted, modifier = Modifier.size(14.dp))
                        Spacer(Modifier.width(Space.s1))
                        Text(item.sourceDeviceLabel ?: device.label, style = type.labelM, color = c.textMuted)
                    }
                    Text(
                        listOf(formatWhen(item.startedAt), formatDuration(item.durationMs), item.client?.name)
                            .filter { !it.isNullOrBlank() }
                            .joinToString(" · "),
                        style = type.labelM.tabular(),
                        color = c.textMuted,
                        modifier = Modifier.padding(top = Space.s1)
                    )
                    if (bookmarks.isNotEmpty()) {
                        Row(
                            Modifier.padding(top = Space.s3).horizontalScroll(rememberScrollState()),
                            horizontalArrangement = Arrangement.spacedBy(Space.s2)
                        ) {
                            bookmarks.forEach { b ->
                                com.echoledger.mobile.ui.design.EchoFilterChip(
                                    listOfNotNull(formatDuration(b.atMs), b.tag).joinToString(" "),
                                    active = false,
                                    onClick = { if (player.ready) player.seekTo(b.atMs) }
                                )
                            }
                        }
                    }
                    Spacer(Modifier.height(Space.s4))
                }
            }
            if (noAudio) {
                item(key = "no-audio") {
                    SectionCard(Modifier.padding(horizontal = Space.screen)) {
                        Text("No audio", style = type.bodyL, color = c.textMuted)
                        Text(
                            "Nothing was heard in this recording, so there is nothing to play or transcribe. " +
                                "Run the capture test to find out why, then delete it.",
                            style = type.bodyM,
                            color = c.textMuted
                        )
                        Row(horizontalArrangement = Arrangement.spacedBy(Space.s2)) {
                            EchoButton("Run capture test", onCaptureTest)
                            EchoButton("Delete", {
                                scope.launch {
                                    vm.delete(item)
                                    onBack()
                                }
                            }, kind = ButtonKind.Secondary)
                        }
                    }
                }
            }
            when {
                noAudio -> Unit

                !loaded -> item(key = "loading") {
                    Row(Modifier.padding(horizontal = Space.screen), verticalAlignment = Alignment.CenterVertically) {
                        com.echoledger.mobile.ui.design.Spinner(16.dp)
                        Spacer(Modifier.width(Space.s2))
                        Text("Loading transcript…", style = type.bodyM, color = c.textMuted)
                    }
                }

                t == null && loadFailed -> item(key = "load-failed") {
                    InlineBanner(
                        "Couldn't load the transcript. Check the connection and try again.",
                        Modifier.padding(horizontal = Space.screen),
                        kind = BannerKind.Error,
                        action = "Retry",
                        onAction = {
                            loaded = false
                            attempt++
                        }
                    )
                }

                t == null -> item(key = "no-transcript") {
                    val status = item.transcript?.finalStatus
                    Text(
                        when (status) {
                            null -> "No transcript for this recording."
                            "failed" -> "Transcription failed."
                            else -> TranscriptText.transcribing(item.transcript?.finalProgress)
                        },
                        style = type.bodyM,
                        color = c.textMuted,
                        modifier = Modifier.padding(horizontal = Space.screen)
                    )
                }

                // One compact row with the action (since v1.3): the server starts again with the best engine.
                t.status == "failed" -> item(key = "failed") {
                    val retryScope = rememberCoroutineScope()
                    var retrying by remember { mutableStateOf(false) }
                    InlineBanner(
                        "Transcription failed. ${failureText(t)}",
                        Modifier.padding(horizontal = Space.screen),
                        kind = BannerKind.Error,
                        action = if (t.errorCode == "no_audio") {
                            null
                        } else if (retrying) {
                            "Retrying…"
                        } else {
                            "Retry"
                        },
                        onAction = {
                            if (!retrying) {
                                retrying = true
                                retryScope.launch {
                                    vm.retranscribe(item.id, t.language)
                                    retrying = false
                                }
                            }
                        }
                    )
                }

                t.status in IN_PROGRESS -> item(key = "progress") {
                    Row(Modifier.padding(horizontal = Space.screen), verticalAlignment = Alignment.CenterVertically) {
                        com.echoledger.mobile.ui.design.Spinner(16.dp)
                        Spacer(Modifier.width(Space.s2))
                        Text(
                            TranscriptText.transcribing(t.progress ?: item.transcript?.finalProgress),
                            style = type.bodyM,
                            color = c.textMuted
                        )
                    }
                }

                segments.isEmpty() -> item(key = "empty") {
                    Text(
                        "The transcript is empty: no speech was detected.",
                        style = type.bodyM,
                        color = c.textMuted,
                        modifier = Modifier.padding(horizontal = Space.screen)
                    )
                }

                else -> {
                    item(key = "transcript-head") {
                        Column(Modifier.padding(horizontal = Space.screen), verticalArrangement = Arrangement.spacedBy(Space.s3)) {
                            val available = t.views?.available.orEmpty()
                            if (t.kind == "final" && t.status == "completed" && available.isNotEmpty()) {
                                ShowAsRow(available, showAs, views) { chooseView(it) }
                            }
                            Row(horizontalArrangement = Arrangement.spacedBy(Space.s2)) {
                                EchoButton("Copy text", {
                                    copy(withTimestamps = false)
                                }, kind = ButtonKind.Secondary, icon = Icons.Rounded.ContentCopy)
                                EchoButton("Copy with timestamps", { copy(withTimestamps = true) }, kind = ButtonKind.Secondary)
                            }
                            EchoButton("Copy for AI (marks unsure words)", {
                                copy(withTimestamps = false, forAi = true)
                            }, kind = ButtonKind.Text)
                            Text(
                                if (t.kind ==
                                    "final"
                                ) {
                                    "Final transcript · ${providerName(t.provider)}"
                                } else {
                                    "Live captions (final pending)"
                                },
                                style = type.labelM,
                                color = c.textMuted
                            )
                        }
                    }
                    itemsIndexed(segments, key = { i, _ -> "s-$i" }, contentType = { _, _ -> "line" }) { index, s ->
                        val active = index == activeIndex
                        val newTurn = !continuesTurn(speakerList, index)
                        val name = speakerName(speakerNames, s.speaker).let { if (it == "Me") "You" else it }
                        val lineText = shown?.getOrNull(index) ?: s.text
                        Column(
                            Modifier
                                .fillMaxWidth()
                                .padding(horizontal = Space.s2)
                                .clip(RoundedCornerShape(Radius.field))
                                .background(
                                    when {
                                        finding && index == findLine -> c.warningFill.copy(alpha = if (c.isDark) 0.18f else 0.24f)
                                        active -> c.accentSoft
                                        else -> androidx.compose.ui.graphics.Color.Transparent
                                    }
                                )
                                .combinedClickable(
                                    onClick = { if (player.ready) player.seekTo(s.startMs) },
                                    onLongClick = {
                                        haptics.performHapticFeedback(HapticFeedbackType.LongPress)
                                        lineMenu = index
                                    },
                                    onLongClickLabel = "Line actions"
                                )
                                .padding(horizontal = Space.s2, vertical = Space.s1)
                        ) {
                            // Long-press a line (since v1.3): copy it, share it, or play from it.
                            DropdownMenu(expanded = lineMenu == index, onDismissRequest = { lineMenu = null }) {
                                DropdownMenuItem(text = { Text("Copy line") }, onClick = {
                                    lineMenu = null
                                    scope.launch {
                                        clipboard.setClipEntry(ClipData.newPlainText("EchoLedger line", "$name: $lineText").toClipEntry())
                                        vm.showMessage("Line copied")
                                    }
                                })
                                DropdownMenuItem(text = { Text("Share line") }, onClick = {
                                    lineMenu = null
                                    val send = Intent(
                                        Intent.ACTION_SEND
                                    ).setType("text/plain").putExtra(Intent.EXTRA_TEXT, "$name: $lineText")
                                    context.startActivity(Intent.createChooser(send, "Share line"))
                                })
                                if (player.ready) {
                                    DropdownMenuItem(text = { Text("Play from here") }, onClick = {
                                        lineMenu = null
                                        player.seekTo(s.startMs)
                                    })
                                }
                            }
                            if (newTurn) {
                                Row(
                                    verticalAlignment = Alignment.CenterVertically,
                                    modifier = Modifier.padding(top = Space.s3, bottom = Space.s1)
                                ) {
                                    com.echoledger.mobile.ui.design.Avatar(name, size = 28.dp)
                                    Spacer(Modifier.width(Space.s2))
                                    Text(
                                        name,
                                        style = type.labelM.copy(fontWeight = FontWeight.SemiBold),
                                        color = if (s.channel ==
                                            2
                                        ) {
                                            c.client
                                        } else {
                                            c.me
                                        }
                                    )
                                    Spacer(Modifier.width(Space.s2))
                                    Text(TranscriptText.stamp(s.startMs), style = type.caption.tabular(), color = c.textFaint)
                                }
                            }
                            val spoken = if (active && s.words.isNotEmpty()) s.words.indexOfLast { it.startMs <= player.positionMs } else -1
                            val lineLanguage = when (showAs) {
                                ShowAs.ORIGINAL -> t.language
                                ShowAs.URDU -> "ur"
                                else -> "en"
                            }
                            Box(Modifier.padding(start = 36.dp)) {
                                TranscriptLine(
                                    s,
                                    spoken,
                                    enabled = player.ready,
                                    override = shown?.getOrNull(index),
                                    language = lineLanguage,
                                    keptOriginal = shown != null && index in views?.kept?.get(showAs.key).orEmpty()
                                ) {
                                    player.seekTo(it)
                                }
                            }
                        }
                    }
                }
            }
            // The tools come after the transcript: reading it is the main job of this page.
            if (!noAudio) {
                item(key = "tools") {
                    Column(
                        Modifier.padding(horizontal = Space.screen).padding(top = Space.s5),
                        verticalArrangement = Arrangement.spacedBy(Space.s3)
                    ) {
                        ExportShareRow(
                            vm,
                            item.id,
                            t?.id?.takeIf { t.status == "completed" && t.kind == "final" },
                            hasAudio = playbackUrl != null,
                            view = showAs.key.takeIf { shown != null }
                        )
                        if (item.transcript?.finalStatus != null) RetranscribeRow(vm, item, version) { version = it }
                    }
                }
            }
            item(key = "organise") {
                Box(Modifier.padding(horizontal = Space.screen).padding(top = Space.s3)) {
                    OrganiseSection(
                        live,
                        Organise.folders(library, clientList),
                        Organise.labels(library),
                        onPin = { scope.launch { vm.organise(item.id, pinned = it) } },
                        onMove = { id -> scope.launch { vm.organise(item.id, clientId = id, clearClient = id == null) } },
                        onCreateClient = { name -> scope.launch { vm.createClient(name)?.let { vm.organise(item.id, clientId = it.id) } } },
                        onLabels = { scope.launch { vm.organise(item.id, labels = it) } },
                        onKeep = { scope.launch { vm.organise(item.id, keep = it) } }
                    )
                }
            }
        }
        if (playbackUrl != null && !noAudio) RecordingPlayer(player)
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
                if (helper.connected && helper.stale) {
                    Text(
                        "The running helper belongs to an older version of the app, so call recording fixes do not reach it. " +
                            "Restart it: Connect via Shizuku again, or run the command below again (or restart the phone).",
                        color = Brand.Warning,
                        fontSize = 13.sp
                    )
                }
                CaptureTestCard(vm)
                val problem by com.echoledger.mobile.recording.CaptureProblems.last.collectAsState()
                LaunchedEffect(Unit) { com.echoledger.mobile.recording.CaptureProblems.load(context) }
                problem?.let {
                    Text("Last capture problem (${formatWhen(it.at.toString())}): ${it.message}", color = Brand.Danger, fontSize = 12.sp)
                }
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
                Switch(
                    checked = settings.consentAcknowledged,
                    modifier = Modifier.semantics {
                        contentDescription = "I understand"
                    },
                    onCheckedChange = { v ->
                        vm.updateSettings { it.copy(consentAcknowledged = v) }
                    }
                )
            }
        }
    }
}

@Composable
private fun StepRow(title: String, done: Boolean, onFix: () -> Unit) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        Icon(
            if (done) Icons.Rounded.CheckCircle else Icons.Rounded.RadioButtonUnchecked,
            contentDescription = null,
            tint = if (done) Brand.Success else Brand.Muted,
            modifier = Modifier.size(18.dp)
        )
        Spacer(Modifier.width(8.dp))
        Text(
            title,
            color = if (done) Brand.Success else Brand.Text,
            modifier = Modifier.weight(1f).semantics { stateDescription = if (done) "Done" else "Needed" },
            fontSize = 14.sp
        )
        if (!done) TextButton(onClick = onFix) { Text("Allow") }
    }
}

/** A short yes/no question before an action that cannot be undone (A6.M4). */
@Composable
internal fun ConfirmDialog(text: String, confirmLabel: String, onConfirm: () -> Unit, onDismiss: () -> Unit) {
    AlertDialog(
        onDismissRequest = onDismiss,
        text = { Text(text) },
        confirmButton = { TextButton(onClick = onConfirm) { Text(confirmLabel, color = Brand.Danger) } },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Cancel") } }
    )
}

/** "If I don't choose within 60 s": what happens to a recording nobody decided on (same choices as the desktop). */
@Composable
internal fun DecisionTimeoutSection(vm: MainViewModel, settings: AppSettings) {
    SectionCard {
        Text("After a call", fontWeight = FontWeight.SemiBold)
        Text("If I don't choose within 60 s", color = Brand.Muted, fontSize = 12.sp)
        listOf("wait" to "Keep waiting (default)", "keep" to "Keep the recording", "discard" to "Discard it").forEach { (value, label) ->
            Row(
                Modifier.fillMaxWidth()
                    .selectable(
                        selected = settings.afterCallTimeout == value,
                        role = Role.RadioButton,
                        onClick = { vm.updateSettings { it.copy(decisionTimeout = value, decisionTimeoutChosen = true) } }
                    ),
                verticalAlignment = Alignment.CenterVertically
            ) {
                RadioButton(selected = settings.afterCallTimeout == value, onClick = null)
                Text(label, modifier = Modifier.padding(start = 8.dp))
            }
        }
    }
    SectionCard {
        Text("Undecided calls", fontWeight = FontWeight.SemiBold)
        Text("Keep automatically, so a call you forgot is never lost", color = Brand.Muted, fontSize = 12.sp)
        com.echoledger.mobile.calls.UndecidedKeepWorker.CHOICES.forEach { (hours, label) ->
            Row(
                Modifier.fillMaxWidth()
                    .selectable(
                        selected = settings.undecidedKeepHours == hours,
                        role = Role.RadioButton,
                        onClick = { vm.updateSettings { it.copy(undecidedKeepHours = hours) } }
                    ),
                verticalAlignment = Alignment.CenterVertically
            ) {
                RadioButton(selected = settings.undecidedKeepHours == hours, onClick = null)
                Text(label, modifier = Modifier.padding(start = 8.dp))
            }
        }
    }
}

/**
 * The App lock group, always visible. When the phone has no screen lock the switch is disabled and the reason and a
 * shortcut to the security settings are shown. Switching the lock on takes one successful unlock, so nobody can lock
 * themselves out with a screen lock they cannot answer.
 */
@Composable
internal fun AppLockSection(vm: MainViewModel, settings: AppSettings) {
    val context = LocalContext.current
    var recheck by remember { mutableIntStateOf(0) }
    // A screen lock set in the system settings counts as soon as the user comes back.
    LifecycleEventEffect(Lifecycle.Event.ON_RESUME) { recheck++ }
    val availability = remember(recheck) { lockAvailability(context) }
    val prompter = LocalUnlockPrompter.current ?: rememberUnlockPrompter()
    val available = availability.method != Method.None
    val on = settings.appLockEnabled && available
    var prompting by remember { mutableStateOf(false) }
    SectionCard {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text("App lock", fontWeight = FontWeight.SemiBold)
                Text("Ask for your fingerprint, face or screen lock when EchoLedger opens.", color = Brand.Muted, fontSize = 12.sp)
            }
            Switch(
                checked = on,
                enabled = available && prompter != null,
                onCheckedChange = { wanted ->
                    if (!wanted) {
                        vm.updateSettings { it.copy(appLockEnabled = false) }
                    } else if (prompter != null && !prompting) {
                        prompting = true
                        prompter.prompt { ok ->
                            prompting = false
                            if (ok) vm.updateSettings { it.copy(appLockEnabled = true) }
                        }
                    }
                }
            )
        }
        if (!available) {
            availability.reason?.let { Text(it, color = Brand.Warning, fontSize = 12.sp) }
            TextButton(onClick = { runCatching { context.startActivity(Intent(Settings.ACTION_SECURITY_SETTINGS)) } }) {
                Text("Open security settings")
            }
        }
        if (on) {
            Text("Lock after", color = Brand.Muted, fontSize = 12.sp)
            val lockAfter = LockAfter.normalize(settings.lockAfterMs)
            LockAfter.choices.forEach { (ms, label) ->
                Row(
                    Modifier.fillMaxWidth()
                        .selectable(
                            selected = lockAfter == ms,
                            role = Role.RadioButton,
                            onClick = { vm.updateSettings { it.copy(lockAfterMs = ms) } }
                        ),
                    verticalAlignment = Alignment.CenterVertically
                ) {
                    RadioButton(selected = lockAfter == ms, onClick = null)
                    Text(label, modifier = Modifier.padding(start = 8.dp))
                }
            }
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text("Hide content in Recents and screenshots")
                    Text("Off by default: screenshots are always allowed unless you turn this on.", color = Brand.Muted, fontSize = 12.sp)
                }
                Switch(
                    checked = settings.secureScreen,
                    onCheckedChange = { v -> vm.updateSettings { it.copy(secureScreen = v) } },
                    modifier = Modifier.semantics { contentDescription = "Hide content in Recents and screenshots" }
                )
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

/** A speech engine by its product name ("assemblyai" → "AssemblyAI"); unknown ids as sent (C8). */
fun providerName(id: String?): String = when (id) {
    null -> ""
    "assemblyai" -> "AssemblyAI"
    "elevenlabs" -> "ElevenLabs"
    "azure_mai" -> "Azure Speech"
    "whispercpp_local" -> "whisper.cpp"
    "parakeet_local" -> "Parakeet"
    else -> id
}

/** Final transcript states that are still being worked on: never shown as an empty transcript (A6.M5). */
private val IN_PROGRESS = setOf("pending", "submitted", "processing", "awaiting_device")

/** The failure reason, plus the phone's own hint when nothing was captured. */
internal fun failureText(t: TranscriptDto): String {
    val reason = t.error ?: "The speech provider could not process this recording."
    return if (t.errorCode == "no_audio") "$reason Check Setup → Call capture (the helper must be running) and record again." else reason
}

/** Setup → Call capture: the guided test and "Copy diagnostics". */
@Composable
private fun CaptureTestCard(vm: MainViewModel) {
    val clipboard = LocalClipboard.current
    val scope = androidx.compose.runtime.rememberCoroutineScope()
    val testing by vm.captureTesting.collectAsState()
    val result by vm.captureTest.collectAsState()
    var left by remember { mutableStateOf(0) }
    LaunchedEffect(testing) {
        left = com.echoledger.mobile.recording.CaptureTest.SECONDS
        while (testing && left > 0) {
            kotlinx.coroutines.delay(1_000)
            left--
        }
    }
    Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
            Button(onClick = { vm.runCaptureTest() }, enabled = !testing) { Text(if (testing) "Testing… $left s" else "Test call capture") }
            TextButton(onClick = {
                scope.launch { clipboard.setClipEntry(ClipData.newPlainText("diagnostics", vm.captureDiagnostics()).toClipEntry()) }
            }) { Text("Copy diagnostics") }
        }
        // Since v1.3: proves the other side's path without a call (a soft beep played as a call and heard back).
        val farTest by vm.farSideTest.collectAsState()
        Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
            OutlinedButton(onClick = { vm.runFarSideTest() }, enabled = farTest != "running") { Text("Test the other side") }
            farTest?.takeIf { it != "running" }?.let {
                Text(
                    if (it.startsWith(
                            "ok"
                        )
                    ) {
                        "Works: the other side of calls can be recorded."
                    } else {
                        "Did not work: ${it.removePrefix("fail: ")}"
                    },
                    color = if (it.startsWith("ok")) Brand.Success else Brand.Danger,
                    fontSize = 12.sp,
                    modifier = Modifier.weight(1f)
                )
            }
        }
        if (testing) {
            Text("Speak for a few seconds. For the other side, run the test during a WhatsApp call.", color = Brand.Muted, fontSize = 12.sp)
        }
        result?.let { r ->
            val colour = when (r.verdict) {
                com.echoledger.mobile.recording.CaptureTest.Verdict.READY -> Brand.Success
                com.echoledger.mobile.recording.CaptureTest.Verdict.PARTLY -> Brand.Warning
                com.echoledger.mobile.recording.CaptureTest.Verdict.NOT_READY -> Brand.Danger
            }
            Text(r.headline, color = colour, fontWeight = FontWeight.SemiBold, fontSize = 14.sp)
            r.stages.forEach { stage ->
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
                    Icon(
                        when (stage.ok) {
                            true -> Icons.Rounded.CheckCircle
                            false -> Icons.Rounded.Cancel
                            null -> Icons.Rounded.RemoveCircleOutline
                        },
                        contentDescription = when (stage.ok) {
                            true -> "Works"
                            false -> "Does not work"
                            null -> "Not checked"
                        },
                        tint = when (stage.ok) {
                            true -> Brand.Success
                            false -> Brand.Danger
                            null -> Brand.Muted
                        },
                        modifier = Modifier.size(16.dp)
                    )
                    Text("${stage.title}: ${stage.detail}", fontSize = 13.sp)
                }
            }
            r.fix?.let { Text("What to do: $it", fontSize = 13.sp) }
        }
    }
}
