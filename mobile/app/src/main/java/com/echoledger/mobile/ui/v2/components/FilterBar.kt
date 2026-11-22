package com.echoledger.mobile.ui.v2.components

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.defaultMinSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Icon
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.minimumInteractiveComponentSize
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.unit.Dp
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.tokens.EchoTokens

/** One quick filter: a [key] the screen knows it by and the [label] people see. */
data class EchoFilter(val key: String, val label: String)

/**
 * A filter chip: 32 dp to see, 48 dp to touch. Selected chips are tinted and carry a check, so the state does not
 * rest on colour alone; screen readers hear "selected" or "not selected".
 */
@Composable
fun EchoChip(
    label: String,
    selected: Boolean,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
    enabled: Boolean = true,
    /** The chip opens a menu or sheet (Views, Source, Date…): a 16 dp chevron on its end side, never a "▾" in the text. */
    dropdown: Boolean = false,
    /** An applied filter's ✕ on the end side: it clears this chip's choices; [clearLabel] is its name for screen readers. */
    onClear: (() -> Unit)? = null,
    clearLabel: String? = null
) {
    val c = EchoV2.colors
    Surface(
        modifier = modifier
            .minimumInteractiveComponentSize()
            // A chip that opens a choice is announced as a drop-down list (as EchoSelect is), not as a checkbox.
            .then(
                if (dropdown) {
                    Modifier.clickable(enabled = enabled, role = Role.DropdownList, onClick = onClick)
                } else {
                    Modifier.selectable(selected = selected, enabled = enabled, role = Role.Checkbox, onClick = onClick)
                }
            ),
        shape = RoundedCornerShape(EchoTokens.Component.chipRadius),
        color = if (selected) c.accentSoft else c.bgSurface,
        contentColor = if (selected) c.accentText else c.textPrimary,
        border = BorderStroke(EchoTokens.Border.hairline, if (selected) c.accent else c.borderStrong)
    ) {
        Row(
            Modifier
                .defaultMinSize(minHeight = EchoTokens.Component.chipHeight)
                .padding(horizontal = EchoTokens.Space.s12),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s4)
        ) {
            if (selected) Icon(EchoIcons.Check, null, Modifier.size(EchoTokens.Icon.md))
            Text(label, style = EchoV2.type.label, maxLines = 1)
            if (dropdown) Icon(EchoIcons.Expand, null, Modifier.size(EchoTokens.Icon.sm))
            if (onClear != null) {
                Box(
                    Modifier
                        .size(EchoTokens.Icon.lg)
                        .clip(CircleShape)
                        .clickable(role = Role.Button, onClick = onClear)
                        .semantics { clearLabel?.let { contentDescription = it } },
                    contentAlignment = Alignment.Center
                ) { Icon(EchoIcons.Close, null, Modifier.size(EchoTokens.Icon.sm)) }
            }
        }
    }
}

/** A row of filter chips that scrolls sideways when they don't fit (large text, Urdu). */
@Composable
fun EchoFilterBar(
    filters: List<EchoFilter>,
    selected: Set<String>,
    onToggle: (String) -> Unit,
    modifier: Modifier = Modifier,
    contentPadding: Dp = EchoTokens.Layout.pagePaddingMobile
) {
    Row(
        modifier
            .horizontalScroll(rememberScrollState())
            .padding(PaddingValues(horizontal = contentPadding)),
        horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8),
        verticalAlignment = Alignment.CenterVertically
    ) {
        filters.forEach { filter ->
            EchoChip(filter.label, selected = filter.key in selected, onClick = { onToggle(filter.key) })
        }
    }
}
