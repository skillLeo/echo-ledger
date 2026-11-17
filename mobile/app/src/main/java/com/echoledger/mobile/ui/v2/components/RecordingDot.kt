package com.echoledger.mobile.ui.v2.components

import androidx.compose.animation.core.RepeatMode
import androidx.compose.animation.core.animateFloat
import androidx.compose.animation.core.infiniteRepeatable
import androidx.compose.animation.core.rememberInfiniteTransition
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import com.echoledger.mobile.ui.reduceMotion
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.tokens.EchoTokens

/**
 * The recording dot: the one looping animation in v2 (docs/v2/DESIGN_SYSTEM.md "Motion"). It pulses every
 * recording-dot.pulse while [live]; paused, held or with Remove animations on it is still. Red means recording only.
 */
@Composable
fun EchoRecordingDot(live: Boolean, modifier: Modifier = Modifier) {
    val c = EchoV2.colors
    val still = !live || reduceMotion()
    val alpha = if (still) {
        1f
    } else {
        val pulse = rememberInfiniteTransition(label = "recording dot")
        pulse.animateFloat(
            initialValue = 1f,
            targetValue = 0.35f,
            animationSpec = infiniteRepeatable(tween(EchoTokens.Component.recordingDotPulseMs / 2), RepeatMode.Reverse),
            label = "recording dot"
        ).value
    }
    Box(
        modifier
            .size(EchoTokens.Component.statusDotSize + EchoTokens.Space.s2)
            .alpha(alpha)
            .background(if (live) c.record else c.textSecondary, CircleShape)
    )
}
