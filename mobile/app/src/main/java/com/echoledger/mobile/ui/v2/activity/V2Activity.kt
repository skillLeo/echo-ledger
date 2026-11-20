package com.echoledger.mobile.ui.v2.activity

import android.content.res.Resources
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.SwipeToDismissBox
import androidx.compose.material3.SwipeToDismissBoxValue
import androidx.compose.material3.Text
import androidx.compose.material3.rememberSwipeToDismissBoxState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import com.echoledger.mobile.R
import com.echoledger.mobile.calls.MissedFix
import com.echoledger.mobile.calls.MissedItem
import com.echoledger.mobile.calls.MissedReason
import com.echoledger.mobile.calls.SkippedCalls
import com.echoledger.mobile.core.SourceApp
import com.echoledger.mobile.data.ActivityResponse
import com.echoledger.mobile.data.LocalRecording
import com.echoledger.mobile.ui.ActivityBadge
import com.echoledger.mobile.ui.MainViewModel
import com.echoledger.mobile.ui.isProblem
import com.echoledger.mobile.ui.v2.components.EchoAvatar
import com.echoledger.mobile.ui.v2.components.EchoBanner
import com.echoledger.mobile.ui.v2.components.EchoBannerKind
import com.echoledger.mobile.ui.v2.components.EchoButton
import com.echoledger.mobile.ui.v2.components.EchoButtonKind
import com.echoledger.mobile.ui.v2.components.EchoEmptyState
import com.echoledger.mobile.ui.v2.components.EchoIcons
import com.echoledger.mobile.ui.v2.components.EchoListRow
import com.echoledger.mobile.ui.v2.components.EchoSectionHeader
import com.echoledger.mobile.ui.v2.components.EchoSettingRow
import com.echoledger.mobile.ui.v2.components.EchoSkeletonList
import com.echoledger.mobile.ui.v2.components.EchoTopBar
import com.echoledger.mobile.ui.v2.components.formatClock
import com.echoledger.mobile.ui.v2.home.DiscardBatch
import com.echoledger.mobile.ui.v2.home.DiscardWords
import com.echoledger.mobile.ui.v2.library.LibraryModel
import com.echoledger.mobile.ui.v2.library.RowStatus
import com.echoledger.mobile.ui.v2.live.afterCallTitle
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.shell.sourceName
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.theme.screenLocale
import com.echoledger.mobile.ui.v2.tokens.EchoTokens
import java.time.LocalDate
import kotlinx.coroutines.launch

/** Where Activity's rows and buttons lead; the shell decides the screens. */
class ActivityActions(
    val onOpenRecording: (String) -> Unit,
    val onFix: (MissedFix) -> Unit,
    val onOpenUntranscribed: () -> Unit,
    /** What needs the person changed: the bell's badge follows it. */
    val onUnread: (Int) -> Unit = {},
    val onBack: (() -> Unit)? = null
)

/**
 * Activity v2 (the bell in Library's header; classic's Activity tab): Needs action (calls waiting for Keep or Discard,
 * each with Keep and Discard and, for several, Keep all and Discard all; recordings that didn't upload, with Retry;
 * missed recordings with why in plain words and their fix; the server's unread problems; untranscribed and failed
 * ones), "Skipped 3 short calls" with Show and Restore, then Today and Earlier. A tap marks a row read and opens its
 * recording; Mark all as read; a swipe either way dismisses a row; "You're all caught up", and "Couldn't load activity"
 * with Retry.
 */
