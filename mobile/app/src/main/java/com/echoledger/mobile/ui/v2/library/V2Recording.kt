package com.echoledger.mobile.ui.v2.library

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.rememberScrollState
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
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
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.style.TextOverflow
import androidx.lifecycle.viewModelScope
import com.echoledger.mobile.R
import com.echoledger.mobile.core.Speakers
import com.echoledger.mobile.core.TranscriptText
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.ScreenDto
import com.echoledger.mobile.data.SegmentDto
import com.echoledger.mobile.data.TranscriptDto
import com.echoledger.mobile.data.TranscriptViewsDto
import com.echoledger.mobile.ui.MainViewModel
import com.echoledger.mobile.ui.RecordingPlayerState
import com.echoledger.mobile.ui.ShowAs
import com.echoledger.mobile.ui.TranscriptVersion
import com.echoledger.mobile.ui.printHtml
import com.echoledger.mobile.ui.rememberRecordingPlayer
import com.echoledger.mobile.ui.shareFile
import com.echoledger.mobile.ui.v2.components.EchoActionItem
import com.echoledger.mobile.ui.v2.components.EchoEmptyState
import com.echoledger.mobile.ui.v2.components.EchoErrorState
import com.echoledger.mobile.ui.v2.components.EchoIconButton
import com.echoledger.mobile.ui.v2.components.EchoIcons
import com.echoledger.mobile.ui.v2.components.EchoPlayer
import com.echoledger.mobile.ui.v2.components.EchoPlayerLabels
import com.echoledger.mobile.ui.v2.components.EchoSettingRow
import com.echoledger.mobile.ui.v2.components.EchoSkeletonList
import com.echoledger.mobile.ui.v2.components.EchoStatus
import com.echoledger.mobile.ui.v2.components.EchoStatusKind
import com.echoledger.mobile.ui.v2.components.EchoTabs
import com.echoledger.mobile.ui.v2.components.EchoTopBar
import com.echoledger.mobile.ui.v2.components.EchoTranscriptLine
import com.echoledger.mobile.ui.v2.components.formatClock
import com.echoledger.mobile.ui.v2.shell.LocalEchoToasts
import com.echoledger.mobile.ui.v2.shell.V2Text
import com.echoledger.mobile.ui.v2.shell.v2Text
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.theme.screenLocale
import com.echoledger.mobile.ui.v2.tokens.EchoTokens
import java.time.ZonedDateTime
import kotlinx.coroutines.launch

/** The transcript as the page shows it: loading, none yet, failed (with the server's plain sentence), or the lines. */
sealed interface TranscriptState {
    data object Loading : TranscriptState
    data object None : TranscriptState
    data object Unreachable : TranscriptState

    /** Failed, with its error code: the page words it in the phone's own words ([failureWords]), never the server's. */
    data class Failed(val errorCode: String?) : TranscriptState
    data class Lines(val segments: List<SegmentDto>) : TranscriptState
}

/** "You" and "Other side" for the call channels; any other speaker keeps its name. */
fun speakerName(speaker: String, text: V2Text): String = when (speaker) {
    Speakers.ME -> text.me
    Speakers.CLIENT -> text.client
    Speakers.SPEAKER -> text.speaker
    else -> speaker
}

fun transcriptState(item: LibraryItem, loaded: TranscriptDto?, tried: Boolean): TranscriptState {
    val summary = item.transcript ?: return TranscriptState.None
    if (summary.finalTranscriptId == null) return TranscriptState.None
    if (loaded == null) return if (tried) TranscriptState.Unreachable else TranscriptState.Loading
    return when {
        loaded.status == "failed" -> TranscriptState.Failed(loaded.errorCode)
        loaded.segments.isEmpty() -> TranscriptState.None
        else -> TranscriptState.Lines(loaded.segments)
    }
}

/**
 * A recording on the v2 components (behind `ui_v2.library`): the title (tap to rename), source and time, the player, the
 * action row Share · Copy ▾ · ⋮, then the transcript with speakers, or its designed empty, loading and error states. The
 * ⋮ menu has Rename, Move to project, Labels, Pin, Keep forever, Change language & re-transcribe, Skip silences,
 * Export ▸ and Delete (asked, then Undo). Its logic is classic's recording page's ([RecordingPage]).
 * [startAtMs]: opened from a search match, it plays from there once ready; [backToSource]: a voice note shared from
 * another app goes back there.
 */
