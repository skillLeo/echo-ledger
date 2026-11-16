package com.echoledger.mobile.ui.v2.samples

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableLongStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.style.TextOverflow
import com.echoledger.mobile.ui.v2.components.EchoActionItem
import com.echoledger.mobile.ui.v2.components.EchoAvatar
import com.echoledger.mobile.ui.v2.components.EchoBanner
import com.echoledger.mobile.ui.v2.components.EchoBannerKind
import com.echoledger.mobile.ui.v2.components.EchoBottomBar
import com.echoledger.mobile.ui.v2.components.EchoButton
import com.echoledger.mobile.ui.v2.components.EchoCard
import com.echoledger.mobile.ui.v2.components.EchoDestination
import com.echoledger.mobile.ui.v2.components.EchoEmptyState
import com.echoledger.mobile.ui.v2.components.EchoErrorState
import com.echoledger.mobile.ui.v2.components.EchoFilter
import com.echoledger.mobile.ui.v2.components.EchoFilterBar
import com.echoledger.mobile.ui.v2.components.EchoIconButton
import com.echoledger.mobile.ui.v2.components.EchoIcons
import com.echoledger.mobile.ui.v2.components.EchoListRow
import com.echoledger.mobile.ui.v2.components.EchoPlayer
import com.echoledger.mobile.ui.v2.components.EchoSearchField
import com.echoledger.mobile.ui.v2.components.EchoSectionHeader
import com.echoledger.mobile.ui.v2.components.EchoSettingRow
import com.echoledger.mobile.ui.v2.components.EchoSkeletonBlock
import com.echoledger.mobile.ui.v2.components.EchoSkeletonCircle
import com.echoledger.mobile.ui.v2.components.EchoSkeletonList
import com.echoledger.mobile.ui.v2.components.EchoStatus
import com.echoledger.mobile.ui.v2.components.EchoStatusKind
import com.echoledger.mobile.ui.v2.components.EchoSwitch
import com.echoledger.mobile.ui.v2.components.EchoTabs
import com.echoledger.mobile.ui.v2.components.EchoTopBar
import com.echoledger.mobile.ui.v2.components.EchoTranscriptLine
import com.echoledger.mobile.ui.v2.components.formatClock
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.tokens.EchoTokens

/*
 * The Gate 3 sample screens (docs/v2/DESIGN_SYSTEM.md): Library, a recording and Settings, built only from the v2
 * components with real-looking content. They live in the debug source set and never ship.
 */

private const val FILTER_TODAY = "today"
private const val FILTER_UNTRANSCRIBED = "untranscribed"

/** Library › All recordings: search, quick filters, WhatsApp-style rows, the bottom bar. */
@Composable
fun SampleLibraryScreen(
    content: SampleContent,
    modifier: Modifier = Modifier,
    initialFilters: Set<String> = emptySet(),
    state: SampleState = SampleState.Content
) {
    val c = EchoV2.colors
    val copy = SampleStateCopy.of(content.language)
    var query by remember(content) { mutableStateOf("") }
    var filters by remember(content) { mutableStateOf(initialFilters) }
    val shown = content.items.filter { item ->
        (FILTER_TODAY !in filters || item.today) &&
            (FILTER_UNTRANSCRIBED !in filters || !item.transcribed) &&
            (query.isBlank() || item.title.contains(query, ignoreCase = true) || item.meta.contains(query, ignoreCase = true))
    }
    Column(modifier.fillMaxSize().background(c.bgCanvas)) {
        EchoTopBar(content.recordings, large = true) {
            Box(
                Modifier
                    .size(EchoTokens.Layout.controlTouchMin)
                    .clickable(onClickLabel = content.accountAndSettings) {}
                    .semantics { contentDescription = content.accountAndSettings },
                contentAlignment = Alignment.Center
            ) {
                EchoAvatar(initials = content.profileName.initials())
            }
        }
        EchoSearchField(
            query = query,
            onQueryChange = { query = it },
            placeholder = content.searchRecordings,
            modifier = Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile)
        )
        EchoFilterBar(
            filters = listOf(
                EchoFilter(FILTER_TODAY, content.filterToday),
                EchoFilter(FILTER_UNTRANSCRIBED, content.filterUntranscribed),
                EchoFilter("starred", content.filterStarred),
                EchoFilter("voice", content.filterVoiceNotes)
            ),
            selected = filters,
            onToggle = { key -> filters = if (key in filters) filters - key else filters + key },
            modifier = Modifier.padding(vertical = EchoTokens.Space.s4)
        )
        when (state) {
            SampleState.Loading -> EchoSkeletonList(7, Modifier.weight(1f))

            SampleState.Empty -> Box(Modifier.weight(1f)) {
                EchoEmptyState(copy.noRecordingsTitle, copy.noRecordingsBody, icon = EchoIcons.Library, actionLabel = copy.setUpRecording)
            }

            SampleState.Error -> Box(Modifier.weight(1f)) {
                EchoErrorState(copy.libraryErrorTitle, copy.libraryErrorBody, actionLabel = copy.tryAgain)
            }

            SampleState.Content -> LibraryList(content, shown)
        }
        EchoBottomBar(
            destinations = listOf(
                EchoDestination("home", content.home, EchoIcons.Home),
                EchoDestination("library", content.library, EchoIcons.Library),
                EchoDestination("live", content.live, EchoIcons.Live),
                EchoDestination("ask", content.ask, EchoIcons.Ask)
            ),
            selectedKey = "library",
            onSelect = {}
        )
    }
}

