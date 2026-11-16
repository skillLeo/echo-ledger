package com.echoledger.mobile.ui.v2.components

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.defaultMinSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Icon
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.clearAndSetSemantics
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.Dp
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.tokens.EchoTokens

/**
 * A static placeholder block while content loads: the hairline colour, no shimmer and no pulse (only the recording dot
 * moves). [fraction] is the share of the available width.
 */
@Composable
fun EchoSkeletonBlock(modifier: Modifier = Modifier, fraction: Float = 1f, height: Dp = EchoTokens.Space.s12) {
    Box(
        modifier
            .fillMaxWidth(fraction)
            .height(height)
            .background(EchoV2.colors.borderDefault, RoundedCornerShape(EchoTokens.Radius.sm))
    )
}

/** A static round placeholder (an avatar, the play button). */
@Composable
fun EchoSkeletonCircle(modifier: Modifier = Modifier, size: Dp = EchoTokens.Component.avatarSize) {
    Box(modifier.size(size).background(EchoV2.colors.borderDefault, CircleShape))
}

/**
 * A list of placeholder rows the shape of [EchoListRow] (avatar, title, meta, time), read once as "Loading" by screen
 * readers instead of as empty shapes.
 */
@Composable
fun EchoSkeletonList(rows: Int, modifier: Modifier = Modifier, avatar: Boolean = true) {
    val loading = EchoV2.strings.loading
    Column(modifier.fillMaxWidth().clearAndSetSemantics { contentDescription = loading }) {
        repeat(rows) { i ->
            Row(
                Modifier
                    .fillMaxWidth()
                    .defaultMinSize(minHeight = if (avatar) EchoTokens.Component.listRowHeightMobileTall else EchoTokens.Layout.rowMobile)
                    .padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s8),
                verticalAlignment = Alignment.CenterVertically,
                horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s16)
            ) {
                if (avatar) EchoSkeletonCircle()
                Column(Modifier.weight(1f), verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s12)) {
                    // Varied widths so the placeholder reads as text, not as a grid.
                    EchoSkeletonBlock(fraction = listOf(0.55f, 0.7f, 0.45f, 0.62f)[i % 4], height = EchoTokens.Space.s16)
                    EchoSkeletonBlock(fraction = listOf(0.85f, 0.6f, 0.75f, 0.5f)[i % 4])
                }
                if (avatar) EchoSkeletonBlock(Modifier.size(EchoTokens.Space.s40, EchoTokens.Space.s12))
            }
        }
    }
}

/**
 * Something went wrong for the whole screen: a plain error icon, what happened in plain words, that nothing is lost when
 * that is true, and one way forward ([actionLabel], usually "Try again").
 */
@Composable
fun EchoErrorState(title: String, body: String, modifier: Modifier = Modifier, actionLabel: String? = null, onAction: () -> Unit = {}) {
    val c = EchoV2.colors
    Column(
        modifier
            .fillMaxWidth()
            .padding(horizontal = EchoTokens.Space.s32, vertical = EchoTokens.Space.s48)
            .semantics { liveRegion = LiveRegionMode.Polite },
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)
    ) {
        Icon(EchoIcons.Error, null, Modifier.size(EchoTokens.Icon.xl), tint = c.danger)
        Text(
            title,
            style = EchoV2.type.titleS,
            color = c.textPrimary,
            textAlign = TextAlign.Center,
            modifier = Modifier.padding(top = EchoTokens.Space.s8).semantics { heading() }
        )
        Text(body, style = EchoV2.type.meta, color = c.textSecondary, textAlign = TextAlign.Center)
        if (actionLabel != null) {
            EchoButton(actionLabel, onAction, kind = EchoButtonKind.Secondary, modifier = Modifier.padding(top = EchoTokens.Space.s8))
        }
    }
}

/** What a banner is about. */
enum class EchoBannerKind { Info, Warning, Error }

/**
 * A banner inside a page for a problem that leaves the rest usable ("Couldn't load your plan"): a hairline box on the
 * soft status fill, the icon, the sentence and one quiet action. No coloured edge.
 */
@Composable
fun EchoBanner(text: String, kind: EchoBannerKind, modifier: Modifier = Modifier, actionLabel: String? = null, onAction: () -> Unit = {}) {
    val c = EchoV2.colors
    val (fill, ink) = when (kind) {
        EchoBannerKind.Info -> c.bgSubtle to c.textSecondary
        EchoBannerKind.Warning -> c.warningSoft to c.warning
        EchoBannerKind.Error -> c.dangerSoft to c.danger
    }
    Surface(
        modifier = modifier.fillMaxWidth().semantics { liveRegion = LiveRegionMode.Polite },
        shape = RoundedCornerShape(EchoTokens.Component.cardRadius),
        color = fill,
        contentColor = c.textPrimary,
        border = BorderStroke(EchoTokens.Border.hairline, c.borderDefault)
    ) {
        Row(
            Modifier.padding(
                start = EchoTokens.Space.s12,
                end = EchoTokens.Space.s4,
                top = EchoTokens.Space.s4,
                bottom = EchoTokens.Space.s4
            ),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s12)
        ) {
            Icon(EchoIcons.Error, null, Modifier.size(EchoTokens.Icon.md), tint = ink)
            Text(text, style = EchoV2.type.meta, modifier = Modifier.weight(1f).padding(vertical = EchoTokens.Space.s8))
            if (actionLabel != null) EchoButton(actionLabel, onAction, kind = EchoButtonKind.Quiet)
        }
    }
}
