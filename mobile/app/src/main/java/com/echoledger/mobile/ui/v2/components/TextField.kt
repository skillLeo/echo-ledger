package com.echoledger.mobile.ui.v2.components

import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.interaction.collectIsFocusedAsState
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.defaultMinSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.semantics.error
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.input.ImeAction
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.tokens.EchoTokens

/**
 * A labelled text field. The label sits above the box (it never floats away), [supportingText] or [error] below it.
 * The label, the value and the error are read together by screen readers; the field is at least 48 dp high.
 */
@Composable
fun EchoTextField(
    value: String,
    onValueChange: (String) -> Unit,
    label: String,
    modifier: Modifier = Modifier,
    placeholder: String? = null,
    supportingText: String? = null,
    error: String? = null,
    enabled: Boolean = true,
    singleLine: Boolean = true,
    leadingIcon: ImageVector? = null,
    keyboardOptions: KeyboardOptions = KeyboardOptions.Default,
    keyboardActions: KeyboardActions = KeyboardActions.Default
) {
    val c = EchoV2.colors
    val type = EchoV2.type
    val interaction = remember { MutableInteractionSource() }
    val focused by interaction.collectIsFocusedAsState()
    val borderColor = when {
        error != null -> c.danger
        focused -> c.accentFocus
        else -> c.borderStrong
    }
    val borderWidth = if (focused || error != null) V2Size.focusRing else V2Size.hairline
    BasicTextField(
        value = value,
        onValueChange = onValueChange,
        enabled = enabled,
        singleLine = singleLine,
        textStyle = type.body.copy(color = if (enabled) c.textPrimary else c.textDisabled),
        cursorBrush = SolidColor(c.accent),
        keyboardOptions = keyboardOptions,
        keyboardActions = keyboardActions,
        interactionSource = interaction,
        modifier = modifier
            .fillMaxWidth()
            .semantics { if (error != null) error(error) },
        decorationBox = { inner ->
            Column(verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s4)) {
                Text(label, style = type.label, color = c.textPrimary)
                Row(
                    Modifier
                        .fillMaxWidth()
                        .defaultMinSize(minHeight = EchoTokens.Layout.controlHeightMobile)
                        .background(c.bgSurface, RoundedCornerShape(EchoTokens.Component.fieldRadius))
                        .border(borderWidth, borderColor, RoundedCornerShape(EchoTokens.Component.fieldRadius))
                        .padding(horizontal = EchoTokens.Space.s12, vertical = EchoTokens.Space.s8),
                    verticalAlignment = Alignment.CenterVertically,
                    horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)
                ) {
                    leadingIcon?.let { Icon(it, null, Modifier.size(V2Size.iconSm), tint = c.textSecondary) }
                    Box(Modifier.weight(1f)) {
                        if (value.isEmpty() && placeholder != null) {
                            Text(placeholder, style = type.body, color = c.textSecondary, maxLines = 1)
                        }
                        inner()
                    }
                }
                when {
                    error != null -> Row(horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s4)) {
                        Icon(EchoIcons.Error, null, Modifier.size(V2Size.iconSm), tint = c.danger)
                        Text(error, style = type.meta, color = c.danger)
                    }

                    supportingText != null -> Text(supportingText, style = type.meta, color = c.textSecondary)
                }
            }
        }
    )
}

/**
 * The search field of Library, Ask and Settings: a filled box with the search icon, the [placeholder] as its name, and
 * a clear button once something is typed.
 */
@Composable
fun EchoSearchField(
    query: String,
    onQueryChange: (String) -> Unit,
    placeholder: String,
    modifier: Modifier = Modifier,
    onSearch: (String) -> Unit = {}
) {
    val c = EchoV2.colors
    val type = EchoV2.type
    val interaction = remember { MutableInteractionSource() }
    val focused by interaction.collectIsFocusedAsState()
    BasicTextField(
        value = query,
        onValueChange = onQueryChange,
        singleLine = true,
        textStyle = type.body.copy(color = c.textPrimary),
        cursorBrush = SolidColor(c.accent),
        keyboardOptions = KeyboardOptions(imeAction = ImeAction.Search),
        keyboardActions = KeyboardActions(onSearch = { onSearch(query) }),
        interactionSource = interaction,
        modifier = modifier.fillMaxWidth(),
        decorationBox = { inner ->
            Row(
                Modifier
                    .fillMaxWidth()
                    .defaultMinSize(minHeight = EchoTokens.Layout.controlHeightMobile)
                    .background(c.bgSubtle, RoundedCornerShape(EchoTokens.Component.fieldRadius))
                    .border(
                        if (focused) V2Size.focusRing else V2Size.hairline,
                        if (focused) c.accentFocus else c.bgSubtle,
                        RoundedCornerShape(EchoTokens.Component.fieldRadius)
                    )
                    .padding(start = EchoTokens.Space.s12),
                verticalAlignment = Alignment.CenterVertically,
                horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)
            ) {
                Icon(EchoIcons.Search, null, Modifier.size(V2Size.icon), tint = c.textSecondary)
                Box(Modifier.weight(1f)) {
                    if (query.isEmpty()) Text(placeholder, style = type.body, color = c.textSecondary, maxLines = 1)
                    inner()
                }
                if (query.isNotEmpty()) {
                    EchoIconButton(EchoIcons.Close, EchoV2.strings.clearSearch, onClick = { onQueryChange("") }, tint = c.textSecondary)
                }
            }
        }
    )
}