@Composable
private fun ColumnScope.LibraryList(content: SampleContent, shown: List<SampleRecording>) {
    val c = EchoV2.colors
    LazyColumn(Modifier.weight(1f)) {
        items(shown, key = { it.title }) { item ->
            val source = content.sourceNames.getValue(item.source)
            EchoListRow(
                title = item.title,
                meta = item.meta,
                titleEnd = item.time,
                metaEnd = { Text(formatClock(item.durationMs), style = EchoV2.type.mono, color = c.textSecondary) },
                status = item.status?.let { (label, kind) -> { EchoStatus(label, kind) } },
                leading = { EchoAvatar(icon = item.source.icon, contentDescription = source) },
                tall = true,
                onClick = {}
            )
        }
    }
}

/** A recording: its title and source, the player, the actions, then the transcript with speakers. */
@Composable
fun SampleRecordingScreen(content: SampleContent, modifier: Modifier = Modifier, state: SampleState = SampleState.Content) {
    val c = EchoV2.colors
    val copy = SampleStateCopy.of(content.language)
    var position by remember { mutableLongStateOf(221_000L) }
    var playing by remember { mutableStateOf(false) }
    var tab by remember { mutableIntStateOf(0) }
    val duration = content.items.first().durationMs
    Column(modifier.fillMaxSize().background(c.bgCanvas)) {
        EchoTopBar(title = "", onBack = {}) {
            EchoIconButton(EchoIcons.More, EchoV2.strings.more, onClick = {})
        }
        if (state == SampleState.Loading) {
            RecordingSkeleton()
            return@Column
        }
        LazyColumn(Modifier.weight(1f)) {
            item {
                Column(
                    Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile),
                    verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)
                ) {
                    Text(
                        content.recordingTitle,
                        style = EchoV2.type.titleL,
                        color = c.textPrimary,
                        maxLines = 2,
                        overflow = TextOverflow.Ellipsis
                    )
                    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
                        Text(
                            content.recordingMeta,
                            style = EchoV2.type.meta,
                            color = c.textSecondary,
                            modifier = Modifier.weight(1f, fill = false)
                        )
                        when (state) {
                            SampleState.Empty -> EchoStatus(copy.untranscribed, EchoStatusKind.Neutral)
                            SampleState.Error -> EchoStatus(copy.failed, EchoStatusKind.Danger)
                            else -> EchoStatus(content.transcribed, EchoStatusKind.Success)
                        }
                    }
                    EchoPlayer(
                        positionMs = position,
                        durationMs = duration,
                        playing = playing,
                        speed = "1×",
                        labels = content.player,
                        onPlayPause = { playing = !playing },
                        onSeek = { position = it },
                        onSpeed = {},
                        modifier = Modifier.padding(top = EchoTokens.Space.s8)
                    )
                }
            }
            item {
                Row(
                    Modifier.fillMaxWidth().padding(horizontal = EchoTokens.Space.s8, vertical = EchoTokens.Space.s8),
                    horizontalArrangement = Arrangement.SpaceEvenly
                ) {
                    EchoActionItem(EchoIcons.Share, content.share, onClick = {}, modifier = Modifier.weight(1f))
                    EchoActionItem(EchoIcons.Ask, content.ask, onClick = {}, modifier = Modifier.weight(1f))
                    EchoActionItem(EchoIcons.Move, content.move, onClick = {}, modifier = Modifier.weight(1f))
                    EchoActionItem(EchoIcons.Delete, content.delete, onClick = {}, modifier = Modifier.weight(1f))
                }
            }
            item { EchoTabs(content.tabs, tab, onSelect = { tab = it }) }
            when (state) {
                SampleState.Empty -> item {
                    EchoEmptyState(
                        copy.noTranscriptTitle,
                        copy.noTranscriptBody,
                        icon = EchoIcons.Transcribe,
                        actionLabel = copy.transcribe
                    )
                }

                SampleState.Error -> item {
                    EchoErrorState(copy.transcriptFailedTitle, copy.transcriptFailedBody, actionLabel = copy.tryAgain)
                }

                else -> items(content.transcript) { line ->
                    EchoTranscriptLine(line.speaker, line.time, line.text, isMe = line.isMe)
                }
            }
            item { Spacer(Modifier.height(EchoTokens.Space.s24)) }
        }
    }
}

