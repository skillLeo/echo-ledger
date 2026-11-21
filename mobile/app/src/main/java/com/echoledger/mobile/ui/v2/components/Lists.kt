package com.echoledger.mobile.ui.v2.components

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.combinedClickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.defaultMinSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.relocation.BringIntoViewRequester
import androidx.compose.foundation.relocation.bringIntoViewRequester
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.selection.selectableGroup
import androidx.compose.foundation.selection.toggleable
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.minimumInteractiveComponentSize
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.selected
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.semantics.stateDescription
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardCapitalization
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.LayoutDirection
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.theme.ProvidePageLook
import com.echoledger.mobile.ui.v2.theme.rememberPageLook
import com.echoledger.mobile.ui.v2.tokens.EchoTokens
import java.util.Locale

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

/** A list's name is at most this many letters. */
const val MAX_LIST_NAME = 40

/**
 * The existing list or fixed chip ([reserved]: All, Unread, Important in the screen's language) with this name, if
 * there is one. Names are compared trimmed and in lower case with the root locale, as the server keys lists.
 */
fun listNameTaken(name: String, existing: List<String>, reserved: List<String> = emptyList()): String? {
    val key = name.trim().lowercase(Locale.ROOT)
    if (key.isEmpty()) return null
    return (existing + reserved).firstOrNull { it.trim().lowercase(Locale.ROOT) == key }
}

/**
 * The colour a new list starts with: the first of the eight, in the picker's order, that no list has yet; when every
 * colour is taken, the one fewest lists have (the earliest of those). [used] holds stored values (#rrggbb, any letter
 * case) or colour names; anything else is ignored. The same rule as desktop's nextListColour.
 */
fun nextListColour(used: Collection<String?>): String {
    val counts = LABEL_COLOUR_NAMES.associateWith { 0 }.toMutableMap()
    for (value in used) {
        val name = if (value != null && value in counts) value else labelColourName(value)
        if (name != null) counts[name] = counts.getValue(name) + 1
    }
    return LABEL_COLOUR_NAMES.minBy { counts.getValue(it) }
}

/** The stored value (#rrggbb) of one of the eight colours, as the server keeps it. */
fun labelColourValue(name: String): String = EchoTokens.labelColours.getValue(name)

/**
 * A list's chip in the Library's chip row (D14): its colour dot and name; selected, the accent's tint with a check, and
 * it is brought into the row's view. A long press opens its menu ([onMenu]; [menu] is drawn anchored to the chip, as
 * EchoListChipMenu). [fixed] chips (All, Unread, Important) have no dot and no menu; [count] shows only when above 0
 * (Unread). A long name ends in an ellipsis.
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
    selectedLabel: String = EchoV2.strings.selected,
    /** What screen readers call the long press ("List options"). */
    menuLabel: String? = null,
    menu: (@Composable () -> Unit)? = null
) {
    val c = EchoV2.colors
    val inView = remember { BringIntoViewRequester() }
    LaunchedEffect(selected) { if (selected) inView.bringIntoView() }
    Box(modifier.bringIntoViewRequester(inView)) {
        Surface(
            modifier = Modifier
                .minimumInteractiveComponentSize()
                .combinedClickable(
                    role = Role.Tab,
                    onClick = onClick,
                    onLongClickLabel = if (fixed) null else menuLabel,
                    onLongClick = if (fixed) null else onMenu
                )
                .semantics {
                    this.selected = selected
                    if (selected) stateDescription = selectedLabel
                },
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
                Text(
                    name,
                    Modifier.widthIn(max = EchoTokens.Space.s64 * 3),
                    style = EchoV2.type.label,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis
                )
                if (count != null && count > 0) Text("$count", style = EchoV2.type.caption, color = c.textSecondary)
            }
        }
        if (!fixed) menu?.invoke()
    }
}

