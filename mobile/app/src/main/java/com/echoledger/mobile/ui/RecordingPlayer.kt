package com.echoledger.mobile.ui

import android.content.Context
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.size
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.outlined.Pause
import androidx.compose.material.icons.outlined.PlayArrow
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.FilledIconButton
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
import androidx.compose.runtime.mutableLongStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.media3.common.AudioAttributes
import androidx.media3.common.C
import androidx.media3.common.MediaItem
import androidx.media3.common.PlaybackException
import androidx.media3.common.Player
import androidx.media3.exoplayer.ExoPlayer
import com.echoledger.mobile.core.TranscriptText
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

/** Play/pause, scrubber and elapsed/total time, in a premium card. */
@Composable
fun RecordingPlayer(state: RecordingPlayerState, modifier: Modifier = Modifier) {
    SectionCard(modifier) {
        Row(verticalAlignment = Alignment.CenterVertically, horizontalArrangement = Arrangement.spacedBy(14.dp)) {
            FilledIconButton(
                onClick = { state.toggle() },
                enabled = state.ready && state.error == null,
                colors = IconButtonDefaults.filledIconButtonColors(
                    containerColor = Brand.Violet,
                    disabledContainerColor = Brand.SurfaceAlt
                ),
                modifier = Modifier.size(52.dp)
            ) {
                when {
                    state.error == null && !state.ready -> CircularProgressIndicator(
                        modifier = Modifier.size(20.dp),
                        strokeWidth = 2.dp,
                        color = Brand.Muted
                    )

                    state.playing -> Icon(Icons.Outlined.Pause, contentDescription = "Pause")

                    else -> Icon(Icons.Outlined.PlayArrow, contentDescription = "Play")
                }
            }
            Column(Modifier.fillMaxWidth(), verticalArrangement = Arrangement.spacedBy(0.dp)) {
                Slider(
                    value = if (state.durationMs > 0) state.positionMs.toFloat() / state.durationMs else 0f,
                    onValueChange = { fraction -> if (state.durationMs > 0) state.seekTo((fraction * state.durationMs).toLong()) },
                    enabled = state.ready && state.error == null,
                    colors = SliderDefaults.colors(
                        thumbColor = Brand.Cyan,
                        activeTrackColor = Brand.Violet,
                        inactiveTrackColor = Brand.SurfaceAlt
                    )
                )
                Row {
                    Text(TranscriptText.stamp(state.positionMs), color = Brand.Muted, fontSize = 12.sp)
                    Text("  /  ${TranscriptText.stamp(state.durationMs)}", color = Brand.Faint, fontSize = 12.sp)
                }
            }
        }
        state.error?.let { Text(it, color = Brand.Danger, fontSize = 12.sp) }
    }
}