@Composable
fun V2ActivityScreen(vm: MainViewModel, actions: ActivityActions, modifier: Modifier = Modifier, scrollToTop: Int = 0) {
    val pending by vm.pending.collectAsState()
    val missed by vm.missed.collectAsState()
    val library by vm.library.collectAsState()
    val toasts = LocalEchoToasts.current
    val scope = rememberCoroutineScope()
    val context = LocalContext.current
    val res = context.resources
    val batch = remember(toasts) { DiscardBatch(context.applicationContext, vm, toasts, scope) }
    batch.words = DiscardWords(
        one = stringResource(R.string.v2_home_discarded),
        many = { n -> res.getString(R.string.v2_home_discarded_many, n) },
        undo = EchoV2.strings.undo,
        alreadyOne = stringResource(R.string.v2_home_already_deleted),
        alreadySome = stringResource(R.string.v2_home_some_already_deleted)
    )
    val shown = library.filter { !it.deleted }
    V2ActivityContent(
        load = { vm.activity() },
        markRead = { vm.markActivityRead(it) },
        waiting = pending.filter { it.state == "pending_decision" }.sortedByDescending { it.startedAt },
        failedUploads = pending.filter { it.state == "failed" }.map { it.id },
        untranscribed = shown.count { LibraryModel.status(it) == RowStatus.Untranscribed },
        failedTranscripts = shown.count { LibraryModel.status(it) == RowStatus.Failed },
        skipped = pending.filter { it.state == SkippedCalls.STATE },
        missed = missed,
        actions = actions,
        onKeep = { vm.decide(it.id, keep = true) },
        onDiscard = { batch.discard(it.id) },
        onRetryUploads = { vm.retryPending() },
        onRestore = { vm.restoreSkipped(it) },
        onMissedRead = { vm.readMissed(it) },
        onMissedDismiss = { vm.dismissMissed(it) },
        modifier = modifier,
        scrollToTop = scrollToTop
    )
}