/**
 * The Library's chip row: [chips] (All · Unread · Important · the person's lists) scrolling sideways, then "+". The
 * selected chip brings itself into view.
 */
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
 * The eight label colours as one radio group: 32 dp dots in 48 dp targets, wrapping when the screen is narrow; the
 * chosen one is ringed and checked in the text colour (as on desktop). Each is named for screen readers by [names].
 */
@OptIn(ExperimentalLayoutApi::class)
@Composable
fun EchoColourPicker(value: String?, onChange: (String) -> Unit, names: Map<String, String>, modifier: Modifier = Modifier) {
    val c = EchoV2.colors
    FlowRow(modifier.selectableGroup()) {
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
                        .size(EchoTokens.Space.s40)
                        .then(if (chosen) Modifier.border(EchoTokens.Border.strong, c.textPrimary, CircleShape) else Modifier),
                    contentAlignment = Alignment.Center
                ) {
                    Box(
                        Modifier
                            .size(EchoTokens.Space.s32)
                            .background(look?.dot ?: c.bgSubtle, CircleShape),
                        contentAlignment = Alignment.Center
                    ) {
                        if (chosen) Icon(EchoIcons.Check, null, Modifier.size(EchoTokens.Icon.sm), tint = c.textPrimary)
                    }
                }
            }
        }
    }
}

/** The words of the new-list sheet, in the screen's language. [duplicate] is given the existing list's own name. */
data class NewListWords(
    val title: String,
    val name: String,
    val colour: String,
    val create: String,
    val duplicate: (String) -> String,
    val colourNames: Map<String, String>
)

/** A list's name field: focused on opening, the keyboard's Done does [onDone], a name in use says so under it. */
@Composable
private fun ListNameField(name: String, onName: (String) -> Unit, label: String, error: String?, onDone: () -> Unit) {
    val focus = remember { FocusRequester() }
    // The sheet's content can be placed a frame after this effect runs; a field not yet placed just isn't focused.
    LaunchedEffect(Unit) { runCatching { focus.requestFocus() } }
    EchoTextField(
        value = name,
        onValueChange = { onName(it.take(MAX_LIST_NAME)) },
        label = label,
        modifier = Modifier.focusRequester(focus),
        error = error,
        keyboardOptions = KeyboardOptions(capitalization = KeyboardCapitalization.Sentences, imeAction = ImeAction.Done),
        keyboardActions = KeyboardActions(onDone = { onDone() })
    )
}

/**
 * "+" → a list's name and colour → Create (D14 §2): the name first (focused, up to 40 letters), the next colour already
 * chosen ([nextListColour] over [usedColours], the lists' stored colours), Create only when the name is free: not one
 * of [existing] or [reserved] in any letter case. [onCreate] gets the trimmed name and the colour as the server stores
 * it (#rrggbb). Done on the keyboard creates too.
 */
@Composable
fun EchoNewListSheet(
    existing: List<String>,
    usedColours: Collection<String?>,
    words: NewListWords,
    onDismiss: () -> Unit,
    reserved: List<String> = emptyList(),
    onCreate: (name: String, colour: String) -> Unit
) {
    var name by remember { mutableStateOf("") }
    var colour by remember { mutableStateOf(nextListColour(usedColours)) }
    val trimmed = name.trim()
    val clash = listNameTaken(name, existing, reserved)
    val ready = trimmed.isNotEmpty() && clash == null
    val create = { if (ready) onCreate(trimmed, labelColourValue(colour)) }
    EchoBottomSheet(onDismiss = onDismiss, title = words.title) {
        Column(
            Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s8),
            verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s16)
        ) {
            ListNameField(name, { name = it }, words.name, clash?.let(words.duplicate), create)
            Text(words.colour, style = EchoV2.type.label, color = EchoV2.colors.textSecondary)
            EchoColourPicker(colour, { colour = it }, words.colourNames)
            EchoButton(words.create, create, Modifier.fillMaxWidth(), enabled = ready)
        }
    }
}

/** The words of Rename…'s sheet. */
data class RenameListWords(val title: String, val name: String, val save: String, val duplicate: (String) -> String)

