package com.echoledger.mobile.ui

import androidx.compose.foundation.gestures.detectTapGestures
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
import androidx.compose.ui.text.SpanStyle
import androidx.compose.ui.text.TextLayoutResult
import androidx.compose.ui.text.buildAnnotatedString
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
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
internal fun TranscriptLine(segment: SegmentDto, spoken: Int, enabled: Boolean, onPlay: (Long) -> Unit) {
    val unsure = remember(segment) { segment.words.indices.filter { Uncertain.word(segment.words[it]) } }
    val useWords = segment.words.isNotEmpty() && (spoken >= 0 || unsure.isNotEmpty())
    val (text, ranges) = remember(segment, useWords) {
        if (useWords) Uncertain.layout(segment.words) else segment.text to emptyList()
    }
    val marked = when {
        segment.uncertain && text.isNotEmpty() -> listOf(text.indices)
        else -> unsure.map { ranges[it] }
    }
    var layout by remember { mutableStateOf<TextLayoutResult?>(null) }
    val colour = Brand.Warning
    val described = when {
        segment.uncertain -> "Not sure of this line"
        unsure.size == 1 -> "Not sure of 1 word"
        unsure.isNotEmpty() -> "Not sure of ${unsure.size} words"
        else -> null
    }
    Text(
        buildAnnotatedString {
            append(text)
            if (spoken in ranges.indices) {
                addStyle(SpanStyle(background = Brand.Warning.copy(alpha = 0.35f)), ranges[spoken].first, ranges[spoken].last + 1)
            }
        },
        fontSize = 14.sp,
        onTextLayout = { layout = it },
        modifier = Modifier
            .then(if (described != null) Modifier.semantics { stateDescription = described } else Modifier)
            .drawBehind { layout?.let { l -> marked.forEach { dottedUnderline(l, it, colour) } } }
            .then(
                if (enabled && unsure.isNotEmpty()) {
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
