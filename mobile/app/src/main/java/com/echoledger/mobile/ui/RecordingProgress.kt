package com.echoledger.mobile.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.semantics.LiveRegionMode
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.liveRegion
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import com.echoledger.mobile.core.RecordingSteps
import com.echoledger.mobile.ui.design.ButtonKind
import com.echoledger.mobile.ui.design.EchoButton

/**
 * The recording page while a recording is on its way (since v1.6, P5.4, as on the desktop): Upload · Transcribe ·
 * Improve with the running one named, one line that says what it is doing with a bar, and "Notify me when ready".
 */
@Composable
fun RecordingStepsBlock(itemId: String, steps: RecordingSteps.Steps, onNotify: () -> Unit, modifier: Modifier = Modifier) {
    val c = LocalEchoColors.current
    val type = LocalEchoType.current
    var asked by rememberSaveable(itemId) { mutableStateOf(false) }
    Column(modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(Space.s2)) {
        Row(
            Modifier.semantics {
                contentDescription = "Progress: " + RecordingSteps.Step.entries.joinToString(", ") { steps.label(it) }
            },
            horizontalArrangement = Arrangement.spacedBy(Space.s3),
            verticalAlignment = Alignment.CenterVertically
        ) {
            RecordingSteps.Step.entries.forEach { step ->
                val state = steps.states.getValue(step)
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Box(
                        Modifier.size(8.dp).clip(CircleShape).background(
                            when (state) {
                                RecordingSteps.State.DONE -> Brand.Success
                                RecordingSteps.State.ACTIVE -> Brand.AccentText
                                RecordingSteps.State.FAILED -> Brand.Danger
                                RecordingSteps.State.WAITING -> c.textMuted.copy(alpha = 0.35f)
                            }
                        )
                    )
                    Spacer(Modifier.width(6.dp))
                    Text(
                        steps.label(step),
                        style = type.bodyM,
                        color = if (state == RecordingSteps.State.ACTIVE) c.text else c.textMuted,
                        fontWeight = if (state == RecordingSteps.State.ACTIVE) FontWeight.SemiBold else FontWeight.Normal
                    )
                }
            }
        }
        // The line says what the running step is doing; it is left out when it would only repeat the step's name.
        val running = RecordingSteps.Step.entries.firstOrNull { steps.states[it] == RecordingSteps.State.ACTIVE }
        if (running == null || steps.line != steps.label(running)) {
            Text(steps.line, style = type.bodyM, color = c.textMuted, modifier = Modifier.semantics { liveRegion = LiveRegionMode.Polite })
        }
        ProgressBar(steps.percent)
        val transcriptOnItsWay = steps.states[RecordingSteps.Step.UPLOAD] == RecordingSteps.State.ACTIVE ||
            steps.states[RecordingSteps.Step.TRANSCRIBE] == RecordingSteps.State.ACTIVE
        if (transcriptOnItsWay) {
            if (asked) {
                Text("You will get a notification when it is ready.", style = type.bodyM, color = c.textMuted)
            } else {
                EchoButton("Notify me when ready", {
                    asked = true
                    onNotify()
                }, kind = ButtonKind.Text)
            }
        }
    }
}

/** The slim "Improving Roman Urdu… 60%" bar over a transcript that can be read already (since v1.6, P5.4). */
@Composable
fun ImprovingBar(steps: RecordingSteps.Steps, modifier: Modifier = Modifier) {
    val c = LocalEchoColors.current
    Column(modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(4.dp)) {
        Text(steps.line, style = LocalEchoType.current.bodyM, color = c.textMuted)
        ProgressBar(steps.percent)
    }
}

@Composable
private fun ProgressBar(percent: Int?) {
    val modifier = Modifier.fillMaxWidth().height(3.dp).clip(CircleShape)
    if (percent == null) {
        LinearProgressIndicator(modifier, color = Brand.AccentText)
    } else {
        LinearProgressIndicator({ percent / 100f }, modifier, color = Brand.AccentText)
    }
}
