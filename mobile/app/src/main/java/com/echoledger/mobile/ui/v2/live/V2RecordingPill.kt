package com.echoledger.mobile.ui.v2.live

import androidx.compose.foundation.BorderStroke
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.defaultMinSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.selected
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.style.TextOverflow
import com.echoledger.mobile.R
import com.echoledger.mobile.recording.PillEvent
import com.echoledger.mobile.recording.PillMachine
import com.echoledger.mobile.recording.PillState
import com.echoledger.mobile.ui.v2.components.EchoIcons
import com.echoledger.mobile.ui.v2.components.EchoRecordingDot
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.theme.EchoV2Colors
import com.echoledger.mobile.ui.v2.theme.LocalEchoV2Colors
import com.echoledger.mobile.ui.v2.tokens.EchoTokens

/**
 * The recording pill on the v2 components (behind `ui_v2.recording_controls`, docs/PLAN_V1_8.md 1.3): "● 02:14 · Omar".
 * It floats over other apps, so it always uses the dark roles. It draws [state] from the existing PillMachine and sends
 * its [PillEvent]s back. A component only: the pill window draws it once the phone core wires it. A tap opens Captions,
 * Pause, Language, Discard (with Undo while it counts down), Stop (two taps), Shrink to a dot, Hide controls and Hide
 * EchoLedger windows.
 */
@Composable
fun V2RecordingPill(
    state: PillState,
    nowMs: Long,
    title: String?,
    onEvent: (PillEvent) -> Unit,
    modifier: Modifier = Modifier,
    /** Hide EchoLedger windows: every floating window a grey dot until it is tapped. */
    onHideWindows: () -> Unit = { com.echoledger.mobile.core.ShareGuard.setHidden(true) }
) {
    CompositionLocalProvider(LocalEchoV2Colors provides EchoV2Colors.Dark) {
        when (state.mode) {
            PillState.Mode.HIDDEN -> Unit

            PillState.Mode.DOT -> PillDot(state, onEvent, modifier)

            PillState.Mode.PILL -> PillBar(state, nowMs, title, onEvent, modifier)

            PillState.Mode.MENU -> Column(modifier, verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)) {
                PillBar(state, nowMs, title, onEvent)
                PillMenu(state, onEvent, onHideWindows)
            }
        }
    }
}

@Composable
private fun PillDot(state: PillState, onEvent: (PillEvent) -> Unit, modifier: Modifier) {
    val c = EchoV2.colors
    val label = stringResource(R.string.v2_live_pill_recording, PillMachine.clock(state.elapsedMs))
    Surface(
        onClick = { onEvent(PillEvent.Tap) },
        shape = CircleShape,
        color = c.bgOverlay,
        border = BorderStroke(EchoTokens.Border.hairline, c.borderDefault),
        shadowElevation = EchoTokens.Space.s8,
        modifier = modifier.size(EchoTokens.Layout.controlTouchMin).semantics { contentDescription = label }
    ) {
        Box(contentAlignment = Alignment.Center) { EchoRecordingDot(live = !state.paused && !state.held) }
    }
}

@Composable
private fun PillBar(state: PillState, nowMs: Long, title: String?, onEvent: (PillEvent) -> Unit, modifier: Modifier = Modifier) {
    val c = EchoV2.colors
    val clock = PillMachine.clock(state.elapsedMs)
    val label = stringResource(R.string.v2_live_pill_recording, clock)
    Surface(
        onClick = { onEvent(if (state.discarding) PillEvent.Undo else PillEvent.Tap) },
        shape = RoundedCornerShape(EchoTokens.Component.chipRadius),
        color = c.bgOverlay,
        contentColor = c.textPrimary,
        border = BorderStroke(EchoTokens.Border.hairline, c.borderDefault),
        shadowElevation = EchoTokens.Space.s8,
        modifier = modifier
            .defaultMinSize(minHeight = EchoTokens.Layout.controlTouchMin)
            .widthIn(max = EchoTokens.Space.s64 * 5)
            .semantics { if (!state.discarding) contentDescription = label }
    ) {
        Row(
            Modifier.padding(horizontal = EchoTokens.Space.s16),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s8)
        ) {
            if (state.discarding) {
                Icon(EchoIcons.Delete, null, Modifier.size(EchoTokens.Icon.md), tint = c.textSecondary)
                Text(stringResource(R.string.v2_live_pill_discarded), style = EchoV2.type.label)
                Text(
                    stringResource(R.string.v2_live_pill_undo, state.undoSeconds(nowMs)),
                    style = EchoV2.type.label,
                    color = c.accentText,
                    modifier = Modifier.padding(vertical = EchoTokens.Space.s12)
                )
                return@Row
            }
            EchoRecordingDot(live = !state.paused && !state.held)
            Text(
                when {
                    state.held -> stringResource(R.string.v2_live_pill_on_hold)
                    state.paused -> stringResource(R.string.v2_live_pill_paused) + " · " + clock
                    else -> clock
                },
                style = EchoV2.type.mono.copy(fontSize = EchoV2.type.label.fontSize),
                modifier = Modifier.padding(vertical = EchoTokens.Space.s12)
            )
            title?.takeIf { it.isNotBlank() }?.let {
                // The dot on its own, so it sits between the clock and the name in either direction; the name keeps its own
                // script's face (Latin letters in Urdu stay in the app's Latin font).
                Text("·", style = EchoV2.type.label.copy(fontFamily = EchoV2.type.latin), color = c.textSecondary)
                Text(
                    it,
                    style = EchoV2.type.content(it, EchoV2.type.label),
                    color = c.textSecondary,
                    maxLines = 1,
                    overflow = TextOverflow.Ellipsis
                )
            }
            if (state.warning != null) Icon(EchoIcons.Error, null, Modifier.size(EchoTokens.Icon.md), tint = c.warning)
        }
    }
}