/** Activity without the view model (tests and pictures). */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun V2ActivityContent(
    load: suspend () -> ActivityResponse?,
    markRead: suspend (List<String>?) -> Int?,
    waiting: List<LocalRecording>,
    failedUploads: List<String>,
    untranscribed: Int,
    failedTranscripts: Int,
    skipped: List<LocalRecording>,
    missed: List<MissedItem>,
    actions: ActivityActions,
    onKeep: (LocalRecording) -> Unit,
    onDiscard: (LocalRecording) -> Unit,
    onRetryUploads: () -> Unit,
    onRestore: (String) -> Unit,
    onMissedRead: (String) -> Unit,
    onMissedDismiss: (String) -> Unit,
    modifier: Modifier = Modifier,
    today: LocalDate = LocalDate.now(),
    scrollToTop: Int = 0
) {
    val c = EchoV2.colors
    val res = LocalContext.current.resources
    val locale = screenLocale()
    var items by remember { mutableStateOf<List<com.echoledger.mobile.data.ActivityItemDto>?>(null) }
    var failed by remember { mutableStateOf(false) }
    var attempt by remember { mutableIntStateOf(0) }
    var unread by remember { mutableIntStateOf(0) }
    var seenMarked by remember { mutableStateOf(setOf<String>()) }
    var dismissed by remember { mutableStateOf(setOf<String>()) }
    val scope = rememberCoroutineScope()
    val list = rememberLazyListState()
    LaunchedEffect(scrollToTop) { if (scrollToTop > 0) list.animateScrollToItem(0) }
    LaunchedEffect(attempt) {
        val answer = load()
        failed = answer == null
        items = answer?.data.orEmpty()
        unread = answer?.unreadCount ?: 0
        actions.onUnread(ActivityBadge.server(items, missed))
        // As classic: informational items count as read once this list has shown them.
        ActivityBadge.seen(items).takeIf { it.isNotEmpty() }?.let { seen ->
            markRead(seen)?.let {
                unread = it
                seenMarked = seen.toSet()
            }
        }
    }

    fun markOne(id: String) {
        scope.launch {
            markRead(listOf(id))?.let { unread = it }
            items = items?.map { i -> if (i.id == id) i.copy(read = true) else i }
            actions.onUnread(ActivityBadge.server(items, missed))
        }
    }

    fun open(item: com.echoledger.mobile.data.ActivityItemDto) {
        if (!item.read && item.id !in seenMarked) markOne(item.id)
        item.sessionId?.let(actions.onOpenRecording)
    }

    fun dismiss(item: com.echoledger.mobile.data.ActivityItemDto) {
        dismissed = dismissed + item.id
        if (!item.read && item.id !in seenMarked) markOne(item.id)
    }

    fun settleMissed(item: MissedItem, dismiss: Boolean) {
        if (dismiss) onMissedDismiss(item.ref) else onMissedRead(item.ref)
        val after = missed.map { if (it.ref == item.ref) it.copy(read = true, dismissed = it.dismissed || dismiss) else it }
        actions.onUnread(ActivityBadge.server(items, after))
        items?.firstOrNull { it.missedRecordingId != null && it.missedRecordingId == item.serverId && !it.read }?.let { markOne(it.id) }
    }
    val mine = missed.mapNotNull { it.serverId }.toSet()
    val loaded = items.orEmpty().filterNot { it.id in dismissed || it.missedRecordingId in mine }
    val problems = loaded.filter { !it.read && it.isProblem() }
    val rest = loaded - problems.toSet()
    val local = missed.filterNot { it.dismissed }
    val localNeeds = local.filter { !it.read }
    val later: List<Any> = (rest + local.filter { it.read }).sortedByDescending { row ->
        when (row) {
            is MissedItem -> row.startedAtMs

            else -> runCatching {
                java.time.OffsetDateTime.parse((row as com.echoledger.mobile.data.ActivityItemDto).at).toInstant().toEpochMilli()
            }.getOrDefault(0L)
        }
    }
    val needs = waiting.isNotEmpty() || failedUploads.isNotEmpty() || problems.isNotEmpty() || localNeeds.isNotEmpty() ||
        untranscribed > 0 || failedTranscripts > 0
    Column(modifier.fillMaxSize().background(c.bgCanvas)) {
        EchoTopBar(stringResource(R.string.v2_act_title), onBack = actions.onBack) {
            if (unread > 0 || localNeeds.isNotEmpty()) {
                EchoButton(stringResource(R.string.v2_act_mark_all), {
                    scope.launch {
                        markRead(null)?.let {
                            unread = it
                            items = items?.map { i -> i.copy(read = true) }
                            actions.onUnread(0)
                        }
                        missed.filter { !it.read }.forEach { onMissedRead(it.ref) }
                    }
                }, kind = EchoButtonKind.Quiet)
            }
        }
        LazyColumn(Modifier.weight(1f), state = list) {
            when {
                failed -> item(key = "failed") {
                    EchoBanner(
                        stringResource(R.string.v2_act_error),
                        EchoBannerKind.Error,
                        Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s8),
                        actionLabel = stringResource(R.string.v2_act_retry),
                        onAction = {
                            failed = false
                            items = null
                            attempt++
                        }
                    )
                }

                items == null -> item(key = "loading") { EchoSkeletonList(4) }

                !needs && loaded.isEmpty() && local.isEmpty() && skipped.isEmpty() -> item(key = "empty") {
                    EchoEmptyState(
                        stringResource(R.string.v2_act_empty_title),
                        stringResource(R.string.v2_act_empty_body),
                        icon = EchoIcons.Bell
                    )
                }
            }
            if (needs) {
                item(key = "h-needs") { EchoSectionHeader(stringResource(R.string.v2_act_needs)) }
                if (waiting.size > 1) {
                    item(key = "waiting-all") {
                        Row(
                            Modifier.fillMaxWidth().padding(horizontal = EchoTokens.Layout.pagePaddingMobile),
                            horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8, Alignment.End)
                        ) {
                            EchoButton(stringResource(R.string.v2_act_discard_all), {
                                waiting.forEach(onDiscard)
                            }, kind = EchoButtonKind.Plain, icon = EchoIcons.Delete)
                            EchoButton(stringResource(R.string.v2_act_keep_all), {
                                waiting.forEach(onKeep)
                            }, kind = EchoButtonKind.Secondary)
                        }
                    }
                }
                items(waiting, key = { "w-" + it.id }) { rec -> WaitingCall(rec, res, onKeep, onDiscard) }
                if (failedUploads.isNotEmpty()) {
                    item(key = "uploads") {
                        EchoSettingRow(
                            if (failedUploads.size == 1) {
                                stringResource(R.string.v2_home_failed_upload_one)
                            } else {
                                stringResource(R.string.v2_home_failed_uploads, failedUploads.size)
                            },
                            subtitle = stringResource(R.string.v2_home_failed_uploads_detail),
                            icon = EchoIcons.Error,
                            trailing = {
                                EchoButton(stringResource(R.string.v2_home_retry), onRetryUploads, kind = EchoButtonKind.Secondary)
                            }
                        )
                    }
                }
                items(localNeeds, key = { "m-" + it.ref }) { row ->
                    Dismissible({ settleMissed(row, dismiss = true) }) {
                        MissedRow(row, today, locale, onClick = { settleMissed(row, dismiss = false) }) {
                            settleMissed(row, dismiss = false)
                            actions.onFix(row.button)
                        }
                    }
                }
                items(problems, key = { "a-" + it.id }) { row ->
                    Dismissible({ dismiss(row) }) { ActivityRow(row, today, locale) { open(row) } }
                }
                if (untranscribed > 0) {
                    item(key = "untranscribed") {
                        EchoSettingRow(
                            if (untranscribed == 1) {
                                stringResource(R.string.v2_home_untranscribed_one)
                            } else {
                                stringResource(R.string.v2_home_untranscribed, untranscribed)
                            },
                            icon = EchoIcons.Transcribe,
                            onClick = actions.onOpenUntranscribed
                        )
                    }
                }
                if (failedTranscripts > 0) {
                    item(key = "failed-transcripts") {
                        EchoSettingRow(
                            if (failedTranscripts == 1) {
                                stringResource(R.string.v2_home_failed_transcript_one)
                            } else {
                                stringResource(R.string.v2_home_failed_transcripts, failedTranscripts)
                            },
                            icon = EchoIcons.Error,
                            onClick = actions.onOpenUntranscribed
                        )
                    }
                }
            }
            if (skipped.isNotEmpty()) item(key = "skipped") { SkippedLine(skipped, onRestore) }
            later.groupBy { row ->
                val at = if (row is MissedItem) {
                    java.time.Instant.ofEpochMilli(row.startedAtMs).toString()
                } else {
                    (row as com.echoledger.mobile.data.ActivityItemDto).at
                }
                com.echoledger.mobile.ui.design.dateGroup(at, today) == "Today"
            }.toSortedMap(compareBy { !it }).forEach { (isToday, rows) ->
                item(key = "h-$isToday") {
                    EchoSectionHeader(stringResource(if (isToday) R.string.v2_act_today else R.string.v2_act_earlier))
                }
                items(rows, key = {
                    if (it is MissedItem) "m-" + it.ref else "a-" + (it as com.echoledger.mobile.data.ActivityItemDto).id
                }) { row ->
                    if (row is MissedItem) {
                        Dismissible({ settleMissed(row, dismiss = true) }) {
                            MissedRow(row, today, locale, onClick = null) { actions.onFix(row.button) }
                        }
                    } else {
                        val item = row as com.echoledger.mobile.data.ActivityItemDto
                        Dismissible({ dismiss(item) }) { ActivityRow(item, today, locale) { open(item) } }
                    }
                }
            }
            item { Box(Modifier.padding(bottom = EchoTokens.Space.s24)) }
        }
    }
}

