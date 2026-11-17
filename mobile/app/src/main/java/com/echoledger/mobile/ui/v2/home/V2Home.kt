package com.echoledger.mobile.ui.v2.home

import android.content.Context
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalConfiguration
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import com.echoledger.mobile.R
import com.echoledger.mobile.calls.Decisions
import com.echoledger.mobile.core.Readiness
import com.echoledger.mobile.data.LocalRecording
import com.echoledger.mobile.recording.PillMachine
import com.echoledger.mobile.recording.RecordingService
import com.echoledger.mobile.ui.MainViewModel
import com.echoledger.mobile.ui.v2.components.EchoAvatar
import com.echoledger.mobile.ui.v2.components.EchoButton
import com.echoledger.mobile.ui.v2.components.EchoButtonKind
import com.echoledger.mobile.ui.v2.components.EchoCard
import com.echoledger.mobile.ui.v2.components.EchoEmptyState
import com.echoledger.mobile.ui.v2.components.EchoErrorState
import com.echoledger.mobile.ui.v2.components.EchoIcons
import com.echoledger.mobile.ui.v2.components.EchoListRow
import com.echoledger.mobile.ui.v2.components.EchoRecordingDot
import com.echoledger.mobile.ui.v2.components.EchoSectionHeader
import com.echoledger.mobile.ui.v2.components.EchoSettingRow
import com.echoledger.mobile.ui.v2.components.EchoSkeletonList
import com.echoledger.mobile.ui.v2.components.EchoStatus
import com.echoledger.mobile.ui.v2.components.EchoStatusKind
import com.echoledger.mobile.ui.v2.components.EchoToastResult
import com.echoledger.mobile.ui.v2.components.EchoToastState
import com.echoledger.mobile.ui.v2.components.EchoTopBar
import com.echoledger.mobile.ui.v2.components.formatClock
import com.echoledger.mobile.ui.v2.library.LibraryModel
import com.echoledger.mobile.ui.v2.live.afterCallTitle
import com.echoledger.mobile.ui.v2.setup.rememberSetupFacts
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.shell.v2Text
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.tokens.EchoTokens
import java.time.Instant
import java.time.ZonedDateTime
import java.util.Locale
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

/** Where Home's rows and buttons lead; the shell decides the screens. */
class HomeActions(
    val onSetup: () -> Unit,
    val onAllow: () -> Unit,
    val onRecord: () -> Unit,
    val onChooseLanguage: () -> Unit,
    val onOpenRecording: (String) -> Unit,
    val onOpenUntranscribed: () -> Unit,
    val onSeeAll: () -> Unit,
    val onPlan: () -> Unit,
    val onAccount: () -> Unit
)

/**
 * Home v2 (behind `ui_v2.home`, docs/v2/IA.md 1): the status strip, the Get started checklist until it is done, what
 * needs the person (calls waiting for Keep or Discard, recordings that didn't upload, untranscribed and failed ones),
 * the last five recordings and the month's minutes. Everything comes from data the phone already has: the Ready check,
 * the recording, the library and the phone's own queue, through the existing view model.
 */
@Composable
fun V2HomeScreen(vm: MainViewModel, initials: String, actions: HomeActions, modifier: Modifier = Modifier) {
    val library by vm.library.collectAsState()
    val pending by vm.pending.collectAsState()
    val syncing by vm.syncing.collectAsState()
    val offline by vm.offline.collectAsState()
    val settings by vm.settings.collectAsState()
    val live by RecordingService.live.collectAsState()
    val setup = rememberSetupFacts(vm, 0)
    val readiness = remember(setup) { Readiness.evaluate(setup.ready) }
    val facts = HomeFacts(
        chip = readiness.chip,
        callsPossible = setup.callsPossible,
        captureReady = setup.helperReady,
        micAllowed = setup.ready.capabilities.micPermission,
        notificationsAllowed = setup.ready.capabilities.notificationPermission,
        live = live,
        library = library,
        pending = pending,
        transcriptionLanguage = settings.transcriptionLanguage,
        syncing = syncing,
        hasSynced = vm.hasSynced(),
        offline = offline,
        usedMinutes = settings.plan.usedMinutes,
        monthlyMinutes = settings.plan.monthlyMinutes
    )
    val toasts = LocalEchoToasts.current
    val scope = rememberCoroutineScope()
    val context = LocalContext.current.applicationContext
    val res = LocalContext.current.resources
    val words = DiscardWords(
        one = stringResource(R.string.v2_home_discarded),
        many = { n -> res.getString(R.string.v2_home_discarded_many, n) },
        undo = EchoV2.strings.undo,
        alreadyOne = stringResource(R.string.v2_home_already_deleted),
        alreadySome = stringResource(R.string.v2_home_some_already_deleted)
    )
    val batch = remember(toasts) { DiscardBatch(context, vm, toasts, scope) }
    batch.words = words
    V2HomeContent(
        state = HomeModel.state(facts),
        initials = initials,
        actions = actions,
        onKeep = { vm.decide(it.id, keep = true) },
        onDiscard = { rec -> batch.discard(rec.id) },
        onRetryUploads = { vm.retryPending() },
        onRetryLibrary = { vm.refresh() },
        modifier = modifier
    )
}

