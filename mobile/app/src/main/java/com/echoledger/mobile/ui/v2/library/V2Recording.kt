package com.echoledger.mobile.ui.v2.library

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
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
import androidx.compose.ui.text.style.TextOverflow
import com.echoledger.mobile.R
import com.echoledger.mobile.core.Speakers
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.ScreenDto
import com.echoledger.mobile.data.SegmentDto
import com.echoledger.mobile.data.TranscriptDto
import com.echoledger.mobile.ui.MainViewModel
import com.echoledger.mobile.ui.RecordingPlayerState
import com.echoledger.mobile.ui.printHtml
import com.echoledger.mobile.ui.rememberRecordingPlayer
import com.echoledger.mobile.ui.shareFile
import com.echoledger.mobile.ui.v2.components.EchoActionItem
import com.echoledger.mobile.ui.v2.components.EchoEmptyState
import com.echoledger.mobile.ui.v2.components.EchoErrorState
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
    data class Failed(val sentence: String?) : TranscriptState
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
        loaded.status == "failed" -> TranscriptState.Failed(loaded.error)
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
    backToSource: Pair<String, () -> Unit>? = null
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
        V2RecordingPage(vm, page, text, { viewing = it }, onBack, modifier, backToSource)
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
    backToSource: Pair<String, () -> Unit>?
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
            scope.launch { if (vm.retranscribe(item.id, language)) say(res.getString(R.string.v2_rec_retranscribing)) }
        }, onDismiss = { sheet = null })

        RecordingSheet.Delete -> DeleteRecordingDialog(onDelete = {
            sheet = null
            vm.delete(item)
            scope.launch { toasts.showUndo(text.deleted, undo) { scope.launch { vm.restore(item.id) } } }
            onBack()
        }, onDismiss = { sheet = null })

        null -> Unit
    }
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
        onRename = { sheet = RecordingSheet.Rename }
    )
}

private val SPEEDS = listOf(1f, 1.25f, 1.5f, 2f)

/** The next playback speed: 1×, 1.25×, 1.5×, 2×, then 1× again. */
fun nextSpeed(current: Float): Float = SPEEDS.firstOrNull { it > current + 0.01f } ?: SPEEDS.first()

fun speedLabel(speed: Float): String = java.math.BigDecimal(speed.toString()).stripTrailingZeros().toPlainString() + "×"

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
    onRename: (() -> Unit)? = null
) {
    val c = EchoV2.colors
    val text = v2Text
    val locale = screenLocale()
    val row = remember(item, text, locale) { LibraryModel.row(item, ZonedDateTime.now(), text, locale) }
    var tab by remember { mutableIntStateOf(0) }
    Column(modifier.fillMaxSize().background(c.bgCanvas)) {
        EchoTopBar(title = "", onBack = onBack)
        LazyColumn(Modifier.weight(1f)) {
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
                            listOf(row.source, row.time).filter { it.isNotBlank() }.joinToString(" · "),
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
                    EchoPlayer(
                        positionMs = positionMs,
                        durationMs = durationMs,
                        playing = playing,
                        speed = speedLabel(speed),
                        labels = EchoPlayerLabels(text.play, text.pause, text.back10, text.forward10, text.position, text.speed),
                        onPlayPause = onPlayPause,
                        onSeek = onSeek,
                        onSpeed = onSpeed,
                        modifier = Modifier.padding(top = EchoTokens.Space.s8)
                    )
                }
            }
            item {
                Row(Modifier.fillMaxWidth().padding(horizontal = EchoTokens.Space.s8, vertical = EchoTokens.Space.s8)) {
                    EchoActionItem(EchoIcons.Share, text.share, onClick = onShare, modifier = Modifier.weight(1f))
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
            item { EchoTabs(listOf(text.transcript, text.details), tab, onSelect = { tab = it }) }
            if (tab == 1) {
                item { DetailRows(item, row, text) }
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
                        EchoErrorState(text.failed, transcript.sentence ?: text.transcriptLoadingError)
                    }

                    is TranscriptState.Lines -> {
                        // Each screen sits after the last line that started at or before its moment.
                        val after = ScreenAssets.byLine(transcript.segments, screens)
                        itemsIndexed(transcript.segments) { i, s ->
                            Column {
                                EchoTranscriptLine(
                                    speakerName(s.speaker, text),
                                    formatClock(s.startMs),
                                    s.text,
                                    isMe =
                                        s.speaker == Speakers.ME
                                )
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
private fun DetailRows(item: LibraryItem, row: V2Row, text: V2Text) {
    Column {
        EchoSettingRow(text.detailSource, value = row.source)
        if (row.duration.isNotEmpty()) EchoSettingRow(text.detailLength, value = row.duration)
        item.client?.name?.let { EchoSettingRow(text.detailProject, value = it) }
        item.languageChip?.let { EchoSettingRow(text.detailLanguage, value = it) }
    }
}
