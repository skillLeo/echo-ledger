package com.echoledger.mobile.ui

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.background
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
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.Delete
import androidx.compose.material.icons.rounded.History
import androidx.compose.material.icons.rounded.MoreVert
import androidx.compose.material.icons.rounded.PushPin
import androidx.compose.material.icons.rounded.Search
import androidx.compose.material.icons.rounded.SearchOff
import androidx.compose.material.icons.rounded.Sort
import androidx.compose.material.icons.rounded.VideoLibrary
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.SwipeToDismissBox
import androidx.compose.material3.SwipeToDismissBoxValue
import androidx.compose.material3.Text
import androidx.compose.material3.pulltorefresh.PullToRefreshBox
import androidx.compose.material3.rememberSwipeToDismissBoxState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalFocusManager
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.unit.dp
import com.echoledger.mobile.core.Bookmarks
import com.echoledger.mobile.core.ItemStatus
import com.echoledger.mobile.core.LibraryFilters
import com.echoledger.mobile.core.LibraryQuery
import com.echoledger.mobile.core.LibrarySort
import com.echoledger.mobile.core.Organise
import com.echoledger.mobile.core.SourceApp
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.LocalRecording
import com.echoledger.mobile.data.SearchItemDto
import com.echoledger.mobile.notify.Notifications
import com.echoledger.mobile.ui.design.BannerKind
import com.echoledger.mobile.ui.design.BarIcon
import com.echoledger.mobile.ui.design.EchoEmptyState
import com.echoledger.mobile.ui.design.EchoTopBar
import com.echoledger.mobile.ui.design.InlineBanner
import com.echoledger.mobile.ui.design.LibraryItemRow
import com.echoledger.mobile.ui.design.ListPadding
import com.echoledger.mobile.ui.design.RecordingRow
import com.echoledger.mobile.ui.design.SearchPill
import com.echoledger.mobile.ui.design.SectionHeader
import com.echoledger.mobile.ui.design.SelectionBar
import com.echoledger.mobile.ui.design.SkeletonRow
import com.echoledger.mobile.ui.design.SourceAppTile
import com.echoledger.mobile.ui.design.Spinner
import com.echoledger.mobile.ui.design.UndoSnackbar
import com.echoledger.mobile.ui.design.dateGroup
import com.echoledger.mobile.ui.design.rowTime
import java.time.LocalDate
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/**
 * The Library (since v1.3): a search pill, filter chips, calls waiting for Keep or Discard, then every recording under
 * sticky date headers. Long-press a row or tap its icon to select; swipe right to pin, left to delete (with Undo); pull
 * down to sync. While searching, titles and transcript matches are listed instead.
 */