/** The words of Home's discard toasts. */
class DiscardWords(val one: String, val many: (Int) -> String, val undo: String, val alreadyOne: String, val alreadySome: String)

/**
 * Home's Discard with Undo, on the delayed discard of v1.1 (P-003). [Decisions.discardLater] runs at once, so the call
 * is "discarding" on the phone: the 10 s keep and the decision notification are cancelled, and it survives the process
 * dying; the existing worker deletes it when its time is over. The toast's END TIME is the earliest stored discard time
 * less [DISCARD_MARGIN_MS], on the same wall clock, so it is gone before any of its calls is; it never waits behind
 * another toast. Discards in a row share one toast ("2 calls discarded · Undo") that undoes them all; Undo calls
 * [Decisions.undoDiscard], and when a call was already gone the toast says so.
 */
class DiscardBatch(
    private val context: Context,
    private val vm: MainViewModel,
    private val toasts: EchoToastState,
    private val scope: CoroutineScope
) {
    /** The words in the screen's language, set on every composition. */
    lateinit var words: DiscardWords

    private val calls = mutableListOf<Pair<String, Long>>()
    private var showing: Job? = null

    fun discard(id: String) {
        scope.launch {
            val discarding = withContext(Dispatchers.IO) { Decisions.discardLater(context, id, toasts.clock()) } ?: return@launch
            vm.reloadLocal()
            val endsAt = runCatching { Instant.parse(discarding.discardAt).toEpochMilli() }.getOrNull() ?: return@launch
            calls += id to endsAt
            showing?.cancel()
            showing = scope.launch { show(calls.toList()) }
        }
    }

    private suspend fun show(batch: List<Pair<String, Long>>) {
        val deadline = batch.minOf { it.second } - DISCARD_MARGIN_MS
        val message = if (batch.size == 1) words.one else words.many(batch.size)
        val result = toasts.show(message, words.undo, deadlineMs = deadline)
        calls.removeAll(batch)
        if (result != EchoToastResult.Action) return
        val back = withContext(Dispatchers.IO) { batch.map { (id, _) -> Decisions.undoDiscard(context, id) } }
        vm.reloadLocal()
        if (back.any { it == null }) toasts.show(if (batch.size == 1) words.alreadyOne else words.alreadySome)
    }
}

/**
 * How long before the file's discard time the toast ends: the slack the delayed discard keeps after its own "Discarded ·
 * Undo" (Decisions.DISCARD_AFTER_MS is that time plus 2 s), so Undo in the toast's last second still finds the call.
 */
internal const val DISCARD_MARGIN_MS = 2_000L

