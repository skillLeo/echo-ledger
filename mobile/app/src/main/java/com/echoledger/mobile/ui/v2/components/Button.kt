package com.echoledger.mobile.ui.v2.components

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.defaultMinSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.sizeIn
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Icon
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.role
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.semantics.stateDescription
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.tokens.EchoTokens

/**
 * What a button does: the one main action, a second action, a quiet text action (accent), a plain text action (the
 * text colour, for a neutral choice such as Delete beside Save), or a destructive one.
 */
enum class EchoButtonKind { Primary, Secondary, Quiet, Plain, Danger }

private data class ButtonColors(val container: Color, val content: Color, val border: Color?)

@Composable
private fun buttonColors(kind: EchoButtonKind, enabled: Boolean): ButtonColors {
    val c = EchoV2.colors
    if (!enabled) {
        return when (kind) {
            EchoButtonKind.Quiet, EchoButtonKind.Plain -> ButtonColors(c.clear, c.textDisabled, null)
            else -> ButtonColors(c.bgSubtle, c.textDisabled, null)
        }
    }
    return when (kind) {
        EchoButtonKind.Primary -> ButtonColors(c.accent, c.textOnAccent, null)
        EchoButtonKind.Secondary -> ButtonColors(c.bgSurface, c.textPrimary, c.borderStrong)
        EchoButtonKind.Quiet -> ButtonColors(c.clear, c.accentText, null)
        EchoButtonKind.Plain -> ButtonColors(c.clear, c.textPrimary, null)
        EchoButtonKind.Danger -> ButtonColors(c.danger, c.textOnAccent, null)
    }
}

/**
 * A text button, 48 dp high (the touch minimum). While [loading] it is disabled and its label becomes [loadingText]
 * ("Loading" by default, or the task: "Uploading"), so a second tap can't send the action twice. No spinner: the only
 * looping animation in v2 is the recording dot.
 */
@Composable
fun EchoButton(
    text: String,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
    kind: EchoButtonKind = EchoButtonKind.Primary,
    enabled: Boolean = true,
    loading: Boolean = false,
    loadingText: String = EchoV2.strings.loading,
    icon: ImageVector? = null
) {
    val colors = buttonColors(kind, enabled && !loading)
    val loadingLabel = EchoV2.strings.loading
    Surface(
        onClick = onClick,
        enabled = enabled && !loading,
        modifier = modifier
            .defaultMinSize(minHeight = EchoTokens.Layout.controlTouchMin)
            .semantics {
                role = Role.Button
                if (loading) stateDescription = loadingLabel
            },
        shape = RoundedCornerShape(EchoTokens.Component.buttonRadius),
        color = colors.container,
        contentColor = colors.content,
        border = colors.border?.let { BorderStroke(EchoTokens.Border.hairline, it) }
    ) {
        Row(
            Modifier
                .defaultMinSize(minHeight = EchoTokens.Layout.controlHeightMobile)
                .padding(
                    horizontal = if (kind == EchoButtonKind.Quiet ||
                        kind == EchoButtonKind.Plain
                    ) {
                        EchoTokens.Space.s12
                    } else {
                        EchoTokens.Space.s16
                    }
                ),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8, Alignment.CenterHorizontally)
        ) {
            if (icon != null && !loading) Icon(icon, contentDescription = null, Modifier.size(EchoTokens.Icon.md))
            Text(
                if (loading) loadingText else text,
                style = EchoV2.type.label,
                textAlign = TextAlign.Center,
                modifier = Modifier.padding(vertical = EchoTokens.Space.s8)
            )
        }
    }
}

/** An icon-only button with a 48 dp touch target. [contentDescription] is required: it is the button's name. */
@Composable
fun EchoIconButton(
    icon: ImageVector,
    contentDescription: String,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
    enabled: Boolean = true,
    tint: Color = EchoV2.colors.textPrimary
) {
    Surface(
        onClick = onClick,
        enabled = enabled,
        modifier = modifier
            .sizeIn(minWidth = EchoTokens.Layout.controlTouchMin, minHeight = EchoTokens.Layout.controlTouchMin)
            .semantics { this.contentDescription = contentDescription },
        shape = CircleShape,
        color = EchoV2.colors.clear,
        contentColor = if (enabled) tint else EchoV2.colors.textDisabled
    ) {
        Column(
            Modifier.size(EchoTokens.Layout.controlTouchMin),
            verticalArrangement = Arrangement.Center,
            horizontalAlignment = Alignment.CenterHorizontally
        ) {
            Icon(icon, contentDescription = null, Modifier.size(EchoTokens.Icon.lg))
        }
    }
}

/** A labelled action with its icon above (an action bar under a recording). The label is the name. */
@Composable
fun EchoActionItem(icon: ImageVector, label: String, onClick: () -> Unit, modifier: Modifier = Modifier, danger: Boolean = false) {
    val color = if (danger) EchoV2.colors.danger else EchoV2.colors.textPrimary
    Surface(
        onClick = onClick,
        modifier = modifier.defaultMinSize(minWidth = EchoTokens.Space.s64, minHeight = EchoTokens.Space.s64),
        shape = RoundedCornerShape(EchoTokens.Component.buttonRadius),
        color = EchoV2.colors.clear,
        contentColor = color
    ) {
        Column(
            Modifier.padding(PaddingValues(horizontal = EchoTokens.Space.s8, vertical = EchoTokens.Space.s8)),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s4)
        ) {
            Icon(icon, contentDescription = null, Modifier.size(EchoTokens.Icon.lg))
            Text(label, style = EchoV2.type.caption, maxLines = 2, overflow = TextOverflow.Ellipsis, textAlign = TextAlign.Center)
        }
    }
}
