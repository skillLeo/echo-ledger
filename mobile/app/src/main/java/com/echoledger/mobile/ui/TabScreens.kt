package com.echoledger.mobile.ui

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.background
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
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.automirrored.rounded.KeyboardArrowRight
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Devices
import androidx.compose.material.icons.rounded.ErrorOutline
import androidx.compose.material.icons.rounded.Group
import androidx.compose.material.icons.rounded.HourglassTop
import androidx.compose.material.icons.rounded.NotificationsNone
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Icon
import androidx.compose.material3.OutlinedTextField
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
import androidx.compose.ui.unit.dp
import com.echoledger.mobile.data.ActivityItemDto
import com.echoledger.mobile.data.ActivityResponse
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.LocalRecording
import com.echoledger.mobile.notify.Notifications
import com.echoledger.mobile.ui.design.Avatar
import com.echoledger.mobile.ui.design.BannerKind
import com.echoledger.mobile.ui.design.BarIcon
import com.echoledger.mobile.ui.design.ButtonKind
import com.echoledger.mobile.ui.design.EchoButton
import com.echoledger.mobile.ui.design.EchoEmptyState
import com.echoledger.mobile.ui.design.EchoTopBar
import com.echoledger.mobile.ui.design.IconTile
import com.echoledger.mobile.ui.design.InlineBanner
import com.echoledger.mobile.ui.design.LibraryItemRow
import com.echoledger.mobile.ui.design.ListPadding
import com.echoledger.mobile.ui.design.RecordingRow
import com.echoledger.mobile.ui.design.SectionHeader
import com.echoledger.mobile.ui.design.SkeletonRow
import com.echoledger.mobile.ui.design.dateGroup
import com.echoledger.mobile.ui.design.rowTime
import java.time.LocalDate
import kotlinx.coroutines.launch

/**
 * The Activity tab (since v1.3): Needs action (calls waiting for Keep or Discard, and problems not seen yet), Today and
 * Earlier. Tapping an item marks it read and opens its recording; "Mark all as read" clears the badge.
 */