/** Home without the view model (tests and screenshots). */
@Composable
fun V2HomeContent(
    state: HomeState,
    initials: String,
    actions: HomeActions,
    onKeep: (LocalRecording) -> Unit,
    onDiscard: (LocalRecording) -> Unit,
    onRetryUploads: () -> Unit,
    onRetryLibrary: () -> Unit,
    modifier: Modifier = Modifier,
    now: ZonedDateTime = ZonedDateTime.now()
) {
    val c = EchoV2.colors
    val text = v2Text
    val res = LocalContext.current.resources
    val locale = LocalConfiguration.current.locales[0] ?: Locale.getDefault()
    Column(modifier.fillMaxSize().background(c.bgCanvas)) {
        EchoTopBar(stringResource(R.string.v2_home_title), large = true) {
            Box(
                Modifier
                    .size(EchoTokens.Layout.controlTouchMin)
                    .clickable(onClickLabel = text.accountAndSettings, onClick = actions.onAccount)
                    .semantics { contentDescription = text.accountAndSettings },
                contentAlignment = Alignment.Center
            ) { EchoAvatar(initials = initials) }
        }
        LazyColumn(Modifier.weight(1f)) {
            item { StatusStrip(state.status, actions) }
            if (state.checklist.isNotEmpty()) item { Checklist(state.checklist, actions) }
            item { EchoSectionHeader(stringResource(R.string.v2_home_needs_action)) }
            if (state.needsAction.isEmpty()) {
                item { EchoSettingRow(stringResource(R.string.v2_home_nothing_needed), icon = EchoIcons.Check) }
            }
            items(state.needsAction, key = { it.key() }) { need ->
                when (need) {
                    is NeedsAction.WaitingDecision -> WaitingCall(need.recording, res, onKeep, onDiscard)

                    is NeedsAction.FailedUploads -> EchoSettingRow(
                        if (need.ids.size == 1) {
                            stringResource(R.string.v2_home_failed_upload_one)
                        } else {
                            stringResource(R.string.v2_home_failed_uploads, need.ids.size)
                        },
                        subtitle = stringResource(R.string.v2_home_failed_uploads_detail),
                        icon = EchoIcons.Error,
                        trailing = { EchoButton(stringResource(R.string.v2_home_retry), onRetryUploads, kind = EchoButtonKind.Secondary) }
                    )

                    is NeedsAction.Untranscribed -> EchoSettingRow(
                        if (need.count == 1) {
                            stringResource(R.string.v2_home_untranscribed_one)
                        } else {
                            stringResource(R.string.v2_home_untranscribed, need.count)
                        },
                        icon = EchoIcons.Transcribe,
                        onClick = actions.onOpenUntranscribed
                    )

                    is NeedsAction.FailedTranscripts -> EchoSettingRow(
                        if (need.count == 1) {
                            stringResource(R.string.v2_home_failed_transcript_one)
                        } else {
                            stringResource(R.string.v2_home_failed_transcripts, need.count)
                        },
                        icon = EchoIcons.Error,
                        onClick = actions.onOpenUntranscribed
                    )
                }
            }
            item {
                Row(
                    Modifier.padding(start = EchoTokens.Layout.pagePaddingMobile, end = EchoTokens.Space.s4, top = EchoTokens.Space.s16),
                    verticalAlignment = Alignment.CenterVertically
                ) {
                    Text(
                        stringResource(R.string.v2_home_recent),
                        style = EchoV2.type.label,
                        color = c.textSecondary,
                        modifier = Modifier.weight(1f).semantics { heading() }
                    )
                    if (state.recentState == RecentState.Ready) {
                        EchoButton(stringResource(R.string.v2_home_see_all), actions.onSeeAll, kind = EchoButtonKind.Quiet)
                    }
                }
            }
            when (state.recentState) {
                RecentState.Loading -> item { EchoSkeletonList(3) }

                RecentState.Error -> item {
                    EchoErrorState(
                        stringResource(R.string.v2_home_error_title),
                        stringResource(R.string.v2_home_error_body),
                        actionLabel = text.tryAgain,
                        onAction = onRetryLibrary
                    )
                }

                RecentState.Empty -> item {
                    EchoEmptyState(
                        stringResource(R.string.v2_home_empty_title),
                        stringResource(R.string.v2_home_empty_body),
                        icon = EchoIcons.VoiceNote,
                        actionLabel = stringResource(R.string.v2_home_action_record),
                        onAction = actions.onRecord
                    )
                }

                RecentState.Ready -> items(state.recent, key = { it.id }) { item ->
                    val row = LibraryModel.row(item, now, text, locale)
                    EchoListRow(
                        title = row.title,
                        meta = row.meta,
                        titleEnd = row.time,
                        metaEnd = { Text(row.duration, style = EchoV2.type.mono, color = c.textSecondary) },
                        leading = { EchoAvatar(icon = row.icon, contentDescription = row.source) },
                        tall = true,
                        onClick = { actions.onOpenRecording(item.id) }
                    )
                }
            }
            state.usedMinutes?.let { used ->
                item { EchoSectionHeader(stringResource(R.string.v2_home_usage)) }
                item {
                    EchoSettingRow(
                        state.monthlyMinutes?.let { stringResource(R.string.v2_home_usage_minutes, used.toInt(), it.toInt()) }
                            ?: stringResource(R.string.v2_home_usage_unlimited, used.toInt()),
                        onClick = actions.onPlan
                    )
                }
            }
            item { Box(Modifier.padding(bottom = EchoTokens.Space.s24)) }
        }
    }
}

private fun NeedsAction.key(): String = when (this) {
    is NeedsAction.WaitingDecision -> "wait-" + recording.id
    is NeedsAction.FailedUploads -> "uploads"
    is NeedsAction.Untranscribed -> "untranscribed"
    is NeedsAction.FailedTranscripts -> "failed"
}

