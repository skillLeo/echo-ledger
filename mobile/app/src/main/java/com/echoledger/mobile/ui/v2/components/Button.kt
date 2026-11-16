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
import androidx.compose.material3.CircularProgressIndicator
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

/** What a button does: the one main action, a second action, a quiet text action, or a destructive one. */
enum class EchoButtonKind { Primary, Secondary, Quiet, Danger }

private data class ButtonColors(val container: Color, val content: Color, val border: Color?)

@Composable
private fun buttonColors(kind: EchoButtonKind, enabled: Boolean): ButtonColors {
    val c = EchoV2.colors
    if (!enabled) {
        return when (kind) {
            EchoButtonKind.Quiet -> ButtonColors(Color.Transparent, c.textDisabled, null)
            else -> ButtonColors(c.bgSubtle, c.textDisabled, null)
        }
    }
    return when (kind) {
        EchoButtonKind.Primary -> ButtonColors(c.accent, c.textOnAccent, null)
        EchoButtonKind.Secondary -> ButtonColors(c.bgSurface, c.textPrimary, c.borderStrong)
        EchoButtonKind.Quiet -> ButtonColors(Color.Transparent, c.accentText, null)
        EchoButtonKind.Danger -> ButtonColors(c.danger, c.textOnAccent, null)
    }
}

/**
 * A text button, 48 dp high (the touch minimum). While [loading] it shows a spinner, says "Loading" to screen readers
 * and ignores taps, so a second tap can't send the action twice.
 */
@Composable
fun EchoButton(
    text: String,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
    kind: EchoButtonKind = EchoButtonKind.Primary,
    enabled: Boolean = true,
    loading: Boolean = false,
    icon: ImageVector? = null
) {
    val colors = buttonColors(kind, enabled)
    val loadingLabel = EchoV2.strings.loading
    Surface(
        onClick = { if (!loading) onClick() },
        enabled = enabled,
        modifier = modifier
            .defaultMinSize(minHeight = EchoTokens.Layout.controlTouchMin)
            .semantics {
                role = Role.Button
                if (loading) stateDescription = loadingLabel
            },
        shape = RoundedCornerShape(EchoTokens.Component.buttonRadius),
        color = colors.container,
        contentColor = colors.content,
        border = colors.border?.let { BorderStroke(V2Size.hairline, it) }
    ) {
        Row(
            Modifier
                .defaultMinSize(minHeight = EchoTokens.Layout.controlHeightMobile)
                .padding(horizontal = if (kind == EchoButtonKind.Quiet) EchoTokens.Space.s12 else EchoTokens.Space.s16),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8, Alignment.CenterHorizontally)
        ) {
            when {
                loading -> CircularProgressIndicator(
                    Modifier.size(V2Size.spinner),
                    color = colors.content,
                    strokeWidth = V2Size.focusRing
                )

                icon != null -> Icon(icon, contentDescription = null, Modifier.size(V2Size.iconSm))
            }
            Text(
                text,
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
        color = Color.Transparent,
        contentColor = if (enabled) tint else EchoV2.colors.textDisabled
    ) {
        Column(
            Modifier.size(EchoTokens.Layout.controlTouchMin),
            verticalArrangement = Arrangement.Center,
            horizontalAlignment = Alignment.CenterHorizontally
        ) {
            Icon(icon, contentDescription = null, Modifier.size(V2Size.icon))
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
        color = Color.Transparent,
        contentColor = color
    ) {
        Column(
            Modifier.padding(PaddingValues(horizontal = EchoTokens.Space.s8, vertical = EchoTokens.Space.s8)),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s4)
        ) {
            Icon(icon, contentDescription = null, Modifier.size(V2Size.icon))
            Text(label, style = EchoV2.type.caption, maxLines = 2, overflow = TextOverflow.Ellipsis, textAlign = TextAlign.Center)
        }
    }
}
