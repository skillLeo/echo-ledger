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
import androidx.compose.ui.text.SpanStyle
import androidx.compose.ui.text.buildAnnotatedString
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.withStyle
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.app.ActivityCompat
import androidx.core.content.ContextCompat
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.compose.LifecycleEventEffect
import com.echoledger.mobile.core.Bookmarks
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
    var filter by remember { mutableStateOf<SourceDevice?>(null) }
    var tagFilter by remember { mutableStateOf<String?>(null) }
    var view by remember { mutableStateOf(SmartView.ALL) }
    var folder by remember { mutableStateOf<String?>(null) }
    var labelFilter by remember { mutableStateOf<String?>(null) }
    var selecting by remember { mutableStateOf(false) }
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
    val anyBookmarks = items.any { !it.deleted && it.markerCount > 0 }
    val folders = Organise.folders(items, clients)
    val labels = Organise.labels(items)
    val counts = Organise.counts(items)
    val visible = items.filter {
        !it.deleted &&
            Organise.inView(it, view) &&
            Organise.inFolder(it, folder) &&
            Organise.hasLabel(it, labelFilter) &&
            (filter == null || SourceDevice.from(it.sourceDevice) == filter) &&
            Bookmarks.matches(it, tagFilter) &&
            (
                q.isEmpty() ||
                    (
                        listOfNotNull(
                            it.title,
                            it.transcript?.preview,
                            it.client?.name,
                            SourceApp.from(it.sourceApp).label
                        ) + it.tags
                        ).any { s ->
                        s.lowercase().contains(q)
                    }
                )
    }
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
                IconButton(onClick = {
                    selecting = !selecting
                    picked = emptySet()
                }) {
                    Icon(
                        if (selecting) Icons.Outlined.Close else Icons.Outlined.Checklist,
                        contentDescription = if (selecting) "Cancel selection" else "Select",
                        tint = if (selecting) Brand.Violet else Brand.Muted
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
        Row(Modifier.horizontalScroll(rememberScrollState()).padding(vertical = 8.dp), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
            FilterChip(selected = filter == null, onClick = { filter = null }, label = { Text("All") })
            SourceDevice.entries.forEach { d ->
                FilterChip(selected = filter == d, onClick = {
                    filter = d
                }, label = { Text(d.label) }, leadingIcon = { Icon(d.icon(), null, Modifier.size(16.dp)) })
            }
        }
        if (anyBookmarks || tagFilter != null) {
            Row(
                Modifier.horizontalScroll(rememberScrollState()).padding(bottom = 12.dp),
                horizontalArrangement = Arrangement.spacedBy(8.dp)
            ) {
                FilterChip(selected = tagFilter == null, onClick = { tagFilter = null }, label = { Text("Any") })
                FilterChip(selected = tagFilter == Bookmarks.ANY, onClick = { tagFilter = Bookmarks.ANY }, label = { Text("Bookmarked") })
                tags.forEach { tag -> FilterChip(selected = tagFilter == tag, onClick = { tagFilter = tag }, label = { Text(tag) }) }
            }
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
        SmartViewChips(counts, view) { view = it }
        FolderLabelFilters(folders, labels, folder, labelFilter, { folder = it }, { labelFilter = it })
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
                title = if (q.isNotEmpty() || filter != null || tagFilter != null || view != SmartView.ALL || folder != null ||
                    labelFilter != null
                ) {
                    "Nothing matches"
                } else {
                    "No recordings yet"
                },
                body = if (q.isNotEmpty() ||
                    filter != null
                ) {
                    "Try a different search or filter."
                } else {
                    "Calls, meetings and voice notes you capture will appear here."
                }
            )
        }
    }
    // The whole page scrolls with the list (header, search and filters go up with it); while selecting, the actions sit
    // in a panel pinned to the bottom, so the list stays reachable and more recordings can be picked.
    Column(Modifier.fillMaxSize().padding(horizontal = 16.dp)) {
        LazyColumn(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            item(key = "library-top") { Column(content = top) }
            if (found.isNotEmpty()) {
                item(key = "search-hits") { SearchResultsSection(found) { result, ms -> onOpenAt(result.session, ms) } }
            }
            items(visible, key = { it.id }) { item ->
                LibraryRow(item, picked = if (selecting) item.id in picked else null, onLongClick = {
                    selecting = true
                    picked = picked + item.id
                }) {
                    if (selecting) picked = if (item.id in picked) picked - item.id else picked + item.id else onOpen(item)
                }
            }
            item { Spacer(Modifier.height(24.dp)) }
        }
        if (selecting) {
            val ids = picked.filter { id -> items.any { it.id == id && !it.deleted } }
            fun run(action: String, clientId: String? = null, label: String? = null) = scope.launch {
                if (vm.bulk(ids, action, clientId, label) != null && action == "delete") {
                    selecting = false
                    picked = emptySet()
                }
            }
            Box(Modifier.heightIn(max = 320.dp).verticalScroll(rememberScrollState()).padding(vertical = 8.dp)) {
                BulkPanel(
                    count = ids.size,
                    folders = folders,
                    onMove = { run("move", clientId = it) },
                    onCreateClient = { name -> scope.launch { vm.createClient(name)?.let { run("move", clientId = it.id) } } },
                    onLabel = { label, add -> run(if (add) "label" else "unlabel", label = label) },
                    onPin = { run(if (it) "pin" else "unpin") },
                    onExport = { format -> scope.launch { vm.exportSelected(ids, format)?.let { shareFile(context, it) } } },
                    onDelete = { run("delete") },
                    onDone = {
                        selecting = false
                        picked = emptySet()
                    },
                    onKeep = { run(if (it) "keep" else "unkeep") },
                    onSelectAll = { picked = visible.map { it.id }.toSet() }
                )
            }
        }
    }
}

