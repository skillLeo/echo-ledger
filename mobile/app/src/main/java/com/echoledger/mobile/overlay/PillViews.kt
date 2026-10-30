package com.echoledger.mobile.overlay

import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.gestures.detectTapGestures
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.IntrinsicSize
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.rounded.ClosedCaption
import androidx.compose.material.icons.rounded.ClosedCaptionDisabled
import androidx.compose.material.icons.rounded.Delete
import androidx.compose.material.icons.rounded.Pause
import androidx.compose.material.icons.rounded.PlayArrow
import androidx.compose.material.icons.rounded.RemoveCircleOutline
import androidx.compose.material.icons.rounded.Stop
import androidx.compose.material.icons.rounded.VisibilityOff
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.onClick
import androidx.compose.ui.semantics.role
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.echoledger.mobile.recording.PillEvent
import com.echoledger.mobile.recording.PillMachine
import com.echoledger.mobile.recording.PillState
import com.echoledger.mobile.ui.design.EchoType
import com.echoledger.mobile.ui.design.tabular

/** The pill's own palette: it floats over other apps, so it is always dark whatever the app's theme. */
private val PILL_BG = Color(0xEB111113)
private val PILL_LINE = Color(0x1AFFFFFF)
private val PILL_TEXT = Color(0xFFEDEEF0)
private val PILL_MUTED = Color(0xFFB0B4BA)
private val PILL_BUTTON = Color(0x14FFFFFF)
internal val PILL_REC = Color(0xFFE5484D)
private val PILL_WARN = Color(0xFFFFCA16)

/** The 8 dp recording dot: pulses while recording, grey and still while paused or on hold. */
@Composable
private fun RecDot(live: Boolean) {
    val alpha = if (live) {
        val t = rememberInfiniteTransition(label = "rec")
        t.animateFloat(1f, 0.35f, infiniteRepeatable(tween(900), RepeatMode.Reverse), label = "dot").value
    } else {
        1f
    }
    Box(Modifier.size(8.dp).alpha(alpha).background(if (live) PILL_REC else PILL_MUTED, CircleShape))
}

/** The collapsed pill (since v1.3 design): 40 dp, near-black at 92 %, a hairline border, the dot and a tabular clock. */
@Composable
internal fun PillView(s: PillState, modifier: Modifier = Modifier, onEvent: (PillEvent) -> Unit = {}) {
    val now = System.currentTimeMillis()
    val type = EchoType.Bundled
    Row(
        modifier
            .height(40.dp)
            .background(PILL_BG, CircleShape)
            .border(1.dp, PILL_LINE, CircleShape)
            .padding(horizontal = 14.dp)
            .semantics { contentDescription = "Recording ${PillMachine.clock(s.elapsedMs)}. Tap for the controls." },
        verticalAlignment = Alignment.CenterVertically
    ) {
        if (s.discarding) {
            Text("Discarded", style = type.labelM, color = PILL_TEXT)
            Spacer(Modifier.width(10.dp))
            Text(
                "Undo ${s.undoSeconds(now)}",
                style = type.labelL.tabular(),
                color = PILL_WARN,
                modifier = Modifier.clickable(role = Role.Button, onClickLabel = "Undo the discard") { onEvent(PillEvent.Undo) }
            )
        } else {
            RecDot(live = !s.paused && !s.held)
            Spacer(Modifier.width(8.dp))
            Text(if (s.held) "On hold" else PillMachine.clock(s.elapsedMs), style = type.labelL.tabular(), color = PILL_TEXT)
            if (s.warning != null) {
                Spacer(Modifier.width(6.dp))
                Text("!", style = type.labelL, color = PILL_WARN)
            }
        }
    }
}

/** Minimised: a 20 dp dot at the edge (grey while paused). */
@Composable
internal fun PillDot(s: PillState, modifier: Modifier = Modifier) {
    Box(
        modifier
            .size(20.dp)
            .background(if (s.paused) PILL_MUTED else PILL_REC, CircleShape)
            .border(2.dp, PILL_BG, CircleShape)
            .semantics { contentDescription = "Recording. Tap for the controls." }
    )
}

/**
 * The open pill: a floating toolbar of 44 dp buttons (Captions · Pause · Stop · Discard · Shrink · Hide) under the clock
 * and the language. A long-press shows a button's name; Stop asks for a second tap, because the toolbar lies over the
 * call app's own buttons.
 */
