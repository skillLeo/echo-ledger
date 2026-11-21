package com.echoledger.mobile.ui.v2.library

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.Text
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
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import com.echoledger.mobile.R
import com.echoledger.mobile.core.SourceApp
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.ListDto
import com.echoledger.mobile.data.LocalRecording
import com.echoledger.mobile.ui.MainViewModel
import com.echoledger.mobile.ui.v2.components.EchoAvatar
import com.echoledger.mobile.ui.v2.components.EchoBanner
import com.echoledger.mobile.ui.v2.components.EchoBannerKind
import com.echoledger.mobile.ui.v2.components.EchoBottomSheet
import com.echoledger.mobile.ui.v2.components.EchoEmptyState
import com.echoledger.mobile.ui.v2.components.EchoErrorState
import com.echoledger.mobile.ui.v2.components.EchoFilter
import com.echoledger.mobile.ui.v2.components.EchoFilterBar
import com.echoledger.mobile.ui.v2.components.EchoIconButton
import com.echoledger.mobile.ui.v2.components.EchoIcons
import com.echoledger.mobile.ui.v2.components.EchoListRow
import com.echoledger.mobile.ui.v2.components.EchoSearchField
import com.echoledger.mobile.ui.v2.components.EchoSectionHeader
import com.echoledger.mobile.ui.v2.components.EchoSettingRow
import com.echoledger.mobile.ui.v2.components.EchoSkeletonList
import com.echoledger.mobile.ui.v2.components.EchoStatus
import com.echoledger.mobile.ui.v2.components.EchoStatusKind
import com.echoledger.mobile.ui.v2.components.EchoTopBar
import com.echoledger.mobile.ui.v2.components.formatClock
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.shell.V2Text
import com.echoledger.mobile.ui.v2.shell.v2Text
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.theme.ProvidePageLook
import com.echoledger.mobile.ui.v2.theme.rememberPageLook
import com.echoledger.mobile.ui.v2.theme.screenLocale
import com.echoledger.mobile.ui.v2.tokens.EchoTokens
import java.time.ZonedDateTime
import kotlinx.coroutines.launch

/** A recording still on the phone (queued, uploading or failed to upload), shown above the library. */
data class UploadRow(
    val id: String,
    val title: String,
    val duration: String,
    val icon: androidx.compose.ui.graphics.vector.ImageVector,
    val failed: Boolean
)

/** What Library shows, worked out from the view model's state. */
sealed interface LibraryState {
    data object Loading : LibraryState
    data object Error : LibraryState
    data class Ready(val rows: List<V2Row>, val uploads: List<UploadRow>, val offline: Boolean, val anyAtAll: Boolean) : LibraryState
}

fun uploadRows(pending: List<LocalRecording>, text: V2Text): List<UploadRow> = pending
    .filter { it.state == "queued" || it.state == "uploading" || it.state == "failed" }
    .map {
        val app = SourceApp.from(it.sourceApp)
        UploadRow(
            it.id,
            it.title?.takeIf { t ->
                t.isNotBlank()
            } ?: it.callerName ?: text.sourceName(it.sourceApp),
            formatClock(it.durationMs + 500),
            LibraryModel.icon(app),
            it.state == "failed"
        )
    }

/** One section of Library: its key (to fold it), its name in the screen's language, and its rows. */
data class RowGroup(val key: String, val name: String, val rows: List<V2Row>)

/** A transcript match of a search: the recording's row and where in it the words are. */
data class TranscriptMatch(val row: V2Row, val atMs: Long, val snippet: String)

/**
 * Library on the v2 components, on the existing [MainViewModel] and its repositories, with every classic Library
 * feature (docs/PLAN_V2_PARITY.md section 3): selection mode (long-press; Move, Label, Share or export, Delete, and in ⋮
 * Pin, Keep forever, Rename, each export format), the filter chips (Views, Source, Date, Project, Language, Status,
 * Label, Bookmark) with Clear all, Sort and group (Group by, Sort by, Density, remembered per view), sections with
 * counts that fold, swipes (right Pin, left Delete with Undo), pull down or ⋮ › Sync now, ⋮ › Select and Recently
 * deleted, and search that finds titles on the phone and transcript words on the server.
 */
