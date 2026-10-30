package com.echoledger.mobile.ui

import android.content.Context
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.gestures.detectHorizontalDragGestures
import androidx.compose.foundation.gestures.detectTapGestures
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.navigationBarsPadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.Pause
import androidx.compose.material.icons.outlined.PlayArrow
import androidx.compose.material.icons.rounded.Forward10
import androidx.compose.material.icons.rounded.Pause
import androidx.compose.material.icons.rounded.PlayArrow
import androidx.compose.material.icons.rounded.Replay10
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.FilledIconButton
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButtonDefaults
import androidx.compose.material3.Slider
import androidx.compose.material3.SliderDefaults
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.Stable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableFloatStateOf
import androidx.compose.runtime.mutableLongStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.semantics.ProgressBarRangeInfo
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.progressBarRangeInfo
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.semantics.setProgress
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.media3.common.AudioAttributes
import androidx.media3.common.C
import androidx.media3.common.MediaItem
import androidx.media3.common.PlaybackException
import androidx.media3.common.Player
import androidx.media3.exoplayer.ExoPlayer
import com.echoledger.mobile.core.TranscriptText
import com.echoledger.mobile.ui.design.BarIcon
import com.echoledger.mobile.ui.design.EchoFilterChip
import com.echoledger.mobile.ui.design.Spinner
import com.echoledger.mobile.ui.design.tabular
import kotlinx.coroutines.delay

/**
 * Streams a recording from its temporary signed URL (docs/API.md "GET /api/sessions/{id}" -> playback_url) with
 * Media3 ExoPlayer, which handles FLAC/Opus/WAV over HTTPS with range requests. The server decrypts on the fly;
 * nothing is written to the phone.
 */
@Stable
class RecordingPlayerState(context: Context) {
    private val player: ExoPlayer = ExoPlayer.Builder(context).build().apply {
        setAudioAttributes(
            AudioAttributes.Builder().setUsage(C.USAGE_MEDIA).setContentType(C.AUDIO_CONTENT_TYPE_SPEECH).build(),
            true
        )
        addListener(object : Player.Listener {
            override fun onPlaybackStateChanged(state: Int) {
                ready = state == Player.STATE_READY || state == Player.STATE_BUFFERING
                if (state == Player.STATE_READY) durationMs = duration.coerceAtLeast(0)
                if (state == Player.STATE_ENDED) playing = false
            }

            override fun onIsPlayingChanged(isPlaying: Boolean) {
                playing = isPlaying
            }

            override fun onPlayerError(error: PlaybackException) {
                this@RecordingPlayerState.error = "This recording could not be played. Go back and open it again."
            }
        })
    }

    var ready by mutableStateOf(false)
        private set
    var playing by mutableStateOf(false)
        private set
    var positionMs by mutableLongStateOf(0L)
        private set
    var durationMs by mutableLongStateOf(0L)
        private set
    var error by mutableStateOf<String?>(null)
        private set
    var speed by mutableFloatStateOf(1f)
        private set
    var skipSilence by mutableStateOf(false)
        private set

    /** Playback speed 0.5-2x (B6). */
    fun changeSpeed(value: Float) {
        speed = value.coerceIn(0.5f, 2f)
        player.setPlaybackSpeed(speed)
    }

    /** The player's own silence skipping (B6); Media3 marks the switch as unstable API. */
    @androidx.annotation.OptIn(markerClass = [androidx.media3.common.util.UnstableApi::class])
    fun changeSkipSilence(on: Boolean) {
        skipSilence = on
        player.skipSilenceEnabled = on
    }

    /** Jumps by [deltaMs] without starting playback. */
    fun jump(deltaMs: Long) {
        val target = (player.currentPosition + deltaMs).coerceAtLeast(0)
        player.seekTo(target)
        positionMs = target
    }

    fun load(url: String) {
        error = null
        ready = false
        positionMs = 0
        durationMs = 0
        player.setMediaItem(MediaItem.fromUri(url))
        player.prepare()
    }

    fun toggle() {
        if (player.playbackState == Player.STATE_ENDED) player.seekTo(0)
        if (player.isPlaying) player.pause() else player.play()
    }

    fun seekTo(ms: Long) {
        player.seekTo(ms.coerceAtLeast(0))
        positionMs = ms.coerceAtLeast(0)
        player.play()
    }

    fun refreshPosition() {
        positionMs = player.currentPosition.coerceAtLeast(0)
    }

    fun release() = player.release()
}

@Composable
fun rememberRecordingPlayer(url: String?): RecordingPlayerState {
    val context = LocalContext.current
    val state = remember { RecordingPlayerState(context.applicationContext) }
    LaunchedEffect(url) { if (url != null) state.load(url) }
    LaunchedEffect(state.playing) {
        while (state.playing) {
            state.refreshPosition()
            delay(200)
        }
    }
    DisposableEffect(Unit) { onDispose { state.release() } }
    return state
}