@Composable
private fun LibraryRow(item: LibraryItem, picked: Boolean? = null, onLongClick: () -> Unit = {}, onClick: () -> Unit) {
    val selection = Modifier.semantics {
        if (picked != null) {
            selected = picked
            stateDescription = if (picked) "Selected" else "Not selected"
        }
    }
    SectionCard(
        Modifier.combinedClickable(onClick = onClick, onLongClick = onLongClick, onLongClickLabel = "Select").then(selection)
            .then(if (picked == true) Modifier.border(2.dp, Brand.Violet, RoundedCornerShape(Radius.lg)) else Modifier)
    ) {
        Row(horizontalArrangement = Arrangement.spacedBy(12.dp), verticalAlignment = Alignment.CenterVertically) {
            SourceAvatar(SourceDevice.from(item.sourceDevice), SourceApp.from(item.sourceApp))
            Column(Modifier.weight(1f)) {
                Text(
                    (if (item.pinned) "★ " else "") + (item.title ?: "Untitled"),
                    fontWeight = FontWeight.SemiBold,
                    fontSize = 15.sp,
                    maxLines = 1
                )
                Text(
                    listOfNotNull(
                        formatWhen(item.startedAt),
                        formatDuration(item.durationMs).ifEmpty {
                            null
                        },
                        item.client?.name,
                        Bookmarks.countLabel(item.markerCount)
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
        if (item.labels.isNotEmpty()) Text(item.labels.joinToString("  ") { "# $it" }, color = Brand.Muted, fontSize = 12.sp, maxLines = 1)
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
fun SessionScreen(vm: MainViewModel, item: LibraryItem, onBack: () -> Unit, startAtMs: Long? = null) {
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
    LaunchedEffect(item.id, attempt) {
        val id = item.transcript?.finalTranscriptId ?: item.transcript?.liveTranscriptId
        transcript = id?.let { vm.transcript(it) }
        // Only a finished transcript that does not arrive is a load failure; otherwise its status says more.
        loadFailed = id != null && transcript == null && item.transcript?.finalStatus == "completed"
        loaded = true
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
    val segments = t?.segments.orEmpty()
    val speakerList = segments.map { it.speaker }
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
            if (playbackUrl != null) {
                item { RecordingPlayer(player) }
                item { PlayerOptions(player) }
                item { Spacer(Modifier.height(4.dp)) }
            }
            item {
                ExportShareRow(vm, item.id, t?.id?.takeIf { t.status == "completed" && t.kind == "final" }, hasAudio = playbackUrl != null)
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
                            else -> "Transcribing…"
                        },
                        color = Brand.Muted
                    )
                }

                t.status == "failed" -> item {
                    SectionCard {
                        Text("Transcription failed", fontWeight = FontWeight.SemiBold, color = Brand.Danger)
                        Text(failureText(t), color = Brand.Muted, fontSize = 13.sp)
                    }
                }

                t.status in IN_PROGRESS -> item { Text("Transcribing…", color = Brand.Muted) }

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
                            if (spoken >= 0) {
                                Text(
                                    buildAnnotatedString {
                                        s.words.forEachIndexed { wi, w ->
                                            if (wi == spoken) {
                                                withStyle(SpanStyle(background = Brand.Warning.copy(alpha = 0.35f))) { append(w.text) }
                                            } else {
                                                append(w.text)
                                            }
                                            if (wi < s.words.lastIndex) append(" ")
                                        }
                                    },
                                    fontSize = 14.sp
                                )
                            } else {
                                Text(s.text, fontSize = 14.sp)
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
                            context.startForegroundService(RecordingService.intent(context, RecordingService.ACTION_TOGGLE))
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
