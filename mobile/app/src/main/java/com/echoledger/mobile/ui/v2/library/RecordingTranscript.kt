package com.echoledger.mobile.ui.v2.library

import android.content.Intent
import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.background
import androidx.compose.foundation.combinedClickable
import androidx.compose.foundation.gestures.detectTapGestures
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.drawBehind
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.PathEffect
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.graphics.drawscope.DrawScope
import androidx.compose.ui.hapticfeedback.HapticFeedbackType
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.platform.LocalHapticFeedback
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.semantics.stateDescription
import androidx.compose.ui.text.SpanStyle
import androidx.compose.ui.text.TextLayoutResult
import androidx.compose.ui.text.buildAnnotatedString
import androidx.compose.ui.text.style.TextDecoration
import com.echoledger.mobile.R
import com.echoledger.mobile.core.Languages
import com.echoledger.mobile.core.Speakers
import com.echoledger.mobile.core.Uncertain
import com.echoledger.mobile.data.LibraryItem
import com.echoledger.mobile.data.SegmentDto
import com.echoledger.mobile.data.TranscriptViewsDto
import com.echoledger.mobile.ui.ShowAs
import com.echoledger.mobile.ui.TranscriptVersion
import com.echoledger.mobile.ui.continuesTurn
import com.echoledger.mobile.ui.previousVersionId
import com.echoledger.mobile.ui.v2.components.EchoBottomSheet
import com.echoledger.mobile.ui.v2.components.EchoButton
import com.echoledger.mobile.ui.v2.components.EchoButtonKind
import com.echoledger.mobile.ui.v2.components.EchoChip
import com.echoledger.mobile.ui.v2.components.EchoIconButton
import com.echoledger.mobile.ui.v2.components.EchoIcons
import com.echoledger.mobile.ui.v2.components.EchoSearchField
import com.echoledger.mobile.ui.v2.components.EchoSegmented
import com.echoledger.mobile.ui.v2.components.formatClock
import com.echoledger.mobile.ui.v2.shell.V2Text
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.tokens.EchoTokens
import kotlin.math.max
import kotlin.math.min

/** A speaker as the page names them: a name the server was given first, then "You", "Other side" or "Speaker". */
fun speakerLabel(speaker: String, names: Map<String, String>, text: V2Text): String =
    names[speaker]?.trim()?.takeIf { it.isNotEmpty() && it != "Me" } ?: speakerName(speaker, text)

/** A view's name in the screen's language. */
@Composable
fun viewWords(view: ShowAs): String = stringResource(
    when (view) {
        ShowAs.ORIGINAL -> R.string.v2_rec_view_original
        ShowAs.ROMAN -> R.string.v2_rec_view_roman
        ShowAs.URDU -> R.string.v2_rec_view_urdu
        ShowAs.ENGLISH -> R.string.v2_rec_view_english
    }
)

/**
 * View: Original · Roman Urdu · اردو · English (only the views made for this language, as classic), switching at once;
 * the line under it says the view is ready (the transcript unchanged), being written, or failed.
 */
@Composable
fun ViewSwitch(available: List<String>, selected: ShowAs, views: TranscriptViewsDto?, onPick: (ShowAs) -> Unit) {
    val options = listOf(ShowAs.ORIGINAL) + ShowAs.entries.filter { it != ShowAs.ORIGINAL && it.key in available }
    Column(verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s4)) {
        EchoSegmented(options.map { viewWords(it) }, options.indexOf(selected).coerceAtLeast(0), { onPick(options[it]) })
        if (selected != ShowAs.ORIGINAL) {
            val name = viewWords(selected)
            Text(
                when (views?.status) {
                    "ready" -> stringResource(R.string.v2_rec_view_ready, name)
                    "failed" -> stringResource(R.string.v2_rec_view_failed)
                    else -> stringResource(R.string.v2_rec_view_making, name)
                },
                style = EchoV2.type.meta,
                color = EchoV2.colors.textSecondary,
                modifier = Modifier.semantics { liveRegion = LiveRegionMode.Polite }
            )
        }
    }
}