@Composable
fun V2RecordingScreen(
    vm: MainViewModel,
    item: LibraryItem,
    onBack: () -> Unit,
    modifier: Modifier = Modifier,
    startAtMs: Long? = null,
    backToSource: Pair<String, () -> Unit>? = null,
    /** No audio's Run capture test. */
    onCaptureTest: () -> Unit = {},
    /** Opens a Settings page by its id ("Check your key" opens the Transcription key page). */
    onOpenSettings: (String) -> Unit = {}
) {
    val text = v2Text
    val page = rememberRecordingPage(vm, item, startAtMs)
    var viewing by remember(item.id) { mutableStateOf<Int?>(null) }
    val segments = page.transcript?.segments.orEmpty()
    CompositionLocalProvider(LocalScreenPictures provides { link -> vm.screenImage(link) }) {
        viewing?.let { start ->
            V2ScreenViewer(
                moments = remember(page.screens, segments, text) { ScreenAssets.moments(page.screens, segments) { speakerName(it, text) } },
                startIndex = start,
                onPlayFrom = { at ->
                    page.playFrom(at)
                    viewing = null
                },
                onClose = { viewing = null },
                modifier = modifier,
                transcriptReady = page.transcript != null
            )
            return@CompositionLocalProvider
        }
        V2RecordingPage(vm, page, text, { viewing = it }, onBack, modifier, backToSource, onCaptureTest, onOpenSettings)
    }
}

/** The sheet or dialog open above the page. */
private enum class RecordingSheet { Menu, Copy, Share, Rename, Move, Labels, Retranscribe, Delete }