/** The recording page while it loads: the title, meta, player and transcript as static blocks. */
@Composable
private fun ColumnScope.RecordingSkeleton() {
    val loading = EchoV2.strings.loading
    Column(
        Modifier
            .weight(1f)
            .padding(horizontal = EchoTokens.Layout.pagePaddingMobile)
            .clearAndSetSemantics { contentDescription = loading },
        verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s12)
    ) {
        EchoSkeletonBlock(fraction = 0.55f, height = EchoTokens.Space.s32)
        EchoSkeletonBlock(fraction = 0.7f, height = EchoTokens.Space.s16)
        EchoSkeletonBlock(Modifier.padding(top = EchoTokens.Space.s16), height = EchoTokens.Border.strong + EchoTokens.Border.strong)
        Row(Modifier.fillMaxWidth().padding(vertical = EchoTokens.Space.s16), horizontalArrangement = Arrangement.Center) {
            EchoSkeletonCircle(size = EchoTokens.Space.s64)
        }
        EchoSkeletonBlock(Modifier.padding(top = EchoTokens.Space.s32), fraction = 0.3f, height = EchoTokens.Space.s16)
        repeat(4) { i ->
            EchoSkeletonBlock(Modifier.padding(top = EchoTokens.Space.s16), fraction = 0.25f)
            EchoSkeletonBlock(fraction = listOf(0.95f, 0.8f, 0.9f, 0.7f)[i], height = EchoTokens.Space.s16)
            EchoSkeletonBlock(fraction = listOf(0.6f, 0.45f, 0.7f, 0.5f)[i], height = EchoTokens.Space.s16)
        }
    }
}

