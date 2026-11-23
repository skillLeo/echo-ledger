package com.echoledger.mobile.ui.v2.components

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
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
import androidx.compose.ui.unit.isSpecified
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.theme.largeText
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
        val largeText = largeText()
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
 * The phone's bottom bar (Library · Projects · [Record] · Live · Ask). Labels always show; the selected destination has
 * the strong text colour and a tinted indicator behind its icon. [centre] (the Record button) sits in the middle of the
 * destinations; a destination tapped again calls [onReselect] (its list scrolls to the top).
 */
@Composable
fun EchoBottomBar(
    destinations: List<EchoDestination>,
    selectedKey: String,
    onSelect: (String) -> Unit,
    modifier: Modifier = Modifier,
    onReselect: (String) -> Unit = {},
    centre: (@Composable () -> Unit)? = null
) {
    val c = EchoV2.colors
    val half = (destinations.size + 1) / 2
    // The labels grow with the text size up to 1.3×, as Material's navigation bar does: five tabs and the Record
    // button share the phone's width, so at 200 % "Library" would be cut to "Libr…". The icons carry the meaning;
    // screen readers get the full label at any size.
    val fontScale = LocalDensity.current.fontScale
    val labelStyle = EchoV2.type.captionStrong.let { s ->
        if (fontScale <= LABEL_SCALE_MAX) {
            s
        } else {
            val k = LABEL_SCALE_MAX / fontScale
            s.copy(fontSize = s.fontSize * k, lineHeight = if (s.lineHeight.isSpecified) s.lineHeight * k else s.lineHeight)
        }
    }
    Column(modifier.fillMaxWidth().background(c.bgSurface)) {
        EchoDivider()
        Row(
            Modifier.fillMaxWidth().navigationBarsPadding().selectableGroup(),
            verticalAlignment = Alignment.CenterVertically
        ) {
            destinations.forEachIndexed { i, d ->
                if (i == half && centre != null) {
                    Box(Modifier.weight(1f), contentAlignment = Alignment.Center) { centre() }
                }
                val selected = d.key == selectedKey
                Column(
                    Modifier
                        .weight(1f)
                        .selectable(selected = selected, role = Role.Tab, onClick = {
                            if (selected) onReselect(d.key) else onSelect(d.key)
                        })
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
                        style = labelStyle,
                        color = if (selected) c.textPrimary else c.textSecondary,
                        maxLines = 1,
                        overflow = TextOverflow.Ellipsis
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
    /** The title opens a menu of what the page shows (Library's "All recordings ▾"); [titleClickLabel] says what for. */
    onTitleClick: (() -> Unit)? = null,
    titleClickLabel: String? = null,
    actions: @Composable () -> Unit = {}
) {
    val c = EchoV2.colors
    val back: @Composable () -> Unit = { if (onBack != null) EchoIconButton(EchoIcons.Back, EchoV2.strings.back, onBack) }
    // A title can be someone's words (a project's or a recording's name): it takes their face and direction.
    val words: @Composable (Modifier, Int) -> Unit = { m, lines ->
        Text(
            title,
            style = EchoV2.type.content(title, if (large) EchoV2.type.titleL else EchoV2.type.titleM),
            color = c.textPrimary,
            textAlign = startAlign(),
            maxLines = lines,
            overflow = TextOverflow.Ellipsis,
            modifier = m
        )
    }
    val titleText: @Composable (Modifier, Int) -> Unit = { m, lines ->
        val start = m.padding(start = if (onBack != null) EchoTokens.Space.s4 else EchoTokens.Space.s0)
        if (onTitleClick == null) {
            words(start.semantics { heading() }, lines)
        } else {
            Box(start) {
                Row(
                    Modifier
                        .defaultMinSize(minHeight = EchoTokens.Layout.controlTouchMin)
                        .clickable(onClickLabel = titleClickLabel, role = Role.DropdownList, onClick = onTitleClick)
                        .semantics(mergeDescendants = true) { heading() },
                    verticalAlignment = Alignment.CenterVertically,
                    horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s4)
                ) {
                    words(Modifier.weight(1f, fill = false), lines)
                    Icon(EchoIcons.Expand, null, Modifier.size(EchoTokens.Icon.lg), tint = c.textSecondary)
                }
            }
        }
    }
    val padded = modifier
        .fillMaxWidth()
        .background(c.bgCanvas)
        .padding(start = if (onBack != null) EchoTokens.Space.s4 else EchoTokens.Layout.pagePaddingMobile, end = EchoTokens.Space.s4)
    // A page without a title (a recording, whose name is in its header) keeps one row at any text size.
    if (!largeText() || title.isBlank()) {
        Row(padded.defaultMinSize(minHeight = EchoTokens.Space.s64), verticalAlignment = Alignment.CenterVertically) {
            back()
            titleText(Modifier.weight(1f), 1)
            actions()
        }
    } else {
        // At 150 % text and more the title never shares its line with the actions ("Activ…" beside "Mark all as
        // read"): it takes the whole width, up to two lines, and the actions go on a line of their own under it.
        Column(padded.padding(bottom = EchoTokens.Space.s4)) {
            Row(Modifier.defaultMinSize(minHeight = EchoTokens.Space.s64), verticalAlignment = Alignment.CenterVertically) {
                back()
                titleText(Modifier.weight(1f), 2)
            }
            Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.End, verticalAlignment = Alignment.CenterVertically) {
                actions()
            }
        }
    }
}

/** The most the bottom bar's labels grow with the text size. */
private const val LABEL_SCALE_MAX = 1.3f
