package com.echoledger.mobile.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Close
import androidx.compose.material.icons.rounded.KeyboardArrowDown
import androidx.compose.material.icons.rounded.KeyboardArrowUp
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.unit.dp
import com.echoledger.mobile.ui.design.BarIcon
import com.echoledger.mobile.ui.design.tabular

/** Find in transcript (since v1.3): the lines that contain [query], ignoring case and accents of Latin letters. */
object TranscriptFind {
    fun matches(lines: List<String>, query: String): List<Int> {
        val needle = fold(query.trim())
        if (needle.isEmpty()) return emptyList()
        return lines.indices.filter { fold(lines[it]).contains(needle) }
    }

    /** "2 of 7", or "No matches" once something is typed. */
    fun counter(current: Int, total: Int): String = if (total == 0) "No matches" else "${current + 1} of $total"

    private fun fold(text: String): String =
        java.text.Normalizer.normalize(text.lowercase(), java.text.Normalizer.Form.NFD).replace(MARKS, "")

    private val MARKS = Regex("\\p{Mn}+")
}

/** The find field under the recording page's top bar: the words, "2 of 7", previous / next and close. */
@Composable
fun FindBar(query: String, onQuery: (String) -> Unit, current: Int, total: Int, onStep: (Int) -> Unit, onClose: () -> Unit) {
    val c = LocalEchoColors.current
    val type = LocalEchoType.current
    val focus = remember { FocusRequester() }
    LaunchedEffect(Unit) { runCatching { focus.requestFocus() } }
    Row(
        Modifier.fillMaxWidth().padding(horizontal = Space.screen, vertical = Space.s1).height(48.dp).clip(CircleShape)
            .background(c.surface2).padding(start = Space.s4, end = Space.s1),
        verticalAlignment = Alignment.CenterVertically
    ) {
        Box(Modifier.weight(1f)) {
            if (query.isEmpty()) Text("Find in transcript", style = type.bodyM, color = c.textMuted, maxLines = 1)
            BasicTextField(
                value = query,
                onValueChange = onQuery,
                singleLine = true,
                textStyle = type.bodyM.copy(color = c.text),
                cursorBrush = SolidColor(c.accentText),
                keyboardOptions = KeyboardOptions(imeAction = ImeAction.Search),
                keyboardActions = KeyboardActions(onSearch = { onStep(1) }),
                modifier = Modifier.fillMaxWidth().focusRequester(focus).semantics { contentDescription = "Find in transcript" }
            )
        }
        if (query.isNotBlank()) {
            Text(
                TranscriptFind.counter(current, total),
                style = type.labelM.tabular(),
                color = c.textMuted,
                modifier = Modifier.padding(horizontal = Space.s2).semantics { liveRegion = LiveRegionMode.Polite }
            )
        }
        if (total > 1) {
            BarIcon(Icons.Rounded.KeyboardArrowUp, "Previous match", { onStep(-1) })
            BarIcon(Icons.Rounded.KeyboardArrowDown, "Next match", { onStep(1) })
        }
        BarIcon(Icons.Rounded.Close, "Close find", onClose)
    }
}