@Composable
fun ActivityScreen(
    load: suspend () -> ActivityResponse?,
    markRead: suspend (List<String>?) -> Int?,
    onOpen: (String) -> Unit,
    onUnread: (Int) -> Unit,
    waiting: List<LocalRecording> = emptyList(),
    onOpenWaiting: () -> Unit = {},
    scrollToTop: Int = 0,
    today: LocalDate = LocalDate.now()
) {
    var items by remember { mutableStateOf<List<ActivityItemDto>?>(null) }
    var unread by remember { mutableIntStateOf(0) }
    // Informational items this list already marked read on the server (they still look new until the next visit).
    var seenMarked by remember { mutableStateOf(setOf<String>()) }
    var failed by remember { mutableStateOf(false) }
    var attempt by remember { mutableIntStateOf(0) }
    val scope = rememberCoroutineScope()
    val list = rememberLazyListState()
    LaunchedEffect(scrollToTop) { if (scrollToTop > 0) list.animateScrollToItem(0) }
    LaunchedEffect(attempt) {
        val answer = load()
        failed = answer == null
        items = answer?.data.orEmpty()
        unread = answer?.unreadCount ?: 0
        onUnread(ActivityBadge.needs(items))
        // Informational items (transcript ready and the like) count as read once this list has shown them.
        ActivityBadge.seen(items).takeIf { it.isNotEmpty() }?.let { seen ->
            markRead(seen)?.let {
                unread = it
                seenMarked = seen.toSet()
            }
        }
    }
    fun open(item: ActivityItemDto) {
        scope.launch {
            if (!item.read && item.id !in seenMarked) {
                markRead(listOf(item.id))?.let { unread = it }
                items = items?.map { i -> if (i.id == item.id) i.copy(read = true) else i }
                onUnread(ActivityBadge.needs(items))
            }
            item.sessionId?.let(onOpen)
        }
    }
    // A swipe either way dismisses an item: it is marked read and leaves the list (the server keeps it).
    var dismissed by remember { mutableStateOf(setOf<String>()) }
    fun dismiss(item: ActivityItemDto) {
        dismissed = dismissed + item.id
        if (!item.read && item.id !in seenMarked) {
            scope.launch {
                markRead(listOf(item.id))?.let { unread = it }
                items = items?.map { i -> if (i.id == item.id) i.copy(read = true) else i }
                onUnread(ActivityBadge.needs(items))
            }
        }
    }
    val loaded = items.orEmpty().filterNot { it.id in dismissed }
    val needsAction = loaded.filter { !it.read && it.isProblem() }
    val rest = loaded - needsAction.toSet()
    LazyColumn(Modifier.fillMaxSize(), state = list, contentPadding = ListPadding) {
        item(key = "top") {
            EchoTopBar("Activity", actions = {
                if (unread > 0) {
                    EchoButton("Mark all as read", {
                        scope.launch {
                            markRead(null)?.let {
                                unread = it
                                items = items?.map { i -> i.copy(read = true) }
                                onUnread(0)
                            }
                        }
                    }, kind = ButtonKind.Text)
                }
            })
        }
        when {
            failed -> item(key = "failed") {
                InlineBanner(
                    "Couldn't load activity. Check the connection.",
                    Modifier.padding(horizontal = Space.screen),
                    kind = BannerKind.Error,
                    action = "Retry",
                    onAction = {
                        failed = false
                        items = null
                        attempt++
                    }
                )
            }

            items == null -> items(4, key = { "skeleton-$it" }) { SkeletonRow() }

            loaded.isEmpty() && waiting.isEmpty() -> item(key = "empty") {
                EchoEmptyState(
                    Icons.Rounded.NotificationsNone,
                    "You're all caught up",
                    body = "Finished transcripts, problems and calls waiting for Keep or Discard show up here."
                )
            }
        }
        if (waiting.isNotEmpty() || needsAction.isNotEmpty()) {
            item(key = "h-action") { SectionHeader("Needs action") }
            item(key = "waiting") { WaitingSummaryCard(waiting, onReview = onOpenWaiting) }
            itemsIndexed(needsAction, key = { _, it -> "a-${it.id}" }) { i, row ->
                DismissibleRow(onDismiss = { dismiss(row) }) {
                    ActivityRow(row, today, divider = i < needsAction.lastIndex) { open(row) }
                }
            }
        }
        rest.groupBy { if (dateGroup(it.at, today) == "Today") "Today" else "Earlier" }.toSortedMap(compareBy { it != "Today" })
            .forEach { (group, rows) ->
                item(key = "h-$group") { SectionHeader(group) }
                itemsIndexed(rows, key = { _, it -> "a-${it.id}" }) { i, row ->
                    DismissibleRow(onDismiss = { dismiss(row) }) {
                        ActivityRow(row, today, divider = i < rows.lastIndex) { open(row) }
                    }
                }
            }
    }
}

/** A row that a swipe either way dismisses ("Dismiss" on a neutral background behind it). */
@OptIn(androidx.compose.material3.ExperimentalMaterial3Api::class)
@Composable
private fun DismissibleRow(onDismiss: () -> Unit, content: @Composable () -> Unit) {
    val state = androidx.compose.material3.rememberSwipeToDismissBoxState()
    LaunchedEffect(state.currentValue) {
        if (state.currentValue != androidx.compose.material3.SwipeToDismissBoxValue.Settled) onDismiss()
    }
    androidx.compose.material3.SwipeToDismissBox(
        state = state,
        backgroundContent = {
            Box(
                Modifier.fillMaxSize().background(LocalEchoColors.current.surface2).padding(horizontal = Space.s5),
                contentAlignment = Alignment.CenterEnd
            ) {
                Text("Dismiss", style = LocalEchoType.current.labelL, color = LocalEchoColors.current.textMuted)
            }
        }
    ) { Box(Modifier.background(Brand.Background)) { content() } }
}

internal fun ActivityItemDto.isProblem(): Boolean = kind.orEmpty().let {
    it.contains("fail") || it.contains("error") ||
        it.contains("problem")
}

/** The Activity tab's badge (since 1.3.2): only what needs me (unread problems, calls waiting), "9+" above 9. */
object ActivityBadge {
    fun needs(items: List<ActivityItemDto>?): Int = items.orEmpty().count { !it.read && it.isProblem() }

    /** Informational items not read yet: marked read once the Activity list has shown them. */
    fun seen(items: List<ActivityItemDto>?): List<String> = items.orEmpty().filter { !it.read && !it.isProblem() }.map { it.id }

    fun label(count: Int): String = if (count > 9) "9+" else count.toString()
}