@Composable
fun V2LibraryScreen(
    vm: MainViewModel,
    initials: String,
    onOpen: (LibraryItem) -> Unit,
    onTrash: () -> Unit,
    onAccount: () -> Unit,
    modifier: Modifier = Modifier,
    initialViews: Set<SavedView> = emptySet(),
    /** Projects (Workspaces), while `ui_v2.ask` is on. */
    onProjects: (() -> Unit)? = null,
    /** A transcript match of a search opens the recording at its moment. */
    onOpenAt: (LibraryItem, Long) -> Unit = { item, _ -> onOpen(item) },
    /** What the shell adds: the Calls chip, the Activity bell, the setup card and the Needs action banner. */
    extras: LibraryExtras? = null
) {
    val text = v2Text
    val library by vm.library.collectAsState()
    val pending by vm.pending.collectAsState()
    val syncing by vm.syncing.collectAsState()
    val offline by vm.offline.collectAsState()
    val clients by vm.clients.collectAsState()
    val settings by vm.settings.collectAsState()
    val lists by vm.lists.collectAsState()
    // The chip row (D14): one chip at a time; a list that is gone goes back to All.
    var chosen by rememberSaveable { mutableStateOf(com.echoledger.mobile.core.LibraryLists.ALL) }
    val chip = com.echoledger.mobile.core.LibraryLists.resolve(chosen, lists)
    LaunchedEffect(chip) { if (chip != chosen) chosen = chip }
    // The lists are read on opening and again after each sync (another device may have changed them).
    LaunchedEffect(syncing) { if (!syncing) vm.loadLists() }
    var addingToList by remember { mutableStateOf(false) }
    var query by rememberSaveable { mutableStateOf("") }
    var filters by remember(initialViews) {
        mutableStateOf(
            V2Filters(
                base = com.echoledger.mobile.core.LibraryFilters(
                    views = if (SavedView.Today in initialViews) setOf(com.echoledger.mobile.core.SmartView.TODAY) else emptySet()
                ),
                untranscribed = SavedView.Untranscribed in initialViews
            )
        )
    }
    val viewKey = com.echoledger.mobile.core.LibraryGrouping.viewKey(filters.base)
    val choice = settings.libraryViews[viewKey] ?: com.echoledger.mobile.core.LibraryViewChoice.DEFAULT
    var sortOpen by remember { mutableStateOf(false) }
    var picked by remember { mutableStateOf<Set<String>>(emptySet()) }
    var folded by rememberSaveable { mutableStateOf(emptySet<String>()) }
    val locale = screenLocale()
    val now = remember(library) { ZonedDateTime.now() }
    val yesterday = stringResource(R.string.v2_lib_yesterday)
    val todayWord = stringResource(R.string.v2_lib_today)
    val thisWeek = stringResource(R.string.v2_lib_this_week)
    val visible = remember(library, filters, query, choice, settings.libraryOpened, chip, lists) {
        val chipped = com.echoledger.mobile.core.LibraryLists.filter(library, chip, lists)
        com.echoledger.mobile.core.LibraryQuery.apply(chipped, filters.base, query, choice.sortBy, opened = settings.libraryOpened)
            .filter { !filters.untranscribed || LibraryModel.status(it).let { s -> s == RowStatus.Untranscribed || s == RowStatus.Failed } }
    }
    val groupNames = groupNames()
    val groups = remember(visible, choice, text, locale, groupNames) {
        val day = { item: LibraryItem -> dayName(item, now, locale, todayWord, yesterday, thisWeek) }
        com.echoledger.mobile.core.LibraryGrouping.groups(visible, choice.groupBy, choice.sortBy, day).map { g ->
            RowGroup(g.key, groupNames[g.label] ?: g.label, g.items.map { LibraryModel.row(it, now, text, locale) })
        }
    }
    val rows = remember(groups) { groups.flatMap { it.rows } }
    val uploads = remember(pending, text) { uploadRows(pending, text) }
    val folders = remember(library, clients) { com.echoledger.mobile.core.Organise.folders(library, clients) }
    val labels = remember(library) { com.echoledger.mobile.core.Organise.labels(library) }
    val tags = remember(library) { com.echoledger.mobile.core.Bookmarks.tags(library) }
    val languages = remember(library) { library.mapNotNull { it.languageChip?.takeIf { l -> l.isNotBlank() } }.distinct().sorted() }
    // Transcript words found on the server (two characters or more), beside the titles matched here.
    var found by remember { mutableStateOf<List<TranscriptMatch>>(emptyList()) }
    val q = query.trim()
    LaunchedEffect(q) {
        found = if (q.length < 2) {
            emptyList()
        } else {
            kotlinx.coroutines.delay(350)
            vm.search(q).flatMap { hit ->
                hit.hits.take(1).map { h -> TranscriptMatch(LibraryModel.row(hit.session, now, text, locale), h.startMs, h.snippet) }
            }
        }
    }
    val state = when {
        library.isEmpty() && uploads.isEmpty() && syncing && !vm.hasSynced() -> LibraryState.Loading
        library.isEmpty() && uploads.isEmpty() && offline -> LibraryState.Error
        else -> LibraryState.Ready(rows, uploads, offline, library.any { !it.deleted } || uploads.isNotEmpty())
    }
    val toasts = LocalEchoToasts.current
    val scope = rememberCoroutineScope()
    val context = androidx.compose.ui.platform.LocalContext.current
    val undo = EchoV2.strings.undo
    val ticked = remember(picked, visible) { visible.mapTo(HashSet()) { it.id }.let { ids -> picked.filter { it in ids }.toSet() } }
    androidx.activity.compose.BackHandler(enabled = ticked.isNotEmpty()) { picked = emptySet() }
    val deletedN = { n: Int -> context.resources.getString(R.string.v2_lib_n_moved, n) }
    val restoredN = { n: Int -> context.resources.getString(R.string.v2_lib_n_restored, n) }
    val capWords = stringResource(R.string.v2_lib_select_cap, com.echoledger.mobile.core.Organise.MAX_BULK)

    /** Ticks [ids] (or unticks them all when [off]), never more than the server takes at once, with the reason. */
    fun pick(ids: Collection<String>, off: Boolean = false) {
        if (off) {
            picked = picked - ids.toSet()
            return
        }
        val room = com.echoledger.mobile.core.Organise.MAX_BULK - picked.size
        val adding = ids.filter { it !in picked }
        picked = picked + adding.take(room.coerceAtLeast(0))
        if (adding.size > room) scope.launch { toasts.show(capWords) }
    }

    // Classic's path for one or many: bulk "delete"; Undo shows only once they went, and brings back exactly those.
    fun deleteRows(items: List<LibraryItem>) {
        scope.launch {
            val outcome = vm.deleteMany(items.map { it.id }) ?: return@launch
            val went = outcome.went
            if (went.isEmpty()) {
                // Some went, but the sync after it failed, so which is not known: say how many, without Undo.
                if (outcome.changed > 0) toasts.show(if (outcome.changed == 1) text.deleted else deletedN(outcome.changed))
                return@launch
            }
            val message = if (went.size == 1) text.deleted else deletedN(went.size)
            toasts.showUndo(message, undo) {
                scope.launch {
                    val back = vm.restoreMany(went)
                    if (back > 0) toasts.show(restoredN(back))
                }
            }
        }
    }
    val res = androidx.compose.ui.platform.LocalContext.current.resources
    val listActions = remember(vm) {
        object : ListActions {
            /** The server's refusal of a name in the screen's words (the sheet already refuses the ones it knows). */
            private fun refused(e: Throwable, name: String) {
                val words = when (vm.problemOf(e)) {
                    MainViewModel.ListProblem.Reserved -> res.getString(R.string.v2_lists_reserved, name)
                    MainViewModel.ListProblem.Exists -> res.getString(R.string.v2_lists_duplicate, name)
                    MainViewModel.ListProblem.Other -> null
                }
                words?.let { scope.launch { toasts.show(it) } }
            }

            override suspend fun create(name: String, colour: String): ListDto? =
                vm.createList(name, colour.lowercase(java.util.Locale.ROOT)).onFailure { refused(it, name) }.getOrNull()

            override suspend fun rename(list: ListDto, name: String): Boolean =
                vm.updateList(list.key, name = name).onFailure { refused(it, name) }.isSuccess

            override suspend fun recolour(list: ListDto, colour: String) {
                vm.updateList(list.key, color = colour.lowercase(java.util.Locale.ROOT)).onFailure { refused(it, list.name) }
            }

            override suspend fun move(list: ListDto, delta: Int) {
                vm.moveList(list.key, delta)
            }

            override suspend fun delete(list: ListDto) {
                vm.deleteList(list.key)
            }
        }
    }
    if (addingToList) {
        val chosenItems = library.filter { it.id in picked }
        AddToListFlow(
            lists = lists,
            inAll = { list -> chosenItems.isNotEmpty() && chosenItems.all { com.echoledger.mobile.core.LibraryLists.carries(it, list) } },
            onToggle = { list, add ->
                scope.launch {
                    // Only the recordings that change: those not in it yet (adding) or in it (taking out).
                    val changing = if (add) {
                        com.echoledger.mobile.core.LibraryLists.gaining(chosenItems, list)
                    } else {
                        chosenItems.filter { com.echoledger.mobile.core.LibraryLists.carries(it, list) }.map { it.id }
                    }
                    val changed = vm.label(changing, list, add) ?: return@launch
                    if (changed.isEmpty()) return@launch
                    val words = res.getString(if (add) R.string.v2_lists_added else R.string.v2_lists_removed, list.name)
                    toasts.showUndo(words, undo) { scope.launch { vm.label(changed, list, !add) } }
                }
            },
            onCreate = { name, colour -> listActions.create(name, colour) },
            onDismiss = { addingToList = false }
        )
    }
    fun act(action: SelectionAction) {
        val ids = ticked.toList()
        val items = visible.filter { it.id in ticked }
        scope.launch {
            when (action) {
                is SelectionAction.Move -> vm.bulk(ids, "move", clientId = action.clientId ?: "")
                is SelectionAction.NewProject -> vm.createClient(action.name)?.let { vm.bulk(ids, "move", clientId = it.id) }
                is SelectionAction.Label -> vm.bulk(ids, if (action.add) "label" else "unlabel", label = action.label)
                is SelectionAction.Pin -> vm.bulk(ids, if (action.on) "pin" else "unpin")
                is SelectionAction.Keep -> vm.bulk(ids, if (action.on) "keep" else "unkeep")
                is SelectionAction.Export -> vm.exportSelected(ids, action.format)?.let { com.echoledger.mobile.ui.shareFile(context, it) }
                is SelectionAction.Rename -> ids.singleOrNull()?.let { vm.organise(it, title = action.title) }
                SelectionAction.Delete -> deleteRows(items)
            }
            if (action !is SelectionAction.Export) picked = emptySet()
        }
    }
    if (sortOpen) {
        SortGroupSheet(choice, { sortOpen = false }) { change ->
            vm.updateSettings { it.copy(libraryViews = it.libraryViews + (viewKey to change)) }
        }
    }
    V2LibraryContent(
        state = state,
        query = query,
        onQuery = { query = it },
        views = emptySet(),
        onToggleView = {},
        initials = initials,
        onOpen = {
            if (ticked.isNotEmpty()) {
                pick(listOf(it.id), off = it.id in picked)
            } else {
                vm.markOpened(it.item)
                onOpen(it.item)
            }
        },
        onDelete = { row -> deleteRows(listOf(row.item)) },
        onRetryUpload = { vm.retryUpload(it) },
        onRetry = { vm.refresh() },
        onTrash = onTrash,
        onAccount = onAccount,
        modifier = modifier,
        onProjects = onProjects,
        extras = extras,
        tools = LibraryTools(
            groups = groups,
            folded = folded,
            onFold = { key -> folded = if (key in folded) folded - key else folded + key },
            selected = ticked,
            onToggle = { row -> pick(listOf(row.id), off = row.id in picked) },
            onSelectGroup = { g ->
                val ids = g.rows.map { it.id }.toSet()
                pick(ids, off = picked.containsAll(ids))
            },
            selectionBar = {
                SelectionBar(
                    count = ticked.size,
                    allSelected = ticked.size == minOf(visible.size, com.echoledger.mobile.core.Organise.MAX_BULK),
                    oneTitle = visible.firstOrNull { it.id == ticked.singleOrNull() }?.title,
                    folders = folders,
                    labels = labels,
                    keepHours = settings.trashKeepHours,
                    onClose = { picked = emptySet() },
                    onSelectAll = { all ->
                        picked = emptySet()
                        if (all) pick(visible.map { it.id })
                    },
                    onAction = ::act,
                    choices = OrganiseChoices.of(visible.filter { it.id in ticked }),
                    onAddToList = { addingToList = true }
                )
            },
            filterRow = { LibraryFilterRow(filters, folders, labels, tags, languages) { filters = it } },
            listRow = {
                LibraryListRow(
                    lists = lists,
                    chip = chip,
                    unread = com.echoledger.mobile.core.LibraryLists.unread(library),
                    onChip = { chosen = it },
                    actions = listActions
                )
            },
            emptyList = when (chip) {
                com.echoledger.mobile.core.LibraryLists.ALL -> null
                com.echoledger.mobile.core.LibraryLists.UNREAD -> stringResource(R.string.v2_lists_unread)
                com.echoledger.mobile.core.LibraryLists.IMPORTANT -> stringResource(R.string.v2_lists_important)
                else -> com.echoledger.mobile.core.LibraryLists.chosenList(chip, lists)?.name
            },
            filtered = filters.count > 0,
            onClearFilters = { filters = V2Filters() },
            onSortGroup = { sortOpen = true },
            compact = choice.densityOf == com.echoledger.mobile.core.Density.COMPACT,
            refreshing = syncing && vm.hasSynced(),
            onSync = { vm.refresh() },
            onSelect = { visible.firstOrNull()?.let { picked = setOf(it.id) } },
            onPin = { row -> scope.launch { vm.bulk(listOf(row.id), if (row.item.pinned) "unpin" else "pin") } },
            matches = found,
            onOpenAt = { m -> onOpenAt(m.row.item, m.atMs) }
        )
    )
}

