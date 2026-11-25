package com.echoledger.mobile.ui.v2.components

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.border
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
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.semantics.stateDescription
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.Density
import androidx.compose.ui.unit.LayoutDirection
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.theme.largeText
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
    /** Not read yet (Activity): a dot in the accent just before the title, as classic has it. */
    unread: Boolean = false,
    /** What screen readers hear for [unread] ("Unread"), in the screen's words. */
    unreadLabel: String? = null,
    /** The title in the regular weight (a Library row its person has opened); semibold otherwise. */
    plainTitle: Boolean = false,
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
            .semantics(mergeDescendants = true) { if (unread && unreadLabel != null) stateDescription = unreadLabel }
            .padding(horizontal = LocalPageMargin.current, vertical = EchoTokens.Space.s8),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s16)
    ) {
        leading?.invoke()
        Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s2)) {
            Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
                if (unread) {
                    Box(
                        Modifier
                            .size(EchoTokens.Component.statusDotSize)
                            .background(c.accent, CircleShape)
                    )
                }
                // From 150 % text a title takes two lines, so it says more than a few letters beside the time.
                Text(
                    title,
                    style = type.content(title, if (plainTitle) type.body else type.bodyStrong),
                    color = c.textPrimary,
                    maxLines = if (largeText()) 2 else 1,
                    overflow = TextOverflow.Ellipsis,
                    textAlign = startAlign(),
                    modifier = Modifier.weight(1f)
                )
                titleEnd?.let { Text(it, style = type.mono, color = c.textSecondary, maxLines = 1) }
            }
            if (meta != null || metaEnd != null || status != null) {
                Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
                    status?.invoke()
                    Text(
                        meta.orEmpty(),
                        style = type.content(meta.orEmpty(), type.meta),
                        color = c.textSecondary,
                        maxLines = 1,
                        overflow = TextOverflow.Ellipsis,
                        textAlign = startAlign(),
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
    /** The title and subtitle are people's own words (an Ask source), not the app's. */
    content: Boolean = false,
    onClick: (() -> Unit)? = null,
    /** The icon in the warning colour: something needs the person (the line under the profile card). */
    warning: Boolean = false
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
            icon?.let { Icon(it, null, Modifier.size(EchoTokens.Icon.lg), tint = if (warning) c.warning else c.textSecondary) }
            Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s2)) {
                if (content) {
                    Text(title, style = type.content(title, type.body), color = c.textPrimary, textAlign = startAlign())
                    subtitle?.let { Text(it, style = type.content(it, type.meta), color = c.textSecondary, textAlign = startAlign()) }
                } else {
                    Text(title, style = type.body, color = c.textPrimary)
                    subtitle?.let { Text(it, style = type.meta, color = c.textSecondary) }
                }
            }
            value?.let {
                Text(
                    it,
                    // A people's-words row's value is a moment in a recording: a clock.
                    style = if (content) type.mono else type.meta,
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
fun EchoAvatar(
    modifier: Modifier = Modifier,
    icon: ImageVector? = null,
    initials: String? = null,
    contentDescription: String? = null,
    /** One of the avatar palette's colours (v2.1 UX-16, DES-08): the initials in white on it, inside a thin ring. */
    fill: androidx.compose.ui.graphics.Color? = null
) {
    val c = EchoV2.colors
    val ring = if (c.isDark) EchoTokens.Dark.avatarRing else EchoTokens.Light.avatarRing
    val ink = if (c.isDark) EchoTokens.Dark.avatarText else EchoTokens.Light.avatarText
    Box(
        modifier
            .size(EchoTokens.Component.avatarSize)
            .background(fill ?: c.bgSubtle, CircleShape)
            .then(if (fill != null) Modifier.border(EchoTokens.Border.hairline, ring, CircleShape) else Modifier)
            .then(if (contentDescription != null) Modifier.semantics { this.contentDescription = contentDescription } else Modifier),
        contentAlignment = Alignment.Center
    ) {
        when {
            icon != null -> Icon(icon, null, Modifier.size(EchoTokens.Icon.md), tint = c.textSecondary)

            // The circle has a fixed size, so its initials don't grow with the text size (the name beside it does).
            initials != null -> CompositionLocalProvider(LocalDensity provides Density(LocalDensity.current.density)) {
                // Their own script's face: Latin initials keep the Latin face on the Urdu screens.
                Text(
                    initials,
                    style = EchoV2.type.content(initials, EchoV2.type.label),
                    color = if (fill != null) ink else c.textPrimary,
                    maxLines = 1
                )
            }
        }
    }
}

/** A person's avatar: their initials, or the person icon when there is no name (never a "?", which reads as Help). */
@Composable
fun EchoPersonAvatar(initials: String, modifier: Modifier = Modifier) {
    if (initials.isBlank()) EchoAvatar(modifier, icon = EchoIcons.Person) else EchoAvatar(modifier, initials = initials)
}

/** A group heading in a list (Settings groups, Library days). Marked as a heading for screen readers. */
@Composable
fun EchoSectionHeader(text: String, modifier: Modifier = Modifier) {
    // v2.1 UX-16: text only (no icon, line or chevron), 14 sp medium in the secondary colour, 24 dp above and 8 below;
    // never tappable, a heading for TalkBack.
    Text(
        text,
        style = EchoV2.type.section,
        color = EchoV2.colors.textSecondary,
        modifier = modifier
            .fillMaxWidth()
            .padding(
                start = LocalPageMargin.current,
                end = LocalPageMargin.current,
                top = EchoTokens.Space.s24,
                bottom = EchoTokens.Space.s8
            )
            .semantics { heading() }
    )
}

/**
 * The rows under a section's title in one rounded group (v2.1 UX-16, One UI's settings cards): 24 dp corners on the
 * surface colour with the hairline border, inside the page's side margins. Its rows keep their own padding; no shadow.
 */
@Composable
fun EchoGroupCard(modifier: Modifier = Modifier, content: @Composable ColumnScope.() -> Unit) {
    val c = EchoV2.colors
    Surface(
        modifier = modifier.fillMaxWidth().padding(horizontal = LocalPageMargin.current).testTag(GROUP_CARD_TAG),
        shape = RoundedCornerShape(EchoTokens.Radius.group),
        color = c.bgSurface,
        contentColor = c.textPrimary,
        // The hairline the desktop's cards have: white on the light page alone barely shows.
        border = BorderStroke(EchoTokens.Border.hairline, c.borderDefault)
    ) {
        Column(content = content)
    }
}

/** The test tag of every [EchoGroupCard]. */
const val GROUP_CARD_TAG = "echo-group-card"

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

/** The screen's start side as a fixed side, so a line whose direction comes from its words still lines up with the rest. */
@Composable
internal fun startAlign(): TextAlign = if (LocalLayoutDirection.current == LayoutDirection.Rtl) TextAlign.Right else TextAlign.Left
