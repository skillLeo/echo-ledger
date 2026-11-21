package com.echoledger.mobile.ui.v2.components

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.combinedClickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.defaultMinSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.selection.selectableGroup
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Icon
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.minimumInteractiveComponentSize
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.semantics.stateDescription
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.tokens.EchoTokens

/** The eight label colours by name, in the order the picker shows them (DESIGN_SYSTEM "Label colours"). */
val LABEL_COLOUR_NAMES: List<String> = EchoTokens.labelColours.keys.toList()

/** The name of a stored label colour (#rrggbb, any letter case) when it is one of the eight, else null. */
fun labelColourName(stored: String?): String? =
    stored?.trim()?.let { s -> EchoTokens.labelColours.entries.firstOrNull { it.value.equals(s, ignoreCase = true) }?.key }

/** How one of the eight label colours draws in the current theme. */
data class EchoLabelLook(val fill: Color, val text: Color, val dot: Color)

/** The look of the label colour [name] in this theme, or null for a colour outside the eight. */
@Composable
fun labelLook(name: String?): EchoLabelLook? {
    val dark = EchoV2.colors.isDark
    return if (dark) darkLook(name) else lightLook(name)
}

private fun lightLook(name: String?): EchoLabelLook? = with(EchoTokens.Light) {
    when (name) {
        "grey" -> EchoLabelLook(labelGreyFill, labelGreyText, labelGreyDot)
        "red" -> EchoLabelLook(labelRedFill, labelRedText, labelRedDot)
        "orange" -> EchoLabelLook(labelOrangeFill, labelOrangeText, labelOrangeDot)
        "amber" -> EchoLabelLook(labelAmberFill, labelAmberText, labelAmberDot)
        "green" -> EchoLabelLook(labelGreenFill, labelGreenText, labelGreenDot)
        "teal" -> EchoLabelLook(labelTealFill, labelTealText, labelTealDot)
        "blue" -> EchoLabelLook(labelBlueFill, labelBlueText, labelBlueDot)
        "pink" -> EchoLabelLook(labelPinkFill, labelPinkText, labelPinkDot)
        else -> null
    }
}

private fun darkLook(name: String?): EchoLabelLook? = with(EchoTokens.Dark) {
    when (name) {
        "grey" -> EchoLabelLook(labelGreyFill, labelGreyText, labelGreyDot)
        "red" -> EchoLabelLook(labelRedFill, labelRedText, labelRedDot)
        "orange" -> EchoLabelLook(labelOrangeFill, labelOrangeText, labelOrangeDot)
        "amber" -> EchoLabelLook(labelAmberFill, labelAmberText, labelAmberDot)
        "green" -> EchoLabelLook(labelGreenFill, labelGreenText, labelGreenDot)
        "teal" -> EchoLabelLook(labelTealFill, labelTealText, labelTealDot)
        "blue" -> EchoLabelLook(labelBlueFill, labelBlueText, labelBlueDot)
        "pink" -> EchoLabelLook(labelPinkFill, labelPinkText, labelPinkDot)
        else -> null
    }
}

/**
 * A label's colour dot, 8 dp. One of the eight draws in its dot colour; any other stored colour (an older app, the
 * API) draws as itself with a hairline ring so it always shows; no colour at all draws the neutral ring.
 */
@Composable
fun EchoLabelDot(stored: String?, modifier: Modifier = Modifier) {
    val c = EchoV2.colors
    val look = labelLook(labelColourName(stored))
    val own = if (look == null) stored?.let { runCatching { Color(android.graphics.Color.parseColor(it.trim())) }.getOrNull() } else null
    Box(
        modifier
            .size(EchoTokens.Component.statusDotSize)
            .then(if (look == null) Modifier.border(EchoTokens.Border.hairline, c.borderStrong, CircleShape) else Modifier)
            .background(look?.dot ?: own ?: c.bgSubtle, CircleShape)
    )
}

/**
 * A list's chip in the Library's chip row (D14): its colour dot and name; selected, the accent's tint with a check. A
 * long press opens its menu ([onMenu]: Rename, Change colour, Move earlier / later, Delete). [fixed] chips (All,
 * Unread, Important) have no dot and no menu; [count] shows only when above 0 (Unread).
 */
