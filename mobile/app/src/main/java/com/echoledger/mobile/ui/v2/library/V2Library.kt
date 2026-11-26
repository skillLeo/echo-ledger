package com.echoledger.mobile.ui.v2.library

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.gestures.scrollBy
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.defaultMinSize
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
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
import androidx.compose.ui.draw.clipToBounds
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.focus.onFocusChanged
import androidx.compose.ui.input.nestedscroll.nestedScroll
import androidx.compose.ui.layout.layout
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import com.echoledger.mobile.R
import com.echoledger.mobile.core.FilterSection
import com.echoledger.mobile.core.LibraryFacets
import com.echoledger.mobile.core.LibraryFilterSet
import com.echoledger.mobile.core.LibraryKind
import com.echoledger.mobile.core.LibraryPlace
import com.echoledger.mobile.core.ProjectNode
import com.echoledger.mobile.core.SourceApp
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.ListDto
import com.echoledger.mobile.data.LocalRecording
import com.echoledger.mobile.ui.MainViewModel
import com.echoledger.mobile.ui.v2.components.EchoAvatar
import com.echoledger.mobile.ui.v2.components.EchoBackToTop
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
import com.echoledger.mobile.ui.v2.components.LocalPageMargin
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
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.launch

/**
 * A recording still on the phone (waiting to upload, uploading, failed to upload, or an import the Library already
 * had), shown above the library.
 */
data class UploadRow(
    val id: String,
    val title: String,
    val duration: String,
    val icon: androidx.compose.ui.graphics.vector.ImageVector,
    val failed: Boolean,
    /** "queued" | "uploading" | "failed" | "duplicate" (classic's upload states), or "waiting" (for Transcribe or Delete). */
    val state: String = if (failed) "failed" else "uploading",
    /** While uploading: how much of it was sent (0-100), null before the first part went. */
    val percent: Int? = null
) {
    /** Classic's rule: one waiting or failed can be deleted (after a question); not one being sent right now. */
    val deletable: Boolean get() = state == "queued" || state == "failed"
}

/** What Library shows, worked out from the view model's state. */
sealed interface LibraryState {
    data object Loading : LibraryState
    data object Error : LibraryState
    data class Ready(val rows: List<V2Row>, val uploads: List<UploadRow>, val offline: Boolean, val anyAtAll: Boolean) : LibraryState
}

/**
 * [sent]: parts sent of all parts per recording uploading now (the upload worker's
 * [com.echoledger.mobile.core.RowProgress.uploads]); [waiting]: the calls waiting for Transcribe or Delete too (Needs you).
 */