/** Settings: search, the profile card with the plan and Upgrade, then plain grouped rows with values at the end. */
@Composable
fun SampleSettingsScreen(content: SampleContent, modifier: Modifier = Modifier, state: SampleState = SampleState.Content) {
    val c = EchoV2.colors
    val copy = SampleStateCopy.of(content.language)
    var query by remember(content, state) { mutableStateOf(if (state == SampleState.Empty) copy.settingsQuery else "") }
    val matching = content.groups.map { g -> g to g.rows.filter { query.isBlank() || it.title.contains(query, ignoreCase = true) } }
        .filter { it.second.isNotEmpty() }
    val switches =
        remember(content) {
            mutableStateOf(content.groups.flatMap { g -> g.rows.mapNotNull { r -> r.switch?.let { r.title to it } } }.toMap())
        }
    Column(modifier.fillMaxSize().background(c.bgCanvas)) {
        EchoTopBar(content.settings, onBack = {})
        LazyColumn(Modifier.weight(1f)) {
            item {
                EchoSearchField(
                    query = query,
                    onQueryChange = { query = it },
                    placeholder = content.searchSettings,
                    modifier = Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile)
                )
            }
            if (state == SampleState.Loading) item { ProfileSkeleton() }
            if (state == SampleState.Error) {
                item {
                    EchoBanner(
                        copy.accountError,
                        EchoBannerKind.Error,
                        Modifier.padding(
                            start = EchoTokens.Layout.pagePaddingMobile,
                            end = EchoTokens.Layout.pagePaddingMobile,
                            top = EchoTokens.Space.s16
                        ),
                        actionLabel = copy.tryAgain
                    )
                }
            }
            if (matching.isEmpty()) {
                item { EchoEmptyState(copy.noSettingsTitle, copy.noSettingsBody, icon = EchoIcons.Search) }
            }
            if (state == SampleState.Content) {
                item {
                    EchoCard(
                        Modifier.padding(
                            start = EchoTokens.Layout.pagePaddingMobile,
                            end = EchoTokens.Layout.pagePaddingMobile,
                            top = EchoTokens.Space.s16
                        )
                    ) {
                        Row(
                            verticalAlignment = Alignment.CenterVertically,
                            horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s12)
                        ) {
                            EchoAvatar(initials = content.profileName.initials())
                            Column(Modifier.weight(1f)) {
                                Text(
                                    content.profileName,
                                    style = EchoV2.type.bodyStrong,
                                    color = c.textPrimary,
                                    maxLines = 1,
                                    overflow = TextOverflow.Ellipsis
                                )
                                Text(
                                    content.profileEmail,
                                    style = EchoV2.type.metaLatin,
                                    color = c.textSecondary,
                                    maxLines = 1,
                                    overflow = TextOverflow.Ellipsis
                                )
                            }
                        }
                        Row(
                            verticalAlignment = Alignment.CenterVertically,
                            horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s12)
                        ) {
                            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s4)) {
                                EchoStatus(content.plan, EchoStatusKind.Accent)
                                Text(content.usage, style = EchoV2.type.meta, color = c.textSecondary)
                            }
                            EchoButton(content.upgrade, onClick = {})
                        }
                    }
                }
            }
            matching.forEach { (group, rows) ->
                item(key = group.title) { EchoSectionHeader(group.title) }
                rows.forEach { row ->
                    item(key = group.title + row.title) {
                        when {
                            row.switch != null -> EchoSwitch(
                                checked = switches.value[row.title] == true,
                                onCheckedChange = { on -> switches.value = switches.value + (row.title to on) },
                                label = row.title,
                                supporting = row.subtitle
                            )

                            row.value == null && row.subtitle == null -> EchoSettingRow(
                                row.title,
                                trailing = { EchoStatus(content.ready, EchoStatusKind.Success) },
                                onClick = {}
                            )

                            else -> EchoSettingRow(row.title, value = row.value, subtitle = row.subtitle, onClick = {})
                        }
                    }
                }
            }
            item { Spacer(Modifier.height(EchoTokens.Space.s32)) }
        }
    }
}

/** The profile card while the account loads. */
@Composable
private fun ProfileSkeleton() {
    val loading = EchoV2.strings.loading
    EchoCard(
        Modifier
            .padding(start = EchoTokens.Layout.pagePaddingMobile, end = EchoTokens.Layout.pagePaddingMobile, top = EchoTokens.Space.s16)
            .clearAndSetSemantics { contentDescription = loading }
    ) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s12)) {
            EchoSkeletonCircle()
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
                EchoSkeletonBlock(fraction = 0.5f, height = EchoTokens.Space.s16)
                EchoSkeletonBlock(fraction = 0.7f)
            }
        }
        EchoSkeletonBlock(fraction = 0.3f, height = EchoTokens.Space.s20)
        EchoSkeletonBlock(fraction = 0.8f)
    }
}

/** Initials of a name: two for Latin names ("Ayesha Siddiqui" → "AS"), the first letter for Urdu and Arabic names. */
internal fun String.initials(): String {
    val words = split(' ').filter { it.isNotBlank() }
    val latin = words.firstOrNull()?.firstOrNull()?.let { it in 'A'..'Z' || it in 'a'..'z' } == true
    return (if (latin) words.take(2) else words.take(1)).joinToString("") { it.take(1) }.uppercase()
}