@Composable
private fun V2RecordingPage(
    vm: MainViewModel,
    page: RecordingPage,
    text: V2Text,
    onOpenScreen: (Int) -> Unit,
    onBack: () -> Unit,
    modifier: Modifier,
    backToSource: Pair<String, () -> Unit>?,
    onCaptureTest: () -> Unit,
    onOpenSettings: (String) -> Unit
) {
    val context = LocalContext.current
    val toasts = LocalEchoToasts.current
    val scope = rememberCoroutineScope()
    val undo = EchoV2.strings.undo
    val item = page.item
    val player = page.player
    val settings by vm.settings.collectAsState()
    val library by vm.library.collectAsState()
    val clients by vm.clients.collectAsState()
    var sheet by remember(item.id) { mutableStateOf<RecordingSheet?>(null) }
    val hasAudio = page.playbackUrl != null && !page.noAudio
    val res = LocalContext.current.resources
    fun say(words: String) {
        scope.launch { toasts.show(words) }
    }
    fun copy(kind: CopyKind) {
        val t = page.transcript ?: return
        val copied = RecordingCopy.text(kind, item, t.segments, page.shown, settings, page.screens, page.speakerNames)
        copyToClipboard(context, "EchoLedger transcript", copied)
        say(
            res.getString(
                when (kind) {
                    CopyKind.WithTimestamps -> R.string.v2_rec_copied_with
                    CopyKind.ForAi -> R.string.v2_rec_copied_ai
                    else -> R.string.v2_rec_copied
                }
            )
        )
    }
    val actions = object : RecordingMenuActions {
        override fun rename() {
            sheet = RecordingSheet.Rename
        }

        override fun move() {
            sheet = RecordingSheet.Move
        }

        override fun labels() {
            sheet = RecordingSheet.Labels
        }

        override fun pin(on: Boolean) {
            scope.launch {
                if (vm.organise(item.id, pinned = on)) say(res.getString(if (on) R.string.v2_rec_pinned else R.string.v2_rec_unpinned))
            }
        }

        override fun keep(on: Boolean) {
            scope.launch {
                if (vm.organise(item.id, keep = on)) say(res.getString(if (on) R.string.v2_rec_kept else R.string.v2_rec_not_kept))
            }
        }

        override fun retranscribe() {
            sheet = RecordingSheet.Retranscribe
        }

        override fun skipSilences(on: Boolean) = player.changeSkipSilence(on)

        override fun export(format: ExportFormat) {
            scope.launch {
                val file = if (format.pack) {
                    vm.meetingPack(item.id, format.wire, page.viewKey)
                } else {
                    val id = page.exportId ?: return@launch
                    vm.export(id, format.wire, page.viewKey)
                } ?: return@launch
                if (format.printed) printHtml(context, file) else shareFile(context, file)
            }
        }

        override fun delete() {
            sheet = RecordingSheet.Delete
        }
    }
    when (sheet) {
        RecordingSheet.Menu -> RecordingMenuSheet(
            item = item,
            hasAudio = hasAudio,
            skipSilence = player.skipSilence,
            canExport = page.exportId != null,
            canRetranscribe = item.transcript?.finalStatus != null && !page.noAudio,
            actions = actions,
            onDismiss = { if (sheet == RecordingSheet.Menu) sheet = null },
            backToSource = backToSource
        )

        RecordingSheet.Copy -> CopyMenuSheet(onCopy = ::copy, onDismiss = { if (sheet == RecordingSheet.Copy) sheet = null })

        RecordingSheet.Share -> RecordingShareSheet(
            vm,
            item,
            shareText = {
                val t = page.transcript
                if (t ==
                    null
                ) {
                    item.title.orEmpty()
                } else {
                    RecordingCopy.text(CopyKind.Text, item, t.segments, page.shown, settings, page.screens)
                }
            },
            transcriptId = page.exportId,
            hasAudio = hasAudio,
            view = page.viewKey,
            onDismiss = { sheet = null }
        )

        RecordingSheet.Rename -> RenameSheet(page.shownTitle.orEmpty(), onRename = { title ->
            sheet = null
            scope.launch {
                if (vm.organise(item.id, title = title)) {
                    page.shownTitle = title.ifEmpty { null }
                    say(res.getString(R.string.v2_rec_renamed))
                }
            }
        }, onDismiss = { sheet = null })

        RecordingSheet.Move -> MoveSheet(
            item,
            com.echoledger.mobile.core.Organise.folders(library, clients),
            onMove = { project ->
                sheet = null
                scope.launch {
                    if (vm.organise(item.id, clientId = project?.id, clearClient = project == null)) {
                        say(project?.let { res.getString(R.string.v2_rec_moved, it.name) } ?: res.getString(R.string.v2_rec_moved_out))
                    }
                }
            },
            onCreate = { name ->
                sheet = null
                scope.launch {
                    vm.createClient(name)?.let { created ->
                        if (vm.organise(item.id, clientId = created.id)) say(res.getString(R.string.v2_rec_moved, created.name))
                    }
                }
            },
            onDismiss = { sheet = null }
        )

        RecordingSheet.Labels -> LabelsSheet(
            item.labels,
            com.echoledger.mobile.core.Organise.labels(library),
            onLabels = { labels -> scope.launch { vm.organise(item.id, labels = labels) } },
            onDismiss = { sheet = null }
        )

        RecordingSheet.Retranscribe -> RetranscribeSheet(item, onRetranscribe = { language ->
            sheet = null
            // The view model says it ("Re-transcribing in Urdu…"), as for classic's page: one toast.
            scope.launch { vm.retranscribe(item.id, language) }
        }, onDismiss = { sheet = null })

        RecordingSheet.Delete -> DeleteRecordingDialog(onDelete = {
            sheet = null
            // The page goes at once; the delete, then its Undo, live on in the shell's toast host on the view model's
            // scope (the page's own scope is cancelled with it). Undo shows only once the server has it in Trash.
            val keep = vm.viewModelScope
            keep.launch {
                if (vm.deleteAndWait(item)) toasts.showUndo(text.deleted, undo) { keep.launch { vm.restore(item.id) } }
            }
            onBack()
        }, onDismiss = { sheet = null })

        null -> Unit
    }
    // Find in transcript: matches in the view on screen; the current one is tinted and scrolled to.
    var finding by remember(item.id) { mutableStateOf(false) }
    var findQuery by remember(item.id) { mutableStateOf("") }
    var findAt by remember(item.id) { mutableIntStateOf(0) }
    val t = page.transcript
    val segments = t?.segments.orEmpty()
    val shown = page.shown
    val findHits = remember(findQuery, shown, segments) {
        com.echoledger.mobile.ui.TranscriptFind.matches(segments.mapIndexed { i, s -> shown?.getOrNull(i) ?: s.text }, findQuery)
    }
    val found = if (finding) findHits.getOrNull(findAt.coerceIn(0, (findHits.size - 1).coerceAtLeast(0))) else null
    var lineActions by remember(item.id) { mutableStateOf<LineUi?>(null) }
    lineActions?.let { line ->
        LineActionsSheet(line, player.ready, onCopy = { words ->
            copyToClipboard(context, "EchoLedger line", words)
            say(res.getString(R.string.v2_rec_line_copied))
        }, onPlay = { page.playFrom(it) }, onDismiss = { lineActions = null })
    }
    // Views not in the plan: said, with the plans one tap away (classic's plan badge).
    val viewsLock = settings.plan.lockFor("views")
    var upgrading by remember { mutableStateOf(false) }
    if (upgrading && viewsLock != null) com.echoledger.mobile.ui.UpgradeSheet(vm, settings.plan, viewsLock) { upgrading = false }
    val finalReady = t?.kind == "final" && t.status == "completed"
    val tools = TranscriptTools(
        names = page.speakerNames,
        shown = shown,
        kept = page.views?.kept?.get(page.showAs.key).orEmpty().toSet(),
        available = if (finalReady && viewsLock == null) t?.views?.available.orEmpty() else emptyList(),
        viewsLocked = finalReady && viewsLock != null,
        showAs = page.showAs,
        views = page.views,
        onView = page::chooseView,
        onPlans = { upgrading = true },
        version = page.version to { v: TranscriptVersion -> page.version = v },
        final = t?.let { it.kind == "final" },
        activeIndex = page.activeIndex,
        canPlay = player.ready,
        onPlay = { player.seekTo(it) },
        onLineActions = { lineActions = it },
        onFind = if (page.canCopy) ({ finding = true }) else null,
        finding = finding && page.canCopy,
        findQuery = findQuery,
        findAt = findAt,
        findTotal = findHits.size,
        foundIndex = found,
        onFindQuery = {
            findQuery = it
            findAt = 0
        },
        onFindStep = { step -> if (findHits.isNotEmpty()) findAt = Math.floorMod(findAt + step, findHits.size) },
        onFindClose = {
            finding = false
            findQuery = ""
        },
        top = transcriptTop(vm, page, scope),
        instead = transcriptInstead(vm, page, settings, onCaptureTest, onOpenSettings) { sheet = RecordingSheet.Delete }
    )

    // The page keeps showing the recording as the Library has it now (its labels, pin and project change here).
    val live = library.firstOrNull { it.id == item.id } ?: item
    V2RecordingContent(
        item = live.copy(title = page.shownTitle),
        transcript = transcriptState(item, page.transcript, page.loaded),
        positionMs = player.positionMs,
        durationMs = player.totalMs.takeIf { it > 0 } ?: (item.durationMs ?: 0),
        playing = player.playing,
        speed = player.speed,
        onPlayPause = { player.toggle() },
        onSeek = { player.seekTo(it) },
        onSpeed = { player.changeSpeed(nextSpeed(player.speed)) },
        onShare = { sheet = RecordingSheet.Share },
        onDelete = { sheet = RecordingSheet.Delete },
        onRetry = { page.retry() },
        onBack = onBack,
        modifier = modifier,
        screens = page.screens,
        onOpenScreen = onOpenScreen,
        onCopy = if (page.canCopy) ({ sheet = RecordingSheet.Copy }) else null,
        onMore = { sheet = RecordingSheet.Menu },
        onRename = { sheet = RecordingSheet.Rename },
        tools = tools,
        summary = {
            SummaryPane(
                vm,
                live,
                ready = finalReady,
                notesOnPlan = settings.plan.aiNotes,
                onPlay = { page.playFrom(it) },
                onPlans = { upgrading = true }
            )
        },
        head = RecordingHead(
            device = item.sourceDeviceLabel ?: item.device?.name,
            bookmarks = page.bookmarks,
            onBookmark = { page.playFrom(it) },
            skipSilence = player.skipSilence,
            onSkipSilence = if (hasAudio) ({ player.changeSkipSilence(it) }) else null,
            showPlayer = hasAudio,
            showShare = !page.noAudio
        )
    )
}

