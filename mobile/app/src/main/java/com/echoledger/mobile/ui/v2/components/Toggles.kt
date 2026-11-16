package com.echoledger.mobile.ui.v2.components

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.defaultMinSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.selection.selectable
import androidx.compose.foundation.selection.toggleable
import androidx.compose.material3.Checkbox
import androidx.compose.material3.CheckboxDefaults
import androidx.compose.material3.RadioButton
import androidx.compose.material3.RadioButtonDefaults
import androidx.compose.material3.Switch
import androidx.compose.material3.SwitchDefaults
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.semantics.Role
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.tokens.EchoTokens

/** The label column shared by the switch, checkbox and radio rows. */
@Composable
private fun ToggleLabel(label: String, supporting: String?, enabled: Boolean, modifier: Modifier) {
    val c = EchoV2.colors
    Column(modifier, verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s2)) {
        Text(label, style = EchoV2.type.body, color = if (enabled) c.textPrimary else c.textDisabled)
        supporting?.let { Text(it, style = EchoV2.type.meta, color = c.textSecondary) }
    }
}

private fun Modifier.toggleRow() = fillMaxWidth()
    .defaultMinSize(minHeight = EchoTokens.Layout.rowMobile)

/**
 * A switch row. The [label] names what it controls ("Auto-transcribe after calls"), never just "On"; the whole row
 * toggles, and the change takes effect at once.
 */
@Composable
fun EchoSwitch(
    checked: Boolean,
    onCheckedChange: (Boolean) -> Unit,
    label: String,
    modifier: Modifier = Modifier,
    supporting: String? = null,
    enabled: Boolean = true
) {
    val c = EchoV2.colors
    Row(
        modifier
            .toggleRow()
            .toggleable(value = checked, enabled = enabled, role = Role.Switch, onValueChange = onCheckedChange)
            .padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s8),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s16)
    ) {
        ToggleLabel(label, supporting, enabled, Modifier.weight(1f))
        Switch(
            checked = checked,
            onCheckedChange = null,
            enabled = enabled,
            colors = SwitchDefaults.colors(
                checkedThumbColor = c.textOnAccent,
                checkedTrackColor = c.accent,
                checkedBorderColor = c.accent,
                uncheckedThumbColor = c.borderStrong,
                uncheckedTrackColor = c.bgSubtle,
                uncheckedBorderColor = c.borderStrong
            )
        )
    }
}

/** A checkbox row, for choices confirmed later with a button. The whole row toggles. */
@Composable
fun EchoCheckbox(
    checked: Boolean,
    onCheckedChange: (Boolean) -> Unit,
    label: String,
    modifier: Modifier = Modifier,
    supporting: String? = null,
    enabled: Boolean = true
) {
    val c = EchoV2.colors
    Row(
        modifier
            .toggleRow()
            .toggleable(value = checked, enabled = enabled, role = Role.Checkbox, onValueChange = onCheckedChange)
            .padding(horizontal = EchoTokens.Space.s4, vertical = EchoTokens.Space.s8),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)
    ) {
        Checkbox(
            checked = checked,
            onCheckedChange = null,
            enabled = enabled,
            colors = CheckboxDefaults.colors(checkedColor = c.accent, uncheckedColor = c.borderStrong, checkmarkColor = c.textOnAccent),
            modifier = Modifier.padding(EchoTokens.Space.s12)
        )
        ToggleLabel(label, supporting, enabled, Modifier.weight(1f))
    }
}

/** A radio row, one of a group. The whole row selects. */
@Composable
fun EchoRadio(
    selected: Boolean,
    onClick: () -> Unit,
    label: String,
    modifier: Modifier = Modifier,
    supporting: String? = null,
    enabled: Boolean = true
) {
    val c = EchoV2.colors
    Row(
        modifier
            .toggleRow()
            .selectable(selected = selected, enabled = enabled, role = Role.RadioButton, onClick = onClick)
            .padding(horizontal = EchoTokens.Space.s4, vertical = EchoTokens.Space.s8),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)
    ) {
        RadioButton(
            selected = selected,
            onClick = null,
            enabled = enabled,
            colors = RadioButtonDefaults.colors(selectedColor = c.accent, unselectedColor = c.borderStrong),
            modifier = Modifier.padding(EchoTokens.Space.s12)
        )
        ToggleLabel(label, supporting, enabled, Modifier.weight(1f))
    }
}