fun uploadRows(
    pending: List<LocalRecording>,
    text: V2Text,
    sent: Map<String, Pair<Int, Int>> = emptyMap(),
    waiting: Boolean = false
): List<UploadRow> = pending
    .filter {
        it.state == "queued" || it.state == "uploading" || it.state == "failed" || it.state == "duplicate" ||
            (waiting && it.state == "pending_decision")
    }
    .map {
        val app = SourceApp.from(it.sourceApp)
        UploadRow(
            it.id,
            it.title?.takeIf { t ->
                t.isNotBlank()
            } ?: it.callerName ?: text.sourceName(it.sourceApp),
            formatClock(it.durationMs + 500),
            LibraryModel.icon(app),
            it.state == "failed",
            if (it.state == "pending_decision") "waiting" else it.state,
            sent[it.id]?.takeIf { _ -> it.state == "uploading" }
                ?.let { (done, total) -> com.echoledger.mobile.core.RowProgress.uploading(done, total).percent }
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
    extras: LibraryExtras? = null,
    /** The title menu's Shared ▸ Shared with me and Request files. */
    onSharedWithMe: () -> Unit = {},
    onRequests: () -> Unit = {},
    /** A call waiting for Transcribe or Delete, listed in Needs you: where the choice is made (Activity). */
    onReviewWaiting: () -> Unit = {},
    /** The tab it opens on, as one kept from before (tests); the person's own choice is kept across a rotation anyway. */
    initialKind: LibraryKind = LibraryKind.ALL
) {
    val text = v2Text
    // U19: each row's snippet comes in its default view (Roman Urdu) once ready (transcript.preview_view says which).
    val library by vm.library.collectAsState()
    val pending by vm.pending.collectAsState()
    val syncing by vm.syncing.collectAsState()
    val offline by vm.offline.collectAsState()
    val clients by vm.clients.collectAsState()
    val settings by vm.settings.collectAsState()
    // Live status (classic's rule): while something is uploading, being transcribed or converted to its default view,
    // the list asks again every 5 s, so "Converting to Roman Urdu… 60%" moves and goes; it stops once all is done.
    // A view still `making` 20 minutes after the recording last changed has stopped on the server (it says failed,
    // which no sync brings): it no longer keeps the list asking; its views are read as the Library opens, then about
    // 5 and 15 minutes on, instead.
    val stale = remember(library) { library.filter { LibraryModel.viewsStale(it) } }
    LaunchedEffect(stale.map { it.id }) {
        for (wait in LibraryModel.VIEWS_RECHECKS) {
            kotlinx.coroutines.delay(wait)
            stale.forEach(vm::recheckViews)
        }
    }
    val busy = pending.any { it.state == "uploading" || it.state == "queued" } ||
        library.any {
            com.echoledger.mobile.core.ItemStatus.of(it) == com.echoledger.mobile.core.ItemStatus.PROCESSING ||
                (it.transcript?.views?.status == "making" && it !in stale)
        }
    LaunchedEffect(busy) {
        while (busy) {
            kotlinx.coroutines.delay(LIVE_REFRESH_MS)
            vm.reloadLocal()
            vm.refreshQuietly()
        }
    }
    val lists by vm.lists.collectAsState()
    val savedViews by vm.savedViews.collectAsState()
    val viewsReady by vm.savedViewsReady.collectAsState()
    // The lists are read on opening and again after each sync (another device may have changed them).
    LaunchedEffect(syncing) { if (!syncing) vm.loadLists() }
    var addingToList by remember { mutableStateOf(false) }
    var asking by remember { mutableStateOf<TranscribeAsk?>(null) }
    var query by rememberSaveable { mutableStateOf("") }
    // Where Library is (the title menu: a view, one of My views or a label), its filters (the contract's filter set) and
    // its tab (All · Calls · Meetings · Voice notes · Other), each kept across a rotation.
    var targetKey by rememberSaveable(initialViews) { mutableStateOf(LibraryScreenState.initialTarget(initialViews).key) }
    var filtersJson by rememberSaveable(initialViews) {
        mutableStateOf(LibraryScreenState.json(LibraryScreenState.initialFilters(initialViews)))
    }
    val filters = remember(filtersJson) { LibraryScreenState.filters(filtersJson) }
    fun setFilters(next: LibraryFilterSet) {
        filtersJson = LibraryScreenState.json(next)
    }
    var kindKey by rememberSaveable { mutableStateOf(initialKind.name) }
    val kind = LibraryKind.entries.firstOrNull { it.name == kindKey } ?: LibraryKind.ALL
    // A list or a view that is gone (deleted here or elsewhere) goes back to All recordings.
    val target = LibraryScreenState.resolve(LibraryTarget.parse(targetKey), lists, savedViews, viewsReady)
    LaunchedEffect(target.key) { if (target.key != targetKey) targetKey = target.key }
    // The Library tab tapped again: All recordings, with the filters, the tab and the search cleared (and the list at
    // its top, LibraryExtras.scrollToTop).
    val reset = extras?.scrollToTop ?: 0
    var seenReset by rememberSaveable { androidx.compose.runtime.mutableIntStateOf(reset) }
    LaunchedEffect(reset) {
        if (reset > seenReset) {
            seenReset = reset
            targetKey = LibraryTarget.ALL.key
            setFilters(LibraryFilterSet())
            kindKey = LibraryKind.ALL.name
            query = ""
        }
    }
    val viewKey = LibraryScreenState.choiceKey(target, savedViews)
    val choice = settings.libraryViews[viewKey] ?: com.echoledger.mobile.core.LibraryViewChoice.DEFAULT
    var sortOpen by remember { mutableStateOf(false) }
    var picked by remember { mutableStateOf<Set<String>>(emptySet()) }
    var folded by rememberSaveable { mutableStateOf(emptySet<String>()) }
    val locale = screenLocale()
    val now = remember(library) { ZonedDateTime.now() }
    val today = now.toLocalDate()
    val yesterday = stringResource(R.string.v2_lib_yesterday)
    val todayWord = stringResource(R.string.v2_lib_today)
    val thisWeek = stringResource(R.string.v2_lib_this_week)
    // The person's projects as a tree; one a recording is in that the list doesn't have yet (offline) is offered too.
    val projects = remember(clients, library) { LibraryScreenState.projects(clients, library) }
    // Every rule of contract 2.0.0-p worked out here, from the synced library (LibraryFacets): instant and offline.
    val waitingCalls by vm.libraryWaiting.collectAsState()
    // Every recording still on this phone, as the server may know it (a waiting call of its own is never twice).
    val own = remember(pending) { pending.map { LibraryScreenState.refs(it) } }
    val facts = remember(library, waitingCalls, own, projects, text) {
        LibraryFacets.prepare(LibraryScreenState.withWaiting(library, waitingCalls, own), projects, now.zone, now.toInstant()) {
            LibraryScreenState.shownTitle(it, text)
        }
    }
    // This phone's own that need the person (each by every reference the server may know it by).
    val localNeeds = remember(pending, now) {
        pending.filter { LibraryScreenState.localNeeds(it, now.toInstant()) }.map { LibraryScreenState.refs(it) }
    }
    val words = remember(query) { LibraryFacets.searchWords(query) }
    val searched = remember(facts, words) { facts.filter { it.matches(words) } }
    val placed = remember(searched, target) { LibraryScreenState.inTarget(searched, target) }
    val filtered = remember(placed, filters, today) { LibraryFacets.filter(placed, filters, today) }
    val kindCounts = remember(filtered) { LibraryKind.entries.associateWith { k -> filtered.count { k.holds(it.kind) } } }
    // Other chosen while it has nothing any more (the filters, the search or a sync took its last one): back to All. Only
    // once it had something here, or the library has synced: a first frame before the cache is read (back from the
    // background, the process made again) keeps the Other the person chose.
    val otherCount = kindCounts[LibraryKind.OTHER] ?: 0
    var otherHad by remember { mutableStateOf(false) }
    // Synced as of the list this frame shows, never mid-sync: read with the list (a sync sets its point just before its
    // items), so a synced library without any Other goes back to All once its sync ends, and only then.
    val synced = remember(library, syncing) { !syncing && vm.hasSynced() }
    LaunchedEffect(kind, otherCount, synced) {
        if (otherCount > 0) otherHad = true
        if (LibraryKind.backToAll(kind, otherCount, otherHad, synced)) kindKey = LibraryKind.ALL.name
    }
    val counted = remember(placed, kind) { placed.filter { kind.holds(it.kind) } }
    val waiting = remember(facts, target) { LibraryScreenState.waiting(facts, target) }
    val visible = remember(filtered, kind, choice, settings.libraryOpened, waiting) {
        com.echoledger.mobile.core.LibraryQuery.apply(
            (filtered.filter { kind.holds(it.kind) } + waiting).map { it.item },
            com.echoledger.mobile.core.LibraryFilters(),
            "",
            choice.sortBy,
            opened = settings.libraryOpened
        )
    }
    val groupNames = groupNames()
    val groups = remember(visible, choice, text, locale, groupNames) {
        val day = { item: LibraryItem -> dayName(item, now, locale, todayWord, yesterday, thisWeek) }
        com.echoledger.mobile.core.LibraryGrouping.groups(visible, choice.groupBy, choice.sortBy, day).map { g ->
            RowGroup(g.key, groupNames[g.label] ?: g.label, g.items.map { LibraryModel.row(it, now, text, locale) })
        }
    }
    val rows = remember(groups) { groups.flatMap { it.rows } }
    val sending by com.echoledger.mobile.core.RowProgress.uploads.collectAsState()
    // What waits on this phone: in All recordings, and in Needs you the calls waiting for a choice and the uploads that
    // failed or are stuck (each counted in Needs you), whatever the filters.
    val uploads = remember(pending, text, sending, target, now) {
        when (target) {
            LibraryTarget.ALL -> uploadRows(pending, text, sending)

            LibraryTarget.Place(LibraryPlace.NEEDS_YOU) ->
                uploadRows(pending.filter { LibraryScreenState.localNeeds(it, now.toInstant()) }, text, sending, waiting = true)

            else -> emptyList()
        }
    }
    val folders = remember(library, clients) { com.echoledger.mobile.core.Organise.folders(library, clients) }
    val labels = remember(library) { com.echoledger.mobile.core.Organise.labels(library) }
    val res0 = androidx.compose.ui.platform.LocalContext.current.resources
    val filterWords = remember(res0, locale, clients, lists, library) { LibraryScreenState.words(res0, locale, clients, lists, library) }
    val depth = remember(projects) { LibraryFacets.depth(projects) }
    val placeCounts = remember(facts, localNeeds) {
        LibraryFacets.placeCounts(facts) + (LibraryPlace.NEEDS_YOU to LibraryFacets.needsYouCount(facts, localNeeds))
    }
    val labelCounts = remember(facts) { LibraryScreenState.labelCounts(facts) }
    val openView = (target as? LibraryTarget.View)?.let { t -> savedViews.firstOrNull { it.id == t.id } }
    val spec = LibraryScreenState.spec(filters, query, choice)
    val viewChanged = openView != null && openView.spec.clean() != spec.clean()
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

                    // The server names the list that has it (its own spelling).
                    MainViewModel.ListProblem.Exists ->
                        res.getString(R.string.v2_lists_duplicate, (e as? com.echoledger.mobile.net.ApiException)?.name ?: name)

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
    asking?.let { ask ->
        TranscribeDialog(ask, onDismiss = { asking = null }) {
            asking = null
            scope.launch {
                val outcome = vm.transcribeMany(ask.ids)
                if (outcome is MainViewModel.TranscribeOutcome.Started) picked = emptySet()
                toasts.show(transcribeWords(res, outcome))
            }
        }
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

                is SelectionAction.Important -> vm.bulk(ids, if (action.on) "important" else "unimportant")

                is SelectionAction.Export -> vm.exportSelected(ids, action.format)?.let { com.echoledger.mobile.ui.shareFile(context, it) }

                is SelectionAction.Rename -> ids.singleOrNull()?.let { vm.organise(it, title = action.title) }

                SelectionAction.Transcribe -> {
                    val audio = items.filter { LibraryModel.audioOnly(it) }.map { it.id }
                    val estimate = vm.transcribeEstimate(audio)
                    when {
                        estimate == null -> toasts.show(res.getString(R.string.v2_tx_failed))
                        estimate.items.isEmpty() -> toasts.show(res.getString(R.string.v2_tx_none))
                        else -> asking = TranscribeAsk(estimate.items.map { it.id }, estimate)
                    }
                }

                SelectionAction.CopyForAi -> {
                    // "Copying…" while the transcripts are read; it gives way to the outcome.
                    val copying = if (items.size > 1) scope.launch { toasts.show(res.getString(R.string.v2_lib_copying)) } else null
                    val copied = try {
                        copyForAi(vm, context, items, settings)
                    } finally {
                        copying?.cancel()
                    }
                    toasts.show(
                        when {
                            copied == 0 -> res.getString(R.string.v2_lib_copy_ai_none)
                            copied == items.size -> res.getString(R.string.v2_rec_copied_ai)
                            else -> res.getQuantityString(R.plurals.v2_lib_copy_ai_some, copied, copied, items.size)
                        }
                    )
                }

                SelectionAction.Delete -> deleteRows(items)

                // Contract 2.0.0-y: what wasn't done stays selected, and the words say how many.
                SelectionAction.MarkRead, SelectionAction.MarkUnread -> {
                    val run = vm.setOpened(ids, action == SelectionAction.MarkRead)
                    // Only what was done or is gone leaves: a tick changed meanwhile, or one a filter hides, stays.
                    picked = picked - run.done - run.notFound
                    toasts.show(openedWords(res, run, action == SelectionAction.MarkRead))
                }
            }
            val keepsSelection = action is SelectionAction.Export || action is SelectionAction.CopyForAi ||
                action is SelectionAction.Transcribe || action == SelectionAction.MarkRead || action == SelectionAction.MarkUnread
            if (!keepsSelection) picked = emptySet()
        }
    }
    if (sortOpen) {
        SortGroupSheet(choice, { sortOpen = false }) { change ->
            vm.updateSettings { it.copy(libraryViews = it.libraryViews + (viewKey to change)) }
        }
    }
    // The last five searches, kept on this phone across restarts (classic's recentSearches, never in a settings export).
    fun keepSearch(words: String) {
        val w = words.trim()
        if (w.isEmpty()) return
        vm.updateSettings { s ->
            s.copy(recentSearches = (listOf(w) + s.recentSearches.filterNot { it.equals(w, ignoreCase = true) }).take(5))
        }
    }
    // A change to My views the server refused for good: given back or dropped, and said.
    LaunchedEffect(vm) { vm.viewProblems.collect { toasts.show(viewWords(res, it)) } }
    var menuOpen by remember { mutableStateOf(false) }
    var filtersAt by remember { mutableStateOf<FilterSection?>(null) }
    var filtersOpen by remember { mutableStateOf(false) }
    var savingView by remember { mutableStateOf(false) }
    var renamingView by remember { mutableStateOf<com.echoledger.mobile.data.MyView?>(null) }
    var deletingView by remember { mutableStateOf<com.echoledger.mobile.data.MyView?>(null) }
    var newLabel by remember { mutableStateOf(false) }
    var renamingLabel by remember { mutableStateOf<ListDto?>(null) }
    var recolouringLabel by remember { mutableStateOf<ListDto?>(null) }
    var deletingLabel by remember { mutableStateOf<ListDto?>(null) }

    /** Opens [t]: one of My views brings its filters, search and display; leaving one clears them. */
    fun choose(t: LibraryTarget) {
        menuOpen = false
        picked = emptySet()
        val view = (t as? LibraryTarget.View)?.let { v -> savedViews.firstOrNull { it.id == v.id } }
        when {
            view != null -> {
                setFilters(view.spec.filterSet)
                query = view.spec.search.orEmpty()
                kindKey = LibraryKind.ALL.name
                val key = LibraryScreenState.choiceKey(t, savedViews)
                vm.updateSettings { it.copy(libraryViews = it.libraryViews + (key to LibraryScreenState.choiceOf(view.spec))) }
            }

            target is LibraryTarget.View -> {
                setFilters(LibraryFilterSet())
                query = ""
            }
        }
        targetKey = t.key
    }

    /** Runs a change to My views; a refusal here (a name in use, 30 views…) is said, or returned for the sheet. */
    fun views(change: () -> Unit): String? = try {
        change()
        null
    } catch (e: com.echoledger.mobile.data.ViewError) {
        viewWords(res, e)
    }
    val title = when (target) {
        is LibraryTarget.Place -> placeName(target.place)
        is LibraryTarget.Label -> lists.firstOrNull { it.key == target.label }?.name.orEmpty()
        is LibraryTarget.View -> openView?.name.orEmpty()
    }
    // The recording opened last (UX-25): back from it, the list shows it with a light tint, focused, where it was. The
    // tint stays until another row is opened or tapped, the view, tab or filters change, or 60 s pass.
    // The row and when its tint ends (SystemClock.elapsedRealtime) are saved, so a rotation or the process made again
    // right after coming back keeps it for the time it has left.
    var openedLast by rememberSaveable { mutableStateOf<String?>(null) }
    var returnedTo by rememberSaveable { mutableStateOf<String?>(null) }
    var tintEnds by rememberSaveable { androidx.compose.runtime.mutableLongStateOf(0L) }
    LaunchedEffect(Unit) {
        openedLast?.let {
            returnedTo = it
            tintEnds = android.os.SystemClock.elapsedRealtime() + RETURN_TINT_MS
            openedLast = null
        }
    }
    LaunchedEffect(returnedTo) {
        if (returnedTo != null) {
            kotlinx.coroutines.delay((tintEnds - android.os.SystemClock.elapsedRealtime()).coerceIn(0L, RETURN_TINT_MS))
            returnedTo = null
        }
    }
    // The view, its filters and its tab: each such list keeps its own scroll position; a change ends the tint.
    val place = "$viewKey|${filtersJson.hashCode()}|${kind.name}"
    var tintedIn by rememberSaveable { mutableStateOf(place) }
    if (tintedIn != place) {
        tintedIn = place
        returnedTo = null
    }
    V2LibraryContent(
        state = state,
        query = query,
        onQuery = { query = it },
        views = emptySet(),
        onToggleView = {},
        initials = initials,
        onOpen = {
            returnedTo = null
            if (ticked.isNotEmpty()) {
                pick(listOf(it.id), off = it.id in picked)
            } else {
                keepSearch(query)
                vm.markOpened(it.item)
                openedLast = it.id
                onOpen(it.item)
            }
        },
        onDelete = { row -> deleteRows(listOf(row.item)) },
        onRetryUpload = { vm.retryUpload(it) },
        onDismissUpload = { vm.dismissLocal(it) },
        onDeleteUpload = { vm.deleteNotUploaded(it) },
        onReviewWaiting = onReviewWaiting,
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
            onToggle = { row ->
                returnedTo = null
                pick(listOf(row.id), off = row.id in picked)
            },
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
                    onAddToList = { addingToList = true },
                    canTranscribe = visible.any { it.id in ticked && LibraryModel.audioOnly(it) }
                )
            },
            filterRow = {
                LibraryKindRow(kind, kindCounts, filters.count, { kindKey = it.name }, onSortDisplay = { sortOpen = true }) {
                    filtersAt = null
                    filtersOpen = true
                }
                if (!filters.isEmpty) {
                    LibraryPillsRow(
                        filters,
                        filterWords,
                        onEdit = {
                            filtersAt = it
                            filtersOpen = true
                        },
                        onRemove = { setFilters(filters.clear(it)) },
                        onClearAll = { setFilters(LibraryFilterSet()) },
                        saveLabel = when {
                            !viewsReady -> null
                            viewChanged -> stringResource(R.string.v2_lf_save_changes)
                            openView == null -> stringResource(R.string.v2_lf_save_view)
                            else -> null
                        },
                        onSave = {
                            val v = openView
                            if (v != null && viewChanged) {
                                views { vm.updateView(v.id, spec) }?.let { scope.launch { toasts.show(it) } }
                            } else {
                                savingView = true
                            }
                        }
                    )
                }
            },
            emptyList = when (target) {
                LibraryTarget.ALL -> null
                else -> title
            },
            filtered = !filters.isEmpty || kind != LibraryKind.ALL,
            onClearFilters = {
                setFilters(LibraryFilterSet())
                kindKey = LibraryKind.ALL.name
            },
            onSortGroup = { sortOpen = true },
            compact = choice.densityOf == com.echoledger.mobile.core.Density.COMPACT,
            swipes = settings.librarySwipes,
            refreshing = syncing && vm.hasSynced(),
            onSync = { vm.refresh() },
            onSelect = { visible.firstOrNull()?.let { picked = setOf(it.id) } },
            onPin = { row -> scope.launch { vm.bulk(listOf(row.id), if (row.item.pinned) "unpin" else "pin") } },
            matches = found,
            onOpenAt = { m ->
                keepSearch(query)
                onOpenAt(m.row.item, m.atMs)
            },
            recent = settings.recentSearches,
            onKeepSearch = ::keepSearch,
            title = title,
            onTitle = { menuOpen = true },
            place = place,
            returnedTo = returnedTo
        )
    )
    if (menuOpen) {
        LibraryMenuSheet(
            LibraryMenuData(target, placeCounts, savedViews, viewsReady, lists, labelCounts),
            LibraryMenuActions(
                onChoose = ::choose,
                onNewView = {
                    menuOpen = false
                    if (filters.isEmpty && query.isBlank()) {
                        filtersAt = null
                        filtersOpen = true
                    } else {
                        savingView = true
                    }
                },
                onRenameView = {
                    menuOpen = false
                    renamingView = it
                },
                onMoveView = { v, delta -> views { vm.moveView(v.id, delta) }?.let { scope.launch { toasts.show(it) } } },
                onDeleteView = {
                    menuOpen = false
                    deletingView = it
                },
                onNewLabel = {
                    menuOpen = false
                    newLabel = true
                },
                onRenameLabel = {
                    menuOpen = false
                    renamingLabel = it
                },
                onRecolourLabel = {
                    menuOpen = false
                    recolouringLabel = it
                },
                onMoveLabel = { list, delta -> scope.launch { listActions.move(list, delta) } },
                onDeleteLabel = {
                    menuOpen = false
                    deletingLabel = it
                },
                onSharedWithMe = {
                    menuOpen = false
                    onSharedWithMe()
                },
                onRequests = {
                    menuOpen = false
                    onRequests()
                },
                // Unread's own ⋮ (as the desktop's and the web's Unread view): every unread recording it lists.
                onMarkAllRead = {
                    menuOpen = false
                    val unread = facts.filter { LibraryFacets.inPlace(it, LibraryPlace.UNREAD) }.map { it.item.id }
                    scope.launch { toasts.show(openedWords(res, vm.setOpened(unread, true), true, selection = false)) }
                },
                onTrash = {
                    menuOpen = false
                    onTrash()
                }
            ),
            onDismiss = { menuOpen = false }
        )
    }
    if (filtersOpen) {
        LibraryFiltersSheet(
            start = filters,
            words = filterWords,
            counts = { draft -> LibraryFacets.facets(counted, draft, facts, today, projects, lists.map { it.name }) },
            total = { draft -> LibraryFacets.filter(counted, draft, today).size },
            depth = depth,
            labelColour = { key -> lists.firstOrNull { it.key == key }?.color },
            focus = filtersAt,
            canSave = viewsReady && openView == null,
            onSave = { draft ->
                setFilters(draft)
                filtersOpen = false
                savingView = true
            },
            onDismiss = { filtersOpen = false }
        ) { draft ->
            setFilters(draft)
            filtersOpen = false
        }
    }
    if (savingView) {
        ViewNameSheet(stringResource(R.string.v2_lf_save_view_title), "", { savingView = false }) { name ->
            try {
                val made = vm.createView(name, spec)
                savingView = false
                targetKey = LibraryTarget.View(made.id).key
                val key = LibraryScreenState.choiceKey(LibraryTarget.View(made.id), listOf(made))
                vm.updateSettings { it.copy(libraryViews = it.libraryViews + (key to choice)) }
                null
            } catch (e: com.echoledger.mobile.data.ViewError) {
                viewWords(res, e)
            }
        }
    }
    renamingView?.let { v ->
        ViewNameSheet(stringResource(R.string.v2_lf_rename_view), v.name, { renamingView = null }) { name ->
            views { vm.renameView(v.id, name) }.also { if (it == null) renamingView = null }
        }
    }
    deletingView?.let { v ->
        com.echoledger.mobile.ui.v2.components.EchoDialog(
            title = stringResource(R.string.v2_lf_delete_view_title, v.name),
            text = stringResource(R.string.v2_lf_delete_view_body),
            confirmLabel = text.delete,
            onConfirm = {
                deletingView = null
                if (target == LibraryTarget.View(v.id)) choose(LibraryTarget.ALL)
                views { vm.deleteView(v.id) }?.let { scope.launch { toasts.show(it) } }
            },
            dismissLabel = text.cancel,
            onDismiss = { deletingView = null },
            destructive = true
        )
    }
    LabelSheets(
        lists = lists,
        creating = newLabel,
        renaming = renamingLabel,
        recolouring = recolouringLabel,
        deleting = deletingLabel,
        actions = listActions,
        onDone = {
            newLabel = false
            renamingLabel = null
            recolouringLabel = null
            deletingLabel = null
        },
        onMade = { choose(LibraryTarget.Label(it.key)) }
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
    /** Under the search: [Filters n], the tabs All · Calls · Meetings · Voice notes (· Other), the pills while filters are on. */
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
    /** The place's name while one other than All recordings shows: an empty one says "Nothing in Clients yet". */
    val emptyList: String? = null,
    /** The last five searches on this phone (classic's), offered while the search field is focused and empty. */
    val recent: List<String> = emptyList(),
    /** Keeps a search among [recent]: on the keyboard's Search, on leaving search, on opening what it found. */
    val onKeepSearch: (String) -> Unit = {},
    /** Rows swipe (right Pin, left Delete); Settings › Appearance › Swipe actions turns them off. */
    val swipes: Boolean = true,
    /** What the list shows ("All recordings", a label, one of My views): the title, which opens [onTitle]'s menu. */
    val title: String? = null,
    val onTitle: (() -> Unit)? = null,
    /** Which list this is (the view, its filters, tab and search): each keeps its own scroll position (UX-25). */
    val place: String = "",
    /** The recording come back to: a light tint and accessibility focus, so the person sees where they were. */
    val returnedTo: String? = null
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
    extras: LibraryExtras? = null,
    /** An import the Library already had: a tap removes its row. */
    onDismissUpload: (String) -> Unit = {},
    /** A recording that never uploaded, deleted after the question (this phone has the only copy). */
    onDeleteUpload: (String) -> Unit = {},
    /** A call waiting for Transcribe or Delete (Needs you): opens where the choice is made. */
    onReviewWaiting: () -> Unit = {}
) {
    val c = EchoV2.colors
    // Each list keeps its own place (UX-25), across a recording opened and closed, a rotation and the process made again.
    val listState = rememberSaveable(tools?.place ?: "", saver = androidx.compose.foundation.lazy.LazyListState.Saver) {
        androidx.compose.foundation.lazy.LazyListState()
    }
    // Back from a recording: its row is where it was on screen (recordings that came in above it meanwhile would move the
    // saved place by as many rows), with focus (screen readers start there) and a light tint while it lasts.
    val returnFocus = remember { androidx.compose.ui.focus.FocusRequester() }
    var openedAt by rememberSaveable { androidx.compose.runtime.mutableIntStateOf(0) }
    var broughtBack by rememberSaveable { mutableStateOf<String?>(null) }
    val openRow: (V2Row) -> Unit = { row ->
        listState.layoutInfo.visibleItemsInfo.firstOrNull { it.key == row.id }?.let { openedAt = it.offset }
        broughtBack = null
        onOpen(row)
    }
    LaunchedEffect(tools?.returnedTo) {
        val t = tools ?: return@LaunchedEffect
        val id = t.returnedTo ?: return@LaunchedEffect
        if (broughtBack != id) {
            // Once per return: a drag in the first frames cancels it, and a rotation then never moves the list again.
            broughtBack = id
            val searching = query.trim().length >= 2
            val shown = t.groups.filter { g -> !(g.name.isNotEmpty() && !searching) || g.key !in t.folded }
            listState.bringBack(id, shown.flatMap { g -> g.rows.map { it.id } }, openedAt)
        }
        runCatching { returnFocus.requestFocus() }
    }
    val scrollToTop = extras?.scrollToTop ?: 0
    LaunchedEffect(scrollToTop) { if (scrollToTop > 0) listState.animateScrollToItem(0) }
    val text = v2Text
    var actionsFor by remember { mutableStateOf<V2Row?>(null) }
    var uploadMenu by remember { mutableStateOf<UploadRow?>(null) }
    var deletingUpload by remember { mutableStateOf<UploadRow?>(null) }
    var menu by remember { mutableStateOf(false) }
    val selecting = tools != null && tools.selected.isNotEmpty()
    var searchFocused by remember { mutableStateOf(false) }
    val focus = androidx.compose.ui.platform.LocalFocusManager.current

    // Leaving search (Back while searching, as classic's back arrow): keeps the words among the recent ones and clears them.
    fun leaveSearch() {
        tools?.onKeepSearch?.invoke(query)
        onQuery("")
        focus.clearFocus(force = true)
    }
    // The full Library (v2.1 UX-14): search is the ⌕ in the title row and opens over the page; a query left in keeps it.
    var searchOpen by rememberSaveable { mutableStateOf(query.isNotEmpty()) }
    val compactHeader = tools != null

    // The full Library's avatar opens its menu (Select, Sync now, Settings); the simple page's opens Settings.
    val onAvatar: () -> Unit = if (compactHeader) ({ menu = true }) else onAccount

    fun closeSearch() {
        leaveSearch()
        searchOpen = false
    }
    androidx.activity.compose.BackHandler(enabled = !selecting && (searchFocused || query.isNotEmpty() || searchOpen)) {
        if (compactHeader) closeSearch() else leaveSearch()
    }
    // The chips' row hides while the list scrolls down and comes back as it scrolls up (UX-14), never while searching.
    val chips = remember { ChipsOffset() }
    CompositionLocalProvider(LocalPageMargin provides if (compactHeader) EchoTokens.Layout.pagePaddingPhone else LocalPageMargin.current) {
        // While the list scrolls, the chips' row follows it (not while searching).
        val follow = if (compactHeader && !searchOpen) Modifier.nestedScroll(chips) else Modifier
        Column(modifier.fillMaxSize().background(c.bgCanvas).then(follow)) {
            if (selecting) {
                tools!!.selectionBar()
            } else if (compactHeader && searchOpen) {
                // Search over the page: ← and the field, "Search in All recordings", focused.
                SearchBar(
                    query,
                    onQuery,
                    stringResource(R.string.v2_lib_search_in, tools?.title ?: stringResource(R.string.v2_library)),
                    onBack = ::closeSearch,
                    onFocus = { searchFocused = it },
                    onSearch = { tools?.onKeepSearch?.invoke(it) }
                )
            } else {
                // With the full Library the title says what the list shows and opens the menu of places (All recordings ▾);
                // then search, the bell and the avatar, whose menu has Select, Sync now and Settings (no ⋮).
                EchoTopBar(
                    tools?.title ?: stringResource(R.string.v2_library),
                    large = tools?.onTitle == null,
                    onTitleClick = tools?.onTitle,
                    titleClickLabel = stringResource(R.string.v2_lf_choose_view)
                ) {
                    onProjects?.let { EchoIconButton(EchoIcons.Folder, stringResource(R.string.v2_ws_projects), it) }
                    if (tools == null) EchoIconButton(EchoIcons.Delete, text.trash, onTrash)
                    if (tools != null) EchoIconButton(EchoIcons.Search, text.searchRecordings, { searchOpen = true })
                    extras?.actions?.invoke()
                    // The avatar last, at the top end, as on every main screen.
                    Box {
                        Box(
                            Modifier
                                .size(EchoTokens.Layout.controlTouchMin)
                                .then(
                                    // The full Library's avatar opens a menu: TalkBack says so ("drop-down list, double tap
                                    // to show choices"); the simple page's opens Settings.
                                    if (compactHeader) {
                                        Modifier.clickable(
                                            onClickLabel = stringResource(R.string.v2_open_menu),
                                            role = androidx.compose.ui.semantics.Role.DropdownList,
                                            onClick = onAvatar
                                        )
                                    } else {
                                        Modifier.clickable(onClickLabel = text.accountAndSettings, onClick = onAvatar)
                                    }
                                )
                                .semantics { contentDescription = text.accountAndSettings },
                            contentAlignment = Alignment.Center
                        ) { com.echoledger.mobile.ui.v2.shell.PersonAvatar(initials) }
                        if (tools != null) {
                            val look = rememberPageLook()
                            androidx.compose.material3.DropdownMenu(expanded = menu, onDismissRequest = { menu = false }) {
                                ProvidePageLook(look) {
                                    AvatarItem(stringResource(R.string.v2_lib_select), EchoIcons.SelectAll) {
                                        menu = false
                                        tools.onSelect()
                                    }
                                    AvatarItem(stringResource(R.string.v2_lib_sync), EchoIcons.Sync) {
                                        menu = false
                                        tools.onSync()
                                    }
                                    AvatarItem(stringResource(R.string.v2_settings), EchoIcons.Settings) {
                                        menu = false
                                        onAccount()
                                    }
                                }
                            }
                        }
                    }
                }
            }
            // The Calls chip only on the simple page; the full Library says what is wrong in one line inside the list.
            if (!selecting && extras != null && !compactHeader) {
                Box(Modifier.padding(horizontal = LocalPageMargin.current).padding(bottom = EchoTokens.Space.s8)) {
                    extras.status()
                }
            }
            if (!selecting && !compactHeader) {
                Row(
                    Modifier.padding(horizontal = LocalPageMargin.current),
                    verticalAlignment = Alignment.CenterVertically,
                    horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s4)
                ) {
                    EchoSearchField(
                        query,
                        onQuery,
                        text.searchRecordings,
                        Modifier.weight(1f).onFocusChanged { searchFocused = it.isFocused },
                        onSearch = { tools?.onKeepSearch?.invoke(it) }
                    )
                }
            }
            if (!selecting) {
                if (tools != null) {
                    if (!searchOpen) Box(Modifier.collapsing(chips)) { Column { tools.filterRow() } }
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
                        val recent = tools?.recent.orEmpty()
                        if (searchFocused && query.isEmpty() && recent.isNotEmpty()) {
                            item(key = "h-recent") { SearchHeader(stringResource(R.string.v2_lib_recent_searches)) }
                            items(recent, key = { "recent-$it" }) { words ->
                                EchoListRow(
                                    title = words,
                                    leading = { androidx.compose.material3.Icon(EchoIcons.Search, null, tint = c.textSecondary) },
                                    onClickLabel = text.searchRecordings,
                                    onClick = { onQuery(words) }
                                )
                            }
                        }
                        if (state.offline) {
                            item {
                                EchoBanner(
                                    text.offlineBanner,
                                    EchoBannerKind.Info,
                                    Modifier.padding(horizontal = LocalPageMargin.current, vertical = EchoTokens.Space.s8),
                                    actionLabel = text.tryAgain,
                                    onAction = onRetry
                                )
                            }
                        }
                        items(state.uploads, key = { "local-" + it.id }) { up ->
                            UploadingRow(
                                up,
                                onRetry = { onRetryUpload(up.id) },
                                onDismiss = { onDismissUpload(up.id) },
                                onMenu = { uploadMenu = up },
                                onReview = onReviewWaiting
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
                                        stringResource(R.string.v2_lf_no_match),
                                        stringResource(R.string.v2_lf_no_match_body),
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
                                LibraryRow(row, selected = null, compact = false, onOpen = {
                                    onOpen(row)
                                }, onLongClick = { actionsFor = row })
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
                                        SwipeRow(row.item.pinned, enabled = !selecting && tools.swipes, onPin = {
                                            tools.onPin(row)
                                        }, onDelete = { onDelete(row) }) {
                                            val back = row.id == tools.returnedTo
                                            LibraryRow(
                                                row,
                                                selected = if (selecting) row.id in tools.selected else null,
                                                compact = tools.compact,
                                                onOpen = { openRow(row) },
                                                onLongClick = { tools.onToggle(row) },
                                                underPinned = g.key == PINNED_GROUP,
                                                returned = back,
                                                modifier = if (back) Modifier.focusRequester(returnFocus) else Modifier
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
                                        leading = { RowSourceLogo(m.row) },
                                        tall = true,
                                        onClick = { tools.onOpenAt(m) }
                                    )
                                }
                            }
                        }
                    }
                    // Back to top (UX-25): at the list's bottom end, above the bottom bar, clear of Record.
                    EchoBackToTop(listState, Modifier.align(Alignment.BottomEnd))
                }
            }
        }
    }
    uploadMenu?.let { up ->
        EchoBottomSheet(onDismiss = { uploadMenu = null }, title = up.title) {
            if (up.failed) {
                MenuRow(EchoIcons.Sync, stringResource(R.string.v2_lib_upload_retry), {
                    uploadMenu = null
                    onRetryUpload(up.id)
                })
            }
            MenuRow(EchoIcons.Delete, stringResource(R.string.v2_lib_upload_delete), {
                uploadMenu = null
                deletingUpload = up
            }, danger = true)
        }
    }
    deletingUpload?.let { up ->
        // It was never uploaded: this phone has its only copy (classic's question, bugs P-002 and P-004).
        com.echoledger.mobile.ui.v2.components.EchoDialog(
            title = stringResource(R.string.v2_lib_upload_delete_title),
            text = stringResource(R.string.v2_lib_upload_delete_body),
            confirmLabel = text.delete,
            onConfirm = {
                deletingUpload = null
                onDeleteUpload(up.id)
            },
            dismissLabel = text.cancel,
            onDismiss = { deletingUpload = null },
            destructive = true
        )
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

/** One item of the avatar's menu (Select, Sync now, Settings). */
@Composable
private fun AvatarItem(words: String, icon: androidx.compose.ui.graphics.vector.ImageVector, onClick: () -> Unit) {
    androidx.compose.material3.DropdownMenuItem(
        text = { Text(words, style = EchoV2.type.body) },
        leadingIcon = { androidx.compose.material3.Icon(icon, null) },
        onClick = onClick
    )
}

/**
 * Search over the page (v2.1 UX-14): ← and the field ("Search in All recordings"), focused as it opens; the list
 * under it shows what the words find, and the recent searches while it is empty.
 */
@Composable
private fun SearchBar(
    query: String,
    onQuery: (String) -> Unit,
    placeholder: String,
    onBack: () -> Unit,
    onFocus: (Boolean) -> Unit,
    onSearch: (String) -> Unit
) {
    val focus = remember { androidx.compose.ui.focus.FocusRequester() }
    LaunchedEffect(Unit) { runCatching { focus.requestFocus() } }
    Row(
        Modifier
            .fillMaxWidth()
            .defaultMinSize(minHeight = EchoTokens.Space.s64)
            .padding(start = EchoTokens.Space.s4, end = LocalPageMargin.current),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s4)
    ) {
        EchoIconButton(EchoIcons.Back, EchoV2.strings.back, onBack)
        EchoSearchField(
            query,
            onQuery,
            placeholder,
            Modifier.weight(1f).focusRequester(focus).onFocusChanged { onFocus(it.isFocused) },
            onSearch = onSearch
        )
    }
}