/** Classic's speeds (PLAYER_SPEEDS): 0.75× to 2×. */
private val SPEEDS = com.echoledger.mobile.ui.PLAYER_SPEEDS

/** The next playback speed: 0.75×, 1×, 1.25×, 1.5×, 2×, then 0.75× again. */
fun nextSpeed(current: Float): Float = SPEEDS.firstOrNull { it > current + 0.01f } ?: SPEEDS.first()

fun speedLabel(speed: Float): String = java.math.BigDecimal(speed.toString()).stripTrailingZeros().toPlainString() + "×"

/** Above a readable transcript (classic's order): Improving…, the backup engine's offer, suggested fixes. */
private fun transcriptTop(vm: MainViewModel, page: RecordingPage, scope: kotlinx.coroutines.CoroutineScope): (@Composable () -> Unit)? {
    val t = page.transcript ?: return null
    val steps = page.steps
    val improving = steps != null && steps.onlyImproving
    val backup = t.backupEngine && t.kind == "final"
    if (!improving && !backup && t.suggestions.isEmpty()) return null
    return {
        Column(verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
            if (improving && steps != null) ImprovingLine(page.item, t, steps)
            if (backup) BackupBanner { vm.retranscribe(page.item.id, t.language) }
            if (t.suggestions.isNotEmpty()) {
                SuggestedFixesCard(t.suggestions, t.segments, onPlay = { page.playFrom(it) }) { id, accept ->
                    scope.launch { vm.decideSuggestion(t.id, id, accept)?.let { page.transcript = it } }
                }
            }
        }
    }
}