/** A row that a swipe either way dismisses ("Dismiss" behind it). */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
private fun Dismissible(onDismiss: () -> Unit, content: @Composable () -> Unit) {
    val state = rememberSwipeToDismissBoxState()
    LaunchedEffect(state.currentValue) { if (state.currentValue != SwipeToDismissBoxValue.Settled) onDismiss() }
    SwipeToDismissBox(
        state = state,
        backgroundContent = {
            Box(
                Modifier.fillMaxSize().background(EchoV2.colors.bgSubtle).padding(horizontal = EchoTokens.Layout.pagePaddingMobile),
                contentAlignment = Alignment.CenterEnd
            ) { Text(stringResource(R.string.v2_act_dismiss), style = EchoV2.type.label, color = EchoV2.colors.textSecondary) }
        }
    ) { Box(Modifier.background(EchoV2.colors.bgCanvas)) { content() } }
}

/** The row's time ("8:00 AM", "Mon", "10 Nov"), kept left to right inside Urdu so "AM" stays after the hour. */
private fun time(at: String?, today: LocalDate, locale: java.util.Locale): String =
    "\u2066" + LibraryModel.latinDigits(com.echoledger.mobile.ui.design.rowTime(at, today, locale = locale)) + "\u2069"

@Composable
private fun ActivityRow(item: com.echoledger.mobile.data.ActivityItemDto, today: LocalDate, locale: java.util.Locale, onClick: () -> Unit) {
    val problem = item.isProblem()
    EchoListRow(
        title = item.title.ifBlank { stringResource(R.string.v2_act_update) },
        meta = item.body.ifBlank { null },
        titleEnd = time(item.at, today, locale),
        leading = { EchoAvatar(icon = if (problem) EchoIcons.Error else EchoIcons.Bell) },
        trailing = if (!item.read) ({ UnreadDot() }) else null,
        tall = true,
        onClick = onClick
    )
}