@OptIn(ExperimentalFoundationApi::class)
@Composable
fun EchoListChip(
    name: String,
    selected: Boolean,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
    stored: String? = null,
    fixed: Boolean = false,
    count: Int? = null,
    onMenu: (() -> Unit)? = null,
    selectedLabel: String = EchoV2.strings.selected
) {
    val c = EchoV2.colors
    Surface(
        modifier = modifier
            .minimumInteractiveComponentSize()
            .combinedClickable(role = Role.Tab, onClick = onClick, onLongClick = if (fixed) null else onMenu)
            .semantics { if (selected) stateDescription = selectedLabel },
        shape = RoundedCornerShape(EchoTokens.Component.chipRadius),
        color = if (selected) c.accentSoft else c.bgSurface,
        contentColor = if (selected) c.accentText else c.textPrimary,
        border = BorderStroke(EchoTokens.Border.hairline, if (selected) c.accent else c.borderDefault)
    ) {
        Row(
            Modifier
                .defaultMinSize(minHeight = EchoTokens.Component.chipHeight)
                .padding(horizontal = EchoTokens.Space.s12),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)
        ) {
            if (selected) Icon(EchoIcons.Check, null, Modifier.size(EchoTokens.Icon.sm))
            if (!fixed) EchoLabelDot(stored)
            Text(name, style = EchoV2.type.label, maxLines = 1)
            if (count != null && count > 0) Text("$count", style = EchoV2.type.caption, color = c.textSecondary)
        }
    }
}

/** The Library's chip row: [chips] (All · Unread · Important · the person's lists) scrolling sideways, then "+". */
@Composable
fun EchoListChipRow(onAdd: () -> Unit, addLabel: String, modifier: Modifier = Modifier, chips: @Composable () -> Unit) {
    Row(
        modifier
            .fillMaxWidth()
            .horizontalScroll(rememberScrollState())
            .padding(horizontal = EchoTokens.Layout.pagePaddingMobile),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)
    ) {
        chips()
        EchoIconButton(EchoIcons.Add, addLabel, onAdd)
    }
}

/**
 * The eight label colours as one radio group: 32 dp dots in 48 dp targets, the chosen one ringed in the text colour
 * with a check. Each is named for screen readers by [names] (the colour's own word in the screen's language).
 */
@Composable
fun EchoColourPicker(value: String?, onChange: (String) -> Unit, names: Map<String, String>, modifier: Modifier = Modifier) {
    val c = EchoV2.colors
    Row(modifier.selectableGroup(), horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s4)) {
        LABEL_COLOUR_NAMES.forEach { name ->
            val chosen = name == value
            val look = labelLook(name)
            Box(
                Modifier
                    .minimumInteractiveComponentSize()
                    .selectable(selected = chosen, role = Role.RadioButton, onClick = { onChange(name) })
                    .semantics { contentDescription = names[name] ?: name },
                contentAlignment = Alignment.Center
            ) {
                Box(
                    Modifier
                        .size(EchoTokens.Space.s32)
                        .then(if (chosen) Modifier.border(EchoTokens.Border.strong, c.textPrimary, CircleShape) else Modifier)
                        .padding(EchoTokens.Space.s4)
                        .background(look?.dot ?: c.bgSubtle, CircleShape),
                    contentAlignment = Alignment.Center
                ) {
                    if (chosen) Icon(EchoIcons.Check, null, Modifier.size(EchoTokens.Icon.sm), tint = c.textOnAccent)
                }
            }
        }
    }
}

/** The words of the new-list sheet, in the screen's language. */
data class NewListWords(
    val title: String,
    val name: String,
    val colour: String,
    val create: String,
    val duplicate: (String) -> String,
    val colourNames: Map<String, String>
)

/**
 * "+" → a list's name and colour → Create (D14 §2): the name first (up to 40 letters), the next colour not in use
 * already chosen, Create only when there is a name that isn't one of [existing] (any letter case).
 */
@Composable
fun EchoNewListSheet(
    existing: List<String>,
    usedColours: Set<String>,
    words: NewListWords,
    onDismiss: () -> Unit,
    onCreate: (name: String, colour: String) -> Unit
) {
    var name by remember { mutableStateOf("") }
    var colour by remember { mutableStateOf(LABEL_COLOUR_NAMES.firstOrNull { it !in usedColours } ?: LABEL_COLOUR_NAMES.first()) }
    val trimmed = name.trim()
    val taken = existing.any { it.trim().equals(trimmed, ignoreCase = true) }
    EchoBottomSheet(onDismiss = onDismiss, title = words.title) {
        Column(
            Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s8),
            verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s16)
        ) {
            EchoTextField(
                value = name,
                onValueChange = { name = it.take(MAX_LIST_NAME) },
                label = words.name,
                error = if (taken) words.duplicate(trimmed) else null
            )
            Text(words.colour, style = EchoV2.type.label, color = EchoV2.colors.textSecondary)
            EchoColourPicker(colour, { colour = it }, words.colourNames)
            EchoButton(
                words.create,
                { onCreate(trimmed, colour) },
                Modifier.fillMaxWidth(),
                enabled = trimmed.isNotEmpty() && !taken
            )
        }
    }
}

/** A list's name is at most this many letters. */
const val MAX_LIST_NAME = 40