/** The views locked on this plan: one line and See plans (classic's plan badge opened the plans the same way). */
@Composable
fun ViewsLocked(onPlans: () -> Unit) {
    Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
        Text(
            stringResource(R.string.v2_rec_views_locked),
            style = EchoV2.type.meta,
            color = EchoV2.colors.textSecondary,
            modifier = Modifier.weight(1f)
        )
        EchoButton(stringResource(R.string.v2_rec_see_plans), onPlans, kind = EchoButtonKind.Quiet)
    }
}

/** Current / Previous after a re-transcribe (when the recording has two versions). */
@OptIn(ExperimentalLayoutApi::class)
@Composable
fun VersionSwitch(item: LibraryItem, version: TranscriptVersion, onVersion: (TranscriptVersion) -> Unit) {
    if (previousVersionId(item) == null) return
    FlowRow(horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
        EchoChip(stringResource(R.string.v2_rec_version_current), selected = version == TranscriptVersion.CURRENT, onClick = {
            onVersion(TranscriptVersion.CURRENT)
        })
        val previous = item.transcript?.alternateLanguage?.let { stringResource(R.string.v2_rec_version_previous_in, Languages.label(it)) }
            ?: stringResource(R.string.v2_rec_version_previous)
        EchoChip(previous, selected = version == TranscriptVersion.PREVIOUS, onClick = { onVersion(TranscriptVersion.PREVIOUS) })
    }
}

/** Find in transcript: the words, "2 of 7" (or No matches), previous and next, close. */
@Composable
fun FindBar(query: String, onQuery: (String) -> Unit, current: Int, total: Int, onStep: (Int) -> Unit, onClose: () -> Unit) {
    Row(
        Modifier.fillMaxWidth().padding(end = EchoTokens.Space.s4),
        verticalAlignment = Alignment.CenterVertically
    ) {
        EchoSearchField(query, onQuery, stringResource(R.string.v2_rec_find), Modifier.weight(1f).padding(start = EchoTokens.Space.s8))
        if (query.isNotBlank()) {
            Text(
                if (total ==
                    0
                ) {
                    stringResource(R.string.v2_rec_find_none)
                } else {
                    stringResource(R.string.v2_rec_find_count, current + 1, total)
                },
                style = EchoV2.type.meta,
                color = EchoV2.colors.textSecondary,
                modifier = Modifier.padding(horizontal = EchoTokens.Space.s4).semantics { liveRegion = LiveRegionMode.Polite }
            )
        }
        EchoIconButton(EchoIcons.Previous, stringResource(R.string.v2_rec_find_prev), { onStep(-1) })
        EchoIconButton(EchoIcons.Next, stringResource(R.string.v2_rec_find_next), { onStep(1) })
        EchoIconButton(EchoIcons.Close, stringResource(R.string.v2_rec_find_close), onClose)
    }
}

/** What a line shows: its speaker (once per turn), time, words in the chosen view, and its marks. */
data class LineUi(
    val index: Int,
    val segment: SegmentDto,
    val speaker: String,
    val newTurn: Boolean,
    val words: String?,
    val keptOriginal: Boolean,
    val active: Boolean,
    val found: Boolean,
    val spoken: Int
)

/** The lines of the transcript as the page shows them (classic's speaker turns, view text, active and found lines). */
fun lines(
    segments: List<SegmentDto>,
    shown: List<String?>?,
    names: Map<String, String>,
    text: V2Text,
    activeIndex: Int,
    positionMs: Long,
    foundIndex: Int?,
    kept: Set<Int>
): List<LineUi> {
    val speakers = segments.map { it.speaker }
    return segments.mapIndexed { i, s ->
        val active = i == activeIndex
        LineUi(
            index = i,
            segment = s,
            speaker = speakerLabel(s.speaker, names, text),
            newTurn = !continuesTurn(speakers, i),
            words = shown?.getOrNull(i),
            keptOriginal = shown != null && i in kept,
            active = active,
            found = i == foundIndex,
            spoken = if (active && s.words.isNotEmpty()) s.words.indexOfLast { it.startMs <= positionMs } else -1
        )
    }
}