/**
 * Rename… on a list chip: the name field with the list's name, and the same check as a new list, so it can't take
 * another list's name or a fixed chip's word. A change of letter case alone is allowed. [onRename] gets it trimmed.
 */
@Composable
fun EchoRenameListSheet(
    current: String,
    existing: List<String>,
    words: RenameListWords,
    onDismiss: () -> Unit,
    reserved: List<String> = emptyList(),
    onRename: (String) -> Unit
) {
    var name by remember { mutableStateOf(current) }
    val self = current.trim().lowercase(Locale.ROOT)
    val clash = listNameTaken(name, existing.filterNot { it.trim().lowercase(Locale.ROOT) == self }, reserved)
    val ready = name.isNotBlank() && clash == null
    val save = { if (ready) onRename(name.trim()) }
    EchoBottomSheet(onDismiss = onDismiss, title = words.title) {
        Column(
            Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s8),
            verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s16)
        ) {
            ListNameField(name, { name = it }, words.name, clash?.let(words.duplicate), save)
            EchoButton(words.save, save, Modifier.fillMaxWidth(), enabled = ready)
        }
    }
}

/** Change colour…: the eight colours with the list's own chosen; [onSave] gets the stored value (#rrggbb). */
@Composable
fun EchoListColourSheet(
    title: String,
    stored: String?,
    names: Map<String, String>,
    saveLabel: String,
    onDismiss: () -> Unit,
    onSave: (String) -> Unit
) {
    var colour by remember { mutableStateOf(labelColourName(stored) ?: LABEL_COLOUR_NAMES.first()) }
    EchoBottomSheet(onDismiss = onDismiss, title = title) {
        Column(
            Modifier.padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s8),
            verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s16)
        ) {
            EchoColourPicker(colour, { colour = it }, names)
            EchoButton(saveLabel, { onSave(labelColourValue(colour)) }, Modifier.fillMaxWidth())
        }
    }
}

/** A list chip's menu words: the owner's "Move left" and "Move right", the same on every platform. */
data class ListChipMenuWords(val rename: String, val recolour: String, val moveLeft: String, val moveRight: String, val remove: String)

/**
 * A list chip's menu (D14 §4), opened by a long press: Rename…, Change colour…, Move left and Move right (each only
 * when there is somewhere to move), then, after a hairline and last, Delete list… in red (the caller asks first, with
 * EchoDeleteListDialog). Left and right are where the chip goes on screen: right to left, left is later in the order,
 * so the moves swap.
 */
@Composable
fun EchoListChipMenu(
    expanded: Boolean,
    onDismiss: () -> Unit,
    words: ListChipMenuWords,
    canMoveEarlier: Boolean,
    canMoveLater: Boolean,
    onRename: () -> Unit,
    onRecolour: () -> Unit,
    onMoveEarlier: () -> Unit,
    onMoveLater: () -> Unit,
    onDelete: () -> Unit
) {
    val rtl = LocalLayoutDirection.current == LayoutDirection.Rtl
    val canLeft = if (rtl) canMoveLater else canMoveEarlier
    val canRight = if (rtl) canMoveEarlier else canMoveLater
    val left = if (rtl) onMoveLater else onMoveEarlier
    val right = if (rtl) onMoveEarlier else onMoveLater
    val look = rememberPageLook()
    val c = EchoV2.colors
    DropdownMenu(expanded = expanded, onDismissRequest = onDismiss) {
        ProvidePageLook(look) {
            Column {
                ListMenuItem(EchoIcons.Rename, words.rename, c.textPrimary, onDismiss, onRename)
                ListMenuItem(EchoIcons.Palette, words.recolour, c.textPrimary, onDismiss, onRecolour)
                if (canLeft) ListMenuItem(null, words.moveLeft, c.textPrimary, onDismiss, left)
                if (canRight) ListMenuItem(null, words.moveRight, c.textPrimary, onDismiss, right)
                HorizontalDivider(thickness = EchoTokens.Border.hairline, color = c.borderDefault)
                ListMenuItem(EchoIcons.Delete, words.remove, c.danger, onDismiss, onDelete)
            }
        }
    }
}