/**
 * How far the chips' row is pushed up (0 to its whole height): the list scrolling down takes it away, scrolling up
 * brings it back, as a collapsing toolbar does. Nothing is consumed: the list scrolls as before.
 */
@androidx.compose.runtime.Stable
internal class ChipsOffset : androidx.compose.ui.input.nestedscroll.NestedScrollConnection {
    var height by androidx.compose.runtime.mutableIntStateOf(0)
    var offset by androidx.compose.runtime.mutableFloatStateOf(0f)

    override fun onPreScroll(
        available: androidx.compose.ui.geometry.Offset,
        source: androidx.compose.ui.input.nestedscroll.NestedScrollSource
    ): androidx.compose.ui.geometry.Offset {
        offset = (offset + available.y).coerceIn(-height.toFloat(), 0f)
        return androidx.compose.ui.geometry.Offset.Zero
    }
}

/** Lays the chips' row out as tall as what is left of it, pushed up by [chips]' offset. */
private fun Modifier.collapsing(chips: ChipsOffset): Modifier = this
    .clipToBounds()
    .layout { measurable, constraints ->
        val placeable = measurable.measure(constraints.copy(minHeight = 0))
        chips.height = placeable.height
        val shown = (placeable.height + chips.offset.toInt()).coerceIn(0, placeable.height)
        layout(placeable.width, shown) { placeable.placeRelative(0, chips.offset.toInt()) }
    }