/**
 * What the shell adds to Library (docs/PLAN_V2_PARITY.md section 2): [actions] in the header before the avatar (the
 * Activity bell), [status] under the title (the "Calls: Ready" chip), [top] above the rows (the setup card and the one
 * Needs action banner), and [scrollToTop], which goes up by one each time the Library tab is tapped again.
 */
class LibraryExtras(
    val actions: @Composable () -> Unit = {},
    val status: @Composable () -> Unit = {},
    val top: @Composable () -> Unit = {},
    val scrollToTop: Int = 0
)

/** What the full Library adds to [V2LibraryContent]; null keeps the simple page (previews and older tests). */
class LibraryTools(
    val groups: List<RowGroup>,
    val folded: Set<String>,
    val onFold: (String) -> Unit,
    val selected: Set<String>,
    val onToggle: (V2Row) -> Unit,
    val onSelectGroup: (RowGroup) -> Unit,
    val selectionBar: @Composable () -> Unit,
    val filterRow: @Composable () -> Unit,
    val filtered: Boolean,
    val onClearFilters: () -> Unit,
    val onSortGroup: () -> Unit,
    val compact: Boolean,
    val refreshing: Boolean,
    val onSync: () -> Unit,
    val onSelect: () -> Unit,
    val onPin: (V2Row) -> Unit,
    val matches: List<TranscriptMatch>,
    val onOpenAt: (TranscriptMatch) -> Unit,
    /** The chip row of lists (D14): All · Unread · Important · the person's lists · "+". */
    val listRow: (@Composable () -> Unit)? = null,
    /** The chosen chip's name while one other than All is chosen: an empty list says "Nothing in Clients yet". */
    val emptyList: String? = null
)

