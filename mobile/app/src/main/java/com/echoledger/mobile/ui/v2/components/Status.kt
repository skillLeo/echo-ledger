package com.echoledger.mobile.ui.v2.components

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.defaultMinSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.tokens.EchoTokens

/** What a status means. Red appears only for [Recording] and [Danger]. */
enum class EchoStatusKind { Neutral, Accent, Success, Warning, Danger, Recording }

/**
 * A status: a dot and a short word on a soft fill ("Ready", "Needs setup", "Failed"). The word carries the meaning, so
 * it reads without colour. At most one per row.
 */
@Composable
fun EchoStatus(label: String, kind: EchoStatusKind, modifier: Modifier = Modifier) {
    val c = EchoV2.colors
    val (fill, ink) = when (kind) {
        EchoStatusKind.Neutral -> c.bgSubtle to c.textSecondary
        EchoStatusKind.Accent -> c.accentSoft to c.accentText
        EchoStatusKind.Success -> c.successSoft to c.success
        EchoStatusKind.Warning -> c.warningSoft to c.warning
        EchoStatusKind.Danger -> c.dangerSoft to c.danger
        EchoStatusKind.Recording -> c.recordSoft to c.record
    }
    Row(
        modifier
            .background(fill, RoundedCornerShape(EchoTokens.Component.chipRadius))
            .padding(horizontal = EchoTokens.Space.s8, vertical = EchoTokens.Space.s2),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s4)
    ) {
        Box(Modifier.size(EchoTokens.Component.statusDotSize).background(ink, CircleShape))
        Text(label, style = EchoV2.type.captionStrong, color = ink, maxLines = 1)
    }
}

/** A count badge (unread, waiting). [contentDescription] says what is counted ("3 untranscribed"). */
@Composable
fun EchoCountBadge(count: Int, contentDescription: String, modifier: Modifier = Modifier, color: Color = EchoV2.colors.accent) {
    Box(
        modifier
            .defaultMinSize(minWidth = EchoTokens.Space.s20, minHeight = EchoTokens.Space.s20)
            .background(color, RoundedCornerShape(EchoTokens.Component.chipRadius))
            .padding(horizontal = EchoTokens.Space.s4)
            .semantics { this.contentDescription = contentDescription },
        contentAlignment = Alignment.Center
    ) {
        Text(if (count > 99) "99+" else count.toString(), style = EchoV2.type.captionStrong, color = EchoV2.colors.textOnAccent)
    }
}
