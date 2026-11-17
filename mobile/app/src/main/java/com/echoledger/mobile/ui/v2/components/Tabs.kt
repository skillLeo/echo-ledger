package com.echoledger.mobile.ui.v2.components

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.IntrinsicSize
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.defaultMinSize
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.selection.selectableGroup
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Icon
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.tokens.EchoTokens

/**
 * Tabs inside a page (Transcript · Summary · Ask · Moments · Details). They scroll sideways when they don't fit; the
 * selected tab has the accent underline and the strong text colour.
 */
@Composable
fun EchoTabs(tabs: List<String>, selectedIndex: Int, onSelect: (Int) -> Unit, modifier: Modifier = Modifier) {
    val c = EchoV2.colors
    Column(modifier.fillMaxWidth()) {
        Row(
            Modifier
                .fillMaxWidth()
                .horizontalScroll(rememberScrollState())
                .padding(horizontal = EchoTokens.Space.s8)
                .selectableGroup()
        ) {
            tabs.forEachIndexed { index, label ->
                val selected = index == selectedIndex
                Column(
                    Modifier
                        .width(IntrinsicSize.Max)
                        .selectable(selected = selected, role = Role.Tab, onClick = { onSelect(index) })
                        .defaultMinSize(minHeight = EchoTokens.Layout.controlTouchMin),
                    horizontalAlignment = Alignment.CenterHorizontally
                ) {
                    Text(
                        label,
                        style = EchoV2.type.label,
                        color = if (selected) c.textPrimary else c.textSecondary,
                        maxLines = 1,
                        modifier = Modifier.padding(horizontal = EchoTokens.Space.s12, vertical = EchoTokens.Space.s12)
                    )
                    Box(
                        Modifier
                            .fillMaxWidth()
                            .padding(horizontal = EchoTokens.Space.s8)
                            .height(EchoTokens.Component.tabsIndicator)
                            .background(if (selected) c.accent else EchoV2.colors.clear)
                    )
                }
            }
        }
        EchoDivider()
    }
}

/**
 * A segmented control: two to four options of one setting (System · Light · Dark), all visible at once. Each segment is
 * 48 dp high; the selected one is tinted and checked.
 */
@Composable
fun EchoSegmented(options: List<String>, selectedIndex: Int, onSelect: (Int) -> Unit, modifier: Modifier = Modifier) {
    val c = EchoV2.colors
    Surface(
        modifier = modifier.fillMaxWidth(),
        shape = RoundedCornerShape(EchoTokens.Component.buttonRadius),
        color = c.bgSurface,
        border = BorderStroke(EchoTokens.Border.hairline, c.borderStrong)
    ) {
        val largeText = LocalDensity.current.fontScale >= LARGE_TEXT_SCALE
        Row(Modifier.height(IntrinsicSize.Min).selectableGroup()) {
            options.forEachIndexed { index, label ->
                val selected = index == selectedIndex
                if (index > 0) Box(Modifier.width(EchoTokens.Border.hairline).fillMaxHeight().background(c.borderStrong))
                Row(
                    Modifier
                        .weight(1f)
                        .fillMaxHeight()
                        .defaultMinSize(minHeight = EchoTokens.Layout.controlTouchMin)
                        .background(if (selected) c.accentSoft else c.bgSurface)
                        .selectable(selected = selected, role = Role.RadioButton, onClick = { onSelect(index) })
                        .padding(horizontal = EchoTokens.Space.s8, vertical = EchoTokens.Space.s8),
                    horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s4, Alignment.CenterHorizontally),
                    verticalAlignment = Alignment.CenterVertically
                ) {
                    // At large text sizes a third of the width can't hold the check and a word, so the tint alone marks
                    // the choice there (screen readers still hear "selected").
                    if (selected && !largeText) Icon(EchoIcons.Check, null, Modifier.size(EchoTokens.Icon.md), tint = c.accentText)
                    Text(
                        label,
                        style = EchoV2.type.label,
                        color = if (selected) c.accentText else c.textPrimary,
                        textAlign = TextAlign.Center,
                        maxLines = 2,
                        overflow = TextOverflow.Ellipsis
                    )
                }
            }
        }
    }
}

/** One destination of the bottom bar. */
data class EchoDestination(val key: String, val label: String, val icon: ImageVector)

/**
 * The phone's bottom bar (Home · Library · Live · Ask). Labels always show; the selected destination has the strong
 * text colour and a tinted indicator behind its icon.
 */
@Composable
fun EchoBottomBar(destinations: List<EchoDestination>, selectedKey: String, onSelect: (String) -> Unit, modifier: Modifier = Modifier) {
    val c = EchoV2.colors
    Column(modifier.fillMaxWidth().background(c.bgSurface)) {
        EchoDivider()
        Row(Modifier.fillMaxWidth().navigationBarsPadding().selectableGroup()) {
            destinations.forEach { d ->
                val selected = d.key == selectedKey
                Column(
                    Modifier
                        .weight(1f)
                        .selectable(selected = selected, role = Role.Tab, onClick = { onSelect(d.key) })
                        .defaultMinSize(minHeight = EchoTokens.Space.s64)
                        .padding(vertical = EchoTokens.Space.s8),
                    horizontalAlignment = Alignment.CenterHorizontally,
                    verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s4, Alignment.CenterVertically)
                ) {
                    Box(
                        Modifier
                            .background(if (selected) c.accentSoft else c.bgSurface, RoundedCornerShape(EchoTokens.Component.chipRadius))
                            .padding(horizontal = EchoTokens.Space.s16, vertical = EchoTokens.Space.s4),
                        contentAlignment = Alignment.Center
                    ) {
                        Icon(d.icon, null, Modifier.size(EchoTokens.Icon.lg), tint = if (selected) c.accentText else c.textSecondary)
                    }
                    Text(
                        d.label,
                        style = EchoV2.type.captionStrong,
                        color = if (selected) c.textPrimary else c.textSecondary,
                        maxLines = 1
                    )
                }
            }
        }
    }
}

/**
 * The top bar of a page: an optional back button, the title, and up to two actions at the end. Back mirrors in
 * right-to-left.
 */
@Composable
fun EchoTopBar(
    title: String,
    modifier: Modifier = Modifier,
    onBack: (() -> Unit)? = null,
    large: Boolean = false,
    actions: @Composable () -> Unit = {}
) {
    val c = EchoV2.colors
    Row(
        modifier
            .fillMaxWidth()
            .background(c.bgCanvas)
            .defaultMinSize(minHeight = EchoTokens.Space.s64)
            .padding(start = if (onBack != null) EchoTokens.Space.s4 else EchoTokens.Layout.pagePaddingMobile, end = EchoTokens.Space.s4),
        verticalAlignment = Alignment.CenterVertically
    ) {
        if (onBack != null) EchoIconButton(EchoIcons.Back, EchoV2.strings.back, onBack)
        Text(
            title,
            style = if (large) EchoV2.type.titleL else EchoV2.type.titleM,
            color = c.textPrimary,
            maxLines = 1,
            overflow = TextOverflow.Ellipsis,
            modifier = Modifier
                .weight(1f)
                .padding(start = if (onBack != null) EchoTokens.Space.s4 else EchoTokens.Space.s0)
                .semantics { heading() }
        )
        actions()
    }
}

/** From this text size on (Android's 150 %), controls drop decoration to keep their words whole. */
private const val LARGE_TEXT_SCALE = 1.5f
