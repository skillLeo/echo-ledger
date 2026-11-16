package com.echoledger.mobile.ui.v2.components

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.semantics.heading
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.style.TextAlign
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.tokens.EchoTokens

/**
 * What a screen says when it has nothing to show: a plain monochrome icon (no tile, no illustration), what is empty,
 * why or what to do, and at most one action.
 */
@Composable
fun EchoEmptyState(
    title: String,
    body: String,
    modifier: Modifier = Modifier,
    icon: ImageVector = EchoIcons.Empty,
    actionLabel: String? = null,
    onAction: () -> Unit = {}
) {
    val c = EchoV2.colors
    Column(
        modifier.fillMaxWidth().padding(horizontal = EchoTokens.Space.s32, vertical = EchoTokens.Space.s48),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)
    ) {
        Icon(icon, null, Modifier.size(V2Size.iconLg), tint = c.textSecondary)
        Text(
            title,
            style = EchoV2.type.titleS,
            color = c.textPrimary,
            textAlign = TextAlign.Center,
            modifier = Modifier.padding(top = EchoTokens.Space.s8).semantics { heading() }
        )
        Text(body, style = EchoV2.type.meta, color = c.textSecondary, textAlign = TextAlign.Center)
        if (actionLabel != null) {
            EchoButton(actionLabel, onAction, kind = EchoButtonKind.Secondary, modifier = Modifier.padding(top = EchoTokens.Space.s8))
        }
    }
}