/**
 * A recording on its way to the server (classic's UploadingRow): "Waiting to upload", "Uploading 40%", Failed (a tap
 * retries) or "Already in your Library · Tap to dismiss" (a tap removes the row). One that can be deleted has ⋮ and a
 * long press for Retry upload and Delete recording….
 */
@Composable
private fun UploadingRow(up: UploadRow, onRetry: () -> Unit, onDismiss: () -> Unit, onMenu: () -> Unit, onReview: () -> Unit = {}) {
    val c = EchoV2.colors
    val text = v2Text
    val duplicate = up.state == "duplicate"
    val waiting = up.state == "waiting"
    val more = stringResource(R.string.v2_lib_upload_more, up.title)
    EchoListRow(
        title = up.title,
        meta = when {
            up.failed -> null
            waiting -> stringResource(R.string.v2_lf_waiting_row)
            duplicate -> stringResource(R.string.v2_lib_upload_duplicate)
            up.state == "queued" -> text.waitingToUpload
            else -> up.percent?.let { stringResource(R.string.v2_lib_uploading_pct, it) } ?: text.uploading
        },
        metaEnd = { Text(up.duration, style = EchoV2.type.mono, color = c.textSecondary) },
        status = when {
            up.failed -> ({ EchoStatus(text.failed, EchoStatusKind.Danger) })
            up.state == "uploading" -> ({ com.echoledger.mobile.ui.v2.components.EchoProgressRing(up.percent) })
            else -> null
        },
        leading = { EchoAvatar(icon = up.icon) },
        trailing = if (up.deletable) ({ EchoIconButton(EchoIcons.More, more, onMenu, tint = c.textSecondary) }) else null,
        tall = true,
        onClickLabel = when {
            up.failed -> text.tryAgain
            waiting -> stringResource(R.string.v2_lib_need_review)
            duplicate -> stringResource(R.string.v2_lib_upload_dismiss)
            else -> null
        },
        onClick = when {
            up.failed -> onRetry
            waiting -> onReview
            duplicate -> onDismiss
            else -> null
        },
        onLongClickLabel = if (up.deletable) more else null,
        onLongClick = if (up.deletable) onMenu else null
    )
}