@Composable
private fun ActivityRow(item: ActivityItemDto, today: LocalDate, divider: Boolean = true, onClick: () -> Unit) {
    val c = LocalEchoColors.current
    val kind = item.kind.orEmpty()
    val (icon, tint) = when {
        item.isProblem() -> Icons.Rounded.ErrorOutline to c.danger
        kind.contains("device") -> Icons.Rounded.Devices to c.textMuted
        else -> Icons.Rounded.CheckCircle to c.accentText
    }
    RecordingRow(
        title = item.title.ifBlank { "Update" },
        time = rowTime(item.at, today),
        meta = item.body,
        tile = { IconTile(icon, tint) },
        onClick = onClick,
        onLongClick = onClick,
        unopened = !item.read,
        divider = divider
    )
}

/**
 * The Clients tab (since v1.3): every client with initials, how many recordings and the last call; a client's page
 * lists its recordings with the same rows as the Library. "+" adds a client.
 */
@Composable
fun ClientsScreen(
    vm: MainViewModel,
    onOpenItem: (LibraryItem) -> Unit,
    scrollToTop: Int = 0,
    today: LocalDate = LocalDate.now(),
    onOpenAt: (String, Long) -> Unit = { _, _ -> }
) {
    val clients by vm.clients.collectAsState()
    val library by vm.library.collectAsState()
    var openId by rememberSaveable { mutableStateOf<String?>(null) }
    var adding by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    val list = rememberLazyListState()
    // The tab tapped again (since 1.3.2): a client page closes first; on the list it scrolls to the top.
    LaunchedEffect(scrollToTop) {
        if (scrollToTop > 0) {
            if (openId != null) openId = null else list.animateScrollToItem(0)
        }
    }
    val live = library.filter { !it.deleted }
    val open = clients.firstOrNull { it.id == openId }
    if (open != null) {
        // The client's workspace (since 1.4): Overview · Recordings · Files · Ask.
        val recordings = live.filter { it.client?.id == open.id }.sortedByDescending { it.startedAt }
        ClientWorkspaceScreen(vm, open, recordings, today, onOpenItem, onOpenAt, onBack = { openId = null })
        return
    }
    LazyColumn(Modifier.fillMaxSize(), state = list, contentPadding = ListPadding) {
        item(key = "top") { EchoTopBar("Clients", actions = { BarIcon(Icons.Rounded.Add, "New client", { adding = true }) }) }
        if (clients.isEmpty()) {
            item(key = "empty") {
                EchoEmptyState(
                    Icons.Rounded.Group,
                    "No clients yet",
                    body = "A client keeps one person's or company's recordings together.",
                    action = "New client",
                    onAction = { adding = true }
                )
            }
        }
        val sorted = clients.sortedWith(
            compareByDescending<com.echoledger.mobile.data.ClientDto> {
                it.pinned
            }.thenBy { it.name.lowercase() }
        )
        itemsIndexed(sorted, key = { _, it -> it.id }) { i, client ->
            val mine = live.filter { it.client?.id == client.id }
            val count = if (mine.isNotEmpty()) mine.size else client.sessionCount
            val last = mine.maxByOrNull { it.startedAt.orEmpty() }?.startedAt
            RecordingRow(
                title = client.name,
                time = "",
                meta = listOfNotNull(countLabel(count), last?.let { "Last call ${rowTime(it, today)}" }).joinToString(" · "),
                tile = { Avatar(client.name, size = 44.dp) },
                onClick = { openId = client.id },
                onLongClick = { openId = client.id },
                divider = i < sorted.lastIndex
            )
        }
    }
    if (adding) {
        NewClientDialog(onDismiss = { adding = false }) { name ->
            adding = false
            scope.launch { vm.createClient(name) }
        }
    }
}

internal fun countLabel(n: Int) = when (n) {
    0 -> "No recordings yet"
    1 -> "1 recording"
    else -> "$n recordings"
}

private fun languageName(code: String) = com.echoledger.mobile.core.Languages.menu(code)

@Composable
private fun NewClientDialog(onDismiss: () -> Unit, onCreate: (String) -> Unit) {
    var name by remember { mutableStateOf("") }
    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("New client") },
        text = { OutlinedTextField(name, { name = it }, singleLine = true, label = { Text("Name") }, modifier = Modifier.fillMaxWidth()) },
        confirmButton = { TextButton(onClick = { onCreate(name.trim()) }, enabled = name.isNotBlank()) { Text("Add") } },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Cancel") } }
    )
}