@Composable
private fun PillMenu(state: PillState, onEvent: (PillEvent) -> Unit, onHideWindows: () -> Unit) {
    val c = EchoV2.colors
    Surface(
        shape = RoundedCornerShape(EchoTokens.Component.menuRadius),
        color = c.bgOverlay,
        contentColor = c.textPrimary,
        border = BorderStroke(EchoTokens.Border.hairline, c.borderDefault),
        shadowElevation = EchoTokens.Space.s8,
        modifier = Modifier.widthIn(max = EchoTokens.Space.s64 * 5)
    ) {
        Column(Modifier.padding(vertical = EchoTokens.Space.s4)) {
            state.warning?.let {
                Text(it, style = EchoV2.type.meta, color = c.warning, modifier = Modifier.padding(horizontal = EchoTokens.Space.s16))
            }
            // A call (since 2.0.5): what happens to it, chosen here, so nothing asks after it.
            if (state.call) {
                val chosen = com.echoledger.mobile.calls.CallChoice.Pick.of(state.choice)
                MenuRow(
                    stringResource(R.string.cc_pill_transcribe),
                    icon = if (chosen == com.echoledger.mobile.calls.CallChoice.Pick.TRANSCRIBE) EchoIcons.Check else EchoIcons.Transcribe,
                    onClick = { onEvent(PillEvent.Choose(com.echoledger.mobile.calls.CallChoice.Pick.TRANSCRIBE)) },
                    selected = chosen == com.echoledger.mobile.calls.CallChoice.Pick.TRANSCRIBE
                )
                MenuRow(
                    stringResource(R.string.cc_pill_audio),
                    icon = if (chosen == com.echoledger.mobile.calls.CallChoice.Pick.AUDIO) EchoIcons.Check else EchoIcons.Mic,
                    onClick = { onEvent(PillEvent.Choose(com.echoledger.mobile.calls.CallChoice.Pick.AUDIO)) },
                    selected = chosen == com.echoledger.mobile.calls.CallChoice.Pick.AUDIO
                )
                MenuRow(
                    stringResource(R.string.cc_pill_stop_delete),
                    icon = EchoIcons.Delete,
                    onClick = { onEvent(PillEvent.DiscardNow(System.currentTimeMillis())) }
                )
                HorizontalDivider(color = c.borderDefault, modifier = Modifier.padding(vertical = EchoTokens.Space.s4))
            }
            MenuRow(
                stringResource(if (state.captions) R.string.v2_live_pill_captions_off else R.string.v2_live_pill_captions),
                // The captions' own mark: Transcribe this call has the transcript's.
                icon = EchoIcons.Captions,
                onClick = { onEvent(PillEvent.ToggleCaptions) }
            )
            MenuRow(
                stringResource(if (state.paused) R.string.v2_live_pill_resume else R.string.v2_live_pill_pause),
                icon = if (state.paused) EchoIcons.Play else EchoIcons.Pause,
                onClick = { onEvent(PillEvent.TogglePause) }
            )
            MenuRow(
                stringResource(R.string.v2_live_pill_language, state.language),
                icon = EchoIcons.Ask,
                onClick = { onEvent(PillEvent.NextLanguage) }
            )
            if (!state.call) {
                MenuRow(
                    stringResource(R.string.v2_live_pill_discard),
                    icon = EchoIcons.Delete,
                    onClick = { onEvent(PillEvent.DiscardNow(System.currentTimeMillis())) }
                )
            }
            MenuRow(
                stringResource(if (state.stopArmed) R.string.v2_live_pill_stop_again else R.string.v2_live_pill_stop),
                icon = EchoIcons.Record,
                onClick = { onEvent(PillEvent.Stop) }
            )
            MenuRow(stringResource(R.string.v2_live_pill_shrink), icon = EchoIcons.Shrink, onClick = { onEvent(PillEvent.ShrinkToDot) })
            MenuRow(stringResource(R.string.v2_live_pill_hide), icon = EchoIcons.Close, onClick = {
                onEvent(PillEvent.HideControls)
            })
            // Before a screen share (as classic since v1.8.1): every floating window becomes a grey dot until it is tapped.
            MenuRow(stringResource(R.string.v2_live_pill_hide_windows), icon = EchoIcons.HideWindows, onClick = {
                onEvent(PillEvent.CloseMenu)
                onHideWindows()
            })
        }
    }
}

/** A menu line of the pill: 48 dp, its icon and words; the whole line is the button. */
@Composable
private fun MenuRow(title: String, icon: ImageVector, onClick: () -> Unit, selected: Boolean? = null) {
    val c = EchoV2.colors
    Row(
        Modifier
            .fillMaxWidth()
            .defaultMinSize(minHeight = EchoTokens.Layout.controlTouchMin)
            .clickable(role = Role.Button, onClick = onClick)
            // A call's choice says whether it is the one chosen (TalkBack: "selected").
            .then(if (selected != null) Modifier.semantics { this.selected = selected } else Modifier)
            .padding(horizontal = EchoTokens.Space.s16),
        verticalAlignment = Alignment.CenterVertically,
        horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s16)
    ) {
        Icon(icon, null, Modifier.size(EchoTokens.Icon.md), tint = c.textSecondary)
        Text(title, style = EchoV2.type.body, color = c.textPrimary, modifier = Modifier.padding(vertical = EchoTokens.Space.s8))
    }
}