/** The status strip: what calls are doing now, in one line, with the fix when something needs setting up. */
@Composable
private fun StatusStrip(status: HomeStatus, actions: HomeActions) {
    val c = EchoV2.colors
    EchoCard(Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s8)) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s12)) {
            when (status) {
                is HomeStatus.Recording -> {
                    EchoRecordingDot(live = !status.paused)
                    Text(
                        stringResource(R.string.v2_home_status_recording, PillMachine.clock(status.elapsedMs)),
                        style = EchoV2.type.bodyStrong,
                        color = c.textPrimary,
                        modifier = Modifier.weight(1f)
                    )
                }

                HomeStatus.Ready -> {
                    EchoStatus(stringResource(R.string.v2_home_status_ready), EchoStatusKind.Success, Modifier.weight(1f, fill = false))
                    Box(Modifier.weight(1f))
                }

                HomeStatus.NeedsSetup -> {
                    EchoStatus(stringResource(R.string.v2_home_status_needs), EchoStatusKind.Warning)
                    Box(Modifier.weight(1f))
                    EchoButton(stringResource(R.string.v2_home_fix), actions.onSetup, kind = EchoButtonKind.Secondary)
                }

                HomeStatus.CallsOff -> {
                    EchoStatus(stringResource(R.string.v2_home_status_off), EchoStatusKind.Neutral, Modifier.weight(1f, fill = false))
                    Box(Modifier.weight(1f))
                    EchoButton(stringResource(R.string.v2_home_open), actions.onSetup, kind = EchoButtonKind.Quiet)
                }

                HomeStatus.VoiceOnly -> {
                    EchoStatus(stringResource(R.string.v2_home_status_voice), EchoStatusKind.Neutral, Modifier.weight(1f, fill = false))
                    Box(Modifier.weight(1f))
                }
            }
        }
    }
}

/** Get started: each item done (a check) or its one action; the count of what is done. */
@Composable
private fun Checklist(items: List<Pair<ChecklistItem, Boolean>>, actions: HomeActions) {
    val c = EchoV2.colors
    Column {
        Row(
            Modifier.fillMaxWidth().padding(
                start = EchoTokens.Layout.pagePaddingMobile,
                end = EchoTokens.Layout.pagePaddingMobile,
                top = EchoTokens.Space.s16
            ),
            verticalAlignment = Alignment.CenterVertically
        ) {
            Text(
                stringResource(R.string.v2_home_get_started),
                style = EchoV2.type.label,
                color = c.textSecondary,
                modifier = Modifier.weight(1f).semantics { heading() }
            )
            Text(
                stringResource(R.string.v2_home_get_started_progress, items.count { it.second }, items.size),
                style = EchoV2.type.caption,
                color = c.textSecondary
            )
        }
        items.forEach { (item, done) ->
            val (title, action, run) = when (item) {
                ChecklistItem.Capture -> Triple(R.string.v2_home_check_capture, R.string.v2_home_action_set_up, actions.onSetup)

                ChecklistItem.Permissions -> Triple(R.string.v2_home_check_permissions, R.string.v2_home_action_allow, actions.onAllow)

                ChecklistItem.FirstRecording -> Triple(
                    R.string.v2_home_check_first_recording,
                    R.string.v2_home_action_record,
                    actions.onRecord
                )

                ChecklistItem.Language -> Triple(R.string.v2_home_check_language, R.string.v2_home_action_choose, actions.onChooseLanguage)
            }
            Row(
                Modifier.fillMaxWidth().padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s4),
                verticalAlignment = Alignment.CenterVertically,
                horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s16)
            ) {
                Icon(
                    if (done) EchoIcons.Check else EchoIcons.Todo,
                    null,
                    Modifier.size(EchoTokens.Icon.lg),
                    tint = if (done) c.success else c.textSecondary
                )
                Text(
                    stringResource(title),
                    style = EchoV2.type.body,
                    color = if (done) c.textSecondary else c.textPrimary,
                    modifier = Modifier.weight(1f).padding(vertical = EchoTokens.Space.s12)
                )
                if (!done) EchoButton(stringResource(action), run, kind = EchoButtonKind.Secondary)
            }
        }
    }
}

/** A call waiting for Keep or Discard: who and how long, then Discard and Keep. */
@Composable
private fun WaitingCall(
    rec: LocalRecording,
    res: android.content.res.Resources,
    onKeep: (LocalRecording) -> Unit,
    onDiscard: (LocalRecording) -> Unit
) {
    val c = EchoV2.colors
    Column {
        EchoListRow(
            title = afterCallTitle(res, rec),
            meta = stringResource(R.string.v2_home_waiting_decision),
            metaEnd = { Text(formatClock(rec.durationMs + 500), style = EchoV2.type.mono, color = c.textSecondary) },
            leading = { EchoAvatar(icon = LibraryModel.icon(com.echoledger.mobile.core.SourceApp.from(rec.sourceApp))) },
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