/**
 * The transcript's state when there are no lines to read (classic's order): no audio, the steps on its way, a failure
 * (Retry, or Check your key), still being transcribed, or empty; null leaves it to the lines or the loading states.
 */
private fun transcriptInstead(
    vm: MainViewModel,
    page: RecordingPage,
    settings: com.echoledger.mobile.data.AppSettings,
    onCaptureTest: () -> Unit,
    onOpenSettings: (String) -> Unit,
    onDelete: () -> Unit
): (@Composable () -> Unit)? {
    val t = page.transcript
    val steps = page.steps
    val item = page.item
    return when {
        page.noAudio -> ({ NoAudioCard(onCaptureTest, onDelete) })

        !page.loaded -> null

        t == null && page.loadFailed -> null

        t == null && steps != null && steps.working -> ({ StepsBlock(item, null, steps) { vm.notifyWhenReady(item.id) } })

        t == null -> null

        t.status == "failed" -> (
            {
                val checkKey = t.errorCode == "own_key_rejected" &&
                    !com.echoledger.mobile.ui.KeyRetry.newerKey(settings.ownKeySavedAt, item.updatedAt)
                FailedBanner(
                    t,
                    checkKey,
                    onRetry = { vm.retranscribe(item.id, t.language) },
                    onCheckKey = { onOpenSettings(com.echoledger.mobile.ui.TRANSCRIPTION_KEY_PAGE) }
                )
            }
            )

        t.status in RecordingPage.IN_PROGRESS && steps != null && steps.working -> (
            {
                StepsBlock(item, t, steps) { vm.notifyWhenReady(item.id) }
            }
            )

        t.status in RecordingPage.IN_PROGRESS -> (
            {
                val res = androidx.compose.ui.platform.LocalContext.current.resources
                Text(
                    waitingWords(res, t) ?: transcribingLine(res, t.progress ?: item.transcript?.finalProgress),
                    style = EchoV2.type.body,
                    color = EchoV2.colors.textSecondary
                )
            }
            )

        t.status !in RecordingPage.IN_PROGRESS && t.segments.isEmpty() -> (
            {
                Text(stringResource(R.string.v2_rec_empty), style = EchoV2.type.body, color = EchoV2.colors.textSecondary)
            }
            )

        else -> null
    }
}

/**
 * The transcript's toolbar and lines on the recording page: the view switch (or the views locked on this plan), Current /
 * Previous, Final or Live captions, the find bar's state, the line playing and the found one, speaker names, and what
 * a tap or a long press on a line does. The defaults draw plain lines with nothing to switch.
 */
