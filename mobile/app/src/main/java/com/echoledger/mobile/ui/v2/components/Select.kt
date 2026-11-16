package com.echoledger.mobile.ui.v2.components

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.defaultMinSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.Icon
import androidx.compose.material3.MenuDefaults
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.role
import androidx.compose.ui.semantics.selected
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.semantics.stateDescription
import androidx.compose.ui.text.style.TextOverflow
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.tokens.EchoTokens

/**
 * A labelled choice. The whole field opens the list (not only the arrow); screen readers hear the label, the current
 * choice and "Show choices". The chosen option carries a check.
 */
@Composable
fun <T> EchoSelect(
    label: String,
    options: List<T>,
    selected: T,
    onSelect: (T) -> Unit,
    optionLabel: (T) -> String,
    modifier: Modifier = Modifier,
    enabled: Boolean = true
) {
    val c = EchoV2.colors
    val type = EchoV2.type
    val openLabel = EchoV2.strings.openMenu
    var open by remember { mutableStateOf(false) }
    val shape = RoundedCornerShape(EchoTokens.Component.fieldRadius)
    Box(modifier.fillMaxWidth()) {
        Column(
            Modifier
                .fillMaxWidth()
                .clickable(enabled = enabled, role = Role.DropdownList, onClickLabel = openLabel) { open = true }
                .semantics(mergeDescendants = true) {
                    role = Role.DropdownList
                    stateDescription = optionLabel(selected)
                },
            verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s4)
        ) {
            Text(label, style = type.label, color = c.textPrimary)
            Row(
                Modifier
                    .fillMaxWidth()
                    .defaultMinSize(minHeight = EchoTokens.Layout.controlHeightMobile)
                    .border(
                        if (open) EchoTokens.Focus.width else EchoTokens.Border.hairline,
                        if (open) c.accentFocus else c.borderStrong,
                        shape
                    )
                    .padding(start = EchoTokens.Space.s12, end = EchoTokens.Space.s8),
                verticalAlignment = Alignment.CenterVertically
            ) {
                Text(
                    optionLabel(selected),
                    style = type.body,
                    color = if (enabled) c.textPrimary else c.textDisabled,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis,
                    modifier = Modifier.weight(1f).padding(vertical = EchoTokens.Space.s8)
                )
                Icon(EchoIcons.Expand, null, Modifier.size(EchoTokens.Icon.lg), tint = c.textSecondary)
            }
        }
        DropdownMenu(
            expanded = open,
            onDismissRequest = { open = false },
            shape = RoundedCornerShape(EchoTokens.Component.menuRadius),
            containerColor = c.bgOverlay,
            border = BorderStroke(EchoTokens.Border.hairline, c.borderDefault)
        ) {
            options.forEach { option ->
                val isSelected = option == selected
                DropdownMenuItem(
                    text = { Text(optionLabel(option), style = type.body) },
                    onClick = {
                        open = false
                        onSelect(option)
                    },
                    trailingIcon = if (isSelected) {
                        { Icon(EchoIcons.Check, null, Modifier.size(EchoTokens.Icon.lg), tint = c.accentText) }
                    } else {
                        null
                    },
                    colors = MenuDefaults.itemColors(textColor = c.textPrimary),
                    modifier = Modifier
                        .defaultMinSize(minHeight = EchoTokens.Layout.controlTouchMin)
                        .semantics { this.selected = isSelected }
                )
            }
        }
    }
}
