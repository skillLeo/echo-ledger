package com.echoledger.mobile.ui

import androidx.activity.compose.BackHandler
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
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.rounded.ArrowBack
import androidx.compose.material.icons.automirrored.rounded.KeyboardArrowRight
import androidx.compose.material.icons.automirrored.rounded.PhoneMissed
import androidx.compose.material.icons.rounded.Add
import androidx.compose.material.icons.rounded.CheckCircle
import androidx.compose.material.icons.rounded.Devices
import androidx.compose.material.icons.rounded.ErrorOutline
import androidx.compose.material.icons.rounded.Group
import androidx.compose.material.icons.rounded.HourglassTop
import androidx.compose.material.icons.rounded.NotificationsNone
import androidx.compose.material.icons.rounded.WarningAmber
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
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
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.echoledger.mobile.calls.MissedFix
import com.echoledger.mobile.calls.MissedItem
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
import com.echoledger.mobile.ui.design.OutlinedTextField
import com.echoledger.mobile.ui.design.RecordingRow
import com.echoledger.mobile.ui.design.SectionHeader
import com.echoledger.mobile.ui.design.SkeletonRow
import com.echoledger.mobile.ui.design.TextButton
import com.echoledger.mobile.ui.design.dateGroup
import com.echoledger.mobile.ui.design.rowTime
import com.echoledger.mobile.ui.design.tabular
import com.echoledger.mobile.util.AppClock
import java.time.LocalDate
import kotlinx.coroutines.launch