@Composable
private fun ListMenuItem(icon: ImageVector?, words: String, tint: Color, close: () -> Unit, action: () -> Unit) {
    DropdownMenuItem(
        text = { Text(words, style = EchoV2.type.body, color = tint) },
        leadingIcon = { if (icon != null) Icon(icon, null, tint = tint) else Spacer(Modifier.size(EchoTokens.Icon.lg)) },
        onClick = {
            close()
            action()
        }
    )
}

/** The words of Delete list…'s question. [title] is given the list's name ("Delete Clients?"). */
data class DeleteListWords(val title: (String) -> String, val body: String, val confirm: String, val cancel: String)

/** Delete list…'s question, with Delete in red: the recordings stay, only the list goes. */
@Composable
fun EchoDeleteListDialog(name: String, words: DeleteListWords, onConfirm: () -> Unit, onDismiss: () -> Unit) {
    EchoDialog(
        title = words.title(name),
        text = words.body,
        confirmLabel = words.confirm,
        onConfirm = onConfirm,
        dismissLabel = words.cancel,
        onDismiss = onDismiss,
        destructive = true
    )
}

/** One of the person's lists as the Add to list sheet shows it: its name and stored colour. */
data class ListEntry(val name: String, val stored: String?)

/** The words of the Add to list sheet. */
data class AddToListWords(val title: String, val newList: String)

/**
 * ⋮ › Add to list, or a long press on a recording (D14 §3): each of the person's lists with its colour dot, ticked
 * when the recording is in it; a tap ticks or unticks and the sheet stays open, so several can be ticked in a row.
 * Then, after a hairline, New list…, which opens the new-list sheet (the recording comes back ticked in it).
 */
@Composable
fun EchoAddToListSheet(
    lists: List<ListEntry>,
    checked: Set<String>,
    words: AddToListWords,
    onToggle: (String) -> Unit,
    onNew: () -> Unit,
    onDismiss: () -> Unit
) {
    val c = EchoV2.colors
    EchoBottomSheet(onDismiss = onDismiss, title = words.title) {
        Column(Modifier.padding(vertical = EchoTokens.Space.s8)) {
            lists.forEach { list ->
                val on = list.name in checked
                Row(
                    Modifier
                        .fillMaxWidth()
                        .defaultMinSize(minHeight = EchoTokens.Layout.controlTouchMin)
                        .toggleable(value = on, role = Role.Checkbox, onValueChange = { onToggle(list.name) })
                        .padding(horizontal = EchoTokens.Layout.pagePaddingMobile),
                    verticalAlignment = Alignment.CenterVertically,
                    horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s12)
                ) {
                    EchoLabelDot(list.stored)
                    Text(list.name, Modifier.weight(1f), style = EchoV2.type.body, maxLines = 1, overflow = TextOverflow.Ellipsis)
                    if (on) Icon(EchoIcons.Check, null, Modifier.size(EchoTokens.Icon.md), tint = c.accent)
                }
            }
            if (lists.isNotEmpty()) HorizontalDivider(thickness = EchoTokens.Border.hairline, color = c.borderDefault)
            Row(
                Modifier
                    .fillMaxWidth()
                    .defaultMinSize(minHeight = EchoTokens.Layout.controlTouchMin)
                    .clickable(role = Role.Button, onClick = onNew)
                    .padding(horizontal = EchoTokens.Layout.pagePaddingMobile),
                verticalAlignment = Alignment.CenterVertically,
                horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s12)
            ) {
                Icon(EchoIcons.Add, null, Modifier.size(EchoTokens.Icon.md), tint = c.textSecondary)
                Text(words.newList, style = EchoV2.type.body)
            }
        }
    }
}