/**
 * The docked player (since v1.3): a thin scrubber you can tap or drag, the times in tabular digits, −10 s, a 48 dp
 * play/pause in brand, +10 s and the speed chip (one tap steps through [PLAYER_SPEEDS]). Sits at the bottom of the
 * recording page, above the system bar.
 */
@Composable
fun RecordingPlayer(state: RecordingPlayerState, modifier: Modifier = Modifier) {
    val c = LocalEchoColors.current
    val type = LocalEchoType.current
    val enabled = state.ready && state.error == null
    Column(modifier.fillMaxWidth().background(c.surface).navigationBarsPadding()) {
        HorizontalDivider(thickness = 1.dp, color = c.border)
        Scrubber(state, enabled, Modifier.fillMaxWidth().padding(horizontal = Space.screen).padding(top = Space.s2))
        Row(
            Modifier.fillMaxWidth().height(64.dp).padding(horizontal = Space.s2),
            verticalAlignment = Alignment.CenterVertically
        ) {
            Text(
                "${TranscriptText.stamp(state.positionMs)} / ${TranscriptText.stamp(state.durationMs)}",
                style = type.labelM.tabular(),
                color = c.textMuted,
                modifier = Modifier.padding(start = Space.s2).weight(1f)
            )
            BarIcon(Icons.Rounded.Replay10, "Back 10 seconds", { state.jump(-10_000) }, tint = if (enabled) c.text else c.textFaint)
            Box(
                Modifier.size(48.dp).clip(CircleShape).background(if (enabled) c.accentStrong else c.surface2)
                    .clickable(enabled = enabled, role = Role.Button) { state.toggle() },
                contentAlignment = Alignment.Center
            ) {
                when {
                    state.error == null && !state.ready -> Spinner(18.dp, c.textMuted)
                    state.playing -> Icon(Icons.Rounded.Pause, contentDescription = "Pause", tint = c.onAccent)
                    else -> Icon(Icons.Rounded.PlayArrow, contentDescription = "Play", tint = if (enabled) c.onAccent else c.textFaint)
                }
            }
            BarIcon(Icons.Rounded.Forward10, "Forward 10 seconds", { state.jump(10_000) }, tint = if (enabled) c.text else c.textFaint)
            Box(Modifier.weight(1f), contentAlignment = Alignment.CenterEnd) {
                val speed = if (state.speed % 1f == 0f) "${state.speed.toInt()}×" else "${state.speed}×"
                EchoFilterChip(
                    speed,
                    active = state.speed != 1f,
                    onClick = {
                        val next = PLAYER_SPEEDS.getOrElse(PLAYER_SPEEDS.indexOf(state.speed) + 1) { PLAYER_SPEEDS.first() }
                        state.changeSpeed(next)
                    },
                    modifier = Modifier.padding(end = Space.s2).semantics { contentDescription = "Playback speed $speed" }
                )
            }
        }
        state.error?.let {
            Text(it, style = type.caption, color = c.danger, modifier = Modifier.padding(horizontal = Space.screen, vertical = Space.s1))
        }
    }
}

/** A 2 dp track (played part in brand) with a 12 dp thumb; tap or drag to move. */
@Composable
private fun Scrubber(state: RecordingPlayerState, enabled: Boolean, modifier: Modifier) {
    val c = LocalEchoColors.current
    val fraction = if (state.durationMs > 0) (state.positionMs.toFloat() / state.durationMs).coerceIn(0f, 1f) else 0f
    fun seekAt(x: Float, width: Int) {
        if (enabled && width > 0 && state.durationMs > 0) state.seekTo(((x / width).coerceIn(0f, 1f) * state.durationMs).toLong())
    }
    Box(
        modifier
            .height(20.dp)
            .pointerInput(enabled, state.durationMs) {
                detectTapGestures { seekAt(it.x, size.width) }
            }
            .pointerInput(enabled, state.durationMs) {
                detectHorizontalDragGestures { change, _ -> seekAt(change.position.x, size.width) }
            }
            .semantics {
                contentDescription = "Position"
                progressBarRangeInfo = ProgressBarRangeInfo(fraction, 0f..1f)
                if (enabled) {
                    setProgress { target ->
                        state.seekTo((target.coerceIn(0f, 1f) * state.durationMs).toLong())
                        true
                    }
                }
            },
        contentAlignment = Alignment.CenterStart
    ) {
        Box(Modifier.fillMaxWidth().height(2.dp).background(c.surface3))
        Box(Modifier.fillMaxWidth(fraction).height(2.dp).background(if (enabled) c.accentStrong else c.borderStrong))
        Box(Modifier.fillMaxWidth(fraction), contentAlignment = Alignment.CenterEnd) {
            Box(Modifier.size(12.dp).clip(CircleShape).background(if (enabled) c.accentStrong else c.borderStrong))
        }
    }
}