/**
 * One recording's row: its source, title, the transcript's first line or project, time, length, status, the screens
 * count and the pinned mark; in selection mode a tick ([selected] not null) and a tap selects.
 */
@Composable
private fun LibraryRow(
    row: V2Row,
    selected: Boolean?,
    compact: Boolean,
    onOpen: () -> Unit,
    onLongClick: () -> Unit,
    /** The row come back to from its recording (UX-25): a neutral grey ([returnedTint]), not the selection's tint. */
    returned: Boolean = false,
    /** Under the Pinned header (Group by Date): the header says it, so the row needs no pin of its own. */
    underPinned: Boolean = false,
    modifier: Modifier = Modifier
) {
    val c = EchoV2.colors
    val text = v2Text
    val res = androidx.compose.ui.platform.LocalContext.current.resources
    val pinned = stringResource(R.string.v2_lib_pinned_mark)
    // U19: "Converting to Roman Urdu… 60%" (with its progress ring) or "Couldn't convert…" in place of the snippet.
    val stage = if (row.status == RowStatus.Done) com.echoledger.mobile.core.RowProgress.of(row.item) else null
    val converting = stage?.step == com.echoledger.mobile.core.RowProgress.Step.IMPROVING
    val conversion = stage?.let { conversionWords(res, row.item) }
    val problem = rowProblem(row)
    // Beside the Transcribing badge: "part 2 of 3 · about 1 min left" (classic's row line).
    val transcribing = if (row.status == RowStatus.Transcribing) transcribingDetails(res, row.item.transcript?.finalProgress) else null
    // A call kept as audio only during quiet hours or Do Not Disturb, when no card asked (the call-choice plan, 2.0.5).
    val context = androidx.compose.ui.platform.LocalContext.current
    val quiet = row.status == RowStatus.Untranscribed &&
        androidx.compose.runtime.remember(row.id) { com.echoledger.mobile.calls.QuietSaved.has(context, row.item.id, row.item.clientRef) }
    val important = stringResource(R.string.v2_lib_important_mark)
    EchoListRow(
        title = row.title,
        // A selected row is tinted all across, as classic's is; the row come back to is a light neutral grey.
        modifier = modifier.then(
            when {
                selected == true -> Modifier.background(c.accentSoft)
                returned -> Modifier.background(returnedTint(c)).semantics { this[ReturnedRow] = true }
                else -> Modifier
            }
        ),
        // A failed transcript or one with nothing heard says so in place of the meta line, as classic's row does;
        // U19's converting line comes next. How long kept audio stays is in its details (v2.1 UX-16).
        meta = when {
            problem != null -> null
            conversion != null -> conversion
            transcribing != null -> transcribing
            quiet -> stringResource(R.string.cc_quiet_saved)
            else -> row.meta
        },
        titleEnd = row.time,
        metaEnd = {
            // v2.1 UX-16: at most two small things after the line: one mark and the length. The mark is Important's
            // flag (named with the row's lists too), else the pin (unless the Pinned header above says it), else the
            // first list's colour dot (named with all its lists), else the screens count.
            val screens = row.item.screenCount ?: 0
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
                val first = row.item.labels.firstOrNull()
                when {
                    row.item.important -> androidx.compose.material3.Icon(
                        EchoIcons.Important,
                        (listOf(important) + row.item.labels).joinToString(", "),
                        Modifier.size(EchoTokens.Icon.sm),
                        tint = c.danger
                    )

                    row.item.pinned && !underPinned -> androidx.compose.material3.Icon(
                        EchoIcons.Pin,
                        pinned,
                        Modifier.size(EchoTokens.Icon.sm),
                        tint = c.textSecondary
                    )

                    first != null -> com.echoledger.mobile.ui.v2.components.EchoLabelDot(
                        LibraryModel.labelColour(row.item, first),
                        Modifier.semantics { contentDescription = row.item.labels.joinToString(", ") }
                    )

                    screens > 0 -> ScreensCountMark(screens)
                }
                // A length never breaks over two lines, at any text size.
                Text(row.duration, style = EchoV2.type.mono, color = c.textSecondary, maxLines = 1, softWrap = false)
            }
        },
        status = if (converting) {
            (
                {
                    com.echoledger.mobile.ui.v2.components.EchoProgressRing(stage?.percent)
                }
                )
        } else {
            problem ?: statusBadge(row.status, text)
        },
        leading = {
            if (selected != null) {
                androidx.compose.material3.Checkbox(selected, null)
            } else {
                RowSourceLogo(row)
            }
        },
        tall = !compact,
        // Not opened yet (opened false; an older server says nothing): the accent dot, first in the marks slot (D12),
        // and a semibold title, as classic.
        marks = com.echoledger.mobile.ui.v2.components.EchoRowMarks(
            unread = row.item.opened == false,
            unreadWord = stringResource(R.string.v2_lib_not_opened)
        ),
        plainTitle = row.item.opened == true,
        onLongClickLabel = stringResource(R.string.v2_lib_select),
        onLongClick = onLongClick,
        onClick = onOpen
    )
}