@Composable
internal fun PillToolbar(s: PillState, modifier: Modifier = Modifier, header: Modifier = Modifier, onEvent: (PillEvent) -> Unit = {}) {
    val type = EchoType.Bundled
    var hint by remember { mutableStateOf<String?>(null) }
    Column(
        modifier
            .width(IntrinsicSize.Max)
            .background(PILL_BG, RoundedCornerShape(22.dp))
            .border(1.dp, PILL_LINE, RoundedCornerShape(22.dp))
            .padding(6.dp)
    ) {
        Row(header.padding(start = 10.dp, end = 4.dp, top = 4.dp, bottom = 2.dp), verticalAlignment = Alignment.CenterVertically) {
            RecDot(live = !s.paused)
            Spacer(Modifier.width(8.dp))
            Text(
                if (s.paused) "Paused · ${PillMachine.clock(s.elapsedMs)}" else PillMachine.clock(s.elapsedMs),
                style = type.labelL.tabular(),
                color = PILL_TEXT,
                modifier = Modifier.weight(1f)
            )
            Text(
                s.language,
                style = type.labelM.copy(fontWeight = FontWeight.SemiBold),
                color = PILL_TEXT,
                modifier = Modifier
                    .border(1.dp, PILL_LINE, CircleShape)
                    .clickable(role = Role.Button, onClickLabel = "Change the language") { onEvent(PillEvent.NextLanguage) }
                    .padding(horizontal = 10.dp, vertical = 6.dp)
            )
        }
        val message = when {
            s.stopArmed -> "Tap again to stop recording"
            hint != null -> hint
            else -> s.warning
        }
        if (message != null) {
            Text(
                message,
                style = type.labelM,
                color = if (s.stopArmed) {
                    PILL_REC
                } else if (hint != null) {
                    PILL_MUTED
                } else {
                    PILL_WARN
                },
                modifier = Modifier.padding(horizontal = 10.dp, vertical = 2.dp)
            )
        }
        val showHint: (String) -> Unit = { hint = it }
        val captionsIcon = if (s.captions) Icons.Rounded.ClosedCaptionDisabled else Icons.Rounded.ClosedCaption
        val pauseIcon = if (s.paused) Icons.Rounded.PlayArrow else Icons.Rounded.Pause
        Row(Modifier.padding(top = 2.dp)) {
            PillButton(captionsIcon, if (s.captions) "Hide captions" else "Show captions", showHint) { onEvent(PillEvent.ToggleCaptions) }
            PillButton(pauseIcon, if (s.paused) "Resume" else "Pause", showHint) { onEvent(PillEvent.TogglePause) }
            PillButton(Icons.Rounded.Stop, if (s.stopArmed) "Tap again to stop recording" else "Stop", showHint, PILL_REC, s.stopArmed) {
                onEvent(PillEvent.Stop)
            }
            PillButton(Icons.Rounded.Delete, "Discard now", showHint, PILL_WARN) {
                onEvent(PillEvent.DiscardNow(System.currentTimeMillis()))
            }
            PillButton(Icons.Rounded.RemoveCircleOutline, "Shrink to a dot", showHint) { onEvent(PillEvent.ShrinkToDot) }
            PillButton(Icons.Rounded.VisibilityOff, "Hide controls (keeps recording)", showHint) { onEvent(PillEvent.HideControls) }
        }
    }
}

/** One 44 dp toolbar button; TalkBack reads [label], a long-press shows it. */
@Composable
private fun PillButton(
    icon: ImageVector,
    label: String,
    onHint: (String) -> Unit,
    tint: Color = PILL_TEXT,
    armed: Boolean = false,
    onTap: () -> Unit
) {
    Box(
        Modifier
            .size(44.dp)
            .background(if (armed) PILL_REC.copy(alpha = 0.22f) else Color.Transparent, CircleShape)
            .pointerInput(label) { detectTapGestures(onTap = { onTap() }, onLongPress = { onHint(label) }) }
            .semantics {
                contentDescription = label
                role = Role.Button
                onClick {
                    onTap()
                    true
                }
            },
        contentAlignment = Alignment.Center
    ) {
        Box(Modifier.size(36.dp).background(PILL_BUTTON, CircleShape), contentAlignment = Alignment.Center) {
            Icon(icon, contentDescription = null, tint = tint, modifier = Modifier.size(20.dp))
        }
    }
}