@Composable
private fun UnreadDot() = Box(
    Modifier.padding(
        EchoTokens.Space.s4
    ).background(EchoV2.colors.accent, androidx.compose.foundation.shape.CircleShape).padding(EchoTokens.Space.s4)
)

/** A missed recording: "Missed recording", the call and its length, why in plain words, and the fix. */
@Composable
private fun MissedRow(item: MissedItem, today: LocalDate, locale: java.util.Locale, onClick: (() -> Unit)?, onFix: () -> Unit) {
    val res = LocalContext.current.resources
    val app = SourceApp.from(item.app)
    val call = listOfNotNull(
        if (app == SourceApp.PHONE_CALL) {
            stringResource(R.string.v2_act_phone_call)
        } else {
            stringResource(R.string.v2_act_app_call, sourceName(res, item.app))
        },
        item.durationS?.let { formatClock(it * 1000) }
    ).joinToString(" · ")
    Column {
        EchoListRow(
            title = stringResource(R.string.v2_act_missed),
            meta = call,
            titleEnd = time(java.time.Instant.ofEpochMilli(item.startedAtMs).toString(), today, locale),
            leading = { EchoAvatar(icon = EchoIcons.Missed) },
            trailing = if (!item.read) ({ UnreadDot() }) else null,
            tall = true,
            onClick = onClick
        )
        Text(
            MissedWords.why(res, item),
            style = EchoV2.type.caption,
            color = EchoV2.colors.textSecondary,
            modifier = Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile)
        )
        EchoButton(
            MissedWords.fix(res, item.button),
            onFix,
            Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s8),
            kind = EchoButtonKind.Secondary
        )
    }
}

/** A missed recording's reason and fix in the screen's language (the phone's own sentences, never free text). */
object MissedWords {
    private val sentences = mapOf(
        MissedReason.PHONE_LINE to R.string.v2_act_why_line,
        "Android did not let auto-record start in the background." to R.string.v2_act_why_background,
        "Auto-record was not running when the call started." to R.string.v2_act_why_not_running
    ) + MissedReason.entries.associate { it.detail to whyOf(it) }

    private fun whyOf(reason: MissedReason): Int = when (reason) {
        MissedReason.HELPER_NOT_ARMED -> R.string.v2_act_why_helper
        MissedReason.PERMISSION -> R.string.v2_act_why_permission
        MissedReason.DETECTION -> R.string.v2_act_why_detection
        MissedReason.MIC_BUSY -> R.string.v2_act_why_mic_busy
        MissedReason.CRASH -> R.string.v2_act_why_crash
        MissedReason.PLAN -> R.string.v2_act_why_plan
        MissedReason.UNKNOWN -> R.string.v2_act_why_unknown
    }

    fun why(res: Resources, item: MissedItem): String = sentences[item.detail]?.let(res::getString)
        ?: item.detail.ifBlank { res.getString(whyOf(MissedReason.of(item.reason))) }