/**
 * A failed row's line, "Transcription failed · Tap to retry" in red with the alert icon (a tap opens the recording,
 * where Try again is), or "No audio" with the muted microphone when nothing was heard; null for every other row.
 */
private fun rowProblem(row: V2Row): (@Composable () -> Unit)? {
    if (row.status != RowStatus.Failed) return null
    val noAudio = row.item.transcript?.finalErrorCode == "no_audio"
    return {
        val c = EchoV2.colors
        val tint = if (noAudio) c.textSecondary else c.danger
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s4)) {
            androidx.compose.material3.Icon(
                if (noAudio) EchoIcons.MicOff else EchoIcons.Error,
                null,
                Modifier.size(EchoTokens.Icon.sm),
                tint = tint
            )
            Text(
                stringResource(if (noAudio) R.string.v2_lib_row_no_audio else R.string.v2_lib_row_failed),
                style = EchoV2.type.meta,
                color = tint,
                maxLines = 1,
                overflow = androidx.compose.ui.text.style.TextOverflow.Ellipsis
            )
        }
    }
}

/**
 * A row's avatar (v2.1 UX-16): the person's initials (a voice note's mic, a meeting's camera) with the source app's own
 * mark as a small badge. The device leaves the row (it is in the recording's details and Filters › Device); TalkBack
 * still hears the app, then the device.
 */