/** LibraryGrouping's fixed English names in the screen's language. */
@Composable
private fun groupNames(): Map<String, String> =
    listOf("Pinned", "No project", "Other", "Voice notes", "Unknown device", "Automatic", "Ready", "Transcribing", "Needs you")
        .associateWith { groupLabel(it) }

/** A date section's name: Today, Yesterday, This week, then the month ("October 2026"), in the screen's language. */
fun dayName(item: LibraryItem, now: ZonedDateTime, locale: java.util.Locale, today: String, yesterday: String, thisWeek: String): String {
    val at = runCatching { java.time.OffsetDateTime.parse(item.startedAt).atZoneSameInstant(now.zone).toLocalDate() }.getOrNull()
        ?: return today
    val days = java.time.temporal.ChronoUnit.DAYS.between(at, now.toLocalDate())
    return when {
        days <= 0L -> today
        days == 1L -> yesterday
        days < 7L -> thisWeek
        else -> LibraryModel.latinDigits(at.format(java.time.format.DateTimeFormatter.ofPattern("LLLL yyyy", locale)))
    }
}

/** Library's page without the view model (previews and tests). */
@Composable
fun V2LibraryContent(
    state: LibraryState,
    query: String,
    onQuery: (String) -> Unit,
    views: Set<SavedView>,
    onToggleView: (SavedView) -> Unit,
    initials: String,
    onOpen: (V2Row) -> Unit,
    onDelete: (V2Row) -> Unit,
    onRetryUpload: (String) -> Unit,
    onRetry: () -> Unit,
    onTrash: () -> Unit,
    onAccount: () -> Unit,
    modifier: Modifier = Modifier,
    onProjects: (() -> Unit)? = null,
    tools: LibraryTools? = null,
    extras: LibraryExtras? = null
) {
    val c = EchoV2.colors
    val listState = androidx.compose.foundation.lazy.rememberLazyListState()
    val scrollToTop = extras?.scrollToTop ?: 0
    LaunchedEffect(scrollToTop) { if (scrollToTop > 0) listState.animateScrollToItem(0) }
    val text = v2Text
    var actionsFor by remember { mutableStateOf<V2Row?>(null) }
    var menu by remember { mutableStateOf(false) }
    val selecting = tools != null && tools.selected.isNotEmpty()
    Column(modifier.fillMaxSize().background(c.bgCanvas)) {
        if (selecting) {
            tools!!.selectionBar()
        } else {
            EchoTopBar(stringResource(R.string.v2_library), large = true) {
                onProjects?.let { EchoIconButton(EchoIcons.Folder, stringResource(R.string.v2_ws_projects), it) }
                if (tools == null) EchoIconButton(EchoIcons.Delete, text.trash, onTrash)
                extras?.actions?.invoke()
                if (tools != null) {
                    Box {
                        EchoIconButton(EchoIcons.More, stringResource(R.string.v2_lib_more), { menu = true })
                        val look = rememberPageLook()
                        androidx.compose.material3.DropdownMenu(expanded = menu, onDismissRequest = { menu = false }) {
                            ProvidePageLook(look) {
                                androidx.compose.material3.DropdownMenuItem(
                                    text = { Text(stringResource(R.string.v2_lib_select), style = EchoV2.type.body) },
                                    leadingIcon = { androidx.compose.material3.Icon(EchoIcons.SelectAll, null) },
                                    onClick = {
                                        menu = false
                                        tools.onSelect()
                                    }
                                )
                                androidx.compose.material3.DropdownMenuItem(
                                    text = { Text(stringResource(R.string.v2_lib_sync), style = EchoV2.type.body) },
                                    leadingIcon = { androidx.compose.material3.Icon(EchoIcons.Sync, null) },
                                    onClick = {
                                        menu = false
                                        tools.onSync()
                                    }
                                )
                                androidx.compose.material3.DropdownMenuItem(
                                    text = { Text(stringResource(R.string.v2_lib_recently_deleted), style = EchoV2.type.body) },
                                    leadingIcon = { androidx.compose.material3.Icon(EchoIcons.Delete, null) },
                                    onClick = {
                                        menu = false
                                        onTrash()
                                    }
                                )
                            }
                        }
                    }
                }
                // The avatar last, at the top end, as on every main screen.
                Box(
                    Modifier
                        .size(EchoTokens.Layout.controlTouchMin)
                        .clickable(onClickLabel = text.accountAndSettings, onClick = onAccount)
                        .semantics { contentDescription = text.accountAndSettings },
                    contentAlignment = Alignment.Center
                ) { com.echoledger.mobile.ui.v2.shell.PersonAvatar(initials) }
            }
        }
        if (!selecting && extras != null) {
            Box(Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile).padding(bottom = EchoTokens.Space.s8)) {
                extras.status()
            }
        }
        if (!selecting) {
            Row(
                Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile),
                verticalAlignment = Alignment.CenterVertically,
                horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s4)
            ) {
                EchoSearchField(query, onQuery, text.searchRecordings, Modifier.weight(1f))
                tools?.let { EchoIconButton(EchoIcons.Sort, stringResource(R.string.v2_lib_sort_group), it.onSortGroup) }
            }
            if (tools != null) {
                tools.listRow?.invoke()
                tools.filterRow()
            } else {
                EchoFilterBar(
                    filters = listOf(
                        EchoFilter(SavedView.Today.name, text.today),
                        EchoFilter(SavedView.Untranscribed.name, text.untranscribed)
                    ),
                    selected = views.map { it.name }.toSet(),
                    onToggle = { onToggleView(SavedView.valueOf(it)) },
                    modifier = Modifier.padding(vertical = EchoTokens.Space.s4)
                )
            }
        }
        when (state) {
            LibraryState.Loading -> EchoSkeletonList(7, Modifier.weight(1f))

            LibraryState.Error -> Box(Modifier.weight(1f)) {
                EchoErrorState(text.loadErrorTitle, text.loadErrorBody, actionLabel = text.tryAgain, onAction = onRetry)
            }

            is LibraryState.Ready -> androidx.compose.material3.pulltorefresh.PullToRefreshBox(
                isRefreshing = tools?.refreshing == true,
                onRefresh = { tools?.onSync?.invoke() ?: onRetry() },
                modifier = Modifier.weight(1f)
            ) {
                LazyColumn(Modifier.fillMaxSize(), state = listState) {
                    if (extras != null && !selecting) item(key = "extras-top") { extras.top() }
                    if (state.offline) {
                        item {
                            EchoBanner(
                                text.offlineBanner,
                                EchoBannerKind.Info,
                                Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s8),
                                actionLabel = text.tryAgain,
                                onAction = onRetry
                            )
                        }
                    }
                    items(state.uploads, key = { "local-" + it.id }) { up ->
                        EchoListRow(
                            title = up.title,
                            meta = if (up.failed) null else text.uploading,
                            metaEnd = { Text(up.duration, style = EchoV2.type.mono, color = c.textSecondary) },
                            status = if (up.failed) ({ EchoStatus(text.failed, EchoStatusKind.Danger) }) else null,
                            leading = { EchoAvatar(icon = up.icon) },
                            tall = true,
                            onClickLabel = if (up.failed) text.tryAgain else null,
                            onClick = if (up.failed) ({ onRetryUpload(up.id) }) else null
                        )
                    }
                    if (state.rows.isEmpty() && state.uploads.isEmpty()) {
                        item {
                            when {
                                tools?.emptyList != null && tools.filtered != true && query.isBlank() -> EchoEmptyState(
                                    stringResource(R.string.v2_lists_empty_title, tools.emptyList),
                                    stringResource(R.string.v2_lists_empty_body),
                                    icon = EchoIcons.Label
                                )

                                tools?.filtered == true -> EchoEmptyState(
                                    stringResource(R.string.v2_lib_nothing_matches),
                                    stringResource(R.string.v2_lib_nothing_matches_body),
                                    icon = EchoIcons.Search,
                                    actionLabel = stringResource(R.string.v2_lib_clear_filters),
                                    onAction = tools.onClearFilters
                                )

                                state.anyAtAll -> EchoEmptyState(text.noMatchTitle, text.noMatchBody, icon = EchoIcons.Search)

                                else -> EchoEmptyState(text.noRecordingsTitle, text.noRecordingsBody, icon = EchoIcons.Library)
                            }
                        }
                    }
                    if (tools == null) {
                        items(state.rows, key = { it.id }) { row ->
                            LibraryRow(row, selected = null, compact = false, onOpen = { onOpen(row) }, onLongClick = { actionsFor = row })
                        }
                    } else {
                        val searching = query.trim().length >= 2
                        if (searching &&
                            state.rows.isNotEmpty()
                        ) {
                            item(key = "h-titles") { SearchHeader(stringResource(R.string.v2_lib_titles)) }
                        }
                        tools.groups.forEach { g ->
                            val showHeader = g.name.isNotEmpty() && !searching
                            if (showHeader) {
                                item(key = "h-" + g.key) {
                                    val ids = g.rows.map { it.id }
                                    SectionHeader(
                                        g.name,
                                        g.rows.size,
                                        g.key in tools.folded,
                                        selecting,
                                        tools.selected.containsAll(ids),
                                        onFold = { tools.onFold(g.key) },
                                        onSelect = { tools.onSelectGroup(g) }
                                    )
                                }
                            }
                            if (!showHeader || g.key !in tools.folded) {
                                items(g.rows, key = { it.id }) { row ->
                                    SwipeRow(row.item.pinned, enabled = !selecting, onPin = {
                                        tools.onPin(row)
                                    }, onDelete = { onDelete(row) }) {
                                        LibraryRow(
                                            row,
                                            selected = if (selecting) row.id in tools.selected else null,
                                            compact = tools.compact,
                                            onOpen = { onOpen(row) },
                                            onLongClick = { tools.onToggle(row) }
                                        )
                                    }
                                }
                            }
                        }
                        if (searching && tools.matches.isNotEmpty()) {
                            item(key = "h-transcripts") { SearchHeader(stringResource(R.string.v2_lib_transcripts)) }
                            items(tools.matches, key = { "t-" + it.row.id + "-" + it.atMs }) { m ->
                                EchoListRow(
                                    title = m.row.title,
                                    meta = m.snippet,
                                    titleEnd = formatClock(m.atMs),
                                    leading = { EchoAvatar(icon = m.row.icon, contentDescription = m.row.source) },
                                    tall = true,
                                    onClick = { tools.onOpenAt(m) }
                                )
                            }
                        }
                    }
                }
            }
        }
    }
    actionsFor?.let { row ->
        EchoBottomSheet(onDismiss = { actionsFor = null }, title = row.title) {
            EchoSettingRow(text.delete, icon = EchoIcons.Delete, onClick = {
                actionsFor = null
                onDelete(row)
            })
        }
    }
}

