package com.echoledger.mobile.ui.v2.live

import android.content.res.Resources
import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.LinearEasing
import androidx.compose.animation.core.tween
import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.style.TextOverflow
import com.echoledger.mobile.R
import com.echoledger.mobile.core.SourceApp
import com.echoledger.mobile.data.LocalRecording
import com.echoledger.mobile.ui.v2.components.EchoAvatar
import com.echoledger.mobile.ui.v2.components.EchoButton
import com.echoledger.mobile.ui.v2.components.EchoButtonKind
import com.echoledger.mobile.ui.v2.components.EchoIcons
import com.echoledger.mobile.ui.v2.components.formatClock
import com.echoledger.mobile.ui.v2.library.LibraryModel
import com.echoledger.mobile.ui.v2.shell.sourceName
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.theme.EchoV2Colors
import com.echoledger.mobile.ui.v2.theme.LocalEchoV2Colors
import com.echoledger.mobile.ui.v2.tokens.EchoTokens

/** What the card is showing: asking, saved (Undo keeps it waiting again), or deleted (Undo brings it back). */
enum class AfterCallMode { Ask, Saved, Deleted }

/** "Call with Omar Khan", else the number, else the source ("WhatsApp"), in the screen's language. */
fun afterCallTitle(res: Resources, rec: LocalRecording): String = rec.title?.takeIf { it.isNotBlank() }
    ?: rec.callerName?.takeIf { it.isNotBlank() }?.let { res.getString(R.string.v2_live_call_with, it) }
    ?: rec.callerNumber?.takeIf { it.any(Char::isDigit) }?.let { res.getString(R.string.v2_live_call_with, "⁦$it⁩") }
    ?: sourceName(res, rec.sourceApp)

/**
 * The after-call card on the v2 components (behind `ui_v2.after_call`, docs/PLAN_V1_8.md 1.1): the source, "Omar Khan ·
 * 4:12" (tap to rename or pick a project), and Delete · Save audio · Transcribe, with a thin line for the time left.
 * A component only: the after-call window draws it once the phone core wires it. [onDelete] is meant for v1.1's
 * delayed delete (P-003): the host keeps the call as "discarding", shows [AfterCallMode.Deleted] with Undo, and nothing
 * is gone until that time ends. [onTranscribe] is null until the host can transcribe from here.
 * It sits over the end-call screen, so it always uses the dark roles.
 */
@Composable
fun V2AfterCallCard(
    title: String,
    durationMs: Long,
    sourceWire: String?,
    mode: AfterCallMode,
    visibleMs: Long,
    onSave: () -> Unit,
    onDelete: () -> Unit,
    onUndo: () -> Unit,
    onTitle: () -> Unit,
    modifier: Modifier = Modifier,
    onTranscribe: (() -> Unit)? = null,
    countdown: Boolean = true
) {
    CompositionLocalProvider(LocalEchoV2Colors provides EchoV2Colors.Dark) {
        val c = EchoV2.colors
        val left = remember(mode) { Animatable(1f) }
        LaunchedEffect(mode, visibleMs) { if (countdown) left.animateTo(0f, tween(visibleMs.toInt(), easing = LinearEasing)) }
        val duration = formatClock(durationMs)
        Surface(
            shape = RoundedCornerShape(EchoTokens.Component.cardRadius),
            color = c.bgOverlay,
            contentColor = c.textPrimary,
            border = BorderStroke(EchoTokens.Border.hairline, c.borderDefault),
            shadowElevation = EchoTokens.Space.s8,
            modifier = modifier.widthIn(max = EchoTokens.Space.s64 * 7).fillMaxWidth().semantics { liveRegion = LiveRegionMode.Polite }
        ) {
            Column {
                Row(
                    Modifier.padding(start = EchoTokens.Space.s12, end = EchoTokens.Space.s4, top = EchoTokens.Space.s8),
                    verticalAlignment = Alignment.CenterVertically,
                    horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s12)
                ) {
                    EchoAvatar(icon = LibraryModel.icon(SourceApp.from(sourceWire)))
                    Column(
                        Modifier
                            .weight(1f)
                            .clickable(
                                enabled = mode == AfterCallMode.Ask,
                                onClickLabel = stringResource(R.string.v2_live_card_rename),
                                onClick = onTitle
                            )
                            .padding(vertical = EchoTokens.Space.s4)
                    ) {
                        Text(
                            when (mode) {
                                AfterCallMode.Ask -> title
                                AfterCallMode.Saved -> stringResource(R.string.v2_live_card_saved)
                                AfterCallMode.Deleted -> stringResource(R.string.v2_live_card_discarded)
                            },
                            style = EchoV2.type.bodyStrong,
                            maxLines = 1,
                            overflow = TextOverflow.Ellipsis
                        )
                        Text(
                            if (mode == AfterCallMode.Ask) duration else "$title · $duration",
                            style = if (mode == AfterCallMode.Ask) EchoV2.type.mono else EchoV2.type.meta,
                            color = c.textSecondary,
                            maxLines = 1,
                            overflow = TextOverflow.Ellipsis
                        )
                    }
                    if (mode != AfterCallMode.Ask) EchoButton(EchoV2.strings.undo, onUndo, kind = EchoButtonKind.Quiet)
                }
                if (mode == AfterCallMode.Ask) {
                    Row(
                        Modifier.fillMaxWidth().padding(horizontal = EchoTokens.Space.s8, vertical = EchoTokens.Space.s4),
                        horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s4, Alignment.End),
                        verticalAlignment = Alignment.CenterVertically
                    ) {
                        EchoButton(
                            stringResource(R.string.v2_live_card_discard),
                            onDelete,
                            kind = EchoButtonKind.Plain,
                            icon = EchoIcons.Delete
                        )
                        EchoButton(
                            stringResource(R.string.v2_live_card_save),
                            onSave,
                            kind = if (onTranscribe == null) EchoButtonKind.Primary else EchoButtonKind.Secondary
                        )
                        onTranscribe?.let { EchoButton(stringResource(R.string.v2_live_card_transcribe), it) }
                    }
                }
                Box(Modifier.fillMaxWidth().height(EchoTokens.Border.strong).background(c.borderDefault)) {
                    Box(Modifier.fillMaxWidth(left.value).height(EchoTokens.Border.strong).background(c.accent))
                }
            }
        }
    }
}