@Composable
fun RowSourceLogo(row: V2Row) {
    val device = com.echoledger.mobile.core.SourceDevice.from(row.item.sourceDevice)
    com.echoledger.mobile.ui.v2.components.EchoRowAvatar(
        com.echoledger.mobile.core.SourceApp.from(row.item.sourceApp),
        // The person's or the caller's name only: never the source's name an untitled row shows, nor a title the
        // server wrote from what was said (the person icon then, with the badge), the desktop's rule too.
        row.item.title?.takeIf { it.isNotBlank() && !row.item.titleAuto }.orEmpty(),
        com.echoledger.mobile.core.LibraryKind.of(row.item.facets?.kind, row.item.sourceApp),
        label = if (device == com.echoledger.mobile.core.SourceDevice.PHONE) {
            row.source
        } else {
            stringResource(R.string.v2_lib_source_device, row.source, deviceName(device))
        }
    )
}

/** At most one badge per row: only what needs attention. */
private fun statusBadge(status: RowStatus, text: V2Text): (@Composable () -> Unit)? = when (status) {
    RowStatus.Done -> null
    RowStatus.Untranscribed -> ({ EchoStatus(text.untranscribed, EchoStatusKind.Neutral) })
    RowStatus.Transcribing -> ({ EchoStatus(text.transcribing, EchoStatusKind.Accent) })
    RowStatus.Failed -> ({ EchoStatus(text.failed, EchoStatusKind.Danger) })
}