@OptIn(ExperimentalFoundationApi::class, ExperimentalMaterial3Api::class)
@Composable
fun LibraryScreen(
    vm: MainViewModel,
    showCallSetup: Boolean,
    onSetup: () -> Unit,
    onOpenAt: (LibraryItem, Long) -> Unit = { _, _ -> },
    waiting: List<LocalRecording> = emptyList(),
    onOpenWaiting: () -> Unit = {},
    onSelecting: (Boolean) -> Unit = {},
    scrollToTop: Int = 0,
    today: LocalDate = LocalDate.now(),
    onOpen: (LibraryItem) -> Unit
) {
    val items by vm.library.collectAsState()
    val pendingLocal by vm.pending.collectAsState()
    val uploading = pendingLocal.filter { it.state in UPLOADING_STATES }
    // Live status (since 1.3.2): while something is uploading or being transcribed, the list asks again every 5 s,
    // and stops by itself once all is done.
    val busy = uploading.any { it.state in MOVING_STATES } || items.any { ItemStatus.of(it) == ItemStatus.PROCESSING }
    LaunchedEffect(busy) {
        while (busy) {
            kotlinx.coroutines.delay(LIVE_REFRESH_MS)
            vm.reloadLocal()
            vm.refreshQuietly()
        }
    }
    val syncing by vm.syncing.collectAsState()
    val offline by vm.offline.collectAsState()
    val clients by vm.clients.collectAsState()
    var filters by remember { mutableStateOf(LibraryFilters()) }
    var sort by remember { mutableStateOf(LibrarySort.NEWEST) }
    var binOpen by remember { mutableStateOf(false) }
    var menuOpen by remember { mutableStateOf(false) }
    var sortOpen by remember { mutableStateOf(false) }
    var picked by remember { mutableStateOf<Set<String>>(emptySet()) }
    var selecting by remember { mutableStateOf(false) }
    var query by rememberSaveable { mutableStateOf("") }
    var searching by rememberSaveable { mutableStateOf(false) }
    // The last five searches, kept on this phone across restarts (never in a settings export).
    val recent = vm.settings.collectAsState().value.recentSearches
    fun keepSearch(words: String) {
        vm.updateSettings { s ->
            s.copy(recentSearches = (listOf(words) + s.recentSearches.filterNot { it.equals(words, ignoreCase = true) }).take(5))
        }
    }
    var deleted by remember { mutableStateOf<LibraryItem?>(null) }
    val scope = rememberCoroutineScope()
    val context = LocalContext.current
    val focus = LocalFocusManager.current
    val list = rememberLazyListState()
    LaunchedEffect(scrollToTop) { if (scrollToTop > 0) list.animateScrollToItem(0) }
    val q = query.trim().lowercase()
    var found by remember { mutableStateOf<List<SearchItemDto>>(emptyList()) }
    // Two characters search every transcript on the server too (titles are matched on the phone).
    LaunchedEffect(q) {
        if (q.length < 2) {
            found = emptyList()
        } else {
            delay(350)
            found = vm.search(q)
        }
    }
    LaunchedEffect(deleted) { if (deleted != null) delay(5_000).also { deleted = null } }
    // Worked out again only when what they depend on changes, not on every recomposition (since 1.3.2: less lag).
    val folders = remember(items, clients) { Organise.folders(items, clients) }
    val labels = remember(items) { Organise.labels(items) }
    val visible = remember(items, filters, query, sort) { LibraryQuery.apply(items, filters, query, sort) }
    val ticked = remember(picked, visible) { visible.mapTo(HashSet()) { it.id }.let { ids -> picked.filter { it in ids }.toSet() } }
    val inSelection = selecting && ticked.isNotEmpty()
    LaunchedEffect(inSelection) { onSelecting(inSelection) }
    LaunchedEffect(ticked.isEmpty()) { if (ticked.isEmpty()) selecting = false }
    fun toggle(item: LibraryItem) {
        selecting = true
        picked = if (item.id in picked) picked - item.id else picked + item.id
    }
    fun leaveSearch() {
        if (q.isNotEmpty()) keepSearch(query.trim())
        query = ""
        searching = false
        focus.clearFocus(force = true)
    }
    BackHandler(enabled = inSelection) { picked = emptySet() }
    BackHandler(enabled = searching && !inSelection) { leaveSearch() }
    fun run(ids: List<String>, action: String, clientId: String? = null, label: String? = null) = scope.launch {
        if (vm.bulk(ids, action, clientId, label) != null && action == "delete") picked = emptySet()
    }

    Box(Modifier.fillMaxSize()) {
        Column(Modifier.fillMaxSize()) {
            if (inSelection) {
                val ids = ticked.toList()
                SelectionBar(ids.size, onClose = { picked = emptySet() }) {
                    SelectionActions(
                        count = ids.size,
                        folders = folders,
                        labels = labels,
                        onSelectAll = { picked = visible.map { it.id }.toSet() },
                        onMove = { run(ids, "move", clientId = it) },
                        onCreateClient = { name -> scope.launch { vm.createClient(name)?.let { run(ids, "move", clientId = it.id) } } },
                        onLabel = { label, add -> run(ids, if (add) "label" else "unlabel", label = label) },
                        onPin = { run(ids, if (it) "pin" else "unpin") },
                        onKeep = { run(ids, if (it) "keep" else "unkeep") },
                        onExport = { format -> scope.launch { vm.exportSelected(ids, format)?.let { shareFile(context, it) } } },
                        onDelete = { run(ids, "delete") }
                    )
                }
            } else if (!searching) {
                EchoTopBar("Library", actions = {
                    Box {
                        BarIcon(Icons.Rounded.MoreVert, "More", { menuOpen = true })
                        DropdownMenu(expanded = menuOpen, onDismissRequest = { menuOpen = false }) {
                            DropdownMenuItem(text = { Text("Select") }, onClick = {
                                menuOpen = false
                                selecting = true
                                visible.firstOrNull()?.let { picked = setOf(it.id) }
                            })
                            DropdownMenuItem(text = { Text("Sync now") }, onClick = {
                                menuOpen = false
                                vm.refresh()
                            })
                            DropdownMenuItem(text = { Text("Recently deleted") }, onClick = {
                                menuOpen = false
                                binOpen = !binOpen
                            })
                        }
                    }
                })
            }
            if (!inSelection) {
                SearchField(
                    query = query,
                    onQuery = {
                        query = it
                        if (it.isNotEmpty()) searching = true
                    },
                    searching = searching,
                    onOpen = { searching = true },
                    onLeave = { leaveSearch() },
                    onSubmit = {
                        if (q.isNotEmpty()) {
                            keepSearch(query.trim())
                        }
                    },
                    trailing = {
                        Box {
                            BarIcon(Icons.Rounded.Sort, "Sort", { sortOpen = true })
                            DropdownMenu(expanded = sortOpen, onDismissRequest = { sortOpen = false }) {
                                LibrarySort.entries.forEach { s ->
                                    DropdownMenuItem(
                                        text = { Text(s.label, color = if (s == sort) Brand.AccentText else Brand.Text) },
                                        onClick = {
                                            sort = s
                                            sortOpen = false
                                        }
                                    )
                                }
                            }
                        }
                    }
                )
                if (!searching && items.any { !it.deleted }) {
                    Spacer(Modifier.height(Space.s3))
                    FilterChips(
                        filters,
                        { filters = it },
                        items,
                        folders,
                        labels,
                        remember(items) { Bookmarks.tags(items) },
                        remember(items) { Organise.counts(items) }
                    )
                    Spacer(Modifier.height(Space.s1))
                }
            }
            PullToRefreshBox(isRefreshing = syncing && vm.hasSynced(), onRefresh = { vm.refresh() }, modifier = Modifier.weight(1f)) {
                LazyColumn(Modifier.fillMaxSize(), state = list, contentPadding = ListPadding) {
                    if (offline) {
                        item(key = "offline") {
                            InlineBanner(
                                "You're offline. Showing the last synced library.",
                                Modifier.padding(horizontal = Space.screen, vertical = Space.s2),
                                kind = BannerKind.Warning,
                                action = "Retry",
                                onAction = { vm.refresh() }
                            )
                        }
                    }
                    if (showCallSetup && !searching) {
                        item(key = "call-setup") {
                            InlineBanner(
                                "WhatsApp calls are not being recorded yet. The one-time setup takes about 2 minutes.",
                                Modifier.padding(horizontal = Space.screen, vertical = Space.s2),
                                kind = BannerKind.Warning,
                                action = "Set up",
                                onAction = onSetup
                            )
                        }
                    }
                    if (binOpen) {
                        item(key = "bin") {
                            Box(Modifier.padding(horizontal = Space.screen, vertical = Space.s2)) {
                                BinSection(load = { vm.bin() }, restore = { vm.restore(it) }, erase = { vm.eraseNow(it) }, onClose = {
                                    binOpen =
                                        false
                                })
                            }
                        }
                    }
                    when {
                        searching -> searchResults(q, recent, visible, found, today, onRecent = {
                            query = it
                        }, onOpen = onOpen, onOpenAt = onOpenAt)

                        visible.isEmpty() && items.isEmpty() && syncing && !vm.hasSynced() -> items(6, key = {
                            "skeleton-$it"
                        }) { SkeletonRow() }

                        visible.isEmpty() -> item(key = "empty") {
                            val narrowed = filters.count > 0
                            if (narrowed) {
                                EchoEmptyState(
                                    Icons.Rounded.SearchOff,
                                    "Nothing matches",
                                    body = "Try a different search or filter.",
                                    action = "Clear filters",
                                    onAction = { filters = LibraryFilters() }
                                )
                            } else {
                                EchoEmptyState(
                                    Icons.Rounded.VideoLibrary,
                                    "No recordings yet",
                                    body = "Calls, meetings and voice notes you capture will appear here."
                                )
                            }
                        }

                        else -> {
                            // One card for every call waiting for a decision (since 1.3.2), then what is on its way
                            // to the server: a new voice note shows here at once, before the server has it.
                            if (waiting.isNotEmpty() && filters.count == 0) {
                                item(key = "waiting") { WaitingSummaryCard(waiting, onReview = onOpenWaiting) }
                            }
                            if (uploading.isNotEmpty() && filters.count == 0) {
                                item(key = "h-uploading") { SectionHeader("Uploading") }
                                itemsIndexed(uploading, key = { _, it -> "u-${it.id}" }) { i, rec ->
                                    val failed = rec.state == "failed"
                                    val moving = rec.state == "uploading"
                                    RecordingRow(
                                        title = rec.title ?: Notifications.decisionText(rec, null).substringBefore(" · "),
                                        time = rowTime(rec.startedAt, today),
                                        meta = UploadRow.meta(rec.state),
                                        tile = { SourceAppTile(SourceApp.from(rec.sourceApp)) },
                                        onClick = {
                                            when (rec.state) {
                                                "failed" -> vm.retryUpload(rec.id)
                                                "duplicate" -> vm.dismissLocal(rec.id)
                                            }
                                        },
                                        // Hold: cancel an upload that has not started, or remove a failed one.
                                        onLongClick = { if (!moving) vm.cancelUpload(rec.id) },
                                        metaColor = if (failed) LocalEchoColors.current.danger else LocalEchoColors.current.textMuted,
                                        status = if (moving || rec.state == "queued") ({ Spinner(14.dp) }) else null,
                                        divider = i < uploading.lastIndex
                                    )
                                }
                            }
                            val grouped = if (sort == LibrarySort.NEWEST || sort == LibrarySort.OLDEST) {
                                visible.groupBy { dateGroup(it.startedAt, today) }
                            } else {
                                mapOf(sort.label to visible)
                            }
                            grouped.forEach { (group, rows) ->
                                stickyHeader(key = "h-$group") { SectionHeader(group) }
                                itemsIndexed(rows, key = { _, it -> it.id }, contentType = { _, _ -> "recording" }) { i, item ->
                                    SwipeRow(
                                        item = item,
                                        enabled = !inSelection,
                                        onPin = { run(listOf(item.id), if (item.pinned) "unpin" else "pin") },
                                        onDelete = {
                                            run(listOf(item.id), "delete")
                                            deleted = item
                                        }
                                    ) {
                                        LibraryItemRow(
                                            item,
                                            today,
                                            onOpen = { onOpen(item) },
                                            onToggle = { toggle(item) },
                                            selecting = inSelection,
                                            selected = item.id in ticked,
                                            divider = i < rows.lastIndex
                                        )
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        deleted?.let { gone ->
            UndoSnackbar(
                "Recording deleted",
                onUndo = {
                    deleted = null
                    scope.launch { if (vm.restore(gone.id)) vm.refresh() }
                },
                modifier = Modifier.align(Alignment.BottomCenter).padding(bottom = Space.s4)
            )
        }
    }
}

/**
 * The search pill. Not searching: a button that opens search. Searching: a field with the keyboard up, a back arrow
 * (or Back) to leave, and ✕ to clear the words.
 */
@Composable
private fun SearchField(
    query: String,
    onQuery: (String) -> Unit,
    searching: Boolean,
    onOpen: () -> Unit,
    onLeave: () -> Unit,
    onSubmit: () -> Unit,
    trailing: @Composable () -> Unit
) {
    if (!searching) {
        SearchPill(
            "Search recordings and transcripts",
            onClick = onOpen,
            modifier = Modifier.padding(horizontal = Space.screen),
            trailing = trailing
        )
        return
    }
    val type = LocalEchoType.current
    val focus = remember { FocusRequester() }
    LaunchedEffect(Unit) { runCatching { focus.requestFocus() } }
    Row(
        Modifier.fillMaxWidth().padding(horizontal = Space.screen).height(48.dp).clip(CircleShape).background(Brand.SurfaceAlt)
            .padding(start = Space.s0, end = Space.s1),
        verticalAlignment = Alignment.CenterVertically
    ) {
        BarIcon(Icons.AutoMirrored.Rounded.ArrowBack, "Leave search", onLeave, tint = Brand.Text)
        Box(Modifier.weight(1f)) {
            if (query.isEmpty()) Text("Search recordings and transcripts", style = type.bodyM, color = Brand.Muted, maxLines = 1)
            BasicTextField(
                value = query,
                onValueChange = onQuery,
                singleLine = true,
                textStyle = type.bodyM.copy(color = Brand.Text),
                cursorBrush = SolidColor(Brand.AccentText),
                keyboardOptions = KeyboardOptions(imeAction = ImeAction.Search),
                keyboardActions = KeyboardActions(onSearch = { onSubmit() }),
                modifier = Modifier.fillMaxWidth().focusRequester(focus).semantics { contentDescription = "Search words" }
            )
        }
        if (query.isNotEmpty()) BarIcon(Icons.Rounded.Close, "Clear search", { onQuery("") })
    }
}

/** While searching: recent searches (nothing typed yet), then Titles and Transcripts. */
private fun androidx.compose.foundation.lazy.LazyListScope.searchResults(
    q: String,
    recent: List<String>,
    titles: List<LibraryItem>,
    found: List<SearchItemDto>,
    today: LocalDate,
    onRecent: (String) -> Unit,
    onOpen: (LibraryItem) -> Unit,
    onOpenAt: (LibraryItem, Long) -> Unit
) {
    if (q.isEmpty()) {
        if (recent.isNotEmpty()) {
            item(key = "h-recent") { SectionHeader("Recent searches") }
            items(recent, key = { "r-$it" }) { r ->
                RecordingRow(
                    title = r,
                    time = "",
                    meta = "",
                    tile = { com.echoledger.mobile.ui.design.IconTile(Icons.Rounded.History, Brand.Muted, size = 32.dp) },
                    onClick = { onRecent(r) },
                    onLongClick = { onRecent(r) },
                    divider = false
                )
            }
        }
        return
    }
    if (titles.isEmpty() && found.isEmpty()) {
        item(key = "nothing") { EchoEmptyState(Icons.Rounded.SearchOff, "Nothing matches", body = "Try a different search or filter.") }
        return
    }
    if (titles.isNotEmpty()) {
        item(key = "h-titles") { SectionHeader("Titles") }
        itemsIndexed(titles, key = { _, it -> "t-${it.id}" }) { i, item ->
            LibraryItemRow(item, today, onOpen = { onOpen(item) }, onToggle = { onOpen(item) }, divider = i < titles.lastIndex)
        }
    }
    if (found.isNotEmpty()) {
        item(key = "h-transcripts") { SectionHeader("Transcripts") }
        item(key = "hits") {
            Box(Modifier.padding(horizontal = Space.screen)) { SearchResultsSection(found) { result, ms -> onOpenAt(result.session, ms) } }
        }
    }
}

/** Swipe right to pin (or unpin), left to delete; the row springs back after a pin. */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun SwipeRow(item: LibraryItem, enabled: Boolean, onPin: () -> Unit, onDelete: () -> Unit, content: @Composable () -> Unit) {
    val state = rememberSwipeToDismissBoxState()
    LaunchedEffect(state.currentValue) {
        when (state.currentValue) {
            SwipeToDismissBoxValue.StartToEnd -> {
                onPin()
                state.reset()
            }

            SwipeToDismissBoxValue.EndToStart -> onDelete()

            SwipeToDismissBoxValue.Settled -> Unit
        }
    }
    val c = LocalEchoColors.current
    SwipeToDismissBox(
        state = state,
        enableDismissFromStartToEnd = enabled,
        enableDismissFromEndToStart = enabled,
        backgroundContent = {
            val toPin = state.dismissDirection == SwipeToDismissBoxValue.StartToEnd
            Row(
                Modifier.fillMaxSize().background(if (toPin) c.accentSoft else c.dangerSoft).padding(horizontal = Space.s5),
                verticalAlignment = Alignment.CenterVertically
            ) {
                if (!toPin) Spacer(Modifier.weight(1f))
                Icon(
                    if (toPin) Icons.Rounded.PushPin else Icons.Rounded.Delete,
                    contentDescription = null,
                    tint = if (toPin) c.accentText else c.danger
                )
                Text(
                    if (toPin) (if (item.pinned) " Unpin" else " Pin") else " Delete",
                    style = LocalEchoType.current.labelL,
                    color = if (toPin) c.accentText else c.danger
                )
            }
        }
    ) { Box(Modifier.background(Brand.Background)) { content() } }
}

/** Local recordings on their way to the server, shown in the Library until the server has them. */
private val UPLOADING_STATES = setOf("queued", "uploading", "failed", "duplicate")
private val MOVING_STATES = setOf("queued", "uploading")

/** The line under a recording on its way to the server. */
object UploadRow {
    fun meta(state: String): String = when (state) {
        "failed" -> "Upload failed · Tap to retry, hold to remove"
        "duplicate" -> "Already in your Library · Tap to dismiss"
        "queued" -> "Waiting to upload · Hold to cancel"
        else -> "Uploading…"
    }
}

/** How often the Library asks again while something is uploading or being transcribed. */
private const val LIVE_REFRESH_MS = 5_000L
