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
import com.echoledger.mobile.ui.design.tabular
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
    today: LocalDate = LocalDate.now(),
    skipped: List<LocalRecording> = emptyList(),
    onRestore: (String) -> Unit = {}
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
        if (skipped.isNotEmpty()) item(key = "skipped") { SkippedCallsLine(skipped, onRestore) }
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

/**
 * "Skipped 3 short calls" (since v1.5): one quiet line for the calls skipped in the last 24 h (unanswered, short or
 * silent); Show lists them, each with Restore, which keeps it like any other call.
 */
@Composable
internal fun SkippedCallsLine(skipped: List<LocalRecording>, onRestore: (String) -> Unit) {
    val c = LocalEchoColors.current
    val type = LocalEchoType.current
    var open by remember { mutableStateOf(false) }
    Column(Modifier.fillMaxWidth().padding(horizontal = Space.screen, vertical = Space.s1)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(
                com.echoledger.mobile.calls.SkippedCalls.line(skipped.size),
                style = type.labelM,
                color = c.textMuted,
                modifier = Modifier.weight(1f)
            )
            EchoButton(if (open) "Hide" else "Show", { open = !open }, kind = ButtonKind.Text)
        }
        if (open) {
            skipped.sortedByDescending { it.startedAt }.forEach { rec ->
                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                    val reason = com.echoledger.mobile.calls.SkippedCalls.reasonOf(rec)?.label ?: "Skipped"
                    Text(
                        listOf(
                            com.echoledger.mobile.core.SourceApp.from(rec.sourceApp).label,
                            formatWhen(rec.startedAt),
                            formatDuration(rec.durationMs).ifEmpty { "0:00" },
                            reason
                        ).joinToString(" · "),
                        style = type.labelM.tabular(),
                        color = c.text,
                        maxLines = 1,
                        overflow = androidx.compose.ui.text.style.TextOverflow.Ellipsis,
                        modifier = Modifier.weight(1f)
                    )
                    EchoButton("Restore", { onRestore(rec.id) }, kind = ButtonKind.Text)
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

internal fun countLabel(n: Int) = when (n) {
    0 -> "No recordings yet"
    1 -> "1 recording"
    else -> "$n recordings"
}

private fun languageName(code: String) = com.echoledger.mobile.core.Languages.menu(code)
