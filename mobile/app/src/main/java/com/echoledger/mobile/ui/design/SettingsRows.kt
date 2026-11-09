package com.echoledger.mobile.ui.design

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.selection.SelectionContainer
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.Check
import androidx.compose.material.icons.rounded.ContentCopy
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import com.echoledger.mobile.ui.Brand
import com.echoledger.mobile.ui.LocalEchoColors
import com.echoledger.mobile.ui.LocalEchoType
import com.echoledger.mobile.ui.Radius
import com.echoledger.mobile.ui.Space
import kotlinx.coroutines.delay

/**
 * A settings section (since v1.7, docs/DESIGN_SYSTEM.md "Settings"): a sentence-case header, its rows in one container
 * on the surface colour with a hairline border, and an optional footer line under it. Rows are separated by inset
 * dividers; nothing is nested in a second card.
 */
@Composable
fun SettingsSection(title: String?, modifier: Modifier = Modifier, footer: String? = null, content: @Composable ColumnScope.() -> Unit) {
    val type = LocalEchoType.current
    Column(modifier.fillMaxWidth().padding(bottom = Space.s4)) {
        if (title != null) {
            Text(
                title,
                style = type.labelM.copy(fontWeight = FontWeight.SemiBold),
                color = Brand.Muted,
                modifier = Modifier.padding(start = Space.screen + Space.s1, end = Space.screen, bottom = Space.s2).semantics {
                    heading()
                }
            )
        }
        Column(
            Modifier.fillMaxWidth().padding(horizontal = Space.screen).clip(RoundedCornerShape(Radius.md)).background(Brand.Surface)
                .border(1.dp, Brand.Border, RoundedCornerShape(Radius.md)),
            content = content
        )
        if (footer != null) {
            Text(
                footer,
                style = type.labelM.copy(fontWeight = FontWeight.Normal),
                color = Brand.Muted,
                modifier = Modifier.padding(start = Space.screen + Space.s1, end = Space.screen, top = Space.s2)
            )
        }
    }
}

/** The hairline between rows of a [SettingsSection], inset to where the text starts. */
@Composable
fun RowDivider(inset: Boolean = true) {
    HorizontalDivider(
        Modifier.padding(start = if (inset) Space.screen + 24.dp + Space.s4 else Space.screen),
        thickness = 1.dp,
        color = Brand.Border
    )
}

/**
 * The plan tag (since v1.7): the plan's name in sentence case, "Free", "Pro" or "Ultra", on the muted surface. Never a
 * plan colour, a lock, a star or capitals: the same quiet tag wherever a plan is named.
 */
@Composable
fun PlanTag(text: String, modifier: Modifier = Modifier, color: Color = Brand.Text) {
    Text(
        text,
        style = LocalEchoType.current.labelM.copy(fontWeight = FontWeight.SemiBold),
        color = color,
        maxLines = 1,
        modifier = modifier.clip(RoundedCornerShape(Radius.tag)).background(Brand.SurfaceAlt).padding(horizontal = 8.dp, vertical = 3.dp)
    )
}

/**
 * One limit as a meter: the title and "230 of 300 min" on one line, a 4 dp bar, and a line under it. Below 80 % the bar
 * is the muted text colour; from 80 % the warning text colour; full (for a limit that blocks something) the danger
 * text colour; the value text takes the same colour. Read by TalkBack as one stop.
 */
@Composable
fun MeterRow(title: String, value: String, fraction: Float?, line: String?, blocking: Boolean = true, spoken: String? = null) {
    val c = LocalEchoColors.current
    val type = LocalEchoType.current
    val tone = when {
        fraction == null -> c.textMuted
        fraction >= 1f && blocking -> c.danger
        fraction >= 0.8f -> c.warning
        else -> c.textMuted
    }
    Column(
        Modifier.fillMaxWidth().heightIn(min = 56.dp).padding(horizontal = Space.screen, vertical = Space.s3)
            .clearAndSetSemantics { contentDescription = spoken ?: listOfNotNull(title, value, line).joinToString(", ") },
        verticalArrangement = Arrangement.spacedBy(6.dp)
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(title, style = type.body, color = c.text, modifier = Modifier.weight(1f), maxLines = 1, overflow = TextOverflow.Ellipsis)
            Text(value, style = type.body, color = if (fraction != null && fraction >= 0.8f) tone else c.textMuted, maxLines = 1)
        }
        if (fraction != null) {
            Box(Modifier.fillMaxWidth().height(4.dp).clip(CircleShape).background(c.surface3)) {
                Box(Modifier.fillMaxWidth(fraction.coerceIn(0.02f, 1f)).height(4.dp).clip(CircleShape).background(tone))
            }
        }
        if (line !=
            null
        ) {
            Text(
                line,
                style = type.labelM.copy(fontWeight = FontWeight.Normal),
                color = if (fraction != null &&
                    fraction >= 0.8f
                ) {
                    tone
                } else {
                    c.textMuted
                }
            )
        }
    }
}

/**
 * A detail to copy (the account title, IBAN, amount, reference): its label, the value (selectable), and a 48 dp copy
 * button that shows a check for 2 s. Android 13+ shows its own "Copied" note, so no message here.
 */
@Composable
fun CopyRow(label: String, value: String, copyValue: String = value, mono: Boolean = false) {
    val context = LocalContext.current
    val type = LocalEchoType.current
    var copied by remember { mutableStateOf(false) }
    LaunchedEffect(copied) {
        if (copied) {
            delay(2_000)
            copied = false
        }
    }
    Row(
        Modifier.fillMaxWidth().heightIn(min = 64.dp).padding(start = Space.screen, end = Space.s1, top = Space.s2, bottom = Space.s2),
        verticalAlignment = Alignment.CenterVertically
    ) {
        Column(Modifier.weight(1f)) {
            Text(label, style = type.labelM.copy(fontWeight = FontWeight.Normal), color = Brand.Muted)
            SelectionContainer {
                Text(
                    value,
                    style = if (mono) type.bodyL.copy(fontFamily = androidx.compose.ui.text.font.FontFamily.Monospace) else type.bodyL,
                    color = Brand.Text
                )
            }
        }
        IconButton(onClick = {
            val clipboard = context.getSystemService(android.content.ClipboardManager::class.java)
            clipboard?.setPrimaryClip(android.content.ClipData.newPlainText(label, copyValue))
            copied = true
        }, modifier = Modifier.size(48.dp)) {
            Icon(
                if (copied) Icons.Rounded.Check else Icons.Rounded.ContentCopy,
                contentDescription = if (copied) "Copied" else "Copy $label",
                tint = if (copied) LocalEchoColors.current.success else Brand.Muted,
                modifier = Modifier.size(20.dp)
            )
        }
    }
}