/**
 * The Activity tab (since v1.3): Needs action (calls waiting for Keep or Discard, and problems not seen yet), Today and
 * Earlier. Tapping an item marks it read and opens its recording; "Mark all as read" clears the badge.
 *
 * Since v1.8 this phone's missed recordings ([missed]) are items too, with the reason in plain words (shown on the phone
 * only: contract 1.8.0-e sends no free text) and a fix button ([onFix]); the server's copy of one ("missed_recording",
 * once it is reported) is not shown twice.
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
    today: LocalDate = AppClock.today(),
    skipped: List<LocalRecording> = emptyList(),
    onRestore: (String) -> Unit = {},
    missed: List<MissedItem> = emptyList(),
    onFix: (MissedFix) -> Unit = {},
    onMissedRead: (String) -> Unit = {},
    onMissedDismiss: (String) -> Unit = {}
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
        onUnread(ActivityBadge.server(items, missed))
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
                onUnread(ActivityBadge.server(items, missed))
            }
            item.sessionId?.let(onOpen)
        }
    }

    /**
     * P-043: a problem with nowhere to go (no recording) isn't a button, so while unread it has a quiet Mark read; seeing
     * it never clears it. An informational one was marked read once seen.
     */
    fun markable(item: ActivityItemDto): (() -> Unit)? =
        { open(item) }.takeIf { item.sessionId == null && !item.read && item.id !in seenMarked }

    /** A missed recording looked at or swiped away: the server's copy of it is read too. */
    fun settleMissed(item: MissedItem, dismiss: Boolean) {
        if (dismiss) onMissedDismiss(item.ref) else onMissedRead(item.ref)
        val copy = items?.firstOrNull { it.missedRecordingId != null && it.missedRecordingId == item.serverId && !it.read }
        val after = missed.map { if (it.ref == item.ref) it.copy(read = true, dismissed = it.dismissed || dismiss) else it }
        onUnread(ActivityBadge.server(items, after))
        copy?.let {
            scope.launch {
                markRead(listOf(it.id))?.let { n -> unread = n }
                items = items?.map { i -> if (i.id == it.id) i.copy(read = true) else i }
            }
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
                onUnread(ActivityBadge.server(items, missed))
            }
        }
    }
    val mine = missed.mapNotNull { it.serverId }.toSet()
    val loaded = items.orEmpty().filterNot { it.id in dismissed || it.missedRecordingId in mine }
    val needsAction = loaded.filter { !it.read && it.isProblem() }
    val rest = loaded - needsAction.toSet()
    val local = missed.filterNot { it.dismissed }
    val localNeeds = local.filter { !it.read }
    // Today and Earlier hold the server's items and the missed recordings already looked at, newest first.
    val later: List<Any> = (rest + local.filter { it.read }).sortedByDescending { row ->
        when (row) {
            is MissedItem -> row.startedAtMs
            else -> runCatching { java.time.OffsetDateTime.parse((row as ActivityItemDto).at).toInstant().toEpochMilli() }.getOrDefault(0L)
        }
    }
    LazyColumn(Modifier.fillMaxSize(), state = list, contentPadding = ListPadding) {
        item(key = "top") {
            EchoTopBar("Activity", actions = {
                if (unread > 0 || localNeeds.isNotEmpty()) {
                    EchoButton("Mark all as read", {
                        scope.launch {
                            markRead(null)?.let {
                                unread = it
                                items = items?.map { i -> i.copy(read = true) }
                                onUnread(0)
                            }
                            missed.filter { !it.read }.forEach { onMissedRead(it.ref) }
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

            loaded.isEmpty() && waiting.isEmpty() && local.isEmpty() -> item(key = "empty") {
                EchoEmptyState(
                    Icons.Rounded.NotificationsNone,
                    "You're all caught up",
                    body = "Finished transcripts, problems and calls waiting for Keep or Discard show up here."
                )
            }
        }
        if (waiting.isNotEmpty() || needsAction.isNotEmpty() || localNeeds.isNotEmpty()) {
            item(key = "h-action") { SectionHeader("Needs action") }
            item(key = "waiting") { WaitingSummaryCard(waiting, onReview = onOpenWaiting) }
            itemsIndexed(localNeeds, key = { _, it -> "m-${it.ref}" }) { i, row ->
                DismissibleRow(onDismiss = { settleMissed(row, dismiss = true) }) {
                    MissedRecordingRow(
                        row,
                        today,
                        divider = i < localNeeds.lastIndex || needsAction.isNotEmpty(),
                        onClick = { settleMissed(row, dismiss = false) },
                        onFix = {
                            settleMissed(row, dismiss = false)
                            onFix(row.button)
                        }
                    )
                }
            }
            itemsIndexed(needsAction, key = { _, it -> "a-${it.id}" }) { i, row ->
                DismissibleRow(onDismiss = { dismiss(row) }) {
                    ActivityRow(row, today, divider = i < needsAction.lastIndex, onMarkRead = markable(row)) { open(row) }
                }
            }
        }
        if (skipped.isNotEmpty()) item(key = "skipped") { SkippedCallsLine(skipped, onRestore) }
        later.groupBy { row ->
            val at = if (row is MissedItem) java.time.Instant.ofEpochMilli(row.startedAtMs).toString() else (row as ActivityItemDto).at
            if (dateGroup(at, today) == "Today") "Today" else "Earlier"
        }.toSortedMap(compareBy { it != "Today" })
            .forEach { (group, rows) ->
                item(key = "h-$group") { SectionHeader(group) }
                itemsIndexed(rows, key = { _, it ->
                    if (it is MissedItem) "m-${it.ref}" else "a-${(it as ActivityItemDto).id}"
                }) { i, row ->
                    if (row is MissedItem) {
                        DismissibleRow(onDismiss = { settleMissed(row, dismiss = true) }) {
                            // Already read: tapping the row does nothing more, so it takes no tap (its button still works).
                            MissedRecordingRow(
                                row,
                                today,
                                divider = i < rows.lastIndex,
                                onClick = null,
                                onFix = { onFix(row.button) }
                            )
                        }
                    } else {
                        val item = row as ActivityItemDto
                        DismissibleRow(onDismiss = { dismiss(item) }) {
                            ActivityRow(item, today, divider = i < rows.lastIndex, onMarkRead = markable(item)) { open(item) }
                        }
                    }
                }
            }
    }
}

/**
 * One missed recording of this phone (since v1.8): "Missed recording", the call ("WhatsApp call · 5:12"), why in plain
 * words (on the phone only), and the button that fixes it (the Ready check or Turn on, the call test, or the plans).
 * [onClick] null: the row takes no tap.
 */
@Composable
internal fun MissedRecordingRow(item: MissedItem, today: LocalDate, divider: Boolean, onClick: (() -> Unit)?, onFix: () -> Unit) {
    val c = LocalEchoColors.current
    val type = LocalEchoType.current
    val app = com.echoledger.mobile.core.SourceApp.from(item.app)
    val call = listOfNotNull(
        if (app == com.echoledger.mobile.core.SourceApp.PHONE_CALL) "Phone call" else "${app.label} call",
        item.durationS?.let { formatDuration(it * 1000).ifEmpty { null } }
    ).joinToString(" · ")
    Column(Modifier.fillMaxWidth()) {
        Row(
            Modifier.fillMaxWidth()
                .then(if (onClick != null) Modifier.clickable(onClick = onClick) else Modifier)
                .padding(horizontal = Space.screen, vertical = Space.s3),
            verticalAlignment = Alignment.Top
        ) {
            IconTile(Icons.AutoMirrored.Rounded.PhoneMissed, c.danger)
            Spacer(Modifier.size(Space.s3))
            Column(Modifier.weight(1f)) {
                Row(verticalAlignment = Alignment.CenterVertically) {
                    if (!item.read) {
                        Box(Modifier.size(6.dp).clip(CircleShape).background(c.accentStrong))
                        Spacer(Modifier.size(Space.s1 + Space.s0))
                    }
                    Text(
                        "Missed recording",
                        style = type.bodyL.copy(fontWeight = if (item.read) FontWeight.Medium else FontWeight.SemiBold),
                        color = c.text,
                        modifier = Modifier.weight(1f)
                    )
                    Text(
                        rowTime(java.time.Instant.ofEpochMilli(item.startedAtMs).toString(), today),
                        style = type.caption.tabular(),
                        color = c.textFaint
                    )
                }
                Text(call, style = type.labelM, color = c.textMuted)
                Text(item.detail, style = type.labelM.copy(fontWeight = FontWeight.Normal), color = c.textMuted)
                EchoButton(
                    item.button.label,
                    onFix,
                    modifier = Modifier.padding(top = Space.s2),
                    kind = ButtonKind.Secondary
                )
            }
        }
        if (divider) {
            androidx.compose.material3.HorizontalDivider(
                Modifier.padding(start = Space.screen + 44.dp + Space.s3),
                thickness = 1.dp,
                color = c.border
            )
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

/** What needs the person: failures and problems, and since v1.8 a missed recording (contract 1.8.0-b: every app). */
internal fun ActivityItemDto.isProblem(): Boolean = kind.orEmpty().let {
    it.contains("fail") || it.contains("error") ||
        it.contains("problem") || it == "missed_recording"
}

/** The Activity tab's badge (since 1.3.2): only what needs me (unread problems, calls waiting), "9+" above 9. */
object ActivityBadge {
    /**
     * The unread problems from the server and this phone's unread missed recordings (since v1.8), a missed recording
     * counted once when the server has its copy too.
     */
    fun needs(items: List<ActivityItemDto>?, missed: List<MissedItem> = emptyList()): Int = server(items, missed) + local(missed)

    /** The server's unread problems, without the copies of this phone's own missed recordings. */
    fun server(items: List<ActivityItemDto>?, missed: List<MissedItem> = emptyList()): Int {
        val mine = missed.mapNotNull { it.serverId }.toSet()
        return items.orEmpty().count { !it.read && it.isProblem() && it.missedRecordingId !in mine }
    }

    /** This phone's missed recordings not looked at yet. */
    fun local(missed: List<MissedItem>): Int = missed.count { !it.read && !it.dismissed }

    /** Informational items not read yet: marked read once the Activity list has shown them. */
    fun seen(items: List<ActivityItemDto>?): List<String> = items.orEmpty().filter { !it.read && !it.isProblem() }.map { it.id }

    fun label(count: Int): String = if (count > 9) "9+" else count.toString()
}

@Composable
private fun ActivityRow(
    item: ActivityItemDto,
    today: LocalDate,
    divider: Boolean = true,
    onMarkRead: (() -> Unit)? = null,
    onClick: () -> Unit
) {
    val c = LocalEchoColors.current
    val kind = item.kind.orEmpty()
    val (icon, tint) = when {
        kind == "missed_recording" -> Icons.AutoMirrored.Rounded.PhoneMissed to c.danger

        item.isProblem() -> Icons.Rounded.ErrorOutline to c.danger

        // A warning keeps its own sign (decision 27); the neutral bell, not a check, for what isn't done.
        kind == "unusual_usage" -> Icons.Rounded.WarningAmber to c.warning

        kind.contains("device") -> Icons.Rounded.Devices to c.textMuted

        kind == "transcript_ready" -> Icons.Rounded.CheckCircle to c.accentText

        else -> Icons.Rounded.NotificationsNone to c.textMuted
    }
    RecordingRow(
        title = item.title.ifBlank { "Update" },
        time = rowTime(item.at, today),
        meta = item.body,
        // 2.0.0-aa: the person the item is about, for the kinds that show a face; else the kind's tile.
        tile = {
            com.echoledger.mobile.ui.v2.activity.ActivityPeople.shownPerson(item)?.let {
                com.echoledger.mobile.ui.v2.activity.ActorAvatar(it, size = 44.dp, classic = true)
            }
                ?: IconTile(icon, tint)
        },
        // P-043: only an item with a recording opens something; one with nowhere to go (a new device) isn't a button.
        onClick = onClick.takeIf { item.sessionId != null },
        onLongClick = onClick,
        unopened = !item.read,
        divider = divider,
        trailing = onMarkRead?.let { mark -> { EchoButton("Mark read", mark, kind = ButtonKind.Text) } }
    )
}

internal fun countLabel(n: Int) = when (n) {
    0 -> "No recordings yet"
    1 -> "1 recording"
    else -> "$n recordings"
}

private fun languageName(code: String) = com.echoledger.mobile.core.Languages.menu(code)