/**
 * One transcript line: the speaker and time at a new turn, then the words. A tap plays from the line (a tap on an unsure
 * word, from that word); a long press offers Copy line, Share line and Play from here. The line being played is tinted
 * and the word being spoken marked; a find match is tinted in the warning colour. Unsure words are underlined with dots.
 */
@OptIn(ExperimentalFoundationApi::class)
@Composable
fun TranscriptRow(line: LineUi, canPlay: Boolean, onPlay: (Long) -> Unit, onActions: () -> Unit) {
    val c = EchoV2.colors
    val haptics = LocalHapticFeedback.current
    val s = line.segment
    Column(
        Modifier
            .fillMaxWidth()
            .padding(horizontal = EchoTokens.Space.s8)
            .clip(RoundedCornerShape(EchoTokens.Component.buttonRadius))
            .background(
                when {
                    line.found -> c.warningSoft
                    line.active -> c.accentSoft
                    else -> c.clear
                }
            )
            .combinedClickable(
                onClickLabel = stringResource(R.string.v2_rec_play_here),
                onClick = { if (canPlay) onPlay(s.startMs) },
                onLongClickLabel = stringResource(R.string.v2_rec_line_actions),
                onLongClick = {
                    haptics.performHapticFeedback(HapticFeedbackType.LongPress)
                    onActions()
                }
            )
            .padding(horizontal = EchoTokens.Space.s8, vertical = EchoTokens.Space.s8),
        verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s4)
    ) {
        if (line.newTurn) {
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
                Text(
                    line.speaker,
                    style = EchoV2.type.label,
                    color = if (s.speaker == Speakers.ME) c.textSecondary else c.textPrimary,
                    maxLines = 1
                )
                Text(formatClock(s.startMs), style = EchoV2.type.mono, color = c.textSecondary)
            }
        }
        LineWords(line, canPlay, onPlay)
        s.check?.let { check ->
            Text(stringResource(R.string.v2_rec_second_engine, check.text), style = EchoV2.type.meta, color = c.textSecondary)
        }
    }
}

@Composable
private fun LineWords(line: LineUi, canPlay: Boolean, onPlay: (Long) -> Unit) {
    val c = EchoV2.colors
    val s = line.segment
    // A view's line: its "[?word]" marks shown underlined, without brackets (classic's withUnsureMarks).
    line.words?.let { words ->
        Text(viewLine(words), style = EchoV2.type.content(words, EchoV2.type.body), color = c.textPrimary)
        if (line.keptOriginal) Text(stringResource(R.string.v2_rec_kept_original), style = EchoV2.type.meta, color = c.textSecondary)
        return
    }
    val unsure = remember(s) { s.words.indices.filter { Uncertain.word(s.words[it]) } }
    val useWords = s.words.isNotEmpty() && (line.spoken >= 0 || unsure.isNotEmpty())
    val (words, ranges) = remember(s, useWords) { if (useWords) Uncertain.layout(s.words) else s.text to emptyList() }
    val marked = if (s.uncertain && words.isNotEmpty()) listOf(words.indices) else unsure.map { ranges[it] }
    val described = when {
        s.uncertain -> stringResource(R.string.v2_rec_unsure_line)
        unsure.size == 1 -> stringResource(R.string.v2_rec_unsure_one)
        unsure.isNotEmpty() -> stringResource(R.string.v2_rec_unsure_many, unsure.size)
        else -> null
    }
    var layout by remember { mutableStateOf<TextLayoutResult?>(null) }
    val dot = EchoTokens.Border.strong
    val gap = EchoTokens.Space.s4
    Text(
        buildAnnotatedString {
            append(words)
            if (line.spoken in ranges.indices) {
                addStyle(SpanStyle(background = c.warningSoft), ranges[line.spoken].first, ranges[line.spoken].last + 1)
            }
        },
        style = EchoV2.type.content(words, EchoV2.type.body),
        color = c.textPrimary,
        onTextLayout = { layout = it },
        modifier = Modifier
            .then(if (described != null) Modifier.semantics { stateDescription = described } else Modifier)
            .drawBehind { layout?.let { l -> marked.forEach { dottedUnderline(l, it, c.warning, dot.toPx(), gap.toPx()) } } }
            .then(
                if (canPlay && unsure.isNotEmpty()) {
                    // A tap on the words plays from the word under it (classic's Uncertain.playFrom).
                    Modifier.pointerInput(s) {
                        detectTapGestures { position ->
                            val offset = layout?.getOffsetForPosition(position) ?: return@detectTapGestures
                            onPlay(Uncertain.playFrom(s, ranges, offset))
                        }
                    }
                } else {
                    Modifier
                }
            )
    )
}

