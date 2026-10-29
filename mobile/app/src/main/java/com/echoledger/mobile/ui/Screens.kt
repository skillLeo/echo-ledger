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
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.outlined.ArrowBack
import androidx.compose.material.icons.automirrored.outlined.LibraryBooks
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
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Badge
import androidx.compose.material3.BadgedBox
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.CircularProgressIndicator
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

/** Shown on Android 14+ until the call-capture helper runs: without it WhatsApp calls are never recorded. */
@Composable
private fun CallSetupBanner(onSetup: () -> Unit) {
    SectionCard(Modifier.clickable(onClick = onSetup)) {
        Text("WhatsApp calls are not being recorded", fontWeight = FontWeight.SemiBold, color = Brand.Warning)
        Text(
            "Finish the one-time call-capture setup (about 2 minutes) so incoming and outgoing WhatsApp calls are saved and transcribed automatically.",
            color = Brand.Muted,
            fontSize = 13.sp
        )
        Text("Set up call recording →", color = Brand.Violet, fontWeight = FontWeight.SemiBold, fontSize = 13.sp)
    }
}

@Composable
fun LibraryScreen(
    vm: MainViewModel,
    showCallSetup: Boolean,
    onSetup: () -> Unit,
    onOpenAt: (LibraryItem, Long) -> Unit = { item, _ -> },
    onOpen: (LibraryItem) -> Unit
) {
    val items by vm.library.collectAsState()
    val syncing by vm.syncing.collectAsState()
    var filters by remember { mutableStateOf(LibraryFilters()) }
    var sort by remember { mutableStateOf(LibrarySort.NEWEST) }
    var binOpen by remember { mutableStateOf(false) }
    var activityOpen by remember { mutableStateOf(false) }
    var unread by remember { mutableStateOf(0) }
    LaunchedEffect(Unit) { unread = vm.activity()?.unreadCount ?: 0 }
    var picked by remember { mutableStateOf<Set<String>>(emptySet()) }
    val clients by vm.clients.collectAsState()
    val scope = androidx.compose.runtime.rememberCoroutineScope()
    val context = LocalContext.current
    var query by remember { mutableStateOf("") }
    val q = query.trim().lowercase()
    var found by remember { mutableStateOf<List<SearchItemDto>>(emptyList()) }
    // Two characters search every transcript on the server too (the list below filters titles on the phone).
    LaunchedEffect(q) {
        if (q.length < 2) {
            found = emptyList()
        } else {
            kotlinx.coroutines.delay(350)
            found = vm.search(q)
        }
    }
    val tags = Bookmarks.tags(items)
    val folders = Organise.folders(items, clients)
    val labels = Organise.labels(items)
    val counts = Organise.counts(items)
    val visible = LibraryQuery.apply(items, filters, query, sort)
    // Only recordings still in the list count as ticked (a deleted or filtered-out one drops out of the selection).
    val ticked = picked.filter { id -> visible.any { it.id == id } }.toSet()
    val top: @Composable ColumnScope.() -> Unit = {
        Spacer(Modifier.height(8.dp))
        ScreenHeader("Library", "Recordings from your phone, computer and the web") {
            Row(verticalAlignment = Alignment.CenterVertically) {
                IconButton(
                    onClick = { activityOpen = !activityOpen },
                    modifier = Modifier.semantics {
                        contentDescription =
                            if (unread > 0) "Activity, $unread unread" else "Activity"
                    }
                ) {
                    BadgedBox(badge = { if (unread > 0) Badge { Text(if (unread > 99) "99+" else "$unread") } }) {
                        Icon(Icons.Outlined.Notifications, contentDescription = null, tint = Brand.Muted)
                    }
                }
                IconButton(onClick = { binOpen = !binOpen }) {
                    Icon(
                        Icons.Outlined.DeleteOutline,
                        contentDescription = "Recently deleted",
                        tint = if (binOpen) Brand.Violet else Brand.Muted
                    )
                }
                IconButton(onClick = { vm.refresh() }) { Icon(Icons.Outlined.Refresh, contentDescription = "Sync", tint = Brand.Muted) }
            }
        }
        OutlinedTextField(
            value = query,
            onValueChange = { query = it },
            placeholder = { Text("Search transcripts and titles", color = Brand.Faint) },
            leadingIcon = { Icon(Icons.Outlined.Search, contentDescription = null, tint = Brand.Faint) },
            singleLine = true,
            shape = RoundedCornerShape(Radius.md),
            modifier = Modifier.fillMaxWidth().padding(top = 12.dp)
        )
        val offline by vm.offline.collectAsState()
        if (offline) {
            Spacer(Modifier.height(12.dp))
            RetryBanner("You're offline. Showing the last synced library.", retrying = syncing, onRetry = { vm.refresh() })
        }
        if (showCallSetup) {
            Spacer(Modifier.height(12.dp))
            CallSetupBanner(onSetup)
        }
        if (activityOpen) {
            ActivityPanel(load = { vm.activity() }, markRead = { vm.markActivityRead(it) }, onOpen = { id ->
                activityOpen = false
                items.firstOrNull { it.id == id && !it.deleted }?.let(onOpen)
            }, onUnread = { unread = it }, onClose = { activityOpen = false })
            Spacer(Modifier.height(8.dp))
        }
        if (binOpen) {
            BinSection(load = { vm.bin() }, restore = { vm.restore(it) }, erase = { vm.eraseNow(it) }, onClose = { binOpen = false })
            Spacer(Modifier.height(8.dp))
        }
        LibraryToolbar(
            shown = visible.size,
            picked = ticked.size,
            filters = filters,
            onFilters = { filters = it },
            sort = sort,
            onSort = { sort = it },
            counts = counts,
            folders = folders,
            labels = labels,
            tags = tags,
            onToggleAll = { picked = if (ticked.size >= visible.size) emptySet() else visible.map { it.id }.toSet() }
        )
        if (visible.isEmpty() && items.isEmpty() && syncing && !vm.hasSynced()) {
            Box(Modifier.fillMaxWidth().padding(top = 48.dp), contentAlignment = Alignment.Center) {
                Column(horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.spacedBy(12.dp)) {
                    CircularProgressIndicator(color = Brand.Violet)
                    Text("Loading your library…", color = Brand.Muted)
                }
            }
        } else if (visible.isEmpty()) {
            EmptyState(
                icon = Icons.AutoMirrored.Outlined.LibraryBooks,
                title = if (q.isNotEmpty() || filters.count > 0) {
                    "Nothing matches"
                } else {
                    "No recordings yet"
                },
                body = if (q.isNotEmpty() || filters.count > 0) {
                    "Try a different search or filter."
                } else {
                    "Calls, meetings and voice notes you capture will appear here."
                }
            )
        }
    }
    // The whole page scrolls with the list (header, search and filters go up with it); while recordings are ticked, the
    // actions sit in one line at the bottom.
    Column(Modifier.fillMaxSize().padding(horizontal = 12.dp)) {
        LazyColumn(Modifier.weight(1f)) {
            item(key = "library-top") { Column(content = top) }
            if (found.isNotEmpty()) {
                item(key = "search-hits") { SearchResultsSection(found) { result, ms -> onOpenAt(result.session, ms) } }
            }
            items(visible, key = { it.id }) { item ->
                CompactLibraryRow(
                    item,
                    checked = item.id in ticked,
                    selecting = ticked.isNotEmpty(),
                    onCheck = { picked = if (item.id in picked) picked - item.id else picked + item.id },
                    onOpen = { onOpen(item) }
                )
            }
            item { Spacer(Modifier.height(24.dp)) }
        }
        if (ticked.isNotEmpty()) {
            val ids = ticked.toList()
            fun run(action: String, clientId: String? = null, label: String? = null) = scope.launch {
                if (vm.bulk(ids, action, clientId, label) != null && action == "delete") picked = emptySet()
            }
            Box(Modifier.padding(vertical = 8.dp)) {
                CompactBulkBar(
                    count = ids.size,
                    folders = folders,
                    labels = labels,
                    onMove = { run("move", clientId = it) },
                    onCreateClient = { name -> scope.launch { vm.createClient(name)?.let { run("move", clientId = it.id) } } },
                    onLabel = { label, add -> run(if (add) "label" else "unlabel", label = label) },
                    onPin = { run(if (it) "pin" else "unpin") },
                    onKeep = { run(if (it) "keep" else "unkeep") },
                    onExport = { format -> scope.launch { vm.exportSelected(ids, format)?.let { shareFile(context, it) } } },
                    onDelete = { run("delete") },
                    onDone = { picked = emptySet() }
                )
            }
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

    Column(Modifier.fillMaxSize().padding(horizontal = 16.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            IconButton(onClick = onBack) { Icon(Icons.AutoMirrored.Outlined.ArrowBack, contentDescription = "Back") }
            Spacer(Modifier.weight(1f))
            var confirmDelete by remember { mutableStateOf(false) }
            IconButton(onClick = {
                confirmDelete = true
            }) { Icon(Icons.Outlined.Delete, contentDescription = "Delete", tint = Brand.Danger) }
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
            if (noAudio) {
                item {
                    SectionCard {
                        Text("No audio", fontWeight = FontWeight.SemiBold, color = Brand.Muted)
                        Text(
                            "Nothing was heard in this recording, so there is nothing to play or transcribe. Run the capture test to find out why, then delete it.",
                            color = Brand.Muted,
                            fontSize = 13.sp
                        )
                        Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            Button(onClick = onCaptureTest) { Text("Run capture test") }
                            OutlinedButton(onClick = {
                                scope.launch {
                                    vm.delete(item)
                                    onBack()
                                }
                            }) { Text("Delete", color = Brand.Danger) }
                        }
                    }
                }
            }
            if (playbackUrl != null && !noAudio) {
                item { RecordingPlayer(player) }
                item { PlayerOptions(player) }
                item { Spacer(Modifier.height(4.dp)) }
            }
            if (!noAudio) {
                item {
                    ExportShareRow(
                        vm,
                        item.id,
                        t?.id?.takeIf { t.status == "completed" && t.kind == "final" },
                        hasAudio =
                            playbackUrl != null,
                        view = showAs.key.takeIf { shown != null }
                    )
                }
                if (item.transcript?.finalStatus != null) {
                    item { RetranscribeRow(vm, item, version) { version = it } }
                }
            }
            if (bookmarks.isNotEmpty()) {
                item {
                    Row(Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(6.dp)) {
                        bookmarks.forEach { b ->
                            FilterChip(
                                selected = false,
                                enabled = player.ready,
                                onClick = { player.seekTo(b.atMs) },
                                label = { Text(listOfNotNull(formatDuration(b.atMs), b.tag).joinToString(" ")) }
                            )
                        }
                    }
                }
            }
            when {
                noAudio -> Unit

                !loaded -> item { Text("Loading transcript…", color = Brand.Muted) }

                t == null && loadFailed -> item {
                    RetryBanner("Couldn't load the transcript.", retrying = false, onRetry = {
                        loaded = false
                        attempt++
                    })
                }

                t == null -> item {
                    val status = item.transcript?.finalStatus
                    Text(
                        when (status) {
                            null -> "No transcript for this recording."
                            "failed" -> "Transcription failed."
                            else -> TranscriptText.transcribing(item.transcript?.finalProgress)
                        },
                        color = Brand.Muted
                    )
                }

                t.status == "failed" -> item {
                    SectionCard {
                        Text("Transcription failed", fontWeight = FontWeight.SemiBold, color = Brand.Danger)
                        Text(failureText(t), color = Brand.Muted, fontSize = 13.sp)
                        // Since v1.3: one tap runs it again (the server starts with the best engine for its language).
                        if (t.errorCode != "no_audio") {
                            val retryScope = rememberCoroutineScope()
                            var retrying by remember { mutableStateOf(false) }
                            Button(
                                enabled = !retrying,
                                onClick = {
                                    retrying = true
                                    retryScope.launch {
                                        vm.retranscribe(item.id, t.language)
                                        retrying = false
                                    }
                                },
                                modifier = Modifier.padding(top = 8.dp).semantics { contentDescription = "Retry the transcription" }
                            ) { Text(if (retrying) "Retrying…" else "Retry") }
                        }
                    }
                }

                t.status in IN_PROGRESS -> item {
                    Text(TranscriptText.transcribing(t.progress ?: item.transcript?.finalProgress), color = Brand.Muted)
                }

                segments.isEmpty() -> item { Text("The transcript is empty: no speech was detected.", color = Brand.Muted) }

                else -> {
                    item {
                        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                            Text(
                                if (t.kind ==
                                    "final"
                                ) {
                                    "Final transcript · ${providerName(t.provider)}"
                                } else {
                                    "Live captions (final pending)"
                                },
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
                        TextButton(onClick = { copy(withTimestamps = false, forAi = true) }) {
                            Text("Copy for AI (marks unsure words)")
                        }
                        val available = t.views?.available.orEmpty()
                        if (t.kind == "final" && t.status == "completed" && available.isNotEmpty()) {
                            ShowAsRow(available, showAs, views) { chooseView(it) }
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
                            // A turn shows its speaker once; following lines of the same speaker leave the label blank.
                            Text(
                                if (continuesTurn(speakerList, index)) "" else speakerName(speakerNames, s.speaker),
                                color = if (s.channel == 2) LocalEchoColors.current.client else LocalEchoColors.current.me,
                                fontWeight = FontWeight.SemiBold,
                                fontSize = 12.sp,
                                modifier = Modifier.width(60.dp)
                            )
                            val spoken = if (active && s.words.isNotEmpty()) s.words.indexOfLast { it.startMs <= player.positionMs } else -1
                            val lineLanguage = when (showAs) {
                                ShowAs.ORIGINAL -> t.language
                                ShowAs.URDU -> "ur"
                                else -> "en"
                            }
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
            // Organising comes after the transcript: reading it is the main job of this page.
            item {
                Spacer(Modifier.height(8.dp))
                val clientList by vm.clients.collectAsState()
                val library by vm.library.collectAsState()
                val live = library.firstOrNull { it.id == item.id } ?: item
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
                Spacer(Modifier.height(8.dp))
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
    val learn by vm.learn.collectAsState()
    val live by RecordingService.live.collectAsState()
    var recording by remember { mutableStateOf(RecordingService.isRecording) }
    LaunchedEffect(Unit) {
        vm.reloadLocal()
        while (true) {
            recording = RecordingService.isRecording
            kotlinx.coroutines.delay(1_000)
        }
    }
    Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()).padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        val haptics = LocalHapticFeedback.current
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
                        .clickable(role = Role.Button, onClickLabel = if (recording) "Stop recording" else "Start recording") {
                            haptics.performHapticFeedback(if (recording) HapticFeedbackType.ToggleOff else HapticFeedbackType.ToggleOn)
                            context.startForegroundService(
                                RecordingService.intent(context, RecordingService.ACTION_TOGGLE, from = "Record tab")
                            )
                        },
                    contentAlignment = Alignment.Center
                ) { Text(if (recording) "STOP" else "REC", fontWeight = FontWeight.Bold, color = LocalEchoColors.current.onAccent) }
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
        LiveRecordingCard(live, settings)
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
                    // C7: the switch carries its row's title for TalkBack (the text is a sibling, not merged).
                    modifier = Modifier.semantics { contentDescription = "Auto-record WhatsApp calls" },
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
                        TextButton(onClick = {
                            haptics.performHapticFeedback(HapticFeedbackType.Reject)
                            vm.decide(rec.id, keep = false)
                        }) { Text("Discard", color = Brand.Danger) }
                        Button(onClick = {
                            haptics.performHapticFeedback(HapticFeedbackType.Confirm)
                            vm.decide(rec.id, keep = true)
                        }) { Text("Keep") }
                    }
                    // Keep transcribes in this language (Auto: the client's or the console default).
                    LanguageChips(rec.language) { vm.setPendingLanguage(rec.id, it) }
                    learn?.takeIf { it.recordingId == rec.id }?.let { asked ->
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Text(asked.question, Modifier.weight(1f), fontSize = 13.sp)
                            TextButton(onClick = { vm.answerLearn(false) }) { Text("No") }
                            TextButton(onClick = { vm.answerLearn(true) }) { Text("Yes") }
                        }
                    }
                }
            }
        }
        val queued = pending.filter { it.state in setOf("queued", "uploading") }
        if (queued.isNotEmpty()) {
            SectionCard {
                Text("Uploading ${queued.size} recording(s)…", fontWeight = FontWeight.SemiBold)
                queued.mapNotNull { it.lastError }.firstOrNull()?.let { Text("Will retry: $it", color = Brand.Muted, fontSize = 12.sp) }
                TextButton(onClick = { vm.retryPending() }) { Text("Retry now") }
            }
        }
        val failedUploads = pending.filter { it.state == "failed" }
        if (failedUploads.isNotEmpty()) {
            SectionCard {
                Text("${failedUploads.size} recording(s) could not be uploaded", fontWeight = FontWeight.SemiBold, color = Brand.Danger)
                failedUploads.forEach { rec ->
                    Column(verticalArrangement = Arrangement.spacedBy(2.dp)) {
                        Text("${SourceApp.from(rec.sourceApp).label} · ${formatWhen(rec.startedAt)}", fontSize = 14.sp)
                        rec.lastError?.let { Text(it, color = Brand.Muted, fontSize = 12.sp) }
                        Row {
                            TextButton(onClick = { vm.retryUpload(rec.id) }) { Text("Retry") }
                            TextButton(onClick = { vm.decide(rec.id, keep = false) }) { Text("Discard", color = Brand.Danger) }
                        }
                    }
                }
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
                Switch(checked = settings.secureScreen, onCheckedChange = { v -> vm.updateSettings { it.copy(secureScreen = v) } })
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
                    if (it.startsWith("ok")) "✓ The other side of calls can be recorded." else "✗ ${it.removePrefix("fail: ")}",
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
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Text(
                        when (stage.ok) {
                            true -> "✓"
                            false -> "✗"
                            null -> "–"
                        },
                        color = when (stage.ok) {
                            true -> Brand.Success
                            false -> Brand.Danger
                            null -> Brand.Muted
                        },
                        fontWeight = FontWeight.Bold
                    )
                    Text("${stage.title}: ${stage.detail}", fontSize = 13.sp)
                }
            }
            r.fix?.let { Text("What to do: $it", fontSize = 13.sp) }
        }
    }
}
