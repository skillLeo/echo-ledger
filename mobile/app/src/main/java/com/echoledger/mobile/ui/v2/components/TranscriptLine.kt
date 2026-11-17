package com.echoledger.mobile.ui.v2.components

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.style.TextDirection
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.tokens.EchoTokens

/**
 * One line of a transcript: who spoke and when, then what they said. Speakers are told apart by name and weight, not
 * by colour. Each line gets its own direction from its text, so an Urdu line reads right to left inside an English
 * screen and the other way round.
 */
@Composable
fun EchoTranscriptLine(speaker: String, time: String, text: String, modifier: Modifier = Modifier, isMe: Boolean = false) {
    val c = EchoV2.colors
    Column(
        modifier
            .fillMaxWidth()
            .semantics(mergeDescendants = true) {}
            .padding(horizontal = EchoTokens.Layout.pagePaddingMobile, vertical = EchoTokens.Space.s12),
        verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s4)
    ) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
            Text(speaker, style = EchoV2.type.label, color = if (isMe) c.textSecondary else c.textPrimary, maxLines = 1)
            Text(time, style = EchoV2.type.mono, color = c.textSecondary)
        }
        Text(text, style = EchoV2.type.body.copy(textDirection = TextDirection.Content), color = c.textPrimary)
    }
}