data class TranscriptTools(
    val names: Map<String, String> = emptyMap(),
    val shown: List<String?>? = null,
    val kept: Set<Int> = emptySet(),
    val available: List<String> = emptyList(),
    val viewsLocked: Boolean = false,
    val showAs: ShowAs = ShowAs.ORIGINAL,
    val views: TranscriptViewsDto? = null,
    val onView: (ShowAs) -> Unit = {},
    val onPlans: () -> Unit = {},
    val version: Pair<TranscriptVersion, (TranscriptVersion) -> Unit>? = null,
    val final: Boolean? = null,
    val activeIndex: Int = -1,
    val canPlay: Boolean = false,
    val onPlay: (Long) -> Unit = {},
    val onLineActions: (LineUi) -> Unit = {},
    val onFind: (() -> Unit)? = null,
    val finding: Boolean = false,
    val findQuery: String = "",
    val findAt: Int = 0,
    val findTotal: Int = 0,
    val foundIndex: Int? = null,
    val onFindQuery: (String) -> Unit = {},
    val onFindStep: (Int) -> Unit = {},
    val onFindClose: () -> Unit = {},
    /** Above the lines: Improving…, the backup engine's offer, suggested fixes. */
    val top: (@Composable () -> Unit)? = null,
    /** In place of the transcript's own state: no audio, the steps on its way, a failure, an empty transcript. */
    val instead: (@Composable () -> Unit)? = null
)

