package com.echoledger.mobile.ui.v2.library

import android.content.Intent
import androidx.compose.foundation.background
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
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.style.TextOverflow
import com.echoledger.mobile.core.Speakers
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.ScreenDto
import com.echoledger.mobile.data.SegmentDto
import com.echoledger.mobile.data.TranscriptDto
import com.echoledger.mobile.ui.MainViewModel
import com.echoledger.mobile.ui.RecordingPlayerState
import com.echoledger.mobile.ui.rememberRecordingPlayer
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
 * A recording on the v2 components (behind `ui_v2.library`): the title, source and time, the player, Share and Delete
 * (with Undo), then the transcript with speakers, or its designed empty, loading and error states.
 */
@Composable
fun V2RecordingScreen(vm: MainViewModel, item: LibraryItem, onBack: () -> Unit, modifier: Modifier = Modifier) {
    val text = v2Text
    var transcript by remember(item.id) { mutableStateOf<TranscriptDto?>(null) }
    var tried by remember(item.id) { mutableStateOf(false) }
    var attempt by remember(item.id) { mutableIntStateOf(0) }
    var url by remember(item.id) { mutableStateOf<String?>(null) }
    LaunchedEffect(item.id, item.transcript?.finalTranscriptId, attempt) {
        item.transcript?.finalTranscriptId?.let { transcript = vm.transcript(it) }
        tried = true
    }
    LaunchedEffect(item.id) { url = vm.playbackUrl(item.id) }
    // The recording's screens (meeting assets): read when the Library says it has any, or doesn't say.
    var screens by remember(item.id) { mutableStateOf<List<ScreenDto>>(emptyList()) }
    LaunchedEffect(item.id) { if (item.screenCount != 0) screens = vm.screens(item.id).sortedBy { it.atMs } }
    var viewing by remember(item.id) { mutableStateOf<Int?>(null) }
    val player = rememberRecordingPlayer(url)
    val segments = transcript?.segments.orEmpty()
    CompositionLocalProvider(LocalScreenPictures provides { link -> vm.screenImage(link) }) {
        viewing?.let { start ->
            V2ScreenViewer(
                moments = remember(screens, segments, text) { ScreenAssets.moments(screens, segments) { speakerName(it, text) } },
                startIndex = start,
                onPlayFrom = { at ->
                    player.seekTo(at)
                    if (!player.playing) player.toggle()
                    viewing = null
                },
                onClose = { viewing = null },
                modifier = modifier,
                transcriptReady = transcript != null
            )
            return@CompositionLocalProvider
        }
        V2RecordingPage(vm, item, text, transcript, tried, player, screens, { viewing = it }, { attempt++ }, onBack, modifier)
    }
}

@Composable
private fun V2RecordingPage(
    vm: MainViewModel,
    item: LibraryItem,
    text: V2Text,
    transcript: TranscriptDto?,
    tried: Boolean,
    player: RecordingPlayerState,
    screens: List<ScreenDto>,
    onOpenScreen: (Int) -> Unit,
    onRetry: () -> Unit,
    onBack: () -> Unit,
    modifier: Modifier
) {
    val context = LocalContext.current
    val toasts = LocalEchoToasts.current
    val scope = rememberCoroutineScope()
    val undo = EchoV2.strings.undo
    V2RecordingContent(
        item = item,
        transcript = transcriptState(item, transcript, tried),
        positionMs = player.positionMs,
        durationMs = player.totalMs.takeIf { it > 0 } ?: (item.durationMs ?: 0),
        playing = player.playing,
        speed = player.speed,
        onPlayPause = { player.toggle() },
        onSeek = { player.seekTo(it) },
        onSpeed = { player.changeSpeed(nextSpeed(player.speed)) },
        onShare = {
            val body = (transcript?.segments.orEmpty()).joinToString("\n") { "${speakerName(it.speaker, text)}: ${it.text}" }
            val share = Intent(Intent.ACTION_SEND).setType("text/plain")
                .putExtra(Intent.EXTRA_SUBJECT, item.title.orEmpty())
                .putExtra(Intent.EXTRA_TEXT, body.ifBlank { item.title.orEmpty() })
            context.startActivity(Intent.createChooser(share, text.share))
        },
        onDelete = {
            // TODO(phone core): MainViewModel.delete is fire-and-forget, so this toast can show before the server has moved
            // the recording to Trash. Show it only after success once the view model has a suspending delete.
            vm.delete(item)
            scope.launch { toasts.showUndo(text.deleted, undo) { scope.launch { vm.restore(item.id) } } }
            onBack()
        },
        onRetry = onRetry,
        onBack = onBack,
        modifier = modifier,
        screens = screens,
        onOpenScreen = onOpenScreen
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
    onOpenScreen: (index: Int) -> Unit = {}
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
                        overflow = TextOverflow.Ellipsis
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
                    EchoActionItem(EchoIcons.Delete, text.delete, onClick = onDelete, modifier = Modifier.weight(1f))
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