@Composable
private fun SearchHeader(words: String) = EchoSectionHeader(words)

/**
 * One recording's row: its source, title, the transcript's first line or project, time, length, status, the screens
 * count and the pinned mark; in selection mode a tick ([selected] not null) and a tap selects.
 */
@Composable
private fun LibraryRow(row: V2Row, selected: Boolean?, compact: Boolean, onOpen: () -> Unit, onLongClick: () -> Unit) {
    val c = EchoV2.colors
    val text = v2Text
    val pinned = stringResource(R.string.v2_lib_pinned_mark)
    EchoListRow(
        title = row.title,
        // A selected row is tinted all across, as classic's is.
        modifier = if (selected == true) Modifier.background(c.accentSoft) else Modifier,
        meta = row.meta,
        titleEnd = row.time,
        metaEnd = {
            // The screens icon and count (meeting assets) before the length, when it has any.
            val screens = row.item.screenCount ?: 0
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
                if (row.item.pinned) {
                    androidx.compose.material3.Icon(
                        EchoIcons.Pin,
                        pinned,
                        Modifier.size(EchoTokens.Icon.sm),
                        tint = c.textSecondary
                    )
                }
                if (screens > 0) ScreensCountMark(screens)
                // A length never breaks over two lines, at any text size.
                Text(row.duration, style = EchoV2.type.mono, color = c.textSecondary, maxLines = 1, softWrap = false)
            }
        },
        status = statusBadge(row.status, text),
        leading = {
            if (selected != null) {
                androidx.compose.material3.Checkbox(selected, null)
            } else {
                EchoAvatar(icon = row.icon, contentDescription = row.source)
            }
        },
        tall = !compact,
        onLongClickLabel = stringResource(R.string.v2_lib_select),
        onLongClick = onLongClick,
        onClick = onOpen
    )
}

/** At most one badge per row: only what needs attention. */
private fun statusBadge(status: RowStatus, text: V2Text): (@Composable () -> Unit)? = when (status) {
    RowStatus.Done -> null
    RowStatus.Untranscribed -> ({ EchoStatus(text.untranscribed, EchoStatusKind.Neutral) })
    RowStatus.Transcribing -> ({ EchoStatus(text.transcribing, EchoStatusKind.Accent) })
    RowStatus.Failed -> ({ EchoStatus(text.failed, EchoStatusKind.Danger) })
}