/** The recording page without the view model or the player (tests). */
@Composable
fun V2RecordingContent(
    item: LibraryItem,
    transcript: TranscriptState,
    positionMs: Long,
    durationMs: Long,
    playing: Boolean,
    speed: Float,
    onPlayPause: () -> Unit,
    onSeek: (Long) -> Unit,
    onSpeed: () -> Unit,
    onShare: () -> Unit,
    onDelete: () -> Unit,
    onRetry: () -> Unit,
    onBack: () -> Unit,
    modifier: Modifier = Modifier,
    screens: List<ScreenDto> = emptyList(),
    onOpenScreen: (index: Int) -> Unit = {},
    /** Copy ▾ (null while there is nothing to copy). */
    onCopy: (() -> Unit)? = null,
    /** The ⋮ menu; without it Delete stays in the action row. */
    onMore: (() -> Unit)? = null,
    /** A tap on the title (Rename); null: the title is plain text. */
    onRename: (() -> Unit)? = null,
    /** The transcript's toolbar and lines (views, find, playing from a line, speaker names); defaults draw plain lines. */
    tools: TranscriptTools = TranscriptTools(),
    /** The head's extras: the device, bookmarks, skip silence. */
    head: RecordingHead = RecordingHead(),
    /** The Summary tab (call notes); null: says there is no summary here. */
    summary: (@Composable () -> Unit)? = null
) {
    val res = LocalContext.current.resources
    val c = EchoV2.colors
    val text = v2Text
    val locale = screenLocale()
    val row = remember(item, text, locale) { LibraryModel.row(item, ZonedDateTime.now(), text, locale) }
    var tab by remember { mutableIntStateOf(0) }
    Column(modifier.fillMaxSize().background(c.bgCanvas)) {
        val list = rememberLazyListState()
        EchoTopBar(title = "", onBack = onBack) {
            tools.onFind?.takeIf { !tools.finding }?.let { EchoIconButton(EchoIcons.Search, stringResource(R.string.v2_rec_find), it) }
        }
        if (tools.finding) {
            FindBar(tools.findQuery, tools.onFindQuery, tools.findAt, tools.findTotal, tools.onFindStep, tools.onFindClose)
        }
        // The find match in view: the lines come after the head, the actions, the screens, the tabs and the toolbar.
        val firstLine = 4 + (if (screens.isNotEmpty()) 1 else 0)
        LaunchedEffect(tools.foundIndex, tab) {
            val found = tools.foundIndex
            if (found != null && tab == 0) list.animateScrollToItem(firstLine + found)
        }
        LazyColumn(Modifier.weight(1f), state = list) {
            item {
                Column(
                    Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile),
                    verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)
                ) {
                    Text(
                        row.title,
                        style = EchoV2.type.content(row.title, EchoV2.type.titleL),
                        color = c.textPrimary,
                        maxLines = 2,
                        overflow = TextOverflow.Ellipsis,
                        modifier = onRename?.let { rename ->
                            Modifier.clickable(onClickLabel = stringResource(R.string.v2_rec_rename), onClick = rename)
                        } ?: Modifier
                    )
                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
                        Text(
                            listOfNotNull(row.source, head.device, row.time, row.duration, item.client?.name)
                                .filter { it.isNotBlank() }.joinToString(" · "),
                            style = EchoV2.type.meta,
                            color = c.textSecondary,
                            modifier = Modifier.weight(1f, fill = false)
                        )
                        when (row.status) {
                            RowStatus.Done -> EchoStatus(text.transcribed, EchoStatusKind.Success)
                            RowStatus.Untranscribed -> EchoStatus(text.untranscribed, EchoStatusKind.Neutral)
                            RowStatus.Transcribing -> EchoStatus(text.transcribing, EchoStatusKind.Accent)
                            RowStatus.Failed -> EchoStatus(text.failed, EchoStatusKind.Danger)
                        }
                    }
                    if (head.bookmarks.isNotEmpty()) {
                        Row(
                            Modifier.horizontalScroll(rememberScrollState()),
                            horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)
                        ) {
                            head.bookmarks.forEach { b ->
                                val at = formatClock(b.atMs)
                                com.echoledger.mobile.ui.v2.components.EchoChip(
                                    listOfNotNull(at, b.tag).joinToString(" "),
                                    selected = false,
                                    onClick = { head.onBookmark(b.atMs) },
                                    modifier = Modifier.semantics { contentDescription = res.getString(R.string.v2_rec_bookmark_at, at) }
                                )
                            }
                        }
                    }
                    if (head.showPlayer) {
                        EchoPlayer(
                            positionMs = positionMs,
                            durationMs = durationMs,
                            playing = playing,
                            speed = speedLabel(speed),
                            labels = EchoPlayerLabels(text.play, text.pause, text.back10, text.forward10, text.position, text.speed),
                            onPlayPause = onPlayPause,
                            onSeek = onSeek,
                            onSpeed = onSpeed,
                            modifier = Modifier.padding(top = EchoTokens.Space.s8),
                            markers = head.bookmarks.map { it.atMs }
                        )
                    }
                    head.onSkipSilence?.takeIf { head.showPlayer }?.let { onSkip ->
                        com.echoledger.mobile.ui.v2.components.EchoSwitch(
                            head.skipSilence,
                            onSkip,
                            label = stringResource(R.string.v2_rec_skip_silence)
                        )
                    }
                }
            }
            item {
                Row(Modifier.fillMaxWidth().padding(horizontal = EchoTokens.Space.s8, vertical = EchoTokens.Space.s8)) {
                    if (head.showShare) EchoActionItem(EchoIcons.Share, text.share, onClick = onShare, modifier = Modifier.weight(1f))
                    onCopy?.let {
                        EchoActionItem(
                            RecordingIcons.Copy,
                            stringResource(R.string.v2_rec_copy),
                            onClick = it,
                            modifier = Modifier.weight(1f)
                        )
                    }
                    if (onMore != null) {
                        EchoActionItem(
                            EchoIcons.More,
                            stringResource(R.string.v2_rec_more),
                            onClick = onMore,
                            modifier = Modifier.weight(1f)
                        )
                    } else {
                        EchoActionItem(EchoIcons.Delete, text.delete, onClick = onDelete, modifier = Modifier.weight(1f))
                    }
                }
            }
            if (screens.isNotEmpty()) {
                item { ScreensStrip(screens, onOpen = onOpenScreen, modifier = Modifier.padding(bottom = EchoTokens.Space.s8)) }
            }
            item {
                EchoTabs(
                    listOf(
                        text.transcript,
                        stringResource(R.string.v2_rec_tab_summary),
                        stringResource(R.string.v2_rec_tab_moments),
                        text.details
                    ),
                    tab,
                    onSelect = { tab = it }
                )
            }
            if (tab == 1) {
                item { summary?.invoke() ?: SummaryUnavailable() }
            } else if (tab == 2) {
                item { MomentsPane(head.bookmarks, screens, head.onBookmark, onOpenScreen) }
            } else if (tab == 3) {
                item { DetailRows(item, row, text, head) }
            } else if (tools.instead != null) {
                item {
                    Box(Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s12)) {
                        tools.instead.invoke()
                    }
                }
            } else {
                when (transcript) {
                    TranscriptState.Loading -> item { EchoSkeletonList(4, avatar = false) }

                    TranscriptState.None -> item {
                        EchoEmptyState(text.noTranscriptTitle, text.noTranscriptBody, icon = EchoIcons.Transcribe)
                    }

                    TranscriptState.Unreachable -> item {
                        EchoErrorState(text.failed, text.transcriptLoadingError, actionLabel = text.tryAgain, onAction = onRetry)
                    }

                    is TranscriptState.Failed -> item {
                        EchoErrorState(text.failed, failureWords(transcript.errorCode))
                    }

                    is TranscriptState.Lines -> {
                        item {
                            Column(
                                Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s8),
                                verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)
                            ) {
                                tools.version?.let { (version, onVersion) -> VersionSwitch(item, version, onVersion) }
                                when {
                                    tools.viewsLocked -> ViewsLocked(tools.onPlans)
                                    tools.available.isNotEmpty() -> ViewSwitch(tools.available, tools.showAs, tools.views, tools.onView)
                                }
                                tools.final?.let { TranscriptKind(it) }
                                tools.top?.invoke()
                            }
                        }
                        // Each screen sits after the last line that started at or before its moment.
                        val after = ScreenAssets.byLine(transcript.segments, screens)
                        val shownLines = lines(
                            transcript.segments,
                            tools.shown,
                            tools.names,
                            text,
                            tools.activeIndex,
                            positionMs,
                            tools.foundIndex,
                            tools.kept
                        )
                        itemsIndexed(shownLines, key = { i, _ -> "line-$i" }) { i, line ->
                            Column {
                                TranscriptRow(line, tools.canPlay, tools.onPlay, onActions = { tools.onLineActions(line) })
                                after[i].orEmpty().forEach { screen ->
                                    ScreenInline(screen, onOpen = { onOpenScreen(screens.indexOf(screen)) })
                                }
                            }
                        }
                    }
                }
            }
            item { Spacer(Modifier.height(EchoTokens.Space.s24)) }
        }
    }
}

