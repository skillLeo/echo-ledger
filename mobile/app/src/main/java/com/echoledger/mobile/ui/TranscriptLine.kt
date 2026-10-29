package com.echoledger.mobile.ui

import androidx.compose.foundation.gestures.detectTapGestures
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.drawBehind
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.PathEffect
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.graphics.drawscope.DrawScope
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.semantics.stateDescription
import androidx.compose.ui.text.AnnotatedString
import androidx.compose.ui.text.SpanStyle
import androidx.compose.ui.text.TextLayoutResult
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.buildAnnotatedString
import androidx.compose.ui.text.intl.LocaleList
import androidx.compose.ui.text.style.TextDecoration
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.echoledger.mobile.core.Languages
import com.echoledger.mobile.core.Uncertain
import com.echoledger.mobile.data.SegmentDto
import kotlin.math.max
import kotlin.math.min

/**
 * The text of one transcript line on the recording page: the word being spoken ([spoken]) highlighted, and what the
 * engine was not sure of underlined with dots (since v1.2). A tap on an uncertain word plays from that word; a tap
 * anywhere else plays the line (the row does that). The text stays as heard.
 */
@Composable
internal fun TranscriptLine(
    segment: SegmentDto,
    spoken: Int,
    enabled: Boolean,
    override: String? = null,
    language: String? = null,
    keptOriginal: Boolean = false,
    onPlay: (Long) -> Unit
) {
    // Show as: the Roman Urdu or English view of the line (labelled above the transcript), without marks. A line the view
    // could not be written for shows its original words with a small note (since v1.3).
    if (override != null) {
        Text(withUnsureMarks(override), fontSize = 14.sp, style = scriptStyle(language))
        if (keptOriginal) Text("Original words: this line could not be written in the view.", fontSize = 11.sp, color = Brand.Muted)
        return
    }
    val unsure = remember(segment) { segment.words.indices.filter { Uncertain.word(segment.words[it]) } }
    val useWords = segment.words.isNotEmpty() && (spoken >= 0 || unsure.isNotEmpty())
    val (text, ranges) = remember(segment, useWords) {
        if (useWords) Uncertain.layout(segment.words) else segment.text to emptyList()
    }
    val marked = when {
        segment.uncertain && text.isNotEmpty() -> listOf(text.indices)
        else -> unsure.map { ranges[it] }
    }
    val described = when {
        segment.uncertain -> "Not sure of this line"
        unsure.size == 1 -> "Not sure of 1 word"
        unsure.isNotEmpty() -> "Not sure of ${unsure.size} words"
        else -> null
    }
    Column {
        LineText(text, ranges, spoken, marked, described, enabled && unsure.isNotEmpty(), segment, language, onPlay)
        segment.check?.let { check ->
            Text("${check.label} heard: ${check.text}", color = Brand.Muted, fontSize = 12.sp, modifier = Modifier.padding(top = 2.dp))
        }
    }
}

@Composable
private fun LineText(
    text: String,
    ranges: List<IntRange>,
    spoken: Int,
    marked: List<IntRange>,
    described: String?,
    tappable: Boolean,
    segment: SegmentDto,
    language: String?,
    onPlay: (Long) -> Unit
) {
    var layout by remember { mutableStateOf<TextLayoutResult?>(null) }
    val colour = Brand.Warning
    Text(
        buildAnnotatedString {
            append(text)
            if (spoken in ranges.indices) {
                addStyle(SpanStyle(background = Brand.Warning.copy(alpha = 0.35f)), ranges[spoken].first, ranges[spoken].last + 1)
            }
        },
        fontSize = 14.sp,
        // Urdu and Arabic get their own letter forms (Android's Nastaliq and Naskh fonts follow the text's locale).
        style = scriptStyle(language),
        onTextLayout = { layout = it },
        modifier = Modifier
            .then(if (described != null) Modifier.semantics { stateDescription = described } else Modifier)
            .drawBehind { layout?.let { l -> marked.forEach { dottedUnderline(l, it, colour) } } }
            .then(
                if (tappable) {
                    Modifier.pointerInput(segment) {
                        detectTapGestures { position ->
                            val offset = layout?.getOffsetForPosition(position) ?: return@detectTapGestures
                            onPlay(Uncertain.playFrom(segment, ranges, offset))
                        }
                    }
                } else {
                    Modifier
                }
            )
    )
}

/** Urdu text in the Urdu locale (Nastaliq, taller lines), Arabic in the Arabic locale (Naskh); others as they are. */
internal fun scriptStyle(language: String?): TextStyle = when (Languages.canonical(language)) {
    "ur" -> TextStyle(localeList = LocaleList("ur"), lineHeight = 28.sp)
    "ar" -> TextStyle(localeList = LocaleList("ar"), lineHeight = 24.sp)
    else -> TextStyle.Default
}

/** A dotted line under [range] of the laid-out text, on every line it spans (right to left text too). */
private fun DrawScope.dottedUnderline(layout: TextLayoutResult, range: IntRange, colour: Color) {
    if (range.isEmpty() || range.last >= layout.layoutInput.text.length) return
    val first = layout.getLineForOffset(range.first)
    val last = layout.getLineForOffset(range.last)
    val dots = PathEffect.dashPathEffect(floatArrayOf(2.dp.toPx(), 3.dp.toPx()))
    for (line in first..last) {
        val start = if (line == first) layout.getHorizontalPosition(range.first, true) else layout.getLineLeft(line)
        val end = if (line == last) layout.getHorizontalPosition(range.last + 1, true) else layout.getLineRight(line)
        val y = layout.getLineBottom(line) - 1.dp.toPx()
        drawLine(
            colour,
            Offset(min(start, end), y),
            Offset(max(start, end), y),
            strokeWidth = 1.5.dp.toPx(),
            cap = StrokeCap.Round,
            pathEffect = dots
        )
    }
}

/** A view's line with its "[?word]" marks shown like the original's unsure words: underlined, without brackets (plan A4). */
internal fun withUnsureMarks(text: String): AnnotatedString {
    val marked = Regex("""\[\?([^\]]+)]""")
    if (!marked.containsMatchIn(text)) return AnnotatedString(text)
    return buildAnnotatedString {
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
}
