package com.echoledger.mobile.ui.v2.components

import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.Slider
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.CompositionLocalProvider
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.platform.LocalLayoutDirection
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.semantics.stateDescription
import androidx.compose.ui.unit.LayoutDirection
import com.echoledger.mobile.ui.v2.theme.EchoV2
import com.echoledger.mobile.ui.v2.tokens.EchoTokens

/** The words of the player, in the screen's language. */
data class EchoPlayerLabels(
    val play: String,
    val pause: String,
    val back10: String,
    val forward10: String,
    val position: String,
    val speed: String
)

/** Formats milliseconds as m:ss or h:mm:ss, always with Latin digits (durations stay left to right). */
fun formatClock(ms: Long): String {
    val total = ms / 1000
    val h = total / 3600
    val m = (total % 3600) / 60
    val s = total % 60
    return if (h > 0) "%d:%02d:%02d".format(java.util.Locale.ROOT, h, m, s) else "%d:%02d".format(java.util.Locale.ROOT, m, s)
}

/**
 * The recording player: the seek bar with the position and the length (and its [markers]), then back 10 s, play or
 * pause, forward 10 s and the speed. Media controls never mirror: the timeline runs left to right in every language.
 */
@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun EchoPlayer(
    positionMs: Long,
    durationMs: Long,
    playing: Boolean,
    speed: String,
    labels: EchoPlayerLabels,
    onPlayPause: () -> Unit,
    onSeek: (Long) -> Unit,
    onSpeed: () -> Unit,
    modifier: Modifier = Modifier,
    /** Moments to mark on the track (bookmarks), in ms from the start; none by default. */
    markers: List<Long> = emptyList()
) {
    val c = EchoV2.colors
    val clock = "${formatClock(positionMs)} / ${formatClock(durationMs)}"
    CompositionLocalProvider(LocalLayoutDirection provides LayoutDirection.Ltr) {
        Column(modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(EchoTokens.Space.s4)) {
            Slider(
                value = if (durationMs > 0) positionMs.toFloat() / durationMs else 0f,
                onValueChange = { onSeek((it * durationMs).toLong()) },
                thumb = { Box(Modifier.size(EchoTokens.Space.s16).background(c.accent, CircleShape)) },
                track = { state ->
                    Canvas(Modifier.fillMaxWidth().height(EchoTokens.Component.sheetHandleHeight)) {
                        val y = size.height / 2
                        drawLine(c.borderDefault, Offset(0f, y), Offset(size.width, y), size.height, StrokeCap.Round)
                        drawLine(c.accent, Offset(0f, y), Offset(size.width * state.value, y), size.height, StrokeCap.Round)
                        if (durationMs > 0) {
                            markers.forEach { at ->
                                val x = size.width * (at.toFloat() / durationMs).coerceIn(0f, 1f)
                                drawCircle(c.warning, radius = size.height * 1.5f, center = Offset(x, y))
                            }
                        }
                    }
                },
                modifier = Modifier
                    .fillMaxWidth()
                    .semantics {
                        contentDescription = labels.position
                        stateDescription = clock
                    }
            )
            Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                Text(formatClock(positionMs), style = EchoV2.type.mono, color = c.textSecondary, modifier = Modifier.weight(1f))
                Text(formatClock(durationMs), style = EchoV2.type.mono, color = c.textSecondary)
            }
            Row(
                Modifier.fillMaxWidth(),
                horizontalArrangement = Arrangement.spacedBy(EchoTokens.Space.s16, Alignment.CenterHorizontally),
                verticalAlignment = Alignment.CenterVertically
            ) {
                Box(Modifier.weight(1f))
                EchoIconButton(EchoIcons.Back10, labels.back10, onClick = { onSeek((positionMs - 10_000).coerceAtLeast(0)) })
                Surface(
                    onClick = onPlayPause,
                    shape = CircleShape,
                    color = c.accent,
                    contentColor = c.textOnAccent,
                    modifier = Modifier
                        .size(EchoTokens.Space.s64)
                        .semantics { contentDescription = if (playing) labels.pause else labels.play }
                ) {
                    Box(contentAlignment = Alignment.Center) {
                        Icon(
                            if (playing) EchoIcons.Pause else EchoIcons.Play,
                            null,
                            Modifier.size(EchoTokens.Icon.lg + EchoTokens.Space.s8)
                        )
                    }
                }
                EchoIconButton(
                    EchoIcons.Forward10,
                    labels.forward10,
                    onClick = { onSeek((positionMs + 10_000).coerceAtMost(durationMs)) }
                )
                Box(Modifier.weight(1f), contentAlignment = Alignment.CenterEnd) {
                    Surface(
                        onClick = onSpeed,
                        color = c.bgSubtle,
                        contentColor = c.textPrimary,
                        shape = RoundedCornerShape(EchoTokens.Component.chipRadius),
                        modifier = Modifier
                            .size(EchoTokens.Layout.controlTouchMin + EchoTokens.Space.s8, EchoTokens.Layout.controlTouchMin)
                            .semantics { contentDescription = "${labels.speed} $speed" }
                    ) {
                        Box(contentAlignment = Alignment.Center) { Text(speed, style = EchoV2.type.mono, color = c.textPrimary) }
                    }
                }
            }
        }
    }
}
