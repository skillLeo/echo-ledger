package com.echoledger.mobile.ui.v2.help

import androidx.compose.foundation.background
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.remember
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.AnnotatedString
import androidx.compose.ui.text.SpanStyle
import androidx.compose.ui.text.buildAnnotatedString
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextDirection
import androidx.compose.ui.unit.LayoutDirection
import com.echoledger.mobile.ui.v2.components.EchoDivider
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.tokens.EchoTokens

/** One block of a guide's Markdown. */
sealed interface MdBlock {
    data class Heading(val level: Int, val text: String) : MdBlock
    data class Paragraph(val text: String) : MdBlock
    data class Quote(val text: String) : MdBlock
    data class ListBlock(val ordered: Boolean, val items: List<String>) : MdBlock
    data class Table(val head: List<String>, val rows: List<List<String>>) : MdBlock
    data class Code(val text: String) : MdBlock
}

/** A stretch of a line: plain, bold or code. A link keeps its words only, as plain text: nothing is ever a target. */
data class MdSpan(val text: String, val bold: Boolean = false, val code: Boolean = false)

/**
 * The user guide's Markdown read as blocks, never as HTML (the same reader as the desktop's, desktop/src/v2/help/
 * markdown.tsx): headings, paragraphs, lists, tables, quotes, bold and code; a link shows its words only. Anything else
 * reads as plain text. The text comes from the server, so the reader is bounded: every step consumes at least one line,
 * at most [MAX_LINES] lines, [MAX_BLOCKS] blocks and [MAX_CHARS] characters are read, a table keeps [MAX_CELLS] cells a
 * row and [MAX_ROWS] rows, and marks are read only in text up to [MAX_INLINE] characters.
 */
object Markdown {
    const val MAX_LINES = 5000
    const val MAX_BLOCKS = 5000
    const val MAX_CHARS = 400_000
    const val MAX_CELLS = 20
    const val MAX_ROWS = 200
    const val MAX_INLINE = 2000

    /** Each mark spans a bounded stretch, so a line full of unmatched "**" or "[" stays linear. */
    private const val MARK = 300

    private val LIST = Regex("""^\s*(?:[-*+]|(\d+)[.)])\s+(.*)$""")
    private val HEADING = Regex("""^(#{1,6})\s+(.*?)\s*#*\s*$""")
    private val TABLE_RULE = Regex("""^\s*\|?\s*:?-{2,}""")
    private val HEADING_START = Regex("""^#{1,6}\s""")
    private val INDENTED = Regex("""^\s{2,}""")
    private val LEADING_SPACE = Regex("""^\s+""")
    private val NESTED_MARK = Regex("""^(?:[-*+]|\d+[.)])\s+""")
    private val INLINE = Regex(
        """\*\*([^*\n]{1,$MARK})\*\*|`([^`\n]{1,$MARK})`|\[([^\]\n]{1,$MARK})\]\((?:[^()\s]|\([^()\s]{0,100}\)){0,500}\)"""
    )

    /** A table row's cells: at most [MAX_CELLS] (the rest are dropped). */
    private fun cells(line: String): List<String> =
        line.trim().removePrefix("|").removeSuffix("|").split("|", limit = MAX_CELLS + 1).take(MAX_CELLS).map { it.trim() }

    fun blocks(markdown: String): List<MdBlock> {
        val lines = markdown.take(MAX_CHARS).replace("\r\n", "\n").replace('\r', '\n').split("\n").take(MAX_LINES)
        val out = mutableListOf<MdBlock>()
        var i = 0
        while (i < lines.size && out.size < MAX_BLOCKS) {
            val line = lines[i]
            if (line.isBlank()) {
                i++
                continue
            }
            if (line.trim().startsWith("```")) {
                val body = mutableListOf<String>()
                i++
                while (i < lines.size && !lines[i].trim().startsWith("```")) body += lines[i++]
                i++
                out += MdBlock.Code(body.joinToString("\n"))
                continue
            }
            val h = HEADING.matchEntire(line)
            if (h != null) {
                out += MdBlock.Heading(h.groupValues[1].length, h.groupValues[2])
                i++
                continue
            }
            if (line.trim().startsWith("|") && i + 1 < lines.size && TABLE_RULE.containsMatchIn(lines[i + 1])) {
                val head = cells(line)
                val rows = mutableListOf<List<String>>()
                i += 2
                while (i < lines.size && lines[i].trim().startsWith("|")) {
                    val row = lines[i++]
                    if (rows.size < MAX_ROWS) rows += cells(row)
                }
                out += MdBlock.Table(head, rows)
                continue
            }
            if (line.trim().startsWith(">")) {
                val body = mutableListOf<String>()
                while (i < lines.size && lines[i].trim().startsWith(">")) body += lines[i++].trim().removePrefix(">").removePrefix(" ")
                out += MdBlock.Quote(body.joinToString(" "))
                continue
            }
            val item = LIST.matchEntire(line)
            if (item != null) {
                val ordered = item.groups[1] != null
                // The first item is always taken, whatever its indent, so the list moves past its own line.
                val items = mutableListOf(item.groupValues[2])
                i++
                while (i < lines.size) {
                    val m = LIST.matchEntire(lines[i])
                    if (m != null && (m.groups[1] != null) == ordered && !INDENTED.containsMatchIn(lines[i])) {
                        items += m.groupValues[2]
                        i++
                        continue
                    }
                    // An indented line (or a nested item) continues the item above it.
                    if (lines[i].isNotBlank() && LEADING_SPACE.containsMatchIn(lines[i])) {
                        items[items.lastIndex] = items.last() + " " + lines[i].trim().replace(NESTED_MARK, "")
                        i++
                        continue
                    }
                    break
                }
                out += MdBlock.ListBlock(ordered, items)
                continue
            }
            // A paragraph: this line whatever it looks like, then the lines that follow until one starts another block.
            val body = mutableListOf(line.trim())
            i++
            while (i < lines.size && lines[i].isNotBlank() && !HEADING_START.containsMatchIn(lines[i]) &&
                LIST.matchEntire(lines[i]) == null && !lines[i].trim().startsWith("|") && !lines[i].trim().startsWith(">")
            ) {
                body += lines[i++].trim()
            }
            out += MdBlock.Paragraph(body.joinToString(" "))
        }
        return out
    }