/** How often the Library asks again while something is on its way (classic's LIVE_REFRESH_MS). */
private const val LIVE_REFRESH_MS = 5_000L

/**
 * Puts the row [key] back at [offset] from the list's top (UX-25). The saved place is an index, off by as many rows as
 * came in above it while its recording was open. A row off screen is found through [order], the rows' keys in the
 * list's order: the headers between them make a first guess a little off, and the next look corrects it.
 */
private suspend fun androidx.compose.foundation.lazy.LazyListState.bringBack(key: Any, order: List<Any>, offset: Int) {
    val target = order.indexOf(key)
    if (target < 0) return
    // The first layout after coming back.
    androidx.compose.runtime.snapshotFlow { layoutInfo.totalItemsCount }.first { it > 0 }
    repeat(BRING_BACK_LOOKS) {
        val visible = layoutInfo.visibleItemsInfo
        visible.firstOrNull { it.key == key }?.let { item ->
            if (item.offset != offset) scrollBy((item.offset - offset).toFloat())
            return
        }
        val seen = visible.firstNotNullOfOrNull { v -> order.indexOf(v.key).takeIf { it >= 0 }?.let { v.index to it } } ?: return
        scrollToItem((seen.first + target - seen.second).coerceAtLeast(0))
    }
}

private const val BRING_BACK_LOOKS = 5

/** The Pinned header's group (Group by Date): its rows need no pin of their own. */
private val PINNED_GROUP = "date:" + com.echoledger.mobile.core.LibraryGrouping.PINNED

/** How long the row come back to stays tinted at most (UX-25). */
private const val RETURN_TINT_MS = 60_000L

/** The row come back to (UX-25): the text colour over the page, as the desktop's, never the accent. */
fun returnedTint(c: com.echoledger.mobile.ui.v2.theme.EchoV2Colors): androidx.compose.ui.graphics.Color =
    c.textPrimary.copy(alpha = if (c.isDark) EchoTokens.Tint.returnedDark else EchoTokens.Tint.returnedLight)

/** Marks the row come back to (tests read it; screen readers get its focus). */
val ReturnedRow = androidx.compose.ui.semantics.SemanticsPropertyKey<Boolean>("ReturnedRow")

/**
 * What marking many read or unread says (contract 2.0.0-y): how many were marked; when some weren't (a stop, or an older
 * server's limit), how many of how many, and how many are still selected.
 */
internal fun openedWords(
    res: android.content.res.Resources,
    run: com.echoledger.mobile.ui.MainViewModel.OpenedRun,
    read: Boolean,
    /** A selection keeps the ones not done; Mark all as read has none to keep, and says to do it again. */
    selection: Boolean = true
): String {
    val marked = run.done.size
    val failure = run.failure
    return when {
        run.left.isEmpty() ->
            res.getQuantityString(if (read) R.plurals.v2_lib_marked_read else R.plurals.v2_lib_marked_unread, marked, marked)

        failure != null && marked == 0 -> openedProblem(res, failure, read)

        failure != null -> res.getString(R.string.v2_lib_marked_stopped, marked, run.total, openedProblem(res, failure, read))

        selection -> res.getString(R.string.v2_lib_marked_some, marked, run.total, run.left.size)

        else -> res.getString(R.string.v2_lib_marked_some_again, marked, run.total)
    }
}

/**
 * What stopped Mark as read or unread, in the screen's language: the connection only when there was none, an older
 * server's 405 as needing a newer server, else the server's own words (or the general sentence).
 */
internal fun openedProblem(res: android.content.res.Resources, failure: Throwable, read: Boolean): String =
    if ((failure as? com.echoledger.mobile.net.ApiException)?.status == 405) {
        res.getString(if (read) R.string.v2_lib_read_needs_newer else R.string.v2_lib_unread_needs_newer)
    } else {
        com.echoledger.mobile.ui.v2.shell.VmMessages.localize(
            res,
            com.echoledger.mobile.core.Problems.text(failure, "The change could not be saved.")
        )
    }