/** "[?word]" marks shown underlined without their brackets. */
fun viewLine(text: String) = buildAnnotatedString {
    val marked = Regex("""\[\?([^\]]+)]""")
    var at = 0
    for (m in marked.findAll(text)) {
        append(text.substring(at, m.range.first))
        pushStyle(SpanStyle(textDecoration = TextDecoration.Underline))
        append(m.groupValues[1])
        pop()
        at = m.range.last + 1
    }
    append(text.substring(at))
}

/** A dotted line under [range] of the laid-out text, on every line it spans (right to left too); classic's drawing. */
private fun DrawScope.dottedUnderline(layout: TextLayoutResult, range: IntRange, colour: Color, dot: Float, gap: Float) {
    if (range.isEmpty() || range.last >= layout.layoutInput.text.length) return
    val first = layout.getLineForOffset(range.first)
    val last = layout.getLineForOffset(range.last)
    val dots = PathEffect.dashPathEffect(floatArrayOf(dot, gap))
    for (l in first..last) {
        val start = if (l == first) layout.getHorizontalPosition(range.first, true) else layout.getLineLeft(l)
        val end = if (l == last) layout.getHorizontalPosition(range.last + 1, true) else layout.getLineRight(l)
        val y = layout.getLineBottom(l) - dot / 2
        drawLine(
            colour,
            Offset(min(start, end), y),
            Offset(max(start, end), y),
            strokeWidth = dot * 0.75f,
            cap = StrokeCap.Round,
            pathEffect = dots
        )
    }
}

/** Long-press a line: Copy line, Share line, Play from here (when the recording can play). */
@Composable
fun LineActionsSheet(line: LineUi, canPlay: Boolean, onCopy: (String) -> Unit, onPlay: (Long) -> Unit, onDismiss: () -> Unit) {
    val context = androidx.compose.ui.platform.LocalContext.current
    val words = "${line.speaker}: ${line.words ?: line.segment.text}"
    EchoBottomSheet(onDismiss = onDismiss, title = stringResource(R.string.v2_rec_line_actions)) {
        Column(Modifier.fillMaxWidth()) {
            MenuRow(RecordingIcons.Copy, stringResource(R.string.v2_rec_copy_line), {
                onDismiss()
                onCopy(words)
            })
            MenuRow(EchoIcons.Share, stringResource(R.string.v2_rec_share_line), {
                onDismiss()
                val send = Intent(Intent.ACTION_SEND).setType("text/plain").putExtra(Intent.EXTRA_TEXT, words)
                context.startActivity(Intent.createChooser(send, null).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
            })
            if (canPlay) {
                MenuRow(EchoIcons.Play, stringResource(R.string.v2_rec_play_here), {
                    onDismiss()
                    onPlay(line.segment.startMs)
                })
            }
        }
    }
}

/** "Final transcript" or "Live captions (final pending)" over the lines. */
@Composable
fun TranscriptKind(final: Boolean) = Text(
    stringResource(if (final) R.string.v2_rec_final else R.string.v2_rec_live),
    style = EchoV2.type.meta,
    color = EchoV2.colors.textSecondary
)