    /** Bold, code and links' words inside a line; text longer than [MAX_INLINE] stays as written. */
    fun inline(text: String, bold: Boolean = false): List<MdSpan> {
        if (text.length > MAX_INLINE) return listOf(MdSpan(text, bold = bold))
        val out = mutableListOf<MdSpan>()
        var last = 0
        for (m in INLINE.findAll(text)) {
            if (m.range.first > last) out += MdSpan(text.substring(last, m.range.first), bold = bold)
            when {
                m.groups[1] != null -> out += inline(m.groupValues[1], bold = true)
                m.groups[2] != null -> out += MdSpan(m.groupValues[2], bold = bold, code = true)
                else -> out += MdSpan(m.groupValues[3], bold = bold)
            }
            last = m.range.last + 1
        }
        if (last < text.length) out += MdSpan(text.substring(last), bold = bold)
        return out
    }
}

/** The spans as styled text: bold and code only. No link annotation: nothing in a guide can be tapped. */
@Composable
private fun styled(text: String): AnnotatedString {
    val c = EchoV2.colors
    return remember(text, c) {
        buildAnnotatedString {
            Markdown.inline(text).forEach { span ->
                val style = SpanStyle(
                    fontWeight = if (span.bold) FontWeight.SemiBold else null,
                    fontFamily = if (span.code) FontFamily.Monospace else null,
                    background = if (span.code) c.bgSubtle else c.clear
                )
                pushStyle(style)
                append(span.text)
                pop()
            }
        }
    }
}

/** A guide's text. Headings are marked as headings for screen readers; each block takes its own direction. */
@Composable
fun EchoMarkdown(text: String, modifier: Modifier = Modifier) {
    val c = EchoV2.colors
    val type = EchoV2.type
    val blocks = remember(text) { Markdown.blocks(text) }
    Column(modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s12)) {
        blocks.forEach { b ->
            when (b) {
                is MdBlock.Heading -> Text(
                    styled(b.text),
                    style = (if (b.level <= 2) type.titleS else type.bodyStrong).copy(textDirection = TextDirection.Content),
                    color = c.textPrimary,
                    modifier = Modifier.semantics { heading() }
                )

                is MdBlock.Paragraph -> Text(
                    styled(b.text),
                    style = type.body.copy(textDirection = TextDirection.Content),
                    color = c.textPrimary
                )

                is MdBlock.Quote -> Row {
                    Text(
                        styled(b.text),
                        style = type.body.copy(textDirection = TextDirection.Content),
                        color = c.textSecondary,
                        modifier = Modifier.padding(start = EchoTokens.Space.s12)
                    )
                }

                is MdBlock.Code -> Text(
                    b.text,
                    style = type.mono.copy(textDirection = TextDirection.Ltr),
                    color = c.textPrimary,
                    modifier = Modifier
                        .fillMaxWidth()
                        .background(c.bgSubtle, RoundedCornerShape(EchoTokens.Component.fieldRadius))
                        .horizontalScroll(rememberScrollState())
                        .padding(EchoTokens.Space.s8)
                )

                is MdBlock.ListBlock -> Column(verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s4)) {
                    b.items.forEachIndexed { n, item ->
                        Row(horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
                            Text(if (b.ordered) "${n + 1}." else "•", style = type.body, color = c.textSecondary)
                            Text(styled(item), style = type.body.copy(textDirection = TextDirection.Content), color = c.textPrimary)
                        }
                    }
                }

                is MdBlock.Table -> Table(b)
            }
        }
    }
}

/** A table that scrolls sideways when it's wider than the page; the header row is strong. */
@Composable
private fun Table(table: MdBlock.Table) {
    val c = EchoV2.colors
    val rtl = LocalLayoutDirection.current == LayoutDirection.Rtl
    Column(Modifier.fillMaxWidth().horizontalScroll(rememberScrollState(), reverseScrolling = rtl)) {
        listOf(table.head).plus(table.rows).forEachIndexed { r, row ->
            Row(Modifier.padding(vertical = EchoTokens.Space.s4)) {
                row.forEach { cell ->
                    Text(
                        styled(cell),
                        style = (if (r == 0) EchoV2.type.label else EchoV2.type.meta).copy(textDirection = TextDirection.Content),
                        color = if (r == 0) c.textPrimary else c.textSecondary,
                        modifier = Modifier.width(EchoTokens.Space.s64 * CELL_UNITS).padding(end = EchoTokens.Space.s8)
                    )
                }
            }
            if (r == 0) EchoDivider()
        }
    }
}

/** A table cell is 2.5 × 64 dp = 160 dp wide. */
private const val CELL_UNITS = 2.5f