    fun fix(res: Resources, fix: MissedFix): String = res.getString(
        when (fix) {
            MissedFix.START_HELPER -> R.string.v2_act_fix_start_helper
            MissedFix.PERMISSIONS -> R.string.v2_act_fix_permissions
            MissedFix.TEST -> R.string.v2_act_fix_test
            MissedFix.CHECK -> R.string.v2_act_fix_check
            MissedFix.PLANS -> R.string.v2_act_fix_plans
            MissedFix.TURN_ON -> R.string.v2_act_fix_turn_on
        }
    )
}

/** "Skipped 3 short calls" with Show and Hide; each with what it was and Restore. */
@Composable
private fun SkippedLine(skipped: List<LocalRecording>, onRestore: (String) -> Unit) {
    val c = EchoV2.colors
    val res = LocalContext.current.resources
    var open by remember { mutableStateOf(false) }
    Column(Modifier.fillMaxWidth().padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s4)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(
                if (skipped.size ==
                    1
                ) {
                    stringResource(R.string.v2_act_skipped_one)
                } else {
                    stringResource(R.string.v2_act_skipped, skipped.size)
                },
                style = EchoV2.type.caption,
                color = c.textSecondary,
                modifier = Modifier.weight(1f)
            )
            EchoButton(stringResource(if (open) R.string.v2_act_hide else R.string.v2_act_show), {
                open = !open
            }, kind = EchoButtonKind.Quiet)
        }
        if (open) {
            skipped.sortedByDescending { it.startedAt }.forEach { rec ->
                Row(verticalAlignment = Alignment.CenterVertically) {
                    val reason = when (SkippedCalls.reasonOf(rec)) {
                        SkippedCalls.Reason.NOT_ANSWERED -> R.string.v2_act_skip_not_answered
                        SkippedCalls.Reason.TOO_SHORT -> R.string.v2_act_skip_too_short
                        SkippedCalls.Reason.NO_SPEECH -> R.string.v2_act_skip_no_speech
                        null -> R.string.v2_act_skip_other
                    }
                    Text(
                        listOf(sourceName(res, rec.sourceApp), formatClock(rec.durationMs), stringResource(reason)).joinToString(" · "),
                        style = EchoV2.type.body,
                        color = c.textPrimary,
                        maxLines = 1,
                        overflow = androidx.compose.ui.text.style.TextOverflow.Ellipsis,
                        modifier = Modifier.weight(1f)
                    )
                    EchoButton(stringResource(R.string.v2_act_restore), { onRestore(rec.id) }, kind = EchoButtonKind.Quiet)
                }
            }
        }
    }
}

/** A call waiting for Keep or Discard: who and how long, then Discard and Keep (as Home had it). */
@Composable
private fun WaitingCall(rec: LocalRecording, res: Resources, onKeep: (LocalRecording) -> Unit, onDiscard: (LocalRecording) -> Unit) {
    val c = EchoV2.colors
    Column {
        EchoListRow(
            title = afterCallTitle(res, rec),
            meta = stringResource(R.string.v2_home_waiting_decision),
            metaEnd = { Text(formatClock(rec.durationMs + 500), style = EchoV2.type.mono, color = c.textSecondary) },
            leading = { EchoAvatar(icon = LibraryModel.icon(SourceApp.from(rec.sourceApp))) },
            tall = true
        )
        Row(
            Modifier.fillMaxWidth().padding(horizontal = EchoTokens.Layout.pagePaddingMobile),
            horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8, Alignment.End)
        ) {
            EchoButton(stringResource(R.string.v2_home_discard), { onDiscard(rec) }, kind = EchoButtonKind.Plain, icon = EchoIcons.Delete)
            EchoButton(stringResource(R.string.v2_home_keep), { onKeep(rec) }, kind = EchoButtonKind.Secondary)
        }
    }
}

/**
 * The bell's badge: the server's unread problems ([serverUnread], from the last Activity answer), this phone's missed
 * recordings not looked at and the calls waiting for Keep or Discard (as classic's Activity tab).
 */
fun activityBadge(serverUnread: Int, missed: List<MissedItem>, pending: List<LocalRecording>): Int =
    serverUnread + ActivityBadge.local(missed) + pending.count { it.state == "pending_decision" }