@Composable
private fun SummaryUnavailable() = Text(
    stringResource(R.string.v2_rec_notes_need_project),
    style = EchoV2.type.body,
    color = EchoV2.colors.textSecondary,
    modifier = Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s12)
)

@Composable
private fun DetailRows(item: LibraryItem, row: V2Row, text: V2Text, head: RecordingHead) {
    Column {
        EchoSettingRow(text.detailSource, value = row.source)
        head.device?.let { EchoSettingRow(stringResource(R.string.v2_rec_detail_device), value = it) }
        if (row.duration.isNotEmpty()) EchoSettingRow(text.detailLength, value = row.duration)
        item.languageChip?.let { EchoSettingRow(text.detailLanguage, value = it) }
        row.time.takeIf { it.isNotBlank() }?.let { EchoSettingRow(stringResource(R.string.v2_rec_detail_created), value = it) }
        item.client?.name?.let { EchoSettingRow(text.detailProject, value = it) }
        if (item.labels.isNotEmpty()) EchoSettingRow(stringResource(R.string.v2_rec_detail_labels), value = item.labels.joinToString(", "))
        EchoSettingRow(
            stringResource(R.string.v2_rec_detail_kept),
            value = stringResource(if (item.keep) R.string.v2_rec_detail_kept_forever else R.string.v2_rec_detail_kept_rules)
        )
        if (item.pinned) EchoSettingRow(stringResource(R.string.v2_rec_detail_pinned), value = stringResource(R.string.v2_rec_detail_yes))
        if (head.bookmarks.isNotEmpty()) {
            EchoSettingRow(stringResource(R.string.v2_rec_detail_bookmarks), value = head.bookmarks.size.toString())
        }
    }
}

/** The recording's device, bookmarks and skip silence for the page's head (defaults: none). */
data class RecordingHead(
    val device: String? = null,
    val bookmarks: List<com.echoledger.mobile.data.SessionMarkerDto> = emptyList(),
    val onBookmark: (Long) -> Unit = {},
    val skipSilence: Boolean = false,
    /** Null while there is no audio to play. */
    val onSkipSilence: ((Boolean) -> Unit)? = null,
    /** The player shows only with audio to play (classic: a playback address, and not "no audio"). */
    val showPlayer: Boolean = true,
    /** Share shows unless nothing was heard (classic). */
    val showShare: Boolean = true
)
