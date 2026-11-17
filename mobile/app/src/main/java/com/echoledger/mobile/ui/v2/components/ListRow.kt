package com.echoledger.mobile.ui.v2.components

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.combinedClickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.defaultMinSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Icon
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.tokens.EchoTokens

/**
 * A list row, 64 dp (one line of meta) or 72 dp ([tall], with an avatar), in the WhatsApp manner: the title and
 * [titleEnd] (a time) on the first line; at most one [status], then [meta] and [metaEnd] (a duration) on the second;
 * [trailing] for a switch or a value. The whole row is one tap target and is read as one item; [onLongClick] (with
 * its [onLongClickLabel], which screen readers offer as an action) opens the row's actions.
 */
@Composable
fun EchoListRow(
    title: String,
    modifier: Modifier = Modifier,
    meta: String? = null,
    titleEnd: String? = null,
    metaEnd: (@Composable () -> Unit)? = null,
    status: (@Composable () -> Unit)? = null,
    leading: (@Composable () -> Unit)? = null,
    trailing: (@Composable () -> Unit)? = null,
    tall: Boolean = false,
    onClickLabel: String? = null,
    onLongClickLabel: String? = null,
    onLongClick: (() -> Unit)? = null,
    onClick: (() -> Unit)? = null
) {
    val c = EchoV2.colors
    val type = EchoV2.type
    Row(
        modifier
            .fillMaxWidth()
            .defaultMinSize(minHeight = if (tall) EchoTokens.Component.listRowHeightMobileTall else EchoTokens.Layout.rowMobile)
            .then(
                when {
                    onLongClick != null -> Modifier.combinedClickable(
                        onClickLabel = onClickLabel,
                        onLongClickLabel = onLongClickLabel,
                        onLongClick = onLongClick,
                        onClick = onClick ?: {}
                    )

                    onClick != null -> Modifier.clickable(onClickLabel = onClickLabel, onClick = onClick)

                    else -> Modifier
                }
            )
            .semantics(mergeDescendants = true) {}
            .padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s8),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s16)
    ) {
        leading?.invoke()
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s2)) {
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
                Text(
                    title,
                    style = type.bodyStrong,
                    color = c.textPrimary,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                    modifier = Modifier.weight(1f)
                )
                titleEnd?.let { Text(it, style = type.mono, color = c.textSecondary, maxLines = 1) }
            }
            if (meta != null || metaEnd != null || status != null) {
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
                    status?.invoke()
                    Text(
                        meta.orEmpty(),
                        style = type.meta,
                        color = c.textSecondary,
                        maxLines = 1,
                        overflow = TextOverflow.Ellipsis,
                        modifier = Modifier.weight(1f)
                    )
                    metaEnd?.invoke()
                }
            }
        }
        trailing?.invoke()
    }
}

/**
 * A settings row: the title, an optional [subtitle] (use few), and the current [value] on the end side. The value is
 * part of the row's spoken name ("Theme, System").
 */
@Composable
fun EchoSettingRow(
    title: String,
    modifier: Modifier = Modifier,
    value: String? = null,
    subtitle: String? = null,
    icon: ImageVector? = null,
    trailing: (@Composable () -> Unit)? = null,
    onClick: (() -> Unit)? = null
) {
    val c = EchoV2.colors
    val type = EchoV2.type
    BoxWithConstraints(modifier.fillMaxWidth()) {
        // A value takes at most this share of the row, so long titles keep their room and short values hug the end.
        val valueMax = maxWidth * 0.45f
        Row(
            Modifier
                .fillMaxWidth()
                .defaultMinSize(minHeight = EchoTokens.Layout.rowMobile)
                .then(if (onClick != null) Modifier.clickable(onClick = onClick) else Modifier)
                .semantics(mergeDescendants = true) {}
                .padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s8),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s16)
        ) {
            icon?.let { Icon(it, null, Modifier.size(EchoTokens.Icon.lg), tint = c.textSecondary) }
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s2)) {
                Text(title, style = type.body, color = c.textPrimary)
                subtitle?.let { Text(it, style = type.meta, color = c.textSecondary) }
            }
            value?.let {
                Text(
                    it,
                    style = type.meta,
                    color = c.textSecondary,
                    maxLines = 2,
                    overflow = TextOverflow.Ellipsis,
                    textAlign = TextAlign.End,
                    modifier = Modifier.widthIn(max = valueMax)
                )
            }
            trailing?.invoke()
        }
    }
}

/** A neutral round avatar holding a monochrome icon (a recording's source) or initials. Never a coloured tile. */
@Composable
fun EchoAvatar(modifier: Modifier = Modifier, icon: ImageVector? = null, initials: String? = null, contentDescription: String? = null) {
    val c = EchoV2.colors
    Box(
        modifier
            .size(EchoTokens.Component.avatarSize)
            .background(c.bgSubtle, CircleShape)
            .then(if (contentDescription != null) Modifier.semantics { this.contentDescription = contentDescription } else Modifier),
        contentAlignment = Alignment.Center
    ) {
        when {
            icon != null -> Icon(icon, null, Modifier.size(EchoTokens.Icon.md), tint = c.textSecondary)
            initials != null -> Text(initials, style = EchoV2.type.label, color = c.textPrimary, maxLines = 1)
        }
    }
}

/** A group heading in a list (Settings groups, Library days). Marked as a heading for screen readers. */
@Composable
fun EchoSectionHeader(text: String, modifier: Modifier = Modifier) {
    Text(
        text,
        style = EchoV2.type.label,
        color = EchoV2.colors.textSecondary,
        modifier = modifier
            .fillMaxWidth()
            .padding(
                start = EchoTokens.Layout.pagePaddingMobile,
                end = EchoTokens.Layout.pagePaddingMobile,
                top = EchoTokens.Space.s24,
                bottom = EchoTokens.Space.s4
            )
            .semantics { heading() }
    )
}

/** A hairline between rows or groups; [inset] starts it after the leading avatar. */
@Composable
fun EchoDivider(modifier: Modifier = Modifier, inset: Boolean = false) {
    Box(
        modifier
            .fillMaxWidth()
            .padding(start = if (inset) DividerInset else EchoTokens.Space.s0)
            .height(EchoTokens.Border.hairline)
            .background(EchoV2.colors.borderDefault)
    )
}

/** A flat card with a hairline border, for mixed content only (the profile card). No shadow, no coloured edge. */
@Composable
fun EchoCard(modifier: Modifier = Modifier, content: @Composable ColumnScope.() -> Unit) {
    val c = EchoV2.colors
    Surface(
        modifier = modifier.fillMaxWidth(),
        shape = RoundedCornerShape(EchoTokens.Component.cardRadius),
        color = c.bgSurface,
        contentColor = c.textPrimary,
        border = BorderStroke(EchoTokens.Border.hairline, c.borderDefault)
    ) {
        Column(Modifier.padding(EchoTokens.Space.s16), verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s12), content = content)
    }
}

/** Where an inset divider starts: after the page padding, the avatar and the gap. */
private val DividerInset = EchoTokens.Layout.pagePaddingMobile + EchoTokens.Component.avatarSize + EchoTokens.Space.s16
